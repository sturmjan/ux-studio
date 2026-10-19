# Changelog

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
