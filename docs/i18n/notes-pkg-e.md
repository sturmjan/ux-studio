# Balíček E: poznámky pro sloučení (F)

## Řádky do CHANGELOG.md (EN, 0.10.0)

- Added plain-language help (what it does, effect, risk, when to switch off) for the settings of Image Optimizer, Auto Image Upload, Content Sync, Cron Control, Page Load, DNS Manager, Code Snippets, Download Files, Duplicate Post, Export Posts, Export Users, Folder Manager, Quick Image and SVG Upload; explanations of WebP, AVIF, CDN, TTL, CIDR, cron and the constants DISABLE_WP_CRON and ALTERNATE_WP_CRON.
- Content Sync: the risks of package management and administrator SSO are spelled out (what a leaked key allows).
- Performance Optimization: deleting revisions, spam comments and expired transients now asks for confirmation, and each check is explained.
- Cron Control: the events list is paginated (25 per page), searchable by hook, source or schedule, shows the plugin that owns each hook, human schedule names and dates in the user's format; compact icon actions; the Mode tab explains DISABLE_WP_CRON and ALTERNATE_WP_CRON in plain words; schedules of other plugins are marked as such.
- DNS Manager: the warning about immediate changes appears only when DNS management is active, both boxes have the same width, TTL and the Cloudflare proxy are explained in the record form.
- Image Optimizer: the capability status is a compact list (label plus badge) instead of full-width bars.
- Page Load: the last 24 hours are shown as a grid of values, the hour column uses the shared date format.
- The groups "Export" and "Duplication" are renamed "Data export" and "Content copying" to tell them apart from their modules (ids unchanged).

## Pokryté položky baseline (`tests/fixtures/help-baseline.json`, smazat při slučování)

- `image-optimizer`: `convert_to_webp`
- `auto-image-upload`: `max_height`
- `content-sync`: `sso_max_failed_redemptions`, `sso_max_issue_per_operator_hour`, `sso_require_ip_match`, `sso_require_ua_match`

Všech 6 položek mých modulů z baseline je v `help.php`. Navíc `help.php` mají (nad rámec baseline) tyto moduly a klíče:
ImageOptimizer (11), AutoImageUpload (3), ContentSync (12), CronControl (5), PageLoad (5), DnsManager (1), CodeSnippets (1), DownloadFiles (5), DuplicatePost (11), ExportPosts (3), ExportUsers (3), FolderManager (2), QuickImage (1), SvgUpload (1). Celkem 64 záznamů.
StockPhotos (pole API klíče se generují podle poskytovatele), FileManager, MediaReplace, MediaTrash, DisableVideoUploads, DuplicateMenu a skupiny nemají statické pole ve schématu, nebo schéma nemají; nic k doplnění.

## Chybějící pojmy ve slovníku (`Core\Glossary`, mimo můj rozsah)

HMAC, mu-plugin (povinný plugin), wp-config.php, .htaccess, FS_METHOD, post meta / user meta (vlastní pole), taxonomie, revize příspěvku, DISABLE_WP_CRON, ALTERNATE_WP_CRON, hub / uzel (node), IPv4 vs. IPv6, GDPR. Ve `help.php` jsou vysvětleny prostými slovy bez značky. Navrhuji přidat aspoň: `hmac`, `mu-plugin`, `post-meta`, `taxonomy`, `revisions`, `alternate-wp-cron`.

## Žádosti na sdílené soubory

- `src/style.scss`: třída pro řádek stavu "popisek + malý odznak" (dnes inline styly v `image-optimizer/Page.tsx`), třída pro stránkování seznamu (`.uxs-pager`, dnes `.uxs-actions` + tlačítka v `cron-control/Page.tsx`) a pro mřížku souhrnných hodnot (`page-load/Page.tsx`, dnes inline `dl` s grid). Inline styly fungují, jen se opakují.
- `src/style.scss`: `.uxs-notice` má `max-width: 520px`; DNS stránka to přebíjí inline `maxWidth: none`, aby měla stejnou šířku jako pruh upozornění.
- `tests/fixtures/help-baseline.json`: smazat šest položek výše.
- `languages/*`: aplikovat `docs/i18n/cs-pkg-e.json` (264 řetězců, 2 množná čísla, názvy skupin "Data export" a "Content copying").

## Otevřené / poznámky

- Přidáno pole REST `cron-control/events`: `schedule_label`, `schedule_origin` (`core`/`ours`/`plugin`), `source` (název pluginu, šablony nebo WordPress podle souboru callbacku). `cron-control/schedules` má navíc `origin` a `display` je pro jádro WordPressu přeložený náš text. Zdroj hooku se zjišťuje reflexí callbacků, takže u hooku bez zaregistrovaného callbacku (plugin je vypnutý) je prázdný.
- Prvky s pomlčkou v existujících (už přeložených) řetězcích jsem nechal, např. volby "— Unassigned —", "— Top level —", a záznam "WooCommerce - recent orders". Mění se tím překlad, proto jen na přání.
- Označení tlačítek přes celou šířku (#10): v mých stránkách jsem nenašel místní značku, která by obecná pravidla balíčku 0 přebíjela. Bez prohlížeče neověřeno.
- Hard-coded české řetězce (`czech-msgids.txt`) v mých modulech nejsou (nalezené jsou v AdminCustomiser a AiAssistant). V mých modulech české znaky zbyly jen v komentářích (FileManager/legacy).
- Pole "Email Address" v ExportUsers je existující anglický řetězec, držím se dosavadního překladu.
- Test čisté logiky: `node tests/cron-events-list.mjs` (stránkování, hledání, filtr zdroje).
- Prohlížečový test chybí (místní web běží z jiného worktree): ověřit vizuálně Cron > Události (tabulka v kartě), Mód, Plány, DNS (banner), Image Optimizer (stav), Page Load.
