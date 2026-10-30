# Package B: notes for the merge (agent F)

Branch `feature/audit-pkgb`, modules: AiAssistant (chat widget, BlogPilot, SEO panel, toolbar), AiMarkdown, AiPanel, ElementorImport.

## CHANGELOG lines (EN)

- Fixed: the visitor chat widget now speaks the language chosen in "Chat language". All of its texts (buttons, forms, rating, errors, FAQ) come from a dictionary built on the server, no Czech is hard-coded in the script any more.
- Fixed: the default greeting, placeholder, GDPR notice and operator timeout message of the chat are real translations of English source texts, so the chat language setting always gives the right language.
- Changed: AI Panel (admin screen, dashboard widget, login page of the panel, rescue endpoint) is fully translatable; the instructions for the AI agent and the protocol help are English.
- Changed: the AI Panel tile in the app uses the standard empty state layout and no longer mentions the rewrite.
- Changed: the AI toolbar, the SEO score panel, the schema and internal link helpers and the Elementor import messages use English source texts with Czech translations.
- Changed: the 33 BlogPilot article types (names and descriptions) are translated.
- Added: help texts for the AI Assistant and AI Markdown settings (providers, models, API keys, token and request limits, MCP, system prompt, RAG, llms.txt, YAML header, WP-Cron regeneration, bot log).
- Improved: the AI Markdown cache list is paginated, no longer lists posts that were deleted, and "Regenerate" is a proper small button.

## Baseline entries covered (remove from `tests/fixtures/help-baseline.json`)

Module `ai-assistant` (whole list, the key disappears): `claude_model`, `deepseek_model`, `default_length`, `default_provider`, `default_tone`, `language`,
`mcp_enabled`, `openai_model`, `operator_notifications_enabled`, `widget_auto_open`, `widget_auto_open_sound`, `widget_color`, `widget_enabled`,
`widget_position`, `widget_text_color`, `widget_type`.

Extra help.php entries (not in the baseline, rows WEAK/JARGON/OK of the audit): ai-assistant `claude_api_key`, `openai_api_key`, `deepseek_api_key`,
`monthly_token_limit`, `daily_request_limit`, `user_daily_request_limit`, `custom_system_prompt`, `widget_auto_open_delay`, `chatbot_provider`,
`chatbot_system_prompt`, `internal_rag_enabled`; ai-markdown all 20 keys (including the 5 WooCommerce ones that appear only with WooCommerce).
`ai-markdown`, `ai-panel` and `elementor-import` are not in the baseline.

Checked with `D:\xampp\htdocs\_uxs-scratch\pkg-b\check_help.py` (every help.php key exists in the schema; no dashes). The WP-CLI coverage test was not run
(the local site runs another worktree).

## Missing glossary terms (add to `Glossary::terms()`, ids in brackets)

The help texts already contain the markers `[[tokens]]`, `[[system prompt]]`, `[[YAML]]`; until the terms exist they render as plain words.

- `tokens` (Tokens): EN "The small pieces of text, roughly three quarters of a word each, that an AI reads and writes. Providers bill and limit by tokens, so longer questions and answers cost more." CS "Malé kousky textu, zhruba tři čtvrtiny slova, které AI čte a píše. Poskytovatelé podle tokenů účtují a omezují, takže delší dotazy a odpovědi stojí víc."
- `system-prompt` (System prompt): EN "The standing briefing an AI gets before every conversation: who it is, what it may talk about and how it should sound. Visitors never see it." CS "Stálé zadání, které AI dostane před každou konverzací: kým je, o čem smí mluvit a jak má znít. Návštěvníci ho nikdy nevidí."
- `yaml` (YAML): EN "A simple text format for structured data: one fact per line, written as name, colon, value. Here it holds the title, address and date of a page at the top of the markdown." CS "Jednoduchý textový formát pro strukturovaná data: jeden údaj na řádek, zapsaný jako název, dvojtečka, hodnota. Tady nese název, adresu a datum stránky na začátku markdownu."
- optional later: `byok` (bring your own key), `api-key`, `provider`.

In the Czech texts the markers are written `[[tokens|tokeny]]`, `[[system prompt|systémovému promptu]]`; the ids are `tokens` and `system-prompt` (`Glossary::id_of`).

## Requests for shared files

- `languages/ux-studio-cs_CZ.po` and `.mo`: apply `docs/i18n/cs-pkg-b.json` (430 entries). Notes: one entry is a plural (`%d entry in total`), some msgids contain real line
  breaks (the AI Panel e-mails), four entries replace the old identity translations ("Hello! How can I help you?", "Type your question...", the GDPR notice,
  "AI Support"). The chat widget reads the same `.mo`, so the Czech chat only works after the `.mo` is rebuilt.
