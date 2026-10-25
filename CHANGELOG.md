# Changelog

## 0.6.0 (unreleased)

- New - SMTP Email: SendGrid (global / EU), Mailgun (US / EU), Postmark (message stream) and Amazon SES (the SES v2 HTTPS
  API signed with Signature Version 4 in PHP, no SDK) next to SMTP, Brevo and Gmail. They send HTML or plain text, Cc /
  Bcc, Reply-To and attachments; keys are encrypted and never sent back to the browser. Optional FALLBACK connection used
  when the primary send fails or is not set up; both attempts are in the log (new columns connection and attempt). Each
  service has its own tab with a test button that sends through that connection only. The migration moves SendGrid,
  Mailgun, Postmark and Amazon SES from WP Mail SMTP and Easy WP SMTP, Post SMTP and FluentSMTP, and the fallback
  connection of Post SMTP (its fallback SMTP server) and FluentSMTP; only more than a primary and a fallback is reported.
  Fixed: the Brevo mailer of WP Mail SMTP / Post SMTP / FluentSMTP was written with a value the module rejected.
- New - Disable Auto Updates: hide the update notices per type (WordPress, plugins, themes) from everybody except the
  listed users, stop the update check per type (cron events, load-time checks and the requests to api.wordpress.org),
  and an exception that still installs security (minor) core releases. All off by default, with a clear warning that
  this hides security fixes. The migration takes it from Disable WordPress Updates (Security Mode) and Easy Updates
  Manager (off, excluded users).
- New - Cron Control: events that call a URL (GET or POST, any interval, timeout, last result and HTTP status). No event
  runs PHP code. Private, loopback and link-local addresses are blocked (checked when saving and at every run) unless a
  developer opens one address with the filter `uxstudio_cron_url_allow_private`. The migration turns the URL events of
  WP Crontrol into these events, switched off until the switch so nothing is called twice.
- New - Auto Image Upload: address of the uploaded images (CDN), file name and alt text patterns with the placeholders of
  Auto Upload Images, largest width / height, excluded domains and content types, custom fields with HTML. The migration
  moves all of them.
- Changed - Menu Organizer: every item of the sub-item editor is a collapsed row with a one-line summary of its rules
  ("Renamed to X, hidden for Editor, never shown to: jan"); the fields open under it. Shorter placeholders, hints below.
- New - Duplicate Post: the copy can keep the status of the original (published, private, pending, draft; a scheduled
  post becomes a draft, a user who cannot publish always gets a pending copy), custom fields that are not copied
  (one per line, `*` as wildcard, for example `_edit_*`) and taxonomies that are not copied, the roles that may
  duplicate (empty = everybody who can edit the post, administrators always) and the link for every post type
  that has a list screen. The role check also covers the edit screens and the REST route.
- New - Link Manager: rules per domain (host, `*.sub.example`, or a path) with new tab yes / no, the exact rel tokens
  (nofollow, sponsored, ugc, noopener, noreferrer, external) and icon yes / no; a list of addresses that are never
  external; "subdomains of this site are internal"; the default target and rel for all external links; the icon is
  the arrow, a Dashicon (before or after the text) or none, and image links can go without it. The content is parsed
  once per `the_content` and not at all when it has no link; the decision for an address is cached for the request.
  Fixed: a relative address without a leading slash and `//host` links were treated as internal / external wrongly,
  `mailto:`, `tel:`, `javascript:` and other schemes are skipped, http / https and www of the own host count as internal.
  Sites that had "Disable external link arrow" keep it (shown as "No icon").
- New - Classic Editor: "Users can choose their editor". Every user has a Default editor in the profile, the list of
  posts offers Edit (classic editor) / Edit (block editor), the editor each post was last opened in is remembered, the
  classic edit screen has a link to the block editor and the block editor a menu item for the classic one. The stored
  values are those of the Classic Editor plugin (user option `classic-editor-settings`, post meta
  `classic-editor-remember`), so a site that used the plugin keeps every choice. New setting "Site default editor".
