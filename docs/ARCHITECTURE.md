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
Pure modules (`Text`, `Parser` classic mode, `Diff`, `Intent`, `Audit`, `Title`, `Images`, `Hreflang`, `Fixes`, `Verify`, `Live_Check::parse/notes`) have no WordPress dependency and are unit-tested in isolation.

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

## Title analysis (added after 0.3.0)
- `SEO_Pilot_Title::analyze()` (pure) returns a structured report stored on the audit as `title_analysis`. Each check has a `basis`: **measured** (counted or compared by code), **estimate** (pixel width: Arial-like metrics at 20 px, shown as a ±10% range against a 580 px reference; fonts and Google's limit vary), or **heuristic** (clarity, alignment, intent cue: a prompt to look, not a requirement).
- Checks: length and estimated width (with a cut-off preview), primary keyword presence / position / repetition / whether it falls in the cut-off part, site-name share, clarity flags, title-to-headings/opening/body alignment (light stemming; Arabic prefix-aware), intent cue, exact duplicates (existing index) and near-duplicates (word overlap >= 75% against `Audit_Runner::index()['title_pool']`).
- Only three results also become audit findings/checklist rows (`title_width_estimate`, `title_near_duplicate`, `title_generic`); the existing title checks are unchanged. `Audit::ENGINE_VERSION` is now `2`; audits saved by older versions show a "Refresh audit" hint in the panel.
- AI: the optional `title_review` task is advisory only (`Recs::APPLICABLE` does not include it). It sends the plugin's measurements as context, returns a critique and up to three alternatives, and nothing is applied; to use an alternative the editor goes through the normal `seo_title` proposal and approval flow.

## Images and alt text (added after 0.3.0)
- `SEO_Pilot_Images::analyze()` (pure) classifies every `<img>` found by `Parser::parse()` (which now also records link context, caption and the `wp-image-N` attachment id): `missing`, `empty_in_link` (an image that is the only content of a link has no accessible name), `decorative` (empty alt, fine), or `has_alt` with rule-of-thumb flags (file name, over 125 characters, "image of", generic word, URL, repeated words, same as page title/caption, same alt on different images). Stored on the audit as `image_analysis`. `Audit_Runner::image_data()` adds Media Library alt text, file size and the featured image (read only, at most 40 attachments).
- The original findings `alt_missing` / `alt_suspicious` keep their ids and evidence shape; new ones are `alt_empty_in_link`, `alt_quality`, `alt_duplicate`, `image_large_file` (info), `featured_alt_missing` (info). SEO Pilot never edits Media Library entries or image files.
- Apply path: `alt_text` recs carry flags `mode` (`add` | `replace`), `old_alt` and `decorative`. `Parser::set_img_alt` adds an alt only where none exists; `Parser::replace_img_alt` replaces an alt only when both the src and the exact old alt still match (otherwise the rec is marked stale). An empty alt is accepted only for recs a person marked decorative.
- Sources of an `alt_text` rec: `user` (typed in the image table), `local` (Media Library text, `Workspace::handle_alt_library`, no AI) and `ai` (opt-in, below). All go through the same accept / apply / rollback flow.
- Vision AI is opt-in with its own setting and confirmation (`send_images`, `ack_images`; `Settings::images_problem()`). `SEO_Pilot_Alt_AI::draft()` takes at most 5 Media Library images referenced by the page, resizes them to 1024 px (`wp_get_image_editor`), reads only files under the uploads directory, and sends them with the page title, section heading, caption and link text (never keywords) in an OpenAI-style `image_url` message. `Validator::parse_alt()` treats the answer as untrusted and flags long text, "image of", repeated words and numbers not found in the page context. Not exercised by the automated tests: it needs a real vision model.

## Languages and translations (added after 0.3.0)
- **Language model** (`SEO_Pilot_Lang`, WordPress-bound): `for_post()` returns the language of a post and its translations. Order of sources: Polylang (`pll_get_post_language`, `pll_get_post_translations`), WPML (`wpml_post_language_details`, `wpml_element_trid`, `wpml_get_element_translations`), the `seo_pilot_post_language` filter, SEO Pilot's own pairing (post meta `_seo_pilot_lang`, `_seo_pilot_trans_group`), then the site locale. Polylang/WPML are read only and every call is guarded. TranslatePress and Weglot create no separate posts, so they are only detected (to avoid printing hreflang twice); their pages are audited through the Live check. The Polylang/WPML/filter adapters could not be run against the real plugins here.
- **Pure helpers** (`SEO_Pilot_Hreflang`): code normalisation and validation (`ar_sa` -> `ar-SA`), `tags()` builds the hreflang list for a group (self + every other page, one tag per language, absolute http(s) URLs, x-default only for the chosen default language, nothing for fewer than two languages), `plan_link()` enforces pairing rules (different languages, no clash when merging groups), `with_lang_attribute()`.
- **Settings → Languages** (`languages`, `default_lang`, `hreflang_output`, `html_lang` in `seo_pilot_settings`): the site's languages with optional "regional wording notes" for the AI. hreflang output and the `<html lang>` override are opt-in, only for pages linked in SEO Pilot's own groups, and disabled whenever a translation plugin is detected. Canonicals are never written.
- **Audit** (`Audit::check_language`): declared language vs script of the text, SEO title / description script vs page language, identical title / description / keyword across translations, likely untranslated copies (text similarity), missing translations (info). Translations are excluded from duplicate, cannibalisation and similar-content checks of each other. Criterion: "Language and translations".
- **Live check**: also reads `<html lang>`, `hreflang`, `og:locale`; `lang_notes()` checks code validity, self-reference, expected alternates, duplicates, x-default, canonical pointing at another language; `reciprocity_notes()` (button "Check live output and its other-language pages", at most 5 same-site pages) checks return tags, canonicals and `lang`.
- **AI**: prompts name the page's language (set by the editor or plugin, else the script guess) and add the language's regional note; explanations use the admin's language when known, otherwise English. The `localize` task drafts a title, description and keyword suggestions for a page from its translation (applicable recs for title and description; `keywords_local` is advice only). The validator flags a draft whose script (Arabic vs Latin) does not match the page language; it cannot tell English from French.
- **Text**: `Text::fold_arabic()` (ى/ي, ة/ه, ؤ/و, ئ/ي, Persian ک ی) is used for keyword matching only; `normalize()` is unchanged. Word lists exist for English and Arabic; `Text::lists_supported()` decides, and for other declared languages the word-frequency, title-alignment and near-duplicate-title checks are skipped and reported. Filters: `seo_pilot_stopwords`, `seo_pilot_intent_phrases`, `seo_pilot_language_lists_supported`, `seo_pilot_post_language`, `seo_pilot_detected_multilingual_plugins`, `seo_pilot_output_hreflang`.

## Fix assistant (Assisted mode, added after 0.3.0)
Flow on a page in Content SEO, three separate buttons, nothing happens in the background:
1. **Fixes available** (`Fixes::for_audit`, pure): the saved audit's findings are matched to a small catalog (`meta_fill`, `meta_improve`, `title_improve`, `alt_library`, `alt_ai`, `localize`). Each has a risk class: **A** uses text the site already has (Media Library alt text), **B** an AI draft that fills an empty field, **C** replaces text a person wrote. A and B are ticked by default, C is not. Unavailable fixes show why (AI not configured, no linked translation, field owned by a read-only SEO plugin, ...).
2. **Prepare** (`Workspace::handle_fix_prepare`): re-audits, then creates proposals in the recommendations table by the existing generators (`Workspace::generate` for title / description / localize, `create_library_alt_recs`, `Alt_AI::draft`). Only fixes that are really available are accepted from the form. The page is not changed. AI is called only on this click.
3. **Apply selected** (`Workspace::handle_fix_apply`): takes a fresh "before" audit, marks the ticked proposals accepted and calls `Approval::apply( ..., 'only' => ids )`, so every existing rule (field allow-list, stale checks, live-page and SEO-plugin confirmations, revision, history, rollback) still applies and other accepted proposals are left alone. `Approval::plan()` and `apply()` gained an optional `only` list; without it they behave as before. On an error, proposals that were pending go back to pending.
4. **Verify** (`Verify::compare`, pure): the re-run audit is compared with the "before" audit by finding id for the findings the applied recommendation types target (`Fixes::targets_for_rec_types`). Verdicts: verified, partly improved (some solved or smaller), not resolved, regressed (a new or larger high/medium finding), nothing to compare. The result and a "Roll back this run" button (the existing `rollback_group`) are shown on the page for 24 hours (transient `seo_pilot_fixrun_<post>`). Assisted mode never rolls back by itself.
Not built yet: Automatic mode, queue, schedule, run history (see the automation plan).
