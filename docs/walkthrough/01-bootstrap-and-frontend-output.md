# Component 1: bootstrap, autoloading, hook wiring, install, front-end output

Files: `seo-pilot.php`, `includes/class-seo-pilot-loader.php`, `includes/class-seo-pilot.php`, `includes/core/class-seo-pilot-installer.php`, `includes/core/class-seo-pilot-util.php`, `uninstall.php`.
Depends on (not yet explained): `Compat::active_plugins` (component 3), `Settings` (component 2), `Approval` (component 10, the code that *saves* the values this component prints).

## 1. Startup sequence

```
WordPress loads active plugins
 └─ seo-pilot.php
     ├─ define SEO_PILOT_VERSION / DB_VERSION / FILE / PATH / URL
     ├─ require loader; SEO_Pilot_Loader::register()          -> spl_autoload_register
     ├─ register_activation_hook -> SEO_Pilot_Installer::activate     (only on activation click)
     └─ add_action('plugins_loaded', 'seo_pilot_boot')
                                         │  (after ALL plugins are loaded)
                                         ▼
                           SEO_Pilot::instance() -> __construct() registers hooks
```

### `seo-pilot.php`

```php
define( 'SEO_PILOT_PATH', plugin_dir_path( __FILE__ ) );
require_once SEO_PILOT_PATH . 'includes/class-seo-pilot-loader.php';
SEO_Pilot_Loader::register();
register_activation_hook( __FILE__, array( 'SEO_Pilot_Installer', 'activate' ) );
function seo_pilot_boot() { return SEO_Pilot::instance(); }
add_action( 'plugins_loaded', 'seo_pilot_boot' );
```

- The constants are used everywhere: `SEO_PILOT_PATH` by the loader, `SEO_PILOT_URL` by `Admin::assets` for the CSS, `SEO_PILOT_DB_VERSION` by `Installer::maybe_upgrade`, `SEO_PILOT_FILE` by `load_textdomain`.
- `register_activation_hook` takes a callable *array* of class name + method. At that moment the class is not loaded; PHP autoloads it through the loader.
- Booting on `plugins_loaded` (not at file include) is what lets `Compat` later see whether Yoast / Rank Math / AIOSEO is loaded. Their constants (`WPSEO_VERSION`, ...) exist by then.

### Autoloader: `includes/class-seo-pilot-loader.php`

```php
private static $map = array( 'SEO_Pilot' => 'includes/class-seo-pilot.php', 'SEO_Pilot_Util' => 'includes/core/class-seo-pilot-util.php', ... );
public static function load( $class ) {
    if ( isset( self::$map[ $class ] ) ) { require_once SEO_PILOT_PATH . self::$map[ $class ]; }
}
```

PHP calls `load()` the first time any unknown class name is used, e.g. `SEO_Pilot_Audit::analyze(...)`. The class name is looked up in a literal array (no naming convention), so **a new class that is not added to `$map` will fatal**. 28 classes are mapped.

## 2. The orchestrator: `SEO_Pilot` (`includes/class-seo-pilot.php`)

`instance()` is the usual singleton. `__construct()` (line 16) does only wiring:

```php
add_action( 'init',       array( $this, 'load_textdomain' ) );
add_action( 'admin_init', array( 'SEO_Pilot_Installer', 'maybe_upgrade' ), 5 );
add_action( 'admin_init', array( $this, 'register_settings' ) );
add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
SEO_Pilot_Admin::init();  SEO_Pilot_Workspace::init();  SEO_Pilot_Admin_Data::init();
```

| Line | Why it exists |
|---|---|
| `admin_init` priority **5** -> `maybe_upgrade` | runs before the default-priority `register_settings`, so tables exist before any admin screen needs them |
| `register_settings` | `register_setting('seo_pilot_settings_group', 'seo_pilot_settings', ['sanitize_callback' => Settings::sanitize ...])`. The Settings form posts to `options.php`; WordPress calls `Settings::sanitize` on the way in (component 2) |
| the three `::init()` calls | each registers its own `admin_menu` / `admin_post_*` hooks (full list in `CODEBASE_OVERVIEW.md`) |

Nothing is loaded for visitors beyond two hooks; admin classes only add hooks and do work when an admin URL is hit.

## 3. Front-end output (the only code that runs for visitors)

### Gate: `owns_output()`

```php
private function owns_output() { return ! SEO_Pilot_Compat::active_plugins(); }
```

`active_plugins()` returns an array like `['yoast']` (built from `defined('WPSEO_VERSION')` etc., then passed through the `seo_pilot_detected_seo_plugins` filter). Empty array = no other SEO plugin = SEO Pilot may print. It is evaluated on each call, not cached.

### Meta description: `output_meta_description()` on `wp_head`, priority 1

```php
if ( is_admin() || ! is_singular() || ! $this->owns_output() ) { return; }
$desc = get_post_meta( get_queried_object_id(), '_seo_pilot_meta_description', true );
if ( is_string( $desc ) && '' !== trim( $desc ) ) {
    echo "\n" . '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
}
```

Step by step:
1. `wp_head` fires while the theme prints `<head>`; priority 1 puts the tag early.
2. Bail out for admin, for anything that is not a single post/page (home, archives, search are **not** covered), or when another SEO plugin owns output.
3. `get_queried_object_id()` is the ID of the current single post. The value was stored earlier as post meta `_seo_pilot_meta_description`.
4. If non-empty it prints one `<meta name="description">`, escaped with `esc_attr`.

