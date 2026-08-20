<?php
/**
 * AI Panel — ZÁCHRANNÝ ENDPOINT (standalone, mimo WordPress)
 * ---------------------------------------------------------------
 * Tento soubor se NIKDY nenačítá přes WordPress. Přistupuje se na něj přímo
 * URL, takže funguje i tehdy, když je WP kompletně rozbitý (bílá obrazovka,
 * fatální chyba v pluginu / theme / wp-config). Jediný účel: přihlásit se a
 * opravit / smazat soubor, který web shodil — abys nemusel na FTP.
 *
 * Rozsah je záměrně omezen POUZE na správu souborů (list/read/write/patch/
 * delete/rename/mkdir/download). Žádné SQL, žádný shell — menší škody, kdyby
 * endpoint někdo našel.
 *
 * Úspora tokenů: pro úpravu existujícího souboru použij ?api=patch (find/replace,
 * pole find|f64 + replace|r64, volitelně all=1/force=1) — pošle se jen měněný
 * úsek místo celého souboru. ?api=read umí start_line/end_line pro částečné čtení.
 *
 * Konfigurace (heslo hash, root, IP whitelist) se čte z config.php ve stejné
 * složce. Soubor generuje a spravuje plugin AI Panel ve WP adminu.
 *
 * @version 1.1.0
 */

declare(strict_types=1);
error_reporting(E_ALL);
@ini_set('display_errors', '0');

/* ============ KONFIGURACE ============ */

$CFG = [
    'hash'          => '',
    'root'          => '',
    'allowed_ips'   => '',
    'require_https' => false,
    'label'         => '',
    'created'       => 0,
];
$cfg_file = __DIR__ . '/config.php';
if (is_file($cfg_file)) {
    $loaded = include $cfg_file;
    if (is_array($loaded)) {
        $CFG = array_merge($CFG, $loaded);
    }
}

// Root: primárně z configu (uloží ho plugin = přesné ABSPATH). Fallback hledá
// wp-load.php / wp-config.php / wp-includes směrem nahoru, takže funguje bez
// ohledu na to, kam je endpoint umístěn.
$ROOT = (string) $CFG['root'];
if ($ROOT === '') {
    $d = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $d = dirname($d);
        if (is_file($d . '/wp-load.php') || is_file($d . '/wp-config.php') || is_dir($d . '/wp-includes')) {
            $ROOT = $d;
            break;
        }
    }
    if ($ROOT === '') $ROOT = dirname(__DIR__, 3);
}
$ROOT = rtrim(str_replace('\\', '/', $ROOT), '/') . '/';

// Bez hesla se endpoint chová jako neexistující (nikdy neodhaluj, co je zač).
if ($CFG['hash'] === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

// Tento skript i jeho konfigurace jsou tabu — nedovol si přepsat/smazat sám
// sebe a přijít o přístup.
$SELF_DIR = rtrim(str_replace('\\', '/', __DIR__), '/') . '/';

/* ============ HELPERS ============ */

function client_ip(): string {
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '?';
}

function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return false;
}

