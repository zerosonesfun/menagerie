=== Menagerie ===
Contributors: billywilcosky
Tags: images, optimization, performance, upload, webp, avif
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes uploaded images smaller and faster - without slowing down your site for visitors.

== Description ==

Menagerie by Billy Wilcosky (https://wilcosky.com) shrinks images when they’re uploaded. Your pages load faster, and you use less storage.

* Works in the browser before the file reaches your server (or on the server if you prefer)
* Saves images in modern formats when possible (AVIF / WebP), with JPEG fallback for older browsers and social sharing
* Safe: if anything goes wrong, the original image still uploads
* No outside services - everything stays on your site

== Installation ==

1. Upload the plugin and activate it
2. Go to **Settings → Menagerie** and choose your options
3. Upload images as usual - Menagerie handles the rest

To convert images already in your Media Library, use **Tools → Image Converter**.

== Frequently Asked Questions ==

= Will this break my uploads? =

No. If optimization fails, the original file is uploaded.

= Do I need another image plugin? =

Usually not. If you already use one (Smush, ShortPixel, EWWW, etc.), turn off overlapping features in one of them so images aren’t compressed twice.

= Are GIFs optimized? =

No. GIFs (and SVGs) are left alone.

= How do I convert old Media Library images? =

**Tools → Image Converter**. Pick a format and run the batch.

= What is the JPEG companion? =

An extra JPEG kept alongside AVIF/WebP so older browsers and social networks can still show the image. Turn it on or off in Settings.

== Screenshots ==

1. Media Library: uploading an image; success toast at the bottom right.
2. Attachment details: optimized file shows AVIF type and a small file size.

== Changelog ==

= 1.2.0 =
* Optional JPEG companion generated in the browser from the original image (not a Media Library attachment).
* Automatic `<picture>` fallback for AVIF/WebP when a companion exists.
* Open Graph prefers the JPEG companion (Yoast/Rank Math filters or native tags).
* Tools → Image Converter generates companions for AVIF/WebP when enabled in Settings.
* Companion files and meta cleaned on attachment delete and uninstall.
* Admin notice when the server cannot encode the selected modern format for intermediate sizes.

= 1.1.1 =
* Rename Tools menu/page to Image Converter.

= 1.1.0 =
* Tools → Image Converter: batch-convert the Media Library to AVIF, WebP, PNG, or JPG with a progress bar and failure summary.
* Uses Menagerie quality, max dimensions, and “Convert when useful”; skips GIF/SVG; respects host encoder support.

= 1.0.4 =
* WordPress 7.1 compatibility: disable core client-side media processing while Menagerie handles uploads to avoid double compression.
* Fix nonce verification so valid half-life tickets are accepted for upload meta and notice dismiss.
* Accessibility: settings checkbox labels use the control title; toast region label is localized; dismiss glyph is hidden from assistive tech; reduced-motion skips toast auto-dismiss.
* Harden upload meta (non-negative byte sizes, image MIME only); clean attachment/user meta on uninstall.
* Server-side fallback skips the metadata “update” pass used by deferred/finalize flows.
* Tested up to WordPress 7.1.

= 1.0.3 =
* Internal version alignment (header/constant); no user-facing changes beyond 1.0.2.

= 1.0.2 =
* Fix MIME type wasm error

= 1.0.1 =
* Readme updates

= 1.0.0 =
* Initial release.
* Skip a second browser encode at upload time when the file was already optimized (e.g. front-end file picker + XHR), improving parity with admin uploads and reducing duplicate work.
* Idle-time encoder prewarm (native probes; WASM AVIF touch when Advanced encoders are on).

== Upgrade Notice ==

= 1.2.0 =
Adds JPEG companion files, picture fallback, and Open Graph JPEG support. Disable “JPEG companion” in Settings for previous upload-only behavior.

= 1.1.1 =
Tools menu renamed to Image Converter.

= 1.1.0 =
Adds Tools → Image Converter for batch Media Library format conversion.

= 1.0.4 =
WordPress 7.1 compatibility, security and accessibility fixes. Recommended update.

= 1.0.2 =
MIME type console error fixed.

= 1.0.0 =
Initial release.
