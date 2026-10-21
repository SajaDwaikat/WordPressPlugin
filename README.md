# SEO Pilot – SEO Assistant for WordPress

A **local-first SEO audit and approval-based optimization assistant** for WordPress, with an optional AI-assisted Content SEO module. It finds SEO problems on your posts and pages, prepares fixes, and applies them **only after you approve**, with a history you can roll back.

> **Status:** work in progress (plugin header version `0.3.0`). It is not published on WordPress.org. Test on a staging site before using it on a live site.

## What it does

- **Audits locally.** About 60 checks on stored post content run on your own server; nothing is sent anywhere for the audit. Every finding says what was found, why it may matter, and what to do, and items a machine cannot judge are marked *needs a human*.
- **SEO titles and meta descriptions:** length, estimated search-result width, keyword placement, duplicates and near-duplicates, alignment with the content. Each check says whether it was *measured*, an *estimate* or a *rule of thumb*.
- **Headings, content and links:** heading structure, readability, repeated text, internal-link suggestions, similar-content detection.
- **Images and alt text:** missing, empty, duplicate or poor alt text; reuse the Media Library's alt text; mark decorative images; optional AI drafts that read the image.
- **Multilingual sites:** Arabic and English text handling, language and translation checks, a Language panel (reads Polylang or WPML, or link translations by hand), a hreflang check in the Live check, and optional hreflang output.
- **AI recommendations (optional):** titles, descriptions, paragraph rewrites, a localized title and description from a translation, and advice-only reviews. You bring your own OpenAI-compatible endpoint and key.
- **Fix assistant (Assisted mode):** lists the fixes available for a page, prepares them, applies the ones you tick, re-runs the audit and tells you whether the problems are gone, with one-click rollback.
- **Search Console data:** import a CSV export to see real queries next to the audit (the plugin has no search data of its own).

## Safety by design

- Nothing changes on a page until you accept a proposal **and** confirm it. Pages that are live, or whose SEO fields belong to another SEO plugin, need an extra confirmation.
- Only a fixed list of fields can ever be written: the post content, the SEO title and the meta description. Canonicals, robots directives, structured data, slugs and dates are never touched.
- Every change is recorded, a WordPress revision is saved first, and changes can be rolled back (it stops if the field was edited since).
- AI is called **only when you press a button**. Settings require a confirmation that page content is sent to your provider; sending images has its own separate switch and confirmation. Your API key is stored encrypted when libsodium is available, or can live in `wp-config.php`.

## What it does not do

- No keyword research, search volume, rankings or traffic numbers, and it never estimates them.
- It does not create canonical tags, robots rules, sitemaps, structured data (schema) or redirects. It only reads and reports on some of them.
- It does not create translations or manage translation plugins; it reads from them or lets you link pages yourself.
- Automatic (unattended) fixing is **not built yet**. At present everything is manual or assisted.

## Requirements

- WordPress 6.0 or later and PHP 7.4 or later (the code has been run and tested on PHP 8.x only).
- PHP extensions: `dom` (required), `mbstring` (strongly recommended), `json`. `sodium` is used to encrypt the API key when available.
- For AI features: outbound HTTPS to your AI provider.

## Install

This repository contains the plugin's source files. To install it:

1. Copy these items into a folder named **`seo-pilot`**: `seo-pilot.php`, `uninstall.php`, `readme.txt`, `assets/`, `includes/` and `languages/`. (Leave out `tests/`, `docs/` and `CLAUDE.md`; they are for development.)
2. Zip the `seo-pilot` folder, then in WordPress go to **Plugins → Add New → Upload Plugin**, or place the folder in `wp-content/plugins/`.
3. Activate **SEO Pilot**, then open **SEO Pilot → Settings** to add your AI endpoint, model and key (optional) and your site languages.

GitHub's *Download ZIP* button also works for trying the code, but WordPress will name the plugin folder after the branch, and the ZIP includes development files. See [docs/INSTALL.md](docs/INSTALL.md) for the full setup guide.

## Repository layout

```
seo-pilot.php        Main plugin file (header, constants, boot)
uninstall.php        Optional data removal when the plugin is deleted
readme.txt           WordPress-style readme
assets/              Admin CSS
includes/
  class-seo-pilot.php, class-seo-pilot-loader.php   Boot and class-map autoloader
  core/              Settings, installer, utilities, language model
  audit/             Audit engine and analysers (titles, images, languages, fixes, verification)
  ai/                AI client, prompts, response validation, rate limiting
  storage/           Recommendations, history, imported search data
  revisions/         Approval workflow: plan, apply, roll back
  admin/             Menus, screens and form handlers
  compat/, intent/   Other SEO plugins' fields; search-intent rules
languages/           Translation files (none yet)
tests/               Unit tests (no WordPress needed) and integration tests
docs/                Architecture, testing and upgrade notes
```

## Development

- **Unit tests** need only PHP, not WordPress:

  ```bash
  php tests/run-unit.php
  ```

- **Integration tests** run inside a real WordPress and create posts, so use a throwaway site only. See [tests/README.md](tests/README.md) and [docs/TESTING.md](docs/TESTING.md).
- Architecture notes: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). Contributor guidance for Claude Code: [CLAUDE.md](CLAUDE.md).
- Every new class must be added to the class map in `includes/class-seo-pilot-loader.php`.

## Branches

| Branch | Purpose |
|---|---|
| `main` | Stable branch. Changes arrive through pull requests from `dev` |
| `dev` | Development and testing. New work lands here first |
| `v1` | Preparation of the first proper version 1 release |
| `plugin-version-1` | The original 0.1.0 MVP upload, kept for reference |

## License

GPL-2.0-or-later, as declared in the plugin header.
