<?php
/**
 * Tiny File Manager configuration bridge for UX Studio.
 *
 * This file is automatically loaded by tinyfilemanager.php from __DIR__.
 * All security-relevant variables are overridden here.
 *
 * SECURITY: TFM's own auth is COMPLETELY DISABLED.
 * Authentication is handled exclusively by WordPress (Module.php).
 */

// ── Direct-access guard (fail-closed) ──
// Tento config vypina vlastni auth TFM, takze se SMI nacist VYHRADNE pres Module.php,
// ktery pred require definuje UXSTUDIO_FM_EMBEDDED (a predtim overi prihlaseni + whitelist).
// Pri primem HTTP pristupu na tinyfilemanager.php/config.php marker chybi -> ukoncit.
// tinyfilemanager.php includuje tento soubor inline, takze exit zastavi cely beh.
if (!defined('UXSTUDIO_FM_EMBEDDED')) {
    http_response_code(404);
    exit;
}

// ── Disable TFM's built-in authentication ──
$use_auth = false;
$auth_users = [];
$readonly_users = [];

// ── Read WP settings from globals (set by Module::loadTinyFileManager) ──
global $uxstudio_fm_root_path, $uxstudio_fm_readonly;

// ── Root path (normalize backslashes for Windows) ──
// SECURITY: fail-closed. Pokud Module.php nenastavil platný root (např. soubor je
// volán mimo standardní cestu), NEPADAT na DOCUMENT_ROOT (= celý web), ale na ABSPATH.
$uxstudio_fm_fallback_root = defined('ABSPATH') ? ABSPATH : __DIR__;
$root_path = isset($uxstudio_fm_root_path) && is_dir($uxstudio_fm_root_path)
    ? str_replace('\\', '/', $uxstudio_fm_root_path)
    : str_replace('\\', '/', $uxstudio_fm_fallback_root);

// ── Root URL ──
$root_url = '';

// ── Read-only mode ──
$global_readonly = !empty($uxstudio_fm_readonly);

// ── Language: Czech ──
$lang = 'cs';

// ── Date format ──
$datetime_format = 'd.m.Y H:i';

// ── Encoding ──
$iconv_input_encoding = 'UTF-8';

// ── Hide sensitive files ──
$exclude_items = [
    '.env',
    '.env.local',
    '.env.production',
    '.git',
    '.gitignore',
    '.gitmodules',
    'wp-config.php',
    '.htpasswd',
    '.user.ini',
];

// ── Upload limit: 50 MB ──
$max_upload_size_bytes = 50 * 1024 * 1024;

// ── Disable TFM IP filtering (WP handles access) ──
$ip_ruleset = 'OFF';
$ip_whitelist = [];
$ip_blacklist = [];

// ── UI preferences ──
$online_viewer = 'google';
$sticky_navbar = true;
$show_hidden_files = false;
$hide_Cols = false;
$theme = 'light';
