# ux1-ai-core

Sdílené AI jádro pro **UX1 Studio** (WordPress plugin) a **UX1 Network app** (Centrální aplikace, CA).

Jádro je obyčejné PHP (8.1+) bez WordPressu a bez frameworku. Obě aplikace si ho vendorují (plugin do `vendor/ux1-ai-core`, CA do `lib/ux1-ai-core`) a spouštějí ho samostatně, každá u sebe. CA nevyřizuje požadavky WordPress webů. Výjimkou je jen volitelný provider „UX1 Network app“, tedy AI brána kvůli účtování kreditů.

Celý plán, rozhodnutí a fáze jsou v repu pluginu: `PLAN.md` §45 (`sturmjan/ux-studio`).

## Pravidla

1. **Žádné volání hostitele.** V `src/` nesmí být WordPress (`$wpdb`, `get_option`, `wp_*`, `add_action`, `__()`...) ani třídy CA. Hlídá to `tests/purity.php` v CI.
2. **Hostitel dodává porty.** Úložiště, HTTP, tajemství, nastavení, překlady, e-mail, plánovač a operátoři jsou rozhraní v `src/Port/`. Plugin a CA mají vlastní adaptéry.
3. **Tenant je `bot_id`.** Každá tabulka a každý dotaz ho nese. Plugin používá `0`.
4. **Nastavení jsou deklarativní.** Každá funkce popíše svá pole (typ, výchozí hodnota, nápověda). Oba dashboardy je vykreslí svým stylem.
5. **Vendorovaná kopie se nikdy neupravuje.** Změna patří sem, pak se vydá verze a aktualizuje se v obou aplikacích.

## Co jádro umí (0.4.0)

- `Http/`: port `HttpClient`, `CurlHttpClient` (CA bundle, IPv4, TLS), SSE buffer.
- `Provider/`: textové providery Claude, OpenAI, DeepSeek (společný `OpenAiCompatibleProvider`), Claude Code Bridge; výjimky `ProviderException` a `BridgeException` s detaily (status, kód, retry_after).
- `Image/`: generování obrázků Gemini a OpenAI gpt-image, styly, poměry, rozlišení, odhad ceny, geometrie ořezů pro sociální sítě (viz `docs/images.md`).
- `Rag/`: chunking, embeddingy (OpenAI, záložní tf-idf-256-v2), hybridní vektorové hledání nad portem `VectorStore` (viz `docs/rag.md`). Hybridní výsledky bez duplicitních úryvků.
- `Image/ImageTool`: sdílený chatový nástroj `generate_image` (definice + převod argumentů na požadavek). Hostitel ho nabídne jen při zapnutém nastavení "povolit obrázky v chatu" (výchozí vypnuto).
- `Content/`: sdílená obsahová pipeline: typy článků (33), návrh témat od AI, psaní článku s kontrolou délky a čištěním HTML, plánovač termínů zveřejnění (rozsah nebo N za den/týden, časové okno, DST) a mřížka měsíce pro kalendář (viz `docs/content.md`).

## Struktura

```
src/            Ux1\AiCore\... (PSR-4, autoload.php)
tests/          čisté PHP testy, spuštění: php tests/run.php
.github/        CI (PHP 8.1-8.4: lint, testy, kontrola čistoty)
```

## Lokální vývoj

Lokálně je repo do obou projektů připojené junction odkazem, takže úpravu hned vidí lokální WordPress (pobyty) i lokální CA. Kopie s pevnou verzí vzniká až při vydání.

## Automatické aktualizace hostitelů

Po tagu `v*` workflow `sync-hosts.yml` otevře v `ux-studio` a `centrani-app` PR s novou vendorovanou kopií. Potřebuje secret `CORE_SYNC_TOKEN` (fine-grained token, Contents + Pull requests: read and write na obě repa); bez něj se jen ohlásí přeskočení.

## Vydání

1. Vydat tag `vX.Y.Z`.
2. Automat (F1) otevře v pluginu i v CA PR, který aktualizuje vendorovanou kopii a ověří kontrolní součet.
