# Third-party licenses (advanced encoders)

When **Advanced encoders (WebAssembly)** is enabled, Menagerie loads the [jSquash](https://github.com/jamsinclair/jSquash) packages, which repackage codecs from the [Squoosh](https://github.com/GoogleChromeLabs/squoosh) project.

| Package        | License    | Notes                                      |
|----------------|------------|--------------------------------------------|
| `@jsquash/webp` | Apache-2.0 | libwebp WebAssembly encoder/decoder        |
| `@jsquash/jpeg` | Apache-2.0 | MozJPEG WebAssembly encoder/decoder        |
| `@jsquash/avif` | Apache-2.0 | libavif WebAssembly encoder/decoder        |

Full license texts are in each package under `node_modules/` after `npm install`. The Menagerie plugin source (PHP and hand-written JS) remains under the GPL v2 or later as stated in the plugin header; consult your legal counsel regarding distribution of Apache-2.0 WASM binaries alongside GPLv2 code if you redistribute the built `assets/js/dist/` tree.
