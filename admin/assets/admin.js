/* ============================================================================
   AIRCOINS NETFI — admin panel behaviour
   Self-contained vanilla JS. No frameworks, no dependencies.
   Exposes a small window.AIRCOINS helper surface and wires:
     - dashboard monitor polling (api/monitor.php every 10s)
     - confirm dialogs for destructive actions
     - REST/Legacy auto-detect button (routers.php action=autodetect)
     - toast notifications
     - bulk voucher generator field logic
     - mobile nav, modals, tabs, flash dismissal, footer clock
   ========================================================================== */
(function () {
  'use strict';

  var POLL_MS = 10000;
  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ------------------------------------------------------------- helpers -- */
  function fmtBytes(n) {
    n = Number(n) || 0;
    var u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'], i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n.toFixed(0) : n.toFixed(n < 10 ? 2 : 1)) + ' ' + u[i];
  }
  function fmtRate(n) { return fmtBytes(n) + '/s'; }
  function fmtUptime(sec) {
    sec = Math.max(0, parseInt(sec, 10) || 0);
    var d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600),
        m = Math.floor(sec % 3600 / 60), s = sec % 60;
    return d + 'd ' + h + 'h ' + m + 'm ' + s + 's';
  }
  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function setText(root, sel, text) {
    var el = $(sel, root); if (el) { el.textContent = text; el.classList.remove('skeleton'); }
  }
  function setMeter(root, sel, pct) {
    var bar = $(sel, root);
    if (bar) { bar.style.width = Math.max(0, Math.min(100, pct)) + '%'; }
  }

  /* -------------------------------------------------------------- toasts -- */
  function toast(message, type, timeout) {
    var host = $('#toasts');
    if (!host) { return; }
    var el = document.createElement('div');
    el.className = 'toast' + (type ? ' toast--' + type : '');
    el.setAttribute('role', 'status');
    el.textContent = message;
    host.appendChild(el);
    var life = timeout || 3800;
    setTimeout(function () {
      el.classList.add('is-out');
      setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 240);
    }, life);
  }

  /* ------------------------------------------------- confirm destructive -- */
  function wireConfirms(root) {
    $$('[data-confirm]', root || document).forEach(function (el) {
      if (el.dataset.confirmBound === '1') { return; }
      el.dataset.confirmBound = '1';
      el.addEventListener('click', function (ev) {
        if (!window.confirm(el.getAttribute('data-confirm'))) {
          ev.preventDefault();
          ev.stopPropagation();
          return false;
        }
      });
    });
  }

  /* ------------------------------------------------------------- modals -- */
  function openModal(id) {
    var m = document.getElementById(id);
    if (!m) { return; }
    m.hidden = false;
    document.body.style.overflow = 'hidden';
    var first = m.querySelector('input,select,textarea,button');
    if (first) { setTimeout(function () { first.focus(); }, 40); }
  }
  function closeModal(m) {
    m = m && m.closest ? (m.closest('.modal') || m) : m;
    if (!m) { return; }
    m.hidden = true;
    document.body.style.overflow = '';
  }
  function wireModals() {
    $$('[data-modal-open]').forEach(function (btn) {
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        var id = btn.getAttribute('data-modal-open');
        // Optional prefill: copy data-fill-* into matching form fields.
        var modal = document.getElementById(id);
        if (modal) {
          $$('[data-fill]', modal).forEach(function (target) {
            var key = target.getAttribute('data-fill');
            var val = btn.getAttribute('data-fill-' + key);
            if (val != null) { target.value = val; }
          });
        }
        openModal(id);
      });
    });
    $$('[data-modal-close]').forEach(function (b) { b.addEventListener('click', function () { closeModal(b); }); });
    $$('.modal').forEach(function (m) {
      m.addEventListener('click', function (ev) { if (ev.target === m || ev.target.classList.contains('modal__scrim')) { closeModal(m); } });
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { $$('.modal').forEach(function (m) { if (!m.hidden) { closeModal(m); } }); closeNav(); }
    });
  }

  /* --------------------------------------------------------------- tabs -- */
  function wireTabs() {
    $$('[data-tab]').forEach(function (tab) {
      tab.addEventListener('click', function () {
        var group = tab.closest('.tabs');
        var name = tab.getAttribute('data-tab');
        $$('.tab', group).forEach(function (t) { t.classList.toggle('is-active', t === tab); });
        var scope = group.parentNode;
        $$('.tabpanel', scope).forEach(function (p) { p.hidden = (p.getAttribute('data-panel') !== name); });
        try { history.replaceState(null, '', '#' + name); } catch (e) {}
      });
    });
    // Deep-link via hash on load.
    var hash = (location.hash || '').replace('#', '');
    if (hash) {
      var t = $('[data-tab="' + hash + '"]');
      if (t) { t.click(); }
    }
  }

  /* ----------------------------------------------------------- mobile nav -- */
  function openNav() { document.body.classList.add('nav-open'); var s = $('[data-nav-scrim]'); if (s) { s.hidden = false; } }
  function closeNav() { document.body.classList.remove('nav-open'); var s = $('[data-nav-scrim]'); if (s) { s.hidden = true; } }
  function wireNav() {
    var burger = $('[data-nav-toggle]');
    if (burger) {
      burger.addEventListener('click', function () {
        if (document.body.classList.contains('nav-open')) { closeNav(); } else { openNav(); }
        burger.setAttribute('aria-expanded', document.body.classList.contains('nav-open') ? 'true' : 'false');
      });
    }
    var scrim = $('[data-nav-scrim]');
    if (scrim) { scrim.addEventListener('click', closeNav); }
  }

  /* -------------------------------------------------------- flash dismiss -- */
  function wireFlash() {
    $$('[data-flash-close]').forEach(function (b) {
      b.addEventListener('click', function () {
        var f = b.closest('.flash');
        if (f) { f.style.display = 'none'; }
      });
    });
  }

  /* --------------------------------------------------------------- clock -- */
  function wireClock() {
    var el = $('#clock');
    if (!el) { return; }
    function tick() {
      var d = new Date();
      var p = function (n) { return (n < 10 ? '0' : '') + n; };
      el.textContent = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }
    tick();
    setInterval(tick, 1000);
  }

  /* -------------------------------------------------- auto-detect API type -- */
  function wireAutodetect() {
    $$('[data-autodetect]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = btn.closest('form');
        if (!form) { return; }
        var host = (form.querySelector('[name="host"]') || {}).value || '';
        var user = (form.querySelector('[name="username"]') || {}).value || '';
        var pass = (form.querySelector('[name="password"]') || {}).value || '';
        var tlsEl = form.querySelector('[name="tls_verify"]');
        if (!host) { toast('Enter a host/IP first.', 'warn'); return; }

        var body = new URLSearchParams();
        body.set('action', 'autodetect');
        var csrf = form.querySelector('[name="csrf_token"]');
        if (csrf) { body.set('csrf_token', csrf.value); }
        body.set('host', host);
        body.set('username', user);
        body.set('password', pass);
        body.set('tls_verify', (tlsEl && tlsEl.checked) ? '1' : '0');
        var idEl = form.querySelector('[name="id"]');
        if (idEl && idEl.value) { body.set('id', idEl.value); }

        var label = btn.textContent;
        btn.classList.add('is-busy');
        btn.innerHTML = '<span class="spin"></span> Probing';

        fetch('routers.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
          body: body.toString(),
          credentials: 'same-origin'
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            btn.classList.remove('is-busy');
            btn.textContent = label;
            if (data && data.ok && data.api_type) {
              var radio = form.querySelector('[name="api_type"][value="' + data.api_type + '"]');
              if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
              var port = form.querySelector('[name="api_port"]');
              if (port && data.api_port) { port.value = data.api_port; }
              toast(data.message || ('Detected: ' + data.api_type.toUpperCase()), 'success', 5200);
            } else {
              toast((data && data.message) || 'Auto-detect failed on both REST:443 and Legacy:8728.', 'error', 5600);
            }
          })
          .catch(function () {
            btn.classList.remove('is-busy');
            btn.textContent = label;
            toast('Auto-detect request failed.', 'error');
          });
      });
    });
  }

  /* ------------------------- api_type radio -> default port + password hint -- */
  function wireApiType() {
    var form = $('[data-router-form]');
    if (!form) { return; }
    var portEl = form.querySelector('[name="api_port"]');
    var portTouched = false;
    if (portEl) { portEl.addEventListener('input', function () { portTouched = true; }); }
    function applyDefaults() {
      var checked = form.querySelector('[name="api_type"]:checked');
      if (!checked || !portEl) { return; }
      var type = checked.value;
      var def = (type === 'rest') ? 443 : 8728;
      // Only override the port if the user has not hand-edited it, or it holds
      // the other mode's default.
      if (!portTouched || portEl.value === '443' || portEl.value === '8728' || portEl.value === '8729' || portEl.value === '') {
        portEl.value = def;
      }
      var pwHint = form.querySelector('[data-pw-hint]');
      if (pwHint) {
        pwHint.textContent = (form.getAttribute('data-mode') === 'edit')
          ? 'Leave blank to keep the stored password.'
          : 'Router API password (encrypted at rest).';
      }
    }
    $$('[name="api_type"]', form).forEach(function (r) { r.addEventListener('change', applyDefaults); });
    applyDefaults();
  }

  /* ------------------------------------------------- voucher field logic -- */
  function wireVouchers() {
    var form = $('[data-voucher-form]');
    if (!form) { return; }
    var count = form.querySelector('[name="count"]');
    var prefix = form.querySelector('[name="prefix"]');
    var len = form.querySelector('[name="code_len"]');
    var out = form.querySelector('[data-voucher-preview]');

    function refresh() {
      if (count) {
        var c = parseInt(count.value, 10);
        if (isNaN(c) || c < 1) { count.value = 1; }
        else if (c > 200) { count.value = 200; }
      }
      if (out) {
        var p = (prefix && prefix.value) ? prefix.value : 'AIR';
        var n = (count && count.value) ? count.value : '1';
        var l = (len && len.value) ? len.value : '6';
        out.textContent = p + 'XXXXXX (× ' + n + ', ' + l + '-char code)';
      }
    }
    [count, prefix, len].forEach(function (el) { if (el) { el.addEventListener('input', refresh); } });
    refresh();
  }

  /* ------------------------------------------------- dashboard monitor poll -- */
  function renderInterfaces(host, list) {
    host.innerHTML = '';
    if (!list || !list.length) {
      host.innerHTML = '<div class="hint" style="padding:6px 0">No interface traffic.</div>';
      return;
    }
    list.forEach(function (iface) {
      var row = document.createElement('div');
      row.className = 'iface';
      row.innerHTML =
        '<span class="iface__n">' + esc(iface.name) + '</span>' +
        '<span class="iface__rx">' + esc(fmtRate(iface.rx_rate)) + '</span>' +
        '<span class="iface__tx">' + esc(fmtRate(iface.tx_rate)) + '</span>';
      host.appendChild(row);
    });
  }

  function paintCard(card, r) {
    var online = !!r.online;
    card.classList.toggle('is-loading', false);
    card.classList.toggle('is-error', !online);

    var badge = $('[data-online]', card);
    if (badge) {
      badge.className = 'badge ' + (online ? 'badge--online' : 'badge--offline');
      badge.innerHTML = '<span class="' + (online ? 'pulse' : '') + '"></span>' + (online ? 'ONLINE' : 'OFFLINE');
    }

    if (!online) {
      var errBox = $('[data-error]', card);
      if (errBox) { errBox.hidden = false; errBox.textContent = r.error || 'Router unreachable.'; }
      var metrics = $('.rcard__metrics', card);
      if (metrics) { metrics.hidden = true; }
      return;
    }

    var errHide = $('[data-error]', card);
    if (errHide) { errHide.hidden = true; }
    var metricsShow = $('.rcard__metrics', card);
    if (metricsShow) { metricsShow.hidden = false; }

    var res = r.resource || {};
    setText(card, '[data-m-identity]', r.identity || res['board-name'] || '—');
    setText(card, '[data-m-version]', res.version || '—');
    setText(card, '[data-m-board]', res['board-name'] || '—');

    var cpu = Number(res['cpu-load']) || 0;
    setText(card, '[data-m-cpu]', cpu.toFixed(0) + '%');
    setMeter(card, '[data-meter-cpu] > i', cpu);

    var free = Number(res['free-memory']) || 0;
    var total = Number(res['total-memory']) || 0;
    var usedPct = total > 0 ? ((total - free) / total) * 100 : 0;
    setText(card, '[data-m-memory]', fmtBytes(total - free) + ' / ' + fmtBytes(total));
    setMeter(card, '[data-meter-mem] > i', usedPct);

    setText(card, '[data-m-uptime]', fmtUptime(res.uptime));
    setText(card, '[data-m-active]', String(r.active_count != null ? r.active_count : 0));

    var ifHost = $('[data-ifaces]', card);
    if (ifHost) { renderInterfaces(ifHost, r.interfaces); }
  }

  function pollMonitor() {
    var cards = $$('.rcard[data-router-id]');
    if (!cards.length) { return; }
    var stamp = $('#monitor-stamp');
    fetch('api/monitor.php', { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (res) {
        if (res.status === 401) { location.href = 'login.php'; throw new Error('unauthorized'); }
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.routers) { return; }
        data.routers.forEach(function (r) {
          var card = $('.rcard[data-router-id="' + String(r.id).replace(/"/g, '') + '"]');
          if (card) { paintCard(card, r); }
        });
        if (stamp) { stamp.textContent = 'updated ' + new Date().toLocaleTimeString(); }
      })
      .catch(function () {
        cards.forEach(function (c) {
          c.classList.add('is-error');
          var errBox = $('[data-error]', c);
          if (errBox) { errBox.hidden = false; errBox.textContent = 'Monitor feed unavailable.'; }
        });
        if (stamp) { stamp.textContent = 'feed error'; }
      });
  }

  function wireDashboard() {
    if (!$('.rcard[data-router-id]')) { return; }
    // Mark cards as loading until the first sample arrives.
    $$('.rcard[data-router-id]').forEach(function (c) {
      c.classList.add('is-loading');
      $$('[data-m-identity],[data-m-version],[data-m-cpu],[data-m-memory],[data-m-uptime],[data-m-active],[data-m-board]', c)
        .forEach(function (el) { el.classList.add('skeleton'); el.textContent = '0000'; });
    });
    pollMonitor();
    setInterval(pollMonitor, POLL_MS);
    var refresh = $('#monitor-refresh');
    if (refresh) { refresh.addEventListener('click', function () { pollMonitor(); toast('Refreshing monitor…', 'info', 1600); }); }
  }

  /* -------------------------------------------------------------- boot -- */
  function boot() {
    wireNav();
    wireFlash();
    wireModals();
    wireTabs();
    wireConfirms();
    wireClock();
    wireAutodetect();
    wireApiType();
    wireVouchers();
    wireDashboard();
  }

  // Public surface (used by inline handlers if ever needed).
  window.AIRCOINS = { toast: toast, openModal: openModal, closeModal: closeModal, fmtBytes: fmtBytes };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
