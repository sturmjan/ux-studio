# Changelog

## 0.8.9

- Fixed - Custom login address: the page is now marked as not cacheable (DONOTCACHEPAGE and no-cache headers). A page
  cache plugin (found live with WP Rocket) took the address for an ordinary page and kept serving a stored copy of the
  login page, so a changed design or logo did not appear and one copy of the form and its tokens was shared by all
  visitors. After the update clear the cache of the login address once.
- Fixed - Custom login address: wp-login.php is loaded with its global variables ($error, $user_login, $action,
  $interim_login) declared, so every load of the address no longer writes "Undefined variable" warnings to the error log.

## 0.8.8

- Fixed - Login page, split layout: the logo was drawn twice, in the round badge above the form and again over the
  media panel. When "Split layout: show logo over the media panel" is on, the badge now shows its default icon and
  the logo appears only once (over the media). With that option off the badge shows the logo as before. The classic
  layout is unchanged.
- Fixed - Starter pack, "Hide WordPress": it no longer touches a login page that already has its own design. When the
  login page has any of: a layout other than classic, a logo, a background or split media image, a split media address,
  custom CSS, a message above the form, a login color, or customization already switched on, the pack leaves all login
  keys alone (it still hides the admin bar logo, sets the footer text, hides the Help tab and the WordPress version and
  cleans the page head) and says so: "The login page already has its own design, the site logo was not added to it."
- Changed - Starter pack: on a plain login page the site logo is still put on it, but it is now said out loud, as a
  separate line in "What will be set" and in the result, with how to remove it (clear the logo in Admin Customiser >
  Login page, or switch off login screen customization).
- Fixed - Settings: an image field (for example the login logo) now shows a small preview of the chosen image next to
  its buttons. Before, an image set outside the form (such as the site logo set by the starter pack) looked empty.
- Existing sites where the starter pack already put the site logo on the login page are not changed. To remove it,
  open Admin Customiser > Login page and press "Remove" at the login logo.

## 0.8.7

- Added - Admin Customiser, "Admin screens": **Hide Rocket Insights (WP Rocket)**. One toggle (off by default) turns off
  Rocket Insights through the official WP Rocket filter `rocket_rocket_insights_enabled`: the tab on the WP Rocket
  settings page, the score and recommendations in its sidebar and dashboard, the column in the post lists, its notices and
  the scheduling of tests are gone. Caching and the rest of WP Rocket are untouched and nothing of WP Rocket is edited;
  switching the toggle off brings it all back. The option is part of the settings export and import.

## 0.8.6

- Fixed - Custom login address: "Lost password" (and the registration confirmation) ended on a page that does not
  exist. WordPress redirects to the relative `wp-login.php?checkemail=confirm`, which it resolves against the custom
  address into `/administrace/wp-login.php`. Such redirects now land on the custom address
  (`/administrace/?checkemail=confirm`); the protected-post password form and every other redirect are untouched.
- Fixed - Shield CAPTCHA: the tick of the "I am not a robot" checkbox sat off the centre on the login page (WordPress
  draws its own tick for a smaller box). The checkbox is now drawn by the plugin, centred, in light and dark mode.

## 0.8.5

- Changed - The PHP version is shown only when there is a problem: the green "PHP x.y.z, no known security problem" card
  in the first step of the new-site wizard and the "PHP x.y.z is up to date" row in the Security module are gone. A safe
  version needs no message; a vulnerable or soon unsupported version still gets the red or yellow card, the dashboard
  widget, the notice and the admin bar node.

## 0.8.4

- Changed - Dashboard Widgets, "Hidden widgets": one table of every widget the dashboard has (WordPress, plugins, UX Studio)
  instead of a "hide specific" list and one multi-select per role. Each row has **Hide** (for everybody) and **Show to**
  (roles; default "Everybody", no limit). A widget limited to roles is shown only to users who have at least one of them.
  Search, filters (hidden, limited to roles, not found, by source), counters, bulk bar (hide, show, show to roles, reset,
  select all / none), "Revert changes", sticky header, cards on narrow screens. Changes show at once and save in the
  background. "Hide all dashboard widgets" and the welcome panel stay where they were.
