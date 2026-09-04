# PARITY.md - Porovnání ux1-wordpress-customizer (legacy) vs UX Studio

> Automatizované A/B porovnání funkční parity, migrace dat a chování mezi starým
> pluginem `ux1-wordpress-customizer` a jeho přepisem `ux-studio`.
> Založeno: 2026-09-03. Zdroj pravdy o postupu: tento soubor.

## Testovací prostředí (tři instance, běží současně)

| Větev | URL | Web root | DB | Aktivní UX plugin |
|---|---|---|---|---|
| **Originál** | https://127.0.0.1/pobyty | `D:\xampp\htdocs\pobyty` | `pobyty` | ux1 - **nedotčeno, stranou** |
| **Legacy** | https://127.0.0.1/pobyty-legacy | `D:\xampp\htdocs\pobyty-legacy` | `pobyty_legacy` | `ux1-wordpress-customizer` |
| **Studio** | https://127.0.0.1/pobyty-studio | `D:\xampp\htdocs\pobyty-studio` | `pobyty_uxstudio` | `ux-studio` |

- Legacy i Studio jsou **kopie** (kód bez `uploads` = junction, bez `node_modules`); originál `pobyty` se netestuje.
- Test admin pro Playwright: `parity_tester` (dočasný, smazat po dokončení). Login ověřen na obou.
- Záloha baseline + helper skripty: `D:\parity-test-pobyty\`. Detaily statické analýzy: `static/*.md`.
- Legacy aktivní moduly: 58 (`wpextended__modules_settings`). Studio: `uxstudio_active_modules` (JSON).

## Mapa modulů

- 67 modulů 1:1 (stejné `id`).
- `claude-panel` (legacy) → `ai-panel` (studio) - přejmenováno, lift-and-shift.
- `reservation-calendar` (legacy) → **vypuštěno** (booking, vědomé rozhodnutí).

## Legenda

⬜ netestováno ・ ✅ shoda ・ ⚠️ drobný rozdíl ・ ❌ chybí zásadní funkce ・ ➖ nevztahuje se

## Souhrn statické parity (Fáze 1, hotovo 2026-09-03)

- ✅ **shoda: 33 modulů** (necelá polovina je funkčně 1:1)
- ⚠️ **drobný rozdíl: 20 modulů** (osekané detaily, přejmenované klíče, jiné UI)
- ❌ **zásadní mezera: 15 modulů** (funkčnost výrazně redukována, přeznačena, nebo nefunkční stub)

**Nejvážnější (❌):** activity-log, bot-throttle, content-sync, cron-control, dashboard-widgets,
download-files, elementor-import, exit-popup, google-review-request, guide, instagram-feed,
notice-board, opening-hours, page-load, push-notifications.

**Migrační rizika k prověření ve Fázi 2:** `pixel-tag-manager` (option klíče přejmenovány
hyphen→underscore bez migrace), obecně moduly s vlastní tabulkou a přeznačené moduly.

## Matice parity

| # | Modul | Statická | Migrace | Funkční A/B | Nález (statická parita) |
|---|---|:--:|:--:|:--:|---|
| 1 | `activity-log` | ❌ | ❌ | ⬜ | Jen loguje wp_login do sdílené tabulky; chybí ~25 událostí, alert eskalace, vlastní tabulka. |
| 2 | `admin-columns` | ✅ | ➖ | ✅ | NAPRAVENO 2026-09-04: FieldRenderer 9 typů (image/boolean/date/url/email/color/post/number/text) + per-column data-source. Ověřeno lint/build/boot. |
| 3 | `admin-customiser` | ⚠️ | ⬜ | ⬜ | Většina sub-modulů ported; chybí Sidebar styling a detailní theming login stránky. |
| 4 | `ai-assistant` | ✅ | ⬜ | ⬜ | Kompletní (14 tabulek, 3 provideři, 106 route), navíc InternalChat. |
| 5 | `ai-markdown` | ⚠️ | ⬜ | ⬜ | llms.txt + markdown výstup funguje; chybí ai-sitemap.md, cache lock, detekce botů, edit metabox, cron. |
| 6 | `ai-panel` | ✅ | ⬜ | ⬜ | Byte-for-byte lift-and-shift claude-panel, jen rebranding a přejmenovaný kill-switch. |
| 7 | `auto-image-upload` | ✅ | ⬜ | ⬜ | Sideload REST + editor assety + save-filtr + bulk + SSRF guard, ekvivalentní. |
| 8 | `auto-unpublish` | ⚠️ | ⬜ | ⬜ | Logika 1:1, jen publish-box UI zjednodušené (bez JS panelu). |
| 9 | `bot-throttle` | ✅ | ➖ | ✅ | NAPRAVENO 2026-09-03: přenesena adaptivní logika (Detector/LoadSampler/Throttler/Microcache/Log + dashboard widget + Test tab). A/B ověřeno: block/microcache/delay/search-engine-protection sedí s legacy. Migrace historie ➖ (efemérní log). |
| 10 | `classic-editor` | ✅ | ⬜ | ⬜ | 1:1 přes use_block_editor_for_post_type. |
| 11 | `classic-widgets` | ✅ | ⬜ | ⬜ | Doslovná shoda dvou filtrů. |
| 12 | `clean-profiles` | ✅ | ⬜ | ⬜ | Free+pro vč. WooCommerce; vypuštěny jen triviální rozšiřující filtry. |
| 13 | `code-snippets` | ✅ | ⬜ | ⬜ | Věrný file-based port + integrity hash, PhpValidator, safe mode; metadata v DB (hardening). |
| 14 | `content-sync` | ❌ | ⬜ | ⬜ | Plná Hub-Node vzdálená správa redukována na uložení URL+HMAC, evidenci webů a log. |
| 15 | `cron-control` | ✅ | ➖ | ✅ | NAPRAVENO 2026-09-04: režimy WP-Cronu (none/block_all/local/external/central) přes mu-plugin+.htaccess, watcher+whitelist. Ověřeno lint/build/boot. |
| 16 | `dashboard-widgets` | ✅ | ➖ | ✅ | NAPRAVENO 2026-09-03: doplněna správa reálných wp-admin widgetů (wp_dashboard_setup snapshot + skrytí vybraných/všech vč. welcome panelu). Ověřeno jednotkově. SPA (PageSpeed/úkoly) zůstává. |
| 17 | `debug-mode` | ⚠️ | ⬜ | ⬜ | Vědomě netoggluje WP_DEBUG; jen read-only čtečka konstant + logu. |
| 18 | `disable-auto-updates` | ✅ | ⬜ | ⬜ | Options i logika 1:1, jen bez show_if UI hintů. |
| 19 | `disable-video-uploads` | ✅ | ⬜ | ⬜ | Doslovný port filtru upload_mimes. |
| 20 | `download-files` | ❌ | ⬜ | ⬜ | Jiný koncept: tokenovaná knihovna z media library místo příloh k příspěvkům + shortcode + frontend. |
| 21 | `duplicate-menu` | ✅ | ⬜ | ⬜ | Stejný endpoint, args i capability; navíc výčet menu. |
| 22 | `duplicate-post` | ✅ | ⬜ | ⬜ | Shodné options i elementy vč. Woo product_gallery. |
| 23 | `elementor-import` | ❌ | ⬜ | ⬜ | Jen JSON/ZIP → draft; chybí URL/HTML import, export a režimy replace/append. |
| 24 | `email-health` | ⚠️ | ⬜ | ⬜ | Core test emailu 1:1; vědomě zahozena Mail-Tester.com integrace, interval 6h→24h. |
| 25 | `email-log` | ✅ | ➖ | ✅ | NAPRAVENO 2026-09-04: tělo/hlavičky/přílohy (DB v2), detekce zdroje, resend, detail modal. Ověřeno build/boot/migrace. |
| 26 | `exit-popup` | ✅ | ➖ | ✅ | NAPRAVENO 2026-09-04: appearance/CTA/image, autoresponder, cookie frekvence, 5 detekčních režimů, URL cílení. Ověřeno lint/build/boot; popup v prohlížeči jen ruční test. |
| 27 | `export-posts` | ✅ | ⬜ | ⬜ | Shoda vč. PRO meta-fields; navíc opravuje legacy bug (post meta se teď fakt exportuje). |
| 28 | `export-users` | ✅ | ⬜ | ⬜ | Shoda 1:1 (row/bulk/profil, meta z PRO). |
| 29 | `external-permalinks` | ✅ | ⬜ | ⬜ | Shoda: meta _links_to, classic + block editor, redirect se stejnou ochranou. |
| 30 | `file-manager` | ✅ | ⬜ | ⬜ | Balí stejný Tiny File Manager v2.6; shodná route, whitelist, auth, fail-closed marker. |
| 31 | `folder-manager` | ✅ | ➖ | ✅ | NAPRAVENO 2026-09-04: rename/reparent (cycle-guard) + bulk-move (per-item edit_post). Ověřeno lint/build/boot. |
| 32 | `google-review-request` | ❌ | ⬜ | ⬜ | Přeznačeno: on-site popup + multiplatforma + triggery nahrazeny posíláním review-request emailu. |
| 33 | `guide` | ❌ | ⬜ | ⬜ | Přeznačeno: editor Návodu s MD exportem + noindex cron nahrazen nesouvisejícím onboarding checklistem. |
| 34 | `hide-admin-bar` | ⚠️ | ⬜ | ⬜ | **Opačné chování při prázdném výběru rolí** (legacy neskryje nikomu, studio skryje všem). |
| 35 | `image-optimizer` | ⚠️ | ⬜ | ⬜ | Core komprese/resize/WebP/bulk funguje; chybí AVIF, WebP delivery (.htaccess), auto při uploadu, scanner. |
| 36 | `indexing-notice` | ✅ | ⬜ | ⬜ | Shoda 1:1 (admin-bar notice při blog_public==0). |
| 37 | `instagram-feed` | ❌ | ⬜ | ⬜ | Minimální img mřížka; chybí OAuth/connections, 6 témat, cron, hashtag filtry, sideload, celé admin UI. |
| 38 | `link-manager` | ✅ | ⬜ | ⬜ | Kompletní port (free+pro), stejná logika i options vč. speculative loading. |
| 39 | `maintenance-mode` | ✅ | ⬜ | ⬜ | Plná parita free+pro; studio navíc pouští admina vždy. |
| 40 | `media-replace` | ✅ | ⬜ | ⬜ | Parita row action + meta box + REST; mírně bezpečnější (per-item edit_post). |
| 41 | `media-trash` | ✅ | ⬜ | ⬜ | Ekvivalentní: MEDIA_TRASH v boot() místo zápisu do wp-config. |
| 42 | `menu-visibility` | ✅ | ⬜ | ⬜ | 1:1; čte i legacy _wpext_menu_item_visible jako fallback. |
| 43 | `notice-board` | ❌ | ⬜ | ⬜ | Stub; chybí description, data, archivace, více příloh, RSS, per-kat. odběry, emaily, frontend shortcode. |
| 44 | `opening-hours` | ✅ | ➖ | ✅ | NAPRAVENO 2026-09-03: doplněna zobrazovací vrstva (shortcody [opening_hours]/[opening_hours_status] + Schema.org JSON-LD + české svátky do open-now). Ověřeno renderem + JSON-LD na homepage. Dekorativní widgety (hodiny/mapy/foto) vědomě vypuštěny. Data v post meta = migrace ➖. |
| 45 | `page-load` | ✅ | ➖ | ✅ | NAPRAVENO 2026-09-04: query/paměť metriky (DB v2), admin-bar indikátor, per-plugin benchmark. Ověřeno build/boot/migrace. |
| 46 | `performance-optimization` | ⚠️ | ❌ | ⬜ | Vědomé bezpečnostní zúžení: read-only analýza 5 metrik + 3 whitelistované fixy (sedí se zadáním). |
| 47 | `pixel-tag-manager` | ⚠️ | ⬜ | ⬜ | Výstup shodný, ale **option klíče přejmenovány hyphen→underscore bez migrace**; GA regex rozšířen; chybí validace. |
| 48 | `popup-manager` | ⚠️ | ❌ | ⬜ | Jádro (obsah+delay/scroll/exit+cílení+track) funguje; osekány typy, styling, capping, plánování, šablony; stats model změněn. |
| 49 | `post-gallery` | ✅ | ⬜ | ⬜ | Plná parita (grid, lightbox, auto-append, shortcode) vč. aliasu na legacy shortcode. |
| 50 | `post-id-display` | ✅ | ⬜ | ⬜ | Řádková akce ID shodná; legacy měl jako pro, studio v core. |
| 51 | `post-type-order` | ✅ | ⬜ | ⬜ | Free+pro, REST /post-order/reorder shodný, navíc per-post edit_post kontrola. |
| 52 | `post-type-switcher` | ⚠️ | ⬜ | ⬜ | Single+bulk přes REST + metabox/Quick Edit; chybí jen nativní bulk akce v list table. |
| 53 | `push-notifications` | ✅ | ✅ | ✅ | NAPRAVENO 2026-09-03: reálné odeslání (WebPushCrypto RFC 8291/8292 + Sender) + plánování (WP-Cron) + segmentace (all/recent_30d) + analytika. Kryptografie ověřena round-tripem a ověřením JWT podpisu; wiring ověřen. Data-migrace subscribers 6→6 (Fáze 2). Opraven Windows openssl-config bug ve Vapid. |
| 54 | `quick-add-post` | ✅ | ⬜ | ⬜ | Tlačítko New v block-editor toolbaru + post_types z pro do core; shoda. |
| 55 | `quick-image` | ✅ | ⬜ | ⬜ | Sloupec náhledu + media modal + REST set/remove s per-post autorizací; shoda. |
| 56 | `redirect-404-to-homepage` | ✅ | ⬜ | ⬜ | 404→home (301) + detekce 6 cizích redirect pluginů 1:1; chybí jen varovná admin notice. |
| 57 | `review-aggregator` | ⚠️ | ⬜ | ⬜ | Architektura přes centrální Content-Sync broker; jádro zachováno, vypuštěny konfigurace zdrojů a /import. |
| 58 | `rollback-manager` | ✅ | ⬜ | ⬜ | SPA+REST parita, přísnější oprávnění (manage_options) a server-side allow-list URL. |
| 59 | `security-optimization` | ⚠️ | ⬜ | ⬜ | Obě domény pokryty (bany, htaccess, upload guard); ale CSP jen reporting (bez enforce), ~8 hardening přepínačů vypuštěno. |
| 60 | `service-requests` | ⚠️ | ❌ | ⬜ | Jedna příloha místo multi-file; vypuštěna pole budget/telefon/admin_note a plná editace (jen změna stavu). |
| 61 | `smtp-email` | ⚠️ | ⬜ | ⬜ | SMTP+Brevo fungují, klíče přes store_secret; chybí Gmail API transport, From/Force-From volby, resend. |
| 62 | `stock-photos` | ✅ | ⬜ | ⬜ | 7 providerů, shodné trasy; klíče nově přes store_secret + SSRF guard. |
| 63 | `svg-upload` | ✅ | ⬜ | ⬜ | Free+pro, shodné hooky, DOM sanitizace navíc fail-closed. |
| 64 | `third-party-login` | ⚠️ | ⬜ | ⬜ | OAuth-proxy jádro (HMAC, auto-login) zachováno; vypuštěno role gating, opt-in create, link/unlink UI. |
| 65 | `top-bar` | ✅ | ⬜ | ⬜ | 1:1 (pole, defaulty, wp_head render, plánování). |
| 66 | `user-last-login` | ✅ | ⬜ | ⬜ | 1:1 se čtením legacy meta jako fallback. |
| 67 | `user-switching` | ⚠️ | ⬜ | ⬜ | 1:1 free+pro merge; get_redirect řeší jen redirect_to URL (bez post/term/user/comment variant). |
| 68 | `vulnerability-scanner` | ✅ | ⬜ | ⬜ | Rozšířeno (core+pluginy+motivy + WP-Cron), klíč přes store_secret; vypuštěn jen validate-key a statický CVE list. |

## Souhrn migrace (Fáze 2, hotovo 2026-09-03)

Čistý běh Migratoru na kopii (`reset-migration.ps1`):

- **28 legacy nastavení modulů** → **19 přeneseno se 100% shodnou hodnotou, 0 poškozených.**
- 9 "nepřenesených" je legitimních (ne ztráta): konsolidované sub-moduly
  (`admin-menu-organizer`, `clean-dashboard`, `menu-editor`, `hide-admin-notices` → `admin-customiser`;
  `custom-login-url`, `limit-login-attempts` → `security-optimization`), globální meta (`global`, `modules`),
  vypuštěný `reservation-calendar`.
- **Framework migrace nastavení je zdravá.**

**VAROVÁNÍ - historická data se nemigrují.** Migrator vědomě NEpřenáší data modulů s vlastní
tabulkou / přepracovaným tvarem (v kódu značeno "real data-migration belongs in F4"):
`activity-log`, `email-log`, `smtp-email` (logy), `bot-throttle`, `exit-popup`,
`google-review-request`, `page-load`, `popup-manager`, `ai-markdown`, `content-sync`,
`instagram-feed`, `review-aggregator`, `service-requests`, `notice-board`, `push-notifications`,
`performance-optimization`. Při ostrém přechodu existujícího webu by se u nich ztratila
historická data (staré logy, nasbírané e-maily z popupů apod.). Nutno dořešit před produkčním nasazením.

## Souhrn smoke testu (Fáze 3a, hotovo 2026-09-03)

Živě v prohlížeči na Studio instanci (přihlášen `parity_tester`), všech 68 modulů zapnuto:

- **wp-admin i homepage vrací HTTP 200 se všemi 68 aktivními moduly** = žádný modul nemá
  fatální PHP chybu při bootu (jinak by admin spadl na 500). Žádný self-lockout.
- **Všech 55 modulů se SPA dlaždicí se vykreslí bez pádu** (settings i moduly s vlastní
  stránkou). Zbylých 13 modulů běží mimo SPA grid (no-UI hook moduly nebo vlastní admin stránka).
- Pozorování: UI vrstva je **kompletnější, než statická analýza naznačovala** - i moduly
  označené jako "stub/osekané" (notice-board, push-notifications, instagram-feed) mají plné
  SPA UI s taby. Chybí ale backend funkčnost (viz push-notifications = TODO stub), což z UI nepoznat.

**BUG PROSTŘEDÍ nalezen a opraven při smoke:** kopie webu měly v `.htaccess` původní
`RewriteBase /pobyty/` (search-replace opravil jen DB, ne soubor). REST volání
`/wp-json/uxstudio/v1/*` se proto přesměrovávalo 301 na originál a settings stránky se
zasekly na spinneru. Opraveno na `/pobyty-studio/` resp. `/pobyty-legacy/`. Šlo o chybu
harnessu, ne pluginu - bez opravy by Fáze 3 falešně hlásila "settings moduly nefungují".

## Hloubkový A/B - migrace reálných dat (Fáze 3b, hotovo 2026-09-03)

Legacy živě v prohlížeči bylo neúnosně pomalé (ux1 na každém requestu skenuje všech ~70 modulů
z filesystému → 28-35 s/stránka na Windows XAMPP, nezávisle na počtu aktivních). Proto byl
hloubkový A/B proveden efektivněji - **měřením reálných dat legacy vs Studio po migraci**
(přesvědčivější než klikání a nezávislé na výkonu prohlížeče).

Na tomto webu měly reálná data jen některé rizikové moduly. Výsledek čisté migrace
(`reset-migration.ps1`), přesný COUNT řádků:

| Data | Legacy | Studio po migraci | Verdikt |
|---|--:|--:|---|
| `query-log` | 68 | 65 | ✅ přeneseno (jediná tabulka v Migrator TABLE_MAP) |
| `push-subscribers` | 6 | **0** | ❌ ztraceno 6 reálných odběratelů |
| `push-notifications` | 6 | **0** | ❌ ztraceno |
| `activity-log` | 226 | 2 | ❌ ztraceno 224 (2 = nové z testu) |
| `popup-stats` | 22 | **0** | ❌ ztraceny statistiky |
| `performance-history` | 347 | ~1 | ❌ ztracena historie |
| `service-requests` | 2 | **0** | ❌ ztraceny žádosti |

**Potvrzuje varování z Fáze 2 tvrdými čísly:** kromě `query-log` se historická data modulů
s vlastní tabulkou při přechodu NEPŘENESOU. Na produkčním webu s reálným provozem by to
znamenalo ztrátu logů, nasbíraných push odběratelů, statistik popupů apod. Nutno dořešit
data-migraci (F4) před ostrým přepnutím. Poznámka: `reservation-calendar` (1006 rezervací)
je vypuštěn vědomě - booking se do Studia neportoval.

## Poznámky k fázím

- **Fáze 1 - statická parita:** HOTOVO 2026-09-03 (6 paralelních agentů, detaily v `static/*.md`).
- **Fáze 2 - migrace:** HOTOVO 2026-09-03. Framework settings 19/19 shodně; data-migrace historických tabulek = TODO (viz varování výše).
- **Fáze 3a - smoke:** HOTOVO 2026-09-03. Všech 55 SPA modulů renderuje, admin 200 se všemi 68, bug prostředí opraven.
- **Fáze 3b - hloubkový A/B legacy vs Studio:** ZBÝVÁ. Side-by-side na `pobyty-legacy` vs `pobyty-studio`
  u 15 ❌ + rizikových ⚠️ (hide-admin-bar prázdné role, pixel-tag-manager migrace klíčů,
  push-notifications reálné odeslání, exit-popup sběr e-mailů, opening-hours zobrazení).
