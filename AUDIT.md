# Audit modulů pluginu ux1-wordpress-customizer

Zdroj: `wp-content/plugins/ux1-wordpress-customizer/modules` (69 modulů + `BaseModule.php`).
Cíl: podklad pro přepis do samostatného pluginu **ux-studio** (React SPA + REST). **Nový plugin nebude mít free/pro vrstvy** — u modulů s podsložkou `pro/` se free i pro bere jako jeden celek k portu.

## Framework – jak se ukládají nastavení

Všechny moduly dědí z `Wpextended\Modules\BaseModule`. Nastavení definovaná v `getSettingsFields()` / čtená přes `$this->getSetting()` / `$this->getSettings()` ukládá framework (`includes/Utils.php`, konstanta `SETTINGS_PREFIX = 'wpextended__'`) do **jednoho** wp_option na modul:

```
wpextended__{module-id}_settings      // module-id doslova z meta.json (s pomlčkami)
```

Např. `wpextended__smtp-email_settings`, `wpextended__activity-log_settings`. V tabulkách níže se tento klíč označuje jako **framework: settings**. Kromě něj řada modulů ukládá vlastní explicitní `get_option/update_option` klíče a vlastní DB tabulky (prefix `wp_ux1_...`) – ty jsou vypsané doslovně.

Klasifikace náročnosti portu:
- **A** – triviální toggle / téměř bez UI (jen zapíná chování).
- **B** – settings formulář, zvládne ho schema renderer.
- **C** – vlastní pod-aplikace s vlastními obrazovkami, tabulkami a datovými toky.

---

## Souhrnná tabulka