function rescue_log(string $event, string $detail = ''): void {
    // Log má příponu .php a guard řádek → přímý HTTP přístup skončí exit()
    // (nic neunikne ani na nginx/php -S, kde .htaccess neplatí).
    $f = __DIR__ . '/rescue.log.php';
    if (!is_file($f)) {
        @file_put_contents($f, "<?php http_response_code(404); exit; ?>\n", LOCK_EX);
    }
    $line = sprintf("[%s] %s\t%s\t%s\n", date('Y-m-d H:i:s'), client_ip(), $event, $detail);
    @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_php_path(string $path): bool {
    return (bool) preg_match('/\.(php|phtml|php[0-9]|phps|inc)$/i', $path);
}

/** In-process kontrola PHP syntaxe bez spuštění kódu. Null = OK. */
function php_syntax_error(string $content): ?string {
    if (!defined('TOKEN_PARSE')) return null;
    try {
        token_get_all($content, TOKEN_PARSE);
        return null;
    } catch (\ParseError $e) {
        return $e->getMessage() . ' (řádek ' . $e->getLine() . ')';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

/** Bezpečně přemapuje relativní cestu do rootu; brání path traversal. */
function safe_path(string $rel, string $root, bool $must_exist = true): string {
    global $SELF_DIR;
    $rel  = ltrim(str_replace('\\', '/', $rel), '/');
    $cand = $root . $rel;

    if ($must_exist) {
        $full = realpath($cand);
        if ($full === false) throw new RuntimeException('Cesta neexistuje: ' . $rel);
    } else {
        $parent = realpath(dirname($cand));
        if ($parent === false) throw new RuntimeException('Rodičovský adresář neexistuje.');
        $rootReal = realpath($root);
        if (strpos($parent . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR) !== 0 && $parent !== $rootReal) {
            throw new RuntimeException('Cesta mimo root.');
        }
        $full = $cand;
    }

    if ($must_exist) {
        $rootReal = realpath($root);
        if (strpos($full . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR) !== 0 && $full !== $rootReal) {
            throw new RuntimeException('Cesta mimo root.');
        }
    }

    // Ochrana samotného záchranného endpointu (config, log, sám sebe).
    $norm = rtrim(str_replace('\\', '/', $full), '/');
    if (strpos($norm . '/', $SELF_DIR) === 0) {
        throw new RuntimeException('Do složky záchranného endpointu nelze zasahovat.');
    }
    return $full;
}

function rel_path(string $abs, string $root): string {
    $r = realpath($root) ?: $root;
    $a = realpath($abs) ?: $abs;
    $rel = str_replace('\\', '/', substr($a, strlen($r)));
    return ltrim($rel, '/') ?: '.';
}

function backup_root(string $root): string {
    $dir = $root . 'wp-content/claude-panel-backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess',
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\nOptions -Indexes\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

function backup_existing(string $abs, string $action, string $root): ?string {
    if (!is_file($abs)) return null;
    $rel   = rel_path($abs, $root);
    $stamp = date('Y-m-d_Hi');
    $batch = backup_root($root) . '/' . $stamp;
    $dest  = $batch . '/' . $rel;
    @mkdir(dirname($dest), 0755, true);
    @copy($abs, $dest);
    @file_put_contents($batch . '/zmeny.txt',
        sprintf("[%s] %-16s %s (rescue)\n", date('Y-m-d H:i:s'), strtoupper($action), $rel),
        FILE_APPEND);
    return $dest;
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($dir);
}

/* ============ SESSION ============ */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('cprescue');
    session_set_cookie_params([
        'lifetime' => 3600,
        'path'     => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    @session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void {
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) $t)) {
        json_out(['error' => 'Neplatný CSRF token'], 403);
    }
}

function is_logged_in(): bool {
    return !empty($_SESSION['auth']) && (int) ($_SESSION['auth_time'] ?? 0) > time() - 3600;
}

/* ============ GATEKEEPING ============ */

// IP whitelist.
$allowed = array_filter(array_map('trim', explode(',', (string) $CFG['allowed_ips'])));
if ($allowed && !in_array(client_ip(), $allowed, true)) {
    rescue_log('ip_blocked', client_ip());
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

// Vynucené HTTPS.
if (!empty($CFG['require_https']) && !is_https()) {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''));
    exit;
}

$SELF_URL = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');

/* ============ LOGOUT ============ */

if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    @session_destroy();
    header('Location: ' . $SELF_URL);
    exit;
}

/* ============ LOGIN ============ */

$login_error = '';
if (($_POST['action'] ?? '') === 'login') {
    // Rate limit (per IP, souborový). Soubor má guard řádek jako log výše.
    $rl_file = __DIR__ . '/rescue.rl.php';
    $rl = [];
    if (is_file($rl_file)) {
        $raw  = (string) file_get_contents($rl_file);
        $nl   = strpos($raw, "\n");
        $json = $nl === false ? '' : substr($raw, $nl + 1);
        $rl   = json_decode($json, true);
        if (!is_array($rl)) $rl = [];
    }
    $ip  = client_ip();
    $rec = $rl[$ip] ?? ['count' => 0, 'last' => 0];
    if ($rec['count'] >= 5 && time() - $rec['last'] < 900) {
        rescue_log('rate_limited', $ip);
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Příliš mnoho pokusů. Počkej ' . (900 - (time() - $rec['last'])) . ' s.';
        exit;
    }

    $pw = (string) ($_POST['password'] ?? '');
    if ($pw !== '' && password_verify($pw, (string) $CFG['hash'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['auth_time'] = time();
        unset($rl[$ip]);
        @file_put_contents($rl_file, "<?php exit; ?>\n" . json_encode($rl), LOCK_EX);
        rescue_log('login_ok', $ip);
        header('Location: ' . $SELF_URL);
        exit;
    } else {
        $rec['count']++;
        $rec['last'] = time();
        $rl[$ip] = $rec;
        @file_put_contents($rl_file, "<?php exit; ?>\n" . json_encode($rl), LOCK_EX);
        rescue_log('login_fail', $ip);
        $login_error = 'Nesprávné heslo.';
    }
}

/* ============ LOGIN FORM ============ */

if (!is_logged_in()) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    ?><!doctype html><html lang="cs"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Záchranný přístup</title><meta name="robots" content="noindex,nofollow">
    <style>
        body{background:#0d1117;color:#c9d1d9;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
        form{background:#161b22;padding:2rem;border-radius:8px;border:1px solid #30363d;width:340px}
        h1{margin:0 0 .3rem;font-size:1.15rem}p.sub{margin:0 0 1rem;color:#8b949e;font-size:.8rem}
        input{width:100%;padding:.6rem;background:#0d1117;border:1px solid #30363d;color:#c9d1d9;border-radius:4px;box-sizing:border-box;font-family:monospace}
        button{width:100%;margin-top:.8rem;padding:.6rem;background:#b45309;color:#fff;border:0;border-radius:4px;cursor:pointer;font-weight:600}
        .err{color:#f85149;margin-top:.5rem;font-size:.9rem}
    </style></head><body>
    <form method="post">
        <h1>🛟 Záchranný přístup</h1>
        <p class="sub">Nezávislý na WordPressu. Jen správa souborů.</p>
        <input type="hidden" name="action" value="login">
        <input type="password" name="password" placeholder="Záchranné heslo" autofocus autocomplete="off">
        <button type="submit">Přihlásit</button>
        <?php if ($login_error): ?><div class="err"><?= htmlspecialchars($login_error, ENT_QUOTES) ?></div><?php endif; ?>
    </form></body></html><?php
    exit;
}

$_SESSION['auth_time'] = time();

/* ============ API (file-only) ============ */

if (isset($_GET['api'])) {
    check_csrf();
    try {
        $action = (string) $_GET['api'];
        switch ($action) {

            case 'list': {
                $path = safe_path($_POST['path'] ?? '.', $ROOT);
                if (!is_dir($path)) throw new RuntimeException('Není adresář.');
                $items = [];
                foreach (scandir($path) as $f) {
                    if ($f === '.' || $f === '..') continue;
                    $full  = $path . '/' . $f;
                    $isDir = is_dir($full);
                    $items[] = [
                        'name'  => $f,
                        'path'  => rel_path($full, $ROOT),
                        'type'  => $isDir ? 'dir' : 'file',
                        'size'  => $isDir ? null : @filesize($full),
                        'mtime' => @filemtime($full),
                    ];
                }
                usort($items, fn($a, $b) => [$a['type'] === 'file', strtolower($a['name'])] <=> [$b['type'] === 'file', strtolower($b['name'])]);
                json_out(['items' => $items, 'path' => rel_path($path, $ROOT)]);
            }

            case 'read': {
                $path = safe_path((string) ($_POST['path'] ?? ''), $ROOT);
                if (!is_file($path)) throw new RuntimeException('Není soubor.');
                $size = filesize($path);
                if ($size > 5 * 1024 * 1024) throw new RuntimeException('Soubor > 5 MB, stáhni jej.');
                $raw = file_get_contents($path);
                // Volitelné částečné čtení po řádcích (start_line/end_line, 1-based včetně).
                $start = isset($_POST['start_line']) ? max(1, (int) $_POST['start_line']) : 0;
                $end   = isset($_POST['end_line'])   ? (int) $_POST['end_line']           : 0;
                if ($start > 0 || $end > 0) {
                    $lines = preg_split('/\r\n|\r|\n/', $raw);
                    $total = count($lines);
                    $from  = $start > 0 ? min($start, $total) : 1;
                    $to    = $end   > 0 ? min($end, $total)   : $total;
                    if ($to < $from) $to = $from;
                    json_out([
                        'content'     => implode("\n", array_slice($lines, $from - 1, $to - $from + 1)),
                        'path'        => rel_path($path, $ROOT),
                        'size'        => $size,
                        'total_lines' => $total,
                        'start_line'  => $from,
                        'end_line'    => $to,
                        'partial'     => true,
                    ]);
                }
                json_out(['content' => $raw, 'path' => rel_path($path, $ROOT), 'size' => $size]);
            }

            case 'write': {
                $path    = safe_path((string) ($_POST['path'] ?? ''), $ROOT, false);
                $content = isset($_POST['c64']) ? base64_decode((string) $_POST['c64'], true) : (string) ($_POST['content'] ?? '');
                if ($content === false) throw new RuntimeException('Neplatný base64 obsah.');
                if (is_php_path($path) && empty($_POST['force'])) {
                    $lint = php_syntax_error($content);
                    if ($lint !== null) {
                        rescue_log('write_blocked', rel_path($path, $ROOT) . ' — ' . substr($lint, 0, 200));
                        json_out(['error' => 'PHP syntax error — zápis zablokován: ' . $lint, 'lint_error' => $lint, 'hint' => 'Oprav syntaxi nebo pošli force=1.'], 422);
                    }
                }
                $backup = backup_existing($path, 'write', $ROOT);
                if (file_put_contents($path, $content) === false) throw new RuntimeException('Zápis selhal.');
                rescue_log('write', rel_path($path, $ROOT) . ' (' . strlen($content) . ' B)' . ($backup ? ' [záloha]' : ''));
                json_out(['ok' => true, 'path' => rel_path($path, $ROOT), 'size' => strlen($content), 'backup' => (bool) $backup]);
            }

            case 'patch': {
                // Cílená úprava (find/replace) — posílá se jen měněný úsek, ne celý
                // soubor → řádově méně tokenů. Pole: path, find|f64, replace|r64, [all], [force].
                $path = safe_path((string) ($_POST['path'] ?? ''), $ROOT);
                if (!is_file($path)) throw new RuntimeException('Není soubor.');
                $find = isset($_POST['f64']) ? base64_decode((string) $_POST['f64'], true) : ($_POST['find'] ?? null);
                $replace = isset($_POST['r64']) ? base64_decode((string) $_POST['r64'], true) : (string) ($_POST['replace'] ?? '');
                if ($find === null || $find === false || $find === '') throw new RuntimeException('Chybí/neplatné pole find (nebo f64).');
                if ($replace === false) throw new RuntimeException('Neplatný base64 v r64.');
                $original    = file_get_contents($path);
                $occurrences = substr_count($original, (string) $find);
                if ($occurrences === 0) {
                    json_out(['error' => 'Hledaný úsek (find) se nenašel.', 'occurrences' => 0], 422);
                }
                $all = !empty($_POST['all']);
                if ($occurrences > 1 && !$all) {
                    json_out(['error' => 'Úsek (find) není jednoznačný — nalezen ' . $occurrences . '×. Rozšiř kontext nebo pošli all=1.', 'occurrences' => $occurrences], 422);
                }
                if ($all) {
                    $new = str_replace((string) $find, (string) $replace, $original);
                    $replaced = $occurrences;
                } else {
                    $pos = strpos($original, (string) $find);
                    $new = substr_replace($original, (string) $replace, $pos, strlen((string) $find));
                    $replaced = 1;
                }
                if (is_php_path($path) && empty($_POST['force'])) {
                    $lint = php_syntax_error($new);
                    if ($lint !== null) {
                        rescue_log('write_blocked', rel_path($path, $ROOT) . ' [patch] — ' . substr($lint, 0, 200));
                        json_out(['error' => 'PHP syntax error po náhradě — zápis zablokován: ' . $lint, 'lint_error' => $lint, 'hint' => 'Oprav náhradu nebo pošli force=1.'], 422);
                    }
                }
                $backup = backup_existing($path, 'patch', $ROOT);
                if (file_put_contents($path, $new) === false) throw new RuntimeException('Zápis selhal.');
                rescue_log('write', rel_path($path, $ROOT) . ' [patch ×' . $replaced . ', ' . strlen($new) . ' B]' . ($backup ? ' [záloha]' : ''));
                json_out(['ok' => true, 'path' => rel_path($path, $ROOT), 'replaced' => $replaced, 'size' => strlen($new), 'backup' => (bool) $backup]);
            }

            case 'delete': {
                $path = safe_path((string) ($_POST['path'] ?? ''), $ROOT);
                if ($path === realpath($ROOT)) throw new RuntimeException('Nelze smazat root.');
                $backup = is_file($path) ? backup_existing($path, 'delete', $ROOT) : null;
                is_dir($path) ? rrmdir($path) : unlink($path);
                rescue_log('delete', rel_path($path, $ROOT) . ($backup ? ' [záloha]' : ''));
                json_out(['ok' => true, 'backup' => (bool) $backup]);
            }

            case 'rename': {
                $from = safe_path((string) ($_POST['from'] ?? ''), $ROOT);
                $to   = safe_path((string) ($_POST['to'] ?? ''), $ROOT, false);
                $backup = is_file($to) ? backup_existing($to, 'rename-overwrite', $ROOT) : null;
                if (!rename($from, $to)) throw new RuntimeException('Přejmenování selhalo.');
                rescue_log('rename', rel_path($from, $ROOT) . ' -> ' . rel_path($to, $ROOT));
                json_out(['ok' => true, 'backup' => (bool) $backup]);
            }

            case 'mkdir': {
                $path = safe_path((string) ($_POST['path'] ?? ''), $ROOT, false);
                if (!mkdir($path, 0755, true) && !is_dir($path)) throw new RuntimeException('mkdir selhal.');
                rescue_log('mkdir', rel_path($path, $ROOT));
                json_out(['ok' => true, 'path' => rel_path($path, $ROOT)]);
            }

            default:
                throw new RuntimeException('Neznámá / nepovolená akce: ' . $action);
        }
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 400);
    }
}

/* ============ DOWNLOAD ============ */

if (isset($_GET['download'])) {
    try {
        $path = safe_path((string) $_GET['download'], $ROOT);
        if (!is_file($path)) throw new RuntimeException('Není soubor.');
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        rescue_log('download', rel_path($path, $ROOT));
    } catch (Throwable $e) {
        http_response_code(400);
        echo $e->getMessage();
    }
    exit;
}

/* ============ UI ============ */

$csrf = csrf_token();
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🛟 Záchranný přístup</title>
<style>
:root{--bg:#0d1117;--bg2:#161b22;--br:#30363d;--fg:#c9d1d9;--mut:#8b949e;--acc:#58a6ff;--ok:#3fb950;--err:#f85149;--warn:#d29922}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,sans-serif;font-size:14px}
header{background:var(--bg2);border-bottom:1px solid var(--br);padding:.6rem 1rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
header h1{margin:0;font-size:1rem}
nav{display:flex;gap:.3rem;margin-left:auto;flex-wrap:wrap}
button{background:#21262d;color:var(--fg);border:1px solid var(--br);padding:.4rem .8rem;border-radius:4px;cursor:pointer}
button:hover{background:#30363d}
button.primary{background:var(--ok);border-color:var(--ok);color:#fff}
button.danger{background:transparent;color:var(--err);border-color:var(--err)}
.logout{color:var(--err)!important;border-color:var(--err)!important}
main{padding:1rem}
.panel{display:none}.panel.active{display:block}
.toolbar{display:flex;gap:.5rem;align-items:center;margin-bottom:.8rem;flex-wrap:wrap}
input,textarea{background:var(--bg2);color:var(--fg);border:1px solid var(--br);border-radius:4px;padding:.4rem;font-family:monospace}
input[type=search]{flex:1;min-width:200px}
table{width:100%;border-collapse:collapse;background:var(--bg2);border:1px solid var(--br);border-radius:4px;overflow:hidden}
th,td{text-align:left;padding:.4rem .6rem;border-bottom:1px solid var(--br);font-family:monospace;font-size:13px}
th{background:#21262d;color:var(--mut);font-weight:normal}
tr:hover td{background:#1c2128}
.type-dir{color:var(--acc);cursor:pointer;font-weight:600}
.type-file{cursor:pointer}
.breadcrumb{font-family:monospace;color:var(--mut)}
.breadcrumb a{color:var(--acc);text-decoration:none;cursor:pointer}
textarea{width:100%;min-height:62vh;font-family:ui-monospace,monospace;font-size:13px;line-height:1.5}
.banner{background:rgba(180,83,9,.15);border:1px solid var(--warn);color:var(--warn);padding:.5rem .8rem;border-radius:4px;margin-bottom:1rem;font-size:.85rem}
.toast{position:fixed;top:1rem;right:1rem;background:var(--bg2);border:1px solid var(--br);padding:.6rem 1rem;border-radius:4px;z-index:99;max-width:420px}
.toast.err{border-color:var(--err);color:var(--err)}.toast.ok{border-color:var(--ok);color:var(--ok)}
.muted{color:var(--mut)}
</style>
</head>
<body>
<header>
    <h1>🛟 Záchranný přístup</h1>
    <span class="muted" id="root-label"></span>
    <nav>
        <button data-tab="files" class="active">📁 Soubory</button>
        <button data-tab="editor">📝 Editor</button>
        <button class="logout" onclick="location.href='?action=logout'">Odhlásit</button>
    </nav>
</header>
<main>
    <div class="banner">⚠️ Nouzový režim mimo WordPress — jen správa souborů. Uprav/smaž soubor, který web shodil, pak odhlas.</div>

    <section id="files" class="panel active">
        <div class="toolbar">
            <div class="breadcrumb" id="breadcrumb"></div>
            <button onclick="fs.refresh()">🔄</button>
            <button onclick="fs.up()">⬆️ Nahoru</button>
            <button onclick="fs.mkdir()">📁+ Složka</button>
            <button onclick="fs.newFile()">📄+ Soubor</button>
            <input type="search" id="filter" placeholder="Filtr...">
        </div>
        <table>
            <thead><tr><th style="width:55%">Název</th><th>Velikost</th><th>Změněno</th><th>Akce</th></tr></thead>
            <tbody id="files-body"></tbody>
        </table>
    </section>

    <section id="editor" class="panel">
        <div class="toolbar">
            <span class="muted" id="editor-path">(žádný soubor)</span>
            <span style="flex:1"></span>
            <button class="primary" onclick="ed.save()">💾 Uložit</button>
            <button onclick="ed.reload()">🔄 Znovu</button>
        </div>
        <textarea id="editor-area" spellcheck="false" placeholder="Klikni na soubor..."></textarea>
    </section>
</main>
<script>
const CSRF = <?= json_encode($csrf) ?>;
async function api(action, data = {}) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    for (const k in data) fd.append(k, data[k]);
    const r = await fetch('?api=' + encodeURIComponent(action), { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd });
    const j = await r.json().catch(() => ({ error: 'Chyba odpovědi' }));
    if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
}
function toast(m, t = '') { const d = document.createElement('div'); d.className = 'toast ' + t; d.textContent = m; document.body.appendChild(d); setTimeout(() => d.remove(), 3800); }
function fmtSize(n){if(n==null)return'';const u=['B','KB','MB','GB'];let i=0;while(n>=1024&&i<3){n/=1024;i++;}return n.toFixed(i?1:0)+' '+u[i];}
function fmtDate(t){return t?new Date(t*1000).toLocaleString('cs-CZ'):'';}
document.querySelectorAll('nav button[data-tab]').forEach(b => b.onclick = () => {
    document.querySelectorAll('nav button[data-tab]').forEach(x => x.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(x => x.classList.remove('active'));
    b.classList.add('active'); document.getElementById(b.dataset.tab).classList.add('active');
});
const fs = {
    cwd: '.',
    async load(p){ try{ const r = await api('list',{path:p}); this.cwd=r.path; document.getElementById('root-label').textContent='📂 '+r.path; this.crumb(r.path); this.table(r.items); }catch(e){ toast(e.message,'err'); } },
    crumb(p){ const parts=p==='.'?[]:p.split('/'); const el=document.getElementById('breadcrumb'); el.innerHTML='<a onclick="fs.load(\'.\')">🏠 root</a>'; let acc=''; parts.forEach(s=>{acc=acc?acc+'/'+s:s; el.innerHTML+=' / <a onclick="fs.load(\''+acc.replace(/'/g,"\\'")+'\')">'+s+'</a>';}); },
    table(items){ const tb=document.getElementById('files-body'); tb.innerHTML=''; const f=document.getElementById('filter').value.toLowerCase();
        items.filter(i=>!f||i.name.toLowerCase().includes(f)).forEach(it=>{ const tr=document.createElement('tr'); const cls=it.type==='dir'?'type-dir':'type-file'; const ic=it.type==='dir'?'📁':'📄';
            tr.innerHTML=`<td><span class="${cls}" data-p="${it.path.replace(/"/g,'&quot;')}">${ic} ${it.name}</span></td><td>${it.type==='dir'?'':fmtSize(it.size)}</td><td class="muted">${fmtDate(it.mtime)}</td><td>${it.type==='file'?`<button onclick="fs.dl('${it.path.replace(/'/g,"\\'")}')">⬇️</button>`:''} <button onclick="fs.ren('${it.path.replace(/'/g,"\\'")}')">✏️</button> <button class="danger" onclick="fs.del('${it.path.replace(/'/g,"\\'")}')">🗑️</button></td>`;
            tr.querySelector('.'+cls).onclick=()=>{ it.type==='dir'?fs.load(it.path):ed.open(it.path); }; tb.appendChild(tr); }); },
    refresh(){ this.load(this.cwd); },
    up(){ const p=this.cwd.split('/'); p.pop(); this.load(p.join('/')||'.'); },
    async mkdir(){ const n=prompt('Název složky:'); if(!n)return; try{ await api('mkdir',{path:this.cwd+'/'+n}); this.refresh(); }catch(e){ toast(e.message,'err'); } },
    async newFile(){ const n=prompt('Název souboru:'); if(!n)return; try{ await api('write',{path:this.cwd+'/'+n,content:'',force:1}); this.refresh(); ed.open(this.cwd+'/'+n); }catch(e){ toast(e.message,'err'); } },
    async del(p){ if(!confirm('Smazat: '+p+' ?'))return; try{ const r=await api('delete',{path:p}); this.refresh(); toast('Smazáno'+(r.backup?' (záloha ✓)':''),'ok'); }catch(e){ toast(e.message,'err'); } },
    async ren(p){ const parts=p.split('/'); const cur=parts.pop(); const n=prompt('Nový název:',cur); if(!n||n===cur)return; const to=(parts.length?parts.join('/')+'/':'')+n; try{ await api('rename',{from:p,to:to}); this.refresh(); }catch(e){ toast(e.message,'err'); } },
    dl(p){ window.location='?download='+encodeURIComponent(p); },
};
document.getElementById('filter').oninput=()=>fs.refresh();
const ed = {
    path:null,
    async open(p){ try{ const r=await api('read',{path:p}); this.path=r.path; document.getElementById('editor-path').textContent='📝 '+r.path+' ('+fmtSize(r.size)+')'; document.getElementById('editor-area').value=r.content; document.querySelector('nav button[data-tab=editor]').click(); }catch(e){ toast(e.message,'err'); } },
    async save(force){ if(!this.path)return toast('Není otevřen soubor','err'); try{ const d={path:this.path,content:document.getElementById('editor-area').value}; if(force)d.force=1; const r=await api('write',d); toast('Uloženo'+(r.backup?' (záloha ✓)':''),'ok'); }catch(e){ if(e.message.includes('syntax')&&confirm(e.message+'\n\nVynutit uložení i tak?')){ this.save(true); } else { toast(e.message,'err'); } } },
    async reload(){ if(this.path)this.open(this.path); },
};
document.getElementById('editor-area').addEventListener('keydown',e=>{ if((e.ctrlKey||e.metaKey)&&e.key==='s'){ e.preventDefault(); ed.save(); } });
fs.load('.');
</script>
</body>
</html>
