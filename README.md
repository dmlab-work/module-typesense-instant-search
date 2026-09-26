# DmLab_TypesenseInstantSearch

Storefront instant, typo-tolerant search-as-you-type for Magento 2 (L3 of the Typesense suite).
Replaces the stock autocomplete **in place** with a rich product dropdown (title / image / price /
SKU). Free and uncapped, no Hyvä/Alpine/React dependency — it works on stock Luma through Magento's
standard RequireJS `data-mage-init` machinery. Read-only: writes no documents, patches no schema.
Depends on `dmlab/module-typesense-indexer` and `-core`.

## Two query modes

**Query Mode** picks how the browser reaches Typesense:

| Mode | Path | Typesense exposed? | Key on page | Needs CORS/CSP |
|---|---|---|---|---|
| **Proxy** *(default)* | browser → Magento AJAX endpoint → Typesense (server-side) | No, same-origin | none | no |
| **Direct** | browser → Typesense with a derived scoped read-only key | Yes, over HTTPS | scoped read-only | yes |

Pick **proxy** to keep Typesense on the internal network (nothing leaves the server, no CORS, no key
on the page). Pick **direct** for the lowest latency when you can expose Typesense over HTTPS. Both
render the identical dropdown from the same fat-document fields — only the fetch target differs.

## Install & configure

```bash
composer require dmlab/module-typesense-instant-search
bin/magento module:enable DmLab_TypesenseInstantSearch
bin/magento setup:upgrade
```

Admin → *Stores → Configuration → DMLab Typesense → Instant Search*:

| Field | Default | Notes |
|---|---|---|
| Enable Instant Search | No | Off → native search |
| Query Mode | Proxy | Proxy keeps Typesense private; Direct queries it from the browser |
| Search-Only Key | — | *Direct only.* Read-only status + Regenerate button — auto-provisioned |
| Public Typesense Host | — | *Direct only.* HTTPS host the browser connects to (`search.example.com[:port]`) |
| Minimum Query Length | 3 | |
| Result Limit | 5 | Suggestions shown |
| Debounce (ms) | 200 | |
| Show Title / Image / Price / SKU | Yes / Yes / Yes / No | |

The direct-only fields appear only when direct mode is selected — switching modes needs no code and
no manual key step. **Direct mode** additionally requires a publicly reachable Typesense host running
with CORS enabled (`--enable-cors`).

## Key model — only a derived key reaches the browser

No CLI, no paste box. When direct mode is saved and no parent key exists, the module calls Typesense
`POST /keys` with the admin key (server-side), mints a parent key restricted to `documents:search` on
the store's aliases, and stores it **encrypted**. Each request then derives a **local HMAC** scoped
to the current store's alias — no API call, no admin key — and only that scoped key reaches the
browser. If the admin key is missing at save time, provisioning fails with a clear error; if the
parent key is absent the widget fails closed and the storefront falls back to native search.

## How it replaces the stock autocomplete

A RequireJS **map override** swaps the `quickSearch` widget implementation in place, inheriting the
stock `data-mage-init` options and rendering into the stock `#search_autocomplete` container. Config
reaches JS as an added `<script type="application/json">` — no template or layout override, so custom
themes using the stock search form need no changes. Any JS/network/config error falls through to the
native search form.

In **direct mode only**, `Model\Csp\TypesensePolicyCollector` adds the configured host to `connect-src`
at runtime (it is admin config, so a static whitelist cannot carry it); in proxy mode it is a no-op.

## Requirements

Magento 2.4.x · PHP 8.3–8.5 · a catalog already indexed into Typesense · (direct mode) a public,
CORS-enabled Typesense host.

## License

OSL-3.0 © DMLab.
