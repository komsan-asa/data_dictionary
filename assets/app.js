/* Data Dictionary — หน้าเว็บ (vanilla JS ไม่พึ่งไลบรารีภายนอก ใช้ในวง LAN ได้) */
(function () {
  'use strict';

  var S = {
    user: null, csrf: null, projects: [], project: null,
    mode: 'table', q: '', filter: 'all', page: 1, total: 0, tables: [], colHits: [],
    table: null, flashCol: null,
    pq: '', pfilter: 'all', psort: 'name' // ค้น กรอง และเรียงรายการโปรเจ็คในแถบซ้าย
  };
  try { // จำตัวกรองและการเรียงของผู้ใช้คนนี้ไว้ในเบราว์เซอร์ (ไม่จำคำค้น)
    var pv = JSON.parse(localStorage.getItem('dd-proj-view') || '{}');
    if (pv.f) S.pfilter = pv.f;
    if (pv.s) S.psort = pv.s;
  } catch (e) { /* ใช้ค่าเริ่มต้น */ }
  var app = document.getElementById('app');

  // ---------- เครื่องมือ ----------
  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') el.className = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else if (k === 'value') el.value = v;
      else el.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) add(el, arguments[i]);
    return el;
  }
  function add(el, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) c.forEach(function (x) { add(el, x); });
    else el.appendChild(c.nodeType ? c : document.createTextNode(String(c)));
  }
  var NS = 'http://www.w3.org/2000/svg';
  var ICONS = {
    sync: '<path d="M20 11a8 8 0 0 0-14.3-4.4M4 4v4h4M4 13a8 8 0 0 0 14.3 4.4M20 20v-4h-4"/>',
    download: '<path d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14"/>',
    spark: '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 16l.7 1.8 1.8.7-1.8.7L19 21l-.7-1.8-1.8-.7 1.8-.7z"/>',
    clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    sliders: '<path d="M4 7h8m5 0h3M4 17h3m5 0h8"/><circle cx="14.5" cy="7" r="2.3"/><circle cx="9.5" cy="17" r="2.3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
    moon: '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
    logout: '<path d="M10 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4M15 16l4-4-4-4M19 12H9"/>',
    userplus: '<circle cx="10" cy="8" r="3.5"/><path d="M3.5 20a6.5 6.5 0 0 1 13 0M19 8v6M16 11h6"/>',
    arrowr: '<path d="M5 12h14m0 0l-5-5m5 5l-5 5"/>',
    panel: '<rect x="3.5" y="4.5" width="17" height="15" rx="3"/><path d="M9.5 4.5v15"/>',
    book: '<path d="M5 4.5h10a3 3 0 0 1 3 3V20H8a3 3 0 0 1-3-3z"/><path d="M5 17a3 3 0 0 1 3-3h10M9 8.5h5"/>',
    table: '<rect x="4" y="5" width="16" height="14" rx="2.5"/><path d="M4 10h16M10 10v9"/>',
    bulb: '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.3 1 2.1h5c0-.8.4-1.6 1-2.1A6 6 0 0 0 12 3z"/>',
    eye: '<path d="M2.5 12s3.5-6.5 9.5-6.5S21.5 12 21.5 12s-3.5 6.5-9.5 6.5S2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>',
    grid: '<rect x="4" y="4" width="7" height="7" rx="1.8"/><rect x="13" y="4" width="7" height="5" rx="1.8"/><rect x="13" y="11" width="7" height="9" rx="1.8"/><rect x="4" y="13" width="7" height="7" rx="1.8"/>'
  };
  function ico(name) {
    var el = document.createElementNS(NS, 'svg');
    el.setAttribute('viewBox', '0 0 24 24'); el.setAttribute('class', 'ico'); el.setAttribute('aria-hidden', 'true');
    el.setAttribute('fill', 'none'); el.setAttribute('stroke', 'currentColor'); el.setAttribute('stroke-width', '1.9');
    el.setAttribute('stroke-linecap', 'round'); el.setAttribute('stroke-linejoin', 'round');
    el.innerHTML = ICONS[name];
    return el;
  }
  function ring(done, total) {
    var r = 18, c = 2 * Math.PI * r, p = total ? Math.min(1, done / total) : 0;
    var el = document.createElementNS(NS, 'svg');
    el.setAttribute('viewBox', '0 0 44 44'); el.setAttribute('class', 'ring' + (total && done >= total ? ' full' : '')); el.setAttribute('aria-hidden', 'true');
    el.innerHTML = '<circle class="t" cx="22" cy="22" r="' + r + '"/><circle class="v" cx="22" cy="22" r="' + r + '" stroke-dasharray="' + c.toFixed(2) + '" stroke-dashoffset="' + (c * (1 - p)).toFixed(2) + '"/>';
    return el;
  }
  function isDark() {
    var t = document.documentElement.getAttribute('data-theme');
    return t ? t === 'dark' : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }
  function toggleTheme(btn) {
    var next = isDark() ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('dd-theme', next); } catch (e) { /* ใช้ได้เฉพาะรอบนี้ */ }
    btn.textContent = ''; btn.appendChild(ico(next === 'dark' ? 'sun' : 'moon'));
  }
  function btn(icon, label, onclick, cls) {
    return h('button', { class: 'btn' + (cls ? ' ' + cls : ''), onclick: onclick }, ico(icon), h('span', null, label));
  }
  function num(n) { return Number(n || 0).toLocaleString('th-TH'); }
  function pct(a, b) { return b ? Math.round(a / b * 100) : 0; }
  function bar(done, total) {
    var p = total ? done / total * 100 : 0;
    return h('div', { class: 'bar' + (total && done >= total ? ' full' : '') }, h('i', { style: 'width:' + p + '%' }));
  }
  function toast(msg, bad) {
    var t = h('div', { class: 'toast' + (bad ? ' bad' : '') }, msg);
    document.getElementById('toasts').appendChild(t);
    setTimeout(function () { t.remove(); }, bad ? 7000 : 4000);
  }
  function call(action, params, body) {
    var url = 'api.php?action=' + encodeURIComponent(action);
    Object.keys(params || {}).forEach(function (k) {
      if (params[k] !== undefined && params[k] !== null && params[k] !== '') url += '&' + k + '=' + encodeURIComponent(params[k]);
    });
    var opt = { credentials: 'same-origin', headers: {} };
    if (body !== undefined) {
      opt.method = 'POST';
      opt.headers['Content-Type'] = 'application/json';
      if (S.csrf) opt.headers['X-CSRF-Token'] = S.csrf;
      opt.body = JSON.stringify(body);
    }
    return fetch(url, opt).then(function (r) {
      return r.text().then(function (txt) {
        var d;
        try { d = JSON.parse(txt); } catch (e) { throw new Error('เซิร์ฟเวอร์ตอบกลับไม่ถูกรูปแบบ (HTTP ' + r.status + ')'); }
        if (!d.ok) {
          if (r.status === 401 && S.user) { S.user = null; boot(); }
          throw new Error(d.error || 'เกิดข้อผิดพลาด');
        }
        return d;
      });
    });
  }
  var get = function (a, p) { return call(a, p); };
  var post = function (a, b, p) { return call(a, p, b || {}); };
  function autosize(t) { t.style.height = 'auto'; t.style.height = (t.scrollHeight + 2) + 'px'; }
  function debounce(fn, ms) { var t; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; }

  // ---------- เริ่มต้น / เข้าสู่ระบบ ----------
  function boot() {
    get('status').then(function (d) {
      S.user = d.user; S.csrf = d.csrf;
      if (!d.user) return renderAuth(d.needs_setup);
      return loadProjects().then(function () {
        var parts = decodeURIComponent(location.hash.replace(/^#\/?/, '')).split('/');
        var p = S.projects.filter(function (x) { return x.code === parts[0]; })[0];
        renderShell();
        if (p) openProject(p.code, parts[1]);
        else if (S.projects.length) openDashboard(); // หน้าแรก = ภาพรวมทุกโปรเจ็ค
      });
    }).catch(function (e) {
      app.textContent = '';
      app.appendChild(h('div', { class: 'empty' }, h('h2', null, 'เปิดระบบไม่ได้'), h('p', { class: 'muted' }, e.message)));
    });
  }

  function renderAuth(setup) {
    var err = h('div', { class: 'form-error', role: 'alert' });
    var u = h('input', { name: 'username', autocomplete: 'username', required: true });
    var p = h('input', { name: 'password', type: 'password', autocomplete: setup ? 'new-password' : 'current-password', required: true });
    var btn = h('button', { class: 'btn primary', type: 'submit' }, setup ? 'สร้างผู้ดูแลและเข้าใช้งาน' : 'เข้าสู่ระบบ');
    var form = h('form', {
      onsubmit: function (ev) {
        ev.preventDefault(); btn.disabled = true; err.textContent = '';
        post(setup ? 'setup' : 'login', { username: u.value, password: p.value }).then(function (d) {
          S.user = d.user; S.csrf = d.csrf; boot();
        }).catch(function (e) { err.textContent = e.message; btn.disabled = false; });
      }
    },
      h('h2', null, setup ? 'ตั้งค่าผู้ดูแลคนแรก' : 'เข้าสู่ระบบ'),
      h('p', { class: 'muted' }, setup ? 'ยังไม่มีผู้ใช้ในระบบ สร้างบัญชีแรกเพื่อเริ่มใช้งาน' : 'ใช้บัญชีของระบบ Data Dictionary'),
      h('label', { class: 'field' }, h('span', null, 'ชื่อผู้ใช้'), u),
      h('label', { class: 'field' }, h('span', null, setup ? 'รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)' : 'รหัสผ่าน'), p),
      err, btn);
    var sample = [['hn', 'เลขประจำตัวผู้ป่วยของโรงพยาบาล'], ['vn', 'เลขที่การมารับบริการแต่ละครั้ง'], ['pttype', 'รหัสสิทธิการรักษา'], ['vstdate', 'วันที่มารับบริการ']];
    app.textContent = '';
    app.appendChild(h('div', { class: 'auth' },
      h('div', { class: 'auth-side' },
        h('div', null, h('h1', null, 'ทุกคอลัมน์มีความหมาย เขียนไว้ที่เดียว'),
          h('p', null, 'Data Dictionary แยกตามโปรเจ็ค ดึงโครงสร้างจากฐานข้อมูลจริง แล้วเติมความหมายโดยทีมงานหรือ Claude')),
        h('div', { class: 'auth-sample' }, sample.map(function (r) { return h('div', null, h('b', null, r[0]), h('span', null, r[1])); }))),
      h('div', { class: 'auth-form' }, form)));
    u.focus();
  }

  // ---------- โครงหลัก ----------
  function loadProjects() { return get('projects').then(function (d) { S.projects = d.projects; }); }

  function renderShell() {
    app.textContent = '';
    var themeBtn = h('button', { class: 'iconbtn', title: 'สลับโหมดสว่าง/มืด', 'aria-label': 'สลับโหมดสว่าง/มืด', onclick: function () { toggleTheme(themeBtn); } }, ico(isDark() ? 'sun' : 'moon'));
    // ปุ่มพับ/กางแถบเมนู: ตอนพับเหลือเป็นแถบไอคอนแคบ จำค่าไว้ในเบราว์เซอร์
    var folded = false;
    try { folded = localStorage.getItem('dd-rail') === 'folded'; } catch (e) { /* ใช้ได้เฉพาะรอบนี้ */ }
    var foldBtn = h('button', { class: 'iconbtn fold', onclick: function () {
      folded = !folded;
      try { localStorage.setItem('dd-rail', folded ? 'folded' : 'full'); } catch (e) { /* ใช้ได้เฉพาะรอบนี้ */ }
      applyFold();
    } }, ico('panel'));
    function applyFold() {
      var shell = document.querySelector('.shell');
      if (shell) shell.classList.toggle('folded', folded);
      foldBtn.title = folded ? 'กางเมนู' : 'พับเมนู';
      foldBtn.setAttribute('aria-label', foldBtn.title);
      foldBtn.setAttribute('aria-expanded', folded ? 'false' : 'true');
    }
    app.appendChild(h('div', { class: 'shell' + (folded ? ' folded' : '') },
      h('aside', { class: 'rail' },
        h('div', { class: 'rail-title' }, h('span', { class: 'logo' }, ico('book')),
          h('div', { class: 'brand' }, h('b', null, 'Data Dictionary'), h('small', null, 'พจนานุกรมข้อมูล')),
          foldBtn),
        h('button', { class: 'rail-home', id: 'railhome', title: 'ภาพรวม', onclick: function () { S.projects.length ? openDashboard() : renderNoProject(); } }, ico('grid'), h('span', null, 'ภาพรวม')),
        railFind(),
        h('nav', { class: 'rail-list', id: 'rail' }),
        h('div', { class: 'online', id: 'online', 'aria-live': 'polite' }),
        h('div', { class: 'rail-foot' },
          btn('plus', 'เพิ่มโปรเจ็ค', function () { projectForm(null); }),
          h('div', { class: 'who' },
            h('span', { class: 'avatar', 'aria-hidden': 'true' }, (S.user.display_name || S.user.username).slice(0, 1).toUpperCase()),
            h('span', { class: 'name' }, S.user.display_name || S.user.username),
            themeBtn,
            h('button', { class: 'iconbtn', title: 'เพิ่มผู้ใช้', 'aria-label': 'เพิ่มผู้ใช้', onclick: userForm }, ico('userplus')),
            h('button', { class: 'iconbtn', title: 'ออกจากระบบ', 'aria-label': 'ออกจากระบบ', onclick: function () { post('logout').then(function () { stopPoll(); clearTimeout(presenceTimer); S.user = null; S.csrf = null; boot(); }); } }, ico('logout'))))),
      h('main', { class: 'main', id: 'main' })));
    applyFold();
    renderRail();
    if (!S.projects.length) renderNoProject();
    presence();
  }

  // ---------- ผู้ใช้ออนไลน์ ----------
  // หน้าเว็บรายงานตัวทุก 30 วินาที พร้อมบอกว่าเปิดโปรเจ็คไหนอยู่ แล้วรับรายชื่อผู้ที่ออนไลน์กลับมา
  var presenceTimer = null, presenceSeq = 0;
  function presence() {
    clearTimeout(presenceTimer);
    if (!S.user) return;
    var again = true, mine = ++presenceSeq; // เรียกซ้อนกันได้ ให้รอบล่าสุดเท่านั้นที่วาดผลและตั้งเวลารอบถัดไป
    post('presence', { viewing: S.project ? S.project.code : '' }).then(function (d) {
      if (mine !== presenceSeq) return;
      if (!d.ready) again = false;
      drawOnline(d.online);
    }).catch(function () { /* เครือข่ายสะดุด รอบหน้าลองใหม่ */ }).then(function () {
      if (S.user && again && mine === presenceSeq) presenceTimer = setTimeout(presence, 30000);
    });
  }
  function drawOnline(list) {
    var box = document.getElementById('online');
    if (!box) return;
    box.textContent = '';
    if (list === null) {
      box.appendChild(h('div', { class: 'online-note' }, 'ยังแสดงผู้ใช้ออนไลน์ไม่ได้ ให้รัน database/migrate_20261110_online.sql หนึ่งครั้ง'));
      return;
    }
    list.sort(function (a, b) { return (b.me ? 1 : 0) - (a.me ? 1 : 0) || a.name.localeCompare(b.name, 'th'); });
    var max = 5, shown = list.slice(0, max);
    add(box, [
      h('div', { class: 'online-head', title: 'ออนไลน์: ' + list.map(function (u) { return u.name; }).join(', ') },
        h('i', { class: 'dot' }), h('span', { class: 'lbl' }, 'ออนไลน์'), h('b', null, list.length), h('span', { class: 'lbl' }, 'คน')),
      shown.map(function (u) {
        var known = u.viewing && S.projects.some(function (p) { return p.code === u.viewing; });
        return h('div', { class: 'online-user', title: u.name + (u.viewing ? ' กำลังดู ' + u.viewing : '') },
          h('span', { class: 'avatar sm', 'aria-hidden': 'true' }, u.name.slice(0, 1).toUpperCase()),
          h('span', { class: 'name' }, u.name + (u.me ? ' (คุณ)' : '')),
          u.viewing ? (known && !u.me
            ? h('button', { class: 'where', title: 'ไปที่โปรเจ็ค ' + u.viewing, onclick: function () { openProject(u.viewing); } }, u.viewing)
            : h('span', { class: 'where' }, u.viewing)) : null);
      }),
      list.length > max ? h('div', { class: 'online-more' }, 'และอีก ' + (list.length - max) + ' คน') : null]);
  }

  // ---------- ค้น กรอง เรียง รายการโปรเจ็ค ----------
  var PFILTERS = [
    ['all', 'ทุกโปรเจ็ค', function () { return true; }],
    ['todo', 'ยังไม่ครบ', function (p) { return p.stats.columns_described < p.stats.columns; }],
    ['done', 'ครบแล้ว', function (p) { return p.stats.columns > 0 && p.stats.columns_described >= p.stats.columns; }],
    ['pending', 'มีข้อเสนอรอพิจารณา', function (p) { return p.pending > 0; }],
    ['nosync', 'ยังไม่เคยซิงค์', function (p) { return !p.last_sync_at; }]
  ];
  function progress(p) { return p.stats.columns ? p.stats.columns_described / p.stats.columns : 0; }
  // ลำดับตามชื่อใช้ลำดับที่เซิร์ฟเวอร์ส่งมา (ORDER BY name) เพื่อให้ตรงกับที่เคยเห็น และใช้ตัดสินเมื่อค่าเท่ากัน
  function byName(a, b) { return S.projects.indexOf(a) - S.projects.indexOf(b); }
  var PSORTS = [
    ['name', 'เรียงตามชื่อ', byName],
    ['least', 'คืบหน้าน้อยก่อน', function (a, b) { return progress(a) - progress(b) || byName(a, b); }],
    ['most', 'คืบหน้ามากก่อน', function (a, b) { return progress(b) - progress(a) || byName(a, b); }],
    ['sync', 'ซิงค์ล่าสุดก่อน', function (a, b) { return String(b.last_sync_at || '').localeCompare(String(a.last_sync_at || '')) || byName(a, b); }]
  ];
  function pick(list, key) { return list.filter(function (x) { return x[0] === key; })[0] || list[0]; }
  function visibleProjects() {
    var words = S.pq.toLowerCase().split(/\s+/).filter(Boolean);
    var keep = pick(PFILTERS, S.pfilter)[2];
    return S.projects.filter(function (p) {
      if (!keep(p)) return false;
      // ค้นได้จากชื่อ รหัส รายละเอียด และชื่อฐานข้อมูลต้นทาง ทุกคำที่พิมพ์ต้องพบ
      var hay = [p.name, p.code, p.description, p.conn_database, p.conn_host].join(' ').toLowerCase();
      return words.every(function (w) { return hay.indexOf(w) !== -1; });
    }).sort(pick(PSORTS, S.psort)[2]);
  }
  function saveProjectView() {
    try { localStorage.setItem('dd-proj-view', JSON.stringify({ f: S.pfilter, s: S.psort })); } catch (e) { /* ใช้ได้เฉพาะรอบนี้ */ }
  }
  function railFind() {
    var search = h('input', { class: 'input', type: 'search', id: 'railsearch', placeholder: 'ค้นหาโปรเจ็ค', 'aria-label': 'ค้นหาโปรเจ็ค', value: S.pq, autocomplete: 'off',
      oninput: function () { S.pq = search.value.trim(); renderRail(); },
      onkeydown: function (ev) {
        if (ev.key === 'Enter') { var first = visibleProjects()[0]; if (first) openProject(first.code); }
        else if (ev.key === 'Escape' && search.value) { ev.preventDefault(); search.value = ''; S.pq = ''; renderRail(); }
      } });
    function select(list, cur, label, set) {
      var el = h('select', { 'aria-label': label, id: label === 'กรองโปรเจ็ค' ? 'railfilter' : 'railsort', onchange: function () { set(el.value); saveProjectView(); renderRail(); } },
        list.map(function (x) { return h('option', { value: x[0] }, x[1]); }));
      el.value = pick(list, cur)[0];
      return el;
    }
    return h('div', { class: 'rail-find' },
      h('div', { class: 'rail-label' }, h('span', null, 'โปรเจ็ค'), h('span', { id: 'railcount' })),
      h('div', { class: 'search' }, ico('search'), search),
      h('div', { class: 'rail-row' },
        select(PFILTERS, S.pfilter, 'กรองโปรเจ็ค', function (v) { S.pfilter = v; }),
        select(PSORTS, S.psort, 'เรียงโปรเจ็ค', function (v) { S.psort = v; })));
  }

  function renderRail() {
    var rail = document.getElementById('rail');
    if (!rail) return;
    rail.textContent = '';
    document.getElementById('railhome').classList.toggle('on', !S.project);
    var list = visibleProjects(), narrowed = S.pq !== '' || pick(PFILTERS, S.pfilter)[0] !== 'all';
    var flt = document.getElementById('railfilter');
    if (flt) { flt.value = pick(PFILTERS, S.pfilter)[0]; flt.classList.toggle('on', flt.value !== 'all'); }
    document.getElementById('railcount').textContent = narrowed ? 'พบ ' + list.length + ' จาก ' + S.projects.length : String(S.projects.length || '');
    if (!list.length && S.projects.length) {
      rail.appendChild(h('div', { class: 'rail-none' },
        h('p', null, S.pq ? 'ไม่พบโปรเจ็คที่ตรงกับ "' + S.pq + '"' + (pick(PFILTERS, S.pfilter)[0] !== 'all' ? ' ในกลุ่ม "' + pick(PFILTERS, S.pfilter)[1] + '"' : '') : 'ไม่มีโปรเจ็คในกลุ่ม "' + pick(PFILTERS, S.pfilter)[1] + '"'),
        h('button', { class: 'btn', onclick: function () {
          S.pq = ''; S.pfilter = 'all'; saveProjectView();
          var box = document.getElementById('railsearch'); if (box) box.value = '';
          renderRail();
        } }, 'แสดงทุกโปรเจ็ค')));
      return;
    }
    list.forEach(function (p) {
      rail.appendChild(h('button', { class: 'proj' + (S.project && S.project.code === p.code ? ' on' : ''),
        title: p.name + ' (' + p.code + ') มีความหมายแล้ว ' + pct(p.stats.columns_described, p.stats.columns) + '%', onclick: function () { openProject(p.code); } },
        h('span', { class: 'pini', 'aria-hidden': 'true' }, p.code.slice(0, 2).toUpperCase()),
        h('b', null, p.name),
        h('div', { class: 'meta' }, h('span', null, p.code), h('span', null, pct(p.stats.columns_described, p.stats.columns) + '%')),
        bar(p.stats.columns_described, p.stats.columns)));
    });
  }

  function renderNoProject() {
    var m = document.getElementById('main');
    m.textContent = '';
    m.appendChild(h('div', { class: 'empty' }, h('div', { class: 'art' }, ico('book')), h('h2', null, 'ยังไม่มีโปรเจ็ค'),
      h('p', { class: 'muted' }, 'สร้างโปรเจ็คแรก ตั้งค่าการเชื่อมต่อฐานข้อมูล แล้วกดซิงค์เพื่อดึงโครงสร้างตารางเข้ามา'),
      btn('plus', 'เพิ่มโปรเจ็ค', function () { projectForm(null); }, 'primary')));
  }

  // ---------- หน้าแรก: ภาพรวมทุกโปรเจ็ค ----------
  // ที่มาของความหมาย เรียงตามลำดับสีที่ตรวจแล้วว่าแยกออกสำหรับผู้มีภาวะตาบอดสี (ห้ามสลับลำดับ)
  var SOURCES = [['human', 'คนเขียน'], ['api', 'นำเข้าทาง API'], ['comment', 'comment ในฐานข้อมูล'], ['guessed', 'ระบบเดา รอยืนยัน'], ['empty', 'ยังไม่มีความหมาย']];
  var tipEl = null;
  function tipShow(anchor, ev, title, rows) {
    if (!tipEl) { tipEl = h('div', { class: 'vtip', role: 'tooltip' }); document.body.appendChild(tipEl); }
    tipEl.textContent = '';
    add(tipEl, [title ? h('div', { class: 'vtip-t' }, title) : null, rows.map(function (r) {
      return h('div', { class: 'vtip-r' }, h('i', { class: 'key' + (r.key ? ' seg-' + r.key : '') }), h('b', null, r.value), h('span', null, r.label), h('em', null, r.share || ''));
    })]);
    tipEl.hidden = false;
    var byPointer = ev && ev.type !== 'focus' && ev.clientX !== undefined, box = anchor.getBoundingClientRect();
    var x = byPointer ? ev.clientX + 14 : box.left, y = byPointer ? ev.clientY + 18 : box.bottom + 8;
    x = Math.max(8, Math.min(x, window.innerWidth - tipEl.offsetWidth - 8));
    if (y + tipEl.offsetHeight > window.innerHeight - 8) y = Math.max(8, (byPointer ? ev.clientY : box.top) - tipEl.offsetHeight - 12);
    tipEl.style.left = x + 'px'; tipEl.style.top = y + 'px';
  }
  function tipHide() { if (tipEl) tipEl.hidden = true; }
  function tipBind(el, title, rowsFn) { // รายละเอียดเดียวกันทั้งตอนชี้และตอนโฟกัสด้วยแป้นพิมพ์
    function show(ev) { tipShow(el, ev, title, rowsFn()); }
    el.addEventListener('pointermove', show); el.addEventListener('pointerleave', tipHide);
    el.addEventListener('focus', show); el.addEventListener('blur', tipHide);
  }
  function share(n, total) { return total ? (n / total * 100 < 1 && n > 0 ? '<1' : Math.round(n / total * 100)) + '%' : '0%'; }
  function sourceParts(o, columns, described) { // o = จำนวนแยกตามที่มา
    return SOURCES.map(function (src) { return { key: src[0], label: src[1], n: src[0] === 'empty' ? Math.max(0, columns - described) : (o[src[0]] || 0) }; });
  }
  function segBar(parts, total, name) {
    var bar = h('div', { class: 'segbar', tabindex: 0, role: 'img',
      'aria-label': name + ': ' + parts.map(function (x) { return x.label + ' ' + num(x.n) + ' คอลัมน์ (' + share(x.n, total) + ')'; }).join(', ') });
    parts.forEach(function (x) { if (x.n > 0) bar.appendChild(h('i', { class: 'seg-' + x.key, style: 'flex:' + x.n + ' 1 0' })); });
    tipBind(bar, name, function () { return parts.map(function (x) { return { key: x.key, value: num(x.n), label: x.label, share: share(x.n, total) }; }); });
    return bar;
  }

  function openDashboard() {
    stopPoll(); tipHide();
    S.project = null; S.table = null; S.tables = [];
    if (location.hash !== '#/') history.replaceState(null, '', '#/');
    renderRail(); presence(); // รายงานว่าไม่ได้เปิดโปรเจ็คใดอยู่
    var m = document.getElementById('main');
    m.textContent = '';
    m.appendChild(h('div', { class: 'dash', id: 'dash' }, h('p', { class: 'boot' }, 'กำลังโหลดภาพรวม…')));
    loadDashboard();
  }
  function loadDashboard() {
    var box = document.getElementById('dash');
    if (box && S.dash) box.classList.add('stale'); // คงภาพเดิมไว้ระหว่างโหลดใหม่ ไม่ให้หน้ากระตุก
    return get('dashboard').then(function (d) {
      if (S.project || !document.getElementById('dash')) return;
      S.dash = d; drawDashboard();
    }).catch(function (e) { toast(e.message, true); if (box) box.classList.remove('stale'); });
  }

  function kpi(label, value, sub, cls) {
    return h('div', { class: 'kpi' + (cls ? ' ' + cls : '') }, h('div', { class: 'l' }, label), h('div', { class: 'v' }, value), sub ? h('div', { class: 's' }, sub) : null);
  }
  function card(title, sub, body, tools) {
    return h('section', { class: 'card' },
      h('header', null, h('div', null, h('h2', null, title), sub ? h('p', { class: 'muted' }, sub) : null), tools || null), body);
  }
  var KIND = { sync: 'ซิงค์', guess: 'เดาความหมาย', clone: 'โคลน', 'import': 'นำเข้า' };
  function statusTag(st) {
    return h('span', { class: 'tag' + (st === 'error' ? ' drop' : st === 'running' ? ' key' : '') }, st === 'ok' ? 'สำเร็จ' : st === 'running' ? 'กำลังทำงาน' : 'ผิดพลาด');
  }

  function drawDashboard() {
    var d = S.dash, t = d.totals, box = document.getElementById('dash');
    if (!box) return;
    box.classList.remove('stale');
    box.textContent = '';
    var empty = t.columns - t.described;

    // ---- ตัวเลขนำ: สัดส่วนคอลัมน์ที่มีความหมายแล้ว + ตัวเลขประกอบ
    var hero = kpi('คอลัมน์ที่มีความหมายแล้ว', t.columns ? Math.floor(t.described / t.columns * 100) + '%' : '0%', null, 'hero');
    add(hero, [h('div', { class: 'meter', role: 'img', 'aria-label': 'มีความหมายแล้ว ' + num(t.described) + ' จาก ' + num(t.columns) + ' คอลัมน์' },
      h('i', { style: 'width:' + (t.columns ? t.described / t.columns * 100 : 0) + '%' })),
      h('div', { class: 's' }, num(t.described) + ' จาก ' + num(t.columns) + ' คอลัมน์ ใน ' + num(t.projects) + ' โปรเจ็ค')]);
    var kpis = h('div', { class: 'kpis' }, hero,
      kpi('ยังไม่มีความหมาย', num(empty), 'คอลัมน์ที่ยังต้องเขียน'),
      kpi('ตาราง', num(t.tables), 'มีคำอธิบายตารางแล้ว ' + num(t.tables_described) + ' (' + share(t.tables_described, t.tables) + ')'),
      kpi('ข้อเสนอรอพิจารณา', num(t.pending), 'จากปุ่ม "ตีความจากข้อมูลจริง"'),
      kpi('ระบบเดา รอยืนยัน', num(t.guessed), 'ความหมายที่ระบบเติมให้ ยังไม่มีคนตรวจ'));

    // ---- โปรเจ็ค: แถบแบ่งส่วนตามที่มาของความหมาย มีคำอธิบายสีชุดเดียวทั้งตาราง และสลับดูเป็นตัวเลขได้
    var legend = h('div', { class: 'legend' }, SOURCES.map(function (src) { return h('span', null, h('i', { class: 'seg-' + src[0] }), src[1]); }));
    var numBtn = h('button', { class: 'btn', 'aria-pressed': S.dashNums ? 'true' : 'false', onclick: function () { S.dashNums = !S.dashNums; drawDashboard(); } },
      S.dashNums ? 'ดูเป็นแถบสี' : 'ดูเป็นตัวเลข');
    var rows = d.projects.map(function (p) {
      var parts = sourceParts(p.by, p.columns, p.described);
      var open = function () { openProject(p.code); };
      var sync = p.sync && p.sync.status !== 'ok' ? [statusTag(p.sync.status), ' ']
        : [];
      return h('tr', { onclick: function (ev) { if (!ev.target.closest('a, button, .segbar')) open(); } },
        h('td', null, h('a', { class: 'pname', href: '#/' + encodeURIComponent(p.code), onclick: function (ev) { ev.preventDefault(); open(); } }, p.name), h('span', { class: 'chip' }, p.code)),
        h('td', { class: 'pbar' }, S.dashNums
          ? h('div', { class: 'pnums' }, parts.map(function (x) { return h('span', null, h('i', { class: 'seg-' + x.key }), x.label + ' ', h('b', null, num(x.n))); }))
          : segBar(parts, p.columns, p.name)),
        h('td', { class: 'n' }, h('b', null, share(p.described, p.columns)), h('small', null, num(p.described) + ' / ' + num(p.columns))),
        h('td', { class: 'n' }, num(p.tables)),
        h('td', { class: 'n' }, p.pending ? num(p.pending) : '–'),
        h('td', null, sync, p.sync ? p.sync.at : (p.has_connection ? 'ยังไม่เคยซิงค์' : 'ยังไม่ได้ตั้งค่าการเชื่อมต่อ')));
    });
    var projects = card('โปรเจ็ค', 'ความหมายของคอลัมน์ในแต่ละโปรเจ็คมาจากไหน กดที่แถวเพื่อเปิดโปรเจ็ค',
      [legend, h('div', { class: 'scroll-x' }, h('table', { class: 'logs ptable' },
        h('thead', null, h('tr', null, h('th', null, 'โปรเจ็ค'), h('th', null, 'ที่มาของความหมาย'), h('th', { class: 'n' }, 'มีความหมายแล้ว'), h('th', { class: 'n' }, 'ตาราง'), h('th', { class: 'n' }, 'รอพิจารณา'), h('th', null, 'ซิงค์ล่าสุด'))),
        h('tbody', null, rows)))], numBtn);

    // ---- ตารางที่มีข้อเสนอรอพิจารณา (งานที่ทำต่อได้ทันที)
    var pending = card('ตารางที่มีข้อเสนอรอพิจารณา', 'ระบบเสนอความหมายไว้แล้ว รอคนตรวจและกด "ใช้"',
      d.pending_tables.length
        ? h('div', { class: 'plist' }, d.pending_tables.map(function (x) {
          return h('button', { onclick: function () { openProject(x.project, x.table_name); } },
            h('span', { class: 'chip' }, x.project), h('b', null, x.table_name), h('span', { class: 'pend' }, 'รอ ' + num(x.n)));
        }))
        : h('p', { class: 'none' }, 'ยังไม่มีข้อเสนอค้างอยู่ เปิดตารางแล้วกด "ตีความจากข้อมูลจริง" เพื่อให้ระบบเสนอความหมาย'));

    // ---- ผู้เขียนความหมาย: แถบเรียงจากมากไปน้อย สีเดียว ตัวเลขอยู่ที่ปลายแถบ
    var maxW = d.writers.reduce(function (m, w) { return Math.max(m, w.columns); }, 0);
    var written = t.human + t.api;
    var writers = card('ผู้เขียนความหมาย', 'จำนวนคอลัมน์ที่เขียนเองหรือนำเข้าทาง API ไม่นับ comment ในฐานข้อมูลและที่ระบบเดา',
      d.writers.length
        ? h('div', { class: 'rbars' }, d.writers.map(function (w) {
          var row = h('div', { class: 'rbar', tabindex: 0, role: 'img', 'aria-label': w.name + ' ' + num(w.columns) + ' คอลัมน์ (' + share(w.columns, written) + ')' },
            h('span', { class: 'nm', title: w.name }, w.name, w.kind === 'api' ? h('small', null, 'API') : null),
            h('div', { class: 'rb' }, h('i', { style: 'width:calc((100% - 64px) * ' + (maxW ? w.columns / maxW : 0) + ')' }), h('span', null, num(w.columns))));
          tipBind(row, w.name, function () { return [{ value: num(w.columns), label: 'คอลัมน์', share: share(w.columns, written) + ' ของที่เขียนทั้งหมด' }]; });
          return row;
        }))
        : h('p', { class: 'none' }, 'ยังไม่มีความหมายที่คนเขียนหรือนำเข้าทาง API'));

    // ---- กิจกรรมล่าสุด
    var activity = card('กิจกรรมล่าสุด', 'การซิงค์ นำเข้า เดาความหมาย และโคลน 10 รายการล่าสุดของทุกโปรเจ็ค',
      d.activity.length
        ? h('div', { class: 'scroll-x' }, h('table', { class: 'logs' },
          h('thead', null, h('tr', null, ['เวลา', 'โปรเจ็ค', 'ประเภท', 'โดย', 'ผล', 'รายละเอียด'].map(function (x) { return h('th', null, x); }))),
          h('tbody', null, d.activity.map(function (l) {
            var detail = l.status === 'ok' && l.kind === 'sync'
              ? num(l.tables_total) + ' ตาราง, ' + num(l.columns_total) + ' คอลัมน์ (ใหม่ ' + num(l.columns_new) + ', หายไป ' + num(l.columns_dropped) + ')' : (l.message || '');
            return h('tr', null, h('td', { class: 'nowrap' }, l.started_at), h('td', null, h('span', { class: 'chip' }, l.project)),
              h('td', { class: 'nowrap' }, KIND[l.kind] || l.kind), h('td', null, l.actor || ''), h('td', null, statusTag(l.status)), h('td', null, detail));
          }))))
        : h('p', { class: 'none' }, 'ยังไม่มีกิจกรรม'));

    add(box, [
      h('div', { class: 'phead' },
        h('div', null, h('h1', null, 'ภาพรวม'),
          h('div', { class: 'sub' }, h('span', null, 'ความคืบหน้าของ Data Dictionary ทุกโปรเจ็ค'), h('span', null, '· ข้อมูล ณ ' + d.generated_at))),
        h('div', { class: 'pactions', style: 'margin-left:auto' },
          btn('sync', 'รีเฟรช', function () { loadProjects().then(renderRail); loadDashboard(); }),
          btn('plus', 'เพิ่มโปรเจ็ค', function () { projectForm(null); }, 'primary'))),
      kpis, projects, h('div', { class: 'cards2' }, pending, writers), activity]);
  }

  function openProject(code, tableName) {
    S.project = S.projects.filter(function (x) { return x.code === code; })[0];
    if (!S.project) return;
    stopPoll(); tipHide();
    S.mode = 'table'; S.q = ''; S.filter = 'all'; S.page = 1; S.tables = []; S.table = null; S.colHits = [];
    renderRail(); renderProject(); presence();
    loadTables().then(function () {
      if (tableName) openTable({ name: tableName });
    });
  }

  function setHash() {
    var hsh = '#/' + encodeURIComponent(S.project.code) + (S.table ? '/' + encodeURIComponent(S.table.name) : '');
    if (location.hash !== hsh) history.replaceState(null, '', hsh);
  }

  function renderProject() {
    var p = S.project, m = document.getElementById('main');
    m.textContent = '';
    var search = h('input', { class: 'input', type: 'search', placeholder: 'ค้นหาชื่อตารางหรือคำอธิบาย', 'aria-label': 'ค้นหา', value: S.q,
      oninput: debounce(function () { S.q = search.value.trim(); S.page = 1; S.mode === 'table' ? loadTables() : loadColumns(); }, 250) });
    function seg(items, cur, pick) {
      return h('div', { class: 'seg' }, items.map(function (it) {
        return h('button', { class: it[0] === cur ? 'on' : '', onclick: function () { pick(it[0]); } }, it[1]);
      }));
    }
    var find = h('div', { class: 'tfind', id: 'tfind' });
    function drawFind() {
      find.textContent = '';
      search.placeholder = S.mode === 'table' ? 'ค้นหาชื่อตารางหรือคำอธิบาย' : 'ค้นหาชื่อคอลัมน์หรือความหมาย';
      add(find, [h('div', { class: 'search' }, ico('search'), search), h('div', { class: 'row' },
        seg([['table', 'ตาราง'], ['column', 'คอลัมน์']], S.mode, function (v) { S.mode = v; S.page = 1; drawFind(); v === 'table' ? loadTables() : loadColumns(); search.focus(); }),
        S.mode === 'table' && seg([['all', 'ทั้งหมด'], ['undocumented', 'ยังไม่ครบ'], ['pending', 'รอพิจารณา'], ['dropped', 'ถูกลบ']], S.filter, function (v) { S.filter = v; S.page = 1; drawFind(); loadTables(); })),
      h('div', { class: 'count', id: 'tcount' })]);
    }
    drawFind();

    var exportMenu = h('div', { class: 'menu' });
    var pop = null;
    exportMenu.appendChild(h('button', { class: 'btn', 'aria-haspopup': 'true', onclick: function (ev) {
      ev.stopPropagation();
      if (pop) { pop.remove(); pop = null; return; }
      var base = 'api.php?action=export&project=' + encodeURIComponent(p.code) + '&format=';
      pop = h('div', { class: 'menu-pop' },
        h('a', { href: base + 'md' }, 'Markdown (.md)'),
        h('a', { href: base + 'csv' }, 'CSV สำหรับ Excel'),
        h('a', { href: base + 'json&download=1' }, 'JSON'));
      exportMenu.appendChild(pop);
      document.addEventListener('click', function off() { if (pop) { pop.remove(); pop = null; } document.removeEventListener('click', off); });
    } }, ico('download'), h('span', null, 'ส่งออก')));

    var syncBtn = h('button', { class: 'btn primary', id: 'syncbtn', onclick: function () {
      if (!p.has_connection) { toast('ตั้งค่าการเชื่อมต่อฐานข้อมูลก่อน แล้วจึงซิงค์'); return projectForm(p); }
      drawSync({ status: 'running', message: 'กำลังเริ่มงาน', elapsed: 0 });
      // งานซิงค์ทำเบื้องหลังบนเซิร์ฟเวอร์ หน้าเว็บแค่ถามสถานะเป็นระยะ
      post('project.sync', { project_id: p.id }).catch(function (e) {
        get('sync.status', { project: p.code }).then(function (d) {
          if (!d.job || d.job.status !== 'running') { toast(e.message, true); checkSync(false); }
        }).catch(function () { toast(e.message, true); });
      });
      stopPoll(); pollTimer = setTimeout(function () { checkSync(true); }, 1000);
    } }, ico('sync'), h('span', { id: 'synclbl' }, 'ซิงค์โครงสร้าง'));

    add(m, [
      h('div', { class: 'phead' },
        h('div', null, h('h1', null, p.name),
          h('div', { class: 'sub' }, h('span', { class: 'chip' }, p.code), h('span', null, p.last_sync_at ? 'ซิงค์ล่าสุด ' + p.last_sync_at : 'ยังไม่เคยซิงค์'))),
        h('div', { class: 'pstats', id: 'pstats' }),
        h('div', { class: 'pactions' }, syncBtn, exportMenu,
          btn('bulb', 'เดาความหมาย', function () { guessModal(p); }),
          btn('spark', 'API สำหรับ Claude', function () { claudeModal(p); }),
          btn('clock', 'ประวัติ', function () { logsModal(p); }),
          btn('sliders', 'ตั้งค่า', function () { projectForm(p); }))),
      h('div', { class: 'syncbar', id: 'syncbar', role: 'status', hidden: true }),
      h('div', { class: 'work' },
        h('div', { class: 'tlist' }, find, h('div', { class: 'titems', id: 'titems' })),
        h('div', { class: 'detail', id: 'detail' }))]);
    drawStats(); drawDetail(); setHash(); checkSync(false);
  }

  // ---------- สถานะงานซิงค์เบื้องหลัง ----------
  var pollTimer = null;
  function stopPoll() { clearTimeout(pollTimer); pollTimer = null; }
  function clock(sec) { var m = Math.floor(sec / 60), x = sec % 60; return m + ':' + (x < 10 ? '0' : '') + x; }

  function drawSync(job) {
    var btn = document.getElementById('syncbtn'), barEl = document.getElementById('syncbar');
    if (!btn || !barEl) return;
    var running = !!job && job.status === 'running';
    btn.disabled = running;
    document.getElementById('synclbl').textContent = running ? 'กำลังซิงค์…' : 'ซิงค์โครงสร้าง';
    barEl.hidden = !running;
    barEl.textContent = '';
    if (!running) return;
    var p = S.project;
    add(barEl, [
      h('span', { class: 'spin', 'aria-hidden': 'true' }),
      h('b', null, 'กำลังซิงค์โครงสร้าง'),
      h('span', { class: 'msg' }, job.message || ''),
      h('span', { class: 'time' }, clock(job.elapsed || 0)),
      h('span', { class: 'muted' }, 'ปิดหน้านี้ได้ งานทำต่อบนเซิร์ฟเวอร์'),
      h('button', { class: 'btn', onclick: function () {
        post('sync.cancel', { project_id: p.id }).then(function () { toast('ยกเลิกการซิงค์แล้ว ข้อมูลเดิมไม่ถูกเปลี่ยน'); checkSync(false); })
          .catch(function (e) { toast(e.message, true); });
      } }, 'ยกเลิก')]);
  }

  /** ถามสถานะงานซิงค์ล่าสุด announce = แจ้งผลเมื่องานจบ (ใช้ตอนเฝ้างานที่กำลังเดินอยู่) */
  function checkSync(announce) {
    if (!S.project) return;
    var code = S.project.code;
    stopPoll();
    get('sync.status', { project: code }).then(function (d) {
      if (!S.project || S.project.code !== code) return;
      var j = d.job;
      drawSync(j);
      if (j && j.status === 'running') { pollTimer = setTimeout(function () { checkSync(true); }, 2000); return; }
      if (!announce || !j) return;
      if (j.status === 'ok') {
        toast('ซิงค์เสร็จ (ใช้เวลา ' + clock(j.elapsed) + '): ' + num(j.tables_total) + ' ตาราง ' + num(j.columns_total) + ' คอลัมน์ (คอลัมน์ใหม่ ' + num(j.columns_new) + ', หายไป ' + num(j.columns_dropped) + ')');
        refreshProject(true);
      } else {
        toast('ซิงค์ไม่สำเร็จ: ' + j.message, true);
      }
    }).catch(function () {
      if (S.project && S.project.code === code && announce) pollTimer = setTimeout(function () { checkSync(true); }, 5000);
    });
  }

  function drawStats() {
    var el = document.getElementById('pstats'), s = S.project.stats;
    if (!el) return;
    el.textContent = '';
    add(el, [
      h('div', { class: 'stat' }, h('b', null, num(s.tables)), 'ตาราง'),
      h('div', { class: 'stat' }, h('b', null, num(s.columns)), 'คอลัมน์'),
      h('div', { class: 'stat ringed' }, ring(s.columns_described, s.columns),
        h('div', null, h('b', null, pct(s.columns_described, s.columns) + '%'), 'มีความหมายแล้ว ' + num(s.columns_described))) ]);
  }

  function refreshProject(redraw) {
    var code = S.project.code, keep = S.table && S.table.name;
    return loadProjects().then(function () {
      S.project = S.projects.filter(function (x) { return x.code === code; })[0] || null;
      renderRail();
      if (!S.project) { S.projects.length ? openDashboard() : renderNoProject(); return; }
      if (redraw) { S.page = 1; S.table = null; renderProject(); return loadTables().then(function () { if (keep) openTable({ name: keep }); }); }
      drawStats();
    });
  }

  // ---------- รายการตาราง / ผลค้นหาคอลัมน์ ----------
  function loadTables() {
    var code = S.project.code;
    return get('tables', { project: code, q: S.q, filter: S.filter, page: S.page }).then(function (d) {
      if (!S.project || S.project.code !== code || S.mode !== 'table') return;
      S.tables = S.page === 1 ? d.tables : S.tables.concat(d.tables);
      S.total = d.total;
      drawTables();
    }).catch(function (e) { toast(e.message, true); });
  }
  function loadColumns() {
    var code = S.project.code;
    return get('search', { project: code, q: S.q }).then(function (d) {
      if (!S.project || S.project.code !== code || S.mode !== 'column') return;
      S.colHits = d.columns; drawColumnHits();
    }).catch(function (e) { toast(e.message, true); });
  }

  function tableItem(t) {
    return h('button', { class: 'titem' + (S.table && S.table.id === t.id ? ' on' : ''), 'data-id': t.id, onclick: function () { openTable({ id: t.id }); } },
      h('div', { class: 'n' }, h('b', null, t.name),
        t.columns_pending ? h('span', { class: 'pend', title: 'ข้อเสนอจากระบบที่รอพิจารณา' }, 'รอ ' + t.columns_pending) : null,
        h('span', null, t.columns_described + '/' + t.columns_total)),
      h('div', { class: 'd' }, t.description || (t.type === 'VIEW' ? 'view' : '')),
      bar(t.columns_described, t.columns_total));
  }
  function drawTables() {
    var box = document.getElementById('titems'), cnt = document.getElementById('tcount');
    if (!box) return;
    box.textContent = '';
    cnt.textContent = num(S.total) + ' ตาราง';
    if (!S.tables.length) {
      var msg = S.q ? 'ไม่พบตารางที่ตรงกับ "' + S.q + '"'
        : S.filter === 'undocumented' ? 'ทุกตารางมีคำอธิบายครบแล้ว'
        : S.filter === 'dropped' ? 'ไม่มีตารางที่ถูกลบจากฐานข้อมูลต้นทาง'
        : S.filter === 'pending' ? 'ไม่มีตารางที่มีข้อเสนอรอพิจารณา เปิดตารางแล้วกด "ตีความจากข้อมูลจริง" เพื่อให้ระบบเสนอ'
        : 'โปรเจ็คนี้ยังไม่มีตาราง กด "ซิงค์โครงสร้าง" เพื่อดึงจากฐานข้อมูล หรือให้ Claude ส่งเข้ามาทาง API';
      box.appendChild(h('div', { class: 'none' }, msg));
      return;
    }
    S.tables.forEach(function (t) { box.appendChild(tableItem(t)); });
    if (S.tables.length < S.total) {
      box.appendChild(h('button', { class: 'btn more', onclick: function () { S.page++; loadTables(); } }, 'แสดงเพิ่ม (เหลือ ' + num(S.total - S.tables.length) + ')'));
    }
  }
  function drawColumnHits() {
    var box = document.getElementById('titems'), cnt = document.getElementById('tcount');
    if (!box) return;
    box.textContent = '';
    cnt.textContent = S.q.length < 2 ? 'พิมพ์อย่างน้อย 2 ตัวอักษร' : 'พบ ' + num(S.colHits.length) + ' คอลัมน์' + (S.colHits.length >= 300 ? ' (แสดง 300 รายการแรก)' : '');
    if (S.q.length >= 2 && !S.colHits.length) box.appendChild(h('div', { class: 'none' }, 'ไม่พบคอลัมน์ที่ตรงกับ "' + S.q + '"'));
    S.colHits.forEach(function (c) {
      box.appendChild(h('button', { class: 'titem', onclick: function () { openTable({ id: c.table_id }, c.column_name); } },
        h('div', { class: 'n' }, h('b', null, c.column_name), h('span', null, c.column_type || '')),
        h('div', { class: 'd' }, h('mark', null, c.table_name), c.description ? ' ' + c.description : '')));
    });
  }

  // ---------- รายละเอียดตาราง ----------
  function openTable(ref, flashCol) {
    var p = { project: S.project.code };
    if (ref.id) p.id = ref.id; else p.name = ref.name;
    return get('table', p).then(function (d) {
      S.table = d.table; S.flashCol = flashCol || null;
      drawDetail(); setHash();
      Array.prototype.forEach.call(document.querySelectorAll('.titem[data-id]'), function (el) {
        el.classList.toggle('on', Number(el.getAttribute('data-id')) === S.table.id);
      });
    }).catch(function (e) { toast(e.message, true); });
  }

  var savedEl = null;
  function markSaved(ok, msg) {
    if (!savedEl) return;
    savedEl.className = 'saved' + (ok ? '' : ' err');
    savedEl.textContent = ok ? 'บันทึกแล้ว ' + new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : msg;
  }

  function syncCounts() {
    var t = S.table, live = t.columns.filter(function (c) { return !c.dropped; });
    var done = live.filter(function (c) { return c.description; }).length;
    var row = S.tables.filter(function (x) { return x.id === t.id; })[0];
    if (row) {
      S.project.stats.columns_described += done - row.columns_described;
      row.columns_described = done; row.description = t.description;
      row.columns_pending = live.filter(function (c) { return c.suggestion; }).length;
      var el = document.querySelector('.titem[data-id="' + t.id + '"]');
      if (el) el.replaceWith(tableItem(row));
      drawStats(); renderRail();
    }
  }

  function drawDetail() {
    var box = document.getElementById('detail'), t = S.table;
    if (!box) return;
    box.textContent = '';
    if (!t) {
      box.appendChild(h('div', { class: 'empty' }, h('div', { class: 'art' }, ico('table')), h('h2', null, 'เลือกตารางทางซ้าย'),
        h('p', { class: 'muted' }, 'เลือกตารางเพื่อดูคอลัมน์และเขียนความหมาย ช่องที่แก้จะบันทึกเองเมื่อออกจากช่อง')));
      return;
    }
    savedEl = h('span', { class: 'saved', 'aria-live': 'polite' });
    function saveTable(field, el) {
      var body = { id: t.id }; body[field] = el.value;
      post('table.save', body).then(function () { t[field] = el.value.trim(); markSaved(true); syncCounts(); })
        .catch(function (e) { markSaved(false, e.message); });
    }
    var tdesc = h('textarea', { rows: 1, placeholder: 'ตารางนี้เก็บข้อมูลอะไร ใช้ตอนไหน', value: t.description,
      oninput: function () { autosize(tdesc); }, onchange: function () { saveTable('description', tdesc); } });
    var tnotes = h('textarea', { rows: 1, placeholder: 'ข้อควรระวัง เงื่อนไขพิเศษ', value: t.notes,
      oninput: function () { autosize(tnotes); }, onchange: function () { saveTable('notes', tnotes); } });
    var tmod = h('input', { placeholder: 'เช่น OPD, IPD, ห้องยา', value: t.module, onchange: function () { saveTable('module', tmod); } });

    var live = t.columns.filter(function (c) { return !c.dropped; });
    box.appendChild(h('div', { class: 'dhead' },
      h('div', { class: 'dtitle' }, h('h2', null, t.name),
        t.type === 'VIEW' && h('span', { class: 'tag' }, 'view'),
        t.dropped && h('span', { class: 'tag drop' }, 'ไม่พบในฐานข้อมูลแล้ว'),
        h('span', { class: 'muted' }, num(live.length) + ' คอลัมน์' + (t.row_estimate !== null ? ' · ประมาณ ' + num(t.row_estimate) + ' แถว' : '') + (t.engine ? ' · ' + t.engine : '')),
        savedEl),
      h('div', { class: 'dmeta' },
        h('label', null, h('span', null, 'คำอธิบายตาราง'), tdesc),
        h('label', null, h('span', null, 'กลุ่มงาน'), tmod)),
      h('div', { class: 'dmeta', style: 'grid-template-columns:1fr' }, h('label', null, h('span', null, 'หมายเหตุ'), tnotes)),
      h('p', { class: 'hint' }, 'กด ', h('kbd', null, 'Enter'), ' เพื่อบันทึกและไปแถวถัดไป, ', h('kbd', null, 'Shift'), '+', h('kbd', null, 'Enter'), ' เพื่อขึ้นบรรทัดใหม่')));

    // ---- ตีความจากข้อมูลจริง / เดาจากชื่อ: ตัวอย่างข้อมูลและข้อเสนอรอพิจารณาของตารางนี้
    var rows = {}, pending = [];
    var SRC_LABEL = { same: 'ชื่อเดียวกัน', dict: 'พจนานุกรม', ref: 'ตารางอ้างอิง', pattern: 'แปลจากชื่อ', data: 'จากข้อมูล', shape: 'ลักษณะข้อมูล' };
    var gbar = h('div', { class: 'gbar', role: 'status', hidden: true });
    function drawGbar(msg) {
      pending = pending.filter(function (x) { return !x.done; });
      var sure = pending.filter(function (x) { return !x.weak; });
      gbar.textContent = '';
      gbar.hidden = !msg && !pending.length;
      if (gbar.hidden) return;
      add(gbar, [h('span', null, (msg ? msg + ' ' : '') + (pending.length ? 'มีข้อเสนอรอพิจารณา ' + num(pending.length) + ' รายการ ตรวจแล้วกด "ใช้" ทีละช่อง หรือ' : '')),
        sure.length ? h('button', { class: 'btn', title: 'ไม่รวมข้อเสนอที่บอกได้แค่ลักษณะข้อมูล', onclick: function () { sure.forEach(function (x) { x.use(); }); drawGbar(''); } }, 'ใช้ทั้งหมด (' + num(sure.length) + ')') : null,
        pending.length ? h('button', { class: 'btn', onclick: function () {
          post('guess.clear', { project_id: S.project.id, id: t.id }).catch(function (e) { toast(e.message, true); });
          pending.slice().forEach(function (x) { x.drop(true); }); drawGbar('');
        } }, 'ไม่ใช้ทั้งหมด') : null]);
    }
    function forget(c, field) { // ลบข้อเสนอของช่องนี้ออกจากข้อมูลในหน้า
      if (!c.suggestion) return;
      delete c.suggestion[field];
      if (!c.suggestion.description && !c.suggestion.allowed_values && !c.suggestion.ref) c.suggestion = null;
      syncCounts();
    }
    function offer(R, field, text, why, weak, source) {
      if (!R || R.c.dropped || R.c[field]) return;
      var slot = R.slots[field], item = { done: false, weak: !!weak };
      item.drop = function (quiet) {
        item.done = true; slot.textContent = '';
        if (!quiet) post('column.save', { id: R.c.id, dismiss: field }).catch(function () { /* ข้อเสนอที่ยังไม่ได้เก็บ */ });
        forget(R.c, field);
      };
      item.use = function () {
        var el = R.els[field];
        el.value = text; autosize(el); R.save(field, el);
        item.done = true; slot.textContent = ''; forget(R.c, field);
      };
      slot.textContent = '';
      slot.appendChild(h('div', { class: 'sugg' + (weak ? ' weak' : ''), title: why || '' },
        h('span', null, text, source ? h('small', null, SRC_LABEL[source] || source) : null),
        h('button', { class: 'mini', onclick: function () { item.use(); drawGbar(''); } }, 'ใช้'),
        h('button', { class: 'mini x', 'aria-label': 'ไม่ใช้ข้อเสนอนี้', onclick: function () { item.drop(); drawGbar(''); } }, '×')));
      pending.push(item);
    }
    function showColumn(R) { // ตัวอย่างข้อมูลและข้อเสนอที่เก็บไว้ของคอลัมน์
      var sm = R.c.sample, sg = R.c.suggestion;
      R.info.textContent = ''; R.vals.textContent = '';
      ['description', 'allowed_values', 'ref'].forEach(function (f) { R.slots[f].textContent = ''; });
      if (sm) {
        R.info.appendChild(h('div', { class: 'shape' }, 'ข้อมูลที่พบ: ' + sm.shape + (sm.nulls ? ' · ว่าง ' + num(sm.nulls) + ' แถว' : '')));
        add(R.vals, [sm.values.map(function (x) { return h('span', { class: 'val' }, x.value, x.label ? h('em', null, x.label) : null, h('i', null, num(x.count))); }),
          sm.values.length && sm.distinct > sm.values.length ? h('span', { class: 'val nul' }, 'และอีก ' + num(sm.distinct - sm.values.length) + ' ค่า') : null]);
      }
      if (sg) {
        if (sg.description) offer(R, 'description', sg.description, sg.why, sg.weak, sg.source);
        if (sg.allowed_values) offer(R, 'allowed_values', sg.allowed_values, 'จากข้อมูลที่อ่านมา ชนิดข้อมูล หรือชื่อของรหัสในตารางอ้างอิง');
        if (sg.ref) offer(R, 'ref', sg.ref, 'ค่าในคอลัมน์ตรงกับรหัสในตารางนี้ หรือชื่อตรงกับ primary key');
      }
    }
    var dataBtn = btn('eye', 'ตีความจากข้อมูลจริง', function () {
      dataBtn.disabled = true;
      post('guess.data', { project_id: S.project.id, id: t.id }).then(function (d) {
        d.columns.forEach(function (x) {
          var c = t.columns.filter(function (k) { return k.id === x.id; })[0];
          if (c) { c.sample = x.sample; c.suggestion = x.suggestion; }
        });
        S.gmsg = d.rows_sampled
          ? 'อ่าน ' + num(d.rows_sampled) + ' แถว' + (d.newest ? 'ล่าสุด' : 'แรก') + 'ของตารางแล้ว' + (d.stored ? ' เก็บตัวอย่างและข้อเสนอไว้ให้พิจารณาภายหลังได้' : ' (ยังไม่ได้เก็บผล: ผู้ดูแลต้องรัน database/migrate_20261110_samples.sql ก่อน)')
          : 'ตารางนี้ยังไม่มีข้อมูล จึงเดาได้จากชื่อคอลัมน์เท่านั้น';
        drawDetail(); syncCounts();
      }).catch(function (e) { toast(e.message, true); dataBtn.disabled = false; });
    }, 'primary');
    dataBtn.title = 'อ่านแถวล่าสุดของตารางจากฐานข้อมูลต้นทาง ดูว่าแต่ละคอลัมน์เก็บข้อมูลลักษณะใด คอลัมน์ที่เป็นรหัสจะตามไปดูตารางที่อ้างอิง แล้วเก็บตัวอย่างค่าที่พบซ้ำไว้ให้พิจารณา';
    if (!S.project.has_connection || t.type === 'VIEW' || t.dropped) dataBtn.disabled = true;
    var nameBtn = btn('bulb', 'เดาจากชื่อคอลัมน์', function () {
      nameBtn.disabled = true;
      get('guess.table', { project: S.project.code, id: t.id }).then(function (d) {
        var n = 0;
        d.suggestions.forEach(function (sg) {
          var c = t.columns.filter(function (k) { return k.id === sg.id; })[0];
          if (!c) return;
          var cur = c.suggestion || {};
          if (sg.description && !cur.description) { cur.description = sg.description; cur.source = sg.source; cur.why = sg.why; n++; }
          if (sg.allowed_values && !cur.allowed_values) { cur.allowed_values = sg.allowed_values; n++; }
          if (sg.ref && !cur.ref) { cur.ref = sg.ref; n++; }
          c.suggestion = cur;
        });
        S.gmsg = n ? '' : 'ไม่มีข้อเสนอเพิ่มจากชื่อคอลัมน์สำหรับช่องที่ยังว่าง';
        drawDetail();
      }).catch(function (e) { toast(e.message, true); nameBtn.disabled = false; });
    });
    nameBtn.title = 'ใช้กฎจากชื่อคอลัมน์อย่างเดียว ไม่อ่านข้อมูลในตาราง ข้อเสนอชุดนี้ไม่ถูกเก็บ';
    var sampledAt = t.columns.map(function (c) { return c.sample && c.sample.at; }).filter(Boolean).sort().pop();
    box.querySelector('.dhead').appendChild(h('div', { class: 'dtools' }, dataBtn, nameBtn,
      sampledAt ? h('span', { class: 'muted' }, 'อ่านตัวอย่างข้อมูลล่าสุด ' + sampledAt) : null));
    box.querySelector('.dhead').appendChild(gbar);

    // หัวคอลัมน์ comment: ปุ่มคัดลอก comment ไปใส่ทุกช่องความหมายที่ยังว่าง
    var copyTh = h('th', { class: 'cmt' });
    function drawCopyAll() {
      var todo = t.columns.filter(function (c) { return !c.dropped && c.db_comment && !c.description; });
      copyTh.textContent = '';
      add(copyTh, ['Comment ในฐานข้อมูล', todo.length ? h('button', { class: 'cbtn', title: 'คัดลอก comment ไปใส่ช่องความหมายที่ยังว่าง ไม่ทับช่องที่มีความหมายแล้ว', onclick: function () {
        todo.forEach(function (c) { rows[c.id].useComment(); });
      } }, 'ใช้กับช่องว่างทั้ง ' + num(todo.length) + ' ช่อง') : null]);
    }
    drawCopyAll();

    var tbody = h('tbody');
    t.columns.forEach(function (c) {
      var tr = h('tr', { class: (c.description ? 'done' : '') + (c.dropped ? ' dropped' : '') });
      var R = rows[c.id] = { c: c, els: {}, slots: {}, vals: h('div', { class: 'vals' }), info: h('div', { class: 'info' }) };
      var gtag = c.guessed ? h('button', { class: 'gtag', title: 'ระบบเดาความหมายนี้ให้ กดเมื่อตรวจแล้วว่าถูกต้อง', onclick: function () {
        post('column.save', { id: c.id, confirm: 1 }).then(function () { c.guessed = false; gtag.remove(); markSaved(true); })
          .catch(function (e) { markSaved(false, e.message); });
      } }, 'ระบบเดา · กดเพื่อยืนยัน') : null;
      function save(field, el) {
        var body = { id: c.id }; body[field] = el.value;
        post('column.save', body).then(function () {
          c[field] = el.value.trim();
          if (field === 'description') { tr.classList.toggle('done', !!c.description); syncCounts(); c.guessed = false; if (gtag) gtag.remove(); drawComment(); drawCopyAll(); }
          markSaved(true);
        }).catch(function (e) { markSaved(false, e.message); });
      }
      R.save = save;
      // comment ที่เขียนกำกับไว้ในฐานข้อมูลต้นทาง พร้อมปุ่มคัดลอกไปใส่ช่องความหมาย
      var ctd = h('td', { class: 'cmt' });
      function useComment() {
        var el = R.els.description;
        el.value = c.db_comment; autosize(el); save('description', el);
      }
      R.useComment = useComment;
      function drawComment() {
        ctd.textContent = '';
        if (!c.db_comment) { ctd.appendChild(h('span', { class: 'nocmt' }, 'ไม่มี comment')); return; }
        ctd.appendChild(h('div', { class: 'ctext' }, c.db_comment));
        if (c.dropped) return;
        if (c.db_comment.trim() === (c.description || '').trim()) {
          ctd.appendChild(h('span', { class: 'cused' }, 'ใช้เป็นความหมายแล้ว'));
        } else {
          ctd.appendChild(h('button', { class: 'cbtn', title: c.description ? 'แทนความหมายที่มีอยู่ด้วย comment นี้' : 'คัดลอก comment นี้ไปใส่ช่องความหมาย',
            'aria-label': 'ใช้ comment เป็นความหมายของ ' + c.name, onclick: useComment }, c.description ? 'แทนด้วย comment' : 'ใช้เป็นความหมาย', ico('arrowr')));
        }
      }
      drawComment();
      function cell(field, placeholder, col) {
        R.slots[field] = h('div', { class: 'slot' });
        var el = R.els[field] = h('textarea', { rows: 1, placeholder: placeholder, value: c[field], 'data-col': col, 'aria-label': placeholder + ' ของ ' + c.name,
          oninput: function () { autosize(el); },
          onchange: function () { save(field, el); },
          onkeydown: function (ev) {
            if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
              ev.preventDefault();
              var next = tr.nextElementSibling && tr.nextElementSibling.querySelector('[data-col="' + col + '"]');
              if (next) next.focus(); else el.blur();
            }
          } });
        return el;
      }
      var meta = [];
      if (c.key === 'PRI') meta.push(h('span', { class: 'tag pk' }, 'PK'));
      else if (c.key === 'UNI') meta.push(h('span', { class: 'tag key' }, 'unique'));
      else if (c.key === 'MUL') meta.push(h('span', { class: 'tag key' }, 'index'));
      if (!c.nullable) meta.push(h('span', null, 'not null'));
      if (c['default'] !== null && c['default'] !== '') meta.push(h('span', null, 'default ' + c['default']));
      if (c.extra) meta.push(h('span', null, c.extra));
      if (c.dropped) meta.push(h('span', { class: 'tag drop' }, 'ถูกลบ'));
      add(tr, [
        h('td', { class: 'name' }, h('div', { class: 'cname' }, c.name), h('div', { class: 'ctype' }, c.type || ''), meta.length ? h('div', { class: 'cmeta' }, meta) : null),
        ctd,
        h('td', null, cell('description', 'ความหมาย', 'd'), gtag, R.slots.description, R.info),
        h('td', null, cell('allowed_values', 'ค่าที่เป็นไปได้', 'v'), R.slots.allowed_values, R.vals),
        h('td', { class: 'ref' }, cell('ref', 'ตาราง.คอลัมน์', 'r'), R.slots.ref)]);
      if (S.flashCol && S.flashCol === c.name) tr.classList.add('flash');
      tbody.appendChild(tr);
    });
    box.appendChild(h('table', { class: 'cols' },
      h('thead', null, h('tr', null, h('th', null, 'คอลัมน์'), copyTh, h('th', null, 'ความหมาย'), h('th', null, 'ค่าที่เป็นไปได้ / รหัส'), h('th', { class: 'ref' }, 'อ้างอิงไปที่'))),
      tbody));
    if (!t.columns.length) box.appendChild(h('div', { class: 'none' }, 'ตารางนี้ยังไม่มีข้อมูลคอลัมน์'));
    t.columns.forEach(function (c) { showColumn(rows[c.id]); });
    drawGbar(S.gmsg || ''); S.gmsg = '';
    if (t.indexes.length) {
      box.appendChild(h('details', { class: 'idx' }, h('summary', null, 'index ' + t.indexes.length + ' รายการ'),
        h('ul', null, t.indexes.map(function (i) { return h('li', null, i.name + (Number(i.is_unique) ? ' (unique)' : '') + ': ' + i.columns); }))));
    }
    Array.prototype.forEach.call(box.querySelectorAll('textarea'), autosize);
    box.scrollTop = 0;
    var fl = box.querySelector('tr.flash');
    if (fl) { fl.scrollIntoView({ block: 'center' }); setTimeout(function () { fl.classList.remove('flash'); }, 1600); }
  }

  // ---------- หน้าต่างย่อย ----------
  function modal(title, body, footer) {
    var veil = h('div', { class: 'veil', onmousedown: function (ev) { if (ev.target === veil) close(); } });
    function close() { veil.remove(); document.removeEventListener('keydown', esc); }
    function esc(ev) { if (ev.key === 'Escape') close(); }
    document.addEventListener('keydown', esc);
    veil.appendChild(h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
      h('header', null, h('h3', null, title), h('button', { class: 'x', 'aria-label': 'ปิด', onclick: close }, '×')),
      h('div', { class: 'body' }, body), footer ? h('footer', null, footer) : null));
    document.body.appendChild(veil);
    var first = veil.querySelector('input, select, textarea, button.btn');
    if (first) first.focus();
    return close;
  }

  function projectForm(p) {
    var isNew = !p; p = p || {};
    function inp(k, attrs) { return h('input', Object.assign({ value: p[k] === null || p[k] === undefined ? '' : p[k] }, attrs || {})); }
    var f = {
      code: inp('code', { placeholder: 'เช่น hosxp', required: true }), name: inp('name', { placeholder: 'เช่น HOSxP โรงพยาบาล', required: true }),
      description: h('textarea', { value: p.description || '' }),
      conn_driver: h('select', null, h('option', { value: '' }, 'ยังไม่ตั้งค่า'), h('option', { value: 'mysql' }, 'MySQL / MariaDB'), h('option', { value: 'pgsql' }, 'PostgreSQL')),
      conn_host: inp('conn_host', { placeholder: '192.168.x.x' }), conn_port: inp('conn_port', { type: 'number', placeholder: '3306' }),
      conn_database: inp('conn_database'), conn_schema: inp('conn_schema', { placeholder: 'public' }),
      conn_username: inp('conn_username', { autocomplete: 'off' }),
      conn_password: h('input', { type: 'password', autocomplete: 'new-password', placeholder: p.has_password ? 'เว้นว่างเพื่อใช้รหัสเดิม' : '' }),
      conn_charset: inp('conn_charset', { placeholder: 'utf8mb4' })
    };
    f.conn_driver.value = p.conn_driver || '';
    var schemaField = h('label', { class: 'field' }, h('span', null, 'Schema'), f.conn_schema);
    var charsetField = h('label', { class: 'field narrow' }, h('span', null, 'Charset'), f.conn_charset);
    function driverChanged() {
      schemaField.style.display = f.conn_driver.value === 'pgsql' ? '' : 'none';
      charsetField.style.display = f.conn_driver.value === 'mysql' ? '' : 'none';
      f.conn_port.placeholder = f.conn_driver.value === 'pgsql' ? '5432' : '3306';
    }
    f.conn_driver.addEventListener('change', driverChanged); driverChanged();
    var note = h('div');
    function say(kind, msg) { note.className = 'note ' + kind; note.textContent = msg; }
    function values() { var o = { id: p.id || 0 }; Object.keys(f).forEach(function (k) { o[k] = f[k].value; }); return o; }
    function save() {
      return post('project.save', values()).then(function (d) {
        p = d.project; f.conn_password.value = ''; f.conn_password.placeholder = p.has_password ? 'เว้นว่างเพื่อใช้รหัสเดิม' : '';
        return loadProjects().then(function () { return d.project; });
      });
    }
    var close;
    var body = h('form', { onsubmit: function (ev) { ev.preventDefault(); } },
      h('div', { class: 'row' }, h('label', { class: 'field' }, h('span', null, 'รหัสโปรเจ็ค (ใช้ใน API)'), f.code), h('label', { class: 'field' }, h('span', null, 'ชื่อโปรเจ็ค'), f.name)),
      h('label', { class: 'field' }, h('span', null, 'รายละเอียด'), f.description),
      h('h4', null, 'การเชื่อมต่อฐานข้อมูลต้นทาง'),
      h('p', { class: 'note' }, 'การซิงค์อ่านเฉพาะโครงสร้างตาราง ไม่อ่านข้อมูล ส่วนปุ่ม "ตีความจากข้อมูลจริง" จะอ่านแถวล่าสุดของตารางเฉพาะตอนที่มีคนกด และต้องมีสิทธิ์ SELECT บนตารางนั้น แนะนำให้ใช้ผู้ใช้ฐานข้อมูลที่มีสิทธิ์อ่านอย่างเดียว'),
      h('div', { class: 'row' }, h('label', { class: 'field' }, h('span', null, 'ชนิดฐานข้อมูล'), f.conn_driver), h('label', { class: 'field' }, h('span', null, 'Host'), f.conn_host),
        h('label', { class: 'field narrow' }, h('span', null, 'Port'), f.conn_port)),
      h('div', { class: 'row' }, h('label', { class: 'field' }, h('span', null, 'ชื่อฐานข้อมูล'), f.conn_database), schemaField, charsetField),
      h('div', { class: 'row' }, h('label', { class: 'field' }, h('span', null, 'ผู้ใช้'), f.conn_username), h('label', { class: 'field' }, h('span', null, 'รหัสผ่าน'), f.conn_password)),
      note);
    var foot = [
      !isNew && h('button', { class: 'btn danger left', onclick: function () {
        var typed = h('input', { class: 'input', placeholder: p.code });
        var c2 = modal('ลบโปรเจ็ค ' + p.name, [
          h('p', { class: 'note bad' }, 'ตาราง คอลัมน์ และคำอธิบายทั้งหมดของโปรเจ็คนี้จะถูกลบถาวร'),
          h('label', { class: 'field' }, h('span', null, 'พิมพ์รหัสโปรเจ็ค ' + p.code + ' เพื่อยืนยัน'), typed)],
          h('button', { class: 'btn danger', onclick: function () {
            post('project.delete', { project_id: p.id, confirm_code: typed.value }).then(function () {
              c2(); close(); toast('ลบโปรเจ็คแล้ว'); S.project = null; S.table = null; history.replaceState(null, '', '#/');
              return loadProjects().then(function () { renderShell(); if (S.projects.length) openDashboard(); });
            }).catch(function (e) { toast(e.message, true); });
          } }, 'ลบโปรเจ็ค'));
      } }, 'ลบโปรเจ็ค'),
      !isNew && h('button', { class: 'btn', onclick: function () { cloneForm(p, function () { close(); }); } }, 'โคลนโปรเจ็ค'),
      h('button', { class: 'btn', onclick: function () {
        say('', 'กำลังทดสอบ…');
        save().then(function (pr) { return post('project.test', { project_id: pr.id }); })
          .then(function (d) { say('ok', 'เชื่อมต่อได้ เซิร์ฟเวอร์รุ่น ' + d.server_version + ' พบ ' + num(d.table_count) + ' ตาราง'); })
          .catch(function (e) { say('bad', e.message); });
      } }, 'บันทึกและทดสอบการเชื่อมต่อ'),
      h('button', { class: 'btn primary', onclick: function () {
        save().then(function (pr) {
          close(); toast('บันทึกโปรเจ็คแล้ว');
          if (isNew || !S.project || S.project.id !== pr.id) { if (!document.getElementById('rail')) renderShell(); openProject(pr.code); }
          else { S.project = S.projects.filter(function (x) { return x.id === pr.id; })[0]; renderRail(); renderProject(); loadTables(); }
        }).catch(function (e) { say('bad', e.message); });
      } }, 'บันทึก')];
    close = modal(isNew ? 'เพิ่มโปรเจ็ค' : 'ตั้งค่าโปรเจ็ค', body, foot);
  }

  function cloneForm(p, done) {
    var code = h('input', { value: (p.code + '-copy').slice(0, 50), required: true });
    var name = h('input', { value: p.name + ' (สำเนา)', required: true });
    var conn = h('input', { type: 'checkbox' }); conn.checked = !!p.has_connection; conn.disabled = !p.has_connection;
    var note = h('div');
    var go = h('button', { class: 'btn primary' }, 'โคลนโปรเจ็ค');
    var close = modal('โคลนโปรเจ็ค: ' + p.name, [
      h('p', { class: 'muted', style: 'margin-bottom:12px' }, 'สร้างโปรเจ็คใหม่จากโปรเจ็คนี้ คัดลอกตาราง คอลัมน์ index ความหมาย ค่าที่เป็นไปได้ อ้างอิง หมายเหตุ และกลุ่มงานทั้งหมด โปรเจ็คเดิมไม่ถูกแก้'),
      h('div', { class: 'row' }, h('label', { class: 'field' }, h('span', null, 'รหัสโปรเจ็คใหม่ (ใช้ใน API)'), code), h('label', { class: 'field' }, h('span', null, 'ชื่อโปรเจ็คใหม่'), name)),
      h('label', { class: 'check' }, conn, h('span', null, 'คัดลอกค่าการเชื่อมต่อฐานข้อมูลด้วย' + (p.has_connection ? ' (แก้เป็นฐานข้อมูลอื่นได้ภายหลังที่ปุ่ม "ตั้งค่า")' : ' (โปรเจ็คนี้ยังไม่ได้ตั้งค่า)'))),
      h('p', { class: 'note' }, 'ไม่คัดลอก: API key (สร้างใหม่ที่ปุ่ม "API สำหรับ Claude"), ประวัติการซิงค์และนำเข้า, ตัวอย่างข้อมูลและข้อเสนอรอพิจารณา'),
      note], go);
    go.addEventListener('click', function () {
      go.disabled = true; note.className = 'note'; note.textContent = 'กำลังโคลน… โปรเจ็คขนาดใหญ่อาจใช้เวลาสักครู่';
      post('project.clone', { project_id: p.id, code: code.value, name: name.value, copy_connection: conn.checked ? 1 : 0 }).then(function (d) {
        close(); if (done) done();
        toast('โคลนโปรเจ็คแล้ว: ' + num(d.project.stats.tables) + ' ตาราง ' + num(d.project.stats.columns) + ' คอลัมน์');
        return loadProjects().then(function () { openProject(d.project.code); });
      }).catch(function (e) { note.className = 'note bad'; note.textContent = e.message; go.disabled = false; });
    });
  }

  function userForm() {
    var u = h('input', { autocomplete: 'off' }), n = h('input'), pw = h('input', { type: 'password', autocomplete: 'new-password' });
    var note = h('div');
    var close = modal('เพิ่มผู้ใช้', [
      h('label', { class: 'field' }, h('span', null, 'ชื่อผู้ใช้'), u), h('label', { class: 'field' }, h('span', null, 'ชื่อที่แสดง'), n),
      h('label', { class: 'field' }, h('span', null, 'รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)'), pw), note],
      h('button', { class: 'btn primary', onclick: function () {
        post('user.add', { username: u.value, display_name: n.value, password: pw.value })
          .then(function () { close(); toast('เพิ่มผู้ใช้ ' + u.value + ' แล้ว'); })
          .catch(function (e) { note.className = 'note bad'; note.textContent = e.message; });
      } }, 'เพิ่มผู้ใช้'));
  }

  function logsModal(p) {
    var body = h('div', null, 'กำลังโหลด…');
    modal('ประวัติการซิงค์และนำเข้า', body);
    get('logs', { project: p.code }).then(function (d) {
      body.textContent = '';
      if (!d.logs.length) { body.appendChild(h('p', { class: 'muted' }, 'ยังไม่มีประวัติ')); return; }
      body.appendChild(h('table', { class: 'logs' },
        h('thead', null, h('tr', null, ['เวลา', 'ประเภท', 'โดย', 'ผล', 'รายละเอียด'].map(function (x) { return h('th', null, x); }))),
        h('tbody', null, d.logs.map(function (l) {
          var detail = l.status === 'ok' && l.kind === 'sync'
            ? num(l.tables_total) + ' ตาราง, ' + num(l.columns_total) + ' คอลัมน์ (ใหม่ ' + num(l.columns_new) + ', หายไป ' + num(l.columns_dropped) + ')' : (l.message || '');
          return h('tr', null, h('td', null, l.started_at), h('td', null, l.kind === 'sync' ? 'ซิงค์' : l.kind === 'guess' ? 'เดาความหมาย' : l.kind === 'clone' ? 'โคลน' : 'นำเข้า'), h('td', null, l.actor || ''),
            h('td', null, h('span', { class: 'tag' + (l.status === 'error' ? ' drop' : '') }, l.status === 'ok' ? 'สำเร็จ' : l.status === 'running' ? 'กำลังทำงาน' : 'ผิดพลาด')), h('td', null, detail));
        }))));
    }).catch(function (e) { body.textContent = e.message; });
  }

  function guessModal(p) {
    var SRC = [['same', 'คอลัมน์ชื่อเดียวกันที่มีคนเขียนความหมายไว้แล้ว', true],
      ['dict', 'พจนานุกรมชื่อที่พบบ่อย เช่น hn, vn, cid, created_at และชื่อกว้าง ๆ ที่เติมหัวเรื่องของตาราง เช่น name ในตารางแผนก = ชื่อแผนก', true],
      ['ref', 'ตารางที่คอลัมน์อ้างอิงถึง เช่น status_id ที่ชี้ไปตารางสถานะ', true],
      ['pattern', 'แปลจากชื่อ เช่น photo_id = รหัสรูปภาพ, discharge_date = วันที่จำหน่าย (แม่นน้อยกว่าวิธีอื่น ดูตัวอย่างก่อนเติม)', true]];
    var LABEL = { same: 'ชื่อเดียวกัน', dict: 'พจนานุกรม', ref: 'ตารางอ้างอิง', pattern: 'แปลจากชื่อ' };
    var boxes = SRC.map(function (x) { var c = h('input', { type: 'checkbox' }); c.checked = x[2]; c.value = x[0]; c.addEventListener('change', plan); return c; });
    var out = h('div'), close, seq = 0;
    var applyBtn = h('button', { class: 'btn primary', disabled: true }, 'เติมความหมาย');
    var undoBtn = h('button', { class: 'btn danger left', hidden: true });
    function picked() { return boxes.filter(function (c) { return c.checked; }).map(function (c) { return c.value; }).join(','); }
    function plan() {
      var my = ++seq, src = picked();
      applyBtn.disabled = true;
      if (!src) { out.textContent = ''; out.appendChild(h('p', { class: 'note' }, 'เลือกวิธีเดาอย่างน้อย 1 วิธี')); return; }
      out.textContent = 'กำลังนับ…';
      get('guess.plan', { project: p.code, sources: src }).then(function (d) {
        if (my !== seq) return;
        out.textContent = '';
        undoBtn.hidden = !d.guessed_now;
        undoBtn.textContent = 'ล้างที่ระบบเดาไว้ (' + num(d.guessed_now) + ')';
        if (!d.columns) { out.appendChild(h('p', { class: 'note' }, 'ไม่มีช่องว่างที่กฎเหล่านี้เดาได้')); return; }
        applyBtn.disabled = false;
        applyBtn.textContent = 'เติมความหมาย ' + num(d.columns) + ' คอลัมน์';
        add(out, [
          h('p', { class: 'note ok' }, 'เติมได้ ' + num(d.columns) + ' คอลัมน์ จาก ' + num(d.names) + ' ชื่อ: ' +
            Object.keys(LABEL).filter(function (k) { return d.by_source[k]; }).map(function (k) { return LABEL[k] + ' ' + num(d.by_source[k]); }).join(', ')),
          h('table', { class: 'logs' },
            h('thead', null, h('tr', null, ['ชื่อคอลัมน์', 'ความหมายที่จะเติม', 'ที่มา', 'จำนวนคอลัมน์'].map(function (x) { return h('th', null, x); }))),
            h('tbody', null, d.samples.map(function (r) {
              return h('tr', null, h('td', { class: 'mono' }, r.name), h('td', null, r.description), h('td', null, LABEL[r.source]), h('td', null, num(r.columns)));
            }))),
          d.names > d.samples.length ? h('p', { class: 'muted', style: 'margin-top:8px' }, 'แสดง ' + d.samples.length + ' ชื่อที่ใช้บ่อยที่สุด จากทั้งหมด ' + num(d.names) + ' ชื่อ') : null]);
      }).catch(function (e) { if (my === seq) { out.textContent = ''; out.appendChild(h('p', { class: 'note bad' }, e.message)); } });
    }
    applyBtn.addEventListener('click', function () {
      applyBtn.disabled = true;
      post('guess.apply', { project_id: p.id, sources: picked() }).then(function (d) {
        close(); toast('เติมความหมายแล้ว ' + num(d.columns) + ' คอลัมน์ รายการที่ระบบเดามีป้าย "ระบบเดา" ให้ตรวจ');
        refreshProject(true);
      }).catch(function (e) { toast(e.message, true); applyBtn.disabled = false; });
    });
    undoBtn.addEventListener('click', function () {
      undoBtn.disabled = true;
      post('guess.undo', { project_id: p.id }).then(function (d) {
        toast('ล้างความหมายที่ระบบเดาแล้ว ' + num(d.cleared) + ' คอลัมน์'); undoBtn.disabled = false;
        refreshProject(true); plan();
      }).catch(function (e) { toast(e.message, true); undoBtn.disabled = false; });
    });
    close = modal('เดาความหมายทั้งโปรเจ็ค: ' + p.name, [
      h('p', { class: 'muted', style: 'margin-bottom:12px' }, 'ระบบเติมเฉพาะช่องความหมายที่ยังว่าง ด้วยกฎในระบบเอง ไม่ส่งข้อมูลออกนอกเครื่องแม่ข่าย ไม่ทับสิ่งที่คนเขียนไว้ และล้างกลับได้'),
      SRC.map(function (x, i) { return h('label', { class: 'check' }, boxes[i], h('span', null, x[1])); }),
      out], [undoBtn, applyBtn]);
    plan();
  }

  function claudeModal(p) {
    var base = location.origin + location.pathname.replace(/[^/]*$/, '') + 'api.php';
    var keyBox = h('div', { class: 'note' }, p.api_key_hint ? 'มี API key อยู่แล้ว (ขึ้นต้นด้วย ' + p.api_key_hint + '…) ระบบไม่เก็บ key ตัวเต็ม ถ้าหายให้สร้างใหม่' : 'โปรเจ็คนี้ยังไม่มี API key');
    var guide = h('pre', { class: 'code' });
    function text(key) {
      return '## Data Dictionary\n' +
        'โปรเจ็คนี้บันทึกความหมายของตาราง/คอลัมน์ไว้ที่ระบบ Data Dictionary (โปรเจ็ค ' + p.code + ')\n' +
        'URL: ' + base + '\n' +
        'ส่ง header ทุกครั้ง:  X-API-Key: ' + (key || '<API key>') + '\n\n' +
        '- อ่านตารางที่ยังไม่มีคำอธิบาย:  GET  ?action=missing&limit=20   (หน้าถัดไปใช้ &after=<next_after>)\n' +
        '- อ่านตารางเดียว:               GET  ?action=table&name=<ชื่อตาราง>\n' +
        '- ค้นหาคอลัมน์:                 GET  ?action=search&q=<คำค้น>\n' +
        '- อ่านทั้งหมด:                  GET  ?action=export&format=json   (กรองด้วย &tables=a,b)\n' +
        '- ส่งคำอธิบาย:                  POST ?action=import  เนื้อหา JSON:\n' +
        '  {"source":"claude","overwrite":false,"tables":[{"name":"patient","description":"ทะเบียนผู้ป่วย",\n' +
        '    "columns":[{"name":"hn","description":"เลขประจำตัวผู้ป่วย","allowed_values":"","ref":""}]}]}\n\n' +
        'กติกา: เมื่อเพิ่มหรือแก้ตาราง/คอลัมน์ ให้ส่งความหมายเข้าระบบนี้ด้วย เขียนเป็นภาษาไทย กระชับ\n' +
        'overwrite=false จะเติมเฉพาะช่องที่ยังว่าง ไม่ทับสิ่งที่คนเขียนไว้ ถ้าไม่แน่ใจความหมายให้เว้นไว้ อย่าเดา';
    }
    guide.textContent = text(null);
    function copy(s) {
      if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(s).then(function () { toast('คัดลอกแล้ว'); });
      var ta = h('textarea', { value: s }); document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); toast('คัดลอกแล้ว'); } catch (e) { toast('คัดลอกไม่ได้ ให้เลือกข้อความแล้วคัดลอกเอง', true); }
      ta.remove();
    }
    modal('API สำหรับ Claude: ' + p.name, [
      h('p', { class: 'muted', style: 'margin-bottom:12px' }, 'ให้ Claude ที่เขียนโปรเจ็คนี้อ่านและส่งความหมายของคอลัมน์เข้ามาเองผ่าน API โดยวางข้อความด้านล่างในไฟล์ CLAUDE.md ของโปรเจ็ค'),
      keyBox,
      h('button', { class: 'btn', style: 'margin-bottom:14px', onclick: function () {
        post('project.key', { project_id: p.id }).then(function (d) {
          keyBox.className = 'note warn'; keyBox.textContent = '';
          add(keyBox, ['API key ใหม่ (แสดงครั้งเดียว คัดลอกเก็บไว้ตอนนี้): ', h('b', { class: 'mono' }, d.api_key)]);
          guide.textContent = text(d.api_key);
          p.api_key_hint = d.api_key.slice(0, 9);
        }).catch(function (e) { toast(e.message, true); });
      } }, p.api_key_hint ? 'สร้าง API key ใหม่ (key เดิมจะใช้ไม่ได้)' : 'สร้าง API key'),
      guide],
      h('button', { class: 'btn primary', onclick: function () { copy(guide.textContent); } }, 'คัดลอกข้อความ'));
  }

  boot();
})();
