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
  function saved(data, msg) { me = data; nav(); toast(msg || 'Saved.'); route(); }

  /* ---------- routing ---------- */

  var VIEWS = [
    ['', 'Setup', null],
    ['company', 'Company', 'company'],
    ['vehicles', 'Vehicles', 'vehicles'],
    ['coverage', 'Zones you serve', 'coverage'],
    ['prices', 'Prices', null],
    ['documents', 'Documents', 'documents'],
    ['payout', 'Payout details', 'payout'],
    ['agreement', 'Agreement', 'agreement'],
    ['account', 'Account', null]
  ];

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

  function nav() {
    clear(side);
    var cur = path();
    VIEWS.forEach(function (v) {
      var st = v[2] && me.steps[v[2]];
      add(side, h('a', { href: C.base + v[0] + (v[0] ? '/' : ''), 'data-go': v[0], class: cur === v[0] ? 'on' : '' },
        h('span', { text: v[1] }),
        st ? h('i', { class: 'dot ' + st.state, title: st.label, 'aria-label': st.label }) : null,
        v[0] === 'prices' ? h('i', { class: 'soon', text: 'soon' }) : null));
    });
  }

  function route() {
    if (!me) return;
    nav();
    var p = path();
    clear(app);
    ({ '': viewSetup, company: viewCompany, vehicles: viewVehicles, coverage: viewCoverage, prices: viewPrices,
       documents: viewDocuments, payout: viewPayout, agreement: viewAgreement, account: viewAccount }[p] || viewSetup)();
  }

  function head(title, sub) {
    return h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: title }), sub ? h('p', { class: 'atc-muted', text: sub }) : null));
  }

  /* ---------- Setup (home) ---------- */

  function viewSetup() {
    var p = me.provider, s = me.steps;
    var keys = ['company', 'vehicles', 'coverage', 'documents', 'payout', 'agreement', 'approval'];
    var done = keys.filter(function (k) { return s[k].state === 'done'; }).length;
    var welcome = /welcome=1/.test(location.search);
    add(app, [
      head(welcome ? 'Welcome, ' + p.name : p.name, p.status === 'live' ? 'You are live: travelers can book you.' : 'Travelers can book you once every step is green.'),
      h('ol', { class: 'steps', 'aria-label': 'Setup steps' }, keys.map(function (k) {
        var st = s[k];
        var link = k === 'approval' ? null : k;
        var inner = [h('span', { class: 'st', text: st.label.toUpperCase() }), h('span', { class: 'nm', text: st.name })];
        return h('li', { class: 'step ' + st.state }, link ? h('a', { href: C.base + link + '/', 'data-go': link }, inner) : h('div', null, inner));
      })),
      h('section', { class: 'card pad' },
        h('h2', { text: done + ' of ' + keys.length + ' done' }),
        h('p', { class: 'atc-muted', text: nextHint() }),
        h('p', { class: 'small atc-muted', text: 'Questions? Write to ' + C.support + '.' }))
    ]);
    function nextHint() {
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        if (s[k].state === 'todo') {
          return {
            company: 'Next: complete your company details.',
            vehicles: 'Next: tick the vehicle categories you sell and add your vehicles.',
            coverage: 'Next: pick the zones you serve on the map.',
            documents: 'Next: upload your permit and your liability insurance.',
            payout: 'Next: tell us where to send your money.',
            agreement: 'Next: read and accept the provider agreement.'
          }[k] || '';
        }
      }
      return p.status === 'live' ? 'All set.' : 'All sent. We review your documents within two working days and switch you on.';
    }
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

  /* ---------- Vehicles ---------- */

  function viewVehicles() {
    var airport = me.airport;
    add(app, head('Vehicles', 'Tick the categories you sell at ' + (airport ? airport.city + ' (' + airport.iata + ')' : 'your airport') + ', then add each vehicle.'));

    var cats = h('section', { class: 'card pad' }, h('h2', { text: 'Categories you sell' }));
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
      add(cats, h('div', { class: 'svc' + (on ? ' on' : '') },
        h('label', { class: 'svc-head' }, box, h('strong', { text: t.name }), h('span', { class: 'atc-muted', text: 'usually up to ' + t.max_passengers + ' passengers · ' + t.max_bags + ' bags' })),
        body));
    });

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

    add(app, [cats, h('section', { class: 'card pad' }, h('h2', { text: 'Your vehicles' }),
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
    add(app, head('Zones you serve', 'Tick every zone where you pick up or drop off travelers. You need a price for each zone you tick.'));
    if (!offered.length) {
      add(app, h('section', { class: 'card pad' }, h('p', { text: 'First tick the vehicle categories you sell.' }),
        h('div', { class: 'atc-actions' }, h('a', { class: 'btn dark', href: C.base + 'vehicles/', 'data-go': 'vehicles', text: 'Go to Vehicles' }))));
      return;
    }
    var chosen = {};
    offered.forEach(function (s) { s.zone_ids.forEach(function (z) { chosen[z] = true; }); });

    var mapBox = h('div', { class: 'atc-map' });
    var list = h('div', { class: 'ticks col' });
    var saveBtn = h('button', { class: 'btn dark', type: 'button', text: 'Save zones' });
    var count = h('span', { class: 'atc-muted' });
    function refreshCount() { count.textContent = Object.keys(chosen).filter(function (k) { return chosen[k]; }).length + ' of ' + me.zones.length + ' zones'; }

    me.zones.forEach(function (z) {
      var cb = h('input', { type: 'checkbox', checked: !!chosen[z.id] });
      cb.addEventListener('change', function () { chosen[z.id] = cb.checked; paint(); refreshCount(); });
      z._cb = cb;
      add(list, h('label', { class: 'tick' }, cb, z.name));
    });
    saveBtn.addEventListener('click', function () {
      saveBtn.disabled = true;
      api('PUT', '/coverage', { airport_id: airport.id, zone_ids: Object.keys(chosen).filter(function (k) { return chosen[k]; }).map(Number) })
        .then(function (d) { saved(d, 'Zones saved.'); }).catch(fail).then(function () { saveBtn.disabled = false; });
    });
    refreshCount();

    add(app, h('div', { class: 'atc-split' },
      h('section', { class: 'card atc-mapcard' }, mapBox, h('p', { class: 'atc-note', text: 'Click a zone on the map or in the list to add or remove it.' })),
      h('section', { class: 'card pad atc-col' },
        h('div', { class: 'atc-actions' }, h('button', { class: 'link', type: 'button', onclick: function () { me.zones.forEach(function (z) { chosen[z.id] = true; z._cb.checked = true; }); paint(); refreshCount(); }, text: 'All' }),
          h('button', { class: 'link', type: 'button', onclick: function () { chosen = {}; me.zones.forEach(function (z) { z._cb.checked = false; }); paint(); refreshCount(); }, text: 'None' }), count),
        list, h('div', { class: 'atc-actions' }, saveBtn))));

    if (!me.zones.length) { clear(list); add(list, h('p', { class: 'atc-muted', text: 'Our team is still drawing the zones for this airport.' })); }

    function paint() {
      Object.keys(polys).forEach(function (id) {
        var on = !!chosen[id];
        polys[id].setOptions({ fillColor: on ? '#FBA53E' : '#FFFFFF', fillOpacity: on ? 0.5 : 0.25, strokeWeight: on ? 2 : 1 });
      });
    }
    loadMaps().then(function () {
      map = new google.maps.Map(mapBox, { center: { lat: parseFloat(airport.lat), lng: parseFloat(airport.lng) }, zoom: 9, mapTypeControl: false, streetViewControl: false, clickableIcons: false, gestureHandling: 'greedy' });
      polys = {};
      var b = new google.maps.LatLngBounds();
      me.zones.forEach(function (z) {
        var path = z.polygon.map(function (p) { return { lat: p[0], lng: p[1] }; });
        path.forEach(function (p) { b.extend(p); });
        var poly = new google.maps.Polygon({ map: map, paths: path, strokeColor: '#15171C', strokeOpacity: 0.9 });
        poly.addListener('click', function () { chosen[z.id] = !chosen[z.id]; z._cb.checked = chosen[z.id]; paint(); refreshCount(); });
        polys[z.id] = poly;
      });
      if (!b.isEmpty()) map.fitBounds(b, 30);
      paint();
    }).catch(function (e) {
      clear(mapBox);
      add(mapBox, h('div', { class: 'atc-nomap' }, h('p', { text: e.message === 'nokey' ? 'The map is not available right now. Use the list.' : e.message })));
    });
  }

  /* ---------- Prices (next version) ---------- */

  function viewPrices() {
    add(app, [head('Prices', 'Coming in the next version.'),
      h('section', { class: 'card pad' },
        h('p', { text: 'Here you will set, for every zone and vehicle, the Suggested retail price and the Amount you will get paid. Type either one and the other fills in.' }),
        h('p', { class: 'atc-muted', text: 'Our commission is ' + (me.commission_bp / 100) + '% of the Suggested retail price. Example: on a USD 100 sale you get paid USD ' + (100 - me.commission_bp / 100).toFixed(2) + '.' }))]);
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

  /* ---------- start ---------- */

  api('GET', '/me').then(function (d) { me = d; route(); }).catch(function (e) {
    clear(app); add(app, h('p', { class: 'chip red', text: 'Could not load the portal: ' + e.message }));
  });
})();
