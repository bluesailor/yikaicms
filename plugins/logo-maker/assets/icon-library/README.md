# Industry Icon Library

This directory is generated from the official Phosphor Icons and Tabler Icons repositories.

- `phosphor/bold`: Phosphor bold SVG assets.
- `phosphor/duotone`: Phosphor duotone SVG assets.
- `tabler/outline`: Tabler outline SVG assets.
- `icons.bin` + `icons-index.php`: Distribution format — every SVG concatenated into
  one blob plus a name → [offset, length] index. Runtime seeks and reads only the
  bytes it needs. Regenerate with `tools/build_logo_icon_bundle.php` after changing
  any asset. The loose `phosphor/` and `tabler/` directories stay in the repository
  as the source of truth but are **not** shipped: 7,618 separate entries exceed the
  ZIP entry limit of the plugin installer's zip-bomb guard, which made the plugin
  impossible to install from the marketplace.
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

