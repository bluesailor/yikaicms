# Logo Maker

This plugin is the offline logo and favicon workspace for the CMS admin. It does not require an API, network access, or bundled font files. It includes the main local capabilities previously provided by `icon-maker`; the old plugin is not loaded at runtime.

## Included capabilities

- Multi-layer LOGO canvas: add, select, drag, resize, recolor, bold, spacing, horizontal/vertical text, and optional SVG icon layers.
- Local candidate generator: 12 deterministic SVG candidates with industry, palette, effect, background, letter-style, and seed controls.
- Icon drawing canvas: a 512 x 512 local workspace for rectangles, ellipses, triangles, stars, lines, brush paths, and text, with undo/redo and guide grid.
- Candidate workflow: reorder candidates or send a selected candidate into the multi-layer canvas.
- Drawing workflow: send the drawn icon directly into this YikaiCMS site's LOGO composition or favicon workflow; it is an internal admin tool, not a standalone file-export page.
- Text and image favicon generation with 16/32/48 previews and direct site-icon application.
- One-click in-site application for favicon, Apple touch icon, Android icons, and `site.webmanifest`; no favicon or icon-pack download is exposed.
- One-click application of the composed LOGO as the site logo; no standalone LOGO file export is exposed.

## Workflow

1. Open **Appearance > Logo Maker**. The plugin is included and enabled by default.
2. Start with **随机图标** or **绘制图标**. This keeps the icon-making step separate from final brand-text composition.
3. Send an icon to **LOGO 排版**, then apply the composed result to the site after confirmation.

The browser uses system font fallbacks such as Arial and Microsoft YaHei. The icon-library SVGs are copied into this plugin so the geometry candidate route remains available offline. ICO files are stored under `uploads/brand/` and activated through the existing `site_favicon` `<head>` link; the legacy root `favicon.ico` remains a fallback. No remote API, API key, CDN, or font download is needed.
