# Installing SEO Pilot 0.3.0

## Requirements
- WordPress 6.0 or later, PHP 7.4 or later (developed and tested on PHP 8.3).
- PHP extensions: `dom` (php-xml), `mbstring` (strongly recommended), `json`. `sodium` is used to encrypt the API key when available (it is bundled with PHP 7.2+).
- Outbound HTTPS from the server to your AI provider (only needed for AI recommendations; the audit is fully local).

## Install
1. Download `seo-pilot.zip`.
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Install Now** → **Activate**.
   (Or unzip into `wp-content/plugins/` so you have `wp-content/plugins/seo-pilot/seo-pilot.php`.)
3. Activation creates two tables (`{prefix}seo_pilot_recs`, `{prefix}seo_pilot_history`). If `dom` is missing, activation stops with a message.

## First-time setup
1. **SEO Pilot → Settings**
   - API base URL (must be `https://`, e.g. `https://api.openai.com/v1/`; `http://localhost` is allowed for local model servers).
   - Model name and API key.
   - Tick the confirmation that page content is sent to the provider (required before any AI request).
   - Optional: raise/lower the content limit, hourly request limit and timeout.
2. **Better key storage (recommended):** put the key in `wp-config.php` instead of the database:
   `define( 'SEO_PILOT_API_KEY', 'your-key' );`
   When the constant exists, the settings page shows no key field and the database is not used for the key.
3. **SEO Pilot → Overview → Run audit now** (local checks only; nothing leaves the server).
4. **SEO Pilot → Content SEO** → open a page.

## Using it
- Optionally set a primary keyword, secondary keywords, search intent and audience, then **Save and refresh audit**.
- Review the checklist and prioritised findings. Items marked *needs a human* cannot be judged by software.
- **Generate recommendations**: open "Show exactly what will be sent" first if you want to see the text that leaves the site.
- For each proposal: edit, **Accept**, **Reject**, or **Regenerate**. Accepting changes nothing on the page.
- Image alt text is never guessed: type it yourself in the alt-text panel.
- **Apply accepted changes** shows the exact diff. On a live page you must tick the confirmation box, or choose "Save as a new draft copy" (the live page is untouched). If Yoast SEO or Rank Math owns the SEO fields, a second confirmation is required.
- **Check live output** (published pages) fetches the page and shows its real title, description, canonical, robots tag and H1 count, and warns about duplicates.
- **History and rollback**: every applied change can be rolled back from the page's workspace. If the content was edited after SEO Pilot changed it, rollback stops and tells you, unless you tick "restore anyway".

## Phase 2 features (0.3.0)
- **Similar content:** the audit compares each page with the other published pages and reports large text overlaps (estimate from word-sequence comparison; pages under about 80 words are not compared). SEO Pilot never sets canonicals or redirects because of it.
- **Related pages on this site:** in the workspace, *Analyse related pages* runs locally (no AI, nothing sent anywhere) and creates: internal-link suggestions (only where this page's text already names another of your pages; applied only with approval, like any other change), a list of pages that mention this page but do not link to it, and terms that closely related pages use but this page does not.
- **Search data:** *SEO Pilot → Search data* imports a CSV export (Google Search Console Pages report, a Queries report for one page, or a combined Page + Query export). Column headers must be in English. You must describe the period the file covers; it is shown beside every number. Real figures appear in the workspace and feed two audit findings (queries your text barely mentions; queries on which two of your pages appear). SEO Pilot never estimates search volume, difficulty, rankings or CTR. A live Google API connection is **not** included.
- **Assessment by criterion:** the workspace shows each criterion (intent, subtopic coverage, headings, readability, internal linking, title/meta, specificity, verification, indexability, images) with its status and the exact checks that influenced it. There is deliberately no single score.
- **Report:** *SEO Pilot → Report* summarises all audited pages and downloads every finding as CSV (formula-injection safe, UTF-8 with BOM for Excel).

## What SEO Pilot never changes
Canonical tags, robots directives, structured data, slugs, publication dates, and post status.

## Uninstall
Deleting the plugin keeps its data by default. To remove everything (recommendations, history, imported search data, keywords, settings, and the SEO titles/descriptions SEO Pilot stores itself), tick the option under **Settings → Data** *before* deleting the plugin.
