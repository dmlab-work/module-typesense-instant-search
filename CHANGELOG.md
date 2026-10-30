# Changelog

## 0.2.1

- Supports Magento 2.4.7+ and PHP 8.2–8.5.
- CI runs on PHP 8.2–8.5 and no longer sends Packagist download notifications.
- Composer keywords, homepage and support links.
- README reworked: intro, screenshot, requirements, Hyvä status.

## 0.2.0

- Proxy fails fast when Typesense is down: 1 s timeout, single attempt, 30 s "unavailable" flag.
- Per-store `public` and `proxy` parent keys, stored encrypted at store scope and revoked only by their own ids; the 0.1.x key is migrated away.
- Keys are ensured on config and store/website save for every store once instant search is enabled anywhere, revoked on store view/store/website delete, and managed via `dmlab:typesense:search-key:ensure` / `:regenerate`. Upgrading: run `:ensure` after `setup:upgrade`.
- `:ensure` rotates keys scoped to another collection (index prefix changed); a prefix change in admin triggers it.
- The proxy queries with a derived, short-lived proxy key; the admin key never serves storefront requests.
- Direct mode serves guests only, on the `<alias>_public` collection with a derived public key that expires per FPC bucket.
- Proxy prices are converted to the display currency and shown with the configured tax display.
- Direct-mode prices apply the currency rate and per-tax-class factors from the page config.
- Dropdown thumbnails use resized `product_thumbnail_image` cache URLs.
- Direct requests avoid CORS preflight, time out after 2 s and fall back to the proxy.
- Keyboard, hover and ARIA fixes: listbox label, `aria-selected`, live region, Enter only follows a deliberately chosen row.
- The rate guard keys on the real client IP; `X-Forwarded-For` is honoured only from trusted proxies.
- Admin: regenerate requires the Typesense config ACL and a confirmation; per-store key status table; translated strings.
- Storefront settings (enable, limits, debounce, display toggles) are store-scoped.
- Requires the sibling Typesense modules `^0.2.0`; pinned Magento package ranges; `setup_version` removed; `en_US.csv`; OSL headers; JS tests in CI.

## 0.1.1

- Requires the sibling Typesense modules `^0.1.1`.

## 0.1.0

- First release under the `dmlab` vendor and `DmLab` namespace.
