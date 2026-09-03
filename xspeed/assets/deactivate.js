/**
 * xSpeed — deactivation feedback modal.
 *
 * Intercepts the Deactivate link for xSpeed Cache on the Plugins screen and
 * shows a short, optional survey. On "Submit & Deactivate" the selected
 * reason(s) are POSTed to admin-ajax (which stores them for Usage_Tracker to
 * transmit); skipping / closing sends nothing. In every case the browser then
 * follows WordPress's real deactivate URL (which carries its own nonce), so
 * deactivation itself is never blocked or altered.
 *
 * Reasons are multi-select (checkboxes) — the admin may pick one or many.
 *
 * Vanilla JS, no dependencies. All user-facing copy is rendered server-side.
 *
 * NOTE: this file is enqueued in the footer, but WordPress prints the modal
 * markup (admin_footer-plugins.php) AFTER the enqueued footer scripts. So we
 * MUST defer wiring until the DOM is fully parsed — running at parse time,
 * getElementById('xspeed-deactivate-modal') returns null and the survey never
 * binds. See the DOMContentLoaded guard at the bottom.
 */
(function () {
	'use strict';

	function init() {
		var cfg = window.XSpeedDeactivate;
		if (!cfg) {
			return;
		}

		var modal = document.getElementById('xspeed-deactivate-modal');
		var link = findDeactivateLink(cfg);
		if (!modal || !link) {
			return;
		}

		var deactivateUrl = link.getAttribute('href');
		var lastFocus = null;
		var pending = false;
		var navigated = false;

		/* --- wiring --------------------------------------------------------- */

		link.addEventListener('click', function (e) {
			if (!deactivateUrl) {
				return;
			}
			e.preventDefault();
			openModal();
		});

		each(reasonInputs(), function (input) {
			input.addEventListener('change', function () {
				syncReason(input);
			});
		});

		each(modal.querySelectorAll('[data-xspeed-close]'), function (el) {
			el.addEventListener('click', function (e) {
				e.preventDefault();
				closeModal();
			});
		});

		on(modal.querySelector('[data-xspeed-skip]'), 'click', function (e) {
			e.preventDefault();
			go(); // deactivate without sending anything
		});

		on(modal.querySelector('[data-xspeed-submit]'), 'click', function (e) {
			e.preventDefault();
			submit();
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && isOpen()) {
				closeModal();
			}
		});

		/* --- behavior ------------------------------------------------------- */

		function submit() {
			if (pending) {
				return;
			}
			pending = true;
			setLoading(true);

			var payload = new URLSearchParams();
			payload.append('action', cfg.action);
			payload.append('nonce', cfg.nonce);

			each(reasonInputs(), function (input) {
				if (!input.checked) {
					return;
				}
				var id = input.value;
				payload.append('reason[]', id);
				var box = detailFor(id);
				if (box && !box.hidden && box.value) {
					payload.append('detail_' + id, box.value);
				}
			});

			fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: payload.toString()
			}).then(go, go);

			// Safety net: never trap the user if the request hangs.
			window.setTimeout(go, 4000);
		}

		// Show/hide a single reason's follow-up textarea + support callout based
		// on its own checked state (multi-select: each is independent).
		function syncReason(input) {
			var id = input.value;
			var box = detailFor(id);
			if (box) {
				box.hidden = !input.checked;
				if (input.checked) {
					box.focus();
				}
			}
			var help = helpFor(id);
			if (help) {
				help.hidden = !input.checked;
			}
		}

		/* --- modal open/close ---------------------------------------------- */

		function openModal() {
			lastFocus = document.activeElement;
			modal.classList.add('is-open');
			modal.setAttribute('aria-hidden', 'false');
			document.body.classList.add('xspeed-deactivate-lock');
			var first = reasonInputs()[0];
			if (first) {
				first.focus();
			}
		}

		function closeModal() {
			modal.classList.remove('is-open');
			modal.setAttribute('aria-hidden', 'true');
			document.body.classList.remove('xspeed-deactivate-lock');
			if (lastFocus && typeof lastFocus.focus === 'function') {
				lastFocus.focus();
			}
		}

		function go() {
			if (navigated) {
				return;
			}
			navigated = true;
			window.location.href = deactivateUrl;
		}

		/* --- helpers -------------------------------------------------------- */

		function reasonInputs() {
			return modal.querySelectorAll('input[name="xspeed-deactivate-reason"]');
		}

		function detailFor(id) {
			return modal.querySelector('.xspeed-deactivate__detail[data-for="' + cssAttr(id) + '"]');
		}

		function helpFor(id) {
			return modal.querySelector('.xspeed-deactivate__help[data-for="' + cssAttr(id) + '"]');
		}

		function isOpen() {
			return modal.classList.contains('is-open');
		}

		function setLoading(loading) {
			var btn = modal.querySelector('[data-xspeed-submit]');
			if (!btn) {
				return;
			}
			btn.disabled = loading;
			btn.classList.toggle('is-loading', loading);
		}
	}

	/**
	 * Locate xSpeed's Deactivate link on the Plugins table. Primary match is the
	 * row's data-plugin attribute; falls back to scanning deactivate links for
	 * our plugin file in the query string (covers markup variations).
	 */
	function findDeactivateLink(cfg) {
		var byRow = document.querySelector('tr[data-plugin="' + cssAttr(cfg.plugin) + '"] .deactivate a');
		if (byRow) {
			return byRow;
		}
		var encoded = encodeURIComponent(cfg.plugin);
		var candidates = document.querySelectorAll('a[href*="action=deactivate"]');
		for (var i = 0; i < candidates.length; i++) {
			var href = candidates[i].getAttribute('href') || '';
			if (href.indexOf('plugin=' + encoded) !== -1 || href.indexOf('plugin=' + cfg.plugin) !== -1) {
				return candidates[i];
			}
		}
		return null;
	}

	function cssAttr(value) {
		return String(value == null ? '' : value).replace(/"/g, '\\"');
	}

	function each(list, fn) {
		Array.prototype.forEach.call(list || [], fn);
	}

	function on(el, evt, fn) {
		if (el) {
			el.addEventListener(evt, fn);
		}
	}

	// The modal markup is printed AFTER this script, so wait for the DOM.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
