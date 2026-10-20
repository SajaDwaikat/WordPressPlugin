# SEO Pilot 0.3.0: architecture

```
seo-pilot.php                bootstrap, constants, activation hook
includes/class-seo-pilot-loader.php   class-map autoloader
includes/class-seo-pilot.php          orchestrator + front-end output (kept from 0.1.0)
includes/core/      Settings (key handling, endpoint validation), Installer (tables, migration), Util
includes/audit/     Text (Arabic-aware), Parser, Audit (pure engine), Audit_Runner, Diff, Live_Check,
                    Similarity (bottom-k shingle sketch), Gap (site-internal term gap), Links (anchor-phrase insertion), Report (criteria, site report, CSV)
includes/intent/    Rule-based search-intent estimate (EN/AR)
includes/ai/        AI_Client, Prompt, Validator, Rate_Limiter
includes/compat/    Yoast / Rank Math / AIOSEO / SEO Pilot field ownership
includes/storage/   Recs, History and Searchdata (CSV import of real search data) repositories
includes/revisions/ Approval: plan, apply (+ draft copy), rollback
includes/admin/     Admin (Overview, Settings, History), Admin_Data (Search data, Report), Workspace (handlers), Workspace_View (screens)
tests/              unit (no WordPress) and integration (inside WordPress)
```
Pure modules (`Text`, `Parser` classic mode, `Diff`, `Intent`, `Audit`, `Live_Check::parse/notes`) have no WordPress dependency and are unit-tested in isolation.

## Data model
- **Post meta (small, per page):** `_seo_pilot_primary_keyword`, `_seo_pilot_secondary_keywords`, `_seo_pilot_intent`, `_seo_pilot_audience`, `_seo_pilot_audit` (latest audit JSON), `_seo_pilot_seo_title`, `_seo_pilot_meta_description` (only output when no other SEO plugin is active), `_seo_pilot_draft_of`.
- **`{prefix}seo_pilot_recs`:** one row per recommendation: `rec_type` (`seo_title`, `meta_description`, `rewrite`, `alt_text` are applicable; `outline`, `topics`, `faq`, `refresh` are advisory), `target` (`seg:<sha1 of the original paragraph>` or `img:<sha1 of src>`), `original`, `proposed`, `rationale`, `flags` (JSON: things to verify, notes, edited), `source` (ai/user), `status` (pending, accepted, rejected, applied, drafted, superseded, stale), `base_hash`, `batch_id`, `created_by`, timestamps.
- **`{prefix}seo_pilot_history`:** one row per changed field: `group_id` (rolled back together), `post_id`, `user_id`, `action` (apply / rollback / draft_copy), `field`, `target_type` + `target_key` (the exact destination), `owner`, `old_value`, `new_value` (what WordPress actually stored), `rec_ids`, `revision_id`, `post_status`, `reverted*`, `note`, `created_at`.
- **`{prefix}seo_pilot_searchdata`:** `import_id`, `post_id`, `page_url`, `query`, `clicks`, `impressions`, `position` (CTR is never stored; it is clicks ÷ impressions). Import metadata (label, kind, row counts, who/when) is in the option `seo_pilot_imports`. The most recent import containing a page is the one used for it.
- **Post meta added in 0.3.0:** `_seo_pilot_sketch` (cache of the similarity sketch, keyed by a hash of the text).
- **Options:** `seo_pilot_settings` (no secrets), `seo_pilot_secret` (encrypted key, autoload off), `seo_pilot_db_version`, `seo_pilot_migrated_020`, `seo_pilot_last_audit` (small per-post counts for the Overview).

## Safety design
1. Proposals live only in `seo_pilot_recs`; generation never touches a post.
2. `SEO_Pilot_Approval::plan()` computes exactly what would change; `apply()` refuses unless: the user may `edit_post`; the plan has no blocked/stale items; a live post has the confirmation flag; a third-party SEO plugin's field has its own confirmation.
3. Paragraph edits locate the original by hash of its inner HTML and replace exactly one occurrence by exact string; if the paragraph changed or is duplicated the edit is refused and marked stale.
4. Writes are whitelisted by destination (`post_content`, `post_title` for legacy rollback, and six documented SEO meta keys). A forged history row cannot make rollback write elsewhere.
5. A WordPress revision of the previous content is saved first; SEO Pilot also stores exact old values.
6. Rollback compares the current value with what SEO Pilot wrote and stops on a conflict unless forced.
7. The audit is re-run after every apply and rollback.

## What runs locally vs. needs an external service
| Local, no API | External AI (only on "Generate") | Needs data SEO Pilot does not have |
|---|---|---|
| Similar-content detection, internal-link suggestions, inbound-link opportunities, site-internal content gap, per-criterion assessment, site report and CSV; imported search data is stored and shown locally. Audit checks, readability heuristics, heading structure, intent estimate, keyword placement, cannibalization (from keywords entered in SEO Pilot), duplicate titles/descriptions, link-target check against the site's own content, live-output check of the site's own pages | SEO title, meta description, paragraph rewrites, outline, missing topics/questions, FAQ ideas, refresh notes | Search volume, keyword difficulty, rankings, CTR: **never estimated**. Real numbers can be imported from a Search Console CSV; a live API connection and keyword-research providers are not built |

## Phase 2 notes
- **Similarity:** 5-word shingles hashed (crc32), bottom-128 sketch per page, estimated Jaccard similarity; reported at 50% and above, high severity at 80%. Template text shared by pages inflates it. Heuristic, needs a human.
- **Internal links:** a suggestion exists only where a phrase naming another real, published, indexable page (its title minus a trailing "| Site", its primary keyword or secondary keywords) already appears in a paragraph outside existing links; one new link per paragraph; the paragraph must have fewer than two links. At apply time the target must still be published at the same permalink, the proposed text may differ from the original only by the approved link, and the paragraph must be unchanged since.
- **Content gap:** bag-of-words cosine similarity against up to 400 other pages; lists terms used by at least two (or the only) related pages and absent here. Exact word forms only.
- **Search data:** Pages / Queries / Page+Query exports with English headers; rows for other hosts and non-WordPress URLs are skipped and counted; Queries reports must be attached to a page you choose.

## Known limitations
- Audits read stored `post_content` only; text kept in page-builder fields or custom fields is not seen.
- Paragraph rewrites work on `core/paragraph` blocks (including nested ones) and classic paragraphs. Headings, lists, tables and other blocks are not rewritten.
- The proposed text is edited per paragraph/field with a combined diff; there is no single free-form editor for the whole article.
- Broken-link detection uses local lookups only (no HTTP crawling); unmatched links are reported as "could not be verified".
- Workspace strings are not all wrapped for translation; Arabic content and RTL layout are supported.
- Single-site only has been tested.
- Search-data import accepts English headers only and plain CSV (no zipped Search Console download); there is no live Google API/OAuth connection and no keyword-research provider.
- Link suggestions use exact phrases, so a page that names another page in a different word form (plural, Arabic attached prefix) gets no suggestion.
- Similar-content and gap analysis read stored `post_content` only.