- New - Maintenance Mode: countdown on the page (days / hours / minutes / seconds, no external script) to a date and
  time of the site; the end also sets Retry-After of the 503 page; optionally the site opens by itself at the end (off
  by default, the saved mode is not changed, only ignored after the end); addresses that stay open (exact path,
  `/section/*`, `*text*` or `regex:`), RSS feeds, and IP addresses / ranges that see the normal site. The background
  image that was already there is used by the migration.
- New - Folder Manager: folder colours and manual order (up / down in the sidebar and on the settings screen, explicit
  order through the API); folders for posts, pages and other post types keep one tree per type, as before.
- New - Settings: a date and time field type.
- New - Migration: Yoast Duplicate Post (status of the copy, excluded fields incl. the template switch, excluded
  taxonomies incl. the format switch, roles with copy_posts), Duplicate Page (all post types), Post Duplicator (same
  status, excluded fields, all types, roles with duplicate_posts); WP External Links (target, rel tokens, Dashicon and
  its position, skip images, subdomains, excluded URLs, "switched off" state); Classic Editor ("users choose" and the
  block default); WP Maintenance Mode (background image from the library, countdown end, excluded addresses and IPs),
  Under Construction (end date opens the site), Maintenance (planned end, whitelisted URLs and IPs), SeedProd (access
  by role and the open addresses of the page); FileBird, Folders and Wicked Folders (colours, order, folders of posts,
  pages and other post types, FileBird post type taxonomies), Real Media Library (order).
- New - Redirects: every rule counts its hits and remembers the time of the last one (shown in the list, sortable
  through the API, can be reset per rule or for all). The count is written after the redirect is sent, so it never
  delays it. A rule can be case sensitive (default: letter case is ignored) and can handle the query string in its
  own way: by the module setting (default), exact (a request with other parameters does not match), ignore, or
  ignore and pass on to the new URL. Existing rules behave exactly as before. CSV, REST, the AI tools and the MCP
  server take the new fields (a new tool resets the counters).
- New - Code Snippets: run conditions per snippet (all must hold): type of page (front page, posts page, single,
  archive, author, search, 404, wp-admin), post type, specific posts, logged in / out, role, mobile / desktop and a
  time window. They narrow where a snippet runs, never widen it; PHP still never runs in REST, AJAX or cron. A PHP
  snippet with a page or post condition is loaded at the `wp` action.
- New - Menu Icons: the whole WordPress Dashicons set next to Lucide, picked on Appearance > Menus. The stylesheet
  comes with WordPress and is loaded on the front end only on pages whose menu uses a Dashicon.
- New - Security: exceptions to "Disable comments": roles that may still comment (and visitors who are not logged
  in), pingbacks / trackbacks that stay allowed, comment types to refuse (pingback, trackback, product review) even
  without disabling comments, and closing comments on posts older than N days. The module filters whether comments
  are open; the WordPress options for closing old posts are never written.
- New - Migration: Redirection case sensitivity, query string handling and hit counters with the last hit; WPCode
  conditional logic (one AND group; "hide" on a single rule) and device type, Header Footer Code Manager targeting
  (site wide with exclusions, specific posts and pages, post types, home, archives, search, device); Menu Icons
  Dashicons 1:1; Disable Comments role exceptions, allowed and refused comment types and closing after N days.
  What cannot be expressed (several OR groups, taxonomy terms, page URLs, categories, schedules) stays reported and
  the snippet stays switched off for review.
- Fixed - Migration: the plan said Redirection rules with "case insensitive" were case sensitive (the plugin's
  flag means the opposite).

- New - Admin Customiser, Menu Organizer: sub-items can be renamed, hidden for chosen roles (or everybody), moved under
  another parent and re-ordered; every item and sub-item can require a capability and be shown or hidden for single
  users (by login). Hidden sub-items are refused by address with "Block access to hidden pages" like top-level items;
  users who can manage options and UX Studio, Plugins, Users and Settings stay protected.
- New - Admin Customiser: own CSS for the admin area and for the login page (plain CSS, HTML tags stripped, 50 KB,
  never printed on the front end), login background image with fit, hover colours of the button and the links, hidden
  meta boxes on the edit screens per post type and role (the list of boxes is learned from the edit screens you open).
