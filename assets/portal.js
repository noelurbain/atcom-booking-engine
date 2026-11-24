/* ATCOM Booking Engine · provider portal (/portal). Plain JS; server text always goes in with textContent. */
(function () {
  'use strict';

  var C = window.ATC || {};
  var app = document.getElementById('atc-app');
  var side = document.getElementById('atc-side');
  var me = null;

  /* ---------- helpers (same as the control panel) ---------- */

  function h(tag, attrs) {
    var el = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k.indexOf('on') === 0 && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'value') el.value = v;
      else if (k === 'checked' || k === 'selected' || k === 'disabled') el[k] = !!v;
      else el.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) add(el, arguments[i]);
    return el;
  }
  function add(el, child) {
    if (child === null || child === undefined || child === false) return;
    if (Array.isArray(child)) { child.forEach(function (c) { add(el, c); }); return; }
    el.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }

  function api(method, path, body, isForm) {
    var opts = { method: method === 'GET' ? 'GET' : 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
    if (method !== 'GET' && method !== 'POST') opts.headers['X-HTTP-Method-Override'] = method;
    if (isForm) opts.body = body;
    else if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(C.rest + path, opts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error((data && data.message) || ('Error ' + res.status));
        return data;
      });
    });
  }
  var toastTimer;
  function toast(msg, bad) {
    var t = document.getElementById('atc-toast');
    t.textContent = msg; t.className = 'show' + (bad ? ' bad' : '');
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.className = ''; }, 3500);
  }
  function fail(e) { toast(e.message || String(e), true); }
  function field(label, input, help) {
    return h('label', { class: 'field' }, h('span', { text: label }), input, help ? h('small', { text: help }) : null);
  }
  // After a save the page is redrawn in place: keep the provider where they were (no jump to the top).
  function saved(data, msg) {
    var y = window.scrollY;
    app.style.minHeight = app.offsetHeight + 'px';
    me = data; nav(); toast(msg || 'Saved.'); route();
    window.scrollTo(0, y);
    setTimeout(function () { app.style.minHeight = ''; }, 600);
  }

  /* ---------- routing ---------- */

  // The setup steps, in the order a new provider does them.
  var SETUP = [
    ['company', 'Company details'],
    ['vehicles', 'Vehicles'],
    ['coverage', 'Zones you serve'],
    ['prices', 'Prices'],
    ['documents', 'Documents'],
    ['payout', 'Payout details'],
    ['agreement', 'Agreement'],
    ['directory', 'Directory profile']
  ];
  var MORE = [
    ['airports', 'Your airports'],
    ['pricelist', 'Upload price list'],
    ['extras', 'Extras'],
    ['meeting', 'Meeting travelers'],
    ['tickets', 'Tickets'],
    ['account', 'Account']
  ];
  function stepIndex(key) { for (var i = 0; i < SETUP.length; i++) if (SETUP[i][0] === key) return i; return -1; }
  function nextTodo() {
    for (var i = 0; i < SETUP.length; i++) if (me.steps[SETUP[i][0]].state === 'todo') return SETUP[i][0];
    return null;
  }

  // What to do on each step, told before the provider does it.
  var GUIDE = {
    company: { why: 'Who you are: your company name, a WhatsApp number and the languages your drivers speak.',
      todo: ['Check your company name, your name and your email.', 'Add a mobile number with WhatsApp and the languages your drivers speak.', 'Press Save.'] },
    vehicles: { why: 'The vehicle categories you sell at your airport, and the cars you drive them with.',
      todo: ['Tick each category you sell. Your car does not have to be the same model, but it must be similar and carry as many people.', 'Check the passengers, bags and free waiting time, then press Save.', 'Further down, add each of your cars: model, plate and seats.'] },
    coverage: { why: 'The areas where you pick up and drop off travelers. Each zone gets its own price.',
      todo: ['Type the name of an area (a town, a hotel zone) and press “Find on the map”. We draw it for you.', 'If the edge is not right, drag the white points. Then press “Save zone”. Do this for each area.', 'Not found? Press “Draw by hand” and click around the area on the map.'] },
    prices: { why: 'One price per zone and vehicle. Travelers can only book where there is a price.',
      todo: ['Click a box in the grid. Each row is a zone, each column a vehicle category.', 'Type the price the traveler pays, one way. We show what you get after our commission.', 'Press “Save price”, then the next box. Round trip prices are optional.'] },
    documents: { why: 'Your transport permit and your liability insurance, so we can switch you on.',
      todo: ['Upload each document as a PDF or a photo, with its expiry date.', 'We check them within two working days.'] },
    payout: { why: 'Where we send your money for each ride.',
      todo: ['Pick bank transfer or Wise, and your currency.', 'Type the account holder and the account details, then press Save.'] },
    agreement: { why: 'The rules between you and us: commission, cancellations, payments.',
      todo: ['Read the provider agreement.', 'Tick the box and press “Accept the agreement”.'] },
    directory: { why: 'Your free company page in our directory, where travelers find transport companies by city.',
      todo: ['Add your logo and a short description of your company.', 'Add your website: it shows on your page once your site links to us. We check this automatically.', 'Press Save.'] }
  };

  function path() {
    var base = new URL(C.base).pathname;
    var p = location.pathname.indexOf(base) === 0 ? location.pathname.slice(base.length) : '';
    return p.replace(/^\/+|\/+$/g, '');
  }
  function go(p) { history.pushState({}, '', C.base + p + (p ? '/' : '')); route(); window.scrollTo(0, 0); }
  window.addEventListener('popstate', route);
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-go]');
    if (a) { e.preventDefault(); go(a.getAttribute('data-go')); }
  });

  function navLink(key, label, extra) {
    var cur = path();
    return h('a', { href: C.base + key + (key ? '/' : ''), 'data-go': key, 'data-nav': key || 'home', class: cur === key || (key && cur.indexOf(key + '/') === 0) ? 'on' : '' }, extra && extra.num ? h('span', { class: 'num', text: extra.num }) : null,
      h('span', { class: 'grow', text: label }),
      key === 'bookings' && me.to_confirm ? h('i', { class: 'count', text: String(me.to_confirm), 'aria-label': me.to_confirm + ' to confirm' }) : null,
      extra && extra.st ? h('i', { class: 'dot ' + extra.st.state, title: extra.st.label, 'aria-label': extra.st.label }) : null);
  }
  function nav() {
    clear(side);
    var live = me.provider.status === 'live';
    add(side, navLink('', live ? 'Overview' : 'Start here'));
    add(side, navLink('bookings', 'Bookings'));
    add(side, h('div', { class: 'side-h', 'data-nav': 'steps' }, h('span', { text: 'Set up, step by step' }),
      SETUP.map(function (v, i) { return navLink(v[0], v[1], { num: String(i + 1), st: me.steps[v[0]] }); })));
    add(side, h('div', { class: 'side-h' }, h('span', { text: 'More' })));
    MORE.forEach(function (v) {
      add(side, navLink(v[0], v[1] + (v[0] === 'airports' && me.airports.length > 1 ? ' (' + me.airports.length + ')' : '')));
    });
    add(side, h('button', { class: 'side-help', type: 'button', 'data-nav': 'help', onclick: function () { welcomeTour(); } }, h('span', { class: 'q', text: '?' }), 'Show me around'));
  }

  function route() {
    if (!me) return;
    nav();
    var p = path();
    clear(app);
    if (p.indexOf('bookings/') === 0) return viewTrip(p.split('/')[1]);
    if (p.indexOf('tickets/') === 0) return viewTicket(parseInt(p.split('/')[1], 10));
    var views = { '': viewSetup, company: viewCompany, vehicles: viewVehicles, coverage: viewCoverage, prices: viewPrices, meeting: viewMeeting, bookings: viewTrips,
       documents: viewDocuments, payout: viewPayout, agreement: viewAgreement, account: viewAccount, tickets: viewTickets, extras: viewExtras, directory: viewDirectory, pricelist: viewPriceList, airports: viewAirports };
    var key = views[p] ? p : '';
    views[key]();
    if (stepIndex(key) >= 0) {
      var top = app.firstChild && app.firstChild.classList && app.firstChild.classList.contains('atc-head') ? app.firstChild.nextSibling : app.firstChild;
      app.insertBefore(stepBar(key), top);
    }
    if (stepIndex(key) >= 0) add(app, stepFooter(key));
    autoTour(key);
  }

  /* ---------- Step guide: where you are, what to do here, what comes next ---------- */

  function stepBar(key) {
    var i = stepIndex(key), st = me.steps[key], g = GUIDE[key];
    return h('section', { class: 'card pad stepbar', 'data-tour': 'stepbar' },
      h('div', { class: 'sb-top' },
        h('span', { class: 'sb-kicker', text: 'STEP ' + (i + 1) + ' OF ' + SETUP.length }),
        h('span', { class: 'chip ' + (st.state === 'done' ? 'green' : st.state === 'waiting' ? 'orange' : 'grey'), text: st.label }),
        h('span', { class: 'sb-dots', 'aria-hidden': 'true' }, SETUP.map(function (s, j) {
          return h('a', { href: C.base + s[0] + '/', 'data-go': s[0], tabindex: '-1', class: 'sb-dot ' + me.steps[s[0]].state + (j === i ? ' cur' : ''), title: (j + 1) + '. ' + s[1] });
        }))),
      h('p', { class: 'sb-why', text: g.why }),
      h('p', { class: 'label', text: 'What to do here' }),
      h('ol', { class: 'sb-todo' }, g.todo.map(function (t) { return h('li', { text: t }); })),
      h('div', { class: 'atc-actions' },
        h('button', { class: 'btn', type: 'button', onclick: function () { pageTour(key, true); } }, h('span', { class: 'q', text: '?' }), ' Show me how'),
        nextBtn(key, false)));
  }
  function nextBtn(key, big) {
    var i = stepIndex(key), next = SETUP[i + 1];
    var done = me.steps[key].state !== 'todo';
    if (!next) return h('a', { class: 'btn' + (done ? ' dark' : ''), href: C.base, 'data-go': '', 'data-tour': big ? 'next' : null, text: 'Back to the start page →' });
    return h('a', { class: 'btn' + (done || big ? ' dark' : ''), href: C.base + next[0] + '/', 'data-go': next[0], 'data-tour': big ? 'next' : null,
      text: (done || big ? 'Next step: ' : 'Skip to step ' + (i + 2) + ': ') + next[1] + ' →' });
  }
  function stepFooter(key) {
    var i = stepIndex(key), prev = SETUP[i - 1];
    return h('div', { class: 'stepfoot' },
      prev ? h('a', { class: 'link', href: C.base + prev[0] + '/', 'data-go': prev[0], text: '← Step ' + i + ': ' + prev[1] }) : h('a', { class: 'link', href: C.base, 'data-go': '', text: '← Start page' }),
      h('span', { class: 'grow' }),
      h('span', { class: 'small atc-muted', text: me.steps[key].state === 'todo' ? 'When this step is done:' : 'This step is done.' }),
      nextBtn(key, true));
  }

  /* ---------- Guided tour: pop-ups that explain each part before the provider uses it ---------- */

  var tourEl = null, tourOff = null;
  function endTour() {
    if (tourEl) { tourEl.remove(); tourEl = null; }
    if (tourOff) { tourOff(); tourOff = null; }
  }
  function findEl(sel) {
    if (typeof sel === 'function') return sel();
    var list = (Array.isArray(sel) ? sel : [sel]).map(function (q) { return typeof q === 'string' ? document.querySelector(q) : q; })
      .filter(function (e) { return e && e.getClientRects().length; });
    return list.length ? list : null;
  }
  function runTour(steps, onEnd) {
    endTour();
    steps = steps.filter(function (s) { return !s.el || findEl(s.el); });
    if (!steps.length) { if (onEnd) onEnd(false); return; }
    var i = 0;
    var block = h('div', { class: 'tour-block' });
    var hole = h('div', { class: 'tour-hole' });
    var pop = h('div', { class: 'tour-pop', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'tour-t' });
    var wrap = h('div', { class: 'tour' }, block, hole, pop);
    document.body.appendChild(wrap);
    tourEl = wrap;
    function show() {
      var s = steps[i];
      var last = i === steps.length - 1;
      clear(pop);
      add(pop, [
        h('div', { class: 'tour-n' }, h('span', { text: s.kicker || (steps.length > 1 ? (i + 1) + ' of ' + steps.length : '') }),
          h('span', { class: 'tour-bar' }, steps.map(function (x, j) { return h('i', { class: j <= i ? 'on' : '' }); }))),
        h('h3', { id: 'tour-t', text: s.title }),
        h('p', { text: s.text }),
        s.list ? h('ol', { class: 'tour-list' }, s.list.map(function (t) { return h('li', { text: t }); })) : null,
        h('div', { class: 'tour-acts' },
          last ? null : h('button', { class: 'link', type: 'button', text: 'Skip', onclick: function () { finish(false); } }),
          h('span', { class: 'grow' }),
          i ? h('button', { class: 'btn', type: 'button', text: 'Back', onclick: function () { i--; show(); } }) : null,
          h('button', { class: 'btn dark', type: 'button', 'data-next': '1', text: last ? (s.done || 'Got it') : 'Next →', onclick: next }))
      ]);
      var t = s.el ? findEl(s.el) : null;
      if (t) {
        var r = box(t);
        if (r.top < 70 || r.bottom > window.innerHeight - 40) t[0].scrollIntoView({ block: r.height > window.innerHeight * 0.6 ? 'start' : 'center' });
      }
      place();
      var b = pop.querySelector('[data-next]'); if (b) b.focus({ preventScroll: true });
    }
    function box(list) {
      var r = { left: Infinity, top: Infinity, right: -Infinity, bottom: -Infinity };
      list.forEach(function (e) { var b = e.getBoundingClientRect(); r.left = Math.min(r.left, b.left); r.top = Math.min(r.top, b.top); r.right = Math.max(r.right, b.right); r.bottom = Math.max(r.bottom, b.bottom); });
      r.width = r.right - r.left; r.height = r.bottom - r.top;
      return r;
    }
    function place() {
      if (!tourEl) return;
      var s = steps[i], t = s.el ? findEl(s.el) : null;
      var vw = document.documentElement.clientWidth, vh = window.innerHeight, gap = 14, pad = 6;
      if (!t) {
        wrap.className = 'tour center';
        pop.style.left = Math.max(8, (vw - pop.offsetWidth) / 2) + 'px';
        pop.style.top = Math.max(8, (vh - pop.offsetHeight) / 2) + 'px';
        return;
      }
      wrap.className = 'tour';
      var r = box(t);
      var hr = { left: Math.max(4, r.left - pad), top: Math.max(4, r.top - pad), right: Math.min(vw - 4, r.right + pad), bottom: Math.min(vh - 4, r.bottom + pad) };
      hole.style.left = hr.left + 'px'; hole.style.top = hr.top + 'px';
      hole.style.width = Math.max(0, hr.right - hr.left) + 'px'; hole.style.height = Math.max(0, hr.bottom - hr.top) + 'px';
      var pw = pop.offsetWidth, ph = pop.offsetHeight, left, top;
      if (s.right && hr.right + gap + pw <= vw - 8) { left = hr.right + gap; top = Math.max(8, Math.min(vh - ph - 8, r.top - 10)); }
      else if (hr.bottom + gap + ph <= vh - 8) { top = hr.bottom + gap; left = r.left; }
      else if (hr.top - gap - ph >= 8) { top = hr.top - gap - ph; left = r.left; }
      else if (hr.right + gap + pw <= vw - 8) { left = hr.right + gap; top = Math.max(8, Math.min(vh - ph - 8, r.top)); }
      else if (hr.left - gap - pw >= 8) { left = hr.left - gap - pw; top = Math.max(8, Math.min(vh - ph - 8, r.top)); }
      else { top = vh - ph - 12; left = (vw - pw) / 2; }
      pop.style.left = Math.max(8, Math.min(vw - pw - 8, left)) + 'px';
      pop.style.top = Math.max(8, top) + 'px';
    }
    function next() { if (i < steps.length - 1) { i++; show(); } else finish(true); }
    function finish(done) { endTour(); if (onEnd) onEnd(done); }
    function onKey(e) {
      if (e.key === 'Escape') finish(false);
      else if (e.key === 'ArrowRight' && e.target.tagName !== 'INPUT') next();
      else if (e.key === 'ArrowLeft' && i && e.target.tagName !== 'INPUT') { i--; show(); }
    }
    var onMove = function () { window.requestAnimationFrame(place); };
    window.addEventListener('resize', onMove);
    window.addEventListener('scroll', onMove, true);
    document.addEventListener('keydown', onKey);
    tourOff = function () { window.removeEventListener('resize', onMove); window.removeEventListener('scroll', onMove, true); document.removeEventListener('keydown', onKey); };
    show();
  }
  function seen(key) {
    if (!me.tour || Array.isArray(me.tour)) me.tour = {};
    me.tour[key] = 1;
    api('POST', '/tour', { key: key }).catch(function () {});
  }
  // Waits for a page part to appear (maps and price grids load after the page).
  function whenReady(sel, cb, ms) {
    var t0 = Date.now();
    (function poll() {
      if (findEl(sel)) return cb();
      if (Date.now() - t0 > (ms || 5000)) return cb();
      setTimeout(poll, 150);
    })();
  }

  function welcomeTour() {
    var p = me.provider, first = nextTodo();
    var steps = [
      { title: 'Welcome, ' + p.name + '!', text: 'We will take you through your setup, one step at a time. Here is the plan before you start. It takes about 20 minutes, and you can stop and come back whenever you like.' },
      { right: true, el: '[data-nav=steps]', title: 'Your 8 steps, in order', text: 'Do them from top to bottom. The dot next to each step turns green when it is done, orange while we review it.' },
      { right: true, el: '[data-nav=company]', title: 'Step 1 · Company details', text: GUIDE.company.why },
      { right: true, el: '[data-nav=vehicles]', title: 'Step 2 · Vehicles', text: GUIDE.vehicles.why },
      { right: true, el: '[data-nav=coverage]', title: 'Step 3 · Zones you serve', text: 'The areas you drive to, drawn on a map. You do not have to draw: type the name of a place and we draw it for you.' },
      { right: true, el: '[data-nav=prices]', title: 'Step 4 · Prices', text: GUIDE.prices.why },
      { right: true, el: '[data-nav=pricelist]', title: 'Shortcut for steps 2 to 4', text: 'Already have a price list in Excel, CSV or Google Sheets? Upload it here: we match your vehicles, draw your zones and fill in your prices. You check everything before it is saved.' },
      { right: true, el: ['[data-nav=documents]', '[data-nav=payout]', '[data-nav=agreement]'], title: 'Steps 5 to 7 · Documents, payout, agreement', text: 'Your permit and insurance, where we pay you, and the provider agreement. We review your documents and switch you on.' },
      { right: true, el: '[data-nav=directory]', title: 'Step 8 · Directory profile', text: GUIDE.directory.why },
      { right: true, el: '[data-nav=airports]', title: 'More than one airport?', text: 'Add every airport you pick up from here. Each airport has its own vehicles, zones and prices.' },
      { right: true, el: '[data-nav=help]', title: 'Lost? Click here', text: 'This tour comes back whenever you click “Show me around”. Each step also has a “Show me how” button that explains that page.' },
      { title: first ? 'Ready? Start with step ' + (stepIndex(first) + 1) : 'You have done every step', text: first ? 'Next: ' + SETUP[stepIndex(first)][1] + '. ' + GUIDE[first].why : 'We review your documents and switch you on. You can still change anything here.', done: first ? 'Start step ' + (stepIndex(first) + 1) + ' →' : 'Done' }
    ];
    runTour(steps, function (done) {
      seen('welcome');
      if (done && first) go(first);
    });
  }

  function pageTour(key, force) {
    var make = TOURS[key];
    if (!make) return;
    var steps = make();
    if (!steps.length) return;
    whenReady(steps[0].el || 'body', function () {
      if (path() !== (key || '') && !(key === '' && path() === '')) return; // the provider moved on
      runTour(steps, function () { seen(key || 'home'); });
    }, force ? 1500 : 6000);
  }

  function autoTour(key) {
    if (tourEl || !me) return;
    var t = me.tour || {};
    if (!t.welcome) { setTimeout(function () { if (!tourEl && path() === key) welcomeTour(); }, 400); return; }
    if (TOURS[key] && !t[key || 'home']) setTimeout(function () { if (!tourEl && path() === key) pageTour(key, false); }, 500);
  }

  var TOURS = {
    company: function () { return [
      { el: '#atc-app form', title: 'Your company details', text: 'Travelers see your company name on their booking. We use your phone and email to reach you about rides.' },
      { el: '#atc-app form button[type=submit]', title: 'Save', text: 'Press Save when it is right. You can change it later.' },
      { el: '[data-tour=next]', title: 'Then the next step', text: 'This button takes you to step 2: your vehicles.' }
    ]; },
    vehicles: function () { return [
      { el: '[data-tour=airportbar]', title: 'The airport you are setting up', text: 'Vehicles, zones and prices are per airport. If you serve more airports, add them here later.' },
      { el: '#atc-app .vt-grid', title: 'Tick what you sell', text: 'Each card is one of our vehicle categories. Tick the ones you sell. Your car can be another model, but it must be similar and carry as many people.' },
      { el: '#atc-app .svc.vt', title: 'Check the numbers, then Save', text: 'When you tick a category it opens: check the passengers, bags and minutes of free waiting at the airport, then press Save.' },
      { el: '[data-tour=cars]', title: 'Add your cars', text: 'Then add each car you will drive: model, plate, seats and insurance date. We need at least one.' },
      { el: '[data-tour=next]', title: 'Next: your zones', text: 'When your categories and cars are in, go to step 3.' }
    ]; },
    coverage: function () { return [
      { el: '[data-tour=find]', title: 'Start here: find an area by name', text: 'Type a town or area you drive to, like “Playa del Carmen” or “Hotel Zone”, and press “Find on the map”. We draw it for you.' },
      { el: '#atc-app .atc-map', title: 'The map', text: 'The area appears in orange. Drag the white points to move its edge, then press “Save zone”. Your saved zones are green.' },
      { el: '[data-tour=drawhand]', title: 'Or draw by hand', text: 'If we cannot find a place, press this and click around the area on the map, point by point.' },
      { el: '[data-tour=ourzones]', title: 'Zones we already drew', text: 'Outlined in black on the map. Tick the ones you serve, then press “Save the zones I ticked”.' },
      { el: '[data-tour=nearby]', title: 'Zones near your airport', text: 'We drew these for a nearby airport. Press “Use this zone” to add one to your zones here, without drawing.' },
      { el: '[data-tour=next]', title: 'Next: your prices', text: 'Once you have your zones, go to step 4 and give each one a price.' }
    ]; },
    prices: function () { return [
      { el: '#atc-app .pe-grid', title: 'Your price grid', text: 'Each row is one of your zones, each column a vehicle category you sell. Empty boxes say “Add price”: travelers cannot book there yet.' },
      { el: '#atc-app .pe-edit', title: 'Type the price here', text: 'Click a box, then type the price the traveler pays, one way, in US dollars, per vehicle. We show what you get after our commission. Press “Save price”.' },
      { el: '#atc-app .pe-pills', title: 'Round trip (optional)', text: 'Switch to Round trip to add a round trip price. Without one, a round trip costs two one-way prices.' },
      { el: '[data-tour=missing]', title: 'What is still missing', text: 'This list shows the boxes still empty. The step turns green when every zone has a price for every vehicle.' },
      { el: '[data-tour=next]', title: 'Next: your documents', text: 'Then upload your permit and insurance in step 5.' }
    ]; },
    pricelist: function () { return [
      { el: '#atc-app .pe-up', title: 'Upload your file', text: 'Pick your Excel or CSV file, or paste from Google Sheets. One row per place and a column per vehicle works best.' },
      { title: 'Then check what we found', text: 'We show how we read your vehicles, places and prices. Confirm each vehicle match and each place before saving. New places become zones you can reshape on the Zones page.' }
    ]; },
    documents: function () { return [
      { el: '#atc-app section.card', title: 'One box per document', text: 'Pick the file (PDF or photo), set the expiry date and press Upload. Do the same for each document.' },
      { el: '[data-tour=next]', title: 'Next: payout details', text: 'We check your documents within two working days. Meanwhile, go on with step 6.' }
    ]; },
    payout: function () { return [
      { el: '#atc-app form', title: 'Where we pay you', text: 'Choose bank transfer or Wise and your currency, type the account details, and press Save. Only the last digits show afterwards.' },
      { el: '[data-tour=next]', title: 'Next: the agreement', text: 'Then read and accept the provider agreement.' }
    ]; },
    agreement: function () { return [
      { el: '#atc-app .agreement', title: 'Read the agreement', text: 'Commission, cancellations and how payments work. Tick the box at the bottom and press “Accept the agreement”.' }
    ]; },
    directory: function () { return [
      { el: '#atc-app .stack', title: 'Your free directory page', text: 'Add your logo, a short description and your website. Travelers find your page when they look for transport in your city.' },
      { el: '#atc-app .dir-bl', title: 'Your website link', text: 'Your website shows on your page when your site links to us. Copy the code into your site, then press “Check now”.' }
    ]; },
    airports: function () { return [
      { el: '[data-tour=aplist]', title: 'Your airports', text: 'Every airport you serve, with how far its setup is: vehicles, zones and prices. Open one to work on it.' },
      { el: '[data-tour=apadd]', title: 'Add an airport', text: 'Type the city or the airport code and pick it from the list. Then set up its vehicles, zones and prices, like for your first airport.' }
    ]; }
  };

  function head(title, sub) {
    return h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: title }), sub ? h('p', { class: 'atc-muted', text: sub }) : null));
  }

  /* ---------- Bookings ---------- */

  function money(c) { return 'USD ' + (c / 100).toFixed(c % 100 ? 2 : 0); }
  function kv(label, value) {
    return h('div', null, h('div', { class: 'small atc-muted strong', text: label }), h('div', { class: 'strong kv-v' }, value === null || value === undefined || value === '' ? '–' : value));
  }
  var tripFilter = 'upcoming';

  function viewTrips() {
    add(app, head('Bookings', 'Every ride you operate. Confirm each new booking within 2 hours. Times are the airport’s local time.'));
    var pills = h('div', { class: 'pe-pills', role: 'group', 'aria-label': 'Show' });
    var csv = h('button', { type: 'button', class: 'btn', text: 'Download CSV' });
    var body = h('div', null, h('p', { class: 'atc-muted', text: 'Loading…' }));
    add(app, [h('div', { class: 'atc-actions', style: 'justify-content:space-between' }, pills, csv), h('section', { class: 'card' }, body)]);
    function load() {
      api('GET', '/bookings?filter=' + tripFilter).then(function (d) {
        tripFilter = d.filter;
        clear(pills);
        d.filters.forEach(function (f) {
          add(pills, h('button', { type: 'button', class: 'pe-pill' + (f.key === d.filter ? ' on' : ''), 'aria-pressed': f.key === d.filter ? 'true' : 'false', onclick: function () { tripFilter = f.key; load(); } },
            f.label + ' ', h('span', { class: 'small', text: '(' + f.count + ')' })));
        });
        clear(body);
        if (!d.rows.length) { add(body, h('p', { class: 'atc-muted pad-x', style: 'padding:18px', text: 'No bookings in this view.' })); return; }
        add(body, h('div', { class: 'scroll' }, h('table', null,
          h('thead', null, h('tr', null, ['Pickup', 'Reservation', 'Flight', 'Route', 'Vehicle', 'Passengers', 'Status', ''].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
          h('tbody', null, d.rows.map(function (r) {
            var ack = r.needs_ack ? h('button', { type: 'button', class: 'btn dark', text: 'Confirm', onclick: function (e) {
              e.target.disabled = true;
              api('POST', '/bookings/' + r.number + '/ack').then(function () { toast('Confirmed. The traveler is counting on you.'); refreshMe(); load(); }).catch(function (x) { e.target.disabled = false; fail(x); });
            } }) : null;
            return h('tr', null,
              h('td', null, h('div', { class: 'strong', text: r.time }), h('div', { class: 'small atc-muted', text: r.day })),
              h('td', null, h('a', { class: 'strong', href: C.base + 'bookings/' + r.number + '/', 'data-go': 'bookings/' + r.number, text: r.number }), h('div', { class: 'small atc-muted', text: r.direction })),
              h('td', { text: r.flight || '–' }),
              h('td', null, h('div', { text: r.from }), h('div', { class: 'small atc-muted', text: '→ ' + r.to })),
              h('td', { text: r.vehicle }),
              h('td', { text: r.pax + (r.bags ? ' · ' + r.bags + ' bags' : '') }),
              h('td', null, h('span', { class: 'chip ' + r.tone, text: r.status })),
              h('td', null, h('div', { class: 'atc-actions', style: 'flex-wrap:nowrap' }, ack, h('a', { class: 'btn', href: C.base + 'bookings/' + r.number + '/', 'data-go': 'bookings/' + r.number, text: 'Open' }))));
          })))));
      }).catch(fail);
    }
    csv.addEventListener('click', function () {
      csv.disabled = true;
      api('GET', '/bookings?all=1&filter=' + tripFilter).then(function (d) {
        var cols = [['date', 'Date'], ['time', 'Time'], ['number', 'Reservation'], ['direction', 'Direction'], ['flight', 'Flight'], ['from', 'From'], ['to', 'To'], ['vehicle', 'Vehicle'], ['pax', 'Passengers'], ['bags', 'Suitcases'], ['traveler', 'Traveler'], ['status', 'Status'], ['amount', 'You get paid (USD)']];
        var q = function (v) { v = String(v === null || v === undefined ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        var lines = [cols.map(function (c) { return c[1]; }).join(',')].concat(d.rows.map(function (r) {
          return cols.map(function (c) { return q(c[0] === 'amount' ? (r.amount / 100).toFixed(2) : r[c[0]]); }).join(',');
        }));
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\n')], { type: 'text/csv' }));
        a.download = 'bookings-' + tripFilter + '.csv'; document.body.appendChild(a); a.click(); a.remove();
      }).catch(fail).then(function () { csv.disabled = false; });
    });
    load();
  }

  function refreshMe() { api('GET', '/me').then(function (d) { me = d; nav(); }).catch(function () {}); }

  function viewTrip(number) {
    add(app, h('p', { class: 'atc-muted', text: 'Loading…' }));
    api('GET', '/bookings/' + encodeURIComponent(number)).then(function draw(b) {
      clear(app);
      var t = b.traveler;
      var digits = (t.phone || '').replace(/\D/g, '');
      add(app, [
        h('a', { class: 'back', href: C.base + 'bookings/', 'data-go': 'bookings', text: '← Bookings' }),
        h('div', { class: 'atc-head' }, h('div', null,
          h('div', { class: 'atc-actions' }, h('h1', { text: b.number }), b.rides.length === 1 ? h('span', { class: 'chip ' + b.rides[0].tone, text: b.rides[0].status }) : null),
          h('p', { class: 'atc-muted', text: 'Received ' + b.received }))),
      ]);
      var cols = h('div', { class: 'pe-split' });
      var left = h('div', { class: 'pe-left' }), right = h('div', { class: 'bk-side' });
      add(cols, [left, right]); add(app, cols);

      b.rides.forEach(function (r) {
        var leg = r.leg;
        function act(path, body, msg) { return api('POST', '/legs/' + leg + '/' + path, body || {}).then(function (nb) { if (msg) toast(msg); refreshMe(); if (nb && nb.rides) draw(nb); else viewTrip(number); }); }
        var card = h('section', { class: 'card pad' });
        add(card, [
          h('div', { class: 'atc-actions', style: 'justify-content:space-between' },
            h('h2', { text: r.direction + ' · ' + r.when }),
            h('span', { class: 'chip ' + r.tone, text: r.status })),
          h('p', { class: 'atc-muted', style: 'margin:0', text: r.vehicle + ', private · You get paid ' + money(r.amount) + ' (traveler paid ' + money(r.retail) + ')' })
        ]);
        // 1. Confirm.
        if (r.leg_status === 'new' && b.status === 'confirmed') {
          add(card, h('div', { class: 'card warn pad', style: 'gap:8px' },
            h('strong', { text: 'New booking: please confirm you got it' }),
            h('span', { class: 'small atc-muted', text: 'Received ' + b.received + '. We remind you after 2 hours and alert our team.' }),
            h('div', null, h('button', { type: 'button', class: 'btn dark', text: 'Confirm booking', onclick: function (e) { e.target.disabled = true; act('ack', {}, 'Confirmed.').catch(function (x) { e.target.disabled = false; fail(x); }); } }))));
        } else if (r.acknowledged) {
          add(card, h('p', { class: 'small atc-muted', style: 'margin:0', text: 'Confirmed ' + r.acknowledged + '.' }));
        }
        // 2. Service code.
        if (r.leg_status === 'completed') {
          add(card, h('div', { class: 'card ok pad', style: 'gap:4px' }, h('strong', { text: r.completed_how === 'code' ? 'Trip completed with the service code' : r.completed_how === 'legacy' ? 'Trip completed (booked and paid on the old site)' : r.completed_how === 'auto' ? 'Trip completed (no code, 24 h after pickup)' : 'Trip marked completed without code' }),
            h('span', { class: 'small atc-muted', text: 'Recorded ' + r.completed + '. ' + money(r.amount) + ' goes into your next payout.' })));
        } else if (r.leg_status === 'no_show') {
          add(card, h('div', { class: 'card warn pad' }, h('strong', { text: 'No-show reported ' + r.completed }), h('span', { class: 'small atc-muted', text: 'Our team checks it before the payout.' })));
        } else if (r.can_code) {
          var codeIn = h('input', { type: 'text', inputmode: 'text', autocomplete: 'off', maxlength: 8, placeholder: 'Code', 'aria-label': 'Service code', style: 'max-width:140px;text-transform:uppercase;font-size:18px;font-weight:800;letter-spacing:.1em' });
          var codeErr = h('p', { class: 'small err', role: 'alert', hidden: true });
          var codeBtn = h('button', { type: 'submit', class: 'btn dark', text: 'Check code' });
          add(card, h('form', { class: 'card pad', style: 'gap:10px', onsubmit: function (e) {
            e.preventDefault(); codeErr.hidden = true; codeBtn.disabled = true;
            act('code', { code: codeIn.value }, 'Trip completed.').catch(function (x) { codeErr.textContent = x.message; codeErr.hidden = false; codeBtn.disabled = false; codeIn.select(); });
          } },
            h('strong', { text: 'Service code' }),
            h('span', { class: 'small atc-muted', text: 'At pickup, ask the traveler for the code on their voucher and type it here. It completes the trip and is your proof that the service was given. ' + b.tries + ' tries left.' }),
            h('div', { class: 'atc-actions' }, codeIn, codeBtn), codeErr,
            r.can_noshow ? h('div', { class: 'atc-actions' },
              h('button', { type: 'button', class: 'btn', text: 'Mark completed without code', onclick: function (e) { e.target.disabled = true; act('complete', {}, 'Marked completed.').catch(function (x) { e.target.disabled = false; fail(x); }); } })) : null,
            h('span', { class: 'small atc-muted', text: 'No code? The trip completes on its own 24 hours after pickup unless you report a no-show.' })));
        } else if (r.leg_status === 'new' || r.leg_status === 'accepted') {
          add(card, h('p', { class: 'small atc-muted', style: 'margin:0', text: 'The service code box opens 12 hours before pickup.' }));
        }
        // 3. No-show and hand back.
        if (r.can_noshow) {
          var note = h('textarea', { rows: 3, placeholder: 'How long the driver waited, where, and whether you reached the traveler' });
          var nsBox = h('div', { class: 'card pad', hidden: true, style: 'gap:8px' }, h('strong', { text: 'Report a no-show' }), note,
            h('div', null, h('button', { type: 'button', class: 'btn danger', text: 'Report no-show', onclick: function (e) { e.target.disabled = true; act('noshow', { note: note.value }, 'No-show reported. Our team will check it.').catch(function (x) { e.target.disabled = false; fail(x); }); } })));
          add(card, [h('div', null, h('button', { type: 'button', class: 'link danger', text: 'Traveler didn’t show up', onclick: function () { nsBox.hidden = !nsBox.hidden; } })), nsBox]);
        }
        if (r.can_release) {
          var why = h('textarea', { rows: 2, placeholder: 'Why (optional): no vehicle free, date clash…' });
          var relBox = h('div', { class: 'card pad', hidden: true, style: 'gap:8px' }, h('strong', { text: 'Hand back this ride?' }), h('span', { class: 'small atc-muted', text: 'Our team gives it to another provider. You won’t be paid for it. Too many hand-backs lower your place in results.' }), why,
            h('div', null, h('button', { type: 'button', class: 'btn danger', text: 'Hand back', onclick: function (e) { e.target.disabled = true; api('POST', '/legs/' + leg + '/release', { reason: why.value }).then(function () { toast('Handed back. Our team will reassign it.'); refreshMe(); go('bookings'); }).catch(function (x) { e.target.disabled = false; fail(x); }); } })));
          add(card, [h('div', null, h('button', { type: 'button', class: 'link', text: 'Hand back this ride', onclick: function () { relBox.hidden = !relBox.hidden; } })), relBox]);
        }
        // Trip facts.
        add(card, h('div', { class: 'kv' },
          kv('Flight', [r.flight, r.flight_time ? (r.direction === 'Arrival' ? 'lands ' : 'departs ') + r.flight_time : null].filter(Boolean).join(' · ')),
          kv('Pickup', r.from), kv('Drop-off', r.to),
          kv('Passengers', t.adults + (t.adults === 1 ? ' adult' : ' adults') + (t.children ? ', ' + t.children + (t.children === 1 ? ' child' : ' children') : '')),
          kv('Luggage', r.bags + ' suitcases, ' + r.carry_ons + ' carry-ons'),
          b.hotel_name ? kv('Hotel booking under', b.hotel_name) : null));
        // Driver and vehicle.
        if (r.leg_status === 'new' || r.leg_status === 'accepted') {
          var dSel = h('select', { 'aria-label': 'Driver' }, h('option', { value: '', text: 'Choose a driver' }), b.drivers.map(function (d) { return h('option', { value: d.id, selected: d.id === r.driver_id, text: d.name + (d.languages ? ' · ' + d.languages : '') }); }));
          var vSel = h('select', { 'aria-label': 'Vehicle' }, h('option', { value: '', text: 'Choose a vehicle' }), b.vehicles.map(function (v) { return h('option', { value: v.id, selected: v.id === r.vehicle_id, text: v.make_model + (v.year ? ' ' + v.year : '') + (v.plate ? ' · ' + v.plate : '') }); }));
          var dn = h('input', { type: 'text', placeholder: 'Name' }), dp = h('input', { type: 'tel', placeholder: 'Mobile' }), dl = h('input', { type: 'text', placeholder: 'Languages, e.g. Spanish, English' });
          var addBox = h('div', { class: 'grid2', hidden: true }, dn, dp, dl, h('button', { type: 'button', class: 'btn', text: 'Add driver', onclick: function () {
            api('POST', '/drivers', { name: dn.value, phone: dp.value, languages: dl.value }).then(function (res) {
              clear(dSel); add(dSel, [h('option', { value: '', text: 'Choose a driver' })].concat(res.drivers.map(function (d) { return h('option', { value: d.id, selected: d.id === res.id, text: d.name + (d.languages ? ' · ' + d.languages : '') }); })));
              addBox.hidden = true; toast('Driver added.');
            }).catch(fail);
          } }));
          add(card, h('div', { class: 'card pad', style: 'gap:10px' }, h('strong', { text: 'Driver and vehicle' }),
            h('div', { class: 'grid2' }, field('Driver', dSel), field('Vehicle', vSel)),
            h('div', { class: 'atc-actions' },
              h('button', { type: 'button', class: 'btn dark', text: 'Save', onclick: function (e) { e.target.disabled = true; act('assign', { driver_id: dSel.value, vehicle_id: vSel.value }, 'Saved.').catch(function (x) { e.target.disabled = false; fail(x); }); } }),
              h('button', { type: 'button', class: 'link', text: '+ Add a driver', onclick: function () { addBox.hidden = !addBox.hidden; } })),
            addBox));
        }
        add(left, card);
      });
      add(left, reportBox(number));

      add(right, h('section', { class: 'card pad' },
        h('h2', { text: 'Traveler' }),
        h('div', { class: 'kv', style: 'grid-template-columns:1fr' }, kv('Name', t.name), kv('Mobile', t.phone + (t.whatsapp ? ' · WhatsApp' : ''))),
        digits ? h('div', { class: 'atc-actions' },
          h('a', { class: 'btn', href: 'tel:+' + digits, text: 'Call' }),
          t.whatsapp ? h('a', { class: 'btn', href: 'https://wa.me/' + digits, target: '_blank', rel: 'noopener', text: 'WhatsApp' }) : null) : null));
      if (b.extras && b.extras.length) add(right, h('section', { class: 'card pad warn' }, h('h2', { text: 'Extras to bring' }), h('ul', { style: 'margin:0;padding-left:18px' }, b.extras.map(function (x) { return h('li', null, h('strong', { text: x.label }), h('span', { class: 'small atc-muted', text: ' · you get ' + money(x.provider) })); }))));
      if (b.notes) add(right, h('section', { class: 'card pad' }, h('h2', { text: 'Note from the traveler' }), h('p', { class: 'prewrap', style: 'margin:0', text: b.notes })));
    }).catch(function (e) { clear(app); add(app, [h('a', { class: 'back', href: C.base + 'bookings/', 'data-go': 'bookings', text: '← Bookings' }), h('p', { class: 'chip red', text: e.message })]); });
  }

  /* ---------- Meeting travelers ---------- */

  function viewMeeting(airportId) {
    clear(app);
    add(app, head('Meeting travelers', 'How your travelers find your driver at the airport. It shows on their confirmation. If you leave it, they see our general steps for the airport.'));
    var body = h('div', { style: 'display:flex;flex-direction:column;gap:18px' }, h('p', { class: 'atc-muted', text: 'Loading…' }));
    add(app, body);
    api('GET', '/meeting' + (airportId ? '?airport_id=' + airportId : '')).then(function (d) {
      clear(body);
      if (!d.airports.length) { add(body, h('p', { class: 'atc-muted', text: 'Add a vehicle at an airport first.' })); return; }
      if (d.airports.length > 1) {
        add(body, field('Airport', h('select', { onchange: function () { viewMeeting(parseInt(this.value, 10)); } },
          d.airports.map(function (a) { return h('option', { value: a.id, selected: a.id === d.airport_id, text: a.label }); }))));
      }
      var own = d.mine.length > 0;
      add(body, h('section', { class: 'card pad' },
        h('h2', { text: own ? 'Your steps' : 'Write your own steps' }),
        h('p', { class: 'small atc-muted', text: own ? 'Your travelers at this airport see these steps.' : 'Your travelers see our steps below for now. Start from them and change what is different for you: where your driver waits, what the sign looks like, which exit to use.' }),
        window.ATCMeeting.editor({
          sets: d.mine, startFrom: d.ours, saveLabel: 'Save my steps',
          removeLabel: 'Use the airport’s steps instead',
          onRemove: own ? function () { api('POST', '/meeting', { airport_id: d.airport_id, sets: [] }).then(function () { toast('Your travelers now see the airport’s steps.'); viewMeeting(d.airport_id); }).catch(fail); } : null,
          onSave: function (sets) { return api('POST', '/meeting', { airport_id: d.airport_id, sets: sets }).then(function (r) { toast('Saved. Your travelers see these steps.'); return r; }).catch(function (e) { fail(e); throw e; }); }
        })));
      if (own) add(body, h('section', { class: 'card pad' }, h('h2', { text: 'The airport’s steps (ours)' }), window.ATCMeeting.preview(d.ours)));
    }).catch(fail);
  }

  /* ---------- Setup (home) ---------- */

  function viewSetup() {
    var p = me.provider, s = me.steps;
    var keys = SETUP.map(function (x) { return x[0]; }).concat(['approval']);
    var done = keys.filter(function (k) { return s[k].state === 'done'; }).length;
    var first = nextTodo();
    var live = p.status === 'live';
    add(app, head(first && !done ? 'Welcome, ' + p.name : p.name, live ? 'You are live: travelers can book you.' : 'Travelers can book you once every step is green. ' + done + ' of ' + keys.length + ' done.'));
    if (first) {
      var i = stepIndex(first);
      add(app, h('section', { class: 'card pad nextcard', 'data-tour': 'nextcard' },
        h('div', { class: 'sb-kicker', text: done ? 'YOUR NEXT STEP · ' + (i + 1) + ' OF ' + SETUP.length : 'START HERE · STEP 1 OF ' + SETUP.length }),
        h('h2', { text: SETUP[i][1] }),
        h('p', { text: GUIDE[first].why }),
        h('div', { class: 'atc-actions' },
          h('a', { class: 'btn dark', href: C.base + first + '/', 'data-go': first, text: (done ? 'Continue with step ' : 'Start step ') + (i + 1) + ' →' }),
          h('button', { class: 'btn', type: 'button', onclick: welcomeTour }, h('span', { class: 'q', text: '?' }), ' Show me around'))));
    } else {
      add(app, h('section', { class: 'card pad nextcard done' }, h('h2', { text: live ? 'All set' : 'Every step is sent' }),
        h('p', { text: live ? 'Keep your prices and documents up to date. New bookings show under Bookings.' : 'We review your documents within two working days and switch you on. We email you when you are live.' })));
    }
    add(app, h('ol', { class: 'steps', 'aria-label': 'Setup steps', 'data-tour': 'steps' }, keys.map(function (k, j) {
      var st = s[k];
      var link = k === 'approval' ? null : k;
      var inner = [h('span', { class: 'st', text: (j + 1) + ' · ' + st.label.toUpperCase() }), h('span', { class: 'nm', text: st.name })];
      return h('li', { class: 'step ' + st.state + (k === first ? ' cur' : '') }, link ? h('a', { href: C.base + link + '/', 'data-go': link }, inner) : h('div', null, inner));
    })));
    if (s.vehicles.state !== 'done' || s.prices.state !== 'done') {
      add(app, h('section', { class: 'card pad', 'data-tour': 'pricelist', style: 'border-color:var(--orange)' },
        h('h2', { text: 'Shortcut: have a price list?' }),
        h('p', { class: 'atc-muted', text: 'Upload your price list (Excel, CSV or Google Sheets) and we do steps 2 to 4 for you: we match your vehicles to our categories, draw a zone for each place near ' + (me.airport ? me.airport.city : 'your airport') + ', and fill in your prices. You check everything before it is saved.' }),
        h('div', { class: 'atc-actions' }, h('a', { class: 'btn', href: C.base + 'pricelist/', 'data-go': 'pricelist', text: 'Upload my price list' }), h('span', { class: 'small atc-muted', text: 'No list? Go step by step.' }))));
    }
    if (me.airports.length > 1) {
      add(app, h('section', { class: 'card pad' }, h('div', { class: 'row-flat' }, h('h2', { text: 'Your airports' }), h('a', { class: 'link', href: C.base + 'airports/', 'data-go': 'airports', text: 'Manage →' })),
        airportTable(true)));
    }
    add(app, h('p', { class: 'small atc-muted', text: 'Questions? Write to ' + C.support + '.' }));
  }

  /* ---------- Airports ---------- */

  function airportLabel(a) { return a ? (a.iata ? a.city + ' (' + a.iata + ')' : a.name) : 'your airport'; }

  // The airport the provider is working on, with a switch when it serves several.
  function airportBar() {
    var list = me.airports;
    var cur = me.airport;
    var ctl;
    if (list.length > 1) {
      ctl = h('select', { 'aria-label': 'Airport you are working on', onchange: function () {
        api('POST', '/airport', { airport_id: +ctl.value }).then(function (d) { saved(d, 'Now working on ' + airportLabel(d.airport) + '.'); }).catch(fail);
      } }, list.map(function (a) { return h('option', { value: a.id, selected: cur && a.id == cur.id, text: a.label + (a.home ? ' · main airport' : '') }); }));
    } else {
      ctl = h('strong', { text: airportLabel(cur) });
    }
    return h('div', { class: 'ap-bar', 'data-tour': 'airportbar' },
      h('span', { class: 'ap-k', text: 'Airport' }), ctl,
      h('span', { class: 'small atc-muted grow', text: list.length > 1 ? 'Vehicles, zones and prices are set per airport.' : '' }),
      h('a', { class: 'link', href: C.base + 'airports/', 'data-go': 'airports', text: '+ Add another airport' }));
  }

  function openAirport(a, where) {
    var to = where;
    if (!to) to = !a.vehicles ? 'vehicles' : (!a.zones ? 'coverage' : 'prices');
    if (me.airport && me.airport.id == a.id) { go(to); return; }
    api('POST', '/airport', { airport_id: a.id }).then(function (d) { me = d; go(to); }).catch(fail);
  }

  function airportTable(compact) {
    var chip = function (ok, text) { return h('span', { class: 'chip ' + (ok ? 'green' : 'grey'), text: (ok ? '✓ ' : '') + text }); };
    return h('div', { class: 'ap-list' }, me.airports.map(function (a) {
      var pr = a.prices;
      return h('div', { class: 'ap-row' + (me.airport && me.airport.id == a.id ? ' cur' : '') },
        h('div', { class: 'ap-name' }, h('strong', { text: a.name }), h('span', { class: 'small atc-muted', text: (a.iata || '') + (a.home ? ' · main airport' : '') })),
        h('div', { class: 'ap-chips' },
          h('button', { class: 'chipbtn', type: 'button', onclick: function () { openAirport(a, 'vehicles'); } }, chip(a.vehicles > 0, a.vehicles ? a.vehicles + ' vehicle categor' + (a.vehicles === 1 ? 'y' : 'ies') : 'Vehicles to do')),
          h('button', { class: 'chipbtn', type: 'button', onclick: function () { openAirport(a, 'coverage'); } }, chip(a.zones > 0, a.zones ? a.zones + ' zone' + (a.zones === 1 ? '' : 's') : 'Zones to do')),
          h('button', { class: 'chipbtn', type: 'button', onclick: function () { openAirport(a, 'prices'); } }, chip(pr.total > 0 && !pr.missing, pr.total ? 'Prices ' + (pr.total - pr.missing) + ' / ' + pr.total : 'Prices to do'))),
        compact ? null : h('div', { class: 'atc-actions' },
          h('button', { class: 'btn dark', type: 'button', text: 'Open', onclick: function () { openAirport(a); } }),
          a.home ? null : h('button', { class: 'link danger', type: 'button', text: 'Stop serving', onclick: function () {
            if (!window.confirm('Stop serving ' + a.name + '? Travelers will no longer find you there. Your zones and prices are kept.')) return;
            api('DELETE', '/airports/' + a.id).then(function (d) { saved(d, 'You no longer serve ' + a.name + '.'); }).catch(fail);
          } })));
    }));
  }

  function viewAirports() {
    add(app, head('Your airports', 'Every airport you pick travelers up from. Each one has its own vehicles, zones and prices.'));
    add(app, h('section', { class: 'card pad', 'data-tour': 'aplist' }, airportTable(false)));

    var root = C.rest.replace(/\/portal\/?$/, '');
    var q = h('input', { type: 'search', placeholder: 'City, airport name or code, e.g. Tulum or TQO', autocomplete: 'off', 'aria-label': 'Search for an airport' });
    var out = h('div', { class: 'ap-results', role: 'list' });
    var timer = null, seq = 0;
    function search(more) {
      var term = q.value.trim(), my = ++seq;
      if (term.length < 2) { clear(out); return; }
      fetch(root + '/airports/search?q=' + encodeURIComponent(term) + (more ? '&more=1' : ''), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        if (my !== seq) return;
        clear(out);
        var mine = me.airports.map(function (a) { return a.id; });
        (d.results || []).forEach(function (r) {
          var have = r.id && mine.indexOf(r.id) >= 0;
          add(out, h('button', { class: 'ap-res', type: 'button', role: 'listitem', disabled: have, onclick: function (e) {
            var b = e.currentTarget; b.disabled = true;
            api('POST', '/airports', r.id ? { airport_id: r.id } : { place_id: r.place_id }).then(function (d2) {
              me = d2; nav(); toast(r.label + ' added. Now pick the vehicles you sell there.'); go('vehicles');
            }).catch(function (e2) { b.disabled = false; fail(e2); });
          } }, h('strong', { text: r.label }), h('span', { class: 'small atc-muted', text: (r.sub || '') + (have ? ' · already yours' : '') })));
        });
        if (!more) add(out, h('button', { class: 'link', type: 'button', text: (d.results || []).length ? 'Not in the list? Look further' : 'No match yet. Look further', onclick: function () { search(true); } }));
        else if (!(d.results || []).length) add(out, h('p', { class: 'small atc-muted', text: 'Still nothing. Write to ' + C.support + ' with the airport name and we add it.' }));
      }).catch(function () {});
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { search(false); }, 300); });
    add(app, h('section', { class: 'card pad', 'data-tour': 'apadd' },
      h('h2', { text: 'Add an airport' }),
      h('p', { class: 'small atc-muted', text: 'After adding it you go through the same three steps as for your first airport: vehicles, zones, prices.' }),
      q, out));
  }

  /* ---------- Company ---------- */

  function viewCompany() {
    var p = me.provider;
    var f = {
      name: h('input', { type: 'text', value: p.name || '' }),
      legal_name: h('input', { type: 'text', value: p.legal_name || '' }),
      contact_name: h('input', { type: 'text', value: p.contact_name || '' }),
      email: h('input', { type: 'email', value: p.email || '' }),
      phone: h('input', { type: 'tel', value: p.phone || '' }),
      languages: h('input', { type: 'text', value: p.languages || '', placeholder: 'Spanish, English' }),
      website: h('input', { type: 'url', value: p.website || '', placeholder: 'https://' }),
      about: h('textarea', { rows: 4, maxlength: 2000, value: p.about || '' }),
      notice: h('select', null, [[0, 'No minimum'], [180, '3 hours'], [360, '6 hours'], [720, '12 hours'], [1440, '24 hours'], [2880, '48 hours']].map(function (o) {
        return h('option', { value: o[0], selected: String(p.min_notice_minutes === null ? 1440 : p.min_notice_minutes) === String(o[0]), text: o[1] });
      }))
    };
    var btn = h('button', { class: 'btn dark', type: 'submit', text: 'Save' });
    add(app, [
      head('Company details', 'What travelers and our team see about you.'),
      h('form', { class: 'card pad', onsubmit: function (e) {
        e.preventDefault(); btn.disabled = true;
        api('PUT', '/company', { name: f.name.value, legal_name: f.legal_name.value, contact_name: f.contact_name.value, email: f.email.value,
          phone: f.phone.value, languages: f.languages.value, website: f.website.value, about: f.about.value, min_notice_minutes: f.notice.value })
          .then(function (d) { saved(d); }).catch(fail).then(function () { btn.disabled = false; });
      } },
        h('div', { class: 'grid2' },
          field('Name travelers see', f.name), field('Legal name (for invoices)', f.legal_name),
          field('Contact person', f.contact_name), field('Email for bookings', f.email, 'New bookings and reminders go here.'),
          field('Mobile with WhatsApp', f.phone, 'Our team uses it on arrival days.'), field('Languages your drivers speak', f.languages),
          field('Website', f.website), field('Minimum notice before pickup', f.notice, 'The shorter your notice, the more bookings you get.')),
        field('About your company: fleet, areas you cover, years in business', f.about),
        h('div', { class: 'atc-actions' }, btn))
    ]);
  }

  /* ---------- Directory profile ---------- */

  function viewDirectory() {
    var box = h('div', { class: 'stack' }, h('p', { class: 'atc-muted', text: 'Loading…' }));
    add(app, [head('Directory profile', 'Your company page in the Airport Transportation directory, where travelers find transport companies by city.'), box]);
    api('GET', '/directory').then(function (d) { dirEditor(box, d, {
      save: function (b) { return api('PUT', '/directory', b); },
      logo: function (fd) { return api('POST', '/directory/logo', fd, true); },
      check: function () { return api('POST', '/directory/check'); },
      after: function () { api('GET', '/me').then(function (m) { me = m; nav(); }).catch(function () {}); },
      staff: false
    }); }).catch(fail);
  }

  function dirEditor(box, d, o) {
    clear(box);
    var sug = d.suggest || {};
    var listVal = function (a) { return (a || []).join(', '); };
    var f = {
      title: h('input', { type: 'text', maxlength: 120, value: d.title || '' }),
      summary: h('textarea', { rows: 3, maxlength: 300, value: d.summary || '' }),
      about: h('textarea', { rows: 6, maxlength: 3000, value: d.about || sug.about || '' }),
      services: h('input', { type: 'text', value: listVal(d.services && d.services.length ? d.services : sug.services) }),
      destinations: h('input', { type: 'text', value: listVal(d.destinations && d.destinations.length ? d.destinations : sug.destinations) }),
      phone: h('input', { type: 'tel', value: d.phone || sug.phone || '' }),
      whatsapp: h('input', { type: 'tel', value: d.whatsapp || sug.phone || '' }),
      address: h('input', { type: 'text', maxlength: 190, value: d.address || '' }),
      website: h('input', { type: 'url', placeholder: 'https://', value: d.website || sug.website || '' }),
      blpage: h('input', { type: 'url', placeholder: 'https://', value: (d.backlink && d.backlink.page) || '' })
    };
    var count = h('small', { text: (f.summary.value.length) + ' / 300' });
    f.summary.addEventListener('input', function () { count.textContent = f.summary.value.length + ' / 300'; });
    function useBtn(input, list, label) {
      return list && list.length ? h('button', { class: 'link', type: 'button', text: label, onclick: function () { input.value = list.join(', '); } }) : null;
    }
    var state = d.url
      ? h('p', null, h('span', { class: 'chip green', text: 'Public' }), ' ', h('a', { href: d.url, target: '_blank', rel: 'noopener', text: 'See your profile ↗' }))
      : h('p', null, h('span', { class: 'chip orange', text: 'Not public yet' }), ' ', h('span', { class: 'small atc-muted', text: d.provider_live ? 'Save to publish it.' : 'It goes public when we approve your company.' }));

    var logoImg = h('span', { class: 'dir-logo' }, d.logo ? null : h('span', { class: 'small atc-muted', text: 'No logo' }));
    if (d.logo) logoImg.style.backgroundImage = 'url("' + String(d.logo).replace(/["\\\n]/g, '') + '")';
    var file = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp' });
    var upBtn = h('button', { class: 'btn', type: 'button', text: d.logo ? 'Replace logo' : 'Upload logo', onclick: function () {
      if (!file.files.length) { file.click(); return; }
      var fd = new FormData(); fd.append('file', file.files[0]);
      upBtn.disabled = true; upBtn.textContent = 'Uploading…';
      o.logo(fd).then(function (nd) { toast('Logo saved.'); o.after ? o.after(nd) : null; dirEditor(box, nd, o); }).catch(function (e) { upBtn.disabled = false; upBtn.textContent = 'Upload logo'; fail(e); });
    } });
    file.addEventListener('change', function () { if (file.files.length) upBtn.click(); });

    var btn = h('button', { class: 'btn dark', type: 'submit', text: 'Save profile' });
    var bl = d.backlink || { required: false, mode: 'auto', status: 'unchecked' };
    var siteMode = o.staff ? h('select', null, [['auto', 'Automatic (follow the link check)'], ['always', 'Always show their website'], ['never', 'Never show their website']].map(function (x) { return h('option', { value: x[0], selected: bl.mode === x[0], text: x[1] }); })) : null;
    var day = function (t) { return t ? t.slice(0, 16).replace('T', ' ') + ' UTC' : ''; };
    var blText = !d.website ? 'No website yet.' : {
      found: 'Link to us found' + (bl.nofollow ? ' (marked nofollow)' : '') + ' · checked ' + day(bl.checked),
      missing: 'No link to us found on ' + (bl.page || d.website) + ' · checked ' + day(bl.checked),
      error: 'We could not open ' + (bl.page || d.website) + (bl.error ? ': ' + bl.error : '') + ' · tried ' + day(bl.checked),
      unchecked: 'Not checked yet.'
    }[bl.status] || '';
    var copyBtn = h('button', { class: 'btn', type: 'button', text: 'Copy', onclick: function () {
      (navigator.clipboard ? navigator.clipboard.writeText(bl.snippet) : Promise.reject()).then(function () { toast('Copied.'); }).catch(function () { snip.select(); });
    } });
    var snip = h('input', { type: 'text', readonly: true, value: bl.snippet || '', style: 'font-family:monospace;font-size:13px', onfocus: function () { snip.select(); } });
    var checkBtn = d.website && o.check ? h('button', { class: 'btn', type: 'button', text: 'Check now', onclick: function () {
      checkBtn.disabled = true; checkBtn.textContent = 'Checking…';
      o.check().then(function (nd) { toast(nd.backlink && nd.backlink.status === 'found' ? 'Link to us found.' : 'Checked.'); dirEditor(box, nd, o); }).catch(function (e) { checkBtn.disabled = false; checkBtn.textContent = 'Check now'; fail(e); });
    } }) : null;
    var blBox = h('div', { class: 'dir-bl' },
      h('div', { class: 'row-flat' }, h('strong', { text: 'Your website on the profile' }),
        h('span', { class: 'chip ' + (d.show_website ? 'green' : 'grey'), text: d.show_website ? 'Shown' : 'Not shown' })),
      bl.required || o.staff ? h('p', { class: 'small', style: 'margin:0', text: blText }) : null,
      bl.required && !o.staff ? h('p', { class: 'small atc-muted', style: 'margin:0', text: 'Your website shows on your profile while your site links to Airport Transportation. We check it every day. Add this link to your home page or to the page you name below:' }) : null,
      bl.required ? h('div', { class: 'dir-snip' }, snip, copyBtn) : null,
      bl.required || o.staff ? field('Page on your site with the link to us (optional)', f.blpage, 'Leave it empty if the link is on your home page.') : null,
      o.staff ? field('Their website on the profile', siteMode, bl.required ? 'Automatic: shown while the link to us is there.' : 'The link check is off in Settings, so Automatic shows every website.') : null,
      checkBtn ? h('div', { class: 'atc-actions' }, checkBtn, h('small', { class: 'atc-muted', text: 'Checks the website and page as saved.' })) : null);
    add(box, [
      h('section', { class: 'card pad' }, state,
        h('div', { class: 'row-flat', style: 'gap:16px;justify-content:flex-start' }, logoImg,
          h('div', { class: 'stack', style: 'gap:6px' }, h('strong', { text: 'Logo' }), h('small', { class: 'atc-muted', text: 'PNG, JPG or WebP, up to 2 MB. A square logo on a white or clear background looks best.' }), h('div', { class: 'atc-actions' }, file, upBtn)))),
      h('form', { class: 'card pad', onsubmit: function (e) {
        e.preventDefault(); btn.disabled = true;
        var body = { title: f.title.value, summary: f.summary.value, about: f.about.value, services: f.services.value, destinations: f.destinations.value,
          phone: f.phone.value, whatsapp: f.whatsapp.value, address: f.address.value, website: f.website.value, backlink_page: f.blpage.value };
        if (siteMode) body.site_mode = siteMode.value;
        o.save(body).then(function (nd) { toast(nd.url ? 'Saved. Your profile is updated.' : 'Saved.'); o.after ? o.after(nd) : null; dirEditor(box, nd, o); }).catch(function (e2) { btn.disabled = false; fail(e2); });
      } },
        h('h2', { text: 'What travelers read' }),
        field('Company name', f.title),
        h('label', { class: 'field' }, h('span', { text: 'Short description (shows under your name)' }), f.summary, count),
        field('About your company', f.about, 'Your fleet, the areas you cover, years in business, languages your drivers speak.'),
        h('label', { class: 'field' }, h('span', { text: 'Services (separate with commas)' }), f.services, h('small', null, 'For example: Private SUV, Minivan, Sprinter. ', useBtn(f.services, sug.services, 'Use my vehicle categories'))),
        h('label', { class: 'field' }, h('span', { text: 'Destinations you serve (separate with commas)' }), f.destinations, h('small', null, useBtn(f.destinations, sug.destinations, 'Use my zones'))),
        h('h2', { text: 'Contact' }),
        h('p', { class: 'small atc-muted', text: 'Travelers see your phone and WhatsApp only when they tap "See phone" or "See WhatsApp".' }),
        h('div', { class: 'grid2' }, field('Phone', f.phone), field('WhatsApp', f.whatsapp), field('Address', f.address),
          field('Website', f.website)),
        blBox,
        d.location ? h('p', { class: 'small atc-muted', text: 'Listed in ' + d.location + (d.airport ? ' · main airport ' + d.airport : '') + '.' }) : null,
        h('div', { class: 'atc-actions' }, btn))
    ]);
  }

  /* ---------- Vehicles ---------- */

  function viewVehicles() {
    var airport = me.airport;
    add(app, head('Vehicles', 'Tick the categories you sell at ' + airportLabel(airport) + ', then add each vehicle.'));
    add(app, airportBar());

    var grid = h('div', { class: 'vt-grid' });
    var other = h('div', { class: 'vt-grid hidden' });
    var otherCount = 0, otherOn = false;
    var otherBtn = h('button', { class: 'btn', type: 'button' });
    var cats = h('section', { class: 'card pad' }, h('h2', { text: 'Categories you sell' }),
      h('p', { class: 'small atc-muted', text: 'Tick each category you sell. The passengers and bags start at our usual numbers: change them to fit your vehicles.' }),
      grid, otherBtn, other);
    me.vehicle_types.forEach(function (t) {
      var s = me.services.find(function (x) { return x.vehicle_type_id == t.id && x.airport_id == airport.id; });
      var on = !!s && s.status !== 'paused';
      var pax = h('input', { type: 'number', min: 1, max: 60, value: s ? s.max_passengers : t.max_passengers });
      var bags = h('input', { type: 'number', min: 0, max: 60, value: s ? s.max_bags : t.max_bags });
      var wait = h('input', { type: 'number', min: 0, max: 240, value: s ? s.free_wait_minutes : 60 });
      var box = h('input', { type: 'checkbox', checked: on });
      var body = h('div', { class: 'svc-body' + (on ? '' : ' hidden') },
        h('div', { class: 'grid3' }, field('Max passengers', pax), field('Max bags', bags), field('Free waiting at the airport (min)', wait)),
        h('div', { class: 'atc-actions' }, h('button', { class: 'btn dark', type: 'button', onclick: function () { save(true); }, text: 'Save ' + t.name })));
      function save(offered) {
        api('POST', '/services', { airport_id: airport.id, vehicle_type_id: t.id, offered: offered, max_passengers: pax.value, max_bags: bags.value,
          free_wait_minutes: wait.value, min_notice_minutes: me.provider.min_notice_minutes === null ? 1440 : me.provider.min_notice_minutes })
          .then(function (d) { saved(d, offered ? t.name + ' saved.' : t.name + ' removed.'); }).catch(fail);
      }
      box.addEventListener('change', function () {
        if (box.checked) { body.classList.remove('hidden'); save(true); } else if (s) { save(false); } else { body.classList.add('hidden'); }
      });
      var limo = t.category && t.category !== 'standard';
      if (limo) { otherCount++; if (on) otherOn = true; }
      add(limo ? other : grid, h('div', { class: 'svc vt' + (on ? ' on' : '') },
        t.image ? h('img', { class: 'vt-img', src: t.image, alt: '', width: 320, height: 200, loading: 'lazy' }) : h('div', { class: 'vt-img vt-noimg', text: 'Photo coming soon' }),
        h('label', { class: 'svc-head' }, box, h('strong', { text: t.name }), h('span', { class: 'atc-muted small', text: 'usually up to ' + t.max_passengers + ' passengers · ' + t.max_bags + ' bags' })),
        body));
    });
    function paintOther() {
      var open = !other.classList.contains('hidden');
      otherBtn.textContent = open ? 'Hide other categories' : 'Other categories: limousines (' + otherCount + ')';
      otherBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (!otherCount) otherBtn.classList.add('hidden');
    if (otherOn) other.classList.remove('hidden');
    otherBtn.addEventListener('click', function () { other.classList.toggle('hidden'); paintOther(); });
    paintOther();

    var tbody = h('tbody');
    me.vehicles.forEach(function (v) {
      add(tbody, h('tr', null,
        h('td', { class: 'strong', text: v.make_model + (v.year ? ' ' + v.year : '') }), h('td', { text: v.vehicle_type }),
        h('td', { text: v.plate || '' }), h('td', { text: v.seats || '' }), h('td', { text: v.insurance_until || '' }),
        h('td', null, h('button', { class: 'link danger', type: 'button', onclick: function () {
          if (!confirm('Remove ' + v.make_model + '?')) return;
          api('DELETE', '/vehicles/' + v.id).then(function (d) { saved(d, 'Removed.'); }).catch(fail);
        }, text: 'Remove' }))));
    });
    if (!me.vehicles.length) add(tbody, h('tr', null, h('td', { colspan: 6, class: 'atc-muted', text: 'No vehicles yet.' })));
    var f = {
      model: h('input', { type: 'text', placeholder: 'Toyota Sienna', required: true }), year: h('input', { type: 'number', min: 1990, max: 2100, placeholder: '2024' }),
      type: h('select', null, me.vehicle_types.map(function (t) { return h('option', { value: t.id, text: t.name }); })),
      plate: h('input', { type: 'text' }), seats: h('input', { type: 'number', min: 1, max: 60 }), ins: h('input', { type: 'date' })
    };
    var form = h('form', { class: 'grid6', onsubmit: function (e) {
      e.preventDefault();
      api('POST', '/vehicles', { make_model: f.model.value, year: f.year.value, vehicle_type_id: f.type.value, plate: f.plate.value, seats: f.seats.value || null, insurance_until: f.ins.value })
        .then(function (d) { saved(d, 'Vehicle added.'); }).catch(fail);
    } },
      field('Make and model', f.model), field('Year', f.year), field('Category', f.type), field('Plate', f.plate), field('Seats', f.seats), field('Insurance until', f.ins),
      h('div', { class: 'atc-actions' }, h('button', { class: 'btn dark', type: 'submit', text: 'Add vehicle' })));

    add(app, [cats, h('section', { class: 'card pad', 'data-tour': 'cars' }, h('h2', { text: 'Your cars' }),
      h('p', { class: 'small atc-muted', text: 'The real cars you drive, at all your airports. Add at least one.' }),
      h('div', { class: 'scroll' }, h('table', null, h('thead', null, h('tr', null, ['Vehicle', 'Category', 'Plate', 'Seats', 'Insurance until', ''].map(function (t) { return h('th', { scope: 'col', text: t }); }))), tbody)),
      h('p', { class: 'label', text: 'Add a vehicle' }), form)]);
  }

  /* ---------- Coverage ---------- */

  var map = null, polys = {}, mapsPromise = null;
  function loadMaps() {
    if (mapsPromise) return mapsPromise;
    mapsPromise = new Promise(function (resolve, reject) {
      if (!C.mapsKey) { reject(new Error('nokey')); return; }
      window.atcMapsReady = resolve;
      var s = document.createElement('script');
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(C.mapsKey) + '&v=weekly&loading=async&callback=atcMapsReady';
      s.async = true; s.onerror = function () { reject(new Error('Google Maps did not load.')); };
      document.head.appendChild(s);
    });
    return mapsPromise;
  }

  function viewCoverage() {
    var airport = me.airport;
    var offered = me.services.filter(function (s) { return s.status !== 'paused' && s.airport_id == airport.id; });
    var ours = me.zones.filter(function (z) { return !z.own; });
    var mine = me.zones.filter(function (z) { return z.own; });
    add(app, head('Zones you serve', 'The areas where you pick up and drop off travelers around ' + airportLabel(airport) + '. Each zone gets its own price on the next step.'));
    add(app, airportBar());
    if (!offered.length) {
      add(app, h('section', { class: 'card pad' }, h('p', { text: 'First tick the vehicle categories you sell.' }),
        h('div', { class: 'atc-actions' }, h('a', { class: 'btn dark', href: C.base + 'vehicles/', 'data-go': 'vehicles', text: 'Go to Vehicles' }))));
      return;
    }
    var chosen = {};
    offered.forEach(function (s) { s.zone_ids.forEach(function (z) { chosen[z] = true; }); });

    var map = null, polys = {}, labels = [], nearby = [], nearPolys = [], showAllNear = false;
    var mode = null; // null | {draw: pts, line, dots} | {edit: id}
    var mapBox = h('div', { class: 'atc-map' });
    var tools = h('div', { class: 'zd-tools' });
    var side = h('section', { class: 'card pad atc-col' });

    add(app, h('div', { class: 'atc-split' },
      h('section', { class: 'card atc-mapcard' }, tools, mapBox),
      side));

    /* ----- side panel ----- */
    function drawSide() {
      clear(side);
      add(side, h('div', { class: 'row-flat' }, h('h2', { text: 'Your zones' }),
        h('button', { class: 'btn', type: 'button', 'data-tour': 'drawhand', text: 'Draw by hand', disabled: !!mode || !map, onclick: startDraw })));
      // Finding an area by name works at any time (it replaces a drawing in progress), except while reshaping.
      var busy = !map || !!(mode && mode.edit);
      var q = h('input', { type: 'search', placeholder: 'Type an area, e.g. Tulum, Playa del Carmen', disabled: busy, value: (mode && mode.suggest && mode.name) || '' });
      var findBtn = h('button', { class: 'btn dark', type: 'submit', text: 'Find on the map', disabled: busy });
      add(side, h('p', { class: 'small atc-muted', style: 'margin:4px 0 0', text: 'The easy way: type the name of an area and we draw it on the map for you. You can adjust the edge before saving.' }));
      add(side, h('form', { class: 'zd-find', 'data-tour': 'find', onsubmit: function (e) {
        e.preventDefault();
        if (!q.value.trim()) { q.focus(); return; }
        if (mode) stopMode();
        findBtn.disabled = true; findBtn.textContent = 'Looking…';
        api('GET', '/zones/suggest?q=' + encodeURIComponent(q.value.trim())).then(function (r) {
          if (!r.candidates.length) { findBtn.disabled = false; findBtn.textContent = 'Find'; toast('We did not find that area near ' + airport.city + '. Draw it by hand.', true); return; }
          startSuggest(r.candidates, r.query);
        }).catch(function (e2) { findBtn.disabled = false; findBtn.textContent = 'Find'; fail(e2); });
      } }, q, findBtn));
      if (!mine.length) add(side, h('p', { class: 'atc-muted', text: ours.length ? 'None yet. Find an area by name, draw one by hand, or tick ours below.' : 'None yet. Find an area by name, or press “Draw by hand” and click around it on the map.' }));
      mine.forEach(function (z) {
        add(side, h('div', { class: 'zd-row' + (mode && mode.edit === z.id ? ' on' : '') },
          h('span', { class: 'zd-swatch' }), h('strong', { text: z.name }),
          h('span', { class: 'zd-acts' },
            h('button', { class: 'link', type: 'button', text: 'Rename', disabled: !!mode, onclick: function () { rename(z); } }),
            h('button', { class: 'link', type: 'button', text: 'Reshape', disabled: !!mode || !map, onclick: function () { startEdit(z); } }),
            h('button', { class: 'link', type: 'button', text: 'Delete', disabled: !!mode, onclick: function () { removeZone(z); } }))));
      });
      if (ours.length) {
        var list = h('div', { class: 'ticks col' });
        ours.forEach(function (z) {
          var cb = h('input', { type: 'checkbox', checked: !!chosen[z.id] });
          cb.addEventListener('change', function () { chosen[z.id] = cb.checked; paint(); });
          z._cb = cb;
          add(list, h('label', { class: 'tick' }, cb, z.name));
        });
        var saveBtn = h('button', { class: 'btn', type: 'button', text: 'Save the zones I ticked', onclick: function () {
          saveBtn.disabled = true;
          var ids = Object.keys(chosen).filter(function (k) { return chosen[k]; }).map(Number);
          mine.forEach(function (z) { if (ids.indexOf(z.id) < 0) ids.push(z.id); });
          api('PUT', '/coverage', { airport_id: airport.id, zone_ids: ids }).then(function (d) { saved(d, 'Zones saved.'); }).catch(fail).then(function () { saveBtn.disabled = false; });
        } });
        add(side, h('div', { 'data-tour': 'ourzones' }, h('h2', { text: 'Our zones', style: 'margin-top:18px' }),
          h('p', { class: 'small atc-muted', text: 'Already drawn by us. Tick the ones you serve too. Where one of your zones covers an address, travelers get your price for your zone.' }),
          h('div', { class: 'atc-actions' },
            h('button', { class: 'link', type: 'button', text: 'All', onclick: function () { ours.forEach(function (z) { chosen[z.id] = true; z._cb.checked = true; }); paint(); } }),
            h('button', { class: 'link', type: 'button', text: 'None', onclick: function () { ours.forEach(function (z) { chosen[z.id] = false; z._cb.checked = false; }); paint(); } })),
          list, h('div', { class: 'atc-actions' }, saveBtn)));
      }
      var near = nearby.filter(function (z) { return !z.used; });
      if (near.length) {
        var nl = h('div', { class: 'zd-near' });
        add(side, h('div', { 'data-tour': 'nearby' }, h('h2', { text: 'Zones near your airport', style: 'margin-top:18px' }),
          h('p', { class: 'small atc-muted', text: 'We drew these for other airports close by (grey on the map). Add one to your zones here in one click.' }), nl));
        near.slice(0, showAllNear ? 60 : 8).forEach(function (z) {
          add(nl, h('div', { class: 'zd-row' }, h('span', { class: 'zd-swatch near' }),
            h('span', { class: 'grow' }, h('strong', { text: z.name }), h('span', { class: 'small atc-muted', text: ' · ' + z.km + ' km, from ' + z.from })),
            h('button', { class: 'link', type: 'button', text: 'Use this zone', disabled: !!mode, onclick: function () { copyZone(z); } })));
        });
        if (near.length > 8 && !showAllNear) add(nl, h('button', { class: 'link', type: 'button', text: 'Show all ' + near.length, onclick: function () { showAllNear = true; drawSide(); } }));
      }
      if (mine.length || Object.keys(chosen).some(function (k) { return chosen[k]; })) {
        add(side, h('div', { class: 'atc-actions', style: 'margin-top:18px' }, h('a', { class: 'btn dark', href: C.base + 'prices/', 'data-go': 'prices', text: 'Next: set your prices →' })));
      }
    }
    function copyZone(z) {
      api('POST', '/zones/copy', { zone_id: z.id }).then(function (d) { saved(d, z.name + ' added to your zones. Now give it a price.'); }).catch(fail);
    }

    /* ----- toolbar over the map while drawing or reshaping ----- */
    function drawTools() {
      clear(tools);
      if (!mode) { tools.className = 'zd-tools hidden'; return; }
      tools.className = 'zd-tools';
      if (mode.draw) {
        var n = mode.draw.length;
        var name = h('input', { type: 'text', maxlength: 100, placeholder: 'Zone name, e.g. Hotel Zone', value: mode.name || '' });
        name.addEventListener('input', function () { mode.name = name.value; save.disabled = n < 3 || !name.value.trim(); });
        var save = h('button', { class: 'btn dark', type: 'button', text: 'Save zone', disabled: n < 3 || !(mode.name || '').trim(), onclick: function () {
          save.disabled = true;
          api('POST', '/zones', { airport_id: airport.id, name: name.value, polygon: mode.draw.map(function (p) { return [p.lat(), p.lng()]; }) })
            .then(function (d) { stopMode(); saved(d, name.value + ' saved. Now give it a price.'); }).catch(function (e) { save.disabled = false; fail(e); });
        } });
        add(tools, [h('strong', { text: n < 3 ? 'Click on the map around the area (' + n + ' of at least 3 points)' : n + ' points. Keep clicking to follow the edge, then name it and save.' }),
          h('div', { class: 'zd-bar' }, name,
            h('button', { class: 'btn', type: 'button', text: 'Undo point', disabled: !n, onclick: undoPoint }),
            h('button', { class: 'btn', type: 'button', text: 'Cancel', onclick: stopMode }), save)]);
        if (n >= 3) setTimeout(function () { if (!name.value) name.focus(); }, 0);
      } else if (mode.suggest) {
        var c = mode.suggest[mode.idx];
        var nm = h('input', { type: 'text', maxlength: 100, value: mode.name || '' });
        nm.addEventListener('input', function () { mode.name = nm.value; });
        add(tools, [h('strong', { text: 'Option ' + (mode.idx + 1) + ' of ' + mode.suggest.length + ': ' + c.label.split(',').slice(0, 2).join(',') + ' · ' + c.kind + ', about ' + c.km2 + ' km²' }),
          h('span', { class: 'small atc-muted', text: 'Drag the white points to adjust the edge before saving.' }),
          h('div', { class: 'zd-bar' }, nm,
            mode.suggest.length > 1 ? h('button', { class: 'btn', type: 'button', text: '‹ Previous', onclick: function () { showSuggest(mode.idx - 1); } }) : null,
            mode.suggest.length > 1 ? h('button', { class: 'btn', type: 'button', text: 'Next ›', onclick: function () { showSuggest(mode.idx + 1); } }) : null,
            h('button', { class: 'btn', type: 'button', text: 'Cancel', onclick: stopMode }),
            h('button', { class: 'btn dark', type: 'button', text: 'Save zone', onclick: function (e) {
              var b = e.currentTarget; b.disabled = true;
              api('POST', '/zones', { airport_id: airport.id, name: nm.value, polygon: mode.poly.getPath().getArray().map(function (p) { return [p.lat(), p.lng()]; }) })
                .then(function (d) { var n = nm.value; stopMode(); saved(d, n + ' saved. Now give it a price.'); }).catch(function (e2) { b.disabled = false; fail(e2); });
            } }))]);
      } else if (mode.edit) {
        add(tools, [h('strong', { text: 'Drag the white points to move the edge. Drag a small middle point to add a corner.' }),
          h('div', { class: 'zd-bar' },
            h('button', { class: 'btn', type: 'button', text: 'Cancel', onclick: function () { stopMode(); paintAll(); } }),
            h('button', { class: 'btn dark', type: 'button', text: 'Save shape', onclick: function () {
              var pts = polys[mode.edit].getPath().getArray().map(function (p) { return [p.lat(), p.lng()]; });
              api('PUT', '/zones/' + mode.edit, { polygon: pts }).then(function (d) { stopMode(); saved(d, 'Shape saved.'); }).catch(fail);
            } }))]);
      }
    }

    function startDraw() {
      mode = { draw: [], name: '', line: new google.maps.Polyline({ map: map, strokeColor: '#E07A1F', strokeWeight: 3, clickable: false }), dots: [] };
      map.setOptions({ draggableCursor: 'crosshair' });
      Object.keys(polys).forEach(function (id) { polys[id].setOptions({ clickable: false }); });
      drawTools(); drawSide();
    }
    function addPoint(p) {
      mode.draw.push(p);
      mode.line.setPath(mode.draw.concat(mode.draw.length > 2 ? [mode.draw[0]] : []));
      mode.dots.push(new google.maps.Marker({ map: map, position: p, clickable: false,
        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 4, fillColor: '#E07A1F', fillOpacity: 1, strokeColor: '#FFFFFF', strokeWeight: 1 } }));
      drawTools();
    }
    function undoPoint() {
      if (!mode || !mode.draw || !mode.draw.length) return;
      mode.draw.pop();
      var d = mode.dots.pop(); if (d) d.setMap(null);
      mode.line.setPath(mode.draw.concat(mode.draw.length > 2 ? [mode.draw[0]] : []));
      drawTools();
    }
    function startSuggest(list, query) {
      mode = { suggest: list, idx: 0, name: query.replace(/,.*$/, ''), poly: null };
      Object.keys(polys).forEach(function (id) { polys[id].setOptions({ clickable: false }); });
      showSuggest(0);
      drawSide();
    }
    function showSuggest(i) {
      mode.idx = (i + mode.suggest.length) % mode.suggest.length;
      if (mode.poly) mode.poly.setMap(null);
      var path = mode.suggest[mode.idx].polygon.map(function (p) { return { lat: p[0], lng: p[1] }; });
      mode.poly = new google.maps.Polygon({ map: map, paths: path, editable: true, strokeColor: '#E07A1F', strokeWeight: 3, fillColor: '#FBA53E', fillOpacity: 0.35, zIndex: 8 });
      var b = new google.maps.LatLngBounds();
      path.forEach(function (p) { b.extend(p); });
      map.fitBounds(b, 60);
      drawTools();
    }
    function startEdit(z) {
      mode = { edit: z.id };
      polys[z.id].setOptions({ editable: true, zIndex: 9 });
      var b = new google.maps.LatLngBounds();
      z.polygon.forEach(function (p) { b.extend({ lat: p[0], lng: p[1] }); });
      map.fitBounds(b, 60);
      drawTools(); drawSide();
    }
    function stopMode() {
      if (mode && mode.line) { mode.line.setMap(null); mode.dots.forEach(function (d) { d.setMap(null); }); }
      if (mode && mode.poly) mode.poly.setMap(null);
      if (mode && mode.edit && polys[mode.edit]) polys[mode.edit].setOptions({ editable: false });
      mode = null;
      if (map) { map.setOptions({ draggableCursor: null }); Object.keys(polys).forEach(function (id) { polys[id].setOptions({ clickable: true }); }); }
      drawTools(); drawSide();
    }
    function rename(z) {
      var n = window.prompt('New name for ' + z.name, z.name);
      if (!n || n.trim() === z.name) return;
      api('PUT', '/zones/' + z.id, { name: n }).then(function (d) { saved(d, 'Renamed.'); }).catch(fail);
    }
    function removeZone(z) {
      if (!window.confirm('Delete ' + z.name + '? Travelers will no longer find you there.')) return;
      api('DELETE', '/zones/' + z.id).then(function (d) { saved(d, z.name + ' deleted.'); }).catch(fail);
    }

    function paint() {
      ours.forEach(function (z) {
        if (!polys[z.id]) return;
        var on = !!chosen[z.id];
        polys[z.id].setOptions({ fillColor: on ? '#FBA53E' : '#FFFFFF', fillOpacity: on ? 0.45 : 0.2, strokeWeight: on ? 2 : 1, strokeColor: '#15171C' });
      });
    }
    function paintAll() {
      Object.keys(polys).forEach(function (id) { polys[id].setMap(null); });
      labels.forEach(function (l) { l.setMap(null); });
      polys = {}; labels = [];
      var b = new google.maps.LatLngBounds();
      me.zones.forEach(function (z) {
        var path = z.polygon.map(function (p) { return { lat: p[0], lng: p[1] }; });
        path.forEach(function (p) { b.extend(p); });
        var poly = new google.maps.Polygon(z.own
          ? { map: map, paths: path, strokeColor: '#1F6B35', strokeWeight: 2, fillColor: '#2E9E55', fillOpacity: 0.35, zIndex: 3 }
          : { map: map, paths: path, strokeColor: '#15171C', strokeOpacity: 0.9, zIndex: 1 });
        if (!z.own) poly.addListener('click', function () { if (mode) return; chosen[z.id] = !chosen[z.id]; if (z._cb) z._cb.checked = chosen[z.id]; paint(); });
        poly.addListener('click', function (e) { if (mode && mode.draw) addPoint(e.latLng); });
        polys[z.id] = poly;
        if (z.own) {
          var c = path.reduce(function (a, p) { return { lat: a.lat + p.lat / path.length, lng: a.lng + p.lng / path.length }; }, { lat: 0, lng: 0 });
          labels.push(new google.maps.Marker({ map: map, position: c, clickable: false, icon: { path: google.maps.SymbolPath.CIRCLE, scale: 0 }, label: { text: z.name, color: '#15171C', fontSize: '12px', fontWeight: '700' } }));
        }
      });
      if (!b.isEmpty()) map.fitBounds(b, 30);
      paint();
    }

    drawSide(); drawTools();
    loadMaps().then(function () {
      map = new google.maps.Map(mapBox, { center: { lat: parseFloat(airport.lat), lng: parseFloat(airport.lng) }, zoom: 10, mapTypeControl: false, streetViewControl: false, clickableIcons: false, gestureHandling: 'greedy' });
      map.addListener('click', function (e) { if (mode && mode.draw) addPoint(e.latLng); });
      paintAll(); drawSide();
      api('GET', '/zones/nearby').then(function (r) {
        var mineNames = me.zones.map(function (z) { return z.name.toLowerCase(); });
        nearby = (r.zones || []).map(function (z) { z.used = mineNames.indexOf(z.name.toLowerCase()) >= 0; return z; });
        nearby.forEach(function (z) {
          if (z.used) return;
          var poly = new google.maps.Polygon({ map: map, paths: z.polygon.map(function (p) { return { lat: p[0], lng: p[1] }; }), strokeColor: '#6B7280', strokeOpacity: 0.8, strokeWeight: 1, fillColor: '#9CA3AF', fillOpacity: 0.08, zIndex: 0 });
          poly.addListener('click', function (e) {
            if (mode && mode.draw) { addPoint(e.latLng); return; }
            if (mode) return;
            if (window.confirm('Add ' + z.name + ' (drawn for ' + z.from + ') to your zones?')) copyZone(z);
          });
          nearPolys.push(poly);
        });
        drawSide();
      }).catch(function () {});
    }).catch(function (e) {
      clear(mapBox);
      add(mapBox, h('div', { class: 'atc-nomap' }, h('p', { text: e.message === 'nokey' ? 'The map is not available right now. Please try again later.' : e.message })));
    });
  }

  /* ---------- Prices ---------- */

  function viewPriceList() {
    add(app, head('Upload your price list', 'The fastest way to set up: your vehicles, zones and prices from one file. Afterwards, check your vehicles (add each car), the shapes of your zones on the map, and any missing price.'));
    add(app, airportBar());
    var box = h('div', { class: 'pe-root' });
    add(app, box);
    window.ATCPrices.mount(box, {
      api: api, base: '/prices', who: 'provider', h1: false, upload: true, toast: toast, fail: fail, airportId: me.airport && me.airport.id,
      onSaved: function () { api('GET', '/me').then(function (d) { me = d; nav(); }); }
    });
  }

  function viewPrices() {
    var ps = me.steps.prices;
    add(app, airportBar());
    if (ps && ps.missing) {
      add(app, h('section', { class: 'card pad warn', 'data-tour': 'missing' }, h('h2', { text: ps.missing + ' one-way price' + (ps.missing === 1 ? '' : 's') + ' still missing' }),
        h('p', { class: 'small', text: 'Every zone you serve needs a price for every vehicle category you sell. Travelers can only book where there is a price.' }),
        h('ul', { class: 'small', style: 'margin:0;padding-left:18px' }, ps.missing_list.map(function (m) { return h('li', { text: m.zone + ' · ' + m.vehicle }); })),
        ps.missing > ps.missing_list.length ? h('p', { class: 'small atc-muted', text: 'and ' + (ps.missing - ps.missing_list.length) + ' more.' }) : null));
    }
    var box = h('div', { class: 'pe-root' });
    add(app, box);
    window.ATCPrices.mount(box, {
      api: api, base: '/prices', who: 'provider', h1: true, toast: toast, fail: fail, airportId: me.airport && me.airport.id,
      onSaved: function () { api('GET', '/me').then(function (d) { me = d; nav(); }); }
    });
  }

  /* ---------- Documents ---------- */

  function viewDocuments() {
    add(app, head('Documents', 'Upload a PDF or a photo. We check each one within two working days and remind you 30 days before it expires.'));
    me.doc_types.forEach(function (t) {
      var docs = me.documents.filter(function (d) { return d.type === t.type; });
      var latest = docs[0];
      var file = h('input', { type: 'file', accept: 'application/pdf,image/jpeg,image/png,image/webp' });
      var exp = h('input', { type: 'date' });
      var btn = h('button', { class: 'btn dark', type: 'submit', text: latest ? 'Upload a new one' : 'Upload' });
      var form = h('form', { class: 'grid3 align-end', onsubmit: function (e) {
        e.preventDefault();
        if (!file.files.length) { file.focus(); return; }
        var fd = new FormData();
        fd.append('type', t.type); fd.append('expires_on', exp.value); fd.append('file', file.files[0]);
        btn.disabled = true; btn.textContent = 'Uploading…';
        api('POST', '/documents', fd, true).then(function (d) { saved(d, 'Uploaded. We will review it.'); }).catch(fail)
          .then(function () { btn.disabled = false; btn.textContent = 'Upload'; });
      } }, field('File (PDF or photo, up to 10 MB)', file), field('Expires on (if it has a date)', exp), h('div', { class: 'atc-actions' }, btn));
      var chip = latest ? h('span', { class: 'chip ' + ({ approved: 'green', rejected: 'red', pending: 'orange' }[latest.status] || 'grey'), text: { approved: 'Approved', rejected: 'Not accepted', pending: 'Under review' }[latest.status] || latest.status }) : h('span', { class: 'chip grey', text: t.required ? 'Required' : 'Optional' });
      add(app, h('section', { class: 'card pad' },
        h('div', { class: 'row-head' }, h('div', null, h('h2', { text: t.name }), h('p', { class: 'atc-muted', text: t.hint })), chip),
        latest ? h('p', { class: 'small' }, h('a', { href: latest.url, target: '_blank', rel: 'noopener', text: latest.original_name }),
          ' · sent ' + latest.created_at.slice(0, 10) + (latest.expires_on ? ' · expires ' + latest.expires_on : '') + (latest.status === 'rejected' && latest.note ? ' · ' + latest.note : '')) : null,
        form));
    });
  }

  /* ---------- Payout ---------- */

  function viewPayout() {
    var p = me.payout;
    var method = h('select', null, h('option', { value: 'bank', selected: p.method !== 'wise', text: 'Bank transfer' }), h('option', { value: 'wise', selected: p.method === 'wise', text: 'Wise' }));
    var currency = h('select', null, h('option', { value: 'MXN', selected: p.currency !== 'USD', text: 'MXN (Mexican pesos)' }), h('option', { value: 'USD', selected: p.currency === 'USD', text: 'USD (US dollars)' }));
    var holder = h('input', { type: 'text', value: p.holder || '', autocomplete: 'off' });
    var bank = h('input', { type: 'text', value: p.bank || '', placeholder: 'BBVA, Santander…' });
    var account = h('input', { type: 'text', inputmode: 'numeric', autocomplete: 'off', placeholder: p.set && p.last4 ? 'ending ' + p.last4 + ' (type again to change)' : '18 digits' });
    var email = h('input', { type: 'email', value: p.email || '' });
    var accLabel = h('span', { text: 'CLABE (18 digits)' });
    var bankRow = h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Bank' }), bank), h('label', { class: 'field' }, accLabel, account));
    var wiseRow = h('div', { class: 'grid2' }, field('Email of your Wise account', email));
    function sync() {
      var wise = method.value === 'wise';
      bankRow.classList.toggle('hidden', wise); wiseRow.classList.toggle('hidden', !wise);
      accLabel.textContent = currency.value === 'MXN' ? 'CLABE (18 digits)' : 'Account number';
    }
    method.addEventListener('change', sync); currency.addEventListener('change', sync); sync();
    var btn = h('button', { class: 'btn dark', type: 'submit', text: 'Save payout details' });
    add(app, [head('Payout details', 'We pay you on the 10th of each month for the trips completed the month before.'),
      p.set ? h('p', { class: 'chip green', text: 'Saved: ' + (p.method === 'wise' ? 'Wise · ' + p.email : (p.bank ? p.bank + ' · ' : '') + 'ending ' + p.last4) + ' · ' + p.currency }) : null,
      h('form', { class: 'card pad', autocomplete: 'off', onsubmit: function (e) {
        e.preventDefault();
        if (method.value === 'bank' && !account.value && p.set) { toast('Type the full account number again to change your details.', true); return; }
        btn.disabled = true;
        api('PUT', '/payout', { method: method.value, currency: currency.value, holder: holder.value, bank: bank.value, account: account.value, email: email.value })
          .then(function (d) { saved(d, 'Payout details saved.'); }).catch(fail).then(function () { btn.disabled = false; });
      } },
        h('div', { class: 'grid2' }, field('How we pay you', method), field('Currency', currency)),
        h('div', { class: 'grid2' }, field('Account holder (as the bank has it)', holder)),
        bankRow, wiseRow,
        h('p', { class: 'small atc-muted', text: 'Stored encrypted. After saving, we only ever show the last 4 digits.' }),
        h('div', { class: 'atc-actions' }, btn))]);
  }

  /* ---------- Agreement ---------- */

  function viewAgreement() {
    var p = me.provider;
    var accepted = !!p.agreement_accepted_at;
    var box = h('input', { type: 'checkbox' });
    var btn = h('button', { class: 'btn dark', type: 'button', disabled: true, text: 'Accept the agreement' });
    box.addEventListener('change', function () { btn.disabled = !box.checked; });
    btn.addEventListener('click', function () {
      api('POST', '/agreement').then(function (d) { saved(d, 'Agreement accepted.'); }).catch(fail);
    });
    add(app, [head('Provider agreement', 'Version ' + me.agreement.version),
      h('section', { class: 'card pad agreement' },
        h('ol', null, me.agreement.terms.map(function (t) { return h('li', { text: t }); })),
        accepted
          ? h('p', { class: 'chip green', text: 'Accepted on ' + p.agreement_accepted_at.slice(0, 16).replace('T', ' ') + ' (UTC)' })
          : h('div', { class: 'atc-actions' }, h('label', { class: 'tick' }, box, 'I have read the agreement and accept it on behalf of ' + p.name + '.'), btn))]);
  }

  /* ---------- Account ---------- */

  function viewAccount() {
    var a = me.account;
    var newEmail = h('input', { type: 'email', autocomplete: 'email' });
    var pwE = h('input', { type: 'password', autocomplete: 'current-password' });
    var eBtn = h('button', { class: 'btn dark', type: 'submit', text: 'Send confirmation link' });
    var cur = h('input', { type: 'password', autocomplete: 'current-password' });
    var nw = h('input', { type: 'password', minlength: 10, autocomplete: 'new-password' });
    var pBtn = h('button', { class: 'btn dark', type: 'submit', text: 'Change password' });
    add(app, [
      head('Account', 'How you sign in to the portal.'),
      h('form', { class: 'card pad', onsubmit: function (e) {
        e.preventDefault(); eBtn.disabled = true;
        api('POST', '/account/email', { email: newEmail.value, current: pwE.value })
          .then(function (d) { saved(d, 'Check ' + d.account.pending_email + ' and click the link to confirm.'); }).catch(fail).then(function () { eBtn.disabled = false; });
      } },
        h('h2', { text: 'Sign-in email' }),
        h('p', null, 'You sign in with ', h('strong', { text: a.email }), '.'),
        a.pending_email ? h('p', { class: 'chip orange', text: 'Waiting for you to confirm ' + a.pending_email + ' (link sent, valid 24 hours)' }) : null,
        h('div', { class: 'grid2' }, field('New sign-in email', newEmail), field('Your current password', pwE)),
        h('p', { class: 'small atc-muted', text: 'We send a link to the new address. Your email only changes when you click it. The email for bookings is under Company.' }),
        h('div', { class: 'atc-actions' }, eBtn)),
      h('form', { class: 'card pad', onsubmit: function (e) {
        e.preventDefault(); pBtn.disabled = true;
        api('POST', '/account/password', { current: cur.value, new: nw.value })
          .then(function () { cur.value = ''; nw.value = ''; toast('Password changed.'); }).catch(fail).then(function () { pBtn.disabled = false; });
      } },
        h('h2', { text: 'Password' }),
        h('div', { class: 'grid2' }, field('Current password', cur), field('New password (at least 10 characters)', nw)),
        h('div', { class: 'atc-actions' }, pBtn))
    ]);
  }

  /* ---------- Extras ---------- */

  function extrasEditor(get, save, intro) {
    var box = h('div', { class: 'stack' }, h('p', { class: 'atc-muted', text: 'Loading…' }));
    var units = { per_booking: 'per booking', per_ride: 'per ride', per_item: 'each' };
    function draw(d) {
      clear(box);
      var pct = d.commission_bp / 100;
      add(box, h('section', { class: 'card' }, h('div', { class: 'scroll' }, h('table', null,
        h('thead', null, h('tr', null, ['Extra', 'Offer it', 'Suggested retail price (USD)', 'Amount you will get paid', 'Max per booking', ''].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
        h('tbody', null, d.items.map(function (x) {
          var on = h('input', { type: 'checkbox', checked: x.on });
          var ret = h('input', { type: 'number', min: 1, step: '1', value: x.retail !== null ? x.retail / 100 : '', placeholder: (x.suggested / 100) + ' suggested', style: 'max-width:130px' });
          var get = h('input', { type: 'number', min: 1, step: '0.01', value: x.provider !== null ? (x.provider / 100).toFixed(2) : '', style: 'max-width:130px' });
          ret.addEventListener('input', function () { get.value = ret.value ? (ret.value * (1 - pct / 100)).toFixed(2) : ''; });
          get.addEventListener('input', function () { ret.value = get.value ? Math.round(get.value / (1 - pct / 100)) : ''; });
          var max = h('select', null, Array.from({ length: x.max_qty }, function (_, i) { return h('option', { value: i + 1, selected: x.my_max === i + 1, text: String(i + 1) }); }));
          var btn = h('button', { class: 'btn', type: 'button', text: 'Save', onclick: function () {
            btn.disabled = true;
            save(x.id, { on: on.checked ? 1 : 0, retail: ret.value, max_qty: max.value }).then(function (nd) { toast(on.checked ? x.name + ' is on.' : x.name + ' is off.'); draw(nd); }).catch(function (e) { btn.disabled = false; fail(e); });
          } });
          return h('tr', null, h('td', null, h('strong', { text: x.name }), h('div', { class: 'small atc-muted', text: units[x.unit] + (x.description ? ' · ' + x.description : '') })), h('td', null, on), h('td', null, ret), h('td', null, get), h('td', null, x.max_qty > 1 ? max : '1'), h('td', null, btn));
        }))))), h('p', { class: 'small atc-muted', text: 'Our commission on extras is ' + pct + '%, as on transfers. Per ride: a child seat on a round trip counts twice. Travelers add extras on the details step; you see them on the booking and in your payout with the ride.' }));
    }
    get().then(draw).catch(fail);
    add(app, [head('Extras', intro), box]);
  }
  function viewExtras() {
    extrasEditor(function () { return api('GET', '/extras'); }, function (id, body) { return api('POST', '/extras/' + id, body); }, 'Child seats, a supermarket stop, drinks: switch on what you can bring and set your price. Most travelers add something.');
  }

  /* ---------- Tickets ---------- */

  var tTypes = { traveler_no_show: 'Traveler didn’t show up', schedule: 'Flight or schedule problem', overcharge: 'Payment or price problem', lost_item: 'Lost item', complaint: 'Complaint about the traveler', provider: 'Question for our team', other: 'Something else' };
  function newTicket(booking, onDone) {
    var type = h('select', null, Object.keys(tTypes).map(function (k) { return h('option', { value: k, selected: k === (booking ? 'other' : 'provider'), text: tTypes[k] }); }));
    var subj = booking ? null : h('input', { type: 'text', maxlength: 200, placeholder: 'Short title' });
    var body = h('textarea', { rows: 4, placeholder: 'Tell us what happened' });
    var btn = h('button', { class: 'btn dark', type: 'submit', text: 'Send to our team' });
    return h('form', { class: 'card pad', style: 'gap:10px', onsubmit: function (e) {
      e.preventDefault(); btn.disabled = true;
      api('POST', '/tickets', { booking: booking || '', type: type.value, subject: subj ? subj.value : '', body: body.value })
        .then(function (t) { toast('Sent. We answer within 48 hours, the same day if the trip is soon.'); onDone(t); }).catch(function (x) { btn.disabled = false; fail(x); });
    } }, h('strong', { text: booking ? 'Report a problem with this booking' : 'Write to our team' }), field('About', type), subj ? field('Title', subj) : null, field('Message', body), h('div', null, btn));
  }
  function reportBox(number) {
    var box = h('div', { hidden: true });
    return h('section', { class: 'card pad' }, h('div', { class: 'atc-actions', style: 'justify-content:space-between' }, h('h2', { text: 'A problem with this booking?' }),
      h('button', { class: 'link', type: 'button', text: 'Report it to our team', onclick: function () { box.hidden = !box.hidden; if (!box.firstChild) add(box, newTicket(number, function (t) { go('tickets/' + t.id); })); } })), box);
  }
  function viewTickets() {
    var form = h('div', { hidden: true });
    var list = h('section', { class: 'card pad' }, h('p', { class: 'atc-muted', text: 'Loading…' }));
    add(app, [head('Tickets', 'Problems and questions between you and our team. To report a problem with a booking, open the booking.'),
      h('div', null, h('button', { class: 'btn dark', type: 'button', text: 'Write to our team', onclick: function () { form.hidden = !form.hidden; if (!form.firstChild) add(form, newTicket('', function (t) { go('tickets/' + t.id); })); } })),
      form, list]);
    api('GET', '/tickets').then(function (rows) {
      clear(list);
      if (!rows.length) { add(list, h('p', { class: 'atc-muted', text: 'No tickets.' })); return; }
      add(list, h('div', { class: 'rows' }, rows.map(function (t) {
        return h('a', { class: 'row', href: C.base + 'tickets/' + t.id + '/', 'data-go': 'tickets/' + t.id },
          h('span', null, h('strong', { text: t.ref + ' · ' + t.subject }), h('div', { class: 'small atc-muted', text: (t.booking ? 'Booking ' + t.booking + ' · ' : '') + 'updated ' + t.updated })),
          h('span', { class: 'chip ' + (t.open ? (t.status_name === 'Waiting for your reply' ? 'orange' : 'blue') : 'grey'), text: t.status_name }));
      })));
    }).catch(fail);
  }
  function viewTicket(id) {
    add(app, h('p', { class: 'atc-muted', text: 'Loading…' }));
    api('GET', '/tickets/' + id).then(function draw(t) {
      clear(app);
      var text = h('textarea', { rows: 4 });
      var send = h('button', { class: 'btn dark', type: 'button', text: 'Send', onclick: function () {
        send.disabled = true; api('POST', '/tickets/' + id, { body: text.value }).then(function (n) { toast('Sent.'); draw(n); }).catch(function (x) { send.disabled = false; fail(x); });
      } });
      add(app, [h('a', { class: 'back', href: C.base + 'tickets/', 'data-go': 'tickets', text: '← Tickets' }),
        head(t.ref + ' · ' + t.subject, (t.booking ? 'Booking ' + t.booking + ' · ' : '') + t.type_name + ' · ' + t.status_name),
        h('section', { class: 'card pad' }, h('ol', { class: 'thread' }, t.messages.map(function (m) {
          return h('li', { class: 'msg ' + m.author }, h('div', { class: 'small atc-muted strong', text: m.name + ' · ' + m.when + ' UTC' }), h('div', { class: 'body', text: m.body }));
        })), t.open ? [field('Your reply', text), h('div', null, send)] : h('p', { class: 'atc-muted', text: 'This ticket is closed. Write a new one if you need us.' }))]);
    }).catch(fail);
  }

  /* ---------- start ---------- */

  api('GET', '/me').then(function (d) { me = d; route(); }).catch(function (e) {
    clear(app); add(app, h('p', { class: 'chip red', text: 'Could not load the portal: ' + e.message }));
  });
})();