- Added - The widget list is a stored snapshot taken when an administrator opens the Dashboard (late, before anything is
  removed), with title, area and a guess of the source (plugin from the file of its callback, marked "estimate"). "Refresh
  list" loads the Dashboard in the background; widgets missing in the latest scan are shown dimmed as "not found" (the rule is
  kept, the entry can be removed). REST `GET/POST dashboard-widgets/rules`, `POST dashboard-widgets/refresh`,
  `DELETE dashboard-widgets/snapshot/{id}`.
- Changed - UX Studio's own widgets (Tasks, Notes, PageSpeed, Security, the widgets of other modules) are in the same table and
  follow the same rules; "Hide all" still leaves them alone.
- Changed - Settings saved before 0.8.4 are converted once: widgets hidden for everybody stay hidden; a widget hidden for some
  roles is now shown to all the other roles (hidden when every role hid it). The old keys stay readable and are no longer used.
  A notice explains the conversion. Export and import carry the new `widget_rules` (unknown roles and bad ids are dropped,
  at most 300 rules); a file from an older version with the old keys is converted on import.

## 0.8.3

- Added - **UX Studio Shield**, a CAPTCHA with no keys, no account and no cookies (Security > CAPTCHA, provider "UX Studio
  Shield"). The browser solves a small proof of work (SHA-256, in a Web Worker, plain JS on the main thread when a worker is
  not allowed); the server signs the challenge (HMAC), checks the answer, refuses an expired, too fast (fill time, default
  1.2 s) or replayed answer (one-time use, atomic) and an invisible honeypot field. Difficulty Easy / Medium / Hard
  (highest number 50k / 150k / 500k), one step harder for an address with recent failed logins. Public route
  `GET uxstudio/v1/shield/challenge` (rate limited per IP, never cached). Without JavaScript, or when the check cannot run,
  the login, registration, lost password, gate and WooCommerce account forms show a simple signed sum instead, so nobody is
  locked out. It stops mass scripted attempts; it does not tell a person from a determined script.
- Added - Provider "Automatic" (default for a fresh install; a site that already stores CAPTCHA settings keeps Turnstile):
  keys from the central application while connected, then own keys, then the Shield. New setting "Type of your own keys".
- Added - The CAPTCHA field of the Forms module no longer depends on the Security CAPTCHA switch: central application keys,
  then own keys, then the Shield. The field inspector shows what is used and offers "Get keys from the central
  application" (`POST forms/captcha/provision`); the keys go into the same store as the login CAPTCHA keys. A form with a
  CAPTCHA field now always protects (before, it did nothing without the switch and keys).
- Added - Starter pack item "CAPTCHA without keys" (login, registration, lost password, comments when the pack leaves them
  on). Left out when a CAPTCHA is already set up, a CAPTCHA plugin is active or the site is behind a non-Cloudflare proxy.
- Changed - CAPTCHA settings screen hides the key fields for the Shield and the Shield settings for providers with keys.

## 0.8.2

