# Texty nápovědy k nastavení (`help.php`) a slovník pojmů

Návod pro každého, kdo dopisuje vysvětlení k volbám modulů. Sdílený registr je `includes/Core/SettingHelp.php`,
slovník `includes/Core/Glossary.php`. SPA už umí všechno vykreslit (`SettingsForm.tsx` -> `SettingExplain`), takže
stačí dodat texty.

## Kde texty leží

Každý modul má vedle sebe soubor `includes/Modules/<Modul>/help.php` (`<Modul>` je název složky, např.
`SecurityOptimization`). Soubor vrací pole `klíč nastavení => části`:

```php
<?php
defined( 'ABSPATH' ) || exit;

return array(
	'retention_days' => array(
		'does'   => __( 'Entries older than this number of days are deleted once a day.', 'ux-studio' ),
		'effect' => __( 'The log stays small and the database does not grow without end. Older entries cannot be recovered.', 'ux-studio' ),
		'risk'   => __( 'With 0 nothing is ever deleted, so a busy site can fill the database over time.', 'ux-studio' ),
		'off'    => __( 'Set 0 only when you keep your own backup of the log.', 'ux-studio' ),
	),
);
```

- Klíč je `key` pole ve `settings_schema()` modulu. Soubor se načítá líně, až když REST pošle schéma modulu.
- Registr přidá `explain` ke každému poli, které má záznam. U pole nemusíš nic měnit.
- Jednorázově jde `explain` dát i přímo do pole schématu: `'explain' => array( 'does' => __( ... ), 'effect' => __( ... ) )`.
  Inline `explain` má přednost před `help.php`. Používej to jen tam, kde text závisí na kódu schématu; jinak `help.php`.
- Rozšíření zvenku: filtr `uxstudio_setting_help` (`$parts`, `$module_id`, `$key`) a `uxstudio_setting_help_file` (cesta
  k `help.php` modulu z doplňku).
- Řetězce vždy přes `__( '...', 'ux-studio' )`, anglicky. Registr je přeloží podle jazyka uživatele.

## Části záznamu

| část     | povinná | co do ní patří |
|----------|---------|----------------|
| `does`   | ano     | Co volba dělá, prostými slovy. Jedna až tři krátké věty. |
| `effect` | ano (u nových) | Co se změní pro web a jeho návštěvníky, když je volba zapnutá. Co uvidí nebo nezažijí. |
| `risk`   | doporučená | Co se může pokazit a kdy. Konkrétně (třeba "za proxy vypadají všichni návštěvníci stejně"), ne obecně ("může způsobit potíže"). Když riziko opravdu není, část vynech. |
| `off`    | doporučená | Kdy volbu vypnout nebo nechat být. |
| `skip`   | jen start. balíček | Podmínka, za které ji úvodní průvodce vynechá. Na obrazovce nastavení se nezobrazuje. |

Starší záznamy (Zabezpečení) `effect` nemají. Prázdný `effect` se prostě nevykreslí, nic se nerozbije. U nového textu
`effect` vždy vyplň.

### Rozdíl mezi `does`, `effect` a `risk`

- `does` je mechanika: "Skryje verzi WordPressu v hlavičce stránky."
- `effect` je výsledek: "Návštěvníci i roboti nezjistí, jakou verzi používáš. Vzhled webu se nemění."
- `risk` je cena: "Některé nástroje pro kontrolu aktualizací z veřejné verze už nepoznají, že web aktualizuješ."

## Tón a délka

- Píšeme pro majitele webu, ne pro programátora. Krátké věty, žádné zkratky bez vysvětlení.
- Každá část má 1 až 3 krátké věty. Delší vysvětlení patří do dokumentace, ne do rozbalovátka.
- Aktivní, přímý tón ("Smaže", "Zablokuje"), žádné "může být případně použito".
- Žádné pomlčky a spojovníky jako interpunkce uvnitř věty (ani "-", ani dlouhé). Použij tečku nebo čárku.
- Nevypisuj text, který už říká popisek pole nebo řádek `help`. Detail má přidat to, co v `help` není.
- Nepřebírej formulace z cizích pluginů, piš vlastními slovy.
- Stejný pojem všude stejně (nastavení = "setting", ne chvíli "option").

## Slovník: značky `[[pojem]]`

Technický pojem v textu označ dvojitými hranatými závorkami. SPA z něj udělá slovo s malým kolečkem "?" a po kliknutí
(Enter, mezerník) ukáže definici ve vyskakovacím okně. Esc ho zavře a vrátí fokus.

```
[[CSP]]                  zobrazí "CSP" + ?
[[REST API]]             zobrazí "REST API" + ?
[[cron]]                 alias, definice WP-Cron, zobrazí slovo "cron"
[[dns|záznamy DNS]]      zobrazí vlastní text "záznamy DNS" + ? s definicí DNS
```