Input: post meta. Output: one HTML tag. It performs no generation; it only prints what exists.

### Title: `filter_document_title( $title )` on `pre_get_document_title`, priority 20

```php
$seo = get_post_meta( get_queried_object_id(), '_seo_pilot_seo_title', true );
return ( is_string( $seo ) && '' !== trim( $seo ) ) ? trim( $seo ) : $title;
```

WordPress core's `wp_get_document_title()` applies `pre_get_document_title` first. If a filter returns a non-empty string, core uses it as the `<title>` instead of building one. `$title` arrives as `''` by default, so returning `$title` unchanged means "no override, use the theme's normal title". Same three guards as above. A theme that does not support `title-tag` does not call this path (the project's own `TESTING.md` lists this as a manual check).

### Where do `_seo_pilot_seo_title` / `_seo_pilot_meta_description` get written?

Not in this component. They are written only when the user clicks Apply: `Workspace::handle_apply` -> `Approval::apply` -> `Compat::set` (`Approval` line 190), after an audit finding, an AI/user proposal and an explicit accept. If Yoast or Rank Math is active, the same apply writes *their* meta keys instead and SEO Pilot prints nothing. `Compat::orphan_value` exists to warn about a stored SEO Pilot value that is not being output for that reason.

## 4. Install / upgrade: `Installer`

Triggers:
- Activation: `activate()` -> refuses without `DOMDocument` (`wp_die`) -> `install()` -> `maybe_migrate()`.
- Every admin load: `maybe_upgrade()` compares `get_option('seo_pilot_db_version')` with `SEO_PILOT_DB_VERSION` ('2') and reinstalls if different, and runs the migration once (flag option `seo_pilot_migrated_020`).

`install()` uses `dbDelta()` (from `wp-admin/includes/upgrade.php`) to create or alter three tables, whose names come from `Recs::table()`, `History::table()`, `Searchdata::table()`: `seo_pilot_recs`, `seo_pilot_history`, `seo_pilot_searchdata` (columns are documented in `ARCHITECTURE.md`). It ends with `update_option(DB_OPTION, version, false)` (autoload off). `dbDelta` is idempotent, which is why re-running it on version bump is safe. It is sensitive to SQL formatting, hence the two spaces in `PRIMARY KEY  (id)`.

`maybe_migrate()` (only if `tables_exist()`):
1. `migrate_api_key`: moves `api_key` out of the `seo_pilot_settings` array into the encrypted secret option via `Settings::store_key`.
2. `migrate_pending_suggestions`: 0.1.0 stored `_seo_pilot_pending_suggestion` post meta; each becomes `seo_title` / `meta_description` rows in `seo_pilot_recs` (status `pending`), then the meta is deleted.
3. `migrate_snapshots`: 0.1.0 rollback snapshots (`_seo_pilot_last_snapshot`) become `seo_pilot_history` rows with group id `legacy-<post_id>`.
4. Deletes `seo_pilot_last_audit` (old format) and sets the migrated flag.

## 5. `Util`, the shared helpers used from here on

| Function | Purpose / caller |
|---|---|
| `capability()` | `apply_filters('seo_pilot_capability','manage_options')`; used by every menu and handler |
| `content_post_types()` | filter `seo_pilot_content_post_types`, default `post`,`page`; `Workspace::guard_post` rejects other types |
| `is_live_status()` | `publish`/`private`/`future` need confirmation before apply |
| `flash()` / `take_flash()` / `render_flash()` | one-time notice: stored in transient `seo_pilot_flash_<user>` (120 s), shown on the next admin page |
| `redirect($page,$args)` | `wp_safe_redirect` to `admin.php?page=...` then `exit`; every `admin_post_*` handler ends with it (post/redirect/get) |
| `save_meta`, `save_json_meta`, `get_json_meta` | `update_post_meta` strips slashes, so values are `wp_slash`ed first; audits are stored as JSON |
| `redact`, `log` | strips Bearer tokens and the API key before logging; logs only if `WP_DEBUG_LOG` |
| `uuid`, `now` | batch/group ids and timestamps |

## 6. `uninstall.php`

Runs only on deletion from the Plugins screen. Always deletes the `seo_pilot_rl` transient and all `_transient_seo_pilot_*`. Only if setting `delete_on_uninstall` is on: drops the three tables, deletes every `_seo_pilot_%` post meta row (including the stored SEO titles/descriptions, so the front end stops printing them) and the plugin options.

## 7. How to trace this yourself

1. Open `seo-pilot.php`, follow `seo_pilot_boot` -> `SEO_Pilot::__construct`.
2. Search the repo for `add_action(` / `add_filter(`: only about 20 hits, all listed in `CODEBASE_OVERVIEW.md`.
3. For the front end, set a value by hand (`update_post_meta($id,'_seo_pilot_meta_description','x')` with no SEO plugin active) and view source: `<meta name="description" content="x">` appears once, from `output_meta_description`.
4. To see why nothing prints, check `Compat::active_plugins()` first.

## 8. Limits observed in this component

- Output is for `is_singular()` only: no home page, archives or taxonomy pages.
- Only title and meta description are printed; no canonical, robots, Open Graph or schema output exists anywhere in the plugin.
- If another SEO plugin is active, SEO Pilot prints nothing and (for Yoast/Rank Math) writes into that plugin's meta keys.
