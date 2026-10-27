# UX Studio - plán kompletního přepisu (nahrazuje ux1-wordpress-customizer)

> Stav: NÁVRH k odsouhlasení. Po schválení se z tohoto dokumentu stává závazná roadmapa.
> Datum: 2026-08-11

---

## 1. Cíl a principy

Přepsat stávající plugin `ux1-wordpress-customizer` (fork WP Extended, 69 modulů, ~165k
řádků, nekonzistentní administrace) do **jednotné SPA platformy** podle vzoru pluginu
`destima-obec`.

Tři hlavní požadavky zadavatele + rozšíření:

1. **Bezpečnost** - viz kap. 6.
2. **Rychlost** - viz kap. 7.
3. **Vzhledová konzistence** - jednotný admin, lucide ikony, jedna barevnost, jedna sada
   animací - viz kap. 5.

Doplněné požadavky:

4. **Nikde žádné `Wpextended` / `wpextended`** - ani v kódu, namespace, ani text-domainu.
5. **Auto-update z GitHubu** - vydání release na GitHubu → weby se aktualizují samy. Kap. 8.
6. **Migrace dat** ze starého pluginu (nic se neztratí). Kap. 9.
7. **Booking modul (`reservation-calendar`) se úplně vynechává.**
8. **Maximální sdílení CSS/JS** - co nejvíc společných skriptů a stylů, žádná duplikace
   mezi moduly. Kap. 7.1.
9. **Plná dvojjazyčnost CZ + EN od začátku** - vše funguje i v anglickém WordPressu,
   všechny řetězce překládané, děláme rovnou s CZ i EN. Kap. 5.6.

Vůdčí principy: jeden shell, jeden design systém, jeden způsob jak psát modul, čistá
identita od základu, žádný big-bang (rollout modul po modulu, web běží celou dobu).

---

## 2. Identita a jmenné konvence (napříč VŠÍM)

| Prvek                | Hodnota                              |
|----------------------|--------------------------------------|
| Název                | **UX Studio**                        |
| Slug / složka        | `ux-studio`                          |
| Hlavní soubor        | `ux-studio.php`                      |
| Text-domain          | `ux-studio`                          |
| PHP namespace        | `UxStudio\`                          |
| Konstanty            | `UXSTUDIO_VERSION`, `UXSTUDIO_PATH`, `UXSTUDIO_URL`, `UXSTUDIO_FILE`, `UXSTUDIO_DB_VERSION`, `UXSTUDIO_API_VERSION` |
| REST namespace       | `uxstudio/v1`                        |
| Prefix options       | `uxstudio_`                          |
| DB tabulky           | `{$wpdb->prefix}uxstudio_*`          |
| CSS proměnné         | `--uxs-*`                            |
| JS global            | `window.uxStudio` (Extension API)    |
| Prefix funkcí/hooků  | `uxstudio_` / `ux_studio/`           |

**Pravidlo:** žádný řetězec `wpextended`/`Wpextended`/`wpext` nesmí projít do nového kódu.
Kontroluje se lint pravidlem + grepem v CI (build spadne, když se najde).

---

## 3. Architektura

### 3.1 Backend (PHP 8.1+, PSR-4)
```
ux-studio/
  ux-studio.php            # bootstrap: konstanty, autoloader, boot na plugins_loaded
  composer.json            # PSR-4 UxStudio\ -> includes/
  includes/
    Autoloader.php
    Plugin.php             # singleton, boot(): registr modulů, REST, admin shell, assety
    Core/
      DB.php               # aktivace, verzování, dbDelta migrace, uninstall
      Rest.php             # registrace REST kontrolerů, jednotné permission/nonce
      Modules.php          # registry: načtení meta.json, enable/disable, lazy boot
      Settings.php         # perzistence nastavení (options), schema validace
      Security.php         # capability mapa, nonce, rate-limit, sanitizace/escaping
      Migrator.php         # import ze starého ux1 (kap. 9)
      GithubUpdater.php    # napojení plugin-update-checker (kap. 8)
    Modules/
      BaseModule.php       # čistá abstrakce (žádný Wpextended kód)
      <modul>/Module.php   # jeden modul = Module.php + meta.json + REST controller + settings schema
    Rest/
      Controller.php       # base REST controller (permission_callback, schema, sanitizace)
      <Modul>Controller.php
  build/                   # zkompilované SPA assety (commitované do release zipu)
  src/                     # zdroje SPA (TS/TSX) - viz 3.3
  languages/
```

### 3.2 REST vrstva (jediný komunikační kanál admin↔server)
- Vše přes `uxstudio/v1/*`. **Žádný admin-ajax, žádné ručně echo-vané HTML formuláře.**
- Každá routa: `permission_callback` (capability check), nonce (`wp_rest`), deklarované
  `args` se schématem + sanitizací. Zápisové routy rate-limited.
- Odpovědi jednotný tvar `{ data, meta }`; chyby `WP_Error` → konzistentní toast v UI.

### 3.3 Frontend - jedna SPA (React + TypeScript, `@wordpress/scripts`)
Stack shodný s destimou (aby to byl opravdu „ten formát"):
- **React + TypeScript (strict, žádné `any`)**, build přes `@wordpress/scripts`
  (dependency extraction → sdílí React s WP jádrem, malý bundle).
- **@tanstack/react-query** - cache, prefetch, invalidace po uložení.
- **Hash router** (`#/modul`, `#/modul-edit?id=42`) - jeden mount, žádné reloady.
- **lucide-react** - jediná povolená ikonová sada.
- **Code-splitting per modul** - stránka modulu se načte lazy až při otevření.

Sdílené UI komponenty (přeneseme a rozšíříme z destimy):
`AppShell`, `Sidebar`, `PageHead`, `ModuleGrid`, `DataTable`, `Modal`, `EditModal`, `Tabs`,
`Toast`, `Confirm`, `ToggleSwitch`, form fields, `RichText`, `DateField`, `Loading`.

**Layout (potvrzeno):** levý svislý **sidebar** s navigací (styl destima) + úvodní
**„šachovnice" dlaždic modulů** (`ModuleGrid`, jako současný modules-grid v ux1) jako
landing/rozcestník. Klik na dlaždici → detail/nastavení modulu ve stejném shellu.

### 3.4 Modulový systém + Extension API
- Každý modul = složka s `meta.json` (id, name, description, group, settings, deps) +
  `Module.php extends BaseModule`.
- Centrální registry, per-modul enable/disable perzistované v options, **lazy boot**
  (načítá se jen aktivní modul → výkon).
- **Extension API** (`window.uxStudio.registerPage`, filtr `ux_studio/modules`) - add-on
  pluginy (obdoba `destima-pec-extensions`) můžou registrovat vlastní stránky.
- **Schema-driven settings:** modul deklaruje pole nastavení → jeden generický React
  renderer je vykreslí. Tím je vzhled „nastavovacích" modulů identický a vynucený.

---

## 4. Katalog modulů (69 = 70 minus booking)

Kategorizace určuje pořadí a náročnost (booking `reservation-calendar` VYŘAZEN):

**Skupina A - triviální přepínače / bez UI (~20)** - hromadně, přes toggle + případně
malé schéma nastavení:
`classic-widgets, classic-editor, disable-video-uploads, disable-auto-updates,
hide-admin-bar, clean-profiles, redirect-404-to-homepage, debug-mode, svg-upload,
post-id-display, menu-visibility, duplicate-menu, top-bar, user-last-login,
quick-add-post, quick-image, media-trash, external-permalinks, post-gallery,
indexing-notice`

**Skupina B - „jen nastavení" přes schéma renderer (~33)**:
`activity-log, admin-columns, auto-image-upload, auto-unpublish, bot-throttle,
cron-control, dashboard-widgets, disable-video-uploads, duplicate-post,
elementor-import, email-health, email-log, exit-popup, export-posts, export-users,
google-review-request, guide, link-manager, maintenance-mode, media-replace,
page-load, pixel-tag-manager, post-type-order, post-type-switcher, quick-*,
rollback-manager, third-party-login, user-switching, vulnerability-scanner,
opening-hours, popup-manager, service-requests, review-aggregator`

**Skupina C - reálné pod-aplikace, každá vlastní SPA stránka (~16)**:
`ai-assistant (25k LOC, 79 souborů - už jednou portnut do destimy = vzor),
security-optimization (10.8k), admin-customiser (7.6k), performance-optimization (6.4k),
file-manager (6.1k), content-sync (5.2k), image-optimizer (5.1k),
push-notifications (4.5k), review-aggregator, notice-board, smtp-email, code-snippets,
folder-manager, claude-panel, instagram-feed, download-files`

> Tady jsou ty „člověkoměsíce". Migrují se jednotlivě dle priority.

---

## 5. Design systém (vzhledová konzistence)

- **Design tokeny = single source of truth** (`src/style.scss` + CSS proměnné `--uxs-*`):
  barvy (brand, povrchy, text, stavové), spacing, radius, stíny, typografie, **časy a
  easing animací**. Definice pro **light i dark mode** - **dark povinný od F0** (každý
  modul se od začátku dělá v obou režimech).
- **Lucide jediná ikonová sada** - dashicons/SVG zoo zakázané (lint pravidlo).
- **Jedna sada animací** - přechody stránek, hover, otevírání modalů, toasty - vše přes
  tokeny (`--uxs-motion-*`), stejné napříč celým adminem.
- **Sdílená knihovna komponent** (kap. 3.3) - žádný modul si nekreslí vlastní tlačítko.
- **A11y + responzivita** - focus stavy, klávesová navigace, aria, funkční na mobilu.
- `DESIGN.md` popisuje tokeny a pravidla; nový modul se bez nich neobejde.

### 5.6 Internationalizace (CZ + EN, potvrzený požadavek)
Vše musí fungovat i v anglickém WordPressu → **děláme rovnou dvojjazyčně od F0.**

- **Zdrojové řetězce v kódu = angličtina** (WP konvence). Tím EN web funguje out-of-the-box;
  čeština je překlad `cs_CZ`. Žádný natvrdo napsaný český text v UI.
- **Jeden text-domain `ux-studio`** pro PHP i JS.
  - PHP: `__()/esc_html__()/_e()` + `load_plugin_textdomain('ux-studio', …/languages)`.
  - React SPA: `@wordpress/i18n` (`__`, `_x`, `sprintf`) + `wp_set_script_translations()`
    (napojení na `languages/*.json` generované z `.po`).
- **`meta.json` modulů** (name/description/keywords) - překládané přes registrované řetězce,
  ne staticky (aby šel název modulu lokalizovat v obou jazycích).
- **Lokalizované formátování** - data/čísla přes sdílený `date.ts`/util respektující WP
  locale (žádné natvrdo `d.m.Y`).
- **Dodávané soubory:** `languages/ux-studio.pot` (šablona) + `ux-studio-cs_CZ.po/.mo` +
  JS `ux-studio-cs_CZ-<handle>.json`. EN = zdrojové řetězce (volitelně explicitní `en_US`).
- **Build/CI:** `wp i18n make-pot` + `make-json` jako součást buildu; **lint/CI guard** na
  neobalené („naked") řetězce a na český text v kódu → build spadne.
- Pravidlo v `DESIGN.md`/dev guide: každý nový modul dodává řetězce jen přes i18n API.

---

## 6. Bezpečnost

- **Server-side validace všeho** (schéma), parametrizované dotazy, output escaping (XSS).
- **Capability model per modul** (least privilege), nikdy nespoléhat na klienta.
- **Nonce + `permission_callback`** na každé REST routě; CSRF přes WP nonce.
- **Rate limiting** na zápisové endpointy.
- **Bezpečný upload** (`file-manager`, `download-files`, `media-replace`, `image-optimizer`):
  kontrola typu/velikosti, MIME sniffing, úložiště mimo web root.
- **Žádné secrets v kódu ani v JS** - API klíče (`ai-assistant`, `smtp-email`,
  `push-notifications`, `instagram-feed`, `stock-photos`, `review-aggregator`) přes
  options šifrovaně / konstanty v `wp-config`, nikdy do frontend bundlu.
- **Prioritní bezpečnostní review nejrizikovějších modulů PŘED portem:**
  - `code-snippets` - spouští PHP kód (sandbox, capability `manage_options`, audit).
  - `file-manager` - čtení/zápis filesystému.
  - `vulnerability-scanner`, `third-party-login`/SSO - auth flow.
- **Audit log** - stavové akce projdou modulem `activity-log`.
- Odstranit starou „conflict detection auto-disable" logiku; nahradit čistým conflict
  guardem (kap. 10).
- Spustit `/security-review` po dokončení skupiny C.

---

## 7. Rychlost / výkon

- **Lazy boot modulů** - PHP jen aktivních modulů (ne všech 69 na každý request).
- **Code-splitting SPA** per modul, malý první bundle, sdílený React z WP jádra.
- **React Query cache + prefetch** nejčastějších stránek (jako destima).
- **Assety enqueue jen na našich admin stránkách.**
- Rozpočet na velikost bundlu + Lighthouse kontrola v QA.

### 7.1 Maximální sdílení CSS/JS (potvrzený požadavek)
Cíl: co nejméně kódu, co nejvíc sdíleného; **žádný modul si neduplikuje styl ani skript**.

- **Jeden shared core bundle** - React, React Query, router, celá sdílená knihovna
  komponent (kap. 3.3), utility (`api.ts`, `route.ts`, `date.ts`, ikony) jsou v jednom
  společném chunku načteném jednou. Moduly z něj jen importují.
- **Jedna stylová vrstva = design tokeny + komponentové styly** (`--uxs-*`). Vzhled řídí
  utility/tokenové třídy, ne per-modul CSS. Modul přidává **jen nezbytné delta styly**,
  a to přes sdílené tokeny (žádné vlastní barvy/rozměry/animace).
- **Ikony:** jeden import point z `lucide-react`, tree-shaking → v bundlu jen reálně
  použité ikony, sdílené napříč moduly (žádné kopie SVG).
- **Code-splitting se sdílenými chunky:** stránka modulu se načítá lazy, ale společný
  kód je vždy ve sdíleném chunku (webpack `splitChunks`), ne zkopírovaný v každém modulu.
- **Nulová duplicita na klientu:** žádné per-modul `wp_enqueue` vlastních knihoven; vše
  jede přes jeden build. `@wordpress/scripts` dependency extraction sdílí React s WP jádrem.
- **Kontrola v CI:** bundle analyzer + pravidlo proti duplicitním závislostem a proti
  per-modul stylesheetům mimo sdílenou vrstvu.

---

## 8. Auto-update z GitHubu (nový požadavek)

Cíl: vydáme release na GitHubu → nainstalované weby se aktualizují samy přes nativní
WordPress update systém (žádný extra plugin u klienta).

- **Knihovna:** `YahnisElsts/plugin-update-checker` v5 (PUC), zabalená v pluginu, napojená
  na **GitHub Releases**. Integruje se do nativního WP update flow → funguje i
  „auto-update" přepínač / `auto_update_plugin`.
- **Header `Update URI`** v hlavním souboru, aby WP.org nepřebíral aktualizace.
- **Release = zip s UŽ ZKOMPILOVANÝMI assety** (`build/`). Klient nesmí potřebovat npm.
- **GitHub Action (CI):** na git tag `v*` → `npm ci && npm run build` → sestaví čistý
  distribuční zip (bez `node_modules`, `src` volitelně) → přiloží jako release asset.
- **Semver** + `CHANGELOG.md`; verze v hlavičce pluginu = zdroj pravdy.
- **Rozhodnutí k potvrzení:** repo **veřejné** (nejjednodušší, žádný token) vs **privátní**
  (nutný GitHub token na straně webu přes konstantu ve `wp-config`, ne v kódu). Doporučuji
  začít veřejným repem.

---

## 9. Migrace dat ze starého pluginu

- **`Migrator.php`** spuštěný při aktivaci (idempotentně, s DB verzí):
  - Přenese `wp_options` starého pluginu (`wpext_*` / dané klíče) → `uxstudio_*`.
  - Přemapuje/zkopíruje custom tabulky modulů (mimo booking: `*_reservations`, `*_rooms`,
    `*_seasons` se ignorují) do `uxstudio_*` schématu.
  - Zmapování starého per-modul on/off stavu → nový registry.
- **Fáze 0 audit** přesně zmapuje option klíče a tabulky každého modulu (podklad pro mapu).
- Před přepnutím: export/záloha DB. Migrace má „dry-run" log.

---

## 10. Rollout, conflict guard, deaktivace starého

- **Postupně, modul po modulu** - web funguje celou dobu.
- **Conflict guard:** nový plugin detekuje aktivní starý ux1 a zobrazí admin notice /
  odmítne kolidující hooky; nikdy neběží oba naráz.
- **Deaktivace starého:** až bude nový hotový a ověřený, starý `ux1-wordpress-customizer`
  se deaktivuje a nechá jen jako záloha (už zazálohován v
  `_plugin-backups/2026-08-11_ux1-original/`). Nový `ux-studio` se aktivuje.

---

## 11. QA, testy, standardy

- **TypeScript strict**, `no any`; **ESLint** + `@wordpress` config; **Prettier**.
- **PHP:** typované, PHP 8.1+, **PHPStan** + **PHP_CodeSniffer** (WPCS).
- **Playwright smoke test** na každou admin stránku (přes playwright-mcp) - render, základní
  akce, žádné console errory.
- **CI grep guard:** žádné `wpextended`, žádné dashicons, žádné inline API klíče.
- `DESIGN.md`, `README.md`, dev guide pro psaní modulů.

---

## 12. Fáze (návrh pořadí)

- **F0 - Základ + audit (blokující):** skeleton pluginu, identita, autoloader, `Plugin`,
  `Core/*`, REST base, AppShell + design tokeny + sdílené komponenty, Extension API,
  GithubUpdater + CI, `Migrator` kostra. Kompletní audit 69 modulů (option klíče, tabulky,
  způsob adminu) → migrační mapa.
- **F1 - Skupina A** (triviální přepínače) - rychlá, ověří shell a registry.
- **F2 - Schema settings renderer + Skupina B** - největší páka konzistence.
- **F3 - Skupina C** po jednom (nejdřív `ai-assistant` dle existujícího vzoru z destimy,
  pak dle priority). Bezpečnostní review rizikových modulů.
- **F4 - Migrace dat, conflict guard, security-review, Playwright, Lighthouse.**
- **F5 - Přepnutí:** deaktivace starého, aktivace `ux-studio`, ověření na živu, release v1.

---

## 13. Rizika

- `ai-assistant` a `security-optimization` jsou samy o sobě velké appky - hlavní časová
  položka.
- Migrace custom tabulek u modulů s netriviálním schématem.
- Auto-update: špatně sestavený release zip (chybějící `build/`) rozbije weby - nutné CI.
- GPL fork WP Extended: přepis OK, GPL zachovat, branding pryč (splňuje i požadavek 4).

---

## 14. Potvrzená rozhodnutí

1. **Layout:** levý sidebar (styl destima) + úvodní „šachovnice" dlaždic modulů (styl ux1).
2. **GitHub repo:** veřejné → auto-update bez tokenu, `Update URI` na GitHub repo.
3. **Dark mode:** povinný od F0 (light + dark tokeny od začátku).
4. **Scope:** všech 69 modulů 1:1, booking (`reservation-calendar`) vyřazen.
5. **Migrace dat:** ano, `Migrator` importuje stará nastavení + tabulky.
6. **i18n:** dvojjazyčné CZ+EN od F0, zdroj EN + překlad cs_CZ, PHP i React (kap. 5.6).
7. **CSS/JS:** maximální sdílení, nulová duplikace mezi moduly (kap. 7.1).
8. **Žádné free/pro vrstvy:** starý plugin rozlišoval free a pro varianty modulů - nový
   plugin toto NEpřebírá. Každý modul se portuje v plné funkčnosti (free+pro sloučeno),
   žádné licencování, žádné `pro/` podsložky.

### Zbývá dodat později (neblokuje F0):
- GitHub org/repo název (pro `Update URI` a CI) - dodáš, až založíme repo.

---

## 15. Plán nápravy po parity auditu (2026-09-03)

Audit `PARITY.md` (statická parita 68 modulů + migrace + smoke + A/B dat) ukázal, že
předpoklad „69 modulů 1:1" (bod 4) **neplatí**: funkčně 1:1 je jen 33 modulů, 20 má drobný
rozdíl, **15 má zásadní funkční mezeru**. Navíc `Migrator` migruje nastavení, ale **NE
historická data** modulů s vlastní tabulkou. Tento oddíl je konkrétní seznam nápravy před
přepnutím (F5). Detaily a čísla: `PARITY.md`, `../../../parity-test-pobyty/static/*.md`.

Priorita = závažnost × reálné použití na TOMTO webu (signál: legacy data/aktivní modul).
Po dokončení KAŽDÉ položky ji znovu ověřit A/B přes harness (`parity-test-pobyty`).

### 15.0 Aktivační handoff flow (POTVRZENO 2026-09-03 - mění kap. 10)

Migrace se spustí **automaticky při aktivaci** ux-studio, pokud je přítomen ux1. Cílový flow:

HOTOVO+OVĚŘENO 2026-09-03 (`Core/Handoff.php`, `ux-studio.php`). Test na kopii: ux1 ON + aktivace ux-studio → ux1 OFF, ux-studio ON, migrace (activity 227, options 27), offer flag set, admin 200.
- [x] při aktivaci ux-studio detekovat aktivní `ux1-wordpress-customizer` (aktivační hook registrován PŘED conflict guardem, takže běží i s aktivním ux1)
- [x] migrace + deaktivace ux1: ODCHYLKA od doslovného zadání - migrujeme PŘED deaktivací (bezpečnější: legacy deactivation hook nemůže smazat data před kopií; výsledek stejný), ux-studio zůstává aktivní
- [x] admin notice s nabídkou smazání ux1 (`delete_plugins`), `delete_plugins` capability + nonce (`admin-post`)
- [x] nabídku zobrazit jen když ux1 soubory existují (jinak flag vyčistit)
- [x] čistá instalace (ux1 není): deaktivace/nabídka se přeskočí, běžný start
- [x] idempotentní (DONE_OPTION guard + offer flag). OVĚŘENO: delete_plugins smaže soubory, ux1_* DB tabulky ZŮSTANOU (ux1 nemá uninstall) → moduly se domigrují i po smazání. Pozn.: na Windows lokálu delete hlásí "nešlo zcela smazat" (zamčené soubory) - prostředí, ne kód; na produkci OK.

### 15.1 Migrace historických dat (KRITICKÉ - jinak tichá ztráta při přepnutí)

Naměřená ztráta (legacy → Studio po čisté migraci): dopiš `Migrator` data-migraci
(mapování sloupců, ne blind copy) + verzování; přidat do „dry-run" logu počet přenesených řádků.
Toto běží uvnitř flow 15.0 (po deaktivaci ux1, před tím než cokoli data smaže).

- [x] `activity-log` - `ux1_activity_log` → `uxstudio_activity_log` (sloupcové mapování user_name/object_name/ip_address → meta JSON). HOTOVO+OVĚŘENO 2026-09-03: 227→227 na kopii.
- [x] `push-notifications` - `ux1_push_subscribers` + `ux1_push_notifications` → uxstudio (napojeno na `ensure_module_tables` hook, endpoint_hash dopočítán). HOTOVO+OVĚŘENO 2026-09-03: 6→6 obojí, mapování i idempotence OK.
- [x] `service-requests` - `ux1_service_requests` → uxstudio (subject→title, user_email→requester_email, status `new`→`open`). HOTOVO+OVĚŘENO 2026-09-03: 2→2.
- [x] `popup-manager` - `ux1_popup_stats` VĚDOMĚ NEMIGROVAT: legacy = denní agregace, Studio = surové události, popup_id odkazuje na jiné CPT. Bez mapování popupů bezcenné. Zdokumentováno v Migrator.php.
- [x] `performance-optimization` - `ux1_performance_history` VĚDOMĚ NEMIGROVAT: jiný datový model + modul vědomě zúžen (potvrzeno). Zdokumentováno.
- [x] `email-log` / `smtp-email` - `wpext_logs` → `uxstudio_email_log` (status odvozen z error). Implementováno; na tomto webu 0 řádků (neověřitelné daty, ale mapování hotové pro produkci).
- [x] `ai-assistant` - `ux1_ai_assistant_conversations` → uxstudio (identické schéma, přímá kopie). HOTOVO+OVĚŘENO 2026-09-03: 2→2. Indexy (product/content) vědomě nemigrovány = regenerovatelné.
- [x] `ai-markdown` - `ux1_ai_markdown_cache` (56) = regenerovatelná cache, VĚDOMĚ NEMIGROVAT (naplní se sama za běhu).
- [x] `query-log` OVĚŘENO 2026-09-03: legacy 65 = studio 65, max(created_at) shodné - žádná ztráta (dřívějších "68" bylo časové měření).
- [x] finální re-run OVĚŘENO 2026-09-03: čistá migrace + zapnutí modulů + boot → shoda u VŠECH migrovaných (activity 227, push 6+6, service 2, ai-konverzace 2, query 65), nemigrované správně 0. **15.1 KOMPLETNÍ.**

### 15.2 Dodělání funkčních mezer (15 ❌)

**P1 - kritické (web to používá a/nebo bezpečnost):**
- [x] `push-notifications` - reálné odeslání doimplementováno: `WebPushCrypto` (VAPID JWT ES256 podpis dle RFC 8292 + payload šifrování aes128gcm dle RFC 8291, ECDH+HKDF, čistý openssl) + `Sender` (per-subscriber šifrování, wp_remote_post, 404/410→smazání expirovaného, delivered/failed události). Plánování (scheduled_at → WP-Cron `uxstudio_push_send`), segmentace (all / recent_30d), analytika (REST `/analytics` + delivered/failed/clicked). SPA: taby Send (URL/segment/naplánování), Subscribers, Analytics. Opraven bug: `Vapid::generate()` nepředával openssl config → na Windows/XAMPP tiše selhával (klíče se negenerovaly). HOTOVO+OVĚŘENO 2026-09-03: JWT se ověří VAPID veřejným klíčem, šifrování round-trip (encrypt→decrypt) PASS, send-loop wiring (sent_count/delivered/failed události, cron scheduling) PASS na pobyty-studio - bez odeslání reálným 6 odběratelům (test přes syntetický nedostupný endpoint, reální zálohováni+obnoveni).
- [x] `activity-log` - doplněno ~20 sledovaných událostí (posty/status-transition, users/role/register/delete, plugins, themes, terms, media, comments, WC objednávky, logout) + `alert_role_escalation` (email při povýšení na admin, toggle). HOTOVO+OVĚŘENO 2026-09-03: post_publish/draft/delete, user_register, role_change, delete-user zalogovány správně.
- [x] `bot-throttle` - přenesena celá adaptivní logika z legacy: Detector (7 kategorií botů + UA/IP whitelist/blacklist + rDNS verifikace), LoadSampler (sliding-window load → tier GREEN/YELLOW/ORANGE/RED s hysterezí), Throttler (per-kategorie × per-tier plán: pass/delay/microcache/block, vyhledávače nikdy neblokovány), Microcache (FS cache s deny-all .htaccess), Log (obohacená tabulka, GDPR hash IP) + PHP dashboard widget + SPA taby Dashboard/Log/Test + REST dashboard/test/clear. Ponechán hard per-IP rate-limit cap. HOTOVO+OVĚŘENO 2026-09-03 na pobyty-studio: GPTBot/AhrefsBot→microcache+delay, Googlebot→jen delay (chráněn), rule=block→429+Retry-After, Googlebot i s block rule→200, microcache capture→hit, human UA→bez zásahu. Schéma migrace v1→v2 (přidány sloupce) ověřena.

**P2 - důležité (osekané, pravděpodobně používané):**
- [ ] `content-sync` - doplnit plnou Hub↔Node správu (CRUD příspěvků/kategorií/médií/ACF, SSO, media transfer); dnes jen uložení URL+HMAC + log
- [x] `dashboard-widgets` - doplněna správa reálných wp-admin dashboard widgetů: `wp_dashboard_setup` hooky snímkují registrované widgety (transient) a odstraní admin vybrané (`hidden_widgets` multiselect) nebo všechny (`disable_all_widgets` toggle + welcome panel). Nastavení nabízí widgety reálně registrované na webu (core defaulty + cache). SPA (PageSpeed/aktivita/úkoly) zůstává. HOTOVO+OVĚŘENO 2026-09-03: skrytí konkrétního widgetu (odstraní cíl, ostatní zůstanou), disable-all (vyprázdní dashboard), cache→available list.
- [x] `exit-popup` - doplněno appearance/CTA/image + barvy/overlay, autoresponder (wp_mail, jen pro nové odběratele), cookie frekvence (session/cookie N dní/always), všech 5 detekčních režimů (mouse-leave, tab-change, window-blur, idle, scroll-up + time-on-page), URL/post-type cílení + hide-for-logged-in. Frontend přes rozšířený assets/exit-popup.js z lokalizovaného configu. HOTOVO 2026-09-04 (fleet agent): php -l + node --check clean, build clean, boot 200. Neověřeno: chování popupu v prohlížeči (jen ruční test).
- [x] `opening-hours` - doplněna zobrazovací vrstva (`Frontend.php`): shortcody `[opening_hours]` (karta s týdenní tabulkou, lokalizované názvy dnů, zvýrazněný dnešek, open-now badge, dnešní výjimka/svátek) + `[opening_hours_status]` (inline badge) + Schema.org JSON-LD (LocalBusiness + openingHoursSpecification, na single lokaci / homepage / dle ID) + české státní svátky (`Holidays.php`, Velikonoce Meeus/Jones/Butcher) započítané do open-now. Vědomě NEportováno: dekorativní widget zoo (analog/digital hodiny, foto karty, 4 mapoví provideři) - nízká marginální hodnota. HOTOVO+OVĚŘENO 2026-09-03 na pobyty-studio: compute_status open=true, shortcode render (badge/open/address/table), status shortcode, svátek 1.1. detekován, Velikonoce 2026=5.4. správně, JSON-LD na homepage validní (LocalBusiness+geo+openingHoursSpecification).
- [x] `notice-board` - plná implementace (dřív stub): 3 vlastní tabulky (DB v2), notice s tělem/kategorií/referencí/více přílohami (media IDs)/publish+expiry daty/auto-archivací+retencí, kategorie, double-opt-in e-mailové odběry per kategorie + notifikace při publikaci, RSS feed (`?feed=uxstudio-notice-board`), frontend shortcode `[uxstudio_notice_board]`, REST CRUD + veřejné subscribe/confirm/unsubscribe (rate-limited). SPA 4 taby. HOTOVO+OVĚŘENO 2026-09-04 (fleet agent): php -l + build clean, boot 200, DB v2 tabulky ověřeny.
- [x] `instagram-feed` - plná implementace (dřív jen img mřížka): přímá Instagram Graph API integrace (`InstagramClient`: authorize/exchange/long-lived/refresh/profile/media), OAuth connect přes admin-post callback (token+app_secret přes Security), per-feed CRUD s 6 tématy, sideload médií do knihovny (dedup přes meta), cron refresh (interval + token refresh 14 dní před expirací), hashtag include/exclude filtry, shortcode `[uxstudio_instagram]`, SPA 4 taby (Connection/Media/Feeds/Settings). DB v2 (feeds + rozšířená media tabulka). ODCHYLKA: standalone OAuth místo broker-only kontraktu (dle zadání gapu; zdokumentováno v docblocku). HOTOVO 2026-09-04 (fleet agent): php -l + build clean, boot 200, DB v2 tabulky ověřeny. Neověřeno: reálný fetch/OAuth (chybí IG token).

**P3 - nižší (osekané, web spíš nepoužívá):**
- [x] `cron-control` - doplněno řízení režimu WP-Cronu (none/block_all/local_only/external/central_app) přes mu-plugin + .htaccess marker (wp-config se NEEDITUJE, WP_Filesystem s writability guardem), watcher naplánovaných úloh (+auto-remove/whitelist s wildcardem), Schedules/Watcher/Mode SPA taby, admin-bar varování. HOTOVO 2026-09-04 (fleet agent): php -l + build clean, boot 200. Nezachován legacy HMAC push cron configu na hub (studio content-sync nemá node schema). Neověřeno funkčně: reálný zápis mu-pluginu/.htaccess a efekt na DISABLE_WP_CRON.
- [x] `download-files` - koncept SJEDNOCEN (rozhodnutí: zachovat studio tokenized knihovnu A přidat legacy frontend): tokenized secure-download endpoint zůstává, přidán shortcode `[download_files]` (ids/category/post) s odkazy přes počítaný endpoint, download counter, login gating, kategorie + attach-to-post (DB v2: +category/+post_id). HOTOVO 2026-09-04 (fleet agent): php -l + build clean, boot 200, DB v2 sloupce ověřeny.
- [x] `elementor-import` - doplněn URL import (SSRF-guarded), HTML import (přes HtmlToElementor), export existující stránky do JSON, režimy new_page/replace/append (s _elementor_data_backup). Elementor-presence guardy → 424 (nikdy nefataluje), IDOR guard (edit_post) na replace/append/export. Doplněn chybějící záznam v registry.ts. HOTOVO+OVĚŘENO FUNKČNĚ 2026-09-04: na pobyty-studio je Elementor 4.0.1 + Pro aktivní; export stránky 17 → import jako nový draft → `_elementor_data` (1727 B) + `_elementor_edit_mode=builder`, PASS.
- [x] `page-load` - doplněny metriky query/paměť (get_num_queries + memory_get_peak_usage, DB v2: +query_count/+memory_peak_kb), admin-bar indikátor (barevně odstupňovaný čas + dropdown queries/paměť), per-plugin benchmark (activated/deactivated_plugin → cron N uncached front-page requestů → tabulka uxstudio_page_load_impact), SPA taby Overview+Plugin impact. HOTOVO+OVĚŘENO 2026-09-04 (fleet agent): build/lint clean, boot 200, DB v2 migrace + impact tabulka ověřeny. Benchmark potřebuje funkční loopback HTTP.

**Vědomá rozhodnutí (POTVRZENO 2026-09-03 uživatelem - záměr, NEdodělávat, jen doplnit do dokumentace parity jako „by design"):**
- `performance-optimization` - vědomě zúženo z bezpečnostních důvodů (read-only + 3 fixy). Ponecháno.
- `google-review-request` - přeznačeno (on-site popup → review-request e-mail). Nová funkce je záměr.
- `guide` - přeznačeno (editor Návodu → onboarding checklist). Záměr.

### 15.3 Drobné rozdíly / regrese s reálným dopadem (⚠️)

- [x] `hide-admin-bar` - regrese OPRAVENA 2026-09-03: prázdné role → `return $show` (neskryje nikomu, jak legacy) místo `return false`; help text sladěn. Ověřeno logicky (3 případy).
- [x] `pixel-tag-manager` - migrace vnitřních klíčů settings blobu (google-analytics→google_analytics atd.) doplněna do Migratoru (krok 4c, idempotentní). HOTOVO+OVĚŘENO 2026-09-03 syntetickými daty: hyphen→underscore, hodnoty zachovány. (Drobnost: validace formátu ID při uložení zbývá - nízká priorita, nejde o ztrátu dat.)
- [x] `smtp-email` - doplněn Gmail OAuth2 transport (`GmailClient`: auth URL/exchange/refresh/send_raw + multipart přílohy; connect/disconnect přes admin_init redirect s nonce+state, klíče přes Security), From/Force-From volby (wp_mail_from filtry prio 999), resend posledního e-mailu + logování Brevo/Gmail sendů. HOTOVO 2026-09-04 (fleet agent): php -l + build clean, boot 200. Neověřeno: reálný Gmail OAuth round-trip (chybí Google Cloud creds).
- [x] `image-optimizer` - doplněn AVIF (GD/Imagick feature-detect, na tomto XAMPP GD AVIF=Y), WebP/AVIF delivery přes `uploads/.htaccess` marker blok (insert_with_markers, realpath guard, Vary: Accept), auto-optimalizace při uploadu (wp_generate_attachment_metadata), scanner nepoužitých obrázků (read-only + recoverable trash) - SPA tab Unused Images + status panel. HOTOVO 2026-09-04 (fleet agent): php -l + build clean, boot 200. Neověřeno: reálný AVIF encode/delivery přes Apache.
- [x] `security-optimization` - doplněn CSP builder s módy off/report-only/enforce (zjištěno: dřív se neposílala ŽÁDNÁ CSP hlavička; presety + custom allowlist, report-uri na existující sink, jen na frontendu - wp-admin chráněn proti self-lockoutu), Upload Guard UI (enable/notify/email v samostatném sub-formu), hardening toggly (block_user_enumeration, disable_file_editing/DISALLOW_FILE_EDIT, protect_login + rozšířeno xmlrpc/wp-version/security-headers). HOTOVO 2026-09-04 (fleet agent): php -l + build clean, boot 200. Pozn.: na Hostingeru CSP stejně přepisuje LiteSpeed (viz reference), enforce je implementován korektně pro hostingy, kde projde.
- [x] `third-party-login` - doplněno role gating (fail-closed: prázdný allow-list = nikdo), opt-in auto-create s konfigurovatelnou rolí (admin nikdy nepřiřaditelný), link/unlink self-service (user meta + HMAC-signed state token, 600s, cap `read`, jen current user). Navíc opraven account-takeover: neznámé identity se už neváží na existující účet přes shodu e-mailu. HOTOVO 2026-09-04 (fleet agent): php -l + build clean, boot 200. Neověřeno: reálný OAuth callback z centrální app.
- [x] `email-log` - doplněno ukládání těla/hlaviček/příloh (capture přes `wp_mail` filtr prio 999 → pending řádek, pak wp_mail_succeeded/failed překlopí stav; DB v2: +source/+message/+headers/+attachments), detekce zdroje (backtrace → plugin/theme, toggle), resend (wp_mail; přílohy jen názvy). SPA: source sloupec + detail modal (hlavičky/přílohy/sandboxed body iframe) + resend. HOTOVO+OVĚŘENO 2026-09-04 (fleet agent): build/lint clean, boot 200, DB v2 sloupce ověřeny.
- [x] `folder-manager` - doplněno rename složky (unikátní slug, kontrola duplicit), reparent (guard proti cyklu/self-parent přes ancestor walk), bulk-move příloh (per-item edit_post capability, skip nevalidních). REST PUT/move/items-move, SPA inline rename + move-under select (bez descendantů) + bulk panel. HOTOVO 2026-09-04 (fleet agent): php -l + build clean, boot 200. Neověřeno funkčně: reálné taxonomy operace na běžícím webu.
- [x] `admin-columns` - doplněn `FieldRenderer` s 9 typy (text/number/boolean/date/image/url/email/color/post) + per-column selektor „render meta value as" a data-source (meta/taxonomy/post_id/thumbnail). Vše escapováno, zpětně kompatibilní (chybějící field_type → text). SPA: druhý select v Type buňce jen pro meta zdroj. HOTOVO 2026-09-04 (fleet agent): php -l + build clean, boot 200. Neověřeno funkčně: render na reálných list tables.
- [ ] projít zbylé ⚠️ z `PARITY.md` a rozhodnout, co je bug a co přijatelný rozdíl

### 15.4 Uzavření (F5 gate)

Stav k 2026-09-04 po dokončení 15.1-15.3 a smoke re-runu:

- **15.1 migrace historických dat** - HOTOVO+OVĚŘENO (viz výše).
- **15.2 funkční mezery (15 ❌)** - HOTOVO všech 15: activity-log, bot-throttle, push-notifications,
  content-sync(ODLOŽEN), cron-control, dashboard-widgets, download-files, elementor-import,
  exit-popup, instagram-feed, notice-board, opening-hours, page-load + google-review-request/guide
  (přeznačené = záměr). Jediná nedokončená: **content-sync** (viz níže).
- **15.3 drobné rozdíly (⚠️)** - hotové: hide-admin-bar, pixel-tag-manager, smtp-email, image-optimizer,
  security-optimization, third-party-login, email-log, folder-manager, admin-columns. Zbývající ⚠️
  (admin-customiser sidebar, ai-markdown sitemap, auto-unpublish JS panel, email-health mail-tester,
  post-type-switcher bulk, user-switching redirect varianty, review-aggregator zdroje) = přijatelné
  drobnosti, ne bloker F5.

Zbývající skutečná ❌ v matici (po srovnání se skutečností):
- `content-sync` - **ODLOŽEN** (5127ř. Hub↔Node, chce vlastní hub+node harness, web je idle node).
- `google-review-request`, `guide`, `performance-optimization`(migrace), `popup-manager`(migrace) =
  **vědomá rozhodnutí potvrzená uživatelem** (by design, ne mezery).

- [x] SPA smoke re-run 18 opravených modulů (Playwright): 0 REST/console chyb, vše renderuje (2026-09-04).
- [x] migrační re-run harness `reset-migration.ps1` (2026-09-04): čistá migrace, počty legacy vs studio bez ztráty - activity_log 226→230 (226 přeneseno + nové z bootu), push_subscribers 6=6, push_notifications 6=6, service_requests 2=2, ai_conversations 2=2, query_log 68 vs 65 = známý časový artefakt (legacy dál loguje, ne ztráta). Home 200 s 21 moduly. Statická parita srovnána v matici výše.
- [ ] dořešit `content-sync` (samostatná session s hub+node harnessem) NEBO vědomě vypustit z v1 scope
- [x] elementor-import ověřen funkčně (Elementor je na pobyty) - export→import PASS.
- [x] migrace vyplněných API klíčů z ux1 do studia (Migrator krok 4d): stock-photos (pexels/pixabay/unsplash/mapillary, plain→secret) + ai-assistant (claude_api_key, ux1-decrypt SECURE_AUTH_KEY → re-encrypt studio) - OVĚŘENO 2026-09-04: hodnoty se shodují po dešifrování, uloženo šifrovaně. Pozn.: ux1 smtp-email/instagram-feed na pobyty NEMAJÍ vyplněno nic (e-maily jdou přes jiné pluginy), takže tam není co přenášet; Gmail/Instagram creds zadá uživatel jednou v UI a jeho testování si dělá sám.
- [ ] zbývá jen funkční A/B smtp-email (Gmail OAuth) + instagram-feed (IG token) - potřebuje reálné creds, testuje uživatel
- [x] **F5 přepnutí PROVEDENO 2026-09-04 na lokálním webu `pobyty`** (DB `pobyty`): záloha DB (166 MB, `D:\parity-test-pobyty\f5-backup\`) → aktivace ux-studio → Handoff deaktivoval ux1 → migrace re-run (guard byl z 15.8., proto přeskočena; smazán a spuštěna znovu kvůli novým krokům vč. klíčů) → zapnuto 48 modulů (mapováno z 58 legacy, konsolidované slity, reservation-calendar vypuštěn). Ověřeno: ux-studio active / ux1 inactive, frontend 200, backend bez fatalu, migrované klíče sedí (pexels/claude). ux1 soubory PONECHÁNY jako záloha (Ux1Lock brání reaktivaci; Handoff nabízí smazání, neprovedeno). Produkční nasazení na server = samostatný krok (deploy pluginu), NEprovedeno.

---

## 15. Přihlášení přes Seznam účet + srovnání handshake s centrální aplikací (2026-09-10)

Zadání bylo přidat Seznam účet jako čtvrtého providera. Při čtení modulu se ukázalo, že
`third-party-login` ve studiu mluvil **jiným protokolem, než centrální aplikace umí** —
posílal `?site=&return_to=&provider=&mode=` na kořen CA, bez podpisu, a čekal zpátky POST
JSON. V CA takový endpoint neexistuje (`BaseAuthController` má jen podepsaný
`?page={provider}_auth&action=init`). Nefungoval tedy ani Google; Seznam by ten rozpor
nevyřešil. Rozhodnutí uživatele: srovnat studio na protokol CA (varianta A).

- [x] `Module::PROVIDERS` rozšířeno o `seznam` (+ labels, settings options, meta.json)
- [x] `handshake_url()` přepsán na protokol CA: `?page={provider}_auth&action=init`
      s `site_url`, `return_url`, `mode`, `nonce`, `ts`, volitelně `user_id`, a `sig`
- [x] `Module::sign()` / `verify()` — HMAC-SHA256 nad ksort()ovanými parametry
      (byte-identické s `GoogleProxySigner` v CA), replay okno 300 s
- [x] jednorázový nonce v transientu (900 s) nese mode + provider + iniciujícího uživatele,
      takže callback nejde zaměnit za jiný; nahradil dřívější self-contained `state` token
- [x] callback route překlopena z `POST` (JSON) na `GET` (podepsaný redirect z CA)
      a odpovídá **přesměrováním**, ne JSONem — přistává na ní prohlížeč návštěvníka
- [x] front-endový vstup `?uxstudio_tpl=login&provider=X` (nonce se razí až při odchodu
      na CA, ne při každém vykreslení login formuláře)
- [x] **nalezeno a opraveno:** modul `security-optimization` zamyká celé REST API na
      přihlášené uživatele, takže veřejný callback vracel 401 a flow nikdy nedoběhl.
      Přidán filtr `uxstudio_rest_public_routes`; TPL modul si přes něj whitelistuje
      jen svou callback routu (chráněnou HMACem a nonce, ne session)
- [x] E2E ověřeno 2026-09-10 na lokálním `pobyty` proti lokální CA: login-start →
      podepsaný init → CA přijala a přesměrovala na `login.seznam.cz` → podepsaný callback
      přihlásil spárovaného uživatele (auth cookie). Negativní případy: neznámý sub →
      `notlinked`, podvržený e-mail → `signature`, replay nonce → `expired`, vymyšlený
      nonce → `expired`. Testovací stav lokálu vrácen zpět.
- [ ] reálný OAuth round-trip se Seznamem — čeká na registraci aplikace na
      `vyvojari.seznam.cz/oauth/admin` a vyplnění Client ID/Secret v CA
- [ ] nasadit na weby (modul je na lokále i po testu **vypnutý**, konfigurace smazaná)

Pasti: `redirect_uri` Seznam vždy přepisuje na https (kromě `localhost`), scope se
odděluje čárkami a musí obsahovat `identity`, a `account_name` u firemních domén není
e-mail — bere se pole `email`. Frontend (`src/`) se neměnil, takže rebuild JS není nutný.


## 16. Modul Service Requests jako klient centrálního ticket systému (2026-09-10)

Modul přestal být vlastníkem dat. Zdrojem pravdy je od F2 tabulka `tickets`
v centrální aplikaci; lokální řádek zůstává jen proto, aby klient viděl svoje
požadavky i ve chvíli, kdy je centrála nedostupná. Číslo, stav a celá
konverzace se zrcadlí odtamtud. Zadání a fáze F1–F7 jsou v `PLAN.md` centrály,
sekce „Ticket systém (helpdesk) v centrále".

**Kanál se nezakládal nový.** Používá se existující dvojice klíčů a existující
podpisové schéma hub↔node:

- tajemství: `node_api_key` (`ContentSyncModule::SECRET_NODE_KEY`) — týž sdílený
  klíč, kterým centrála volá tenhle web opačným směrem,
- podpis: `ContentSync\HmacAuth::sign()`, tedy
  `METHOD \n URL \n TIMESTAMP \n NONCE \n sha256(body)`,
- adresa: `central_app_url` z nastavení content-syncu.

Centrála ověřuje `HmacAuth::signWithNonce()` a podpis spálí v `hmac_nonces`,
takže zachycený požadavek nejde přehrát. Žádné nové párování, žádná nová
kryptografie, jedno místo, kde se web přepojí na jinou centrálu.

**Nové soubory:** `ServiceRequests/CentralClient.php` (podepsaný klient),
`ServiceRequests/Sync.php` (fronta, backoff, pull, sběr prostředí).
Schéma modulu na v2: rozšířené sloupce požadavku + zrcadlo z centrály +
tabulka `uxstudio_service_request_outbox` pro odpovědi klienta.

- [x] Formulář sbírá URL stránky, typ a naléhavost a **automaticky přikládá
      kontext webu** (WP, PHP, šablona, jazyk, role, prohlížeč, 30 aktivních
      pluginů s verzemi). Sbírá se v okamžiku odeslání — pozdější čtení by
      popsalo web, jaký je teď, ne jaký byl, když se to rozbilo.
- [x] Push do centrály hned při založení, při neúspěchu fronta s backoffem
      (0/1/5/15/60/180/360/720 min, 8 pokusů) a cron `uxstudio_five_minutes`.
      Trvalá chyba (4xx mimo 429) retry zastaví a zůstane vidět jako
      `sync_state='error'` i s důvodem.
- [x] Idempotence přes `external_id = uxs-<id>`; opakovaný push jen vrátí
      existující ticket a nic nepřepíše.
- [x] Odpovědi klienta jdou přes outbox (jedna neodeslaná zpráva nesmí
      zablokovat další), příloha se posílá zvlášť podepsaným uploadem.
- [x] Pull stavu a vlákna; obrazovka ukazuje číslo `TCK-…`, stav slovy,
      konverzaci, odeslání odpovědi a tlačítko Obnovit.
- [x] E2E ověřeno 2026-09-10 na lokálním `pobyty` proti lokální CA — 22 kontrol
      včetně toho, že **interní poznámka operátora se na web nedostane** a že
      požadavek založený při nedostupné centrále se neztratí a po obnovení
      spojení se dopushuje.
- [ ] nasadit (na lokále zůstává spárování s lokální CA kvůli proklikání)

**Opraveno při té příležitosti:** `SmtpEmail\Module::capture_last_message()` měl
typovaný parametr `array`, ale filtr `wp_mail` nemá zaručeno, že pole ponese —
plugin Disable Emails do něj posílá `false`. Na webu s ním aktivním tím padalo
odeslání servisního požadavku fatální chybou. Teď se nepolní hodnota propustí beze změny.

**Past:** `Sync::collect_environment()` volá `get_plugin_data()`, takže potřebuje
`wp-admin/includes/plugin.php`. V kontextu REST požadavku není načtený sám od sebe.

## 17. RankMath Content AI parita — sdílené jádro s centrani-app (2026-09-15)

**Cíl:** dohnat funkce https://rankmath.com/content-ai/ (content score v editoru,
40+ AI content/copywriting nástrojů, topic research, schema markup, interní
linking, RankBot chat) — plná parita, ne jen SEO jádro. Byznys logika žije v
`centrani-app` jako API (`SeoAnalyzer`, `Checks/*`, prompty), ux-studio je
konzument pro live panel ve WP editoru. Plný plán: `centrani-app/PLAN.md` §22
(sdílená architektura, F1-F5). Zdejší úkoly jsou WP-strana toho plánu.

**Rozhodnutí:** keyword research AI-only (Claude/OpenAI odhad z kontextu),
žádné placené keyword API se search volume/difficulty (F3).

### 17.1 F1 — SEO scoring most ✅ backend *(hotovo lokálně 2026-09-15)*
- [x] `includes/Modules/AiAssistant/SeoAiClient.php` — HMAC klient, reuse
      `Modules\ContentSync\HmacAuth::sign()` + `node_api_key` secret a
      `central_app_url` nastavení (žádný nový secret, žádné nové párování —
      stejný kanál, jen v opačném směru než hub→node sync).
- [x] `SeoAiClient::analyze(array $fields)` volá `centrani-app`
      `?page=seo_ai_api&action=analyze`.
- [x] `includes/Modules/AiAssistant/SeoScorePanel.php` + `SeoBootstrap.php` —
      REST `POST uxstudio/v1/ai-assistant/seo/score` (proxy na CA), zapojeno
      do `Module::boot()`.
- [x] E2E ověřeno 15.9. přes `wp-cli eval`: `SeoAiClient::analyze()` vrátil
      reálné skóre z CA (secret na obou stranách sedí — `Security::get_secret`
      na WP straně == `sites.api_key` na CA straně pro site #142), REST route
      zaregistrovaná.
- [x] `includes/Modules/AiAssistant/SeoScoreEditor.php` — registruje
      `_uxstudio_ai_seo_focus_keyword` (nový) + REST-exposuje existující
      `SeoManager::META_TITLE`/`META_DESCRIPTION` a nové `META_SCORE`/
      `META_GRADE`, enqueue `seo-score-panel.js`/`.css` v block editoru.
- [x] `assets/js/seo-score-panel.js` — vanilla JS (bez wp-scripts buildu,
      stejný vzor jako `Modules\ExternalPermalinks`), `PluginSidebar` se
      skóre badge + checklistem, debounced (700 ms) přes `wp.data.subscribe`,
      focus keyword/meta title/desc editovatelné přímo v panelu, skóre+grade
      se po každé analýze zapíše do post meta (přežije uložení článku).
- [x] E2E ověřeno 15.9. přes `rest_do_request()` uvnitř wp-cli: přihlášený
      admin dostal HTTP 200 s reálným skóre, anonym HTTP 401; registrace
      meta (`show_in_rest=1` pro všech 5 klíčů) potvrzena.

F1 je hotové kompletně (backend most + editor UI).

### 17.2 F2 — Copywriting/content generátory ✅ backend *(hotovo lokálně 2026-09-15)*
- [x] `includes/Modules/AiAssistant/PromptLibrary.php` — registr 39 šablon
      (blog, product, social, email/bio, copy-formulas AIDA/IDCA/PAS/HERO/
      SPIN/BAB, video/podcast, recipe, freeform, ai_command, topic_research,
      faq), tón hlasu + jazyk (cs/en dle nastavení modulu jako u ostatních
      generátorů).
- [x] `ContentGenerator::generate_from_prompt(string $toolKey, array $vars,
      array $opts): array` — validuje required vars, staví prompt z šablony,
      volá provider, parsuje JSON (reuse `parse_json_response`).
- [x] `ContentGenerator::generate_alt_text(array $context)` — ALT text
      z KONTEXTU (title/caption/filename/nadřazený post), ne ze skutečného
      obrázku — žádný provider v `ProviderFactory` zatím neumí vision vstup
      (`AiProviderInterface::generate_content()` je čistě textové). Stejné
      omezení jako `emcp-tools`' `add-alt-text-from-context`.
- [x] `ContentRestController` — `POST /ai-assistant/content/tool` (parametr
      `tool`) + `GET /ai-assistant/content/tools` (katalog pro picker UI).
- [x] `bulk-alt/status` + `bulk-alt/run` podle vzoru `bulk-seo/status|run`
      (WP_Query na `_wp_attachment_image_alt` NOT EXISTS/prázdné, limit
      1-20/request).
- [x] E2E ověřeno přes `rest_do_request()`: katalog vrátil 39 nástrojů,
      neznámý tool/chybějící povinná proměnná vrací čistou 400 chybu,
      `bulk-alt/status` vrátil reálný počet (8488 na lokále), samotné
      volání AI providera narazilo na účtový limit Claude API (ne na chybu
      v kódu — routing/parsing/JSON error handling funguje).
- [x] `ContentToolbarEditor.php` + `assets/js/ai-toolbar.js` *(16.9.2026)* —
      AI akce v toolbaru bloku nad OZNAČENÝM textem přes
      `registerFormatType` (formát se nikdy neaplikuje, je to jen nosič
      tlačítka) + `ToolbarDropdownMenu`: Rozvinout (`sentence_expander`),
      Přepsat lépe (`paragraph_rewriter`), Shrnout (`text_summarizer`),
      Opravit gramatiku (`fix_grammar`). Výsledek nahradí označený úsek přes
      `richText.insert()`. Pozice výběru se zapamatuje PŘED voláním — jinak
      by odpověď přepsala to, kam uživatel mezitím klikl.
- [x] `ContentToolbarEditor::actions()` ověřuje klíče proti `PromptLibrary` —
      po přejmenování nástroje tlačítko zmizí, místo aby tiše vracelo
      „Unknown content tool".
- [x] Ověřeno: 4 akce se rozpadly správně proti PromptLibrary, hook
      `enqueue_block_editor_assets` navěšen, a celý řetězec
      `generate_from_prompt()` protažen podvrženým providerem — instrukce
      nástroje se opravdu dostane do system promptu a odpověď má tvar
      `{text, _usage, _provider, _model, _tool}`, tedy `payload.data.text`,
      na který JS spoléhá. ŽIVÉ volání AI ověřeno NEBYLO (účtový limit
      Claude API do 1. 10.).
- [ ] ZBÝVÁ: tool picker UI pro zbylých 35 nástrojů z `PromptLibrary`
      (endpoint i katalog `GET /content/tools` hotové, chybí jen obrazovka).
- [ ] ZBÝVÁ (volitelné): `uxstudio_ai_assistant_tool_history` tabulka pro
      historii výstupů.

### 17.3 F3 — Topic research ✅ *(hotovo lokálně 2026-09-15)*
- [x] `SeoAiClient::topic_research()` — reuse nové sdílené `call()` helper
      metody (refaktorováno z `analyze()`, žádná duplicitní HMAC logika).
- [x] `SeoScorePanel::topic_research()` — `POST
      uxstudio/v1/ai-assistant/seo/topic-research`.
- [x] JS: sbalitelná sekce "Návrh klíčových slov" v `seo-score-panel.js` —
      tlačítko vezme aktuální focus keyword jako seed, zobrazí chips s
      related keywords + seznam podtémat, s hintem že jde o AI odhad, ne
      reálná data.
- [x] E2E ověřeno přes `rest_do_request()` — auth/routing prošly, HTTP 424
      jen kvůli nedostupnému lokálnímu `claude_code` bridge (ne kvůli chybě
      v kódu).

### 17.4 F4 — Schema markup + cross-site linking ✅ *(hotovo lokálně 16.9.2026)*
- [x] `SeoAiClient::schema()` + `::link_suggestions()` — čistý konzument
      nových CA endpointů přes sdílenou `call()` metodu.
- [x] `SeoScorePanel::schema()` + `::link_suggestions()` — REST proxy
      `POST uxstudio/v1/ai-assistant/seo/schema` a `.../seo/link-suggestions`.
- [x] JS: dvě další sbalitelné sekce v `seo-score-panel.js` — "Schema markup
      (JSON-LD)" s tlačítkem Vygenerovat + Zkopírovat (`navigator.clipboard`,
      tmavý `<pre>` blok), "Interní odkazy" s tlačítkem Najít návrhy +
      seznam odkazů (nebo hint, že chybí portfolio/shoda).
- [x] E2E ověřeno přes `rest_do_request()` — schema vrátila reálný
      Article+FAQPage JSON-LD, link-suggestions vrátil prázdné pole (web
      zatím bez `portfolio_key` na CA straně — správné chování).

### 17.6 Ověření v živém prohlížeči (16.9.2026) — 3 nálezy

Playwright proti lokálnímu `pobyty`. Panel i toolbar ověřeny naostro
(skóre 72 → 80 po zadání klíčového slova, schema vygenerovalo
Article+FAQPage, interní odkazy vrátily relevantní návrhy a irelevantní
vynechaly, toolbar "AI nástroje" se ukázal a chyba AI se zobrazila jako
snackbar, aniž by poškodila označený text).

**Tři nálezy, které testy přes `rest_do_request()` ukázat nemohly:**

1. **OPRAVENO** (commit def5ca8): panel bušil naprázdno — `wp.data.subscribe`
   reaguje i na cizí změny storu (~12×/s), naměřeno 13 požadavků za 24 s
   při nulové aktivitě uživatele. Po opravě 0 / 15 s v klidu.
2. **OPRAVENO** (tentýž commit): read-only routy utrácely sdílený rozpočet
   60 ZÁPISŮ/min a panel ho vyčerpal sám — v prohlížeči pak reálně spadlo
   „Příliš mnoho požadavků, zpomalte" a přestaly by procházet i skutečné
   zápisy uživatele včetně uložení článku.
3. **NEOPRAVENO, k rozhodnutí:** na tomhle webu je aktivní plugin **Classic
   Editor** s vynuceným klasickým editorem, takže
   `enqueue_block_editor_assets` nikdy neproběhne a **SEO panel ani AI
   toolbar tam vůbec nejsou**. Navíc staré články jsou `core/freeform`
   (jeden classic blok), kde se rich-text toolbar nenabídne ani v blokovém
   editoru. RankMath Content AI umí Block i Classic editor + Elementor a
   Divi; naše řešení zatím jen blokový editor. Pokud to má fungovat i tady,
   je potřeba classic varianta (metabox + TinyMCE tlačítko) — v centrani-app
   už hotový vzor je: `views/partials/seo_panel.php` + `public/js/seo-panel.js`.
4. **NEOPRAVENO, vedlejší nález mimo tuhle práci:** modul
   `SecurityOptimization::remove_version_query_arg()` strhává `?ver=` ze
   VŠECH skriptů a stylů pluginu. Prohlížeč si je pak cachuje natrvalo a po
   aktualizaci pluginu dostane uživatel starý JS — přesně na tohle jsem při
   testu narazil (měřil jsem starou verzi panelu). Stojí za samostatné
   rozhodnutí, protože to sabotuje každý budoucí update assetů.

### 17.5 F5 — RankBot ✅ *(hotovo lokálně 16.9.2026)*
- [x] `Mcp/Tools/SeoTools.php` — 4 MCP nástroje nad SEO mostem:
      `ai-assistant/seo-score` (post_id nebo raw content), `seo-topic-research`
      (seed keyword), `seo-schema` (post_id), `seo-link-suggestions` (post_id).
      Všechny read-only, permission `edit_posts`, vstupy se skládají ze
      skutečného postu přes `SeoManager`/`SeoScoreEditor` meta klíče.
- [x] Bespoke `execute_callback`, NE `RestEndpointTool` wrapper — ten je
      podle vlastní dokumentace vyhrazený pro `wp/v2`/`wc/v3` routy, nikdy
      `uxstudio/v1`. Stejný vzor jako `SiteInfoTools`.
- [x] Zaregistrováno v `McpAbilitiesRegistry::register_tools()`.
- [x] Ověřeno: všechny 4 schopnosti se registrují přes reálný
      `McpBootstrap` (testováno s `mcp_enabled` zapnutým JEN v paměti přes
      filtr, bez zápisu do DB) a všechny 4 reálně proběhly proti skutečnému
      postu — schema vrátila Article JSON-LD, score 45/fail, link suggestions
      prázdné (web bez `portfolio_key`), chybová cesta bez post_id čistě.
- [x] Samostatný chat mód `seo_advisor` se NEDĚLAL: jakmile je MCP zapnuté,
      existující InternalChat i externí klienti (Claude Desktop, CA bridge)
      tyhle nástroje vidí automaticky — bespoke mód by byl duplicitní vrstva
      navíc. Rozhodnutí zaznamenáno, ne tiše vypuštěno z plánu.

## 18. Menu Icons & Item Status (2026-09-15)

Nový modul `includes/Modules/MenuIcons/` — ikona (SVG/Lucide/Media Library) +
visible/hidden/disabled stav na jednotlivých položkách nav menu, vedle a
nezávisle na login-based `MenuVisibility`. Postmeta-only, žádná nová DB
tabulka. Admin UI je vanilla PHP+JS na `nav-menus.php` (stejný vzor jako
`MenuVisibility`), ne React SPA — modul má jen generický `settings_schema()`
(velikost ikony).

Kostra HOTOVÁ A OVĚŘENÁ LOKÁLNĚ 2026-09-15 (necommitnuto do stavu "aktivní
modul", jen do repa): `Module.php` (fieldset na položce menu, uložení,
`wp_get_nav_menu_items`/`wp_nav_menu_objects`/`nav_menu_link_attributes`/
`nav_menu_css_class`/`nav_menu_item_title` filtry), `SvgSanitizer.php`
(přísný DOMDocument allowlist — otestováno na script tag/onload/foreign
`<image javascript:>`, všechny zamítnuty; PHP CLI test proti `php -l`
i běhový test proveden a smazán, kód sám žádný test soubor neobsahuje),
`IconLibrary.php` (35 kurátorovaných Lucide ikon, čte lokální SVG z
`assets/lucide/`, nikdy síť), `bin/sync-menu-icons.mjs` + `npm run
icons:sync` (kopíruje z `lucide-static`, spuštěno, 35/35 synced — POZOR
past: `lucide-static` v aktuální verzi přejmenoval `home`→`house` a
`help-circle`→`circle-help`, allowlist na to má reálné, ne intuitivní
názvy). Ikona `sparkles` doplněna do `src/app/moduleIcons.tsx`.

- [ ] Modul zapnout v `Modules` registru a projít reálně v adminu na
      `pobyty` (uložit ikonu/status na položce, ověřit frontend render).
- [ ] REST endpoint pro živé hledání Lucide ikon / širší sadu, pokud 35
      kurátorovaných nestačí (dnes jen `<select>` ze statického seznamu).
- [ ] Rozhodnout, jestli `hidden` stav sloučit s `MenuVisibility`
      (`_uxstudio_menu_item_visible`) do jednoho zdroje pravdy, nebo nechat
      oba nezávislé (dnešní stav — nižší riziko, ale uživatel má dvě místa
      pro "skrýt položku").
- [ ] `npm run build` (frontend se změnil jen v `moduleIcons.tsx` — mapování
      ikony pro SPA, ne funkční kód modulu).
- [ ] Bonus nápady z návrhu (neimplementováno): icon-only mód na mobilu bez
      textu, barva/velikost ikony per-položka, bulk přiřazení ikon, import/
      export konfigurace menu.

## Auto-provisioning Cloudflare Turnstile klíčů (Security Optimization)

Cíl: admin nemusí ručně zakládat Turnstile widget v Cloudflare dashboardu a
kopírovat site_key/secret_key do nastavení - `captcha_key_source` (schema
pole v `Module.php`) přepíná mezi `manual` (dnešní chování), `ca`
(automaticky přes Centrální aplikaci, sdílený fleet-wide widget) a
`self_api` (automaticky přes vlastní Cloudflare API token, vlastní widget).
Ověřování Turnstile tokenu při loginu zůstává vždy přímo tento web →
Cloudflare - CA/vlastní token se volá jen jednou při uložení nastavení
(provisioning), ne při každém přihlášení, aby výpadek CA/Cloudflare API
nezamkl přihlašování. Plný návrh a bezpečnostní úvaha: plán session
17.9.2026, `TurnstileCaProvisioner.php` (HMAC přes stejný hub<->node kanál
jako `ServiceRequests\CentralClient`, cíl `?page=turnstile_provision` na
CA) a `TurnstileSelfProvisioner.php` (přímo Cloudflare API, vlastní token
scoped jen na `Account.Turnstile:Edit`). Zároveň přepnut default
`captcha_placement` z `inline` na `gate` (nové instalace mají rovnou
standalone "verify you're not a robot" stránku před wp-login/wp-admin).

HOTOVO A OVĚŘENO `php -l` 17.9.2026 (commit `4369438`, necommitnuto NA
GITHUB): schema pole `captcha_key_source`/`captcha_cf_account_id`/
`captcha_cf_api_token`, `Module::maybe_provision_captcha_keys()` (hák v
`save_settings()`, volá se jen když chybí site_key nebo se změnila
`home_url()`), oba provisionery. CA strana: broker `turnstile_provision`
(`controllers/TurnstileProvisionController.php`,
`core/CloudflareTurnstileClient.php`) - viz `PLAN.md` v `centrani-app`.

- [ ] Vyplnit reálný Cloudflare API token (`Account.Turnstile:Edit`) v CA
      Nastavení → Fleet Turnstile, aby šlo `ca` režim vůbec vyzkoušet.
- [ ] End-to-end test v prohlížeči: `captcha_key_source=ca` na webu s
      Content Sync párováním → uložit → ověřit, že se `captcha_site_key`
      vyplnil sám a `has_captcha_secret_key=true`; totéž pro `self_api` s
      vlastním tokenem.
- [ ] Ověřit, že `/wp-admin/` v anonymním okně po provisioningu skutečně
      ukáže reálný Turnstile widget na `?action=uxstudio_verify`.
- [ ] Rozhodnout, jestli `captcha_cf_widget_id`/registered-domain (dnes
      plain `get_option()`, mimo settings schema, proto se nezobrazí v
      generickém SPA rendereru) nepotřebují vlastní admin-viditelný stav
      (např. "widget vytvořen, doména zaregistrována dne...").
- [ ] Po ověření zvážit push na GitHub (repo je veřejné).

---

## 19. Passkey (WebAuthn) na wp-login — modul `passkeys` (2026-09-22)

Protějšek sekce 24 v `centrani-app/PLAN.md`. **Nejdřív se dělá CA, tenhle modul
až po ní** — důvod není v pořadí práce, ale v tom, že většinu užitku CA pokryje
sama a tenhle modul řeší jiné publikum.

### Koho to je pro, a koho ne

Passkey je vázaný na doménu. 97 klientských webů = 97 různých registrovatelných
domén = 97 samostatných registrací na každém tvém zařízení. **Pro správce sítě je
to tedy nepoužitelné** a nemá to ani smysl stavět — `ContentSync\SsoRedeemer` už
dnes dělá `wp_set_auth_cookie()` na základě tokenu z CA, takže passkey v CA
pokryje vstup do všech 97 wp-adminů zadarmo.

Tenhle modul je proto **výhradně pro koncové klienty, kteří se na svůj vlastní
web hlásí sami** a do CA přístup nemají. Pro ně je to jediná dostupná ochrana
proti phishingu — a zároveň odstranění hesla, které si stejně vedou v sešitě.

### Rozhodnutí

| | |
|---|---|
| **Umístění** | **Nový samostatný modul `passkeys`**, ne přílepek k `security-optimization`. Ten už je na 21 souborech a míchá IP bany, CAPTCHu, .htaccess, CSP a upload guard — passkey je jiná doména (identita, ne obrana perimetru) a chce vlastní zapínání i vlastní tabulku. Sousedí spíš s `third-party-login`, ale ten je o delegaci identity na cizí providery, tady je opak. |
| **RP ID** | Registrovatelná doména z `home_url()`, tedy `klient.cz` i pro web na `www.klient.cz`. Počítá se jednou při první registraci a **uloží se do option** — když se web přestěhuje na jinou doménu, klíče přestanou platit a modul to musí poznat a říct to nahlas, ne tiše selhat u přihlášení. |
| **Role passkey** | Náhrada hesla. `userVerification: "required"`, stejně jako v CA. |
| **Fallback** | Standardní WP heslo zůstává. Volitelně nastavení „po zaregistrování klíče skrýt pole s heslem" — ale **jen skrýt, nikdy nezakázat**, jinak si klient zamkne vlastní web. |
| **Sdílení kódu s CA** | Jádro (`WebAuthn.php`) je čisté PHP bez závislostí na frameworku. **Zdroj pravdy je verze v CA**, sem se přenáší kopie s namespace `UxStudio\Core`. Stejné testovací vektory musí projít na obou stranách — jinak se to za půl roku rozejde a rozdíl se pozná až na produkci klienta. Precedent pro sdílené jádro už je (`SeoAiClient`, `ContentSync\HmacAuth`). |
| **Distribuce** | Modul **defaultně vypnutý**. Jde na 97 živých webů auto-updatem — zapnout ho plošně by znamenalo změnit přihlašovací obrazovku všem klientům najednou bez varování. |
| **Composer** | Nepřidává se. Plugin má `vendor/` jen pro `plugin-update-checker` a vlastní vendor namespace na 97 webech s cizími pluginy je zbytečné riziko kolize. PHP 8.1 + `openssl` stačí. |

### Datový model

Tabulka přes `DB::ensure_module_tables( 'passkeys', 1, ... )` → option
`uxstudio_dbv_passkeys`, stejně jako ostatní moduly:

```
{prefix}uxstudio_passkeys
  id            BIGINT UNSIGNED AUTO_INCREMENT PK
  user_id       BIGINT UNSIGNED NOT NULL        -- WP user ID
  credential_id VARBINARY(255) NOT NULL UNIQUE
  public_key    TEXT NOT NULL                   -- PEM (SPKI)
  alg           SMALLINT NOT NULL               -- -7 ES256, -257 RS256
  sign_count    BIGINT UNSIGNED NOT NULL DEFAULT 0
  transports    VARCHAR(64) NULL
  name          VARCHAR(64) NOT NULL
  created_at    DATETIME NOT NULL
  last_used_at  DATETIME NULL
  KEY user_id (user_id)
```

**Zásadní rozdíl proti CA: WordPress nemá `$_SESSION`.** Challenge se tedy
nedrží v session, ale v **transientu s TTL 120 s**, klíčovaném náhodným ID, které
si prohlížeč nese v krátkodobé cookie (`SameSite=Strict`, `HttpOnly`, `Secure`).
Bez té vazby by challenge byl jen globální řetězec, který může uplatnit kdokoli.
Po použití se transient maže, opakované použití = odmítnutí. Úklid po vypršených
transientech řeší WP sám.

### Kde to v UI žije (a proč ne ve SPA)

Obě obrazovky, kterých se to týká, jsou **mimo React SPA** pluginu, a to je
záměr, ne kompromis:

- **Přihlášení** — `wp-login.php`, hák `login_form` (stejné místo, kde už kreslí
  tlačítka `ThirdPartyLogin`) + `login_enqueue_scripts` pro skript a styl.
- **Registrace klíče** — klasická obrazovka profilu uživatele, háky
  `show_user_profile` / `edit_user_profile` + `personal_options_update`.
  Tam klient hledá „svoje nastavení", ne v administraci pluginu.
- **Administrace pluginu (SPA)** — jen zapnutí modulu, jeho nastavení a
  přehled „kdo z uživatelů má kolik klíčů", jako read-only výpis.

### Endpointy

REST namespace `uxstudio/v1`, čtyři routy, obě přihlašovací registrované jako
veřejné přes filtr `uxstudio_rest_public_routes` (stejný mechanismus, jakým si
`ThirdPartyLogin` pouští callback a `Analytics` hit route):

```
POST /passkeys/register/options   (auth: přihlášený uživatel, nonce)
POST /passkeys/register/verify    (auth: přihlášený uživatel, nonce)
POST /passkeys/login/options      (public, rate-limited)
POST /passkeys/login/verify       (public, rate-limited) -> wp_set_auth_cookie()
```

### Fáze

- [ ] **F1 — přenos jádra.** `includes/Core/WebAuthn.php` (kopie z CA,
      namespace `UxStudio\Core`) + `includes/Core/Crypto.php` s ECDSA/base64url
      helpery. Testovací skript v `bin/` se **stejnými vektory jako v CA**.
- [ ] **F2 — kostra modulu.** `includes/Modules/Passkeys/` — `meta.json`
      (`id: passkeys`, `group: security`, `icon: key`, `settings: true`),
      `Module.php` extends `BaseModule`, `DB::ensure_module_tables()`,
      `Store.php` (CRUD nad tabulkou). Modul defaultně vypnutý.
- [ ] **F3 — registrace.** `RestController` s oběma register routami, challenge
      v transientu + cookie, UI v profilu uživatele (seznam klíčů, přidat,
      přejmenovat, smazat). Zápis do `ActivityLog` u přidání i smazání.
- [ ] **F4 — přihlášení.** Login routy, `wp_set_auth_cookie()` po ověření,
      tlačítko a conditional UI na `wp-login.php`, feature detection.
      Zapojit `ActivityLog::log( 'passkeys', 'login', ... )` a **projít
      `AttemptsHandler`**, aby se neúspěšné pokusy počítaly do stejného limitu
      jako heslo — jinak je passkey endpoint obchvat rate limitu.
- [ ] **F5 — souhra se `security-optimization`.** Vlastní URL loginu, CAPTCHA
      gate (`?action=uxstudio_verify`), IP bany a country blocklist musí platit
      i pro passkey cestu. **Ověřit každou kombinaci ručně** — tohle je místo,
      kde se dvě nezávisle vyvinuté ochrany nejspíš potlučou.
- [ ] **F6 — i18n.** Všechny řetězce v CZ i EN od začátku (požadavek 9 z kap. 1),
      včetně chybových hlášek na `wp-login.php`.
- [ ] **F7 — test a rollout.** Testovací matice jako v CA (Windows Hello, iOS,
      Android, hardwarový klíč, správce hesel, prohlížeč bez podpory) + negativní
      testy. Pak zapnout na **jednom** vlastním webu, nechat měsíc běžet, a teprve
      pak nabídnout klientům.

### Pasti a rizika

- **Zamčení klienta z vlastního webu je tady reálné riziko**, na rozdíl od CA,
  kde si účet umíš opravit v DB. Klient s jedním zařízením, které ztratí, nemá
  koho zavolat kromě tebe. Proto heslo nikdy nezakazovat, jen skrývat, a
  v nastavení modulu mít viditelné upozornění.
- **Přestěhování domény zneplatní všechny klíče.** Uložené RP ID se musí
  porovnávat s aktuálním `home_url()` při každém načtení loginu a při
  nesouhlasu nabídnout heslo a vypsat srozumitelnou hlášku. Bez toho klient
  po migraci hostingu uvidí jen „přihlášení selhalo".
- **Staging a produkce mají různé domény**, takže klíč ze stagingu na produkci
  nefunguje. Není to chyba, ale musí to být v dokumentaci, jinak to bude hlášené
  jako chyba.
- **Kolize s jinými login pluginy.** Na klientských webech běží cizí pluginy,
  které si taky sahají na `login_form` a `authenticate`. Priorita háků a chování
  při souběhu s Limit Login Attempts a spol. se musí ověřit, ne předpokládat.
- **Duplikace jádra proti CA** je vědomý dluh. Bez testu se stejnými vektory na
  obou stranách se to rozejde. Ten test není volitelný.
- **`sign_count` bývá nula** — platí totéž co v CA, nesmí z toho být tvrdé
  odmítnutí.
- **Lokální vývoj na `127.0.0.1` nefunguje**, RP ID nesmí být IP adresa.
  Lokálně chodit na `localhost`.

### Otevřené otázky

- [ ] Nechat klienta zaregistrovat klíč samoobslužně, nebo to nechat na tobě při
      předání webu? Samoobsluha je levnější, ale „přidat klíč" je přesně to, co
      by udělal útočník s ukradenou session.
- [ ] Má se stav passkeyů (kolik uživatelů, kolik klíčů) posílat do CA jako
      součást inventáře webu, aby to bylo vidět na kartě Bezpečnost? Kanál na to
      už existuje (`SecurityApiController` inventory), byla by to jen další
      položka.
- [ ] Ponechat modul navždy opt-in, nebo ho po odzkoušení zapnout plošně na
      webech v režimu `sprava`?

---

## 20. Form Builder — modul `forms` (2026-09-22)

Vzor je **Elementor Forms** (uživatel to výslovně chce) — bez platebních bran
(vědomě mimo rozsah). Modul zatím v UX Studiu VŮBEC NEEXISTUJE (ověřeno —
`ux1-wordpress-customizer`/`ux-studio` žádný form builder nemá, jen izolovaná
jednoúčelová pole v `security-optimization`/`service-requests`/starém
`reservation-calendar`). **Sesterský projekt Destima ale form builder hotový
má** (`destima-obec/includes/Modules/Forms`, `src/app/pages/Forms.tsx`, stack
React+TS+`@dnd-kit`+react-query — identický s UX Studiem) — je to ověřený
bezpečnostní základ, na kterém stavíme, ne reference na UX úroveň. Co odtamtud
PŘEBÍRÁME beze změny: honeypot, CSV export s ochranou proti
formula-injection (`csv_safe_cell`), stažení přiloženého souboru přes
capability-gated REST routu (ne přímý URL), revize definice formuláře (stejný
vzor jako `RollbackManager`/existující `Revisions`). Co Destima NEMÁ a
Elementor ano — to je jádro rozšíření v tomhle plánu: bohatší podmíněná
logika (AND/OR, operátory, ne jen `pole = hodnota`), šířka pole ve sloupcích,
řetězené akce po odeslání (ne jen e-mail), webhook, a hlavně **nativní
Elementor widget** (Destima Elementor vůbec neřeší, UX Studio ho už jako
závislost integruje — `ElementorImport`).

### 20.1 Analýza Elementor Forms (od čeho se odpichujeme)

| Oblast | Co Elementor (Pro) umí |
|---|---|
| **Pole** | Text, Textarea, Email, URL, Tel, Number, Password, Hidden, HTML (statický blok), Select, Select2, Radio, Checkbox, skupina checkboxů, Acceptance (souhlas s odkazem na podmínky), Date, Time, Upload souboru, Rating, Name (jméno/příjmení jako jedno pole), Address (strukturovaná adresa), Step (rozdělovač na kroky), Signature (podpis myší/prstem), reCAPTCHA v2/v3/invisible |
| **Nastavení pole** | label, placeholder, povinné, šířka ve sloupcích (10-100 %, zvlášť desktop/tablet/mobil), výchozí hodnota, min/max délka nebo hodnota, vlastní CSS třída, unikátní ID |
| **Podmíněná logika** | per-pole i per-krok, víc pravidel, AND/OR mezi nimi, operátory (rovná se/nerovná se/obsahuje/je prázdné/je vyplněné/větší/menší) |
| **Vícekrokové formuláře** | pole typu Step dělí formulář na stránky, styl indikátoru postupu (kroky/progress bar/žádný), validace kroku před postupem dál |
| **Akce po odeslání (řetězitelné)** | E-mail (komu, předmět, reply-to, šablona s dynamickými tagy z polí), druhý e-mail (autoresponder odesílateli), Webhook (POST JSON na URL), Redirect, vytvoření WP příspěvku ze submission, integrace na marketing/CRM nástroje (Mailchimp, Google Sheets, Slack, Zapier...) |
| **Submissions (Elementor Pro)** | Každé odeslání uložené v DB, přehled v adminu s hledáním/filtrem, log toho, které akce proběhly a s jakým výsledkem, opětovné odeslání e-mailu, export CSV, needeleted/unread stav |
| **Anti-spam** | honeypot (skryté pole), reCAPTCHA v2/v3/invisible, Akismet |
| **Styl** | šířka obsahu, mezery mezi sloupci/řádky, pozice labelu (nad/inline/skrytý), velikost inputů, stavy focus/error/hover, tlačítko stylované zvlášť |
| **Ostatní** | dynamické tagy pro výchozí hodnoty (z URL parametru, meta, uživatele), GDPR souhlas, omezení typu/velikosti nahrávaného souboru |

**Vědomě MIMO rozsah (na žádost uživatele):** platební brány (Stripe/PayPal
pole a akce). **Vědomě ODLOŽENO na pozdější fázi** (nejsou v Elementor Pro
zdarma ani kritická pro MVP): nativní CRM/marketing integrace (Mailchimp
apod.) — pokrývá je obecný Webhook, který dá napojit na Zapier/Make/n8n bez
budování N vlastních konektorů; Signature pole; vytvoření WP příspěvku ze
submission.

### 20.2 Rozhodnutí

| | |
|---|---|
| **Umístění** | Nový samostatný modul `forms`, `group: "content"`. Nesahá na `service-requests` (to je klient centrálního ticket systému, jiná doména) ani na `security-optimization` (odtud si jen **půjčuje** `CaptchaVerifier`, nevlastní ho). |
| **Renderovací kanály** | Tři, sdílejí stejná uložená data formuláře: (1) shortcode `[uxstudio_form id="1"]` (vzor `NoticeBoard::render_shortcode` — scoped inline styly), (2) Gutenberg blok (stejný render přes `render_callback`, blok je jen UI nad shortcode atributy), (3) **nativní Elementor widget** (fáze F3, viz 20.7). Frontend markup a validace jsou stejné pro všechny tři — liší se jen obalový kontejner. |
| **Datový zdroj pravdy** | `uxstudio/v1/forms` REST, ukládání do vlastních tabulek (ne CPT+postmeta — formuláře nejsou obsah, jsou konfigurace+data, stejně jako u Destimy). |
| **Podmíněná logika** | Upgrade proti Destimě: pravidlo = `{ field, operator, value }`, pole `conditions: Rule[]`, `logic: 'all' | 'any'` (AND/OR). Vyhodnocuje se **na klientu** (pro UX — okamžité show/hide) i **znovu na serveru** při submitu (globální bezpečnostní baseline — nikdy nevěřit jen klientské validaci; skryté/neaktivní pole se ze submitu ignorují, i kdyby je útočník poslal ručně). |
| **Stránkování formuláře (multi-step)** | Uživatelský požadavek, proto přesunuto do **F1** (ne F2, jak byl původní návrh). Pole typu `step` jako u Destimy (`step` index na každém poli), navíc `progress_style: 'steps' \| 'bar' \| 'none'`. Krokovou validaci dělá klient pro UX (Další/Zpět, blokace postupu při chybě), server validuje VŽDY všechna aktivní pole najednou při finálním submitu (ne per-krok round-trip — jeden formulář = jeden REST zápis, meziukládání rozpracovaného kroku není v MVP). |
| **Struktura formuláře (řádky/sloupce)** | Uživatelský požadavek — editovatelný layout, ne jen lineární seznam polí. Model shodný s Elementorem: každé pole má `width` v % (100/75/66/50/33/25), **pole s `width < 100` se v canvasu i na frontendu vizuálně řadí vedle sebe do řádku** (CSS `flex-wrap`, žádná ruční správa "řádků" jako samostatných entit — přesně tak to dělá Elementor a je to jednodušší na údržbu než vnořený rows/columns strom). Šířka se nastavuje zvlášť pro desktop/tablet/mobil (`width`, `width_tablet`, `width_mobile`) — na mobilu se typicky vynutí 100 % bez ohledu na desktop nastavení. Vizuální editor v builderu (20.5) ukazuje živě, jak se pole zalamují. |
| **Popisky polí (label vs. placeholder)** | Uživatelský požadavek — přepínatelné, ne napevno dané. `label_display` na úrovni **celého formuláře** (`settings_json`, tab Vzhled): `'visible'` (výchozí — label nad/vedle polem, placeholder jen jako doplňkový příklad) nebo `'placeholder_only'` (label se vizuálně skryje, placeholder nese text). Navíc **per-pole override** `label_display: 'inherit' \| 'visible' \| 'placeholder_only'` pro výjimky (typicky pole v jednom úzkém řádku vedle sebe, kde viditelné labely nedávají prostorově smysl). Přístupnost: `'placeholder_only'` label jen **vizuálně** skryje (`aria-hidden` na viditelném textu se nepoužívá — místo toho label zůstává v DOM jako `sr-only`/`aria-label` na inputu), placeholder sám nikdy nenahrazuje label u čteček obrazovky ani u polí bez zadané hodnoty po opuštění focusu — je to known WCAG anti-pattern (placeholder zmizí při psaní), řešení kopíruje běžnou praxi Elementoru/formulářových frameworků, ne vlastní vynález. |
| **Archiv odeslaných formulářů** | Uživatelský požadavek — musí jít **dohledat**, ne jen procházet poslední stránku. Řeší 20.6 (plnotextové hledání + trvalé uchování + jednotná obrazovka napříč všemi formuláři). |
| **E-mailové šablony** | Uživatelský požadavek — hotové, hezké HTML šablony pro e-mailovou akci, ne holý textový/HTML box jako u `GoogleReviewRequest`. Řeší 20.9 (nová, plugin dnes žádnou sdílenou HTML šablonu pro e-maily nemá — je to první modul, který to zavádí). |
| **Dashboard widget** | Uživatelský požadavek. Vlastní `DashboardWidget.php` po vzoru `BotThrottle\DashboardWidget` (`wp_add_dashboard_widget`, statická třída `register()/add()/render()`, server-rendered inline HTML, deep-link do SPA) — **ne** přes modul `DashboardWidgets` (ten jen spravuje/skrývá nativní wp-admin widgety a má vlastní samostatný widget s úkoly/poznámkami/PageSpeed; není to registr, do kterého by se ostatní moduly hlásily). Detaily v 20.10. |
| **Akce po odeslání** | Řetěz akcí uložený jako `actions: Action[]` v `settings_json`, vykonávaný synchronně v pořadí, chyba jedné akce nezastaví další (log má per-akci status). MVP: `email`, `webhook`, `redirect`. Fáze F4: `create_post`. |
| **E-mail akce** | Neposílá se přímo přes `wp_mail()` napřímo — jde přes existující `SmtpEmail` modul (pokud je zapnutý, jinak fallback na `wp_mail`) a zapisuje se do `EmailLog` (stejná viditelnost doručení/resend jako u ostatních modulů, žádná nová e-mailová cesta navíc). Šablona předmětu/těla podporuje `{pole_key}` tagy z odpovědi. |
| **Webhook akce** | Obecná POST JSON na URL zadanou v nastavení formuláře. Payload se podepisuje HMAC (stejný vzor jako `ContentSync\HmacAuth` — hlavička `X-UxStudio-Signature`), aby si příjemce (Zapier/Make/n8n/vlastní endpoint) mohl ověřit původ. Tajný klíč per formulář, generovaný, nikdy v kódu (soulad s bezpečnostní baseline). |
| **Anti-spam** | Honeypot **vždy zapnutý** (skryté pole, zero-config). Volitelně captcha — **žádná nová implementace**, modul jen zavolá `SecurityOptimization\CaptchaVerifier` (Turnstile/reCAPTCHA už je ve pluginu hotové a nakonfigurované). Rate-limit na submit routě přes `BotThrottle\Guard::exceeded()` (stejný sdílený guard jako Analytics/BotThrottle — burst limit, žádná třetí duplicitní logika). |
| **Nahrávání souborů** | Pole `file`: allowlist přípon/MIME, limit velikosti (nastavitelný, default 10 MB), uložení **mimo webroot** (`wp-content/uploads/uxstudio-forms-private/`) s deny-all `.htaccess` po vzoru globální bezpečnostní baseline pro citlivá data — stahování jen přes capability-gated REST routu s nonce (přesný vzor `download_file` v Destimě, ale navíc kontrola, že přihlášený uživatel smí VIDĚT konkrétní formulář). |
| **CSV export** | Přebírá se Destimin `csv_safe_cell()` vzor 1:1 (ochrana proti formula injection přidáním `'` před buňku začínající na `=+-@`) — je to bezpečnostně nenulová věc, ne kosmetika, přepisovat znovu je zbytečné riziko regrese. |
| **AI generování formuláře** | Fáze F3. Volitelné tlačítko „Vygenerovat AI" v builderu — text popisu → návrh polí. Nejde přes novou AI cestu, ale přes existující `AiAssistant`/`SeoAiClient` sdílené jádro (stejný vzor jako Destima `ai_generate`, ale bez duplikace klienta). |
| **GDPR / retence** | Nastavení formuláře: `retention_days` (volitelné auto-mazání starých odpovědí cronem), pole typu `acceptance` s povinným odkazem na zásady zpracování. |
| **Moderní pole (multiselect, animovaný checkbox/switch, upload)** | Uživatelský požadavek — nekreslit si vlastní checkbox/multiselect ručně, postavit to na ověřené knihovně. Zdůvodnění výběru a rozsah v **20.4b**. |
| **Composer/závislosti** | Frontend: `@dnd-kit/core` + `@dnd-kit/sortable` + `@dnd-kit/utilities` (nové npm závislosti, ale ověřený pattern — stejné verze jako v Destimě, žádné nativní HTML5 D&D kvůli mobilu/dotyku) + `react-aria-components` (nová, zdůvodnění 20.4b). Backend: žádné nové Composer balíčky (stejné zdůvodnění jako u `passkeys` v §19 — `vendor/` je jen pro plugin-update-checker). |

### 20.3 Datový model

```
{prefix}uxstudio_forms
  id             BIGINT UNSIGNED AUTO_INCREMENT PK
  title          VARCHAR(190) NOT NULL
  description    TEXT NULL
  fields_json    LONGTEXT NOT NULL        -- pole definic (viz 20.4)
  settings_json  LONGTEXT NOT NULL        -- akce, styl, anti-spam, retence, webhook secret
  status         VARCHAR(20) NOT NULL DEFAULT 'active'   -- active|draft|archived
  created_by     BIGINT UNSIGNED NULL
  created_at     DATETIME NOT NULL
  updated_at     DATETIME NOT NULL
  KEY status (status)

{prefix}uxstudio_form_submissions
  id             BIGINT UNSIGNED AUTO_INCREMENT PK
  form_id        BIGINT UNSIGNED NOT NULL
  form_title     VARCHAR(190) NOT NULL    -- snapshot názvu formuláře v době odeslání
  values_json    LONGTEXT NOT NULL        -- { field_key: hodnota }
  fields_snapshot_json LONGTEXT NOT NULL  -- snapshot { key: {label, type} } v době odeslání
  meta_json      LONGTEXT NULL            -- ip_hash, UA, referrer, UTM, page_url
  search_text    MEDIUMTEXT NOT NULL      -- plaintext konkatenace hodnot, pro FULLTEXT hledání
  status         VARCHAR(20) NOT NULL DEFAULT 'unread'    -- unread|read|spam|trash
  created_at     DATETIME NOT NULL
  KEY form_id (form_id), KEY status (status), KEY created_at (created_at),
  FULLTEXT KEY search_text (search_text)

{prefix}uxstudio_form_submission_files
  id             BIGINT UNSIGNED AUTO_INCREMENT PK
  submission_id  BIGINT UNSIGNED NOT NULL
  field_key      VARCHAR(190) NOT NULL
  original_name  VARCHAR(255) NOT NULL
  stored_path    VARCHAR(500) NOT NULL    -- mimo webroot, viz 20.2
  mime           VARCHAR(100) NOT NULL
  size           INT UNSIGNED NOT NULL
  KEY submission_id (submission_id)

{prefix}uxstudio_form_action_log
  id             BIGINT UNSIGNED AUTO_INCREMENT PK
  submission_id  BIGINT UNSIGNED NOT NULL
  action_type    VARCHAR(30) NOT NULL     -- email|webhook|redirect|create_post
  status         VARCHAR(10) NOT NULL     -- ok|fail
  detail         TEXT NULL                -- HTTP status, chybová hláška, message-id
  created_at     DATETIME NOT NULL
  KEY submission_id (submission_id)
```

Registrace přes `DB::ensure_module_tables( 'forms', 1, ... )` jako všechny
ostatní moduly (option `uxstudio_dbv_forms`).

**Proč `fields_snapshot_json` a `form_title` duplikují data z `uxstudio_forms`:**
archiv musí zůstat čitelný, i když se formulář později upraví (přejmenuje
pole, smaže volbu ze selectu) nebo úplně smaže. Bez snapshotu by stará
odpověď ukazovala buď dnešní (nesedící) popisky polí, nebo prázdné hodnoty
po smazání formuláře. Se snapshotem je `uxstudio_forms` jen "aktuální
definice pro nové odpovědi", archiv žije nezávisle na ní — smazání
formuláře proto **maže jen definici, ne odpovědi** (musí to být explicitní
druhá akce s vlastním potvrzením, ne kaskáda).

**Proč `search_text` + `FULLTEXT`:** dohledatelnost byl explicitní
požadavek. `LIKE '%...%'` přes `values_json` by fungovalo, ale bez indexu
lineárně prohledává celou tabulku a nejde v něm hledat "obsahuje slovo A i B
v libovolném pořadí" rozumně rychle. `search_text` je při zápisu vyplněný
plain-textový výtah hodnot všech textových polí (bez HTML, bez hesel/citlivých
typů), nad kterým jede `MATCH() AGAINST()` — škáluje na tisíce odpovědí bez
zvláštní infrastruktury (Elasticsearch/Meilisearch by tu byl zjevný
over-engineering).

### 20.4 Katalog polí (MVP proti Elementoru, bez plateb)

| Typ | Poznámka |
|---|---|
| `text`, `textarea`, `email`, `url`, `tel`, `number`, `password`, `hidden` | základ, 1:1 s Elementorem |
| `select`, `radio`, `checkbox`, `checkbox_group` | volby (`options[]`) |
| `multiselect` | uživatelský požadavek — dropdown s vícenásobným výběrem a "chips" pro zvolené hodnoty, ne skupina checkboxů. Postaveno na `react-aria-components` (20.4b). |
| `acceptance` | checkbox se souhlasem + odkaz (GDPR) |
| `date`, `time` | nativní HTML5 input, žádný vlastní datepicker v MVP |
| `rating` | škála 2-10 (převzato z Destimy) |
| `address` | strukturované podpole (ulice/město/PSČ/země) jako jedno pole |
| `name` | křestní+příjmení jako jedno pole (Elementor vzor) |
| `file` | viz 20.2 zabezpečení uploadu; vícenásobný výběr souborů + drag&drop zóna s náhledy (20.4b), ne holý `<input type=file>` |
| `html` | statický obsahový blok (nadpis/odstavec mezi pole) |
| `step` | rozdělovač kroku, ne skutečné vstupní pole |
| `captcha` | vizuální placeholder napojený na `CaptchaVerifier`, ne samostatná implementace |
| `signature` | **F4**, mimo MVP (nízká priorita, žádný jasný interní use-case zatím) |

Každé pole navíc (proti Destimě): `width`/`width_tablet`/`width_mobile`
(20.4a), `conditions`/`logic` (20.2), `css_class`, `default_value`
(vč. tokenů `{today}`, `{query.utm_source}` — dynamické tagy z URL),
`label_display` (`'inherit' | 'visible' | 'placeholder_only'` — **nastavitelné
u každého pole samostatně**, ne jen jako globální přepínač s výjimkami;
`'inherit'` je výchozí a řídí se formulářovým nastavením z 20.2, ale
kterékoliv pole ho může přebít vlastní hodnotou nezávisle na ostatních).

### 20.4a Struktura formuláře (řádky a sloupce)

Uživatelský požadavek: "musí jít upravovat struktura, tedy sloupce atd."
Data model je popsaný v 20.2 (řádek `Struktura formuláře`) — pole mají
procentuální šířku a řadí se vedle sebe jako flex-wrap, žádný samostatný
"row" objekt navíc. Co z toho plyne pro builder a frontend:

- **Pořadí v poli `fields[]` = pořadí vykreslení.** Řádek vznikne přirozeně
  tak, že N po sobě jdoucích polí má součet šířek ≤ 100 %; jakmile by další
  pole řádek přetáhlo přes 100 %, zalomí se samo (CSS, žádná ruční logika).
  Přesun pole mezi "řádky" je tedy jen změna pořadí (drag) nebo změna šířky
  — nemusí se řešit zvlášť.
- **Editace šířky přímo v canvasu**, ne jen v postranním panelu — úchyt na
  pravém okraji pole (drag-to-resize, přichytávání na 25/33/50/66/75/100 %),
  doplněný číselným vstupem v pravém panelu pro přesnost.
- **Přepínač breakpointu** (Desktop/Tablet/Mobil) nad canvasem mění, které z
  `width`/`width_tablet`/`width_mobile` se právě edituje — canvas se
  vizuálně zúží, aby bylo vidět skutečné zalamování na dané šířce (obdoba
  responzivního náhledu v Elementoru).
- **Pole `html`** (statický blok) i **`step`** (předěl kroku) mají vždy
  `width: 100` bez výjimky — nedávalo by smysl je zalamovat vedle jiných polí.

### 20.4b Moderní stavební prvky (react-aria-components)

Uživatelský požadavek: "mělo by to umět i multiselect a další moderní pole
třeba animované checkboxy, upload souboru atd." — a rovnou zadání "ideálně
nějaká moderní knihovna", ne ruční implementace.

**Volba: [`react-aria-components`](https://react-spectrum.adobe.com/react-aria/)
(Adobe).** Zdůvodnění proti alternativám:

| Knihovna | Proč ne / proč ano |
|---|---|
| **Radix UI** | Výborné primitivy pro Checkbox/Switch/Select, ale **nemá nativní multi-select listbox** (`Select` je jen jednovýběrový) ani upload komponentu — musel by se řešit druhou knihovnou navíc. |
| `react-select` | Multiselect umí, ale je to starší knihovna s vlastním (těžším) stylovacím modelem a slabší accessibilitou než moderní *aria*-first knihovny; vizuálně to i s override CSS "cítit" jako cizí prvek vedle vlastního design systému. |
| **`react-aria-components`** | **Jedna knihovna pokrývá všechno požadované**: `<ListBox selectionMode="multiple">`/`<Select>`/`<ComboBox>` pro multiselect, `<Checkbox>`/`<CheckboxGroup>`/`<Switch>` pro animované volby, **`<FileTrigger>`+`<DropZone>`** přímo pro upload s drag&drop. Plně **unstyled** (žádné vlastní CSS k přepisování — stylujeme čistě přes `--uxs-*` tokeny z 5. Design systém), nejvyšší úroveň přístupnosti na trhu (WAI-ARIA Authoring Practices, klávesová navigace, screen reader), aktivně udržovaná (týdenní vydání), TS-first. |

Co konkrétně nahrazuje/rozšiřuje:
- **`multiselect`** (20.4) → `react-aria-components` `<ComboBox>`/`<ListBox selectionMode="multiple">` s "chips" pro vybrané hodnoty, klávesová navigace našeptávačem.
- **`checkbox`/`checkbox_group`** → `<Checkbox>`/`<CheckboxGroup>` s animovaným stavem (fajfka se kreslí/mizí přes CSS transition na `--uxs-motion-*` tokeny, ne skokem jako u nativního `<input>`), vizuálně shodné s `ToggleSwitch` komponentou, kterou plugin už má.
- **`file`** → `<FileTrigger>` (tlačítko "Vybrat soubor(y)") + `<DropZone>` (přetažení myší), náhledy vybraných souborů (ikona podle MIME nebo thumbnail u obrázků) a odebrání před odesláním — vše na klientu, server pořád validuje MIME/velikost/allowlist nezávisle (20.8, klientská validace je jen UX).
- Vzniklé obalové komponenty (`MultiSelectField`, `AnimatedCheckbox`, `FileUploadField`) se zapíší do **sdílené knihovny komponent** (3.3/5. Design systém) — ne jen lokálně v modulu `forms` — protože stejné potřeby (multiselect, checkbox, upload) se dřív nebo později objeví i v jiných modulech a duplikovat vlastní implementaci by bylo přesně to, čemu má sdílená knihovna předcházet.
- Zbylá "obyčejná" pole (text/select-jednovýběrový/radio/date/…) zůstávají na nativních HTML prvcích stylovaných design tokeny — `react-aria-components` se nasazuje cíleně tam, kde nativní `<input>`/`<select>` UX limit skutečně naráží (multi-výběr, drag&drop upload, animovaný switch), ne plošně všude, aby bundle zůstal malý (3.3 zdůvodňuje code-splitting per modul stejnou logikou).

### 20.5 Builder UI (React SPA, `src/modules/forms`)

- **Levý panel** — paleta polí podle kategorie (Základní/Volby/Pokročilé/Layout).
- **Střed (canvas)** — seznam polí, přetahování přes `@dnd-kit` (stejný vzor jako
  Destima `Forms.tsx`: `DndContext` + `SortableContext` +
  `useSortable`, úchyt jen na `GripVertical` ikoně, ne na celém řádku).
  Vizuální oddělovače kroků, živé zalamování polí do řádků podle šířky
  (20.4a) a přepínač breakpointu Desktop/Tablet/Mobil.
- **Pravý panel** — nastavení vybraného pole (taby Obecné/Validace/Podmínky/Šířka).
- **Horní taby formuláře** — Pole / Akce po odeslání / Vzhled / Odpovědi
  (submissions inbox) / Nastavení (anti-spam, retence).
- Sdílené komponenty pluginu se znovu použijí (`DataTable`, `Modal`,
  `Tabs`, `ToggleSwitch`, `Confirm`, `Toast` — žádná nová UI knihovna
  kromě dnd-kit).

### 20.6 Archiv odeslaných formulářů

Uživatelský požadavek: "musí existovat archiv odeslaných formulářů z
administrace, to musí jít dohledat" — proto vlastní obrazovka **Archiv**
(horní tab vedle Pole/Akce/Vzhled/Nastavení, viz 20.5), ne jen prostý výpis
poslední várky:

- **Napříč všemi formuláři i jednotlivě.** Výchozí pohled je "Archiv"
  (agregovaně, se sloupcem "Formulář"), z detailu formuláře jde otevřít
  filtrovaně jen na něj — jedna implementace `DataTable`, dva vstupní body.
- **Stránkování na serveru**, ne "načti všechno a filtruj v prohlížeči" —
  `GET uxstudio/v1/forms/submissions?page=&per_page=&form_id=&status=&q=&from=&to=`,
  odpověď `{ data, meta: { total, page, per_page } }` (jednotný tvar dle 3.2).
  Bez toho by archiv po pár tisících odpovědí zpomalil celou SPA stránku.
- **Plnotextové hledání** (`q` parametr) přes `search_text`/`FULLTEXT` (20.3)
  — najde odpověď podle jména, e-mailu, textu ve zprávě atd., napříč všemi
  poli i formuláři najednou. To je jádro "musí jít dohledat", ne jen
  filtr podle data.
- **Trvalé uchování jako výchozí stav.** Žádné tiché mazání — `retention_days`
  z 20.2 je vypnuté (`null`), dokud ho admin sám nezapne u konkrétního
  formuláře. Archiv je právně/provozně důkazní materiál (kdo co kdy odeslal),
  ne cache.
- Sloupce tabulky se generují z `fields_snapshot_json` dané odpovědi (20.3) —
  **ne** z aktuální definice formuláře, takže staré odpovědi zůstanou čitelné
  i po úpravě formuláře.
- Stavy nepřečteno/přečteno/spam/koš, detail v `Modal` vč. logu akcí
  (`form_action_log` — kdy e-mail prošel/webhook selhal, tlačítko "Odeslat
  znovu"), export CSV (20.2, respektuje aktivní filtr/hledání, ne jen
  aktuální stránku), stažení přiložených souborů přes gated routu.

### 20.7 Elementor integrace (nativní widget, fáze F3)

`ElementorImport` modul už dokazuje, že je Elementor v UX Studiu first-class
závislost. Nový soubor (registrovaný stejným hookem `elementor/widgets/register`,
jaký `ElementorImport` už používá) přidá widget **„UX Form"** do panelu
Elementoru:

- Widget nemá vlastní pole — má select „Vyber formulář" (načte seznam z
  `uxstudio/v1/forms`) + zdědí stylové kontroly Elementoru (typografie,
  barvy, mezery pro label/input/button, stavy hover/focus/error) tak, jak to
  dělá originál — díky tomu je vzhled formuláře v Elementoru plně WYSIWYG,
  stejně jako u vzoru, ze kterého vycházíme.
- V editoru Elementoru renderuje živý náhled přes stejnou REST routu jako
  shortcode (žádná druhá implementace renderu).
- Widget se registruje, jen když je Elementor aktivní — bez něj modul `forms`
  funguje normálně přes shortcode/Gutenberg.

### 20.8 Bezpečnost (mapování na globální baseline)

- Server-side validace VŽDY, klientská je jen UX (viz 20.2 podmíněná logika).
- Parametrizované dotazy (`$wpdb->prepare`), žádné ruční skládání SQL.
- Upload: allowlist MIME+přípona, limit velikosti, uložení mimo webroot,
  stahování jen capability-gated.
- Rate-limit přes `BotThrottle\Guard` na veřejné submit routě — brání DoS
  zaplavením i spamu, ne jen honeypot.
- Webhook secret a případné API klíče (budoucí integrace) jen v DB (options),
  nikdy v kódu/gitu.
- CSV export: ochrana proti formula injection (převzato z Destimy, 20.2).
- Veřejná submit routa je bez WP nonce (nepřihlášený návštěvník) — ochranou
  je honeypot + captcha + rate-limit, ne nonce; admin REST routy mají nonce
  jako všude v pluginu (3.2).

### 20.9 E-mailové šablony (HTML)

Uživatelský požadavek: "předpřipravené hezké HTML šablony pro e-maily."
Plugin dnes **nemá žádnou sdílenou HTML šablonu pro e-maily** — `GoogleReviewRequest`
i ostatní moduly posílají holý HTML string z textového pole nastavení. Forms
zavádí první verzi téhle vrstvy (může se v budoucnu vytáhnout do `Core/`, až
o ni požádá druhý modul — teď by to byla předčasná abstrakce):

- **`EmailTemplateRenderer`** — sada 3-4 vestavěných šablon (`minimal`,
  `card`, `branded`) jako statické HTML se zavřenými inline styly (e-mailoví
  klienti CSS soubory ani `<style>` bloky spolehlivě nepodporují — musí to
  být `style="..."` na každém elementu, stejně jako to dělají Elementor/Mailchimp).
  Šablona má sloty: hlavička (logo ze `custom_logo` webu + barva z nastavení
  formuláře nebo plugin brand barvy), nadpis, tělo (text/HTML z akce),
  **`{{submission_table}}`** — automaticky vyrenderovaná tabulka
  label→hodnota z odeslaných polí (podle `fields_snapshot_json`, ne surových
  klíčů), CTA tlačítko (volitelné, pro autoresponder např. "Přejít na web"),
  patička (název webu, volitelný odkaz na zásady zpracování).
- **Merge tagy** v předmětu i vlastním textu: `{pole_key}`, `{form_title}`,
  `{submission_date}`, `{submission_table}` — stejná syntaxe jako u Destimy
  (`{today}` apod.), aby si uživatel nemusel pamatovat dvě různé notace napříč
  pluginem.
- **Multipart e-mail** (`text/html` + `text/plain` alternativa generovaná
  automaticky stripováním HTML) — bez toho e-mail často skončí ve spamu nebo
  je nečitelný v klientech bez HTML, což je tichá chyba, kterou nikdo neuvidí
  dokud si nestěžuje příjemce.
- **Živý náhled v builderu** (tab Akce → e-mailová akce → "Náhled") —
  vyrenderuje aktuální šablonu s ukázkovými daty přímo v SPA (`iframe`
  se `srcDoc`, bez odeslání skutečného e-mailu).
- Dvě šablony, dva adresáti: notifikace pro provozovatele (`branded`,
  obsahuje celou `submission_table`) a autoresponder pro odesílatele
  (`minimal`/`card`, jen poděkování + volitelně kopie jím vyplněných údajů) —
  volitelné jako druhá `email` akce v řetězu (20.2).
- Odchozí e-mail (subjekt i vyrenderované tělo) se loguje do `EmailLog` beze
  změny — je to poslední krok stejné existující cesty, ne nová.

### 20.10 Dashboard widget

Uživatelský požadavek — widget na nativním wp-admin dashboardu (`/wp-admin/index.php`),
po vzoru `BotThrottle\DashboardWidget` (18.1 v kódu, viz nález v 20.2):
statická třída `Forms\DashboardWidget` s `register()` (hook `wp_dashboard_setup`),
`add()` (`wp_add_dashboard_widget`, jen pro `manage_options`) a `render()`
(server-renderované inline HTML, žádný React — widget žije mimo SPA stejně
jako u BotThrottle).

Obsah widgetu:
- Počet nepřečtených odpovědí celkem (velké číslo, barevný akcent jako u
  BotThrottle) + rozpad podle formuláře (top 5 podle objemu za posledních
  7 dní).
- Posledních 5 odpovědí (jméno/e-mail pokud pole existuje, formulář, čas
  "před X hodinami") s odkazem na detail.
- Deep-link "Zobrazit archiv" → `admin_url( 'admin.php?page=ux-studio#/module?id=forms&tab=archive&status=unread' )`
  — přesně vzor BotThrottle (`#/module?id=bot-throttle`).

**Synergie s `DashboardWidgets` modulem zdarma:** ten modul periodicky
snapshotuje VŠECHNY registrované wp-admin dashboard widgety
(`cache_dashboard_widgets`) a umožňuje je adminovi skrýt. Nový widget
formulářů se do toho seznamu propíše automaticky, bez jakékoli extra
integrace — je to jen další položka `wp_dashboard_setup`, přesně jako dnes
`uxstudio_bot_throttle_widget`.

### 20.11 Fáze

- [x] **F1 — MVP se všemi uživatelskými požadavky rovnou zabudovanými**
      (ne odloženými do F2/F3, jak byl původní návrh — přepracováno na
      žádost uživatele): tabulky vč. `fields_snapshot_json`/`search_text`
      (20.3), REST CRUD formulářů se **stránkováním a plnotextovým hledáním
      archivu** (20.6), pole z 20.4 kromě `signature` vč. **`multiselect`**
      a **`file` s drag&drop** postavených na `react-aria-components` (20.4b),
      **animovaný checkbox/switch** (20.4b) místo nativních prvků,
      **struktura polí do sloupců** (`width`/breakpointy, 20.4a) v builderu,
      **přepínatelné popisky vs. placeholdery** per formulář i per pole
      (`label_display`, 20.2) vč. a11y `sr-only` fallbacku, **vícekrokové
      formuláře** (`step`, progress indikátor) na frontendu i v builderu,
      shortcode render, honeypot, e-mailová akce **s výběrem z hotových HTML
      šablon** (20.9, přes SmtpEmail/EmailLog), **Archiv** jako
      plnohodnotná obrazovka (ne jen "seznam + detail bez CSV") vč. CSV
      exportu (20.2 `csv_safe_cell`), **dashboard widget** (20.10).
- [x] **F2 — Elementor-úroveň UX** *(hotovo lokálně 2026-09-22)*: podmíněná
      logika rozšířena o `greater`/`less` operátory (klient i server -
      `Fields::is_active()`), řetězené akce doplněny o `webhook` (obecný
      POST JSON, HMAC podpis `X-UxStudio-Signature` stejným vzorem jako
      `ContentSync\HmacAuth`, tajný klíč per formulář generovaný a uložený
      jen v `settings_json`) a `redirect` (URL vrácená v REST odpovědi,
      klientský runtime přesměruje místo zobrazení úspěchu), captcha
      doopravdy vynucena přes `CaptchaVerifier::verify_token()` navíc
      podmíněná i vypínačem `captcha_enabled` v Security Optimization (dřív
      to bralo v potaz jen nakonfigurovaný klíč), rate-limit
      `BotThrottle\Guard::exceeded()` na `/forms/submit` (bylo už od F1),
      Gutenberg blok `uxstudio/form` (`GutenbergBlock.php`, render_callback
      = shortcode render, editor bez vlastního build kroku, náhled přes
      jádrový `ServerSideRender`/`/wp/v2/block-renderer`, výběr formuláře
      přes novou `edit_posts`-gated routu `/forms/options`), druhá
      e-mailová šablona pro autoresponder — beze změny datového modelu,
      jde jen o druhou `email` akci v řetězu (to bylo možné už od F1).
- [x] **F3 — Distribuce a polish** *(hotovo lokálně 2026-09-22)*: nativní
      Elementor widget (`ElementorWidget.php`, hook `elementor/widgets/register`,
      select formuláře + stylové kontroly label/input/tlačítko vč. stavů
      hover/focus/error, render deleguje na `Module::render_shortcode()` -
      žádná druhá implementace renderu, 20.7); AI generování formuláře
      (`ContentGenerator::generate_form_fields()` ve sdíleném AI jádru,
      `Module::generate_ai_fields()` sanitizuje výstup přes `Fields::sanitize_fields()`
      se seedem existujících klíčů, tlačítko „Generate with AI" v `FieldsTab`);
      log akcí v archivu s „Odeslat znovu" - ověřeno, že už bylo funkční od F2
      (`Actions::resend()` → `Actions::run()` skutečně přeposílá a zapisuje
      do `form_action_log`, beze změny); revize definice formuláře
      (`Revisions.php`, nová tabulka `uxstudio_form_revisions` v2 schématu,
      snapshot při každém uložení title/fields/settings, obnova snapshotuje
      aktuální stav jako novou revizi první - nikdy nevratná akce, tab
      „Revisions" v builderu); vlastní editor HTML e-mailové šablony
      (`template: 'custom'`, `EmailTemplateRenderer::sanitize_custom_html()` -
      allowlist rozšiřuje `wp_kses_post()` o `style` atribut a
      html/head/body/meta/title shell, ale bez `<script>`/`<iframe>`, textarea
      v `ActionsTab` nahrazuje vestavěný wrapper místo doplnění).
- [x] **F4 — volitelné rozšíření** *(hotovo lokálně 2026-09-23)*: pole
      `signature` (canvas podpis myší/prstem v `PublicRenderer` runtime JS,
      uloženo jako PNG stejnou soukromou cestou jako `file` —
      `FileStorage::store_binary()` znovu ověřuje bajty přes `getimagesize()`,
      limit 2 MB, `Fields::FILE_LIKE_TYPES` sjednocuje `file`/`signature` napříč
      CSV exportem, e-mailovou šablonou a archivem); akce `create_post`
      (`Actions::run_create_post()` — vytvoří WP příspěvek zvoleného CPT/stavu
      z `title_template`/`content_template` s merge tagy, každá hodnota navíc
      jako `_uxsf_{key}` postmeta, autor: nastavený/tvůrce formuláře/první
      administrátor; editor akce v `ActionsTab` s výběrem post typu přes novou
      routu `/forms/post-types`); `retention_days` (nastavení formuláře v
      `SettingsTab`, výchozí `null` = archiv trvalý dle 20.6, `Retention`
      třída registruje denní WP-Cron `uxstudio_forms_retention_cron`, maže jen
      formuláře, které to samy zapnuly, přes `Submissions::delete_older_than()`).
      Napojení na CA jako cíl akce zůstává neimplementované (viz Otevřené
      otázky níže) — mimo rozsah této volitelné fáze, řeší se webhookem.
      Mimochodem opravena i nekompletní F3 AI-generace: `Module::generate_ai_fields()`
      volal `ContentGenerator::generate_form_fields()`, která v repu chyběla
      (byla jen rozpracovaná v pracovním stromu) — doplněna a commitnuta spolu
      s F4.

### Otevřené otázky

- [ ] Má `forms` směřovat i k `service-requests`/CA jako volitelný cíl akce
      (vedle e-mailu a webhooku), nebo je webhook jako univerzální únik
      dostačující a specifickou CA integraci řešit až na vyžádání?
- [ ] Stahovat `@dnd-kit` jako novou závislost pluginu, nebo je (vzhledem k
      tomu, že Destima ho už používá se stejným stackem) prostě zkopírovat
      ověřenou verzi z `destima-obec/package.json`?

### 20.12 Šablony formulářů, vícekrokový formulář a editor e-mailu (0.7.2, 2026-10-26)

Požadavek: u „Název nového formuláře" galerie hotových šablon (vzor: knihovna shadcnspace, jen rozvržení, ne texty) a
přestavba obrazovky „Akce po odeslání → E-mailové oznámení" na dva sloupce s živým náhledem a WYSIWYG zprávou.

**Šablony** (`includes/Modules/Forms/Templates.php`, REST `GET forms/templates`, `POST forms/from-template`):
- Šablona je PHP definice (anglické zdrojové texty přes `__()`, česky v `.po`). Vytvoření jde přes `Module::create_form()`,
  tedy stejnou sanitizací jako běžné uložení (`Fields::sanitize_fields()`, `sanitize_settings()`); formulář vznikne jako
  koncept. Souhlas se zpracováním osobních údajů (`consent`) dostane odkaz na stránku zásad ochrany soukromí webu.
- Devět šablon: Kontaktní formulář (1 krok), Poptávka služby (2), Rezervace termínu (2), Registrace na akci (1), Žádost o
  zaměstnání (3, nahrání životopisu), Objednávka (3, karty, číslované sekce), Přihlášení k newsletteru (jeden řádek),
  Zpětná vazba a hodnocení (1, hodnocení 1-5, e-mail a souhlas jen na podmínku), Reklamace (2, nahrání fotek). Kategorie:
  Kontakt, Poptávka, Rezervace, Událost, Kariéra, Obchod, Zpětná vazba.
- Každá šablona se sběrem osobních údajů (pole e-mail/telefon) má povinný souhlas GDPR; oznámení správci vždy, potvrzení
  odesílateli (`to: {email}`) tam, kde se e-mail vyplňuje vždy. Zpětná vazba potvrzení nemá (e-mail je volitelný).
- Galerie: karta s živým náhledem z vlastních definic polí (`TemplateGallery.tsx`, žádné obrázky), počet polí, odznak
  „Vícekrokový: N", filtr podle kategorie, „Prázdný formulář". Název z pole „Název nového formuláře", jinak název šablony.

**Veřejný formulář** (`PublicRenderer`):
- [x] Rozvržení přes 12sloupcovou mřížku: šířky desktop / tablet (do 1024 px) / mobil (do 782 px) se opravdu použijí (dřív
      se mobilní šířka ignorovala a dva sloupce po 50 % se kvůli mezeře zalamovaly pod sebe).
- [x] Vícekrokový: postup `steps` (proužky), `numbers` (číslované kroky s názvy, odškrtnuté hotové), `bar` (pruh s textem
      „Krok X z Y"); legenda kroku je zaměřitelný nadpis (`tabindex=-1`), po Další/Zpět se na něj přesune fokus;
      validace kroku před Další s chybou u pole (`aria-invalid`, `aria-describedby`) a souhrnem v `role=alert`; Enter ve
      vícekrokovém formuláři postoupí o krok místo odeslání napůl vyplněného formuláře; chyba ze serveru přepne na krok
      s chybným polem; po odeslání panel s poděkováním a „Odeslat další odpověď". Bez JavaScriptu jsou vidět všechny kroky.
- [x] Nastavení vzhledu (záložka Vzhled): rámeček `layout` = plain | card (záhlaví s názvem a popisem, patička s tlačítky) |
      inline (e-mail a tlačítko v jednom řádku), `columns` 1/2, `numbered_sections`, `submit_text`; předvolby „Karta se
      záhlavím a patičkou", „Dva sloupce", „Číslované sekce". Nová pole: `choice_style` (list/cards/inline) u radio a
      skupiny zaškrtávátek, `step_description` u zalomení kroku.

**E-mailové oznámení** (`EmailActionEditor.tsx`, `WpEditor.tsx`, `EmailTemplateRenderer`):
- [x] Dva sloupce: vlevo nastavení, vpravo živý náhled (lepkavý, Počítač/Mobil, zpoždění 600 ms). Náhled vykresluje
      `EmailTemplateRenderer::build_html()`, tedy tentýž kód jako skutečné odeslání, v `iframe` se `srcdoc` a `sandbox=""`.
      Odpověď nese i varování kontrastu a vyřešené výchozí barvy.
- [x] Zpráva je editor WordPressu (`wp_enqueue_editor()` jen na obrazovce UX Studia a jen se zapnutými Formuláři,
      `wp.editor.initialize`, TinyMCE + záložka Kód, „Přidat mediální soubor"). Slučovací značky z rozbalovací nabídky
      (`{form_title}`, `{submission_date}`, `{submission_table}`, klíče polí) u zprávy i předmětu.
- [x] Záhlaví per akce: logo z Knihovny médií (bez něj logo webu), pozice vlevo/střed/vpravo, šířka 40-400 px, barva
      záhlaví a odkazů (`#rgb`/`#rrggbb` ověřeno na serveru), varování při kontrastu pod 4,5:1; barva textu v záhlaví se
      volí automaticky. Stejná komponenta platí i pro potvrzení odesílateli (je to druhá e-mailová akce).
- [x] Sanitizace zprávy `sanitize_message()`: výslovný seznam značek (text, nadpisy, seznamy, odkazy, obrázky), schémata
      jen http/https/mailto/tel, `style` jen z bezpečného seznamu vlastností, celé bloky script/style/iframe/form/object
      pryč i s obsahem. Po doplnění značek `finalize_html()`: relativní adresy obrázků a odkazů na web se zabsolutní,
      `data:`/`javascript:` zmizí, odkazy dostanou barvu. Hodnoty polí se do těla HTML vkládají escapované.
- [x] E-mailově bezpečné značkování: tabulky s `width`, `bgcolor`, `align`, `cellpadding`, tlačítko jako buňka tabulky,
      obrázky s `width`/`border`, žádný flex/grid/position; `<style>` jen v podmíněném bloku pro Outlook.
- [ ] Ruční zkouška skutečného doručení šablon do Outlooku a Gmailu (náhled v prohlížeči to nenahradí).
- [ ] Rozhodnout, jestli má zpětná vazba dostávat potvrzení i s volitelným e-mailem (teď ne, aby neodcházela chybná akce).

Testy: `tests/forms-templates.php`, `tests/forms-email-renderer.php` (oba přes `wp eval-file`).

### 20.13 Po odeslání a měření konverzí (0.7.3, 2026-10-26)

Požadavek: po úspěšném odeslání má návštěvník vidět poděkování, které vypadá dobře (vzor: vlastní formulář na
autoservisvydra.cz - formulář se nahradí zeleným kruhem s kreslenou fajfkou), a majitel webu má umět započítat konverzi
do Google Analytics, Google Ads, Meta Pixelu a Skliku bez zásahu do kódu.

**Nastavení** (záložka „Akce po odeslání", `settings.after_submit` a `settings.tracking`, třída `AfterSubmit.php`):
- [x] Karta „Po odeslání" nahoře: režim `replace` (skrýt formulář a zobrazit poděkování, výchozí) / `message` (zpráva nad
  formulářem, formulář zůstane a vymaže se) / `redirect` (stránka webu z výběru, nebo adresa).
- [x] Obsah poděkování: nadpis (výchozí „Děkujeme"), zpráva ve WordPress editoru (`WpEditor`, tučné, odkazy, obrázek z
  Knihovny médií), slučovací značky `{name}`, `{email}`, `{form_title}`; ikona „Animovaná fajfka" / „Bez ikony", barva
  akcentu (prázdná = zelená `#1a7f37`; formulář sám barvu nemá), přepínače „Odeslat další odpověď" (výchozí vypnuto) a
  „Posunout stránku na poděkování" (výchozí zapnuto).
- [x] Živý náhled vedle nastavení (dva sloupce jako e-mail), světlé a tmavé pozadí, „Přehrát animaci znovu". Náhled
  renderuje stejný PHP kód jako veřejný formulář (`POST forms/{id}/preview-after-submit`, sandboxovaný iframe bez skriptů).
- [x] Migrace bez zásahu: starý `success_text` se čte dál (stává se zprávou, nadpis je výchozí), `success_text` se nadále
  ukládá i jako prostý text zprávy. Textové pole „Zpráva o úspěchu" zmizelo ze záložky Vzhled.
- [x] Jedno přesměrování na formulář: akce `redirect` už v seznamu akcí není. Starý formulář s akcí `redirect` se při čtení
  převede na režim `redirect` s adresou (cizí web se povolí automaticky, dřív to fungovalo) a akce se z řetězu odstraní.
  Na adresu mimo web se jinak nepřesměruje, dokud to editor výslovně nepovolí („Povolit adresu na jiném webu").
- [x] Šablony z 0.7.2 mají vlastní nadpis poděkování (Rezervace přijata, Objednávka odeslána, Jste přihlášeni, ...).

**Bezpečnost poděkování**
- [x] Zpráva se čistí explicitním seznamem povolených značek (`AfterSubmit::sanitize_message()`, vzor
  `EmailTemplateRenderer::sanitize_message()` upravený pro web): odkazy `http`, `https`, `mailto`, `tel`, obrázky jen
  `http(s)`, žádný script, iframe, form, svg ani atribut `on*`, CSS jen z krátkého seznamu vlastností.
- [x] Slučovací značky nahrazuje server (`AfterSubmit::merge()`), hodnoty se escapují (`esc_html`), neznámé značky zmizí a
  hodnota, která vypadá jako značka, se nerozbalí podruhé. Prohlížeč nikdy nestaví HTML z hodnoty pole; do REST odpovědi
  jde hotové `html`, nadpis jde jako prostý text a klient ho vkládá přes `textContent`.

**Veřejný formulář** (`PublicRenderer`, sdílené CSS v `AfterSubmit::done_css()`)
- [x] Box `role="status"` + `aria-live="polite"`, fokus na nadpis (`preventScroll`), vlastní plynulé posunutí jen když je box
  mimo obraz (`prefers-reduced-motion` = skok), animace: box 0,4 s (posun 8 px), kruh 0,6 s se zpožděním 0,1 s
  (`stroke-dasharray` 151), fajfka 0,4 s se zpožděním 0,6 s (40). `prefers-reduced-motion` animace vypíná a nechává
  nakreslené. Funguje ve vícekrokovém formuláři, v shortcodu, bloku i widgetu Elementoru (všechny volají `render()`).
- [x] Druhé odeslání, dokud běží první, se ignoruje; při přesměrování se formulář zamkne.

**Měření konverzí** (karta „Měření konverzí", `settings.tracking`)
- [x] Při každém započteném odeslání (ne při chybě validace, ne u honeypot spamu) se vyvolá `CustomEvent`
  `uxstudio:form-submitted` na formuláři (bublá, `detail = { formId, formTitle, submissionId }`) a do `window.dataLayer` se
  vloží `{ event: 'uxstudio_form_submit', form_id, form_title, submission_id }` (přepínač, výchozí zapnuto). Hodnoty polí
  ani osobní údaje se neposílají nikdy. Jednou na odeslání (dedupe podle `submissionId`).
- [x] Předvolby, každá s vlastním přepínačem a poli, spustí se jen když je sledovač už na stránce (funkce `gtag`, `fbq`,
  `rc.conversionHit`); žádný cizí skript se nenačítá, takže lišta souhlasu zůstává pánem nad tím, co se načte:
  GA4 `gtag('event', název, { form_id, form_title })` (výchozí `generate_lead`); Google Ads
  `gtag('event','conversion',{ send_to:'AW-…/štítek', value, currency })`; Meta Pixel `fbq('track', 'Lead')` nebo vlastní
  událost přes `trackCustom`; Sklik `rc.conversionHit({ id, value, consent })` podle oficiální nápovědy Seznamu
  (napoveda.sklik.cz, kód konverze; od 8/2024 je povinný parametr `consent`, v nastavení se volí 1 nebo 0).
- [x] Validace na serveru: Ads `^AW-\d+/[\w-]+$`, názvy událostí `^[A-Za-z][A-Za-z0-9_]{0,39}$` (Meta vlastní 50 znaků,
  standardní jen ze seznamu), Sklik ID číslo, měna 3 písmena, hodnota nezáporná.
- [x] Modul Pixel & Tag Manager: karta ukazuje nastavená ID, jeho `gtag` a `fbq` se používají tak, jak jsou; je-li jeho
  Google tag `AW-…`, nabídne se jako začátek pole „ID a štítek konverze".
- [x] Při přesměrování se měření spustí PŘED odchodem a `gtag` dostane `transport_type: 'beacon'`; odchod je o 200 ms
  opožděn.
- [x] „Vlastní skript po odeslání": pole s CodeMirrorem (`wp.codeEditor`, jinak textarea). **Zápis smí jen uživatel s
  `unfiltered_html`, na multisite jen super admin** (`AfterSubmit::user_can_edit_script()`), vynuceno na serveru při
  vytvoření, uložení i obnově revize (`Module::guard_settings()`); ostatní vidí pole jen ke čtení a uložená hodnota se jim
  nikdy nepřepíše, ani když pošlou jinou. Skript se vypíše jako inline `<script>`, který jen zaregistruje funkci
  `window.uxstudioFormHooks[formId] = function ( detail ) { … }` (žádné `eval` ani `new Function`, takže CSP bez
  `unsafe-eval` stačí); `</script` a `<!--` se v kódu zneškodní. Volá se v `try/catch` až po zobrazení poděkování a
  `detail` je zmrazený. **CSP:** inline skript potřebuje `script-src 'unsafe-inline'` (výchozí politiky našeho CSP modulu
  ho obsahují, stejně jako runtime formuláře samotný); nonce ani hash pomocník modul CSP nemá, takže při přísné politice
  bez `unsafe-inline` se skript zablokuje a v UI je to napsané.

**Testy:** `tests/forms-after-submit.php` (WP): čištění zprávy, escapování značek, migrace `success_text` a akce
`redirect`, cíl přesměrování, oprávnění k uložení skriptu, escapování skriptu, validace nastavení měření, šablony.
Prohlížeč (Playwright, lokální pobyty): jedno `dataLayer` push, stub `gtag`/`fbq`/`rc` dostanou volání, hák proběhl, rozbitý
skript poděkování nerozbije, vícekrokový formulář, přesměrování, režim zprávy, reduced motion.

**Otevřené:** nonce/hash pro inline skript v CSP modulu; zda se mají hodnoty polí volitelně posílat do `dataLayer`
(zatím záměrně ne); Sklik: souhlas se bere jako pevná hodnota z nastavení, ne ze stavu lišty cookies.

### 20.14 Smazání nepoužívaných výchozích šablon jen podle původu (0.7.3, 2026-10-26)

Nastavení Security `remove_unused_default_themes` (commit 70c8d3a) maže jen šablony Twenty*, které přinesl sám WordPress.
Původ každé z nich drží jedna volba `uxstudio_default_theme_origins` (`origins` slug => `initial|core|manual`, `snapshot`
všech slugů šablon, `time`); logika je ve třídě `SecurityOptimization\DefaultThemes` (čisté `classify()` a
`select_deletions()`, test `tests/default-themes-origin.php`).

- `initial`: je tam od instalace, nebo když sledování začalo (první sync bez záznamu).
- `core`: objevila se po aktualizaci jádra (`_core_updated_successfully`; fallback `upgrader_process_complete` type core).
  `update_core()` kopíruje nové bundled šablony sám, mimo theme upgrader, proto diff proti snapshotu.
- `manual`: `upgrader_process_complete` type theme + action install (ZIP, wordpress.org, WP-CLI; slug z
  `$upgrader->result['destination_name']`, hromadně z `$options['themes']`), nebo se objevila mezi dvěma snapshoty bez
  aktualizace jádra (FTP, správce souborů; zjistí denní běh).
- `upgrader_pre_install` (bez typu / core) před aktualizací jádra obnoví snapshot, takže šablona nahraná přes FTP dřív se
  nezamění za jádrovou. Hooky běží vždy, i s vypnutým nastavením; ActivityLog nezapisuje instalace šablon, proto
  jediný důkaz o ruční instalaci je vlastní záznam `manual`.
- Mazat se smí jen `initial` a `core`, nepoužité, mimo ochranná pravidla (aktivní, rodič, rodič jiné, nejnovější výchozí
  jako záloha). `manual` a nezaznamenané se nemažou nikdy. ActivityLog nese `themes` a `origins`.

### 20.15 Souhlas pro Sklik z cookie lišty a CSP přes hashe (0.7.4, 2026-10-26)

**Souhlas pro Sklik** (`AfterSubmit::sklik_consent_mode()`, `assets/js/forms-consent-detect.js`, veřejný runtime v `PublicRenderer`):
- [x] `settings.tracking.sklik.consent` je `auto` (výchozí) | `1` | `0`, `consent_fallback` 0|1 (výchozí 0 = bez souhlasu).
- [x] Detekce běží v prohlížeči ve chvíli odeslání konverze, první nástroj, který odpoví, vyhrává: Complianz
  (`cmplz_has_consent('marketing')`, jinak cookie `cmplz_marketing` allow/deny), CookieYes
  (`getCkyConsent().categories.advertisement`), Cookiebot (`Cookiebot.consent.marketing`), Google Consent Mode v2
  (přehrání příkazů `consent default/update` z `dataLayer`, poslední `ad_storage` platí), `window.uxstudioConsent.marketing`
  (budoucí vlastní modul consent). Přítomný nástroj bez souhlasu = 0; žádný známý nástroj = fallback.
- [x] Ověřená API: viz hlavička souboru `forms-consent-detect.js` (zdroje). Real Cookie Banner a Borlabs Cookie
  NEJSOU zahrnuty: jejich API se ptá na konkrétní službu (RCB `consentSync(typ, název, hostitel)`, Borlabs
  `hasConsent(serviceId)`), nikoli na kategorii marketing; výchozí ID skupiny Borlabs v dokumentaci není. Doplnit,
  až bude jasné, na co se ptát.
- [x] Migrace: formulář se zapnutým Sklikem si ponechá uloženou 1/0 jako pevnou hodnotu; vypnutý Sklik s uloženou 1
  (dřívější výchozí, ne rozhodnutí) se čte jako `auto`; uložená 0 zůstává 0; nový formulář = `auto`.
- [x] Meta Pixel a Google Ads se nemění (spouštějí se jen když je tracker na stránce, to řídí CMP).
- [x] Test: `tests/forms-consent-detect.mjs` (Node, stejný soubor jako v runtime), `tests/forms-after-submit.php`.
- [ ] Doplnit další CMP (Real Cookie Banner, Borlabs, Cookie Notice, OneTrust), jakmile bude ověřené veřejné API.

**CSP: vlastní inline skripty přes hashe** (`CspInlineScripts`, `CspPolicy::with_inline_hashes()`, `Forms\InlineScripts`):
- [x] Proč hashe a ne nonce: nonce se musí měnit s každou odpovědí, takže stránka z cache by dostala starý nonce a
  prohlížeč by skript zablokoval. Hash závisí jen na textu skriptu, ten je v každé odpovědi stejný. Nonce záměrně NEJSOU.
- [x] Hlavička se posílá před obsahem, proto modul registruje PROVIDER, který vrací přesné texty všech skriptů, které
  se mohou objevit (ne jen těch na aktuální stránce). Tisk i hash vycházejí z jedné funkce (`PublicRenderer::runtime_body()`,
  `AfterSubmit::hook_script_body()`), takže se nemohou rozejít. Text je jen s LF: HTML parser převádí CRLF/CR na LF dřív,
  než prohlížeč hash spočítá (past, na kterou jsme narazili v prohlížeči).
- [x] Hashe se přidají JEN do přísného `script-src` (bez `'unsafe-inline'`, jinak by prohlížeč `'unsafe-inline'`
  ignoroval a rozbil všechny cizí inline skripty). Vestavěné úrovně `basic`/`strict` `'unsafe-inline'` mají vždy, hashe se
  tedy uplatní u politiky zúžené filtry `uxstudio_csp_script_src` / `uxstudio_csp_directives`. `script-src-elem` se posuzuje
  zvlášť, chybí-li `script-src`, rozhoduje `default-src`. Audit (Report-Only inventura) hashe nikdy nedostane.
- [x] Pokryto: společný skript formulářů (statický) a vlastní skript po odeslání každého AKTIVNÍHO formuláře (hashe v
  autoloadované volbě `uxstudio_forms_hook_hashes`, přestavba při vytvoření, úpravě, obnovení revize a smazání formuláře,
  a při změně verze pluginu). Konfigurace formuláře už není skript, ale `<script type="application/json">`.
- [x] Strop 200 hashů (`CspInlineScripts::MAX`), při překročení záznam do error logu a varování v záložce CSP.
- [x] Záložka CSP ukazuje seznam pokrytých skriptů a upozornění, že inline skripty jiných pluginů a šablony se řeší zvlášť.
- [x] Test: `tests/csp-inline-scripts.php` (vektor `alert(1)`, registr, strop, přísný/volný script-src), `tests/forms-csp-hashes.php`
  (přestavba cache, tištěný skript = hashovaný text, CRLF). Ověřeno v prohlížeči s vynuceným přísným CSP: skript
  formuláře i vlastní skript proběhly bez porušení CSP.
- [ ] Další moduly s vlastními inline skripty (Pixel & Tag Manager, Popup Manager, Analytics beacon, Push, Snippets) zatím
  hashe nemají; vzor: `CspInlineScripts::register()` + jedna funkce pro text skriptu.
- [ ] Přepínač „přísný režim bez unsafe-inline" v nastavení CSP (dnes jen přes filtr).

**Rychlé hledání v liště administrace** (`AdminCustomiser/QuickSearch.php`, `assets/js|css/admin-customiser-quick-search.*`):
- [x] Jedno sloučené ovládání: lupa vpravo, rozbalovací pole (animace 0,22 s, respektuje `prefers-reduced-motion`),
  rozbalovací seznam jako ARIA combobox/listbox, klávesnice (šipky, Home/End, Enter, Esc, Tab), Ctrl+K / Cmd+K ve wp-admin.
- [x] Při zapnutém hledání se odebere tlačítko příkazové palety jádra (WP 7.0+, uzel `command-palette`) a Ctrl+K převezme
  naše pole (zachytávací fáze, jádro ho nevidí; ne v blokovém editoru). Poslední položka „Příkazy WordPressu..." otevře
  `wp.data.dispatch('core/commands').open()` (jen když existuje). Předvyplnit dotaz API neumí.
- [x] Barvy se berou z lišty (světlé i tmavé schéma), mobil: pole jako řádek přes celou šířku pod lištou.
- [x] Oprava: na webu (front-end) lišta vznikala až po skriptu, hledání tam nereagovalo.
- [ ] Ověřit na webu s blokovým editorem, že Ctrl+K v textu dál vkládá odkaz.

### 20.16 Rozbalovací nabídky v liště: rychlé hledání a skrytá upozornění (0.7.5, 2026-10-26)

Obě nabídky přepracované do jednoho světlého stylu (bílý panel, okraj #e5e7eb, poloměr 12 px, stín, 13 px, max výška
`min(70vh, 560px)`, animace 140 ms s ohledem na `prefers-reduced-motion`), i v tmavých schématech administrace.
- [x] Rychlé hledání (`QuickSearch/Integrations.php`, `MiscRestController`, `assets/js|css/admin-customiser-quick-search.*`):
  řádek nese `kind` (ikona), `meta` (popisek typu z `singular_name`, přípona souboru u médií), `thumb`, odkazy s `action`
  edit|view|open; odpověď má `meta.groups` (celkový počet a odkaz na seznam v administraci). Limit 5 řádků na skupinu,
  celkem 40. Název se čistí na serveru (`Integrations::clean_title()`: bez značek, emoji `<img>` nahrazeno `alt`).
  Dotaz na příspěvky teď běží přes `WP_Query` kvůli `found_posts`, s `suppress_filters` (jako dřív `get_posts`).
- [x] Past: WordPress emoji skript (twemoji) přepisuje emoji znaky v nově přidaných uzlech na `<img>`; v nabídce je to
  rozbitý obrázek. Třída `wp-exclude-emoji` musí být na každém přidaném uzlu (řádek, nadpis, název), na rodiči nestačí.
- [x] Past: `#wpadminbar ul li` dává každému `li` `z-index: 99999`, což přebije lepivé nadpisy a patičku; řádky mají
  `z-index: auto`. A `#wpadminbar *` dává prvkům vlastní řádkování (nadpis skupiny byl 46 px místo 31).
- [x] Skrytá upozornění (`HideAdminNotices`, `assets/js|css/admin-customiser-notices.*`): panel se přesouvá do `<body>`
  (pozice pod zvonkem z `getBoundingClientRect`), upozornění je karta (`.uxstudio-nc`) s typem z tříd původního
  upozornění; původní prvek zůstává v kartě živý (cizí JS a AJAX dál fungují). Křížek klikne na původní `.notice-dismiss`.
  "Skrýt vše" záměrně NENÍ: dosavadní funkce nic neodmítala a hromadné odmítnutí by trvale skrylo i důležitá hlášení.
- [x] Past: jádro WordPressu při DOM ready přesouvá všechna `.notice` pod nadpis stránky, a to i z karet; karta, která o
  upozornění přišla, si ho při dalším průchodu bere zpět (dřív se kopie na stránce smazala jako duplicita a karta zanikla).
- [x] Úspěšná hlášení (`.notice-success`, `#message`) a `#uxstudio-php-version-notice` zůstávají na stránce (dosavadní pravidlo).
- [x] Testy: všechny stávající zelené; vizuálně ověřeno na lokálním pobyty (Fresh, tmavé schéma, 600 px).
- [ ] Vydání 0.7.5

## 21. Audit bezpečnost / funkčnost / výkon (2026-09-30)

Statický audit celého pluginu (73 modulů, ~110k ř. PHP) + měření na lokálu. Lint PHP 8.4
čistý, `tsc` 5 typových nepřesností (bez runtime dopadu), `npm audit` 2× moderate jen
v build nástrojích (do zipu nejdou). Pořadí = priorita.

### 21.1 Bezpečnost — VYSOKÁ
- [x] Login lockout neblokuje správné heslo: `AttemptsHandler::check_attempted_login` na
      `authenticate` prio 30 (po ověření hesla) vrátí WP_User a resetuje počítadlo →
      přesunout na prio 5, blokovat nezávisle na výsledku. + sjednotit `current_time('mysql')`
      vs `NOW()` (TZ posun expirace).
- [x] CaptchaGate obejde `wp-login.php?action=x` (jádro neznámou akci mění na login, gate
      čte surové `$_REQUEST['action']`) i `action=retrievepassword` → gate na
      `authenticate` když je `log` vyplněné + `lostpassword_post`; cookie vázat na hash UA.
- [x] Inline CAPTCHA (`CaptchaHandler.php:131`) se ověřuje jen u správného hesla → oracle
      na správné heslo; ověřovat před prio 20.
- [x] Klientská IP (`IpBanStore.php:420`, `AttemptsHandler.php:235`): XFF bere první
      (podvržitelnou) položku, `X-Forwarded-For: 127.0.0.1` = loopback allowlist → bypass
      všech banů; CF hlavička bez ověření REMOTE_ADDR v CF rozsazích → použít vzor
      `Analytics/ClientIp.php` všude (i BotThrottle, AiPanel IP lock).
- [x] AiPanel `rescue.php` přežije vypnutí modulu/pluginu a sám nekontroluje expiraci →
      `expires_at` v config.php + kontrola v rescue.php + úklid při disable/deactivation.
- [x] PushNotifications: veřejný subscribe uloží libovolný `endpoint` (i 127.0.0.1 /
      169.254.169.254), `Sender.php:136` posílá přes `wp_remote_post` → stored SSRF;
      https + allowlist push služeb + `wp_safe_remote_post`.
- [x] MediaReplace IDOR (`RestController.php:54-81`): `new_attachment_id` bez `delete_post`
      kontroly → autor smaže/ukradne cizí médium.
- [x] Posty chráněné heslem unikají přes `?format=markdown` (AiMarkdown `is_eligible`) a
      AI chat index (`ContentIndexer`) → vynechat `post_password !== ''`, přeindexovat.

### 21.2 Bezpečnost — STŘEDNÍ / NÍZKÁ
- [x] FileManager `/spravce-souboru/` = druhý login formulář bez CAPTCHA/lockoutu, přihlásí
      kohokoli → redirect na `wp_login_url()`; `?logout` GET bez nonce.
- [x] SvgUpload sanitizer nečistí atributy kořenového `<svg>` (`Sanitizer.php:115`) a
      prefilter `on\w+=` obejde mezera před `=` → XSS z role s SVG uploadem.
- [x] AI chat: mail bombing (nová `session_id` = nový e-mail adminovi, contact limit
      klíčovaný klientským session_id) → limit per IP hash + globální strop mailů/h.
- [x] AI chat: `message` bez limitu délky, výchozí token/request limity 0 → denial of wallet.
- [x] CSV formula injection: ExitPopup export (`page_url` z veřejného endpointu),
      ExportUsers/ExportPosts → escape jako `Forms/Csv.php`.
- [x] CodeSnippets safe-mode cookie `uxstudio_safe_mode=1` vypne snippety i anonymům →
      respektovat jen u admina.
- [x] DownloadFiles `require_login` soubor nechrání (příloha je veřejně v uploads) →
      přesunout mimo uploads nebo přejmenovat volbu na „skrýt odkaz“.
- [x] MCP SeoTools bez `edit_post` na `post_id`; MCP JWT platí pro celé REST API i s
      vypnutým MCP (`McpBootstrap.php:35`) → omezit na MCP routy.
- [x] CodeSnippets/FileManager/AiPanel/RollbackManager ignorují `DISALLOW_FILE_MODS/EDIT`.
- [x] ThirdPartyLogin: nonce nevázaná na prohlížeč (login CSRF), anonymní tvorba transientů
      bez limitu.
- [x] Drobnosti: AiPanel session nevázaná na grant, NoticeBoard re-subscribe přepíše
      kategorie, Forms autoresponder `{email}` relay, SSRF (admin-only) v HtmlToElementor
      a RAG WebCrawleru, AutoImageUpload `post_id` bez kontroly, podpis release zipu.

### 21.3 Funkčnost
- [x] ReviewAggregator `fetch()` volá neexistující `/api/reviews/fetch` + CA nezná
      `X-UxStudio-Signature` → stažení recenzí vždy selže.
- [x] Vypnutí CronControl (modulu i pluginu) nechá mu-plugin s `DISABLE_WP_CRON` +
      `.htaccess` deny → WP-Cron mrtvý natrvalo. Zavést `BaseModule::on_disable()` +
      `register_deactivation_hook` (uklidit i .htaccess bloky SecurityOptimization/ImageOptimizer).
- [x] AiAssistant `UsageLimiter` pro `user_id=0` počítá celý web → per-user limit zablokuje
      veřejný chat všem.
- [x] VulnerabilityScanner `report_to_central` má typ `checkbox` (renderer ho nezná) → `toggle`.
- [x] dbDelta schémata AiAssistant (~14 tabulek) + SecurityOptimization: `PRIMARY KEY (id), KEY…`
      na jednom řádku → „Multiple primary key defined“ při bumpu verze.
- [x] Handoff: `deactivate_plugins(ux1)` spustí legacy Deactivator (může smazat tabulky
      dřív, než se lazy zkopírují) → `deactivate_plugins(..., true)`.
- [x] Migrator: chybí data-migrace recenzí (`ux1_reviews`), instagram, bot-throttle, exit-popup.
- [x] ServiceRequests `Sync::is_due` míchá lokální čas a `time()` → backoff +2 h v létě.
- [ ] i18n: ~1998 z 3961 výskytů řetězců chybí v cs_CZ.po (Forms, AiAssistant, SecurityOpt.).
- [x] Forms: po úspěšném odeslání se neresetuje Turnstile/reCAPTCHA → druhé odeslání 422.
- [x] WIP drobnosti: widget „Disk usage“ synchronně skenuje ABSPATH; SecOpt widget ukazuje
      vypršelé bany a „in 2 hours“ u minulosti; login CSS `.wp-pwd` rozbíjí ukazatel síly
      hesla; WooCommerce ikona + odkaz `wc-orders` jen s HPOS; AiPanel CSV export testuje
      starý slug `ux1-claude-panel`.

### 21.4 Výkon (lokál: ~100 ms a 44 SQL dotazů z UX Studia na request)
- [x] Analytics beacon = druhý plný boot WP na každé zobrazení (~1,3 s CPU) → odlehčený
      endpoint (SHORTINIT/mu-plugin), rate-limit v APCu/UPSERT.
- [x] BotThrottle `record_metrics` na shutdown každého requestu přepisuje ~35 kB option
      `uxstudio_bt_load_window` (bez APCu) → jen s APCu / vzorkovat / agregát, jen frontend.
- [x] Upload Guard full-scan: worklist 2,4 MB v jedné option přepisované po 40 souborech;
      sken visí ve stavu `running` od 16. 8. bez navazující cron události → fronta v tabulce
      + watchdog, starou option smazat.
- [x] `hide_wp_version` (default true) strhává `?ver` ze VŠECH assetů + `.htaccess` dává
      `immutable` 1 rok → po updatu rok starý CSS/JS. Strhávat jen `ver == wp version`.
- [x] 39 SELECTů na neautoloadované `uxstudio_dbv_*` + nastavení modulů → jedna
      autoloadovaná option s verzemi.
- [x] SecurityOptimization `maybe_update_htaccess` na každém bootu (schéma + 2× decrypt +
      md5) → jen při uložení nastavení / admin_init.
- [x] `AttemptsHandler::cleanup_expired_attempts` DELETE na každém `init` → denní cron.
- [x] BotThrottle `usleep` 5-10 s drží PHP worker při přetížení → 429/503 + Retry-After.
- [x] AI chat widget (78 kB JS + 45 kB CSS neminifikováno) na každé stránce → minifikovat,
      lazy load po kliku.
- [x] EmailHealth posílá testovací mail synchronně na `admin_init` → cron.
- [x] Retence chybí: activity_log, popup_stats, smtp_logs, push_events, ai_markdown_log,
      content_sync_log, performance_history, grr_stats, ai_assistant_usage, chat_history.
- [x] Drobnosti: `Modules::discover()` 73× json_decode na request (cache), PUC načítán i na
      frontendu, CodeSnippets 2× `SELECT *`, PopupManager WP_Query ve footeru, SR sync cron
      každých 5 min i bez centrály, 6 osiřelých `ux1_*` cron událostí.

### 21.5 Stav po opravách (2026-09-30, větev `fix/audit-2026-09-30`)
Opraveno v 5 commitech (Core / Security Optimization / rizikové moduly / AI Assistant /
ostatní moduly), lint čistý, build OK, web 200. Nové sdílené třídy: `Core\ClientIp`,
`Core\Csv`, `Core\Retention`, `BaseModule::on_disable()` + deactivation hook.
Zbývá / k rozhodnutí:
- [x] Lockout jen podle IP (rozhodnuto 30. 9.): dřív blokoval i správné heslo podle JMÉNA,
      takže šlo zamknout admina. Přihlášení už neruší zámky jiných IP. Ověřeno skriptem.
- [ ] Podpis release: vygenerovat Ed25519 klíč, secret `UXSTUDIO_SIGNING_KEY` na GitHub,
      veřejný klíč do `GithubUpdater::RELEASE_PUBLIC_KEY` (bez klíče se neověřuje nic).
- [ ] ReviewAggregator ověřit živě proti CA (lokální CA má web jako `http://localhost/pobyty`,
      WP je `https://127.0.0.1/pobyty/` → podpis nesedí jen kvůli URL). ID profilu musí být číselné.
- [ ] Uklidit legacy `.htaccess` bloky „UX1 Image Optimizer WebP“ (uploads) a prázdný
      „UX1 Cron Control“ (kořen) - jen na webech, kde už běží ux-studio ImageOptimizer.
- [ ] Retence konverzací veřejného AI chatu (obsahují IP) - polling handoffu počítá s pořadím.
- [ ] Blog Pilot crony se po vypnutí AiAssistant neruší; ContentSync volá `new IpBanStore()`
      bez proxy režimu.
- [ ] Vizuálně ověřit reset hesla na wp-login (WIP Login.php, selektor `.wp-pwd`) a admin SPA
      proklik (Playwright MCP se v session nepřipojil).
- [ ] File Manager `?auth=` přestane fungovat, když je zapnutá CAPTCHA na loginu (důsledek
      toho, že teď jde přes `wp_authenticate`).


## 22. Přesměrování - modul `redirects` (2026-10-16)

Náhrada pluginu Redirection (wordpress.org/plugins/redirection), se kterou umí AI pracovat
nativně přes Abilities API (jádro WP 6.9+, na lokálu 7.1) → MCP. Mapování starých URL na
nové dělá AI agent sám, modul hlídá, aby výsledek odpovídal SEO pravidlům.

**SEO pravidla zabudovaná v `Repository::save()` (platí pro UI, import, AI i auto-redirect):**
- 301 výchozí (308 = trvalé se zachováním metody, 302/307 dočasné s varováním, 410 = obsah
  zrušen bez náhrady → rychlejší vyřazení z indexu než 404, vykresleno 404 šablonou + noindex);
- žádné smyčky ani přesměrování na sebe (včetně zdroje bez query, který chytá libovolnou query,
  a regexu, který chytá vlastní cíl);
- žádné řetězy: A→B + B→C se uloží jako A→C, a když přibude B→C později, starší A→B se
  přesměruje na C (`repoint_upstream`);
- cíl na tomhle webu se uloží relativně a naváže na `post_id` → po změně slugu se cíl posune sám;
- varování: cíl = homepage (Google bere jako soft 404), dočasný kód, neověřený cíl, zdroj má
  živý obsah; systémové cesty (wp-admin, wp-login.php, wp-json…) a homepage jako zdroj zakázané.

**Engine:** `init` prio 1, jen front-end GET/HEAD přes index.php. Exact = 1 indexovaný dotaz
přes sha1 hash (cesta lowercase, bez koncového lomítka, dekódovaná, query seřazená); regexy
v autoloaded optionu `uxstudio_redirects_state`. Query návštěvníka (utm, gclid) se přenáší.
301/308 dostane `Cache-Control: max-age=3600` (jinak to prohlížeč cachuje navždy).
`X-Redirect-By: UX Studio Redirects`. Modul `redirect-404-to-homepage` respektuje
`Engine::handled()` (410 se nikdy nezmění na redirect na homepage).

**AI (category `redirects`):** `list-rules`, `upsert-rules` (max 500, `dry_run` výchozí true,
per-položka status + errors/warnings), `delete-rules`, `test-url` (průchod pravidly + `live`
HTTP s reálnými kódy každého hopu), `audit` (+`fix_chains`), `list-404` (`unresolved_only`),
`list-site-urls` (inventář nového webu), `find-target` (kandidáti podle slugu/titulku, skóre).
Stejné operace v REST `uxstudio/v1/redirects/*` pro SPA.

**Další:** log 404 agregovaný po URL (bez IP, retence 90 dní, strop 20 000 řádků), automatická
301 při změně slugu/rodiče publikovaného obsahu (i podstránky), import CSV + z tabulek
Redirection, export CSV, SPA (pravidla + test URL, 404, SEO audit, import/export, nastavení).

- [x] PHP: Url, Repository, Engine, NotFoundLog, Tester, Importer, SiteIndex, Abilities,
      RestController, Module; php -l čisté; normalizace URL ověřena samostatným testem (13 případů)
- [x] SPA `src/modules/redirects/Page.tsx`, ikona `signpost`, registry
- [x] Ověřeno na lokálu 16.10. (po opravě DB): tabulky, uložení přes Repository (smyčka, self,
      wp-admin zamítnuté; řetěz zploštěn na 1 hop; upstream A→B přepojen na post), curl: 301 s utm,
      case/lomítko, query pravidlo, regex `$1`, `/$1` neutekl na cizí host, 410 + noindex,
      `X-Redirect-By`; auto-301 při změně slugu + smazání pravidla na obnovené URL; log 404;
      8 abilities (dry-run výchozí, volání bez vstupu OK); REST pro SPA; dry-run importu z
      Redirection na pobyty = 338 pravidel. Pozn.: `.htaccess` (Performance Optimization) přepisuje
      `Cache-Control` na `no-cache` pro všechny .php → 301 se v prohlížeči necachuje (neškodné).
- [x] Překlady cs_CZ (100 řetězců vč. meta.json). PAST: `wp i18n update-po` vyhodí ručně vedené
      msgid z meta.json (nejsou v kódu) - nové řetězce připisovat do .po cíleně, pak make-mo,
      make-json, merge-json.
- [x] Vizuálně ověřeno 18.10. v wp-admin (Playwright přes Node): 5 záložek česky, přidání pravidla z formuláře, světlý i tmavý režim, 0 chyb v konzoli i REST; opraven rámeček v rámečku a bílé hledání v tmavém režimu (79983a1)
- [ ] Import z Redirection na webu, kde ho používá, pak Redirection vypnout
- [ ] Zvážit: `trash` publikovaného obsahu → nabídnout 410/redirect (záměrně vynecháno,
      automatická 410 by blokovala obnovení z koše)

## 23. Upload Guard - incident 16.-17. 10. a přestavba na „jen hlásí" (2026-10-18)

**Co se stalo:** noční plný sken (17 714 souborů) označil 189 jako podezřelé a 12 z nich sám
přesunul do karantény (`uxstudio_security_auto_quarantine` = výchozí `true`). Žádný nebyl
škodlivý: Predis/Credis (Redis Cache - slovo `eval` v docblocku), 2× Akeeba, 6× UX1, 2× UX Studio
včetně `UploadGuardDetectionEngine.php`. Bez něj padal autoload v `boot()` → fatal na každém
requestu → 500 celého webu. Soubory vráceny ručně, modul Security vypnutý.

**Příčiny:** skóre podle výskytu slov v surovém textu (komentáře, řetězce), detektor bez výjimky
na sebe sama (305 bodů), sken celého wp-content včetně cizích pluginů, destruktivní akce bez
potvrzení, pád jednoho modulu shodí celý plugin.

**Nový model:**
- [x] Skener jen čte a hlásí. Automatická karanténa odstraněna úplně (i filtr). Karanténa =
      ruční akce u jednoho nálezu s potvrzením (`POST findings/{id}/quarantine`).
- [x] `UploadGuardQuarantine::quarantine_file` odmítne soubory, které web načítá (jádro, aktivní
      plugin/šablona, drop-in, vlastní kód, `get_included_files()`) - `UploadGuardScope::protection_reason`.
      Odmítne i soubor změněný od skenu (hash).
- [x] Rozsah: uploads (PHP + js/svg/html), mu-plugins, drop-iny ve wp-content, plugins, themes
      (jen PHP). Cache, upgrade, zálohy apod. se neprochází.
- [x] Natvrdo vyjmuto: `ux-studio`, `ux1-wordpress-customizer`, karanténa, složky
      `vendor`/`vendor-prefixed`/`node_modules`/`bower_components`/`dependencies` v kódu.
- [x] Pluginy z WordPress.org: MD5 proti `downloads.wordpress.org/plugin-checksums/<slug>/<ver>.json`
      (transient 12 h, negativní 24 h). Shoda = čisté bez heuristiky. Liší se = high, není v balíčku
      = medium, plus heuristika.
- [x] PHP v uploads = high, pokud obsahuje aspoň jeden spustitelný token (prázdný „Silence is
      golden" index.php je čistý).
- [x] Detektor PHP nad tokeny (`token_get_all`): komentáře a řetězce se ignorují, volání metod
      (`->exec`, `::exec`) a deklarace (`function eval`) nejsou volání. Kritické jsou jen kombinace
      v jednom příkazu: dekodér → eval/shell, `$_GET/$_POST/...` → eval/shell/include,
      blob → eval. Osamocené `exec()`/`eval()` = low, nehlásí se.
- [x] Migrace DB v3: smazány otevřené nálezy starého enginu a neschválená cache hashů
      (rozhodnutí admina - karanténa/schváleno/smazáno, schválené hashe - zůstávají).
- [x] Schválení nálezu v karanténě, který už byl ručně vrácen, nespadne na „restore failed".
- [x] Jádro: `Modules::boot_module` v `try/catch Throwable` - modul, který nenaběhne, se pro daný
      request přeskočí a v adminu se ukáže chyba. Nevypíná se natrvalo (po vrácení souboru naběhne sám).
- [x] Výkon: sestavení seznamu na lokálu 157 s → 6,8 s (kontrola podle jména, `stat()` až u
      relevantních souborů; adresáře resolvované jednou za request).
- [ ] Nasadit na web, kde incident proběhl, zapnout Security a pustit sken - zkontrolovat, že
      nálezy dávají smysl (čekáno: jednotky, ne stovky).
- [ ] Zvážit kontrolní součty jádra WP (`api.wordpress.org/core/checksums`) a šablon.

### 23.1 Databáze vzorů: php-malware-finder vs. MalwareBazaar (2026-10-18)

Wordfence CLI skončil a jeho Free signatury stejně nešly komerčně. Porovnány dvě volné cesty,
podmínka: musí běžet na obyčejném webhostingu (čisté PHP, bez exec/YARA/ClamAV).

| | php-malware-finder (PMF) | MalwareBazaar |
|---|---|---|
| Co to je | YARA pravidla na PHP webshelly (LGPLv3), repo `jvoisin/php-malware-finder`, poslední commit 05/2023 | databáze hashů vzorků (abuse.ch), API s bezplatným klíčem |
| Na hostingu | YARA nejde → převést regexy do PHP (ověřeno, jde) | jen HTTP dotaz s hashem, ale klíč nesmí na weby → přes CA |
| Záchyt na 17 vzorcích PMF* | 16 | jen přesná shoda bajtů; injektovaný/per-web generovaný PHP malware má pokaždé jiný hash |
| Falešné poplachy, lokální pobyty | **567** z 14 977 PHP (3,8 %), jádro WP **65** z 1 301 | 0 (hash) |
| Whitelist | hashe WP verzí do 2018 → na moderním WP k ničemu | - |

\* Defender zablokoval čtení 11 z 28 vzorků (c99, r57...), měřeno na 17 čitelných.

**Rozhodnutí:** PMF ne jako knihovnu, ale jeho vzory přepsané do tokenového analyzátoru
(`UploadGuardPhpAnalyzer`) - záchyt **15/17** (dřív 4/17), falešné poplachy **0** na webu
(stále jen 6 odchylek od WordPress.org) a **1** „medium" v jádru (`update.php` include ověřeného
pluginu). MalwareBazaar jen volitelně později, centrálně z CA nad hashi souborů, které nejdou
ověřit kontrolními součty - přínos pro PHP malý.

- [x] Analyzátor: sledování dat z požadavku přes proměnné (jen v rámci funkce, klíč do cizího pole
      se nepočítá, přiřazení přes jinou funkci stav ruší), volání přes proměnnou/prvek pole,
      `call_user_func($_POST..)`, nebezpečná funkce jako textový callback, `extract($_REQUEST)`,
      literály dekódující se na `eval(` (\x/base64), include obrázku/.ico/escapované cesty, kód za
      150+ mezerami, pozpátku psaná jména, `chr().chr()` řetěz, `preg_replace /e`, heslo
      `md5($_POST)==hash`, krátké tagy `<?` (s vypnutým short_open_tag by je tokenizer neviděl).
- [ ] Nezachyceno: `real/awvjtnz.php` (callback přes proměnnou vrácenou jinou proměnnou) a
      `sucuri_2014_04.php` (callback v `array_diff_ukey`, minul ho i PMF).

### 23.2 Kdy a jak rychle sken běží (2026-10-19)

**Předtím:** plný sken každou noc v pevných 03:00 UTC (všechny weby na serveru naráz), pokaždé
znovu čte a hashuje všech ~15 000 souborů, dávky po 40 souborech každých 5 s (~30 min nepřetržité
zátěže), ruční „Skenovat nyní" bez omezení.

**Pravidla teď** (konstanty v `UploadGuardScanner`, frekvence v nastavení):
- [x] Frekvence `upload_guard_schedule`: týdně (výchozí) / denně / jen ručně. Denní cron dál
      kontroluje upload frontu a integritu klíčových souborů (pár souborů), plný sken jen když je na řadě.
- [x] Noční okno 4 h podle času webu se startem 01:00 + posun z `crc32(home_url)` (0-2 h), tedy
      konec nejpozději 07:00; každý web má stejně dlouhé okno (původní 01-06 h s posunem okno
      zkracovalo až na 1 h), weby na jednom serveru se nepotkají. Mimo okno se automatický běh pozastaví
      a pokračuje další noc. Staré události (03:00 UTC) se jednou přeplánují.
- [x] Dávka: max 40 PŘEČTENÝCH souborů nebo 8 s práce; kontrola nezměněného souboru (jen `stat()`)
      se nepočítá. Pauza 30 s mezi dávkami (dřív 5 s).
- [x] Zátěž: `sys_getloadavg()` / počet jader > 1 → odložit o 5 min (kde hosting load skrývá, nic).
- [x] Nezměněné soubory (velikost + čas změny v cache, DB v4) se nečtou; každý 4. automatický
      běh a každý ruční je hloubkový (čte vše) - pojistka proti podvrženému mtime.
- [x] Ruční sken: kdykoli (i ve dne), ale max 1× za hodinu (REST 429 s počtem minut).
- [x] Zámek přes `add_option`: dvě souběžné dávky (dva požadavky WP-Cronu) nepoběží; zámek
      po pádu vyprší za 2 min. Běh delší než 14 dní se ukončí jako přerušený (hloubkový běh při
      cronu á 5 min může potřebovat víc nocí; týdenní běh se nespustí, dokud běží předchozí).
- [x] Kontrolní součty WordPress.org: cache 30 dní (data vydané verze se nemění).
- [x] UI ukazuje „čeká na noční okno / server je vytížený, pokračuje <čas>" a hlášku cooldownu.

**Kdo to řídí:** každý web sám přes svůj WP-Cron (žádný centrální orchestrátor). WP-Cron běží jen
při požadavku na web nebo ze serverového cronu (Cron Control „externí" typicky á 5 min), takže 30 s
je jen minimum. Při cronu á 5 min by hloubkový běh po 40 souborech trval přes 6 nocí.
- [x] `SLOW_CRON_GAP`: když od předchozí dávky uběhly ≥ 2 min, limit 40 přečtených souborů neplatí
      a dávku omezuje jen 8 s práce (~3 % jednoho jádra při cronu á 5 min). Simulace na lokálu
      (Windows, pomalé čtení ~87 souborů/8 s): hloubkový běh při cronu á 5 min 377 → 170 dávek
      (~14 h čistého času, tj. 3-4 noci); na Linuxovém hostingu výrazně rychleji.
- [ ] **Centrální řízení z CA (návrh, neimplementováno):** Cron Control už má režim „řízeno
      centrální aplikací" (CA volá wp-cron.php). CA zná, které weby sdílí server (např. ns318721),
      a mohla by plné skeny celé flotily řadit tak, aby na jednom serveru běžel vždy jen jeden web.
      Potřeba: v CA fronta skenů po serverech + endpoint v UX Studiu „spusť/pokračuj v dávce" s
      podpisem (`Core\Broker` HMAC), web pak vlastní noční slot nepoužije. Jednotlivý web tohle sám
      zajistit neumí - o ostatních webech na serveru neví.

Změřeno na lokálu (15 077 souborů): hloubkový běh 377 dávek, nejdelší 6,1 s; rychlý běh
16 dávek, nejdelší 0,6 s, 0 přečtených souborů (~7 min i s pauzami); automatický běh ve dne
se pozastavil do 04:08; druhý ruční do hodiny zamítnut; dávka při drženém zámku nic neudělala.

**Mez metody:** ClickFix plugin ze seco-traktory.cz (jen `wp_enqueue_script` s cizí doménou)
je podle obsahu čistý a žádná heuristika ho nenajde - to řeší návrh v `PLAN-strazce-integrity.md`
(nové soubory, které tam nikdo nedal + cizí domény na frontendu).

## 24. Správa DNS přes Cloudflare - modul `dns-manager` (2026-10-19, NÁVRH)

**Cíl:** klient upravuje DNS záznamy své domény ve wp-admin, aniž by dostal přístup do
Cloudflare. Každá změna se zapíše do logu (kdo, kdy, co z čeho na co), admin dostane e-mail
a uživatel je před uložením výslovně upozorněn, že změna má přímý dopad na web, e-maily
a další služby navázané na DNS.

### 24.1 Rozhodnutí

- **Cloudflare token NIKDY na webu klienta.** Admin WordPressu by si ho přečetl z DB a měl by
  plné API. Web volá broker v CA (stejný HMAC kanál přes `sites.api_key` jako
  `turnstile_provision` / `ServiceRequests\CentralClient`), CA drží token a volá Cloudflare.
- **Zóna se odvozuje z ověřené `site_url`**, nikdy z těla požadavku. Kompromitovaný klíč
  jednoho webu nesáhne na cizí doménu (stejný princip jako u Turnstile).
- **Token per zóna**, scope jen `Zone > DNS > Edit` + `Include > Specific zone`. Únik jednoho
  tokenu = jedna doména, ne celá flotila. Nesdílet s Turnstile tokenem (`Account.Turnstile:Edit`).
- **Zdroj pravdy pro log je CA**, kopie na webu je jen pro zobrazení klientovi. Admin
  WordPressu umí smazat řádky ve své DB, v CA ne.
- **E-mail o změně odesílá CA**, ne WordPress. Když klient rozbije MX své domény, `admin_email`
  na téže doméně už poštu nedostane, a WP mail může jít přes SMTP plugin, který si klient vypne.
  CA posílá na adresu agentury (nezávislá doména) + volitelně `admin_email` webu.
- **Validace a zámky platí v CA.** Kontroly ve WP/SPA jsou jen pro UX, obejít se dají.
- **Modul zapíná CA**, ne klient: per web příznak `dns_enabled` + napojená zóna. Bez toho
  modul ve wp-admin ukáže jen „Správa DNS není pro tento web aktivní".
- **Žádné AI abilities pro zápis.** Případně jen `dns/list-records` (čtení). Chybná AI změna
  MX = tichá ztráta pošty.

### 24.2 Co klient smí (v1)

| Typ | Smí | Pozn. |
|---|---|---|
| A, AAAA, CNAME | přidat / upravit / smazat | proxied přepínač jen u těchto tří |
| MX | přidat / upravit / smazat | zesílené varování, smazání = opsat název domény |
| TXT | přidat / upravit / smazat | SPF/DKIM/DMARC poznáno podle obsahu → zesílené varování |
| SRV, CAA | jen číst | v2 podle poptávky |
| NS, SOA | skryté | |

**Zamčené záznamy** (vidí je, ale nesmí měnit ani smazat; ikona zámku + důvod):
- apex a `www` (A/AAAA/CNAME), na kterých běží web - zamčeno automaticky při napojení zóny;
- záznamy, které přidal admin v CA jako zamčené (např. ověření Google, DKIM poštovního
  hostingu, záznamy pro monitoring);
- zámek je v CA tabulce (ne v Cloudflare `tags`, ty nejsou na všech plánech).

Klient smí přidat záznam se stejným názvem jako zamčený jen tam, kde to nekoliduje
(např. další TXT na apexu ano, druhý CNAME ne - Cloudflare to stejně odmítne).

### 24.3 Upozornění pro uživatele (UX)

1. **Trvalý banner** nahoře na stránce (varování, ne jen info): „Změny DNS se projeví okamžitě
   a mají přímý dopad na fungování webu, e-mailové schránky a další služby navázané na doménu
   (ověření Google, platební brány, SSL certifikát). Chybný záznam může znamenat nedostupný web
   nebo ztracené e-maily. Pokud si nejste jistí, kontaktujte nás."
2. **První vstup:** modální potvrzení „Rozumím rizikům" - uloží se per uživatel
   (`user_meta` s časem) a zapíše do logu jako akce `acknowledged`. Bez něj jen čtení.
3. **Před každým uložením** potvrzovací dialog s přehledem změny (před → po) a zaškrtávátkem
   „Rozumím, že tato změna ovlivní fungování webu a navázaných služeb". Tlačítko je do té doby
   neaktivní.
4. **Zesílené varování** (červené, konkrétní důsledek):
   - MX: „Změna nebo smazání MX záznamu přesměruje nebo zastaví doručování e-mailů na @domena";
   - SPF/DKIM/DMARC: „Odeslané e-maily mohou začít padat do spamu nebo být odmítnuty";
   - CNAME/A na subdoméně, která má platný certifikát nebo běžící službu: „Služba na
     sub.domena přestane odpovídat";
   - smazání MX nebo poslední A/AAAA subdomény: potvrzení opsáním názvu záznamu.
5. **Po uložení** informace o šíření: proxied záznam platí hned, neproxied až po vypršení TTL
   (uvést konkrétní TTL), lokální cache může držet starou hodnotu.

Texty projdou `design:ux-copy` a jdou do cs_CZ překladu (viz past z §22: ručně připisovat do .po).

### 24.4 Log změn

**CA (autoritativní)** - nová tabulka `dns_change_log`:

| Sloupec | Obsah |
|---|---|
| `id`, `created_at` (UTC) | |
| `site_id`, `zone_id` | |
| `batch_id` | jedno uložení v UI = jedna dávka (= jeden e-mail) |
| `wp_user_id`, `wp_user_login`, `wp_user_name`, `wp_user_email` | snímek v okamžiku změny (uživatel se může později přejmenovat nebo smazat) |
| `ip`, `user_agent` | IP celá kvůli auditu bezpečnostní změny (oprávněný zájem), retence 24 měsíců |
| `action` | `create` / `update` / `delete` / `revert` / `acknowledged` / `denied` |
| `record_id`, `record_type`, `record_name` | |
| `before_json`, `after_json` | celý záznam z Cloudflare před a po (content, ttl, proxied, priority, comment) |
| `status`, `error` | `success` / `error` / `conflict` + chybová hláška Cloudflare |

Logují se i **zamítnuté pokusy** (zamčený záznam, neplatná hodnota, rate limit) jako `denied`
- důležité, když něco nefunguje a klient tvrdí, že „nic neměnil".

**WP (kopie pro klienta)** - tabulka `{prefix}uxstudio_dns_log` se stejnými sloupci bez IP.
Záložka „Historie změn" ve SPA: filtr podle uživatele / typu / data, řádek ukazuje diff
(před → po, změněná pole zvýrazněná). Zdroj pro výpis je CA (`action=log`), lokální tabulka jen
jako záloha, když CA neodpovídá.

**Vrácení změny:** u každého úspěšného řádku tlačítko „Vrátit" (jen pro uživatele s oprávněním)
= nová operace s `action=revert`, která obnoví `before_json`. Prochází stejnými zámky,
validací, potvrzením i e-mailem. V CA navíc vrácení pro admina agentury.

**Snapshot zóny:** před první změnou dne a před každou dávkou s mazáním CA uloží
`GET /zones/{id}/dns_records/export` (BIND) do `dns_zone_snapshots`, retence 90 dní. Ruční
obnova celé zóny jen z CA.

### 24.5 E-mail o změně

- **Kdy:** po každé dávce (ne po každém záznamu). Víc dávek do 10 min od stejného uživatele
  se sloučí do jednoho e-mailu (proti zahlcení, vzor `BotThrottle\BanNotifier`).
  Zamítnuté pokusy (`denied`) jen v denním souhrnu, kromě 5+ zamítnutí za hodinu = okamžitě.
- **Komu:** e-mail agentury (nastavení CA, výchozí adresa notifikací) + volitelně `admin_email`
  webu + další adresy per web v CA. Uživateli, který změnu provedl, kopie (potvrzení).
- **Obsah:** předmět `[domena.cz] Změna DNS: 2 záznamy (MX, TXT)`; kdo (jméno, login, e-mail,
  IP), kdy (Europe/Prague), web a zóna; tabulka změn typ / název / před / po; u MX/SPF/DKIM
  zvýrazněné upozornění; odkaz na historii ve wp-admin a odkaz na vrácení v CA.
- Odesílá CA svou mailovou vrstvou (stejnou jako notifikace monitoringu), HTML šablona
  v jejím stylu.

### 24.6 Oprávnění a ochrana

- Nová capability `uxstudio_manage_dns`. Výchozí nikdo; v nastavení modulu admin webu
  vybere konkrétní uživatele (jen role administrator). Čtení: `uxstudio_view_dns`.
- REST `uxstudio/v1/dns/*`: `permission_callback` na capability + nonce (standard pluginu).
- Zápisové operace vyžadují čerstvé přihlášení (posledních 15 min) nebo znovu zadat heslo;
  s modulem `passkeys` stačí passkey. (Otevřená otázka O4.)
- Rate limit v CA: 30 zapsaných záznamů / hodinu / web, max 20 záznamů v jedné dávce.
  Ve WP navíc `BotThrottle\Guard` na REST endpointu.
- **Optimistický zámek:** web posílá `modified_on` záznamu, jak ho viděl. CA před zápisem
  záznam znovu načte; když se mezitím změnil (např. agentura v Cloudflare dashboardu), vrátí
  409 a UI nabídne obnovit. Žádné tiché přepsání.
- Validace v CA: IPv4/IPv6 formát a ne privátní rozsahy (10/8, 192.168/16, 127/8, ::1),
  CNAME ne na apex bez flatteningu, název jen v rámci zóny, žádný `*` wildcard v v1, TXT
  max 2048 znaků, TTL 1 (auto) nebo 60-86400, MX priorita 0-65535, kontrola SPF syntaxe
  (jeden `v=spf1` na název, max 10 lookupů - varování).

### 24.7 Architektura

```
wp-admin SPA (src/modules/dns-manager/Page.tsx)
  → REST uxstudio/v1/dns/{records,records/{id},log,revert,ack}
    → includes/Modules/DnsManager/CaClient.php  (HMAC, jako TurnstileCaProvisioner)
      → CA ?page=dns_manage&action={list,create,update,delete,log,revert}
        → DnsManageController.php (auth přes site_url, zámky, validace, rate limit, log, mail)
          → core/CloudflareDnsClient.php (token per zóna, dešifrovaný až tady)
            → api.cloudflare.com/client/v4/zones/{zone_id}/dns_records
```

**UX Studio** `includes/Modules/DnsManager/`: `Module.php`, `meta.json`, `CaClient.php`,
`RestController.php`, `Validator.php` (zrcadlo pravidel z CA, jen UX), `LocalLog.php`,
`DashboardWidget.php` (poslední 3 změny, vzor §20.10). SPA: záložky Záznamy / Historie změn /
Nastavení (kdo smí upravovat).

**CA**: `controllers/DnsManageController.php`, `core/CloudflareDnsClient.php`,
`core/DnsValidator.php`, `core/DnsChangeMailer.php`; tabulky `dns_zones` (site_id, zone_id,
zone_name, token šifrovaně stejně jako Turnstile token, dns_enabled, notify_emails),
`dns_record_locks`, `dns_change_log`, `dns_zone_snapshots`. V detailu webu sekce „DNS":
napojení zóny (vložit token → CA sama najde zone_id přes `GET /zones?name=` a ověří
`GET /user/tokens/verify`), zámky, historie, vrácení, obnova snapshotu.

**Cloudflare API** (ověřit na živém tokenu, u Turnstile se ukázalo, že PATCH s API tokenem
neprošel): `GET /zones/{id}/dns_records?per_page=5000`, `POST`, `PATCH /dns_records/{id}`
(záložně `PUT` s celým tělem), `DELETE`, `GET /dns_records/export`. Limit 1200 req / 5 min
na token - bohatě stačí. Výpis záznamů cache v CA 60 s, invalidace po zápisu.

### 24.8 Fáze

- [x] F1 CA: tabulky (migrace 113), `CloudflareDnsClient` (list/create/update/delete/export, PATCH
      s fallbackem na PUT), napojení zóny v detailu webu (`?page=dns_admin`) + ověření tokenu
      (`/user/tokens/verify`, `/zones?name=`, výpis záznamů). Ověřeno 19.10. proti FAKE Cloudflare
      (`tools/fake-cloudflare.php`). NEOVĚŘENO na živé zóně: PATCH vs PUT s API tokenem
- [x] F2 CA: `DnsManageController` (HMAC, zóna ze `site_url`), `DnsValidator`, zámky (auto apex +
      www + hostitel), rate limit 30/h, optimistický zámek 409, log vč. `denied`; ověřeno
      `tools/test-dns-manager.php` (150 kontrol, vše projde proti fake Cloudflare)
- [x] F3 CA: snapshot zóny (denní + před mazáním), `revert`, `DnsChangeMailer` se slučováním dávek
      (cron `flushDue`), denní souhrn a okamžité upozornění při 5+ zamítnutích, historie + vrácení
      + stažení snapshotu v detailu webu. Chybí: obnova celé zóny ze snapshotu (jen stažení BIND,
      import ručně v Cloudflare)
- [x] F4 UX Studio: modul `DnsManager` - capability `uxstudio_manage_dns`/`view_dns`, `CaClient`,
      REST, lokální log, nastavení „kdo smí upravovat", stav „není aktivní". Ověřeno WP -> CA
      end-to-end na lokále (curl s cookie + nonce, fake Cloudflare): ack, create, validace,
      zámek, 409, revert, `reauth_required` po zestárnutí přihlášení
- [x] F5 SPA: výpis (zámky, filtr typu), formulář, banner, potvrzení prvního vstupu, potvrzovací
      dialog s diffem, zesílená varování MX/SPF/DKIM/subdoména, opsání názvu při mazání MX /
      poslední A na subdoméně, info o šíření; Historie s diffem a „Vrátit". Ověřeno Playwrightem
      (Node) ve wp-admin, světlý i tmavý režim, 0 chyb v konzoli
- [x] F6 Překlady cs_CZ (89 řetězců), ikona `globe`, dashboard widget. Texty NEPROŠLY skillem
      `design:ux-copy` (psáno přímo podle §24.3) - doporučena revize
- [ ] F7 Ověření na živé testovací zóně: všechny typy proti reálnému Cloudflare, 409 při souběžné
      změně v dashboardu, e-mail po rozbití MX testovací domény (lokálně SMTP nefunguje, e-maily
      ověřeny jen zachycením transportu), PATCH vs PUT. Proti fake Cloudflare hotovo
      (zamčený záznam = denied, cizí zóna v těle ignorována, 409, revert, snapshot)
- [ ] F8 Pilot na jednom klientovi, pak nasazení CA (záloha podle pravidel live serveru)

### 24.9 Pasti a rizika

- **Zóny v cizím Cloudflare účtu** (klient má vlastní účet): klient musí vytvořit token
  a předat ho; návod s obrázky v CA. Když token revokuje, modul ukáže „spojení přerušeno".
- **Změny mimo modul** (agentura v dashboardu) se do logu nepropíšou. V2: denní porovnání
  snapshotů a zápis rozdílů jako `external_change`.
- **Cloudflare hlásí chyby jako 400 s kódem** (např. 81053 záznam existuje, 9005 neplatný
  obsah) - převést na srozumitelné české hlášky, ne surový text.
- **Proxied CNAME na cizí služby** (Mailchimp, ověřovací CNAME) musí být DNS-only, jinak
  neověří - u známých cílů (`*.mcsv.net`, `*.mandrillapp.com`, `_domainkey`) proxied vypnout
  a zamknout přepínač.
- **Hostinger přepisuje CSP** - netýká se (žádný cizí skript), ale potvrzovací dialogy
  nespoléhat na inline JS mimo SPA bundle.

### 24.10 Otevřené otázky

- O1: Jsou zóny klientů ve vašem Cloudflare účtu, nebo v jejich vlastních? (onboarding, F1)
- O2: Má informační e-mail jít i na `admin_email` webu, nebo jen agentuře? (výchozí: obojí,
      vypínatelné per web)
- O3: Smí klient upravovat i apex/www (třeba při přechodu na jiný hosting), nebo vždy jen
      přes vás? (výchozí: zamčené, odemyká admin v CA)
- O4: Vyžadovat před zápisem znovu heslo / passkey? (výchozí: ano, pokud přihlášení starší
      než 15 min)

## 25. CSP - inventura, hodnocení rizika, widget a bezpečný přechod na Enforce (2026-10-20)

Zadání uživatele: zapnutí CSP musí upozornit, že může rozbít web; výchozí cesta je
Content-Security-Policy-Report-Only s povinným reportováním; modul musí umět projít celý web
a správně povolit všechny služby; povinný widget na nástěnce ukazuje všechno, co se načítá,
a zvýrazní podezřelé zdroje. Navazuje na `PLAN-strazce-integrity.md` (incident seco-traktory:
škodlivá byla jen cizí doména ve `<script src>`), sdílí s ním hodnocení domén.

### 25.1 Nálezy v původní implementaci

- Report-Only s dosavadní politikou (`unsafe-inline`, `img-src https:`, povolené presety)
  nehlásí nic, co politika povoluje - tedy neukáže, co web načítá. Pro inventuru je potřeba
  záměrně přísná politika pro cizí původy.
- Report endpoint byl veřejný bez tokenu a bez kontroly `document-uri` (kdokoli mohl plnit
  tabulku), nefiltroval šum z rozšíření prohlížeče, `sample_ip` se ukládal syrově (komentář
  tvrdil opak), URL včetně query stringu (tokeny, e-maily).
- Chyběl `report-to` / `Reporting-Endpoints`, varování při zapnutí, kontrola, že hlavička
  vůbec dorazí (Hostinger ji přepisuje, viz paměť `hostinger-prepisuje-csp`), a brána
  před Enforce. `csp_custom_allowlist` se přidává do všech direktiv najednou.

### 25.2 Režimy (`csp_mode`)

| Režim | Hlavička | Politika | Účel |
|---|---|---|---|
| `off` | žádná | - | výchozí |
| `audit` (nový) | Report-Only | inventurní: cizí původy zakázané, inline/eval povolené | zjistit, co web načítá |
| `report-only` | Report-Only | kandidátní (presety + schválené zdroje) | test před ostrým režimem |
| `enforce` | CSP | kandidátní | blokuje |

- Inventura povoluje `unsafe-inline` / `unsafe-eval`: inline skripty a `style=""` jsou ve WP
  na každé stránce desítky, report za každý by zahltil server. Cílem jsou cizí domény
  (načtení skriptu, exfiltrace přes connect/img/frame) - ty se nahlásí vždy.
- Hlavička se neposílá v náhledech page builderů a Customizeru a nikdy ve wp-admin.
- Vzorkování `csp_sample_rate` (% zobrazení stránek s Report-Only hlavičkou, výchozí 100)
  + automatická pojistka: nad 600 reportů za hodinu se Report-Only hlavička na zbytek hodiny
  pozastaví (Enforce se nevzorkuje nikdy).
- Nouzový vypínač `define( 'UXSTUDIO_CSP_DISABLE', true );` ve wp-config.php.

### 25.3 Reporty a data

- `report-uri` + `report-to` (`Reporting-Endpoints`), URL nese token z `wp_salt()` a značku
  politiky. Bez tokenu, s cizím `document-uri` nebo ze schématu rozšíření prohlížeče se
  report zahodí (204).
- Tabulka `uxstudio_csp_violations` v5: `policy`, `script_sample`, `line_number`, `pages`
  (až 10 cest), `risk_level`, `risk_score`, `risk_reasons`, `service`, `domain_created`,
  `risk_checked_at`. URL bez query stringu, IP se neukládá.
- Retence: vyřešené starší 90 dní a všechny starší 180 dní se mažou (denní údržba).

### 25.4 Hodnocení rizika (`CspRisk`, `CspServices`, `CspRdap`)

- Vlastní doména a subdomény = vlastní; katalog známých služeb (presety + běžná CDN,
  analytika, platby, české služby) = známá služba.
- Signály: `http://`, IP místo domény, punycode, riziková TLD, překlep známé domény
  (Levenshtein), tunely / pastebiny / dynamické DNS, náhodně vypadající název, doména mladší
  30 / 180 dní (RDAP, bez klíče, cache 30 dní). Skript/připojení/rámec váží víc než
  obrázek/font.
- Úroveň: `high` (červeně, e-mail adminovi max. 1× za 6 h), `medium`, `unknown` (neznámá
  služba bez signálu), `known`, `self`.
- Reputační služby (Safe Browsing / Web Risk, URLhaus) až přes broker v CA - mimo tuto fázi.

### 25.5 Brána pro Enforce a rollback

Enforce jde zapnout, až když: (1) self-test potvrdí, že hlavička opravdu dorazí do prohlížeče,
(2) režim `report-only` běží aspoň 7 dní, (3) není otevřený nález z testovací politiky
za posledních 7 dní, (4) není otevřený nález s vysokým rizikem. Jinak jen s výslovným
„rozumím riziku" (`csp_enforce_override`, neukládá se). Server bránu vynucuje i bez UI.
Automatický návrat do `report-only` (`csp_auto_rollback`): během 72 h po zapnutí Enforce
přibudou aspoň 3 nové blokované zdroje typu skript/připojení/rámec s aspoň 3 hlášeními
→ zpět na test + e-mail.

### 25.6 Postup

- [x] F1 Inventurní politika, report-to, token, filtr šumu, soukromí, vzorkování a pojistka, varovný dialog
- [x] F2 DB v5, nové sloupce, retence
- [x] F3 Povinný widget na nástěnce (nejde skrýt ani přes Dashboard Widgets)
- [x] F4 Lokální heuristiky + RDAP, e-mail u vysokého rizika
- [x] F5 Self-test hlavičky, brána pro Enforce, automatický rollback
- [x] Ověřeno 2026-10-20 na lokálním pobyty (worktree napojený junction, po testu vráceno):
      reporty s tokenem/bez tokenu/cizí dokument/rozšíření, query string a IP se neukládají,
      kjhg.lol = vysoké riziko (RDAP: registrace 2026-09-05), dialog pro Inventuru a Enforce,
      serverová brána (`csp_gate_blocked`), Schválit všechny bezpečné (jen pro danou direktivu),
      widget na nástěnce, e-mail, automatický návrat z Enforce. Testy logiky: `php tests/csp-logic.php`.
- [ ] Ověřit na živém webu, co dorazí do prohlížeče (Hostinger přepisuje CSP - self-test to ukáže)
- [ ] F6 Aktivní sken (WP-cron stáhne homepage, sitemapu, košík) - později
- [ ] F7 Reputační broker v CA (Safe Browsing / URLhaus) - později

## 26. Průvodce migrací z cizích pluginů + hlídání překryvů + MCP (2026-10-21, NÁVRH)

Zadání: u pluginů s plnou shodou (seznam schválený 21. 10., skupina A) + Admin Customiser
umět přenést nastavení a data, ověřit, že přenos proběhl, otestovat v bezpečném prostředí
a teprve pak nabídnout deaktivaci. Průvodce se spustí po instalaci nebo ručně z menu.
Modul nejde zapnout, když běží plugin, který dělá totéž. Součástí MCP server, který umí
s UX Studiem pracovat nativně.

### 26.1 Principy

- **Zdroj se nikdy nemění.** Migrace data cizího pluginu jen čte; zapisuje jen do UX Studia.
  Rollback = obnovit snímek našich dat + znovu aktivovat plugin.
- **Nic automaticky.** Deaktivace jen po ověření a potvrzení uživatelem (stejně jako Upload Guard).
- **Idempotence.** Mapovací tabulka `uxstudio_migration_map` (zdroj → cílové ID), opakovaný
  běh nic nezdvojí. Velká data po dávkách, stav v `uxstudio_migration_run`, jde navázat.
- **Ztrátové části se říkají předem.** Plán každého zdroje vypíše „přenese se / nepřenese se".

### 26.2 Architektura (`includes/Core/Migration/`)

- `Catalog` - registr zdrojů (slug pluginu, cílový modul, typ plná/částečná).
- `Source` (rozhraní) - `detect()` (nainstalováno, aktivní, verze, počty), `plan()`,
  `migrate( batch, dry_run )`, `verify()`, `smoke_urls()`.
- `Sources/*` - jeden soubor na plugin, Redirection reuse `Redirects\Importer`, WP Extended reuse `Migrator`.
- `Snapshot` - záloha cílových options/tabulek před zápisem, rollback 30 dní.
- `Runner` - stavový automat, dávky, obnovení po timeoutu.
- `PreviewSandbox` + mu-plugin `uxstudio-preview.php` - bezpečný náhled (26.4).
- `ActivationGuard` - blokace zapnutí modulu (26.5).
- REST `uxstudio/v1/migration/{scan,plan,run,status,verify,preview,deactivate,rollback}`.

### 26.3 Průvodce (SPA `#/wizard`)

1 Vítejte → 2 Sken webu → 3 Výběr → 4 Kontrola (snímek + zkušební běh) → 5 Migrace →
6 Ověření a bezpečný náhled → 7 Deaktivace → 8 Hotovo (report do Activity Logu).
Spouští se po aktivaci pluginu (Handoff redirect, jednou) a ručně z menu „Průvodce migrací".
Animace jen CSS (krokový pruh, radar skenu, postupné karty, kreslené fajfky), lucide ikony,
respektuje `prefers-reduced-motion`.

### 26.4 Bezpečný náhled (sandbox v rámci session)

Mu-plugin (načte se před pluginy) při platném podepsaném cookie/hlavičce (HMAC na `wp_salt`,
user ID, expirace 30 min) přes `option_active_plugins` vypne pro TENTO požadavek staré pluginy
a zapne cílové moduly. Návštěvníci dál jedou na starých pluginech. Automatické smoke testy
loopbackem s tokenem: homepage 200 bez fatal erroru, klíčové stránky, wp-admin, plus testy
zdrojů (vzorek přesměrování → shodný Location/kód, testovací e-mail přes nové SMTP adminovi,
formulář komentářů zmizel, nová login URL odpovídá, snippety bez chyby…). Admin může web
v náhledu proklikat sám (lišta „Náhled bez starých pluginů").
Neověří: chování jen pro návštěvníky z cizí IP, cron běhy, e-maily skutečným příjemcům.

### 26.5 Hlídání překryvů

- Zapnutí modulu, když je aktivní plugin ze skupiny A → REST 409 `uxstudio_plugin_overlap`,
  UI nabídne průvodce s předvybraným pluginem. Admin Customiser (částečná) → varování + potvrzení.
- Aktivace cizího pluginu při zapnutém modulu (`activated_plugin`) → upozornění, nic se nevypne.
- Skupina B jen upozorňuje (bez migrace a bez deaktivace).

### 26.6 MCP server UX Studia

- Přesun MCP z AI Assistantu do jádra (`Core/Mcp`), běží i bez AI modulu.
- Transport Streamable HTTP přes WordPress MCP Adapter (přibalit composerem) + Abilities API.
- Abilities `uxstudio/*`: moduly (seznam, zapnout/vypnout přes guard), nastavení (podle schématu,
  tajné hodnoty nikdy ven), migrace (scan/plan/run s `dry_run`=true výchozí/verify/deactivate
  jen s `confirm`), CSP inventura, IP bany, Activity Log, e-mail log, přesměrování (už existují).
- Tokeny se scope (read / write / admin / migration), expirace, každé volání do Activity Logu.
- UI vygeneruje příkaz pro Claude Code / konfiguraci pro Claude Desktop a Cursor.
- Později: CA jako agregátor - jeden MCP pro celou flotilu.

### 26.7 Fáze

- [x] F1 Catalog + detekce + ActivationGuard + upozornění (bez migrace) - hotovo 2026-10-21: `includes/Core/Migration/{Catalog,Overlaps,ActivationGuard}.php`, REST `overlaps` + `overlaps/ignore|unignore`, 409 `uxstudio_plugin_overlap` na `modules/{id}` (filtr `uxstudio_overlap_guard_enabled` vypíná kontrolu jen na REST cestě, WP-CLI a `Modules::set_enabled()` zůstávají volné), panel na Modulech, modal 409, lišta na stránce modulu, wp-admin notice; test `tests/overlap-catalog.php`
- [x] F2 Průvodce (UI) + sken + plán + zkušební běh + Snapshot/rollback + zdroje jen s nastavením - hotovo 2026-10-21 (viz „F2 poznámky“ pod seznamem)
- [x] F3 Zdroje s daty (36 pluginů ve 14 zdrojích) + staging - hotovo 2026-10-21 (viz „F3 poznámky“ na konci §26)
- [x] F4 Bezpečný náhled + smoke testy + přepnutí + vrácení přepnutí - hotovo 2026-10-21 (viz „F4 poznámky“ §26.10); sandbox je mu-plugin `uxstudio-preview.php` + `PreviewBoot`, testy `tests/migration-preview.php`
- [x] F5 Admin Customiser zdroje (AME, Adminimize, White Label CMS, Adminify, LoginPress) + Admin Columns - hotovo 2026-10-21 (viz §26.11); testy `tests/migration-admin-sources.php`
- [x] F6 MCP v jádru + abilities + scope tokenů + UI pro připojení klienta - hotovo 2026-10-21 (viz §26.12); testy `tests/mcp-core.php`
- [x] F7 Automatické spuštění po instalaci, položka v menu, překlady, dokumentace - hotovo 2026-10-21 (viz §26.13); testy `tests/wizard-autostart.php`, `tests/login-url-notice.php`

**F2 poznámky (2026-10-21).**
- Kód: `includes/Core/Migration/` - `Source` (rozhraní), `AbstractSource` (detekce, plán, zkušební běh, zápis, ověření; zdroj implementuje jen `map()`), `Mapping`, `SettingsWriter`, `Snapshot`, `Runner`, `History`, `MigrationMap`, `PluginInfo`, `SourceRegistry`, `MigrationRestController`, `Bootstrap`, `Sources/*` (14 tříd, 32 pluginů). SPA `src/app/wizard/` (krok = položka v `getSteps()`, F4 jen přidá další), styl `wizard.scss`. Test `tests/migration-sources.php`.
- Zápis jde přes `Settings::clean()` (schéma modulu), ne přes `BaseModule::save_settings()`: ta má vedlejší účinky (přepis bloku v `.htaccess`, bany zemí, provisioning CAPTCHA) a migrace smí být jen kopie nastavení. Tajné hodnoty přes `Security::store_secret`, a jen když se liší (náhodný IV by jinak dělal z opakovaného běhu změnu).
- Plugin s uloženým nastavením se přenáší i neaktivní (s varováním); plugin, který funguje jen svou přítomností (WPS Hide Login, Hide Admin Bar, Disable XML-RPC, Disable WordPress Updates, Classic Editor), se přenáší jen když je aktivní, jeho výchozí hodnoty se berou jako efektivní nastavení.
- Sken hlásí i „plugin odstraněn, ale nastavení zůstala“ (`data_only`); je volitelný, nepředvybraný.
- Hodnota, kterou modul neumí (neexistující typ obsahu, role), se v plánu označí a `verify()` ji hlásí jako rozdíl - nikdy se tiše neztratí. Lokálně např. `e-landing-page` u Disable Comments.
- Mapovací tabulka: řádek na dvojici (zdrojový klíč > cílový klíč), unikátní klíč `source_plugin+source_key+target_type`; rollback řádky běhu maže.
- Snímek: option `uxstudio_migration_snapshot_<run>`; tabulky (`capture_table`) jsou připravené pro F3, v F2 nepoužité (WP Extended běží v režimu `settings_only`, tabulky a import activity logu zůstávají F3). Snímek se po vrácení maže, jinak po 30 dnech (denní cron `uxstudio_migration_purge`). Vrácení obnovuje jen volby/tabulky cíle, ne `uxstudio_migration_run(s)`.
- Krok Kontrola dělá zkušební běh (bez zápisu, bez snímku); snímek vzniká až na začátku skutečného běhu (první řádek kroku Migrace). Rozdělaný zkušební běh nikdy neblokuje nový běh, rozdělaný skutečný ano (409).
- Pokud je cílový modul (např. Security Optimization) už zapnutý, přenesená nastavení se projeví hned - plán na to upozorní u každého zdroje.
- Neověřeno proti skutečnému pluginu: `redirect-404-to-homepage` (slug na wordpress.org neexistuje, bere se jako plugin bez nastavení); formáty pluginů, které nejsou lokálně nainstalované, jsou čtené z kódu stažených zipů, ne z živých dat.


### 26.8 Staging: nic se nezapne, dokud neběží přepnutí (F3, priorita 0)

Revize po F2 našla chybu: `SettingsWriter` psal přenesené hodnoty rovnou do živého nastavení modulu. U už zapnutého modulu
(Security Optimization je zapnutá skoro všude) se tak migrace WPS Hide Login projevila hned vlastní login URL vedle
WPS Hide Login (hrozba vyzamčení), totéž CAPTCHA. Porušovalo to pravidlo „modul přebírá práci až při přepnutí po náhledu".

- **Modul vypnutý** → nastavení se zapíše přímo do `uxstudio_<modul>` (bez účinku, dokud se modul nezapne).
- **Modul zapnutý** → hodnoty jdou do `Core\Migration\Staging` (option `uxstudio_migration_staged`, bez autoloadu): `run_id → {created, settings[modul][klíč], secrets[option]{modul, sealed}, activations[]{source, kind, ids}}`. Tajné hodnoty jen zašifrované (`Security::seal`, AES-256-GCM jako `store_secret`), nikdy čistý text.
- Náhled, zkušební běh i `verify()` skládají **živá nastavení + čekající staging** (nejnovější běh vyhrává), takže druhý běh stejného zdroje nezapíše nic nového (idempotence) a ověření srovnává zdroj s tím, co bude platit.
- `Staging::apply( run_id )` (použije přepnutí ve F4): zapíše staged hodnoty přes `Settings::save` (ne přes `save_settings` modulu, jeho vedlejší účinky – `.htaccess`, bany, provisioning klíčů – spustí přepnutí po zapnutí modulu), odešle tajné hodnoty přes `Security::store_secret`, zapne data vytvořená neaktivní (rozhraní `Activatable`), smaže záznam, zapíše `applied` do historie, Activity Log a vyvolá akci `uxstudio_migration_staged_applied( run_id, moduly )`. Druhé volání nic nedělá.
- `Staging::discard( run_id )` volá `Runner::rollback` (a denní purge maže záznamy starší než okno snímku, 30 dní). Neaktivní data odstraní obnova tabulek ze snímku.
- `migration/status` vrací `staging` (počty hodnot, tajných hodnot a aktivací čekajících na přepnutí), historie `staging` a `applied`. Průvodce: „Připraveno, začne platit po přepnutí" místo „Zapsáno" (kontrola, migrace, ověření, hotovo).
- **Pravidlo pro data (F3):** co mění chování webu, dokud běží starý plugin (přesměrování, snippety, viditelnost menu, přihlášení, CAPTCHA, režim údržby, plánování expirace, externí odkazy), vzniká **neaktivní** a aktivuje se přes `Staging::apply`. Neškodné (složky médií, last-login meta, historie e-mailů, definice cron intervalů) se zapisuje přímo.

### 26.9 F3 poznámky: zdroje s daty (2026-10-21)

**Kód.** `AbstractSource` dostal datovou fázi: první volání `migrate()` zapíše nastavení, další čtou foreign úložiště po dávkách (500, kurzor, `processed / total` v `uxstudio_migration_run`), zkušební běh projde vše jedním průchodem bez zápisu. Zdroj implementuje `read_batch`, `apply_item`, `preview_item`, `verify_item` (+ `target_tables()`, `options()`). `AbstractMetaSource` je základ pro zdroje zapisující post/user meta. Zápis jde vždy přes API modulu (`Repository::save`, `SnippetManager::createSnippet`, termová API, `Security::store_secret`), nikdy přímo do souborů nebo tabulek modulu mimo jeho logiku. Snímek umí tabulky (`target_tables()`, záloha `CREATE TABLE ... LIKE` + zároveň záloha verzí tabulek `uxstudio_module_db_versions`, aby po vrácení smazaná tabulka nezůstala „nainstalovaná“). Objekty v core tabulkách (termy složek, meta, cron události, soubory snippetů) maže `Revert` podle mapovací tabulky: zapisují se jen objekty, které běh VYTVOŘIL (nikdy existující), takže vrácení nic cizího nesmaže. Po `Staging::apply` už vrácení ze snímku neprojde (409 `uxstudio_migration_applied`, doladí F4 s krokem „zrušit přepnutí“).

**Volby zdroje** (`Source::options()`, REST `migration/run` parametr `options`): zatím jen „Přenést i historii“ u logů e-mailů (výchozí vypnuto). Plán vrací `data[]` (počty objektů, např. 313 přesměrování), `options[]`, `staged`.

**Klasifikace (přímo = neškodné, staged = mění chování webu, dokud běží starý plugin).** Pro modul vypnutý se vše zapisuje přímo (bez účinku do zapnutí), kromě snippetů.

| Zdroj | Cíl | Zacházení při zapnutém modulu |
|---|---|---|
| Redirection, Safe Redirect Manager, Simple 301, 301 Redirects | redirects | pravidla vzniknou NEAKTIVNÍ, aktivní z pluginu se aktivují přes `Staging::apply` |
| Code Snippets, WPCode, HFCM | code-snippets | VŽDY vypnuté (i při vypnutém modulu); zapnou se v `apply`; snippety se ztrátou pravidel (podmínky, cílení, zařízení) zůstanou vypnuté k revizi |
| Page Links To | external-permalinks | nastavení `post_types` staged (adresy zůstávají v postmeta `_links_to`, modul čte totéž pole) |
| PublishPress Future | auto-unpublish | data čekají ve stagingu, `apply` zapíše meta a naplánuje událost |
| Menu Icons, Nav Menu Roles, If Menu, Menu Items Visibility Control | menu-icons, menu-visibility | meta čeká ve stagingu, `apply` zapíše |
| WP Mail SMTP, Easy WP SMTP, Post SMTP, FluentSMTP | smtp-email | nastavení i zašifrované heslo/klíče staged |
| WP Maintenance Mode, Coming Soon (SeedProd), Maintenance, Under Construction | maintenance-mode | vše vč. `mode` staged |
| Simple History, WP Activity Log, Activity Log (Aryo), Adminify | activity-log | `retention_days` staged (kratší doba = mazání dat) |
| Post Types Order, Intuitive CPO | post-type-order | `post_types` staged |
| FileBird, Folders, Real Media Library, Wicked Folders | folder-manager | PŘÍMO (jen organizace médií) |
| When Last Login, WP Last Login | user-last-login | PŘÍMO |
| WP Mail Logging, Email Log | email-log | PŘÍMO (retence + volitelně historie) |
| WP Crontrol | cron-control | PŘÍMO (definice intervalů do `uxstudio_cron_schedules`, modul je registruje) |

**Ztrátové části (výběr, přesné texty jsou v plánech):** Redirection - server/login/referrer/agent/cookie/role/IP pravidla, náhodné a pass-through cíle, rozlišování velikosti písmen a práce s query, počítadla a logy; duplicitní adresa (modul má jedno pravidlo na adresu, platí první); SRM - 403/404, force https, wildcard `*` se převede na regex s `$1`; Simple 301 - pravidla s `*` při vypnutém wildcardu nikdy nefungovala, přeskočí se. Snippety - shortcode (content), run-once, condition, text/universal/blocks, umístění před/za příspěvkem, on demand; HFCM vždy jako typ html (jeho JS/CSS nese vlastní značky), cílení stránek/zařízení = revize. Složky - jen média (složky příspěvků/stránek se hlásí), kolekce/galerie/zkratky RML, ruční pořadí, barvy. Menu - role se u Nav Menu Roles zúží na „přihlášený“ a vypíší se po položkách, „skrýt pro roli“ a více pravidel If Menu a PHP výrazy se nepřenášejí; ikony se mapují na nejbližší Lucide z povolené sady (nemapované se vypíší jménem). SMTP - OAuth tokeny Gmail/Outlook se nekopírují („připojit znovu“, přenese se client id/secret), nepodporované mailery (SendGrid, Mailgun ...) se jmenují, vícenásobné připojení FluentSMTP jen výchozí; heslo se dešifruje schématem pluginu (libsodium / base64 / AES-CTR), konstanty `WPMS_*` z wp-config jsou efektivní hodnoty. Cron - PHP a URL události Crontrolu a pozastavené hooky se jen počítají. Activity Log - historie se NEimportuje (formáty událostí se liší), jen retence. Údržba - pozadí obrázkem, odpočet, přihlášení, per-URL pravidla.

**Cron Control** dostal minimální podporu vlastních intervalů (`uxstudio_cron_schedules` + filtr `cron_schedules`), jinak by události s intervalem z Crontrolu po jeho odinstalaci zůstaly viset.

**Past:** u více vybraných pluginů se stejným cílovým modulem s nastavením se přenášejí po sobě a později zpracovaný přepíše dřívější (výběr v průvodci na to upozorní); data (přesměrování, snippety, složky) se naopak sčítají.

**Ověřeno živě** (pobyty, kopie s reálnými daty + fixtury pro neinstalované pluginy): 338 řádků Redirection → 313 pravidel (25 duplicit), 4 snippety z tabulky `snippets`, heslo WP Mail SMTP (dešifrované a uložené zašifrovaně), 1 e-mail z `wpml_mails`; WPS Hide Login proti zapnuté Security Optimization zůstal ve stagingu (živá volba beze změny, `wp-login.php` běží, vlastní URL neaktivní); druhý běh nezapsal nic; vrácení obnovilo tabulky, volby, soubory snippetů i meta. Testy `tests/migration-sources.php`, `tests/migration-data-sources.php`.

### 26.10 F4 poznámky: bezpečný náhled, smoke testy, přepnutí (2026-10-21)

**Kód.** `includes/Core/Migration/`: `PreviewBoot` (rané jádro, načítá ho mu-plugin), `Preview` (stav, mu-plugin, cookie, lišta, enter/exit), `Smoke` (testy loopbackem), `Switcher` (přepnutí a vrácení). REST `migration/{preview,preview/end,smoke,switch-plan,switch,revert-switch,probe}`; `Source::smoke_checks()` (nepovinné, default prázdné); `Activatable::deactivate()` (opak `activate`); `BaseModule::switch_options()` + `after_migration_switch()` (Security: přepis `.htaccess` jen když se obsah bloku liší, bany zemí; Cron Control: mu-plugin/htaccess/watch). SPA: kroky Náhled, Přepnutí, Hotovo (`steps-preview.tsx`, `flows.ts`), historie běhů ukazuje Přepnuto / Přepnutí vráceno, „Náhled a přepnutí“ pro pokračování a „Vrátit přepnutí“.

**Náhled.** Při startu se zapíše `wp-content/mu-plugins/uxstudio-preview.php` (malý, bez tokenu nic nedělá, bez UX Studia nic nedělá, po posledním náhledu se maže; nezapisovatelná složka = náhled „není dostupný“ a průvodce nabídne přepnutí bez náhledu jen s výslovným potvrzením). Token `<run>.<uid>.<exp>.<scope>.<hmac>`, klíč AUTH_KEY+AUTH_SALT (stejná hodnota jako `wp_salt('auth')` bez filtru `salt`; mu-plugin běží před `pluggable.php`, `wp_salt()` tam není), platnost 30 min. Cookie `uxstudio_preview` (scope c) je svázané se session tokenem z cookie `wordpress_logged_in_*`: cizí session nebo bez přihlášení nic. Hlavička `X-UXS-Preview` (scope h) je jen pro loopback testy. Cookie se nastaví až odkazem „Otevřít web v náhledu“ (`admin-post.php?action=uxstudio_preview_enter`); wizardové REST routy `uxstudio/v1/migration/*` a admin-post enter/exit se cookie nikdy nepovažují za náhled (jinak by se náhled nedal ukončit ani přepnout). V náhledu: starý plugin pryč z `active_plugins` (i síťového seznamu), cílové moduly přidané do `uxstudio_active_modules`, staged hodnoty složené přes `option_uxstudio_<modul>` a `default_option_`, staged tajné hodnoty přes `pre_option_` (už zašifrované stejným formátem), staged neaktivní data přes `Preview::ids()/includes()` (Redirects `Repository::state/find_exact`, Code Snippets `getEnabledSnippets`) a překryv `get_post_metadata` pro meta menu. Hlavička `X-UXS-Preview-Active` dokazuje, že se náhled opravdu uplatnil.

**Nic se neukládá.** `DISABLE_WP_CRON` + `DONOTCACHEPAGE`, `pre_schedule_event` a spol. vracejí false, filtr `query` zahazuje každý zápis (INSERT/UPDATE/DELETE/CREATE...), který míří na volbu, transient, meta klíč nebo tabulku `uxstudio_*` / `uxsmb_*` nebo na `active_plugins`; navíc výslovné kontroly v `HtaccessWriter`, `ImageOptimizer\HtaccessDelivery`, `CronControl::sync_filesystem`, `DB::maybe_upgrade/ensure_module_tables`. **Nejde izolovat:** zápisy cizího kódu (aktivní pluginy, které zůstávají, ukládají cokoli chtějí: session tokeny, transienty, příspěvky, pokud admin v náhledu něco uloží), odchozí HTTP volání a e-maily (test SMTP posílá skutečný e-mail adminovi), zápisy do souborů mimo naše moduly, chování jen pro návštěvníky z cizí IP, cron běhy; meta a data, která potřebují cron (PublishPress Future), se v náhledu neprojeví.

**Smoke testy.** Úvodní stránka, 3 poslední příspěvky + 3 stránky, wp-login.php, wp-admin (s cookies přihlášeného), + testy zdrojů: vzorek ≤ 20 přesměrování (stejný stav a Location jako starý plugin v baseline), snippety (stránky bez fatalu), Disable Comments (formulář komentářů pryč), WPS Hide Login (nová adresa 200 + formulář, wp-login.php dle nastavení; generický test wp-login se pak vynechá), SMTP (testovací e-mail přes REST modulu), Classic Editor (`migration/probe` vrací `use_block_editor_for_post_type`). Každý test = jedno volání, baseline bez hlavičky vs. s ní, `redirection=0`, cache buster, `https_local_ssl_verify`. Fatal = 5xx nebo značky „critical error / Fatal error / Uncaught“. Výsledky ok/warn/fail/info se ukládají do historie běhu (platí 24 h); fail blokuje tlačítko Přepnout, dokud admin nezaškrtne „přepnout i tak“.

**Přepnutí** (`Switcher::switch_on`, vždy za celý běh): záznam `uxstudio_migration_switch_<run>` (volby před, staging, seznam pluginů a modulů, hash `.htaccess`) → `Staging::apply` → zapnutí cílových modulů (guard z F1 obejitý a zalogovaný) → `after_migration_switch()` → `deactivate_plugins()` (ne tiše) → rychlá kontrola úvodní stránky zvenku, při pádu se vše hned vrátí. Změna adresy přihlášení se musí potvrdit zaškrtnutím (nová adresa s tlačítkem Kopírovat, zmínka `UXSTUDIO_DISABLE_LOGIN_LOCK`). **Vrácení:** moduly zapnuté přepnutím pryč → pluginy zpět (`activate_plugin`, hooky běží) → volby přesně zpět (vč. pořadí `active_plugins`) → `Activatable::deactivate` → `after_migration_switch` u modulů, co zůstávají → staging zpátky. Dostupné 30 dní. `Runner::rollback` u přepnutého běhu nejdřív vrátí přepnutí. Vše v Activity Logu (`migration_switched`, `migration_module_enabled`, `migration_switch_reverted` ...).

**Nalezeno při ověření (opraveno):** modul Security neuměl vlastní adresu přihlášení v podadresáři (`current_path()` nebral v potaz `/pobyty`) a kontrola kolize slugu se vždy trefila do catch-all pravidla stránek `(.?.+?)...`, takže vlastní adresa přihlášení se nikdy neaktivovala; smoke test to odhalil. Obojí opraveno v `SecurityOptimization\Module`. `Snapshot::restore_options` nově nemění autoload flag, když se sémanticky neliší (`yes` vs `on`).

**Ověřeno živě (pobyty):** Classic Editor + Disable Comments: migrace, 11/11 testů v náhledu, cookie jen pro danou session, návštěvník bez cookie dál na starých pluginech, po sérii náhledových požadavků beze změny všech `uxstudio_*` voleb, `.htaccess`, počtu řádků Activity Logu; přepnutí (pluginy deaktivované, modul classic-editor zapnutý, 11/11 live testů) a vrácení přesně zpět (všechny sledované volby, `.htaccess` byte po bytu); WPS Hide Login (fixture): náhled, přepnutí (`wp-login.php` 404, `/pobyty/f4-login/` 200), vrácení (`wp-login.php` 200). Testy `tests/migration-preview.php` (149 kontrol).

### 26.11 F5 poznámky: Admin Customiser a Admin Columns (2026-10-21)

**Kód.** `Sources/{AbstractAdminSource,AdminMenuEditorSource,AdminimizeSource,WhiteLabelCmsSource,AdminifySource,LoginPressSource,AdminColumnsSource}.php`, `MenuSimulator` (čisté funkce: kdo vidí kterou položku, pravidlo proti vyzamčení). Jádro dostalo čtvrtý druh hodnot: **celé volby mimo schéma modulu** (`Mapping::option`, `Staging::put_options|pending_options`, přehrání v `PreviewBoot`, `Switcher` je zálohuje a vrací) - pro konfiguraci Menu Organizeru (`uxstudio_admin_customiser_menu_config`) a sloupce (`uxstudio_admin_columns`). `MenuOrganizer::clean_config()` a `AdminColumns\Module::clean_column()` jsou čisté sanitizéry sdílené s migrací. `Source::extra_modules()` (Hide Admin Bar, Dashboard Widgets se zapnou spolu s Admin Customiser). Smoke typ `admin_access`.

**Mapování.** AME (`ws_menu_editor[_pro]`): skupiny mezi oddělovači = kategorie Skupina N + Ostatní, pořadí, přejmenování, dashicony, skryté položky, role (hidden_from_actor, grant_access=false) = whitelist `item_roles`, přesunuté podpoložky = `promoted_subs`, vlastní odkazy. Adminimize: per-role menu = `item_roles`/`hidden_items` (jedna kategorie, nepřeskupuje menu; existující uspořádání z AME zůstane), `.show-admin-bar` per role = Hide Admin Bar (přesně), admin bar uzly, obrazovkové volby a widgety nástěnky jen když je plugin skrývá správcům (modul je globální, varování o ostatních rolích). WLCMS: přihlášení (logo URL -> ID přílohy, barvy), logo admin lišty, skrytí loga WP, patička, nápověda, volby obrazovky, nag, widgety nástěnky. Adminify: menu editor (`_wpadminify_menu_settings`), patička (ne výchozí reklamní), hledání, oznámení, widgety. LoginPress: login_* klíče. Admin Columns: tabulka `admin_columns` (AC 4+) i `cpac_options_*`, typy default/meta/thumbnail/ID/taxonomie, odstraněné nativní sloupce = `enabled:false`.

**Nepřenese se (výběr, přesné texty v plánech).** AME: požadovaná oprávnění (`extra_capability`), pravidla pro jednotlivé uživatele, grant roli bez oprávnění, přejmenování/skrytí/přesun podpoložek, iframe/HTML položky, barvy, multisite. Organizér jen skrývá, stránka zůstává dostupná adresou (plánové varování; od A3 ji volitelně blokuje přepínač „Zablokovat přístup ke skrytým stránkám“, viz 27.6); uživatelům s `manage_options` ukazuje vždy vše. Adminimize: meta boxy, patička/hlavička, vlastní selektory, položky skryté jen jiným rolím. WLCMS: pozadí, hover barvy, vlastní CSS/JS, uvítací panely, RSS, titulek stránky. Adminify: vlastní UI/motiv, horizontální menu, Pro moduly. LoginPress: pozadí, hover, CSS, chování. AC: Pro typy, filtry/řazení/editace, layouty per role.

**Pravidlo proti vyzamčení.** `MenuSimulator::enforce_access`: po mapování se pro roli administrator a aktuálního uživatele simuluje menu; pravidlo, které by skrylo UX Studio, Pluginy, Uživatele nebo Nastavení, se zahodí a hlásí se v plánu (warning + lossy) i při ověření. Smoke `admin_access` otevře ta 4 menu v náhledu i po přepnutí (odmítnutí poznává jen podle chybové obrazovky WP: skutečný web má stejné věty v přeložených JS řetězcích, první verze měla falešné poplachy).

**Ověření (menu).** Ověření porovnává per role množinu viditelných položek ze zdroje a z cíle, jen u položek, na které má plugin pravidlo (jiné může skrývat druhý plugin); známé rozdíly se vypíšou, nevysvětlené selžou. Krok Kontrola ukazuje tabulku role: skryto před/po. Průvodce: částečná shoda se dá zaškrtnout až po otevření seznamu „co se nepřenese“ (předvybrání z URL se u ní ignoruje).

**Živě (pobyty).** AME + Adminimize + WLCMS: náhled 11/11 (vč. admin_access 4/4), menu v náhledu podle AME, přepnutí (pluginy vypnuty, moduly zapnuty, živé testy 11/11) a vrácení: `active_plugins` byte po bytu, `.htaccess` shodný, volby shodné kromě `mw_adminimize` (Adminimize si sám obnovuje cache uzlů admin lišty při každé návštěvě admina). Past: widgety nástěnky přijme modul až po první návštěvě nástěnky (cache transientu), plán to říká. Fixtury AME/Adminimize/WLCMS/Adminify jsou skutečné hodnoty (oříznuté); LoginPress a AC vychází z kódu pluginů.

### 26.12 F6 poznámky: MCP server v jádru (2026-10-21)

**Co se našlo.** `/mcp/` v AI Assistantu nikdy neobsluhoval žádný vlastní transport: `McpBootstrap` jen ověřoval JWT a registroval abilities do WP Abilities API, vlastní endpoint měl dodat samostatný plugin WordPress MCP Adapter (v `vendor/` ani v `composer.json` nebyl, lokálně není nainstalovaný). Lokální WP je 7.1.3, Abilities API v jádru je (od 6.9), ale plugin deklaruje `Requires at least: 6.5`, takže se na něj spolehnout nejde.

**Rozhodnutí: vlastní minimální transport místo přibalení adaptéru.** Důvody: (1) adaptér potřebuje Abilities API, tedy bychom museli přibalit i jeho balíček nebo polyfill; (2) scope tokenů, filtrování `tools/list` podle scope, limit volání a IP allowlist se do cizího transportu dají dostat jen oklikou přes jeho permission callback; (3) v prostředí není composer a `vendor/*` je v gitu ignorovaný (jen `plugin-update-checker` se verzuje), release workflow by musel vendor sestavovat. Proto `includes/Core/Mcp/` obsahuje JSON-RPC 2.0 server (Streamable HTTP, JSON režim: `initialize`, `ping`, `tools/list|call`, `resources/list|read|templates/list`, `prompts/list|get`, protokoly 2025-06-18, 2025-03-26, 2024-11-05) na `POST uxstudio/v1/mcp`. Bez sessions, GET odpovídá 405, žádné CORS hlavičky, cizí `Origin` = 403. Souběh s adaptérem: adaptér dál obsluhuje `/mcp/` a `/wp-abilities/` a přijímá jen tokeny se scope `admin`; naše abilities `uxstudio/*` se (kde Abilities API je) zrcadlí do `wp_register_ability`, takže je adaptér vidí taky.

**Moduly.** `Config` (volba `uxstudio_mcp`, výchozí vypnuto, starý `mcp_enabled` z AI Assistantu se zkopíruje jednou při prvním čtení; pole v AI modulu zůstává, řídí tool calling chatu), `Auth` (filtr `determine_current_user`, bearer jen na `uxstudio/v1/mcp` a na prefixech `/mcp/`, `/wp-abilities/`), `Tokens` (`uxsmcp_<id>_<secret>`, uložen jen SHA-256, scope read/write/migration/admin, expirace povinná max 90 dnů, IP allowlist včetně CIDR a IPv6, odvolání, `last_used`, vázaný na heslo uživatele jako starý JWT, limit 120 volání/min na token = 429 + `Retry-After`), `LegacyJwt` (staré tokeny AI Assistantu platí dál jako `admin` do svého vypršení), `Registry` + `CoreAbilities` + `MigrationAbilities` + `Bridge` (redirects a obsahové nástroje AI Assistantu), `Protocol`, `Server`, `Prompts`, `AdminRest` (`uxstudio/v1/mcp-admin*`), `Secrets`, `Schema`. Abilities nepřepisují logiku, volají tytéž REST routy jako UI (`Internal::rest`), takže platí guard překryvů (F1), snapshot, pravidla přepnutí (F4) a validace nastavení modulu beze změny.

**Scope.** `read` (site-overview, modules-list, module-settings-get, overlaps-list, csp-inventory, activity-log-query, email-log-search, migration-scan/plan/status/runs/switch-plan/preview-status, redirects list/test/404/site-urls/find-target, AI čtecí nástroje), `write` (module-enable/disable, module-settings-update, overlap-ignore/unignore, redirects upsert/delete/audit, AI zapisovací nástroje), `migration` (migration-run, verify, preview-start/end, smoke, switch, revert, rollback). `write` a `migration` zahrnují `read`, `admin` všechno. Nástroje, které potřebují modul (redirects, email log, CSP, AI), se nabízejí jen při zapnutém modulu. Destruktivní nástroje (`destructive` v anotacích, i `destructiveHint` AI nástrojů) a switch/revert/rollback vyžadují `confirm: true`; bez něj se nic neděje a odpověď popíše důsledky (u switche i změnu přihlašovací adresy, pak je potřeba i `ack_login`). `override: true` (jen s `confirm`) odemkne přepnutí při neúspěšných nebo nedostupných testech náhledu. `migration-run` má `dry_run` výchozí true.

**Tajemství.** `module-settings-get` nahrazuje hodnoty klíčů typu password/secret/token/api_key... příznakem `has_<klíč>` (a zapečetěné `uxs1:` hodnoty vždy), výstup každého nástroje prochází `Secrets::scrub`. `module-settings-update` odmítne klíče vypadající jako přihlašovací údaje, pokud token nemá `admin` a volání `allow_secrets: true`, a klíče mimo schéma. Do Activity Logu (modul `mcp`, `token_id`, `ip`) se zapisují jen názvy argumentů a bezpečné příznaky, nikdy hodnoty.

**UI.** Nastavení, záložka „MCP server“ (`src/app/McpSettings.tsx`, `mcp.scss`): přepínač, endpoint, tabulka tokenů (scope, expirace, naposledy použit, IP, odvolání, staré tokeny označené), dialog vytvoření (token se ukáže jednou) a hotové konfigurace pro Claude Code, Claude Desktop (`mcp-remote`) a Cursor. Záložka MCP v AI Assistantu jen odkazuje.

**Ověřeno.** `tests/mcp-core.php` (matice scope, tokeny, hash, expirace, IP, odvolání, limit, confirm, maskování, guard, staré JWT, výchozí vypnutí). Živě na pobyty přes curl i skutečného klienta (`claude mcp add --transport http`, ✔ Connected, certifikát nevadil): initialize, tools/list podle scope (12/17/20/25, s AI modulem 41/76), site-overview, modules-list, overlaps-list, scan, plan, dry run, reálný běh + verify + náhled + smoke `all` 10/10 + switch bez confirm (popis) + switch + revert + rollback, `module-enable cron-control` odmítnut guardem, 403 read tokenem na write, 401 u odvolaného i prošlého, 403 IP, 429 po 120 voláních. Nalezené a opravené: nekonečná rekurze v `determine_current_user` (zápis volby vyvolá hook, který se ptá na uživatele), AI nástroje s `destructiveHint` obcházely `confirm`.

**Neověřeno / otevřené.** Přibalený MCP Adapter plugin nebyl proti našim routám zkoušen (není nainstalovaný). Smoke testy z MCP nemají cookies relace, admin kontroly běží přes loopback token náhledu. Volba `uxstudio_mcp` se vytvoří při prvním požadavku (jednorázová migrace starého příznaku).

### 26.13 F7 poznámky: dokončení (2026-10-21)

**Stav §26 (souhrn).** F1-F7 hotové lokálně na větvi `feature/migration-wizard`, nepushnuto, verze pluginu nezvýšena (CHANGELOG má položku 0.6.0 "unreleased", vydání je zvláštní rozhodnutí). Hotovo: hlídání překryvů (409 + upozornění), průvodce o devíti krocích, 36 pluginů ve 14 zdrojích + Admin Customiser + Admin Columns, snímek a vrácení na 30 dní, staging pro již zapnuté moduly, bezpečný náhled s testy, přepnutí a vrácení přepnutí, MCP server v jádru. Otevřené: přibalený MCP Adapter plugin nezkoušen (§26.12), smoke testy z MCP nemají cookies relace, formáty neinstalovaných pluginů čtené z kódu (§26.7 F2).

**Automatické spuštění.** `Core/Migration/AutoStart`: aktivační hook jen zařadí nabídku (volba `uxstudio_wizard_autostart` = `pending` + `_user`), a to jen při kliknutí na "Aktivovat" u jednoho pluginu na obrazovce Pluginy (ne hromadně `activate-selected`/`activate-multi`, ne WP-CLI, ne síťově, ne AJAX). Přesměrování na `#/wizard` provede až další běžný požadavek správce (`admin_init`, priorita 20) a jen když sken najde alespoň jeden plugin aktivní nebo s daty; nabídka se spotřebuje i bez přesměrování (`done` / `skipped`), takže se neopakuje. Po Handoffu z UX1 se nepřesměrovává (nepřekrýt upozornění "smazat starý plugin"), upozornění má vedle tlačítka odkaz "Otevřít průvodce migrací". Webům, které jen aktualizují (aktivace neproběhne), a po spotřebované nabídce ukazuje Moduly malý zavíratelný pruh "Našli jsme N pluginů ..." (REST `migration/hint`, zavření per uživatel v user meta), dokud neproběhla žádná migrace. Položka "Průvodce migrací" v menu zůstává.

**Čitelná Kontrola.** `Core/Migration/Describe` (čisté funkce): složité hodnoty = jednořádkové shrnutí (počty, krátký seznam jmen, "+ N dalších") a rozbalitelný detail `label -> hodnota` (`from_detail`/`to_detail`, u ověření `rows`); ID se nahrazují jmény z `Mapping::names()` (tituly položek menu ze zdroje), z jádra WP (Nástěnka, Příspěvky ...) a z rolí webu. Tabulka: první sloupec `min-width` a `overflow-wrap: normal` (příčina rozbitých slov bylo `overflow-wrap: anywhere` právě v prvním sloupci), hodnoty `anywhere`, tabulka se na úzkém displeji posouvá v rámečku; stejné pravidlo v seznamu Ověření.

**Přihlašovací adresa (release safety).** `Modules/SecurityOptimization/LoginUrlNotice` + `Module::login_lock_status()`: při prvním načtení administrace po aktualizaci, kdy je modul zapnutý, se zapíše stav (`uxstudio_login_url_notice`) a je-li zámek skutečně v platnosti (zapnuto, slug, bez `UXSTUDIO_DISABLE_LOGIN_LOCK`, bez kolize slugu), odejde JEDEN e-mail na e-mail webu (stav se zapíše před odesláním, výsledek `sent`/`failed` v něm a v Activity Logu) a každý správce vidí trvalé upozornění s adresou, tlačítkem Kopírovat a odkazem na Zabezpečení > Přihlášení; skrývá se per uživatel (`admin-post`). Nastavení se nikdy nemění. Zámek zapnutý později uživatelem nic neposílá.

**Náhled nedostupný.** Filtr `uxstudio_preview_mu_writable` (test i hostitel), test odmítnutí 409 `uxstudio_preview_unavailable`; v UI se cesta v důvodu láme uvnitř rámečku.


**Nález při živém ověření (login).** Se zapnutou vlastní adresou se formulář na ní odesílal na `wp-login.php`, které modul sám zavírá (`block_direct_wp_login`), takže přihlášení neprošlo (stránka se zobrazila, přihlášení ne). Oprava: filtry `site_url`/`network_site_url` (`Module::filter_login_site_url`, čistá funkce `LoginUrl::swap`) přesměrují akci formuláře, "zapomenuté heslo" i přesměrování na přihlášení na vlastní adresu; po opravě živý test prošel (formulář na nové adrese, přihlášení OK, přímé `wp-login.php` = 302). Test `tests/login-url-notice.php`.

**Živě (pobyty).** Aktivace/deaktivace z obrazovky Pluginy: první aktivace přesměrovala na `#/wizard`, druhá ne. Moduly: pruh "Našli jsme 15 pluginů ...". Kontrola s AME + Adminimize + WLCMS: souhrny a rozbalitelný detail se jmény místo ID, popisky se nelámou, nepřetéká ani na 600 px; Ověření stejně. Nedostupný náhled (odpověď serveru nahrazena v prohlížeči, kód je pokryt testem) se vykreslí celý. E-mail s adresou: Disable Emails ho zablokoval, stav `mail: failed` a záznam v Activity Logu to hlásí. Pozn.: `url_redirect` na pobyty (`http://localhost/pobyty/administrace`) skládá `home_url` na `/pobyty/pobyty/administrace` (stávající chování normalizace, F7 se ho nedotkl).

## 27. Doplnění modulů, aby šly přenést funkce cizích pluginů (2026-10-22)

Návrh z 21. 10. (seznamy „nepřenese se" ze zdrojů §26.9, §26.11, F2), priority schválené
uživatelem 22. 10.: A = časté / bezpečnost (teď), B = užitečné (později), C = nedělat.
Ke každé nové funkci se upraví i zdroj migrace (položka přejde z „nepřenese se" do „přenese se"),
přibudou testy a živé ověření na pobyty. Větev `feature/module-gaps-a` nad `feature/migration-wizard`.

### 27.1 Vlna A

- [x] A1 Zabezpečení: dvoufázové ověření TOTP (vzor z Centrální aplikace), prodlužované blokace
      po opakovaných pokusech, vlastní e-mail pro upozornění, CAPTCHA pro komentáře a formuláře
      WooCommerce + vzhled widgetu; migrace: IP seznamy z Limit Login / Loginizer do Firewallu
      (hotovo 22. 10. 2026 lokálně, poznámky v 27.4; komentáře a pokladnu WooCommerce na pobyty
      nešlo ověřit živě, viz „Neověřeno")
- [x] A2 Přesměrování: podmínky pravidel (přihlášený/role, referrer, user agent, IP, cookie),
      víc pravidel na adresu; Úryvky kódu: snippet jako shortcode, spuštění jednou, umístění
      před/za obsahem (hotovo 22. 10. 2026 lokálně, poznámky v 27.5)
- [x] A3 Úpravy administrace: skutečné zablokování stránek skrytých v menu podle role;
      Viditelnost menu: zobrazit/skrýt podle role, víc pravidel na položku
      (hotovo 22. 10. 2026 lokálně, poznámky v 27.6; blokaci zapíná výchozí vypnutý přepínač, Adminimize
      ve výchozím stavu stránky blokuje, takže jeho migrace přepínač zapne)

### 27.2 Vlna B (spuštěna 2026-10-23, větev `feature/module-gaps-b` nad `feature/module-gaps-a`)

- [x] B1 Administrace: podpoložky menu (přejmenování, skrytí, přesun), pravidla pro konkrétní
      uživatele a vlastní oprávnění položky (AME); widgety nástěnky podle role + vlastní uvítací
      panel (Adminimize, WLCMS); vlastní CSS administrace a přihlášení, pozadí a hover barvy
      přihlášení (WLCMS, LoginPress); meta boxy v editoru podle role (Adminimize); filtry a řazení
      podle sloupců (Admin Columns)
      (hotovo 23. 10. 2026 lokálně, poznámky v 27.7)
- [x] B2 Obsah I: počítadlo zásahů, velikost písmen a parametry v adrese (Redirection); podmínky
      spuštění snippetů - typ stránky, přihlášený, zařízení (WPCode, HFCM); Dashicons v ikonách menu
      (Menu Icons); výjimky Zákazu komentářů - role, typy komentářů, zavírání po N dnech
      (hotovo 23. 10. 2026 lokálně, poznámky v 27.8)
- [x] B3 Obsah II: volby Duplikace - stav kopie, vynechaná pole a taxonomie, oprávnění podle role,
      všechny typy obsahu (Yoast Duplicate Post); pravidla externích odkazů - target/rel podle domény,
      výjimky, ikona (WP External Links); volba editoru pro uživatele (Classic Editor); obrázek,
      odpočet a výjimky údržby (WP Maintenance Mode, SeedProd); složky příspěvků a stránek, barvy,
      ruční řazení (FileBird, Folders)
      (hotovo 23. 10. 2026 lokálně, poznámky v 27.9)
- [x] B4 Technika: SendGrid, Mailgun, Postmark, Amazon SES + záložní spojení (WP Mail SMTP, FluentSMTP);
      skrytí upozornění a vypnutí kontroly aktualizací + výjimka pro bezpečnostní (Easy Updates Manager);
      URL události cronu (WP Crontrol); volby Auto Upload Images; sbalené řádky editoru podpoložek menu
      (hotovo 24. 10. 2026 lokálně, poznámky v 27.10)

### 27.3 Nedělat (C)

PHP výrazy v podmínkách menu a PHP události cronu (spouštění kódu z nastavení), celé UI
Adminify, inline editace a Pro sloupce Admin Columns, import historie Activity Logu,
Rewrite & Republish, kolekce a galerie Real Media Library.

### 27.4 A1 poznámky: Zabezpečení (2026-10-22)

**Dvoufázové ověření (TOTP).** `Core\Totp` (RFC 6238, SHA1, 30 s, 6 číslic, okno plus minus 1, test se vektory z přílohy B),
`SecurityOptimization\TwoFactorStore` (data uživatele, žádný výstup) a `TwoFactor` (přihlášení, profil, role). Nastavení modulu:
`two_factor_enabled` (výchozí vypnuto), `two_factor_required_roles`, `two_factor_grace_logins` (výchozí 3); téma „Dvoufázové ověření“
v menu modulu s přehledem (kdo ho má, kdo ho má povinné a ještě nemá, záchranný přepínač).
- **Průběh přihlášení.** Filtr `authenticate` na PHP_INT_MAX, zaregistrovaný až po zámku pokusů a CAPTCHA (stejná priorita = pořadí
  registrace), takže druhý krok uvidí jen uživatel, který prošel zámkem, CAPTCHA i heslem. Správné heslo uživatele s 2FA nenastaví
  cookie ani nespustí `wp_login`: zobrazí se druhý krok uvnitř wp-login.php (`login_header`), čekající přihlášení je jen hash tokenu
  v user meta (10 minut). Formulář jde na `?action=uxstudio_2fa` (`login_form_uxstudio_2fa`), takže vlastní adresa přihlášení funguje
  beze změny (`wp_login_url()` je přes `site_url` filtr přepsané). Správný kód nebo záložní kód nastaví cookie, spustí `wp_login`
  (čítače zámku se nulují až teď) a `login_redirect`. Každý jiný přihlašovací pokus heslem pro účet s 2FA (formulář WooCommerce,
  AJAX, XML-RPC s heslem) se odmítne (fail closed). Hesla aplikací (REST, XML-RPC) se nijak nemění: core je jen označí
  (`application_password_did_authenticate`) a my je propustíme; pozor, `protect_login` je u modulu stejně vypíná.
- **Bezpečnost.** Tajný klíč zašifrovaný `Security::seal`, záložní kódy jen jako HMAC hash (sůl `auth`, vázané na uživatele), jednorázové,
  konstantní porovnání. Kód z jednoho 30s kroku jde použít jen jednou (replay), jinak by sniffnutý kód platil až 90 s. Limit 5 chybných
  kódů za 10 minut na uživatele, pak se ani správný kód nevyhodnotí do konce okna; zapsáno `two_factor_locked`. Vypnutí a nové záložní
  kódy vyžadují heslo nebo platný kód. Admin zruší cizí 2FA řádkovou akcí na Uživatelích, v profilu nebo tlačítkem v přehledu (nonce,
  `edit_users` + `edit_user`). Události v Activity Logu: `two_factor_enabled|disabled|reset|failed|locked|recovery_used|recovery_regenerated|grace_used|setup_forced`.
  Konstanta `UXSTUDIO_DISABLE_2FA` v wp-config.php vypne celé druhé kolo i profil; je popsaná v nastavení i v přehledu.
- **Povinné role.** Po přihlášení bez nastavené 2FA: `login_gate` spotřebuje jedno odložení a pošle na profil (`profile.php?uxstudio_2fa_setup=1`);
  po vyčerpání je nastavení povinné: `admin_init` pošle každou stránku administrace na profil (profil, admin-post a async-upload zůstávají,
  AJAX a odhlášení také), uživatel se nikdy nezamkne potichu a může si ji kdykoli nastavit. Nastavení 2FA čítač odložení nevrací
  (vypnutí a nové přihlášení hned vynutí). Není to bezpečnostní hranice (REST s cookie jde dál), je to nucení k nastavení.
- QR kód se negeneruje (žádná závislost): klíč po čtveřicích s tlačítkem Kopírovat a odkaz `otpauth://` pro telefon.

**Prodlužované blokace a e-mail.** `lockout_progressive` (výchozí vypnuto), `lockout_multiplier` (1 až 10, výchozí 2), `lockout_max_hours` (1 až 168,
výchozí 24), `lockout_notify_email` (prázdné = e-mail správce, neplatný se zahodí). Délka další blokace = základ krát faktor na počet blokací
téže IP s `status = 1` za posledních 24 h (ruční „Odemknout“ je nepočítá), strop = maximum (`AttemptsHandler::progressive_minutes`, čistá
funkce včetně přetečení na INF). Zpráva „zkuste to za N minut“ ukazuje skutečný zbytek jen při zapnutém prodlužování (výchozí chování se nemění).

**CAPTCHA mimo přihlášení.** `captcha_forms` rozšířeno o `comment`, `woo_login`, `woo_register`, `woo_lostpassword`, `woo_checkout` (výchozí seznam beze změny),
třída `CaptchaForms` funguje u obou umístění (brána existuje jen pro wp-login.php). Každý formulář se ověřuje na serveru: komentáře `pre_comment_on_post` plus
anonymní REST komentář (`rest_pre_insert_comment`, token v parametru), WooCommerce `woocommerce_process_login_errors`, `..._registration_errors`,
`lostpassword_post` (jen formulář WC), `woocommerce_checkout_process`. Přihlášení návštěvníci se u komentářů a pokladny nevyhodnocují, wp-admin se netýká.
Token je u poskytovatele jednorázový, proto `CaptchaVerifier::verify_token` pamatuje verdikt na požadavek (dva handlery na jeden požadavek). Vzhled: `captcha_theme`
(auto, light, dark) a `captcha_size` (normal, compact, flexible; reCAPTCHA bere flexible jako normal a auto jako light).

**Migrace.** `LoginSource` (Limit Login Attempts Reloaded, Loginizer): seznam povolených IP se sloučí do `ip_firewall_allowlist` (stávající řádky první, bez duplicit,
zohledňuje i staging), blokované IP vzniknou jako bany s `origin = migration` v datové fázi (`IpEntries`: adresa, CIDR, rozsah od-do rozřezaný na přesné bloky,
zástupné `*`, bloky širší než /8 a /16 se hlásí a nepřenášejí), při zapnutém modulu NEAKTIVNÍ a zapne je `Staging::apply` (`Activatable`), s nimi se staged zapne
`ip_firewall_enabled`; idempotentní podle hodnoty (`IpBanStore::find_by_value`) a mapování, vrácení ze snímku (`uxstudio_ip_bans`, `uxstudio_ip_ban_ranges`, příznak
`uxstudio_ip_bans_active`). Prodlužování se mapuje přibližně (faktor = (dlouhá/základní)^(1/N), strop = dlouhá doba) a plán to říká jako poznámku. Vlastní e-mail
(`admin_notify_email`, `notify_email_address`). Z „nepřenese se“ zmizelo: delší blokace, seznamy IP, vlastní adresa upozornění; zůstává: seznamy uživatelských jmen,
cloud LLAR, CAPTCHA/passwordless/sociální přihlášení Loginizeru a 2FA (tajné klíče nejdou přenést, „uživatelé si 2FA nastaví znovu“). `CaptchaSource`: komentáře a formuláře
WooCommerce, motiv a velikost; zůstává jazyk widgetu, záložní režim a výjimky, nově se hlásí formuláře jiných pluginů (CF7, WPForms, Elementor ...).
Správa banů (přepnout, smazat) smí i `migration` původ, v seznamu je štítek „Přeneseno“.

**Testy.** `tests/security-2fa.php` (vektory RFC 6238, okno, záložní kódy, replay, limit, token, grace, vypínač), `tests/security-lockout.php`, rozšířené
`tests/migration-sources.php` a `tests/migration-data-sources.php`; všech 11 testů zelených.

**Ověřeno živě (pobyty, dočasný uživatel, vše vráceno).** Heslo, druhý krok, špatný kód odmítnut, správný kód a záložní kód (podruhé odmítnut), replay odmítnut,
5 chybných kódů zamkne a správný kód se pak neuzná, vypínač v wp-config (vráceno byte po bytu), povinná role s grace 2 (profil, potom vynucení, odhlášení dostupné,
po nastavení volno), přehled v nastavení a řádková akce na Uživatelích (špatný nonce 403), prodlužování (v tabulce zámků 1 a potom 3 minuty), widget Turnstile s testovacími
klíči u formulářů WooCommerce (téma, velikost, token projde, bez tokenu odmítnuto, lost-password také), skutečný zapisovač banů migrace (vytvořit neaktivní, aktivovat,
deaktivovat, druhé volání beze změny, smazat).

**Neověřeno / otevřené.** Komentáře a pokladna WooCommerce živě: na pobyty je aktivní Disable Comments (formulář se nevykresluje, odeslání končí „komentáře nejsou povoleny“),
pokladna potřebuje košík; server-side kód je stejný jako u ověřených formulářů, ale vykreslení widgetu v komentářích a na pokladně jsem neviděl. E-mail o blokaci
(vlastní adresa) se neposílal (na pobyty ho blokuje Disable Emails), ověřeno jen uložení a validace. Formáty voleb `cfturnstile_size`, `captcha_theme`/`captcha_size`
WP Captcha a `admin_notify_email` LLAR vycházejí z kódu pluginů, ne z reálných dat. Při zapnuté CSP (Enforce) musí politika pustit `challenges.cloudflare.com`
(frame i script), jinak se Turnstile v prohlížeči nezobrazí; na pobyty běží CSP jen v režimu Report only a hlásí to. Pozor při testování: zámek pokusů se počítá na IP
(127.0.0.1), každý test se špatným heslem zamkne lokální přihlášení všem, včetně správce.

### 27.5 A2 poznámky: podmínky přesměrování a funkce úryvků (2026-10-22)

**Podmínky pravidel.** `Redirects\Conditions` (čisté PHP, bez WP volání v ověřování a shodě): typy `login` (in/out), `role` (role i capability,
jako `current_user_can` v Redirection), `referrer`, `agent`, `host`, `cookie`, `header` (operátor equals/contains/regex, regex bez oddělovačů, vždy
bez ohledu na velikost), `ip` (adresy a CIDR, IPv4 i IPv6, `ClientIp::in_ranges`); každá podmínka jde obrátit (`negate`), až 5 podmínek na pravidlo,
platí všechny (AND). Uloženo jako kanonický JSON (`conditions`) + `cond_key` (sha1, '' = bez podmínek). Validace na serveru (kompilace regexu,
parsování CIDR, délky, počty). Redirection „cíl při shodě / při neshodě" = dvě pravidla, druhé s obrácenou podmínkou.
- **Více pravidel na adresu.** Tabulka v2 (`DB::ensure_module_tables`, verze 2): `UNIQUE source_hash` se před dbDelta zahodí (dbDelta neumí změnit
  unikátní klíč na běžný), přibude `UNIQUE (source_hash, cond_key)` a sloupce `conditions`, `cond_key`; stávající řádky zůstávají beze změny.
  Pořadí: delší klíč (adresa s query) první, pak pravidla s podmínkami (position, id), na konci pravidlo bez podmínek (záložní, bez ohledu na pozici).
  Pořád jeden indexovaný dotaz (LIMIT 50); podmínky se čtou jen u pravidel, jejichž adresa sedí, kontext požadavku se staví líně a jednou.
  Regexová pravidla v autoloadovaném stavu nesou `conditions` taky.
- **Ochrana smyček a řetězů.** Smyčka na sebe a regex, který chytá vlastní cíl, zůstává chyba i u podmíněných pravidel. Řetěz se zploští jen přes
  jedno nepodmíněné pravidlo; cíl s podmíněnými pravidly se neslučuje (varování `target_conditional`) a cesta zpět na zdroj je jen varování
  `conditional_loop` (dvojice /members -> /login pro hosty a /login -> /members pro přihlášené je legitimní). `repoint_upstream` jen pro osamělé
  nepodmíněné pravidlo. Odpověď podmíněného pravidla jde s `nocache_headers()`.
- **Tester a AI.** `Tester::test( url, live, visitor )`: bez `visitor` je to anonym bez hlaviček, `skipped_rule_ids` říká, která pravidla se přeskočila;
  `live=true` jde ze serveru, takže se podmínky vyhodnotí pro ten požadavek. Abilities `upsert-rules` (conditions, position), `list-rules` (filtr
  `conditional`), `test-url` (visitor); MCP je bere z `Abilities::definitions()`, validace přes `Core\Mcp\Schema` ověřena v testu. Upsert stejného zdroje s
  jinými podmínkami PŘIDÁ pravidlo, změna existujícího jde přes `id`. 404 log počítá „vyřešeno" pro anonyma. CSV export/import má sloupce `conditions` (JSON) a `position`.
- **SPA.** Editor podmínek ve formuláři pravidla (`modules/redirects/Conditions.tsx`), štítek „Podmínka" + popis v seznamu, štítek „Záložní" u pravidla bez
  podmínky na adrese s víc pravidly, pole pořadí, test URL „jako konkrétní návštěvník".

**Úryvky kódu.** Tabulka v2: `post_types`, `last_run`, `run_result`. Nová umístění (`run_location`): `shortcode`, `before_content`, `after_content`,
`run_once` (jen PHP); `SnippetManager::allowedLocations()` je vynucuje na serveru (dřív neznámé umístění PHP znamenalo „všude").
- **Shortcode / umístění kolem obsahu** (`SnippetRenderer`): snippet jde jen cestou, kterou říká jeho umístění; stejné kontroly jako Executor (zapnutý, platný,
  cesta v adresáři snippetů, integrita těsně před include, safe mode); PHP se nikdy nespouští v REST/AJAX/cron a ve wp-admin jen pro `manage_options`;
  HTML/CSS/JS jsou uložený text, takže se vykreslí všude, kde se shortcode rozbaluje. Výstup PHP přes ob_start, výjimka = prázdný výstup, `$atts` a `$content`
  ve statické closure. Rekurze a hloubka (3) hlídané. Umístění: `the_content` priorita 12, jen hlavní smyčka jednotlivého příspěvku (`is_singular`,
  `in_the_loop`, `is_main_query`, `get_the_ID() == get_queried_object_id()`), ne feed/REST/admin, jen vybrané typy (výchozí post+page). Pozor: WordPress páruje
  stejné shortcode tagy, samostatné se píšou `[uxstudio_snippet id="1" /]`.
- **Spustit jednou** (`SnippetExecutor::runOnceSnippets`, `admin_init`): jen GET na stránce wp-admin (ne AJAX/admin-post), jen `manage_options`, ne v bezpečném
  náhledu ani v safe mode. Snippet se NEJDŘÍV atomicky vypne (`UPDATE ... WHERE enabled = 1 AND run_location = 'run_once'`, uspěje jen jeden požadavek, pád kódu ho
  nezacyklí), pak běží pod stejnou kontrolou cesty a integrity; výsledek (ok, výjimka, fatální chyba přes shutdown, co vytiskl) je u snippetu, v seznamu a v Activity Logu.
  Opětovné zapnutí ho znovu „nabije".

**Migrace.** Redirection: login/role/referrer/agent/cookie/IP/server/header -> podmínky (jazyk, vlastní filtr, typ stránky zůstávají ztrátové); duplicitní adresa se
zahazuje jen při stejných podmínkách, jinak vznikne víc pravidel. Pořadí se mění jen u degenerovaných konfigurací (nepodmíněné pravidlo před podmíněným je teď vždy
poslední). Code Snippets: `content` -> shortcode (HTML bez PHP značek jako html, jinak php začínající `?>`), `single-use` -> run once; WPCode: ruční snippety ->
shortcode, before/after post/content -> before/after content (post+page), ostatní místa (odstavce, mezi příspěvky) zůstávají ztrátová; HFCM: before/after content a `manual` ->
shortcode. Run-once snippet se NIKDY nezapne při přepnutí (`keep_off`), plán to říká; shortcody `[wpcode id]`, `[code_snippet id]`, `[hfcm id]` v příspěvcích se nepřepisují (plán upozorní).

**Ověřeno živě (pobyty, dočasný admin a odběratel, vše vráceno).** Upgrade tabulky v1 -> v2 se třemi řádky (unikát zrušen, nový klíč, řádky beze změny, curl 301/302/410 jako dřív);
41 curl případů (každý typ podmínky + negace, AND, pořadí, záložní, 410, regex s $1, falešná cookie, POST). Redirection (338 + 3 řádky): 316 pravidel (313 + 3 s podmínkou
host; předtím 313, server pravidla se vyhazovala), 25 duplicit zůstává (stejná adresa i podmínky, v Redirection by platilo jen první; 10 z nich má stejný cíl, 15 jiný),
suchý běh, ostrý běh se zapnutým i vypnutým modulem, ověření, druhý běh nic nezapíše, vrácení. Snippety: shortcode (HTML i PHP s atributy), umístění před/za obsahem,
safe mode, REST s přihlášením (PHP nic, HTML ano), run-once (náhled ho nespustí ani nezabere, běžná stránka administrace ho spustí právě jednou, front-end/odběratel/AJAX/POST ne),
fixtury Code Snippets a HFCM přes průvodce (staging, náhled s hlavičkou, vrácení).
**Neověřeno / otevřené.** WPCode (taxonomie) jen v jednotkových testech; `tests/rest-public-routes.php` hlásí 1 selhání (Complianz `/%2e%2e/` route) mimo rozsah A2.
Vrácení staršího běhu průvodce po novějším (stejná tabulka) vrátí snímek toho novějšího, vracet je třeba od nejnovějšího (starší chování F3).

### 27.6 A3 poznámky: blokace skrytých stránek a pravidla viditelnosti menu (2026-10-22)

**Blokace přístupu (Úpravy administrace).** Nastavení `menu_lock_enabled` (Boční menu, výchozí VYPNUTO, takže stávající weby dál jen skrývají), potřebuje zapnutý
Organizér menu. Rozhodování je čistá funkce `MenuSimulator::request_blocked()` (stejná, kterou používá migrace pro kontrolu), napojená v `MenuLock` na `admin_init`
(priorita 1: Organizér už zaznamenal původní menu na `admin_menu` 99999 a WordPress svou kontrolu oprávnění udělal dřív v `wp-admin/includes/menu.php`; zámek tedy jen
přidává odmítnutí, nikdy nic nepovolí). Odmítnutí je přesně to, co WordPress ukazuje u zakázané stránky: `wp_die( 'Sorry, you are not allowed to access this page.', 403 )`
(hláška z jádra, přeložená WordPressem), plus akce `uxstudio_menu_lock_blocked( $top, $user_id )`.
- **Jak se požadavek přiřadí k položce menu** (`MenuSimulator::resolve_request`, bez WP): z původního `$menu` / `$submenu` před úpravou Organizérem. `page=` se porovná se slugem položky
  (stránky pluginů, `admin.php?page=x`, `tools.php?page=x`), jinak soubor a query argumenty položky (`edit.php?post_type=page`, `edit-tags.php?taxonomy=category`, `post-new.php?post_type=page`), vítězí
  položka s nejvíc shodnými argumenty. Editace (`post.php`, `term.php`) patří k seznamu stejného typu příspěvku / taxonomie (typ se čte z `$_REQUEST`, u `post.php` z ID příspěvku, aby uložení stránky
  nepatřilo k Příspěvkům). Požadavek, který nesedí na žádnou položku (např. blok `wp_block` bez menu), se NIKDY neblokuje: zámek odmítá jen to, co pozitivně pozná.
- **Co se blokuje.** Položka, kterou Organizér pro uživatele skrývá: `hidden_items` (skryto všem), `item_roles` (bílá listina rolí), `category_roles` (celá skupina; kategorie se určuje stejně jako při vykreslení:
  přiřazení, jinak automatický odhad, jinak záložní; dřív to simulátor uměl jen s ručním přiřazením). Uživatel s víc rolemi projde, když jedna role položku smí. **Skrytá nadřazená položka = její vlastní adresa i adresy
  všech jejích podpoložek** (Organizér neumí skrýt jedinou podpoložku, podpoložka je tam, kde je nadřazená), **kromě** podpoložky přesunuté na nejvyšší úroveň (`promoted_subs`), kterou Organizér tomuto uživateli ukazuje.
- **Co se neblokuje nikdy:** uživatel s `manage_options` (Organizér mu ukazuje vše; `hidden_items` skrývá i správci, ale nezablokuje ho), soubory `index.php` (nástěnka), `profile.php`, `admin-ajax.php`, `admin-post.php`,
  `async-upload.php`, `options.php`, `load-scripts.php`, `load-styles.php`, REST, cron a WP-CLI (zámek hlídá jen načtení stránek administrace), čtyři chráněné obrazovky (UX Studio, Pluginy, Uživatelé, Nastavení) a
  všechno pod nimi (`MenuSimulator::protected_screens`, stejné pravidlo proti vyzamčení jako při migraci), požadavek bez odpovídající položky menu.
- **Záchranný přepínač** `define( 'UXSTUDIO_DISABLE_MENU_LOCK', true );` v wp-config.php vypne blokování pro všechny (napsáno v nápovědě nastavení). Záložka Organizér menu upozorňuje, že skrytí samo stránku neblokuje.
- Pozor: skrytí `edit.php` (Příspěvky) před rolí s právem upravovat příspěvky zablokuje i editaci příspěvků (`post.php`) té role; „skrytá stránka“ je i její editace.

**Viditelnost menu (pravidla).** Položka má seznam pravidel a zobrazí se, jen když platí VŠECHNA (AND, jako If Menu): `in` (přihlášený), `out` (odhlášený), `role` (má aspoň jednu z rolí; odhlášený nemá žádnou, takže neprojde),
`not_role` (nemá žádnou z rolí; odhlášený projde). Čistá logika `MenuVisibility\Rules` (normalizace, vyhodnocení, kanonický zápis, `keep()` pro potomky). Uložení je zpětně kompatibilní: jediné pravidlo přihlášený / odhlášený se
dál píše jako `_uxstudio_menu_item_visible` = `1` / `2` (starší kód i migrace), vše ostatní jako kanonický JSON v `_uxstudio_menu_item_rules` (`[{"t":"in"},{"t":"role","r":["editor"]}]`, bez zpětných lomítek, protože
`update_post_meta` je odstraňuje); JSON má přednost, starý klíč `_wpext_menu_item_visible` se čte dál. Front-end filtr na `wp_get_nav_menu_items` (ne v administraci): **děti skryté položky se skryjí s ní** (dřív by vyplavaly
na nejvyšší úroveň). Editor na Vzhled > Menu: řádky podmínek (výběr typu + zaškrtávací políčka rolí, Přidat podmínku / Odebrat) s JSON ve skrytém poli; JS (`assets/menu-visibility.js`) staví řádky ze skrytého pole a funguje i u
položek přidaných AJAXem; nonce a `edit_theme_options` jako dřív, starý formulář s radii se na serveru pořád přijme. Blokovou navigaci (`wp_navigation`) modul nepodporuje a nefiltruje (nepoužívá položky menu). Pozor na
stránkové cache (výsledek závisí na přihlášení / roli, stejně jako dřív u přihlášen / odhlášen).

**Migrace.**
- *Admin Menu Editor:* blokuje jen „Přístup“ odškrtnutý pro roli (`grant_access` = false); „Skrýt“ (`hidden_from_actor`, `hidden`) je jen skrytí bez blokace. `model['blocked']` → přepínač se zapne, když aspoň jedna položka v Organizéru
  zůstala a plugin ji pro roli (kromě správců) blokuje. Položky, které plugin jen skryl, se při zapnutém přepínači blokují taky (jeden přepínač na celé menu), plán je jmenuje (varování + lossy „zablokují se i...“).
- *Adminimize:* **volba `mw_adminimize_prevent_page_access` je „Allow Page Access“, hodnota 1 blokaci VYPÍNÁ** (výchozí 0 = skryté stránky se blokují). F5 to četl obráceně (lossy „Prevent page access blokuje“ při hodnotě 1), opraveno:
  `block_all` = volba prázdná → přepínač se zapne (na pobyty je volba 0, takže se zapne a varování „Organizér jen skrývá“ zmizí). Skryté podpoložky plugin blokuje taky, Organizér ne (lossy to říká).
- *Adminify:* jen skrývá (`hidden_for`), přepínač se nezapne.
- Kde se přepínač nezapne, zůstává varování „Organizér položky jen skrývá“ (s radou přepínač zapnout); kde se zapne, nahradí ho poznámka. Chráněné obrazovky, které plugin blokuje, zůstanou otevřené (lossy je jmenuje).
  Ověření má novou kontrolu `menu-lock` (každá dvojice položka + role, kterou plugin blokoval, je blokovaná i v cíli, také ze staging konfigurace).
- *Nav Menu Roles:* `in` / `out` s režimem show / hide přesně, seznam rolí = `role`, „skrýt pro role“ = `not_role` (plugin vyhodnocení otáčí, odhlášený položku vidí), neexistující role (plugin bere i oprávnění) se vynechají a jmenují.
- *If Menu:* podmínky `user-logged-in` a `user-is-<role>` (show / hide), víc podmínek spojených AND, NEBO z „uživatel je <role>“ = jedno pravidlo rolí; jakékoli jiné NEBO a jiné podmínky (stránky, data, WooCommerce) zůstávají ztrátové a počítají se.
- *Menu Items Visibility Control:* výraz v PHP plugin spouští přes `eval`, UX Studio ho nikdy nespustí. Jen pevné jednoduché tvary se rozpoznají vzorem (`is_user_logged_in()`, `!is_user_logged_in()`, `current_user_can('<role>')`, negace, spojené `&&`, role musí na webu existovat),
  nic se nevykonává; cokoli jiného (oprávnění, `||`, jiné funkce, vložený kód) je ztrátové (PLAN 27.3 C platí). Zdroj porovnává existující pravidlo významem (`meta_state` přes `Rules::same`), takže položka s jiným pravidlem se zachová a nahlásí.
  Staging: při zapnutém modulu čekají meta ve staging oblasti, náhled je překrývá (`get_post_metadata`), rollback maže jen to, co běh zapsal.

**Vedlejší opravy.** (1) `SecurityOptimization\Module::current_rest_route()` posílal cestu přes `sanitize_text_field()`, která maže procentem kódované znaky, takže hlídač dot-segmentů v `is_public_rest_route()` nikdy neviděl `%2e%2e`
(nevyužitelné, WP odpovídá 404, ale hlídač musí držet): cesta i `rest_route` se čtou surové (jen se porovnávají, nic se nevypisuje), kontrola tečkových segmentů běží na surové i dekódované podobě; `Core\Mcp\Auth::request_route()` už surová byla.
`tests/rest-public-routes.php` je celý zelený. (2) Překlad e-mailu o vlastní přihlašovací adrese (`LoginUrlNotice`) měl v `.po` msgstr s doslovnými zalomeními řádků uvnitř uvozovek (neplatná syntaxe PO, `make-mo` ho zahodil jako prázdný),
přepsáno s `\n`, `.mo` přegenerováno.

**Testy.** `tests/admin-menu-lock.php` (122 kontrol: resolver, matice role x požadavek, chráněné obrazovky, `manage_options`, víc rolí, kategorie, přepínače a záchranná konstanta), `tests/menu-visibility-rules.php`,
rozšířené `tests/migration-admin-sources.php` a `tests/migration-data-sources.php`; všech 15 samostatných testů a obě WP-CLI testy (`tests/rest-public-routes.php`, `tests/redirects-cpt-target.php`) zelené.

**Ověřeno živě (pobyty, dočasní uživatelé editor / shop_manager / subscriber / vlastní role bez `manage_options` / administrátor, vše vráceno).** Zámek vypnutý: nic se nemění; zapnutý (Média jen pro správce a autory, WooCommerce jen pro správce a editory,
Stránky skryté všem): editor 403 u Médií, Stránek, Nové stránky a editace stránky (`post.php`), Příspěvky a profil dál 200; shop_manager 403 u `wc-orders`, `wc-reports`, Médií, Stránek, ale seznam Produktů 200 (bez pravidla); vlastní role s právy
`activate_plugins` a `list_users` bez `manage_options` otevře Pluginy a Uživatele, přestože je `item_roles` vylučuje; administrátor nikdy 403; záchranná konstanta v wp-config.php (dočasně, vráceno byte po bytu) vrátí matici stejnou jako vypnutý zámek. Viditelnost menu:
kombinace pravidel x 6 návštěvníků (nepřihlášený, odběratel, editor, správce obchodu, vlastní role, administrátor), potomek skryté položky zmizí s ní, uložení z editoru (kanonický JSON, starý klíč se smaže, položka přidaná AJAXem má editor).
Migrace: AME + Adminimize (skutečná data pobyty; modul vypnutý: přímý zápis, zapnutý: staging, `menu_lock_enabled` se zapnul jen u Adminimize), suchý běh nic nezapsal, ověření bez chyby vč. `menu-lock`, náhled 11/11 (admin_access 4/4), rollback přesně
(`uxstudio_admin_customiser` md5 shodné); fixtury Nav Menu Roles, If Menu a Menu Items Visibility Control (skutečné kódy pluginů, neaktivní): přímý zápis, staging, náhled překrývá meta jen správci s cookie, rollback.
**Původ zapnuté blokace.** Když migrace zapne `menu_lock_enabled`, zapíše volbu `uxstudio_menu_lock_origin` = `{ source, label, run, time }` (`Core\Migration\MenuLockOrigin`): přímý zápis v `AbstractSource::migrate_settings`, u zapnutého modulu leží poznámka ve stagingu (`lock_origin`) a zapíše ji `Staging::apply`. Rollback ji odstraní snapshotem (`target_options`), zrušení přepnutí `Switcher` záznamem voleb; ruční změna přepínače (`Module::save_settings`) ji smaže. UI: informační callout pod přepínačem (Úpravy administrace, Boční menu) s datem a odkazem do historie průvodce, odznak na kartě Organizér menu (`GET admin-customiser/menu-lock`), a text kroku Kontrola říká, kde se nastavení najde a že jde vypnout. Ověřeno na skutečných datech pobyty (Adminimize, modul vypnutý): poznámka zapsána, světlý i tmavý režim, rollback ji vrátil, vše obnoveno z dumpu.

**Neověřeno / otevřené.** Multisite; položka menu přidaná pluginem po `admin_init` priority 1 se v původním menu nenajde, takže se nezablokuje; blokové navigace; editor pravidel v Customizeru (jen klasická obrazovka Vzhled > Menu).

### 27.7 B1 poznámky: administrace (2026-10-23)

**Model.** Config Organizéru (`uxstudio_admin_customiser_menu_config`) má nové klíče: `sub_titles`, `sub_hidden` (role, `*` = všichni), `sub_moves`, `sub_order`
(klíč podpoložky je `původní rodič>slug`), `item_caps` a `item_users` (`{show, hide}` přihlašovacích jmen; platí i pro nejvyšší úroveň). Jediné místo, které rozhoduje, je
`MenuSimulator` (`pass`, `sub_own_visible`, `sub_visible`, `request_blocked`), stejně ho používá běh menu, zámek i migrace. Pravidla: hide uživatele a chybějící oprávnění položku
skryjí (kromě čtyř chráněných obrazovek pro uživatele s `manage_options`), show uživatele přebije seznam rolí a rolí kategorie, ne oprávnění (show nikdy nedává práva);
uživatel s víc rolemi má podpoložku skrytou jen když ji skrývá každá jeho role. Skrytí podpoložky platí i pro správce (jako `hidden_items`), zámek správce nikdy neblokuje.
První podpoložka menu má stejný slug jako menu: `request_blocked` ji bere jako podpoložku (jinak by pravidlo na „Přehled příspěvků“ nikdy nezabralo).
**Past (nalezeno živě):** podpoložky se mění až na filtru `submenu_file` (priorita 99999), ne při `admin_menu`: WordPress rozhoduje o přístupu ke stránce hned po `admin_menu`
podle původní struktury (odebraná nebo přesunutá podpoložka pluginu by dala „nemáte oprávnění“ i bez zámku) a Admin Menu Editor celé `$menu` / `$submenu` vyměňuje na `submenu_file`
(priorita 1001), takže dřívější změna by zmizela. Přesunutá podpoložka dostane adresu stránky, kterou otevírá (`tools.php?page=x`), zvýraznění nového rodiče řeší `parent_file` /
`submenu_file` (priorita 100000, mapa přesunů se počítá ze snímku původního menu). Horizontální rozvržení dostává transformovaný `$submenu` jen pro flyouty. Pořadí podpoložek: `sub_order[rodič]`
(přesunuté se řadí na konec, pokud nejsou uvedené). Editor: sekce „Podpoložky a pokročilá pravidla“ v záložce Organizér menu (karta na podpoložku, výběr rolí, přihlašovací jména s našeptávačem
z `GET .../menu-organizer/people`).
**Vlastní CSS.** `Core\CssSanitizer` (typ pole `css`): odstraní `<style`, `</style`, `<script`, komentáře HTML, `expression(`, `javascript:`, `-moz-binding`, zbylé `<` před písmenem, `/` nebo `!` přepíše na `\3C `, limit 50 KB (ořez za poslední `}`);
sanitizuje se při uložení i při výpisu. Výpis jen `admin_head` / `login_head` (`CustomCss`), žádný hook na front endu; CSS přihlášení se vypisuje i bez zapnutého přizpůsobení přihlášení. Vlastní JS administrace zůstává mimo rozsah (ztrátové).
Přihlášení: `login_background_image` + `login_background_style` (cover / contain / center / repeat; jen klasické rozvržení), `login_button_hover_bg_color`, `login_button_hover_text_color`, `login_link_hover_color`.
**Meta boxy a widgety podle role.** Pole schématu se generují na roli (`hidden_metaboxes_<role>` s hodnotami `post_type|box_id`, `hidden_widgets_<role>`), takže funguje obecný formulář i staging. Katalog boxů: jádro (seed) + zachycení z `$wp_meta_boxes`
na `do_meta_boxes` (priorita 9999, nejdřív zachycení, potom `remove_meta_box`), uložený v `uxstudio_metabox_catalog` (bez autoloadu, max. 200 na typ); box Publikovat (`submitdiv`) se nenabízí. Co modul nezná, schéma zahodí, proto zdroje
neznámé boxy a widgety jmenují (otevřete jednou obrazovku úprav / nástěnku) a zapisují jen známé (`known_widgets`), aby kontrola nehlásila falešnou chybu. Uvítací panel: `welcome_panel_*` (box `uxstudio_welcome_panel` nahoře v prvním sloupci, `wp_kses_post` při uložení i výpisu, role prázdné = všichni).
**Sloupce.** `sortable` / `filterable` ve sloupci; `ColumnQuery`: řazení vlastního pole, první kategorie a autora jde přes SQL v `posts_clauses` (podotázka na vlastní řádek pole, chybějící hodnoty vždy na konec). **Past (nalezeno živě):** `meta_key` / `meta_query` s OR „existuje / neexistuje“
řadí příspěvky bez pole podle hodnoty JINÉHO meta řádku. Filtry: `uxs_f_<klíč>` (pole = rovná se, taxonomie podle term_id, autor), jen seznamy typů příspěvků (uživatelé, komentáře, média ne).
**Migrace.** AME: přejmenované / skryté / přesunuté podpoložky, `extra_capability` (v AME se přidává k výchozímu oprávnění, stejně jako u nás), pravidla `user:<login>` (grant = show, který jen odkrývá). Adminimize: podpoložky podle role (záznamy jen podle slugu zůstávají ztrátové), widgety podle role, meta boxy `mw_adminimize_disabled_metaboxes_<typ>_<role>_items` (jen `#id` boxů).
WLCMS: pozadí, hover barvy, vlastní CSS přihlášení a administrace, widgety podle `dashboard_widgets_visibility_roles` (přesně pro uvedené role), první aktivní textový uvítací panel. LoginPress: pozadí, hover, CSS. AC: `sort` / `filter` sloupců. Zámek: podpoložky pod chráněnými obrazovkami se nikdy neblokují (ztrátová věta to říká);
`enforce_access` zahazuje user-hide, oprávnění a skrytí chráněných obrazovek (hlásí se jedna příčina na slug).
**Zůstává ztrátové:** vlastní JS (WLCMS, LoginPress), další uvítací panely a panely ze stránky / Elementoru, přihlašovací pozadí s jinou pozicí / jednosměrným opakováním, `access_level` jiný než výchozí, aktéři `special:` v AME, řazení a filtry v seznamech uživatelů / komentářů / médií a u sloupců bez hodnoty, inline editace, položky Adminimize bez rodiče, části editační obrazovky, které nejsou meta boxy.
**Testy.** `tests/admin-b1.php` (nový), rozšířený `tests/migration-admin-sources.php`; všech 16 samostatných testů zelených.
**Ověřeno živě (pobyty, dočasní uživatelé admin / editor / shop_manager / author, vše vráceno z dumpu).** Přejmenování, pořadí, skrytí podle role / `*`, show a hide uživatele, oprávnění, přesun pluginové podpoložky WooCommerce pod Produkty (stránka se otevře, zvýrazněná); bez zámku 200, se zámkem 403 jen u skrytých, správce a chráněné obrazovky nikdy;
vlastní CSS (injekce `</style><script>` odstraněna, 112 KB oříznuto, front end bez CSS, přihlášení s pozadím a hover); meta boxy podle role a typu (Yoast, Woodmart, katalog se učí); widgety podle role a uvítací panel; řazení a filtry proti SQL (vlastní pole nahoru i dolů, autor, kategorie, filtry, kombinace);
migrace AME / Adminimize / WLCMS (přímý zápis i staging, suchý běh, ověření, náhled s `admin_access` 4/4, rollback bajt po bajtu). Opraveno při ověření: přesun podpoložky mizel kvůli AME na `submenu_file`, řazení podle pole, `convert_to_screen()` v REST návrhu klíčů sloupců.
**Neověřeno / otevřené.** LoginPress a Admin Columns nejsou nainstalované (fixtury z kódu); Adminimize na pobyty nemá data podpoložek, widgetů ani meta boxů (jen fixtury); meta boxy v blokovém editoru nejsou ověřené (pobyty používá klasický); sloupce jen `edit.php`; podpoložky se mění až při vykreslení, takže kód čtoucí `$submenu` dříve vidí původní menu.

### 27.8 B2 poznámky: obsah I (2026-10-23)

**Přesměrování.** Tabulka v3 (`DB::ensure_module_tables`, dbDelta přidá sloupce, stávající řádky dostanou výchozí hodnoty = chování beze změny; ověřeno na tabulce
v2 s řádky: sloupce shozené a při prvním požadavku znovu přidané): `case_sensitive`, `query_mode` ('' = výchozí), `source_cs` (zdroj s písmeny, jak je zadán, jen u pravidla
s rozlišováním). Sloupce `hits` a `last_hit` existovaly od v1.
- **Počítadlo.** `Repository::queue_hit()` jen poznamená zásah do paměti a jednou za požadavek zaregistruje `shutdown` (nejnižší priorita); `flush_hits()` pak zapíše jeden
  `UPDATE hits = hits + N, last_hit = ...` na pravidlo (po `fastcgi_finish_request()`, pokud ho PHP má). Žádný SELECT navíc, před přesměrováním se nic nezapisuje. POZOR: pod mod_php
  (lokální Apache) `fastcgi_finish_request` neexistuje, odpověď se tam dokončí až po zápisu, jde o jediný krátký UPDATE. Nastavení `track_hits` platí dál. Seznam ukazuje počet i
  poslední zásah (`formatDate`), `orderby=hits|last_hit` v API, vynulování po pravidlech i všech (`POST redirects/rules/reset-hits`, ability `redirects/reset-hits`, v MCP označená jako
  destruktivní = vyžaduje potvrzení). CSV export má sloupce `case_sensitive`, `query_mode`, `last_hit` (+ `hits`); import čte počitadla JEN u nových pravidel, živá počitadla
  existujícího pravidla nikdy nepřepíše, a co CSV neříká o volbách (`case_sensitive`, `query_mode`), se u existujícího pravidla nemění.
- **Velikost písmen.** Výchozí je beze změny (písmena se ignorují). `case_sensitive` platí pro přesná pravidla i regex: u přesného se po nalezení podle hashe (malá písmena) porovná `source_cs`
  s cestou, jak ji návštěvník napsal (`Url::request()['path_cs']`, `Repository::applies()`), u regexu se vynechá příznak `i` a použije se `path_cs`. Pravidlo `/Foo` -> `/foo` je tak platné
  (kanonizace písmen; bez příznaku je to smyčka), Engine i kontrola smyček porovnávají písmena, jak jsou. Omezení: jedna adresa se stejnými podmínkami může mít jen jedno pravidlo bez ohledu
  na písmena (UNIQUE `source_hash + cond_key` vychází z malých písmen), takže `/Foo` a `/foo` jako dvě různá pravidla nejdou. Hodnoty v parametrech zůstávají citlivé na velikost, klíče parametrů malé.
- **Parametry.** `query_mode`: `default` (jako dosud: zdroj bez parametrů bere libovolné, předávání řídí nastavení modulu; zdroj s parametry jen přesně ty), `exact` (zdroj bez parametrů nebere požadavek
  s jakýmkoli parametrem, jako „přesná shoda“ v Redirection), `ignore` (cesta stačí, parametry se nepředají), `pass` (cesta stačí, parametry se vždy předají). `ignore` a `pass` vyžadují zdroj BEZ
  parametrů (chyba `query_mode_source_query`), u regexu se volba ignoruje s varováním. Rozhodnutí o předání je `Engine::passes_query()`. Podmínky A2 se vyhodnocují až po `applies()`: pravidlo, které
  `exact` vyřadí, se nehlásí jako „přeskočeno kvůli podmínkám“.
- **Migrace Redirection.** Příznaky se čtou z `match_data.source`, jinak z globálních voleb pluginu (`redirection_options`). POZOR na název: `flag_case = true` znamená „ignorovat velikost písmen“, výchozí
  (false) je citlivé (starý text „N pravidel bylo citlivých na velikost“ to měl obráceně, opraveno). `flag_query`: exact -> `exact`, exactorder -> `exact` (pořadí se neřeší, hlásí se), ignore / pass ->
  `ignore` / `pass`; u zdroje, který sám uvádí parametry, nejde subset shoda (hlásí se, platí přesná shoda). `last_count` -> `hits`, `last_access` (UTC) -> `last_hit` v čase webu, epocha = nikdy. Přenáší se i
  `flag_trailing = false` jako ztrátové (modul lomítko na konci vždy ignoruje). Ztrátové dál: logy, duplicitní adresa se stejnými podmínkami. Plán říká, kolik pravidel bere jen adresu
  BEZ parametrů (jako v pluginu), takže `?utm_source=` na staré adrese s výchozím „exact“ přesměrování nespustí (v Redirection také ne).

**Úryvky kódu: podmínky spuštění.** Nový `SnippetConditions` (čistá logika + sestavení kontextu), sloupec `conditions` (JSON) v tabulce metadat v3 (jako umístění: mění je jen přístup do DB). Až 6 podmínek, platí
všechny (AND), každá jde obrátit: typ stránky (každý požadavek má JEDEN druh v pořadí admin, úvodní, stránka příspěvků, jednotlivý obsah, autor, archiv, vyhledávání, 404 - stejné pořadí jako „type of page“
ve WPCode, takže se mapuje 1:1; statická úvodní stránka je „úvodní“, ne „jednotlivá“), typ obsahu (jednotlivý / archiv / obojí), konkrétní ID, přihlášený / odhlášený, role, zařízení (`wp_is_mobile()`), časové okno
(čas webu, oba konce včetně). Podmínky jen zužují, nic nerozšiřují; nerozpoznaná podmínka snippet nikdy nespustí; stránková podmínka před zpracováním požadavku nesedí ani negovaná.
- **Kdy se vyhodnocují.** Vložený HTML / CSS / JS a umístění `before/after_content` a shortcode až při výpisu (stránka i uživatel už známé). PHP snippet bez stránkové podmínky se posoudí při načítání (uživatel,
  zařízení, čas, wp-admin jsou známé hned), PHP s podmínkou stránky / příspěvku se načte na akci `wp` (priorita 0), takže háčky dřív (init, wp_loaded) v něm nevyběhnou; editor na to upozorňuje.
  PHP se v REST / AJAX / cron nikdy nespouští (beze změny, ověřeno živě i s podmínkou), „spustit jednou“ ve wp-admin se bez splněných podmínek nezabere.
- **Migrace.** WPCode: jedna skupina podmínek (AND) typu type_of_page (= / !=), post_type, logged_in, user_role; `show: hide` jen u jediného pravidla (otočí se), `_wpcode_device_type` desktop / mobile; vše ostatní
  (více OR skupin, page_url, referrer, taxonomie, user_meta, plán `_wpcode_schedule` - Pro funkce, formát nešlo ověřit) zůstává ztrátové a snippet vypnutý k revizi. HFCM: `All` (+ vyloučené stránky a příspěvky),
  `s_pages`, `s_posts`, `s_custom_posts` (+ `spt_display_on`), `s_is_home`, `s_is_archive`, `s_is_search`, zařízení; kategorie, štítky, nejnovější příspěvky zůstávají k revizi. `CodeSnippetsSource::collect()` nakonec
  projde každou sadu normalizérem modulu (neprojde = revize bez podmínek).

**Ikony menu: Dashicons.** Celá sada z jádra (350 tříd z `dashicons.css`, uložená jako seznam v `IconLibrary` = zároveň whitelist), typ ikony „Dashicons (WordPress)“ ve výběru, hodnota se ukládá jako třída
(`dashicons-admin-home`, stejně jako Menu Icons). Na front endu `wp_enqueue_style('dashicons')` jen při vykreslení položky s Dashicon (styl zařazený po `wp_head` jde do patičky); přihlášení návštěvníci ho mají
kvůli admin baru. Neznámá třída se při uložení zahodí, při výpisu vynechá. Migrace: Dashicon 1:1 (třída z pluginu musí být v sadě, jinak nejbližší Lucide jako dřív), Font Awesome / Genericons / obrázky beze změny.

**Zákaz komentářů: výjimky.** Nastavení v Zabezpečení > Obsah: `disable_comments_allowed_roles` (role + „návštěvníci bez přihlášení“; takový uživatel není dotčen zákazem ani zavíráním, včetně nástrojů v administraci,
tras REST a počítadel), `disable_comments_allowed_types` (pingback / trackback zůstanou povolené při zákazu), `comments_blocked_types` (pingback, trackback, review odmítnout i bez zákazu, `preprocess_comment` -> 403),
`comments_close_after_days`. **Rozhodnutí: modul filtruje `comments_open` / `pings_open`, volby jádra `close_comments_for_old_posts` a `close_comments_days_old` nikdy nezapisuje** (po vypnutí modulu je stav jako dřív,
ověřeno: volby zůstaly 0 / 14). Výjimka rolí platí pro zákaz i pro zavírání starých příspěvků (jako v pluginu); rozhoduje se při každém požadavku, takže pro stránkovou cache platí upozornění v nápovědě.
Příspěvek, který je sám zavřený (`comment_status`), výjimka neotevře. POZOR: pingbacky chodí přes XML-RPC a „Zakázat XML-RPC“ (výchozí zapnuto) zavírá `pings_open` globálně, takže povolený pingback potřebuje
vypnuté „Zakázat XML-RPC“ (nápověda to říká). Migrace Disable Comments: `exclude_by_role` (jen existující role + `logged-out-users`, neexistující se jmenují), `allowed_comment_types` (pingback / trackback),
`blocked_comment_types` (pingback / trackback / review), `auto_close_days`; ztrátové: ostatní typy (note, vlastní), podmíněná pravidla podle taxonomie / šablony, skrytí existujících komentářů u starých příspěvků.
Kontrola `comment_form_absent` v průvodci se vynechá, když smí komentovat nepřihlášení.

**Testy.** `tests/content-b2.php` (nový, 120 kontrol: počítadlo, matice písmena x parametry x podmínky, CSV round trip, schémata abilities + MCP validátor, matice podmínek snippetů, Dashicons, výjimky komentářů přes
reflexi modulu, žádný zápis voleb jádra), rozšířené `tests/migration-sources.php`, `tests/migration-data-sources.php`, `tests/mcp-core.php`; všech 17 samostatných testů zelených.

**Ověřeno živě (pobyty, dočasní uživatelé admin / editor / odběratel, vše vráceno).** Redirects: upgrade tabulky v2 -> v3 se třemi řádky (beze změny, hity), 20 curl případů (písmena, parametry, regex s písmeny, podmínky),
REST (seznam, vynulování, export), abilities přes `/wp-abilities/v1`. Snippety: 7 druhů stránek x 5 návštěvníků (hosté, mobil, odběratel, editor, admin) pro 16 snippetů, wp-admin jen pro administrátora, PHP v REST
ticho, časová okna, PHP s časnou podmínkou (přihlášený, role, mobil). Ikony menu: výběr v Vzhled > Menu, uložení, zahození podvržené hodnoty, vykreslení, Dashicons CSS jen na stránce s menu. Komentáře (plugin Disable
Comments dočasně vypnutý): role, REST, odeslání, trackback povolený / odmítnutý, zavírání po dnech, jádro beze změny. Migrace: skutečná data Redirection (316 pravidel, 41 550 zásahů, 314 s posledním zásahem, čas převeden UTC ->
Praha, druhý běh 0 zapsáno, ověření 316 z 316, vrácení) a fixtury WPCode, HFCM, Menu Icons, Disable Comments (staging, náhled s podmínkami v praxi, vrácení).

**Neověřeno / otevřené.** Autorské archivy na pobyty přesměrovává web sám, proto se druh „autor“ živě neověřil (jen jednotkově). Odmítnutí typu `review` (WooCommerce) živě nešlo (stejná cesta jako trackback, ověřený),
formulář komentářů téma na šabloně příspěvku nevykresluje (ověřeno přes `comments_open()` a odeslání). WPCode / HFCM / Menu Icons nejsou na pobyty nainstalované (fixtury z kódu pluginů ve `plugin-src`). Zápis zásahů
po odeslání odpovědi funguje jen na PHP-FPM / FastCGI. Přesměrování migrací s vypnutým modulem (přímý zápis) se živě neopakovalo (kód stejný, jednotkově ověřen).

### 27.9 B3 poznámky: obsah II (2026-10-23)

**Duplikace.** Nastavení modulu: `keep_status` (kopie má stav originálu: publikovaný / soukromý / čekající / koncept; naplánovaný příspěvek dává koncept,
protože datum se nekopíruje a WordPress by ho hned publikoval; uživatel bez práva publikovat dostane vždy „čeká na schválení“, jako dřív), `excluded_meta`
(textarea, jedno pole na řádek nebo čárkou, `*` = libovolné znaky, `#` komentář; vestavěný seznam `_edit_lock`, `_edit_last`, cache Elementoru platí dál),
`excluded_taxonomies` (veřejné taxonomie a taxonomie s editorem), `allowed_roles` (prázdné = kdo smí příspěvek upravit, administrátoři vždy; kontrola je v odkazu
v seznamu, na editačních obrazovkách, v `admin-post` i v REST) a `all_post_types` (každý typ s `show_ui`, kromě pevného vyloučení attachment / elementor_library
...; výběr typů se pak ignoruje). Čistá logika je v `DuplicateRules` (seznam, shoda s hvězdičkou, stav kopie, role), `Duplicator` dostal vyloučené taxonomie.
Hvězdička Yoastu znamená `[a-zA-Z0-9_]*`, v modulu „cokoli“; u běžných názvů polí je výsledek stejný (migrace to uvádí v poznámce).

**Odkazy.** `LinkRules` (čisté): řádek pravidla `adresa tab=ano|ne rel=tokeny|none icon=ano|ne` (první shodný vyhrává, chybné řádky se hlásí s číslem), vzory
adres (`host` = tento host bez `www`, `*.sub.host`, cokoli s lomítkem se porovnává s `host/cesta`, hvězdička kdekoli), `classify` (druh odkazu: přeskočit /
kotva / vnitřní / vnější). Nastavení: `default_target` (nová karta / ponechat), `default_rel` (uložený prázdný řetězec = nic, nikdy neuložené = původní tři
tokeny), `icon_type` (šipka / Dashicon z celé sady `IconLibrary` / žádná), `icon_dashicon`, `icon_position`, `icon_skip_images`, `link_rules`,
`internal_domains`, `subdomains_internal`. Staré „Zakázat šipku“ (`disable_arrow`) se čte dál, když `icon_type` nikdy nebyl uložen, a v nastavení se ukáže jako
„Bez ikony“. **Výkon:** `the_content` bez odkazu se vůbec neparsuje, jinak jeden `DOMDocument` na volání, rozhodnutí o adrese se pamatuje po dobu požadavku
(max. 2000 položek), pravidla se parsují jednou za požadavek. **Změna chování (oprava):** relativní adresa bez lomítka na začátku a `//host` se dřív
klasifikovaly obráceně, `mailto:` / `tel:` / `javascript:` a jiná schémata se přeskakují, shoda hostu ignoruje schéma a `www`; poznámka „otevře se v nové kartě“
se do `aria-label` přidává jen tam, kde odkaz v nové kartě opravdu je.

**Editor.** `EditorChoice` (čisté) zrcadlí logiku pluginu Classic Editor: Přidat nový = výchozí editor uživatele (volba v profilu, jinak web), existující příspěvek =
zapamatovaný editor, jinak podle obsahu (bez bloků = klasický), `?classic-editor__forget` = výslovná volba (s `?classic-editor` klasický). **Uložené hodnoty jsou
klíče pluginu**: uživatelská volba `classic-editor-settings` (`update_user_option`, tedy s prefixem tabulek), post meta `classic-editor-remember`. Migrace proto
nic nekopíruje a žádná volba nezmizí. V režimu „uživatelé si volí“ modul NEfiltruje `use_block_editor_for_post_type` (to by přebilo volbu u příspěvku), ale
`use_block_editor_for_post` s prioritou 100. Nové nastavení `allow_users` a `default_editor` (klasický / blokový; blokový dává smysl jen s přepínačem, `post_types`
je pak výchozí jen pro klasický). Profil: řádek „Výchozí editor“ se třemi volbami (jako web / klasický / blokový; „jako web“ volbu maže). Seznam příspěvků:
„Upravit (klasický editor)“ / „Upravit (blokový editor)“ místo „Upravit“, stav „Klasický / Blokový editor“ u názvu; klasická obrazovka má box Editor s odkazem, blokový
položku „Přepnout na klasický editor“ (`assets/block-editor-switch.js`).

**Údržba.** Obrázek na pozadí už modul měl (médium + přepínač), migrace ho teď použije. Nové: `countdown_enabled`, `countdown_end` (nový typ pole `datetime`, čas webu,
uložený `YYYY-MM-DDTHH:MM`), `countdown_label`, `countdown_auto_disable` (**rozhodnutí: výchozí vypnuto; po konci se režim jen ignoruje při každém požadavku, uložené
nastavení se nemění a nic se nezapisuje ani neplánuje**, admin lišta ukáže „Odpočet skončil, web je otevřený“), `exclude_paths`, `allow_feeds`, `bypass_ips`.
Odpočet: stránka dostane zbývající sekundy ze serveru a tiká podle hodin prohlížeče od načtení (hodiny návštěvníka se neberou v úvahu), bez JS ukáže datum;
po nule se při zapnutém auto-vypnutí stránka jednou znovu načte (zámek v `sessionStorage` na konec odpočtu). Konec odpočtu řídí i `Retry-After` stránky 503
(max. 30 dní, jinak hodina) i bez zobrazeného odpočtu; na existující stránce WordPressu se odpočet nezobrazuje. Výjimky adres (`MaintenanceRules`): přesná cesta
(bez ohledu na lomítko a velikost písmen), `/sekce/*` (stránka i vše pod ní), `*text*`, `regex:` (zkouší se cesta s koncovým lomítkem i bez; chybný výraz se
hlásí a ignoruje); porovnává se jen cesta bez dotazu a bez adresáře webu. IP: `ClientIp::get('auto')` a `ClientIp::in_ranges` (IP i CIDR, IPv6), propouští i do
administrace. Stránka údržby volá `wp_head()`, takže SEO pluginy do ní dávají popis požadované stránky (stávající chování, neměněno).

**Složky.** **Rozhodnutí: jedna sdílená taxonomie `uxstudio_media_folder` s typem objektu v term meta `uxstudio_folder_post_type`** (modul to tak měl už před B3: složky
příspěvků, stránek i CPT, filtr přes `?uxstudio_folder=`, strom v postranním panelu, hromadné „Přesunout do složky“, metabox; důvod: jedna registrace, žádná migrace
taxonomií při zapnutí dalšího typu, strom každého typu je nezávislý). Nové term meta: `uxstudio_folder_color` (`#rgb` / `#rrggbb`, jen validovaná hodnota) a
`uxstudio_folder_order` (pozice mezi sourozenci 1, 2, 3 ...; chybějící = 0, řadí se podle názvu, takže staré složky vypadají beze změny). Seznam složek je řazen
(pozice, název), nová složka a přesunutá složka jdou na konec úrovně. REST: `POST folders/{id}/color`, `POST folders/{id}/position` (`up` / `down`), `POST
folders/reorder` (`ids[]`, jen složky jedné úrovně a jednoho typu). Panel na seznamech: barevná ikona, šipky nahoru / dolů, výběr barvy a odebrání barvy; stránka modulu
v SPA: sloupce Barva a Pořadí. Média zůstávají beze změny (bez barvy, pořadí 0 u starých složek).

**Migrace.** Přesun z „nepřenese se“ do „přenese se“: Yoast Duplicate Post (stav kopie, vynechaná pole vč. přepínače šablony, vynechané taxonomie vč. přepínače formátu,
role s `copy_posts`), Duplicate Page (všechny typy), Post Duplicator (stav „stejný“, vynechaná pole, všechny typy, role s `duplicate_posts`); WP External Links (cíl, tokeny rel
vč. sponsored / ugc / external, Dashicon a jeho pozice, bez ikony u obrázků, podřízené domény, vyloučené URL jako vzory `*text*`, stav „vypnuto“ = bez zásahu do odkazů);
Classic Editor (`allow-users` = přepínač + výchozí editor, blokový výchozí s volbou uživatelů; bez volby uživatelů a s blokovým editorem se dál nepřenáší nic); WP Maintenance Mode
(obrázek z knihovny médií přes URL, konec odpočtu = start + dny / hodiny / minuty, výjimky adres jako `*text*`, IP včetně `1.2.3.*` jako rozsah), Under Construction (`end_date` =
konec + auto-vypnutí, přesně jako v pluginu), Maintenance (plánovaný konec jen pro Retry-After, whitelist URL přesně, IP), SeedProd (role stránky, výchozí otevřené adresy);
FileBird (barvy z `fbv_folder_colors`, `ord`, taxonomie `fbv_pt_tax_<typ>` s `fbv_tax_order`), Folders (`media_folder`, `folder` = stránky, `post_folder`, `<typ>_folder`; `wcp_custom_order`,
`folder_info.has_color`), Wicked Folders (`wf_<typ>_folders`, `wf_order`, `wf_color`), Real Media Library (pořadí; plugin barvy nemá). Typy příspěvků, které dostaly složky, se přidají
do `post_types` modulu (sjednocení s tím, co modul má; zapisuje se přímo, jen organizuje). Barva a pořadí se píší jen u složek, které běh VYTVOŘIL (existující složka se stejným jménem,
nadřazenou složkou a typem se jen znovu použije), takže vrácení smaže přesně to, co přibylo. Pořadí se ukládá jako `ord + 1` (0 je „nikdy neřazeno“). Chování měnící věci
(údržba, výchozí stav duplikace, pravidla odkazů, editor) se při zapnutém cílovém modulu drží ve stagingu (§26.8), složky se zapisují přímo.

**Zůstává ztrátové.** Duplikace: zvýšení menu order, Rewrite & Republish a reference na originál, kopírování data / slugu / autora / hesla / komentářů / potomků / příloh,
oprávnění „kopírovat cizí“ (Post Duplicator), typ / datum / autor / přípona slugu kopie. Odkazy: titulky a třídy přidávané odkazům, nastavení vnitřních odkazů, potvrzení odchodu, vždy
externí adresy (`include_urls`), přeskočené příspěvky, komentáře a widgety (modul upravuje obsah příspěvků), ikona-obrázek a Font Awesome (nahradí šipka nebo Dashicon), `_new` / `_top`
(odkaz si ponechá cíl). Editor: nic. Údržba: předvolené obrázky pozadí WPMM, formulář pro odběr, sociální sítě, kontaktní formulář, chat, GDPR, redirect, „pustit roboty“, režim
„jen vybrané adresy“ pluginu Maintenance, tajný odkaz, barvy / rozložení cizích pluginů, odpočet stránky SeedProd. Složky: ikony složek, příznaky připnuté / zamčené / s prioritou,
složky jednoho uživatele (v modulu jsou sdílené), kolekce a galerie, zkratky RML, složky typů, které web nemá.

**Testy.** `tests/content-b3.php` (nový, 80 kontrol: pravidla duplikace, motor (pole, taxonomie), modul (stav, role, typy), pravidla odkazů, obsah přes DOM, výkon, matice editoru, modul editoru,
výjimky a odpočet údržby, model složek v paměťovém úložišti termů), rozšířené `tests/migration-sources.php` a `tests/migration-data-sources.php`; všech 18 samostatných testů zelených.

**Ověřeno živě (pobyty, dočasní uživatelé admin / editor / autor / odběratel, vše vráceno z dumpu v `D:\parity-test-pobyty\gaps-b3_20261023-2115`).** Duplikace (curl + Playwright, 21 kontrol):
kopie jako koncept, ponechaný stav (publikovaný, soukromý, naplánovaný = koncept), pole s hvězdičkou a taxonomie, role (autor odkaz nevidí, starý odkaz dá 403, REST 403, editor a administrátor ano),
všechny typy (`reference` ano, `elementor_library` ne). Odkazy (13 kontrol na veřejném článku): výchozí chování, pravidlo podle domény, podřízené domény, cesta, seznam „nikdy externí“, odkaz bez
lomítka a `mailto`, `//host`, třída vyloučení, Dashicon vlevo, bez ikony u obrázku, ikona žádná, starý web s `disable_arrow`. Editor (Playwright, 21 kontrol; plugin Classic Editor při tom dočasně
vypnutý): Přidat nový, existující příspěvek, profil, volba bloku, zapamatování v obou směrech, „podle webu“, výchozí blokový, vypnutý přepínač = dřívější chování, odkazy v seznamu. Údržba (návštěvník bez
přihlášení, `Retry-After`, výjimky, IP, odpočet tikající v prohlížeči, pozadí z knihovny, auto-vypnutí, admin lišta, obě rozložení; kanály RSS po dočasném povolení v Zabezpečení, které web vypíná): na konci
režim vypnutý a volba smazaná. Složky (REST + Playwright): stromy stránek a příspěvků nezávislé na médiích, barvy, pořadí (šipka nahoru v panelu ověřena zvlášť s čekáním na překreslení), filtr, hromadný přesun
z obrazovky, SPA bez chyb v konzoli. Migrace přes `Runner` s dry-run, skutečným během, opakovaným během, ověřením a rollbackem (po každém běhu stav shodný): Classic Editor se skutečnými daty webu (replace=classic,
allow=disallow; vypnutý i zapnutý modul = staging; varianty allow a block dočasně přepsané a vrácené), Yoast, Duplicate Page, Post Duplicator, WP External Links, WPMM, Under Construction, Maintenance, SeedProd
a FileBird / Folders / Wicked / RML s fixturami se skutečnými schématy (tabulky `fbv`, `realmedialibrary`, taxonomie, term meta; testovací přílohy, po testu smazané). Při ověření nalezeno a opraveno: `array_splice`
přečísloval klíče odkazů v seznamu (CSS třídy), Maintenance čte datum v čase webu (posun o hodinu), taxonomie `folder` pluginu Folders nešla najít přes `LIKE`. Obrázky v `C:\Users\sturm\.claude\screenshots\b3-*.png`
(česky, SPA světle i tmavě, konzole čistá).

**Neověřeno / otevřené.** Zdroje WP External Links, Yoast Duplicate Post, Duplicate Page (nainstalován, neaktivní), Post Duplicator, WPMM, UCP, Maintenance, SeedProd, FileBird, Folders, Wicked Folders a RML nejsou
na pobyty aktivní, formáty jsou z kódu stažených zipů a z fixtur (jen Classic Editor běžel nad skutečnými daty). Dashicony ve WPEL (`icon_dashicon`) se berou jako třídy `dashicons-*`. Obsah jiných stránek
v nové kartě po auto-vypnutí údržby u stránkových cache záleží na cache pluginu. Přepínač „klasický editor“ v blokovém editoru se ověřil jen zjištěním adresy v `uxStudioEditorSwitch` (menu samotné se nekliklo).
Pole `exclude_urls` WPEL se v modulu porovnává s hostem nebo `host/cesta`, ne s celým řetězcem adresy (hvězdičky na obou stranách to nahrazují). Hvězdička v seznamu vynechaných polí není shodná s Yoastem (viz výše).
`languages/ux-studio-cs_CZ-883112...json` a `-40aeb3...json` jsou JSON překlady pro nový hash buildu (`index.c815c1cf214ea80e7185.js`).

### 27.10 B4 poznámky: technika (2026-10-24)

**E-mail (SMTP Email).** Nové třídy `MailMessage` (zpráva z argumentů `wp_mail()` stejně jako jádro: adresy i `Jméno <adresa>`, hlavičky From / Cc / Bcc /
Reply-To / Content-Type, filtry `wp_mail_from`, `wp_mail_from_name`, `wp_mail_content_type` až po hlavičkách, přílohy načtené do paměti do 25 MB), `Providers`
(čisté funkce `request()` a `verdict()` na poskytovatele + `send()` přes HTTP API WordPressu, takže jde mockovat `pre_http_request`) a `SigV4`. Hostitelé jsou
pevní; Mailgun doména se ověřuje regexem po štítcích (jinak se žádný požadavek nesestaví), region SES je ze seznamu 23 hodnot.
- **Rozhodnutí: SES přes HTTPS API v2 (`/v2/email/outbound-emails`), SigV4 v PHP, ne přes SMTP přihlašovací údaje.** Důvod: nepotřebuje SMTP spojení (sdílené hostingy
  blokují porty), nemá druhou sadu údajů (SMTP heslo se odvozuje z tajného klíče) a vrací skutečný text chyby. Bez přílohy jde `Content.Simple`, s přílohou `Content.Raw`
  (vlastní MIME, Bcc jen v obálce). Access key id i secret jdou přes `Security::store_secret`. SigV4 ověřen vektory z dokumentace AWS (get-vanilla, post-vanilla,
  příklad IAM, odvozený podpisový klíč) a živě nezávislou implementací v Pythonu nad skutečnými požadavky.
- **Záložní spojení.** `pre_wp_mail` handler (`handle_pre_wp_mail`) se zavěsí, kdykoli primární není SMTP nebo je zvolené záložní; čisté SMTP bez záložního zůstává na
  výchozí cestě WordPressu. `send_chain()` zkusí primární, potom záložní; každý pokus je řádek logu (`mailer`, `attempt` = primary / fallback / test, tabulka v2). Nenastavené
  primární se přeskočí (řádek „Toto připojení není nastavené“); bez záložního se chová jako dřív (Brevo a nové služby `null` = výchozí přenos WordPressu, Gmail `false`).
  Záložní shodné s primárním se ignoruje (i při uložení). SMTP jako primární i záložní běží přes **vnitřní volání `wp_mail()`** s příznakem `forced_smtp` (handler ho pustí
  dál, `phpmailer_init` nastaví SMTP i když SMTP není primární); filtr `wp_mail` se pro vnitřní volání odpojí a vrátí (běžel už jednou, patičky pluginů by se zdvojily).
  Chyba SMTP se bere z `wp_mail_failed`. Hooky se volí při startu podle uložených nastavení (změna platí od dalšího požadavku; testovací tlačítko volá přenos přímo).
- **Test po spojení:** `POST smtp-email/test` s `mailer` pošle přes přesně to spojení bez záložního (log `attempt = test`); bez `mailer` jako web (primární, záložní).
  Záložka každé služby má tlačítko, nastavení musí být uložené. Secret pole nikdy nejdou do REST (`has_*`, `mailers_ready`).
- **Brevo a Gmail se nezměnily** (Brevo dál posílá `htmlContent = wpautop(zpráva)` bez příloh a Cc; Gmail jak byl), jen jsou za společným řetězem.
- **Migrace.** WP Mail SMTP / Easy WP SMTP: skupiny `sendgrid`, `mailgun` (region US / EU), `postmark` (+ message stream), `amazonses` (client_id = access key, client_secret,
  region); klíče API se v pluginu ukládají tak, jak jsou, šifrované je jen SMTP heslo, proto `sodium_decrypt` vrací prostou hodnotu beze změny (40znakový tajný klíč AWS
  vypadá jako base64 a zůstane celý, ověřeno testem); konstanty `WPMS_*`. Post SMTP: `sendgrid_api` (+ `sendgrid_region` Global / EU), `mailgun_api` (region je zaškrtávátko
  „Evropa“), `postmark_api`, klíče base64; jeho záložní SMTP server (možná 2x base64 heslo) se stane `fallback_mailer = smtp`, když je primární jiná služba, jinak (druhý
  SMTP) se hlásí. FluentSMTP: výchozí připojení = primární, `misc.fallback_connection` = záložní (poskytovatelé smtp, sendinblue, gmail, sendgrid, mailgun, postmark, ses),
  další připojení se hlásí s počtem. Záložní stejné služby jako primární se nepřenáší a hlásí se. Oprava: `sendinblue` se mapoval na neexistující hodnotu `mailer`.
  Z „nepřenese se“ zmizelo: SendGrid, Mailgun, Postmark, SES, záložní připojení; zůstává: mailery bez protějšku (Mailjet, Outlook, Zoho ...), OAuth tokeny Gmailu,
  bounce adresa, log pluginu, více než dvě připojení FluentSMTP, SES oblasti mimo seznam (hlásí se). WP Mail SMTP Lite nemá záložní připojení (je to Pro funkce, formát
  jsem nemohl ověřit).

**Aktualizace (Disable Auto Updates).** Nová nastavení (vše vypnuto): `hide_notices_core|plugins|themes`, `updates_visible_to` (přihlašovací jména),
`disable_checks_core|plugins|themes`, `allow_security_core`.
- **Rozhodnutí: skrytí je pro VŠECHNY včetně administrátorů, výjimkou jsou jen vyjmenovaní uživatelé** (agenturní případ: klient je také admin). Prázdný seznam = nevidí nikdo.
  Skrývání zeslabí to, co vrací `get_site_transient( 'update_core|plugins|themes' )` (filtr `site_transient_*` s prioritou `PHP_INT_MAX - 10`): oznámení, bublina v menu, admin
  lišta, řádky pluginů i stránka Aktualizace utichnou naráz. **Cron a WP-CLI vidí vždy skutečné nabídky** (automatický updater a `wp plugin update` je potřebují).
- **Vypnutí kontroly:** `pre_site_transient_update_*` vrací „zkontrolováno právě teď, nic nového“, odstraní se akce `wp_update_*`, `_maybe_update_*`, `load-*` a při `init`
  se smažou cron události dané kategorie; požadavky na `api.wordpress.org` (`/core/version-check/`, `/plugins/update-check/`, `/themes/update-check/`) vrací `WP_Error`
  (samotná transient by nestačila, `wp_update_plugins` bez `checked` by šel na síť). Po vypnutí modulu core cron události vytvoří znovu.
- **Výjimka pro bezpečnostní (minor) aktualizace jádra:** `allow_minor_auto_core_updates` na prioritě 20 true, major a dev false, `automatic_updater_disabled` se
  nenastaví ani když je vypnuto vše, a kontrola jádra se NEvypne (nabídky jsou zdroj bezpečnostních vydání), i když je `disable_checks_core` zapnuto. Nápověda i varování
  říkají, že skrytí a vypnutí kontroly skrývá bezpečnostní opravy.
- **Migrace.** Disable WordPress Updates (aktivní): vše vypnout + skrýt + zastavit kontrolu; `osdwp_security_mode` = `allow_security_core` a jádro se neskrývá ani nezastavuje (plugin
  v tom režimu nechává kontrolu i oznámení jádra). Easy Updates Manager: `all_updates = off` nebo `core|plugin|theme_updates = off` = skrýt + zastavit kontrolu u dané
  kategorie; `advanced.excluded_users` (ID) -> `updates_visible_to` (přihlašovací jména) a pak se kontrola nezastavuje, jen se skrývá (vyloučení uživatelé musí aktualizace vidět).
  Zůstává ztrátové: e-mailová upozornění, log aktualizací, vývojové verze, indikátor v admin liště a skryté testy Stavu webu, aktualizace po jedné (`individual`).

**URL události cronu.** `UrlEvents` (definice v option `uxstudio_cron_url_events` bez autoloadu, WP-Cron hook `uxstudio_cron_url_event` s id jako jediným argumentem, `sync()` drží cron
v souladu při `init` v administraci / cronu / CLI, po vypnutí modulu se záznamy odeberou a definice zůstanou). Pole: jméno, adresa, GET / POST (tělo jako JSON, je-li platný), interval
(core i vlastní z `uxstudio_cron_schedules`, nebo jednorázově v čas webu), timeout 1 až 60 s, zapnuto, poslední výsledek (čas, HTTP kód, `ok` = 2xx / `error` / `blocked`, text, ms; tělo
odpovědi se neukládá). Jednorázová událost se po spuštění sama vypne. Nikdy PHP události (27.3 C).
- **SSRF:** adresa je http(s) bez přihlašovacích údajů; každá IP, na kterou se host přeloží (A i AAAA), musí být veřejná (soukromé, loopback, link-local vč. 169.254.169.254, CGNAT,
  multicast, rezervované, IPv4-mapped IPv6, 6to4 a Teredo se skrytou neveřejnou IPv4). Kontrola je při uložení **i při každém spuštění**, požadavek jde přes `wp_safe_remote_request`
  (znovu ověří adresu i přesměrování, jen porty 80, 443, 8080). Jedinou adresu lze otevřít filtrem `uxstudio_cron_url_allow_private( false, $url, $host )`; takový požadavek nesleduje přesměrování.
  Zbytkové riziko: přeložení DNS mezi kontrolou a požadavkem (`wp_safe_remote_request` ověřuje znovu).
- **Migrace WP Crontrol:** události `crontrol_url_cron_job` (args[0] = `url`, `method`, `name`; interval a čas dalšího běhu z WP-Cron záznamu) vzniknou **vypnuté** (modul zapnutý i vypnutý, jako
  snippety) a seznam čeká ve stagingu; `Staging::apply` je zapne a naplánuje se stejným intervalem a prvním během (`CronSource` implementuje `Activatable`). Neveřejná adresa a neexistující
  interval se přeskočí s důvodem (ověření je pak červené u této položky, jako u přeskočeného intervalu). PHP události a pozastavené hooky zůstávají ztrátové. Originální události Crontrolu zůstávají
  v WP-Cron a bez pluginu nic nedělají.

**Auto Image Upload.** Nová nastavení: `base_url` (adresa v obsahu místo domény webu, `/` = jen cesta), `file_name_pattern`, `alt_pattern` (zástupné znaky Auto Upload Images: `%filename% %image_alt%
%postname% %post_id% %post_date% %post_year% %post_month% %post_day% %today_date% %year% %month% %today_day% %url% %timestamp% %random%`, formát dat `Y-m-j` jako v pluginu, výchozí vzor
nemění nic), `max_width` / `max_height` (zmenšení souboru se zachováním poměru stran, nikdy zvětšení, ne SVG ani GIF), `excluded_domains` (host bez www, `*.domena` pro subdomény),
`excluded_post_types`, `custom_fields` (post meta s HTML, po uložení příspěvku). Pro nový příspěvek je `%postname%` a `%post_id%` prázdné (WordPress je při filtru ještě nemá, plugin stejně).
`%date%` a `%day%` by Settings sanitizer snědl jako oktet (`%da`), proto je migrace přejmenuje na `%today_date%` a `%today_day%`. Migrace přenáší všechno; ztrátové zůstává, že plugin používal zmenšenou
kopii a nechával originál (modul zmenšuje soubor samotný) a že plugin znovu použil shodný soubor (modul si pamatuje stažené adresy).

**Editor podpoložek menu.** Každá položka a podpoložka je sbalený řádek: tlačítko s názvem, kódem a jednořádkovým souhrnem pravidel (`summarizeRules`: přejmenováno, přesunuto, skryto pro role,
vyžaduje oprávnění, vždy / nikdy zobrazeno uživatelům; bez pravidel „Beze změn“), `aria-expanded` + `aria-controls`, Enter i mezerník, šipky řazení vedle (mimo tlačítko). Placeholder „jan, eva“,
nápověda pod poli.

**Testy.** `tests/tech-b4.php` (nový, 123 kontrol: vektory SigV4, zpráva, požadavky a verdikty všech čtyř služeb, řetěz s fallbackem a log, SMTP přes vnitřní `wp_mail`, matice skrývání / kontroly /
výjimky, matice SSRF, uložení a běh URL událostí, vzory a zmenšení obrázků, `process_content`), rozšířené `tests/migration-sources.php` a `tests/migration-data-sources.php`; všech 19 samostatných testů zelených.

**Ověřeno živě (pobyty, dočasní uživatelé, vše vráceno z dumpu).** Mock poskytovatelů přes dočasný mu-plugin: všechny čtyři služby (hlavička, tělo, Cc / Bcc / Reply-To, příloha), fallback (500 -> Mailgun EU,
401 + timeout, Postmark 200 s ErrorCode 406, SES 403), nenastavené spojení, per-mailer test; podpisy SES nad skutečnými požadavky shodné s nezávislým Pythonem (jednoduchý i raw MIME); skutečný PHPMailer
(dočasně definovaný skutečný `wp_mail`, web má Disable Emails a motiv blokuje poštu filtrem) k `127.0.0.1:1`: SMTP selže -> SendGrid, SendGrid selže -> SMTP; skrytí oznámení v prohlížeči (klient bez
oznámení, vyjmenovaný vidí 3 pluginy, admin lišta, bublina), zastavení kontroly (žádný požadavek na plugins / themes update-check, cron události pryč), bezpečnostní výjimka; URL události přes REST
(vytvoření, 8 blokovaných cílů, neznámý interval, jednorázová v čas webu, spuštění, `wp cron event run` s 503 a chybou přenosu, skutečné volání lokálního endpointu otevřeného filtrem a zablokované po jeho
odebrání); Auto Image Upload na skutečném obrázku (zmenšeno 600x400 -> 200x133, název, alt, CDN adresa, vyloučená doména a typ, vlastní pole, REST cesta); migrace skutečných dat WP Mail SMTP (heslo shodné
s dešifrovaným pluginu, rollback a `Staging::apply`), WP Crontrol (skutečné intervaly + fixtura URL událostí, aktivace naplánuje události) a Easy Updates Manager (skutečná data), fixtury Post SMTP, FluentSMTP,
Easy WP SMTP a Auto Upload Images; snímky `b4-*` světlý i tmavý.

**Neověřeno / otevřené.** Skutečný klíč žádného poskytovatele neexistoval, odpovědi jsou z mocku (tvar požadavků podle dokumentace poskytovatelů a kódu WP Mail SMTP / FluentSMTP). Nastavení
SES (oblasti `eusc-*` a `cn-*` se nenabízejí). Nepřenáší se druhé a další připojení FluentSMTP. Disable WordPress Updates nebyl nainstalovaný (jen fixtura a test, v živé migraci vyšel
„neaktivní = nic“). Hlídač Cron Control může po zapnutí modulu hlásit staré události Crontrolu jako cizí. Po `wp_delete_attachment` zůstávaly na této instalaci náhledy v uploads (nesouvisí s B4,
uklizeno ručně). Pro nový příspěvek jsou `%postname%` a `%post_id%` prázdné.


## 28. Bezpečnostní revize před 0.6.0 (2026-10-24)

Nezávislá revize našla 12 věcí. Opraveno na větvi `feature/release-fixes`, každá skupina je samostatný commit. Standalone testy: `csp-logic`, `security-2fa`, `mcp-core`, `admin-menu-lock`,
`safe-regex` (nový), `pinned-http` (nový), `tech-b4`.

### 28.1 Opraveno

1. **CSP: padělané zprávy mohly vypnout Enforce (vysoká).** Token `k` v adrese zpráv je veřejný (je v každé hlavičce odpovědi), `p` se bralo z požadavku. Teď `p` = písmeno politiky + 12 znaků HMAC
   přes (písmeno, režim, `since` aktuálního režimu), přijímá se jen štítek aktivního režimu (`CspPolicy::report_tag` / `verify_report_tag`); starý nebo padělaný štítek se zahodí (204). U každého
   řádku se ukládá krátký seznam až 10 solených hashů (síť IP: IPv4 /24 nebo IPv6 /64, bez UA, `CspLogger::reporter_hash`), sloupec `reporters` (DB verze 6), v UI se ukazuje počet oznamovatelů. Automatický rollback chce 3 řádky,
   každý s alespoň 3 různými oznamovateli, a alespoň 3 různé oznamovatele dohromady. Kontrola „bez testovacích porušení“ v bráně Enforce se **nemění**: otrávená brána jen zdrží zapnutí
   (selže bezpečně, správce může řádky vyřešit nebo smazat, případně vědomě přepsat), nikdy nic nevypne. **Akceptováno:** útočník se třemi různými sítěmi (/24 nebo /64) a platným štítkem aktivního režimu rollback
   spustit umí; štítek je veřejný, takže neprokazuje odesílatele. Další obrana by byla jen sledování zdroje (nespolehlivé).
2. **CSP: „Schválit všechny bezpečné“ schvalovalo i neznámé domény (střední).** `safe_open_rows` bere jen `known` a `self`; `unknown` se schvaluje po jednom (nové potvrzení v UI).
3. **2FA selhávalo otevřeně při nečitelném tajném klíči (střední).** `is_enabled` čte jen příznak; nečitelný klíč (změněné soli) = přihlášení heslem samotným neprojde, projde jen
   uložený hash obnovovacího kódu, jinak správce resetuje faktor. Záznam `two_factor_secret_unreadable` do Activity Logu (max. 1x za hodinu na uživatele) a červené oznámení pro správce.
   **Zdokumentováno, ne změněno:** tajné klíče i hashe obnovovacích kódů jsou odvozené z `wp_salt('auth')`, takže po změně solí obnovovací kódy také přestanou platit (správce resetuje faktor
   v Uživatelích, při vlastním zamčení konstanta `UXSTUDIO_DISABLE_2FA`). Samostatný klíč v DB by nebyl zpětně kompatibilní a ležel by vedle zašifrovaných dat.
4. **MCP: `write` mohl přesměrovat uložená tajemství (střední).** Nová třída `Mcp\Sensitive`: seznam klíčů po modulech (`ADMIN_WRITE`, předpony `ip_firewall_*`, `two_factor_*`, `csp_*`, `sso_*`
   atd.) a příznak `'sensitive' => true` ve schématu pole; zápis jen s rozsahem `admin`, odmítnutí vrací kód `admin_scope_required` se seznamem klíčů (i pro `dry_run`). Pro `read` se maskují
   nesecret hodnoty, jejichž znalost oslabuje ochranu (`READ_HIDDEN`: tajná adresa přihlášení, allowlisty IP, výjimky Upload Guardu, bypass IP údržby, `node_allowed_ips`,
   `central_allowed_ips`, BotThrottle `whitelist_*`); příznak `'mcp_hidden' => true` ve schématu. E-mailové adresy příjemců nejsou maskované (nejsou přihlašovací údaje). Webhooky formulářů nejsou
   v nastavení modulů, MCP se jich nedotkne. `Secrets::PATTERN` rozšířen o `access_key`, `signing_key`, `encryption_key`, `session_key`, `service_account`, `webhook_url/secret/key`, `jwt`.
5. **Zámek menu: obejití přes `post_type` na `post.php` (nízká až střední).** Na `post.php` se typ určuje vždy z ID příspěvku (`post_ID` před `post`), argument požadavku se ignoruje; na `edit.php`,
   `post-new.php` a podobných se bere jen registrovaný typ.
6. **MCP: závod revoke a touch (nízká).** `last_used` a `last_ip` jsou v samostatném neautoloadovaném optionu `uxstudio_mcp_used_<id>`; `touch` už seznam tokenů nepřepisuje, `revoke` option maže.
7. **MCP: dávka obcházela limit (nízká).** Každá zpráva dávky stojí jedno volání (`Tokens::hit($id, $now, $cost)`), nevejde-li se dávka do zbytku rozpočtu, je odmítnuta celá (429), celkový
   časový strop dávky 20 s (zbylé zprávy dostanou chybu).
8. **Starý JWT (nízká).** `LegacyJwt::validate` ověřuje `manage_options` uživatele.
9. **ReDoS (nízká).** `Core\SafeRegex`: subjekt max. 1024 B (na hranici znaku), dočasně snížený `pcre.backtrack_limit` (20000, po volání vrácen), chyba = nepasuje. Použito u podmínek přesměrování,
   pravidel přesměrování, výjimek údržby (`regex:` i zástupné znaky), pravidel odkazů a vzorů duplikace. Při uložení `SafeRegex::probe` odmítne vzor, který na sadě záludných řetězců narazí na limit
   (heuristika, žádný důkaz; runtime limity jsou skutečná ochrana). Důsledek: regex pravidla přesměrování vidí jen prvních 1024 B adresy.
10. **Výjimky údržby (nízká).** `request_path` sjednotí `//` a `\`, `path_excepted` nikdy nepovolí cestu se segmentem `..` (ani zakódovaným, ani dvojitě zakódovaným).
11. **2FA zbytky.** (a) Pokusy o kód a obnovovací kódy běží pod zámkem na uživatele (`INSERT IGNORE` řádku v `wp_options`, TTL 15 s, čekání max. 2 s, při neúspěchu zámku se pokus nepřijme),
    takže limit 5 pokusů a jednorázové kódy nejdou předběhnout paralelními požadavky; (b) uživatel po konci odkladu dostane 403 na `admin-ajax.php` (mimo `uxstudio_2fa` a `heartbeat`) a na
    zapisující REST požadavky (mimo `/wp/v2/users/me`; čtení povolena); (c) přihlášení 2FA účtu mimo formulář (XML-RPC, WooCommerce, AJAX) vrací stejnou chybu jako špatné heslo (a počítá se
    jako neúspěšné přihlášení).
12. **DNS rebinding (URL události cronu, Auto Image Upload).** `Core\PinnedHttp`: host se přeloží jednou, všechny adresy musí projít kontrolou, cURL se připne na kontrolovanou adresu
    (`CURLOPT_RESOLVE` přes `http_api_curl`), přesměrování se sledují ručně a každý skok se znovu přeloží, zkontroluje a připne. **Omezení:** bez transportu cURL (jen streams) se připnutí
    neprovede, požadavek jde přes `wp_safe_remote_request` jako dřív. Cron s adresou otevřenou filtrem `uxstudio_cron_url_allow_private` zůstává bez připnutí (záměrně otevřeno kódem).

### 28.2 Akceptováno nebo jen zdokumentováno

- **Ochrana trvalosti náhledu migrace** (`PreviewBoot::guard_query`) zahazuje zápisy do SQL, které se týkají tabulek `uxstudio_*` / `uxsmb_*`, optionů `uxstudio_*` a seznamů
  `active_plugins` (rozpoznání regulárním výrazem nad textem dotazu). Nechrání data jiných pluginů a jádra (příspěvky, uživatelé, objednávky vzniklé při návštěvě stránky v náhledu) a nemusí chytit
  dotaz, kde název optionu vznikne jinak než jako uvozený řetězec. Náhled proto zůstává nástrojem důvěryhodného správce na webu, kde se nepracuje s produkčními daty, ne bezpečnostní hranicí.
- **Zástupce snippetu `$atts`.** PHP úryvky dostávají atributy shortcodu, které může psát každý, kdo smí do příspěvku shortcode vložit. V editoru úryvků je u shortcodového umístění varování
  (nepředávat do `eval`, SQL ani neescapovaného výstupu).
- **Rozsah `migration`** může zapnout importované PHP úryvky, tedy odpovídá spuštění kódu na webu. Napsáno u rozsahu v dialogu tokenu MCP; tokeny s tímto rozsahem se mají vydávat jako admin.
- **Jednoduchý limit REST/MCP** je počítadlo v transientu (ne atomické); při souběhu může propustit pár volání navíc.

### 28.3 Ověření

Živě na `https://127.0.0.1/pobyty` (dočasné uživatele, tokeny a zapnuté moduly vrátit z dumpu): viz výsledky v závěrečné zprávě.

### 28.4 Dopad aktualizace (2026-10-24)

Nezávislá revize „co se stane na ~97 klientských webech po automatické aktualizaci z 0.5.0". Opraveno (commity na `feature/release-fixes`):

1. **Balíček neobsahoval jen plugin.** `release.yml` teď vynechává `tests/`, `bin/`, `PLAN*.md`, `DESIGN.md`, `AUDIT.md`, `PARITY.md`, `languages/.build`, `.gitignore`, `.editorconfig`,
   `package*.json`, `webpack.config.js`, `tsconfig.json`, `composer.*` (kořenové cesty, `vendor/` se nedotkne); `bin/merge-json-translations.php` běží v CI PŘED sestavením zipu. Krok v témže
   kroku shodí vydání, když se některá z těchto cest v `dist/ux-studio` objeví nebo chybí `ux-studio.php`, `build/`, `vendor/plugin-update-checker`. Každý soubor v `tests/` začíná
   `if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }`. Za běhu se nic z vyřazeného nečte (ověřeno hledáním v `includes/` a `ux-studio.php`; odkazy na PLAN.md jsou jen komentáře).
2. **Vlastní přihlašovací adresa.** Nová třída `Core\Upgrade` (spouští ji `Plugin::boot()` hned po `DB::maybe_upgrade()`, před startem modulů) zapíše option `uxstudio_login_lock_pre060`
   (`configured`) z nastavení, jak je zanechala 0.5.0; e-mail a oznámení `LoginUrlNotice` jdou jen tam, kde bylo `configured` (web, který adresu nastaví až po aktualizaci, nic nedostane),
   s neutrálním zněním. `postpass` (`LoginUrl::is_public_action`) nikdy neblokuje `block_direct_wp_login()` a `site_url` filtr ho nepřepisuje. `login_lock_status()` má důvod
   `no_pretty_permalinks` (prázdná struktura nebo `/index.php/...`): zámek se neuplatní (`block_direct_wp_login`, filtr i obsluha slugu používají tentýž stav, počítaný jednou za požadavek) a správce
   vidí oznámení. Kolize slugu se do Activity Logu zapisuje nejvýš 1x denně (transient). **Chování WordPressu:** `logout`, `rp`, `resetpass`, `confirmaction`, `lostpassword` a formuláře
   se generují přes `site_url`/`network_site_url`, filtr je přepíše na tajnou adresu a tam fungují (`?action=...` bez přesměrování); přímé volání `wp-login.php?action=...` je blokované stejně
   jako přihlášení (včetně odkazů z e-mailů odeslaných před zapnutím zámku). **Změna oproti 0.5.0:** na webu s prostými trvalými odkazy, kde 0.5.0 zavřelo `wp-login.php` a adresu neobsloužilo,
   se zámek nově nepoužije (selže otevřeně).
3. **CSP schéma v5.** `CspUploadGuardBootstrap` v migraci jen mění strukturu, maže IP (`WHERE sample_ip <> ''`) a volá `CspRerate::start()`: zapamatuje nejvyšší `id` (option
   `uxstudio_csp_legacy_max_id`, trvale) a stav práce. Přehodnocení po 200 řádcích běží z WP-Cron (`uxstudio_csp_rerate`, jednorázová událost za 20 s, hlídaná při každém startu), zámek je atomický
   `add_option` (zastaralý po 300 s se přebírá), řádek po přehodnocení má `risk_checked_at = NULL`, aby ho znovu zjemnila kontrola stáří domény. E-mail o vysokém riziku se pro řádky s `id` ≤ legacy
   nikdy neposílá (eskalace v `rate_domain_ages` ani `notify_high_risk`). v6 (sloupec `reporters`) je jen nullable sloupec přes dbDelta, žádný datový krok. Řádky starší než 180 dní (a vyřešené starší 90 dní)
   maže denní údržba `CspLogger::purge()` (přidáno do CHANGELOGu).
4. **Vrácení migrace.** `Snapshot::seal()` na konci reálného běhu uloží otisk (hash hodnot optionů, počet řádků a `CHECKSUM TABLE` tabulek); `Runner::rollback_impact()` +
   `GET migration/rollback-impact` vrátí moduly, tabulky a co se od běhu změnilo. Společný dialog `UndoMigrationModal` (historie běhů i krok Ověření) vypíše obnovované moduly a tabulky, napíše,
   že pozdější změny v nich se ztratí, a při změně nebo při nemožnosti to zjistit (včetně chyby dotazu) drží tlačítko vypnuté, dokud uživatel nezaškrtne souhlas. Průvodce 0.5.0 nebyl, takže žádný
   běh bez otisku nevzniká; bez otisku se přesto bere jako „možná změněno".
5. **`DB::ensure_module_tables`.** Po migrátoru se hledá neúspěšný `CREATE`/`ALTER` (nárůst `$EZSQL_ERROR`, který wpdb plní i při potlačených chybách, a `last_error` posledního dotazu, pokud šlo o CREATE/ALTER).
   Neúspěch = verze se nezapíše, transient `uxstudio_dbfail_<modul>` 15 min brání opakování v každém požadavku, `error_log` a jednou denně Activity Log `db_migration_failed` se skutečnou chybou.
   `DESCRIBE` a datové dotazy se nepočítají (dbDelta na neexistující tabulce vždy ohlásí selhání `DESCRIBE`). Úspěšná cesta je beze změny.
6. **Nápověda průvodce na Modulech.** Počet se cachuje v transientu 1 h (`uxstudio_wizard_hint_count`), maže ho `activated_plugin`/`deactivated_plugin`/`deleted_plugin` a zápis do historie běhů.
   `has_data()` e-mail logu a přesměrování používá `SELECT 1 ... LIMIT 1`. **Zbývá:** `FolderSource::has_data()` stále čte celou kolekci složek (počty se v plánu průvodce používají); cache to řeší na 1x za hodinu.
7. **CSP `since`.** `Upgrade::record_csp_state()`: je-li `csp_mode` audit/report-only/enforce a stav nemá `since` (nebo jiný režim), zapíše se `mode`, `since = now`. Enforce ze 0.5.0 dostane
   `enforce_since = 0`, tedy bez automatického vrácení (dokumentováno v CHANGELOGu). Pozn.: `since` vstupuje do podpisu značky `p` v adrese hlášení; stránky v cache s hlavičkou bez značky (0.5.0 značku
   neposílalo) se hlášení zahodí, dokud se cache neobnoví.
8. **`KEY match_enabled` u přesměrování.** Ověřeno na tabulce ve tvaru 0.5.0 v databázi `pobyty_scratch`: dbDelta po upgradu nepřidá duplicitní index (mezera v `(match_type, enabled)` nevadí),
   vznikne `source_cond` a prostý `source_hash`; druhý běh nic nemění. Zjištěno mimochodem: dbDelta při každém běhu hlásí „Changed type" u `BIGINT UNSIGNED`/`SMALLINT UNSIGNED`/`INT` (jen kosmetický ALTER na MariaDB,
   neškodné, neřešeno).

**Co je simulované (`pobyty_upd`):** kopie lokální databáze s aktivním jen tímto pluginem, schématem CSP vráceným do tvaru 0.5.0 (3000 řádků), verzí `security_optimization_csp_upload = 4`, zapnutým
vlastním přihlášením a `csp_mode = report-only`, spuštěno jako CLI požadavek (`DB_NAME` přepsán před `wp-load.php`, `DISABLE_WP_CRON`, odchozí HTTP zablokováno). Výsledek: první načtení WordPressu
včetně migrace ~0,9 s, 0 řádků přehodnoceno, pak 16 dávek (nejdelší ~1 s), staré řádky bez e-mailu, nový řádek vysokého rizika = 1 e-mail, oznámení o přihlašovací adrese = 1 e-mail a podruhé nic. Nesimulováno:
skutečné stažení balíčku přes plugin-update-checker a pomalé sdílené hostingy (dávka 200 řádků trvala ~1 s na lokálním stroji).

### 28.5 Druhá revize (2026-10-25)

Opraveno (commity na `feature/release-fixes`):

1. **Zámek `wp-login.php` šel obejít (vysoká).** `block_direct_wp_login()` normalizoval `action` přes `sanitize_key()`, takže `POSTPASS` nebo `post.pass` vypadalo jako `postpass`, ale WordPress
   porovnává surovou hodnotu a neznámou akci vrátí na přihlašovací formulář. Teď `LoginUrl::is_public_request()`: výjimka jen pro přesně `postpass`, metodu POST a přítomné `post_password`.
   `is_public_action()` a filtr `site_url` pracují s adresou, kterou sestavuje kód (ne s požadavkem návštěvníka), takže tam stejný problém není.
2. **CSP: počítání oznamovatelů (středně vysoká).** `CspLogger::reporter_hash()` hashuje jen síť (IPv4 /24, IPv6 /64), UA se nepoužívá. Automatický rollback z Enforce tedy chce 3 různé sítě.
3. **Zámek menu četl `$_REQUEST` (střední).** `MenuLock::request_args()` bere GET a POST zvlášť jako jádro: `post.php` otevře `$_GET['post']`, jinak `$_POST['post_ID']` (`post_ID` v URL se ignoruje);
   `page` jen z GET; `taxonomy` a `post_type` z `$_REQUEST` jako `edit-tags.php` a `$typenow`.
4. **CSP: „schválit bezpečné“ u sdílených domén (střední).** `CspLogger::bulk_approvable()`: pro `script-src*`, `worker-src`, `frame-src`, `child-src`, `object-src` a `default-src` jen vlastní web nebo
   host z katalogu, který není na sdílené doméně (`CspServices::shared_domains()`: CloudFront, googleusercontent, googleapis, jsDelivr, unpkg, cdnjs, wordpress.com, github.io, appspot a další).
   Ruční schválení jednoho řádku zůstává.
5. **MCP: zapnutí a vypnutí bezpečnostních modulů (střední).** `module-enable` a `module-disable` chtějí rozsah `admin` pro moduly z `Sensitive::SECURITY_MODULES`. Celý modul Security a Disable Auto Updates je
   zapisovatelný jen s `admin`; `Sensitive::ADMIN_WRITE` rozšířeno o další klíče (pixely, systémové prompty, údržba, bot throttle, CSS přihlášení a další), `READ_HIDDEN` o uživatele SMTP a Gmail client id.
6. **`DB::ensure_module_tables` (střední, neověřeno).** Empiricky (dbDelta dvakrát na všech 25 schématech modulů, tabulky s prefixem `zzt_`, pak smazány): druhý běh nevyvolal žádnou chybu,
   předpoklad „neškodný ALTER při každém běhu“ se na lokálním MariaDB nepotvrdil. Přesto: duplicitní klíč nebo sloupec (1060, 1061, 1050, 1091) verzi neblokuje a po kroku se ověří, že každá tabulka
   z `CREATE TABLE` (filtr `dbdelta_create_queries`) existuje.
7. **SSRF u Auto Image Upload (střední).** Jedna sdílená kontrola `PinnedHttp::is_public_ip()` (CGNAT, 0.0.0.0/8, multicast, 6to4, Teredo, mapované adresy); používá ji Cron URL události, Auto Image Upload,
   Elementor Import a Stock Photos.
8. **Migrace (nízká).** `Runner` zapíše záznam historie před `Snapshot::seal()` (a po zapečetění doplní `sealed`); u tabulek nad 200 000 řádků se `CHECKSUM TABLE` vynechá, otisk je jen počet řádků.
9. **SafeRegex (nízká).** Selže-li `ini_set('pcre.backtrack_limit')`, zapíše se to jednou za požadavek do `error_log`. **Zdokumentováno:** předmět delší než 1024 B se ořízne, takže záporná podmínka
   (neodpovídá vzoru) nad delším předmětem vyhodnotí oříznutou část; neshoda se bere jako „nesplněno“.
10. **CSP přehodnocení (nízká).** `CspRerate::run_batch()` obalí řádek do `try/catch Throwable` a posune `last_id` před zpracováním, takže jedna vadná řádka nezastaví další dávky.
11. **2FA: zbylé nálezy (střední).** (a) Přihlášení mimo `wp-login.php` (WooCommerce, `wp_signon`) uživatele s 2FA dostane po ověřeném hesle jasnou hlášku s odkazem na přihlašovací stránku
    (`TwoFactor::non_form_login_error()`, kód `uxstudio_2fa_non_form`) a limiter ani adaptivní CAPTCHA to nepočítají jako neúspěch (`is_non_form_error()` v `AttemptsHandler` a `CaptchaHandler`);
    XML-RPC dál dostane odpověď jako při špatném hesle. (b) Vynucené nastavení po odkladu blokuje AJAX a zápisy REST jen pro požadavky z adminu (referer v `admin_url()`, chybějící referer se bere jako admin) a pro nástroje
    (aplikační hesla, MCP tokeny); veřejný web přihlášeného uživatele (wc-ajax, formuláře) funguje. (c) Pro vynuceného uživatele se z heartbeatu vyřadí `heartbeat_autosave`, takže přes něj nejde zapsat.

## 29. Úvodní průvodce pro nový web (0.7.0)

Zadání (schváleno 2026-10-25): na zcela novém webu, kde není co migrovat, se průvodce otevře po aktivaci
také a nabídne „startovací balíček“ modulů vhodných pro každý WordPress, rovnou přednastavených.
Když jsou k převzetí cizí pluginy, zůstává migrace (§26) a startovací balíček se nabídne i po ní.

### 29.1 Princip

- **Nic mimo whitelist.** Položky balíčku mají pevné id (`StarterPack::ids()`); REST bere jen id z whitelistu,
  hodnoty čistí schéma nastavení daného modulu.
- **Žádné obcházení hlídání překryvů.** Moduly se zapínají přes `ActivationGuard`, nastavení se ukládají přes
  `BaseModule::save_settings()` (stejná cesta jako `POST /settings/{id}`, takže se provedou vedlejší efekty,
  třeba zápis `.htaccess`). Nastavení, které už dělá cizí plugin (Disable Comments, Limit Login Attempts a jiné),
  se z přednastavení vynechá, ne zdvojí (`Overlaps::blocked_settings()`).
- **Snímek před změnou.** Jedna volba `uxstudio_starter_snapshot`: předchozí seznam modulů a předchozí hodnoty
  voleb všech dotčených modulů. `revert` vrátí přesně toto, vypne zapnuté moduly (spustí se jejich `on_disable`,
  tedy i odstranění bloku v `.htaccess`) a znovu spustí vedlejší efekty u modulů, které zůstaly zapnuté.
- **Přihlašovací adresa ve dvou krocích, nikdy bez záchrany.** Krok A uloží `/administrace` a přihlašovací
  formulář tam funguje, ale `wp-login.php` zůstává otevřený (stav `uxstudio_login_url_pending`). Krok B
  (`onboarding/login-url/confirm`) stav zruší, ověří, že zámek opravdu platí, a pošle e-mail s adresou.
  Nepotvrzený stav po 24 hodinách jen hlásí „expired“, do zablokování nikdy nepřejde.
- **Detekce.** WooCommerce, Cloudflare (`HTTP_CF_CONNECTING_IP`), proxy (`HTTP_X_FORWARDED_FOR`), typ serveru
  (`SERVER_SOFTWARE`), zapisovatelnost `.htaccess`, hezké trvalé odkazy, kolize slugu, logo webu.

### 29.2 Obsah balíčku

Předzaškrtnuto: Security (komentáře, editor souborů, XML-RPC, verze WP, bezpečnostní hlavičky, limit přihlášení
5 pokusů/15 min progresivně, revize 10, Heartbeat pomalu, emoji), e-maily o aktualizacích (nové nastavení),
Skrýt WordPress (admin bar, patička, nápověda, přihlašovací stránka s logem webu, odkazy v `<head>`),
klasický editor + widgety, čisté profily, widgety nástěnky (poznámky, aktivita), duplikace příspěvků + menu,
externí odkazy, AI Markdown, Bot Throttle (120 req/min, Ahrefs a Semrush mimo seznam), optimalizace obrázků
(WebP, kvalita 82), koš médií, stock fotky, skener zranitelností, activity log (90 dní), upozornění na
indexaci, vlastní přihlašovací adresa `/administrace` (poslední, jen jako pending).
Nezaškrtnuto: SMTP (potřebuje údaje), e-mail log (14 dní), přesměrování + 404 log, výměna médií, poslední
přihlášení uživatelů, skrytí admin lišty pro zákazníky, CSP inventura (`audit`, vzorkování 20 %).
Nikdy: vynucené 2FA, IP firewall, blokování zemí, vynucení HTTPS, HSTS, omezení REST API, ochrana přihlášení,
blokování výčtu uživatelů, CSP Enforce, modul Disable Auto Updates.

### 29.3 Nová pravidla překryvů

- `ai-markdown` x llms.txt SEO pluginů (Yoast, Rank Math, All in One SEO): plný překryv jen když je funkce
  cizího pluginu zapnutá (jeho volba, nebo fyzický `llms.txt` v kořeni), jinak jen informace.
  Katalog má nové klíče `foreign_when` (podmínky na volby a soubory cizího pluginu) a `foreign_else` (`partial`|`none`).
- `image-optimizer` x EWWW, Smush, ShortPixel, Imagify, Converter for Media, Optimole: plný překryv;
  LiteSpeed Cache jen při zapnuté optimalizaci obrázků/WebP, Jetpack jen se zapnutým obrazovým CDN (Photon).

### 29.4 Fáze

- [x] F1 Backend: `StarterPack`, `Environment`, `Applier`, REST `uxstudio/v1/onboarding/*` (starter, apply, revert, login-url/confirm), snímek a vrácení
- [x] F1 AutoStart: rozhodnutí `new` (nový web bez čeho migrovat) a příznak `starter` v nápovědě na obrazovce Moduly
- [x] F1 Security: nová nastavení `hide_wp_head_links`, `suppress_update_emails`, stav `pending` přihlašovací adresy
- [x] F1 Nová pravidla překryvů (ai-markdown, image-optimizer) a testy `tests/onboarding-starter.php`, `tests/overlap-catalog.php`
- [x] F1 Překlady nových řetězců PHP (cs_CZ)
- [x] F2 Kontrola verze PHP (kritická zranitelnost / konec podpory; popis v 29.5; průvodce ji čte z `GET uxstudio/v1/security/php-version`)
- [x] F3 UI průvodce: režim `#/wizard?mode=new`, výběr položek, přednastavení slovy, potvrzení přihlašovací adresy, vrácení změn
- [x] F4 Napojení na centrální aplikaci (strana pluginu; strana CA se dělá zvlášť v centrani-app)
- [x] F5 Překlady JS (build `index.dc3d7fb3086a456127fe.js` má svůj JSON v `languages/`)
- [x] F5 Živé QA na čistém webu (vrajt, Apache, bez WooCommerce, bez proxy): aktivace otevře `#/wizard?mode=new`; použito 17 z 17 položek; `/administrace/` ukáže přihlašovací formulář; potvrzení zavře `wp-login.php` (404) a `wp-admin` přesměruje na novou adresu; skutečné přihlášení touto adresou funguje; selhání e-mailu skončí hláškou; vrácení obnoví moduly, nastavení i `wp-login.php`; krok Centrální aplikace: podepsaný POST přijala lokální CA (podpis i živá výzva prošly, CA ukázala přihlášení)
- [x] F5 Opravy z QA: bez loga webu se na přihlášení skryje i logo WordPressu (`form_options` = `hide_login_logo`); po vrácení se z `.htaccess` odstraní celý blok `UX Studio Security` včetně komentářů (`HtaccessWriter::strip_block()`, platí i pro `on_disable` a prázdná pravidla)
- [ ] F5 Živé QA: nginx, Cloudflare, WooCommerce
- [ ] F5 Živě: registrační stránka v CA (čeká na nasazení CA) a produkční `turnstile_provision` v CA
- [ ] F5 Vydání 0.7.0

### 29.5 Kontrola verze PHP (F2)

**Proč.** 2026-10-21 vyšlo php.net bezpečnostní upozornění (CVE-2026-109618 a CVE-2026-109619, vzdálené spuštění kódu
bez přihlášení, CVSS 4.0 9.2), opraveno v 8.5.12, 8.4.27, 8.3.36 a 8.2.35. Web na starší verzi je zranitelný a
majitel to z administrace nepozná. Třídy v `includes/Modules/SecurityOptimization/`:

- `PhpVersionCheck` (čistá logika, bez WordPressu): vestavěná tabulka `BASELINE` (větev -> nejnižší bezpečná verze a
  konec bezpečnostní podpory) a `ADVISORIES` (CVE, adresa, první opravená verze po větvích), `parse()`, `evaluate()`,
  `normalize_remote()`, `merge()`, `snoozed()`, `mail_due()`.
- `PhpVersionMaintenance`: denní WP-Cron `uxstudio_php_version_refresh`, stažení z php.net, uložení, e-mail, `result()`
  a `payload()`.
- `PhpVersionNotice`: widget na nástěnce (nahoře, nejde skrýt) a upozornění na všech obrazovkách administrace.
- `PhpVersionRestController`: `GET uxstudio/v1/security/php-version`.

**Formát API php.net (ověřeno 2026-10-25, bez klíče, JSON).**

- `GET /releases/states` (strojově čitelná podpora větví): `{"8":{"8.4":{"state":"stable","initial_release":"2024-11-21T00:00:00+00:00","active_support_end":"...","security_support_end":"2028-12-31T00:00:00+00:00"},...}}`.
  `state` je `stable`, `security` nebo `eol`. Obsahuje i větve 7.x a 5.6.
- `GET /releases/?json&version=8.4&max=10`: `{"8.4.27":{"announcement":true,"tags":["security"],"date":"21 Oct 2026","source":[...]},"8.4.26":{...},"8.4.25":{"tags":[""],...}}`.
  Bezpečnostní vydání má v `tags` hodnotu `security`, běžné má prázdný řetězec. Bez `max` vrací jediné (nejnovější) vydání
  bez klíče s verzí (`...,"version":"8.4.27"`).
- `GET /releases/active` (a `/releases/active.php`): `{"8":{"8.2":{"version":"8.2.35","tags":["security"],"date":...},...}}`,
  jen poslední vydání podporovaných větví. `GET /releases/?json` je to samé po hlavních verzích (`supported_versions`).
  Pro kontrolu se nepoužívá: poslední vydání nemusí být bezpečnostní, takže se bere `states` (konec podpory) a pro každou
  podporovanou větev `?json&version=<větev>&max=10` (nejvyšší vydání se štítkem `security` = nejnižší bezpečná verze).

**Pravidla rozhodnutí** (`evaluate(PHP_VERSION, tabulka, dnes)` -> `level` ok|warning|critical, `reason`
outdated|unsupported|eol_soon|unknown, `branch`, `current`, `minimum`, `eol`, `days_to_eol`, `cves`, `advisory_url`,
`distro_build`, `recommended`). V tomto pořadí:

1. Nečitelná verze nebo prázdná tabulka: ok, `unknown` (nehádáme).
2. Větev nižší než nejnižší v tabulce (8.1 a starší) nebo po konci podpory (`dnes > eol`): critical, `unsupported`.
3. Větev novější než tabulka (8.6 RC a dál): ok.
4. Verze pod nejnižší bezpečnou verzí větve: critical, `outdated`; CVE a adresa upozornění jen když je pokrývá známé
   upozornění (`fixed` po větvích).
5. Konec podpory do 90 dnů (včetně): warning, `eol_soon`.
6. Jinak ok.

Z `8.1.2-1ubuntu2.20` se bere číselný začátek `8.1.2` a nastaví se `distro_build` (distribuce často opravuje bez změny
čísla, proto je u upozornění věta o zpětných opravách).

**Vzdálená data.** Stahuje jen denní cron při zapnutém modulu Zabezpečení (`wp_safe_remote_get`, timeout 5 s, 128 kB na
odpověď, nejvýš 8 větví, celkem do 20 s). Při chybě zůstanou poslední dobrá data (volba `uxstudio_php_version_remote`)
a vestavěná tabulka. Platí jen to, co projde kontrolou (`^\d+\.\d+\.\d+\z`, větev sedí, patch do 99, platné datum).
Vzdálená data smějí nejnižší bezpečnou verzi jen ZVÝŠIT a konec podpory jen POSUNOUT dřív, nikdy opačně, a nesmí oživit
větev, kterou vestavěná tabulka nezná jako starou. Zcela novou větev (8.6 po vydání) přidají.

**Kde se zobrazuje (jen když `level` není ok).**

- Widget nástěnky (červený/žlutý) a upozornění na všech obrazovkách pro `manage_options`. Upozornění nemá křížek; skryje ho
  jen tlačítko „Ověřeno u hostingu, skrýt na 30 dní“ (nonce, volba `uxstudio_php_version_snooze` s časem a přesnou
  `PHP_VERSION`; jiná verze upozornění vrátí). Widget zůstává.
- E-mail na `admin_email`: jednou, když web zkritickne, pak nejvýš jednou za 7 dní (volba `uxstudio_php_version_mail`,
  zapsaná před odesláním; po návratu z critical se smaže).
- REST: `GET uxstudio/v1/security/php-version` (`manage_options`, bez HTTP požadavku) vrací výše uvedená pole plus
  `php_full`, `checked`, `source` (bundled|php.net), `fetched_at`, `snoozed`. Totéž pole `php_version` (jen výsledek) je
  v `GET security-optimization/scan-status`.

**Bez modulu Zabezpečení.** REST a čistá funkce fungují vždy (průvodce F3 výsledek potřebuje i tam, kde Zabezpečení
ještě není zapnuté; čte vestavěnou tabulku a dříve stažená data). Cron, widget, upozornění a e-mail jsou jen se zapnutým
modulem, vypnutí modulu cron zruší.

**Centrální aplikace.** `SecurityApiController::inventory()` (`?page=security_api&action=inventory`, podpis HMAC) čte z těla
POST pole `php_version` a z něj číselný začátek ukládá do `sites.php_version`. Plugin ho do 0.6.0 neposílal, teď ho
`CentralVulnerabilityClient::report()` přidává (`PHP_VERSION` beze změny) spolu s novým doplňkovým polem
`php_check: {level, reason}` (starší CA ho ignoruje). Odesílá se při každém ručním i plánovaném skenu zranitelností.

**Ověřeno.** `tests/php-version-check.php` (rozhodnutí pro 5.6 až 8.6, hranice 90 dnů a posledního dne podpory, přípony
distribucí, vzdálená data zvyšující / snižující / neplatná, snooze, plán e-mailů). Živě na pobyty (XAMPP PHP 8.4.19):
critical `outdated` se dvěma CVE, `refresh()` proti php.net uloží 8.2 až 8.5, REST 200.

### 29.6 Napojení na centrální aplikaci (F4, strana pluginu)

Třída `Core\Onboarding\CentralApp`, REST `onboarding/ca/prepare` (POST), `onboarding/ca/status` (GET), `onboarding/ca/captcha` (POST).

- **Protokol (ověřeno proti `ConnectController` v CA, 2026-10-25).** Prohlížeč POSTuje na `{adresa CA}/?page=connect_site` pole
  `site_url`, `site_name`, `api_key` (min. 32 znaků), `timestamp` (+-600 s), `site_type=wordpress`, `return_url` (stejný původ
  jako `site_url`, jinak ho CA zahodí), `signature = HMAC-SHA256( site_url|site_name|timestamp|site_type , api_key )`
  (`site_type` je v podepsaném textu, když se posílá). Podpis sám nic neprokazuje, CA proto zavolá podepsaný `ping` webu
  (nejdřív protokol legacy, pak `uxstudio`), takže plugin musí mít Synchronizaci obsahu v režimu node a uložený klíč
  PŘED přesměrováním. Zpět CA připojí `ux1_connected=1` (nebo `ux1_connect_cancelled=1`).
- **prepare**: ověří adresu (https, `http` jen localhost/127.0.0.1/[::1]; bez přihlašovacích údajů, dotazu a fragmentu), odmítne
  centrálu, která už spravuje jiné weby, uloží snímek (společný se startovacím balíčkem, `Applier::snapshot_for`), zapne modul přes
  `ActivationGuard`, nastaví `mode=node`, `central_app_url`, `sso_allow_admin=false`, `node_allow_packages=false`, ponechá nebo
  vytvoří `node_api_key`, ověří vlastní podepsaný ping (hlavička `X-UXS-Selfcheck`, nepočítá se jako ozvání CA) a vrátí
  `{action, fields, ping, warnings, local}`. Klíč je jen v této odpovědi (`Cache-Control: no-store`), do logu nejde.
- **status**: připraveno, kdy se CA naposledy ozvala (`uxstudio_node_last_ping`, zapisuje `NodeController::ping`), stav CAPTCHA.
- **captcha**: `captcha_key_source=ca` + `captcha_enabled` přes uložení nastavení Zabezpečení (klíče získá
  `TurnstileCaProvisioner`); při neúspěchu se CAPTCHA nevynucuje (žádné zamknutí), snímek umožní vrácení.
- Adresa produkční CA: `https://app.ux1.cz/` (výchozí, lze přepsat v rozbalovacím poli "Pokročilé").
- Testy: `tests/onboarding-central-app.php` (podpis proti nezávisle spočítané hodnotě, adresa, původ, pole formuláře).

### 29.7 Vydání 0.7.1 (opravy po nasazení 0.7.0)

- [x] Varování před verzí PHP běží z jádra pluginu (`Core\PhpVersion\Bootstrap`), ne z modulu Zabezpečení: denní cron
  `uxstudio_php_version_refresh` (stejný název háčku jako v 0.7.0, takže při aktualizaci nevznikne druhý), e-mail
  (poprvé při přechodu na kritický stav, pak nejvýše týdně), widget na nástěnce, upozornění na všech obrazovkách
  administrace včetně stránky UX Studia a uzel v liště administrace (na webu i v administraci, jen `manage_options`).
  Rozhodnutí kdo co vidí je čistá funkce `PhpVersionNotice::visibility()` (test v `tests/php-version-check.php`).
  Kritické upozornění nejde zavřít (jen "Ověřeno u hostingu, skrýt na 30 dní", platí i pro uzel v liště); varování
  o konci podpory jde skrýt pro jednoho uživatele (user meta `uxstudio_php_version_dismissed`). Volba "Skrýt upozornění
  v administraci" v Admin Customiseru kritické upozornění neskrývá (`#uxstudio-php-version-notice` je výjimka,
  přesun do zásobníku obchází třída `below-h2`). Zrušení pluginu cron odstraní (`Bootstrap::deactivate()`), vypnutí
  modulu Zabezpečení už ne.
- [x] Startovací balíček: e-mailový log (14 dní), poslední přihlášení a přehled CSP (jen `audit`, vzorek 20 %) jsou
  zaškrtnuté. Přehled CSP se vynechá (`csp_set`), pokud je režim CSP už zapnutý; nikdy se nesníží report-only ani enforce.
- [x] Krok Centrální aplikace v průvodci je prezentace (`src/app/wizard/ca-step.tsx`): devět karet, každá odpovídá
  funkci v kódu centrální aplikace (viz zpráva k vydání), box "Co se na webu změní", otázky, přilepená lišta dole s
  "Připojit" a "Přeskočit". Protokol připojení se nezměnil.
- [x] Poslední krok průvodce: animace odznaku, odstup boxů, ikony modulů v odkazech.
- [x] Sdílené rozvržení formulářů: varianty `uxs-form--stacked` a `uxs-form--inline`, řádek `uxs-form__line` a container
  query (pod 520 px šířky formuláře se řádky skládají pod sebe samy). Inspektor Form Builderu má vlastní segmentované
  záložky uvnitř panelu místo portálu do levého menu.
- [x] Form Builder "Vygenerovat pole pomocí AI": karta s textovým polem, volba vícekrokového formuláře, počítadlo 1000
  znaků (kontrola i na serveru), návrhy; `GET forms/ai-status` hlásí vypnutý modul / chybějící klíč.
- [x] Admin Customiser: `hide_footer_version` (výchozí vypnuto) je součástí položky "Skrýt WordPress".
- [ ] Vydání 0.7.1

### 29.8 Zabezpečení v balíčku pro nový web (0.8.2)

- [x] Položka `security-hardening` zapíná navíc (jen při použití balíčku, hotové weby se nemění): `block_usernames` +
  `block_username_attempts`, `protect_login`, `disable_rss_feeds`, `protect_wp_config`, `disable_directory_listing`,
  `block_sensitive_files`, `block_user_enumeration`, `hide_author_slugs`, `enable_permissions_policy`, `force_https`.
  `canonical_redirect` a `enable_hsts` zůstávají vypnuté záměrně (HSTS drží prohlížeč rok).
- [x] Podmínky (vše v `StarterPack::security_hardening()`, vstupy v `Environment`): uživatelská jména jen s omezením
  pokusů (proxy bez Cloudflare = vynechat vše tři); `block_username_attempts` se vynechá, když existuje účet s
  vyhrazeným jménem (kód `handle_blocked_username_login` blokuje IP i při překlepu hesla u existujícího účtu, kontroluje
  se jen jméno; správné heslo blokaci nespustí); `protect_login` se vynechá, když má někdo Application Password;
  RSS a slugy autorů se vynechají nad 5 publikovaných příspěvků (`BLOG_POSTS_LIMIT`), RSS i při pluginu z
  `FEED_PLUGINS`; `block_user_enumeration` při pluginu z `HEADLESS_PLUGINS`; Permissions-Policy při WooCommerce nebo
  pluginu z `HEADER_PLUGINS`; pravidla `.htaccess` jen na Apache/LiteSpeed se zapisovatelným souborem; `force_https`
  jen když je web i tento požadavek https, běží `http://` a není před ním neznámá proxy.
- [x] `protect_login` už neschovává zprávy o blokaci, CAPTCHA a 2FA (`Module::generic_credential_errors`, filtr
  `wp_login_errors`; řetězcový filtr `login_errors` zůstal jako záloha).
- [x] Pojistka `.htaccess` (`Core\Onboarding\HtaccessGuard`, volaná z `Applier`): kopie souboru před uložením,
  smyčkový požadavek na úvodní stránku a wp-login.php, při 5xx obnova a jeden pokus bez výpisu adresářů, pak vrácení
  všech klíčů; smyčka přesměrování u `force_https` se vrací samostatně. Rozhodnutí jsou čisté funkce (`should_roll_back`,
  `retry_keys`, `redirect_loop`, `https_failed`). Pravidla v `HtaccessWriter` mají Apache 2.4 i 2.2 větev.
- [x] Vysvětlivky voleb (`SettingHelp`, jedno místo pro schéma Zabezpečení a průvodce) a seznam "Vynecháno na tomto webu".
- [x] Katalog překryvů: Disable Feeds, Stop User Enumeration (blokují), Disable Application Passwords (informace).
- [ ] Wordfence, Solid Security a All In One Security se do blokování nepřidaly (jejich volby nejsou ve wp_options
  jednoznačně čitelné); zůstává jen informace.

## 30. Export a import nastavení (0.8.0)

Zadání (2026-10-26): přenést nastavení modulů mezi weby (a mezi vývojem a ostrým webem) souborem, bez ručního
přepisování. Stránka je v Nastavení pluginu, záložka „Export a import“ (`#/settings?section=transfer`, díl
`&part=export|import`); v hlavičce nastavení každého modulu je odkaz „Exportovat toto nastavení“
(`&module=<id>` otevře export s tím modulem zaškrtnutým). Kód je v `includes/Core/Transfer/`, SPA v `src/app/transfer/`.

### 30.1 Princip

- **Whitelist podle schématu, ne výpis volby.** Exportuje se jen to, co deklaruje `settings_schema()` modulu a co
  nepatří na seznam „nikdy“ (§30.3). Klíč souboru, který ve schématu není, se zahodí jako neznámý, klíč ze seznamu
  „nikdy“ se zahodí i když ho někdo do souboru dopsal.
- **Zápis jen přes vlastní cestu modulu.** `BaseModule::save_settings()` (schéma, sanitizace, vedlejší efekty jako
  zápis `.htaccess`), zapnutí modulu přes `ActivationGuard` a `Applier::enable()`. Žádný přímý zápis do voleb.
- **Čisté funkce bez WordPressu**: `Envelope` (sestavení, kanonický JSON, kontrolní součet, kontrola souboru),
  `NonPortable`, `Planner` (whitelist, merge/replace, diff, popisky), `TypeCheck`, `ImportRules`, `Crypto`.
  Testují se samostatně (`tests/transfer-*.php`).
- **Náhled nic nezapisuje; „použít“ plánuje znovu** ze souboru, který dostane, náhledu nevěří.
- **Snímek před zápisem** (`uxstudio_import_snapshot`, 30 dní, jen poslední import): předchozí seznam modulů, volby
  dotčených modulů včetně zašifrovaných voleb s tajnými hodnotami, pomocné volby (čekající přihlašovací adresa, hash
  `.htaccess`, poznámka menu locku, stav CSP) a u obsahu vytvořené položky a předchozí verze upravených.
  „Vrátit import zpět“: nejdřív obsah, pak moduly zapnuté importem zpět vypnout (spustí se `on_disable`), pak volby a
  nakonec `after_migration_switch()` u modulů, které zůstaly zapnuté (znovu se zapíše `.htaccess`).

### 30.2 Formát souboru (`format_version` 1)

JSON v UTF-8, hezky formátovaný, bez XML (přesné typy, žádné atributy, nic spustitelného). Obálka:

```
{ "format": "uxstudio-settings", "format_version": 1, "plugin_version": "0.8.0",
  "exported_at": "2026-10-26T10:00:00+00:00", "site": { "name": "...", "host": "..." },
  "options": { "include_enabled": true, "include_data": false, "has_secrets": false },
  "modules": { "<id>": { "enabled": true, "settings": { ... }, "data": { "<kind>": [ ... ] }, "needs_manual": [ "key" ] } },
  "secrets": null | { "kdf": "argon2id", "v": 1, "opslimit": 3, "memlimit": 67108864, "salt": "..", "nonce": "..", "ciphertext": ".." },
  "checksum": "sha256:<hex kanonického JSON modules + secrets>" }
```

- Kanonický JSON pro součet: klíče objektů seřazené, seznamy v pořadí, prázdné pole vždy `[]`, bez escapování `/` a
  unicode. Nesoulad součtu je jen varování (soubor někdo upravil ručně).
- Verze: novější hlavní verze formátu se odmítne s jasnou zprávou, starší se přijme; soubor z novějšího pluginu dá
  upozornění. Limity: 2 MB, hloubka 32, nikdy `unserialize`, `eval` ani `include`; id modulu `^[a-z0-9][a-z0-9-]*$`.
- Obrázek: `{"__media":{"id":42,"url":"https://…"}}`. Při importu se id ze souboru ignoruje; použije se jen příloha,
  jejíž adresa (nebo stejná cesta pod `uploads` tohoto webu) v knihovně médií existuje, jinak se nastavení nechá
  prázdné a hlásí se.
- Serializátor je izolovaný v `Envelope::encode()` / `Envelope::parse()`, jiný formát by dodal jen tyto dvě funkce.
- Stažení: `POST transfer/export` postaví soubor na serveru a vrátí jednorázovou adresu
  (`admin-post.php?action=uxstudio_transfer_download`, nonce + token + uživatel, pět minut); ta pošle
  `application/json`, `Content-Disposition: attachment; filename="ux-studio-nastaveni-<host>-<RRRR-MM-DD>.json"`,
  `X-Content-Type-Options: nosniff`, `no-store`. Soubory do 256 kB přijdou i jako text pro „Zkopírovat do schránky“.

### 30.3 Registr toho, co se nikdy nepřenáší (`NonPortable`)

Jedno místo: `includes/Core/Transfer/NonPortable.php`.

- `KEYS` (klíče nastavení po modulech): content-sync `mode`, `node_api_key`, `central_app_url`, `hmac_secret`,
  `node_allowed_ips`, `node_default_author`; third-party-login `central_app_url`, `hmac_secret`; bot-throttle
  `central_report_secret`; cron-control `central_allowed_ips`; review-aggregator `central_profile_id`; dns-manager
  `manage_user_ids`; opening-hours `schema_location_ids`, `schema_pages`; ai-markdown `exclude_ids`; maintenance-mode
  `existing_page`, `custom_layout`; security-optimization `captcha_site_key`, `captcha_secret_key`,
  `captcha_key_source`, `existing_page` (klíče CAPTCHA patří k názvu webu, přenos by zamkl přihlášení).
- Obecné pravidlo: klíč, který podle `Mcp\Secrets::is_secret_key()` vypadá jako přihlašovací údaj a není v seznamu
  přenosných tajných hodnot ani v `HARMLESS` (zkontrolované falešné shody: `monthly_token_limit`,
  `sso_token_ttl_seconds`, `bypass_ips`, `pass_query`), se nepřenáší.
- `SECRET_OPTIONS` (jediné, co smí cestovat, a jen zašifrované): smtp-email (heslo, Brevo, Gmail client secret,
  SendGrid, Mailgun, Postmark, SES), stock-photos (6 klíčů), ai-assistant (Claude, OpenAI, DeepSeek),
  security-optimization (`captcha_cf_api_token`), opening-hours (Google Maps, Mapy.cz), instagram-feed
  (`app_secret`), dashboard-widgets (PageSpeed klíč, GA service account JSON).
- `MANUAL_CONNECTIONS` (mimo schéma, vždy do `needs_manual`): Gmail OAuth, přístupový token Instagramu.
- `STATE_OPTION_PATTERNS` (stav, cache, logy, značky, verze DB, tajné volby; do exportu se nedostanou, protože nejsou
  ve schématu): `uxstudio_wizard_*`, `uxstudio_*_pending`, `uxstudio_*snapshot*`, `uxstudio_starter_*`,
  `uxstudio_module_db_versions`, `uxstudio_modules_meta_cache`, `uxstudio_secret_*`, `uxstudio_menu_lock_origin`,
  `uxstudio_rl_*`, `uxstudio_csp_*`, `uxstudio_overlap_*`, `uxstudio_activity_*`, `uxstudio_*_cache|_log|_state|_version`,
  `uxstudio_mcp_*`, `uxstudio_central_*`, `uxstudio_node_*`.
- Zapečetěná hodnota `uxs1:` se nikdy nezapíše do souboru, ať se klíč jmenuje jakkoli.
- Co je jen v `needs_manual`: klíče z těchto seznamů, které mají na zdrojovém webu hodnotu, a tajné hodnoty, když export
  nebyl zašifrovaný.

### 30.4 Šifrování tajných hodnot

Volitelné (výchozí vypnuto), libsodium: klíč z hesla `sodium_crypto_pwhash` (argon2id, náhodná sůl 16 B,
`opslimit` 3, `memlimit` 64 MB), obsah (JSON `modul → klíč → hodnota`) `sodium_crypto_secretbox` s náhodným nonce
(24 B). Blok nese verzi a parametry; při dešifrování se parametry omezí (ochrana před soubory, které žádají obří
paměť). Špatné heslo i poškozený blok = totéž `null` a jedna hláška. Heslo min. 12 znaků + potvrzení, nikdy se
neloguje; pokus o odemčení je omezený (8 chyb / 15 minut na uživatele). `Crypto::available()` vyžaduje skutečné
rozšíření (WordPress má jen čisté PHP náhrady, ty argon2id neumí); bez něj je volba vypnutá s vysvětlením.

### 30.5 Obsah modulů (datové providery)

Rozhraní `DataProvider` (`module()`, `kind()`, `label()`, `capability()`, `blocked_reason()`, `count()`, `export()`,
`plan()`, `import()`, `undo()`); registr `DataProviders` s filtrem `ux_studio/transfer_providers` pro další moduly.
Položky nemají databázová id, počítadla ani osobní údaje; párují se přirozeným klíčem, import jen vytváří a
aktualizuje, nikdy nemaže. Obsah se importuje jen do modulu, který byl zapnutý už na začátku požadavku (vznikly mu
tabulky a typy příspěvků).

- Formuláře (`forms`): definice (název, popis, pole, nastavení, stav), klíč = název. Nikdy odeslání, soubory, log
  akcí ani revize. Bez webhookového tajemství, `logo_id`, `author_id`, `redirect_page_id`; tajemství existujícího
  formuláře se při aktualizaci zachová. Vlastní skript po odeslání odstraní samotný modul uživateli bez
  `unfiltered_html`.
- Přesměrování (`redirects`): pravidla (zdroj, typ, cíl, kód, podmínky, volby, poznámka, pořadí), klíč = typ + zdroj +
  podmínky. Cíl daný id příspěvku se exportuje jako adresa. Zápis přes `Repository::save()` (zamítne rezervované cesty,
  smyčky a nebezpečné regexy). Bez počítadel a 404 logu.
- Code Snippets (`code-snippets`): kód; export i import vyžadují `unfiltered_html` a stejné podmínky jako editor
  snippetů (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, `edit_plugins`). Importovaný snippet je VŽDY vypnutý (i při
  aktualizaci), undo vrátí i původní stav zapnutí.
- Popup Manager (`popup-manager`): texty, spouštěč, cílení na role; stránky cílení jsou id a nepřenášejí se (existující
  popup si je ponechá). Bez statistik.
- Opening Hours (`opening-hours`): pobočky (adresa, telefon, hodiny, výjimky, oznámení, souřadnice). Bez dočasného
  přepsání „dnes zavřeme později“.
- Notice Board (`notice-board`): jen kategorie. Ne oznámení, dokumenty, odběratele.
- Záměrně NEJSOU: odeslání formulářů, logy a statistiky, odběratelé, oznámení s dokumenty, složky médií (visí na id
  příloh), viditelnost menu (visí na id položek menu), IP bany (provozní data), e-mailový log.

### 30.6 Bezpečnostní pravidla importu (`ImportRules`, `Importer`)

Vynucuje server, náhled je ukazuje jako „Bezpečnostní opatření“. Pravidla jen odebírají nebo oslabují hodnotu.

1. `csp_mode` ze souboru nikdy nepřepne na `enforce` (nejvýš `report-only`; už zapnuté `enforce` zůstává); příznak
   `csp_enforce_override` není ve schématu, takže ho soubor nemůže nést; brána `CspGate` zůstává rozhodující.
2. Vlastní přihlašovací adresa: nová adresa projde dvoukrokovým stavem `LoginUrlPending` (spustí se PŘED uložením);
   prázdná nebo „nejednoduchá“ adresa se zahodí. Po importu Zabezpečení se zkontroluje `login_lock_status()`; když je
   zámek v platnosti a nová adresa nevrací přihlašovací formulář (`Applier::probe()`), vrátí se volby Zabezpečení ze
   snímku.
3. Vynucené 2FA (`two_factor_enabled`, role v `two_factor_required_roles`) jen když má importující správce vlastní 2FA.
4. Blokování zemí jen když je IP importujícího správce na allowlistu firewallu (stávajícím nebo z téhož souboru);
   seznamy IP určené k blokování (`BLOCK_IP_LISTS`, dnes prázdný registr) nikdy neponechají řádek s jeho IP.
5. Zámek menu (`menu_lock_enabled`) jen když `MenuSimulator::enforce_access()` nad stávající konfigurací menu nic
   nezahodí (správce zůstane mít UX Studio, Pluginy, Uživatele, Nastavení).
6. Režim údržby nikdy nezapne import bez zvláštní zaškrtávací volby.
7. Kód (pole typu `css`, vlastní CSS údržby, snippety) jen když má importující uživatel `unfiltered_html`.
8. Přesměrování na HTTPS a HSTS jen když import běží přes HTTPS; CAPTCHA na přihlášení jen když má web vlastní klíče.
9. Vlastní přihlašovací jméno se odstraní ze seznamu blokovaných jmen.
10. Klíče z `Sensitive::ADMIN_WRITE` vyžadují správce (`manage_options`), což je podmínka celé funkce; každý modul
    navíc vyžaduje svou `capability()`. Zápis je omezený sdíleným limitem zápisů; export/import trasy jsou
    autentizované, seznam veřejných tras REST se nemění.
11. Zápis do vypnutého modulu jen tam, kde `save_settings()` nemá vedlejší efekty (modul ho nepřepisuje, nebo je v
    `SAFE_WHEN_OFF`); jinak se nastavení přeskočí s vysvětlením (např. zápis `.htaccess` při vypnutém Zabezpečení).
12. Vypnutí modulu, který drží data (provider má položky), jen s volbou „Vypnout i moduly s daty“; data se nikdy
    nemažou.

Typy: `TypeCheck` je přísný (toggle jen `true`/`false`, číslo jen číslo, výběr jen existující volba, multiselect jen
seznam; neexistující volby se vynechají a hlásí, ne přetypují); klíč s chybným typem se odmítne se zprávou.

### 30.7 Stav

- [x] Obálka, kanonický součet, verze, limity (`Envelope`), test `tests/transfer-envelope.php`
- [x] Registr nepřenositelného (`NonPortable`), whitelist podle schématu, média přes adresu
- [x] Šifrování tajných hodnot (`Crypto`), test `tests/transfer-crypto.php` (spouštět s `-d extension=sodium`)
- [x] Přísné typy, merge/replace, diff, maskování (`TypeCheck`, `Planner`), pravidla (`ImportRules`),
  test `tests/transfer-import-rules.php`
- [x] Exportér, importér, snímek, vrácení (`Exporter`, `Importer`, `ImportSnapshot`), REST (`transfer/*`), stažení
- [x] Providery obsahu: formuláře, přesměrování, snippety, popupy, pobočky, kategorie nástěnky
- [x] SPA: export (seznam modulů, volby, šifrování), import (soubor/vložení, náhled s diffem, volby, výsledek, vrácení),
  odkaz „Exportovat toto nastavení“ v hlavičkách modulů
- [x] Test proti WordPressu `tests/transfer-wp.php` (export všeho, žádné tajné hodnoty z databáze v souboru, náhled
  0 změn, kolo na neškodné hodnotě, nepřátelský soubor, Zabezpečení, šifrované tajné hodnoty, providery)
- [ ] Vydání 0.8.0 (verze, tag, vydání na GitHubu)
- [ ] Později: schopnost MCP (`transfer/export` jen bez tajných hodnot a `import` jen s rozsahem `admin`) - záměrně
  zatím NEPŘIDÁNO
- [ ] Později: export složek médií a viditelnosti menu (potřebují mapování id), ZIP s médii

## 31. Historie upozornění v administraci (0.8.1)

Zadání (2026-10-27): „Udělej ještě nějakou samostatnou stránku, kde budou historicky vidět i ty zavřená upozornění.“
Doplněk ke skrytým upozorněním (§20.16): panel pod zvonkem v liště zaznamenává, co zachytil, a stránka **Historie
upozornění** to ukazuje. Stránka je podstránka „Úpravy administrace“ ve skupině Vzhled administrace
(`#/module?id=admin-appearance&feature=admin-customiser&view=notice-history`, záznam v `featureViews.ts`) a odkazuje na
ni patička panelu pod zvonkem („Zobrazit historii“). Kód: `includes/Modules/AdminCustomiser/NoticeHistory/`
(`Rules` čisté funkce, `Store` tabulka, `RestController`), SPA `src/modules/admin-customiser/NoticeHistoryTab.tsx`,
zachytávání v `assets/js/admin-customiser-notices.js`.

### 31.1 Co se zaznamenává

- **Jen to, co panel opravdu zachytí** (stejný výběr jako panel): `.notice`, `.error`, `.updated`, `.update-nag`, bez
  `#message`, `.below-h2`, `.notice-success` (průběžné „Nastavení uloženo“), `.settings-error` / `#setting-error-*`,
  bez věcí v liště a bez vnořených upozornění. Kritické upozornění na verzi PHP (`#uxstudio-php-version-notice`, 0.7.1)
  se nepřesouvá do panelu a server ho navíc odmítne zapsat, i kdyby přišlo.
- **Jen když je skrývání zapnuté a upozornění se přesouvají do panelu** (`hide_notices_enabled` a
  `hide_notices_relocate`). Při vypnutí se nic nezaznamenává (REST odpoví `recorded: 0`) a stránka ukáže vysvětlení s
  tlačítkem „Otevřít nastavení“. Starší záznamy zůstanou čitelné.
- **Jen správci** (`manage_options`): skript dostane adresu REST, nonce a seznam skrytých upozornění jen od správce;
  texty mohou obsahovat jména a e-maily, proto se nikdy nevypisují na front endu, neposílají jinam a nejsou součástí
  exportu nastavení (tabulka není ve schématu; `uxstudio_admin_notices*` je na seznamu stavových voleb `NonPortable`).
- Zachycení: panel po 2,2 s od načtení pošle **jedním** požadavkem (`POST admin-notices/log`) všechna upozornění,
  která v něm leží (`pagehide` ho pošle dřív, když stránku opustíte); upozornění, která přijdou později, se v té
  stránce nezapíšou. Každé upozornění se v jednom načtení stránky počítá jednou (`seen_count`).

### 31.2 Tabulka `uxstudio_admin_notices`

Vytvoří se líně přes `DB::ensure_module_tables('admin-customiser', 1, …)` (dbDelta, verze se zapíše až po úspěchu;
když verze přežije zmizelou tabulku, vytvoří se znovu). Jeden řádek na upozornění webu (ne na uživatele):
`id`, `notice_key` CHAR(40) UNIQUE, `client_key`, `type`, `title`, `text`, `source_slug`, `source_name`, `source_how`,
`screen`, `dismissible`, `first_seen`, `last_seen`, `seen_count`, `reappeared_count`, `state`
(`active`|`dismissed`|`gone`), `dismissed_at`, `dismissed_by`, `dismiss_method` (`panel`|`plugin`), `first_seen_user`.
Časy v čase webu jako ostatní tabulky.

- **Klíč** = `sha1(typ | text bez čísel)`: malá písmena, každá řada číslic se sloučí na `#`, mezery sloučené, prvních
  240 znaků; titulek se hashuje spolu s textem. „3 aktualizace“ a „12 aktualizací“ je tedy totéž upozornění.
- **Text** se na serveru čistí znovu (tagy, skripty, entity, řídicí znaky, mezery), max 2000 znaků, titulek 160.
- **Stavy**: nové = `active`; znovu spatřené `gone` nebo `dismissed` (zavřené pluginem) = zpět `active` a
  `reappeared_count`+1; zavřené křížkem = `dismissed`. `gone` se nenastaví hned, jen úklidem: aktivní upozornění,
  které 7 dní nikdo neviděl (`uxstudio_admin_notices_gone_days`), je „zmizelé“.
- **Úklid** (bez cronu, oportunisticky při zápisu nebo výpisu, nejvýš co 6 hodin): `gone` po 7 dnech, smazání řádků,
  které se neobjevily 90 dní (`uxstudio_admin_notices_retention_days`), a strop 1000 řádků (zůstanou nejnovější podle
  `last_seen`). Čistá logika je v `Rules::gone_ids()` a `Rules::purge_ids()`. Cron se nezakládá, takže není co rušit.
- Žádnou cestu „uninstall“ plugin nemá; data smaže tlačítko „Vymazat celou historii“ (`DELETE admin-notices`).

### 31.3 Zavření a „Znovu zobrazit“

- Křížek karty: je-li v upozornění vlastní ovládání (core `.notice-dismiss`, Elementor, Freemius `.fs-close`, „Skrýt“),
  spustí se a historie zapíše `dismiss_method = plugin` (plugin si zavření pamatuje sám, odsud nejde vrátit; ukáže se
  „Zavřeno pluginem, nelze obnovit“). Bez vlastního ovládání panel upozornění jen skryje, zapíše `panel` a **pamatuje
  si to**: texty takto zavřených upozornění (`client_key`, nejvýš 100) jdou správci do skriptu a upozornění se v panelu
  už neukáže. „Znovu zobrazit v panelu“ (`POST admin-notices/{id}/reshow`, jen pro `panel`) značku zruší, upozornění se
  v panelu objeví při příštím načtení. Klik na vlastní ovládání pluginu v kartě se zapíše také jako `plugin`.
- Zavření dorazí na server i dřív než záznam (řádek se vytvoří při zavření).

### 31.4 Odhad zdroje (`Rules::guess_source`, vždy jen odhad)

Pořadí: vlastní upozornění UX Studia (třída/id `uxstudio`/`uxs`, titulek „UX Studio…“) → jádro WordPressu (věta
„WordPress X is available“ česky i anglicky, nebo `update-nag` s textem začínajícím WordPress) → `data-plugin` /
`data-slug` (adresář pluginu, i když není aktivní) → Freemius `fs-slug-<slug>` → otisky tříd známých pluginů (Elementor
`e-notice`, UpdraftPlus, Wordfence, Yoast, Rank Math, AIOSEO, WooCommerce, ACF, Jetpack, Akismet, CF7, WPForms,
LiteSpeed, WP Rocket, WP Mail SMTP) → titulek shodný s názvem aktivního pluginu → název aktivního pluginu jako celé
slovo v textu (nejdelší shoda). Slug se převede na čitelný název podle aktivních pluginů (`get_plugins()`), jinak
„Neznámý zdroj“ (prázdný slug). UI u zdroje píše „odhad“ a vysvětlení v bublině.

### 31.5 REST (`uxstudio/v1`, vše `manage_options`, schémata argumentů)

`POST admin-notices/log` (max 40 položek, tělo max 200 kB, 40 požadavků za minutu na uživatele, mimo sdílený rozpočet
zápisů), `POST admin-notices/dismiss`, `GET admin-notices` (stav, typ, zdroj, období, hledání, strana, 20 na stranu),
`GET admin-notices/stats`, `GET admin-notices/export.csv` (nonce `_wpnonce`, bezpečné proti vzorcům jako `Core\Csv`),
`DELETE admin-notices/{id}`, `POST admin-notices/bulk-delete` (max 200), `DELETE admin-notices`,
`POST admin-notices/{id}/reshow`. SQL jen přes `$wpdb->prepare`, výstup escapuje React.

### 31.6 Stav

- [x] Čisté funkce `Rules` (klíč, čištění textu, odhad zdroje, stavy, úklid, CSV, filtry), test `tests/notice-history.php`
- [x] Tabulka `uxstudio_admin_notices` (dbDelta, oprava zmizelé tabulky), `Store`
- [x] REST (zachycení, výpis, statistiky, mazání, hromadné mazání, vymazání všeho, znovu zobrazit, CSV)
- [x] Zachytávání v panelu (jedna dávka na načtení, zavření křížkem, skrytí zavřených panelem, patička „Zobrazit historii“)
- [x] SPA stránka „Historie upozornění“ (filtry, hledání, stránkování, hromadné akce, export, prázdné/chybové stavy)
- [x] Test proti WordPressu `tests/notice-history-wp.php`, čeština, QA v prohlížeči se syntetickými upozorněními
- [ ] Vydání 0.8.1 (verze, tag, vydání na GitHubu)
- [ ] Později: zachytit i upozornění, která přijdou po 2,2 s od načtení (druhá dávka), a česky popsat „Zavřeno přes“ v CSV
- [ ] Později: stránka pro uživatele s nižším oprávněním než správce (teď jen správci)

## 32. Štít UX Studia - CAPTCHA bez klíčů a CAPTCHA formulářů přes centrální aplikaci (0.8.3)

Kód: `SecurityOptimization/CaptchaShield.php` (protokol, čisté funkce), `CaptchaResolver.php` (pořadí poskytovatelů),
`ShieldRuntime.php` (tajný klíč, jednorázové úložiště, REST, widget), `CaptchaVerifier.php` (config místo Module),
`Forms/FormsCaptcha.php`, `assets/js/shield.js` + `assets/css/shield.css` (min přes `npm run build:widget`), SPA
`src/modules/forms/CaptchaInspector.tsx`, `TopicSettings.showField`, `StarterPack` položka `captcha-shield`.

### 32.1 Protokol (podle otevřeného konceptu ALTCHA, vlastní implementace)

- `GET uxstudio/v1/shield/challenge` -> `{algorithm:'SHA-256', salt(32 hex), number_max, challenge=sha256(salt.number), issued(ms), expires(s, +300), signature}`; `signature = HMAC-SHA256(secret, 'v1|alg|challenge|salt|number_max|issued|expires')`. Tajný klíč = HMAC(náhodná volba `uxstudio_shield_secret` bez autoloadu, `wp_salt('auth')`), nikdy se nevrací.
- Odpověď prohlížeče: base64 JSON `{algorithm, challenge, number, salt, signature, issued, expires, number_max}`. Server ověří: podpis (`hash_equals`), platnost, minimální dobu od vydání (výchozí 1,2 s, nastavení 0 až 10 s; stránka si počká sama), `sha256(salt.number)`, a až nakonec spálí (`INSERT IGNORE` do `wp_options`, `_uxs_shield_used_<hash>`, bez autoloadu; úklid po 1/25 zápisů). Odmítnutá odpověď nespálí výzvu.
- Obtížnost: Lehká/Střední/Těžká = 50k/150k/500k; po neúspěšných přihlášeních z IP (práh `captcha_adaptive_threshold`, 15 min) o stupeň těžší.
- Honeypot `uxs_trap`; pole odpovědi `uxstudio_shield` (u formulářů `uxs_shield_f<ID>`).
- SHA-256 v čistém JS po jednom bloku (Web Crypto `subtle.digest` je async po každém hashi, změřeno 24 tis. proti 1 mil. hashů/s, a na http chybí); worker = tentýž soubor.

### 32.2 Bez JavaScriptu a při selhání (nesmí zamknout správce)

Odpověď JS chybí -> jen potom se zkouší napsaný součet (`uxstudio_shield_nojs` + podepsaný token, otázka z nonce+tajemství, min. 3 s, platnost 6 h, jednorázový, limit 8/min na IP). Platí pro přihlášení, registraci, obnovu hesla, bránu a WooCommerce účet; ne pro komentáře (cache stránek) a Formuláře (potřebují JS). Špatná JS odpověď na součet nepřepadá. Selže-li výzva nebo výpočet, widget ukáže otázku; je-li skript zablokovaný, otázka se ukáže po 4 s (CSS), bez JS hned.

### 32.3 Pořadí poskytovatele

`auto`: klíče z CA (jen při připojení, `node_api_key` + `central_app_url`) > vlastní klíče > Štít. Klíče z CA po ztrátě spojení platí dál jako vlastní. Formuláře: totéž vždy, bez ohledu na `captcha_enabled`. Výchozí `captcha_provider`: `auto` jen když volba modulu nemá žádné `captcha_*`, jinak `turnstile` (stávající weby se nemění). Klíče jedním úložištěm (`Module::store_captcha_keys`, `Security::store_secret`); tlačítko nikdy nepřepíše vlastní klíče.

### 32.4 Centrální aplikace (jen čteno, beze změny)

`TurnstileProvisionController` vydává JEDEN sdílený `managed` widget pro celou flotilu; web se přidá do seznamu domén podle autentizovaného `site_url`. Stejný pár klíčů je proto použitelný pro přihlášení i formuláře (stejná doména, hostname se ověřuje u Cloudflare), změna není nutná. Pozor: limit 5 registrací/hod/web; nezná `action`, takže Turnstile nerozliší přihlášení a formulář. Volitelně pro CA: endpoint `action=status` bez registrace a samostatný widget/akci pro formuláře, pokud bude potřeba statistika zvlášť.

### 32.5 Starter pack

Položka `captcha-shield` (přihlášení, registrace, obnova hesla, komentáře když je balíček nevypíná; `auto`, vložené umístění). Vynechá se: CAPTCHA už zapnutá/klíče (`captcha_set`), plugin s CAPTCHA (katalog překryvů, přidáno 5 pluginů), proxy bez Cloudflare (`captcha_proxy`, stejné pravidlo jako limit přihlášení). Vysvětlení přes `SettingHelp` (`captcha_enabled`, `captcha_provider`).

### 32.6 Stav

- [x] Protokol, JS, CSS, REST, widget, ověření, jednorázové úložiště
- [x] Login, registrace, obnova hesla, brána, komentáře, WooCommerce, pole formuláře
- [x] Náhradní cesta bez JS, test v prohlížeči (JS vypnuté, skript zablokovaný, REST zablokované)
- [x] Nastavení (skrytá pole, info box), inspektor pole CAPTCHA, položka starter packu
- [x] Testy `captcha-shield.php`, `captcha-shield-wp.php`, `captcha-shield.mjs`, `rest-public-routes.php`
- [ ] Vyzkoušet na pomalém telefonu (těžká obtížnost naměřena 2,5 až 3,4 s při simulovaném zpomalení CPU 4x až 6x)
- [ ] Reálné klíče z CA (v testu mockované HTTP)
- [ ] Blokové pokladny WooCommerce nemají kam vložit widget (jako dosud)

## 33. Skrytí widgetů nástěnky jako tabulka (0.8.4)

Záložka „Skrytí widgetů“ modulu Dashboard Widgets byla spínač „skrýt vše“, výběr „skrýt konkrétní“ a pro každou roli další
výběr. Je z toho jedna tabulka všech widgetů, které nástěnka na webu zná. U každého jde zapnout „Skrýt“ (pro všechny) a
„Zobrazit pro“ (role). Výchozí stav je „Všichni“, tedy žádné omezení. Spínač „Skrýt všechny widgety nástěnky“ a uvítací
panel zůstaly, kde byly.

### 33.1 Datový model

Nastavení `widget_rules` v řádku `uxstudio_dashboard_widgets` (typ pole `widget_rules`, `managed`: obecný formulář ho nekreslí
a při uložení neposílá zpět):

```json
{ "<id widgetu>": { "hidden": true, "roles": ["administrator", "editor"] } }
```

- `hidden`: widget nevidí nikdo (ani správce, žádný obchvat pro super adminy; widgety nástěnky nejsou kritické).
- `roles` neprázdné: widget vidí jen uživatel, který má alespoň jednu z těch rolí (více rolí = stačí jedna shoda).
  Uživatel bez role seznam rolí nesplní.
- Záznam s výchozími hodnotami (neskryto, všechny role) se neukládá. Sada rolí, která pokrývá všechny role webu, se uloží
  jako „všichni“ (prázdný seznam). Při zapnutém „hide all“ se pravidla nepoužijí; zůstanou jen widgety UX Studia (id
  `uxstudio_*`, widgety ostatních modulů), stejně jako dřív.
- Čisté funkce jsou v `WidgetRules` (bez WordPressu): `visible`, `hidden_for`, `sanitize`, `set_items`, `apply_bulk`,
  `from_legacy`, `legacy_hash`, `merge_snapshot`, `is_stale`, `needs_write`, `classify_path`. Test `tests/dashboard-widget-rules.php`.
- Meze: id `[A-Za-z0-9_\-:.]` nejvýš 120 znaků, nejvýš 300 záznamů, nejvýš 60 rolí na záznam.

### 33.2 Převod ze starého formátu (jednou, idempotentně)

Starý stav: `hidden_widgets` (skryté pro všechny) a `hidden_widgets_<role>` (skryté pro roli). `Module::maybe_migrate()` běží na
`init` (priorita 1) a po uložení souboru s nastavením (`save_settings`):

- id ve `hidden_widgets` -> `hidden: true`;
- widget skrytý aspoň jedné roli -> `roles` = všechny role webu minus role, které ho skrývají; vyjde-li prázdno (skrývají ho
  všechny role) -> `hidden: true`. Uživatel s více rolemi dřív widget neviděl jen když ho skrývala každá jeho role, což je přesně
  „vidí ho, má-li aspoň jednu povolenou roli“ (test porovnává všechny kombinace rolí se starým pravidlem);
- role, které web nezná, se přeskočí; globální skrytí přebije seznam rolí;
- idempotence: otisk starých dat (`legacy_hash`) se uloží do řádku nastavení. Stejná stará data se podruhé nepřevádějí (uživatel
  mezitím upravil tabulku, staré seznamy ji nesmí přepsat). Jiná stará data (starší soubor, migrační zdroj) se převedou znovu a
  sloučí přes existující pravidla. Bez starých dat se zapíše jen `rules_version`;
- staré klíče se nemažou (čitelné jedno vydání) a nepoužívají se. V schématu jsou s příznakem `legacy` a `managed`: obecný
  formulář je nekreslí ani neposílá, exportér je nevypisuje, import starého souboru je přijme a převede;
- jednorázové upozornění „Nastavení bylo převedeno z původního formátu“ (`rules_notice`), zavře se tlačítkem „Rozumím“.

Důsledek, o kterém je dobré vědět: seznam povolených rolí je výčet. Role založená později (třeba nový plugin) ve výčtu není, takže
převedený widget neuvidí, dokud ji správce v tabulce nepřidá. V tabulce se dlouhý výčet zobrazuje jako „Všichni kromě: …“.

### 33.3 Snímek widgetů a obnovení

- `WidgetSnapshot::capture()` běží na `wp_dashboard_setup` s prioritou 9990, tedy po všech widgetech tohoto pluginu (1000, 1001)
  i cizích a před odstraněním podle pravidel (9991). Snímek vzniká jen při zobrazení nástěnky uživatelem s `manage_options`
  (jinak by pohled redaktora označil správcovské widgety za zmizelé). Nástěnka WordPressu „Vítejte“ není meta box, ale akce
  `welcome_panel`: do snímku se přidá jako `dashboard_welcome`.
- Volba `uxstudio_dashboard_widget_snapshot` (bez autoloadu): `scan_at` a `widgets[id]` = `title`, `context`, `priority`, `source`,
  `source_name`, `guess`, `first_seen`, `last_seen`. Zápis jen při změně nebo po 6 hodinách (jinak se mění jen časy). Tlačítko
  „Obnovit seznam“ vynutí zápis (`POST refresh` nastaví transient na 10 minut, SPA načte `index.php` na pozadí s cookies správce).
- Widget s `last_seen` starším než `scan_at` je „nenalezeno“ (ztlumený řádek, pravidlo zůstává). Smazat jde jen takový záznam
  (i s pravidlem), živý widget by se vrátil (409).
- Zdroj: `WidgetSnapshot::source()` zjistí soubor callbacku reflexí a `WidgetRules::classify_path()` ho zařadí
  (jádro, UX Studio, plugin podle složky a názvu z `get_plugins()`, motiv, MU plugin). Všechno mimo jádro a UX Studio se vypisuje
  jako „odhad“ (callback může ležet ve sdílené knihovně).
- Widgety, které z nástěnky odstraní jiné nastavení dřív (Zabezpečení: novinky a stav webu, vypnutý přepínač widgetu jiného modulu),
  se do snímku nedostanou, protože už nejsou registrované. Uvítací panel s vlastní záložkou (`uxstudio_welcome_panel`) se nevypisuje.
- Odstranění: `Module::apply_rules()` na 9991 a ještě jednou na `do_meta_boxes` (widget zaregistrovaný ještě později).
  Nástěnka sítě (`wp_network_dashboard_setup`) a uživatelská nástěnka (`wp_user_dashboard_setup`) se neřeší, jako dřív.

### 33.4 REST (`uxstudio/v1`, `manage_options`, schémata argumentů, 60 zápisů za minutu na uživatele)

- `GET dashboard-widgets/rules`: `rules`, `widgets` (řádky se `stale`), `roles`, `scan_at`, `notice`, `index`.
- `POST dashboard-widgets/rules`: `items` [{id, hidden?, roles?}], `action` (`hide`, `show`, `roles`, `reset`, `replace`,
  `dismiss_notice`) s `ids` a `roles`; `replace` vrací celou mapu zpět (Vrátit změny). Role se ověřují proti `wp_roles()`,
  nejvýš 300 položek, id podle vzoru.
- `POST dashboard-widgets/refresh`, `DELETE dashboard-widgets/snapshot/{id}`.

### 33.5 Export a import

Pole `widget_rules` je v whitelistu podle schématu. `TypeCheck` ho přijme jen jako mapu záznamů s `hidden` typu bool a `roles`
jako seznamem, víc než 300 záznamů odmítne, neznámé role a nepovolená id zahodí a počet ukáže jako varování v náhledu. Exportér
starých klíčů (`legacy`) nevypisuje. Import starého souboru jde stejnou cestou převodu (33.2). Modul je ve `SAFE_WHEN_OFF`: jeho
`save_settings` jen ukládá hodnoty, import tedy funguje i při vypnutém modulu.

### 33.6 Obrazovka

`src/modules/dashboard-widgets/WidgetTable.tsx`: vyhledávání, filtr (Všechny, Skryté, Omezené na role, Nenalezeno), filtr zdroje,
počítadla, „naposledy obnoveno“, lepicí záhlaví tabulky, role jako malé čipy ve vyskakovacím seznamu (Escape ho jen zavře),
lepicí lišta hromadných akcí (Skrýt, Zobrazit, Zobrazit pro + Použít, Obnovit výchozí, Vybrat vše, Zrušit výběr), na úzké obrazovce
karty. Každá změna se ukáže hned (optimisticky) a uloží se na pozadí s toastem; „Vrátit změny“ vrátí pravidla, jak byla při otevření.

### 33.7 Stav

- [x] Datový model a čisté funkce `WidgetRules`, test `tests/dashboard-widget-rules.php`
- [x] Snímek registrovaných widgetů, zdroj podle souboru callbacku, obnovení, stav „nenalezeno“
- [x] Odstranění podle pravidel (správce proti redaktorovi), „hide all“, pozdě registrované widgety
- [x] Převod ze starého formátu s otiskem dat, banner, starý soubor při importu
- [x] REST (pravidla, hromadné akce, replace, refresh, smazání záznamu)
- [x] Export a import `widget_rules` (typ pole, TypeCheck, Planner, NonPortable `legacy`)
- [x] Tabulka v SPA (světlý i tmavý režim, úzké karty, klávesnice), čeština
- [x] Test proti WordPressu `tests/dashboard-widget-rules-wp.php`, QA v prohlížeči na pobyty
- [ ] Vydání 0.8.4 (verze, tag, vydání na GitHubu)
- [ ] Později: nástěnka sítě a uživatelská nástěnka, pravidla pro konkrétního uživatele (ne jen roli)
- [ ] Později: po zavedení nové role nabídnout „přidat do všech převedených pravidel“

## 34. Skrytí Rocket Insights z WP Rocketu (0.8.7)

Úpravy administrace, sekce Obrazovky administrace, přepínač `hide_rocket_insights` (výchozí vypnuto). Požadavek: „zakázat Rocket Insights“.

### 34.1 Výzkum (WP Rocket 3.23.5.1, shoduje se s verzí na chalupyroubal.cz)

Rocket Insights je ve zdrojích `inc/Engine/Admin/RocketInsights/`. Všechny vstupy do administrace se ptají jedné třídy `Context\Context::is_allowed()`
(a `SaasContext`), a ta volá oficiální filtr **`rocket_rocket_insights_enabled`** (boolean, `apply_filters` bez předpony). Za ním je:
záložka a obsah v nastavení (`Settings\Page::rocket_insights_section`), widget skóre v postranním panelu a na přehledu, widget doporučení, sloupec
`rocket_insights` v seznamech (`PostListing\Subscriber::can_display_column`), aktivační upozornění, hláška po uložení nastavení, REST routy,
stav licence na přehledu a plánování testů (`schedule_jobs`, `maybe_add_homepage_automatically` při vypnutí rušení úkolů). Jiný oficiální vypínač
(konstanta, volba) neexistuje. `rocket_insights_settings_enabled` jen zešedí pole Sledování výkonu, ne celou funkci.

### 34.2 Řešení

`includes/Modules/AdminCustomiser/HideRocketInsights.php`: filtr s prioritou 99 vrací false a jako pojistka se na obrazovce seznamu obsahu
odstraní klíč sloupce z `manage_{typ}_posts_columns` a `manage_edit-{typ}_sortable_columns` na `PHP_INT_MAX`. Žádné CSS ani JS, takže změna názvů
tříd ve WP Rocketu nic nerozbije. Důsledek: filtr platí i mimo administraci (cron, REST), takže se nespouštějí ani nové naplánované testy.
Už uložené výsledky WP Rocket nemaže. Test `tests/hide-rocket-insights.php`.

### 34.3 Stav

- [x] Výzkum zdrojů a ověření verze na živém webu
- [x] Přepínač, filtr a pojistka sloupce, test, český překlad
- [x] QA na syntetické stránce (pobyty bez WP Rocketu)
- [ ] Vydání 0.8.7 (verze, tag, vydání na GitHubu)
- [ ] Ověřit na živém webu s WP Rocketem po nasazení

## 35. Logo na přihlašovací stránce: balíček a split (0.8.8)

Nález z chalupyroubal.cz: `login_logo` = logo šablony, které uživatel nezadal, a v rozvržení split bylo logo vykreslené dvakrát.

### 35.1 Řešení

- `StarterPack::login_already_customised( array )` (čistá funkce, `tests/login-logo.php`): true, když je cokoli z: `login_customization_enabled`,
  `login_style` jiný než `classic`, `login_logo`, `login_background_image`, `login_split_media_image` > 0, split adresa videa nebo YouTube,
  `login_custom_css`, `login_message`, jakákoli `login_*_color`. `Environment` předává aktuální `login_*` nastavení v klíči `login_settings`.
- Položka „Skrýt WordPress“: při „už má vlastní vzhled“ nesahá na žádný klíč přihlášení (ani `login_customization_enabled`, `form_options`), zbytek
  (admin lišta, patička, nápověda, verze, hlavička) se použije; info řádek v seznamu a ve výsledku. Na čisté stránce zůstává logo webu, ale
  výslovně: řádek „Logo webu na přihlašovací stránce“ v „Co se nastaví“ (`Applier::login_logo_row`) a poznámka ve výsledku s návodem na odebrání.
- `Login::brand_icon_logo_url()`: je-li logo přes panel s médiem, kulatý odznak nad formulářem ho neopakuje (výchozí ikona). Klasické rozvržení beze změny.
- `MediaField` (SettingsForm) ukazuje náhled obrázku (`wp.media.attachment`), takže ID nastavené mimo formulář není „prázdné“.
- Existující weby se nemigrují (logo si uživatel odebere v nastavení).

### 35.2 Stav

- [x] Pravidlo, položka balíčku, odznak, náhled v nastavení, testy, překlad, QA na pobyty
- [ ] Vydání 0.8.8 (verze, tag)
- [ ] Ověřit na chalupyroubal.cz po aktualizaci (logo jen jednou; logo z balíčku si tam uživatel odebere sám)
