# SEO Pilot: codebase overview (as implemented in 0.3.0)

Everything here was read from the source. File and line references are to the files in this folder.

## 1. Shape of the plugin

About 5,500 lines of procedural-style OOP PHP. No Composer, no JavaScript, no REST routes, no AJAX. The only front end asset is `assets/admin.css`. Every class is `final` with mostly `static` methods; the only singleton is `SEO_Pilot`.

```
seo-pilot.php                         entry point: constants, autoloader, activation hook, plugins_loaded
includes/class-seo-pilot-loader.php   class-name -> file map (hand maintained)
includes/class-seo-pilot.php          SEO_Pilot: wires hooks, prints <title>/<meta description> on the front end
includes/core/      Settings, Installer, Util
includes/compat/    Compat       which SEO plugin owns title/description, read/write their meta keys
includes/audit/     Text, Parser, Audit, Audit_Runner, Diff, Live_Check, Similarity, Gap, Links, Report
includes/intent/    Intent       rule-based search-intent guess
includes/ai/        AI_Client, Prompt, Validator, Rate_Limiter
includes/storage/   Recs, History, Searchdata   (the three custom tables)
includes/revisions/ Approval     plan / apply / rollback: the ONLY code that writes to posts
includes/admin/     Admin, Workspace (handlers), Workspace_View (screens), Admin_Data
uninstall.php                         optional data deletion
```

## 2. Entry points

| Entry | Where | What it does |
|---|---|---|
| Plugin load | `seo-pilot.php` | defines `SEO_PILOT_*` constants, `SEO_Pilot_Loader::register()`, hooks `seo_pilot_boot` to `plugins_loaded` |
| Activation | `register_activation_hook` -> `SEO_Pilot_Installer::activate` | needs `DOMDocument`, creates 3 tables, migrates 0.1.0 data |
| Boot | `SEO_Pilot::__construct` (`includes/class-seo-pilot.php:16`) | registers all front-end and admin hooks |
| Front end | `wp_head` (priority 1) and `pre_get_document_title` (priority 20) | prints SEO Pilot's meta description and title, only when no other SEO plugin is active |
| Admin pages | `admin_menu` in `Admin::menu` and `Admin_Data::menu` | menu "SEO Pilot": Overview, Content SEO, History, Settings, Search data, Report |
| Form posts | `admin_post_seo_pilot_*` | 1 in `Admin` (`run_audit`) plus 3 legacy ones that only redirect to Content SEO, 9 in `Workspace`, 3 in `Admin_Data` |
| Uninstall | `uninstall.php` | always clears transients; drops tables and meta only if the "delete on uninstall" setting is on |

All hook registrations in the plugin (complete list):

```
SEO_Pilot::__construct     init -> load_textdomain; admin_init(5) -> Installer::maybe_upgrade;
                           admin_init -> register_settings; wp_head(1) -> output_meta_description;
                           pre_get_document_title(20) -> filter_document_title
Admin::init                admin_menu, admin_enqueue_scripts, admin_post_seo_pilot_{run_audit, ai_suggest, apply_fix, rollback}
Workspace::init            admin_post_seo_pilot_{audit_post, save_page, generate, rec_action, save_alt, apply,
                           rollback_group, live_check, analyse_site}   (built in a loop, handler = handle_<name>)
Admin_Data::init           admin_menu(11), admin_post_seo_pilot_{import_search, delete_import, export_report}
```

Filters the plugin itself exposes: `seo_pilot_capability` (default `manage_options`), `seo_pilot_content_post_types` (default `post`, `page`), `seo_pilot_detected_seo_plugins`.

## 3. The core idea: recommend, then approve, then apply

```
 audit (local)            generate (AI or user)          approve                  apply
 Audit_Runner ──findings──> Workspace::generate ──rows──> Recs status=accepted ──> Approval::plan -> apply
 reads post_content        AI_Client + Prompt +           (Workspace::handle_       writes post meta / post_content,
 writes post meta          Validator                      rec_action)               writes History, re-audits
 _seo_pilot_audit          writes ONLY seo_pilot_recs                               rollback via History
```

Code that only produces recommendations or reports: `Audit*`, `Intent`, `Similarity`, `Gap`, `Links::suggest`, `Live_Check`, `Report`, `Prompt`, `AI_Client`, `Validator`, `Recs`.
Code that changes the website: `Approval::apply` and `Approval::rollback` (plus `Compat::set/write_key`, `Parser::replace_paragraph/set_img_alt` which they call), and the front-end output in `SEO_Pilot` which only *reads* what apply stored.

## 4. What is NOT implemented (verified by searching the source)

| Area | Finding |
|---|---|
| Canonical URLs | Not generated or changed. `Live_Check` only reads canonical tags from a fetched page and warns on duplicates. |
| Meta robots / noindex | Not output or changed. `Compat::robots_noindex` and `site_discourages_search` only read Yoast / Rank Math / legacy flag / `blog_public`. |
| XML sitemap | Not implemented. No code references sitemaps. |
| Redirects | Not implemented. (`SEO_Pilot_Util::redirect` is only the admin form post-redirect.) |
| Structured data / schema | Not generated. `Live_Check` only counts `application/ld+json` scripts. |
| Open Graph / Twitter cards | Not implemented. |
| Featured images / image processing | Not implemented. No code touches thumbnails, attachments or image files. Only `alt` attributes inside `post_content` are audited and edited. |
| H1 / heading edits | Headings are audited (`Audit::check_structure`) and the AI can propose an outline, but outlines are advisory; nothing rewrites headings. |
| Off-page SEO | No backlink data or integrations. Links are only classified internal/external in `Audit::classify_link` / `check_links`. |
| Search Console API | Not built. Only a CSV import (`Searchdata`, `Admin_Data`). |
| Title/description on non-singular pages | Not output (`is_singular()` is required). |

Features that do exist (title, meta description, alt text, internal-link insertion, paragraph rewrites, audits, AI, approval/rollback, Search Console CSV, reports) are indexed in `FEATURE_TO_CODE_MAP.md`.

## 5. Documentation set

- `FEATURE_TO_CODE_MAP.md`: feature -> files / classes / functions
- `CODE_WALKTHROUGH_PROGRESS.md`: ordered checklist of components
- `walkthrough/01-bootstrap-and-frontend-output.md`: component 1 in detail (more files are added as we go)
- Pre-existing, kept as is: `ARCHITECTURE.md`, `TESTING.md`, `INSTALL.md`, `UPGRADE.md`
