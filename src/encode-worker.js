/**
 * Web Worker: WASM encode via jSquash — codecs loaded on demand.
 *
 * @package Menagerie
 */
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
			ab = await encode(imageData, {
				quality: q,
				alpha_quality: q,
			});
		} else if (format === 'image/jpeg') {
			const { encode } = await import('@jsquash/jpeg');
			ab = await encode(imageData, {
				quality: q,
			});
		} else if (format === 'image/avif') {
			const { encode } = await import('@jsquash/avif');
			ab = await encode(imageData, {
				quality: q,
				qualityAlpha: q,
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
