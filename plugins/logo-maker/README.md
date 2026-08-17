# Logo Maker

This plugin provides a small offline logo maker for the CMS admin. It does not require an API, network access, or bundled font files.

## Workflow

1. Open **Appearance > Logo Maker**. The plugin is included and enabled by default.
2. Enter the brand text and optional tagline.
3. Choose a basic layout, symbol, colors, and background, then generate the logo locally.
4. Preview or download SVG/PNG/ICO files, or apply a result as the site logo or favicon.
5. Open `https://logo.yikaicms.com` for fonts, advanced wordmarks, batch candidates, and complex editing.

The SVG uses system font fallbacks such as Arial and Microsoft YaHei. No font file is copied into the CMS package, and the core plugin remains usable when the network is unavailable.

Candidate SVGs are kept in the logged-in admin session for ten minutes. Applying a candidate performs a second server-side SVG safety check before writing it under `uploads/brand/`.
