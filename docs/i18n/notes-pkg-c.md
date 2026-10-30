# Package C notes (branch feature/audit-pkgc)

## CHANGELOG lines (EN, for 0.10.0)

- Added plain-language help (what it does, what changes, risks) for all 27 options of the Admin Customiser that had none, plus Clean Profiles, Dashboard Widgets and User Last Login.
- Admin Customiser: the meta box explanation is now shown once at the top of the card instead of under each of the 7 roles.
- Module list: plugin conflicts are collapsed into one summary line ("N conflicts with plugins", Show / Hide). Module names may wrap to two lines instead of being cut.
- Module list: all counts (All, tabs, Enabled, Disabled) now count features (things with their own switch), a note under the filters explains it, and the status filter also looks inside groups.
- Activity log and the dashboard widgets show module names, readable actions, object types and user names instead of internal identifiers; the log filters are selects with the values that really occur.
- User Last Login: the custom date format appears only when "Custom format" is selected and has an example.
- Global settings: removed the duplicate dark mode switch (the one in the sidebar stays), the language is shown by name, values line up with their labels.
- Color fields: swatch of the color in effect, "Default (theme color)" in plain text, a real Reset button.
- Names: "Classic WordPress screens" (group) vs "Classic post editor" and "Classic widgets screen" (features). Analytics and login texts now have English source strings with Czech translations.

## Baseline entries now covered (F: delete from tests/fixtures/help-baseline.json)

Module `admin-customiser` (all 25 keys): admin_bar_enabled, admin_footer_text, consolidate_plugin_actions, form_options, hide_admin_bar_elements, hide_footer_version, hide_help_tab, hide_notices_enabled, hide_screen_options, hide_sidebar_icons, icon_replacer_enabled, login_background_style, login_customization_enabled, login_form_border_radius, login_logo, login_remember_me_default, login_split_media_type, login_split_media_youtube_url, login_split_show_logo, move_site_link_right, move_updates_right, quick_search_enabled, quick_search_post_types, server_clock_enabled, server_clock_position.

Module `dashboard-widgets`: welcome_panel_title.

Also added (not in the baseline): admin-customiser admin_bar_logo and sidebar_width (were WEAK), clean-profiles sections (WEAK), user-last-login custom_format. The color fields of admin-customiser were not in the baseline (they already pass the coverage test through the card text); no extra help was written for them.

## Files outside my list that I touched (one line or one small hunk each)

- `src/app/SettingsForm.tsx`: `case 'color'` now renders the new `src/components/ColorField.tsx` (the color field lived here, not in a separate component) plus its import. Please keep this hunk when merging.
- `includes/Core/Transfer/Exporter.php` line 238: `NonPortable::manual_label( $connection['label'] )`. Needed because the labels are in a class constant that cannot call `__()`.

## Files F must handle

- `languages/`: apply `docs/i18n/cs-pkg-c.json` (159 entries, one plural). Module names and descriptions from `meta.json` are registered by hand in `languages/ux-studio-meta.php` (see the comment in `Core\Rest::translate_meta`), so these new msgids must go there as well: Visitor Statistics + its description, Classic WordPress screens + description, Classic post editor, Classic widgets screen, the descriptions of the Dashboard and Admin appearance groups.
- `src/style.scss`: optional cleanups, everything works without them because I used inline styles.
  - `.uxs-grid.is-list .uxs-tile__name { white-space: normal; overflow: visible; text-overflow: clip; flex: 0 1 260px; line-height: 1.3; }` (sub rows 244px) would replace the inline style of `ModuleRow` in `src/app/ModuleGrid.tsx`.
  - `.uxs-color` rules (`input.is-empty`, `code`) are no longer used by the color field and can be deleted.
  - A class for the plugin conflicts summary row (now an inline flex row inside `.uxs-overlaps`).
- Dark mode: the sidebar switch in `src/app/App.tsx` is the one kept. The "Appearance" block was removed from `GlobalSettings.tsx`. If F prefers the opposite, the sidebar button is in App.tsx (not my file).

## Verified facts

- 82 `meta.json` files = 63 top-level rows (9 groups + 54 standalone modules) + 19 features nested in groups; 73 features (leaf modules). The old "All (63)" counted rows, "Enabled (59)" counted rows where a group counts as on when any child is on. New counts are in features: All = 73, Enabled + Disabled = 73. The "z 53" in the export dialog is a different count (exportable modules); it belongs to the Transfer package and is not changed here.

## Open items

- `src/app/Overlaps.tsx` is not mine: the expanded conflict panel is still the old long list (it is shown only after "Show"). If F wants a more compact list, the change belongs there.
- Action labels in the activity log cover about 45 common codes; other codes (for example `gmail_connect`) fall back to a readable form ("Gmail connect"). New module owners can extend `Modules/ActivityLog/Labels.php`. The log's module filter now lists only modules present in the log.
- The English wording "Centrální aplikace" was replaced by "central app" in four Admin Customiser strings (old Czech translations in the .po no longer match, the new ones are in cs-pkg-c.json). Code comments in Login.php still say "Centrální aplikace" (harmless).
- The other group descriptions (Duplicate, Email, Export, Redirect tools, Reviews, Updates) still say "switched on separately"; I changed only my own groups to "turned on" (terminology Turn on / Turn off).
- Not covered by browser QA (the local site runs another worktree): the module list layout, color field and global settings should be checked once after the merge.
- Guide, IndexingNotice, PostIdDisplay, PostTypeOrder, PostTypeSwitcher, QuickAddPost, MenuIcons, MenuVisibility, HideAdminBar, AdminColumns: no missing help in the audit CSV (all OK), no change needed beyond the TypeScript fix in `src/modules/admin-columns/Page.tsx` (swap of two rows no longer yields `undefined`).
