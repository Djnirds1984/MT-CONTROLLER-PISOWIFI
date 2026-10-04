/* AIRCOINS NETFI admin panel (vanilla JS, no dependencies).
 *
 * Served by lighttpd under /admin; every API call is same-origin
 * /api/admin/*, proxied to the Go service. Mutating calls carry the
 * X-Aircoins-Auth header (the backend rejects cross-site forms without
 * it). All rendering goes through createElement/textContent — no
 * innerHTML, no user data in markup strings.
 */
(function () {
  'use strict';

  /* ------------------------------ helpers --------------------------- */

  function $(id) { return document.getElementById(id); }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  function clear(n) { while (n.firstChild) { n.removeChild(n.firstChild); } }

  function showBox(node, text, cls) {
    if (!text) { node.hidden = true; return; }
    node.hidden = false;
    node.textContent = text;
    node.className = 'adm-alert' + (cls ? ' ' + cls : '');
  }

  function toast(msg, isErr) {
    var t = $('toast');
    t.textContent = msg;
    t.hidden = false;
    t.classList.toggle('err', !!isErr);
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.hidden = true; }, 4000);
  }

  function fail(view, err) {
    toast(err.message || String(err), true);
    if (view) { showBox(view, err.message || String(err), 'err'); }
  }

  function fmtSec(sec) {
    sec = Number(sec) || 0;
    if (sec <= 0) { return '0m'; }
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var out = [];
    if (d) { out.push(d + 'd'); }
    if (h) { out.push(h + 'h'); }
    if (m || out.length === 0) { out.push(m + 'm'); }
    return out.join(' ');
  }

  function fmtSize(bytes) {
    if (bytes === null || bytes === undefined || bytes === '') { return '—'; }
    var n = Number(bytes);
    if (n < 1024) { return n + ' B'; }
    return (n / 1024).toFixed(1) + ' KB';
  }

  function busy(btn, on) {
    if (btn) { btn.disabled = !!on; }
  }

  var ticks = {};
  function setTick(key, fn, ms) { ticks[key] = setInterval(fn, ms); }
  function stopTicks() {
    Object.keys(ticks).forEach(function (k) {
      clearInterval(ticks[k]);
      delete ticks[k];
    });
  }

  /* ------------------------------- API ------------------------------ */

  function api(path, opts) {
    opts = opts || {};
    var init = { method: opts.method || 'GET', headers: {} };
    if (opts.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }
    if (init.method !== 'GET') { init.headers['X-Aircoins-Auth'] = '1'; }
    return fetch(path, init).then(function (r) {
      if (r.status === 401) {
        enterLogin();
        throw new Error('Session ended — sign in again.');
      }
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || d.ok === false) {
          throw new Error(d.error || ('HTTP ' + r.status));
        }
        return d;
      });
    });
  }

  /* ---------------------------- auth flow --------------------------- */

  function enterLogin() {
    stopTicks();
    $('appView').hidden = true;
    $('loginView').hidden = false;
  }

  function enterApp(who) {
    $('loginView').hidden = true;
    $('appView').hidden = false;
    $('adminWho').textContent = who;
    if (!location.hash) { location.hash = '#/dashboard'; }
    route();
  }

  /* --------------------------- hash router -------------------------- */

  var LOADERS = {
    dashboard: loadDashboard,
    vouchers: loadVouchers,
    users: loadUsers,
    vendo: loadVendo,
    sessions: loadSessions,
    router: loadRouter,
    settings: loadSettings,
    tools: loadTools
  };

  function route() {
    var name = (location.hash || '').replace(/^#\/?/, '') || 'dashboard';
    if (!LOADERS[name]) { name = 'dashboard'; }
    Array.prototype.forEach.call($('nav').querySelectorAll('a'), function (a) {
      a.classList.toggle('active', a.getAttribute('data-view') === name);
    });
    Array.prototype.forEach.call(document.querySelectorAll('.adm-view'), function (v) {
      v.hidden = v.id !== 'view-' + name;
    });
    stopTicks();
    LOADERS[name]();
  }

  /* ---------------------------- dashboard --------------------------- */

  function loadDashboard() {
    refreshOverview();
    setTick('dashboard', refreshOverview, 30000);
  }

  function refreshOverview() {
    api('/api/admin/overview').then(function (d) {
      var grid = $('statGrid');
      clear(grid);
      [
        ['Vouchers', d.vouchers],
        ['RADIUS users', d.radius_users],
        ['Online now', d.router_configured ? d.online_sessions : '—'],
        ['Coins today', d.coins_today],
        ['Minutes today', d.minutes_today]
      ].forEach(function (pair) {
        var card = el('div', 'adm-stat');
        card.appendChild(el('span', null, pair[0]));
        card.appendChild(el('b', null, String(pair[1] === undefined ? '—' : pair[1])));
        grid.appendChild(card);
      });
      showBox($('dashNote'), d.router_configured ? '' :
        'Router not configured — set it in Settings, then run the MikroTik setup page.', 'warn');
      var body = $('recentBody');
      clear(body);
      var rows = d.recent_coins || [];
      if (!rows.length) {
        body.appendChild(el('tr').appendChild(el('td', null, 'No coins recorded yet.')).parentNode);
      }
      rows.forEach(function (r) {
        var tr = el('tr');
        tr.appendChild(el('td', null, r.mac));
        tr.appendChild(el('td', null, String(r.coins)));
        tr.appendChild(el('td', null, fmtSec(r.minutes * 60)));
        tr.appendChild(el('td', null, r.created_at));
        body.appendChild(tr);
      });
    }).catch(function (e) { fail(null, e); });
  }

  /* ---------------------------- vouchers ---------------------------- */

  var vState = { filter: 'all', q: '', list: [] };

  function loadVouchers() {
    vState = { filter: $('vFilter').value, q: $('vSearch').value.trim().toUpperCase(), list: [] };
    renderVouchers();
    api('/api/admin/vouchers').then(function (d) {
      vState.list = d.vouchers || [];
      renderVouchers();
    }).catch(function (e) { fail(null, e); });
  }

  function renderVouchers() {
    var body = $('vBody');
    clear(body);
    var shown = 0;
    vState.list.forEach(function (v) {
      if (vState.filter === 'used' && !v.used) { return; }
      if (vState.filter === 'unused' && v.used) { return; }
      if (vState.q && v.code.toUpperCase().indexOf(vState.q) === -1) { return; }
      if (shown >= 200) { return; }
      shown++;
      var tr = el('tr');
      var tdC = el('td');
      tdC.appendChild(el('b', null, v.code));
      tr.appendChild(tdC);
      tr.appendChild(el('td', null, fmtSec(v.minutes * 60)));
      var tdS = el('td');
      tdS.appendChild(el('span', 'adm-badge ' + (v.used ? 'warn' : 'ok'), v.used ? 'USED' : 'UNUSED'));
      tr.appendChild(tdS);
      tr.appendChild(el('td', null, v.first_use || '—'));
      tr.appendChild(el('td', null, String(v.sessions)));
      tr.appendChild(el('td', null, v.batch || '—'));
      var tdA = el('td');
      var del = el('button', 'adm-btn small danger', 'Delete');
      del.type = 'button';
      del.addEventListener('click', function () {
        if (!window.confirm('Delete voucher ' + v.code + '? Its RADIUS credential is removed too.')) { return; }
        busy(del, true);
        api('/api/admin/vouchers/delete', { method: 'POST', body: { code: v.code } })
          .then(function () { toast('Voucher deleted.'); loadVouchers(); })
          .catch(function (e) { busy(del, false); fail(null, e); });
      });
      tdA.appendChild(del);
      tr.appendChild(tdA);
      body.appendChild(tr);
    });
    $('vNote').textContent = vState.list.length > 500
      ? 'Showing the newest 500 of ' + vState.list.length + ' vouchers.'
      : (shown + ' shown.');
  }

  function generateVouchers() {
    var count = parseInt($('vgCount').value, 10);
    var minutes = parseInt($('vgMinutes').value, 10);
    var prefix = $('vgPrefix').value.trim().toUpperCase();
    if (!count || count < 1 || count > 500) { showBox($('vgErr'), 'Count must be 1..500.', 'err'); return; }
    if (!minutes || minutes < 5 || minutes > 43200) { showBox($('vgErr'), 'Minutes must be 5..43200.', 'err'); return; }
    busy($('vgBtn'), true);
    showBox($('vgErr'), '');
    api('/api/admin/vouchers', {
      method: 'POST',
      body: { count: count, minutes: minutes, prefix: prefix }
    }).then(function (d) {
      busy($('vgBtn'), false);
      $('genBatch').textContent = d.batch;
      $('genCount').textContent = d.generated;
      $('genCodes').textContent = (d.codes || []).join('\n');
      $('genCodes').dataset.text = (d.codes || []).join('\n');
      $('genBox').hidden = false;
      toast('Generated ' + d.generated + ' codes.');
      loadVouchers();
    }).catch(function (e) { busy($('vgBtn'), false); showBox($('vgErr'), e.message, 'err'); });
  }

  function copyCodes() {
    var text = $('genCodes').dataset.text || '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { toast('Copied.'); }, function () { toast('Copy failed.', true); });
      return;
    }
    var ta = el('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); toast('Copied.'); } catch (e) { toast('Copy failed.', true); }
    document.body.removeChild(ta);
  }

  function downloadCodes() {
    var text = $('genCodes').dataset.text || '';
    var blob = new Blob([text + '\n'], { type: 'text/plain' });
    var a = el('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'aircoins-' + $('genBatch').textContent + '.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
  }

  /* ------------------------------ users ----------------------------- */

  function loadUsers() {
    var q = $('uSearch').value.trim();
    var path = '/api/admin/users' + (q ? '?filter=' + encodeURIComponent('*' + q + '*') : '');
    api(path).then(function (d) { renderUsers(d.users || []); })
      .catch(function (e) { fail(null, e); });
  }

  function renderUsers(users) {
    var body = $('uBody');
    clear(body);
    if (!users.length) {
      var tr0 = el('tr');
      tr0.appendChild(el('td', null, 'No RADIUS users match.'));
      body.appendChild(tr0);
    }
    users.forEach(function (u) {
      var tr = el('tr');
      var tdU = el('td');
      tdU.appendChild(el('b', null, u.username));
      tr.appendChild(tdU);
      var tdK = el('td');
      tdK.appendChild(el('span', 'adm-badge info', u.kind || 'user'));
      tr.appendChild(tdK);
      tr.appendChild(el('td', null, u.password || '—'));
      tr.appendChild(el('td', null, fmtSec(u.seconds)));
      tr.appendChild(el('td', null, String(u.sessions || 0)));
      tr.appendChild(el('td', null, u.first_use || '—'));
      var tdA = el('td');
      var del = el('button', 'adm-btn small danger', 'Delete');
      del.type = 'button';
      del.addEventListener('click', function () {
        if (!window.confirm('Delete user ' + u.username + ' from RADIUS?')) { return; }
        busy(del, true);
        api('/api/admin/users/delete', { method: 'POST', body: { username: u.username } })
          .then(function () { toast('User deleted.'); loadUsers(); })
          .catch(function (e) { busy(del, false); fail(null, e); });
      });
      tdA.appendChild(del);
      tr.appendChild(tdA);
      body.appendChild(tr);
    });
    $('uNote').textContent = 'MAC users log in with their device address as both username and password.';
  }

  function saveUser() {
    var username = $('uUser').value.trim();
    var minutes = parseInt($('uMinutes').value, 10);
    if (!username) { showBox($('uErr'), 'Username is required.', 'err'); return; }
    if (!minutes || minutes < 5 || minutes > 43200) { showBox($('uErr'), 'Minutes must be 5..43200.', 'err'); return; }
    busy($('uSave'), true);
    showBox($('uErr'), '');
    api('/api/admin/users/save', {
      method: 'POST',
      body: {
        username: username,
        password: $('uPass').value,
        minutes: minutes,
        extend: $('uExtend').checked
      }
    }).then(function (d) {
      busy($('uSave'), false);
      toast('Saved ' + d.username + '.');
      $('uUser').value = '';
      $('uPass').value = '';
      loadUsers();
    }).catch(function (e) { busy($('uSave'), false); showBox($('uErr'), e.message, 'err'); });
  }

  /* ------------------------------ vendo ----------------------------- */

  function loadVendo() {
    api('/api/admin/vendo').then(function (d) { renderVendo(d.devices || []); })
      .catch(function (e) { fail(null, e); });
  }

  function renderVendo(devices) {
    var list = $('vendoList');
    clear(list);
    if (!devices.length) {
      var empty = el('div', 'adm-card');
      empty.appendChild(el('p', 'adm-hint', 'No vendo machines yet — click "Add device".'));
      list.appendChild(empty);
      return;
    }
    devices.forEach(function (d) { list.appendChild(vendoCard(d)); });
  }

  function vendoCard(d) {
    d.rates = d.rates || [];
    var card = el('div', 'adm-vendo');
    var head = el('h3', null, '#' + d.id + ' ' + d.name + (d.enabled ? '' : '  [disabled]'));
    card.appendChild(head);

    var grid = el('div', 'adm-grid');
    function field(label, value, id) {
      var wrap = el('div');
      var lab = el('label', 'adm-field', label);
      var input = el('input');
      input.id = id;
      input.value = value === null || value === undefined ? '' : value;
      lab.appendChild(input);
      wrap.appendChild(lab);
      return wrap;
    }
    grid.appendChild(field('Name', d.name, 'vd_name_' + d.id));
    grid.appendChild(field('Location', d.location, 'vd_loc_' + d.id));
    grid.appendChild(field('NodeMCU API URL', d.api_url, 'vd_api_' + d.id));
    grid.appendChild(field('Minutes per coin pulse (fallback)', d.minutes_per_pulse, 'vd_mpp_' + d.id));
    card.appendChild(grid);

    var en = el('label', 'adm-check');
    var enBox = el('input');
    enBox.type = 'checkbox';
    enBox.id = 'vd_en_' + d.id;
    enBox.checked = !!d.enabled;
    en.appendChild(enBox);
    en.appendChild(el('span', null, 'Enabled (visible on the portal)'));
    card.appendChild(en);

    var btns = el('div', 'adm-toolbar');
    var save = el('button', 'adm-btn small', 'Save device');
    save.type = 'button';
    save.addEventListener('click', function () {
      busy(save, true);
      api('/api/admin/vendo/save', {
        method: 'POST',
        body: {
          id: d.id,
          name: $('vd_name_' + d.id).value.trim(),
          location: $('vd_loc_' + d.id).value.trim(),
          api_url: $('vd_api_' + d.id).value.trim(),
          minutes_per_pulse: parseInt($('vd_mpp_' + d.id).value, 10) || 15,
          enabled: $('vd_en_' + d.id).checked
        }
      }).then(function () { busy(save, false); toast('Device saved.'); loadVendo(); })
        .catch(function (e) { busy(save, false); fail(null, e); });
    });
    btns.appendChild(save);

    var del = el('button', 'adm-btn small danger', 'Delete device');
    del.type = 'button';
    del.addEventListener('click', function () {
      if (!window.confirm('Delete vendo #' + d.id + ' and its rates?')) { return; }
      busy(del, true);
      api('/api/admin/vendo/delete', { method: 'POST', body: { id: d.id } })
        .then(function () { toast('Device deleted.'); loadVendo(); })
        .catch(function (e) { busy(del, false); fail(null, e); });
    });
    btns.appendChild(del);
    card.appendChild(btns);

    // rates editor
    var rateHead = el('h3', null, 'Promo rates (shown on the portal)');
    card.appendChild(rateHead);
    var rateBox = el('div');
    function rateRow(rt) {
      var row = el('div', 'adm-rate-row');
      var c = el('input');
      c.type = 'number';
      c.min = '1';
      c.placeholder = 'coins';
      c.value = rt ? rt.coins : '';
      var m = el('input');
      m.type = 'number';
      m.min = '1';
      m.placeholder = 'minutes';
      m.value = rt ? rt.minutes : '';
      var rm = el('button', 'adm-btn small danger', 'x');
      rm.type = 'button';
      rm.addEventListener('click', function () { row.parentNode.removeChild(row); });
      row.appendChild(c);
      row.appendChild(m);
      row.appendChild(rm);
      return row;
    }
    d.rates.forEach(function (rt) { rateBox.appendChild(rateRow(rt)); });
    card.appendChild(rateBox);

    var rateBtns = el('div', 'adm-toolbar');
    var addRate = el('button', 'adm-btn small', 'Add rate');
    addRate.type = 'button';
    addRate.addEventListener('click', function () { rateBox.appendChild(rateRow(null)); });
    rateBtns.appendChild(addRate);

    var saveRates = el('button', 'adm-btn small', 'Save rates');
    saveRates.type = 'button';
    saveRates.addEventListener('click', function () {
      var rates = [];
      var bad = null;
      Array.prototype.forEach.call(rateBox.children, function (row) {
        var ins = row.querySelectorAll('input');
        var c = parseInt(ins[0].value, 10);
        var m = parseInt(ins[1].value, 10);
        if (!c || !m) { bad = 'Every rate needs coins and minutes.'; return; }
        rates.push({ coins: c, minutes: m });
      });
      if (bad) { fail(null, new Error(bad)); return; }
      busy(saveRates, true);
      api('/api/admin/vendo/rates', {
        method: 'POST',
        body: { vendo_id: d.id, rates: rates }
      }).then(function () { busy(saveRates, false); toast('Rates saved.'); })
        .catch(function (e) { busy(saveRates, false); fail(null, e); });
    });
    rateBtns.appendChild(saveRates);
    card.appendChild(rateBtns);
    return card;
  }

  function addVendo() {
    api('/api/admin/vendo/save', {
      method: 'POST',
      body: { id: 0, name: 'New vendo', location: '', api_url: '', minutes_per_pulse: 15, enabled: false }
    }).then(function () { toast('Device added — fill in its details.'); loadVendo(); })
      .catch(function (e) { fail(null, e); });
  }

  /* ---------------------------- sessions ---------------------------- */

  function loadSessions() {
    refreshSessions();
    setTick('sessions', function () {
      if ($('sAuto').checked) { refreshSessions(); }
    }, 15000);
  }

  function refreshSessions() {
    api('/api/admin/sessions').then(function (d) {
      if (!d.router) {
        showBox($('sNote'), 'Router not configured — live sessions need the router REST URL.', 'warn');
        var empty = $('sBody');
        clear(empty);
        return;
      }
      showBox($('sNote'), '');
      var body = $('sBody');
      clear(body);
      var rows = d.sessions || [];
      if (!rows.length) {
        var tr0 = el('tr');
        tr0.appendChild(el('td', null, 'No active sessions.'));
        body.appendChild(tr0);
      }
      rows.forEach(function (s) {
        var tr = el('tr');
        tr.appendChild(el('td', null, s.user || '—'));
        tr.appendChild(el('td', null, s.address || '—'));
        tr.appendChild(el('td', null, s.mac || '—'));
        tr.appendChild(el('td', null, s.uptime || '—'));
        tr.appendChild(el('td', null, s.time_left || '—'));
        tr.appendChild(el('td', null, s.server || '—'));
        var tdA = el('td');
        var kick = el('button', 'adm-btn small danger', 'Kick');
        kick.type = 'button';
        kick.addEventListener('click', function () {
          if (!window.confirm('Disconnect ' + (s.user || s.address) + '?')) { return; }
          busy(kick, true);
          api('/api/admin/kick', { method: 'POST', body: { id: s.id } })
            .then(function () { toast('Disconnected.'); refreshSessions(); })
            .catch(function (e) { busy(kick, false); fail(null, e); });
        });
        tdA.appendChild(kick);
        tr.appendChild(tdA);
        body.appendChild(tr);
      });
    }).catch(function (e) { fail(null, e); });
  }

  /* ------------------------ mikrotik configurator ------------------- */

  var mtData = null;

  function loadRouter() {
    api('/api/admin/mikrotik/status').then(function (d) {
      mtData = d;
      renderMT(d);
    }).catch(function (e) { fail(null, e); });
  }

  function renderMT(d) {
    var box = $('mtChecks');
    clear(box);
    box.appendChild(el('h3', null, 'Current router state'));
    if (!d.router_configured) {
      var p = el('p', 'adm-hint', 'Set router REST URL, user and password in Settings first.');
      box.appendChild(p);
    }
    (d.checks || []).forEach(function (c) {
      var row = el('div', 'adm-check-row');
      row.appendChild(el('span', 'adm-badge ' + (c.ok ? 'ok' : 'bad'), c.ok ? 'OK' : 'FIX'));
      var div = el('div');
      div.appendChild(el('b', null, c.label));
      div.appendChild(el('small', null, c.detail || ''));
      if (c.warn) { div.appendChild(el('small', 'warn', 'Note: ' + c.warn)); }
      row.appendChild(div);
      box.appendChild(row);
    });

    // interface dropdown: prefer our profile's server, then any live server
    var sel = $('mtIface');
    var prev = sel.value;
    clear(sel);
    var chosen = '';
    (d.servers || []).forEach(function (s) {
      if (!chosen && !s.disabled && s.profile === 'aircoins') { chosen = s.interface; }
    });
    if (!chosen) {
      (d.servers || []).forEach(function (s) { if (!chosen && !s.disabled) { chosen = s.interface; } });
    }
    var ifaces = d.interfaces || [];
    if (!ifaces.length) {
      var o0 = el('option');
      o0.value = '';
      o0.textContent = d.router_configured ? '(no interfaces listed)' : '(configure router first)';
      sel.appendChild(o0);
    }
    ifaces.forEach(function (f) {
      var o = el('option');
      o.value = f.name;
      o.textContent = f.name + (f.type ? ' (' + f.type + ')' : '') + (f.running ? '' : ' [down]');
      if (f.name === chosen || f.name === prev) { o.selected = true; }
      sel.appendChild(o);
    });

    // form prefill (never overwrite what the operator is typing)
    if (!$('mtIP').value) {
      $('mtIP').value = d.saved.sbc_ip || d.sbc.ip || '';
    }
    if (!$('mtMac').value) {
      $('mtMac').value = d.saved.sbc_mac || d.sbc.mac || '';
    }
    var secHint = d.saved.radius_secret_set
      ? 'A secret is already stored — reuse it (see Settings) or generate a new one (update clients.conf too).'
      : '';
    $('mtApply').title = secHint;
  }

  function genSecret() {
    var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    var out = '';
    var rnd = new Uint8Array(24);
    if (window.crypto && window.crypto.getRandomValues) {
      window.crypto.getRandomValues(rnd);
    } else {
      for (var i = 0; i < rnd.length; i++) { rnd[i] = Math.floor(Math.random() * 256); }
    }
    for (var j = 0; j < rnd.length; j++) { out += alphabet.charAt(rnd[j] % alphabet.length); }
    $('mtSecret').value = out;
  }

  function applyMT() {
    var body = {
      sbc_ip: $('mtIP').value.trim(),
      secret: $('mtSecret').value,
      sbc_mac: $('mtMac').value.trim(),
      interface: $('mtIface').value,
      create_server: $('mtCreate').checked
    };
    if (!/^\d{1,3}(\.\d{1,3}){3}$/.test(body.sbc_ip)) {
      showBox($('mtErr'), 'Enter the SBC IPv4 address (e.g. 10.0.0.252).', 'err');
      return;
    }
    if (body.secret.length < 8 || /\s/.test(body.secret)) {
      showBox($('mtErr'), 'Secret must be at least 8 chars without spaces.', 'err');
      return;
    }
    if (!body.interface) {
      showBox($('mtErr'), 'Pick the hotspot interface (Re-check first).', 'err');
      return;
    }
    if (!window.confirm('Apply the aircoins hotspot/RADIUS configuration to the router?')) { return; }
    busy($('mtApply'), true);
    showBox($('mtErr'), '');
    api('/api/admin/mikrotik/apply', { method: 'POST', body: body }).then(function (d) {
      busy($('mtApply'), false);
      var box = $('mtResults');
      clear(box);
      box.appendChild(el('h3', null, 'Apply results'));
      var bad = 0;
      (d.results || []).forEach(function (r) {
        bad += r.ok ? 0 : 1;
        var row = el('div', 'adm-check-row');
        row.appendChild(el('span', 'adm-badge ' + (r.ok ? 'ok' : 'bad'), r.ok ? 'OK' : 'FAIL'));
        var div = el('div');
        div.appendChild(el('b', null, r.label));
        if (r.detail) { div.appendChild(el('small', null, r.detail)); }
        if (r.error) { div.appendChild(el('small', 'warn', r.error)); }
        row.appendChild(div);
        box.appendChild(row);
      });
      box.hidden = false;
      toast(bad ? bad + ' step(s) failed — see results.' : 'Router configuration applied.', !!bad);
      setTimeout(loadRouter, 800);
    }).catch(function (e) { busy($('mtApply'), false); showBox($('mtErr'), e.message, 'err'); });
  }

  /* ---------------------------- settings ---------------------------- */

  var ST_KEYS = ['portal_name', 'sbc_url', 'router_url', 'router_user',
    'router_pass', 'radius_secret', 'rate_limit', 'idle_timeout', 'interim'];

  function loadSettings() {
    api('/api/admin/settings').then(function (d) {
      var s = d.settings || {};
      ST_KEYS.forEach(function (k) {
        var input = $('st_' + k);
        if (input) { input.value = s[k] || ''; }
      });
      var chips = $('stGr');
      clear(chips);
      var gr = d.group_replies || {};
      Object.keys(gr).forEach(function (attr) {
        chips.appendChild(el('span', 'adm-badge info', attr + ' = ' + gr[attr]));
      });
      if (!Object.keys(gr).length) {
        chips.appendChild(el('span', 'adm-badge warn', 'no group replies yet — press Save to push them'));
      }
    }).catch(function (e) { fail(null, e); });
  }

  function saveSettings() {
    var body = {};
    ST_KEYS.forEach(function (k) {
      var input = $('st_' + k);
      if (input) { body[k] = input.value.trim(); }
    });
    busy($('stSave'), true);
    api('/api/admin/settings', { method: 'POST', body: body }).then(function () {
      busy($('stSave'), false);
      toast('Settings saved — RADIUS group attributes pushed.');
      loadSettings();
    }).catch(function (e) { busy($('stSave'), false); fail(null, e); });
  }

  /* ------------------------------ tools ----------------------------- */

  function loadTools() {
    api('/api/admin/tools/stubs').then(function (d) {
      showBox($('stubNote'), d.router_error ? ('Router: ' + d.router_error) :
        (d.router ? '' : 'Configure the router in Settings to upload stubs.'), 'warn');
      var body = $('stubList');
      clear(body);
      (d.stubs || []).forEach(function (st) {
        var tr = el('tr');
        var tdN = el('td');
        tdN.appendChild(el('b', null, st.name));
        tr.appendChild(tdN);
        var tdS = el('td');
        var cls = st.status === 'ok' ? 'ok' : (st.status === 'too_large' ? 'warn' : 'bad');
        tdS.appendChild(el('span', 'adm-badge ' + cls, st.status.toUpperCase()));
        tr.appendChild(tdS);
        tr.appendChild(el('td', null, fmtSize(st.local_size)));
        tr.appendChild(el('td', null, fmtSize(st.remote_size)));
        var tdA = el('td');
        var up = el('button', 'adm-btn small', 'Upload');
        up.type = 'button';
        up.addEventListener('click', function () { uploadStubs([st.name], up); });
        tdA.appendChild(up);
        tr.appendChild(tdA);
        body.appendChild(tr);
      });
    }).catch(function (e) { fail(null, e); });
  }

  function uploadStubs(names, btn) {
    busy(btn, true);
    api('/api/admin/tools/stubs/upload', { method: 'POST', body: { names: names } })
      .then(function (d) {
        busy(btn, false);
        var bad = 0;
        (d.results || []).forEach(function (r) {
          if (!r.ok) { bad++; fail(null, new Error(r.name + ': ' + (r.error || 'failed'))); }
        });
        if (!bad) { toast('Stubs uploaded and verified on the router.'); }
        loadTools();
      })
      .catch(function (e) { busy(btn, false); fail(null, e); });
  }

  /* ------------------------------- init ----------------------------- */

  document.addEventListener('DOMContentLoaded', function () {
    $('loginForm').addEventListener('submit', function (ev) {
      ev.preventDefault();
      api('/api/admin/login', {
        method: 'POST',
        body: { username: $('loginUser').value.trim(), password: $('loginPass').value }
      }).then(function (d) {
        try { localStorage.setItem('aircoins_admin', d.username); } catch (e) { /* private mode */ }
        $('loginPass').value = '';
        enterApp(d.username);
      }).catch(function (e) { showBox($('loginErr'), e.message, 'err'); });
    });

    $('logoutBtn').addEventListener('click', function () {
      api('/api/admin/logout', { method: 'POST' })
        .catch(function () { /* already gone */ })
        .then(function () {
          try { localStorage.removeItem('aircoins_admin'); } catch (e) { /* ignore */ }
          enterLogin();
        });
    });

    $('dashRefresh').addEventListener('click', refreshOverview);

    $('vgBtn').addEventListener('click', generateVouchers);
    $('genCopy').addEventListener('click', copyCodes);
    $('genDl').addEventListener('click', downloadCodes);
    $('vFilter').addEventListener('change', function () { vState.filter = this.value; renderVouchers(); });
    $('vSearch').addEventListener('input', function () {
      vState.q = this.value.trim().toUpperCase();
      renderVouchers();
    });
    $('vRefresh').addEventListener('click', loadVouchers);

    $('uSave').addEventListener('click', saveUser);
    $('uRefresh').addEventListener('click', loadUsers);
    $('uSearch').addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { loadUsers(); }
    });

    $('dvAdd').addEventListener('click', addVendo);

    $('sRefresh').addEventListener('click', refreshSessions);
    $('sAuto').addEventListener('change', refreshSessions);

    $('mtRefresh').addEventListener('click', loadRouter);
    $('mtGen').addEventListener('click', genSecret);
    $('mtApply').addEventListener('click', applyMT);

    $('stSave').addEventListener('click', saveSettings);

    $('stubAll').addEventListener('click', function () { uploadStubs([], this); });

    window.addEventListener('hashchange', route);

    // probe the session cookie before showing anything
    api('/api/admin/overview').then(function () {
      var who = 'admin';
      try { who = localStorage.getItem('aircoins_admin') || 'admin'; } catch (e) { /* ignore */ }
      enterApp(who);
    }).catch(function () { enterLogin(); });
  });
})();
