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
            // Rescue endpoint (standalone, outside WordPress).
            'rescue_installed'     => false,
            'rescue_url'           => '',
            'rescue_file'          => '',
            'rescue_dir'           => '',
            'rescue_created'       => 0,
            'rescue_allowed_ips'   => '',
            'rescue_require_https' => false,
            'rescue_key_enc'       => '',     // key encrypted with the WP salts (so it can be shown in the admin)
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
        // UX Studio: shared resolver (proxy headers only from Cloudflare /
        // configured trusted proxies) instead of the raw REMOTE_ADDR.
        $ip = \UxStudio\Core\ClientIp::get('auto');
        return $ip !== '' ? $ip : '?';
    }

    /**
     * Texts of the rescue endpoint UI in the language of the admin who installs it. rescue.php runs outside
     * WordPress (no __()), so they are written into its config.php. KEEP IN SYNC with the rt() calls there.
     */
    public static function rescue_i18n() {
        return [
            'Too many attempts. Wait %d s.' => __('Too many attempts. Wait %d s.', 'ux-studio'),
            'Wrong password.' => __('Wrong password.', 'ux-studio'),
            'Rescue access' => __('Rescue access', 'ux-studio'),
            'Independent of WordPress. File management only.' => __('Independent of WordPress. File management only.', 'ux-studio'),
            'Rescue password' => __('Rescue password', 'ux-studio'),
            'Log in' => __('Log in', 'ux-studio'),
            'Files' => __('Files', 'ux-studio'),
            'Editor' => __('Editor', 'ux-studio'),
            'Log out' => __('Log out', 'ux-studio'),
            'Emergency mode outside WordPress: file management only. Edit or delete the file that broke the site, then log out.' => __('Emergency mode outside WordPress: file management only. Edit or delete the file that broke the site, then log out.', 'ux-studio'),
            'Up' => __('Up', 'ux-studio'),
            'New folder' => __('New folder', 'ux-studio'),
            'New file' => __('New file', 'ux-studio'),
            'Filter...' => __('Filter...', 'ux-studio'),
            'Name' => __('Name', 'ux-studio'),
            'Size' => __('Size', 'ux-studio'),
            'Modified' => __('Modified', 'ux-studio'),
            'Actions' => __('Actions', 'ux-studio'),
            'no file' => __('no file', 'ux-studio'),
            'Save' => __('Save', 'ux-studio'),
            'Reload' => __('Reload', 'ux-studio'),
            'Click a file...' => __('Click a file...', 'ux-studio'),
            'Could not parse the response' => __('Could not parse the response', 'ux-studio'),
            'Name of the folder:' => __('Name of the folder:', 'ux-studio'),
            'Name of the file:' => __('Name of the file:', 'ux-studio'),
            'Delete:' => __('Delete:', 'ux-studio'),
            'Deleted' => __('Deleted', 'ux-studio'),
            '(backup)' => __('(backup)', 'ux-studio'),
            'New name:' => __('New name:', 'ux-studio'),
            'No file is open' => __('No file is open', 'ux-studio'),
            'Saved' => __('Saved', 'ux-studio'),
            'Force the save anyway?' => __('Force the save anyway?', 'ux-studio'),
        ];
    }

    /**
     * UX Studio: proxy ranges whose forwarding headers rescue.php may trust
     * (it runs outside WordPress, so it gets them via config.php). Same set
     * Core\ClientIp uses: Cloudflare edges + configured trusted proxies.
     */
    public static function rescue_trusted_proxies() {
        return array_values(array_filter(array_map('strval', \UxStudio\Core\ClientIp::trusted_proxy_ranges())));
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
            wp_send_json_error(['message' => __('You do not have permission to do that.', 'ux-studio')], 403);
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
        if (self::rescue_install($password, $rescue_ip, is_ssl(), intval($opts['access_expires_at']))) {
            wp_clear_scheduled_hook('cp_rescue_expire');
            wp_schedule_single_event(intval($opts['access_expires_at']), 'cp_rescue_expire');
        }
        // Znovu načti nastavení, ať se rescue údaje objeví v renderu.
        $opts = self::get_settings();

        self::notify_admin_email(
            __('AI Panel: access granted', 'ux-studio'),
            sprintf(
                /* translators: 1: number of minutes, 2: panel URL, 3: expiry date and time */
                __("Access was granted for %1\$d minutes.\nURL: %2\$s\nExpires: %3\$s\n\nIf this was not you, remove the access in AI Panel in the admin right away.", 'ux-studio'),
                $duration_min,
                self::access_url($slug),
                wp_date('Y-m-d H:i:s', $opts['access_expires_at'])
            )
        );

        wp_send_json_success([
            'html'    => self::render_widget_body_html($opts, $password),
            'message' => __('Access was granted.', 'ux-studio'),
        ]);
    }

    public static function ajax_revoke() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do that.', 'ux-studio')], 403);
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
            'message' => __('Access was removed.', 'ux-studio'),
        ]);
    }

    public static function ajax_clear_log() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do that.', 'ux-studio')], 403);
        }
        check_ajax_referer('cp_ajax', 'nonce');

        delete_option(CP_AUDIT_OPT);
        delete_option(CP_ATTEMPTS_OPT);
        self::audit('audit_log_cleared');

        wp_send_json_success([
            'html'    => self::render_audit_log_html(),
            'message' => __('The audit log was cleared.', 'ux-studio'),
        ]);
    }

    public static function maybe_export_csv() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['page'], $_GET['cp_export']) || $_GET['page'] !== 'ux-studio-ai-panel' || $_GET['cp_export'] !== 'csv') return;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'cp_export_csv')) wp_die('Bad nonce', 403);

        $log = get_option(CP_AUDIT_OPT, []);
        if (!is_array($log)) $log = [];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="claude-panel-audit-' . wp_date('Y-m-d-His') . '.csv"');
        $out = fopen('php://output', 'w');
        // BOM pro Excel
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [__('Time', 'ux-studio'), __('IP', 'ux-studio'), __('Event', 'ux-studio'), __('Details', 'ux-studio')]);
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
    public static function rescue_install($password, $allowed_ips, $require_https, $expires_at = 0) {
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
            // UX Studio: rescue.php itself refuses (and deletes itself) after
            // this moment, even if WP-Cron / the plugin never cleans it up.
            'expires_at'    => (int) $expires_at,
            'proxies'       => self::rescue_trusted_proxies(),
            'i18n'          => self::rescue_i18n(),
            'locale'        => str_replace('_', '-', determine_locale()),
        ];
        $php = "<?php\n// AI Panel - rescue config. Do not edit by hand; managed by the plugin.\n"
             . "// Direct access over HTTP prints nothing (it only returns an array).\nreturn "
             . var_export($config, true) . ";\n";
        if (file_put_contents($dir . '/config.php', $php) === false) return false;

        // Ochrana (Apache): rescue-<rand>.php nech dostupné, config.php ne.
        // Log a rate-limit soubor mají navíc vlastní PHP guard (exit) pro nginx.
        file_put_contents($dir . '/.htaccess',
            "# AI Panel rescue - protect the configuration, keep the script reachable\n" .
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
        // UX Studio: also the default location, in case the option was lost.
        $up = wp_upload_dir(null, false);
        $default_dir = empty($up['basedir']) ? '' : trailingslashit($up['basedir']) . 'claude-panel-rescue';
        if ($default_dir !== '' && is_dir($default_dir)) {
            self::rrmdir_safe($default_dir);
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
            '🛡️ ' . __('AI Panel: remote access', 'ux-studio'),
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
            var CPT = <?= wp_json_encode([
                'ok'            => __('OK', 'ux-studio'),
                'error'         => __('Error.', 'ux-studio'),
                'networkError'  => __('Network error:', 'ux-studio'),
                'confirmGrant'  => __('This generates a one-time URL and password. Continue?', 'ux-studio'),
                'confirmRevoke' => __('Really remove the access? The other side will lose the connection.', 'ux-studio'),
                'confirmClear'  => __('Really clear the whole audit log?', 'ux-studio'),
            ]) ?>;
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
                                notice(j.data.message || CPT.ok, 'ok');
                            } else {
                                notice((j && j.data && j.data.message) || CPT.error, 'err');
                            }
                        })
                        .catch(function(e){ notice(CPT.networkError + ' ' + e.message, 'err'); })
                        .finally(function(){ setBusy(false); });
                }

                root.addEventListener('submit', function (e) {
                    var f = e.target;
                    if (f && f.matches('form[data-cpw-action="grant"]')) {
                        e.preventDefault();
                        if (!confirm(CPT.confirmGrant)) return;
                        var dur = f.querySelector('[name=duration]').value;
                        var lockEl = f.querySelector('[name=lock_ip]');
                        var lock = (lockEl && lockEl.checked) ? '1' : '0';
                        send('cp_grant_ajax', { duration: dur, lock_ip: lock });
                    } else if (f && f.matches('form[data-cpw-action="revoke"]')) {
                        e.preventDefault();
                        if (!confirm(CPT.confirmRevoke)) return;
                        send('cp_revoke_ajax', {});
                    }
                });

                root.addEventListener('click', function (e) {
                    if (e.target && e.target.matches('[data-cpw-copy]')) {
                        e.preventDefault();
                        var u = root.querySelector('#cpw-url'); u = u ? u.textContent : '';
                        var p = root.querySelector('#cpw-pwd'); p = p ? p.textContent : '';
                        var promptText =
                            'For the AI agent: temporary admin access to a WordPress site through the "ai-panel" plugin. ' +
                            'Steps: (1) GET ' + u + '?api=help&brief=1 - returns a short machine-readable JSON of the protocol (login flow, CSRF, base64 obfuscation for the WAF, all API actions: list/read/grep/write/patch/delete/mkdir/rename/upload/exec/sql/wpinfo, download endpoint) INCLUDING the "efficiency" section - follow it so you do not burn tokens needlessly (search with grep instead of reading whole files; edit with patch, not write; partial reads; economical SQL). The full help without &brief is for humans only. ' +
                            '(2) Log in with POST to ' + u + ' with the fields action=login and password=<password>; accept the session cookie cppnl and parse `const CSRF = "..."` from the HTML response. ' +
                            '(3) Continue according to the help documentation. When you are done, tell me - the user removes the access by hand in the WP admin dashboard (kill switch).\n\n' +
                            'URL: ' + u + '\n' +
                            'Password: ' + p;

                        // Add the rescue endpoint (outside WP) when it is installed,
                        // so the AI agent knows at once how to restore the site if it breaks it.
                        var ru = root.querySelector('#cpw-rescue-url'); ru = ru ? ru.textContent : '';
                        var rk = root.querySelector('#cpw-rescue-key'); rk = rk ? rk.textContent : '';
                        if (ru && rk) {
                            promptText +=
                                '\n\n=== RESCUE ENDPOINT (independent of WordPress) ===\n' +
                                'IMPORTANT: If your change causes a fatal PHP error that takes the whole site down (white screen) and this panel and wp-admin stop responding, DO NOT PANIC and DO NOT WAIT for FTP. There is a separate tool running outside WordPress that works even when WP is broken. It can ONLY manage files - log in and fix or delete the file that broke the site.\n' +
                                'Rescue URL: ' + ru + '\n' +
                                'Rescue key: ' + rk + '\n' +
                                'Login: POST action=login & password=<key>, session cookie cprescue, CSRF from `const CSRF = "..."`. API (POST ?api=): list/read/write/patch/delete/rename/mkdir (POST fields path, content|c64, from/to; patch: find|f64 + replace|r64). Edit an existing file with patch (diff only, not the whole file); read supports start_line/end_line. GET ?download=path. Writing *.php is linted (422 on a syntax error, bypass with force=1) and backed up before overwriting.';
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
                            'AI Panel - rescue access (outside WordPress).\nURL: ' + ru + '\nKey (password): ' + rk +
                            '\nLogin: open the URL and enter the key as the password. File management only.'
                        ).then(function () {
                            var c = root.querySelector('#cpw-rescue-copied');
                            if (c) { c.style.display = 'inline'; setTimeout(function(){ c.style.display = 'none'; }, 3000); }
                        });
                    } else if (e.target && e.target.matches('[data-cpw-clear-log]')) {
                        e.preventDefault();
                        if (!confirm(CPT.confirmClear)) return;
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
                <span class="cpw-status"><?= esc_html__('ALLOWED', 'ux-studio') ?></span>
                <span class="cpw-time">- <?= self::kses(sprintf(
                    /* translators: %s: remaining time, for example "3 h 20 min" */
                    __('expires in %s', 'ux-studio'),
                    '<strong>' . esc_html(self::format_duration($expires_in)) . '</strong>'
                )) ?></span>
            </div>

            <?php if ($one_time_password): ?>
                <div class="cpw-creds">
                    <h4>📋 <?= esc_html__('Access details (you will not see the password again!)', 'ux-studio') ?></h4>
                    <table class="cpw-creds-table">
                        <tr><th>URL</th><td><code id="cpw-url"><?= esc_html($access_url) ?></code></td></tr>
                        <tr><th><?= esc_html__('Password', 'ux-studio') ?></th><td><code id="cpw-pwd"><?= esc_html($one_time_password) ?></code></td></tr>
                    </table>
                    <button type="button" class="button button-primary button-small" data-cpw-copy>📋 <?= esc_html__('Copy for the AI agent', 'ux-studio') ?></button>
                    <span id="cpw-copied" class="cpw-copied">✓ <?= esc_html__('Copied', 'ux-studio') ?></span>
                    <p style="margin: .5rem 0 0; font-size: 11px; color: #6b7280;"><?= self::kses(
                        $has_rescue_w
                            ? __('The clipboard will hold the URL, the password and a short prompt for the AI agent, so it can load the API documentation from <code>?api=help</code> on any computer, plus the URL and key of the rescue endpoint in case the site goes down.', 'ux-studio')
                            : __('The clipboard will hold the URL, the password and a short prompt for the AI agent, so it can load the API documentation from <code>?api=help</code> on any computer.', 'ux-studio')
                    ) ?></p>
                </div>
            <?php else: ?>
                <p style="margin: .3em 0; font-size: 12px;"><?= esc_html__('URL:', 'ux-studio') ?> <code style="font-size: 11px; word-break: break-all;"><?= esc_html($access_url) ?></code></p>
            <?php endif; ?>

            <?php if ($has_rescue_w): ?>
                <div class="cpw-creds" style="background:#fff7ed;border-color:#fed7aa;">
                    <h4>🛟 <?= esc_html__('Rescue endpoint (outside WordPress): active', 'ux-studio') ?></h4>
                    <table class="cpw-creds-table">
                        <tr><th>URL</th><td><code id="cpw-rescue-url"><?= esc_html($opts['rescue_url']) ?></code></td></tr>
                        <tr><th><?= esc_html__('Key', 'ux-studio') ?></th><td><code id="cpw-rescue-key"><?= esc_html($rescue_key_w) ?></code></td></tr>
                    </table>
                    <button type="button" class="button button-small" data-cpw-copy-rescue>📋 <?= esc_html__('Copy the rescue access', 'ux-studio') ?></button>
                    <span id="cpw-rescue-copied" class="cpw-copied">✓ <?= esc_html__('Copied', 'ux-studio') ?></span>
                    <p style="margin:.4rem 0 0;font-size:11px;color:#9a3412;">
                        <?= self::kses(sprintf(
                            /* translators: %s: allowed IP address or "anyone" */
                            __('For restoring the site when a fatal error takes it down (wp-admin too). Log in with the key above; file management only. Limited to IP %s. It is deleted automatically when the access expires.', 'ux-studio'),
                            '<code>' . esc_html($opts['rescue_allowed_ips'] ?: __('anyone', 'ux-studio')) . '</code>'
                        )) ?>
                    </p>
                </div>
            <?php endif; ?>

            <form data-cpw-action="revoke" class="cpw-actions">
                <button class="button button-small cpw-btn-revoke">⛔ <?= esc_html__('Remove the access now', 'ux-studio') ?></button>
            </form>

        <?php else: ?>
            <div class="cpw-row">
                <span class="cpw-dot cpw-dot-off"></span>
                <span class="cpw-status"><?= esc_html__('OFF', 'ux-studio') ?></span>
                <span class="cpw-time">- <?= esc_html__('the panel URL returns 404', 'ux-studio') ?></span>
            </div>

            <form data-cpw-action="grant" class="cpw-grant">
                <label style="font-size: 12px;"><?= esc_html__('Allow for:', 'ux-studio') ?>
                    <select name="duration">
                        <option value="15">15 min</option>
                        <option value="60">1 h</option>
                        <option value="240" selected>4 h</option>
                        <option value="720">12 h</option>
                        <option value="1440">24 h</option>
                        <option value="2880"><?= esc_html__('2 days', 'ux-studio') ?></option>
                        <option value="10080"><?= esc_html__('7 days', 'ux-studio') ?></option>
                    </select>
                </label>
                <label style="font-size:12px;display:flex;align-items:center;gap:.35rem;width:100%;">
                    <input type="checkbox" name="lock_ip" value="1" checked>
                    <?= self::kses(sprintf(
                        /* translators: %s: the IP address of the current admin */
                        __('Lock the rescue access to my IP %s', 'ux-studio'),
                        '<code style="font-size:11px;">' . esc_html(self::client_ip()) . '</code>'
                    )) ?>
                </label>
                <button class="button button-primary">✅ <?= esc_html__('Allow access', 'ux-studio') ?></button>
            </form>
            <p class="cpw-warn">⚠️ <?= self::kses(__('Once allowed, it gives full access to files, the database and the shell. Allow it only if you trust the other side.<br>Checked = the rescue endpoint can be opened only from your IP (safer). Uncheck it if the AI agent runs on another IP or server.', 'ux-studio')) ?></p>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /* ============ ADMIN PAGE ============ */

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'ux-studio'), 403);
        }

        // Handle "toggle dashboard widget" form post.
        $settings_notice = '';
        if (isset($_POST['cp_toggle_widget']) && check_admin_referer('cp_toggle_widget')) {
            $opts_in = self::get_settings();
            $opts_in['show_dashboard_widget'] = !empty($_POST['show_dashboard_widget']);
            self::update_settings($opts_in);
            self::audit('dashboard_widget_toggled', $opts_in['show_dashboard_widget'] ? 'enabled' : 'disabled');
            $settings_notice = $opts_in['show_dashboard_widget']
                ? __('The dashboard widget was turned on.', 'ux-studio')
                : __('The dashboard widget was hidden.', 'ux-studio');
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
            <p style="color:#6b7280;margin:.2rem 0 1rem"><?= esc_html__('Temporary controlled access to files, the database and the shell for a remote administrator. The same controls are also in the dashboard widget.', 'ux-studio') ?></p>

            <?php if ($settings_notice): ?>
                <div class="notice notice-success is-dismissible"><p><?= esc_html($settings_notice) ?></p></div>
            <?php endif; ?>

            <h2 style="margin-top: 1rem;"><?= esc_html__('Access status', 'ux-studio') ?></h2>
            <div class="cpw" id="cpw-root" data-ajax="<?= $ajax_url ?>" data-nonce="<?= esc_attr($nonce) ?>">
                <?= self::render_widget_body_html($opts, $one_time_password) ?>
            </div>

            <h2 style="margin-top: 2rem;"><?= esc_html__('Settings', 'ux-studio') ?></h2>
            <form method="post" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:1rem;max-width:720px;">
                <?php wp_nonce_field('cp_toggle_widget'); ?>
                <input type="hidden" name="cp_toggle_widget" value="1">
                <label style="display:flex;align-items:center;gap:.6rem;font-weight:600;">
                    <input type="checkbox" name="show_dashboard_widget" value="1" <?php checked($widget_on); ?>>
                    <?= esc_html__('Show the widget on the main dashboard', 'ux-studio') ?>
                </label>
                <p class="description" style="margin:.4rem 0 .8rem 1.8rem;">
                    <?= esc_html__('When this is off, you control the panel only from this page. Useful if you do not want to see the "Allow access" button on the dashboard.', 'ux-studio') ?>
                </p>
                <?php submit_button($widget_on ? __('Hide the widget from the dashboard', 'ux-studio') : __('Show the widget on the dashboard', 'ux-studio'), $widget_on ? 'secondary' : 'primary', 'submit', false); ?>
            </form>

            <?php self::render_rescue_section($opts); ?>

            <div id="cp-audit-wrap">
                <?= self::render_audit_log_html() ?>
            </div>
        </div>
        <?php
    }

    /**
     * Info section about the rescue endpoint. No form - the endpoint is created and deleted
     * AUTOMATICALLY together with granting / expiring the access.
     * The current URL + key are shown at the top in "Access status" while it is active.
     */
    public static function render_rescue_section($opts) {
        $active = !empty($opts['access_active']) && intval($opts['access_expires_at']) > time();
        ?>
        <h2 style="margin-top: 2rem;">🛟 <?= esc_html__('Rescue access', 'ux-studio') ?> <small style="color:#6b7280;font-weight:normal">(<?= esc_html__('works even when WordPress is broken', 'ux-studio') ?>)</small></h2>

        <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:1rem 1.25rem;max-width:760px;">
            <p style="margin-top:0;color:#4b5563;font-size:13px;">
                <?= self::kses(__('A separate file <strong>outside WordPress</strong> for when a fatal error takes the whole site down (white screen, broken wp-admin). You log in to it and fix or delete the broken file <strong>without FTP</strong>. It can do <strong>file management only</strong> (no SQL, no shell) and makes a backup before every overwrite or delete.', 'ux-studio')) ?>
            </p>
            <p style="color:#4b5563;font-size:13px;">
                <?= self::kses(sprintf(
                    /* translators: %s: the IP address of the current admin */
                    __('<strong>There is nothing to set up.</strong> The endpoint is created <strong>automatically when you click "Allow access"</strong>: the key is the same password as the access, and login is limited to <strong>your current IP</strong> (%s). When the access <strong>expires</strong> or is <strong>removed</strong>, the endpoint <strong>deletes itself</strong>.', 'ux-studio'),
                    '<code>' . esc_html(self::client_ip()) . '</code>'
                )) ?>
            </p>
            <?php if ($active && !empty($opts['rescue_installed'])): ?>
                <p style="color:#15803d;font-size:13px;font-weight:600;margin-bottom:0;">● <?= esc_html__('Active now. You will find the URL and key at the top, in "Access status".', 'ux-studio') ?></p>
            <?php else: ?>
                <p style="color:#6b7280;font-size:13px;margin-bottom:0;"><?= esc_html__('Not active now. It appears once you allow access.', 'ux-studio') ?></p>
            <?php endif; ?>
            <div style="background:#fef3c7;border-left:3px solid #f59e0b;color:#92400e;padding:8px 12px;border-radius:3px;font-size:12px;margin:.8rem 0 0;">
                ⚠️ <?= self::kses(__('The endpoint is a back door protected by a key and limited to your IP. On <strong>nginx</strong> <code>.htaccess</code> does not apply, so check there that <code>config.php</code> next to the script cannot be downloaded (it returns nothing).', 'ux-studio')) ?>
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

        $base_url = admin_url('admin.php?page=ux-studio-ai-panel');
        $export_url = wp_nonce_url(add_query_arg('cp_export', 'csv', $base_url), 'cp_export_csv');

        ob_start();
        ?>
        <h2 style="margin-top: 2rem;"><?= esc_html__('Audit log', 'ux-studio') ?> <small style="color:#6b7280;font-weight:normal">(<?= esc_html(sprintf(
            /* translators: %d: number of audit log entries */
            _n('%d entry in total', '%d entries in total', $total, 'ux-studio'),
            $total
        )) ?>)</small></h2>

        <div class="cp-audit-toolbar">
            <span><?= esc_html__('Filter:', 'ux-studio') ?></span>
            <a href="<?= esc_url($base_url) ?>" class="button button-small <?= $filter === '' ? 'button-primary' : '' ?>"><?= esc_html__('All', 'ux-studio') ?></a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'access', $base_url)) ?>" class="button button-small <?= $filter === 'access' ? 'button-primary' : '' ?>"><?= esc_html__('Access', 'ux-studio') ?></a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'login', $base_url)) ?>" class="button button-small <?= $filter === 'login' ? 'button-primary' : '' ?>"><?= esc_html__('Login', 'ux-studio') ?></a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'file', $base_url)) ?>" class="button button-small <?= $filter === 'file' ? 'button-primary' : '' ?>"><?= esc_html__('Files', 'ux-studio') ?></a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'sql', $base_url)) ?>" class="button button-small <?= $filter === 'sql' ? 'button-primary' : '' ?>">SQL</a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'exec', $base_url)) ?>" class="button button-small <?= $filter === 'exec' ? 'button-primary' : '' ?>"><?= esc_html__('Shell', 'ux-studio') ?></a>
            <a href="<?= esc_url(add_query_arg('cp_filter', 'system', $base_url)) ?>" class="button button-small <?= $filter === 'system' ? 'button-primary' : '' ?>"><?= esc_html__('System', 'ux-studio') ?></a>
            <span class="spacer"></span>
            <a href="<?= esc_url($export_url) ?>" class="button button-small">⬇️ <?= esc_html__('Export CSV', 'ux-studio') ?></a>
            <button type="button" class="button button-small" data-cpw-clear-log>🗑️ <?= esc_html__('Clear the log', 'ux-studio') ?></button>
        </div>

        <?php if (empty($sliced)): ?>
            <p style="color:#6b7280;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:1rem;"><?= $filter
                ? esc_html(sprintf(
                    /* translators: %s: name of the active filter */
                    __('No audit entries yet (filter: %s).', 'ux-studio'),
                    $filter
                ))
                : esc_html__('No audit entries yet.', 'ux-studio') ?></p>
        <?php else: ?>
            <table class="widefat striped cp-audit">
                <thead>
                    <tr>
                        <th style="width:160px;"><?= esc_html__('Time', 'ux-studio') ?></th>
                        <th style="width:120px;"><?= esc_html__('IP', 'ux-studio') ?></th>
                        <th style="width:140px;"><?= esc_html__('Category', 'ux-studio') ?></th>
                        <th style="width:160px;"><?= esc_html__('Event', 'ux-studio') ?></th>
                        <th><?= esc_html__('Details', 'ux-studio') ?></th>
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
                        <span class="displaying-num"><?= esc_html(sprintf(
                            /* translators: %d: number of items */
                            _n('%d item', '%d items', $total, 'ux-studio'),
                            $total
                        )) ?></span>
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
            'login'  => '🔐 ' . __('Login', 'ux-studio'),
            'file'   => '📁 ' . __('File', 'ux-studio'),
            'sql'    => '🗄️ ' . __('SQL', 'ux-studio'),
            'exec'   => '💻 ' . __('Shell', 'ux-studio'),
            'access' => '🛡️ ' . __('Access', 'ux-studio'),
            'system' => '⚙️ ' . __('System', 'ux-studio'),
        ];
        return $map[$cat] ?? '⚙️ ' . __('System', 'ux-studio');
    }

    /* ============ MISC ============ */

    /** Escapes a translated string that may contain a few inline tags. */
    private static function kses($html) {
        return wp_kses((string) $html, ['strong' => [], 'code' => ['style' => []], 'br' => []]);
    }

    public static function format_duration($sec) {
        $sec = max(0, (int) $sec);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $parts = [];
        if ($h > 0) $parts[] = sprintf(/* translators: %d: hours */ __('%d h', 'ux-studio'), $h);
        if ($m > 0 || $h === 0) $parts[] = sprintf(/* translators: %d: minutes */ __('%d min', 'ux-studio'), $m);
        return implode(' ', $parts);
    }
}
