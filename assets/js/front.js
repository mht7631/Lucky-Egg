/**
 * Lucky Egg — frontend controller (Vanilla JS, no dependencies).
 *
 * The browser is NOT a security authority: it never generates codes, never enforces
 * rate limits, never decides permissions and never authenticates anyone. Login state is
 * WordPress's; the server reads it on every request. The UI only follows server responses.
 *
 * State machine:
 *   idle -> checking -> (collecting-user | ready-to-crack | blocked | idle)
 *   collecting-user -> (submitting-user | idle)
 *   submitting-user -> (ready-to-crack | collecting-user | blocked | idle)
 *   ready-to-crack -> cracking
 *   cracking -> (result | ready-to-crack | collecting-user | blocked | idle)
 *   result, blocked: terminal ("blocked" includes login_required, which shows a WP login link)
 */
(function () {
	'use strict';

	var config = window.LuckyEggConfig;
	if (!config || !config.ajaxUrl || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
		return;
	}

	var i18n = config.i18n || {};

	var TRANSITIONS = {
		'idle': ['checking'],
		'checking': ['collecting-user', 'ready-to-crack', 'blocked', 'idle'],
		'collecting-user': ['submitting-user', 'idle'],
		'submitting-user': ['ready-to-crack', 'collecting-user', 'blocked', 'idle'],
		'ready-to-crack': ['cracking'],
		'cracking': ['result', 'ready-to-crack', 'collecting-user', 'blocked', 'idle'],
		'result': [],
		'blocked': []
	};

	var BLOCKING_CODES = ['rate_limited', 'not_allowed', 'campaign_unavailable', 'invalid_nonce', 'login_required'];
	var REOPEN_CODES = ['phone_required', 'info_required'];

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function inArray(value, list) {
		return list.indexOf(value) !== -1;
	}

	function toEnglishDigits(value) {
		return String(value || '')
			.replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); })
			.replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); });
	}

	/* UX-only pre-check; the server always re-validates. */
	function normalizePhone(value) {
		var phone = toEnglishDigits(value).replace(/[\s\-\.\(\)\u00A0\u200B-\u200F\u202A-\u202E\u2066-\u2069]+/g, '');
		if (phone.indexOf('+98') === 0) {
			phone = '0' + phone.slice(3);
		} else if (phone.indexOf('0098') === 0) {
			phone = '0' + phone.slice(4);
		} else if (/^98\d{10}$/.test(phone)) {
			phone = '0' + phone.slice(2);
		} else if (/^9\d{9}$/.test(phone)) {
			phone = '0' + phone;
		}
		return phone;
	}

	function vibrate(pattern) {
		try {
			if (window.navigator && typeof window.navigator.vibrate === 'function') {
				window.navigator.vibrate(pattern);
			}
		} catch (e) {
			/* Vibration unsupported or blocked: ignore silently. */
		}
	}

	function prefersReducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function isSafeUrl(url) {
		return typeof url === 'string' && /^https?:\/\//i.test(url);
	}

	/*
	 * Pages served from a full-page cache may carry an expired nonce. On "invalid_nonce"
	 * a fresh nonce for the current WordPress session is fetched once and the call retried.
	 */
	function request(action, payload) {
		return send(action, payload).then(function (res) {
			if (res.success || !res.data || res.data.code !== 'invalid_nonce' || !config.actions.nonce) {
				return res;
			}
			return send(config.actions.nonce, {}).then(function (fresh) {
				if (!fresh.success || !fresh.data.nonce) {
					return res;
				}
				config.nonce = fresh.data.nonce;
				return send(action, payload);
			});
		});
	}

	function send(action, payload) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', config.nonce);
		body.append('redirect', window.location.href);
		Object.keys(payload || {}).forEach(function (key) {
			body.append(key, payload[key]);
		});

		return window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}).then(function (response) {
			return response.text().then(function (text) {
				var json = null;
				try {
					json = JSON.parse(text);
				} catch (e) {
					json = null;
				}
				if (!json || typeof json.success !== 'boolean') {
					return { success: false, data: { code: 'server_error', message: i18n.genericError } };
				}
				if (!json.data || typeof json.data !== 'object') {
					json.data = {};
				}
				if (!json.success && !json.data.message) {
					json.data.message = i18n.genericError;
				}
				return json;
			});
		}).catch(function () {
			return { success: false, data: { code: 'network', message: i18n.networkError } };
		});
	}

	/* ------------------------------------------------------------------ */
	/* Sound (Web Audio API, created lazily after a user gesture)          */
	/* ------------------------------------------------------------------ */

	var Sound = {
		ctx: null,

		context: function () {
			if (this.ctx) {
				return this.ctx;
			}
			var AudioCtx = window.AudioContext || window.webkitAudioContext;
			if (!AudioCtx) {
				return null;
			}
			try {
				this.ctx = new AudioCtx();
			} catch (e) {
				this.ctx = null;
			}
			return this.ctx;
		},

		unlock: function () {
			var ctx = this.context();
			if (ctx && ctx.state === 'suspended' && typeof ctx.resume === 'function') {
				try {
					var p = ctx.resume();
					if (p && typeof p.catch === 'function') {
						p.catch(function () {});
					}
				} catch (e) { /* ignore */ }
			}
		},

		noise: function (ctx, start, duration, gain, freq, q) {
			var length = Math.max(1, Math.floor(ctx.sampleRate * duration));
			var buffer = ctx.createBuffer(1, length, ctx.sampleRate);
			var data = buffer.getChannelData(0);
			for (var i = 0; i < length; i++) {
				data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / length, 3);
			}
			var source = ctx.createBufferSource();
			source.buffer = buffer;
			var filter = ctx.createBiquadFilter();
			filter.type = 'bandpass';
			filter.frequency.value = freq;
			filter.Q.value = q;
			var amp = ctx.createGain();
			amp.gain.setValueAtTime(gain, start);
			amp.gain.exponentialRampToValueAtTime(0.001, start + duration);
			source.connect(filter);
			filter.connect(amp);
			amp.connect(ctx.destination);
			source.start(start);
			source.stop(start + duration + 0.05);
		},

		tone: function (ctx, start, freq, duration, gain) {
			var osc = ctx.createOscillator();
			var amp = ctx.createGain();
			osc.type = 'sine';
			osc.frequency.setValueAtTime(freq, start);
			amp.gain.setValueAtTime(0.0001, start);
			amp.gain.exponentialRampToValueAtTime(gain, start + 0.02);
			amp.gain.exponentialRampToValueAtTime(0.0001, start + duration);
			osc.connect(amp);
			amp.connect(ctx.destination);
			osc.start(start);
			osc.stop(start + duration + 0.05);
		},

		crack: function () {
			var ctx = this.context();
			if (!ctx) { return; }
			try {
				var t = ctx.currentTime + 0.01;
				this.noise(ctx, t, 0.06, 0.7, 3200, 1.2);
				this.noise(ctx, t + 0.07, 0.05, 0.45, 2400, 1.5);
			} catch (e) { /* ignore */ }
		},

		breakOpen: function () {
			var ctx = this.context();
			if (!ctx) { return; }
			try {
				var t = ctx.currentTime + 0.01;
				this.noise(ctx, t, 0.28, 0.9, 1800, 0.8);
				this.noise(ctx, t + 0.04, 0.14, 0.6, 4200, 1.0);
				this.noise(ctx, t + 0.1, 0.32, 0.4, 900, 0.7);
				var notes = [880, 1108.73, 1318.51, 1760];
				for (var i = 0; i < notes.length; i++) {
					this.tone(ctx, t + 0.35 + i * 0.09, notes[i], 0.6, 0.18);
				}
			} catch (e) { /* ignore */ }
		}
	};

	/* ------------------------------------------------------------------ */
	/* Egg instance                                                        */
	/* ------------------------------------------------------------------ */

	function LuckyEgg(root) {
		this.root = root;
		this.campaign = root.getAttribute('data-campaign') || '';
		this.soundOn = root.getAttribute('data-sound') === '1';
		this.vibrateOn = root.getAttribute('data-vibrate') === '1';
		this.readyText = root.getAttribute('data-ready-text') || '';
		this.state = 'idle';
		this.busy = false;

		this.egg = root.querySelector('.lucky-egg__egg');
		this.hint = root.querySelector('.lucky-egg__hint');
		this.notice = root.querySelector('.lucky-egg__notice');
		this.result = root.querySelector('.lucky-egg__result');
		this.codeEl = root.querySelector('.lucky-egg__code');
		this.copyBtn = root.querySelector('.lucky-egg__copy');
		this.smsNote = root.querySelector('.lucky-egg__sms-note');
		this.particles = root.querySelector('.lucky-egg__particles');

		this.modal = root.querySelector('.lucky-egg-modal');
		this.form = this.modal ? this.modal.querySelector('.lucky-egg-modal__form') : null;
		this.modalError = this.modal ? this.modal.querySelector('.lucky-egg-modal__error') : null;
		this.submitBtn = this.modal ? this.modal.querySelector('.lucky-egg-modal__submit') : null;
		this.lastFocus = null;

		if (!this.egg || !this.campaign) {
			return;
		}

		/* Move the modal to <body> so transformed ancestors cannot break position:fixed. */
		if (this.modal && this.modal.parentNode !== document.body) {
			var accent = '';
			try {
				accent = window.getComputedStyle(root).getPropertyValue('--le-accent');
			} catch (e) { accent = ''; }
			if (accent) {
				this.modal.style.setProperty('--le-accent', accent.trim());
			}
			document.body.appendChild(this.modal);
		}

		this.bind();
	}

	LuckyEgg.prototype.setState = function (next) {
		var allowed = TRANSITIONS[this.state] || [];
		if (!inArray(next, allowed)) {
			return false;
		}
		this.state = next;
		this.root.setAttribute('data-state', next);
		return true;
	};

	LuckyEgg.prototype.bind = function () {
		var self = this;

		this.egg.addEventListener('click', function (event) {
			event.preventDefault();
			self.onEggClick();
		});

		if (this.copyBtn) {
			this.copyBtn.addEventListener('click', function () {
				self.copyCode();
			});
		}

		if (this.modal) {
			var closers = this.modal.querySelectorAll('[data-le-close]');
			for (var i = 0; i < closers.length; i++) {
				closers[i].addEventListener('click', function () {
					self.cancelModal();
				});
			}
			this.modal.addEventListener('keydown', function (event) {
				if (event.key === 'Escape' || event.key === 'Esc') {
					self.cancelModal();
				}
			});
		}

		if (this.form) {
			this.form.addEventListener('submit', function (event) {
				event.preventDefault();
				self.submitUser();
			});
		}
	};

	LuckyEgg.prototype.onEggClick = function () {
		if (this.soundOn) {
			Sound.unlock();
		}
		if (this.busy) {
			return;
		}
		if (this.state === 'idle') {
			this.prepare();
		} else if (this.state === 'ready-to-crack') {
			this.crack();
		} else if (this.state === 'collecting-user') {
			this.openModal();
		}
	};

	LuckyEgg.prototype.showNotice = function (message, loginUrl) {
		if (!this.notice) { return; }
		this.notice.textContent = '';
		if (!message) {
			this.notice.hidden = true;
			return;
		}
		var text = document.createElement('span');
		text.textContent = message;
		this.notice.appendChild(text);
		if (isSafeUrl(loginUrl)) {
			var link = document.createElement('a');
			link.className = 'lucky-egg__login';
			link.href = loginUrl;
			link.textContent = i18n.login || 'Login';
			this.notice.appendChild(link);
		}
		this.notice.hidden = false;
	};

	LuckyEgg.prototype.setHint = function (text) {
		if (this.hint && text) {
			this.hint.textContent = text;
		}
	};

	/* Step 1: ask the server what to do ------------------------------- */

	LuckyEgg.prototype.prepare = function () {
		var self = this;
		this.busy = true;
		this.showNotice('');
		this.setState('checking');

		request(config.actions.prepare, { campaign: this.campaign }).then(function (res) {
			self.busy = false;
			if (res.success && res.data.step === 'collect-user') {
				self.setState('collecting-user');
				self.openModal(res.data.prefill || {});
			} else if (res.success && res.data.step === 'ready') {
				self.becomeReady();
			} else {
				self.handleError(res.data, 'idle');
			}
		});
	};

	LuckyEgg.prototype.openModal = function (prefill) {
		if (!this.modal) { return; }
		this.lastFocus = document.activeElement;
		this.modal.hidden = false;
		document.body.classList.add('lucky-egg-modal-open');
		this.setModalError('');
		if (this.form && prefill && prefill.name && this.form.elements.name && !this.form.elements.name.value) {
			this.form.elements.name.value = prefill.name;
		}
		var first = this.form ? this.form.querySelector('input') : null;
		if (first) {
			window.setTimeout(function () {
				try { first.focus(); } catch (e) { /* ignore */ }
			}, 50);
		}
	};

	LuckyEgg.prototype.closeModal = function () {
		if (!this.modal) { return; }
		this.modal.hidden = true;
		document.body.classList.remove('lucky-egg-modal-open');
		if (this.lastFocus && typeof this.lastFocus.focus === 'function') {
			try { this.lastFocus.focus(); } catch (e) { /* ignore */ }
		}
	};

	LuckyEgg.prototype.cancelModal = function () {
		if (this.busy) {
			return;
		}
		this.closeModal();
		if (this.state === 'collecting-user') {
			this.setState('idle');
		}
	};

	LuckyEgg.prototype.setModalError = function (message) {
		if (!this.modalError) { return; }
		this.modalError.textContent = message || '';
		this.modalError.hidden = !message;
	};

	LuckyEgg.prototype.setSubmitting = function (on) {
		if (!this.submitBtn) { return; }
		this.submitBtn.disabled = !!on;
		this.submitBtn.textContent = on ? (i18n.submitting || '...') : (i18n.submit || '');
	};

	/* Step 1b: guest info --------------------------------------------- */

	LuckyEgg.prototype.submitUser = function () {
		if (this.busy || this.state !== 'collecting-user' || !this.form) {
			return;
		}
		var self = this;
		var name = String(this.form.elements.name.value || '').replace(/\s+/g, ' ').trim();
		var phone = normalizePhone(this.form.elements.phone.value);

		if (name.length < 2 || name.length > 60) {
			this.setModalError(i18n.invalidName);
			return;
		}
		if (!/^09\d{9}$/.test(phone)) {
			this.setModalError(i18n.invalidPhone);
			return;
		}

		this.busy = true;
		this.setModalError('');
		this.setSubmitting(true);
		this.setState('submitting-user');

		request(config.actions.register, { campaign: this.campaign, name: name, phone: phone }).then(function (res) {
			self.busy = false;
			self.setSubmitting(false);

			if (res.success && res.data.step === 'ready') {
				self.closeModal();
				self.becomeReady();
				return;
			}

			var code = res.data.code || '';
			if (inArray(code, BLOCKING_CODES)) {
				self.closeModal();
				self.handleError(res.data, 'idle');
				return;
			}

			self.setState('collecting-user');
			/* Validation/account errors and transient errors stay inside the modal. */
			self.setModalError(res.data.message || i18n.genericError);
		});
	};

	/* Ready: first crack visual ---------------------------------------- */

	LuckyEgg.prototype.becomeReady = function () {
		if (!this.setState('ready-to-crack')) {
			return;
		}
		this.root.classList.add('is-cracked');
		this.setHint(this.readyText);
		if (this.soundOn) {
			Sound.crack();
		}
		if (this.vibrateOn) {
			vibrate(40);
		}
	};

	/* Step 2: crack ---------------------------------------------------- */

	LuckyEgg.prototype.crack = function () {
		var self = this;
		var started = Date.now();
		this.busy = true;
		this.showNotice('');
		this.setState('cracking');
		this.root.classList.add('is-shaking');
		this.setHint(i18n.cracking);

		request(config.actions.crack, { campaign: this.campaign }).then(function (res) {
			var wait = Math.max(0, 650 - (Date.now() - started));
			window.setTimeout(function () {
				self.root.classList.remove('is-shaking');
				if (res.success && res.data.step === 'result' && res.data.code) {
					self.reveal(res.data);
					return;
				}
				self.busy = false;
				var code = res.data.code || '';
				if (inArray(code, REOPEN_CODES)) {
					self.root.classList.remove('is-cracked');
					self.setState('collecting-user');
					self.openModal({});
					self.setModalError(res.data.message || '');
					return;
				}
				self.handleError(res.data, 'ready-to-crack');
				if (self.state === 'ready-to-crack') {
					self.setHint(self.readyText);
				}
			}, wait);
		});
	};

	LuckyEgg.prototype.reveal = function (data) {
		var self = this;
		this.setState('result');
		this.root.classList.add('is-broken');

		if (this.soundOn) {
			Sound.breakOpen();
		}
		if (this.vibrateOn) {
			vibrate([100, 50, 200]);
		}
		this.spawnParticles();

		if (this.codeEl) {
			this.codeEl.textContent = String(data.code);
		}
		if (this.smsNote) {
			if (data.sms === 'queued' && i18n.smsQueued) {
				this.smsNote.textContent = i18n.smsQueued;
				this.smsNote.hidden = false;
			} else {
				this.smsNote.hidden = true;
			}
		}

		window.setTimeout(function () {
			if (self.result) {
				self.result.hidden = false;
			}
			/* Next frame so the transition runs after display change. */
			window.requestAnimationFrame(function () {
				self.root.classList.add('is-revealed');
			});
		}, prefersReducedMotion() ? 0 : 480);
	};

	LuckyEgg.prototype.spawnParticles = function () {
		if (!this.particles || prefersReducedMotion()) {
			return;
		}
		var colors = ['#f59e0b', '#fbbf24', '#fde68a', '#f97316', '#ffffff', '#facc15'];
		var count = window.innerWidth < 480 ? 22 : 34;
		var container = this.particles;
		var fragment = document.createDocumentFragment();

		for (var i = 0; i < count; i++) {
			var p = document.createElement('span');
			var angle = (Math.PI * 2 * i) / count + (Math.random() - 0.5) * 0.5;
			var distance = 80 + Math.random() * 120;
			p.className = 'lucky-egg__particle';
			p.style.setProperty('--dx', Math.round(Math.cos(angle) * distance) + 'px');
			p.style.setProperty('--dy', Math.round(Math.sin(angle) * distance) + 'px');
			p.style.setProperty('--rot', Math.round(Math.random() * 720 - 360) + 'deg');
			p.style.setProperty('--delay', (Math.random() * 0.15).toFixed(2) + 's');
			p.style.setProperty('--size', (5 + Math.round(Math.random() * 7)) + 'px');
			p.style.setProperty('--radius', Math.random() > 0.5 ? '50%' : '2px');
			p.style.setProperty('--color', colors[i % colors.length]);
			fragment.appendChild(p);
		}
		container.appendChild(fragment);

		window.setTimeout(function () {
			while (container.firstChild) {
				container.removeChild(container.firstChild);
			}
		}, 1900);
	};

	LuckyEgg.prototype.copyCode = function () {
		var self = this;
		var text = this.codeEl ? this.codeEl.textContent : '';
		if (!text) { return; }

		function done() {
			if (!self.copyBtn) { return; }
			self.copyBtn.textContent = i18n.copied;
			window.setTimeout(function () {
				self.copyBtn.textContent = i18n.copy;
			}, 1800);
		}

		function fallback() {
			var area = document.createElement('textarea');
			area.value = text;
			area.setAttribute('readonly', '');
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild(area);
			area.select();
			try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
			document.body.removeChild(area);
		}

		if (window.navigator.clipboard && typeof window.navigator.clipboard.writeText === 'function') {
			window.navigator.clipboard.writeText(text).then(done, fallback);
		} else {
			fallback();
		}
	};

	LuckyEgg.prototype.handleError = function (data, fallbackState) {
		var code = (data && data.code) || '';
		var message = (data && data.message) || i18n.genericError;

		if (inArray(code, BLOCKING_CODES)) {
			this.setState('blocked');
			this.busy = true;
			this.showNotice(message, code === 'login_required' ? data.login_url : '');
			return;
		}
		this.setState(fallbackState);
		if (fallbackState === 'idle') {
			this.root.classList.remove('is-cracked');
		}
		this.showNotice(message);
	};

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	function initAll(scope) {
		var base = scope && scope.querySelectorAll ? scope : document;
		var nodes = base.querySelectorAll('.lucky-egg[data-campaign]');
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i].getAttribute('data-le-ready') === '1') {
				continue;
			}
			nodes[i].setAttribute('data-le-ready', '1');
			new LuckyEgg(nodes[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { initAll(document); });
	} else {
		initAll(document);
	}

	/*
	 * Elementor editor/preview re-renders widgets dynamically. Elementor fires
	 * "elementor/frontend/init" through jQuery, which a native listener does not receive.
	 */
	var elementorHooked = false;
	function hookElementor() {
		if (elementorHooked || !window.elementorFrontend || !window.elementorFrontend.hooks) {
			return;
		}
		elementorHooked = true;
		window.elementorFrontend.hooks.addAction('frontend/element_ready/lucky_egg.default', function ($scope) {
			var el = $scope && $scope[0] ? $scope[0] : null;
			if (el) {
				initAll(el);
			}
		});
	}
	if (window.jQuery) {
		window.jQuery(window).on('elementor/frontend/init', hookElementor);
	}
	window.addEventListener('elementor/frontend/init', hookElementor);
	hookElementor();
})();
