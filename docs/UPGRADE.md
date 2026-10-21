# Upgrading to 0.3.0 (from 0.2.0)
Replace the plugin folder and open any SEO Pilot admin page. The upgrade adds one table (`{prefix}seo_pilot_searchdata`, database version 2) and changes nothing else. The first audit after upgrading stores a small similarity sketch in post meta (`_seo_pilot_sketch`) for each page; it is rebuilt automatically when a page's text changes. Nothing needs to be re-confirmed. Existing recommendations and history are untouched. New recommendation types: `internal_link` (applicable, with approval), `inbound_links` and `site_gap` (guidance only).

# Upgrading from the 0.1.0 MVP to 0.2.0 (and on to 0.3.0)

## Steps
1. Back up the database (the upgrade creates tables and moves some options).
2. Deactivate the old plugin, **replace the plugin folder** with this one (same folder name `seo-pilot`, same main file `seo-pilot.php`), reactivate. Or upload the new zip over the old version.
3. Open any SEO Pilot admin page once; the upgrade runs on `admin_init` and is idempotent.

## What the upgrade does automatically
| 0.1.0 data | 0.2.0 result |
|---|---|
| `seo_pilot_settings.api_key` (plain text, autoloaded) | Moved to `seo_pilot_secret` (encrypted when libsodium exists, **not** autoloaded) and removed from the settings array |
| `_seo_pilot_pending_suggestion` post meta | Converted into two **pending** recommendations (SEO title, meta description) that must be reviewed and accepted like any other; the meta is deleted |
| `_seo_pilot_last_snapshot` post meta | Converted into history entries (grouped `legacy-<post id>`) so the old change can still be rolled back; the meta is deleted |
| `seo_pilot_last_audit` option | Deleted (format changed). **Run the audit again.** |
| `_seo_pilot_meta_description` post meta | Kept, still used |

## Things that behave differently
- **AI is off until you re-confirm.** Tick the "page content is sent to the provider" box in Settings. This is deliberate.
- **Approving a suggested title no longer overwrites the visible post title.** It fills the SEO title field (the MVP wrote it into `post_title`, which is usually the H1). If you rolled back MVP changes, legacy history entries restore `post_title` exactly as recorded.
- **While Yoast SEO / Rank Math / All in One SEO is active**, SEO Pilot does not print its own title or description. A description the MVP saved into SEO Pilot's own field while one of those plugins was active was never output; the audit now reports it ("orphaned") and you can apply it to the SEO plugin's field with approval.
- The old `seo_pilot_ai_suggest`, `seo_pilot_apply_fix` and `seo_pilot_rollback` form actions now redirect to the Content SEO workspace.
- Overview no longer contains the settings form; it moved to **SEO Pilot → Settings**.

## Rolling back the upgrade
The old tables are only added to, never altered. Reinstalling 0.1.0 works, but 0.1.0 ignores the new data. The migrated API key stays in `seo_pilot_secret`; re-enter the key in 0.1.0's settings if you downgrade.
