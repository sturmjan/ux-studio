# UX Studio

Modular WordPress admin platform - one consistent React SPA for all site tools.
Successor to the legacy "UX One - Wordpress customizer" plugin (full rewrite,
no WP Extended code).

## Stack
- PHP 8.1+, PSR-4 (`UxStudio\`), REST API only (`uxstudio/v1`) - no admin-ajax
- React + TypeScript (strict), `@wordpress/scripts`, TanStack Query, hash router
- lucide-react icons only; design tokens (`--uxs-*`) with mandatory dark mode
- i18n: English source strings, Czech (`cs_CZ`) translation, `@wordpress/i18n`
- Auto-updates from GitHub Releases (plugin-update-checker, pre-built zips)

## Development

```
npm install
npm start          # dev build with watch
npm run build      # production build into build/
```

## Docs
- `PLAN.md` - full rewrite plan and phase roadmap
- `DESIGN.md` - binding design-system rules for every module
- `AUDIT.md` - audit of all legacy modules + data migration map
- `languages/README.md` - translation workflow

## Module anatomy
See DESIGN.md §7. A module is `includes/Modules/<Id>/` with `meta.json` +
`Module.php` (extends `BaseModule`); optional REST controller and optional
lazy-loaded SPA screen. Reference implementation: `Modules/HideAdminBar`.
