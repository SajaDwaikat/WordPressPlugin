# Component 2: Settings and API key handling

Files: `includes/core/class-seo-pilot-settings.php` (188 lines), the form in `SEO_Pilot_Admin::render_settings` (`includes/admin/class-seo-pilot-admin.php:136`), registration in `SEO_Pilot::register_settings` (`includes/class-seo-pilot.php:34`), redaction in `SEO_Pilot_Util::redact`.
Builds on component 1 (hook wiring). Used later by the AI pipeline (component 8).

## 1. What is stored where

| Data | WordPress option | Autoload | Notes |
|---|---|---|---|
| Everything except the key: endpoint, model, `max_chars`, `rate_limit`, `timeout`, `send_headings`, `ack`, `ack_user`, `ack_time`, `delete_on_uninstall`, `provider` | `seo_pilot_settings` (const `Settings::OPTION`) | default (yes) | an array |
| API key | `seo_pilot_secret` (const `SECRET_OPTION`) | **no** (`update_option(..., false)`) | encrypted string; or no row at all |
| Alternative key source | PHP constant `SEO_PILOT_API_KEY` in `wp-config.php` | n/a | wins over the database |

## 2. The save path (what happens when you press "Save settings")

```
Settings form  (Admin::render_settings)         method=post action=options.php
  settings_fields('seo_pilot_settings_group')   prints nonce + hidden option_page field
  inputs named  seo_pilot_settings[endpoint], [model], [api_key], [ack], ...
        │
        ▼  WordPress core options.php checks nonce + capability, finds the group registered in
        │  SEO_Pilot::register_settings() (admin_init), then calls the registered callback
        ▼
Settings::sanitize( $input )  ->  returns the array WordPress stores in option seo_pilot_settings
        │  side effects inside sanitize:  store_key()/delete the secret option
        ▼
redirect back to the settings page; settings_errors('seo_pilot_settings') shows any add_settings_error()
```

Registration (`class-seo-pilot.php:35`):

```php
register_setting( 'seo_pilot_settings_group', SEO_Pilot_Settings::OPTION, array(
    'type' => 'array', 'sanitize_callback' => array( 'SEO_Pilot_Settings', 'sanitize' ),
    'default' => SEO_Pilot_Settings::defaults() ) );
```

