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
