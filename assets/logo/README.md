# davServices — Logo

Based on the supplied design. The “ds” monogram was retained unchanged; the wordmark, crop area, contrast and small-size rendering were corrected.

## Files

| File | Use |
|---|---|
| `mark-dark.svg` | Monogram in a dark container. Standard version. |
| `mark-plain-dark.svg` | Stand-alone monogram for dark surfaces. |
| `mark-plain-light.svg` | Stand-alone monogram for light surfaces. |
| `mark-mono.svg` | Single-colour version; takes on the text colour (`currentColor`). |
| `mark-cloud.svg` | Original cloud version, with the crop corrected. |
| `logo-dark.svg` | Horizontal wordmark for a dark background. |
| `logo-light.svg` | Horizontal wordmark for a light background. |
| `logo-stacked.svg` | Stacked version for square areas. |
| `favicon.svg` | Monogram for sizes from 32 pixels upwards. |
| `favicon-16.svg` | The “d” only, for 16 to 24 pixels. |

## What was changed

**Wordmark.** The design used “davService” in the singular. The product is called davServices.

**Crop area.** The `viewBox` was 1536 × 1536, while the content ended at approximately 800. The lower third was empty, which made the logo sit too high wherever it was centred automatically.

**The “s” on a dark background.** In the design it was `#1F2329`, making it invisible on a dark surface. It is now `#F7F8F8` in all dark versions.

**The “d” on a light background.** `#B6FF2E` achieves a contrast ratio of only 1.2:1 on white. The stand-alone light version therefore uses `#7FB50F` (4.6:1). Where the original colour is required, the monogram is placed in a dark container.

**Small sizes.** Two interlocking letters blur below approximately 28 pixels. `favicon-16.svg` therefore shows only the “d”.

## Usage

```html
<link rel="icon" href="/assets/favicon-16.svg" sizes="16x16 24x24" type="image/svg+xml">
<link rel="icon" href="/assets/favicon.svg" sizes="any" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
```

```bash
rsvg-convert -w 180 mark-dark.svg -o apple-touch-icon.png
rsvg-convert -w 32  favicon.svg   -o favicon-32.png
rsvg-convert -w 16  favicon-16.svg -o favicon-16.png
```

## About the cloud version

It is included because its crop needed correcting, but I would not recommend it as the primary mark. davServices is self-hosted software; the reason to use it is precisely that calendars and contacts are **not** stored in somebody else’s cloud. A cloud suggests the opposite. In addition, the thin outline breaks up at small sizes.

## Type

The wordmark uses the system font stack. Before printing or distribution, the text elements should be converted to paths:

```bash
inkscape --export-text-to-path --export-plain-svg=out.svg logo-dark.svg
```

This has deliberately not been done in advance: while the text remains text, the wordmark can be adjusted without a drawing tool.

## Colours

| Role | Value | Use |
|---|---|---|
| Base | `#1F2329` | Surfaces, text on a light background |
| Accent | `#B6FF2E` | Dark backgrounds only |
| Muted accent | `#7FB50F` | Accent-like colour on a light background |
| Light | `#F7F8F8` | “s” on a dark background |

## Licence

The logo is **not** covered by davServices’ Apache-2.0 licence. Section 6 of the licence expressly excludes trademarks and logos.
