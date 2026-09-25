# Plugin-owned data in whole-site packages

For plugin authors: how a plugin makes its own public data travel with a whole-site template package, and how import treats plugins the package depends on. Read it together with [Plugin development](./PLUGIN-DEVELOPMENT.md).

## Ownership and default-deny boundary

The core never chooses plugin tables, fields, settings or SQL. For each active plugin it calls:

```php
apply_filters('site_template_plugin_export', [], $slug)
```

The unchanged `[]` means that no plugin data is exported. An adapter must explicitly return contract version 1, its own schema fingerprint, a JSON-safe public payload and a target-only state digest. The state digest and `replaceable` flag may cover private activity, but are never written to an exported package.

Portable data is stored as `plugin-data/<slug>.json`. Its path is declared by `manifest.plugin_data`, and its bytes are covered by the existing `manifest.files` SHA-256 map. Packages without plugin data remain format v1; packages with it use format v2 so older importers reject rather than silently drop the contract.

Each plugin JSON entry is capped at 4 MiB, all plugin entries together at 8 MiB, and the decoded node budget is shared across the package. These bounds apply during both online inspection and offline market verification so preview cannot exhaust a 128 MiB shared-host process.

The core settings allowlist remains unchanged. A plugin must never work around it by broadening `SiteTemplateData::settingAllowed()` for a prefix such as `shop_*` or `seo_*`.

## Import and recovery

Before copying or staging files, `prepare()` checks each available target adapter through the same export filter. Schema id, version and SHA-256 must match exactly, and target state must be replaceable. A missing dependency remains blocked by default; after the replace-content confirmation (and the trusted-source confirmation for uploaded packages), only that missing slug's payload is skipped. A present but incompatible adapter cannot be bypassed.

When a declared plugin is already on the site, or available from the official plugin market, the import step lists it as an option that is ticked by default. The single import action installs (through the same verified market chain as the plugin page) and enables the ticked plugins, rebuilds the preview in a new request so their adapters are registered, and then imports their data. Plugins named in the theme's `required_plugins` must also be declared in the package's plugin list; they cannot be unticked, and import stops if they are still missing.

Inside the same database transaction as core replacement, the importer calls:

```php
do_action('site_template_plugin_import', $payload, $slug)
```

It then exports the plugin again and requires the public payload to equal the requested state. A missing/no-op handler, validation error or partial write rolls the entire import back. The local journal retains the target's previous public payload and opaque state digest. Persistent recovery reuses the same import action; a disabled plugin, schema drift or new private activity changes the state digest and closes recovery.

Plugin import callbacks must use the shared `db()` connection and parameterized SQL, validate and lock their own tables, and avoid file, network, mail or queue side effects inside the transaction.

## Shop contract

The shop adapter belongs under `plugins/shop/`, not in core. Its explicit public allowlist contains canonical product sales configuration (`product_id`, SKU, nullable sale price, stock, status and validated SKU variants) plus normalized non-secret storefront/shipping settings. It resets sales counters and timestamps on import.

It must never export orders, order items, payments, payment notifications, refunds, member addresses, manual-payment account details, gateway identifiers, certificates, private keys or API secrets. Any private commerce activity makes the target non-replaceable and prevents later recovery.

## Verification

Run the generic isolated round trip and focused tests under PHP 8.0:

```text
php tests/fixtures/site-template-plugin-probe.php
php vendor/bin/phpunit --filter SiteTemplatePluginData
```

The probe registers real export/import hooks, checks the independent hashed JSON entry, schema rejection before staging, explicit skipping of one missing slug, transaction rollback, post-import verification, target-secret preservation, private-activity recovery blocking and exact recovery through the same action.
