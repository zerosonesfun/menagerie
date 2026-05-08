=== Menagerie ===
Contributors: billywilcosky
Tags: images, optimization, performance, upload, webp, avif
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Client-side image optimization in the browser before upload. Safe fallbacks keep uploads working when optimization cannot run.

Download size and visitors: Prefer no extra browser weight? Use *Server-side only* (and optional server-side fallback) in Settings → Menagerie. If you keep client-side optimization with *Advanced encoders (WebAssembly)* on, sticking to WebP typically adds on the order of ~0.2–0.5 MB to what the browser downloads for the codec path (plus a small main script). AVIF can require larger codec data—up to ~3.5 MB for the encoder in a worst case—but that loads only when someone uploads an image and the pipeline reaches AVIF; the browser caches it, so it is not a full repeat download every time. The optimizer runs only on pages where uploads are enabled (per your settings), not on every page view for every visitor. Client-side optimization is not a heavy download for casual browsing.

== Description ==

Menagerie by Billy Wilcosky (https://wilcosky.com) resizes and re-encodes images in the visitor’s browser before they reach your server. If anything fails—browser limits, timeouts, or unsupported formats—the original file is uploaded unchanged.

* No external services or APIs
* AVIF / WebP / JPEG strategies with automatic fallbacks
* Transparency-aware encoding
* EXIF orientation handled via modern decode APIs where supported
* Toast status messages (accessible, with reduced-motion support)
* Optional detection of other image optimization plugins to reduce double compression
* Attachment meta records when Menagerie optimized a file (original size, optimized size, output MIME type)—filenames are not used for tracking

== Installation ==

1. Upload the `menagerie` folder to `/wp-content/plugins/`
2. Activate **Menagerie** through the **Plugins** screen
3. Go to **Settings → Menagerie** to configure options

== Building from source / releases ==

* For **Advanced encoders (WebAssembly)** to run in the browser, the Vite output must exist: `assets/js/dist/menagerie-optimizer.js` and its sibling `assets/` chunks. From the plugin directory run: `npm install` then `npm run build`. Commit the `assets/js/dist/` tree with your release.
* The live script only turns **wasmEncoders** on in the browser when that bundle is present; otherwise the classic encoder path is used. If you save the setting without a build, an admin notice explains what is missing.
* **Bulk uploads** (many images at once): WASM work is **queued** on a single worker so jobs do not overlap; combined with the existing upload queue, multi-file batches complete in order without corrupting encodes.

== Frequently Asked Questions ==

= Will this break my uploads? =

No. Optimization is optional enhancement. If processing fails, the plugin uses the original file.

= Why do I see a warning about other optimization plugins? =

Running Menagerie together with server-side optimizers (Smush, ShortPixel, EWWW, etc.) can compress images twice. Consider disabling overlapping features in one of the plugins.

= My file keeps its original extension but the bytes are WebP/JPEG. Is that OK? =

WordPress and PHP typically rely on MIME detection during upload. Menagerie does not rename files by design.

= How does quality compare to other optimizers? =

Default quality (85) is in the same range many hosting and SaaS tools use for “lossy” web delivery (often roughly 80–90). You can raise or lower it in Settings. Downscaling uses the browser’s high-quality image smoothing when resizing.

= Are GIFs optimized? =

Animated GIFs are left unchanged so frames are not collapsed to a single image. Static GIFs are also skipped for the same reason; upload them as PNG or JPEG if you need smaller files.

= Will colors look exactly like my original? =

The browser encodes to sRGB via canvas, similar to other client-side tools. Very wide-gamut or HDR sources may look slightly different on some displays—that is normal for web-safe output.

= What does “Advanced encoders (WebAssembly)” do? =

Optional MozJPEG / WebP / AVIF encoders run in the browser (via the jSquash libraries) and often produce smaller or higher-quality files than the browser’s built-in canvas encoder at the same quality setting. The first encode may download codec data; very large AVIF codecs load only when AVIF output is attempted. If anything fails, Menagerie falls back to the built-in encoder. The setting only activates WASM in the browser when `assets/js/dist/` is built and present—see **Building from source / releases** above.

= How hard does Menagerie try to output AVIF and WebP? =

With Advanced encoders on, AVIF and WebP each get several WASM attempts with short delays (cold WebAssembly load), faster encoder options where the libraries allow, prewarming both codecs in the worker after load, then the browser’s native canvas encoder with a quality ladder before moving to the next format. JPEG WASM does not use the same multi-retry pattern because the canvas JPEG path is usually reliable.

= Do bulk or multi-file uploads work? =

Yes. Uploads are processed in sequence so memory stays bounded, and WASM encodes are queued on one worker so concurrent jobs cannot clash—suitable for selecting many images in the Media Library or batch front-end uploads.

= Can the server optimize an image that was already optimized in the browser? =

Usually no: when the browser succeeds, Menagerie sends menagerie_meta and the attachment is marked so server-side fallback skips it. In rare cases—network loss, REST quirks, or a failed nonce—that meta never arrives, _menagerie_processed is not set, and server-side fallback may encode the file again. That is uncommon.

= Server-side fallback: performance and timeouts =

Server-side optimization runs during the upload request (decode, resize, encode, regenerate thumbnails). Very large originals on low PHP max_execution_time or memory limits can fail or time out; raising limits or uploading smaller sources helps. Async or background processing is not implemented yet.

= Does server-side fallback prefer AVIF? =

In Auto format mode, yes—the server uses the same order as the browser (AVIF, then WebP, then JPEG or PNG). You only get AVIF or WebP if PHP can encode that format (see Tools → Site Health → Info → Media handling). Menagerie attempts AVIF and WebP each twice before falling through. If the host cannot encode a format, the chain continues automatically.

= Why would server-side optimization do nothing when both “Process uploads” options are off? =

Settings requires at least one of “Process uploads in the admin” or “Process uploads on the front end” for any Menagerie processing—including server-side fallback. If both are unchecked, nothing runs; enable the context you use.

= Why might the same image become AVIF in the admin but WebP on the front end? =

On the **front end**, file inputs are optimized once when you pick a file, then the upload runs again through the same pipeline. The second pass skips work when the file is already small and “Convert when useful” applies—so the first successful format (AVIF, WebP, or JPEG in order) tends to stick. The **admin** Media Library usually runs a single optimization pass on the original file. Menagerie also prewarms encoders after load so the first upload is closer to later ones. None of this changes settings between contexts; it is timing and pass count.

== Screenshots ==

1. Media Library: uploading an image; success toast at the bottom right.
2. Attachment details: optimized file shows AVIF type and a small file size.

== Changelog ==

= 1.0.3 =
* Ability to hide plugin conflict banner in admin longer

= 1.0.2 =
* Fix MIME type wasm error

= 1.0.1 =
* Readme updates

= 1.0.0 =
* Initial release.
* Skip a second browser encode at upload time when the file was already optimized (e.g. front-end file picker + XHR), improving parity with admin uploads and reducing duplicate work.
* Idle-time encoder prewarm (native probes; WASM AVIF touch when Advanced encoders are on).

== Upgrade Notice ==

= 1.0.2 =
MIME type console error fixed.

= 1.0.0 =
Initial release.
