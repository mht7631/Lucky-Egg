/**
 * Lucky Egg — admin helpers (media picker, color sync, confirmations, copy).
 * Added file: required for the WordPress Media Library picker on the campaign form.
 */
(function () {
	'use strict';

	var cfg = window.LuckyEggAdmin || { i18n: {} };

	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!target || typeof target.closest !== 'function') {
			return;
		}

		var mediaBtn = target.closest('[data-le-media]');
		if (mediaBtn) {
			event.preventDefault();
			if (!window.wp || !window.wp.media) {
				return;
			}
			var input = document.getElementById(mediaBtn.getAttribute('data-le-media'));
			var preview = document.getElementById(mediaBtn.getAttribute('data-le-preview'));
			var frame = window.wp.media({
				title: cfg.i18n.selectImage,
				button: { text: cfg.i18n.useImage },
				library: { type: ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'] },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				if (input && attachment && attachment.url) {
					input.value = attachment.url;
				}
				if (preview && attachment && attachment.url) {
					preview.src = attachment.url;
				}
			});
			frame.open();
			return;
		}

		var clearBtn = target.closest('[data-le-clear]');
		if (clearBtn) {
			event.preventDefault();
			var field = document.getElementById(clearBtn.getAttribute('data-le-clear'));
			var img = document.getElementById(clearBtn.getAttribute('data-le-preview'));
			if (field) {
				field.value = '';
			}
			if (img && img.getAttribute('data-fallback')) {
				img.src = img.getAttribute('data-fallback');
			}
			return;
		}

		var confirmBtn = target.closest('[data-le-confirm]');
		if (confirmBtn) {
			if (!window.confirm(confirmBtn.getAttribute('data-le-confirm'))) {
				event.preventDefault();
			}
			return;
		}

		var copyEl = target.closest('[data-le-copy]');
		if (copyEl && window.navigator.clipboard) {
			var original = copyEl.textContent;
			window.navigator.clipboard.writeText(copyEl.getAttribute('data-le-copy')).then(function () {
				copyEl.textContent = cfg.i18n.copied || original;
				window.setTimeout(function () {
					copyEl.textContent = original;
				}, 1200);
			}, function () {});
		}
	});

	/* Keep hex text field and native color picker in sync. */
	document.addEventListener('input', function (event) {
		var el = event.target;
		if (!el || !el.classList) {
			return;
		}
		var cell = el.closest ? el.closest('td') : null;
		if (!cell) {
			return;
		}
		if (el.classList.contains('lucky-egg-color-picker')) {
			var text = cell.querySelector('.lucky-egg-color-text');
			if (text) {
				text.value = el.value;
			}
		} else if (el.classList.contains('lucky-egg-color-text') && /^#[0-9a-f]{6}$/i.test(el.value)) {
			var picker = cell.querySelector('.lucky-egg-color-picker');
			if (picker) {
				picker.value = el.value;
			}
		}
	});
})();
