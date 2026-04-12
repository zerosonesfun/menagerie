/**
 * jSquash WASM encoders in a Web Worker (Vite bundles encode-worker.js separately).
 * Encodes are serialized on a single queue so bulk/concurrent uploads cannot interleave worker messages.
 *
 * @package Menagerie
 */
import EncodeWorker from './encode-worker.js?worker';

let workerInstance = null;

/** @type {Promise<unknown>} */
let workerQueue = Promise.resolve();

export function resetWasmWorker() {
	if (workerInstance) {
		try {
			workerInstance.terminate();
		} catch (e) {
			/* ignore */
		}
		workerInstance = null;
	}
	workerQueue = Promise.resolve();
}

function getWorker() {
	if (!workerInstance) {
		workerInstance = new EncodeWorker();
	}
	return workerInstance;
}

/**
 * Run one encode on the worker; internal use.
 *
 * @param {ImageData} imageData
 * @param {string} mime
 * @param {number} qualityPercent
 * @returns {Promise<ArrayBuffer|null>}
 */
function encodeInWorkerOnce(imageData, mime, qualityPercent) {
	const w = getWorker();
	const copy = new Uint8ClampedArray(imageData.data);
	const buf = copy.buffer.slice(0);

	return new Promise(function (resolve) {
		var finished = false;
		function done(ab) {
			if (finished) {
				return;
			}
			finished = true;
			resolve(ab);
		}

		function onMessage(ev) {
			w.removeEventListener('message', onMessage);
			w.removeEventListener('error', onError);
			var d = ev.data;
			if (d && d.ok && d.buffer instanceof ArrayBuffer) {
				done(d.buffer);
			} else {
				done(null);
			}
		}

		function onError() {
			w.removeEventListener('message', onMessage);
			w.removeEventListener('error', onError);
			resetWasmWorker();
			done(null);
		}

		w.addEventListener('message', onMessage);
		w.addEventListener('error', onError);

		try {
			w.postMessage(
				{
					type: 'menagerie-encode',
					format: mime,
					width: imageData.width,
					height: imageData.height,
					buffer: buf,
					quality: qualityPercent,
				},
				[buf]
			);
		} catch (e) {
			w.removeEventListener('message', onMessage);
			w.removeEventListener('error', onError);
			done(null);
		}
	});
}

/**
 * @param {ImageData} imageData
 * @param {string} mime image/webp | image/jpeg | image/avif
 * @param {number} qualityPercent 1–100
 * @returns {Promise<ArrayBuffer|null>}
 */
export function encodeInWorker(imageData, mime, qualityPercent) {
	var job = workerQueue.then(function () {
		return encodeInWorkerOnce(imageData, mime, qualityPercent);
	});
	workerQueue = job.catch(function () {
		return null;
	});
	return job;
}
