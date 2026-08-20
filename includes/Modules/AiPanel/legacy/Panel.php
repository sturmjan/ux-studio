<?php
/**
 * AI Panel — runtime helpers (UX Studio module: ai-panel).
 *
 * Originally a standalone plugin, later the "Claude Panel" UX1 module. Now
 * loaded by UxStudio\Modules\AiPanel\Module; lifecycle (activate/deactivate,
 * admin menu, hook registration) is handled there. This file and its
 * siblings (Runtime.php, rescue.php) are carried over unchanged - the
 * access/security model is intentionally NOT touched by the port.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CP_VERSION'))      define('CP_VERSION',     '1.4.0');
if (!defined('CP_OPTION'))       define('CP_OPTION',      'claude_panel_settings');
if (!defined('CP_AUDIT_OPT'))    define('CP_AUDIT_OPT',   'claude_panel_audit');
if (!defined('CP_ATTEMPTS_OPT')) define('CP_ATTEMPTS_OPT','claude_panel_attempts');

final class Claude_Panel_Bootstrap {

    public static function default_settings() {
        return [
            'access_active'        => false,
            'access_slug'          => '',
            'access_password_hash' => '',
            'access_expires_at'    => 0,
            'allowed_ips'          => '',     // comma-separated; empty = allow all
            'require_https'        => true,
            'notify_email'         => '',     // defaults to admin_email if empty
            'last_grant_password'  => '',     // shown ONCE after grant, then cleared on next page render
            'show_dashboard_widget'=> true,   // toggle from settings page
            // Záchranný endpoint (standalone, mimo WordPress).
            'rescue_installed'     => false,
            'rescue_url'           => '',
            'rescue_file'          => '',
            'rescue_dir'           => '',
            'rescue_created'       => 0,
            'rescue_allowed_ips'   => '',
            'rescue_require_https' => false,
            'rescue_key_enc'       => '',     // klíč zašifrovaný WP salty (kvůli zobrazení v adminu)
        ];
    }

    public static function get_settings() {
        $opts = get_option(CP_OPTION, []);
        if (!is_array($opts)) {
            $opts = [];
        }
        return array_merge(self::default_settings(), $opts);
    }

    public static function update_settings($opts) {
        update_option(CP_OPTION, $opts);
    }

    /* ============ AUDIT LOG ============ */

    public static function audit($event, $details = '') {
        $log = get_option(CP_AUDIT_OPT, []);
        if (!is_array($log)) {
            $log = [];
        }
        $log[] = [
            'time'    => time(),
            'ip'      => self::client_ip(),
            'event'   => (string) $event,
            'details' => (string) $details,
        ];
        // Keep only last 200 entries.
        if (count($log) > 200) {
            $log = array_slice($log, -200);
        }
        update_option(CP_AUDIT_OPT, $log, false);
    }

    public static function client_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '?';
    }

    /* ============ FRONTEND ROUTE ============ */

    public static function maybe_route_panel() {
        // SECURITY kill-switch (viz UX1_DISABLE_CLAUDE_PANEL ve wp-config.php).
        if (defined('UX1_DISABLE_CLAUDE_PANEL') && UX1_DISABLE_CLAUDE_PANEL) {
            return;
        }
        $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!is_string($path)) {
            return;
        }
        $path = trim($path, '/');

        // Match /cp-<32hex>/ or /cp-<32hex>
        $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path !== '') {
            if ($path === $home_path) {
                $path = '';
            } elseif (strpos($path, $home_path . '/') === 0) {
                $path = substr($path, strlen($home_path) + 1);
            }
        }

        if (!preg_match('#^cp-([a-f0-9]{32})(?:/.*)?$#i', $path, $m)) {
            return;
        }

        $opts = self::get_settings();

        // Access not active OR slug mismatch → 404 (don't reveal anything).
        if (empty($opts['access_active']) || empty($opts['access_slug'])) {
            self::send_404();
        }
        if (!hash_equals((string) $opts['access_slug'], strtolower($m[1]))) {
            self::send_404();
        }

        // Expired → revoke + 410 Gone.
        if (intval($opts['access_expires_at']) < time()) {
            $opts['access_active']        = false;
            $opts['access_slug']          = '';
            $opts['access_password_hash'] = '';
            $opts['access_expires_at']    = 0;
            self::update_settings($opts);
            self::audit('access_auto_expired');
            // Vypršení = smazání záchranného endpointu.
            self::rescue_remove();
            wp_clear_scheduled_hook('cp_rescue_expire');
            status_header(410);
            nocache_headers();
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Access expired.';
            exit;
        }

        // HTTPS enforcement.
        if (!empty($opts['require_https']) && !is_ssl()) {
            $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
            wp_redirect($url);
            exit;
        }

        // IP whitelist.
        $allowed = self::parse_ip_list($opts['allowed_ips']);
        if (!empty($allowed) && !in_array(self::client_ip(), $allowed, true)) {
            self::audit('ip_blocked', self::client_ip());
            self::send_404();
        }

        // Hand off to panel runtime.
        require_once __DIR__ . '/Runtime.php';
        Claude_Panel_Runtime::handle_request($opts);
        exit;
    }

    public static function send_404() {
        status_header(404);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        exit;
    }

    public static function parse_ip_list($csv) {
        $out = [];
        foreach (explode(',', (string) $csv) as $ip) {
            $ip = trim($ip);
            if ($ip !== '') {
                $out[] = $ip;
            }
        }
        return $out;
    }

    /* ============ AJAX HANDLERS ============ */

    public static function ajax_grant() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Nemáte oprávnění.'], 403);
        }
        check_ajax_referer('cp_ajax', 'nonce');

        $duration_min = isset($_POST['duration']) ? intval($_POST['duration']) : 240;
        $duration_min = max(5, min(60 * 24 * 7, $duration_min)); // 5 min – 7 days

        $opts = self::get_settings();

        // Generate fresh slug + password.
        $slug     = bin2hex(random_bytes(16));
        $password = self::generate_strong_password(28);

        $opts['access_active']        = true;
        $opts['access_slug']          = $slug;
        $opts['access_password_hash'] = wp_hash_password($password);
        $opts['access_expires_at']    = time() + $duration_min * 60;
        $opts['last_grant_password']  = ''; // password se předá v AJAX odpovědi přímo

        self::update_settings($opts);
        self::audit('access_granted', sprintf('duration=%d min, slug=cp-%s', $duration_min, $slug));

        // Záchranný endpoint se vytvoří AUTOMATICKY spolu s přístupem:
        //  - klíč = stejné heslo jako přístup (jeden údaj pro obojí),
        //  - IP omezena na toho, kdo přístup povoluje (aktuální admin),
        //  - smaže se při odebrání i automaticky po vypršení (WP-cron níže
        //    + fallback na admin_init).
        // Selhání instalace (např. nezapisovatelný uploads) grant nezruší.
        // Volba „zamknout na IP" z formuláře (výchozí = zamknuto, bezpečnější).
        $lock_ip   = (!isset($_POST['lock_ip']) || $_POST['lock_ip'] === '1');
        $rescue_ip = $lock_ip ? self::client_ip() : '';
        if (self::rescue_install($password, $rescue_ip, is_ssl())) {
            wp_clear_scheduled_hook('cp_rescue_expire');
            wp_schedule_single_event(intval($opts['access_expires_at']), 'cp_rescue_expire');
        }
        // Znovu načti nastavení, ať se rescue údaje objeví v renderu.
        $opts = self::get_settings();

        self::notify_admin_email(
            'AI Panel: přístup povolen',
            sprintf(
                "Přístup byl povolen na %d minut.\nURL: %s\nVyprší: %s\n\nPokud jste to nebyl(a) Vy, ihned přístup odeberte v admin AI Panel.",
                $duration_min,
                self::access_url($slug),
                wp_date('Y-m-d H:i:s', $opts['access_expires_at'])
            )
        );

        wp_send_json_success([
            'html'    => self::render_widget_body_html($opts, $password),
            'message' => 'Přístup byl povolen.',
        ]);
    }

    public static function ajax_revoke() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Nemáte oprávnění.'], 403);
        }
        check_ajax_referer('cp_ajax', 'nonce');

        $opts = self::get_settings();
        $opts['access_active']        = false;
        $opts['access_slug']          = '';
        $opts['access_password_hash'] = '';
        $opts['access_expires_at']    = 0;
        $opts['last_grant_password']  = '';
        self::update_settings($opts);
        self::audit('access_revoked_manual');

        // Odebrání přístupu = i smazání záchranného endpointu.
        self::rescue_remove();
        wp_clear_scheduled_hook('cp_rescue_expire');
        $opts = self::get_settings();

        wp_send_json_success([
            'html'    => self::render_widget_body_html($opts, ''),
            'message' => 'Přístup byl odebrán.',
        ]);
    }

    public static function ajax_clear_log() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Nemáte oprávnění.'], 403);
        }
        check_ajax_referer('cp_ajax', 'nonce');

        delete_option(CP_AUDIT_OPT);
        delete_option(CP_ATTEMPTS_OPT);
        self::audit('audit_log_cleared');

        wp_send_json_success([
            'html'    => self::render_audit_log_html(),
            'message' => 'Audit log byl vymazán.',
        ]);
    }

    public static function maybe_export_csv() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['page'], $_GET['cp_export']) || $_GET['page'] !== 'ux1-claude-panel' || $_GET['cp_export'] !== 'csv') return;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'cp_export_csv')) wp_die('Bad nonce', 403);

        $log = get_option(CP_AUDIT_OPT, []);
        if (!is_array($log)) $log = [];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="claude-panel-audit-' . wp_date('Y-m-d-His') . '.csv"');
        $out = fopen('php://output', 'w');
        // BOM pro Excel
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Čas', 'IP', 'Událost', 'Detaily']);
        foreach ($log as $row) {
            fputcsv($out, [
                wp_date('Y-m-d H:i:s', intval($row['time'] ?? 0)),
                (string) ($row['ip'] ?? ''),
                (string) ($row['event'] ?? ''),
                (string) ($row['details'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }

    /* ============ HELPERS ============ */

    public static function generate_strong_password($len = 28) {
        // wp_generate_password bez ambiguous chars (0/O, l/1).
        return wp_generate_password($len, true, false);
    }

    public static function access_url($slug) {
        return home_url('/cp-' . $slug . '/');
    }

    public static function notify_admin_email($subject, $body) {
        $opts = self::get_settings();
        $to = !empty($opts['notify_email']) ? $opts['notify_email'] : get_option('admin_email');
        if (empty($to)) {
            return;
        }
        $site = get_bloginfo('name');
        wp_mail($to, '[' . $site . '] ' . $subject, $body);
    }

    /* ============ RESCUE ENDPOINT (standalone, mimo WP) ============ */

    /* Reverzibilní šifrování klíče (kvůli zobrazení v adminu). Odvozeno z WP
     * salt → k dešifrování je potřeba i wp-config.php, ne jen dump DB. Rescue
     * soubor sám drží jen hash (jednosměrný). */

    public static function crypt_key() {
        $salt = function_exists('wp_salt') ? wp_salt('auth')
              : (defined('AUTH_KEY') ? AUTH_KEY : 'cp-fallback-salt');
        return hash('sha256', 'claude-panel-rescue|' . $salt, true);
    }

    public static function encrypt_secret($plain) {
        if (!function_exists('openssl_encrypt')) {
            return 'plain:' . base64_encode((string) $plain);
        }
        $iv = random_bytes(16);
        $ct = openssl_encrypt((string) $plain, 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, $iv);
        return 'enc:' . base64_encode($iv . $ct);
    }

    public static function decrypt_secret($blob) {
        $blob = (string) $blob;
        if ($blob === '') return '';
        if (strpos($blob, 'plain:') === 0) return (string) base64_decode(substr($blob, 6));
        if (strpos($blob, 'enc:') !== 0)  return '';
        $raw = base64_decode(substr($blob, 4));
        if ($raw === false || strlen($raw) < 17) return '';
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $pt = openssl_decrypt($ct, 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, $iv);
        return $pt === false ? '' : $pt;
    }

    /**
     * Fallback úklid: pokud endpoint existuje, ale přístup už není aktivní
     * (vypršel nebo byl odebrán), endpoint se smaže. Volá se na admin_init pro
     * případ, že by WP-cron událost `cp_rescue_expire` na hostingu neproběhla.
     */
    public static function maybe_cleanup_expired_rescue() {
        $opts = self::get_settings();
        if (empty($opts['rescue_installed'])) {
            return;
        }
        $access_active = !empty($opts['access_active']) && intval($opts['access_expires_at']) > time();
        if (!$access_active) {
            self::rescue_remove();
            wp_clear_scheduled_hook('cp_rescue_expire');
        }
    }

    /**
     * Nainstaluje / přeinstaluje záchranný endpoint. Zkopíruje self-contained
     * šablonu includes/rescue.php do wp-content/uploads/claude-panel-rescue/
     * pod náhodným názvem a zapíše vedle ní config.php (hash hesla + root + IP).
     */
    public static function rescue_install($password, $allowed_ips, $require_https) {
        self::rescue_remove(false); // starou instalaci ukliď (rotace názvu = nová URL)

        $up = wp_upload_dir();
        if (!empty($up['error'])) return false;
        $dir = trailingslashit($up['basedir']) . 'claude-panel-rescue';
        if (!wp_mkdir_p($dir)) return false;

        $template = __DIR__ . '/rescue.php';
        if (!is_file($template)) return false;

        $fname = 'claude-rescue-' . bin2hex(random_bytes(8)) . '.php';
        if (!copy($template, $dir . '/' . $fname)) return false;

        $config = [
            'hash'          => password_hash($password, PASSWORD_DEFAULT),
            'root'          => ABSPATH,
            'allowed_ips'   => $allowed_ips,
            'require_https' => (bool) $require_https,
            'label'         => get_bloginfo('name'),
            'created'       => time(),
        ];
        $php = "<?php\n// AI Panel — rescue config. Nesahat ručně; spravuje plugin.\n"
             . "// Přímý přístup přes HTTP nic nevypíše (jen vrátí pole).\nreturn "
             . var_export($config, true) . ";\n";
        if (file_put_contents($dir . '/config.php', $php) === false) return false;

        // Ochrana (Apache): rescue-<rand>.php nech dostupné, config.php ne.
        // Log a rate-limit soubor mají navíc vlastní PHP guard (exit) pro nginx.
        file_put_contents($dir . '/.htaccess',
            "# AI Panel rescue — chraň konfiguraci, skript nech dostupný\n" .
            "<FilesMatch \"^config\\.php$\">\n" .
            "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n" .
            "  <IfModule !mod_authz_core.c>\n    Deny from all\n  </IfModule>\n" .
            "</FilesMatch>\nOptions -Indexes\n");
        file_put_contents($dir . '/index.html', '');

        $opts = self::get_settings();
        $opts['rescue_installed']     = true;
        $opts['rescue_file']          = $dir . '/' . $fname;
        $opts['rescue_dir']           = $dir;
        $opts['rescue_url']           = trailingslashit($up['baseurl']) . 'claude-panel-rescue/' . $fname;
        $opts['rescue_created']       = time();
        $opts['rescue_allowed_ips']   = $allowed_ips;
        $opts['rescue_require_https'] = (bool) $require_https;
        $opts['rescue_key_enc']       = self::encrypt_secret($password);
        self::update_settings($opts);
        self::audit('rescue_installed', 'IP=' . ($allowed_ips ?: 'kdokoli') . ', url=' . $opts['rescue_url']);
        return true;
    }

    public static function rescue_remove($update_option = true) {
        $opts = self::get_settings();
        $dir  = (string) $opts['rescue_dir'];
        if ($dir && is_dir($dir) && strpos($dir, 'claude-panel-rescue') !== false) {
            self::rrmdir_safe($dir);
        }
        if ($update_option) {
            $opts['rescue_installed'] = false;
            $opts['rescue_file']      = '';
            $opts['rescue_dir']       = '';
            $opts['rescue_url']       = '';
            $opts['rescue_created']   = 0;
            $opts['rescue_key_enc']   = '';
            self::update_settings($opts);
            self::audit('rescue_removed');
        }
    }

    private static function rrmdir_safe($dir) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? self::rrmdir_safe($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /* ============ DASHBOARD WIDGET ============ */

    public static function register_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $opts = self::get_settings();
        if (empty($opts['show_dashboard_widget'])) {
            return;
        }
        wp_add_dashboard_widget(
            'claude_panel_dashboard_widget',
            '🛡️ AI Panel — vzdálený přístup',
            [__CLASS__, 'render_dashboard_widget']
        );

        // Push our widget to the top of the dashboard.
        global $wp_meta_boxes;
        $dashboard = $wp_meta_boxes['dashboard']['normal']['core'] ?? [];
        $widget_id = 'claude_panel_dashboard_widget';
        if (isset($wp_meta_boxes['dashboard']['normal']['core'][$widget_id])) {
            $widget = $wp_meta_boxes['dashboard']['normal']['core'][$widget_id];
            unset($wp_meta_boxes['dashboard']['normal']['core'][$widget_id]);
            $wp_meta_boxes['dashboard']['normal']['core'] = array_merge(
                [$widget_id => $widget],
                $wp_meta_boxes['dashboard']['normal']['core']
            );
        }
    }

    public static function render_dashboard_widget() {
        $opts = self::get_settings();

        // Pop one-time password — display once, then clear.
        $one_time_password = '';
        if (!empty($opts['last_grant_password'])) {
            $one_time_password = $opts['last_grant_password'];
            $opts['last_grant_password'] = '';
            self::update_settings($opts);
        }

        self::print_widget_assets();
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $nonce    = wp_create_nonce('cp_ajax');
        ?>
        <div class="cpw" id="cpw-root" data-ajax="<?= $ajax_url ?>" data-nonce="<?= esc_attr($nonce) ?>">
            <?= self::render_widget_body_html($opts, $one_time_password) ?>
        </div>
        <?php
    }

    /**
     * Vytiskne CSS + JS sdílené dashboard widgetem a admin stránkou.
     * Idempotent — vytiskne max 1× na request.
     */
    public static function print_widget_assets() {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        ?>
        <style>
            #claude_panel_dashboard_widget .inside { margin: 0; padding: 0; }
            .cpw { padding: 12px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
            .cpw-row { display: flex; align-items: center; gap: .5rem; margin-bottom: .8rem; }
            .cpw-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
            .cpw-dot-on { background: #15803d; box-shadow: 0 0 0 4px rgba(21,128,61,.18); }
            .cpw-dot-off { background: #6b7280; }
            .cpw-status { font-weight: 600; }
            .cpw-time { color: #6b7280; font-size: .9em; }
            .cpw-creds { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: .8rem; margin: .5rem 0 .8rem; }
            .cpw-creds h4 { margin: 0 0 .5rem; font-size: 13px; }
            .cpw-creds-table { border-collapse: collapse; width: 100%; margin-bottom: .5rem; }
            .cpw-creds-table th { text-align: left; padding: 4px 8px 4px 0; width: 50px; vertical-align: top; font-weight: 600; }
            .cpw-creds-table td { padding: 4px 0; }
            .cpw-creds-table code { background: #fff; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 3px; font-size: 12px; word-break: break-all; display: inline-block; max-width: 100%; }
            .cpw-warn { color: #92400e; background: #fef3c7; border-left: 3px solid #f59e0b; padding: 6px 10px; border-radius: 3px; font-size: 12px; margin: .5rem 0 0; }
            .cpw-grant { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin: .5rem 0; }
            .cpw-grant select { max-width: 130px; }
            .cpw-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .5rem; }
            .cpw-link-row { font-size: 12px; color: #6b7280; margin-top: .8rem; padding-top: .8rem; border-top: 1px solid #e5e7eb; }
            .cpw-btn-revoke { color: #b91c1c !important; border-color: #b91c1c !important; }
            .cpw-copied { color: #15803d; font-size: 12px; display: none; margin-left: .5rem; }
            .cpw-flash { margin: 0 0 .8rem; padding: .4rem .8rem; border-radius: 4px; font-size: 13px; }
            .cpw-flash-ok { background: #f0fdf4; border-left: 3px solid #15803d; color: #15803d; }
            .cpw-flash-err { background: #fef2f2; border-left: 3px solid #b91c1c; color: #b91c1c; }
            .cpw-flash-info { background: #eff6ff; border-left: 3px solid #3b82f6; color: #1e40af; }
            /* Audit log */
            .cp-audit-toolbar { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin: 1rem 0 .5rem; }
            .cp-audit-toolbar .spacer { flex: 1; }
            .cp-audit { background: #fff; }
            .cp-audit td { vertical-align: top; font-size: 13px; }
            .cp-audit code { background: #f3f4f6; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
            .cp-audit .cp-cat { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
            .cp-cat-login { background: #dbeafe; color: #1e40af; }
            .cp-cat-file  { background: #ddd6fe; color: #5b21b6; }
            .cp-cat-sql   { background: #fce7f3; color: #9d174d; }
            .cp-cat-exec  { background: #fed7aa; color: #9a3412; }
            .cp-cat-system{ background: #e5e7eb; color: #374151; }
            .cp-cat-access{ background: #fef3c7; color: #92400e; }
        </style>
        <script>
        (function () {
            function init(root) {
                if (!root || root.dataset.cpwInit === '1') return;
                root.dataset.cpwInit = '1';
                var ajax  = root.dataset.ajax;
                var nonce = root.dataset.nonce;

                function setBusy(on) { root.style.opacity = on ? '0.55' : '1'; root.style.pointerEvents = on ? 'none' : ''; }

                function notice(text, type) {
                    var bar = root.querySelector('#cpw-flash');
                    if (!bar) return;
                    bar.textContent = text;
                    bar.className = 'cpw-flash cpw-flash-' + (type || 'info');
                    bar.style.display = 'block';
                    setTimeout(function(){ if (bar) bar.style.display = 'none'; }, 4000);
                }

                function send(action, extra, target) {
                    var body = new FormData();
                    body.append('action', action);
                    body.append('nonce', nonce);
                    if (extra) Object.keys(extra).forEach(function(k){ body.append(k, extra[k]); });
                    setBusy(true);
                    return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function(r){ return r.json(); })
                        .then(function(j){
                            if (j && j.success && j.data) {
                                if (target === 'log') {
                                    var logEl = document.getElementById('cp-audit-wrap');
                                    if (logEl) logEl.innerHTML = j.data.html;
                                } else {
                                    root.innerHTML = j.data.html;
                                }
                                notice(j.data.message || 'OK', 'ok');
                            } else {
                                notice((j && j.data && j.data.message) || 'Chyba.', 'err');
                            }
                        })
                        .catch(function(e){ notice('Chyba sítě: ' + e.message, 'err'); })
                        .finally(function(){ setBusy(false); });
                }

                root.addEventListener('submit', function (e) {
                    var f = e.target;
                    if (f && f.matches('form[data-cpw-action="grant"]')) {
                        e.preventDefault();
                        if (!confirm('Tímto vygenerujete jednorázové URL a heslo. Pokračovat?')) return;
                        var dur = f.querySelector('[name=duration]').value;
                        var lockEl = f.querySelector('[name=lock_ip]');
                        var lock = (lockEl && lockEl.checked) ? '1' : '0';
                        send('cp_grant_ajax', { duration: dur, lock_ip: lock });
                    } else if (f && f.matches('form[data-cpw-action="revoke"]')) {
                        e.preventDefault();
                        if (!confirm('Opravdu odebrat přístup? Druhá strana přijde o spojení.')) return;
                        send('cp_revoke_ajax', {});
                    }
                });

                root.addEventListener('click', function (e) {
                    if (e.target && e.target.matches('[data-cpw-copy]')) {
                        e.preventDefault();
                        var u = root.querySelector('#cpw-url'); u = u ? u.textContent : '';
                        var p = root.querySelector('#cpw-pwd'); p = p ? p.textContent : '';
                        var promptText =
                            'Pro AI agenta: dočasný administrační přístup k WordPress webu přes plugin "ai-panel". ' +
                            'Postup: (1) GET ' + u + '?api=help&brief=1 — vrátí stručný strojový JSON protokolu (login flow, CSRF, base64 obfuskace pro WAF, všechny API akce: list/read/grep/write/patch/delete/mkdir/rename/upload/exec/sql/wpinfo, download endpoint) VČETNĚ sekce "efficiency" — řiď se jí, ať zbytečně nepálíš tokeny (hledej přes grep, ne čti celé soubory; úpravy přes patch, ne write; částečné read; úsporné SQL). Plný help bez &brief je jen pro člověka. ' +
                            '(2) Přihlaš se POST na ' + u + ' s polem action=login a password=<heslo>; přijmi session cookie cppnl a vyparsuj `const CSRF = "..."` z HTML odpovědi. ' +
                            '(3) Pokračuj podle help dokumentace. Po dokončení mě informuj — uživatel ručně odebere přístup ve WP admin dashboardu (kill switch).\n\n' +
                            'URL: ' + u + '\n' +
                            'Heslo: ' + p;

                        // Přibal i záchranný endpoint (mimo WP), pokud je nainstalovaný,
                        // ať AI agent hned ví, čím obnovit web, kdyby ho shodil.
                        var ru = root.querySelector('#cpw-rescue-url'); ru = ru ? ru.textContent : '';
                        var rk = root.querySelector('#cpw-rescue-key'); rk = rk ? rk.textContent : '';
                        if (ru && rk) {
                            promptText +=
                                '\n\n=== ZÁCHRANNÝ ENDPOINT (nezávislý na WordPressu) ===\n' +
                                'DŮLEŽITÉ: Pokud tvoje změna způsobí fatální PHP chybu, která shodí celý web (bílá obrazovka) a tento panel i wp-admin přestanou reagovat, NEPANIKAŘ a NEČEKEJ na FTP. Existuje samostatný nástroj běžící mimo WordPress, který funguje i když je WP rozbitý. Umí JEN správu souborů — přihlaš se a oprav nebo smaž soubor, který web shodil.\n' +
                                'Rescue URL: ' + ru + '\n' +
                                'Rescue klíč: ' + rk + '\n' +
                                'Přihlášení: POST action=login & password=<klíč>, session cookie cprescue, CSRF z `const CSRF = "..."`. API (POST ?api=): list/read/write/patch/delete/rename/mkdir (POST pole path, content|c64, from/to; patch: find|f64 + replace|r64). Úprav existující soubor přes patch (jen diff, ne celý soubor); read umí start_line/end_line. GET ?download=path. Zápis *.php se lintuje (422 při syntax chybě, obejít force=1) a před přepisem zálohuje.';
                        }

                        navigator.clipboard.writeText(promptText).then(function () {
                            var c = root.querySelector('#cpw-copied');
                            if (c) { c.style.display = 'inline'; setTimeout(function(){ c.style.display = 'none'; }, 3000); }
                        });
                    } else if (e.target && e.target.matches('[data-cpw-copy-rescue]')) {
                        e.preventDefault();
                        var ru = root.querySelector('#cpw-rescue-url'); ru = ru ? ru.textContent : '';
                        var rk = root.querySelector('#cpw-rescue-key'); rk = rk ? rk.textContent : '';
                        navigator.clipboard.writeText(
                            'AI Panel — záchranný přístup (mimo WordPress).\nURL: ' + ru + '\nKlíč (heslo): ' + rk +
                            '\nPřihlášení: otevři URL, zadej klíč jako heslo. Jen správa souborů.'
                        ).then(function () {
                            var c = root.querySelector('#cpw-rescue-copied');
                            if (c) { c.style.display = 'inline'; setTimeout(function(){ c.style.display = 'none'; }, 3000); }
                        });
                    } else if (e.target && e.target.matches('[data-cpw-clear-log]')) {
                        e.preventDefault();
                        if (!confirm('Opravdu vymazat celý audit log?')) return;
                        send('cp_clear_log_ajax', {}, 'log');
                    }
                });
            }
            // Init existing roots. Run now AND on DOMContentLoaded — the
            // assets script is printed before the .cpw root in the dashboard
            // widget output, so a synchronous call here finds nothing.
            function bootAll() {
                document.querySelectorAll('.cpw[data-ajax]').forEach(init);
            }
            bootAll();
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bootAll);
            }
        })();
        </script>
        <?php
    }

    /**
     * Renders just the inner content of the widget (without #cpw-root wrapper).
     * Same markup is produced both on initial pageload and on AJAX response —
     * client-side JS just replaces #cpw-root.innerHTML.
     */
    public static function render_widget_body_html($opts, $one_time_password = '') {
        $is_active  = !empty($opts['access_active']) && intval($opts['access_expires_at']) > time();
        $expires_in = $is_active ? intval($opts['access_expires_at']) - time() : 0;
        $access_url = $is_active ? self::access_url($opts['access_slug']) : '';

        ob_start();
        ?>
        <div id="cpw-flash" class="cpw-flash" style="display:none"></div>

        <?php
        $rescue_key_w = self::decrypt_secret($opts['rescue_key_enc'] ?? '');
        $has_rescue_w = !empty($opts['rescue_installed']) && !empty($opts['rescue_url']) && $rescue_key_w !== '';
        ?>
        <?php if ($is_active): ?>
            <div class="cpw-row">
                <span class="cpw-dot cpw-dot-on"></span>
                <span class="cpw-status">POVOLEN</span>
                <span class="cpw-time">— vyprší za <strong><?= esc_html(self::format_duration($expires_in)) ?></strong></span>
            </div>

            <?php if ($one_time_password): ?>
                <div class="cpw-creds">
                    <h4>📋 Přístupové údaje (heslo už znovu neuvidíte!)</h4>
                    <table class="cpw-creds-table">
                        <tr><th>URL</th><td><code id="cpw-url"><?= esc_html($access_url) ?></code></td></tr>
                        <tr><th>Heslo</th><td><code id="cpw-pwd"><?= esc_html($one_time_password) ?></code></td></tr>
                    </table>
                    <button type="button" class="button button-primary button-small" data-cpw-copy>📋 Zkopírovat pro AI agenta</button>
                    <span id="cpw-copied" class="cpw-copied">✓ Zkopírováno</span>
                    <p style="margin: .5rem 0 0; font-size: 11px; color: #6b7280;">Schránka bude obsahovat URL, heslo a stručný prompt pro AI agenta, aby si na libovolném PC sám načetl dokumentaci API z <code>?api=help</code><?= $has_rescue_w ? ' — a navíc URL + klíč k záchrannému endpointu pro případ, že by se web shodil.' : '.' ?></p>
                </div>
            <?php else: ?>
                <p style="margin: .3em 0; font-size: 12px;">URL: <code style="font-size: 11px; word-break: break-all;"><?= esc_html($access_url) ?></code></p>
            <?php endif; ?>

            <?php if ($has_rescue_w): ?>
                <div class="cpw-creds" style="background:#fff7ed;border-color:#fed7aa;">
                    <h4>🛟 Záchranný endpoint (mimo WordPress) — aktivní</h4>
                    <table class="cpw-creds-table">
                        <tr><th>URL</th><td><code id="cpw-rescue-url"><?= esc_html($opts['rescue_url']) ?></code></td></tr>
                        <tr><th>Klíč</th><td><code id="cpw-rescue-key"><?= esc_html($rescue_key_w) ?></code></td></tr>
                    </table>
                    <button type="button" class="button button-small" data-cpw-copy-rescue>📋 Zkopírovat záchranný přístup</button>
                    <span id="cpw-rescue-copied" class="cpw-copied">✓ Zkopírováno</span>
                    <p style="margin:.4rem 0 0;font-size:11px;color:#9a3412;">
                        Pro obnovu webu, když ho fatální chyba shodí (i wp-admin). Přihlášení klíčem výše, jen správa souborů.
                        Omezeno na IP <code><?= esc_html($opts['rescue_allowed_ips'] ?: 'kdokoli') ?></code>. Smaže se automaticky po vypršení přístupu.
                    </p>
                </div>
            <?php endif; ?>

            <form data-cpw-action="revoke" class="cpw-actions">
                <button class="button button-small cpw-btn-revoke">⛔ Okamžitě odebrat přístup</button>
            </form>

        <?php else: ?>
            <div class="cpw-row">
                <span class="cpw-dot cpw-dot-off"></span>
                <span class="cpw-status">VYPNUT</span>
                <span class="cpw-time">— panel URL vrací 404</span>
            </div>

            <form data-cpw-action="grant" class="cpw-grant">
                <label style="font-size: 12px;">Povolit na:
                    <select name="duration">
                        <option value="15">15 min</option>
                        <option value="60">1 h</option>
                        <option value="240" selected>4 h</option>
                        <option value="720">12 h</option>
                        <option value="1440">24 h</option>
                        <option value="2880">2 dny</option>
                        <option value="10080">7 dní</option>
                    </select>
                </label>
                <label style="font-size:12px;display:flex;align-items:center;gap:.35rem;width:100%;">
                    <input type="checkbox" name="lock_ip" value="1" checked>
                    Zamknout záchranný přístup na moji IP <code style="font-size:11px;"><?= esc_html(self::client_ip()) ?></code>
                </label>
                <button class="button button-primary">✅ Povolit přístup</button>
            </form>
            <p class="cpw-warn">⚠️ Po povolení dává plný přístup k souborům, DB i shellu. Povolujte jen pokud důvěřujete druhé straně.<br>
            Zaškrtnuto = záchranný endpoint půjde otevřít jen z tvé IP (bezpečnější). Odškrtni, pokud AI agent běží na jiné IP/serveru.</p>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /* ============ ADMIN PAGE ============ */

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        // Handle "toggle dashboard widget" form post.
        $settings_notice = '';
        if (isset($_POST['cp_toggle_widget']) && check_admin_referer('cp_toggle_widget')) {
            $opts_in = self::get_settings();
            $opts_in['show_dashboard_widget'] = !empty($_POST['show_dashboard_widget']);
            self::update_settings($opts_in);
            self::audit('dashboard_widget_toggled', $opts_in['show_dashboard_widget'] ? 'enabled' : 'disabled');
            $settings_notice = $opts_in['show_dashboard_widget']
                ? 'Dashboard widget byl povolen.'
                : 'Dashboard widget byl skryt.';
        }

        $opts = self::get_settings();
        $one_time_password = '';
        if (!empty($opts['last_grant_password'])) {
            $one_time_password = $opts['last_grant_password'];
            $opts['last_grant_password'] = '';
            self::update_settings($opts);
        }

        self::print_widget_assets();
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        $nonce    = wp_create_nonce('cp_ajax');
        $widget_on = !empty($opts['show_dashboard_widget']);
        ?>
        <div class="wrap">
            <h1>🛡️ AI Panel</h1>
            <p style="color:#6b7280;margin:.2rem 0 1rem">Dočasný řízený přístup k souborům, databázi a shellu pro vzdáleného administrátora. Stejné ovládání najdete i v dashboard widgetu.</p>

            <?php if ($settings_notice): ?>
                <div class="notice notice-success is-dismissible"><p><?= esc_html($settings_notice) ?></p></div>
            <?php endif; ?>

            <h2 style="margin-top: 1rem;">Stav přístupu</h2>
            <div class="cpw" id="cpw-root" data-ajax="<?= $ajax_url ?>" data-nonce="<?= esc_attr($nonce) ?>">
                <?= self::render_widget_body_html($opts, $one_time_password) ?>
            </div>

            <h2 style="margin-top: 2rem;">Nastavení</h2>
            <form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:1rem;max-width:720px;">
                <?php wp_nonce_field('cp_toggle_widget'); ?>
                <input type="hidden" name="cp_toggle_widget" value="1">
                <label style="display:flex;align-items:center;gap:.6rem;font-weight:600;">
                    <input type="checkbox" name="show_dashboard_widget" value="1" <?php checked($widget_on); ?>>
                    Zobrazovat widget na hlavním dashboardu
                </label>
                <p class="description" style="margin:.4rem 0 .8rem 1.8rem;">
                    Když je vypnuto, panel ovládáš výhradně z této stránky. Hodí se, pokud nechceš mít na dashboardu vidět tlačítko „Povolit přístup".
                </p>
                <?php submit_button($widget_on ? 'Skrýt widget z dashboardu' : 'Povolit widget na dashboardu', $widget_on ? 'secondary' : 'primary', 'submit', false); ?>
            </form>

            <?php self::render_rescue_section($opts); ?>

            <div id="cp-audit-wrap">
                <?= self::render_audit_log_html() ?>
            </div>
        </div>
        <?php
    }

    /**
     * Informační sekce o záchranném endpointu. Žádný formulář — endpoint se
     * vytváří i maže AUTOMATICKY spolu s povolením/vypršením přístupu.
     * Aktuální URL + klíč se ukazují nahoře ve „Stav přístupu", když je aktivní.
     */
    public static function render_rescue_section($opts) {
        $active = !empty($opts['access_active']) && intval($opts['access_expires_at']) > time();
        ?>
        <h2 style="margin-top: 2rem;">🛟 Záchranný přístup <small style="color:#6b7280;font-weight:normal">(funguje i když je WordPress rozbitý)</small></h2>

        <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:1rem 1.25rem;max-width:760px;">
            <p style="margin-top:0;color:#4b5563;font-size:13px;">
                Samostatný soubor <strong>mimo WordPress</strong> pro případ, že fatální chyba shodí celý web
                (bílá obrazovka, nefunkční wp-admin). Přihlásíš se do něj a rozbitý soubor opravíš/smažeš
                <strong>bez FTP</strong>. Umí <strong>jen správu souborů</strong> (žádné SQL ani shell) a před
                každým přepsáním/smazáním dělá zálohu.
            </p>
            <p style="color:#4b5563;font-size:13px;">
                <strong>Nic nenastavuješ.</strong> Endpoint vznikne <strong>automaticky při „Povolit přístup"</strong>:
                klíč = stejné heslo jako přístup, přihlášení je omezeno na <strong>tvoji aktuální IP</strong>
                (<code><?= esc_html(self::client_ip()) ?></code>). Po <strong>vypršení</strong> nebo
                <strong>odebrání</strong> přístupu se sám <strong>smaže</strong>.
            </p>
            <?php if ($active && !empty($opts['rescue_installed'])): ?>
                <p style="color:#15803d;font-size:13px;font-weight:600;margin-bottom:0;">● Právě je aktivní — URL a klíč najdeš nahoře ve „Stav přístupu".</p>
            <?php else: ?>
                <p style="color:#6b7280;font-size:13px;margin-bottom:0;">Teď neaktivní. Objeví se po povolení přístupu.</p>
            <?php endif; ?>
            <div style="background:#fef3c7;border-left:3px solid #f59e0b;color:#92400e;padding:8px 12px;border-radius:3px;font-size:12px;margin:.8rem 0 0;">
                ⚠️ Endpoint je zadní vrátka chráněná klíčem + omezená na tvou IP. Na <strong>nginx</strong> neplatí
                <code>.htaccess</code> — tam si ověř, že <code>config.php</code> vedle skriptu není stažitelné (vrací prázdno).
            </div>
        </div>
        <?php
    }

    /**
     * Render audit-log section (heading + toolbar + table). Same markup is used
     * on initial page render and on AJAX response after clearing the log.
     */
    public static function render_audit_log_html() {
        $log = get_option(CP_AUDIT_OPT, []);
        if (!is_array($log)) $log = [];

        $filter = isset($_GET['cp_filter']) ? sanitize_key($_GET['cp_filter']) : '';
        $valid_filters = ['login', 'file', 'sql', 'exec', 'access', 'system'];
        if ($filter && in_array($filter, $valid_filters, true)) {
            $log = array_filter($log, function ($e) use ($filter) {
                return self::categorize_event($e['event'] ?? '') === $filter;
            });
            $log = array_values($log);
        } else {
            $filter = '';
        }

        $log = array_reverse($log); // newest first

        $per_page = 100;
        $page     = max(1, intval($_GET['paged'] ?? 1));
        $total    = count($log);
        $total_pages = max(1, (int) ceil($total / $per_page));
        $page     = min($page, $total_pages);
        $sliced   = array_slice($log, ($page - 1) * $per_page, $per_page);

        $base_url = admin_url('admin.php?page=ux1-claude-panel');
        $export_url = wp_nonce_url(add_query_arg('cp_export', 'csv', $base_url), 'cp_export_csv');

        ob_start();
        ?>
        <h2 style="margin-top: 2rem;">Audit log <small style="color:#6b7280;font-weight:normal">(celkem <?= esc_html($total) ?> záznamů)</small></h2>

        <div class="cp-audit-toolbar">
            <span>Filtrovat:</span>
            <a href="<?= esc_url($base_url) ?>" class="button button-small <?= $filter === '' ? 'button-primary' : '' ?>">Vše</a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'access', $base_url)) ?>" class="button button-small <?= $filter === 'access' ? 'button-primary' : '' ?>">Přístup</a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'login', $base_url)) ?>" class="button button-small <?= $filter === 'login' ? 'button-primary' : '' ?>">Přihlášení</a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'file', $base_url)) ?>" class="button button-small <?= $filter === 'file' ? 'button-primary' : '' ?>">Soubory</a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'sql', $base_url)) ?>" class="button button-small <?= $filter === 'sql' ? 'button-primary' : '' ?>">SQL</a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'exec', $base_url)) ?>" class="button button-small <?= $filter === 'exec' ? 'button-primary' : '' ?>">Shell</a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'system', $base_url)) ?>" class="button button-small <?= $filter === 'system' ? 'button-primary' : '' ?>">Systém</a>
            <span class="spacer"></span>
            <a href="<?= esc_url($export_url) ?>" class="button button-small">⬇️ Export CSV</a>
            <button type="button" class="button button-small" data-cpw-clear-log>🗑️ Vymazat log</button>
        </div>

        <?php if (empty($sliced)): ?>
            <p style="color:#6b7280;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:1rem;">Zatím žádné záznamy v auditu<?= $filter ? ' (s filtrem „' . esc_html($filter) . '")' : '' ?>.</p>
        <?php else: ?>
            <table class="widefat striped cp-audit">
                <thead>
                    <tr>
                        <th style="width:160px;">Čas</th>
                        <th style="width:120px;">IP</th>
                        <th style="width:140px;">Kategorie</th>
                        <th style="width:160px;">Událost</th>
                        <th>Detaily</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sliced as $row):
                    $cat = self::categorize_event($row['event'] ?? '');
                    ?>
                    <tr>
                        <td><?= esc_html(wp_date('Y-m-d H:i:s', intval($row['time'] ?? 0))) ?></td>
                        <td><code><?= esc_html($row['ip'] ?? '?') ?></code></td>
                        <td><span class="cp-cat cp-cat-<?= esc_attr($cat) ?>"><?= esc_html(self::category_label($cat)) ?></span></td>
                        <td><code><?= esc_html($row['event'] ?? '') ?></code></td>
                        <td><?= esc_html($row['details'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav" style="margin-top: .5rem;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?= esc_html($total) ?> položek</span>
                        <span class="pagination-links">
                            <?php for ($p = 1; $p <= $total_pages; $p++):
                                $url = add_query_arg(['cp_filter' => $filter, 'paged' => $p], $base_url);
                                if ($p === $page) {
                                    echo '<span class="button button-small button-primary">' . $p . '</span> ';
                                } else {
                                    echo '<a class="button button-small" href="' . esc_url($url) . '">' . $p . '</a> ';
                                }
                            endfor; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    public static function categorize_event($event) {
        $event = (string) $event;
        if ($event === 'login_ok' || $event === 'login_fail') return 'login';
        if (in_array($event, ['write', 'delete', 'mkdir', 'rename', 'upload', 'download'], true)) return 'file';
        if (strpos($event, 'sql') === 0) return 'sql';
        if ($event === 'exec') return 'exec';
        if (strpos($event, 'access_') === 0) return 'access';
        return 'system';
    }

    public static function category_label($cat) {
        $map = [
            'login'  => '🔐 Přihlášení',
            'file'   => '📁 Soubor',
            'sql'    => '🗄️ SQL',
            'exec'   => '💻 Shell',
            'access' => '🛡️ Přístup',
            'system' => '⚙️ Systém',
        ];
        return $map[$cat] ?? '⚙️ Systém';
    }

    /* ============ MISC ============ */

    public static function format_duration($sec) {
        $sec = max(0, (int) $sec);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $parts = [];
        if ($h > 0) $parts[] = $h . ' h';
        if ($m > 0 || $h === 0) $parts[] = $m . ' min';
        return implode(' ', $parts);
    }
}