| modul | UI způsob | skupina | tabulky | secrets | riziko |
|---|---|---|---|---|---|
| activity-log | admin stránka (list) + framework settings; vlastní DB | C | `ux1_activity_log` | – | přímé SQL, logování |
| admin-columns | framework settings (+ pro) | B | – | – | nízké |
| admin-customiser | framework settings, front enqueue (quick search) | B | – | – | nízké |
| ai-assistant | React/JS + rozsáhlé REST, admin menu, cron | C | 20+ `ux1_ai_assistant_*` (viz níže) | **OpenAI/Anthropic klíče, JWT** | AI klíče, RAG, front chat, cron |
| ai-markdown | framework settings + admin list + REST + cron | C | `ux1_ai_markdown_log`, `ux1_ai_markdown_cache` | AI klíč (přes settings) | generuje/serviruje MD, cron, bot detekce |
| auto-image-upload | framework settings + admin-ajax (bulk) | B | – | – | stahuje externí obrázky |
| auto-unpublish | bez settings, jen chování + cron | A | – | – | plánované odpublikování |
| bot-throttle | framework settings + admin menu + 3× ajax; vlastní DB | C | `ux1_bot_throttle_buckets`, `ux1_bot_throttle_log` | – | throttling requestů, logování |
| classic-editor | framework settings | A | – | – | nízké |
| classic-widgets | bez settings, jen toggle | A | – | – | nízké |
| **claude-panel** | dashboard widget + 3× ajax + **vlastní frontend routa `/cp-<hex>/`** | C | – (options) | **heslo (hash), rescue klíč (enc)** | **KRITICKÉ: vzdálený shell/SQL/zápis souborů** |
| clean-profiles | framework settings (checkboxy) | B | – | – | nízké |
| code-snippets | framework settings + admin UI + REST; **spouští PHP** | C | – (soubory na disku) | – | **spouští uživatelský PHP kód** |
| content-sync | admin menu (hub/node) + rozsáhlé REST (HMAC/SSO); vlastní DB | C | `ux1_content_sync_sites`, `ux1_content_sync_log`, `ux1_central_sso_tokens` | **HMAC shared secret, SSO tokeny** | vzdálený zápis obsahu, SSO, cross-site |
| cron-control | framework settings + 2× ajax + cron; **zapisuje mu-plugin/.htaccess** | C | – | – | zápis souborů (mu-plugin, .htaccess) |
| dashboard-widgets | admin dashboard + 5× REST + cron | C | – (options) | **Google PSI + GA service-account JSON** | ukládá GA credentials |
| debug-mode | bez settings (free); pro toggle WP_DEBUG | A | – | – | čte/přepíná WP_DEBUG |
| disable-auto-updates | framework settings | A | – | – | nízké |
| disable-video-uploads | bez settings, toggle | A | – | – | nízké |
| download-files | framework settings + REST + shortcode + front enqueue | B/C | – | – | veřejné stahování souborů |
| duplicate-menu | framework settings + 1× REST | A | – | – | nízké |
| duplicate-post | framework settings + 1× REST | A | – | – | nízké |
| elementor-import | bez settings + 3× REST | B | – | – | import/export obsahu |
| email-health | framework settings + admin menu + 1× ajax + cron | B | – | – | posílá testovací mail (mail-tester) |
| email-log | admin menu (list) + 2× REST; vlastní DB | C | `ux1_email_log` | – | loguje obsah e-mailů |
| exit-popup | framework settings + admin menu + 1× REST + shortcode/front; vlastní DB | C | `ux1_exit_popup_emails` | – | sbírá e-maily návštěvníků |
| export-posts | framework settings | B | – | – | export dat |
| export-users | framework settings | B | – | – | export osobních údajů |
| external-permalinks | framework settings | B | – | – | nízké |
| file-manager | admin menu → **tinyfilemanager** | C | – | – | **plný správce souborů serveru** |
| folder-manager | framework settings + 8× REST | C | – (taxonomie/meta) | – | organizace médií |
| google-review-request | framework settings + REST (Stats); vlastní DB | C | `ux1_grr_stats` | Google odkaz (bez klíče) | odesílání žádostí o recenzi |
| guide | admin menu + front enqueue + cron | B | – | – | indexing check |
| hide-admin-bar | framework settings | A | – | – | nízké |
| image-optimizer | admin menu + **15× ajax** + cron (background) | C | – | – | hromadná manipulace se soubory médií |
| indexing-notice | bez settings, notice | A | – | – | nízké |
| instagram-feed | admin menu + front enqueue + cron; vlastní DB | C | `ux1_instagram_feeds`, `ux1_instagram_media` | **IG/FB token (centrála)** | externí API, tokeny |
| link-manager | framework settings | B | – | – | nízké |
| maintenance-mode | framework settings | B | – | – | přepíná veřejný web |
| media-replace | bez settings + REST (pro) | B | – | – | přepis souborů médií |
| media-trash | bez settings, toggle | A | – | – | nízké |
| menu-visibility | bez settings | B | – | – | nízké |
| notice-board | admin menu + 12× REST + shortcode + front; vlastní DB (úřední deska) | C | `ux1_notice_board`, `ux1_notice_board_categories`, `ux1_notice_board_subscriptions` | – | veřejné dokumenty, odběry |
| opening-hours | framework settings + REST + **6 shortcodů** + front; mapy | C | – (options/CPT) | **Mapy.cz / Google Maps klíč** | veřejný výstup, mapové API |
| page-load | admin menu + 1× ajax + cron; vlastní DB | C | `ux1_page_load_log`, `ux1_page_load_events` | – | logování requestů |
| performance-optimization | admin menu + **9× ajax** + REST + cron; vlastní DB | C | `ux1_query_log`, `ux1_performance_history` | – | „fix" akce mění konfiguraci |
| pixel-tag-manager | framework settings | B | – | – | vkládá tracking skripty |
| popup-manager | framework settings + admin menu + REST + front; vlastní DB | C | `ux1_popup_stats` | – | veřejný výstup |
| post-gallery | framework settings + shortcode + front | B | – | – | veřejný výstup |
| post-id-display | framework settings | A | – | – | nízké |
| post-type-order | framework settings + 1× REST | B | – | – | nízké |
| post-type-switcher | framework settings + ajax + REST (pro) | B | – | – | mění typ příspěvku (SQL) |
| push-notifications | admin menu + **14× REST** + cron; vlastní DB; service worker | C | `ux1_push_subscribers`, `ux1_push_notifications`, `ux1_push_segments`, `ux1_push_events` | **VAPID klíče (enc), FCM** | web-push, odběratelé, front SW |
| quick-add-post | framework settings | A | – | – | nízké |
| quick-image | framework settings + REST (pro) | B | – | – | nízké |
| redirect-404-to-homepage | bez settings, toggle | A | – | – | nízké |
| **reservation-calendar** | **VYŘAZEN – booking** | – | (neauditováno) | – | mimo scope |
| review-aggregator | framework settings + admin menu + REST + shortcode + front + cron; vlastní DB | C | `ux1_reviews` | **import token** | externí recenze, veřejný výstup |
| rollback-manager | framework settings + 3× REST | C | – | – | přepisuje verze pluginů/témat |
| security-optimization | framework settings + admin menu + 3× ajax + REST + cron; vlastní DB; upload-guard | C | `ux1_ip_bans`, `ux1_ip_ban_ranges`, `ux1_csp_violations`, `wpext_login_attempt`, `wpext_login_failed` | – | login hardening, IP bany, .htaccess |
| service-requests | admin menu + 7× REST; vlastní DB | C | `ux1_service_requests` | – | správa požadavků |
| smtp-email | framework settings + REST + cron; vlastní DB (log, pro) | C | `wpext_logs` | **SMTP heslo, Gmail OAuth refresh_token, Brevo API klíč** | odesílání pošty, secrets |
| stock-photos | framework settings + REST | C | – | **Unsplash/Pexels/Pixabay/Giphy/Openverse API klíče** | externí API klíče |
| svg-upload | framework settings | B | – | – | **nahrávání SVG (XSS riziko)** |
| third-party-login | framework settings + REST + 1× ajax + front | C | – | **Google/Facebook/Apple/Seznam OAuth secrets** | **SSO/auth, secrets** |
| top-bar | framework settings + front enqueue | B | – | – | veřejný výstup |
| user-last-login | framework settings | B | – | – | nízké |
| user-switching | bez settings | B | – | – | **přepínání identity uživatele** |
| vulnerability-scanner | admin menu + 4× REST | C | – (options) | WPScan/wpscan.com | skenování zranitelností |

---

## Detaily per modul

> Framework klíč `wpextended__{id}_settings` je u modulů s `settings: true` implicitní a dále se neopakuje, pokud není potřeba zdůraznit. Uvedeny jsou **vlastní** option klíče a tabulky doslova z kódu.

### activity-log (Log aktivit)
- **UI:** admin stránka se seznamem záznamů + framework settings.
- **Options:** `ux1_activity_log_db_version`. Framework settings.
- **Tabulky:** `{$wpdb->prefix}ux1_activity_log`.
- **Cron:** – (čištění se řeší jinak). **Frontend:** –.
- **Skupina C.** **Rizika:** přímé SQL, ukládá historii akcí uživatelů.