- Syntaxe přesně: `[[term]]` nebo `[[term|zobrazený text]]`. Mezi závorkami žádné další `]` ani `|`.
- `term` se porovnává bez ohledu na velikost písmen; mezery a tečky se mění na pomlčky (`REST API` -> `rest-api`,
  `llms.txt` -> `llms-txt`). Aliasy jsou v `Glossary::ALIASES` (`cron` -> `wp-cron`, `schema` -> `schema-org`...).
- Značka, která nic nenajde, se zobrazí jako obyčejný text (překlep nic nerozbije).
- Značky fungují v částech `does`, `effect`, `risk`, `off`, `skip`. Označ pojem jen při prvním výskytu v textu.
- Pojem, který ve slovníku chybí, přidej do `Glossary::terms()` (anglicky, 1 až 2 věty, `__()`), nebo filtrem
  `uxstudio_glossary`. Nesahej na `languages/`, viz níže.
- Z JS (vlastní stránky modulů) použij komponentu `GlossaryTerm` nebo `HelpText` ze `src/components/GlossaryTerm.tsx`.

Pojmy ve slovníku: CSP, HSTS, Report-Only, Heartbeat, XML-RPC, REST API, WP-Cron, TTL, DNS, SPF, DKIM, DMARC, OAuth,
webhook, regex, 301, 302, 410, canonical URL, slug, transient, object cache, lazy load, WebP, AVIF, LCP, CLS, CDN,
honeypot, proof-of-work, user enumeration, application passwords, SSO, MCP, RAG, llms.txt, JSON-LD, Schema.org, VAPID,
CIDR, User-Agent, rate limit, nonce, SMTP, IMAP.

## Český překlad dodávej v JSON, ne v `.po`

Do `languages/` v tomto kole nesahej (překlady se do `.po` aplikují naráz v balíčku F). Každý balíček dodá soubor
`docs/i18n/cs-pkg-<písmeno nebo číslo>.json`:

```json
{
  "Entries older than this number of days are deleted once a day.": "Záznamy starší než tento počet dní se jednou denně mažou.",
  "%d entry": { "msgid_plural": "%d entries", "msgstr": [ "%d záznam", "%d záznamy", "%d záznamů" ] }
}
```

- Klíč je přesný anglický `msgid` (stejný znak po znaku jako v `__()`), hodnota český překlad.
- Množné číslo je objekt s `msgid_plural` a polem `msgstr` (cs má tři tvary).
- Značky `[[CSP]]` a `[[dns|záznamy DNS]]` v překladu zachovej; `term` nech anglicky, přeložit lze jen zobrazený text za `|`.
- Dokud překlad nebude v `.po`, UI zobrazuje anglický text (záložní jazyk). To je v pořádku.

## Test pokrytí

`tests/setting-help-coverage-wp.php` projde schéma každého modulu a selže pro pole bez `help` a bez `explain`
(z `help.php` nebo inline), kromě polí v `tests/fixtures/help-baseline.json` (`{ "modul": ["klíč", ...] }`).
Baseline se jen zmenšuje:

1. Dopiš záznam do `help.php`.
2. Smaž stejný klíč z baseline. Pokud to zapomeneš, test selže hláškou, že položka v baseline už není potřeba.
3. Nové pole bez textu se do baseline nepřidává, test na něj selže. Přidej text.

Spuštění (z `D:\xampp\htdocs\pobyty`, vždy s `</dev/null`):

```
D:/xampp/wp-cli/wp.bat --skip-plugins --skip-themes eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\setting-help-coverage-wp.php </dev/null
```

S proměnnou prostředí `UXS_REGENERATE=1` test jen vypíše aktuální seznam polí bez textu jako JSON (nic nezapisuje).
Výjimka z testu: pole `color` v kartě, jejíž nadpis má popis (`group_help`), protože barvu vysvětluje karta.

## Příklady ze Zabezpečení

`includes/Modules/SecurityOptimization/help.php` obsahuje 25 vzorových záznamů (části `does`, `risk`, `off`, `skip`),
například `limit_login_attempts`:

- `does`: "After 5 wrong passwords the visitor's IP address is locked out for 15 minutes (longer after repeated lockouts)."
- `risk`: "Behind a proxy every visitor looks like the same IP address, so a few typos could lock everybody out. ..."
- `off`: "When the site is behind a proxy that hides the real IP address and you did not set the IP source in Security > Firewall."

Všimni si konkrétnosti rizika a toho, že `off` říká kdy, ne jen "když to nepotřebuješ".

## Modul bez nastavení

Stránka modulu, který nemá žádné pole, ukáže prázdný stav (ikona, název, popis, vypínač). Chceš-li tam krátký odstavec
"Jak to funguje", přidej do `meta.json` modulu klíč `how` (anglicky, pár vět). Překlad dodej stejně přes `docs/i18n`.
