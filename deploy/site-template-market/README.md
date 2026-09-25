# Whole-site template market — local release preparation

This directory is a deployment overlay for the update service. Nothing here publishes automatically.
The client entry is `/admin/site_template_market.php`; it downloads and verifies an archive, then opens the existing whole-site import preview. The importer's CMS-series and exact schema checks, dependency checks, replace confirmation (sites with existing content), staged extraction and transaction remain authoritative. Packages from this market are verified before the preview opens, so the trusted-source confirmation required for uploaded ZIPs is skipped.

## Package and catalog contract

- Catalog: `GET https://update.yikaicms.com/api/site-templates/list.php?protocol_version=1&cms_version=2.0.0&php_version=8.0.0&format_versions=1,2`.
- Envelope: `{"code":0,"data":{"protocol_version":1,"updated_at":"ISO-8601","templates":[]}}`.
- Item: `slug`, localized `name/description/category_name` (plain, `_en`, `_ja`), `category`, semantic `version`, `cms` (the CMS version the package was built on; compatible with sites of the same major.minor series that are not older), integer `format_version`, `requires_php` (`>=8.0.0`), `status` (`draft` or `published`), `tier` (`free` or `pro`; `pro` entries are listed but blocked), optional `screenshot`, optional `demo_url` (only `https://demo.yikaicms.com/<dir>/` is accepted by the client).
- Package: `<slug>-site-v<version>.zip` at `/packages/site-templates/`. This is the original SiteTemplateService archive, not a ThemeInstaller ZIP.
- Delivery fields: `package`, `download_url`, exact integer `size_bytes` (maximum 32 MiB), lowercase `hash` (`sha256:<64 hex>`), base64 `sig`.
- Cover: `/assets/site-templates/<slug>/<version>/preview.webp`. JPG/PNG cover extensions are accepted for older assets.
- Signature protocol v2: RSA-SHA256 over the UTF-8 bytes of `site-template-v2|<slug>|<version>|<cms>|<format_version>|<requires_php>|<tier>|<status>|<package>|<size_bytes>|sha256:<hex>`, **without a trailing newline**. It binds compatibility and authorization state plus every field that selects or describes the downloaded bytes. There is intentionally no legacy-v1 verification fallback: all draft entries must be re-signed with v2 before publication.
- Only free official resources are supported by this first endpoint. A paid tier stays blocked; this endpoint is not an entitlement bypass.

The 20 entries in `data/site-templates.json` are the original 1.20.1 draft preparation catalog. Their `cms` is 1.20.1, so 2.0.x sites show them as incompatible; they remain only as a format sample. The published 2.0.0 catalog (30 templates) is generated in the release build directory outside this repository. Unpublished, unsigned, incompatible or unsupported-format entries can be displayed but carry no usable delivery information. The API returns only its public allowlist; local source paths and preparation notes are never returned. The server does not sign anything and stores no private keys.

## Release procedure

1. Finish each source site, convert its generated images and cover to WebP, and export the whole-site ZIP.
2. Re-import each ZIP into a clean matching installation and verify pages, media, editable documents, plugin data and private-data exclusions.
3. Copy the ZIP unchanged to the catalog package filename, plus the cover to the documented path. Fill version, exact CMS, format version, byte count and SHA256 from the final bytes.
4. Use the existing authorized signing process with its existing private key to sign the canonical string. This task creates no production keys and generates no production signatures. Never commit a private key.
5. Set `status=published` only after the package/cover and signature are complete and release has been explicitly authorized. Upload the immutable package and cover first, then this API and the catalog. Recheck HTTP bodies, SHA256, signature and the actual admin preview after release.
6. Preserve versioned packages. A new export receives a new version; never overwrite an already published ZIP under the same name.

The local `data/site-templates.json` is a preparation catalog. Empty delivery values intentionally block downloads and must not be force-enabled.

## Compatibility and operator experience

The importer accepts a package built on the same major.minor CMS series as the site and not newer than it (for example a 2.0.0 package on a 2.0.3 site); the content schema must still match exactly. Theme metadata such as `requires_cms` does not override this.
Format 1 is for ordinary packages. Format 2 carries supported public plugin data and needs a 2.0.0 or later importer. Plugins declared by the package are listed on the import step, ticked by default, and installed from the official plugin market (or enabled locally) before their data is imported; theme `required_plugins` cannot be unticked, and new plugin-data packages cannot bypass missing dependencies.

An administrator opens the site template market (optionally views the live demo), chooses **Preview and import**, reviews the package and ticks the plugins to install, enters site details, then confirms import. A site with existing content must also tick the backup/replace confirmation on that step. Market selection alone never applies a package or ticks a confirmation box.

Catalog reads are bounded to 512 KiB/200 items, cached for display for 60 seconds (including failure), and fetched again on every download POST. Downloads require authenticated metadata, fixed HTTPS official paths, no redirects, exact byte length and SHA256. Local test fixtures use transport injection and ephemeral test keys only; runtime URLs/public keys have no test override.

## Verification

`php vendor/bin/phpunit --filter SiteTemplateMarketTest`

`php tests/fixtures/site-template-market-probe.php` (PHP 8.0; Windows may require `OPENSSL_CONF` pointing to the local OpenSSL configuration for the ephemeral test key).

The page is included in `tests/smoke/admin_pages.php`. Its normal unavailable-catalog state is a rendered page with the local ZIP import path still available.

## Local twenty-template review

`php tools/prepare-site-template-market.php --inventory=<sites.json> --delivery=<dedicated-public-staging-directory> --covers=<existing-cover-directory> --catalog=deploy/site-template-market/data/site-templates.json --import-report=<external-import-validation-summary.json>`

Run this only after the final ZIPs and desktop WebP covers are stable. `--delivery` must be a dedicated public staging root containing only the exact catalog packages and previously generated public output; unknown files, directories and symlinks are rejected. Do not point it at a general delivery workspace containing reports, credentials, source files or backups.

The preparation process must have exclusive access to `--delivery` and the catalog file: do not run another copier, generator or cleanup job against them at the same time. The tool snapshots packages into a private candidate, rechecks live target containment before and after each replacement, and rolls back ordinary PHP errors. Its cross-file commit is not power-loss or forced-process-termination atomic on Windows because PHP 8.0 exposes no portable `ReplaceFile` transaction; after an interrupted run, rerun validation from the unchanged source packages before using the staging directory.

The verifier runs without a site database. It validates every ZIP path and digest, expansion limits, the manifest/file-list match, required theme files and all `uploads/...` references in exported data and textual theme files. The external import report must cover every inventory theme with a successful status, the exact version and the verified ZIP's `package_sha256`. Only then does the tool copy covers and write a public-field allowlisted draft catalog, `catalog.json`, `index.php` and `review-router.php`. Its JSON result uses relative public paths and never includes local source paths. It does not copy package binaries into Git.

For local review only: `php -S 127.0.0.1:8169 -t <release-directory> <release-directory>/review-router.php`. The gallery is clearly labeled unpublished and its ZIP links point to the local delivery packages. `review.php` is the gallery source, not a file to deploy as a production market endpoint. Formal deployment consists only of the API, the approved catalog, immutable packages and matching covers.
The local gallery MUST run with review-router.php: it allowlists only the page and catalog-declared ZIP/WebP files. Backups, reports, dependency folders, catalog internals, PHP source and all other paths return 404. Never serve the release directory as an unrestricted static document root.
