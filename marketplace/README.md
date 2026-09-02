# Theme marketplace sources

`themes/` is the runtime installation directory and the CMS package only bundles `default`.

Optional themes live under `marketplace/themes/{slug}` as packaging sources. They must be
packed, hashed, and RSA-signed by `update.yikaicms/bin/pack-themes.sh` before publication.
The resulting ZIP files are installed by `admin/theme.php` into `themes/{slug}`.

Version policy:

- The CMS version source of truth is `config/version.php` (`CMS_VERSION`).
- Each market theme owns its own SemVer in `theme.json.version`.
- Each market theme must also declare `theme.json.requires_cms` as `>=` the
  current `CMS_VERSION`; update it whenever the CMS version changes.
- Bump a theme's own `version` whenever its source changes or it depends on a
  new CMS/Blox/theme-runtime capability.
- See `docs/theme-versioning-policy.md` for the full release discipline.

Current market themes:

- `aurora`
- `business`
- `minimal`
- `trade`

`marketplace/retired/` is local archival material and is intentionally ignored and excluded
from release packages. The old `blox` theme shell is retired because the Blox editor now uses
the supported `default` theme directly.
