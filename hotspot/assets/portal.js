/* AIRCOINS NETFI — router-native captive portal logic.
 *
 * Served by RouterOS from flash/hotspot; the $(mac) token below is
 * expanded server-side before the browser ever sees this file. This
 * page IS the captive portal — no stub, no redirect to the SBC.
 *
 * Auth: PAP form post to $(link-login-only) (form #sendin in
 * login.html); the router forwards credentials to FreeRADIUS on the
 * SBC (use-radius=yes). Vouchers/MAC users are provisioned with
 * Cleartext-Password == username, so password mirrors username.
 *
 * Vendo list + promo rates come from the panel API over an ABSOLUTE
 * URL (the page origin is the router, not the SBC). The hotspot
 * walled garden must accept the LAN so these calls pass pre-login.
 */
(function () {
  'use strict';

  var SBC = 'http://10.0.0.252'; /* panel API origin, no trailing slash */

  /* RouterOS $(mac) arrives as AA:BB:CC:DD:EE:FF; the vendo API
   * contract wants 12 uppercase hex chars, separators stripped. */
  var MAC = '$(mac)'.replace(/[^0-9a-fA-F]/g, '').toUpperCase();

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

  /* ---- login submission: PAP post to the router's own login URL ------ */

  function submitLogin(username, password) {
    var f = $('sendin');
    f.elements.username.value = username;
    f.elements.password.value = password;
    f.submit();
  }

  /* ---- vendo devices + promo rates (panel API) ------------------------ */

  function loadVendo() {
    fetch(SBC + '/api/vendo', { cache: 'no-store' })
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
      coinStatus('Device MAC unavailable on this page.', true);
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