- Added - Starter pack, item "Security hardening and cleanup": these protections are now switched on for a NEW site
  (sites that already applied the pack keep their settings; applies only when the pack is applied):
  - "Lock out IPs that try a blocked username" and "Block reserved usernames" (together with the login attempt limit).
    Left out when the limit is not applied (unknown reverse proxy, a login plugin does it) and when an existing account
    already has a reserved name: a failed login with such a name locks the IP at once, also for that real account, so
    one typo in its password would lock the owner out.
  - "Harden the login page": generic error for a wrong username or password, Application Passwords switched off (new
    ones cannot be created; switch it off in Security > Login to use MCP or REST clients that sign in with them).
    Left out when any user already has an Application Password.
  - "Disable RSS feeds" (feeds answer 403, feed links leave the page head). Left out with more than 5 published posts or
    when a plugin that needs feeds is active (Mailchimp for WP, Seriously Simple Podcasting, PowerPress, Feedzy,
    FeedWordPress, WP RSS Aggregator).
  - "Protect wp-config.php", "Disable directory listing" and "Block readme.html / license.txt" (also wp-config-sample.php)
    through `.htaccess`, only on Apache or LiteSpeed with a writable file.
  - "Block user enumeration" (`?author=N` and the public REST users list; the core users sitemap is not changed).
    Left out when WPGraphQL, Faust.js or WPGatsby is active.
  - "Hide author slugs" (author pages get a code instead of the user name; old `/author/name/` addresses answer 404).
    Left out with more than 5 published posts.
  - "Permissions-Policy" header. Left out with WooCommerce or when a header plugin is active.
  - "Force HTTPS", only when the site address and this request are https, plain http answers, and no unknown proxy is in
    front. A redirect loop (for example Cloudflare in the Flexible mode) is detected and the setting is taken back.
  - The canonical URL redirect and HSTS stay off on purpose.
- Added - Safety net for the rules the pack writes into `.htaccess`: after saving, the site asks itself for its home page
  and wp-login.php; on an HTTP 5xx the file is put back byte for byte, the pack retries once without "Disable directory
  listing" (the line some hosts refuse) and, if that fails too, takes all `.htaccess` rules back. When the site cannot
  ask itself, the item says so. After success the rules are verified (403 on wp-config.php and readme.html, no file list
  in uploads, redirect to https, closed users route) and the result is shown as "Verified" / "Could not be verified".
- Added - Plain-language explanation of every option of the item: what it does, the risk, when to switch it off and
  when the pack leaves it out. The same wording (one PHP class, `SettingHelp`) is shown under the option in Security
  ("Details") and in the wizard under "What will be set"; options left out on the site are listed with the reason.
- Fixed - "Harden the login page" no longer hides the messages that explain why a login is refused: the lockout of the
  attempt limiter, CAPTCHA and two-step verification keep their own text. Only the wrong username and wrong password
  errors become generic.
- Changed - The `.htaccess` rules for wp-config.php and the sensitive files use the Apache 2.4 syntax with the old
  syntax as a fallback (`<IfModule mod_authz_core.c>` / `<IfModule !mod_authz_core.c>`): without the compatibility module
  the old `Order`/`Deny` lines gave HTTP 500 for the whole site. Sites that use these options get the new rules written on
  the next visit to the admin.
