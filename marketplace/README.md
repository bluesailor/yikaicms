# Theme marketplace sources

`themes/` is the runtime installation directory. The source tree tracks only `default` there;
the full package build also stages `business` and `minimal` from the marketplace sources so a
new installation starts with three choices. CMS upgrades treat every non-default theme as
site-owned content and never overwrite it.

Optional themes live under `marketplace/themes/{slug}` as their single packaging source. They must be
packed, hashed, and RSA-signed by `update.yikaicms/bin/pack-themes.sh` before publication.
The resulting ZIP files are installed by `admin/theme.php` into `themes/{slug}`.

Version policy:

- The CMS version source of truth is `config/version.php` (`CMS_VERSION`).
- Each market theme owns its own SemVer in `theme.json.version`.
- Each market theme must also declare `theme.json.requires_cms` as `>=` the
  current `CMS_VERSION`; update it whenever the CMS version changes.
- Bump a theme's own `version` whenever its source changes or it depends on a
  new CMS/Blox/theme-runtime capability.
- The full release discipline lives in the internal engineering docs (yikaicms-docs/theme-versioning-policy.md), which are not part of the public repository.

Current market themes:

- `aurora`
- `business`
- `minimal`
- `trade`

Banner media compatibility is tracked in `marketplace/banner-media-compatibility.json`.
Every listed native theme must render Banner images and videos through the recorded
`HomeBannerItemElement` shared media method. A `default-fallback` theme intentionally
omits its own Banner template and inherits the supported default one.
Market ZIPs also carry `capabilities.banner_video: true` in `theme.json`; the checklist
test keeps that package metadata aligned. Update the checklist and bump the affected
theme version whenever this contract changes.

`marketplace/retired/` is local archival material and is intentionally ignored and excluded
from release packages. The old `blox` theme shell is retired because the Blox editor now uses
the supported `default` theme directly.
