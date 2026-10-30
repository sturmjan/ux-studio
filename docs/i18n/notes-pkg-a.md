# Balíček A: poznámky pro slučovací agent F

## Řádky do CHANGELOG.md (EN)

- Help for every setting of Security, Disable Auto Updates, Vulnerability Scanner, SMTP Email, Email Log, Email Health, Bot Throttle and Third-Party Login, with risk notes for options that can lock you out, hide updates, redirect or turn off protection.
- Security: the "Client IP source" choices have short names, the detail (REMOTE_ADDR, CIDR, loopback) moved to the help; the IP ban form and the login attempts tab explain what they do.
- Security: help for the login attempt limit now states the real defaults (3 attempts, 30 minutes) instead of 5 and 15.
- Email Log: the source column reads "Plugin: Disable Emails" instead of "plugin: disable-emails", the raw "Pending" status is now "Unconfirmed" with an explanation, and the tabs of SMTP Email ("Delivery log") and Email Log ("Messages") have distinct names.
- Email Health: explains what the test proves and what it does not.
- Rollback Manager: explains the risk and asks for confirmation before it replaces files.
- Vulnerability Scanner: the description now names the real data source (Wordfence Intelligence through the central app) instead of WPScan.
- Debug Mode and User Switching: "How it works" text on the module page.
- SMTP Email: the Gmail "no connected account" error is translatable.

## Záznamy baseline, které jsou nově pokryté (smazat z `tests/fixtures/help-baseline.json`)

Soubory `help.php` mají záznam pro všechny tyto klíče, stejný seznam je v `D:\xampp\htdocs\_uxs-scratch\pkg-a\baseline-covered.json`.

- `security-optimization`: captcha_mode, disable_embeds, lockout_time, max_attempts, notify_admin
- `disable-auto-updates`: hide_notices_plugins, hide_notices_themes
- `smtp-email`: encryption, from_name, host, mailgun_region, port, sendgrid_region, ses_region, username
- `bot-throttle`: aggressive_max, aggressive_min, block_response_code, enabled, light_max, light_min, min_delay_ms, retention_days, threshold_orange, threshold_red, threshold_yellow

Moduly updates-group, rollback-manager, vulnerability-scanner, email-log, email-health, email-group, third-party-login, user-switching a debug-mode v baseline nejsou (nemají co odebrat). Test pokrytí (`setting-help-coverage-wp.php`) jsem nespouštěl, WP-CLI běží proti jinému worktree; klíče jsem ověřil vlastním kontrolním skriptem proti `'key' =>` ve schématu.

## Chybějící pojmy ve slovníku `Core\Glossary` (nepřidáno, sdílený soubor)

- `CAPTCHA` (použit jako `[[CAPTCHA]]` v captcha_shield_adaptive a captcha_adaptive_threshold; bez pojmu se zobrazí jako prostý text). Návrh: "A quick check that tells a person from a script, for example a puzzle or a tick box. It stops mass attempts but not every clever script."
- `CVE` (jen jako prostý text, nepoužito jako značka). Návrh: "A public number for a known security hole in software."
- `reverse DNS` (v `verify_rdns` je značka `[[dns|reverse DNS]]`, definice DNS pokryje základ).
- Použité existující pojmy: smtp, spf, dkim, oauth, cidr, user-agent, rest-api, dns, cron (alias WP-Cron).

## Opravy existujících českých překladů (stejný msgid, nový msgstr; v `cs-pkg-a.json`)

- "Disable core updates" -> "Vypnout aktualizace jádra", "Disable plugin updates" -> "Vypnout aktualizace pluginů", "Disable theme updates" -> "Vypnout aktualizace motivů" (v .po je u motivů "šablon", pravidlo je "motiv"). Pokud F nechce přepisovat, může tyto tři řádky z JSON vynechat.

## Změněné anglické msgidy (staré překlady v .po přestanou platit, nový překlad je v JSON)

