# WP Cookie Consent

A lightweight, dependency-free GDPR-style cookie consent banner for
WordPress — no jQuery, no external CDN, no 40 KB consent library.

## How it works

1. First visit: a fixed banner (top or bottom) shows category toggles —
   **Necessary** (locked on), **Analytics**, **Marketing**.
2. Accept all / reject all / save choices stores a JSON consent cookie
   (`wcc_consent`, configurable TTL, `SameSite=Lax`).
3. On consent, blocked scripts are revived automatically; until then they
   stay inert.

## Blocking scripts until consent

Register your third-party script with a consent category:

```php
wp_enqueue_script( 'my-pixel', 'https://example.com/pixel.js', array(), '1.0', true );
wp_script_add_data( 'my-pixel', 'wcc_category', 'marketing' ); // or 'analytics'
```

The plugin swaps its tag for an inert placeholder and revives it once the
visitor consents to that category.

In templates, gate code with the helper:

```php
if ( function_exists( 'wcc_consented' ) && wcc_consented( 'analytics' ) ) {
    // print analytics snippet
}
```

## Extras

- `[wcc_manage label="Cookie settings"]` shortcode — visitors can change
  their choice later (put it on your privacy page).
- All labels/texts editable under **Settings → Cookie Consent**; optional
  cookie-policy URL link in the banner.

## Install

Upload the folder to `wp-content/plugins/`, activate, adjust texts under
**Settings → Cookie Consent**. Uninstalling removes the option.

MIT licensed.
