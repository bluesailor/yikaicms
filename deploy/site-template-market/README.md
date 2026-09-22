# Whole-site template market — local release preparation

This directory is a deployment overlay for the update service. Nothing here publishes automatically.
The client entry is `/admin/site_template_market.php`; it downloads and verifies an archive, then opens the existing whole-site import preview. The existing fresh-install fingerprint, exact CMS/schema checks, dependency checks, trust confirmation, staged extraction and transaction remain authoritative.

## Package and catalog contract

- Catalog: `GET https://update.yikaicms.com/api/site-templates/list.php?protocol_version=1&cms_version=1.20.1&php_version=8.0.0&format_versions=1,2`.
- Envelope: `{"code":0,"data":{"protocol_version":1,"updated_at":"ISO-8601","templates":[]}}`.
- Item: `slug`, localized `name/description/category_name` (plain, `_en`, `_ja`), `category`, semantic `version`, exact `cms`, integer `format_version`, `requires_php` (`>=8.0.0`), `status` (`draft` or `published`), `tier` (`free`).
- Package: `<slug>-site-v<version>.zip` at `/packages/site-templates/`. This is the original SiteTemplateService archive, not a ThemeInstaller ZIP.
- Delivery fields: `package`, `download_url`, exact integer `size_bytes` (maximum 32 MiB), lowercase `hash` (`sha256:<64 hex>`), base64 `sig`.
- Cover: `/assets/site-templates/<slug>/<version>/preview.webp`. JPG/PNG cover extensions are accepted for older assets.
- Signature: RSA-SHA256 over the UTF-8 bytes of `site-template|<slug>|<version>|<cms>|<format_version>|sha256:<hex>`, **without a trailing newline**. The client uses the existing official license public key. The resource prefix and format/CMS binding prevent cross-resource or format substitution.
- Only free official resources are supported by this first endpoint. A paid tier stays blocked; this endpoint is not an entitlement bypass.

All 20 supplied entries start as `draft`. Unpublished, unsigned, incompatible or unsupported-format entries can be displayed but carry no usable delivery information. The API returns only its public allowlist; local source paths and preparation notes are never returned. The server does not sign anything and stores no private keys.

## Release procedure

1. Finish each source site, convert its generated images and cover to WebP, and export the whole-site ZIP.
2. Re-import each ZIP into a clean matching installation and verify pages, media, editable documents, plugin data and private-data exclusions.
3. Copy the ZIP unchanged to the catalog package filename, plus the cover to the documented path. Fill version, exact CMS, format version, byte count and SHA256 from the final bytes.
4. Use the existing authorized signing process with its existing private key to sign the canonical string. This task creates no production keys and generates no production signatures. Never commit a private key.
5. Set `status=published` only after the package/cover and signature are complete and release has been explicitly authorized. Upload the immutable package and cover first, then this API and the catalog. Recheck HTTP bodies, SHA256, signature and the actual admin preview after release.
6. Preserve versioned packages. A new export receives a new version; never overwrite an already published ZIP under the same name.

The local `data/site-templates.json` is a preparation catalog. Empty delivery values intentionally block downloads and must not be force-enabled.

## Compatibility and operator experience

The current importer requires the package CMS string and content schema to exactly match the destination. Theme metadata such as `requires_cms >=1.20.1` does not override this.
Format 1 is for ordinary packages. Format 2 carries supported public plugin data and requires the new importer; stock 1.20.1 must **not** be advertised as sufficient for those packages before the importer upgrade is released. Required plugin code is installed separately, and new plugin-data packages cannot bypass missing dependencies.

An administrator starts on a fresh installation, opens the site template market, chooses **Preview and import**, reviews the package and required plugins, enters site details, then confirms trust and import in the existing page. Market selection alone never applies a package or checks either confirmation box.

Catalog reads are bounded to 512 KiB/200 items, cached for display for 60 seconds (including failure), and fetched again on every download POST. Downloads require authenticated metadata, fixed HTTPS official paths, no redirects, exact byte length and SHA256. Local test fixtures use transport injection and ephemeral test keys only; runtime URLs/public keys have no test override.

## Verification

`php vendor/bin/phpunit --filter SiteTemplateMarketTest`

`php tests/fixtures/site-template-market-probe.php` (PHP 8.0; Windows may require `OPENSSL_CONF` pointing to the local OpenSSL configuration for the ephemeral test key).

The page is included in `tests/smoke/admin_pages.php`. Its normal unavailable-catalog state is a rendered page with the local ZIP import path still available.

## Local twenty-template review

`php tools/prepare-site-template-market.php --inventory=<sites.json> --delivery=<existing-release-directory> --covers=<existing-cover-directory> --catalog=deploy/site-template-market/data/site-templates.json`

Run this only after the final ZIPs and desktop WebP covers are stable. It reads each final archive's identity, exact CMS/format, size and SHA256; preserves `draft` plus an empty signature; copies the covers; and places `catalog.json` and the standalone `index.php` gallery in the external delivery directory. It does not copy package binaries into Git.

For local review only: `php -S 127.0.0.1:8169 -t <release-directory> <release-directory>/review-router.php`. The gallery is clearly labeled unpublished and its ZIP links point to the local delivery packages. `review.php` is the gallery source, not a file to deploy as a production market endpoint. Formal deployment consists only of the API, the approved catalog, immutable packages and matching covers.
The local gallery MUST run with review-router.php: it allowlists only the page and catalog-declared ZIP/WebP files. Backups, reports, dependency folders, catalog internals, PHP source and all other paths return 404. Never serve the release directory as an unrestricted static document root.
