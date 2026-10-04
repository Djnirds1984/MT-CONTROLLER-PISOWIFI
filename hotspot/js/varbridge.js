/*
 * AIRCOINS NETFI — varbridge.js
 *
 * Dual-mode bridge for the captive portal.
 *
 * Router-native mode: MikroTik substitutes $(var) tokens server-side before
 * the browser sees the HTML.  chap-id, chap-challenge, mac, ip, etc. are
 * already filled in by RouterOS.
 *
 * External (SBC) mode: lighttpd serves the same HTML but does NOT substitute
 * $(var) tokens.  The router-side stubs redirect here with query params
 * (?mac=…&ip=…&login=…&logout=…&user=…&err=…).  This script detects the
 * literal $(…) tokens in the DOM, builds a window.PORTAL object from the
 * query string, and patches the DOM to fill in the blanks.
 */
(function () {
    "use strict";

    // ── Parse query string ──────────────────────────────────────────────────
    function parseQuery() {
        var params = {};
        var qs = window.location.search.substring(1);
        if (!qs) return params;
        qs.split("&").forEach(function (pair) {
            var parts = pair.split("=");
            var key = decodeURIComponent(parts[0] || "");
            var val = decodeURIComponent(parts[1] || "");
            if (key) params[key] = val;
        });
        return params;
    }

    // ── Detect mode ─────────────────────────────────────────────────────────
    // If the DOM contains literal "$(chap-id)" text, the page was NOT processed
    // by RouterOS → we are in external/SBC mode.
    var bodyText = document.body ? document.body.innerHTML : "";
    var isExternal = bodyText.indexOf("$(chap-id)") !== -1;

    var q = parseQuery();

    // ── Build PORTAL object ─────────────────────────────────────────────────
    var PORTAL = {
        external: isExternal,
        mac:      q.mac   || "",
        ip:       q.ip    || "",
        user:     q.user  || "",
        login:    q.login || "",   // router login URL (for external PAP)
        logout:   q.logout || "",
        dst:      q.dst   || "",
        error:    q.err   || "",
        chapId:    "",
        chapChallenge: ""
    };

    // ── Helper URL builders ─────────────────────────────────────────────────
    PORTAL.statusUrl = function () {
        return "status.html?mac=" + encodeURIComponent(PORTAL.mac)
             + "&ip="   + encodeURIComponent(PORTAL.ip)
             + "&user=" + encodeURIComponent(PORTAL.user);
    };

    PORTAL.loginUrl = function () {
        return PORTAL.login || "login.html";
    };

    // ── DOM patching (external mode only) ───────────────────────────────────
    if (isExternal) {
        // Fill in hidden form fields that the router would have set
        var tokenMap = {
            "mac":       PORTAL.mac,
            "ip":        PORTAL.ip,
            "username":  PORTAL.user,
            "link-login-only": PORTAL.login,
            "link-logout":     PORTAL.logout,
            "link-orig":       PORTAL.dst,
            "error":           PORTAL.error
        };

        // Replace $(var) in form actions and hidden field values
        var forms = document.querySelectorAll("form");
        for (var i = 0; i < forms.length; i++) {
            var action = forms[i].getAttribute("action") || "";
            if (action.indexOf("$(link-login-only)") !== -1) {
                forms[i].setAttribute("action", PORTAL.login);
            }
        }

        // Patch hidden inputs whose name matches a known token
        var inputs = document.querySelectorAll("input[type=hidden]");
        for (var j = 0; j < inputs.length; j++) {
            var name = inputs[j].getAttribute("name") || "";
            if (tokenMap.hasOwnProperty(name)) {
                inputs[j].setAttribute("value", tokenMap[name]);
            }
        }

        // Strip $(if chap-id)...$(endif) blocks — they contain CHAP-only content
        // that should not appear in external mode
        var ifBlocks = document.body.innerHTML;
        // Remove $(if chap-id)...$(endif) sections (CHAP-only blocks)
        ifBlocks = ifBlocks.replace(/\$\(if chap-id\)[\s\S]*?\$\(endif\)/gi, "");
        // Remove remaining $(if ...) / $(endif) wrappers but keep inner content
        ifBlocks = ifBlocks.replace(/\$\(if [^)]*\)/gi, "");
        ifBlocks = ifBlocks.replace(/\$\(endif\)/gi, "");
        document.body.innerHTML = ifBlocks;
    } else {
        // Router-native mode: extract chap-id and chap-challenge from hidden fields
        var chapIdEl = document.querySelector("input[name=chap-id]");
        var chapChEl = document.querySelector("input[name=chap-challenge]");
        if (chapIdEl) PORTAL.chapId = chapIdEl.getAttribute("value") || "";
        if (chapChEl) PORTAL.chapChallenge = chapChEl.getAttribute("value") || "";
    }

    // ── Expose globally ─────────────────────────────────────────────────────
    window.PORTAL = PORTAL;
})();
