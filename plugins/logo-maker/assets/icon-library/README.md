# Industry Icon Library

This directory is generated from the official Phosphor Icons and Tabler Icons repositories.

- `phosphor/bold`: Phosphor bold SVG assets.
- `phosphor/duotone`: Phosphor duotone SVG assets.
- `tabler/outline`: Tabler outline SVG assets.
- `catalog.json`: Offline search catalog with source, style, category and tags.
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

