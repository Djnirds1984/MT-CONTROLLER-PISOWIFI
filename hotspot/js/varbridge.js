/*!
 * varbridge.js — AIRCOINS NETFI captive portal dual-mode bridge.
 *
 * The SAME hotspot HTML files run in two modes:
 *   (A) router-native : served by MikroTik, every $(var) is already substituted,
 *                       this script is a no-op.
 *   (B) external      : served by lighttpd on an SBC, $(var) stay literal text and
 *                       the real values arrive as URL query params. This script
 *                       detects that case, publishes window.PORTAL, and patches the
 *                       literal tokens out of the DOM so the page renders correctly.
 *
 * Dependency-free. Must be loaded in <head> BEFORE any inline script that reads
 * window.PORTAL. Substitution of the DOM happens on DOMContentLoaded (once <body>
 * exists); detection + window.PORTAL + helpers are available immediately.
 */
(function (window, document) {
	'use strict';

	/* ------------------------------------------------------------------ *
	 * 1. Query params                                                     *
	 * ------------------------------------------------------------------ */
	function parseParams(search) {
		var out = {};
		var q = (search || '').replace(/^\?/, '');
		if (!q) { return out; }
		var parts = q.split('&');
		for (var i = 0; i < parts.length; i++) {
			if (!parts[i]) { continue; }
			var eq = parts[i].indexOf('=');
			var key = eq === -1 ? parts[i] : parts[i].slice(0, eq);
			var val = eq === -1 ? '' : parts[i].slice(eq + 1);
			try { key = decodeURIComponent(key.replace(/\+/g, ' ')); } catch (e) {}
			try { val = decodeURIComponent(val.replace(/\+/g, ' ')); } catch (e) {}
			out[key] = val;
		}
		return out;
	}

	var params = parseParams(window.location.search);

	/* ------------------------------------------------------------------ *
	 * 2. External-mode detection                                          *
	 *    - the router redirect always carries a `login` param, or          *
	 *    - the served HTML still contains a literal $(mac) token.          *
	 * ------------------------------------------------------------------ */
	function htmlHasToken(root) {
		try {
			var html = (root && root.outerHTML) ? root.outerHTML : '';
			if (!html && document.body) { html = document.body.innerHTML || ''; }
			return html.indexOf('$(mac)') !== -1 || html.indexOf('$(link-login-only)') !== -1;
		} catch (e) {
			return false;
		}
	}

	var external = (typeof params.login === 'string' && params.login !== '') ||
		htmlHasToken(document.documentElement);

	/* ------------------------------------------------------------------ *
	 * 3. window.PORTAL                                                    *
	 * ------------------------------------------------------------------ */
	function basePath() {
		// directory of the current document, e.g. "/" or "/portal/"
		return window.location.pathname.replace(/[^/]*$/, '');
	}

	var PORTAL = {
		external: !!external,
		params: params,

		/* read a single query param (live, falls back to the parsed snapshot) */
		qs: function (name) {
			if (params && typeof params[name] !== 'undefined') { return params[name]; }
			var live = parseParams(window.location.search);
			return (typeof live[name] !== 'undefined') ? live[name] : null;
		},

		/* build the SBC status URL carrying the params needed after PAP login */
		statusUrl: function () {
			var p = params;
			return basePath() + 'status.html' +
				'?mac=' + encodeURIComponent(p.mac || '') +
				'&ip=' + encodeURIComponent(p.ip || '') +
				'&login=' + encodeURIComponent(p.login || '') +
				'&logout=' + encodeURIComponent(p.logout || '');
		},

		/* build the SBC login URL (used for redirects back to the portal) */
		loginUrl: function () {
			var p = params;
			return basePath() + 'login.html' +
				'?mac=' + encodeURIComponent(p.mac || '') +
				'&ip=' + encodeURIComponent(p.ip || '') +
				'&login=' + encodeURIComponent(p.login || '') +
				'&logout=' + encodeURIComponent(p.logout || '') +
				'&user=' + encodeURIComponent(p.user || '');
		}
	};

	window.PORTAL = PORTAL;

	/* Nothing else to do in router-native mode. */
	if (!PORTAL.external) { return; }

	/* ------------------------------------------------------------------ *
	 * 4. External-mode DOM patching (runs once <body> is parsed)          *
	 * ------------------------------------------------------------------ */

	/* literal token -> value map (only these are substituted) */
	function tokenMap() {
		return {
			'$(mac)': params.mac || '',
			'$(ip)': params.ip || '',
			'$(username)': params.user || '',
			'$(error)': params.err || '',
			'$(link-logout)': params.logout || ''
		};
	}

	function isSkippable(node) {
		var p = node.parentNode;
		if (!p || !p.nodeName) { return false; }
		return p.nodeName === 'SCRIPT' || p.nodeName === 'STYLE';
	}

	/* 4a. remove literal $(if ...) / $(endif) marker text nodes */
	function stripConditionals() {
		var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
		var kill = [];
		var n;
		var reIf = /^\s*\$\(if\s[^)]*\)\s*$/;
		var reEnd = /^\s*\$\(endif\)\s*$/;
		while ((n = walker.nextNode())) {
			var v = n.nodeValue;
			if (v && (reIf.test(v) || reEnd.test(v))) { kill.push(n); }
		}
		for (var i = 0; i < kill.length; i++) {
			if (kill[i].parentNode) { kill[i].parentNode.removeChild(kill[i]); }
		}
	}

	/* 4b. error block: hide when no error, else show with params.err text */
	function handleError() {
		var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
		var targets = [];
		var n;
		while ((n = walker.nextNode())) {
			if (isSkippable(n)) { continue; }
			if (n.nodeValue && n.nodeValue.indexOf('$(error)') !== -1) {
				var par = n.parentNode;
				if (par && par.nodeType === 1 && targets.indexOf(par) === -1) { targets.push(par); }
			}
		}
		var err = (params.err || '').replace(/^\s+|\s+$/g, '');
		for (var i = 0; i < targets.length; i++) {
			if (err) {
				targets[i].textContent = err;
				targets[i].style.display = '';
			} else {
				targets[i].style.display = 'none';
			}
		}
	}

	/* 4c. substitute remaining literal tokens in text nodes */
	function substituteText() {
		var map = tokenMap();
		var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
		var n;
		while ((n = walker.nextNode())) {
			if (isSkippable(n)) { continue; }
			var v = n.nodeValue;
			if (!v || v.indexOf('$(') === -1) { continue; }
			var replaced = v;
			for (var tok in map) {
				if (Object.prototype.hasOwnProperty.call(map, tok)) {
					replaced = replaced.split(tok).join(map[tok]);
				}
			}
			if (replaced !== v) { n.nodeValue = replaced; }
		}
	}

	/* 4d. substitute tokens in attributes + point login forms at params.login */
	function substituteAttrs() {
		var map = tokenMap();
		var all = document.body.getElementsByTagName('*');
		for (var i = 0; i < all.length; i++) {
			var el = all[i];
			var attrs = el.attributes;
			if (!attrs) { continue; }
			for (var a = 0; a < attrs.length; a++) {
				var attr = attrs[a];
				var val = attr.value;
				if (!val || val.indexOf('$(') === -1) { continue; }

				/* login form action -> router login URL from `login` param */
				if (attr.name === 'action' && val === '$(link-login-only)') {
					if (params.login) { el.setAttribute('action', params.login); }
					continue;
				}

				var replaced = val;
				for (var tok in map) {
					if (Object.prototype.hasOwnProperty.call(map, tok)) {
						replaced = replaced.split(tok).join(map[tok]);
					}
				}
				if (replaced !== val) { el.setAttribute(attr.name, replaced); }
			}
		}
	}

	function patch() {
		if (!document.body) { return; }
		/* late detection safety-net: full body may reveal literal tokens */
		if (!PORTAL.external && htmlHasToken(document.documentElement)) {
			PORTAL.external = true;
		}
		if (!PORTAL.external) { return; }
		try {
			stripConditionals();
			handleError();
			substituteText();
			substituteAttrs();
		} catch (e) {
			/* never break the page: portal stays usable, tokens may remain */
			if (window.console && console.error) { console.error('varbridge patch failed', e); }
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', patch);
	} else {
		patch();
	}

}(window, document));