- Changed - Overlap catalog: Disable Feeds (full, for "Disable RSS feeds"), Stop User Enumeration (full, for "Block user
  enumeration") and Disable Application Passwords (information only, for "Harden the login page").

## 0.8.1

- Added - Notice history: the bell panel of hidden admin notices now remembers what it caught. A new page "Notice
  history" (Admin appearance > Admin customiser > Notice history, and a "Show history" link in the panel footer) lists
  every notice with its type, title, text, estimated source plugin, state (active, closed, gone), first and last
  sighting, how many times it was shown and who closed it. Filter by state, type, source and period, search the text,
  page through 20 at a time, delete one or many records, clear the whole history, export a CSV (safe to open in Excel)
  and show a notice that was hidden by the panel again. Notices closed through the plugin's own control are marked
  "closed by the plugin" and cannot be restored from here. A notice that has not been seen for 7 days is marked gone;
  records are kept for 90 days after the last sighting (at most 1000). Only administrators can read it, nothing is
  recorded while hiding of notices is off, and the history is never part of a settings export.
- Changed - Notices that the panel's cross closes without a control of the plugin stay out of the panel until you show
  them again on the history page (before, they came back on the next page load).

## 0.8.0

- Added - Export and import of settings (Settings > "Export and import"). Export: tick the modules (grouped like the
  Modules screen, search, "Select all", a badge for modules that are off), choose what goes in (on/off state of the
  modules, module content, secret values) and download a JSON file or copy it. Every module's header has an "Export this
  setting" link that opens the export with that module ticked. The file only holds settings declared by each module's
  schema; the Central App connection, licence and OAuth tokens, CAPTCHA keys of a host name, ids of pages, posts and
  users, wizard markers, caches, logs and database versions never travel (the file lists the fields to fill in again).
  Secret values (API keys, passwords) are off by default; when you turn them on they are encrypted with a passphrase of
  at least 12 characters (libsodium, argon2id; the option is off with an explanation on a server without the sodium
  extension). Module content that can travel: form definitions, redirect rules, code snippets (need `unfiltered_html`),
  popups, locations with opening hours and notice categories; never submissions, logs or statistics.
- Added - Import: drop or paste a file, see a server-side preview with a diff per module (old and new value in words,
  secrets masked, counts of changes and unchanged settings, what is skipped and why), pick modules, choose "Merge" (only
  the listed settings) or "Replace the whole setting", optionally switch modules on and off as in the file (through the
  same overlap check as the Modules screen), then import. A snapshot is taken first and "Undo the import" restores it for
  30 days. Safety measures: the Content-Security-Policy is never switched to Enforce, a new login address starts in the
  pending state (wp-login.php stays open until confirmed) and is checked afterwards, forced two-factor login needs your
  own second factor, country blocks need your IP on the allowlist, the menu lock must keep key screens open for you,
  maintenance mode needs an extra tick, code needs `unfiltered_html` (snippets arrive switched off), HTTPS redirects need
  an HTTPS session. Everything is saved through the module's own save path, never straight into an option.

## 0.7.6

- Fixed - Quick search: the field in the admin bar is dark again, like the rest of WordPress' bar (it takes the bar's
  text color and a translucent tint of it); only the results dropdown stays light.
- Fixed - Hidden admin notices: notices of plugins that use the Freemius SDK (their own "Dismiss / Skrýt" block) now get
  the same cross as all other cards and can be closed from the panel; the plugin's own dismissal still runs. Other
  plugins' dismiss links (buttons or links with "dismiss", "close" or "hide" classes) are recognised the same way.

## 0.7.5

- Changed - Admin Customiser, "Quick search": the dropdown is redesigned, light in every admin color scheme. Compact
  36 px rows with a type icon (post, page, product, other content, user, tag), a thumbnail for images and the avatar for
  users, the title as stored (markup stripped, emoji images replaced by their character, the match highlighted) and a
  muted label on the right ("Page", "Product tag", "PNG"). The whole row opens the item (Edit when you may edit it,
  otherwise View); small pencil and eye buttons appear on hover and on the keyboard-active row, Shift+Enter takes the
  second action. Group headings stay at the top while scrolling and show the number of matches, at most 5 rows per
  group, and "Show all (N)" opens the matching admin list with the search. Loading shows skeleton rows, empty results a
  short message; "WordPress commands..." is a footer that stays visible. The open field is a light pill with the
  magnifier inside.
- Changed - Admin Customiser, "Hide admin notices" (tray in the admin bar): the notices dropdown is light and compact. Each
  notice is a card with a bar by type (error red, warning amber, success green, info blue), the foreign plugin's
  backgrounds, colors, labels, icons, logos and large paddings stripped, links and buttons restyled as small controls,
  three lines with "Show more", and an "x" that triggers the plugin's own dismiss button (so its AJAX dismissal still
  runs). Header with the count, empty state "No notices", a smaller bell badge. The panel now lives in the page body
  under the bell, so it no longer inherits the admin bar's styles. The critical PHP version notice still stays on the
  page.
- Fixed - Admin Customiser, "Hide admin notices": notices that core or a plugin moved back into the page after they had
  been moved to the tray (core does this for every notice on DOM ready) could disappear instead of staying in the tray.
- Fixed - Admin Customiser, "Quick search": WordPress emoji images in a title no longer show as broken images.

## 0.7.4

