# Feature -> code map (0.3.0)

Status: **Implemented**, **Partial**, **Report only** (detects/warns, never changes), **Missing**.
"Applies" means it can change the website; "Recommends" means it only produces suggestions or findings.
Entries marked (to be walked through) have not yet been explained step by step; see `CODE_WALKTHROUGH_PROGRESS.md`.

| Feature | Status | Where | Key functions | Applies or recommends |
|---|---|---|---|---|
| Print SEO title | Implemented | `includes/class-seo-pilot.php` | `filter_document_title` on `pre_get_document_title` | Applies (output only, singular posts, no other SEO plugin) |
| Print meta description | Implemented | same | `output_meta_description` on `wp_head` | Applies (output only, same conditions) |
| Detect other SEO plugins | Implemented | `includes/compat/class-seo-pilot-compat.php` | `active_plugins`, `owner`, `has_conflict` | Neither |
| Read / write title and description of Yoast, Rank Math, SEO Pilot | Implemented | same | `get`, `set`, `write_key`, `field_key` | `set` / `write_key` write post meta (called only from Approval) |
| All in One SEO | Partial | same | `aioseo_read` | Read only (table `aioseo_posts`, column names unverified per docs) |
| Audit engine (findings + per-criterion checks) | Implemented | `includes/audit/class-seo-pilot-audit.php` | `analyze`, `check_indexing`, `check_meta`, `check_structure`, `check_content`, `check_readability`, `check_links`, `check_images`, `check_similar`, `check_search_data`, `check_keywords` | Recommends |
| Collect page data for the audit, store results | Implemented | `includes/audit/class-seo-pilot-audit-runner.php` | `build_page`, `audit_post`, `audit_all`, `index`, `page_settings`, `save_page_settings` | Writes only `_seo_pilot_*` meta |
| HTML parsing (headings, images, links, paragraphs) | Implemented | `includes/audit/class-seo-pilot-parser.php` | `parse`, `paragraph_segments`, `hrefs` | Recommends |
| SEO title / meta description checks | Implemented | `Audit::check_meta` | length, missing, duplicates, keyword placement | Recommends |
| H1 / heading structure | Report only | `Audit::check_structure` | H1 count, level skips, empty and duplicate headings | Recommends |
| Heading outline suggestions | Advisory only | `Prompt::tasks` (`outline`), `Validator::parse` | | Recommends; no apply path |
| Image alt text: detection | Implemented | `Audit::check_images`, `Parser::parse`, `Text::looks_like_filename_alt` | | Recommends |
| Image alt text: user-written fix | Implemented | `Workspace::handle_save_alt` -> `Recs::insert` (type `alt_text`) -> `Approval::apply` -> `Parser::set_img_alt` | | Applies to `post_content` after approval |
| Featured images, image processing | Missing | none | | |
| Canonical, robots, structured data (checks on live page) | Report only | `includes/audit/class-seo-pilot-live-check.php` | `fetch`, `parse`, `notes` | Recommends |
| noindex detection | Report only | `Compat::robots_noindex`, `Compat::site_discourages_search`, `Audit::check_indexing` | | Recommends |
| Sitemaps, redirects, schema generation, Open Graph | Missing | none | | |
| Keyword placement and cannibalization | Implemented | `Audit::check_keywords`, `Audit_Runner::index` | | Recommends |
| Readability heuristics | Implemented | `Audit::check_readability`, `includes/audit/class-seo-pilot-text.php` | | Recommends |
| Arabic-aware text handling | Implemented | `Text::normalize`, `count_phrase`, `language`, `arabic_ratio` | | Support |
| Search intent estimate | Implemented | `includes/intent/class-seo-pilot-intent.php` | | Recommends |
| Similar content detection | Implemented | `includes/audit/class-seo-pilot-similarity.php`, `Audit_Runner::cached_sketch`, `Audit::check_similar` | `sketch`, `jaccard`, `find_similar` | Recommends |
| Content gap | Implemented | `includes/audit/class-seo-pilot-gap.php`, `Workspace::analyse_site` | `vector`, `cosine`, `related`, `missing_terms` | Recommends |
| Internal link suggestions | Implemented | `includes/audit/class-seo-pilot-links.php`, `Workspace::analyse_site` | `phrases_for`, `suggest`, `link_phrase` | Recommends; applied via Approval as a `rewrite` |
| Broken link check | Partial | `Audit_Runner::link_resolver`, `Audit::check_links` | local lookups only, no HTTP | Recommends |
| AI request | Implemented | `includes/ai/class-seo-pilot-ai-client.php` | `chat`, `post` | Sends content to the configured provider |
| AI prompt and context | Implemented | `includes/ai/class-seo-pilot-prompt.php`, `Workspace::prompt_context` | `tasks`, `system_prompt`, `build` | |
| AI response validation | Implemented | `includes/ai/class-seo-pilot-validator.php` | `extract_json`, `parse`, `strip_new_links`, `clean_inline` | |
| Rate limiting | Implemented | `includes/ai/class-seo-pilot-rate-limiter.php` | `check`, `record`, `remaining` | |
| API key storage and endpoint validation | Implemented | `includes/core/class-seo-pilot-settings.php` | `store_key`, `encrypt`, `decrypt`, `validate_endpoint`, `sanitize` | |
| Generate recommendations | Implemented | `Workspace::handle_generate` -> `generate` -> `clean_proposed` | | Writes `seo_pilot_recs` only |
| Review (accept / reject / edit) | Implemented | `Workspace::handle_rec_action`, `Recs` | | |
| Plan, apply, draft copy | Implemented | `includes/revisions/class-seo-pilot-approval.php` | `plan`, `apply` | **The only code that changes posts** |
| Rollback | Implemented | `Approval::rollback`, `Workspace::handle_rollback_group`, `History` | | Restores exact old values |
| Diff display | Implemented | `includes/audit/class-seo-pilot-diff.php` | `ops`, `html` | Display |
| Search Console CSV import | Implemented | `includes/storage/class-seo-pilot-searchdata.php`, `Admin_Data::handle_import` | | Stores data only |
| Site report and CSV export | Implemented | `includes/audit/class-seo-pilot-report.php`, `Admin_Data::render_report`, `handle_export` | | Report |
| Tables, upgrade, 0.1.0 migration | Implemented | `includes/core/class-seo-pilot-installer.php` | `activate`, `install`, `maybe_upgrade`, `maybe_migrate` | Creates tables |
| Admin screens | Implemented | `includes/admin/*` | see Overview | |
| Uninstall | Implemented | `uninstall.php` | | Deletes data only if opted in |
