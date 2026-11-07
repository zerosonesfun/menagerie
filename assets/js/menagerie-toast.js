(function () {
	'use strict';

	var cfg = window.MenagerieToast;
	if (!cfg || typeof cfg !== 'object') {
		return;
	}

	var shown = Object.create(null);
	var region = null;

	function strings() {
		return cfg.strings || {};
	}

	function messageForOutcome(outcome) {
		var s = strings();
		switch (outcome) {
			case 'converted_avif':
				return s.converted_avif || '';
			case 'fallback_webp':
				return s.fallback_webp || '';
			case 'converted_webp':
				return s.converted_webp || '';
			case 'failed_keep_original':
				return s.failed_keep || '';
			case 'companion_only':
				return s.companion_ok || '';
			default:
				return '';
		}
	}

	function toneForOutcome(outcome) {
		if (outcome === 'failed_keep_original') {
			return 'warn';
		}
		return 'ok';
	}

	function ensureRegion() {
		if (region && region.isConnected) {
			return region;
		}
		region = document.createElement('div');
		region.className = 'menagerie-toast-region';
		region.setAttribute('role', 'status');
		region.setAttribute('aria-live', 'polite');
		document.body.appendChild(region);
		return region;
	}

	function dismiss(el) {
		if (el && el.parentNode) {
			el.parentNode.removeChild(el);
		}
	}

	function showToast(outcome, dedupeKey) {
		var msg = messageForOutcome(outcome);
		if (!msg) {
			return;
		}
		if (dedupeKey) {
			if (shown[dedupeKey]) {
				return;
			}
			shown[dedupeKey] = true;
		}

		var host = ensureRegion();
		var toast = document.createElement('div');
		toast.className = 'menagerie-toast menagerie-toast--' + toneForOutcome(outcome);

		var p = document.createElement('p');
		p.className = 'menagerie-toast__msg';
		p.textContent = msg;
		toast.appendChild(p);

		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'menagerie-toast__dismiss';
		btn.setAttribute('aria-label', strings().dismiss || 'Dismiss');
		var glyph = document.createElement('span');
		glyph.className = 'menagerie-toast__dismiss-glyph';
		glyph.setAttribute('aria-hidden', 'true');
		glyph.textContent = '\u00d7';
		btn.appendChild(glyph);
		btn.addEventListener('click', function () {
			dismiss(toast);
		});
		toast.appendChild(btn);

		host.appendChild(toast);

		var reduceMotion =
			window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		if (!reduceMotion) {
			window.setTimeout(function () {
				dismiss(toast);
			}, 6000);
		}
	}

	function handleMenageriePayload(payload, idHint) {
		if (!payload || typeof payload !== 'object') {
			return;
		}
		// Server sets notify only for outcomes produced in the current request.
		if (!payload.notify) {
			return;
		}
		var outcome = payload.outcome || 'none';
		if (outcome === 'none') {
			return;
		}
		var key = String(idHint || '') + ':' + outcome;
		showToast(outcome, key);
	}

	function extractFromAttachment(att) {
		if (!att) {
			return;
		}
		var data = att.attributes ? att.attributes : att;
		var menagerie = data.menagerie || (att.get && att.get('menagerie'));
		var id = data.id || (att.get && att.get('id')) || '';
		handleMenageriePayload(menagerie, id);
	}

	function bindWpUploader() {
		if (!window.wp || !wp.Uploader || !wp.Uploader.queue) {
			return;
		}
		try {
			wp.Uploader.queue.on('add', function (attachment) {
				if (!attachment || !attachment.on) {
					return;
				}
				// Payload may already be present when the model is added.
				extractFromAttachment(attachment);
				attachment.on('change:menagerie', function () {
					extractFromAttachment(attachment);
				});
				attachment.on('change:url', function () {
					window.setTimeout(function () {
						extractFromAttachment(attachment);
					}, 50);
				});
				attachment.on('change:uploading', function () {
					if (attachment.get && attachment.get('uploading')) {
						return;
					}
					window.setTimeout(function () {
						extractFromAttachment(attachment);
					}, 50);
				});
			});
		} catch (e) {
			/* ignore */
		}
	}

	function pathLooksLikeMedia(url) {
		if (!url || typeof url !== 'string') {
			return false;
		}
		return /\/wp\/v2\/media(\/|\?|&|$)/.test(url) || /\/wp-json\/wp\/v2\/media(\/|\?|&|$)/.test(url);
	}

	function isMutatingMethod(method) {
		var m = String(method || 'GET').toUpperCase();
		return m === 'POST' || m === 'PUT' || m === 'PATCH';
	}

	function inspectJsonBody(body) {
		if (!body || typeof body !== 'object') {
			return;
		}
		// Never toast from collection/list responses.
		if (Array.isArray(body)) {
			return;
		}
		if (body.menagerie) {
			handleMenageriePayload(body.menagerie, body.id);
		}
	}

	function bindFetch() {
		if (typeof window.fetch !== 'function') {
			return;
		}
		var orig = window.fetch;
		window.fetch = function () {
			var args = arguments;
			var input = args[0];
			var init = args[1] || {};
			var url =
				typeof input === 'string'
					? input
					: input && input.url
						? String(input.url)
						: '';
			var method =
				(init && init.method) ||
				(input && input.method) ||
				'GET';
			return orig.apply(this, args).then(function (response) {
				if (
					!response ||
					!response.ok ||
					!pathLooksLikeMedia(url) ||
					!isMutatingMethod(method)
				) {
					return response;
				}
				try {
					var clone = response.clone();
					clone
						.json()
						.then(function (body) {
							inspectJsonBody(body);
						})
						.catch(function () {
							/* ignore non-json */
						});
				} catch (e) {
					/* ignore */
				}
				return response;
			});
		};
	}

	function bindXHR() {
		if (!window.XMLHttpRequest) {
			return;
		}
		var open = XMLHttpRequest.prototype.open;
		var send = XMLHttpRequest.prototype.send;
		XMLHttpRequest.prototype.open = function (method, url) {
			this.__menagerieMethod = typeof method === 'string' ? method : 'GET';
			this.__menagerieUrl = typeof url === 'string' ? url : '';
			return open.apply(this, arguments);
		};
		XMLHttpRequest.prototype.send = function () {
			this.addEventListener(
				'load',
				function () {
					if (this.status < 200 || this.status >= 300) {
						return;
					}
					if (!pathLooksLikeMedia(this.__menagerieUrl || '')) {
						return;
					}
					if (!isMutatingMethod(this.__menagerieMethod)) {
						return;
					}
					try {
						var body = JSON.parse(this.responseText);
						inspectJsonBody(body);
					} catch (e) {
						/* ignore */
					}
				},
				{ once: true }
			);
			return send.apply(this, arguments);
		};
	}

	function init() {
		bindWpUploader();
		bindFetch();
		bindXHR();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
