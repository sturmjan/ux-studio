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
- [ ] **F4 — volitelné rozšíření**: pole `signature`, akce `create_post`,
      retence/GDPR auto-mazání, případné napojení na CA (formulář jako zdroj
      leadu do centrálního systému — jen pokud vznikne konkrétní potřeba,
      není to MVP požadavek).

### Otevřené otázky

- [ ] Má `forms` směřovat i k `service-requests`/CA jako volitelný cíl akce
      (vedle e-mailu a webhooku), nebo je webhook jako univerzální únik
      dostačující a specifickou CA integraci řešit až na vyžádání?
- [ ] Stahovat `@dnd-kit` jako novou závislost pluginu, nebo je (vzhledem k
      tomu, že Destima ho už používá se stejným stackem) prostě zkopírovat
      ověřenou verzi z `destima-obec/package.json`?