- Changed - Form Builder, "Conversion tracking": the Sklik consent flag is no longer a fixed value. When the conversion
  fires, the plugin reads the visitor's real choice from the cookie banner on the page (Complianz, CookieYes, Cookiebot,
  Google Consent Mode `ad_storage`, or `window.uxstudioConsent.marketing`). The new setting "Consent for Sklik" is
  "According to the cookie banner (recommended)", "Always 1" or "Always 0", with a fallback for pages where no banner is
  found (default 0, so Sklik counts the conversion without personalization). Forms that had Sklik on keep their fixed
  1 or 0; new forms use the banner. Meta Pixel and Google Ads are unchanged.
- New - Security, Content Security Policy: UX Studio's own inline scripts (the shared form script and every active
  form's custom "after submit" script) are allowed by a SHA-256 hash when the policy is strict, i.e. its `script-src`
  has no `'unsafe-inline'`. Hashes are cache safe (unlike nonces) and are added only to a strict policy, because a browser
  ignores `'unsafe-inline'` as soon as a hash is present. The CSP tab lists which scripts are covered; the list is limited
  to 200 hashes.
- Changed - Form Builder: the form configuration is printed as a JSON data block instead of an inline script per form,
  so a strict CSP has nothing extra to allow for it.
- Changed - Admin Customiser, "Quick search": one merged search control. A magnifier on the right side of the admin bar
  grows into an inline field (Ctrl+K / Cmd+K, Esc or a click outside closes it) with a keyboard-driven results dropdown.
  When quick search is on, WordPress's own command palette button is removed from the admin bar and Ctrl+K opens ours;
  the last dropdown entry "WordPress commands..." opens the core palette. Works with light and dark admin color
  schemes, and as a full-width row on small screens.
- Fixed - Admin Customiser, "Quick search": on the front-end admin bar the field did nothing, because WordPress prints
  the bar after the script ran.

## 0.7.3

- New - Security, "Delete unused default themes": removes unused Twenty* themes (the starter pack turns it on), but only
  those WordPress itself brought - part of the install or copied by a core update. A Twenty* theme installed by hand
  (upload, wordpress.org, WP-CLI, FTP) is never deleted; the origin of each default theme is tracked. The active theme,
  its parent, parents of other themes and the newest default theme (the fallback) always stay.
- Fixed - Form Builder, "Conversion tracking": the Google Ads fields Value and Currency now sit side by side.
- New - Form Builder, "Actions after submit": a new card "After submit" decides what the visitor sees after sending the
  form - a thank-you that replaces the form (default), a message above the form, or a redirect to a page or address.
  The thank-you has a title, a message written in the WordPress editor (bold, links, images from the Media Library),
  merge tags such as {name} (escaped on the server), an animated check mark or no icon, an accent color, a "Send another
  response" button and scrolling to the thank-you. A live preview with a light and dark background and "Play animation
  again" sits next to the settings. The animation respects "reduce motion", focus moves to the title for screen readers.
- Changed - Form Builder: the success message moved from Appearance to the new card; existing forms keep their text
  and their redirect action becomes the redirect mode (a form has one redirect, so two can no longer collide). Every
  template has its own thank-you title.
- New - Form Builder, "Conversion tracking": every counted submit fires a `uxstudio:form-submitted` DOM event and a
  dataLayer push `uxstudio_form_submit` (form id, title and submission id, never field values). Optional presets for GA4,
  Google Ads conversion, Meta Pixel and Sklik run only when that tracker is already on the page - the plugin loads no
  third-party script, so your cookie consent tool stays in control. With a redirect the conversion is sent first.
- New - Form Builder: a custom script after submit, edited with CodeMirror. Only a user with the `unfiltered_html`
  capability (on multisite a super admin) can write or change it; it runs after the thank-you is shown, in a
  `try/catch`, and needs a Content Security Policy that allows inline scripts.

## 0.7.2

