# UX Studio - Design System

Závazná pravidla pro každý modul a každou obrazovku. Porušení = neprojde review/CI.

## 1. Tokeny = jediný zdroj vzhledu
Všechny barvy, rozměry, stíny, radiusy a animace jsou CSS proměnné `--uxs-*`
definované v `src/style.scss` na `#ux-studio-root`. Modul **nikdy** nedefinuje
vlastní hex barvu, px stín ani timing - jen konzumuje tokeny.

- Barvy: `--uxs-brand`, `--uxs-bg`, `--uxs-surface`, `--uxs-surface-2`,
  `--uxs-border`, `--uxs-text`, `--uxs-text-soft`, stavové `--uxs-success/warning/danger/info`
- Tvar: `--uxs-radius-s/-/l`, stíny `--uxs-shadow-1/2`
- Spacing: `--uxs-sp-1..6` (4/8/12/16/24/32)
- Motion: `--uxs-motion-fast/-/slow` + `--uxs-ease` - **jediné povolené** časy/easing
- Typografie: `--uxs-font`, `--uxs-fs-s/-/m/l`

## 2. Dark mode je povinný
Dark varianta je součást tokenů (`[data-theme="dark"]`). Každý nový modul se
kontroluje v obou režimech; komponenta, která v dark režimu nefunguje, se nemerguje.

## 3. Ikony: pouze lucide
Jediný zdroj ikon je `lucide-react`. Zakázané: dashicons, vlastní SVG, emoji ikony,
jiné ikonové sady. Import jednotlivě (tree-shaking).

## 4. Sdílené komponenty
Modul nesmí stavět vlastní tlačítko/modal/tabulku, když existuje sdílená komponenta
(`src/app/`): `App` shell, `ModuleGrid`, `PageHead`, `DataTable`, `Modal`, `EditModal`,
`Tabs`, `Toast`, `Confirm`, `ToggleSwitch`, `Loading`, form fields. Chybí-li něco,
rozšíří se sdílená knihovna - ne modul.

## 5. i18n
Zdrojové řetězce anglicky, vždy přes `__()/`@wordpress/i18n``, text-domain `ux-studio`.
Žádný natvrdo psaný text (česky ani anglicky bez obalu). Data/čísla formátovat podle
WP locale.

## 6. Přístupnost
Interaktivní prvky: focus stav, `aria-label` u ikonových tlačítek, klávesová
ovladatelnost (Enter/Space), `role`/`aria-pressed` u přepínačů. Kontrast dle WCAG AA
v obou tématech.

## 7. Struktura modulu

```
includes/Modules/<id>/
  meta.json      # id, name, description (EN), group, icon (lucide název), settings
  Module.php     # extends BaseModule; boot() + settings_schema() + rest_controller()
  RestController.php  # jen pokud má vlastní data (extends UxStudio\Rest\Controller)
src/modules/<id>/     # jen pokud má vlastní SPA obrazovku (lazy-loaded)
```

Moduly s pouhým nastavením žádný vlastní TSX nemají - vykreslí je generický
schema-driven settings renderer.

## 8. Sdílení kódu
Společné utility (`api.ts`, `route.ts`, `prefs.ts`, datum/formátování) jsou v
`src/app/`. Per-modul kód se lazy-loaduje, ale sdílené závislosti zůstávají ve
společném chunku - žádná duplikace knihoven.

## 9. Menu modulu podle témat (2026-10-20)

- Položky menu modulu jsou **témata** (Firewall a blokace IP, Přihlášení, Log…), jedna úroveň.
  Žádná položka „Nastavení" s podsekcemi vedle karet s daty o stejných věcech - téma pak bylo
  v menu dvakrát (Firewall vs. Blokace IP).
- Stránka tématu = jeho nastavení (`src/app/TopicSettings.tsx`, vlastní Uložit) a pod tím data.
  Stránky, které začínají daty (log, chat), mají nastavení sbalené (`collapsible`).
- Samostatná položka „Nastavení" je v pořádku jen pro obecné věci modulu, které nepatří
  k žádnému tématu (poskytovatel AI a klíče, nastavení odesílání e-mailů).
- Žádné texty typu „tento přepínač je na kartě Nastavení" - přepínač patří tam, kde se o něm mluví.
- Témata se vybírají podle přeložené sekce (`inSections`) nebo klíčů (`withKeys`). Když se
  název sekce z PHP začne používat i v JS, spustit `languages/.build/po-refs.py`, jinak ho
  aplikace nepřeloží a téma zůstane prázdné.

## 10. Tlačítka a výběry (2026-10-21)

- Moduly používají třídy WordPressu (`button`, `button-primary`, `button-small`, `button-link`,
  `button-link-delete`); vzhled jim dává `#ux-studio-root` v `src/style.scss`. Vlastní styly
  tlačítek v modulech nepsat a nepřepisovat je inline (padding/výška).
- Tlačítko, pole i výběr mají stejnou výšku 46 px (padding 12 px, řádek 20 px, rámeček 1 px)
  a zaoblení `--uxs-radius-s`. Malé tlačítko asi 32 px.
- Výběry nestylovat zkratkou `background:` bez obrázku - šipku dodává globální pravidlo
  přes `background-image`; používat `background-color`.

## 11. Data a časy (2026-10-21)

- Datum z REST/DB (`2026-10-19 08:09:01`) se nikdy nevypisuje surově - vždy `formatDate()`
  ze `src/app/date.ts`. Formát se řídí jazykem uživatele administrace (cs_CZ: `19. 10. 2026 8:09`).
