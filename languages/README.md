# Translations

- Source strings in code are **English** (WP convention) - an English WordPress works out of the box.
- Czech (`cs_CZ`) is the shipped translation.

## Important gotchas

1. **The .pot must be generated from the compiled `build/` output, not the `src/`
   TSX.** `wp i18n make-pot` cannot parse TypeScript/TSX, so it silently skips
   `src/**/*.tsx` and you get zero JS strings. Always `npm run build` first, then
   extract with `build/` INCLUDED (only `node_modules,vendor,languages` excluded).
2. **The SPA loads one handle-based JSON file:**
   `ux-studio-cs_CZ-ux-studio-app.json`. WordPress checks this exact name first
   (`{domain}-{locale}-{handle}.json`, see `load_script_textdomain()`), so all JS
   strings - including those in lazy-loaded module chunks - must live in this one
   file (@wordpress/i18n loads one locale_data set per domain).
3. **Module names/descriptions come from each module's `meta.json` (data, not
   code)**, so make-pot cannot extract them. They are translated at the REST layer
   via `translate($string,'ux-studio')` (see `Core\Rest::translate_meta()`); the
   `.build/parse-pot.mjs` step appends them to the string list so they land in the
   `.po`/`.mo`.

## Regenerating / re-translating

```
npm run build                      # 1. compile TSX -> build/ (REQUIRED first)
npm run i18n:pot                   # 2. extract PHP + built JS into ux-studio.pot
node languages/.build/parse-pot.mjs   # 3. pot + meta.json -> .build/strings.json
node languages/.build/chunk.mjs 12    # 4. split for parallel translation
#    translate each .build/chunks/chunk-NN.json -> .build/out/out-NN.json
node languages/.build/assemble.mjs        # 5. -> ux-studio-cs_CZ.po (+ validation)
node languages/.build/assemble-json.mjs   # 6. -> ux-studio-cs_CZ-ux-studio-app.json
php wp-cli.phar i18n make-mo languages/ux-studio-cs_CZ.po languages/  # 7. -> .mo
```

Files:
- `ux-studio.pot` - template (generated, committed)
- `ux-studio-cs_CZ.po` / `.mo` - Czech translation (PHP + JS source strings)
- `ux-studio-cs_CZ-ux-studio-app.json` - Czech strings for the SPA bundle
- `.build/` - translation pipeline scripts (dev only, not loaded at runtime)

## Adding a few new strings (without update-po)

`wp i18n update-po` drops the meta.json strings from the .po, so for a handful
of new strings append them instead:

```
npm run build && npm run i18n:pot
python languages/.build/po-missing.py          # -> .build/missing.json (+ list)
#  write a JSON list of Czech strings in the same order
python languages/.build/po-append.py my-cs.json  # checks %s/%d placeholders
php wp-cli.phar i18n make-mo languages/ux-studio-cs_CZ.po languages/
php wp-cli.phar i18n make-json languages/ux-studio-cs_CZ.po --no-purge
php bin/merge-json-translations.php
```

## Many new strings from dictionaries (post-audit packages)

When the new strings come as `{ "English": "Czech" }` dictionaries (`docs/i18n/cs-pkg-*.json`), use the helpers in
`.build/` instead of translating by hand. The terminology rules (color, e-mail, allowlist/blocklist, central app,
turn on/off) live in `.build/term_rules.py`; a dictionary written before a rule still matches the new msgids.

```
npm run build && npm run i18n:pot     # (wp = php path/to/wp-cli.phar)
python languages/.build/po-refs.py
python languages/.build/po-missing.py
python languages/.build/po-apply-dict.py docs/i18n/cs-pkg-a.json ...   # also adds new meta.json names/descriptions
python languages/.build/po-set.py changes.json    # change the Czech of EXISTING entries (also singular -> plural)
python languages/.build/po-prune.py               # drop entries renamed by a terminology rule (needs .build/term-map.json)
php wp-cli.phar i18n make-mo languages/ux-studio-cs_CZ.po languages/
php wp-cli.phar i18n make-json languages/ux-studio-cs_CZ.po --no-purge
rm languages/ux-studio-cs_CZ-ux-studio-app.json && php bin/merge-json-translations.php   # rebuild the handle file from the current chunks
```

The md5-named JSON files that `make-json` writes (one per chunk) are inputs of the merge only; they are git-ignored.
`languages/ux-studio-meta.php` mentioned in old comments does not exist: the names and descriptions of `meta.json`
live in the `.po` only (added by `po-apply-dict.py`).

