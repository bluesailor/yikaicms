# Public plugin data in site-template packages

This is a local delivery candidate. It does not publish or enable plugins on customer sites.

## Contract

Ordinary packages remain format v1. Packages containing supported plugin data use v2: older importers reject them instead of silently ignoring the data. Plugin code is neither bundled nor executed. The target must independently install and enable every required plugin before applying v2, and cannot skip this check using legacy force confirmation.

`SiteTemplatePluginData` uses fixed table and field allowlists. Unsupported plugins retain the explicit excluded-data warning. The core settings allowlist is not widened to any plugin prefix. Public sales data is applied and restored in the same transaction as core content. Private activity is hashed only for the local freshness/recovery guard, never exported. A target with shop customer activity is rejected, and activity after import blocks restoration. Plugin migrations are not run by template import.

| Plugin | Portable data | Excluded |
| --- | --- | --- |
| stay-inquiry | Explicit stateless marker; room products, images and room-booking form already use core public tables | Submitted inquiries and visitors |
| pet-cart-demo | Explicit stateless marker, with shop dependency required | Sessions, carts, users, operational state |
| shop | Canonical product_id, sku, nullable price, stock, status, validated specs_json; show_price | Sales counts, orders/items, addresses, payments/notifications/refunds, gateway keys, mail settings and accounts |

Variant fields are limited to id, label, SKU, price and stock. Stable IDs, product references, bounds and stock totals are checked. Shipping/payment configuration remains target-owned: public catalog and cart examples do not imply checkout or payment parity.

## Demo migration boundary

- Minsu uses stay-inquiry 1.0.0 with no private tables/settings. Its plugin must be installed separately and its room-booking form retained. Room-type/consent values are currently hardcoded in validation.php, so form and plugin choices must remain compatible. This is an inquiry form, not availability, booking confirmation or payment.
- The pet development site currently uses shop 0.8.0 and pet-cart-demo 1.1.0. Its guard now permits test orders/order lookup while blocking real payment; the older README describes an earlier guard. Do not copy this actively developed shop into a marketplace release.
- Pet also customizes product.php, the theme header, a shop cart view and config/overrides.php. Core/plugin file overrides do not travel in site templates. Delivery needs the official purchase hook or Builder element, an independently reviewed/versioned plugin release, reconciliation of theme settings with pinned overrides, and isolated cart/test-order/payment-blocking checks.
- No source demo/plugin files were changed by this patch. Pet marketplace publication is not included. A stable plugin release and portable theme integration remain separate gates.

## Tests

Run under PHP 8.0:

```
php tests/fixtures/site-template-probe.php
php tests/fixtures/site-template-plugin-probe.php
```

The plugin probe uses an isolated in-memory database and temporary site. It verifies privacy exclusion, shared translation stock, strict variant data, format downgrade rejection, missing dependencies despite force confirmation, atomic rollback on sales insertion failure, no copied customer records, refusal to restore over a new order, and exact persistent recovery. It does not connect to any demo database or execute plugin code.
