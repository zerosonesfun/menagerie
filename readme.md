# Menagerie

**Contributors:** billywilcosky  
**Tags:** images, optimization, performance, upload, webp, avif  
**Requires WordPress:** 6.9+  
**Tested up to:** 7.0  
**Requires PHP:** 8.0+  
**Stable tag:** 1.0.0  
**License:** [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

Client-side image optimization in the browser before upload. Safe fallbacks keep uploads working when optimization cannot run.

## Description

Menagerie by [Billy Wilcosky](https://wilcosky.com) resizes and re-encodes images in the visitor’s browser before they reach your server. If anything fails—browser limits, timeouts, or unsupported formats—the original file is uploaded unchanged.

- No external services or APIs
- AVIF / WebP / JPEG strategies with automatic fallbacks
- Transparency-aware encoding
- EXIF orientation handled via modern decode APIs where supported
- Toast status messages (accessible, with reduced-motion support)
- Optional detection of other image optimization plugins to reduce double compression
- Attachment meta records when Menagerie optimized a file (original size, optimized size, output MIME type)—filenames are not used for tracking

## Installation

1. Upload the `menagerie` folder to `/wp-content/plugins/`
2. Activate **Menagerie** in **Plugins**
3. Open **Settings → Menagerie** to configure options

## Building from source / releases

- For **Advanced encoders (WebAssembly)** to run, the Vite build must be present: `assets/js/dist/menagerie-optimizer.js` plus the generated `assets/` chunks beside it. From the plugin directory run `npm install` then `npm run build`, and commit `assets/js/dist/` with your release.
- The browser only receives `wasmEncoders: true` when that bundle exists; otherwise the classic script path is used and behavior matches “WASM off.” If you enable the setting without running the build, a **Settings** notice explains that `dist/` is missing.
- **Bulk uploads**: WASM encodes are **serialized** on one worker queue so overlapping jobs cannot corrupt output; together with the existing sequential optimization queue, many-file batches are handled safely.
- **Zipping without `node_modules`**: run `scripts/make-zip.sh` from the repo (it writes `menagerie-release.zip` next to the plugin parent folder, excluding `node_modules`). Or manually: `zip -r my-menagerie.zip menagerie -x "menagerie/node_modules/*"`. Do not distribute `node_modules` to end users.

## Frequently asked questions

### Will this break my uploads?

No. Optimization is an optional enhancement. If processing fails, the plugin uses the original file.

### Why do I see a warning about other optimization plugins?

Running Menagerie together with server-side optimizers (Smush, ShortPixel, EWWW, etc.) can compress images twice. Consider disabling overlapping features in one of the plugins.

### My file keeps its original extension but the bytes are WebP or JPEG. Is that OK?

WordPress and PHP typically rely on MIME detection during upload. Menagerie does not rename files by design.

### How does quality compare to other optimizers?

Default quality (85) is in the same range many hosting and SaaS tools use for “lossy” web delivery (often roughly 80–90). You can raise or lower it in **Settings → Menagerie**. Downscaling uses the browser’s high-quality image smoothing when resizing.

### Are GIFs optimized?

Animated GIFs are left unchanged so frames are not collapsed to a single image. Static GIFs are also skipped for the same reason; upload as PNG or JPEG if you need smaller files.

### Will colors look exactly like my original?

The browser encodes to sRGB via canvas, similar to other client-side tools. Very wide-gamut or HDR sources may look slightly different on some displays—that is normal for web-safe output.

### What does “Advanced encoders (WebAssembly)” do?

Optional MozJPEG, WebP, and AVIF encoders run in the browser (via the [jSquash](https://github.com/jamsinclair/jSquash) libraries) and often produce smaller or higher-quality files than the browser’s built-in canvas encoder at the same quality setting. The first encode may download codec data; large AVIF codecs load only when AVIF output is attempted. If anything fails, Menagerie falls back to the built-in encoder.

WASM is only activated when the built bundle exists under `assets/js/dist/`—see **Building from source / releases**. See `THIRD-PARTY-LICENSES.md` for codec licenses.

### Do bulk or multi-file uploads work?

Yes. Work is queued so large batches stay predictable, and WASM encodes run one-at-a-time on the worker queue to avoid races during bulk or rapid uploads.

## Screenshots

1. Settings under **Settings → Menagerie**

## Changelog

### 1.0.0

- Initial release.

## Upgrade notice

**1.0.0** — Initial release.