- `Direct connection (REMOTE_ADDR) - safe default` -> `Direct connection (default)`
- `Behind Cloudflare (CF-Connecting-IP)` -> `Behind Cloudflare`
- `Behind a reverse proxy (X-Forwarded-For)` -> `Behind a reverse proxy`
- `Automatic (keys if there are any, otherwise the Shield)` -> `Automatic (recommended)`
- `Standalone page before login (also gates wp-admin)` -> `Standalone page before login` (co brána chrání navíc, vysvětluje stávající help u `captcha_placement`)
- Vulnerability Scanner `meta.json` description (WPScan pryč).
- Help `limit_login_attempts.does` a `block_username_attempts.does` v `SecurityOptimization/help.php` (čísla 5 a 15 neodpovídala výchozím hodnotám 3 a 30).
- Pozor: `SecurityOptimization/help.php` i `Module.php` jsou v CRLF. Zachováno.

## Co jsem nemohl udělat / otevřené body

1. **#17 zkracování bez třípunktu**: popisky voleb jsem zkrátil, ale samotné ořezání dlouhého textu v `select` bez výpustky je věc `src/style.scss` (sdílený soubor). Návrh: `select { text-overflow: ellipsis; }` a případně `min-width: 0`. Jiné dlouhé volby, které by to mohlo postihnout: `csp_mode` ("Inventory - find out what the website loads (Report only, recommended first)") je v CSP balíčku, nesahal jsem.
2. **Bot Throttle `verify_rdns` nemá žádný efekt**: `Detector::detect()` vrací klíč `verified`, ale nic ho nečte (ani `Module::evaluate()`, ani log). Nápověda to říká otevřeně. Oprava (nevěrohodného robota zařadit do `generic_crawlers`) mění chování, proto jsem ji neudělal.
3. **Bot Throttle `blacklist_ua`**: původní help slibovalo "ALWAYS aggressively throttled", ale kategorie `blacklist` není v `Signatures::categories()`, takže `Throttler::default_action_for_category()` vrátí `throttle_light`. Nápověda popisuje skutečné chování (zpomalení jen při zátěži). Pokud je záměr agresivní, stačí v `Throttler` vrátit `throttle_aggressive` pro `blacklist`.
4. **Bot Throttle limit za minutu** se nevztahuje na administrátory, ale vztahuje se na všechny ostatní včetně IP v `whitelist_ip` (allowlist vyjímá jen z detekce botů). Je to v nápovědě.
5. **Third-Party Login**: `RestController::handle_login()` vytvoří uživatele dřív, než ověří, že jeho role je v `allowed_roles`. Při nesouladu (auto_create_role není povolená) zůstane vytvořený účet, který se nemůže přihlásit. Nápověda to uvádí; skutečná oprava je pořadí kontrol v kódu (neměnil jsem).
6. **Debug Mode** nemá v SPA žádnou stránku: endpoint `debug-mode/status` nikdo nevolá, modul ukazuje jen prázdný stav s textem `how`. Pokud má modul něco ukazovat, chybí stránka (mimo můj rozsah).
7. Upload Guard (`UploadGuardDetectionEngine`, `UploadGuardIntegrityChecker`) má anglické popisky detekcí a zprávu uložené do databáze bez `__()`. Je to záměrné (konstanty se parsují před načtením textdomény a hodnoty se ukládají do dat), nechal jsem je.
8. Texty v existujících řetězcích stránek (např. vulnerability-scanner "Confirmed CVE" odstavec) obsahují dlouhé pomlčky; jsou to staré msgidy se stávajícím překladem, nezměněno.
9. `npx tsc --noEmit` hlásí 4 chyby v cizích souborech (admin-columns, instagram-feed a další), v mých souborech žádná. `npm run build` prošel.
10. Prohlížečový test jsem neprováděl (zadání).

## Počty

Nové záznamy `help.php`: DisableAutoUpdates 17, VulnerabilityScanner 4, ThirdPartyLogin 8, SecurityOptimization +20 nových a 2 přepsané, BotThrottle 23, SmtpEmail 26, EmailLog 2, EmailHealth 3. V `cs-pkg-a.json` je 340 msgidů.
