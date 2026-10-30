# Balíček D: poznámky pro F (sloučení)

## Řádky do CHANGELOG.md (EN)

- Added plain-language help (what it does, what changes, risk, when to switch it off) to the settings of Exit Popup, Maintenance Mode, Push Notifications, Review Aggregator, Opening Hours, Link Manager, Redirects, Pixel & Tag Manager, Instagram Feed, Notice Board, Google Review Request, Post Gallery and Top Bar, and help lines to the pages of Forms, Notice Board, Push Notifications, Redirects, Instagram Feed, Service Requests and Google Review Request.
- Settings that only matter for another value are now hidden (Maintenance Mode, Exit Popup, Link Manager, Push Notifications, Review Aggregator, Opening Hours, Notice Board, Redirects). Their saved values are kept.
- Top bar: the start and end of the schedule use a date and time picker instead of a text field.
- Reviews: the buttons "Fetch reviews" and "Request a full scan" sit in a proper button row, the technical wording (NAS, broker) is gone.
- Redirects: the "Test a URL" box has a clear layout (address, options, button) with a short explanation.
- Instagram Feed: the registration steps are a numbered, neutral info box instead of a red error box.
- Exit Popup: the call to action button fields have plain names ("Show a button (call to action)", "Button address (URL)").
- Module names: group "Redirects and 404 errors" and feature "Redirect rules" no longer share the name "Redirects".
- Opening Hours: removed the developer note from the module description.
- Post Gallery: the lightbox buttons (Close, Previous, Next) are translated.

## Baseline: pokryté položky (smazat z tests/fixtures/help-baseline.json)

Klíče, které mají nově záznam v help.php modulu (soubor `includes/Modules/<Modul>/help.php`):

- exit-popup (32): autoresponder_body, autoresponder_subject, bg_color, body, cta_bg_color, cta_new_tab, cta_text, cta_text_color, delay_seconds, detect_idle, detect_mouse_leave, detect_scroll_up, detect_tab_change, detect_window_blur, email_button_text, email_placeholder, email_success_message, enable_autoresponder, enable_cta, enable_email, frequency_mode, headline, hide_for_logged_in, idle_timeout, mouse_leave_delay, overlay_opacity, scroll_up_desktop, scroll_up_mobile, targeting_mode, text_color, time_on_page, title_color (celý modul exit-popup z baseline zmizí)
- maintenance-mode (12): background_colour, background_image, body_colour, body_text, countdown_label, enable_background_image, enable_logo, footer_colour, footer_text, headline_colour, headline_text, logo_width (celý modul)
- push-notifications (8): auto_send_post_types, prompt_bg_color, prompt_btn_allow, prompt_btn_color, prompt_btn_later, prompt_position, prompt_text, prompt_title (celý modul)
- review-aggregator (6): auto_fetch, order_by, show_avatar, show_date, show_platform, show_stars (celý modul)
- opening-hours (5): schema_location_ids, schema_on_homepage, severity_critical_color, severity_info_color, severity_warning_color (celý modul)
- link-manager (1): icon_position (celý modul)
- notice-board (1): per_page (celý modul)
- google-review-request (1): email_subject (celý modul)

Test `tests/setting-help-coverage-wp.php` jsem nespouštěl (potřebuje WP-CLI nad databází pobyty, což jsem nesměl). Kontrola klíčů help.php proti schématům je skriptem v `_uxs-scratch/pkg-d/check.py` (shoduje se).

## Žádosti o sdílené soubory

1. `src/app/SettingsForm.tsx`, pole `media` (ikona v Exit Popup, #14): tlačítko říká napevno "Změnit obrázek" / "Vybrat obrázek" a "Odebrat" je šedý odkaz (`button-link`). Prosím: (a) volitelná vlastnost schématu, např. `media_noun: 'icon'` (nebo `button_label`), aby pole `popup_icon` ukázalo "Vybrat ikonu" / "Změnit ikonu" (msgid "Change icon", "Select icon", "Select an icon"); (b) "Odebrat" jako malé tlačítko `button button-small` místo `button-link`. Schéma `popup_icon` pak stačí doplnit `'media_noun' => 'icon'`, řeknu jak.
2. `tests/fixtures/help-baseline.json`: smazat položky výše.
3. Slovník `Core\Glossary`: chybějící pojmy viz níže (nepovinné, texty fungují i bez nich).
4. `src/style.scss`: nic nutného. Test box přesměrování používá jen `.uxs-actions`, `.uxs-form__row`, `.uxs-form__help`.
5. `languages/*.po`: aplikovat `docs/i18n/cs-pkg-d.json` (jednoduché i množné tvary, žádné množné tvary nejsou potřeba).

## Chybějící pojmy ve slovníku (nepovinné)

- speculative loading (spekulativní načítání), prefetch, prerender (Link Manager)
- exit intent (Exit Popup)
- GA4 / Google Analytics, Meta Pixel (Pixel a Tag Manager, Forms Tracking)
- retry-after (Maintenance Mode)

## Otevřené body

- Skupina "Reviews" (id `reviews`) jsem nepřejmenoval. Funkce uvnitř se jmenují "Review Aggregator" a "Google Review Request", takže názvy nekolidují. Pokud F v menu najde další "Recenze" (např. WooCommerce), je to mimo můj rozsah.
- `src/modules/instagram-feed/Page.tsx:419` hlásí `tsc` chybu TS2367 (porovnání `Feed` a `string`). Je stará, nevznikla v tomto balíčku. `tsc` hlásí i `index.tsx` (scss) a `admin-columns` (mimo rozsah).
- Popisy MCP schopností v `Redirects/Abilities.php` jsou anglicky natvrdo záměrně (čte je stroj, ne člověk), neobalil jsem je do `__()`.
- U Forms jsem doplnil nápovědu jen k polím, která ji neměla a mají dopad (inspektor polí, e-mail, akce, po odeslání). Karta Sledování (TrackingCard) má vlastní podrobné nápovědy v podmíněných větvích, audit je jen nezachytil.
- `show_if` v Exit Popup vyžaduje, aby řídicí a závislé pole byly ve stejné sekci (stránka modulu filtruje schéma po sekcích). Proto jsem barvy tlačítka (`cta_bg_color`, `cta_text_color`) přesunul ze sekce Appearance do Content, hned pod přepínač tlačítka. Hodnoty zůstaly.
- Maintenance Mode: pole obsahu (nadpis, texty, patička) jsem při volbě "Existing Page" neskrýval, protože `existing-page.php` načítá společnou hlavičku s vlastním CSS a barvami. Skrývá se jen `custom_layout` / `existing_page`, `access_roles`, `countdown_label`, logo a obrázek na pozadí.
- Moduly Forms, Popup Manager, Auto Unpublish nemají schéma nastavení v `Module.php`, `show_if` se jich netýká.
- Nic nebylo ověřeno v prohlížeči (místní web běží z jiného worktree).
