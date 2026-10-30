# DmLab_TypesenseInstantSearch

[![CI](https://github.com/dmlab-work/module-typesense-instant-search/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-typesense-instant-search/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-typesense-instant-search/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-typesense-instant-search) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-typesense-instant-search)](https://packagist.org/packages/dmlab/module-typesense-instant-search) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-typesense-instant-search)](https://packagist.org/packages/dmlab/module-typesense-instant-search) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-typesense-instant-search/php)](https://packagist.org/packages/dmlab/module-typesense-instant-search) [![License](https://img.shields.io/packagist/l/dmlab/module-typesense-instant-search)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

Shoppers see matching products with photo, name and price while they type, typos included.
Replaces Magento's stock search suggestions on Luma, with no theme or template changes.
Typesense stays private behind your store by default. Free, no caps.

![Instant search dropdown on Luma](.github/images/dropdown.jpeg)

## Install

```bash
composer require dmlab/module-typesense-instant-search dmlab/module-typesense-search
bin/magento module:enable DmLab_TypesenseInstantSearch DmLab_TypesenseSearch
bin/magento setup:upgrade
bin/magento dmlab:typesense:search-key:ensure
```

`:ensure` creates the per-store search keys with the admin API key, so set the Typesense connection first
(*Stores → Configuration → Catalog → Catalog Search*, engine `typesense`). The widget stays off until it
runs or the config is saved.

Admin → *Stores → Configuration → DMLab Typesense → Instant Search*:

| Field | Default | Scope | Notes |
|---|---|---|---|
| Enable Instant Search | No | store view | Off → native search |
| Query Mode | Proxy | global | See [Architecture](#architecture) |
| Search-Only Key | — | global | Per-store key status + Regenerate button |
| Public Typesense Host | — | global | *Direct only.* HTTPS host the browser connects to (`search.example.com[:port]`) |
| Minimum Query Length | 3 | store view | |
| Result Limit | 5 | store view | Suggestions shown |
| Debounce (ms) | 200 | store view | |
| Show Title / Image / Price / SKU | Yes / Yes / Yes / No | store view | |

The proxy rate-limits per client IP. `X-Forwarded-For` is honoured only from trusted proxies; list
yours in `app/etc/di.xml` or a module `di.xml`:

```xml
<type name="DmLab\TypesenseInstantSearch\Model\ClientIp">
    <arguments>
        <argument name="trustedProxies" xsi:type="array">
            <item name="lb" xsi:type="string">10.0.0.10</item>
        </argument>
    </arguments>
</type>
```

## Requirements

Magento 2.4.7+ · PHP 8.2–8.5 · Typesense ≥ 30 · `dmlab/module-typesense-core` and `-indexer` 0.2.x
(pulled automatically; the indexer builds the product index and the `_public` guest collection) ·
`dmlab/module-typesense-search` (registers the `typesense` engine that routes catalog indexing to Typesense) ·
direct mode only: a public HTTPS Typesense host started with `--enable-cors`.

## Hyvä

Luma and other RequireJS themes only. The widget is a RequireJS map override of the stock
`quickSearch`, so on Hyvä the theme's own search stays and this module does nothing.
Hyvä support is not available yet.

Pairs with [dmlab/module-typesense-search](https://github.com/dmlab-work/module-typesense-search) (Typesense for search results and layered navigation).

## Architecture

Read-only storefront layer of the Typesense suite: writes no documents, patches no schema.

### Query modes

| Mode | Who | Path | Collection | Key on page |
|---|---|---|---|---|
| **Proxy** *(default)* | everyone | browser → Magento AJAX endpoint → Typesense | main alias | none |
| **Direct** | NOT LOGGED IN visitors only | browser → Typesense over HTTPS | `<mainAlias>_public` | derived, read-only |

- **Proxy** queries server-side with a key derived from the store's proxy parent key, never the admin
  key: 1 s timeout, one attempt, a 30 s "Typesense down" flag, so a hung cluster cannot exhaust PHP workers.
- **Direct** has the lowest latency. The public collection holds only the NOT LOGGED IN price, so no
  group prices leak; logged-in customers always use the proxy. Any direct failure (network, non-2xx,
  2 s timeout) switches the widget to the proxy. The key travels as a query parameter, so requests are
  CORS simple requests (no preflight).

### Key model

- Each store view gets encrypted **parent keys** at store scope with their Typesense key ids: a **proxy**
  key (always) and a **public** key (direct mode only), both limited to `documents:search` on that store's alias.
- Keys are created on config save, on store/website save (store views with Instant Search enabled), or by
  CLI: `bin/magento dmlab:typesense:search-key:ensure|regenerate [--store=<code>]`. Changing the index
  prefix rotates them on save; after `config:set`, run `:ensure`.
- Deleting a store view, store or website revokes its keys by id.
- **Regenerate** creates new keys, saves them, flushes the FPC, then revokes the old keys by id; other
  installs sharing the cluster are never touched. A failed create keeps the old key.
- Requests use locally derived HMAC keys that expire: page keys are bucketed by the FPC TTL so cached
  pages stay byte-identical; proxy keys live 5 minutes.
- `:ensure` revokes a leftover legacy single key (matched by description and value prefix).
- No admin key → saving fails with a clear error. No proxy key → the widget is not rendered and the
  storefront keeps native search. No public key → guests use the proxy.

### Prices, images, rate guard

- Prices follow the store currency and the catalog tax display setting in both modes.
- Thumbnails are resized cache URLs of the `product_thumbnail_image` view.xml image, not originals.
- The proxy allows 30 requests / 10 s per client IP (see trusted proxies in [Install](#install)).

### Stock autocomplete replacement

A RequireJS map override swaps the `quickSearch` widget in place, inheriting the stock `data-mage-init`
options and rendering into the stock `#search_autocomplete` container. Config reaches JS as an added
`<script type="application/json">`; no template or layout override, so custom themes using the stock
search form need no changes. Any JS, network or config error falls through to the native search form.

### CSP

In direct mode `Model\Csp\TypesensePolicyCollector` adds the configured host to `connect-src` at runtime
(admin config, so a static whitelist cannot carry it); in proxy mode it is a no-op.

## License

OSL-3.0 © DMLab.
