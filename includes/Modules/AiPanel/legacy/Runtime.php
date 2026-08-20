<?php
/**
 * AI Panel Runtime
 *
 * Ported and adapted from the standalone wp-panel.php.
 * Authentication uses a one-time password generated in WP admin (hashed).
 * URL routing is handled by the Bootstrap class — by the time we get here,
 * slug is already verified and access is active and not expired.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claude_Panel_Runtime {

    /** @var array */
    private static $opts = [];

    /** @var string */
    private static $root = '';

    /** @var string */
    private static $access_url = '';

    public static function handle_request($opts) {
        self::$opts       = $opts;
        self::$root       = ABSPATH; // WordPress install root
        self::$access_url = home_url('/cp-' . $opts['access_slug'] . '/');

        // Disable any plugin output we don't want.
        @error_reporting(E_ALL);
        @ini_set('display_errors', '0');

        // Custom session for the panel only. Cookie path must include the WP
        // home subdirectory (e.g. /chalupy/cp-…/) — jinak prohlížeč cookie
        // nepošle zpět a login se zacyklí.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $cookie_path = wp_parse_url(self::$access_url, PHP_URL_PATH) ?: '/cp-' . $opts['access_slug'] . '/';
            session_name('cppnl');
            session_set_cookie_params([
                'lifetime' => 3600,
                'path'     => $cookie_path,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            @session_start();
        }

        // Public discovery endpoint — vrací JSON dokumentaci API pro automatizované
        // klienty (např. AI agenty), aby se na cizím PC bez memory poznali, co
        // je za panel a jak ho použít. Nevyžaduje login, ale slug + aktivní access
        // už byl ověřen v Bootstrapu (jinak by se sem nedostali).
        if (($_GET['api'] ?? '') === 'help') {
            self::send_help();
            exit;
        }

        // Logout
        if (($_GET['action'] ?? '') === 'logout') {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            @session_destroy();
            wp_redirect(self::$access_url);
            exit;
        }

        // Login submission
        $login_error = '';
        if (($_POST['action'] ?? '') === 'login') {
            list($attempts, $ip) = self::rate_limit_check();
            $pw = $_POST['password'] ?? '';

            if (wp_check_password($pw, self::$opts['access_password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['auth']      = true;
                $_SESSION['auth_time'] = time();
                self::rate_limit_ok($attempts, $ip);
                Claude_Panel_Bootstrap::audit('login_ok');
                Claude_Panel_Bootstrap::notify_admin_email(
                    'AI Panel: úspěšné přihlášení',
                    sprintf("Někdo se právě přihlásil do AI Panelu.\nIP: %s\nČas: %s\n\nPokud jste to nebyl(a) Vy, ihned odeberte přístup v admin AI Panel.",
                        $ip, wp_date('Y-m-d H:i:s'))
                );
                wp_redirect(self::$access_url);
                exit;
            } else {
                self::rate_limit_fail($attempts, $ip);
                Claude_Panel_Bootstrap::audit('login_fail');
                $login_error = 'Nesprávné heslo.';
            }
        }

        // Not logged in → render login.
        if (!self::is_logged_in()) {
            self::render_login($login_error);
            exit;
        }

        // Refresh session age.
        $_SESSION['auth_time'] = time();

        // API endpoints
        if (isset($_GET['api'])) {
            self::handle_api();
            exit;
        }
        if (isset($_GET['download'])) {
            self::handle_download();
            exit;
        }

        // Main UI
        self::render_ui();
    }

    /* ============ DISCOVERY ============ */

    public static function send_help() {
        header('Content-Type: application/json; charset=utf-8');
        header('X-AI-Panel: ' . CP_VERSION);

        // brief=1 → minimální strojová verze (angličtina, bez prózy). Načítá se
        // na začátku každé session → menší = méně tokenů. Plná verze zůstává default.
        if (!empty($_GET['brief'])) {
            echo json_encode([
                'name'    => 'AI Panel',
                'version' => CP_VERSION,
                'base'    => self::$access_url,
                'auth'    => 'POST base {action:login, password:PW} -> 302 + cookie cppnl; parse CSRF from `const CSRF = "..."` in main UI HTML; send CSRF in POST field `csrf` or header X-CSRF-Token.',
                'call'    => 'POST base?api=<action> (form-encoded). path params are relative to WP ABSPATH.',
                'actions' => [
                    'list'   => 'path [,compact=1 -> items:[name,"d"|"f"]]',
                    'read'   => 'path [,start_line,end_line] (partial read; returns total_lines). 5MB cap.',
                    'grep'   => 'pattern|p64, path(file|dir) [,regex=1,ignore_case=1,glob=*.php,max=200,context=N]. Returns only matching lines {file,line,text}.',
                    'write'  => 'path, content|c64. Full-file write. PHP is linted (422 lint_error; force=1). Auto-backup.',
                    'patch'  => 'path, find|f64, replace|r64 [,all=1,force=1]. PREFER over write. 422 if find not unique (occurrences).',
                    'delete' => 'path',
                    'mkdir'  => 'path',
                    'rename' => 'from, to',
                    'upload' => 'multipart files[], path',
                    'exec'   => 'cmd|cmd64 [,cwd] -> {output,rc,cwd}',
                    'sql'    => 'query|q64 [,maxrows=500,compact=1,maxcell=N]. SELECT->{columns,rows,count}; else {ok,affected,insertId}.',
                    'wpinfo' => '-> WP/server/db info',
                    'download' => 'GET base?download=path',
                ],
                'b64_fields' => ['sql:query->q64', 'exec:cmd->cmd64', 'write:content->c64', 'patch:find->f64,replace->r64', 'grep:pattern->p64'],
                'efficiency' => [
                    'Edit via patch (diff), not write (whole file).',
                    'Search via grep, do not read whole files/dirs to find text.',
                    'Read big files partially (start_line/end_line).',
                    'list?compact=1 for big dirs. SQL: select needed cols + LIMIT, add compact=1 + maxcell for bulky results.',
                ],
                'expires_in_seconds' => max(0, intval(self::$opts['access_expires_at']) - time()),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $help = [
            'name'        => 'AI Panel',
            'version'     => CP_VERSION,
            'purpose'     => 'Single-page admin panel pro WordPress: dočasný řízený přístup k souborům, databázi a shellu serveru.',
            'audience'    => 'Libovolný AI agent / autonomní klient (Claude Code, Codex, Gemini CLI a podobné). Tento JSON popisuje protokol natolik, aby agent mohl panel obsluhovat bez další lidské nápovědy.',
            'how_to_use'  => [
                '1. Načti tento endpoint (' . self::$access_url . '?api=help) – potvrzuje identitu pluginu.',
                '2. Přihlaš se: POST na ' . self::$access_url . ' s polem `action=login` a `password=<heslo>`. Server vrátí 302 + session cookie `cppnl` (scope: cesta panelu, HttpOnly, SameSite=Strict).',
                '3. Z odpovědi po loginu (HTML hlavního UI) vyparsuj CSRF token: `const CSRF = "<token>"` v <script> tagu.',
                '4. Volej API přes POST na ' . self::$access_url . '?api=<action>. CSRF token posílej v poli `csrf` nebo hlavičce `X-CSRF-Token`.',
                '5. Pro akce `sql`, `exec`, `write`, `patch` posílej payload v base64 (níže), abys obešel WAF (Wordfence apod.).',
                '6. Po dokončení prací: GET ' . self::$access_url . '?action=logout. Uživatel pak v WP admin dashboardu klikne „Okamžitě odebrat přístup" (kill switch).',
            ],
            'auth' => [
                'method'      => 'POST form, klasický password',
                'rate_limit'  => '5 chybných pokusů → 15 min lockout (per IP)',
                'session_ttl' => '3600 s (po každém requestu se obnovuje)',
                'csrf'        => 'Token v session, vrácen v HTML hlavního UI jako `const CSRF = "..."`. Posílej v POST poli `csrf` nebo HTTP hlavičce `X-CSRF-Token`.',
            ],
            'endpoints' => [
                'GET  /'              => 'Login form (před přihlášením) nebo hlavní UI (po přihlášení).',
                'POST /'              => 'Login: pole `action=login`, `password=<heslo>`.',
                'GET  ?api=help'      => 'Tento JSON. Bez auth. Přidej `&brief=1` pro stručnou strojovou verzi (výrazně méně tokenů) — doporučeno pro automatizované klienty.',
                'GET  ?action=logout' => 'Logout.',
                'POST ?api=list'      => 'Listing adresáře. Pole: `path` (relativní k WP rootu). Volitelně `compact=1` → položky jako poziční pole `[name, "d"|"f"]` bez size/mtime/perms (méně tokenů u velkých adresářů; `format` popisuje pořadí).',
                'POST ?api=grep'      => 'Hledání v souboru NEBO adresáři (rekurzivně) — vrací JEN matchující řádky, ne celé soubory (řádově méně tokenů než read+lokální hledání). Pole: `pattern` NEBO `p64` (base64), `path` (soubor/adresář, default \'.\'), volitelně `regex=1` (PCRE; jinak literal), `ignore_case=1`, `glob` (filtr názvu, např. `*.php`), `max` (strop matchů, default 200), `maxcell` (ořez délky řádku, default 300), `context` (± řádky, 0–10). Vrací `{matches:[{file,line,text}], count, files_scanned, truncated?}`. Přeskakuje binárky, *.min.*, zálohy a soubory >3 MB.',
                'POST ?api=read'      => 'Přečti soubor. Pole: `path`. Volitelně `start_line` + `end_line` (1-based včetně) pro částečné čtení jen daného rozsahu řádků — šetří tokeny u velkých souborů (vrací i `total_lines`). Limit 5 MB.',
                'POST ?api=write'     => 'Zapiš CELÝ soubor. Pole: `path`, `content` NEBO `c64` (base64). Vytváří + přepisuje. POZOR: pro úpravu existujícího souboru použij radši ?api=patch (mnohem méně tokenů). U *.php se obsah nejdřív zkontroluje na syntax chyby a zápis se při chybě zablokuje (HTTP 422, pole `lint_error`) — vynutíš přes `force=1`. Existující soubor se před přepsáním zálohuje.',
                'POST ?api=patch'     => 'PREFEROVANÁ úprava existujícího souboru (find/replace) — posílá se JEN měněný úsek místo celého souboru, řádově méně tokenů než write. Pole: `path`, `find` NEBO `f64` (base64), `replace` NEBO `r64` (base64), volitelně `all=1` (nahradí všechny výskyty; jinak musí být `find` v souboru jednoznačný, jinak HTTP 422 + `occurrences`), `force=1` (přeskočí PHP lint). U *.php se lintuje VÝSLEDEK; před zápisem se dělá záloha. Vrací `{ok, replaced, size, backup}`.',
                'POST ?api=delete'    => 'Smaž soubor / adresář (rekurzivně). Pole: `path`. Plugin chrání sám sebe a WP root.',
                'POST ?api=mkdir'     => 'Vytvoř adresář (rekurzivně). Pole: `path`.',
                'POST ?api=rename'    => 'Přejmenuj / přesuň. Pole: `from`, `to`.',
                'POST ?api=upload'    => 'Upload souborů. multipart/form-data: `files[]`, `path` (cílový adresář).',
                'POST ?api=exec'      => 'Spusť shell příkaz. Pole: `cmd` NEBO `cmd64` (base64), volitelně `cwd`. Vrací `{output, rc, cwd}`.',
                'POST ?api=sql'       => 'Spusť SQL na WP DB. Pole: `query` NEBO `q64` (base64). SELECT vrací `{columns, rows, count}`, ostatní vrací `{ok, affected, insertId}`. Úsporné volitelné parametry u čtení: `maxrows` (strop řádků, default 500, `0`=bez stropu; při ořezu vrací `truncated:true`), `compact=1` (řádky jako poziční pole místo objektů → jména sloupců jen jednou v `columns`, odpověď má `format:"rows"`), `maxcell` (ořez dlouhých textových buněk na N znaků se značkou `…[+N B]`). Doporučení: vybírej jen potřebné sloupce + `LIMIT`, ne `SELECT *`.',
                'POST ?api=wpinfo'    => 'WordPress / server info: WP verze, locale, DB connection, PHP, server, disk, exec_enabled atd.',
                'GET  ?download=path' => 'Stáhni soubor (binární stream, Content-Disposition: attachment).',
            ],
            'obfuscation' => [
                'reason' => 'Wordfence a podobné WAF blokují payloady jako "SELECT", "rm -rf", apod. Posíláním v base64 (s alternativním názvem pole) tyto signature-based filtry obejdeš.',
                'fields' => [
                    'sql:   query  → q64  (base64 SQL dotazu)',
                    'exec:  cmd    → cmd64 (base64 shell příkazu)',
                    'write: content→ c64  (base64 obsahu souboru)',
                    'patch: find   → f64, replace → r64 (base64 úseků)',
                ],
            ],
            'efficiency' => [
                'reason' => 'Panel se obsluhuje přes HTTP — každý přenesený bajt jsou tokeny (a vygenerovaný obsah jsou drahé output tokeny). Tyto zásady drží spotřebu nízko:',
                'rules'  => [
                    'Na začátku session načti ?api=help&brief=1 (ne plný help) — je řádově menší.',
                    'Úpravy souborů dělej přes ?api=patch (find/replace), NE přes ?api=write s celým obsahem — celý soubor (navíc v base64) je řádově víc tokenů než malý diff.',
                    'Hledej přes ?api=grep (vrací jen matchující řádky), NEČTI kvůli hledání celé soubory ani adresáře.',
                    'Velké soubory čti částečně: ?api=read se start_line/end_line místo celého souboru.',
                    'Velké adresáře listuj s ?api=list&compact=1.',
                    'U SELECT vybírej jen potřebné sloupce a používej LIMIT. Pro objemné výsledky přidej compact=1 a maxcell=500. Vyhni se SELECT * u wp_posts/wp_options (velké HTML / serializované sloupce).',
                ],
            ],
            'response_format' => [
                'success' => 'HTTP 200, JSON podle akce.',
                'error'   => 'HTTP 4xx, JSON: {"error": "human readable message"}.',
                'auth_fail' => 'HTTP 403 + {"error": "Neplatný CSRF token"} pro chybějící CSRF; redirect na login pokud session vypršela.',
                'rate_limited' => 'HTTP 429 + plain text se zbývajícím cooldownem.',
                'expired_access' => 'HTTP 410 — uživatel musí povolit přístup znovu v WP admin dashboardu.',
            ],
            'safety' => [
                'php_lint'   => 'Zápis *.php se ověří přes token_get_all(TOKEN_PARSE). Syntax error → HTTP 422 a zápis se NEprovede. Obejdeš polem force=1.',
                'auto_backup'=> 'Před každým write/delete/rename, které přepisuje/maže existující soubor, se originál zkopíruje do wp-content/claude-panel-backups/<datum>/ (web-nedostupné). Rollback = zkopírovat zpět.',
                'rescue'     => 'Pokud i tak dojde k pádu WP (fatální chyba za běhu, ne jen syntax), existuje samostatný záchranný endpoint mimo WordPress — spravuje ho admin ve WP → AI Panel.',
            ],
            'filesystem_root' => 'WordPress ABSPATH. Všechny `path` parametry jsou relativní k němu.',
            'audit_log'       => 'Všechny zápisové operace se logují do WP options `claude_panel_audit` (admin to vidí v dashboardu, pokud chce). Operace: login_ok, login_fail, write, delete, mkdir, rename, upload, exec, sql, download.',
            'time_limit'      => 'Přístup auto-expiruje. Aktuální stav viz `expires_in_seconds` níže.',
            'expires_in_seconds' => max(0, intval(self::$opts['access_expires_at']) - time()),
            'expires_at_iso'     => date('c', intval(self::$opts['access_expires_at'])),
        ];
        echo json_encode($help, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /* ============ AUTH HELPERS ============ */

    public static function is_logged_in() {
        return !empty($_SESSION['auth'])
            && intval($_SESSION['auth_time'] ?? 0) > time() - 3600;
    }

    public static function csrf_token() {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function check_csrf() {
        $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
            http_response_code(403);
            echo json_encode(['error' => 'Neplatný CSRF token']);
            exit;
        }
    }

    /* ============ RATE LIMIT ============ */

    public static function rate_limit_check() {
        $ip   = Claude_Panel_Bootstrap::client_ip();
        $data = get_option(CP_ATTEMPTS_OPT, []);
        if (!is_array($data)) $data = [];
        $rec  = $data[$ip] ?? ['count' => 0, 'last' => 0];
        if ($rec['count'] >= 5 && time() - $rec['last'] < 900) {
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Příliš mnoho pokusů. Počkej ' . (900 - (time() - $rec['last'])) . ' s.';
            exit;
        }
        return [$data, $ip];
    }

    public static function rate_limit_fail($data, $ip) {
        $rec = $data[$ip] ?? ['count' => 0, 'last' => 0];
        $rec['count']++;
        $rec['last'] = time();
        $data[$ip] = $rec;
        update_option(CP_ATTEMPTS_OPT, $data, false);
    }

    public static function rate_limit_ok($data, $ip) {
        unset($data[$ip]);
        update_option(CP_ATTEMPTS_OPT, $data, false);
    }

    /* ============ FS HELPERS ============ */

    public static function safe_path($rel, $must_exist = true) {
        $rel  = str_replace('\\', '/', (string) $rel);
        $rel  = ltrim($rel, '/');
        $cand = self::$root . $rel;
        if ($must_exist) {
            $full = realpath($cand);
            if ($full === false) {
                throw new Exception('Cesta neexistuje: ' . $rel);
            }
        } else {
            $parent = realpath(dirname($cand));
            if ($parent === false) {
                throw new Exception('Rodičovský adresář neexistuje.');
            }
            $rootReal = realpath(self::$root);
            if (strpos($parent . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR) !== 0
                && $parent !== $rootReal) {
                throw new Exception('Cesta mimo root.');
            }
            return $cand;
        }
        $rootReal = realpath(self::$root);
        if (strpos($full . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR) !== 0
            && $full !== $rootReal) {
            throw new Exception('Cesta mimo root.');
        }
        return $full;
    }

    public static function rel_path($abs) {
        $r = realpath(self::$root);
        $a = realpath($abs) ?: $abs;
        $rel = str_replace('\\', '/', substr($a, strlen($r)));
        return ltrim($rel, '/') ?: '.';
    }

    public static function rrmdir($dir) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? self::rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    /** Nahradí jen PRVNÍ výskyt $find v $subject (pro ?api=patch). */
    public static function str_replace_first($find, $replace, $subject) {
        $pos = strpos($subject, $find);
        if ($pos === false) return $subject;
        return substr_replace($subject, $replace, $pos, strlen($find));
    }

    /* ============ SAFETY: LINT + BACKUP ============ */

    public static function is_php_path($path) {
        return (bool) preg_match('/\.(php|phtml|php[0-9]|phps|inc)$/i', (string) $path);
    }

    /**
     * Zkontroluje PHP syntaxi obsahu BEZ jeho spuštění.
     * Vrací null když je vše OK, jinak textovou chybovou hlášku.
     * token_get_all(TOKEN_PARSE) běží in-process, nevyžaduje php binárku ani
     * exec() a nikdy nespustí kontrolovaný kód.
     */
    public static function php_syntax_error($content) {
        if (!defined('TOKEN_PARSE')) {
            return null;
        }
        try {
            token_get_all((string) $content, TOKEN_PARSE);
            return null;
        } catch (\ParseError $e) {
            return $e->getMessage() . ' (řádek ' . $e->getLine() . ')';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    public static function backup_root() {
        $dir = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : self::$root . 'wp-content') . '/claude-panel-backups';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess',
                "# AI Panel backups — deny all web access\n" .
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" .
                "Options -Indexes\n");
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir;
    }

    /**
     * Zálohuje existující soubor PŘED přepsáním/smazáním se zachováním
     * relativní cesty. Vrací cestu k záloze, nebo null když nebylo co zálohovat.
     */
    public static function backup_existing($abs, $action) {
        if (!is_file($abs)) {
            return null;
        }
        $rel   = self::rel_path($abs);
        $stamp = wp_date('Y-m-d_Hi');
        $batch = self::backup_root() . '/' . $stamp;
        $dest  = $batch . '/' . $rel;
        wp_mkdir_p(dirname($dest));
        @copy($abs, $dest);
        @file_put_contents(
            $batch . '/zmeny.txt',
            sprintf("[%s] %-16s %s\n", wp_date('Y-m-d H:i:s'), strtoupper($action), $rel),
            FILE_APPEND
        );
        return $dest;
    }

    /* ============ INFO ============ */

    public static function wp_info() {
        global $wpdb;
        $info = [
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'os'             => PHP_OS,
            'server'         => $_SERVER['SERVER_SOFTWARE'] ?? 'n/a',
            'doc_root'       => self::$root,
            'memory_limit'   => ini_get('memory_limit'),
            'upload_max'     => ini_get('upload_max_filesize'),
            'post_max'       => ini_get('post_max_size'),
            'max_exec_time'  => ini_get('max_execution_time'),
            'disk_free'      => @disk_free_space(self::$root),
            'disk_total'     => @disk_total_space(self::$root),
            'exec_enabled'   => function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions')))),
            'site_url'       => get_option('siteurl'),
            'home_url'       => get_option('home'),
            'locale'         => get_locale(),
            'multisite'      => is_multisite() ? 'yes' : 'no',
            'db' => [
                'DB_HOST'   => DB_HOST,
                'DB_NAME'   => DB_NAME,
                'DB_USER'   => DB_USER,
                'DB_CHARSET'=> defined('DB_CHARSET') ? DB_CHARSET : '',
                'table_prefix' => $wpdb->prefix,
            ],
        ];
        return $info;
    }

    /* ============ API ============ */

    public static function handle_api() {
        header('Content-Type: application/json; charset=utf-8');
        self::check_csrf();
        try {
            $action = $_GET['api'];
            $res = null;

            switch ($action) {

                case 'list': {
                    $path = self::safe_path($_POST['path'] ?? '.');
                    if (!is_dir($path)) throw new Exception('Není adresář.');
                    // compact=1 → jen name+type (poziční pole), bez size/mtime/perms/path.
                    // Šetří tokeny u velkých adresářů; detaily si vyžádej cíleně jinou akcí.
                    $compact = !empty($_POST['compact']);
                    $items = [];
                    foreach (scandir($path) as $f) {
                        if ($f === '.' || $f === '..') continue;
                        $full = $path . '/' . $f;
                        $isDir = is_dir($full);
                        if ($compact) {
                            // [name, "d"|"f"] — jména sloupců popsána ve `format`.
                            $items[] = [$f, $isDir ? 'd' : 'f'];
                        } else {
                            $items[] = [
                                'name'  => $f,
                                'path'  => self::rel_path($full),
                                'type'  => $isDir ? 'dir' : 'file',
                                'size'  => $isDir ? null : @filesize($full),
                                'mtime' => @filemtime($full),
                                'perms' => substr(sprintf('%o', @fileperms($full) ?: 0), -4),
                            ];
                        }
                    }
                    if ($compact) {
                        usort($items, fn($a,$b) => [$a[1] === 'f', strtolower($a[0])] <=> [$b[1] === 'f', strtolower($b[0])]);
                        $res = ['items' => $items, 'path' => self::rel_path($path), 'parent' => self::rel_path(dirname($path)), 'format' => 'name,type(d|f)'];
                    } else {
                        usort($items, fn($a,$b) => [$a['type'] === 'file', strtolower($a['name'])] <=> [$b['type'] === 'file', strtolower($b['name'])]);
                        $res = ['items' => $items, 'path' => self::rel_path($path), 'parent' => self::rel_path(dirname($path))];
                    }
                    break;
                }

                case 'read': {
                    $path = self::safe_path($_POST['path']);
                    if (!is_file($path)) throw new Exception('Není soubor.');
                    $size = filesize($path);
                    if ($size > 5 * 1024 * 1024) throw new Exception('Soubor je příliš velký pro zobrazení (>5 MB). Stáhni jej.');
                    $raw = file_get_contents($path);

                    // Volitelné částečné čtení po řádcích (start_line/end_line, 1-based
                    // včetně). Šetří tokeny — nemusíš tahat celý soubor kvůli pár řádkům.
                    $start = isset($_POST['start_line']) ? max(1, intval($_POST['start_line'])) : 0;
                    $end   = isset($_POST['end_line'])   ? intval($_POST['end_line'])         : 0;
                    if ($start > 0 || $end > 0) {
                        $lines = preg_split('/\r\n|\r|\n/', $raw);
                        $total = count($lines);
                        $from  = $start > 0 ? min($start, $total) : 1;
                        $to    = $end   > 0 ? min($end, $total)   : $total;
                        if ($to < $from) $to = $from;
                        $slice = array_slice($lines, $from - 1, $to - $from + 1);
                        $res = [
                            'content'     => implode("\n", $slice),
                            'path'        => self::rel_path($path),
                            'size'        => $size,
                            'total_lines' => $total,
                            'start_line'  => $from,
                            'end_line'    => $to,
                            'partial'     => true,
                        ];
                    } else {
                        $res = ['content' => $raw, 'path' => self::rel_path($path), 'size' => $size];
                    }
                    break;
                }

                case 'grep': {
                    // Hledání v souboru NEBO adresáři (rekurzivně) — vrací JEN
                    // matchující řádky s číslem řádku, ne celé soubory → řádově
                    // méně tokenů než tahat obsah a hledat lokálně.
                    // Pole: pattern|p64, path (default '.'), [regex=1], [ignore_case=1],
                    //       [glob=*.php] (filtr názvu), [max=200] (strop matchů),
                    //       [maxcell=300] (ořez délky řádku), [context=0] (± řádky).
                    $pattern = isset($_POST['p64'])
                        ? (string) base64_decode($_POST['p64'], true)
                        : (string) ($_POST['pattern'] ?? '');
                    if ($pattern === false || $pattern === '') throw new Exception('Chybí/neplatný pattern (nebo p64).');

                    $base       = self::safe_path($_POST['path'] ?? '.');
                    $regex      = !empty($_POST['regex']);
                    $ci         = !empty($_POST['ignore_case']);
                    $glob       = isset($_POST['glob']) ? (string) $_POST['glob'] : '';
                    $max        = isset($_POST['max'])     ? max(1, intval($_POST['max']))     : 200;
                    $maxcell    = isset($_POST['maxcell']) ? max(0, intval($_POST['maxcell'])) : 300;
                    $context    = isset($_POST['context']) ? max(0, min(10, intval($_POST['context']))) : 0;

                    // Sestav vyhledávací uzávěr (literal vs. regex).
                    if ($regex) {
                        $delim = '~';
                        $re    = $delim . str_replace($delim, '\\' . $delim, $pattern) . $delim . ($ci ? 'i' : '');
                        if (@preg_match($re, '') === false) throw new Exception('Neplatný regex.');
                        $match_line = function ($line) use ($re) { return (bool) preg_match($re, $line); };
                    } else {
                        $needle = $ci ? mb_strtolower($pattern) : $pattern;
                        $match_line = function ($line) use ($needle, $ci) {
                            return strpos($ci ? mb_strtolower($line) : $line, $needle) !== false;
                        };
                    }

                    // Seznam souborů ke skenu.
                    $files = [];
                    if (is_file($base)) {
                        $files[] = $base;
                    } elseif (is_dir($base)) {
                        $it = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        foreach ($it as $f) {
                            if (!$f->isFile()) continue;
                            $name = $f->getFilename();
                            // Přeskoč zálohy pluginu a zjevně binární/objemné soubory.
                            if (strpos($f->getPathname(), 'claude-panel-backups') !== false) continue;
                            if ($glob !== '' && !fnmatch($glob, $name)) continue;
                            if ($f->getSize() > 3 * 1024 * 1024) continue;
                            if (preg_match('/\.(png|jpe?g|gif|webp|ico|svg|woff2?|ttf|eot|zip|gz|tar|pdf|mp[34]|mov|avi|bin|exe|so|dll|class|min\.js|min\.css)$/i', $name)) continue;
                            $files[] = $f->getPathname();
                        }
                    } else {
                        throw new Exception('Cesta není soubor ani adresář.');
                    }

                    $matches   = [];
                    $truncated = false;
                    $scanned   = 0;
                    foreach ($files as $file) {
                        if ($truncated) break;
                        $fh = @fopen($file, 'r');
                        if (!$fh) continue;
                        $scanned++;
                        $rel   = self::rel_path($file);
                        $lineno = 0;
                        $buffer = [];       // pro kontext
                        while (($line = fgets($fh)) !== false) {
                            $lineno++;
                            $line = rtrim($line, "\r\n");
                            if ($context > 0) {
                                $buffer[$lineno] = $line;
                                if (count($buffer) > $context + 1) array_shift($buffer);
                            }
                            if ($match_line($line)) {
                                $text = $line;
                                if ($maxcell > 0 && strlen($text) > $maxcell) {
                                    $text = substr($text, 0, $maxcell) . '…[+' . (strlen($text) - $maxcell) . ' B]';
                                }
                                $m = ['file' => $rel, 'line' => $lineno, 'text' => $text];
                                if ($context > 0) {
                                    // pár řádků před (z bufferu) — jen pro orientaci
                                    $before = [];
                                    foreach ($buffer as $ln => $bt) {
                                        if ($ln < $lineno) $before[] = $ln . ':' . $bt;
                                    }
                                    if ($before) $m['before'] = $before;
                                }
                                $matches[] = $m;
                                if (count($matches) >= $max) { $truncated = true; break; }
                            }
                        }
                        fclose($fh);
                    }

                    $res = [
                        'matches'       => $matches,
                        'count'         => count($matches),
                        'files_scanned' => $scanned,
                    ];
                    if ($truncated) $res['truncated'] = true;
                    break;
                }

                case 'write': {
                    $path = self::safe_path($_POST['path'], false);
                    $content = isset($_POST['c64'])
                        ? base64_decode($_POST['c64'], true)
                        : ($_POST['content'] ?? '');
                    if ($content === false) throw new Exception('Neplatný base64 obsah.');

                    // Bezpečnostní lint: PHP soubory se před zápisem zkontrolují
                    // na syntaktické chyby (nejčastější příčina bílé obrazovky
                    // celého webu). Lze vynutit polem force=1 (na vlastní riziko).
                    $force = !empty($_POST['force']);
                    if (self::is_php_path($path) && !$force) {
                        $lint = self::php_syntax_error($content);
                        if ($lint !== null) {
                            Claude_Panel_Bootstrap::audit('write_blocked', self::rel_path($path) . ' — ' . substr($lint, 0, 200));
                            http_response_code(422);
                            echo json_encode([
                                'error'      => 'PHP syntax error — zápis zablokován kvůli ochraně webu: ' . $lint,
                                'lint_error' => $lint,
                                'hint'       => 'Oprav syntaxi, nebo pošli force=1 pro vynucení zápisu.',
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                    }

                    // Záloha původní verze PŘED přepsáním → instantní rollback.
                    $backup = self::backup_existing($path, 'write');

                    if (file_put_contents($path, $content) === false) throw new Exception('Zápis selhal.');
                    Claude_Panel_Bootstrap::audit('write', self::rel_path($path) . ' (' . strlen($content) . ' B)'
                        . ($backup ? ' [záloha ✓]' : ''));
                    $res = ['ok' => true, 'path' => self::rel_path($path), 'size' => strlen($content), 'backup' => (bool) $backup];
                    break;
                }

                case 'patch': {
                    // Cílená úprava (find/replace) — posílá se JEN měněný úsek místo
                    // celého souboru → řádově méně (drahých output) tokenů než write.
                    // Pole: path, find|f64, replace|r64, [all=1], [force=1].
                    $path = self::safe_path($_POST['path']);
                    if (!is_file($path)) throw new Exception('Není soubor.');

                    $find = isset($_POST['f64'])
                        ? base64_decode($_POST['f64'], true)
                        : ($_POST['find'] ?? null);
                    $replace = isset($_POST['r64'])
                        ? base64_decode($_POST['r64'], true)
                        : ($_POST['replace'] ?? '');
                    if ($find === null || $find === false || $find === '') {
                        throw new Exception('Chybí/neplatné pole find (nebo f64).');
                    }
                    if ($replace === false) throw new Exception('Neplatný base64 v r64.');

                    $original    = file_get_contents($path);
                    $occurrences = substr_count($original, $find);
                    if ($occurrences === 0) {
                        http_response_code(422);
                        echo json_encode([
                            'error'       => 'Hledaný úsek (find) se v souboru nenašel.',
                            'occurrences' => 0,
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $all = !empty($_POST['all']);
                    if ($occurrences > 1 && !$all) {
                        http_response_code(422);
                        echo json_encode([
                            'error'       => 'Úsek (find) není jednoznačný — nalezen ' . $occurrences . '×. '
                                           . 'Rozšiř kontext, nebo pošli all=1 pro nahrazení všech výskytů.',
                            'occurrences' => $occurrences,
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }

                    $new      = $all
                        ? str_replace($find, $replace, $original)
                        : self::str_replace_first($find, $replace, $original);
                    $replaced = $all ? $occurrences : 1;

                    // Lint VÝSLEDKU (ne jen náhrady) u PHP — chrání web před bílou obrazovkou.
                    $force = !empty($_POST['force']);
                    if (self::is_php_path($path) && !$force) {
                        $lint = self::php_syntax_error($new);
                        if ($lint !== null) {
                            Claude_Panel_Bootstrap::audit('write_blocked', self::rel_path($path) . ' [patch] — ' . substr($lint, 0, 200));
                            http_response_code(422);
                            echo json_encode([
                                'error'      => 'PHP syntax error po náhradě — zápis zablokován: ' . $lint,
                                'lint_error' => $lint,
                                'hint'       => 'Oprav náhradu, nebo pošli force=1 pro vynucení zápisu.',
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                    }

                    $backup = self::backup_existing($path, 'patch');
                    if (file_put_contents($path, $new) === false) throw new Exception('Zápis selhal.');
                    Claude_Panel_Bootstrap::audit('write', self::rel_path($path) . ' [patch ×' . $replaced . ', ' . strlen($new) . ' B]'
                        . ($backup ? ' [záloha ✓]' : ''));
                    $res = ['ok' => true, 'path' => self::rel_path($path), 'replaced' => $replaced, 'size' => strlen($new), 'backup' => (bool) $backup];
                    break;
                }

                case 'delete': {
                    $path = self::safe_path($_POST['path']);
                    if ($path === realpath(self::$root)) throw new Exception('Nelze smazat root.');
                    // Protect the host UX1 plugin (which contains this module) from being deleted via the panel.
                    $host_plugin_dir = defined('WP_EXTENDED_PATH') ? realpath(WP_EXTENDED_PATH) : false;
                    if ($host_plugin_dir && strpos($path, $host_plugin_dir) === 0) {
                        throw new Exception('Nelze smazat samotný plugin.');
                    }
                    $backup = is_file($path) ? self::backup_existing($path, 'delete') : null;
                    is_dir($path) ? self::rrmdir($path) : unlink($path);
                    Claude_Panel_Bootstrap::audit('delete', self::rel_path($path) . ($backup ? ' [záloha ✓]' : ''));
                    $res = ['ok' => true, 'backup' => (bool) $backup];
                    break;
                }

                case 'mkdir': {
                    $path = self::safe_path($_POST['path'], false);
                    if (!mkdir($path, 0755, true) && !is_dir($path)) throw new Exception('mkdir selhal.');
                    Claude_Panel_Bootstrap::audit('mkdir', self::rel_path($path));
                    $res = ['ok' => true, 'path' => self::rel_path($path)];
                    break;
                }

                case 'rename': {
                    $from = self::safe_path($_POST['from']);
                    $to   = self::safe_path($_POST['to'], false);
                    // Když cíl existuje, přepíše se — zálohuj ho před ztrátou.
                    $backup = is_file($to) ? self::backup_existing($to, 'rename-overwrite') : null;
                    if (!rename($from, $to)) throw new Exception('Přejmenování selhalo.');
                    Claude_Panel_Bootstrap::audit('rename', self::rel_path($from) . ' -> ' . self::rel_path($to) . ($backup ? ' [záloha ✓]' : ''));
                    $res = ['ok' => true, 'backup' => (bool) $backup];
                    break;
                }

                case 'upload': {
                    $dir = self::safe_path($_POST['path'] ?? '.');
                    if (!is_dir($dir)) throw new Exception('Cíl není adresář.');
                    $uploaded = [];
                    if (empty($_FILES['files'])) throw new Exception('Žádné soubory.');
                    $files = $_FILES['files'];
                    $count = is_array($files['name']) ? count($files['name']) : 1;
                    for ($i = 0; $i < $count; $i++) {
                        $name = basename(is_array($files['name']) ? $files['name'][$i] : $files['name']);
                        $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                        if (!$name || !is_uploaded_file($tmp)) continue;
                        $dest = $dir . '/' . $name;
                        if (!move_uploaded_file($tmp, $dest)) throw new Exception('Upload ' . $name . ' selhal.');
                        $uploaded[] = self::rel_path($dest);
                    }
                    Claude_Panel_Bootstrap::audit('upload', implode(', ', $uploaded));
                    $res = ['ok' => true, 'uploaded' => $uploaded];
                    break;
                }

                case 'exec': {
                    if (!function_exists('exec')) throw new Exception('exec() zakázáno.');
                    $cmd = isset($_POST['cmd64'])
                        ? (string) base64_decode($_POST['cmd64'], true)
                        : (string) ($_POST['cmd'] ?? '');
                    if ($cmd === false || trim($cmd) === '') throw new Exception('Prázdný/neplatný příkaz.');
                    $cwd = isset($_POST['cwd']) ? self::safe_path($_POST['cwd']) : self::$root;
                    $output = [];
                    $rc = 0;
                    $old = getcwd();
                    chdir($cwd);
                    exec($cmd . ' 2>&1', $output, $rc);
                    chdir($old);
                    Claude_Panel_Bootstrap::audit('exec', $cmd . ' (rc=' . $rc . ')');
                    $res = ['output' => implode("\n", $output), 'rc' => $rc, 'cwd' => self::rel_path($cwd)];
                    break;
                }

                case 'wpinfo':
                    $res = self::wp_info();
                    break;

                case 'sql': {
                    global $wpdb;
                    $query = isset($_POST['q64'])
                        ? (string) base64_decode($_POST['q64'], true)
                        : (string) ($_POST['query'] ?? '');
                    if ($query === false || trim($query) === '') throw new Exception('Prázdný/neplatný dotaz.');

                    // Detekuj typ dotazu (první klíčové slovo). Modifikační dotazy
                    // jdou do auditu; čistá čtení (SELECT/SHOW/DESCRIBE/EXPLAIN) ne.
                    $stripped = ltrim($query);
                    // Skip leading /* comments */ a -- line comments
                    $stripped = preg_replace('#^/\*.*?\*/\s*#s', '', $stripped);
                    $stripped = preg_replace('#^--[^\n]*\n\s*#s', '', $stripped);
                    if (preg_match('/^\s*([A-Za-z]+)/', $stripped, $m)) {
                        $verb = strtoupper($m[1]);
                    } else {
                        $verb = 'UNKNOWN';
                    }
                    $write_verbs = [
                        'INSERT','UPDATE','DELETE','REPLACE','TRUNCATE',
                        'CREATE','DROP','ALTER','RENAME',
                        'GRANT','REVOKE',
                        'LOAD','CALL','LOCK','UNLOCK',
                        'SET','HANDLER','MERGE',
                    ];
                    $is_write = in_array($verb, $write_verbs, true);

                    // Use raw mysqli to allow non-SELECT statements with affected rows / insert id.
                    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
                    if ($mysqli->connect_error) throw new Exception('DB: ' . $mysqli->connect_error);
                    $mysqli->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
                    $result = $mysqli->query($query);
                    if ($result === false) {
                        $err = $mysqli->error;
                        // Log neúspěšný modifikační dotaz, ať máme stopu i u chyb.
                        if ($is_write) {
                            Claude_Panel_Bootstrap::audit('sql_error', $verb . ' (FAILED: ' . substr($err, 0, 150) . ') — ' . substr($query, 0, 200));
                        }
                        $mysqli->close();
                        throw new Exception('SQL: ' . $err);
                    }
                    if ($result === true) {
                        if ($is_write) {
                            Claude_Panel_Bootstrap::audit(
                                'sql_write',
                                $verb . ' (affected=' . $mysqli->affected_rows
                                . ($mysqli->insert_id ? ', insertId=' . $mysqli->insert_id : '')
                                . '): ' . substr($query, 0, 240)
                            );
                        }
                        $res = ['ok' => true, 'affected' => $mysqli->affected_rows, 'insertId' => $mysqli->insert_id];
                    } else {
                        $cols = [];
                        while ($field = $result->fetch_field()) $cols[] = $field->name;

                        // Guardraily proti token-žroutům u čtení:
                        //   maxrows — strop na počet vrácených řádků (default 500, 0 = bez stropu)
                        //   compact — 1 = řádky jako poziční pole (jména sloupců jen jednou)
                        //   maxcell — ořez dlouhých textových buněk na N znaků (0 = bez ořezu)
                        $maxrows = isset($_POST['maxrows']) ? max(0, intval($_POST['maxrows'])) : 500;
                        $compact = !empty($_POST['compact']);
                        $maxcell = isset($_POST['maxcell']) ? max(0, intval($_POST['maxcell'])) : 0;

                        $rows = [];
                        $truncated_rows = false;
                        $trimmed_cells  = 0;
                        while ($r = $result->fetch_assoc()) {
                            if ($maxrows > 0 && count($rows) >= $maxrows) { $truncated_rows = true; break; }
                            if ($maxcell > 0) {
                                foreach ($r as $k => $v) {
                                    if (is_string($v) && strlen($v) > $maxcell) {
                                        $r[$k] = substr($v, 0, $maxcell) . '…[+' . (strlen($v) - $maxcell) . ' B]';
                                        $trimmed_cells++;
                                    }
                                }
                            }
                            $rows[] = $compact ? array_values($r) : $r;
                        }
                        // Pokud jde o SELECT INTO OUTFILE / SELECT FOR UPDATE apod., taky logujeme
                        if ($is_write) {
                            Claude_Panel_Bootstrap::audit('sql_write', $verb . ' (rows=' . count($rows) . '): ' . substr($query, 0, 240));
                        }
                        $res = ['columns' => $cols, 'rows' => $rows, 'count' => count($rows)];
                        if ($compact)        $res['format']        = 'rows';
                        if ($truncated_rows) $res['truncated']     = true;
                        if ($trimmed_cells)  $res['trimmed_cells'] = $trimmed_cells;
                    }
                    $mysqli->close();
                    break;
                }

                default:
                    throw new Exception('Neznámá akce: ' . $action);
            }

            echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public static function handle_download() {
        try {
            $path = self::safe_path($_GET['download']);
            if (!is_file($path)) throw new Exception('Není soubor.');
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            readfile($path);
            Claude_Panel_Bootstrap::audit('download', self::rel_path($path));
        } catch (Throwable $e) {
            http_response_code(400);
            echo $e->getMessage();
        }
    }

    /* ============ RENDER ============ */

    public static function render_login($err = '') {
        $action = esc_url(self::$access_url);
        ?><!doctype html><html lang="cs"><head><meta charset="utf-8">
        <title>AI Panel — přihlášení</title>
        <meta name="robots" content="noindex,nofollow">
        <meta name="claude-panel" content="<?= esc_attr(CP_VERSION) ?>">
        <meta name="claude-panel-help" content="?api=help">
        <meta name="claude-panel-purpose" content="Single-page WP admin panel — file/db/exec API. GET ?api=help for full protocol JSON.">
        <style>
            body{background:#0d1117;color:#c9d1d9;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
            form{background:#161b22;padding:2rem;border-radius:8px;border:1px solid #30363d;width:340px}
            h1{margin:0 0 1rem;font-size:1.2rem}
            input{width:100%;padding:.6rem;background:#0d1117;border:1px solid #30363d;color:#c9d1d9;border-radius:4px;box-sizing:border-box;font-family:monospace}
            button{width:100%;margin-top:.8rem;padding:.6rem;background:#238636;color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:600}
            .err{color:#f85149;margin-top:.5rem;font-size:.9rem}
            .muted{color:#8b949e;margin-top:.8rem;font-size:.8rem;text-align:center}
        </style></head><body>
        <form method="post" action="<?= $action ?>">
            <h1>🛡️ AI Panel</h1>
            <input type="hidden" name="action" value="login">
            <input type="password" name="password" placeholder="Heslo" autofocus autocomplete="off">
            <button type="submit">Přihlásit</button>
            <?php if ($err): ?><div class="err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <div class="muted">Tento přístup je dočasný a po vypršení automaticky končí.</div>
        </form></body></html><?php
    }

    public static function render_ui() {
        $csrf       = self::csrf_token();
        $access_url = self::$access_url;
        $expires_in = max(0, intval(self::$opts['access_expires_at']) - time());
        ?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="claude-panel" content="<?= esc_attr(CP_VERSION) ?>">
<meta name="claude-panel-help" content="?api=help">
<meta name="claude-panel-purpose" content="Single-page WP admin panel — file/db/exec API. GET ?api=help for full protocol JSON.">
<title>AI Panel</title>
<style>
:root{--bg:#0d1117;--bg2:#161b22;--br:#30363d;--fg:#c9d1d9;--mut:#8b949e;--acc:#58a6ff;--ok:#3fb950;--err:#f85149;--warn:#d29922}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,sans-serif;font-size:14px}
header{background:var(--bg2);border-bottom:1px solid var(--br);padding:.6rem 1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
header h1{margin:0;font-size:1rem}
nav{display:flex;gap:.3rem;margin-left:auto;flex-wrap:wrap}
nav button{background:transparent;color:var(--fg);border:1px solid var(--br);padding:.4rem .8rem;border-radius:4px;cursor:pointer}
nav button.active{background:var(--acc);color:#fff;border-color:var(--acc)}
nav button:hover:not(.active){background:#21262d}
.logout{color:var(--err)!important;border-color:var(--err)!important}
.expires{color:var(--warn);font-size:.85rem}
main{padding:1rem}
.panel{display:none}
.panel.active{display:block}
.toolbar{display:flex;gap:.5rem;align-items:center;margin-bottom:.8rem;flex-wrap:wrap}
input,textarea,select{background:var(--bg2);color:var(--fg);border:1px solid var(--br);border-radius:4px;padding:.4rem;font-family:inherit}
input[type=text],input[type=search]{flex:1;min-width:200px;font-family:monospace}
button{background:#21262d;color:var(--fg);border:1px solid var(--br);padding:.4rem .8rem;border-radius:4px;cursor:pointer}
button:hover{background:#30363d}
button.primary{background:var(--ok);border-color:var(--ok);color:#fff}
button.danger{background:transparent;color:var(--err);border-color:var(--err)}
table{width:100%;border-collapse:collapse;background:var(--bg2);border:1px solid var(--br);border-radius:4px;overflow:hidden}
th,td{text-align:left;padding:.4rem .6rem;border-bottom:1px solid var(--br);font-family:monospace;font-size:13px}
th{background:#21262d;color:var(--mut);font-weight:normal}
tr:hover td{background:#1c2128}
.type-dir{color:var(--acc);cursor:pointer;font-weight:600}
.type-file{cursor:pointer}
.type-file:hover,.type-dir:hover{text-decoration:underline}
.muted{color:var(--mut)}
.breadcrumb{font-family:monospace;color:var(--mut);margin-right:.5rem}
.breadcrumb a{color:var(--acc);text-decoration:none;cursor:pointer}
.breadcrumb a:hover{text-decoration:underline}
textarea{width:100%;min-height:60vh;font-family:ui-monospace,'Courier New',monospace;font-size:13px;line-height:1.5}
#terminal-out,#sql-out{background:#010409;color:#c9d1d9;padding:1rem;border-radius:4px;font-family:ui-monospace,monospace;font-size:13px;white-space:pre-wrap;max-height:60vh;overflow:auto;border:1px solid var(--br)}
.info-grid{display:grid;grid-template-columns:200px 1fr;gap:.4rem 1rem;font-family:monospace;font-size:13px}
.info-grid .k{color:var(--mut)}
.info-grid .v{word-break:break-all}
.info-section{background:var(--bg2);border:1px solid var(--br);border-radius:4px;padding:1rem;margin-bottom:1rem}
.info-section h3{margin:0 0 .8rem;font-size:.95rem;color:var(--acc)}
.badge{display:inline-block;padding:.1rem .4rem;border-radius:3px;font-size:11px;font-family:monospace}
.badge.ok{background:rgba(63,185,80,.2);color:var(--ok)}
.badge.warn{background:rgba(210,153,34,.2);color:var(--warn)}
.badge.err{background:rgba(248,81,73,.2);color:var(--err)}
dialog{background:var(--bg2);color:var(--fg);border:1px solid var(--br);border-radius:6px;padding:1rem;max-width:90vw;max-height:90vh}
dialog::backdrop{background:rgba(0,0,0,.6)}
.toast{position:fixed;top:1rem;right:1rem;background:var(--bg2);border:1px solid var(--br);padding:.6rem 1rem;border-radius:4px;z-index:9999;max-width:400px}
.toast.err{border-color:var(--err);color:var(--err)}
.toast.ok{border-color:var(--ok);color:var(--ok)}
kbd{background:#21262d;border:1px solid var(--br);border-radius:3px;padding:.1rem .3rem;font-family:monospace;font-size:11px}
</style>
</head>
<body>
<header>
    <h1>🛡️ AI Panel</h1>
    <span class="muted" id="root-label"></span>
    <span class="expires" id="expires"></span>
    <nav>
        <button data-tab="files" class="active">📁 Soubory</button>
        <button data-tab="editor">📝 Editor</button>
        <button data-tab="terminal">💻 Terminál</button>
        <button data-tab="sql">🗄️ SQL</button>
        <button data-tab="info">ℹ️ WP Info</button>
        <button class="logout" onclick="location.href='<?= esc_url(self::$access_url) ?>?action=logout'">Odhlásit</button>
    </nav>
</header>

<main>

<!-- SOUBORY -->
<section id="files" class="panel active">
    <div class="toolbar">
        <div class="breadcrumb" id="breadcrumb"></div>
        <button onclick="fs.refresh()">🔄</button>
        <button onclick="fs.up()">⬆️ Nahoru</button>
        <button onclick="fs.mkdir()">📁+ Nová složka</button>
        <button onclick="fs.newFile()">📄+ Nový soubor</button>
        <button onclick="fs.uploadDialog()">⬆️ Upload</button>
        <input type="search" id="filter" placeholder="Filtr v tomto adresáři...">
    </div>
    <table>
        <thead><tr><th style="width:50%">Název</th><th>Velikost</th><th>Práva</th><th>Změněno</th><th>Akce</th></tr></thead>
        <tbody id="files-body"></tbody>
    </table>
</section>

<!-- EDITOR -->
<section id="editor" class="panel">
    <div class="toolbar">
        <span class="muted" id="editor-path">(žádný soubor)</span>
        <span style="flex:1"></span>
        <button class="primary" onclick="ed.save()">💾 Uložit <kbd>Ctrl+S</kbd></button>
        <button onclick="ed.reload()">🔄 Znovu načíst</button>
    </div>
    <textarea id="editor-area" spellcheck="false" placeholder="Klikni na soubor v průzkumníku..."></textarea>
</section>

<!-- TERMINÁL -->
<section id="terminal" class="panel">
    <div class="toolbar">
        <span class="muted">CWD:</span>
        <input type="text" id="term-cwd" value=".">
        <input type="text" id="term-cmd" placeholder="php -v    |    wp plugin list    |    dir" style="flex:2">
        <button class="primary" onclick="term.run()">▶️ Spustit</button>
        <button onclick="document.getElementById('terminal-out').textContent=''">Vyčistit</button>
    </div>
    <div id="terminal-out">Zadej příkaz výše. Enter spouští. Tip: použij "wp" pro WP-CLI pokud je nainstalováno.</div>
</section>

<!-- SQL -->
<section id="sql" class="panel">
    <div class="toolbar">
        <button class="primary" onclick="sql.run()">▶️ Spustit <kbd>Ctrl+Enter</kbd></button>
        <button onclick="sql.insertTemplate('SELECT * FROM <?= esc_js($GLOBALS['wpdb']->prefix) ?>options LIMIT 20')">📋 Options</button>
        <button onclick="sql.insertTemplate('SELECT * FROM <?= esc_js($GLOBALS['wpdb']->prefix) ?>users')">📋 Users</button>
        <button onclick="sql.insertTemplate('SHOW TABLES')">📋 Tables</button>
    </div>
    <textarea id="sql-query" style="min-height:150px" placeholder="SELECT * FROM <?= esc_js($GLOBALS['wpdb']->prefix) ?>posts WHERE post_status='publish' LIMIT 10">SELECT option_name, option_value FROM <?= esc_js($GLOBALS['wpdb']->prefix) ?>options WHERE option_name IN ('siteurl','home','blogname','admin_email','template') LIMIT 20;</textarea>
    <div style="margin-top:1rem" id="sql-out">Napiš SQL dotaz a spusť.</div>
</section>

<!-- INFO -->
<section id="info" class="panel">
    <div class="toolbar">
        <button onclick="info.load()">🔄 Načíst</button>
    </div>
    <div id="info-body">Klikni na „Načíst".</div>
</section>

</main>

<dialog id="upload-dialog">
    <h3 style="margin-top:0">Upload souborů</h3>
    <form id="upload-form" enctype="multipart/form-data">
        <p class="muted">Cíl: <span id="upload-target"></span></p>
        <input type="file" name="files[]" multiple required>
        <div style="margin-top:1rem;display:flex;gap:.5rem;justify-content:flex-end">
            <button type="button" onclick="document.getElementById('upload-dialog').close()">Zrušit</button>
            <button type="submit" class="primary">Nahrát</button>
        </div>
    </form>
</dialog>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const SELF = <?= json_encode($access_url) ?>;
const EXPIRES_IN = <?= intval($expires_in) ?>;

function b64(s) { return btoa(unescape(encodeURIComponent(String(s)))); }
const B64_FIELDS = { sql:{query:'q64'}, exec:{cmd:'cmd64'}, write:{content:'c64'} };

async function api(action, data = {}, isUpload = false) {
    const url = SELF + '?api=' + encodeURIComponent(action);
    const opts = { method: 'POST', headers: { 'X-CSRF-Token': CSRF } };
    if (isUpload) {
        data.append('csrf', CSRF);
        opts.body = data;
    } else {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        const rewrite = B64_FIELDS[action] || {};
        for (const k in data) {
            if (rewrite[k]) fd.append(rewrite[k], b64(data[k]));
            else fd.append(k, data[k]);
        }
        opts.body = fd;
    }
    const r = await fetch(url, opts);
    const j = await r.json().catch(() => ({ error: 'Chyba parsování odpovědi' }));
    if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
}

function toast(msg, type = '') {
    const d = document.createElement('div');
    d.className = 'toast ' + type;
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(() => d.remove(), 3500);
}

function fmtSize(n){if(n==null)return'';const u=['B','KB','MB','GB'];let i=0;while(n>=1024&&i<u.length-1){n/=1024;i++;}return n.toFixed(i?1:0)+' '+u[i];}
function fmtDate(t){if(!t)return'';return new Date(t*1000).toLocaleString('cs-CZ');}

// Expiry countdown
let expiresLeft = EXPIRES_IN;
function fmtCountdown(s){if(s<=0)return'VYPRŠEL — odhlas se a kontaktuj admina';const h=Math.floor(s/3600);const m=Math.floor((s%3600)/60);return 'Vyprší za ' + (h>0?h+' h ':'') + m + ' min';}
function tickExpiry(){document.getElementById('expires').textContent=fmtCountdown(expiresLeft);if(expiresLeft<=0)return;expiresLeft--;}
tickExpiry(); setInterval(tickExpiry, 1000);

document.querySelectorAll('nav button[data-tab]').forEach(b => {
    b.onclick = () => {
        document.querySelectorAll('nav button[data-tab]').forEach(x => x.classList.remove('active'));
        document.querySelectorAll('.panel').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        document.getElementById(b.dataset.tab).classList.add('active');
    };
});

const fs = {
    cwd: '.',
    async load(p) {
        try {
            const r = await api('list', { path: p });
            this.cwd = r.path;
            document.getElementById('root-label').textContent = '📂 ' + r.path;
            this.renderBreadcrumb(r.path);
            this.renderTable(r.items);
        } catch (e) { toast(e.message, 'err'); }
    },
    renderBreadcrumb(p) {
        const parts = p === '.' ? [] : p.split('/');
        const el = document.getElementById('breadcrumb');
        el.innerHTML = '<a onclick="fs.load(\'.\')">🏠 root</a>';
        let acc = '';
        parts.forEach(seg => {
            acc = acc ? acc + '/' + seg : seg;
            el.innerHTML += ' / <a onclick="fs.load(\'' + acc.replace(/'/g, "\\'") + '\')">' + seg + '</a>';
        });
    },
    renderTable(items) {
        const tb = document.getElementById('files-body');
        tb.innerHTML = '';
        const filter = document.getElementById('filter').value.toLowerCase();
        items.filter(i => !filter || i.name.toLowerCase().includes(filter)).forEach(it => {
            const tr = document.createElement('tr');
            const nameCls = it.type === 'dir' ? 'type-dir' : 'type-file';
            const icon = it.type === 'dir' ? '📁' : '📄';
            tr.innerHTML = `
                <td><span class="${nameCls}" data-p="${it.path.replace(/"/g, '&quot;')}">${icon} ${it.name}</span></td>
                <td>${it.type === 'dir' ? '' : fmtSize(it.size)}</td>
                <td>${it.perms}</td>
                <td class="muted">${fmtDate(it.mtime)}</td>
                <td>
                    ${it.type === 'file' ? `<button onclick="fs.download('${it.path.replace(/'/g, "\\'")}')">⬇️</button>` : ''}
                    <button onclick="fs.rename('${it.path.replace(/'/g, "\\'")}')">✏️</button>
                    <button class="danger" onclick="fs.del('${it.path.replace(/'/g, "\\'")}')">🗑️</button>
                </td>`;
            tr.querySelector('.' + nameCls).onclick = () => {
                if (it.type === 'dir') fs.load(it.path);
                else ed.open(it.path);
            };
            tb.appendChild(tr);
        });
    },
    refresh() { this.load(this.cwd); },
    up() { const parts = this.cwd.split('/'); parts.pop(); this.load(parts.join('/') || '.'); },
    async mkdir() { const n=prompt('Název nové složky:'); if(!n)return; try{await api('mkdir',{path:this.cwd+'/'+n}); this.refresh(); toast('Složka vytvořena','ok');}catch(e){toast(e.message,'err');} },
    async newFile(){ const n=prompt('Název nového souboru:'); if(!n)return; try{await api('write',{path:this.cwd+'/'+n,content:''}); this.refresh(); ed.open(this.cwd+'/'+n);}catch(e){toast(e.message,'err');} },
    async del(p) { if(!confirm('Opravdu smazat: '+p+' ?'))return; try{await api('delete',{path:p}); this.refresh(); toast('Smazáno','ok');}catch(e){toast(e.message,'err');} },
    async rename(p){ const parts=p.split('/'); const cur=parts.pop(); const n=prompt('Nový název:',cur); if(!n||n===cur)return; const to=(parts.length?parts.join('/')+'/':'')+n; try{await api('rename',{from:p,to:to}); this.refresh(); toast('Přejmenováno','ok');}catch(e){toast(e.message,'err');} },
    download(p) { window.location = SELF + '?download=' + encodeURIComponent(p); },
    uploadDialog(){ document.getElementById('upload-target').textContent=this.cwd; document.getElementById('upload-dialog').showModal(); },
};
document.getElementById('filter').oninput = () => fs.refresh();
document.getElementById('upload-form').onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('path', fs.cwd);
    try { await api('upload', fd, true); document.getElementById('upload-dialog').close(); fs.refresh(); toast('Nahráno','ok'); }
    catch (err) { toast(err.message, 'err'); }
};

const ed = {
    path: null,
    async open(p) { try { const r = await api('read', { path: p }); this.path = r.path; document.getElementById('editor-path').textContent='📝 '+r.path+'  ('+fmtSize(r.size)+')'; document.getElementById('editor-area').value = r.content; document.querySelector('nav button[data-tab=editor]').click(); } catch (e) { toast(e.message, 'err'); } },
    async save() { if(!this.path) return toast('Žádný soubor není otevřen','err'); try { const c = document.getElementById('editor-area').value; await api('write', { path: this.path, content: c }); toast('Uloženo: '+this.path, 'ok'); } catch (e) { toast(e.message,'err'); } },
    async reload() { if(!this.path) return; this.open(this.path); },
};
document.getElementById('editor-area').addEventListener('keydown', e => { if((e.ctrlKey||e.metaKey) && e.key === 's'){ e.preventDefault(); ed.save(); } });

const term = {
    async run() {
        const cmd = document.getElementById('term-cmd').value;
        const cwd = document.getElementById('term-cwd').value;
        const out = document.getElementById('terminal-out');
        out.textContent += '\n$ ' + cmd + '\n';
        try { const r = await api('exec', { cmd, cwd }); out.textContent += r.output + '\n[rc='+r.rc+']\n'; }
        catch (e) { out.textContent += '[ERR] '+e.message+'\n'; }
        out.scrollTop = out.scrollHeight;
    },
};
document.getElementById('term-cmd').addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); term.run(); } });

const sql = {
    async run() {
        const q = document.getElementById('sql-query').value;
        const out = document.getElementById('sql-out');
        try {
            const r = await api('sql', { query: q });
            if (r.rows) {
                if (!r.rows.length) { out.innerHTML = '<span class="muted">0 řádků.</span>'; return; }
                let h = '<table><thead><tr>'+r.columns.map(c=>'<th>'+c+'</th>').join('')+'</tr></thead><tbody>';
                r.rows.forEach(row => { h += '<tr>'+r.columns.map(c=>'<td>'+(row[c]===null?'<span class="muted">NULL</span>':String(row[c]).replace(/[<>&]/g,x=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[x])))+'</td>').join('')+'</tr>'; });
                h += '</tbody></table><p class="muted">'+r.count+' řádků</p>';
                out.innerHTML = h;
            } else {
                out.innerHTML = '<span style="color:var(--ok)">✓ OK. Dotčeno řádků: '+r.affected+(r.insertId?', insertId='+r.insertId:'')+'</span>';
            }
        } catch (e) { out.innerHTML = '<span style="color:var(--err)">'+e.message+'</span>'; }
    },
    insertTemplate(q) { document.getElementById('sql-query').value = q; },
};
document.getElementById('sql-query').addEventListener('keydown', e => { if((e.ctrlKey||e.metaKey) && e.key === 'Enter'){ e.preventDefault(); sql.run(); } });

const info = {
    async load() {
        try {
            const r = await api('wpinfo');
            let h = '';
            h += '<div class="info-section"><h3>🌐 WordPress</h3><div class="info-grid">';
            h += row('WP verze', r.wp_version);
            h += row('Site URL', r.site_url);
            h += row('Home URL', r.home_url);
            h += row('Locale', r.locale);
            h += row('Multisite', r.multisite);
            h += '</div></div>';

            if (r.db) {
                h += '<div class="info-section"><h3>🗄️ Databáze</h3><div class="info-grid">';
                for (const k in r.db) h += row(k, r.db[k] || '<span class="muted">—</span>');
                h += '</div></div>';
            }

            h += '<div class="info-section"><h3>⚙️ Server</h3><div class="info-grid">';
            h += row('PHP', r.php_version);
            h += row('OS', r.os);
            h += row('Server', r.server);
            h += row('Doc root', r.doc_root);
            h += row('Memory limit', r.memory_limit);
            h += row('Upload max', r.upload_max);
            h += row('Post max', r.post_max);
            h += row('Max exec time', r.max_exec_time + 's');
            h += row('Disk volný', fmtSize(r.disk_free));
            h += row('Disk celkem', fmtSize(r.disk_total));
            h += row('exec()', r.exec_enabled ? '<span class="badge ok">povoleno</span>' : '<span class="badge err">zakázáno</span>');
            h += '</div></div>';

            document.getElementById('info-body').innerHTML = h;
        } catch (e) { toast(e.message, 'err'); }
        function row(k, v) { return '<div class="k">'+k+'</div><div class="v">'+v+'</div>'; }
    },
};

fs.load('.');
info.load();
</script>
</body>
</html><?php
    }
}