- New - Form Builder: template gallery under "New form title" - nine ready-made forms (contact, service inquiry,
  booking, event registration, job application, order, newsletter signup, feedback and rating, complaint) with a live
  mini preview, field count, a "Multi-step" badge with the number of steps and category chips. A click creates the form
  with fields, widths for desktop, tablet and mobile, step breaks, a required GDPR consent where personal data is
  collected, an e-mail to the site admin and a confirmation to the sender, then opens the builder.
- Changed - Form Builder, public form: multi-step forms now work properly. Numbered progress (or a bar), Back and
  Next with validation of the current step, focus moves to the step heading, errors are announced and linked to their
  field, Enter moves to the next step instead of sending a half-filled form, a server error takes the visitor to the
  right step, and a thank-you panel replaces the form after sending.
- Fixed - Form Builder, public form: the tablet and mobile widths set in the builder are used (a 12 column grid), and
  two fields of 50 percent no longer wrap under each other.
- New - Form Builder, Appearance: presets "Card with header and footer", "Two columns" and "Numbered sections",
  frame (plain, card, one row), columns, section numbers and the text of the submit button. Radio and checkbox groups
  can be drawn as cards or as a row of buttons (for example a 1 to 5 rating); a step can have a description.
- New - Form Builder, e-mail notification: two columns with a live preview of the real e-mail (desktop and mobile),
  the message in the WordPress editor with Add Media and a merge tag menu, a header with a logo from the Media Library
  (left, center or right, width), header and link colors with a contrast warning. Works for the confirmation to the
  sender too. Images are sent with absolute addresses; scripts, event handlers and `javascript:` links are removed, and
  submitted values are escaped in the HTML body.

## 0.7.1

- Fixed - Forms: the module no longer fails to start on sites where Elementor is missing or not loaded yet (the
  Elementor widget class was loaded too early, a fatal error that skipped the whole module since 0.4.0).
- Changed - PHP version warning works with no module enabled: the daily refresh from php.net, the e-mail to the
  administrator (once when the site first turns critical, then at most weekly), the dashboard widget and the admin notice
  now run from the plugin core, so a freshly installed site is warned right after activation. The notice shows on every
  admin screen, also on the UX Studio page, and the Admin Customiser option "hide admin notices" never hides it. While
  critical it has no close button (only "Verified with hosting, hide for 30 days"); the end-of-support warning can be
  hidden per user. Administrators also get a red (critical) or yellow (warning) node in the admin bar, on the site and in
  the admin, hidden while the 30 day snooze is on. Visitors and users without `manage_options` get nothing. A site that
  updates from 0.7.0 keeps its single schedule (same cron hook) and the Security module no longer registers a second copy.
- Changed - Starter pack: the e-mail log (14 days), the last login of users and the Content Security Policy inventory
  (audit only, 20 percent sample, never enforces) are now ticked by default. The CSP item is left out when a CSP mode is
  already set, so report-only or enforce is never downgraded.
- Changed - Wizard step "Central application" is now a short presentation of what the central application does (nine
  cards, how it works, what changes on the site, questions) with a toolbar fixed to the bottom of the screen holding
  "Connect" and a clear "Skip".
- Fixed - Wizard last step: the success badge now animates in, the summary and module boxes are apart, and the module
  buttons show the module icon.
- Fixed - Form Builder: the "New form title" row (and the same row in Popup Manager, Opening Hours, Stock Photos,
  Activity Log, Redirects and DNS Manager) is one aligned line with a 46 px field and button; new shared form variants
  `uxs-form--stacked` and `uxs-form--inline`.
- Changed - Form Builder "Generate fields with AI": prompt card with a growing text box, a multi-step option, a character
  counter (1000 at most, also checked on the server), example chips and Ctrl+Enter. A clear message with a link to the AI
  Assistant settings replaces the form when the module is off or no API key is set.
- New - Admin Customiser: "Hide the WordPress version in the admin footer" (off by default), part of the starter pack item
  "Hide WordPress". An update notice in the footer is never hidden.