- Obsolete Czech msgids that can be dropped from the `.po`: "AI nástroje", "Pracuji…", "Nejdřív označ text, se kterým má AI pracovat.", "AI nástroj selhal:",
  "AI nevrátila žádný text.", "Dobrý den! Jak vám mohu pomoci?", the Czech GDPR and placeholder and operator texts of `PromptTemplates`, "SEO skóre", "Klíčové slovo",
  "SEO titulek", "SEO popis", "Analyzuji…", "Návrh klíčových slov", "Hledám…", "Související klíčová slova", "Podtémata k pokrytí", "Vygenerovat", "Zkopírovat", "Zkopírováno!",
  "Interní odkazy", "Najít návrhy", "SEO analýza selhala.", "Generování schema markup selhalo.", "Návrh interních odkazů selhal.", "AI Podpora" and the other Czech msgids of
  `czech-msgids.txt` that belong to these files.
- `CHANGELOG.md`: lines above.
- `tests/fixtures/help-baseline.json`: remove the `ai-assistant` block (16 keys).
- `DESIGN.md` section 13 (optional): "The visitor chat texts come from `AiAssistant\ChatLocale`; `__()` inside `ChatLocale::run( $lang, ... )` answers from the plugin `.mo` of the chat language, independent of the WordPress locale."
- No change to `src/style.scss` or `src/components/*` was needed (the AI Panel tile uses the existing `.uxs-empty` block).

## How the chat language works now

1. `ChatBootstrap` reads the setting `language` (cs or en, anything else falls back to cs) through `ChatLocale::normalize()`.
2. `AssistantConfig::widget_i18n()` builds the dictionary (70 keys) with plain `__( 'English text', 'ux-studio' )` calls inside `ChatLocale::run( $language, ... )`.
   While the callback runs, filters on `gettext` and `gettext_with_context` answer from the plugin `.mo` of the chat language (read with the WordPress `MO` class,
   first `WP_LANG_DIR/plugins/`, then `languages/`). English returns the source text, which also undoes a Czech admin or visitor locale.
3. Why not `switch_to_locale()`: it only works for languages WordPress itself has installed, so a Czech chat on a site installed in English would stay English
   and a Slovak site with the chat set to Czech would get Slovak. The own `.mo` lookup is independent of the site, the visitor and the logged-in user.
4. The dictionary and the default texts are cached in transients per chat language and per translation file (plugin version plus `.mo` modification time),
   so the catalog is not read on every page view.
5. The widget (`cfg.i18n`) uses `t( key )`; English defaults per key in the script stay as a fallback for an old cached page, so the widget config is backward compatible
   (missing `i18n` gives an English widget, `openLabel` and the other keys are unchanged).
6. The currency of product cards comes from WooCommerce (`i18n.currency`), not a hard-coded "Kč".
7. `ChatEngine` fallback reply uses the same helper. Tested by `tests/chat-locale.php` (needs the WordPress `pomo` classes, `UXS_WP_PATH`, skips if absent).

## Other design decisions

- AI Panel: `Panel.php` and `Runtime.php` run inside WordPress, so they use `__()`. `rescue.php` runs OUTSIDE WordPress: it has a tiny `rt()` and the plugin writes the
  translated UI texts into the rescue `config.php` (`i18n`, `locale`) when the endpoint is installed (`Claude_Panel_Bootstrap::rescue_i18n()`; keep it in sync with the `rt()`
  calls of `rescue.php`). The API error messages of the rescue endpoint are English only. Endpoints that are already installed have no `i18n`, so they show English until the next grant.
- The `?api=help` protocol JSON, the "copy for the AI agent" prompts and the comments in `config.php` / `.htaccess` are now English and not translatable (machine-facing, they must not change with the admin language).
  Audit log details such as "[backup ✓]" are English too.
- `ArticleTypes::TYPES` stays English (it is part of the prompt); `get_all()` (REST for the UI) returns names and descriptions translated.
- `ai-markdown/list` now takes `limit` and `offset` and returns `{ items, total }` (was a plain array); only the app page consumes it.

## Open items

- REST answers of the public chat (`ChatRestController`, `HandoffRestController`: "Too many requests", "Invalid email address", "Your request has been recorded..." and so on) are
  translated with the site locale, not the chat language. The widget shows `data.error` from the server first, so a Czech chat on an English site can show an English error.
  Fix: run the public routes inside `ChatLocale::run()` for anonymous visitors.
- The "Chat language" select still offers only Czech and English; there is no "automatic" value (the brief mentioned cs/en/auto). A third option should take the site locale and
  would need a decision on the languages of the AI prompts (`PromptTemplates`, `PromptBuilder` have Czech and English variants only).
- Prompts for the AI model (`PromptTemplates::intro/rules`, `PromptBuilder`, `ContentGenerator::language_line`) contain Czech by design (they are instructions to the model, not UI) and were left alone.
- The fields inside the React tabs of the AI Assistant (BlogPilot, Content Creator, Knowledge Hub, RAG Training, Chat filter) and of Elementor Import have no per-field hints (the audit lists about 30 of them).
  They need `HelpText` / `GlossaryTerm` from `src/components`, which are shared files; not done in this package.
- Browser QA of the widget, the AI Panel pages and the AI Markdown cache list was not done (the local site runs another worktree).
- `npx tsc --noEmit` still reports 4 old errors in `admin-columns`, `instagram-feed` and `index.tsx` (style.scss typing); none in files of this package.
