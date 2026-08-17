# Theme marketplace sources

`themes/` is the runtime installation directory and the CMS package only bundles `default`.

Optional themes live under `marketplace/themes/{slug}` as packaging sources. They must be
packed, hashed, and RSA-signed by `update.yikaicms/bin/pack-themes.sh` before publication.
The resulting ZIP files are installed by `admin/theme.php` into `themes/{slug}`.

Current market themes:

- `aurora`
- `business`
- `minimal`
- `trade`

`marketplace/retired/` is local archival material and is intentionally ignored and excluded
from release packages. The old `blox` theme shell is retired because the Blox editor now uses
the supported `default` theme directly.