### admin-columns (Admin Columns)
- **UI:** framework settings (+ `pro/`). **Options:** framework + `wpextended__admin-columns_defaults` (pro). **Skupina B.**

### admin-customiser (Admin Customiser)
- **UI:** framework settings; pro quick-search 1× REST (`admin-customiser/pro/includes/QuickSearch.php`) + front enqueue.
- **Options:** framework + `wpext_search_module_info`. **Skupina B.**

### ai-assistant (AI Asistent)
- **UI:** admin menu + rozsáhlé REST (Controller 24, BackendController 16, RagController 10, HandoffController 11, BlogPilotController 9, KnowledgeHubController 4, JwtAuth 3) + JS front chat (`wp_enqueue_scripts`). Cron.
- **Options:** framework; `ux1_ai_assistant_blog_pilot_db_version`, `ux1_ai_assistant_keys_migrated`. AI klíče (OpenAI/Anthropic) a JWT secret v settings.
- **Tabulky:** `ux1_ai_assistant_conversations`, `_chat_history`, `_content_index`, `_product_index`, `_vectors`, `_knowledge`, `_documents`, `_faqs`, `_training_sources`, `_inquiries`, `_error_log`, `_usage`, `_blog_generators`, `_blog_generated_posts` (vše s prefixem `wp_ux1_ai_assistant_`).
- **Cron:** `ux1_ai_assistant_daily_reindex` (daily), `ux1_ai_assistant_vector_batch`, `ux1_ai_assistant_blog_pilot_generate`.
- **Frontend:** JS chat widget na frontendu.
- **Skupina C.** **Rizika:** AI klíče, RAG/embeddingy, JWT auth pro externí backend, generování obsahu, front výstup.

### ai-markdown (AI Markdown)
- **UI:** framework settings + admin list (`ListTable`) + REST (9) + cron.
- **Tabulky:** `ux1_ai_markdown_log`, `ux1_ai_markdown_cache`.
- **Cron:** `ux1_ai_markdown_daily_check` (daily). **Secrets:** AI klíč přes settings.
- **Skupina C.** **Rizika:** serviruje markdown variantu stránek botům, bot detekce, cron.

### auto-image-upload (Automatický upload obrázku)
- **UI:** framework settings + admin-ajax `wp_ajax_ux1_auto_image_upload_bulk`. **Skupina B.** Stahuje externí obrázky do knihovny médií.

### auto-unpublish (Automatické odpublikování)
- **UI:** bez settings; jen plánování. **Cron:** `ux1_auto_unpublish_post` (single event per příspěvek). **Skupina A.**

### bot-throttle (Bot Throttle)
- **UI:** framework settings + admin menu + 3× ajax (`ux1_bot_throttle_clear_cache`, `_clear_log`, `_test_ua`).
- **Tabulky:** `ux1_bot_throttle_buckets`, `ux1_bot_throttle_log`. **Skupina C.** **Rizika:** throttling požadavků, logování IP/UA.

### classic-editor (Klasický editor)
- **UI:** framework settings. **Skupina A.**

### classic-widgets (Klasické widgety)
- **UI:** bez settings, toggle. **Skupina A.**

### claude-panel (Claude Panel)  — KRITICKÉ RIZIKO
- **UI:** dashboard widget + admin submenu `ux1-claude-panel` + 3× ajax (`cp_grant_ajax`, `cp_revoke_ajax`, `cp_clear_log_ajax`) + **vlastní veřejná frontend routa `/cp-<32hex>/`** (init@0), plus standalone „rescue" endpoint mimo WordPress.
- **Options:** `claude_panel_settings` (konstanta `CP_OPTION`), `claude_panel_audit` (`CP_AUDIT_OPT`, log 200 záznamů), `claude_panel_attempts` (`CP_ATTEMPTS_OPT`). **Nepoužívá** framework settings.
- **Secrets:** `access_password_hash` (hash jednorázového hesla), `rescue_key_enc` (klíč šifrovaný WP salty), `last_grant_password`.
- **Cron:** `cp_rescue_expire` (single, naplánovaný při grantu). **Tabulky:** –.
- **Skupina C.** **Rizika: NEJVYŠŠÍ** — panel umožňuje vzdálené spouštění shellu (`exec`), SQL dotazů, čtení/zápis/patch souborů serveru. Kill-switch `define('UX1_DISABLE_CLAUDE_PANEL', true)`. Do nového pluginu portovat jen s maximální opatrností (nebo vynechat / oddělit).
- **Migrace:** options `claude_panel_*` jsou mimo framework – přenést zvlášť.

### clean-profiles (Vyčistit profily)
- **UI:** framework settings (checkboxy sekcí/polí profilu). **Skupina B.**

