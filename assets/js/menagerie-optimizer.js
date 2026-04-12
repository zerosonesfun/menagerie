/**
 * Menagerie — client-side image optimization before upload.
 *
 * @package Menagerie
 */
(function () {
	'use strict';

	/** @type {MenagerieConfigShape} */
	var C = window.MenagerieConfig || {};
	var NS = window.Menagerie || {};

	var OPT_TIMEOUT_MS = 60000;
	var SMALL_FILE_BYTES = 120 * 1024;

	/**
	 * @typedef {Object} MenagerieConfigShape
	 * @property {string} [context]
	 * @property {boolean} [enabled]
	 * @property {string} [formatMode]
	 * @property {number} [maxWidth]
	 * @property {number} [maxHeight]
	 * @property {number} [quality]
	 * @property {boolean} [convertWhenUseful]
	 * @property {boolean} [showToasts]
	 * @property {boolean} [preserveTransparency]
	 * @property {boolean} [processAdmin]
	 * @property {boolean} [processFrontend]
	 * @property {boolean} [serverSideOnly]
	 * @property {string} [uploadNonce]
	 * @property {Object} [strings]
	 */

	function str(key, fallback) {
		if (C.strings && C.strings[key]) {
			return C.strings[key];
		}
		return fallback;
	}

	function contextAllowed() {
		if (!C.enabled) {
			return false;
		}
		if (C.serverSideOnly) {
			return false;
		}
		if (C.context === 'admin' && !C.processAdmin) {
			return false;
		}
		if (C.context === 'front' && !C.processFrontend) {
			return false;
		}
		return true;
	}

	function isImageFile(file) {
		if (!file || !file.type) {
			return false;
		}
		return file.type.indexOf('image/') === 0 && file.type !== 'image/svg+xml';
	}

	/**
	 * Raster types we may re-encode. GIF is excluded so animated GIFs are not flattened to one frame.
	 *
	 * @param {File} file
	 * @returns {boolean}
	 */
	function shouldOptimizeRaster(file) {
		if (!isImageFile(file)) {
			return false;
		}
		if (file.type === 'image/gif') {
			return false;
		}
		return true;
	}

	function shouldProcessSequentially() {
		if (typeof navigator !== 'undefined' && navigator.deviceMemory && navigator.deviceMemory <= 4) {
			return true;
		}
		if (typeof navigator !== 'undefined' && navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2) {
			return true;
		}
		if (typeof window !== 'undefined' && window.matchMedia) {
			try {
				if (window.matchMedia('(max-width: 768px)').matches) {
					return true;
				}
			} catch (e) {
				/* ignore */
			}
		}
		return false;
	}

	var sequential = shouldProcessSequentially();
	var chain = Promise.resolve();

	function enqueueTask(fn) {
		if (sequential) {
			chain = chain.then(function () {
				return fn();
			});
			return chain;
		}
		return Promise.resolve().then(fn);
	}

	// --- Toast -----------------------------------------------------------------

	function ToastController() {
		this.host = null;
	}

	ToastController.prototype.ensureHost = function () {
		if (this.host && this.host.parentNode) {
			return this.host;
		}
		var el = document.createElement('div');
		el.className = 'menagerie-toast-host';
		el.setAttribute('aria-label', 'Notifications');
		/* Top layer (above <dialog>, popovers, z-index): Chrome 114+, Safari 17+, Firefox 125+ */
		if (typeof el.showPopover === 'function') {
			el.setAttribute('popover', 'manual');
		}
		document.body.appendChild(el);
		/* Inline !important beats late-loaded theme CSS (e.g. “back to top” with z-index + !important). */
		el.style.setProperty('position', 'fixed', 'important');
		el.style.setProperty('z-index', '2147483647', 'important');
		if (typeof el.showPopover === 'function') {
			try {
				el.showPopover();
			} catch (e) {
				el.removeAttribute('popover');
			}
		}
		this.host = el;
		return el;
	};

	ToastController.prototype.show = function (message, variant) {
		if (!C.showToasts) {
			return function () {};
		}
		var host = this.ensureHost();
		var toast = document.createElement('div');
		toast.className = 'menagerie-toast menagerie-toast--' + (variant || 'info');
		toast.setAttribute('role', 'status');
		toast.setAttribute('aria-live', 'polite');

		var statusDot = document.createElement('span');
		statusDot.className = 'menagerie-toast__status';
		statusDot.setAttribute('aria-hidden', 'true');

		var p = document.createElement('p');
		p.className = 'menagerie-toast__text';
		p.appendChild(statusDot);
		p.appendChild(document.createTextNode(message));

		var dismiss = document.createElement('button');
		dismiss.type = 'button';
		dismiss.className = 'menagerie-toast__dismiss';
		dismiss.setAttribute('aria-label', str('dismiss', 'Dismiss'));
		dismiss.appendChild(document.createTextNode('\u00D7'));

		toast.appendChild(p);
		toast.appendChild(dismiss);
		host.appendChild(toast);

		var tid = null;
		function remove() {
			if (tid) {
				clearTimeout(tid);
			}
			if (toast.parentNode) {
				toast.parentNode.removeChild(toast);
			}
		}
		dismiss.addEventListener('click', remove);
		tid = setTimeout(remove, 8000);
		return remove;
	};

	var toasts = new ToastController();

	// --- Bitmap / canvas -------------------------------------------------------

	var avifCache = null;
	var webpCache = null;

	function supportsCreateImageBitmapOrientation() {
		return typeof createImageBitmap === 'function';
	}

	function detectAvifEncode() {
		if (avifCache !== null) {
			return avifCache;
		}
		avifCache = new Promise(function (resolve) {
			if (typeof document === 'undefined' || !document.createElement) {
				resolve(false);
				return;
			}
			var c = document.createElement('canvas');
			c.width = 4;
			c.height = 4;
			if (!c.toBlob) {
				resolve(false);
				return;
			}
			c.toBlob(
				function (blob) {
					resolve(!!(blob && blob.type === 'image/avif'));
				},
				'image/avif',
				0.5
			);
		});
		return avifCache;
	}

	function detectWebpEncode() {
		if (webpCache !== null) {
			return webpCache;
		}
		webpCache = new Promise(function (resolve) {
			if (typeof document === 'undefined' || !document.createElement) {
				resolve(false);
				return;
			}
			var c = document.createElement('canvas');
			c.width = 4;
			c.height = 4;
			if (!c.toBlob) {
				resolve(false);
				return;
			}
			c.toBlob(
				function (blob) {
					resolve(!!(blob && blob.type === 'image/webp'));
				},
				'image/webp',
				0.8
			);
		});
		return webpCache;
	}

	function withTimeout(promise, ms) {
		return new Promise(function (resolve, reject) {
			var t = setTimeout(function () {
				reject(new Error('timeout'));
			}, ms);
			promise.then(
				function (v) {
					clearTimeout(t);
					resolve(v);
				},
				function (e) {
					clearTimeout(t);
					reject(e);
				}
			);
		});
	}

	/**
	 * @param {File} file
	 * @returns {Promise<ImageBitmap|HTMLImageElement>}
	 */
	function decodeToBitmap(file) {
		if (typeof createImageBitmap === 'function') {
			var opts = supportsCreateImageBitmapOrientation() ? { imageOrientation: 'from-image' } : undefined;
			if (opts) {
				return createImageBitmap(file, opts).catch(function () {
					return createImageBitmap(file);
				});
			}
			return createImageBitmap(file);
		}
		return new Promise(function (resolve, reject) {
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				URL.revokeObjectURL(url);
				resolve(img);
			};
			img.onerror = function () {
				URL.revokeObjectURL(url);
				reject(new Error('decode'));
			};
			img.src = url;
		});
	}

	function applyHighQualityScaling(ctx) {
		if (!ctx) {
			return;
		}
		try {
			ctx.imageSmoothingEnabled = true;
			if ('imageSmoothingQuality' in ctx) {
				ctx.imageSmoothingQuality = 'high';
			}
		} catch (e) {
			/* ignore */
		}
	}

	function bitmapToCanvas(bitmap) {
		var w = bitmap.width;
		var h = bitmap.height;
		var canvas = document.createElement('canvas');
		canvas.width = w;
		canvas.height = h;
		var ctx = canvas.getContext('2d');
		if (!ctx) {
			throw new Error('2d');
		}
		applyHighQualityScaling(ctx);
		ctx.drawImage(bitmap, 0, 0);
		try {
			if (typeof bitmap.close === 'function') {
				bitmap.close();
			}
		} catch (e) {
			/* ignore */
		}
		return { canvas: canvas, ctx: ctx, width: w, height: h };
	}

	function sampleHasAlpha(ctx, w, h) {
		if (w < 2 || h < 2) {
			return false;
		}
		var corners = [
			[0, 0],
			[w - 1, 0],
			[0, h - 1],
			[w - 1, h - 1],
		];
		for (var i = 0; i < corners.length; i++) {
			var d = ctx.getImageData(corners[i][0], corners[i][1], 1, 1).data;
			if (d[3] < 255) {
				return true;
			}
		}
		return false;
	}

	function canvasToBlob(canvas, type, quality) {
		return new Promise(function (resolve, reject) {
			if (!canvas.toBlob) {
				reject(new Error('toBlob'));
				return;
			}
			canvas.toBlob(
				function (blob) {
					if (!blob) {
						reject(new Error('blob'));
					} else {
						resolve(blob);
					}
				},
				type,
				quality
			);
		});
	}

	/** No-op without Vite dist build (WASM encoders unavailable). */
	function tryWasmEncode(canvasEl, mime) {
		void canvasEl;
		void mime;
		return Promise.resolve(null);
	}

	/**
	 * Native canvas AVIF — quality ladder then type-only (dist build uses WASM + this).
	 *
	 * @param {HTMLCanvasElement} canvasEl
	 * @param {number} q 0–1
	 * @returns {Promise<{blob: Blob, mime: string}|null>}
	 */
	function canvasToBlobAvifNative(canvasEl, q) {
		return detectAvifEncode().then(function (ok) {
			if (!ok) {
				return null;
			}
			var qn = typeof q === 'number' && !isNaN(q) ? Math.min(1, Math.max(0.1, q)) : 0.85;
			return canvasToBlob(canvasEl, 'image/avif', qn).then(
				function (blob) {
					return { blob: blob, mime: blob.type || 'image/avif' };
				},
				function () {
					return canvasToBlob(canvasEl, 'image/avif', 0.5).then(
						function (blob) {
							return { blob: blob, mime: blob.type || 'image/avif' };
						},
						function () {
							return canvasToBlob(canvasEl, 'image/avif').then(
								function (blob) {
									return { blob: blob, mime: blob.type || 'image/avif' };
								},
								function () {
									return null;
								}
							);
						}
					);
				}
			);
		});
	}

	/**
	 * Native canvas WebP — quality ladder (dist build also uses WASM retries + this).
	 *
	 * @param {HTMLCanvasElement} canvasEl
	 * @param {number} q 0–1
	 * @returns {Promise<{blob: Blob, mime: string}|null>}
	 */
	function canvasToBlobWebpNative(canvasEl, q) {
		return detectWebpEncode().then(function (ok) {
			if (!ok) {
				return null;
			}
			var qn = typeof q === 'number' && !isNaN(q) ? Math.min(1, Math.max(0.1, q)) : 0.85;
			return canvasToBlob(canvasEl, 'image/webp', qn).then(
				function (blob) {
					return { blob: blob, mime: blob.type || 'image/webp' };
				},
				function () {
					return canvasToBlob(canvasEl, 'image/webp', 0.5).then(
						function (blob) {
							return { blob: blob, mime: blob.type || 'image/webp' };
						},
						function () {
							return canvasToBlob(canvasEl, 'image/webp').then(
								function (blob) {
									return { blob: blob, mime: blob.type || 'image/webp' };
								},
								function () {
									return null;
								}
							);
						}
					);
				}
			);
		});
	}

	/**
	 * @param {string} mode
	 * @param {boolean} hasAlpha
	 * @param {boolean} preserve
	 * @returns {{ types: string[], flatten: boolean }}
	 */
	function pickOutputTypes(mode, hasAlpha, preserve) {
		var types = [];
		var flatten = false;
		if (mode === 'jpeg') {
			types.push('image/jpeg');
			flatten = hasAlpha && !preserve;
			return { types: types, flatten: flatten };
		}
		if (mode === 'webp') {
			types.push('image/webp');
			return { types: types, flatten: false };
		}
		// auto
		if (hasAlpha && preserve) {
			types.push('image/avif');
			types.push('image/webp');
			types.push('image/png');
			return { types: types, flatten: false };
		}
		if (hasAlpha && !preserve) {
			types.push('image/avif');
			types.push('image/webp');
			types.push('image/jpeg');
			flatten = true;
			return { types: types, flatten: flatten };
		}
		types.push('image/avif');
		types.push('image/webp');
		types.push('image/jpeg');
		return { types: types, flatten: false };
	}

	/**
	 * @param {HTMLCanvasElement} canvasEl
	 * @param {string[]} types
	 * @param {number} q
	 * @returns {Promise<{blob: Blob, mime: string}>}
	 */
	function encodeFirstWorking(canvasEl, types, q) {
		var i = 0;
		function next() {
			if (i >= types.length) {
				return Promise.reject(new Error('encode'));
			}
			var mime = types[i++];
			if (mime === 'image/png') {
				return canvasToBlob(canvasEl, 'image/png').then(function (blob) {
					return { blob: blob, mime: blob.type || 'image/png' };
				});
			}
			if (mime === 'image/avif') {
				return tryWasmEncode(canvasEl, 'image/avif').then(function (wasm) {
					if (wasm) {
						return wasm;
					}
					return canvasToBlobAvifNative(canvasEl, q).then(function (native) {
						if (native) {
							return native;
						}
						return next();
					});
				});
			}
			if (mime === 'image/webp') {
				return tryWasmEncode(canvasEl, 'image/webp').then(function (wasm) {
					if (wasm) {
						return wasm;
					}
					return canvasToBlobWebpNative(canvasEl, q).then(function (native) {
						if (native) {
							return native;
						}
						return next();
					});
				});
			}
			if (mime === 'image/jpeg') {
				return tryWasmEncode(canvasEl, 'image/jpeg').then(function (wasm) {
					if (wasm) {
						return wasm;
					}
					return canvasToBlob(canvasEl, mime, q).then(function (blob) {
						return { blob: blob, mime: blob.type || mime };
					});
				});
			}
			return canvasToBlob(canvasEl, mime, q).then(function (blob) {
				return { blob: blob, mime: blob.type || mime };
			});
		}
		return next();
	}

	function flattenCanvas(source) {
		var flat = document.createElement('canvas');
		flat.width = source.width;
		flat.height = source.height;
		var f = flat.getContext('2d');
		if (!f) {
			return source;
		}
		applyHighQualityScaling(f);
		f.fillStyle = '#ffffff';
		f.fillRect(0, 0, flat.width, flat.height);
		f.drawImage(source, 0, 0);
		return flat;
	}

	/**
	 * @param {File} file
	 * @returns {Promise<{blob: Blob, mime: string, skipped: boolean}>}
	 */
	function optimizeImageFile(file) {
		if (!shouldOptimizeRaster(file)) {
			return Promise.resolve({ blob: file, mime: file.type || 'application/octet-stream', skipped: true });
		}
		return Promise.resolve()
			.then(function () {
				return decodeToBitmap(file);
			})
			.then(function (bitmap) {
				var b = bitmapToCanvas(bitmap);
				var maxW = C.maxWidth || 2560;
				var maxH = C.maxHeight || 2560;
				var w = b.width;
				var h = b.height;
				var scale = Math.min(maxW / w, maxH / h, 1);
				var nw = Math.max(1, Math.round(w * scale));
				var nh = Math.max(1, Math.round(h * scale));

				var out = document.createElement('canvas');
				out.width = nw;
				out.height = nh;
				var octx = out.getContext('2d');
				if (!octx) {
					throw new Error('2d');
				}
				applyHighQualityScaling(octx);
				octx.drawImage(b.canvas, 0, 0, w, h, 0, 0, nw, nh);

				try {
					b.canvas.width = 0;
					b.canvas.height = 0;
				} catch (e2) {
					/* ignore */
				}

				var hasAlpha = sampleHasAlpha(octx, nw, nh);
				var preserve = !!C.preserveTransparency;
				var mode = C.formatMode || 'auto';
				var q = (C.quality || 85) / 100;

				if (C.convertWhenUseful && file.size < SMALL_FILE_BYTES && scale >= 1) {
					return Promise.resolve({ blob: file, mime: file.type || 'application/octet-stream', skipped: true });
				}

				var plan = pickOutputTypes(mode, hasAlpha, preserve);
				var canvasEl = out;
				if (plan.flatten) {
					canvasEl = flattenCanvas(out);
				}

				return encodeFirstWorking(canvasEl, plan.types, q).then(function (res) {
					try {
						if (canvasEl !== out) {
							canvasEl.width = 0;
							canvasEl.height = 0;
						}
						out.width = 0;
						out.height = 0;
					} catch (e3) {
						/* ignore */
					}
					return { blob: res.blob, mime: res.mime, skipped: false };
				});
			});
	}

	/**
	 * @param {File} original
	 * @param {Blob} blob
	 * @param {string} mime
	 * @returns {File}
	 */
	function fileFromBlob(original, blob, mime) {
		try {
			return new File([blob], original.name, {
				type: mime || blob.type || original.type,
				lastModified: Date.now(),
			});
		} catch (e) {
			var fallback = new Blob([blob], { type: mime || original.type });
			/** @type {*} */ (fallback).name = original.name;
			return /** @type {File} */ (fallback);
		}
	}

	function buildMetaPayload(originalSize, optimizedSize, mimeOut, processed) {
		return JSON.stringify({
			processed: processed,
			originalBytes: originalSize,
			optimizedBytes: optimizedSize,
			mimeOut: mimeOut,
			nonce: C.uploadNonce || '',
		});
	}

	/**
	 * @param {File} file
	 * @returns {Promise<File>}
	 */
	function runOptimize(file) {
		var origSize = file.size;
		var dismissBusy = C.showToasts ? toasts.show(str('optimizing', 'Optimizing your image…'), 'info') : function () {};

		return withTimeout(optimizeImageFile(file), OPT_TIMEOUT_MS)
			.then(function (res) {
				dismissBusy();
				if (res.skipped) {
					return file;
				}
				var outFile = fileFromBlob(file, res.blob, res.mime);
				if (C.showToasts) {
					toasts.show(str('optimized', 'Image optimized, you may submit.'), 'success');
				}
				/** @type {*} */ (outFile).menagerieMeta = buildMetaPayload(origSize, outFile.size, res.mime, true);
				return outFile;
			})
			.catch(function () {
				dismissBusy();
				if (C.showToasts) {
					toasts.show(str('fallback', 'Optimization skipped; your original image will upload.'), 'subtle');
				}
				return file;
			});
	}

	/**
	 * @param {HTMLInputElement} input
	 * @param {File[]} files
	 */
	function setInputFiles(input, files) {
		try {
			var dt = new DataTransfer();
			for (var i = 0; i < files.length; i++) {
				dt.items.add(files[i]);
			}
			input.files = dt.files;
		} catch (e) {
			/* DataTransfer unsupported */
		}
	}

	/**
	 * @param {File[]} files
	 * @param {number} index
	 * @returns {Promise<File[]>}
	 */
	function optimizeFilesList(files, index) {
		if (index >= files.length) {
			return Promise.resolve(files);
		}
		var f = files[index];
		if (!shouldOptimizeRaster(f)) {
			return optimizeFilesList(files, index + 1);
		}
		return enqueueTask(function () {
			return runOptimize(f).then(function (out) {
				var next = files.slice();
				next[index] = out;
				return optimizeFilesList(next, index + 1);
			});
		});
	}

	function handleFileInputChange(ev) {
		if (!contextAllowed()) {
			return;
		}
		var input = ev.target;
		if (!input || input.tagName !== 'INPUT' || input.type !== 'file') {
			return;
		}
		if (input.getAttribute('data-menagerie-ignore') === '1') {
			return;
		}
		if (input._menagerieInternal) {
			return;
		}
		if (!input.files || input.files.length === 0) {
			return;
		}
		var list = Array.prototype.slice.call(input.files);
		if (!list.some(shouldOptimizeRaster)) {
			return;
		}

		if (typeof ev.stopImmediatePropagation === 'function') {
			ev.stopImmediatePropagation();
		}
		ev.preventDefault();

		optimizeFilesList(list, 0).then(function (newList) {
			input._menagerieInternal = true;
			setInputFiles(input, newList);
			var ev2;
			try {
				ev2 = new Event('change', { bubbles: true });
			} catch (e) {
				ev2 = document.createEvent('Event');
				ev2.initEvent('change', true, true);
			}
			input.dispatchEvent(ev2);
			input._menagerieInternal = false;
		});
	}

	function dropTargetsOptimizable(dt) {
		if (!dt || !dt.files || !dt.files.length) {
			return false;
		}
		for (var i = 0; i < dt.files.length; i++) {
			if (shouldOptimizeRaster(dt.files[i])) {
				return true;
			}
		}
		return false;
	}

	function findFileInputNear(el) {
		if (!el) {
			return null;
		}
		var form = el.closest && el.closest('form');
		if (form) {
			var inp = form.querySelector('input[type=file]');
			if (inp) {
				return inp;
			}
		}
		return null;
	}

	function handleDrop(ev) {
		if (!contextAllowed()) {
			return;
		}
		var dt = ev.dataTransfer;
		if (!dropTargetsOptimizable(dt)) {
			return;
		}
		var target = ev.target;
		var input = target && target.tagName === 'INPUT' && target.type === 'file' ? target : findFileInputNear(target);
		if (!input) {
			return;
		}

		ev.preventDefault();
		ev.stopPropagation();

		var list = Array.prototype.slice.call(dt.files);
		optimizeFilesList(list, 0).then(function (newList) {
			input._menagerieInternal = true;
			setInputFiles(input, newList);
			var ev2;
			try {
				ev2 = new Event('change', { bubbles: true });
			} catch (e) {
				ev2 = document.createEvent('Event');
				ev2.initEvent('change', true, true);
			}
			input.dispatchEvent(ev2);
			input._menagerieInternal = false;
		});
	}

	// --- FormData / XHR / fetch -------------------------------------------------

	/**
	 * URLs that may carry image uploads. Optimization still runs only when the body is FormData
	 * and contains at least one raster file (see patchXHR / patchFetch hasFile checks).
	 */
	function isUploadUrl(url) {
		if (!url || typeof url !== 'string') {
			return false;
		}
		return (
			url.indexOf('async-upload.php') !== -1 ||
			url.indexOf('/wp-json/wp/v2/media') !== -1 ||
			url.indexOf('admin-ajax.php') !== -1
		);
	}

	/**
	 * @param {FormData} fd
	 * @returns {Promise<FormData>}
	 */
	function processFormData(fd) {
		var pairs = [];
		fd.forEach(function (value, key) {
			pairs.push({ key: key, value: value });
		});

		function processIndex(idx) {
			if (idx >= pairs.length) {
				var out = new FormData();
				var meta = null;
				for (var j = 0; j < pairs.length; j++) {
					var p = pairs[j];
					if (p.value instanceof File) {
						out.append(p.key, p.value, p.value.name);
						var m = /** @type {*} */ (p.value).menagerieMeta;
						if (typeof m === 'string') {
							meta = m;
						}
					} else {
						out.append(p.key, p.value);
					}
				}
				if (meta) {
					out.set('menagerie_meta', meta);
				}
				return Promise.resolve(out);
			}

			var cur = pairs[idx];
			if (!(cur.value instanceof File) || !shouldOptimizeRaster(cur.value)) {
				return processIndex(idx + 1);
			}

			/* Front-end file input already optimized; avoid a second encode at send time (admin parity). */
			var existingMeta = /** @type {*} */ (cur.value).menagerieMeta;
			if (typeof existingMeta === 'string' && existingMeta.length > 0) {
				return processIndex(idx + 1);
			}

			return enqueueTask(function () {
				return runOptimize(cur.value).then(function (outFile) {
					pairs[idx].value = outFile;
					return processIndex(idx + 1);
				});
			});
		}

		return processIndex(0);
	}

	var xhrPatched = false;

	function patchXHR() {
		if (xhrPatched || typeof XMLHttpRequest === 'undefined') {
			return;
		}
		xhrPatched = true;
		var origOpen = XMLHttpRequest.prototype.open;
		var origSend = XMLHttpRequest.prototype.send;

		XMLHttpRequest.prototype.open = function (method, url) {
			this._menagerieUrl = typeof url === 'string' ? url : '';
			return origOpen.apply(this, arguments);
		};

		XMLHttpRequest.prototype.send = function (body) {
			var xhr = this;
			var url = xhr._menagerieUrl || '';
			if (!contextAllowed() || !isUploadUrl(url) || !(body instanceof FormData)) {
				return origSend.call(this, body);
			}

			var hasFile = false;
			body.forEach(function (v) {
				if (v instanceof File && shouldOptimizeRaster(v)) {
					hasFile = true;
				}
			});
			if (!hasFile) {
				return origSend.call(this, body);
			}

			// runOptimize() shows the optimizing toast; avoid duplicating it here.

			processFormData(body)
				.then(function (newFd) {
					origSend.call(xhr, newFd);
				})
				.catch(function () {
					origSend.call(xhr, body);
				});
		};
	}

	var fetchPatched = false;

	function patchFetch() {
		if (fetchPatched || typeof window.fetch !== 'function') {
			return;
		}
		fetchPatched = true;
		var origFetch = window.fetch;

		/**
		 * @param {typeof fetch} origFetch0
		 * @param {*} input
		 * @param {RequestInit|undefined} init
		 * @param {FormData} formData
		 * @returns {Promise<Response>}
		 */
		function fetchWithOptimizedFormData(origFetch0, input, init, formData) {
			var hasFile = false;
			formData.forEach(function (v) {
				if (v instanceof File && shouldOptimizeRaster(v)) {
					hasFile = true;
				}
			});
			if (!hasFile) {
				return origFetch0.call(window, input, init);
			}

			return processFormData(formData)
				.then(function (newFd) {
					var nextInit = Object.assign({}, init || {}, { body: newFd });
					return origFetch0.call(window, input, nextInit);
				})
				.catch(function () {
					return origFetch0.call(window, input, init);
				});
		}

		window.fetch = function (input, init) {
			var url = '';
			if (typeof input === 'string') {
				url = input;
			} else if (input && typeof input === 'object' && typeof input.url === 'string') {
				url = input.url;
			}

			var args = arguments;

			if (!contextAllowed() || !isUploadUrl(url)) {
				return origFetch.apply(window, args);
			}

			/* init.body wins over Request body (fetch / Request semantics). */
			if (init && init.body instanceof FormData) {
				return fetchWithOptimizedFormData(origFetch, input, init, init.body);
			}

			/* fetch(Request) with FormData only on the Request — no init.body to read synchronously. */
			if (input instanceof Request && (!init || init.body === undefined)) {
				return input
					.clone()
					.formData()
					.then(function (fd) {
						return fetchWithOptimizedFormData(origFetch, input, init, fd);
					})
					.catch(function () {
						return origFetch.apply(window, args);
					});
			}

			return origFetch.apply(window, args);
		};
	}

	function bindFileInputs() {
		if (C.context === 'admin') {
			return;
		}
		document.addEventListener(
			'change',
			function (e) {
				handleFileInputChange(e);
			},
			true
		);

		document.addEventListener(
			'drop',
			function (e) {
				handleDrop(e);
			},
			true
		);
	}

	/**
	 * Warm native encode probes. Dist build also warms the WASM worker via scheduleEncoderPrewarm in src/app.js.
	 */
	function scheduleEncoderPrewarm() {
		if (typeof document === 'undefined' || !document.createElement) {
			return;
		}
		function run() {
			detectAvifEncode();
			detectWebpEncode();
		}
		setTimeout(run, 0);
		if (typeof requestIdleCallback === 'function') {
			requestIdleCallback(
				function () {
					run();
				},
				{ timeout: 8000 }
			);
		} else {
			setTimeout(run, 1);
		}
	}

	function initPluploadFallback() {
		if (!window.wp || !window.wp.Uploader || !window.wp.Uploader.prototype) {
			return;
		}
		var Proto = window.wp.Uploader.prototype;
		if (Proto.menageriePatched) {
			return;
		}
		Proto.menageriePatched = true;
		var origInit = Proto.init;
		Proto.init = function () {
			var ret = origInit.apply(this, arguments);
			try {
				if (this.uploader && this.uploader.bind) {
					this.uploader.bind('BeforeUpload', function (up) {
						if (up.settings && up.settings.multipart_params) {
							up.settings.multipart_params.menagerie_meta = up.settings.multipart_params.menagerie_meta || '';
						}
					});
				}
			} catch (e) {
				/* ignore */
			}
			return ret;
		};
	}

	function init() {
		if (!contextAllowed()) {
			return;
		}
		patchXHR();
		patchFetch();
		bindFileInputs();
		if (typeof window !== 'undefined' && window.document && document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initPluploadFallback);
		} else {
			initPluploadFallback();
		}
		scheduleEncoderPrewarm();
	}

	NS.config = C;
	NS.init = init;
	window.Menagerie = Object.freeze(NS);

	init();
})();
