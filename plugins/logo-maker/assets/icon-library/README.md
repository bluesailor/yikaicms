# Industry Icon Library

This directory is generated from the official Phosphor Icons and Tabler Icons repositories.

The icon set covers three upstream styles — `phosphor/bold`, `phosphor/duotone` and
`tabler/outline` — addressed by those name prefixes inside the bundle below.
- `icons.bin` + `icons-index.php`: Distribution format **and single source of truth** —
  every SVG concatenated into one blob plus a name → [offset, length] index. Runtime
  seeks and reads only the bytes it needs. They were never shipped as loose files:
  7,618 separate entries exceed the ZIP entry limit of the plugin installer's
  zip-bomb guard, which made the plugin impossible to install from the marketplace.

  The loose `phosphor/` and `tabler/` directories were removed from the repository on
  2026-08-30 (7,618 files / 6.5 MB). They were fully redundant with the bundle — the
  index carries exactly the same 7,618 entries — shipped nowhere and read by nothing at
  runtime. To change the icon set, re-import from upstream checkouts (see below) and
  regenerate the bundle with `tools/build_logo_icon_bundle.php`.
- `catalog.json`: Offline search catalog with source, style, category and tags
  (development aid; not read at runtime and not shipped).
- `industry-map.php`: Curated runtime mapping from industries to logo motifs.
- `stats.json`: Imported asset counts.
- `licenses/`: Required upstream license notices.

The SVG files are normalized and sanitized by `tools/import-icon-library.php`. Runtime generation reads only the curated entries in `industry-map.php`; it never loads the full catalog into the browser.

Brand and product-logo assets are excluded during import and must not be added to the runtime mappings.

Rebuild from local upstream checkouts:

```powershell
php tools/import-icon-library.php --phosphor=C:\path\to\phosphor-core --tabler=C:\path\to\tabler-icons --output=assets\icon-library
```

Do not add brand logos to the industry mappings.

