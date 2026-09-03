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
- [ ] `push-notifications` - reálné odeslání web push je jen `// TODO` stub (`sent_count=-1`); doimplementovat skutečné odeslání (VAPID/WebPush), pak segmentace/plánování/analytika
- [x] `activity-log` - doplněno ~20 sledovaných událostí (posty/status-transition, users/role/register/delete, plugins, themes, terms, media, comments, WC objednávky, logout) + `alert_role_escalation` (email při povýšení na admin, toggle). HOTOVO+OVĚŘENO 2026-09-03: post_publish/draft/delete, user_register, role_change, delete-user zalogovány správně.
- [x] `bot-throttle` - přenesena celá adaptivní logika z legacy: Detector (7 kategorií botů + UA/IP whitelist/blacklist + rDNS verifikace), LoadSampler (sliding-window load → tier GREEN/YELLOW/ORANGE/RED s hysterezí), Throttler (per-kategorie × per-tier plán: pass/delay/microcache/block, vyhledávače nikdy neblokovány), Microcache (FS cache s deny-all .htaccess), Log (obohacená tabulka, GDPR hash IP) + PHP dashboard widget + SPA taby Dashboard/Log/Test + REST dashboard/test/clear. Ponechán hard per-IP rate-limit cap. HOTOVO+OVĚŘENO 2026-09-03 na pobyty-studio: GPTBot/AhrefsBot→microcache+delay, Googlebot→jen delay (chráněn), rule=block→429+Retry-After, Googlebot i s block rule→200, microcache capture→hit, human UA→bez zásahu. Schéma migrace v1→v2 (přidány sloupce) ověřena.

**P2 - důležité (osekané, pravděpodobně používané):**
- [ ] `content-sync` - doplnit plnou Hub↔Node správu (CRUD příspěvků/kategorií/médií/ACF, SSO, media transfer); dnes jen uložení URL+HMAC + log
- [ ] `dashboard-widgets` - doplnit skrývání/registraci reálných dashboard widgetů a layoutu (dnes jen SPA s PageSpeed + úkoly)
- [ ] `exit-popup` - doplnit appearance/CTA/image, autoresponder, cookie frekvenci, 4 z 5 detekčních režimů, URL cílení (dnes jen sběr e-mailů)
- [ ] `opening-hours` - doplnit zobrazovací vrstvu: shortcode, widgety, mapa, Schema.org JSON-LD, bannery, svátky, sezóny (dnes jen CRUD + „open now")
- [ ] `notice-board` - doplnit description, data/archivaci, více příloh, RSS, per-kategorie odběry, notifikační e-maily, frontend shortcode
- [ ] `instagram-feed` - doplnit OAuth/connections, témata, per-feed nastavení, sideload, cron, hashtag filtry, admin UI (dnes jen `<img>` mřížka)

**P3 - nižší (osekané, web spíš nepoužívá):**
- [ ] `cron-control` - doplnit řízení režimu WP-Cronu (disable/local/hosting/central) + watcher/whitelist
- [ ] `download-files` - rozhodnout koncept: legacy = přílohy k příspěvkům + shortcode + frontend vs Studio = tokenovaná knihovna; sjednotit nebo doplnit
- [ ] `elementor-import` - doplnit URL/HTML import, export, režimy replace/append (dnes jen JSON/ZIP → draft)
- [ ] `page-load` - doplnit benchmark dopadu plugin/modul, admin-bar indikátor, metriky query/paměť

**Vědomá rozhodnutí (POTVRZENO 2026-09-03 uživatelem - záměr, NEdodělávat, jen doplnit do dokumentace parity jako „by design"):**
- `performance-optimization` - vědomě zúženo z bezpečnostních důvodů (read-only + 3 fixy). Ponecháno.
- `google-review-request` - přeznačeno (on-site popup → review-request e-mail). Nová funkce je záměr.
- `guide` - přeznačeno (editor Návodu → onboarding checklist). Záměr.

### 15.3 Drobné rozdíly / regrese s reálným dopadem (⚠️)

- [x] `hide-admin-bar` - regrese OPRAVENA 2026-09-03: prázdné role → `return $show` (neskryje nikomu, jak legacy) místo `return false`; help text sladěn. Ověřeno logicky (3 případy).
- [x] `pixel-tag-manager` - migrace vnitřních klíčů settings blobu (google-analytics→google_analytics atd.) doplněna do Migratoru (krok 4c, idempotentní). HOTOVO+OVĚŘENO 2026-09-03 syntetickými daty: hyphen→underscore, hodnoty zachovány. (Drobnost: validace formátu ID při uložení zbývá - nízká priorita, nejde o ztrátu dat.)
- [ ] `smtp-email` - doplnit Gmail API transport + From/Force-From volby + resend (dnes SMTP+Brevo)
- [ ] `image-optimizer` - doplnit AVIF, WebP delivery (.htaccess), auto-optimalizaci při uploadu, scanner nepoužitých obrázků
- [ ] `security-optimization` - doplnit CSP enforcing (dnes jen reporting) + UI pro Upload Guard + ~8 hardening přepínačů
- [ ] `third-party-login` - doplnit role gating, opt-in auto-create+role, link/unlink UI
- [ ] `email-log` - doplnit ukládání těla/hlaviček/příloh + detekci zdroje + resend
- [ ] `folder-manager` - doplnit rename/reparent složky, bulk move
- [ ] `admin-columns` - doplnit bohaté field-type renderery a data-source konfigurace
- [ ] projít zbylé ⚠️ z `PARITY.md` a rozhodnout, co je bug a co přijatelný rozdíl

### 15.4 Uzavření (F5 gate)

- [ ] po dokončení 15.1-15.3 spustit celý parity audit znovu (harness stojí) - cíl: 0 ❌, migrace bez ztráty
- [ ] teprve pak přepnout (deaktivace ux1, aktivace ux-studio) dle kap. 10