- Sloupce uložené v UTC (`gmdate`, např. CSP, DNS log) volat s `{ utc: true }`, hodnota
  s příponou ` UTC` se pozná sama. Jen datum bez času: `{ dateOnly: true }`.
- V PHP (e-maily, widgety na nástěnce) `mysql2date( get_option( 'date_format' ), … )` nebo
  `wp_date()`, ne surový řetězec.

## 12. Dlouhé formuláře: karty a podmíněná pole (2026-10-27)

- Schématem řízený formulář (`SettingsFields`) kreslí pole s `group` jako karty s nadpisem a jednou větou popisu (hlavička je tlačítko s
  `aria-expanded`, ovládací prvek min. 46 px). První karta a karty s nevýchozí hodnotou jsou otevřené, ostatní sbalené.
- Pole, které platí jen při určité hodnotě jiného pole, má `show_if` (`equals` / `in` / `truthy`, seznam = AND, viz PLAN.md 36.2): kontrolní
  přepínač stojí hned nad závislými poli, nesplněné pole se nevykreslí a jeho hodnota se nemění. Animace je `Reveal` (`--uxs-motion`),
  s `prefers-reduced-motion` bez animace. Nové moduly to používají místo vlastního skrývání v `Page.tsx`.


## 13. Nápověda k nastavení, slovník a rozvržení (0.10.0)

- **Nápověda k volbám je jedna pro všechny moduly.** Texty leží v `includes/Modules/<Modul>/help.php` (`klíč => does / effect / risk / off / skip`,
  řetězce přes `__()`), registr `Core\SettingHelp` je přidá jako `explain` ke schématu a `SettingExplain` je vykreslí jako "Podrobnosti".
  Nová volba dostane záznam hned; test `tests/setting-help-coverage-wp.php` hlídá, že žádné pole nezůstane bez textu mimo `tests/fixtures/help-baseline.json`.
  Postup, tón a délka: `docs/help-texts.md`.
- **Technický pojem** v textu nápovědy se píše `[[CSP]]` nebo `[[dns|záznamy DNS]]`. Definici dodává `Core\Glossary`, zobrazí ji `GlossaryTerm`
  (kolečko "?", fokusovatelné, Enter / mezerník otevře, Esc zavře). Vlastní komponenty používají `GlossaryTerm` / `HelpText` z `src/components/GlossaryTerm.tsx`,
  žádné vlastní tooltipy.
- **Jazyk je per uživatel.** REST trasy `uxstudio/v1` běží v jazyce přihlášeného uživatele (`Core\Locale`), ne webu. Texty se proto nikdy nepřekládají
  mimo požadavek ani se nekešují napříč jazyky (cache jen s klíčem jazyka, viz `SettingHelp::file_entries()`).
- **Úzká obrazovka.** Pod 1100 px šířky aplikace je postranní panel zásuvka (tlačítko "Menu", Esc a klepnutí vedle zavře, fokus se vrací); na široké
  obrazovce jde panel sklopit a volba se pamatuje v `localStorage` (`uxstudio-sidebar`). Tabulky `.uxs-table` bývají v `<div className="uxs-table-wrap">`,
  který posouvá vodorovně uvnitř vlastní karty; dlouhé texty se zalamují. Řádek ovládacích prvků (`.uxs-form__line`) se zalamuje a pole mají minimální šířku.
- **Navigace.** Aktivní je právě jedna primární položka (funkce, u modulu bez funkcí jeho název). Záložky pod ní jsou sekundární úroveň: odsazená,
  čára vlevo, aktivní = tučný text v barvě značky bez výplně.
- **Pole a mezery.** Každé textové pole (`text`, `url`, `email`, `password`, `search`, `tel`, `number`, `date`, `time`, `datetime-local`) a výběr má 46 px a
  `--uxs-radius-s`; seznam typů je jedna proměnná `$uxs-text-fields` v `style.scss`, vlastní styly polí v modulech se nepíšou. Tlačítka vedle sebe patří do
  `.uxs-actions` (mezera 12 px, zalamuje), tlačítko Uložit pod formulářem má nad sebou 24 px (sourozenec `.uxs-form` + `.button`, nebo `.uxs-form__actions`).
  Akční tlačítko v kartě se neroztahuje na celou šířku (`align-self: flex-start`), jen na mobilu u úmyslně blokového.
- **Modul bez nastavení** ukáže prázdný stav (`NoSettings.tsx`): ikona, název, popis, "nemá nastavení, funguje po zapnutí", vypínač, případně "Jak to funguje"
  z klíče `how` v `meta.json`. Bez tlačítka Uložit.
- **Chat pro návštěvníky mluví jazykem z nastavení "Chat language"**, ne jazykem webu ani uživatele. Texty chatu pocházejí z `AiAssistant\ChatLocale`:
  `__()` uvnitř `ChatLocale::run( $lang, ... )` odpovídá z `.mo` pluginu pro jazyk chatu; veřejné REST trasy chatu a předání člověku se obalují
  `ChatLocale::wrap()`, takže i chybové zprávy odpovídají jazyku widgetu.
- **Překlady**: zdrojové řetězce jsou anglicky s jednotnou terminologií (color, e-mail, allowlist/blocklist, central app, turn on/off; bez pomlček
  jako interpunkce). Čeština: motiv, smazat, e-mail. Pravidla: `languages/.build/term_rules.py`, postup: `languages/README.md`.
- **Skupina**: export nastavení je jednou, v hlavičce skupiny; zpět vede jedna šipka (drobečková navigace "<- skupina", u funkce skupiny se šipka v nadpisu skryje).