- New - Dashboard Widgets: widgets hidden per role (on top of the global list) and a custom welcome panel (text with
  basic HTML, optionally only for some roles).
- New - Admin Columns: columns of post type lists can be sortable and filterable (custom field, taxonomy, author, ID).
- New - Migration: Admin Menu Editor (renamed, hidden and moved sub-items, extra capability, rules for single users),
  Adminimize (sub-menu items and dashboard widgets per role, meta boxes per post type and role), White Label CMS
  (login background, hover colours, own CSS of login and admin, widgets per role, welcome panel), LoginPress
  (background image, hover colours, own CSS) and Admin Columns (sorting and filtering) now transfer what they could not.
- Fixed - Admin Columns: the column key suggestions failed with an error on some requests.
- New - Admin Customiser: "Block access to hidden pages" (off by default). A menu item the Menu Organizer
  hides from a role (limited to other roles, hidden for everybody, or in a group limited to roles) is also
  refused when its address is typed in ("Sorry, you are not allowed to access this page", 403). Sub-items
  follow their parent, except a sub-item moved to the top level that the user can see. Never blocked: users
  who can manage options, the dashboard, profile, UX Studio, Plugins, Users and Settings, AJAX, REST. Rescue
  constant `UXSTUDIO_DISABLE_MENU_LOCK` in wp-config.php.
- New - Menu Visibility: besides logged in / logged out an item can be shown only to some roles or hidden
  from some roles, and several conditions can be combined (all must hold). Children of a hidden item are
  hidden with it. Existing settings keep working.
- New - Migration: Admin Menu Editor and Adminimize switch the access lock on when the old plugin really
  blocked the pages (Adminimize does by default, "Allow Page Access" turns it off); Nav Menu Roles, If Menu
  and Menu Items Visibility Control roles and combined rules become Menu Visibility rules (PHP expressions
  stay lossy, only a few fixed forms are recognized without running anything).
