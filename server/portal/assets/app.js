/* AIRCOINS NETFI portal logic (vanilla JS, no dependencies).
 *
 * Page context arrives via query params written by the router-side
 * redirector stubs: mac, ip, error, link-login (older aliases: login,
 * link-login-only), dst. There are deliberately NO RouterOS $(var)
 * template tags in this tree — the portal is served by lighttpd on
 * the SBC.
 */
(function () {
  'use strict';

  var Q = new URLSearchParams(location.search);
  var MAC = (Q.get('mac') || '').replace(/[^0-9a-fA-F]/g, '').toUpperCase();
  var IP = Q.get('ip') || '';
  var LOGIN_URL = normalizeLoginURL(
    Q.get('link-login') || Q.get('login') || Q.get('link-login-only'));
  var ERROR = Q.get('error') || '';
  var LOGGED_OUT = Q.get('logged_out') === '1';

  var devices = [];

  function $(id) { return document.getElementById(id); }

  function show(node, text, isErr) {
    node.hidden = false;
    node.textContent = text;
    node.classList.toggle('err', !!isErr);
  }

  function coinStatus(text, isErr) {
    var el = $('coinStatus');
    if (!text) { el.hidden = true; return; }
    show(el, text, isErr);
  }

  /* ---- login submission: real top-level form POST (router PAP) ------- */

  /* normalizeLoginURL validates the router login URL arriving in the
   * query string (link-login, or the older login / link-login-only
   * aliases) and returns scheme://host:port/path with any query and
   * fragment dropped: link-login ships as the full ".../login?dst=..."
   * URL, but the form action must be the bare login endpoint. Returns
   * '' when the value is unusable. */
  function normalizeLoginURL(raw) {
    raw = (raw || '').trim();
    if (!/^https?:\/\//i.test(raw)) { return ''; }
    try {
      var u = new URL(raw);
      return u.origin + u.pathname;
    } catch (e) {
      return raw;
    }
  }

  /* statusDst is the post-login landing page: the SBC session status
   * page, carrying mac/ip so it renders device details immediately.
   * The router sends the browser here after a successful login. */
  function statusDst() {
    var dst = location.origin + '/status.html';
    var extra = [];
    if (MAC) { extra.push('mac=' + encodeURIComponent(MAC)); }
    if (IP) { extra.push('ip=' + encodeURIComponent(IP)); }
    if (extra.length) { dst += '?' + extra.join('&'); }
    return dst;
  }

  /* primeForm fills the hidden sendin form on page load so the action
   * (router login URL) and the dst/mac fields are already populated
   * when inspected in the browser, before the first submit. */
  function primeForm() {
    var f = $('sendin');
    if (LOGIN_URL) { f.action = LOGIN_URL; }
    f.elements.dst.value = statusDst();
    var m = f.elements.mac;
    if (m) { m.value = MAC; }
  }

  function submitLogin(username, password) {
    if (!LOGIN_URL) {
      show($('errBox'), 'Login URL missing. Open this page from the WiFi portal.', true);
      return;
    }
    var f = $('sendin');
    f.action = LOGIN_URL;
    f.elements.username.value = username;
    /* Password mirrors the username: RADIUS vouchers and MAC users are
     * provisioned with Cleartext-Password == username, so an empty
     * password would be rejected by FreeRADIUS. */
    f.elements.password.value = password;
    f.elements.dst.value = statusDst();
    var m = f.elements.mac;
    if (m) { m.value = MAC; }
    f.submit();
  }

  /* ---- vendo devices + promo rates ----------------------------------- */

  function loadVendo() {
    fetch('/api/vendo', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.portal_name) {
          $('brand').textContent = d.portal_name;
          document.title = d.portal_name;
        }
        devices = d.devices || [];
        renderRates();
        renderDeviceSelect();
      })
      .catch(function () {
        var body = $('ratesBody');
        body.textContent = '';
        var tr = document.createElement('tr');
        var td = document.createElement('td');
        td.textContent = 'Rates unavailable right now.';
        tr.appendChild(td);
        body.appendChild(tr);
        var sel = $('vendoSelect');
        sel.textContent = '';
        var opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'No vendo available';
        sel.appendChild(opt);
      });
  }

  function renderRates() {
    var body = $('ratesBody');
    body.textContent = '';
    var rows = [];
    devices.forEach(function (d) {
      (d.rates || []).forEach(function (rt) {
        rows.push({ coins: rt.coins, minutes: rt.minutes });
      });
    });
    if (!rows.length) {
      devices.forEach(function (d) {
        rows.push({ coins: 1, minutes: d.minutes_per_pulse });
      });
    }
    if (!rows.length) {
      var tr0 = document.createElement('tr');
      var td0 = document.createElement('td');
      td0.textContent = 'No rates configured.';
      tr0.appendChild(td0);
      body.appendChild(tr0);
      return;
    }
    rows.forEach(function (r) {
      var tr = document.createElement('tr');
      var tdL = document.createElement('td');
      tdL.textContent = r.coins + (r.coins > 1 ? ' coins' : ' coin');
      var tdR = document.createElement('td');
      tdR.className = 'piso-amt';
      tdR.textContent = formatMinutes(r.minutes);
      tr.appendChild(tdL);
      tr.appendChild(tdR);
      body.appendChild(tr);
    });
  }

  function renderDeviceSelect() {
    var sel = $('vendoSelect');
    sel.textContent = '';
    if (!devices.length) {
      var opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'No vendo available';
      sel.appendChild(opt);
      return;
    }
    devices.forEach(function (d) {
      var opt = document.createElement('option');
      opt.value = String(d.id);
      opt.textContent = d.name + (d.location ? ' - ' + d.location : '');
      opt.dataset.idx = String(devices.indexOf(d));
      sel.appendChild(opt);
    });
  }

  function formatMinutes(m) {
    if (m >= 60 && m % 60 === 0) { return (m / 60) + ' hr'; }
    if (m >= 60) { return Math.floor(m / 60) + 'h ' + (m % 60) + 'm'; }
    return m + ' min';
  }

  /* ---- coin insertion (NodeMCU contract) ------------------------------ */

  function insertCoin() {
    var btn = $('insertBtn');
    var sel = $('vendoSelect');
    var dev = null;
    devices.forEach(function (d) {
      if (String(d.id) === sel.value) { dev = d; }
    });
    if (!dev || !dev.api_url) {
      coinStatus('Select a vendo machine first.', true);
      return;
    }
    if (!MAC || MAC.length !== 12) {
      coinStatus('Device MAC missing from portal link. Reopen the portal from WiFi.', true);
      return;
    }
    btn.disabled = true;
    coinStatus('Waiting for coin... (insert within 30s)', false);

    var ctrl = new AbortController();
    var timer = setTimeout(function () { ctrl.abort(); }, 35000);

    fetch(dev.api_url + '/insertCoin.php?mac=' + encodeURIComponent(MAC), {
      method: 'POST',
      signal: ctrl.signal
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        clearTimeout(timer);
        if (data.status === 'true') {
          coinStatus('Added ' + (data.time_added || 'time') + ' - reconnecting...', false);
          submitLogin(MAC, MAC); // auto MAC login picks up the new budget
        } else if (data.error === 'no_coin') {
          coinStatus('No coin inserted. Try again.', true);
        } else {
          coinStatus('Vendo error: ' + (data.error || 'unknown'), true);
        }
      })
      .catch(function () {
        clearTimeout(timer);
        coinStatus('Vendo machine not reachable.', true);
      })
      .finally(function () { btn.disabled = false; });
  }

  /* ---- init ------------------------------------------------------------ */

  document.addEventListener('DOMContentLoaded', function () {
    primeForm();
    if (LOGGED_OUT) {
      show($('infoBox'), 'You are logged out. Buy a new code or insert coins to reconnect.', false);
    }
    if (ERROR) {
      var msg = ERROR;
      try { msg = decodeURIComponent(ERROR); } catch (e) { /* keep raw */ }
      show($('errBox'), msg, true);
    }

    var info = [];
    if (MAC && MAC.length === 12) {
      var pretty = MAC.replace(/(..)(?=.)/g, '$1:');
      info.push('Device: ' + pretty);
    }
    if (IP) { info.push('IP: ' + IP); }
    if (info.length) { $('clientInfo').textContent = info.join(' | '); }

    $('connectBtn').addEventListener('click', function () {
      var code = $('codeInput').value.trim();
      if (!code) {
        show($('errBox'), 'Enter a voucher code first.', true);
        return;
      }
      submitLogin(code, code);
    });
    $('codeInput').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { $('connectBtn').click(); }
    });
    $('insertBtn').addEventListener('click', insertCoin);

    loadVendo();
  });
})();
