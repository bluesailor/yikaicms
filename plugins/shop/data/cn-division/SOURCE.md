# Mainland-China address data

- Upstream: https://github.com/kk-418/cn-division
- Version: `2026.0.1`
- Pinned commit: `88f2021ea21769bdb95d93699d8a625fcd9165ef` (2026-09-02)
- Imported on: 2026-09-22
- Original file: `dist/code/pca.json` (unaltered UTF-8 JSON)
- SHA-256: `153f67889222d83323c63220bd6e3625221aac2dfd7f3ebc731f7156ac8ee94b`
- Upstream repository license: MIT; the original notice is included as `LICENSE`.
- The upstream states its data is based on the Ministry of Civil Affairs place-name service,
  with explicit supplemental entries for some special administrative areas.

This is a versioned data snapshot, not a guarantee that every administrative change is
already reflected. No upstream scripts, npm package, network API or CDN are needed at runtime.
The dataset contains mainland-China regions only. Some cities without county-level children
use town/street entries or a repeated city node at the third selection level. Codes must be
kept as strings and interpreted together with their parents; a city and its repeated child
may share a six-digit code.

For future updates, download from a reviewed, fixed upstream commit, retain the license,
update this source record, `shopMainlandRegionVersion()` and the pinned SHA-256 in
`shopMainlandRegionTree()`, and run the ShopRegion,
ShopShipping and checkout cascade regression tests. Do not silently fetch latest data on
customer sites or rewrite historical order address snapshots. Existing shipping configuration
continues to use canonical name paths; review renamed/merged regions before updating data.
