# DmLab_TypesenseInstantSearch

[![CI](https://github.com/dmlab-work/module-typesense-instant-search/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-typesense-instant-search/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-typesense-instant-search/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-typesense-instant-search) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-typesense-instant-search)](https://packagist.org/packages/dmlab/module-typesense-instant-search) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-typesense-instant-search)](https://packagist.org/packages/dmlab/module-typesense-instant-search) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-typesense-instant-search/php)](https://packagist.org/packages/dmlab/module-typesense-instant-search) [![License](https://img.shields.io/packagist/l/dmlab/module-typesense-instant-search)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

Storefront instant, typo-tolerant search-as-you-type for Magento 2 (L3 of the Typesense suite).
Replaces the stock autocomplete **in place** with a rich product dropdown (title / image / price /
SKU). Free and uncapped, no Hyvä/Alpine/React dependency — it works on stock Luma through Magento's
standard RequireJS `data-mage-init` machinery. Read-only: writes no documents, patches no schema.
Depends on `dmlab/module-typesense-indexer` and `-core`.

## Two query modes

**Query Mode** picks how the browser reaches Typesense:

| Mode | Who | Path | Collection | Key on page |
|---|---|---|---|---|
| **Proxy** *(default)* | everyone | browser → Magento AJAX endpoint → Typesense | main alias | none |
| **Direct** | NOT LOGGED IN visitors only | browser → Typesense over HTTPS | `<mainAlias>_public` | derived, read-only |

- **Proxy** keeps Typesense on the internal network. It queries server-side with a key derived from the
  store's proxy parent key — never the admin key — using a 1 s timeout, one attempt and a 30 s
  "Typesense down" flag, so a hung cluster cannot exhaust PHP workers.
- **Direct** gives the lowest latency. Guests query the public collection, which holds only the NOT
  LOGGED IN price, so no group prices leak. Logged-in customers always use the proxy. Any direct
  failure (network, non-2xx, 2 s timeout) switches the widget to the proxy.

## Install & configure

```bash
composer require dmlab/module-typesense-instant-search
bin/magento module:enable DmLab_TypesenseInstantSearch
bin/magento setup:upgrade
```

Upgrading from 0.1.x: run `bin/magento dmlab:typesense:search-key:ensure` after `setup:upgrade` — it
creates the per-store keys and revokes the 0.1.x key. Until then the widget stays off.

Admin → *Stores → Configuration → DMLab Typesense → Instant Search*:

| Field | Default | Scope | Notes |
|---|---|---|---|
| Enable Instant Search | No | store view | Off → native search |
| Query Mode | Proxy | global | |
| Search-Only Key | — | global | Per-store key status + Regenerate button |
| Public Typesense Host | — | global | *Direct only.* HTTPS host the browser connects to (`search.example.com[:port]`) |
| Minimum Query Length | 3 | store view | |
| Result Limit | 5 | store view | Suggestions shown |
| Debounce (ms) | 200 | store view | |
| Show Title / Image / Price / SKU | Yes / Yes / Yes / No | store view | |

**Direct mode** needs a public HTTPS Typesense host started with `--enable-cors`. The key travels as a
query parameter, so requests are CORS simple requests — no preflight.

## Key model — only derived keys leave the server

- Each store view gets its own **parent keys**, stored encrypted at store scope together with their
  Typesense key ids: a **proxy** key (always) and a **public** key (direct mode only), both limited to
  `documents:search` on that store's alias.
- Keys are created on config save and on store/website save (for store views with Instant Search
  enabled), or by CLI: `bin/magento dmlab:typesense:search-key:ensure|regenerate [--store=<code>]`.
- Changing the index prefix rotates the keys on save; after `config:set`, run `:ensure`.
- Deleting a store view, store or website revokes its keys by id.
- **Regenerate** creates new keys, saves them, flushes the FPC, then revokes the old keys **by id** —
  other installs sharing the cluster are never touched. A failed create keeps the old key.
- Requests use locally derived HMAC keys that expire: page keys are bucketed by the FPC TTL, so cached
  pages stay byte-identical; proxy keys live 5 minutes.
- The single 0.1.x key is revoked on the first ensure (matched by description and value prefix).
- No admin key → saving fails with a clear error. No proxy key → the widget is not rendered and the
  storefront keeps native search. No public key → guests use the proxy.

## Prices, images, rate guard

- Prices follow the store currency and the catalog tax display setting in both modes.
- Thumbnails are resized cache URLs of the `product_thumbnail_image` view.xml image, not originals.
- The proxy rate-limits per client IP (30 requests / 10 s). `X-Forwarded-For` is honoured only from
  trusted proxies; list yours in `app/etc/di.xml` or a module `di.xml`:

```xml
<type name="DmLab\TypesenseInstantSearch\Model\ClientIp">
    <arguments>
        <argument name="trustedProxies" xsi:type="array">
            <item name="lb" xsi:type="string">10.0.0.10</item>
        </argument>
    </arguments>
</type>
```

## How it replaces the stock autocomplete

A RequireJS **map override** swaps the `quickSearch` widget implementation in place, inheriting the
stock `data-mage-init` options and rendering into the stock `#search_autocomplete` container. Config
reaches JS as an added `<script type="application/json">` — no template or layout override, so custom
themes using the stock search form need no changes. Any JS/network/config error falls through to the
native search form.

In **direct mode only**, `Model\Csp\TypesensePolicyCollector` adds the configured host to `connect-src`
at runtime (it is admin config, so a static whitelist cannot carry it); in proxy mode it is a no-op.

## Compatibility

**Luma/RequireJS themes only — Hyvä is not supported.** The widget binds through RequireJS
`quickSearch`; on Hyvä its native search stays and this module's widget is inert.

## Requirements

Magento 2.4.9+ · PHP 8.3–8.5 · `dmlab/module-typesense-core` and `-indexer` 0.2.x (the indexer maintains
`<mainAlias>_public`) ·
(direct mode) a public, CORS-enabled Typesense host.

## License

OSL-3.0 © DMLab.