- New - Migration: when Adminimize (or Admin Menu Editor with "Access" unticked) switches "Block access to hidden pages" on,
  UX Studio remembers where it came from. Admin Customiser shows a note under the toggle ("Switched on by the
  migration from the plugin Adminimize ... You can switch it off") with a link to the migration history, the
  Menu Organizer tab shows a badge with the state, and the migration check says where the setting is. The note
  disappears on rollback, when the switch is undone, or when you change the setting yourself.
- Fixed - Adminimize migration read "Allow Page Access" the wrong way round.
- Fixed - Security: a percent-encoded dot segment (`/complianz/v1/%2e%2e/wp/v2/users`) is recognised again
  when the REST API is restricted (the route was sanitized before the check).
- Fixed - The Czech translation of the e-mail about a changed login address was missing.
- Fixed - Security: the custom login address could be bypassed with a look-alike `action` (`POSTPASS`, `post.pass`);
  only a real password form of a protected post (POST, `action=postpass`) is let through.
- Fixed - Security: CSP auto rollback counts reporting networks (IPv4 /24, IPv6 /64) instead of IP and user agent;
  "approve all safe" no longer approves hosts on shared or user-content domains (CDNs, buckets) for scripts, frames and workers.
- Fixed - Security: the Menu Organizer lock reads the post id and page the way WordPress does (query string `post`, body `post_ID`).
- Fixed - Security: over MCP, enabling or disabling a security module needs the admin scope; more settings that guard the site are admin-write.
- Fixed - Security: one shared check for public IP addresses in all places that download from a visitor-supplied URL.
- Fixed - A harmless duplicate key or column error no longer blocks a module's database upgrade; the expected tables are verified.

- New - Redirects: a rule can have conditions (logged in / logged out, role or capability, referrer,
  user agent, IP address or CIDR range, cookie, domain, HTTP header; text conditions are "contains",
  "is exactly" or a regex, each can be inverted). One old URL can have several rules, the first one
  whose conditions hold wins and a rule without conditions is the fallback. The URL tester can
  simulate a visitor, the AI tools and the MCP server take the same fields.
- New - Code Snippets: a snippet can be a shortcode [uxstudio_snippet id="N"], can be inserted before
  or after the post content (for chosen post types) or can run once (on the next admin page, then it
  switches itself off and stores the result). PHP never runs in REST, AJAX or cron requests.
- New - Migration: Redirection login / role / referrer / agent / cookie / IP / server / header rules
  become rules with conditions; Code Snippets "content" and "single-use", WPCode manual and
  before / after content, Header Footer Code Manager before / after content and shortcode snippets
  are transferred (run-once snippets are never switched on automatically).

- New - Security: two-step verification (authenticator app, 6-digit code, recovery
  codes) that every user switches on in their own profile; optional requirement per
  role with a grace period (nobody is locked out), reset by an administrator on the
  Users screen, rescue constant `UXSTUDIO_DISABLE_2FA` in wp-config.php. Application
  passwords are not affected.
- New - Security: lockouts can grow with every repeated lockout of the same IP within
  24 hours (up to a maximum), and the lockout e-mail can go to its own address.
- New - Security: CAPTCHA also for comments and for WooCommerce login, registration,
  lost password and checkout, with a choice of widget theme and size.
- New - Migration: allow and block IP lists of Limit Login Attempts Reloaded and
  Loginizer go to the Firewall (blocked addresses wait for the switch), together with
  progressive lockouts and the notification address; the CAPTCHA plugins' comment and
  WooCommerce forms and widget look are transferred too.
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
  still switches the feature off. Refinements after the update review: the e-mail and the notice are only sent to
  sites that had the address configured BEFORE this release (an upgrade routine records that on the first request of
  the new code, so setting it up later never triggers them) and are worded neutrally ("is in force", not "has
  changed"); the password form of protected posts (`wp-login.php?action=postpass`) is never blocked and never
  rewritten to the secret address (it would have leaked the slug into every public page); on a site with plain
  or `/index.php/` permalinks the address cannot be served, so the lock is NOT enforced (wp-login.php stays open) and
  administrators get a notice to choose pretty permalinks (before, wp-login.php was closed for good there); the
  slug-collision entry in the Activity Log is written at most once a day (it used to be one row per request).
- Change - Update from 0.5.0, Security / CSP: the schema step of the violation table no longer rates every stored row
  in the first request after the update. The structure (dbDelta) is changed at once, the old rows are re-rated 200 at a
  time by a short WP-Cron chain protected by a lock. Rows that existed before the update never trigger the high-risk
  e-mail, only new reports do. CSP rows are still purged automatically: resolved ones after 90 days, any after 180
  days. A site that already ran report-only or audit in 0.5.0 starts counting the days for the Enforce gate from the
  update (before, the gate showed "0 days" forever). A site that already ran Enforce keeps `enforce_since = 0`, so the
  72 hour auto-rollback does not apply to a policy that was enforced before the rollback existed.
- Fix - Module tables: a module's schema version is no longer recorded when a CREATE / ALTER TABLE failed (the missing
  table or column would never have been created again). The failure is retried every 15 minutes, written to the PHP
  error log and, once a day, to the Activity Log (`db_migration_failed`). Behaviour on success is unchanged.
- Change - Migration wizard: the undo dialog lists the modules and tables it restores and states that everything changed
  there after the migration is lost; when a setting or table changed since the run (or that cannot be told) the button
  stays off until the user ticks that they understand it. A fingerprint of the covered options and tables is taken when
  the run finishes.
- Change - Migration wizard: the hint on the Modules screen reuses its scan for an hour (cleared when a plugin is
  activated, deactivated or deleted, or a migration runs) instead of scanning every foreign plugin on each visit, and
  the "has data" checks of the e-mail log and redirect sources use `LIMIT 1` instead of `COUNT(*)`.
- Dev - Release package: the zip no longer contains `tests/`, `bin/`, the internal documents (PLAN, DESIGN, AUDIT,
  PARITY), `languages/.build` and build configuration, and the workflow fails when any of them slips in. Every test
  file exits unless run from the command line or WP-CLI.
- Dev - Tests: `tests/{mcp-core,migration-sources,migration-data-sources,migration-preview,migration-admin-sources,overlap-catalog,csp-logic,wizard-autostart,login-url-notice,update-impact}.php`
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
