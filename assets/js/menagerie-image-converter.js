/**
 * Menagerie - Tools → Image Converter (batched AJAX).
 *
 * @package Menagerie
 */
(function () {
	'use strict';

	var cfg = window.MenagerieConverter || {};
	var form = document.getElementById('menagerie-image-converter-form');
	if (!form || !cfg.ajaxUrl) {
		return;
	}

	var submitBtn = document.getElementById('menagerie-convert-submit');
	var targetSelect = document.getElementById('menagerie-convert-target');
	var progressWrap = document.getElementById('menagerie-converter-progress');
	var progressTrack = progressWrap
		? progressWrap.querySelector('.menagerie-converter-progress__track')
		: null;
	var progressBar = progressWrap
		? progressWrap.querySelector('.menagerie-converter-progress__bar')
		: null;
	var statusEl = document.getElementById('menagerie-converter-status');
	var summaryEl = document.getElementById('menagerie-converter-summary');

	var str = function (key, fallback) {
		if (cfg.strings && cfg.strings[key]) {
			return cfg.strings[key];
		}
		return fallback || key;
	};

	var sprintf = function (template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(template).replace(/%(\d+)\$[sd]|%[sd]/g, function (match, pos) {
			var v;
			if (pos) {
				v = args[parseInt(pos, 10) - 1];
			} else {
				v = args[i++];
			}
			return v === undefined ? '' : String(v);
		});
	};

	var selectWasDisabled = !!(targetSelect && targetSelect.disabled);
	var submitWasDisabled = !!(submitBtn && submitBtn.disabled);

	var setBusy = function (busy) {
		if (submitBtn) {
			submitBtn.disabled = busy ? true : submitWasDisabled;
		}
		if (targetSelect) {
			targetSelect.disabled = busy ? true : selectWasDisabled;
		}
	};

	var setProgress = function (done, total) {
		var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
		if (progressBar) {
			progressBar.style.width = pct + '%';
		}
		if (progressTrack) {
			progressTrack.setAttribute('aria-valuenow', String(pct));
			progressTrack.setAttribute('aria-valuemax', '100');
		}
		if (statusEl) {
			statusEl.textContent = sprintf(str('progress', 'Converting %1$s of %2$s…'), done, total);
		}
	};

	var post = function (action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (k) {
			body.append(k, String(data[k]));
		});
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		}).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok || !json || json.success !== true) {
					var msg =
						json && json.data && json.data.message
							? json.data.message
							: str('error', 'Something went wrong. Please try again.');
					throw new Error(msg);
				}
				return json.data || {};
			});
		});
	};

	var renderSummary = function (totals) {
		if (!summaryEl) {
			return;
		}
		summaryEl.hidden = false;
		summaryEl.textContent = '';

		var heading = document.createElement('h2');
		heading.textContent = str('done', 'Conversion finished.');
		summaryEl.appendChild(heading);

		var list = document.createElement('ul');
		list.className = 'menagerie-converter-summary__counts';
		[
			['summaryConverted', totals.converted],
			['summarySkipped', totals.skipped],
			['summaryFailed', totals.failed.length],
		].forEach(function (row) {
			var li = document.createElement('li');
			li.textContent = sprintf(str(row[0], '%d'), row[1]);
			list.appendChild(li);
		});
		summaryEl.appendChild(list);

		if (totals.failed.length) {
			var failHeading = document.createElement('h3');
			failHeading.textContent = str('failuresHeading', 'Failed conversions');
			summaryEl.appendChild(failHeading);

			var failList = document.createElement('ul');
			failList.className = 'menagerie-converter-summary__failures';
			var maxShow = 25;
			totals.failed.slice(0, maxShow).forEach(function (f) {
				var li = document.createElement('li');
				var title = f.title || '#' + f.id;
				var reason = f.reason || '';
				li.appendChild(document.createTextNode(title + (reason ? ' - ' + reason : '')));
				failList.appendChild(li);
			});
			summaryEl.appendChild(failList);
			if (totals.failed.length > maxShow) {
				var more = document.createElement('p');
				more.className = 'description';
				more.textContent = sprintf(
					str('andMore', '…and %d more.'),
					totals.failed.length - maxShow
				);
				summaryEl.appendChild(more);
			}
		}
	};

	var runBatches = function (target, total, offset, totals) {
		if (total === 0) {
			if (statusEl) {
				statusEl.textContent = str('empty', 'No images found in the Media Library.');
			}
			setBusy(false);
			return Promise.resolve();
		}

		setProgress(Math.min(offset, total), total);

		return post('menagerie_image_convert_batch', {
			target: target,
			offset: offset,
		}).then(function (data) {
			totals.converted += data.converted || 0;
			totals.skipped += data.skipped || 0;
			if (Array.isArray(data.failed)) {
				totals.failed = totals.failed.concat(data.failed);
			}
			var next = typeof data.nextOffset === 'number' ? data.nextOffset : offset + (data.processed || 0);
			setProgress(Math.min(next, total), total);

			if (data.done) {
				if (statusEl) {
					statusEl.textContent = str('done', 'Conversion finished.');
				}
				if (progressBar) {
					progressBar.style.width = '100%';
				}
				if (progressTrack) {
					progressTrack.setAttribute('aria-valuenow', '100');
				}
				renderSummary(totals);
				setBusy(false);
				return;
			}

			return runBatches(target, total, next, totals);
		});
	};

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		if (!targetSelect || targetSelect.disabled || (submitBtn && submitBtn.disabled)) {
			return;
		}
		var target = targetSelect.value;
		var selected = targetSelect.options[targetSelect.selectedIndex];
		if (!target || (selected && selected.disabled)) {
			return;
		}
		if (!window.confirm(str('confirm', 'Continue?'))) {
			return;
		}

		setBusy(true);
		if (summaryEl) {
			summaryEl.hidden = true;
			summaryEl.textContent = '';
		}
		if (progressWrap) {
			progressWrap.hidden = false;
		}
		if (statusEl) {
			statusEl.textContent = str('starting', 'Starting…');
		}
		setProgress(0, 1);

		post('menagerie_image_convert_start', { target: target })
			.then(function (data) {
				var total = data.total || 0;
				return runBatches(target, total, 0, {
					converted: 0,
					skipped: 0,
					failed: [],
				});
			})
			.catch(function (err) {
				if (statusEl) {
					statusEl.textContent = err && err.message ? err.message : str('error', 'Something went wrong. Please try again.');
				}
				setBusy(false);
			});
	});
})();
