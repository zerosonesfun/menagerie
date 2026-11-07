=== Menagerie ===
Contributors: billywilcosky
Tags: images, jpeg, webp, avif, media, companion, fallback
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optional WebP/AVIF upload conversion, JPEG companions, picture/OG fallback, and bulk library conversion for WordPress 7.1+.

== Description ==

Menagerie by Billy Wilcosky (https://wilcosky.com) is the compatibility layer around WordPress 7.1+ client-side media processing:

* Optional upload output: keep as uploaded, or convert JPEG/PNG to WebP or AVIF via WordPress’s native format filter (CSMP and server uploads)
* If AVIF is selected but does not land, Menagerie may rescue to WebP on the server when the host supports it
* Menagerie adds JPEG companions for older browsers and social previews
* Editable admin and front-end upload toasts so you can see what format uploaded
* Automatic `<picture>` fallback (modern srcset + JPEG fallback srcset)
* Open Graph prefers the full JPEG companion when the SEO image maps to that attachment
* Tools → Image Converter for bulk Media Library format conversion

No outside encode services - everything stays on your site.

== Installation ==

1. Upload the plugin and activate it (WordPress 7.1+)
2. Go to **Settings → Menagerie** and choose upload format, companion, and converter options
3. Upload images as usual - WordPress processes them; Menagerie adds JPEG companions when needed

To convert images already in your Media Library, use **Tools → Image Converter**.

== Frequently Asked Questions ==

= Does Menagerie still optimize uploads in the browser? =

No. WordPress 7.1+ owns upload processing. Use **Upload output format** to ask WordPress to convert JPEG/PNG to WebP or AVIF. Menagerie also provides JPEG companions, picture/OG fallback, and the Image Converter.

= How do I convert new uploads to WebP or AVIF? =

**Settings → Menagerie → Upload output format.** Choose WebP or AVIF. This uses WordPress’s `image_editor_output_format` filter so client-side media processing and server uploads stay in sync. Leave “Keep as uploaded” for stock WordPress behavior.

= What is the JPEG companion? =

An extra JPEG kept alongside AVIF/WebP so older browsers and social networks can still show the image. It is generated on the server after upload (WordPress does not expose a client-side companion API for format conversion). It lives in uploads and is linked to the attachment - it is not a separate Media Library item. Optionally, sized JPEG companions are generated for intermediate sizes.

= Will this break my uploads? =

No. Menagerie does not intercept the upload pipeline. If companion generation fails, the primary AVIF/WebP attachment still works.

= Are GIFs converted? =

No. GIFs (and SVGs) are left alone by the Image Converter. Upload output format only maps JPEG and PNG.

= How do I convert old Media Library images? =

**Tools → Image Converter**. Pick a format and run the batch. Quality and “Convert when useful” are in Settings → Menagerie.

== Changelog ==

= 2.0.0 =
* Pivot to WordPress 7.1+ media companion: remove client-side upload optimizer/WASM/conflict detector and CSMP disable filter.
* Optional upload output format: keep as uploaded, or convert JPEG/PNG to WebP or AVIF via `image_editor_output_format` (honored by WP 7.1 CSMP and server editors).
* When AVIF is selected but the attachment stays JPEG/PNG, server-side WebP rescue when the host can encode WebP.
* Editable upload toasts (admin + front end) for conversion success, WebP fallback, failure, and companion creation.
* Server-only JPEG companions after WP upload finalize (create and update metadata), with sized companions and picture/OG fallback.
* Slim settings: companion, picture, Open Graph, converter quality / convert when useful.
* Admin notice when the selected modern format cannot be encoded on the server (non-CSMP browsers).
* Requires WordPress 7.1+.

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

= 2.0.0 =
Requires WordPress 7.1+. Optional WebP/AVIF upload conversion via WordPress’s native format filter; JPEG companions, picture/OG fallback, and Image Converter.

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
