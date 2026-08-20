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
