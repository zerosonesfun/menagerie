/**
 * Web Worker: WASM encode via jSquash — codecs loaded on demand.
 *
 * @package Menagerie
 */
if (
	typeof WebAssembly !== 'undefined' &&
	typeof WebAssembly.instantiateStreaming === 'function'
) {
	const nativeInstantiateStreaming = WebAssembly.instantiateStreaming.bind(WebAssembly);
	WebAssembly.instantiateStreaming = async function (source, importObject) {
		const response = await source;
		if (typeof Response !== 'undefined' && response instanceof Response) {
			const contentType = String(response.headers.get('content-type') || '');
			if (!contentType.toLowerCase().includes('application/wasm')) {
				const bytes = await response.arrayBuffer();
				return WebAssembly.instantiate(bytes, importObject);
			}
		}
		return nativeInstantiateStreaming(Promise.resolve(response), importObject);
	};
}

self.onmessage = async function (e) {
	const msg = e.data;
	if (!msg || msg.type !== 'menagerie-encode') {
		return;
	}
	const { format, width, height, buffer, quality } = msg;
	const rgba = new Uint8ClampedArray(buffer);
	const imageData = new ImageData(rgba, width, height);
	const q = typeof quality === 'number' ? Math.min(100, Math.max(1, quality)) : 85;

	try {
		let ab;
		if (format === 'image/webp') {
			const { encode } = await import('@jsquash/webp');
			/* method 2 / single pass: faster encode vs defaults, less likely to lose race before next fallback */
			ab = await encode(imageData, {
				quality: q,
				alpha_quality: q,
				method: 2,
				pass: 1,
			});
		} else if (format === 'image/jpeg') {
			const { encode } = await import('@jsquash/jpeg');
			ab = await encode(imageData, {
				quality: q,
			});
		} else if (format === 'image/avif') {
			const { encode } = await import('@jsquash/avif');
			/* speed 10 = fastest libaom pass: shorter main-thread time, less likely to lose race before WebP fallback */
			ab = await encode(imageData, {
				quality: q,
				qualityAlpha: q,
				speed: 10,
			});
		} else {
			self.postMessage({ ok: false, error: 'unsupported-format' });
			return;
		}
		if (!ab || !(ab instanceof ArrayBuffer)) {
			self.postMessage({ ok: false, error: 'encode-empty' });
			return;
		}
		self.postMessage({ ok: true, buffer: ab }, [ab]);
	} catch (err) {
		self.postMessage({
			ok: false,
			error: err && err.message ? String(err.message) : String(err),
		});
	}
};