## 0.7.0

- New - Wizard for a new site: on a fresh WordPress the plugin opens `#/wizard?mode=new` right after activation (it is
  also offered after a migration and as a hint on the Modules screen). It shows the detected environment (server,
  Cloudflare, proxy, WooCommerce, permalinks) and the PHP version check, then the recommended modules by topic with every
  preset value in words. Modules a foreign plugin already covers are left alone (or just that one setting is left out).
  Everything is applied item by item with progress, and "Undo everything" restores modules, settings and the
  login address from one snapshot taken before the first change.
- New - Starter pack: Security hardening (comments, file editor, XML-RPC, WordPress version, security headers, login
  attempt limit, revisions, Heartbeat, emoji), update e-mails, Hide WordPress, classic editor and widgets, tidy profiles,
  dashboard widgets, duplicate posts and menus, external links, AI readable site (llms.txt and Markdown), Bot Throttle
  (Ahrefs and Semrush are never slowed down), image optimization, media trash, stock photos, vulnerability scanner,
  activity log, indexing notice, SMTP and e-mail log, redirects, media replace, last login, hidden admin bar for
  subscribers and the CSP audit. Every item can be unticked; WooCommerce sites keep product reviews working.
- New - Custom login address `/administrace` in two steps: first the login form also works on the new address while
  wp-login.php stays open, and only when you confirm that you saw the form is wp-login.php closed (404) and wp-admin
  redirected to the new address. The address is e-mailed to the administrator. An unconfirmed address never locks you out.
- New - Hide WordPress: removes the WordPress logo from the admin bar, replaces the admin footer text with the site name,
  hides the Help tab, shows the site logo on the login page (no logo at all when the site has none) and removes the
  generator and other WordPress links from the page head (Security > Hide WordPress links in the head).
- New - Update e-mails: Security can switch off the automatic update e-mails WordPress sends to the administrator.
- New - PHP version check in Security: tells administrators when the PHP version of the site has a known critical
  vulnerability (the php.net advisory of 2026-10-21, CVE-2026-109618 and CVE-2026-109619, fixed in 8.5.12, 8.4.27,
  8.3.36 and 8.2.35), is no longer supported (8.1 and older, or past the end of security support) or loses support within
  90 days. A dashboard widget (red or yellow) and an admin notice without a close button; the notice can be hidden for
  30 days with "Verified with hosting" (a different PHP version shows it again, useful for distribution builds that
  backport fixes). One e-mail when the site first turns critical, then at most weekly. The bundled table is refreshed
  daily from php.net (short timeout, size limit, validated; it can only make the verdict stricter, and the last good data
  is kept on a failure). The result is also shown in Security > Site hardening and in the wizard, and the exact PHP
  version and the verdict are sent to the central app with the software inventory.
- New - Overlap rules: AI Markdown against the llms.txt of Yoast, Rank Math and All in One SEO (a full overlap only when
  their llms.txt is switched on) and Image optimization against EWWW, Smush, ShortPixel, Imagify, Converter for Media,
  Optimole (and LiteSpeed Cache or Jetpack when their image optimization is on).
- New - Connect the site to the Central App from the wizard (one-click connect): switches Content Sync to node mode,
  keeps or creates the node key, checks that the site answers the signed ping and posts the signed connect form to the
  Central App (key in the POST only, never logged). Optional CAPTCHA with Turnstile keys from the Central App (off by
  default). Administrator sign-in and package installation from the Central App stay off.
- Fixed - Undoing the starter pack (or switching Security off) now removes the whole `UX Studio Security` block from
  .htaccess, including the empty marker lines WordPress leaves behind.

## 0.6.0

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
  passwords are not affected. A login outside wp-login.php (for example WooCommerce) of
  an account with 2FA says so and points to the login page, and it is not counted as a
  failed login. Before the setup is done, only requests from the admin are blocked; the
  public site keeps working, and heartbeat can no longer autosave.
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