Note the plugin does **not** write its own `admin_post` handler for settings; it relies on core `options.php`. Capability for that page is therefore enforced by WordPress (the group's option page capability, default `manage_options`), while the screen itself is gated by `Admin::check_cap()` using `Util::capability()`.

### `sanitize( $input )` line by line (`settings.php:149`)

```php
$input = is_array( $input ) ? $input : array();
$old   = self::all();
$clean = self::defaults();
```
Start from the defaults; **only keys copied below survive**. That also means the key is never inside this array (the migration `Installer::migrate_api_key` removes any old one).

```php
$endpoint_in = isset( $input['endpoint'] ) ? $input['endpoint'] : $old['endpoint'];
list( $ok, $normalized, $error ) = self::validate_endpoint( $endpoint_in );
if ( $ok ) { $clean['endpoint'] = $normalized; }
else { $clean['endpoint'] = $old['endpoint']; add_settings_error( self::OPTION, 'seo_pilot_endpoint', $error, 'error' ); }
```
A bad URL does not wipe the working one: the old value is kept and an error is queued for `settings_errors()`.

```php
$clean['model']     = isset(...) ? sanitize_text_field( $input['model'] ) : $old['model'];
$clean['max_chars'] = max( 1000, min( 12000, ... ) );     // also rate_limit 1-200, timeout 10-120
$clean['send_headings'] = ! empty( $input['send_headings'] ) ? 1 : 0;
```
Numbers are clamped; checkboxes become 0/1 (an unchecked box is simply absent from POST).

```php
if ( ! empty( $input['ack'] ) ) {
    $clean['ack'] = 1;
    if ( ! empty( $old['ack'] ) ) { keep old ack_user / ack_time }
    else { $clean['ack_user'] = get_current_user_id(); $clean['ack_time'] = current_time( 'mysql' ); }
}
```
The acknowledgement ("I understand page content is sent to this AI provider") records who and when, only the first time. If the box is unticked, `ack` falls back to the default 0, which makes `configuration_problem()` block all AI calls.

```php
if ( ! empty( $input['clear_api_key'] ) ) { self::store_key( '' ); }
elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) { self::store_key( sanitize_text_field( (string) $input['api_key'] ) ); }
return $clean;
```
The key is handled as a side effect: an empty password field means "keep the current key" (the form never prints the saved key back; `value=""`). The comment on the function says it must be idempotent because WordPress may call the callback twice on the first save.

## 3. Reading settings

- `defaults()` is the single list of keys.
- `all()`: `array_merge( defaults(), get_option(...) )` then re-clamps `max_chars`, `rate_limit`, `timeout`, so even a hand-edited option cannot exceed the bounds.
- `get( $key, $default )` is a thin wrapper over `all()`. Callers: `Prompt::build` (`max_chars`, `send_headings`), `Rate_Limiter` (`rate_limit`), `Workspace::generate` (`timeout`), `Settings::provider_host`.

## 4. The API key

### Where it comes from: `api_key()` / `key_source()`

```php
if ( defined( 'SEO_PILOT_API_KEY' ) && '' !== trim( (string) SEO_PILOT_API_KEY ) ) { return trim( (string) SEO_PILOT_API_KEY ); }
return self::decrypt( (string) get_option( self::SECRET_OPTION, '' ) );
```
Priority: constant first, then the decrypted database value, else `''`. `key_source()` returns `'constant'`, `'database'` or `'none'`, which the settings screen uses to show either "Set by the SEO_PILOT_API_KEY constant" (no input at all) or a password field with a "Remove the saved key" checkbox. `has_key()` is `'' !== api_key()`.

### Storing and encrypting: `store_key()`, `encrypt()`, `decrypt()`, `crypto_key()`

```php
private static function crypto_key() { return substr( hash( 'sha256', wp_salt( 'auth' ) . '|seo-pilot-secret', true ), 0, 32 ); }
public static function encrypt( $plain ) {
    if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
        $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = sodium_crypto_secretbox( $plain, $nonce, self::crypto_key() );
        return 'v1:' . base64_encode( $nonce . $cipher );
    }
    return 'plain:' . base64_encode( $plain );
}
```
- The encryption key is derived from the site's `AUTH_KEY`/`AUTH_SALT` (via `wp_salt('auth')`), so the ciphertext in the database is useless without `wp-config.php`. It is not a separate secret: anyone who can read `wp-config.php` and the database can decrypt it.
- Format: `v1:` + base64(nonce + ciphertext) for libsodium; **`plain:` + base64 when libsodium is missing**. That fallback is base64 only, i.e. not encrypted. The settings screen text says "encrypted (when libsodium is available)".
- `decrypt()` reverses the two formats; any failure (bad base64, wrong salts, unknown prefix, sodium missing for a `v1:` value) returns `''`, which behaves as "no key". If you change the site's salts the saved key silently stops working.
- `store_key('')` deletes the option, otherwise `update_option(SECRET_OPTION, encrypt($plain), false)`.

### Not leaking the key

`Util::redact()` (used by `Util::log`) removes `Bearer ...` tokens, anything that looks like `sk-...`/`key_...`, and the exact current key if it is 8+ characters. The key is only turned into a header inside `AI_Client::post` (`Authorization: Bearer ...`, `ai-client.php:47`), i.e. on the server side; the settings form never receives it.

## 5. Endpoint validation: `validate_endpoint( $url )`

Returns `[ ok, normalized, error ]`:
1. Empty string is valid (not configured).
2. `wp_parse_url` must give a scheme and host.
3. Scheme must be `https`; `http` is allowed only for `localhost`, `127.0.0.1`, `::1`.
4. Credentials in the URL (`user:pass@`) are rejected.
5. Normalised with `esc_url_raw`.

It is called twice: when saving (`sanitize`) and again just before each request in `AI_Client::chat` (`ai-client.php:21`), so a value inserted into the option by other means is still checked.

## 6. The gate for every AI call: `configuration_problem()`

```php
if ( '' === $s['endpoint'] || '' === $s['model'] || ! self::has_key() ) { return 'Configure the AI endpoint, model, and API key in SEO Pilot → Settings first.'; }
if ( empty( $s['ack'] ) ) { return 'Confirm in SEO Pilot → Settings that you understand page content is sent to the AI provider.'; }
return '';
```
Empty string = ready. Callers: `Admin::render_overview` (shows the status), `Workspace_View` (hides/shows Generate and shows where content goes), `Workspace::generate` (`workspace.php:339`) and `AI_Client::chat` (`ai-client.php:17`). So the block is enforced twice in the server path, not only in the UI.

## 7. Trace it yourself

1. Search `Settings::` across `includes/`: about 30 call sites, listed by file in the section above.
2. Save a test value, then read it: `get_option('seo_pilot_settings')` shows no key; `get_option('seo_pilot_secret')` shows `v1:...` (or `plain:...`).
3. Define `SEO_PILOT_API_KEY` in `wp-config.php` and reload Settings: the key field disappears.

## 8. Observations

- `provider` exists in defaults (`openai_compatible`) but `sanitize` never reads it and nothing else in the source branches on it; only an OpenAI-compatible Chat Completions call exists.
- `ack` is reset to 0 whenever a save happens without the box ticked.
- Rate limiting settings are stored here but enforced in `Rate_Limiter` (component 8).
