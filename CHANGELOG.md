# Changelog

## 0.6.0 (unreleased)

- New - Migration wizard (Migration wizard in the menu): finds plugins whose job a
  UX Studio module does, copies their settings and data (36 plugins in 14 sources,
  including Admin Menu Editor, Adminimize, White Label CMS, Adminify, LoginPress and
  Admin Columns), checks the result, lets you look at the site without the old
  plugins in a safe preview (only your browser, 30 minutes), runs live tests and
  switches only when you confirm. The old plugins are only read, a snapshot keeps
  the whole migration reversible for 30 days, and no plugin is deactivated
  automatically. The first activation of UX Studio from the Plugins screen opens the
  wizard once when something can be taken over (not on bulk, network or WP-CLI
  activation); the Modules screen shows a dismissible hint until a migration ran.
- New - The check and verify steps print complex values (menu configuration, list
  columns, lists of slugs) as a short summary ("6 categories", "3 renamed items")
  with an expandable detail that uses labels instead of internal ids.
- New - Overlap guard: enabling a module while a plugin that does the same job is
  active is refused with an offer to start the wizard; activating such a plugin
  later only warns. Nothing is switched off for you.
- New - MCP server in the core (Settings > MCP server): works without the AI module,
  tokens with scopes (read, write, migration, admin), expiry, IP allowlist and
  rate limit, every call is in the Activity Log, ready-made configuration for
  Claude Code, Claude Desktop and Cursor.
- New - Security: Content-Security-Policy inventory of sources with a risk rating,
  a mandatory dashboard widget and a gate before switching to Enforce.
- Change - Module pages are organised by topic (data and its settings together),
  buttons and selects look the same everywhere, dates and numbers use the Czech
  format.
- Fix - Security: the custom login URL now really activates on sites in a
  subdirectory, and the slug collision check no longer blocks a free slug. Sites that
  had "Enable custom login URL" switched on while it silently did nothing will now have
  wp-login.php blocked after this update, so the first admin page load shows every
  administrator a notice with the new address (copy button, link to turn it off) and
  sends ONE e-mail with it to the site e-mail. The setting is never changed
  automatically; `define( 'UXSTUDIO_DISABLE_LOGIN_LOCK', true );` in wp-config.php
  still switches the feature off.
- Dev - Tests: `tests/{mcp-core,migration-sources,migration-data-sources,migration-preview,migration-admin-sources,overlap-catalog,csp-logic,wizard-autostart,login-url-notice}.php`
  (standalone, `php tests/<name>.php`).

## 0.5.0

- New - Admin UI: the module overview is a list only; grouped modules list their
  features as sub-rows with their own switches. A module's tabs and sections
  show as a tree in the left sidebar (group features, Menu Organizer included),
  settings forms use a label-left layout with dividers, larger type, long
  multi-selects become a searchable dropdown, and saving shows a status toast.
- New - Dashboard widgets: tasks, notes, PageSpeed, recent user activity and
  Elementor form submissions on the wp-admin dashboard (lost in the rewrite).
  Every UX Studio dashboard widget, including other modules' (WooCommerce
  orders/products, service requests, IP bans, forms, bot throttle, DNS, AI
  Panel), is switched in one place.
- New - Security: direct wp-login.php access can redirect to a path or page or
  show a custom message; failed logins with a blocked username lock the IP out;
  admin notice for existing accounts with blocked usernames; CAPTCHA thresholds
  in the form; allowlist a CSP violation source in one click; Upload Guard
  custom patterns and skipped paths.
- New - AI Markdown: /ai-sitemap.md, scheduled regeneration, taxonomies in
  llms.txt, AI-bot-only access log with search and stats.
- New - Cron Control sends the central-app mode to the central app and shows
  the result; the central app can switch the mode back (new node route).
- New - Code snippets "everywhere" for CSS/JS/HTML, opening-hours JSON-LD on
  extra pages, popup cache compatibility and stats retention, review fetch
  interval and deep scan request.
- New - DNS Management module (needs the central app with DNS support).
- Fix - Migration from UX1 (runs once on update, never overwrites values set in
  UX Studio): social login providers, AI Markdown post types/taxonomies/log
  retention (migrated sites exposed every post type in llms.txt), CSP mode,
  opening-hours JSON-LD, review profile id, review request Place ID, dashboard
  tasks/notes and hidden widgets, Upload Guard notification e-mail.
- Dev - `bin/parity-keys.py` compares every UX1 settings field with UX Studio.

## 0.4.2

- Fix - Redirects: a rule pointing at a custom post type no longer produces two
  chained 301s (`/old` -> `/?p=73` -> pretty URL). The engine now runs on
  `init` priority 99, after custom post types are registered, and
  `Repository::resolve_target()` only trusts `get_permalink()` when the post
  type exists and the result is not a plain `?p=ID` link; otherwise the stored
  (relative) target is used. Rules still follow slug changes of their target.
- Fix - Redirects: a target with an anchor or query (`/#kontakt`, `/shop/?x=1`)
  is no longer bound to a post id, so the `#fragment` / query is kept. Rules
  saved earlier with an anchor get the anchor re-appended to the live
  permalink.
- Tests: `tests/redirects-cpt-target.php` (manual, `wp eval-file`).

## 0.4.1

- Security - Access Control: with *Restrict REST API to logged-in users* on,
  `/complianz/v1/` is now public by default, so the Complianz cookie banner
  loads and consent is stored without a workaround. The built-in list is the
  initial value of the `uxstudio_rest_public_routes` filter; the match is still
  a strict prefix on the resolved route. Core routes (`/wp/v2/users`…) stay
  closed for anonymous visitors.