### code-snippets (Kódové fragmenty)  — spouští PHP
- **UI:** framework settings + vlastní admin UI + REST (6 rout, `code-snippets/rest/Controller.php`).
- **Úložiště:** snippety se ukládají jako **soubory na disku** — `WP_CONTENT_DIR . '/ux1-snippets'` (index.php „Silence is golden"), PHP se spouští přes `include $filePath` (`SnippetExecutor.php`). Metadata pravděpodobně v options/CPT.
- **Tabulky:** –. **Skupina C.** **Rizika: VYSOKÉ** — spouští uživatelský PHP kód, zápis do wp-content, `manage_options` gate. Migrace: přenést adresář `ux1-snippets/` + metadata.

### content-sync (Vzdálená správa obsahu)
- **UI:** admin submenu; režim `disabled` / `hub` / `node` (`Utils::getSetting('content-sync','mode')`). REST: NodeController 34, HubController 11, SsoController 3 (namespace `wpextended/v1`, base `/content-sync/...`: `/ping`, `/posts`, `/posts/{id}`, `/sso/issue`, `/sso/revoke-all`, `/sso/sync-operators`, …).
- **Auth:** HMAC (`HmacAuth::sign` = `hash_hmac('sha256', …, $secretKey)`) + SSO tokeny.
- **Options:** framework (mode, secret). Také čte cizí options v NodeController (agregace: `ux1_email_health_result`, `ux1_cron_watch_result`, `ux1_mail_tester_result`, `lwug_findings`…).
- **Tabulky:** `ux1_content_sync_sites`, `ux1_content_sync_log`, `ux1_central_sso_tokens`. Čte i `ux1_service_requests`.
- **Cron:** `ux1_content_sync_sso_cleanup` (daily, jen node). **Secrets:** HMAC shared secret, SSO tokeny.
- **Skupina C.** **Rizika:** vzdálený zápis obsahu na jiné weby, SSO přihlašování, cross-site komunikace.

### cron-control (Zákaz WP-Cron)  — zápis souborů
- **UI:** framework settings + admin UI + 2× ajax (`ux1_cron_control_resync`, `ux1_cron_watch_run`) + cron watcher.
- **Zápis souborů:** vytváří **mu-plugin `ux1-cron-control.php`** (`DISABLE_WP_CRON=true`) a upravuje **.htaccess** (`Require all denied` / `Require local`) — marker `UX1 Cron Control`.
- **Options:** `ux1_cron_control_hash` (`HASH_OPTION`), `ux1_cron_control_remote_result` (`REMOTE_RESULT_OPTION`), `ux1_cron_watch_result` (`CronWatcher::RESULT_OPTION`). Framework settings pod `wpextended__cron-control`.
- **Cron:** `ux1_cron_watch_daily` (daily). **Tabulky:** –.
- **Skupina C.** **Rizika:** zápis mu-plugin a .htaccess, ovlivňuje spouštění cronu celého webu.

### dashboard-widgets (Widgety domovské obrazovky)
- **UI:** admin dashboard render + 5× REST (`/dashboard-widgets/pagespeed/run`, `/analytics/refresh`, `/save-layout`, `/tasks`, `/notes`) + cron.
- **Options:** `ux1_dashboard_forced_layout`, `ux1_dashboard_forced_closed`, `ux1_dashboard_tasks`, `ux1_dashboard_notes`. Framework settings obsahují `ga_property_id`, `ga_service_account_json` (GA), toggly widgetů.
- **Secrets:** Google PageSpeed Insights klíč + **GA service-account JSON** (v settings). **Cron:** `ux1_dashboard_pagespeed_refresh` (daily 03:00).
- **Skupina C.** **Rizika:** ukládá Google service-account credentials; čte cizí tabulky (`e_submissions`, `ux1_activity_log`, `ux1_service_requests`, `wpext_login_failed`).

### debug-mode (Ladicí mód)
- **UI:** free bez settings; `pro/Bootstrap.php` přidává admin notices + toggle `WP_DEBUG` (`maybeDisableModule`, čte `WP_DEBUG`). **Skupina A.** **Rizika:** čte/přepíná debug stav (může sahat na wp-config).

### disable-auto-updates (Zakázat automatické aktualizace)
- **UI:** framework settings. **Skupina A.**

### disable-video-uploads (Zakázat nahrávání videí)
- **UI:** bez settings, toggle (filtr povolených typů). **Skupina A.**

### download-files (Soubory ke stažení)
- **UI:** framework settings + REST (3) + shortcode `[ux1_download_files]` + front enqueue.
- **Skupina B/C** (veřejný shortcode + REST). **Rizika:** veřejné stahování souborů.

### duplicate-menu (Duplikovat menu)
- **UI:** framework settings + 1× REST. **Skupina A.**

### duplicate-post (Duplikovat příspěvky a stránky)
- **UI:** framework settings + 1× REST. **Skupina A.**

### elementor-import (Elementor Import/Export)
- **UI:** bez settings + 3× REST (`elementor-import/Bootstrap.php`). **Skupina B.** **Rizika:** import/export obsahu Elementoru.

### email-health (Kontrola emailu)
- **UI:** framework settings + admin menu + 1× ajax `ux1_mail_tester_run` + cron.
- **Options:** `ux1_email_health_result`. **Cron:** `ux1_mail_tester_weekly` (interval `ux1_weekly`). **Skupina B.** **Rizika:** posílá testovací e-mail na mail-tester.com.

### email-log (Log emailů)
- **UI:** admin menu (list) + 2× REST. **Options:** `ux1_email_log_db_version`.
- **Tabulky:** `ux1_email_log`. **Skupina C.** **Rizika:** ukládá obsah odchozích e-mailů.

### exit-popup (Už odcházíte?)
- **UI:** framework settings + admin menu + 1× REST `/exit-popup/subscribe` + front (popup) + `EmailListTable`.
- **Tabulky:** `ux1_exit_popup_emails`. **Skupina C.** **Rizika:** sbírá e-maily návštěvníků (GDPR), veřejný výstup.

### export-posts (Exportovat příspěvky)
- **UI:** framework settings. **Skupina B.** Export dat.

### export-users (Exportovat uživatele)
- **UI:** framework settings. **Skupina B.** **Rizika:** export osobních údajů.

### external-permalinks (Externí trvalé odkazy)
- **UI:** framework settings (meta box). **Skupina B.**

### file-manager (Správce souborů)  — plný správce serveru
- **UI:** admin menu → embeduje **tinyfilemanager** (`tinyfilemanager.php`, `config.php`) přes autorizovanou WP routu (`WP_EXTENDED_PATH`).
- **Tabulky:** –. **Options:** framework. **Skupina C.** **Rizika: VYSOKÉ** — plný přístup k souborovému systému serveru (čtení/zápis/mazání/upload/editace).

### folder-manager (Správce složek)
- **UI:** framework settings + 8× REST (`folder-manager/rest/Controller.php`). Organizace médií do složek (taxonomie/meta).
- **Skupina C.** **Rizika:** reorganizace knihovny médií.

### google-review-request (Žádost o recenzi)
- **UI:** framework settings + REST (Stats). **Tabulky:** `ux1_grr_stats`. **Skupina C.** **Rizika:** odesílání žádostí o Google recenzi.

### guide (Návod)
- **UI:** admin menu + front enqueue + cron. **Cron:** `ux1_guide_check_indexing` (daily). **Skupina B.**

### hide-admin-bar (Skrýt administrační lištu)
- **UI:** framework settings. **Skupina A.**

### image-optimizer (Optimalizace obrázků)
- **UI:** admin menu + **15× ajax** (`ux1_io_bg_start`, `_bg_stop`, `_bg_progress`, `_bg_clear`, …) + cron (background optimizer).
- **Cron:** `ux1_io_background_optimize`. **Options:** framework; čte `site_icon`, `widget_*`. **Skupina C.** **Rizika:** hromadné přepisování/manipulace se soubory médií.

### indexing-notice (Oznámení o indexaci)
- **UI:** bez settings, admin notice (čte `blog_public`). **Skupina A.**

### instagram-feed (Instagram Feed)
- **UI:** admin menu + front enqueue + cron. **Tabulky:** `ux1_instagram_feeds`, `ux1_instagram_media`.
- **Options:** `ux1_instagram_last_sync_*`. **Cron:** `ux1_instagram_feed_cron`. **Secrets:** IG/FB access token (autoritativní záznam v centrále dle `ConnectionManager`). **Skupina C.** **Rizika:** externí API, tokeny, front výstup.

### link-manager (Správce odkazů)
- **UI:** framework settings. **Skupina B.**

### maintenance-mode (Režim údržby)
- **UI:** framework settings. **Skupina B.** **Rizika:** vypíná veřejný web.

### media-replace (Nahradit médium)
- **UI:** bez settings + REST (pro). **Skupina B.** **Rizika:** přepis souborů médií.

### media-trash (Koš médií)
- **UI:** bez settings, toggle. **Skupina A.**

### menu-visibility (Viditelnost menu)
- **UI:** bez settings (per-item nastavení menu). **Skupina B.**

### notice-board (Úřední nástěnka)
- **UI:** admin menu + 12× REST + shortcode `[uredni_nastenka]` + front enqueue.
- **Options:** `ux1_notice_board_page_id`, `ux1_notice_board_db_version`, `ux1_nb_feed_version`.
- **Tabulky:** `ux1_notice_board`, `ux1_notice_board_categories`, `ux1_notice_board_subscriptions`. **Skupina C.** **Rizika:** veřejné dokumenty úřední desky, e-mailové odběry.

### opening-hours (Pobočky / Otevírací doba)
- **UI:** framework settings + REST (RestController 4, Widgets 1) + **6 shortcodů** (`oh_status`, `oh_week`, `oh_list`, `oh_next_open`, `oh_banner`, `oh_map`, `oh_widget`) + front (mapy).
- **Secrets:** Mapy.cz / Google Maps API klíč (v settings). **Skupina C.** **Rizika:** veřejný výstup, mapové API klíče.

### page-load (Doba načítání)
- **UI:** admin menu + 1× ajax `ux1_page_load_chart_data` + cron. **Options:** `ux1_page_load_schema_version`.
- **Tabulky:** `ux1_page_load_log`, `ux1_page_load_events`. **Skupina C.** **Rizika:** logování requestů.

### performance-optimization (Výkon a optimalizace)
- **UI:** admin menu + **9× ajax** (`ux1_perf_analyze`, `_analyze_category`, `_fix`, `_history`, …) + REST (5) + cron.
- **Options:** `ux1_query_monitor_settings`. **Tabulky:** `ux1_query_log`, `ux1_performance_history` (čte i `actionscheduler_actions`).
- **Skupina C.** **Rizika:** „fix" akce mění konfiguraci webu, monitorování dotazů.

### pixel-tag-manager (Správce pixelových tagů)
- **UI:** framework settings. **Skupina B.** **Rizika:** vkládá tracking skripty (GTM/FB pixel) do stránek.

### popup-manager (Popup Manager)
- **UI:** framework settings + admin menu + REST (Stats 2) + front enqueue. **Tabulky:** `ux1_popup_stats`. **Skupina C.** **Rizika:** veřejný výstup.

### post-gallery (Galerie k článku)
- **UI:** framework settings + shortcode `[ux1_galerie]` + front. **Skupina B.**

### post-id-display (Zobrazení ID příspěvku)
- **UI:** framework settings. **Skupina A.**

### post-type-order (Pořadí typů příspěvků)
- **UI:** framework settings + 1× REST. **Skupina B.**

### post-type-switcher (Přepínač typů příspěvků)
- **UI:** framework settings + ajax `save_bulk_edit` + REST (pro). **Skupina B.** **Rizika:** mění post_type (přímé SQL).

### push-notifications (Push notifikace)
- **UI:** admin menu + **14× REST** (`push-notifications/rest/Controller.php`) + cron + service worker na frontendu.
- **Options:** `ux1_push_db_version`, `ux1_push_vapid_keys` (`VapidManager::OPTION_KEY`, hodnota šifrovaná).
- **Tabulky:** `ux1_push_subscribers`, `ux1_push_notifications`, `ux1_push_segments`, `ux1_push_events`.
- **Secrets:** VAPID klíče (enc), FCM (`fcm.googleapis.com`). **Skupina C.** **Rizika:** web-push, správa odběratelů, front service worker.

### quick-add-post (Rychlé přidání příspěvku)
- **UI:** framework settings. **Skupina A.**

### quick-image (Rychlý obrázek)
- **UI:** framework settings + REST (pro). **Skupina B.**

### redirect-404-to-homepage (Přesměrovat 404 na domovskou stránku)
- **UI:** bez settings, toggle. **Skupina A.**

### reservation-calendar (Rezervační kalendář) — VYŘAZEN – booking
- Modul existuje (meta id `reservation-calendar`, „Rezervační kalendář"), obsahuje vlastní tabulky a StandaloneTokenManager. **Neauditováno detailně — mimo scope nového pluginu.**

### review-aggregator (Agregátor recenzí)
- **UI:** framework settings + admin menu + REST (5) + shortcode `[ux1_reviews]` + front + cron.
- **Options:** `ux1_review_aggregator_import_token`, `ux1_review_aggregator_last_sync`.
- **Tabulky:** `ux1_reviews`. **Cron:** `ux1_review_aggregator_fetch`. **Secrets:** import token. **Skupina C.** **Rizika:** stahování externích recenzí, veřejný výstup.

### rollback-manager (Správce vrácení verzí)
- **UI:** framework settings + 3× REST. **Skupina C.** **Rizika:** přepisuje verze pluginů/témat (stahuje a instaluje soubory).

### security-optimization (Zabezpečení a optimalizace)
- **UI:** framework settings + admin menu + 3× ajax (`lwug_manual_scan`, `lwug_dismiss_notice`, `ux1_disable_canonical_redirect`) + REST (RestApi 3, CspReportController 1) + cron + upload-guard subsystém.
- **Options:** `ux1_security_htaccess_hash`, `ux1_csp_violations_db_version`; čte cizí `wpextended__custom-login-url_settings`, `wpextended__limit-login-attempts_settings`, `wpextended__modules_settings`, `wpextended__security-optimization`.
- **Tabulky:** `ux1_ip_bans`, `ux1_ip_ban_ranges`, `ux1_csp_violations`, `wpext_login_attempt`, `wpext_login_failed`.
- **Cron:** `lwug_nightly_scan` (daily), další upload-guard daily. **Skupina C.** **Rizika:** login hardening, IP bany, .htaccess zápis, CSP, sken uploadů.

### service-requests (Servisní požadavky)
- **UI:** admin menu + 7× REST. **Tabulky:** `ux1_service_requests`. **Skupina C.** **Rizika:** správa požadavků (osobní data).

### smtp-email (Odesílání pošty)
- **UI:** framework settings + REST (Bootstrap 1 + pro 4) + cron.
- **Tabulky:** `wpext_logs` (pro LogHandler). **Cron:** `wpextended_smtp_email_purge_logs` (daily).
- **Secrets:** SMTP heslo, **Gmail OAuth `refresh_token`** (`GmailClient`/`GmailOAuth`), Brevo API klíč — vše přes framework settings.
- **Skupina C.** **Rizika:** odesílání pošty, ukládání přístupových údajů (secrets).

### stock-photos (Fotobanky)
- **UI:** framework settings + REST (3). **Secrets:** API klíče Unsplash (`api.unsplash.com`), Pexels (`api.pexels.com`), Pixabay (`pixabay.com/api`), Giphy (`api.giphy.com`), Openverse (`api.openverse.org`) — v settings. **Skupina C.** **Rizika:** externí API klíče.

### svg-upload (Nahrávání SVG souborů)
- **UI:** framework settings. **Skupina B.** **Rizika:** povolení SVG uploadů (potenciál XSS – sanitizace).

### third-party-login (Přihlašování třetí stranou)
- **UI:** framework settings + REST (1) + 1× ajax `ux1_tpl_dismiss_notice` + front (login tlačítka).
- **Secrets:** OAuth client_id/secret pro Google, Facebook, Apple, Seznam — v settings.
- **Skupina C.** **Rizika: VYSOKÉ** — SSO/autentizace, ukládání OAuth secrets.

### top-bar (Top lišta)
- **UI:** framework settings + front enqueue. **Skupina B.** Veřejný výstup lišty.

### user-last-login (Poslední přihlášení uživatele)
- **UI:** framework settings (+ user meta s časem přihlášení). **Skupina B.**

### user-switching (Přepínání uživatelů)
- **UI:** bez settings (odkazy v seznamu uživatelů). **Skupina B.** **Rizika:** přepínání identity uživatele (impersonace).

### vulnerability-scanner (Skener zranitelností)
- **UI:** admin menu + 4× REST. **Options:** `ux1_vulnerability_notice_dismissed`. **Secrets:** WPScan (`wpscan.com`) – volitelně API token. **Skupina C.** **Rizika:** volání externí databáze zranitelností.

---

## Migrační mapa dat

Nový prefix: **`uxstudio_`** (options i tabulky). Framework nastavení každého modulu doporučeno přenést pod jednotné `uxstudio_{modul}_settings` (místo `wpextended__{modul}_settings`). Mimo `reservation-calendar`.

### A) Framework settings options (per modul)

Všechny existující `wpextended__{id}_settings` → `uxstudio_{id}_settings` (id se zjednodušeným podtržítkem dle konvence nového pluginu). Týká se všech modulů se `settings: true`. Kromě toho tyto cizí framework klíče čtené modulem security-optimization je nutné buď přejmenovat, nebo mapovat: `wpextended__custom-login-url_settings`, `wpextended__limit-login-attempts_settings`, `wpextended__modules_settings`, `wpextended__security-optimization`.

### B) Vlastní option klíče

| starý option klíč | modul | nový název |
|---|---|---|
| `ux1_activity_log_db_version` | activity-log | `uxstudio_activity_log_db_version` |
| `wpextended__admin-columns_defaults` | admin-columns | `uxstudio_admin_columns_defaults` |
| `wpext_search_module_info` | admin-customiser | `uxstudio_search_module_info` |
| `ux1_ai_assistant_blog_pilot_db_version` | ai-assistant | `uxstudio_ai_assistant_blog_pilot_db_version` |
| `ux1_ai_assistant_keys_migrated` | ai-assistant | `uxstudio_ai_assistant_keys_migrated` |
| `ux1_email_health_result` | email-health | `uxstudio_email_health_result` |
| `ux1_email_log_db_version` | email-log | `uxstudio_email_log_db_version` |
| `ux1_instagram_last_sync_*` | instagram-feed | `uxstudio_instagram_last_sync_*` |
| `ux1_notice_board_page_id` | notice-board | `uxstudio_notice_board_page_id` |
| `ux1_notice_board_db_version` | notice-board | `uxstudio_notice_board_db_version` |
| `ux1_nb_feed_version` | notice-board | `uxstudio_notice_board_feed_version` |
| `ux1_page_load_schema_version` | page-load | `uxstudio_page_load_schema_version` |
| `ux1_query_monitor_settings` | performance-optimization | `uxstudio_query_monitor_settings` |
| `ux1_push_db_version` | push-notifications | `uxstudio_push_db_version` |
| `ux1_push_vapid_keys` | push-notifications | `uxstudio_push_vapid_keys` (secret, enc) |
| `ux1_review_aggregator_import_token` | review-aggregator | `uxstudio_review_aggregator_import_token` (secret) |
| `ux1_review_aggregator_last_sync` | review-aggregator | `uxstudio_review_aggregator_last_sync` |
| `ux1_security_htaccess_hash` | security-optimization | `uxstudio_security_htaccess_hash` |
| `ux1_csp_violations_db_version` | security-optimization | `uxstudio_csp_violations_db_version` |
| `ux1_vulnerability_notice_dismissed` | vulnerability-scanner | `uxstudio_vulnerability_notice_dismissed` |
| `ux1_dashboard_forced_layout` | dashboard-widgets | `uxstudio_dashboard_forced_layout` |
| `ux1_dashboard_forced_closed` | dashboard-widgets | `uxstudio_dashboard_forced_closed` |
| `ux1_dashboard_tasks` | dashboard-widgets | `uxstudio_dashboard_tasks` |
| `ux1_dashboard_notes` | dashboard-widgets | `uxstudio_dashboard_notes` |
| `ux1_cron_control_hash` | cron-control | `uxstudio_cron_control_hash` |
| `ux1_cron_control_remote_result` | cron-control | `uxstudio_cron_control_remote_result` |
| `ux1_cron_watch_result` | cron-control | `uxstudio_cron_watch_result` |
| `claude_panel_settings` | claude-panel | `uxstudio_claude_panel_settings` (obsahuje secrets) |
| `claude_panel_audit` | claude-panel | `uxstudio_claude_panel_audit` |
| `claude_panel_attempts` | claude-panel | `uxstudio_claude_panel_attempts` |

### C) Custom DB tabulky (prefix `wp_` = `$wpdb->prefix`)

| stará tabulka | modul | nová tabulka |
|---|---|---|
| `ux1_activity_log` | activity-log | `uxstudio_activity_log` |
| `ux1_ai_assistant_conversations` | ai-assistant | `uxstudio_ai_assistant_conversations` |
| `ux1_ai_assistant_chat_history` | ai-assistant | `uxstudio_ai_assistant_chat_history` |
| `ux1_ai_assistant_content_index` | ai-assistant | `uxstudio_ai_assistant_content_index` |
| `ux1_ai_assistant_product_index` | ai-assistant | `uxstudio_ai_assistant_product_index` |
| `ux1_ai_assistant_vectors` | ai-assistant | `uxstudio_ai_assistant_vectors` |
| `ux1_ai_assistant_knowledge` | ai-assistant | `uxstudio_ai_assistant_knowledge` |
| `ux1_ai_assistant_documents` | ai-assistant | `uxstudio_ai_assistant_documents` |
| `ux1_ai_assistant_faqs` | ai-assistant | `uxstudio_ai_assistant_faqs` |
| `ux1_ai_assistant_training_sources` | ai-assistant | `uxstudio_ai_assistant_training_sources` |
| `ux1_ai_assistant_inquiries` | ai-assistant | `uxstudio_ai_assistant_inquiries` |
| `ux1_ai_assistant_error_log` | ai-assistant | `uxstudio_ai_assistant_error_log` |
| `ux1_ai_assistant_usage` | ai-assistant | `uxstudio_ai_assistant_usage` |
| `ux1_ai_assistant_blog_generators` | ai-assistant | `uxstudio_ai_assistant_blog_generators` |
| `ux1_ai_assistant_blog_generated_posts` | ai-assistant | `uxstudio_ai_assistant_blog_generated_posts` |
| `ux1_ai_markdown_log` | ai-markdown | `uxstudio_ai_markdown_log` |
| `ux1_ai_markdown_cache` | ai-markdown | `uxstudio_ai_markdown_cache` |
| `ux1_bot_throttle_buckets` | bot-throttle | `uxstudio_bot_throttle_buckets` |
| `ux1_bot_throttle_log` | bot-throttle | `uxstudio_bot_throttle_log` |
| `ux1_content_sync_sites` | content-sync | `uxstudio_content_sync_sites` |
| `ux1_content_sync_log` | content-sync | `uxstudio_content_sync_log` |
| `ux1_central_sso_tokens` | content-sync | `uxstudio_central_sso_tokens` |
| `ux1_email_log` | email-log | `uxstudio_email_log` |
| `ux1_exit_popup_emails` | exit-popup | `uxstudio_exit_popup_emails` |
| `ux1_grr_stats` | google-review-request | `uxstudio_grr_stats` |
| `ux1_instagram_feeds` | instagram-feed | `uxstudio_instagram_feeds` |
| `ux1_instagram_media` | instagram-feed | `uxstudio_instagram_media` |
| `ux1_notice_board` | notice-board | `uxstudio_notice_board` |
| `ux1_notice_board_categories` | notice-board | `uxstudio_notice_board_categories` |
| `ux1_notice_board_subscriptions` | notice-board | `uxstudio_notice_board_subscriptions` |
| `ux1_page_load_log` | page-load | `uxstudio_page_load_log` |
| `ux1_page_load_events` | page-load | `uxstudio_page_load_events` |
| `ux1_query_log` | performance-optimization | `uxstudio_query_log` |
| `ux1_performance_history` | performance-optimization | `uxstudio_performance_history` |
| `ux1_popup_stats` | popup-manager | `uxstudio_popup_stats` |
| `ux1_push_subscribers` | push-notifications | `uxstudio_push_subscribers` |
| `ux1_push_notifications` | push-notifications | `uxstudio_push_notifications` |
| `ux1_push_segments` | push-notifications | `uxstudio_push_segments` |
| `ux1_push_events` | push-notifications | `uxstudio_push_events` |
| `ux1_reviews` | review-aggregator | `uxstudio_reviews` |
| `ux1_ip_bans` | security-optimization | `uxstudio_ip_bans` |
| `ux1_ip_ban_ranges` | security-optimization | `uxstudio_ip_ban_ranges` |
| `ux1_csp_violations` | security-optimization | `uxstudio_csp_violations` |
| `wpext_login_attempt` | security-optimization | `uxstudio_login_attempt` |
| `wpext_login_failed` | security-optimization | `uxstudio_login_failed` |
| `ux1_service_requests` | service-requests | `uxstudio_service_requests` |
| `wpext_logs` | smtp-email | `uxstudio_smtp_logs` |

### D) Data mimo DB (soubory) k přenosu

- **code-snippets:** adresář `wp-content/ux1-snippets/` (PHP soubory snippetů) → `wp-content/uxstudio-snippets/` + metadata.
- **cron-control:** mu-plugin `mu-plugins/ux1-cron-control.php` + blok v `.htaccess` (marker `UX1 Cron Control`) → přegenerovat pod novým markerem.
- **claude-panel:** standalone „rescue" endpoint (soubor mimo WP) — řešit samostatně.
- **security-optimization:** bloky v `.htaccess` (hash v `ux1_security_htaccess_hash`).

### E) Cron hooky k přeregistrování

`ux1_ai_assistant_daily_reindex`, `ux1_ai_assistant_vector_batch`, `ux1_ai_assistant_blog_pilot_generate`, `ux1_ai_markdown_daily_check`, `ux1_auto_unpublish_post`, `ux1_content_sync_sso_cleanup`, `ux1_cron_watch_daily`, `ux1_dashboard_pagespeed_refresh`, `ux1_mail_tester_weekly` (+ interval `ux1_weekly`), `ux1_guide_check_indexing`, `ux1_io_background_optimize`, `ux1_instagram_feed_cron`, `ux1_review_aggregator_fetch`, `lwug_nightly_scan`, `wpextended_smtp_email_purge_logs`, `cp_rescue_expire` (claude-panel). Doporučeno přejmenovat na prefix `uxstudio_...`.

### F) Secrets vyžadující bezpečné uložení (šifrování / mimo klienta)

- **ai-assistant:** OpenAI/Anthropic API klíč, JWT secret.
- **ai-markdown:** AI klíč.
- **smtp-email:** SMTP heslo, Gmail OAuth refresh_token, Brevo API klíč.
- **third-party-login:** OAuth client secrets (Google/Facebook/Apple/Seznam).
- **stock-photos:** Unsplash/Pexels/Pixabay/Giphy API klíče.
- **dashboard-widgets:** Google PageSpeed klíč, GA service-account JSON.
- **push-notifications:** VAPID klíče (privátní), FCM.
- **instagram-feed:** IG/FB access token.
- **opening-hours:** Mapy.cz / Google Maps klíč.
- **review-aggregator:** import token.
- **claude-panel:** heslo (hash) + rescue klíč (enc).
- **content-sync:** HMAC shared secret + SSO tokeny.
- **vulnerability-scanner:** WPScan API token (volitelný).
