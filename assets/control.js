/* ATCOM Booking Engine · control panel (/control). Plain JS, no build step.
 * Every text from the server goes in with textContent, never innerHTML. */
(function () {
  'use strict';

  var C = window.ATC || {};
  var app = document.getElementById('atc-app');
  var state = { boot: null, airportId: null, zones: [], zoneId: null, map: null, mapReady: null };

  /* ---------- helpers ---------- */

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
    t.textContent = msg;
    t.className = 'show' + (bad ? ' bad' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.className = ''; }, 3500);
  }
  function fail(e) { toast(e.message || String(e), true); }

  function money(bp) { return (bp / 100).toFixed(bp % 100 ? 2 : 0) + '%'; }
  function zoneName(id) {
    var z = state.zones.find(function (x) { return x.id === id; });
    return z ? z.name : 'No zone';
  }

  /* ---------- routing ---------- */

  function path() {
    var base = new URL(C.base).pathname;
    var p = location.pathname.indexOf(base) === 0 ? location.pathname.slice(base.length) : '';
    return p.replace(/^\/+|\/+$/g, '');
  }
  function go(p) { history.pushState({}, '', C.base + p + (p ? '/' : '')); route(); }
  window.addEventListener('popstate', route);
  var topSearch = document.querySelector('.atc-search');
  if (topSearch) topSearch.addEventListener('submit', function (e) {
    e.preventDefault();
    var q = topSearch.querySelector('input').value.trim();
    history.pushState({}, '', C.base + 'providers/' + (q ? '?q=' + encodeURIComponent(q) : ''));
    route();
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-go]');
    if (a) { e.preventDefault(); go(a.getAttribute('data-go')); }
  });
  document.querySelectorAll('a[data-view]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); go(a.getAttribute('data-view')); });
  });

  function route() {
    var parts = path().split('/');
    document.querySelectorAll('a[data-view]').forEach(function (a) {
      a.classList.toggle('on', a.getAttribute('data-view') === (parts[0] === 'import' ? 'providers' : parts[0]));
    });
    if (parts[0] === 'providers' && parts[1] && parts[2] === 'extras') return viewProviderExtras(parseInt(parts[1], 10));
    if (parts[0] === 'providers' && parts[1] && parts[2] === 'directory') return viewProviderDirectory(parseInt(parts[1], 10));
    if (parts[0] === 'providers' && parts[1]) return viewProvider(parseInt(parts[1], 10));
    if (parts[0] === 'extras') return viewExtrasCatalog();
    if (parts[0] === 'providers') return viewProviders();
    if (parts[0] === 'import') return viewImport();
    if (parts[0] === 'zones') return viewZones();
    if (parts[0] === 'meeting') return viewMeeting();
    if (parts[0] === 'payouts') return viewPayouts();
    if (parts[0] === 'coupons') return viewCoupons();
    if (parts[0] === 'insights') return viewInsights();
    if (parts[0] === 'report') return viewReport();
    if (parts[0] === 'tickets' && parts[1]) return viewTicket(parseInt(parts[1], 10));
    if (parts[0] === 'tickets') return viewTickets();
    if (parts[0] === 'affiliates' && parts[1]) return viewAffiliate(parseInt(parts[1], 10));
    if (parts[0] === 'affiliates') return viewAffiliates();
    if (parts[0] === 'airports') return viewAirports();
    if (parts[0] === 'bookings' && parts[1]) return viewBooking(parts[1]);
    if (parts[0] === 'bookings') return viewBookings();
    return viewDashboard();
  }

  /* ---------- Extras ---------- */

  var xUnits = { per_booking: 'per booking', per_ride: 'per ride', per_item: 'each' };

  function viewExtrasCatalog() {
    clear(app);
    var list = h('div');
    var formBox = h('div');
    function form(x, groups, done) {
      x = x || {};
      var name = h('input', { type: 'text', value: x.name || '', maxlength: 190 });
      var desc = h('input', { type: 'text', value: x.description || '', maxlength: 255 });
      var grp = h('select', null, Object.keys(groups).map(function (k) { return h('option', { value: k, selected: x.group === k, text: groups[k] }); }));
      var unit = h('select', null, Object.keys(xUnits).map(function (k) { return h('option', { value: k, selected: x.unit === k, text: xUnits[k] }); }));
      var max = h('input', { type: 'number', min: 1, max: 10, value: x.max_qty || 1, style: 'max-width:100px' });
      var sug = h('input', { type: 'number', min: 0, step: '1', value: x.suggested ? x.suggested / 100 : '', style: 'max-width:120px' });
      var sort = h('input', { type: 'number', value: x.sort || 999, style: 'max-width:100px' });
      var st = h('select', null, h('option', { value: 'active', selected: x.status !== 'off', text: 'On' }), h('option', { value: 'off', selected: x.status === 'off', text: 'Off (hidden everywhere)' }));
      var btn = h('button', { class: 'btn dark', type: 'submit', text: x.id ? 'Save' : 'Add to the catalog' });
      return h('form', { class: 'card pad', style: 'gap:10px', onsubmit: function (e) {
        e.preventDefault(); btn.disabled = true;
        api('POST', '/extras' + (x.id ? '/' + x.id : ''), { name: name.value, description: desc.value, group: grp.value, unit: unit.value, max_qty: max.value, suggested: sug.value, sort: sort.value, status: st.value }).then(function () { toast('Saved.'); done(); }).catch(function (er) { btn.disabled = false; fail(er); });
      } }, h('strong', { text: x.id ? 'Edit ' + x.name : 'New extra' }),
        h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Name (travelers see it)' }), name), h('label', { class: 'field' }, h('span', { text: 'Group' }), grp)),
        h('label', { class: 'field' }, h('span', { text: 'Short description' }), desc),
        h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Charged' }), unit), h('label', { class: 'field' }, h('span', { text: 'Most per booking' }), max)),
        h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Suggested price for providers (USD)' }), sug), h('label', { class: 'field' }, h('span', { text: 'Order' }), sort)),
        h('label', { class: 'field' }, h('span', { text: 'Status' }), st), h('div', null, btn));
    }
    function load() {
      api('GET', '/extras').then(function (r) {
        clear(list);
        add(list, h('section', { class: 'card' }, h('div', { class: 'scroll' }, h('table', null,
          h('thead', null, h('tr', null, ['Extra', 'Group', 'Charged', 'Max', 'Suggested', 'Status', ''].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
          h('tbody', null, r.catalog.map(function (x) {
            return h('tr', null, h('td', null, h('strong', { text: x.name }), h('div', { class: 'small atc-muted', text: x.description })), h('td', { text: r.groups[x.group] || x.group }), h('td', { text: xUnits[x.unit] }), h('td', { text: String(x.max_qty) }), h('td', { text: x.suggested ? cash(x.suggested) : '–' }),
              h('td', null, h('span', { class: 'chip ' + (x.status === 'active' ? 'green' : 'grey'), text: x.status === 'active' ? 'On' : 'Off' })),
              h('td', null, h('button', { class: 'link', type: 'button', text: 'Edit', onclick: function () { clear(formBox); add(formBox, form(x, r.groups, function () { clear(formBox); load(); })); window.scrollTo(0, 0); } })));
          }))))));
        newBtn.onclick = function () { clear(formBox); add(formBox, form(null, r.groups, function () { clear(formBox); load(); })); };
      }).catch(fail);
    }
    var newBtn = h('button', { class: 'btn dark', type: 'button', text: 'New extra' });
    add(app, [h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Extras catalog' }), h('p', { class: 'atc-muted', text: 'The extras travelers can add. Each provider switches on the ones it offers and sets its own price (in its portal, or here under the provider: Edit their extras).' })), newBtn), formBox, list]);
    load();
  }

  function viewProviderExtras(id) {
    clear(app);
    var box = h('div', { class: 'stack' }, h('p', { class: 'atc-muted', text: 'Loading…' }));
    add(app, [h('a', { class: 'back', href: C.base + 'providers/' + id + '/', 'data-go': 'providers/' + id, text: '← Provider' }), h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Their extras' }), h('p', { class: 'atc-muted', text: 'What this provider offers and at what price, as in their portal.' }))), box]);
    function draw(d) {
      clear(box);
      var pct = d.commission_bp / 100;
      add(box, h('section', { class: 'card' }, h('div', { class: 'scroll' }, h('table', null,
        h('thead', null, h('tr', null, ['Extra', 'Offered', 'Traveler pays (USD)', 'Provider gets', 'Max', ''].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
        h('tbody', null, d.items.map(function (x) {
          var on = h('input', { type: 'checkbox', checked: x.on });
          var ret = h('input', { type: 'number', min: 1, step: '1', value: x.retail !== null ? x.retail / 100 : '', placeholder: (x.suggested / 100) + ' suggested', style: 'max-width:130px' });
          var get = h('span', { text: x.provider !== null ? cash(x.provider) : '–' });
          ret.addEventListener('input', function () { get.textContent = ret.value ? 'USD ' + (ret.value * (1 - pct / 100)).toFixed(2) : '–'; });
          var max = h('input', { type: 'number', min: 1, max: x.max_qty, value: x.my_max, style: 'max-width:80px' });
          var btn = h('button', { class: 'btn', type: 'button', text: 'Save', onclick: function () { btn.disabled = true; api('POST', '/providers/' + id + '/extras/' + x.id, { on: on.checked ? 1 : 0, retail: ret.value, max_qty: max.value }).then(function (nd) { toast('Saved.'); draw(nd); }).catch(function (e) { btn.disabled = false; fail(e); }); } });
          return h('tr', null, h('td', null, h('strong', { text: x.name }), h('div', { class: 'small atc-muted', text: xUnits[x.unit] })), h('td', null, on), h('td', null, ret), h('td', null, get), h('td', null, max), h('td', null, btn));
        }))))));
    }
    api('GET', '/providers/' + id + '/extras').then(draw).catch(fail);
  }

  /* ---------- Searches (insights) ---------- */

  function rangeBar(st, onChange, periods) {
    var bar = h('div', { class: 'pe-pills', role: 'group', 'aria-label': 'Period' });
    function draw() {
      clear(bar);
      periods.forEach(function (o) {
        add(bar, h('button', { type: 'button', class: 'pe-pill' + (String(st.days) === String(o[0]) ? ' on' : ''), text: o[1], onclick: function () { st.days = o[0]; draw(); onChange(); } }));
      });
    }
    draw();
    return bar;
  }

  function viewInsights() {
    clear(app);
    var st = { days: 30 };
    var body = h('div', { class: 'stack' });
    add(app, [
      h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Where travelers are searching' }), h('p', { class: 'atc-muted', text: 'Every results page on our sites, without personal data (our team and robots don’t count). Airports with searches and no provider are your recruitment list.' }))),
      h('div', { class: 'pe-bar' }, rangeBar(st, load, [[1, 'Today'], [7, '7 days'], [30, '30 days'], [90, '90 days'], [365, '12 months']])),
      body
    ]);
    function table(head, rows) {
      return h('div', { class: 'scroll' }, h('table', null, h('thead', null, h('tr', null, head.map(function (t) { return h('th', { scope: 'col', text: t }); }))), h('tbody', null, rows)));
    }
    function load() {
      clear(body); add(body, h('p', { class: 'atc-muted', text: 'Loading…' }));
      api('GET', '/insights?days=' + st.days).then(function (r) {
        clear(body);
        var f = r.funnel, tv = r.travelers;
        var max = Math.max.apply(null, r.per_day.map(function (d) { return d.searches; }).concat([1]));
        var base = h('input', { type: 'number', min: 0, value: tv.base, style: 'max-width:160px' });
        add(body, [
          h('div', { class: 'stats' },
            h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Searches' }), h('span', { class: 'big', text: String(r.searches) }), h('span', { class: 'small atc-muted', text: r.from + ' to ' + r.to })),
            h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Unique visitors' }), h('span', { class: 'big', text: String(r.visitors) })),
            h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Searches with nothing to sell' }), h('span', { class: 'big', text: String(r.none) }), h('span', { class: 'small atc-muted', text: r.searches ? Math.round(100 * r.none / r.searches) + '%' : '' })),
            h('div', { class: 'stat' + (r.no_provider_airports ? ' warn' : '') }, h('span', { class: 'small atc-muted strong', text: 'Airports searched with no provider' }), h('span', { class: 'big', text: String(r.no_provider_airports) })),
            h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Paid bookings' }), h('span', { class: 'big', text: String(f.paid) }), h('span', { class: 'small atc-muted', text: cash(f.sales) + (r.searches ? ' · ' + (100 * f.paid / r.searches).toFixed(1) + '% of searches' : '') }))),
          r.per_day.length > 1 ? h('section', { class: 'card pad' }, h('h2', { text: 'Searches per day' }), h('div', { class: 'bars' }, r.per_day.map(function (d) { return h('span', { title: d.day + ': ' + d.searches + ' searches, ' + d.visitors + ' visitors', style: 'height:' + Math.max(2, Math.round(100 * d.searches / max)) + '%' }); }))) : null,
          h('section', { class: 'card pad' }, h('h2', { text: 'Funnel' }), h('div', { class: 'funnel' },
            [['Searches', f.searches], ['Checkout started', f.started], ['Details filled in', f.details], ['Paid', f.paid]].map(function (x, i, all) {
              return h('div', null, h('strong', { text: String(x[1]) }), h('span', { class: 'small atc-muted', text: x[0] + (i && all[i - 1][1] ? ' · ' + Math.round(100 * x[1] / all[i - 1][1]) + '%' : '') }));
            }))),
          h('div', { class: 'pe-split' },
            h('div', { class: 'pe-left' },
              h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'By airport' })), table(['Airport', 'Searches', 'Nothing to sell', 'Visitors', 'Ready providers'], r.airports.length ? r.airports.map(function (a) {
                return h('tr', null, h('td', null, h('strong', { text: a.name }), ' ', h('span', { class: 'small atc-muted', text: a.iata })), h('td', { class: 'strong', text: String(a.searches) }), h('td', { text: String(a.none) }), h('td', { text: String(a.visitors) }),
                  h('td', null, a.ready ? String(a.ready) : h('span', { class: 'chip orange', text: 'None yet' })));
              }) : [h('tr', null, h('td', { colspan: 5, class: 'atc-muted', text: 'No searches yet in this period.' }))])),
              r.unserved.length ? h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Airports we don’t serve' })), table(['Typed', 'Searches'], r.unserved.map(function (u) { return h('tr', null, h('td', { text: u.airport }), h('td', { class: 'strong', text: String(u.searches) })); }))) : null),
            h('div', { class: 'bk-side' },
              h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Top destinations' })), table(['Destination', 'Searches'], r.destinations.map(function (d) { return h('tr', null, h('td', null, d.dest, d.iata ? h('span', { class: 'small atc-muted', text: ' · ' + d.iata }) : null, d.none ? h('div', { class: 'small atc-muted', text: d.none + ' with nothing to sell' }) : null), h('td', { class: 'strong', text: String(d.searches) })); }))),
              h('section', { class: 'card pad' }, h('h2', { text: 'Traveler count' }),
                h('p', { class: 'small atc-muted', text: 'The figure on the homepage and checkout: this base, or the real number of people who searched in the last 30 days once that is higher. The pop-up uses a quarter of it for 7 days and a 30th for 24 hours.' }),
                h('div', { class: 'stats' }, h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Searchers, 30 days' }), h('span', { class: 'big', text: String(tv.searchers) })), h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Public figure' }), h('span', { class: 'big', text: tv.public.toLocaleString('en-US') }))),
                h('label', { class: 'field' }, h('span', { text: 'Base' }), base),
                h('div', null, h('button', { class: 'btn dark', type: 'button', text: 'Save base', onclick: function (e) { e.target.disabled = true; api('POST', '/insights/base', { base: base.value }).then(function () { toast('Saved.'); load(); }).catch(function (x) { e.target.disabled = false; fail(x); }); } }))))),
          h('div', { class: 'grid3' },
            h('section', { class: 'card pad' }, h('h2', { text: 'Top airports (bookings)' }), r.top_airports.length ? h('table', { class: 'bare' }, h('tbody', null, r.top_airports.map(function (a) { return h('tr', null, h('th', { scope: 'row', text: a.name + ' (' + a.iata + ')' }), h('td', { text: String(a.bookings) })); }))) : h('p', { class: 'atc-muted small', text: 'None in this period.' })),
            h('section', { class: 'card pad' }, h('h2', { text: 'Top providers' }), r.top_providers.length ? h('table', { class: 'bare' }, h('tbody', null, r.top_providers.map(function (a) { return h('tr', null, h('th', { scope: 'row', text: a.name }), h('td', { text: a.transfers + ' transfers' })); }))) : h('p', { class: 'atc-muted small', text: 'None in this period.' })),
            h('section', { class: 'card pad' }, h('h2', { text: 'Top affiliates' }), r.top_affiliates.length ? h('table', { class: 'bare' }, h('tbody', null, r.top_affiliates.map(function (a) { return h('tr', null, h('th', { scope: 'row', text: a.name }), h('td', { text: a.bookings + ' · ' + cash(a.sales) })); }))) : h('p', { class: 'atc-muted small', text: 'No affiliate bookings in this period.' })))
        ]);
      }).catch(fail);
    }
    load();
  }

  /* ---------- Money report ---------- */

  function viewReport() {
    clear(app);
    var st = { period: 'day', date: new Date().toISOString().slice(0, 10), basis: 'trip' };
    var body = h('div', { class: 'stack' });
    var date = h('input', { type: 'date', value: st.date, style: 'max-width:180px' });
    var basis = h('select', { style: 'max-width:200px' }, h('option', { value: 'trip', text: 'By trip date' }), h('option', { value: 'booked', text: 'By booking date' }));
    var pills = h('div', { class: 'pe-pills', role: 'group' });
    function drawPills() { clear(pills); [['day', 'Day'], ['week', 'Week'], ['month', 'Month']].forEach(function (o) { add(pills, h('button', { type: 'button', class: 'pe-pill' + (st.period === o[0] ? ' on' : ''), text: o[1], onclick: function () { st.period = o[0]; drawPills(); load(); } })); }); }
    function qs() { return '?period=' + st.period + '&date=' + date.value + '&basis=' + basis.value; }
    date.addEventListener('change', load); basis.addEventListener('change', load);
    var dl = h('button', { class: 'btn', type: 'button', text: 'Download for Excel', onclick: function () {
      api('GET', '/report/csv' + qs()).then(function (r) {
        var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([r.csv], { type: 'text/csv' })); a.download = 'money-report-' + st.period + '-' + date.value + '.csv'; document.body.appendChild(a); a.click(); a.remove();
      }).catch(fail);
    } });
    add(app, [
      h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Money report' }), h('p', { class: 'atc-muted', text: 'Every paid booking with all its fees: what the traveler paid, Stripe’s fee, the affiliate’s commission, refunds, what the provider gets and what we keep. In USD. Bookings from the old site are left out (settled there).' })), dl),
      h('div', { class: 'pe-bar' }, pills, date, basis),
      body
    ]);
    drawPills();
    var cols = [['Reservation', null], ['Status', null], ['Provider', null], ['Channel', null], ['Price', 'price'], ['Discount', 'discount'], ['Extras', 'extras'], ['Charged', 'charged'], ['Stripe fee', 'fee'], ['Affiliate', 'affiliate'], ['Refunded', 'refunded'], ['Provider gets', 'provider_cost'], ['We keep', 'margin']];
    function m(c) { return c === null || c === undefined ? '–' : (c < 0 ? '− ' : '') + cash(Math.abs(c)); }
    function tbl(rows, tot, title) {
      return h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: title + ' · ' + tot.count })),
        h('div', { class: 'scroll' }, h('table', { class: 'report' },
          h('thead', null, h('tr', null, cols.map(function (c) { return h('th', { scope: 'col', class: c[1] ? 'num' : '', text: c[0] }); }))),
          h('tbody', null, rows.length ? rows.map(function (x) {
            return h('tr', null,
              h('td', { class: 'rep-ref' }, bookingLink(x.number), h('div', { class: 'small atc-muted', text: x.date + ' ' + x.time + ' · ' + x.traveler }), h('div', { class: 'small atc-muted', text: x.route + ' · ' + x.vehicle + ' · ' + x.trip })),
              h('td', { text: x.status }), h('td', { text: x.provider }), h('td', null, x.channel, h('div', { class: 'small atc-muted', text: x.method + (x.coupon ? ' · ' + x.coupon : '') })),
              cols.slice(4).map(function (c) { return h('td', { class: 'num' + (c[1] === 'margin' ? ' strong' : '') + (c[1] === 'margin' && x.margin < 0 ? ' neg' : ''), text: m(x[c[1]]) }); }));
          }) : h('tr', null, h('td', { colspan: cols.length, class: 'atc-muted', text: 'None.' }))),
          rows.length ? h('tfoot', null, h('tr', null, h('th', { colspan: 4, text: 'Total' }), cols.slice(4).map(function (c) { return h('th', { class: 'num', text: m(tot[c[1]]) }); }))) : null)));
    }
    function load() {
      clear(body); add(body, h('p', { class: 'atc-muted', text: 'Loading…' }));
      api('GET', '/report' + qs()).then(function (r) {
        clear(body);
        var a = r.all;
        add(body, [
          h('p', { class: 'atc-muted', text: (r.from === r.to ? r.from : r.from + ' to ' + r.to) + ' · ' + (r.basis === 'booked' ? 'by booking date' : 'by trip date') }),
          h('div', { class: 'stats' },
            h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Charged' }), h('span', { class: 'big', text: cash(a.charged) }), h('span', { class: 'small atc-muted', text: a.count + ' bookings' })),
            h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Providers get' }), h('span', { class: 'big', text: cash(a.provider_cost) })),
            h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Stripe fees' }), h('span', { class: 'big', text: cash(a.fee) }), r.fee_missing ? h('span', { class: 'small atc-muted', text: r.fee_missing + ' without the fee saved (paid before 0.13)' }) : null),
            h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Affiliates and refunds' }), h('span', { class: 'big', text: cash(a.affiliate + a.refunded) })),
            h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'We keep' }), h('span', { class: 'big' + (a.margin < 0 ? ' neg' : ''), text: m(a.margin) }), h('span', { class: 'small atc-muted', text: a.charged ? Math.round(100 * a.margin / a.charged) + '% of charged' : '' }))),
          r.period !== 'day' && r.by_day.length > 1 ? h('section', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', { text: 'Per day' })), h('div', { class: 'scroll' }, h('table', { class: 'report' },
            h('thead', null, h('tr', null, ['Day', 'Bookings', 'Charged', 'Stripe fee', 'Affiliate', 'Refunded', 'Provider gets', 'We keep'].map(function (t, i) { return h('th', { class: i ? 'num' : '', text: t }); }))),
            h('tbody', null, r.by_day.map(function (d) { return h('tr', null, h('td', { text: d.day }), h('td', { class: 'num', text: String(d.count) }), ['charged', 'fee', 'affiliate', 'refunded', 'provider_cost', 'margin'].map(function (k) { return h('td', { class: 'num', text: m(d[k]) }); })); }))))) : null,
          tbl(r.rows, r.totals, 'Bookings'),
          tbl(r.other, r.other_totals, 'Cancelled, refunded and no-show')
        ]);
      }).catch(fail);
    }
    load();
  }

  /* ---------- Discount codes ---------- */

  function viewCoupons() {
    clear(app);
    var tbody = h('tbody');
    var affs = [];
    function couponForm(c, done) {
      c = c || {};
      var code = h('input', { type: 'text', value: c.code || '', maxlength: 30, style: 'text-transform:uppercase', placeholder: 'SUMMER10' });
      var kind = h('select', null, h('option', { value: 'percent', selected: c.amount === null || c.amount === undefined, text: 'Percent off' }), h('option', { value: 'amount', selected: c.amount !== null && c.amount !== undefined, text: 'Amount off (USD)' }));
      var val = h('input', { type: 'number', min: 1, step: '0.01', value: c.percent !== null && c.percent !== undefined ? c.percent : (c.amount ? (c.amount / 100).toFixed(2) : ''), style: 'max-width:140px' });
      var owner = h('select', null, h('option', { value: 'platform', selected: c.owner !== 'affiliate', text: 'Ours (out of our share)' }), h('option', { value: 'affiliate', selected: c.owner === 'affiliate', text: 'An affiliate’s (out of its commission)' }));
      var aff = h('select', null, h('option', { value: '', text: 'Choose the affiliate' }), affs.map(function (a) { return h('option', { value: a.id, selected: a.id === c.party_id, text: a.name + ' · ' + a.code + ' · ' + pctBp(a.rate) }); }));
      var affRow = h('label', { class: 'field' }, h('span', { text: 'Affiliate' }), aff);
      var min = h('input', { type: 'number', min: 0, step: '1', value: c.min ? c.min / 100 : '', placeholder: 'none', style: 'max-width:140px' });
      var from = h('input', { type: 'date', value: c.from || '' }), to = h('input', { type: 'date', value: c.to || '' });
      var max = h('input', { type: 'number', min: 1, step: '1', value: c.max_uses || '', placeholder: 'no limit', style: 'max-width:140px' });
      var note = h('input', { type: 'text', value: c.note || '', maxlength: 190, placeholder: 'Where it is used (for us)' });
      var status = h('select', null, h('option', { value: 'active', selected: c.status !== 'off', text: 'On' }), h('option', { value: 'off', selected: c.status === 'off', text: 'Off' }));
      function sync() { affRow.hidden = owner.value !== 'affiliate'; if (owner.value === 'affiliate') kind.value = 'percent'; kind.disabled = owner.value === 'affiliate'; }
      owner.addEventListener('change', sync); sync();
      var btn = h('button', { class: 'btn dark', type: 'submit', text: c.id ? 'Save' : 'Create code' });
      return h('form', { class: 'card pad', style: 'gap:10px', onsubmit: function (e) {
        e.preventDefault(); btn.disabled = true;
        var body = { code: code.value, owner: owner.value, party_id: aff.value, min: min.value, from: from.value, to: to.value, max_uses: max.value, note: note.value, status: status.value };
        if (kind.value === 'percent') body.percent = val.value; else body.amount = val.value;
        api('POST', '/coupons' + (c.id ? '/' + c.id : ''), body).then(function () { toast(c.id ? 'Saved.' : 'Code created.'); done(); }).catch(function (x) { btn.disabled = false; fail(x); });
      } },
        h('strong', { text: c.id ? 'Edit ' + c.code : 'New discount code' }),
        h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Code' }), code), h('label', { class: 'field' }, h('span', { text: 'Who pays the discount' }), owner)),
        affRow,
        h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Discount' }), h('div', { class: 'atc-actions' }, kind, val)), h('label', { class: 'field' }, h('span', { text: 'Minimum booking (USD)' }), min)),
        h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Valid from' }), from), h('label', { class: 'field' }, h('span', { text: 'Valid until' }), to)),
        h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Uses allowed' }), max), h('label', { class: 'field' }, h('span', { text: 'Status' }), status)),
        h('label', { class: 'field' }, h('span', { text: 'Note' }), note),
        h('p', { class: 'small atc-muted', text: 'Ours: up to 20% or a fixed amount; the provider is paid the same and an affiliate still earns its rate on what the traveler paid. An affiliate’s: up to its own rate, taken from its commission, and the booking counts as theirs.' }),
        h('div', null, btn));
    }
    var formBox = h('div');
    function load() {
      api('GET', '/coupons').then(function (rows) {
        clear(tbody);
        if (!rows.length) add(tbody, h('tr', null, h('td', { colspan: 6, class: 'atc-muted', text: 'No codes yet.' })));
        rows.forEach(function (c) {
          add(tbody, h('tr', null,
            h('td', null, h('code', { class: 'strong', text: c.code }), c.note ? h('div', { class: 'small atc-muted', text: c.note }) : null),
            h('td', { text: c.percent !== null ? c.percent + '%' : cash(c.amount) }),
            h('td', null, c.owner === 'affiliate' ? h('a', { href: C.base + 'affiliates/' + c.party_id + '/', 'data-go': 'affiliates/' + c.party_id, text: c.party }) : 'Ours'),
            h('td', { class: 'small', text: [c.min ? 'from ' + cash(c.min) : null, c.from ? 'starts ' + c.from : null, c.to ? 'until ' + c.to : null].filter(Boolean).join(' · ') || '–' }),
            h('td', { text: c.uses + (c.max_uses ? ' of ' + c.max_uses : '') }),
            h('td', null, h('span', { class: 'chip ' + (c.state === 'Active' ? 'green' : 'grey'), text: c.state }), ' ', h('button', { class: 'link', type: 'button', text: 'Edit', onclick: function () { clear(formBox); add(formBox, couponForm(c, function () { clear(formBox); load(); })); window.scrollTo(0, 0); } }))));
        });
      }).catch(fail);
    }
    add(app, [
      h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Discount codes' }), h('p', { class: 'atc-muted', text: 'Codes travelers type on the payment step. Each use is counted when the booking is paid.' })),
        h('button', { class: 'btn dark', type: 'button', text: 'New code', onclick: function () { clear(formBox); add(formBox, couponForm(null, function () { clear(formBox); load(); })); } })),
      formBox,
      h('section', { class: 'card' }, h('div', { class: 'scroll' }, h('table', null,
        h('thead', null, h('tr', null, ['Code', 'Discount', 'Paid by', 'Conditions', 'Uses', 'Status'].map(function (t) { return h('th', { scope: 'col', text: t }); }))), tbody)))
    ]);
    api('GET', '/affiliates?status=live').then(function (r) { affs = r; }).catch(function () {});
    load();
  }

  /* ---------- Tickets ---------- */

  var tkTone = { open: 'orange', waiting_us: 'orange', waiting_traveler: 'blue', waiting_provider: 'blue', resolved: 'green', closed: 'grey' };
  function tkLink(t) { return h('a', { class: 'strong', href: C.base + 'tickets/' + t.id + '/', 'data-go': 'tickets/' + t.id, text: t.ref }); }
  function tkChips(t) {
    return [h('span', { class: 'chip ' + (tkTone[t.status] || 'grey'), text: t.status_name }), t.urgent && t.open ? h('span', { class: 'chip red', text: 'Urgent' }) : null, t.overdue ? h('span', { class: 'chip red', text: 'Reply overdue' }) : null, t.held ? h('span', { class: 'chip orange', text: 'Money on hold' }) : null];
  }

  function newTicketForm(booking, onDone) {
    var types = { no_show: 'Driver didn’t show up or was late', wrong_vehicle: 'Wrong vehicle or service', overcharge: 'Asked to pay more', refund: 'Refund request', schedule: 'Flight or schedule problem', lost_item: 'Lost item', complaint: 'Complaint', traveler_no_show: 'Traveler didn’t show up', provider: 'Provider question', affiliate: 'Affiliate question', other: 'Something else' };
    var ref = h('input', { type: 'text', value: booking || '', placeholder: 'AT…', style: 'text-transform:uppercase' });
    var type = h('select', null, Object.keys(types).map(function (k) { return h('option', { value: k, text: types[k] }); }));
    var subject = h('input', { type: 'text', maxlength: 200, placeholder: 'Short title (optional)' });
    var body = h('textarea', { rows: 3, placeholder: 'What happened (our team only; reply to the traveler or provider from the ticket)' });
    var money = h('input', { type: 'number', min: 0, step: '0.01', placeholder: '0.00', style: 'max-width:140px' });
    var hold = h('input', { type: 'checkbox' });
    var btn = h('button', { class: 'btn dark', type: 'submit', text: 'Open ticket' });
    return h('form', { class: 'card pad', style: 'gap:10px', onsubmit: function (e) {
      e.preventDefault(); btn.disabled = true;
      api('POST', '/tickets', { booking: ref.value.trim(), type: type.value, subject: subject.value, body: body.value, money: money.value, hold: hold.checked ? 1 : 0 })
        .then(function (t) { toast('Ticket ' + t.ref + ' opened.'); onDone(t); }).catch(function (x) { btn.disabled = false; fail(x); });
    } },
      h('strong', { text: 'New ticket' }),
      h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Booking (optional)' }), ref), h('label', { class: 'field' }, h('span', { text: 'Type' }), type)),
      h('label', { class: 'field' }, h('span', { text: 'Title' }), subject),
      h('label', { class: 'field' }, h('span', { text: 'Note' }), body),
      h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Money at stake (USD)' }), money), h('label', { class: 'atc-inline', style: 'align-self:end' }, hold, ' Hold the provider’s and affiliate’s money for this booking')),
      h('div', null, btn));
  }

  function ticketsCard(b, number) {
    var list = b.tickets || [];
    var form = h('div', { hidden: true });
    return h('section', { class: 'card pad' + (list.some(function (t) { return t.open; }) ? ' warn' : '') },
      h('div', { class: 'row-flat' }, h('h2', { text: 'Tickets' }), h('button', { class: 'link', type: 'button', text: 'Open a ticket', onclick: function () {
        form.hidden = !form.hidden; if (!form.firstChild) add(form, newTicketForm(number, function (t) { go('tickets/' + t.id); }));
      } })),
      list.length ? h('div', { class: 'rows' }, list.map(function (t) {
        return h('a', { class: 'row', href: C.base + 'tickets/' + t.id + '/', 'data-go': 'tickets/' + t.id }, h('span', null, h('strong', { text: t.ref + ' · ' }), t.subject), h('span', { class: 'atc-actions' }, tkChips(t)));
      })) : h('p', { class: 'atc-muted small', text: 'No tickets on this booking.' }),
      form);
  }

  function viewTickets() {
    clear(app);
    var params = new URLSearchParams(location.search);
    var st = { status: params.get('status') || 'open', q: '', mine: false };
    var tbody = h('tbody');
    var pills = h('div', { class: 'pe-pills', role: 'group', 'aria-label': 'Show' });
    var q = h('input', { type: 'search', placeholder: 'Booking, title or provider', 'aria-label': 'Find a ticket' });
    var mine = h('input', { type: 'checkbox' });
    var form = h('div', { hidden: true });
    var counts = {};
    function drawPills() {
      clear(pills);
      [['open', 'Open'], ['urgent', 'Urgent or overdue'], ['waiting_us', 'Waiting on us'], ['holds', 'Money on hold'], ['resolved', 'Resolved'], ['all', 'All']].forEach(function (o) {
        var n = { open: counts.open, urgent: counts.urgent, waiting_us: counts.us, holds: counts.holds }[o[0]];
        add(pills, h('button', { type: 'button', class: 'pe-pill' + (st.status === o[0] ? ' on' : ''), 'aria-pressed': st.status === o[0] ? 'true' : 'false', text: o[1] + (n ? ' · ' + n : ''), onclick: function () { st.status = o[0]; load(); } }));
      });
    }
    function load() {
      api('GET', '/tickets?status=' + st.status + '&q=' + encodeURIComponent(q.value) + (mine.checked ? '&mine=1' : '')).then(function (r) {
        counts = r.counts; drawPills(); clear(tbody);
        if (!r.tickets.length) add(tbody, h('tr', null, h('td', { colspan: 6, class: 'atc-muted', text: st.status === 'open' ? 'No open tickets.' : 'Nothing here.' })));
        r.tickets.forEach(function (t) {
          add(tbody, h('tr', null,
            h('td', null, tkLink(t), h('div', { class: 'small atc-muted', text: 'by ' + t.opened_by + ' · ' + t.created })),
            h('td', null, h('div', { class: 'strong', text: t.subject }), h('div', { class: 'small atc-muted', text: t.type_name })),
            h('td', null, t.booking ? bookingLink(t.booking) : '–', t.provider ? h('div', { class: 'small atc-muted', text: t.provider }) : null),
            h('td', { class: 'nowrap', text: t.money ? cash(t.money) : '–' }),
            h('td', null, h('div', { text: t.updated }), h('div', { class: 'small atc-muted', text: 'last: ' + t.last_by + (t.assigned ? ' · ' + t.assigned : '') })),
            h('td', null, h('div', { class: 'atc-actions' }, tkChips(t)))));
        });
      }).catch(fail);
    }
    var tm; q.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(load, 300); });
    mine.addEventListener('change', load);
    add(app, [
      h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Tickets' }), h('p', { class: 'atc-muted', text: 'Problems on bookings from travelers, providers, card disputes and low ratings. First reply within 48 hours; urgent ones (trip in the next 48 hours, disputes) the same day.' })),
        h('button', { class: 'btn dark', type: 'button', text: 'New ticket', onclick: function () { form.hidden = !form.hidden; if (!form.firstChild) add(form, newTicketForm('', function (t) { go('tickets/' + t.id); })); } })),
      form,
      h('div', { class: 'pe-bar' }, pills, h('label', { class: 'atc-inline small' }, mine, ' Mine'), q),
      h('section', { class: 'card' }, h('div', { class: 'scroll' }, h('table', null,
        h('thead', null, h('tr', null, ['Ticket', 'About', 'Booking', 'At stake', 'Last reply', 'Status'].map(function (t) { return h('th', { scope: 'col', text: t }); }))), tbody)))
    ]);
    drawPills(); load();
  }

  function viewTicket(id) {
    clear(app);
    add(app, h('p', { class: 'atc-muted', text: 'Loading…' }));
    api('GET', '/tickets/' + id).then(function draw(t) {
      clear(app);
      var bi = t.booking_info;
      function upd(body, msg) { return api('POST', '/tickets/' + id, body).then(function (n) { toast(msg || 'Saved.'); draw(n); }).catch(fail); }
      // Thread
      var thread = h('ol', { class: 'thread' }, t.messages.map(function (m) {
        return h('li', { class: 'msg ' + m.author + (m.audience === 'internal' ? ' internal' : '') },
          h('div', { class: 'small atc-muted strong' }, m.name + ' · ' + m.role + ' · ' + m.when + ' UTC', h('span', { class: 'chip ' + (m.audience === 'internal' ? 'grey' : 'blue'), text: m.audience === 'internal' ? 'internal' : 'seen by ' + m.audience })),
          h('div', { class: 'body', text: m.body }));
      }));
      var to = h('select', null, h('option', { value: 'internal', text: 'Internal note (our team only)' }), bi ? h('option', { value: 'traveler', text: 'Reply to the traveler (email)' }) : null, t.party_id ? h('option', { value: 'provider', text: 'Reply to the provider (email + portal)' }) : null);
      var text = h('textarea', { rows: 4 });
      var send = h('button', { class: 'btn dark', type: 'button', text: 'Send', onclick: function () {
        send.disabled = true;
        api('POST', '/tickets/' + id + '/reply', { audience: to.value, body: text.value }).then(function (n) { toast(to.value === 'internal' ? 'Note added.' : 'Sent.'); draw(n); }).catch(function (x) { send.disabled = false; fail(x); });
      } });
      // Side: status, assign, hold
      var status = h('select', null, Object.keys({ open: 1, waiting_traveler: 1, waiting_provider: 1, waiting_us: 1, resolved: 1, closed: 1 }).map(function (k) {
        return h('option', { value: k, selected: t.status === k, text: { open: 'Open', waiting_traveler: 'Waiting on the traveler', waiting_provider: 'Waiting on the provider', waiting_us: 'Waiting on us', resolved: 'Resolved', closed: 'Closed' }[k] });
      }));
      status.addEventListener('change', function () { upd({ status: status.value }, 'Status changed.'); });
      var who = h('select', null, h('option', { value: '0', text: 'Nobody' }), (t.staff || []).map(function (s) { return h('option', { value: s.id, selected: s.id === t.assigned_id, text: s.name }); }));
      who.addEventListener('change', function () { upd({ assigned_to: who.value }, 'Assigned.'); });
      // Resolve
      var res = h('div', { class: 'card pad', hidden: true, style: 'gap:10px' });
      if (t.open || t.held) {
        var outcome = h('select', null, [['no_fault', 'Provider not at fault: pay as usual'], ['provider_fault', 'Provider at fault: don’t pay them (affiliate commission reversed too)'], ['partial', 'Partly: the provider gets…'], ['no_money', 'No money changes']].map(function (o) { return h('option', { value: o[0], text: o[1] }); }));
        var pAmt = h('input', { type: 'number', min: 0, step: '0.01', value: bi ? (bi.provider_cents / 100).toFixed(2) : '', style: 'max-width:140px' });
        var pRow = h('label', { class: 'field', hidden: true }, h('span', { text: 'Provider gets in total (USD)' }), pAmt);
        outcome.addEventListener('change', function () { pRow.hidden = outcome.value !== 'partial'; });
        var refund = h('input', { type: 'number', min: 0, step: '0.01', placeholder: '0.00', style: 'max-width:140px' });
        var note = h('textarea', { rows: 2, placeholder: 'What we decided (sent to the people you tick below)' });
        var tt = h('input', { type: 'checkbox', checked: !!bi }), tp = h('input', { type: 'checkbox', checked: !!t.party_id });
        var go2 = h('button', { class: 'btn dark', type: 'button', text: 'Resolve', onclick: function () {
          go2.disabled = true;
          api('POST', '/tickets/' + id + '/resolve', { outcome: outcome.value, provider: pAmt.value, refund: refund.value, note: note.value, tell_traveler: tt.checked ? 1 : 0, tell_provider: tp.checked ? 1 : 0 })
            .then(function (n) { toast('Resolved.'); draw(n); }).catch(function (x) { go2.disabled = false; fail(x); });
        } });
        add(res, [h('strong', { text: 'Resolve' }), h('label', { class: 'field' }, h('span', { text: 'Outcome' }), outcome), pRow,
          bi && !bi.legacy ? h('label', { class: 'field' }, h('span', { text: 'Refund to the traveler’s card (USD, optional; up to ' + cash(bi.total - bi.refunded) + ')' }), refund) : null,
          h('label', { class: 'field' }, h('span', { text: 'Decision' }), note),
          bi ? h('label', { class: 'atc-inline' }, tt, ' Tell the traveler') : null, t.party_id ? h('label', { class: 'atc-inline' }, tp, ' Tell the provider') : null,
          h('div', null, go2)]);
      }
      add(app, [
        h('a', { class: 'back', href: C.base + 'tickets/', 'data-go': 'tickets', text: '← Tickets' }),
        h('section', { class: 'card pad' },
          h('div', { class: 'atc-actions' }, h('h1', { text: t.ref }), tkChips(t)),
          h('p', { class: 'strong', text: t.subject }),
          h('p', { class: 'atc-muted', text: t.type_name + ' · opened by ' + t.opened_by + ' · ' + t.created + ' UTC' + (t.due && t.open ? ' · reply due ' + t.due + ' UTC' : '') + (t.outcome ? ' · outcome: ' + t.outcome.replace('_', ' ') : '') }),
          t.dispute ? h('p', null, h('a', { href: 'https://dashboard.stripe.com/disputes/' + t.dispute, target: '_blank', rel: 'noopener', text: 'Answer the dispute in Stripe ↗' })) : null,
          h('div', { class: 'atc-actions' }, (t.open || t.held) ? h('button', { class: 'btn dark', type: 'button', text: 'Resolve…', onclick: function () { res.hidden = !res.hidden; } }) : null)),
        res,
        h('div', { class: 'pe-split' },
          h('div', { class: 'pe-left' },
            h('section', { class: 'card pad' }, h('h2', { text: 'Conversation' }), t.messages.length ? thread : h('p', { class: 'atc-muted', text: 'No messages yet.' }),
              h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Who reads it' }), to)), h('label', { class: 'field' }, h('span', { text: 'Message' }), text), h('div', null, send))),
          h('div', { class: 'bk-side' },
            h('section', { class: 'card pad' }, h('h2', { text: 'Ticket' }), h('label', { class: 'field' }, h('span', { text: 'Status' }), status), h('label', { class: 'field' }, h('span', { text: 'Assigned to' }), who),
              h('div', { class: 'atc-actions' },
                h('button', { class: 'link', type: 'button', text: t.urgent ? 'Not urgent' : 'Mark urgent', onclick: function () { upd({ urgent: t.urgent ? 0 : 1 }); } }),
                bi ? h('button', { class: 'link', type: 'button', text: t.held ? 'Release the money' : 'Hold the money', onclick: function () { upd({ hold: t.held ? 0 : 1 }, t.held ? 'Released: it goes into the next payout.' : 'On hold: kept out of payouts until resolved.'); } }) : null)),
            bi ? h('section', { class: 'card pad' }, h('h2', { text: 'Booking' }),
              h('div', { class: 'kv' }, kv('Reference', bookingLink(bi.number)), kv('Status', bi.status), kv('Traveler', bi.traveler), kv('Email', bi.email), kv('Phone', bi.phone), kv('Paid', cash(bi.total) + (bi.refunded ? ' · refunded ' + cash(bi.refunded) : '')))) : null,
            t.lines && t.lines.length ? h('section', { class: 'card pad money' }, h('h2', { text: 'Money on this booking' }), h('table', { class: 'bare' }, h('tbody', null, t.lines.map(function (l) {
              return h('tr', null, h('th', { scope: 'row' }, h('div', { text: l.role + (l.name ? ' · ' + l.name : '') }), h('div', { class: 'small atc-muted', text: l.status.replace('_', ' ') + (l.in_payout ? ' · in a payout' : '') })), h('td', { text: (l.amount < 0 ? '− ' : '') + cash(Math.abs(l.amount)) }));
            })))) : null))
      ]);
    }).catch(fail);
  }

  /* ---------- Affiliates ---------- */

  function pctBp(bp) { return (bp / 100).toString().replace(/\.0+$/, '') + '%'; }
  var affTone = { live: 'green', pending: 'orange', paused: 'orange', declined: 'grey' };
  var affLabel = { live: 'Approved', pending: 'To review', paused: 'Paused', declined: 'Declined' };

  function viewAffiliates() {
    clear(app);
    var params = new URLSearchParams(location.search);
    var st = params.get('status') || '';
    var tbody = h('tbody');
    var pills = h('div', { class: 'pe-pills', role: 'group', 'aria-label': 'Show' });
    var q = h('input', { type: 'search', placeholder: 'Find by name, email or code', 'aria-label': 'Find an affiliate' });
    var all = [];
    function drawPills() {
      clear(pills);
      [['', 'All'], ['pending', 'To review'], ['live', 'Approved'], ['paused', 'Paused'], ['declined', 'Declined']].forEach(function (o) {
        add(pills, h('button', { type: 'button', class: 'pe-pill' + (st === o[0] ? ' on' : ''), 'aria-pressed': st === o[0] ? 'true' : 'false', text: o[1], onclick: function () { st = o[0]; drawPills(); draw(); } }));
      });
    }
    function draw() {
      clear(tbody);
      var t = q.value.trim().toLowerCase();
      var rows = all.filter(function (a) { return (!st || a.status === st) && (!t || (a.name + ' ' + a.email + ' ' + a.code + ' ' + a.contact).toLowerCase().indexOf(t) > -1); });
      if (!rows.length) add(tbody, h('tr', null, h('td', { colspan: 7, class: 'atc-muted', text: 'No affiliates match.' })));
      rows.forEach(function (a) {
        add(tbody, h('tr', null,
          h('td', null, h('a', { class: 'strong', href: C.base + 'affiliates/' + a.id + '/', 'data-go': 'affiliates/' + a.id, text: a.name }), h('div', { class: 'small atc-muted', text: [a.contact, a.email].filter(Boolean).join(' · ') })),
          h('td', null, h('code', { text: a.code }), a.invited ? h('div', { class: 'small atc-muted', text: 'invited by an affiliate' }) : null),
          h('td', { text: pctBp(a.rate) + (a.custom ? ' (own rate)' : '') }),
          h('td', { text: String(a.clicks) }),
          h('td', { text: String(a.bookings) }),
          h('td', { class: 'strong nowrap', text: cash(a.earned) }),
          h('td', null, h('span', { class: 'chip ' + (affTone[a.status] || 'grey'), text: affLabel[a.status] || a.status }), h('div', { class: 'small atc-muted', text: 'since ' + a.since }))));
      });
    }
    q.addEventListener('input', draw);
    add(app, [
      h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Affiliates' }), h('p', { class: 'atc-muted', text: 'Sites, agencies and creators who send us travelers. They earn their rate on completed bookings within 30 days of a click, and the affiliate who invited them earns the referral rate. Approving sends them the invitation to their panel.' }))),
      h('div', { class: 'pe-bar' }, pills, q),
      h('section', { class: 'card' }, h('div', { class: 'scroll' }, h('table', null,
        h('thead', null, h('tr', null, ['Affiliate', 'Code', 'Rate', 'Clicks', 'Bookings', 'Commission', 'Status'].map(function (t) { return h('th', { scope: 'col', text: t }); }))), tbody)))
    ]);
    drawPills();
    add(tbody, h('tr', null, h('td', { colspan: 7, class: 'atc-muted', text: 'Loading…' })));
    api('GET', '/affiliates').then(function (r) { all = r; draw(); }).catch(fail);
  }

  function viewAffiliate(id) {
    clear(app);
    add(app, h('p', { class: 'atc-muted', text: 'Loading…' }));
    api('GET', '/affiliates/' + id).then(function draw(a) {
      clear(app);
      if (a.warning) toast(a.warning, true);
      function save(body, msg) { return api('POST', '/affiliates/' + id, body).then(function (n) { toast(msg || 'Saved.'); draw(n); }).catch(fail); }
      var actions = h('div', { class: 'atc-actions' });
      if (a.status === 'pending') add(actions, [
        h('button', { class: 'btn dark', type: 'button', text: 'Approve and send the invitation', onclick: function (e) { e.target.disabled = true; save({ status: 'live' }, 'Approved. The invitation is on its way.'); } }),
        h('button', { class: 'btn', type: 'button', text: 'Decline', onclick: function () { save({ status: 'declined' }, 'Declined.'); } })]);
      if (a.status === 'live') add(actions, h('button', { class: 'btn', type: 'button', text: 'Pause', onclick: function () { save({ status: 'paused' }, 'Paused: their links still work, but bookings no longer earn commission.'); } }));
      if (a.status === 'paused' || a.status === 'declined') add(actions, h('button', { class: 'btn dark', type: 'button', text: a.status === 'paused' ? 'Turn back on' : 'Approve after all', onclick: function () { save({ status: 'live' }, 'Approved.'); } }));
      if (a.status === 'live' && a.portal.state !== 'active') add(actions, h('button', { class: 'link', type: 'button', text: a.portal.state === 'invited' ? 'Resend the invitation' : 'Send the invitation', onclick: function (e) {
        e.target.disabled = true; api('POST', '/affiliates/' + id + '/invite').then(function (r) { toast('Sent to ' + r.sent_to + (r.test ? ' (test mode)' : '') + '.'); }).catch(fail);
      } }));
      var rate = h('input', { type: 'number', min: 0, max: 30, step: '0.5', value: a.custom ? a.rate / 100 : '', placeholder: (a.default / 100) + ' (default)', style: 'max-width:140px' });
      var st = a.stats;
      add(app, [
        h('a', { class: 'back', href: C.base + 'affiliates/', 'data-go': 'affiliates', text: '← Affiliates' }),
        h('section', { class: 'card pad' },
          h('div', { class: 'atc-actions' }, h('h1', { text: a.name }), h('span', { class: 'chip ' + (affTone[a.status] || 'grey'), text: affLabel[a.status] || a.status }), h('code', { text: a.code })),
          h('p', { class: 'atc-muted', text: [a.contact, a.email, a.phone, 'since ' + a.since].filter(Boolean).join(' · ') }),
          a.website ? h('p', null, h('a', { href: a.website, target: '_blank', rel: 'noopener', text: a.website + ' ↗' })) : null,
          a.about ? h('p', { class: 'note', text: a.about }) : null,
          a.parent ? h('p', { class: 'small' }, 'Invited by ', h('a', { href: C.base + 'affiliates/' + a.parent.id + '/', 'data-go': 'affiliates/' + a.parent.id, text: a.parent.name })) : null,
          actions),
        h('div', { class: 'stats' },
          h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Clicks this month' }), h('span', { class: 'big', text: String(st.clicks) })),
          h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Bookings this month' }), h('span', { class: 'big', text: String(st.bookings) })),
          h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Commission waiting' }), h('span', { class: 'big', text: cash(st.pending) })),
          h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Earned, to pay' }), h('span', { class: 'big', text: cash(st.earned) })),
          h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: 'Paid' }), h('span', { class: 'big', text: cash(st.paid) }))),
        h('div', { class: 'pe-split' },
          h('div', { class: 'pe-left' },
            h('section', { class: 'card pad' }, h('h2', { text: 'Bookings' }),
              a.bookings.length ? h('div', { class: 'scroll' }, h('table', null,
                h('thead', null, h('tr', null, ['Reference', 'Trip', 'Status', 'Total', 'Commission'].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
                h('tbody', null, a.bookings.map(function (b) {
                  return h('tr', null, h('td', null, bookingLink(b.number), h('div', { class: 'small atc-muted', text: 'booked ' + b.booked + (b.for_client ? ' · by them for a client' : '') + (b.referral ? ' · referral' : '') })),
                    h('td', null, h('div', { text: b.where }), h('div', { class: 'small atc-muted', text: b.trip })), h('td', { text: b.status }), h('td', { class: 'nowrap', text: cash(b.total) }), h('td', { class: 'strong nowrap' }, cash(b.commission), h('div', { class: 'small atc-muted', text: b.cstatus })));
                })))) : h('p', { class: 'atc-muted', text: 'No bookings yet.' })),
            a.referred.length ? h('section', { class: 'card pad' }, h('h2', { text: 'Affiliates they invited' }), h('div', { class: 'rows' }, a.referred.map(function (r) {
              return h('a', { class: 'row', href: C.base + 'affiliates/' + r.id + '/', 'data-go': 'affiliates/' + r.id }, h('span', { text: r.name }), h('span', { class: 'chip ' + (affTone[r.status] || 'grey'), text: affLabel[r.status] || r.status }));
            }))) : null),
          h('div', { class: 'bk-side' },
            h('section', { class: 'card pad' }, h('h2', { text: 'Commission rate' }),
              h('label', { class: 'field' }, h('span', { text: 'Rate (%), empty = default ' + pctBp(a.default) }), rate),
              h('div', { class: 'atc-actions' }, h('button', { class: 'btn dark', type: 'button', text: 'Save rate', onclick: function () { save({ rate: rate.value }, 'Rate saved. It applies to new bookings.'); } }))),
            h('section', { class: 'card pad' }, h('h2', { text: 'Link and panel' }),
              a.link ? h('p', { class: 'small' }, h('code', { text: a.link })) : null,
              h('p', { class: 'small atc-muted', text: { active: 'Using the panel', invited: 'Invited ' + (a.portal.invited_at || '').slice(0, 10) + ', no password yet', not_invited: 'Not invited yet' }[a.portal.state] })),
            h('section', { class: 'card pad' }, h('h2', { text: 'Payout details' }),
              h('p', { class: 'small', text: a.payout.set ? ({ paypal: 'PayPal', wise: 'Wise', bank: 'Bank' }[a.payout.method] || a.payout.method) + ' · ' + (a.payout.email || ((a.payout.bank ? a.payout.bank + ' · ' : '') + 'ending ' + a.payout.last4)) : 'Not added yet.' }))))
      ]);
    }).catch(fail);
  }

  /* ---------- Bookings ---------- */

  function bookingLink(n) { return h('a', { class: 'strong ref', href: C.base + 'bookings/' + n + '/', 'data-go': 'bookings/' + n, text: n }); }

  function viewBookings() {
    clear(app);
    var params = new URLSearchParams(location.search);
    var st = { status: params.get('status') || '', q: params.get('q') || '', page: 1 };
    var tbody = h('tbody');
    var info = h('span', { class: 'small atc-muted' });
    var pager = h('div', { class: 'atc-actions' });
    var search = h('input', { type: 'search', placeholder: 'Find a reference', 'aria-label': 'Find a booking by reference', value: st.q });
    var pills = h('div', { class: 'pe-pills', role: 'group', 'aria-label': 'Show' });
    var opts = [['', 'All'], ['upcoming', 'Coming up'], ['no_provider', 'No provider'], ['paid', 'Paid'], ['unpaid', 'Not paid'], ['cancelled', 'Cancelled']];
    function drawPills() {
      clear(pills);
      opts.forEach(function (o) {
        add(pills, h('button', { type: 'button', class: 'pe-pill' + (st.status === o[0] ? ' on' : ''), 'aria-pressed': st.status === o[0] ? 'true' : 'false', text: o[1], onclick: function () { st.status = o[0]; st.page = 1; drawPills(); load(); } }));
      });
    }
    add(app, [
      h('div', { class: 'atc-head' },
        h('div', null, h('h1', { text: 'Bookings' }), h('p', { class: 'atc-muted', text: 'Every booking, newest first. Pick-up times in the airport\'s local time.' })),
        h('a', { class: 'btn dark', href: C.home, target: '_blank', rel: 'noopener', title: 'Book on the site while signed in: at payment you can pay with the client\'s card or email them the payment link.', text: 'New booking for a client ↗' })),
      h('div', { class: 'pe-bar' }, pills, search),
      h('section', { class: 'card' }, h('div', { class: 'scroll' }, h('table', null,
        h('thead', null, h('tr', null, ['Reference', 'Pick-up', 'Route', 'Vehicle', 'Pax', 'Provider', 'Total', 'Status'].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
        tbody))),
      h('div', { class: 'pe-bar' }, info, pager)
    ]);
    drawPills();
    var timer;
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { st.q = search.value; st.page = 1; load(); }, 300); });
    function load() {
      var qs = '?status=' + encodeURIComponent(st.status) + '&q=' + encodeURIComponent(st.q) + '&page=' + st.page;
      api('GET', '/bookings' + qs).then(function (r) {
        clear(tbody); clear(pager);
        if (!r.bookings.length) add(tbody, h('tr', null, h('td', { colspan: 8, class: 'atc-muted', text: 'No bookings match.' })));
        r.bookings.forEach(function (b) {
          add(tbody, h('tr', null,
            h('td', null, bookingLink(b.number), h('div', { class: 'small atc-muted', text: 'booked ' + b.booked + (b.legacy ? ' · old site' : '') })),
            h('td', { class: 'strong', text: b.travel || '–' }),
            h('td', null, h('div', { text: b.route || '–' }), h('div', { class: 'small atc-muted', text: b.trip === 'round_trip' ? 'Round trip' : 'One way' })),
            h('td', { text: b.vehicle || '–' }),
            h('td', { text: String(b.pax) }),
            h('td', null, b.provider ? document.createTextNode(b.provider) : (b.status === 'Paid' ? h('span', { class: 'chip red', text: 'Nobody yet' }) : '–')),
            h('td', { class: 'strong nowrap', text: cash(b.total) }),
            h('td', null, h('span', { class: 'chip ' + b.tone, text: b.status }))));
        });
        info.textContent = r.total + ' booking' + (r.total === 1 ? '' : 's') + (r.pages > 1 ? ' · page ' + r.page + ' of ' + r.pages : '');
        if (r.page > 1) add(pager, h('button', { class: 'btn', type: 'button', text: '← Newer', onclick: function () { st.page--; load(); } }));
        if (r.page < r.pages) add(pager, h('button', { class: 'btn', type: 'button', text: 'Older →', onclick: function () { st.page++; load(); } }));
      }).catch(fail);
    }
    load();
  }

  function kv(label, value) {
    return h('div', null, h('div', { class: 'small atc-muted strong', text: label }), h('div', { class: 'strong kv-v' }, value === null || value === undefined || value === '' ? '–' : value));
  }

  /* Team actions on a booking: resend, refund or cancel, service code. */
  function bookingActions(b, number, draw) {
    var a = b.actions || {};
    var box = h('div', { class: 'bk-actions' });
    var panel = h('div', { class: 'card pad', hidden: true, style: 'gap:10px;margin-top:10px' });
    var resend = a.can_resend ? h('button', { type: 'button', class: 'btn', text: 'Resend confirmation', onclick: function () {
      resend.disabled = true;
      api('POST', '/bookings/' + number + '/resend').then(function (nb) { toast('Confirmation sent again to ' + nb.traveler.email + '.'); }).catch(fail).then(function () { resend.disabled = false; });
    } }) : null;
    var refundBtn = a.refund_left > 0 || a.can_cancel ? h('button', { type: 'button', class: 'btn', text: 'Refund or cancel', onclick: function () { panel.hidden = !panel.hidden; } }) : null;
    if (refundBtn) {
      var amt = h('input', { type: 'number', min: 0, step: '0.01', max: (a.refund_left / 100).toFixed(2), value: (a.refund_left / 100).toFixed(2), style: 'max-width:160px' });
      var cancel = h('input', { type: 'checkbox', checked: true });
      var reason = h('input', { type: 'text', placeholder: 'Reason (the traveler does not see it)' });
      var go = h('button', { type: 'button', class: 'btn danger', text: 'Refund' });
      function label() { var v = +amt.value || 0; go.textContent = (cancel.checked ? 'Cancel booking' + (v ? ' and refund ' : '') : 'Refund ') + (v ? cash(Math.round(v * 100)) : ''); }
      amt.addEventListener('input', label); cancel.addEventListener('change', label); label();
      go.addEventListener('click', function () {
        go.disabled = true;
        api('POST', '/bookings/' + number + '/refund', { amount: amt.value, cancel: cancel.checked ? 1 : 0, reason: reason.value }).then(function (nb) { toast(cancel.checked ? 'Booking cancelled. Traveler and provider emailed.' : 'Refund sent. The traveler got an email.'); draw(nb); }).catch(function (e) { go.disabled = false; fail(e); });
      });
      add(panel, [
        h('strong', { text: 'Refund or cancel' }),
        h('span', { class: 'small atc-muted', text: a.refund_left > 0 ? 'Up to ' + cash(a.refund_left) + ' can go back to the traveler’s card (Stripe).' : 'Nothing left to refund through Stripe.' }),
        h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Refund (USD)' }), amt), h('label', { class: 'field' }, h('span', { text: 'Reason' }), reason)),
        a.can_cancel ? h('label', { class: 'atc-inline' }, cancel, ' Also cancel the booking (rides and provider pay are cancelled; traveler and provider are emailed)') : null,
        h('div', null, go)
      ]);
      if (!a.can_cancel) { cancel.checked = false; label(); }
    }
    var code = a.service_code ? h('span', { class: 'small atc-muted', text: 'Service code ' + a.service_code + (a.code_tries ? ' · ' + a.code_tries + ' wrong tries' : '') }) : null;
    var unlock = a.code_locked ? h('button', { type: 'button', class: 'btn', text: 'Unlock service code', onclick: function () {
      unlock.disabled = true; api('POST', '/bookings/' + number + '/unlock').then(function (nb) { toast('Unlocked: the provider can try the code again.'); draw(nb); }).catch(fail);
    } }) : null;
    add(box, [h('div', { class: 'atc-actions' }, resend, refundBtn, unlock, code), panel]);
    return box;
  }

  function reassignBox(l, number, draw) {
    var wrap = h('div');
    var open = h('button', { type: 'button', class: l.status === 'released' ? 'btn dark' : 'link', text: l.status === 'released' ? 'Give this ride to another provider' : 'Change provider', onclick: function () { form.hidden = !form.hidden; } });
    var sel = h('select', null, h('option', { value: '', text: l.candidates.length ? 'Choose a provider' : 'No provider covers this zone' }), l.candidates.map(function (c, i) {
      return h('option', { value: c.service_id, 'data-i': i, text: c.label + (c.suggested === null ? ' · no price for this zone' : ' · gets ' + cash(c.suggested)) + (c.retail !== null && c.retail > l.retail ? ' · sells ' + cash(c.retail) : '') + (c.current ? ' (now)' : '') });
    }));
    var amt = h('input', { type: 'number', min: 0, step: '0.01', placeholder: '0.00', style: 'max-width:140px' });
    var tell = h('input', { type: 'checkbox', checked: true });
    // Price difference: the traveler pays it, or we absorb it.
    var diffBox = h('div', { class: 'card warn pad', hidden: true, style: 'gap:8px' });
    var askPay = h('input', { type: 'radio', name: 'diff' + l.id, value: 'ask', checked: true });
    var absorb = h('input', { type: 'radio', name: 'diff' + l.id, value: 'absorb' });
    var chargeAmt = h('input', { type: 'number', min: 1, step: '0.01', style: 'max-width:140px' });
    var chargeWhy = h('input', { type: 'text', maxlength: 160 });
    var share = h('span', { class: 'small strong' });
    function picked() { var o = sel.options[sel.selectedIndex]; return o && o.getAttribute('data-i') !== null ? l.candidates[+o.getAttribute('data-i')] : null; }
    function update() {
      var c = picked();
      var diff = c && c.retail !== null ? c.retail - l.retail : 0;
      diffBox.hidden = !(diff > 0);
      if (diff > 0) {
        clear(diffBox);
        add(diffBox, [
          h('strong', { text: 'This service sells for ' + cash(c.retail) + '. The traveler paid ' + cash(l.retail) + ' for this ride: ' + cash(diff) + ' more.' }),
          h('label', { class: 'atc-inline' }, askPay, ' Ask the traveler to pay the difference (email with a card payment link)'),
          h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Amount to ask (USD)' }), chargeAmt), h('label', { class: 'field' }, h('span', { text: 'What for (the traveler sees it)' }), chargeWhy)),
          h('label', { class: 'atc-inline' }, absorb, ' We absorb it (the traveler pays nothing more)'),
          h('span', { class: 'small atc-muted', text: 'The ride changes now either way. If the traveler doesn’t pay, you can cancel the request or change the ride back.' })
        ]);
        if (!chargeAmt.dataset.touched) chargeAmt.value = (diff / 100).toFixed(2);
        if (!chargeWhy.dataset.touched) chargeWhy.value = 'Upgrade to ' + c.label.split(' · ').slice(1).join(' · ').replace(/\s*\(up to \d+\)/, '');
      }
      var got = Math.round((+amt.value || 0) * 100);
      var extra = diff > 0 && askPay.checked ? Math.round((+chargeAmt.value || 0) * 100) : 0;
      var ours = l.retail + extra - got;
      share.textContent = c ? 'Our share on this ride after the change: ' + (ours < 0 ? '− ' : '') + cash(Math.abs(ours)) + (extra ? ' (once the traveler pays)' : '') : '';
      share.className = 'small strong' + (ours < 0 ? ' neg' : '');
    }
    chargeAmt.addEventListener('input', function () { chargeAmt.dataset.touched = 1; update(); });
    chargeWhy.addEventListener('input', function () { chargeWhy.dataset.touched = 1; });
    askPay.addEventListener('change', update); absorb.addEventListener('change', update); amt.addEventListener('input', update);
    sel.addEventListener('change', function () { var c = picked(); if (c && c.suggested !== null) amt.value = (c.suggested / 100).toFixed(2); delete chargeAmt.dataset.touched; delete chargeWhy.dataset.touched; update(); });
    var save = h('button', { type: 'button', class: 'btn dark', text: 'Give the ride', onclick: function () {
      if (!sel.value) { toast('Choose a provider first.', true); return; }
      var body = { leg: l.id, service_id: sel.value, amount: amt.value, tell: tell.checked ? 1 : 0 };
      if (!diffBox.hidden && askPay.checked) { body.charge = chargeAmt.value; body.charge_reason = chargeWhy.value; }
      save.disabled = true;
      api('POST', '/bookings/' + number + '/reassign', body).then(function (nb) {
        if (nb.warning) toast(nb.warning, true); else toast(body.charge ? 'Done. The new provider got the booking, and the traveler got the payment request.' : 'Done. The new provider got the booking by email.');
        draw(nb);
      }).catch(function (e) { save.disabled = false; fail(e); });
    } });
    var form = h('div', { class: 'card pad', hidden: true, style: 'gap:10px' },
      h('strong', { text: 'Give this ride to' }),
      h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Provider and vehicle' }), sel), h('label', { class: 'field' }, h('span', { text: 'They get (USD)' }), amt)),
      diffBox,
      share,
      h('label', { class: 'atc-inline' }, tell, ' Tell the traveler (email with the new provider)'),
      h('span', { class: 'small atc-muted', text: 'The current provider is told the ride is no longer theirs.' }),
      h('div', null, save));
    if (l.status === 'released') add(wrap, h('p', { class: 'chip red', text: 'Handed back by the provider: needs a new one' }));
    add(wrap, [h('div', null, open), form]);
    return wrap;
  }

  function chargesBox(b, number, draw) {
    var a = b.actions || {};
    var list = b.charges || [];
    if (!a.can_charge && !list.length) return null;
    var panel = h('div', { class: 'card pad', hidden: true, style: 'gap:10px' });
    var amt = h('input', { type: 'number', min: 1, step: '0.01', placeholder: '0.00', style: 'max-width:160px' });
    var why = h('input', { type: 'text', maxlength: 160, placeholder: 'e.g. Extra stop at the supermarket' });
    var go = h('button', { type: 'button', class: 'btn dark', text: 'Send the payment request', onclick: function () {
      go.disabled = true;
      api('POST', '/bookings/' + number + '/charge', { amount: amt.value, reason: why.value }).then(function (nb) { toast('Sent. The traveler got an email with a payment link.'); draw(nb); }).catch(function (e) { go.disabled = false; fail(e); });
    } });
    add(panel, [h('strong', { text: 'Ask the traveler to pay more' }),
      h('div', { class: 'grid2' }, h('label', { class: 'field' }, h('span', { text: 'Amount (USD)' }), amt), h('label', { class: 'field' }, h('span', { text: 'What for (the traveler sees it)' }), why)),
      h('span', { class: 'small atc-muted', text: 'They pay by card on Stripe. When paid, it adds to the booking total and to our share. Set the provider’s pay with Change provider.' }),
      h('div', null, go)]);
    var tone = { open: 'orange', paid: 'green', cancelled: 'grey' };
    var label = { open: 'Waiting for payment', paid: 'Paid', cancelled: 'Cancelled' };
    return h('section', { class: 'card pad' },
      h('h2', { text: 'Extra payments' }),
      list.length ? h('div', { class: 'rows' }, list.map(function (c) {
        var stop = c.status === 'open' ? h('button', { type: 'button', class: 'link', text: 'Cancel request', onclick: function () {
          stop.disabled = true;
          api('POST', '/bookings/' + number + '/charge-cancel', { id: c.id }).then(function (nb) { toast('Request cancelled.'); draw(nb); }).catch(function (e) { stop.disabled = false; fail(e); });
        } }) : null;
        return h('div', null, h('span', null, h('div', { text: c.reason }), h('div', { class: 'small atc-muted', text: 'Asked ' + c.created + (c.paid_at ? ' · paid ' + c.paid_at : '') })),
          h('span', { class: 'atc-actions' }, h('strong', { text: cash(c.amount) }), h('span', { class: 'chip ' + tone[c.status], text: label[c.status] }), stop));
      })) : h('p', { class: 'small atc-muted', text: 'Nothing asked so far.' }),
      a.can_charge ? h('div', null, h('button', { type: 'button', class: 'link', text: 'Ask the traveler to pay more', onclick: function () { panel.hidden = !panel.hidden; } })) : null,
      panel);
  }

  function noShowBox(l, number, draw) {
    var note = h('input', { type: 'text', placeholder: 'Why (optional, for our records)' });
    function decide(pay) {
      return function (e) {
        e.target.disabled = true;
        api('POST', '/bookings/' + number + '/noshow', { leg: l.id, pay: pay ? 1 : 0, note: note.value }).then(function (nb) { toast(pay ? 'Accepted: the provider is paid.' : 'Rejected: the provider is not paid for this ride.'); draw(nb); }).catch(function (x) { e.target.disabled = false; fail(x); });
      };
    }
    return h('div', { class: 'card warn pad', style: 'gap:8px' },
      h('strong', { text: 'No-show reported by the provider' }),
      h('span', { class: 'small atc-muted', text: 'Read their note in “What happened”. Accepting pays them; rejecting does not. To refund the traveler, use Refund or cancel.' }),
      note,
      h('div', { class: 'atc-actions' }, h('button', { type: 'button', class: 'btn dark', text: 'Accept: pay the provider', onclick: decide(true) }), h('button', { type: 'button', class: 'btn', text: 'Reject: don’t pay', onclick: decide(false) })));
  }

  function viewBooking(number) {
    clear(app);
    add(app, h('p', { class: 'atc-muted', text: 'Loading…' }));
    api('GET', '/bookings/' + encodeURIComponent(number)).then(function draw(b) {
      clear(app);
      var leg0 = b.legs[0] || {};
      var noteBox = h('textarea', { rows: 2, placeholder: 'Add a note' });
      var noteBtn = h('button', { class: 'btn', type: 'button', text: 'Add note', onclick: function () {
        noteBtn.disabled = true;
        api('POST', '/bookings/' + encodeURIComponent(number) + '/notes', { text: noteBox.value }).then(function (nb) { toast('Note added.'); draw(nb); }).catch(fail).then(function () { noteBtn.disabled = false; });
      } });
      add(app, [
        h('a', { class: 'back', href: C.base + 'bookings/', 'data-go': 'bookings', text: '← Bookings' }),
        h('section', { class: 'card pad bk-head' },
          h('div', null,
            h('div', { class: 'atc-actions' }, h('h1', { text: b.number }), h('span', { class: 'chip ' + b.tone, text: b.status }), b.legacy ? h('span', { class: 'chip grey', text: 'From the old site' }) : null),
            h('p', { class: 'atc-muted', text: [b.traveler.name || null, 'booked ' + b.booked, b.source.site || null, (b.lang || '').toUpperCase()].filter(Boolean).join(' · ') })),
          b.legacy ? h('p', { class: 'small atc-muted', text: 'This trip is run from the old site: change the provider, the trip or refunds there.' }) : bookingActions(b, number, draw)),
        h('div', { class: 'pe-split' },
          h('div', { class: 'pe-left' },
            b.legs.map(function (l, i) {
              return h('section', { class: 'card pad' },
                h('h2', { text: (b.legs.length > 1 ? (i === 0 ? 'Going' : 'Coming back') : 'Trip') + ' · ' + (l.direction === 'arrival' ? 'from the airport' : 'to the airport') }),
                h('div', { class: 'kv' },
                  kv('Pick-up', l.pickup ? l.pickup + ' (' + l.timezone + ')' : null),
                  kv('Airport', l.airport),
                  kv(l.direction === 'arrival' ? 'Drop-off' : 'Pick-up place', (l.direction === 'arrival' ? l.to : l.from) + (l.zone ? ' · zone ' + l.zone : '')),
                  kv('Vehicle and party', [l.vehicle, (b.pax.adults + b.pax.children) + ' passengers', l.bags ? l.bags + ' bags' : null].filter(Boolean).join(' · ')),
                  kv('Provider', l.party_id ? h('a', { href: C.base + 'providers/' + l.party_id + '/', 'data-go': 'providers/' + l.party_id, text: l.provider }) : h('span', { class: 'chip red', text: 'Nobody yet' })),
                  kv('Flight', l.flight || null)),
                l.notes ? h('p', { class: 'note', text: 'Note from the traveler: ' + l.notes }) : null,
                l.noshow_open ? noShowBox(l, number, draw) : null,
                l.can_reassign ? reassignBox(l, number, draw) : null);
            }),
            b.extras && b.extras.length ? h('section', { class: 'card pad' }, h('h2', { text: 'Extras' }), h('table', { class: 'bare' }, h('tbody', null, b.extras.map(function (x) { return h('tr', null, h('th', { scope: 'row' }, h('div', { text: x.label }), h('div', { class: 'small atc-muted', text: 'provider gets ' + cash(x.provider) })), h('td', { text: cash(x.retail) })); })))) : null,
            ticketsCard(b, number),
            h('section', { class: 'card pad' },
              h('h2', { text: 'Traveler' }),
              b.legacy && !b.traveler.name ? h('p', { class: 'atc-muted', text: 'Name, email and phone were not copied from the old site; they are on the old site\'s booking.' }) :
                h('div', { class: 'kv' }, kv('Name', b.traveler.name), kv('Email', b.traveler.email), kv('Mobile and WhatsApp', b.traveler.phone), kv('Account', b.traveler.account))),
            h('section', { class: 'card pad' },
              h('h2', { text: 'What happened' }),
              h('ol', { class: 'timeline' }, b.events.map(function (e) {
                return h('li', null, h('span', { class: 'atc-muted small strong', text: e.when }), h('span', null, h('strong', { text: e.note ? 'Note: ' : '' }), e.what, h('span', { class: 'atc-muted', text: ' · ' + e.who })));
              })),
              h('label', { class: 'field' }, h('span', { text: 'Internal note (our team only)' }), noteBox),
              h('div', { class: 'atc-actions' }, noteBtn))),
          h('div', { class: 'bk-side' },
            h('section', { class: 'card pad money' },
              h('h2', { text: 'The money, both ways' }),
              h('table', { class: 'bare' }, h('tbody', null, b.money.map(function (m, i) {
                return h('tr', { class: i === b.money.length - 1 && b.money.length > 2 ? 'total' : '' },
                  h('th', { scope: 'row' }, h('div', { text: m.party }), m.status ? h('div', { class: 'small atc-muted', text: m.status }) : null),
                  h('td', { text: (m.amount < 0 ? '− ' : '') + cash(Math.abs(m.amount)) }));
              })))),
            chargesBox(b, number, draw),
            h('section', { class: 'card pad' },
              h('h2', { text: 'Payment' }),
              h('div', { class: 'rows' },
                h('div', null, h('span', { text: 'State' }), h('strong', { text: b.payment.state })),
                h('div', null, h('span', { text: 'Method' }), h('strong', { text: b.payment.method ? b.payment.method.charAt(0).toUpperCase() + b.payment.method.slice(1) : '–' })),
                b.payment.stripe ? h('div', null, h('span', { text: 'Stripe payment' }), h('a', { href: 'https://dashboard.stripe.com/payments/' + b.payment.stripe, target: '_blank', rel: 'noopener', text: 'Open in Stripe ↗' })) : null,
                h('div', null, h('span', { text: 'Refunded' }), h('strong', { text: b.payment.refunded ? cash(b.payment.refunded) : 'nothing' })))),
            h('section', { class: 'card pad' },
              h('h2', { text: 'Where it came from' }),
              h('div', { class: 'rows' },
                h('div', null, h('span', { text: 'Site' }), h('strong', { text: b.source.site || '–' })),
                h('div', null, h('span', { text: 'Channel' }), h('strong', { text: b.channel })),
                h('div', null, h('span', { text: 'Affiliate' }), b.source.affiliate_id ? h('a', { href: C.base + 'affiliates/' + b.source.affiliate_id + '/', 'data-go': 'affiliates/' + b.source.affiliate_id, text: b.source.affiliate }) : h('strong', { text: 'none' })),
                b.source.referrer ? h('div', null, h('span', { text: 'Invited by' }), h('strong', { text: b.source.referrer })) : null,
                b.source.booked_by ? h('div', null, h('span', { text: 'Booked by' }), h('strong', { text: b.source.booked_by })) : null,
                b.source.link_sent ? h('div', null, h('span', { text: 'Payment link sent' }), h('strong', { text: b.source.link_sent.slice(0, 16) })) : null,
                h('div', null, h('span', { text: 'Coupon' }), h('strong', { text: b.source.coupon || 'none' })),
                b.source.area ? h('div', null, h('span', { text: 'Their area name' }), h('strong', { text: b.source.area })) : null))))
      ]);
    }).catch(fail);
  }

  /* ---------- Dashboard ---------- */

  function cash(c) { return 'USD ' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: c % 100 ? 2 : 0, maximumFractionDigits: 2 }); }
  function stat(label, value, sub) {
    return h('div', { class: 'stat' }, h('span', { class: 'small atc-muted strong', text: label }), h('strong', { class: 'big', text: String(value) }), sub ? h('span', { class: 'small atc-muted', text: sub }) : null);
  }
  function tripsTable(rows, empty) {
    if (!rows.length) return h('p', { class: 'atc-muted pad-x', text: empty });
    return h('div', { class: 'scroll' }, h('table', null,
      h('thead', null, h('tr', null, ['Pick-up (local time)', 'Booking', 'Airport', 'Trip', 'Vehicle', 'Pax', 'Provider'].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
      h('tbody', null, rows.map(function (r) {
        return h('tr', null,
          h('td', { class: 'strong', text: r.when || '–' }),
          h('td', null, bookingLink(r.number)),
          h('td', { text: r.airport || '–' }),
          h('td', null, h('div', { text: (r.direction === 'arrival' ? 'Airport → ' : '→ Airport · ') + (r.zone || '') }), h('div', { class: 'small atc-muted', text: r.place || '' })),
          h('td', { text: r.vehicle || '–' }),
          h('td', { text: String(r.pax) }),
          h('td', null, r.party_id ? h('a', { href: C.base + 'providers/' + r.party_id + '/', 'data-go': 'providers/' + r.party_id, text: r.provider }) : h('span', { class: 'chip red', text: 'Nobody yet' })));
      }))));
  }
  function bookingChip(r) {
    if (r.status === 'cancelled') return h('span', { class: 'chip grey', text: r.payment === 'refunded' ? 'Cancelled, refunded' : 'Cancelled' });
    if (r.status === 'held') return h('span', { class: 'chip orange', text: 'Not paid' });
    return h('span', { class: 'chip green', text: 'Paid' });
  }

  function demandCard(dm) {
    if (!dm || (!dm.airport.length && !dm.address.length && !dm.unpriced.length)) {
      return h('section', { class: 'card pad' }, h('h2', { text: 'What travelers asked for and we don’t sell yet' }), h('p', { class: 'atc-muted', text: 'Nothing yet. Airports we don’t have, addresses outside our zones and areas nobody has priced show here as travelers search for them.' }));
    }
    function list(title, rows, hint, airportCol) {
      if (!rows.length) return null;
      return h('div', null, h('p', { class: 'label', text: title }), h('p', { class: 'small atc-muted', text: hint }),
        h('div', { class: 'scroll' }, h('table', { class: 'narrow' }, h('tbody', null, rows.map(function (r) {
          var x = h('button', { class: 'link', type: 'button', text: 'Dismiss', onclick: function () {
            api('POST', '/demand/' + r.id).then(function () { x.closest('tr').remove(); }).catch(fail);
          } });
          return h('tr', null,
            h('td', null, h('div', { class: 'strong', text: r.term }), r.map ? h('a', { class: 'small', href: 'https://www.google.com/maps?q=' + encodeURIComponent(r.map), target: '_blank', rel: 'noopener', text: 'See on the map ↗' }) : null),
            airportCol ? h('td', { text: r.airport || '' }) : null,
            h('td', { class: 'nowrap', text: r.hits + (r.hits === 1 ? ' search' : ' searches') }),
            h('td', { class: 'small atc-muted nowrap', text: 'last ' + r.last }),
            h('td', null, x));
        })))));
    }
    return h('section', { class: 'card pad' },
      h('h2', { text: 'What travelers asked for and we don’t sell yet' }),
      list('Airports we don’t have', dm.airport, 'What they typed in the airport box and we could not find. Add the airport and its providers.', false),
      list('Addresses outside our zones', dm.address, 'Bookable once a zone covers them: draw or widen a zone.', true),
      list('Zones nobody has priced', dm.unpriced, 'The zone exists but no live provider has a price there.', true));
  }

  function viewDashboard() {
    clear(app);
    add(app, h('p', { class: 'atc-muted', text: 'Loading…' }));
    api('GET', '/dashboard').then(function (d) {
      clear(app);
      var m = d.month, all = d.all, pr = d.providers, td = d.todo;
      add(app, [
        h('div', { class: 'atc-head' },
          h('div', null, h('h1', { text: 'Dashboard' }), h('p', { class: 'atc-muted', text: 'Every site, every airport. ' + (d.legacy ? d.legacy + ' bookings came over from the old site (no traveler names or contacts; those stay there).' : '') })),
          h('span', { class: 'small atc-muted', text: 'Updated ' + d.now })),

        h('section', { class: 'stats' },
          stat('Paid bookings, last 30 days', m.paid, all.paid + ' all time'),
          stat('Sales, last 30 days', cash(m.sales), cash(all.sales) + ' all time'),
          stat('Ours, last 30 days', cash(m.ours), 'Providers get ' + cash(m.providers)),
          stat('Not paid, last 30 days', m.unpaid, 'Started and never paid'),
          stat('Cancelled, last 30 days', m.cancelled, all.cancelled + ' all time')),

        d.no_provider.length ? h('section', { class: 'card warn' },
          h('div', { class: 'card-head' }, h('h2', { text: 'Paid trips nobody is driving yet' }), h('span', { class: 'chip red', text: String(d.no_provider.length) })),
          tripsTable(d.no_provider, '')) : null,

        h('section', { class: 'card' },
          h('div', { class: 'card-head' }, h('h2', { text: 'Coming up' }), h('span', { class: 'small atc-muted', text: 'Paid trips, next first' })),
          tripsTable(d.upcoming, 'No paid trips coming up.')),

        h('div', { class: 'atc-two' },
          h('section', { class: 'card' },
            h('div', { class: 'card-head' }, h('h2', { text: 'Latest bookings' })),
            h('div', { class: 'scroll' }, h('table', { class: 'narrow' },
              h('thead', null, h('tr', null, ['Booking', 'Airport', 'Total', 'Status'].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
              h('tbody', null, d.recent.length ? d.recent.map(function (r) {
                return h('tr', null,
                  h('td', null, bookingLink(r.number), h('div', { class: 'small atc-muted', text: r.booked + (r.legacy ? ' · old site' : '') })),
                  h('td', { text: r.airport || '–' }),
                  h('td', { text: cash(+r.total_cents) + (r.trip_type === 'round_trip' ? ' RT' : '') }),
                  h('td', null, bookingChip(r)));
              }) : h('tr', null, h('td', { colspan: 4, class: 'atc-muted', text: 'No bookings yet.' })))))),
          h('section', { class: 'card' },
            h('div', { class: 'card-head' }, h('h2', { text: 'Top airports' }), h('span', { class: 'small atc-muted', text: 'Paid sales, all time' })),
            h('div', { class: 'scroll' }, h('table', { class: 'narrow' },
              h('thead', null, h('tr', null, ['Airport', 'Bookings', 'Sales'].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
              h('tbody', null, d.by_airport.length ? d.by_airport.map(function (r) {
                return h('tr', null, h('td', null, h('div', { class: 'strong', text: r.airport || '–' }), h('div', { class: 'small atc-muted', text: r.name || '' })), h('td', { text: String(r.bookings) }), h('td', { text: cash(r.sales) }));
              }) : h('tr', null, h('td', { colspan: 3, class: 'atc-muted', text: 'No sales yet.' }))))))),

        td.proposed.length ? h('section', { class: 'card warn' },
          h('div', { class: 'card-head' }, h('h2', { text: 'Zones proposed by providers' }), h('span', { class: 'small atc-muted', text: 'From their price lists: places outside our zones. Their prices wait until you approve.' })),
          h('div', { class: 'scroll' }, h('table', { class: 'narrow' },
            h('thead', null, h('tr', null, ['Zone', 'Airport', 'Provider', 'Prices', ''].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
            h('tbody', null, td.proposed.map(function (z) {
              var ok = h('button', { class: 'btn dark', type: 'button', text: 'Approve' });
              var no = h('button', { class: 'link danger', type: 'button', text: 'Reject' });
              function decide(approve) {
                if (!approve && !confirm('Reject "' + z.name + '"? Its ' + z.prices + ' prices are deleted.')) return;
                ok.disabled = no.disabled = true;
                api('POST', '/zones/' + z.id + '/decide', { approve: approve }).then(function () {
                  toast(approve ? z.name + ' approved. Give it a shape in Zones and hotels.' : z.name + ' rejected.');
                  viewDashboard();
                }).catch(fail);
              }
              ok.addEventListener('click', function () { decide(true); });
              no.addEventListener('click', function () { decide(false); });
              return h('tr', null,
                h('td', null, h('div', { class: 'strong', text: z.name }), z.at ? h('a', { class: 'small', href: 'https://www.google.com/maps?q=' + encodeURIComponent(z.at), target: '_blank', rel: 'noopener', text: 'See on the map ↗' }) : null),
                h('td', { text: z.iata || z.airport }),
                h('td', null, z.party_id ? h('a', { href: C.base + 'providers/' + z.party_id + '/', 'data-go': 'providers/' + z.party_id, text: z.provider }) : '–'),
                h('td', { text: String(z.prices) }),
                h('td', null, h('div', { class: 'atc-actions' }, ok, no)));
            }))))) : null,

        h('section', { class: 'stats' },
          stat('Providers live', pr.live, pr.pending + ' pending · ' + pr.suspended + ' suspended'),
          stat('Using the portal', pr.portal.active, pr.portal.invited + ' invited, no password yet'),
          stat('Not invited yet', pr.portal.not_invited, 'Send from the Providers list'),
          stat('Documents to review', td.documents, '')),

        h('div', { class: 'atc-two' },
          h('section', { class: 'card pad' },
            h('h2', { text: 'To do' }),
            h('div', { class: 'list' },
              td.new_signups.length ? td.new_signups.map(function (p) {
                return h('a', { class: 'row', href: C.base + 'providers/' + p.id + '/', 'data-go': 'providers/' + p.id }, h('span', null, h('strong', { text: p.name }), h('span', { class: 'small atc-muted', text: ' · new sign-up, ' + String(p.created_at).slice(0, 10) })), h('span', { class: 'chip orange', text: 'Review' }));
              }) : h('p', { class: 'atc-muted', text: 'No new provider sign-ups waiting.' }),
              pr.portal.not_invited ? h('a', { class: 'row', href: C.base + 'providers/', 'data-go': 'providers' }, h('span', { text: pr.portal.not_invited + ' providers from the old site have not been invited to the new portal' }), h('span', { class: 'chip orange', text: 'Invite' })) : null)),
          h('section', { class: 'card pad' },
            h('h2', { text: 'Zones without a shape' }),
            h('p', { class: 'small atc-muted', text: 'Travelers can only book addresses inside a zone shape. Draw or suggest a shape for these.' }),
            td.no_shape.length ? h('div', { class: 'ticks' }, td.no_shape.map(function (r) {
              return h('a', { class: 'chip grey', href: C.base + 'zones/', 'data-go': 'zones', text: r.airport + ': ' + r.n });
            })) : h('p', { class: 'atc-muted', text: 'Every zone has a shape.' }))),

        demandCard(td.demand),

        td.held_prices.length ? h('section', { class: 'card pad' },
          h('details', null,
            h('summary', { class: 'strong', text: td.held_prices.length + ' prices left out of the import (another currency or a typo): the provider re-enters them' }),
            h('ul', null, td.held_prices.map(function (t) { return h('li', { class: 'small', text: t }); })))) : null,

        h('p', { class: 'small atc-muted', text: 'Coming with checkout: payouts owed, refunds and disputes, affiliate sales.' })
      ]);
    }).catch(fail);
  }

  /* ---------- Google Maps ---------- */

  function loadMaps() {
    if (state.mapReady) return state.mapReady;
    state.mapReady = new Promise(function (resolve, reject) {
      if (!C.mapsKey) { reject(new Error('nokey')); return; }
      window.atcMapsReady = function () { resolve(); };
      var s = document.createElement('script');
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(C.mapsKey) + '&v=weekly&libraries=places&loading=async&callback=atcMapsReady';
      s.async = true;
      s.onerror = function () { reject(new Error('Google Maps did not load.')); };
      document.head.appendChild(s);
    });
    return state.mapReady;
  }

  /* ---------- Payouts ---------- */

  var payoutPeriod = '';
  function viewPayouts() {
    clear(app);
    add(app, h('p', { class: 'atc-muted', text: 'Loading…' }));
    api('GET', '/payouts' + (payoutPeriod ? '?period=' + payoutPeriod : '')).then(function draw(d) {
      clear(app);
      payoutPeriod = d.period;
      var sel = h('select', { 'aria-label': 'Month', onchange: function () { payoutPeriod = this.value; viewPayouts(); } }, d.periods.map(function (p) { return h('option', { value: p.value, selected: p.value === d.period, text: p.label }); }));
      var approve = h('button', { type: 'button', class: 'btn dark', text: 'Approve run', disabled: !d.can_approve, onclick: function () {
        approve.disabled = true;
        api('POST', '/payouts', { period: d.period }).then(function (nd) { toast('Approved. Download the payment list and pay.'); draw(nd); }).catch(function (e) { approve.disabled = false; fail(e); });
      } });
      var csv = h('button', { type: 'button', class: 'btn', text: 'Download payment list (CSV)', onclick: function () {
        api('GET', '/payouts/list?period=' + d.period).then(function (rows) {
          if (!rows.length) { toast('Nothing approved and unpaid for this month.', true); return; }
          var cols = ['payout', 'provider', 'method', 'holder', 'bank', 'account', 'email', 'amount', 'currency', 'concept'];
          var q = function (v) { v = String(v === null || v === undefined ? '' : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
          var text = [cols.join(',')].concat(rows.map(function (r) { return cols.map(function (c) { return q(r[c]); }).join(','); })).join('\n');
          var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + text], { type: 'text/csv' })); a.download = 'payouts-' + d.period + '.csv'; document.body.appendChild(a); a.click(); a.remove();
        }).catch(fail);
      } });
      var t = d.totals;
      add(app, [
        h('div', { class: 'atc-head' },
          h('div', null, h('h1', { text: 'Payout run · ' + d.pay_date }), h('p', { class: 'atc-muted', text: 'Rides completed up to ' + d.label + '. Balances under ' + cash(d.minimum) + ' roll over to the next month.' })),
          h('div', { class: 'atc-actions' }, h('label', { class: 'atc-inline' }, 'Month', sel), csv, approve)),
        h('ol', { class: 'steps-mini' }, h('li', null, h('strong', { text: '1 · Review and approve' })), h('li', null, h('strong', { text: '2 · Pay from the list (bank or Wise)' })), h('li', null, h('strong', { text: '3 · Mark paid: the provider gets a statement' }))),
        h('div', { class: 'stats' },
          h('div', { class: 'stat' }, h('span', { text: 'To pay' }), h('strong', { text: cash(t.pay) })),
          h('div', { class: 'stat' }, h('span', { text: 'Paid' }), h('strong', { text: cash(t.paid) })),
          h('div', { class: 'stat' }, h('span', { text: 'Held (no-shows to decide)' }), h('strong', { text: cash(t.held) })),
          h('div', { class: 'stat' }, h('span', { text: 'Rolled over' }), h('strong', { text: cash(t.rolled) }))),
        h('section', { class: 'card' }, d.rows.length ? h('div', { class: 'scroll' }, h('table', null,
          h('thead', null, h('tr', null, ['Provider', 'Country', 'Method', 'Rides', 'Held', 'To pay', 'Status', ''].map(function (x) { return h('th', { scope: 'col', text: x }); }))),
          h('tbody', null, d.rows.map(function (r) {
            var ref = h('input', { type: 'text', placeholder: 'Bank reference', style: 'max-width:150px' });
            var paid = r.payout_id && r.status !== 'Paid' ? h('div', { class: 'atc-actions', style: 'flex-wrap:nowrap' }, ref, h('button', { type: 'button', class: 'btn', text: 'Mark paid', onclick: function (e) {
              e.target.disabled = true;
              api('POST', '/payouts/' + r.payout_id + '/paid', { reference: ref.value }).then(function (nd) { toast('Marked paid. Statement emailed.'); draw(nd); }).catch(function (x) { e.target.disabled = false; fail(x); });
            } })) : (r.reference ? h('span', { class: 'small atc-muted', text: r.paid_at + ' · ' + r.reference }) : null);
            var lines = r.payout_id ? h('button', { type: 'button', class: 'link', text: 'Rides', onclick: function () {
              api('GET', '/payouts/' + r.payout_id + '/lines').then(function (ls) {
                var tr = h('tr', null, h('td', { colspan: 8 }, h('table', { class: 'bare' }, h('tbody', null, ls.map(function (x) { return h('tr', null, h('th', { scope: 'row', text: x.date + ' · ' + x.number + ' · ' + x.direction + (x.how ? ' · ' + x.how : '') }), h('td', { text: cash(x.amount) })); })))));
                row.parentNode.insertBefore(tr, row.nextSibling); lines.remove();
              }).catch(fail);
            } }) : null;
            var row = h('tr', null,
              h('td', null, h('a', { class: 'strong', href: C.base + (r.type === 'affiliate' ? 'affiliates/' : 'providers/') + r.party_id + '/', 'data-go': (r.type === 'affiliate' ? 'affiliates/' : 'providers/') + r.party_id, text: r.name }), r.type === 'affiliate' ? h('span', { class: 'chip grey', text: 'Affiliate' }) : null, lines ? h('div', null, lines) : null),
              h('td', { text: r.country || '–' }), h('td', { text: r.method || '–' }), h('td', { text: String(r.trips) }),
              h('td', { text: r.held ? cash(r.held) : '–' }), h('td', { class: 'strong', text: cash(r.to_pay) }),
              h('td', null, h('span', { class: 'chip ' + r.tone, text: r.status })), h('td', null, paid));
            return row;
          })))) : h('p', { class: 'atc-muted', style: 'padding:18px', text: 'No completed rides to pay for this month yet.' })),
        h('p', { class: 'small atc-muted', text: 'Stripe Connect can only pay accounts in the US, UK, EEA, Canada and Switzerland, so Mexican providers are paid from the payment list. Only admins can download it: it has full account numbers.' })
      ]);
    }).catch(fail);
  }

  /* ---------- Airports ---------- */

  function viewAirports() {
    clear(app);
    var search = h('input', { type: 'search', placeholder: 'Find by code, name, city or country', 'aria-label': 'Find an airport', style: 'max-width:320px' });
    var body = h('section', { class: 'card' }, h('p', { class: 'atc-muted', style: 'padding:18px', text: 'Loading…' }));
    add(app, [h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Airports' }), h('p', { class: 'atc-muted', text: 'Airports added from Google come without a code. Fix the code, city or time zone here: pickup times and emails use the time zone.' })), search), body]);
    var tzs = [], timer;
    function load() {
      api('GET', '/airports?q=' + encodeURIComponent(search.value.trim())).then(function (d) {
        tzs = d.timezones; clear(body);
        if (!d.airports.length) { add(body, h('p', { class: 'atc-muted', style: 'padding:18px', text: 'No airport matches.' })); return; }
        add(body, h('div', { class: 'scroll' }, h('table', null,
          h('thead', null, h('tr', null, ['Code', 'Airport', 'City', 'Country', 'Time zone', 'Zones', 'Providers', ''].map(function (x) { return h('th', { scope: 'col', text: x }); }))),
          h('tbody', null, d.airports.map(row)))));
      }).catch(fail);
    }
    function row(a) {
      var tr = h('tr', null,
        h('td', null, a.iata ? h('strong', { text: a.iata }) : h('span', { class: 'chip red', text: 'No code' })),
        h('td', { text: a.name }), h('td', { text: a.city }), h('td', { text: a.country }), h('td', { text: a.timezone }),
        h('td', { text: String(a.zones) }), h('td', { text: String(a.providers) }),
        h('td', null, h('button', { type: 'button', class: 'btn', text: 'Edit', onclick: function () { tr.replaceWith(editor(a, tr)); } })));
      return tr;
    }
    function editor(a, old) {
      var f = {
        iata: h('input', { type: 'text', value: a.iata || '', maxlength: 3, style: 'max-width:80px;text-transform:uppercase' }),
        name: h('input', { type: 'text', value: a.name }), city: h('input', { type: 'text', value: a.city }),
        country: h('input', { type: 'text', value: a.country, maxlength: 2, style: 'max-width:60px;text-transform:uppercase' }),
        timezone: h('select', null, tzs.map(function (z) { return h('option', { value: z, selected: z === a.timezone, text: z }); })),
        lat: h('input', { type: 'number', step: 'any', value: a.lat === null ? '' : a.lat }), lng: h('input', { type: 'number', step: 'any', value: a.lng === null ? '' : a.lng }),
        status: h('select', null, h('option', { value: 'active', selected: a.status !== 'hidden', text: 'Active' }), h('option', { value: 'hidden', selected: a.status === 'hidden', text: 'Hidden from travelers' }))
      };
      var tr = h('tr', null, h('td', { colspan: 8 }, h('div', { class: 'card pad', style: 'gap:10px' },
        h('div', { class: 'grid3' }, field('IATA code', f.iata), field('Name', f.name), field('City', f.city), field('Country (2 letters)', f.country), field('Time zone', f.timezone), field('Shown to travelers', f.status), field('Latitude', f.lat), field('Longitude', f.lng)),
        h('div', { class: 'atc-actions' },
          h('button', { type: 'button', class: 'btn dark', text: 'Save', onclick: function (e) {
            e.target.disabled = true;
            var body = {}; Object.keys(f).forEach(function (k) { body[k] = f[k].value; });
            api('POST', '/airports/' + a.id, body).then(function (na) { toast('Airport saved.'); tr.replaceWith(row(na.id ? na : a)); }).catch(function (x) { e.target.disabled = false; fail(x); });
          } }),
          h('button', { type: 'button', class: 'btn', text: 'Cancel', onclick: function () { tr.replaceWith(old); } })))));
      return tr;
    }
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 250); });
    load();
  }

  /* ---------- Meeting your driver ---------- */

  function viewMeeting() {
    clear(app);
    var boot = state.boot;
    if (!boot) { add(app, h('p', { class: 'atc-muted', text: 'Loading…' })); return; }
    if (!state.airportId && boot.airports.length) state.airportId = boot.airports[0].id;
    var sel = h('select', { 'aria-label': 'Airport', onchange: function () { state.airportId = parseInt(this.value, 10); viewMeeting(); } },
      boot.airports.map(function (a) { return h('option', { value: a.id, selected: a.id == state.airportId, text: a.iata ? a.city + ' (' + a.iata + ')' : a.name }); }));
    var body = h('div', { class: 'atc-main', style: 'gap:18px' }, h('p', { class: 'atc-muted', text: 'Loading…' }));
    add(app, [
      h('div', { class: 'atc-head' },
        h('div', null, h('h1', { text: 'Meeting your driver' }), h('p', { class: 'atc-muted', text: 'What travelers see on their confirmation: how to find their driver at this airport. A provider can write their own for their bookings; otherwise travelers see ours.' })),
        h('div', { class: 'atc-actions' }, h('label', { class: 'atc-inline' }, 'Airport', sel))),
      body
    ]);
    api('GET', '/meeting?airport_id=' + state.airportId).then(function (d) {
      clear(body);
      add(body, h('section', { class: 'card pad' },
        h('h2', { text: 'Our steps for this airport' }),
        h('p', { class: 'small atc-muted', text: d.ours.length ? 'Shown to every traveler whose provider has not written their own.' : 'Nothing written yet: travelers see the general steps below until you save.' }),
        window.ATCMeeting.editor({
          sets: d.ours, startFrom: d.fallback, saveLabel: 'Save for this airport',
          onSave: function (sets) { return api('POST', '/meeting', { airport_id: state.airportId, sets: sets }).then(function (r) { toast('Saved. Travelers see these steps from now on.'); return r; }).catch(function (e) { fail(e); throw e; }); }
        })));
      add(body, h('section', { class: 'card pad' },
        h('h2', { text: 'Providers with their own steps' }),
        d.providers.length ? d.providers.map(function (p) { return h('div', null, h('h3', { text: p.name }), window.ATCMeeting.preview(p.sets)); })
          : h('p', { class: 'small atc-muted', text: 'None yet at this airport.' })));
    }).catch(fail);
  }

  /* ---------- Zones and hotels ---------- */

  var Z = { polys: {}, markers: [], drawing: null, editing: null, suggest: null };

  function viewZones() {
    clear(app);
    var boot = state.boot;
    var airportSel = h('select', { 'aria-label': 'Airport', onchange: function () { state.airportId = parseInt(this.value, 10); state.zoneId = null; Z.fitted = false; viewZones(); } },
      boot.airports.map(function (a) { return h('option', { value: a.id, selected: a.id == state.airportId, text: a.iata ? a.city + ' (' + a.iata + ')' : a.name + ' (no code yet)' }); }));

    var drawBtn = h('button', { class: 'btn', type: 'button', onclick: addZone, text: 'Add a zone' });
    var head = h('div', { class: 'atc-head' },
      h('div', null, h('h1', { text: 'Zones and hotels' }), h('p', { class: 'atc-muted', text: 'Every price is per zone, so every hotel and address must sit in exactly one zone.' })),
      h('div', { class: 'atc-actions' }, h('label', { class: 'atc-inline' }, 'Airport', airportSel), drawBtn));

    var mapBox = h('div', { id: 'atc-map', class: 'atc-map' });
    var mapBar = h('div', { id: 'atc-mapbar', class: 'atc-mapbar' });
    var mapNote = h('p', { class: 'atc-note', text: 'Pick a zone from the list, then draw or adjust its shape. Click a shape to select its zone. Hotels show as dots.' });
    var left = h('section', { class: 'card atc-mapcard', 'aria-label': 'Map' }, mapBar, mapBox, mapNote);

    var pills = h('div', { id: 'atc-pills', class: 'card atc-pills' });
    var zoneCard = h('section', { id: 'atc-zonecard', class: 'card' });
    var addCard = h('section', { id: 'atc-addcard', class: 'card pad' });
    var unmatched = h('section', { id: 'atc-unmatched', class: 'card pad warn' });
    var right = h('div', { class: 'atc-col' }, pills, addCard, zoneCard, unmatched);

    add(app, [head, h('div', { class: 'atc-split' }, left, right)]);

    Z.polys = {}; Z.markers = []; Z.drawing = null; Z.editing = null; Z.suggest = null; state.map = null;

    loadMaps().then(function () {
      state.map = new google.maps.Map(mapBox, {
        center: airportCenter(), zoom: 10, mapTypeControl: false, streetViewControl: false, clickableIcons: false, gestureHandling: 'greedy'
      });
      state.map.addListener('click', onMapClick);
      refreshZones();
    }).catch(function (e) {
      clear(mapBox);
      add(mapBox, h('div', { class: 'atc-nomap' },
        h('strong', { text: e.message === 'nokey' ? 'The map needs the Website key.' : e.message }),
        h('p', { text: e.message === 'nokey' ? 'Add ATC_MAPS_BROWSER_KEY to wp-config.php. Lists below still work.' : 'Check the Website key restrictions in Google Cloud.' })));
      refreshZones();
    });

    renderAddHotel();
  }

  function airportCenter() {
    var a = state.boot.airports.find(function (x) { return x.id == state.airportId; }) || {};
    return { lat: parseFloat(a.lat) || 21.0365, lng: parseFloat(a.lng) || -86.8771 };
  }

  function refreshZones() {
    return api('GET', '/zones?airport_id=' + state.airportId).then(function (zones) {
      state.zones = zones;
      if (state.zoneId && !zones.some(function (z) { return z.id === state.zoneId; })) state.zoneId = null;
      if (!state.zoneId && zones.length) state.zoneId = zones[0].id;
      drawZonesOnMap();
      renderPills();
      renderZoneCard();
      renderUnmatched();
    }).catch(fail);
  }

  function drawZonesOnMap() {
    if (!state.map) return;
    Object.keys(Z.polys).forEach(function (k) { Z.polys[k].setMap(null); });
    Z.polys = {};
    state.zones.forEach(function (z) {
      var on = z.id === state.zoneId;
      var poly = new google.maps.Polygon({
        map: state.map,
        paths: z.polygon.map(function (p) { return { lat: p[0], lng: p[1] }; }),
        strokeColor: '#15171C', strokeWeight: on ? 2.5 : 1.2, strokeOpacity: 0.9,
        fillColor: on ? '#FBA53E' : '#FFFFFF', fillOpacity: on ? 0.45 : 0.25,
        zIndex: on ? 2 : 1
      });
      poly.addListener('click', function (e) {
        if (Z.drawing) { onMapClick(e); return; }
        selectZone(z.id);
      });
      Z.polys[z.id] = poly;
    });
    if (state.zones.length && !Z.fitted) {
      var b = new google.maps.LatLngBounds();
      state.zones.forEach(function (z) { z.polygon.forEach(function (p) { b.extend({ lat: p[0], lng: p[1] }); }); });
      b.extend(airportCenter());
      state.map.fitBounds(b, 30);
      Z.fitted = true;
    }
    renderMapBar();
  }

  function selectZone(id) {
    stopEditing(false);
    cancelDraw();
    cancelSuggest();
    state.zoneId = id;
    drawZonesOnMap();
    renderPills();
    renderZoneCard();
    fitZone(id);
  }

  function zoneById(id) { return state.zones.find(function (x) { return x.id === id; }); }

  function fitZone(id) {
    if (!state.map) return;
    var z = zoneById(id);
    if (z && z.polygon.length) {
      var b = new google.maps.LatLngBounds();
      z.polygon.forEach(function (p) { b.extend({ lat: p[0], lng: p[1] }); });
      state.map.fitBounds(b, 60);
    }
  }

  function addZone() {
    var name = prompt('Name of the new zone, e.g. Isla Blanca');
    if (!name || !name.trim()) return;
    api('POST', '/zones', { airport_id: state.airportId, name: name.trim() }).then(function (r) {
      state.zoneId = r.id;
      return refreshZones();
    }).then(function () { if (state.map) suggestShape(); }).catch(fail);
  }

  /* ready-made shapes: OpenStreetMap boundaries and Google's map box for the zone's name */
  function suggestShape(query) {
    var id = state.zoneId, z = zoneById(id);
    if (!state.map || !z) return;
    stopEditing(false); cancelDraw(); cancelSuggest();
    Z.suggest = { zoneId: id, list: [], idx: 0, poly: null, loading: true, query: query || z.name, errors: [] };
    renderMapBar();
    api('GET', '/zones/' + id + '/suggest' + (query ? '?q=' + encodeURIComponent(query) : '')).then(function (r) {
      if (!Z.suggest || Z.suggest.zoneId !== id) return;
      Z.suggest.loading = false;
      Z.suggest.list = r.candidates || [];
      Z.suggest.errors = r.errors || [];
      if (!Z.suggest.list.length) { renderMapBar(); return; }
      showSuggestion(0);
    }).catch(function (e) { cancelSuggest(); fail(e); });
  }
  function showSuggestion(i) {
    var sg = Z.suggest;
    if (!sg || !sg.list.length) return;
    sg.idx = (i + sg.list.length) % sg.list.length;
    if (sg.poly) sg.poly.setMap(null);
    var c = sg.list[sg.idx];
    var path = c.polygon.map(function (p) { return { lat: p[0], lng: p[1] }; });
    if (Z.polys[sg.zoneId]) Z.polys[sg.zoneId].setOptions({ fillOpacity: 0.08, strokeOpacity: 0.35 });
    sg.poly = new google.maps.Polygon({ map: state.map, paths: path, editable: true, strokeColor: '#E07A1F', strokeWeight: 3, fillColor: '#FBA53E', fillOpacity: 0.35, zIndex: 5 });
    var b = new google.maps.LatLngBounds();
    path.forEach(function (p) { b.extend(p); });
    state.map.fitBounds(b, 60);
    renderMapBar();
  }
  function useSuggestion() {
    var sg = Z.suggest;
    if (!sg || !sg.poly) return;
    var pts = sg.poly.getPath().getArray().map(function (p) { return [p.lat(), p.lng()]; });
    var id = sg.zoneId;
    api('PUT', '/zones/' + id, { polygon: pts }).then(function (r) {
      cancelSuggest();
      state.zoneId = id;
      toast('Shape saved.' + (r.places_moved ? ' ' + r.places_moved + ' hotels matched to zones again.' : ''));
      refreshZones();
    }).catch(fail);
  }
  function cancelSuggest() {
    if (!Z.suggest) return;
    if (Z.suggest.poly) Z.suggest.poly.setMap(null);
    Z.suggest = null;
    drawZonesOnMap();
  }

  function renderPills() {
    var el = document.getElementById('atc-pills');
    if (!el) return;
    clear(el);
    if (!state.zones.length) {
      add(el, h('p', { class: 'atc-muted', text: 'No zones yet. Use “Add a zone”.' }));
      return;
    }
    state.zones.forEach(function (z) {
      var shaped = z.polygon.length > 0;
      add(el, h('button', { type: 'button', class: 'pill' + (z.id === state.zoneId ? ' on' : '') + (shaped ? '' : ' noshape'), 'aria-pressed': z.id === state.zoneId ? 'true' : 'false', title: shaped ? '' : 'No shape yet', onclick: function () { selectZone(z.id); } },
        z.name + ' ', h('small', { text: shaped ? String(z.places) : 'no shape' })));
    });
  }

  /* drawing the selected zone's shape (new or replacing the old one) */
  function startDraw() {
    if (!state.map || !state.zoneId) return;
    stopEditing(false);
    cancelDraw();
    cancelSuggest();
    if (Z.polys[state.zoneId]) Z.polys[state.zoneId].setOptions({ fillOpacity: 0.08, strokeOpacity: 0.35 });
    Z.drawing = { zoneId: state.zoneId, points: [], line: new google.maps.Polyline({ map: state.map, strokeColor: '#E07A1F', strokeWeight: 3 }), dots: [] };
    state.map.setOptions({ draggableCursor: 'crosshair' });
    renderMapBar();
  }
  function onMapClick(e) {
    if (!Z.drawing) return;
    var p = e.latLng;
    Z.drawing.points.push(p);
    Z.drawing.line.setPath(Z.drawing.points.concat(Z.drawing.points.length > 2 ? [Z.drawing.points[0]] : []));
    Z.drawing.dots.push(new google.maps.Marker({ map: state.map, position: p, clickable: false,
      icon: { path: google.maps.SymbolPath.CIRCLE, scale: 4, fillColor: '#E07A1F', fillOpacity: 1, strokeColor: '#FFFFFF', strokeWeight: 1 } }));
    renderMapBar();
  }
  function cancelDraw() {
    if (!Z.drawing) return;
    Z.drawing.line.setMap(null);
    Z.drawing.dots.forEach(function (d) { d.setMap(null); });
    Z.drawing = null;
    state.map.setOptions({ draggableCursor: null });
    drawZonesOnMap();
  }
  function undoPoint() {
    if (!Z.drawing || !Z.drawing.points.length) return;
    Z.drawing.points.pop();
    var d = Z.drawing.dots.pop(); if (d) d.setMap(null);
    Z.drawing.line.setPath(Z.drawing.points.concat(Z.drawing.points.length > 2 ? [Z.drawing.points[0]] : []));
    renderMapBar();
  }
  function saveDraw() {
    var id = Z.drawing.zoneId;
    var pts = Z.drawing.points.map(function (p) { return [p.lat(), p.lng()]; });
    api('PUT', '/zones/' + id, { polygon: pts }).then(function (r) {
      cancelDraw();
      state.zoneId = id;
      toast('Shape saved.' + (r.places_moved ? ' ' + r.places_moved + ' hotels matched to zones again.' : ''));
      refreshZones();
    }).catch(fail);
  }

  /* reshaping the selected zone */
  function startEditing() {
    cancelSuggest();
    var poly = Z.polys[state.zoneId];
    if (!poly) return;
    Z.editing = state.zoneId;
    poly.setEditable(true);
    renderMapBar();
  }
  function stopEditing(save) {
    if (!Z.editing) return;
    var id = Z.editing, poly = Z.polys[id];
    Z.editing = null;
    if (!poly) return;
    poly.setEditable(false);
    if (save) {
      var pts = poly.getPath().getArray().map(function (p) { return [p.lat(), p.lng()]; });
      api('PUT', '/zones/' + id, { polygon: pts }).then(function (r) {
        toast('Shape saved.' + (r.places_moved ? ' ' + r.places_moved + ' hotels changed zone.' : ''));
        refreshZones();
      }).catch(fail);
    } else {
      drawZonesOnMap();
    }
    renderMapBar();
  }

  function renderMapBar() {
    var bar = document.getElementById('atc-mapbar');
    if (!bar) return;
    clear(bar);
    var sel = zoneById(state.zoneId);
    var needsShape = sel && !sel.polygon.length && state.map;
    bar.style.display = (Z.drawing || Z.editing || Z.suggest || needsShape) ? '' : 'none';
    if (Z.suggest) {
      var sg = Z.suggest, sz = zoneById(sg.zoneId);
      var again = h('input', { type: 'text', value: sg.query, 'aria-label': 'Search another name' });
      var retry = h('button', { class: 'btn', type: 'button', onclick: function () { if (again.value.trim()) suggestShape(again.value.trim()); }, text: 'Search' });
      if (sg.loading) { add(bar, h('span', { text: 'Looking for a ready-made shape for “' + sg.query + '”…' })); return; }
      if (!sg.list.length) {
        add(bar, [
          h('span', { text: 'No ready-made shape for “' + sg.query + '”' + (sg.errors.length ? ' (' + sg.errors.join('; ') + ')' : '') + '. Try another name, or draw it.' }),
          again, retry,
          h('button', { class: 'btn dark', type: 'button', onclick: startDraw, text: 'Draw it myself' }),
          h('button', { class: 'btn', type: 'button', onclick: cancelSuggest, text: 'Cancel' })
        ]);
        return;
      }
      var c = sg.list[sg.idx];
      add(bar, [
        h('div', { class: 'sug' },
          h('strong', { text: (sz ? sz.name + ' · ' : '') + 'Option ' + (sg.idx + 1) + ' of ' + sg.list.length + ': ' + c.kind + ', about ' + c.km2 + ' km²' }),
          h('small', { text: c.label + (c.source === 'osm' ? ' · © OpenStreetMap contributors' : ' · Google') }),
          h('small', { text: 'Drag the white points to adjust it before saving.' })),
        h('button', { class: 'btn dark', type: 'button', onclick: useSuggestion, text: 'Use this shape' }),
        sg.list.length > 1 ? h('button', { class: 'btn', type: 'button', onclick: function () { showSuggestion(sg.idx + 1); }, text: 'Next option' }) : null,
        again, retry,
        h('button', { class: 'btn', type: 'button', onclick: startDraw, text: 'Draw it myself' }),
        h('button', { class: 'btn', type: 'button', onclick: cancelSuggest, text: 'Cancel' })
      ]);
      return;
    }
    if (Z.drawing) {
      var n = Z.drawing.points.length;
      var dz = zoneById(Z.drawing.zoneId);
      add(bar, [
        h('span', { text: (dz ? dz.name + ': ' : '') + (n < 3 ? 'click around its edge on the map (' + n + ' points so far, at least 3).' : n + ' points. Keep clicking or save.') }),
        n >= 3 ? h('button', { class: 'btn dark', type: 'button', onclick: saveDraw, text: 'Save shape' }) : null,
        n ? h('button', { class: 'btn', type: 'button', onclick: undoPoint, text: 'Undo point' }) : null,
        h('button', { class: 'btn', type: 'button', onclick: cancelDraw, text: 'Cancel' })
      ]);
      return;
    }
    if (Z.editing) {
      add(bar, [
        h('span', { text: 'Drag the white points to reshape. Drag a midpoint to add a corner.' }),
        h('button', { class: 'btn dark', type: 'button', onclick: function () { stopEditing(true); }, text: 'Save shape' }),
        h('button', { class: 'btn', type: 'button', onclick: function () { stopEditing(false); }, text: 'Cancel' })
      ]);
      return;
    }
    if (needsShape) {
      add(bar, [
        h('span', { text: sel.name + ' has no shape yet.' }),
        h('button', { class: 'btn dark', type: 'button', onclick: function () { suggestShape(); }, text: 'Suggest a shape' }),
        h('button', { class: 'btn', type: 'button', onclick: startDraw, text: 'Draw it myself' })
      ]);
    }
  }

  function renderZoneCard() {
    var card = document.getElementById('atc-zonecard');
    if (!card) return;
    clear(card);
    var z = state.zones.find(function (x) { return x.id === state.zoneId; });
    if (!z) { add(card, h('p', { class: 'pad atc-muted', text: 'Select or draw a zone to see its hotels.' })); return; }

    var search = h('input', { type: 'search', placeholder: 'Find a hotel', 'aria-label': 'Find a hotel' });
    var tbody = h('tbody');
    var head = h('div', { class: 'card-head' },
      h('h2', { text: z.name + ' · ' + z.places + (z.places === 1 ? ' hotel' : ' hotels') }),
      h('div', { class: 'atc-actions' }, search,
        z.polygon.length
          ? h('button', { class: 'btn', type: 'button', disabled: !state.map, onclick: startEditing, text: 'Adjust shape' })
          : h('button', { class: 'btn dark', type: 'button', disabled: !state.map, onclick: function () { suggestShape(); }, text: 'Suggest a shape' }),
        z.polygon.length
          ? h('button', { class: 'btn', type: 'button', disabled: !state.map, onclick: function () { suggestShape(); }, text: 'Suggest a shape' })
          : h('button', { class: 'btn', type: 'button', disabled: !state.map, onclick: startDraw, text: 'Draw it myself' }),
        z.polygon.length ? h('button', { class: 'btn', type: 'button', disabled: !state.map, onclick: function () {
          if (confirm('Draw ' + z.name + ' again from scratch? The current shape stays until you save the new one.')) startDraw();
        }, text: 'Redraw' }) : null,
        z.polygon.length ? h('button', { class: 'btn dark', type: 'button', onclick: function () { findAllHotels(z); }, text: 'Find all hotels inside' }) : null,
        h('button', { class: 'btn', type: 'button', onclick: function () { renameZone(z); }, text: 'Rename' }),
        h('button', { class: 'btn danger', type: 'button', onclick: function () { archiveZone(z); }, text: 'Archive' })));
    add(card, [head, h('p', { id: 'atc-progress', class: 'atc-progress', 'aria-live': 'polite' }), h('div', { class: 'scroll' }, h('table', null,
      h('thead', null, h('tr', null, ['Hotel', 'Address', 'Matched', 'Zone', ''].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
      tbody))]);

    function load() {
      api('GET', '/places?airport_id=' + state.airportId + '&zone_id=' + z.id + (search.value ? '&search=' + encodeURIComponent(search.value) : '')).then(function (rows) {
        clear(tbody);
        if (!rows.length) add(tbody, h('tr', null, h('td', { colspan: 5, class: 'atc-muted', text: search.value ? 'No hotel matches.' : 'No hotels in this zone yet.' })));
        rows.forEach(function (p) { add(tbody, placeRow(p)); });
        showMarkers(rows);
      }).catch(fail);
    }
    var t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 300); });
    load();
  }

  function placeRow(p) {
    var sel = h('select', { 'aria-label': 'Zone for ' + p.name, onchange: function () {
      api('PUT', '/places/' + p.id, { zone_id: this.value === 'auto' ? 'auto' : parseInt(this.value, 10) })
        .then(function () { toast('Moved.'); refreshZones(); }).catch(fail);
    } },
      h('option', { value: 'auto', text: 'Automatic (by address)' }),
      state.zones.map(function (z) { return h('option', { value: z.id, selected: p.matched_by === 'manual' && z.id === p.zone_id, text: z.name }); }));
    if (p.matched_by !== 'manual') sel.value = 'auto';
    return h('tr', null,
      h('td', { class: 'strong', text: p.name }),
      h('td', { class: 'atc-muted', text: p.address || '' }),
      h('td', null, h('span', { class: 'chip ' + (p.matched_by === 'manual' ? 'blue' : 'green'), text: p.matched_by === 'manual' ? 'By hand' : 'By address' })),
      h('td', null, sel),
      h('td', null, h('button', { class: 'link danger', type: 'button', onclick: function () {
        if (!confirm('Remove “' + p.name + '” from the list? Bookings already made keep their address.')) return;
        api('DELETE', '/places/' + p.id).then(function () { toast('Removed.'); refreshZones(); }).catch(fail);
      }, text: 'Remove' })));
  }

  function showMarkers(rows) {
    if (!state.map) return;
    Z.markers.forEach(function (m) { m.setMap(null); });
    Z.markers = rows.filter(function (p) { return p.lat !== null; }).map(function (p) {
      return new google.maps.Marker({ map: state.map, position: { lat: p.lat, lng: p.lng }, title: p.name,
        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 5, fillColor: '#15171C', fillOpacity: 1, strokeColor: '#FFFFFF', strokeWeight: 1.5 } });
    });
  }

  function findAllHotels(z) {
    if (Z.finding) { toast('Already searching. Wait for it to finish.', true); return; }
    api('GET', '/zones/' + z.id + '/tiles').then(function (r) {
      var n = r.count;
      if (!n) { toast('Nothing to search in this shape.', true); return; }
      if (!confirm('Search Google for every hotel inside ' + z.name + '? It checks ' + n + (n === 1 ? ' part' : ' parts') + ' of the zone, one after another (about ' + Math.max(1, Math.round(n * 3 / 60)) + ' min). Hotels already listed are skipped.')) return;
      Z.finding = true;
      var total = { added: 0, existing: 0, other_zone: 0 }, errors = 0, i = 0;
      function bar() { return document.getElementById('atc-progress') || {}; }
      function step() {
        bar().textContent = 'Searching ' + z.name + ': part ' + (i + 1) + ' of ' + n + ' · ' + total.added + ' new hotels so far';
        api('POST', '/zones/' + z.id + '/import-tile', { index: i }).then(function (res) {
          total.added += res.added; total.existing += res.existing; total.other_zone += res.other_zone;
        }).catch(function (e) {
          errors++;
          if (errors >= 3) throw e;
        }).then(function () {
          i++;
          if (i < n) return step();
          finish();
        }).catch(function (e) { Z.finding = false; bar().textContent = ''; fail(e); refreshZones(); });
      }
      function finish() {
        Z.finding = false;
        bar().textContent = '';
        toast(total.added + ' hotels added to ' + z.name + ', ' + total.existing + ' were already listed' + (total.other_zone ? ', ' + total.other_zone + ' belong to an overlapping zone' : '') + (errors ? ' (' + errors + ' parts failed)' : '') + '.');
        refreshZones();
      }
      step();
    }).catch(fail);
  }

  function renameZone(z) {
    var name = prompt('New name for “' + z.name + '”', z.name);
    if (!name || name.trim() === z.name) return;
    api('PUT', '/zones/' + z.id, { name: name.trim() }).then(function () { toast('Renamed.'); refreshZones(); }).catch(fail);
  }
  function archiveZone(z) {
    if (!confirm('Archive “' + z.name + '”? Its hotels will be matched to other zones or listed as needing a zone. Providers stop serving it.')) return;
    api('DELETE', '/zones/' + z.id).then(function () { toast('Zone archived.'); state.zoneId = null; Z.fitted = false; refreshZones(); }).catch(fail);
  }

  function renderUnmatched() {
    var el = document.getElementById('atc-unmatched');
    if (!el) return;
    api('GET', '/places?unmatched=1&airport_id=' + state.airportId).then(function (rows) {
      clear(el);
      if (!rows.length) { el.className = 'card pad ok'; add(el, h('p', { class: 'strong', text: 'All hotels are in a zone.' })); return; }
      el.className = 'card pad warn';
      add(el, h('h2', { text: rows.length + (rows.length === 1 ? ' hotel needs a zone' : ' hotels need a zone') }));
      add(el, h('p', { class: 'atc-muted', text: 'They sit outside every zone. Reshape a zone to cover them, or pick one by hand.' }));
      var list = h('div', { class: 'list' });
      rows.forEach(function (p) {
        var sel = h('select', { 'aria-label': 'Zone for ' + p.name },
          h('option', { value: '', text: 'Pick a zone…' }),
          state.zones.map(function (z) { return h('option', { value: z.id, text: z.name }); }));
        add(list, h('div', { class: 'row' },
          h('div', null, h('div', { class: 'strong', text: p.name }), h('div', { class: 'atc-muted', text: p.address || '' })),
          h('div', { class: 'atc-actions' }, sel, h('button', { class: 'btn dark', type: 'button', onclick: function () {
            if (!sel.value) { sel.focus(); return; }
            api('PUT', '/places/' + p.id, { zone_id: parseInt(sel.value, 10) }).then(function () { toast('Placed.'); refreshZones(); }).catch(fail);
          }, text: 'Save' }))));
      });
      add(el, list);
    }).catch(fail);
  }

  function renderAddHotel() {
    var el = document.getElementById('atc-addcard');
    clear(el);
    var slot = h('div', { class: 'atc-ac' });
    var lines = h('textarea', { rows: 4, placeholder: 'One hotel per line, e.g.\nHotel Riu Cancun\nHyatt Ziva Cancun', 'aria-label': 'Hotel names' });
    var results = h('div', { class: 'list' });
    var importBtn = h('button', { class: 'btn dark', type: 'button', text: 'Find and add' });
    importBtn.addEventListener('click', function () {
      var names = lines.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
      if (!names.length) { lines.focus(); return; }
      if (names.length > 25) { toast('Up to 25 at a time.', true); return; }
      importBtn.disabled = true; importBtn.textContent = 'Searching ' + names.length + '…';
      api('POST', '/places/import', { airport_id: state.airportId, lines: names }).then(function (rows) {
        clear(results);
        var ok = 0;
        rows.forEach(function (r) {
          if (r.ok) ok++;
          add(results, h('div', { class: 'row' + (r.ok ? '' : ' bad') },
            h('div', null, h('div', { class: 'strong', text: r.ok ? r.name : r.query }), h('div', { class: 'atc-muted', text: r.ok ? r.address : ('Not added: ' + r.error) })),
            r.ok ? h('span', { class: 'chip ' + (r.zone_id ? 'green' : 'orange'), text: r.existing ? 'Already listed' : (r.zone_id ? zoneName(r.zone_id) : 'Needs a zone') }) : null));
        });
        toast(ok + ' of ' + rows.length + ' found.');
        lines.value = '';
        refreshZones();
      }).catch(fail).then(function () { importBtn.disabled = false; importBtn.textContent = 'Find and add'; });
    });

    var checkOut = h('div', { class: 'list' });
    add(el, [
      h('h2', { text: 'Hotels and addresses' }),
      h('p', { class: 'atc-muted', text: 'Travelers can book any hotel or address inside a zone: they pick it live from Google and get that zone’s price. This list is optional. It pins hotels near a border to the right zone (by hand) and fills the suggestions travelers see first. Use “Find all hotels inside” on a zone to fill it in one go.' }),
      h('div', { class: 'atc-two' },
        h('div', null, h('p', { class: 'label', text: 'Check any hotel or address' }), slot,
          h('p', { class: 'atc-muted small', text: 'Shows the zone and price area a traveler would get. Nothing is saved unless you add it.' }), checkOut),
        h('div', null, h('p', { class: 'label', text: 'Or add a list of hotels by name (up to 25)' }), lines, importBtn)),
      results
    ]);

    loadMaps().then(function () {
      return google.maps.importLibrary('places');
    }).then(function (lib) {
      var c = airportCenter();
      var ac = new lib.PlaceAutocompleteElement({
        locationBias: { radius: 50000, center: c },
        includedRegionCodes: ['mx']
      });
      ac.setAttribute('placeholder', 'Type a hotel or address');
      slot.appendChild(ac);
      var handler = function (ev) {
        var pred = ev.placePrediction;
        var placePromise = Promise.resolve(pred ? pred.toPlace() : ev.place);
        placePromise.then(function (place) {
          return place.fetchFields({ fields: ['id', 'displayName', 'formattedAddress', 'location'] }).then(function () { return place; });
        }).then(function (place) {
          var pl = { airport_id: state.airportId, name: place.displayName, address: place.formattedAddress,
            lat: place.location.lat(), lng: place.location.lng(), google_place_id: place.id };
          return api('GET', '/zone-at?airport_id=' + state.airportId + '&lat=' + pl.lat + '&lng=' + pl.lng + '&place_id=' + encodeURIComponent(pl.google_place_id))
            .then(function (r) { showCheck(pl, r); });
        }).catch(fail);
      };
      function showCheck(pl, r) {
        clear(checkOut);
        if (state.map) {
          if (Z.check) Z.check.setMap(null);
          Z.check = new google.maps.Marker({ map: state.map, position: { lat: pl.lat, lng: pl.lng }, title: pl.name });
          state.map.panTo({ lat: pl.lat, lng: pl.lng });
        }
        var addBtn = r.listed ? h('span', { class: 'chip grey', text: 'Already in the list' }) : h('button', { class: 'btn', type: 'button', onclick: function () {
          api('POST', '/places', pl).then(function (x) {
            toast(x.zone_id ? 'Added to ' + zoneName(x.zone_id) + '.' : 'Added. It sits outside every zone.');
            if (x.zone_id) state.zoneId = x.zone_id;
            clear(checkOut);
            refreshZones();
          }).catch(fail);
        }, text: 'Add to the list' });
        add(checkOut, h('div', { class: 'row' + (r.zone_id ? '' : ' bad') },
          h('div', null, h('div', { class: 'strong', text: pl.name }), h('div', { class: 'atc-muted', text: pl.address || '' }),
            h('div', { class: 'small', text: r.zone_id ? 'Bookable at ' + r.zone + ' prices (' + r.how + ').' : 'Outside every zone: travelers would get a quote request, not a price.' })),
          h('div', { class: 'atc-actions' }, h('span', { class: 'chip ' + (r.zone_id ? 'green' : 'orange'), text: r.zone_id ? r.zone : 'No zone' }), addBtn)));
      }
      ac.addEventListener('gmp-select', handler);
      ac.addEventListener('gmp-placeselect', handler);
    }).catch(function () {
      add(slot, h('p', { class: 'atc-muted', text: 'Google search needs the Website key. The list import below uses the Server key.' }));
    });
  }

  /* ---------- Providers ---------- */

  function portalCell(p, onDone) {
    var st = p.portal || { state: 'not_invited' };
    var label = { active: 'Active' + (st.active_since ? ' since ' + st.active_since : ''), invited: 'Invited ' + (st.invited_at || '').slice(0, 10) + (st.invite_count > 1 ? ' (×' + st.invite_count + ')' : ''), not_invited: 'Not invited' }[st.state];
    var chip = h('span', { class: 'chip ' + ({ active: 'green', invited: 'orange', not_invited: 'grey' }[st.state]), text: label });
    if (st.state === 'active') return h('div', { class: 'atc-actions' }, chip);
    var btn = h('button', { class: 'link', type: 'button', text: st.state === 'invited' ? 'Resend invitation' : 'Send sign-up invitation' });
    btn.addEventListener('click', function () {
      if (!p.email) { toast('Add an email for ' + p.name + ' first.', true); return; }
      btn.disabled = true;
      api('POST', '/providers/' + p.id + '/invite').then(function (r) {
        toast(r.test ? 'Test mode: invitation sent to ' + r.sent_to + ' (would go to ' + p.email + ').' : 'Invitation sent to ' + r.sent_to + '.');
        onDone();
      }).catch(fail).then(function () { btn.disabled = false; });
    });
    return h('div', { class: 'atc-actions' }, chip, btn);
  }

  function viewProviders() {
    clear(app);
    var tbody = h('tbody');
    var banner = h('div');
    var search = h('input', { type: 'search', placeholder: 'Find a provider', 'aria-label': 'Find a provider' });
    search.value = new URLSearchParams(location.search).get('q') || '';
    var filter = h('select', { 'aria-label': 'Filter' },
      [['all', 'All'], ['live', 'Live'], ['pending', 'Pending'], ['not_invited', 'Not invited yet'], ['invited', 'Invited, no password yet'], ['active', 'Using the portal']].map(function (o) { return h('option', { value: o[0], text: o[1] }); }));
    var bulk = h('button', { class: 'btn', type: 'button', text: 'Invite everyone not invited yet' });
    var count = h('span', { class: 'atc-muted' });
    var all = [];
    add(app, [
      h('div', { class: 'atc-head' },
        h('div', null, h('h1', { text: 'Providers' }), h('p', { class: 'atc-muted', text: 'Transport companies we sell. Providers brought over from the old site get a sign-up invitation to choose their password.' })),
        h('div', { class: 'atc-actions' },
          h('a', { class: 'btn', href: C.base + 'import/', 'data-go': 'import', text: 'Import from the old site' }),
          h('button', { class: 'btn dark', type: 'button', onclick: newProvider, text: 'Add a provider' }))),
      banner,
      h('div', { class: 'atc-actions' }, search, filter, count, bulk),
      h('section', { class: 'card' }, h('div', { class: 'scroll' }, h('table', null,
        h('thead', null, h('tr', null, ['Provider', 'Contact', 'Airports', 'Status', 'Portal'].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
        tbody)))
    ]);
    function render() {
      clear(tbody);
      var q = search.value.trim().toLowerCase(), f = filter.value;
      var rows = all.filter(function (p) {
        if (q && (p.name + ' ' + (p.email || '')).toLowerCase().indexOf(q) === -1) return false;
        if (f === 'live' || f === 'pending') return p.status === f;
        if (f === 'not_invited' || f === 'invited' || f === 'active') return p.portal.state === f;
        return true;
      });
      count.textContent = rows.length + ' of ' + all.length;
      if (!rows.length) add(tbody, h('tr', null, h('td', { colspan: 5, class: 'atc-muted', text: all.length ? 'No provider matches.' : 'No providers yet.' })));
      rows.slice(0, 300).forEach(function (p) {
        add(tbody, h('tr', null,
          h('td', null, h('a', { class: 'strong', href: C.base + 'providers/' + p.id + '/', 'data-go': 'providers/' + p.id, text: p.name })),
          h('td', { class: 'atc-muted', text: [p.email, p.phone].filter(Boolean).join(' · ') }),
          h('td', { text: p.airports || '' }),
          h('td', null, statusChip(p.status)),
          h('td', null, portalCell(p, load))));
      });
      var notInvited = all.filter(function (p) { return p.portal.state === 'not_invited' && p.email && p.status !== 'archived'; }).length;
      bulk.textContent = 'Invite everyone not invited yet (' + notInvited + ')';
      bulk.disabled = !notInvited;
    }
    function load() {
      api('GET', '/providers').then(function (r) {
        all = r.providers;
        clear(banner);
        if (r.invite_test_to) add(banner, h('p', { class: 'chip orange', text: 'Test mode: invitations go to ' + r.invite_test_to + ', not to the providers. Change it in Booking Engine → Settings on the live site.' }));
        render();
      }).catch(fail);
    }
    search.addEventListener('input', render);
    filter.addEventListener('change', render);
    bulk.addEventListener('click', function () {
      var n = all.filter(function (p) { return p.portal.state === 'not_invited' && p.email && p.status !== 'archived'; }).length;
      if (!confirm('Send the sign-up invitation to ' + n + ' providers? They go out 40 at a time; click again for the rest.')) return;
      bulk.disabled = true; bulk.textContent = 'Sending…';
      api('POST', '/providers/invite-all').then(function (r) {
        toast(r.sent + ' invitations sent' + (r.test_to ? ' to ' + r.test_to + ' (test mode)' : '') + (r.remaining ? '. ' + r.remaining + ' left: click again.' : '.') + (r.failed.length ? ' ' + r.failed.length + ' failed.' : ''), !!r.failed.length);
        load();
      }).catch(fail).then(function () { bulk.disabled = false; });
    });
    load();
  }

  function viewImport() {
    clear(app);
    var file = h('input', { type: 'file', accept: 'application/json,.json' });
    var out = h('div', { class: 'list' });
    function run(dry) {
      if (!file.files.length) { file.focus(); toast('Choose the import file first.', true); return; }
      var fd = new FormData(); fd.append('file', file.files[0]); fd.append('dry_run', dry ? '1' : '0');
      clear(out); add(out, h('p', { class: 'atc-muted', text: dry ? 'Checking…' : 'Importing… this can take a minute.' }));
      api('POST', '/import', fd, true).then(function (r) {
        clear(out);
        if (!r.ok) { add(out, h('p', { class: 'chip red', text: r.error })); return; }
        add(out, h('section', { class: 'card pad' + (dry ? '' : ' ok') },
          h('h2', { text: dry ? 'Check only: nothing was saved' : 'Import done' }),
          h('div', { class: 'ticks' },
            h('span', { class: 'chip grey', text: 'Airports: ' + r.airports_new + ' new, ' + r.airports_existing + ' already here' }),
            h('span', { class: 'chip grey', text: 'Providers: ' + r.providers_new + ' new, ' + r.providers_existing + ' already here' }),
            h('span', { class: 'chip grey', text: 'Zones: ' + r.zones_new + ' new, ' + r.zones_shape_added + ' got a shape, ' + r.zones_existing + ' already shaped' }),
            h('span', { class: 'chip grey', text: 'Vehicle categories: ' + r.services }),
            h('span', { class: 'chip grey', text: 'Prices: ' + r.prices }),
            h('span', { class: 'chip grey', text: 'Bookings: ' + r.bookings_new + ' new, ' + r.bookings_existing + ' already here' }),
            r.travelers_updated !== undefined ? h('span', { class: 'chip grey', text: 'Travelers: ' + r.travelers_updated + ' bookings got name and contact, ' + r.legs_updated + ' rides got flight, notes and bags' }) : null,
            r.provider_applications_new !== undefined ? h('span', { class: 'chip grey', text: 'Provider sign-ups: ' + r.provider_applications_new + ' new (pending), ' + r.provider_applications_existing + ' already here' }) : null,
            r.affiliates_new !== undefined ? h('span', { class: 'chip grey', text: 'Affiliates: ' + r.affiliates_new + ' new, ' + r.affiliates_existing + ' already here' }) : null,
            r.bookings_completed ? h('span', { class: 'chip green', text: r.bookings_completed + ' past bookings marked completed' }) : null,
            r.prices_held ? h('span', { class: 'chip orange', text: r.prices_held + ' prices left out (wrong currency or typo)' }) : null),
          r.issues.length ? h('details', null, h('summary', { class: 'strong', text: r.issues.length + ' things to look at' }), h('ul', null, r.issues.map(function (i) { return h('li', { class: 'small', text: i }); }))) : null,
          dry ? h('div', { class: 'atc-actions' }, h('button', { class: 'btn dark', type: 'button', onclick: function () { run(false); }, text: 'Looks right: import now' })) : h('div', { class: 'atc-actions' }, h('a', { class: 'btn', href: C.base + 'providers/', 'data-go': 'providers', text: 'See the providers' }))));
      }).catch(fail);
    }
    add(app, [
      h('a', { class: 'back', href: C.base + 'providers/', 'data-go': 'providers', text: '← Providers' }),
      h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Import from the old site' }), h('p', { class: 'atc-muted', text: 'Providers, the airports they serve, their coverage areas (as zones), their prices and the old bookings. A second file adds traveler names and contacts, provider and affiliate sign-ups, and completes past bookings. Travelers keep paying the same; providers get 75% under the new 25% commission. Safe to run again: nothing is doubled, and shapes you drew here are never replaced.' }))),
      h('section', { class: 'card pad' },
        h('label', { class: 'field' }, h('span', { text: 'Import file (atcom-migration.json or atcom-migration-2.json)' }), file),
        h('div', { class: 'atc-actions' },
          h('button', { class: 'btn dark', type: 'button', onclick: function () { run(true); }, text: 'Check first' }),
          h('button', { class: 'btn', type: 'button', onclick: function () { if (confirm('Import now without checking first?')) run(false); }, text: 'Import' })),
        h('p', { class: 'small atc-muted', text: 'Nobody is emailed by the import. Invitations are sent from the Providers list when you decide.' })),
      out
    ]);
  }

  function statusChip(s) {
    var c = { live: 'green', pending: 'orange', paused: 'orange', suspended: 'red', archived: 'grey', draft: 'grey' }[s] || 'grey';
    return h('span', { class: 'chip ' + c, text: s.charAt(0).toUpperCase() + s.slice(1) });
  }

  function newProvider() {
    var name = prompt('Provider name (as travelers will see it)');
    if (!name || !name.trim()) return;
    api('POST', '/providers', { name: name.trim(), country: 'MX', payout_currency: 'MXN' }).then(function (p) {
      toast('Provider added.');
      go('providers/' + p.id);
    }).catch(fail);
  }

  function field(label, input, help) {
    return h('label', { class: 'field' }, h('span', { text: label }), input, help ? h('small', { text: help }) : null);
  }

  /* ---------- Directory profile ---------- */

  function directoryCard(id) {
    var card = h('section', { class: 'card pad' }, h('h2', { text: 'Directory profile' }), h('p', { class: 'atc-muted', text: 'Loading…' }));
    function draw(d) {
      clear(card);
      add(card, h('div', { class: 'row-flat' }, h('h2', { text: 'Directory profile' }),
        d.linked ? h('div', { class: 'atc-actions' },
          d.url ? h('a', { class: 'btn', href: d.url, target: '_blank', rel: 'noopener', text: 'View profile ↗' }) : null,
          h('a', { class: 'btn dark', href: C.base + 'providers/' + id + '/directory/', 'data-go': 'providers/' + id + '/directory', text: 'Edit profile' })) : null));
      if (d.linked) {
        var logo = h('span', { class: 'dir-logo', style: 'width:64px;height:64px;flex-basis:64px;border-radius:14px' });
        if (d.logo) logo.style.backgroundImage = 'url("' + String(d.logo).replace(/["\\\n]/g, '') + '")';
        add(card, h('div', { class: 'row-flat', style: 'justify-content:flex-start;gap:14px' }, logo,
          h('div', null, h('strong', { text: d.title }), ' ', h('span', { class: 'chip ' + (d.status === 'publish' ? 'green' : 'orange'), text: d.status === 'publish' ? 'Public' : (d.provider_live ? 'Draft, goes public when saved' : 'Draft, goes public on approval') }),
            h('div', { class: 'small atc-muted', text: [d.location, d.summary ? 'description ✓' : 'no description', d.logo ? 'logo ✓' : 'no logo', d.website ? (d.show_website ? 'website shown' : 'website not shown') + ({ found: ', link to us found', missing: ', no link to us', error: ', their site did not answer' }[(d.backlink || {}).status] || ', not checked yet') + ((d.backlink || {}).mode === 'always' ? ' (always on)' : (d.backlink || {}).mode === 'never' ? ' (always off)' : '') : 'no website', d.src === 'engine' ? 'made from their sign-up' : 'profile from the directory'].filter(Boolean).join(' · ') }))),
          h('p', { class: 'small' }, h('button', { class: 'link', type: 'button', text: 'Unlink this profile', onclick: function () {
            if (!confirm('Unlink “' + d.title + '” from this provider? The profile stays in the directory as it is.')) return;
            api('POST', '/providers/' + id + '/directory/link', { post_id: 0 }).then(function (nd) { toast('Unlinked.'); draw(nd); }).catch(fail);
          } })));
        return;
      }
      add(card, h('p', { class: 'atc-muted', text: 'Not linked to a directory profile yet. Link the profile that is theirs, or make a new one from their details.' }));
      if (d.match) add(card, h('div', { class: 'dir-hit' }, h('span', null, 'Looks like theirs: ', h('a', { href: d.match.url, target: '_blank', rel: 'noopener', text: d.match.title + ' ↗' })),
        h('button', { class: 'btn dark', type: 'button', text: 'Link it', onclick: function () { linkTo(d.match.id); } })));
      var q = h('input', { type: 'search', placeholder: 'Search the directory by company name' });
      var hits = h('div', { class: 'dir-hits' });
      var timer;
      q.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          if (q.value.trim().length < 2) { clear(hits); return; }
          api('GET', '/directory/search?q=' + encodeURIComponent(q.value.trim())).then(function (list) {
            clear(hits);
            if (!list.length) add(hits, h('p', { class: 'small atc-muted', text: 'No profile with that name.' }));
            list.forEach(function (x) {
              add(hits, h('div', { class: 'dir-hit' }, h('span', null, h('a', { href: x.url, target: '_blank', rel: 'noopener', text: x.title }), h('span', { class: 'small atc-muted', text: ' ' + [x.location, x.status === 'publish' ? '' : 'draft'].filter(Boolean).join(' · ') })),
                x.owner_id ? h('span', { class: 'small atc-muted', text: 'Linked to ' + x.owner }) : h('button', { class: 'btn', type: 'button', text: 'Link', onclick: function () { linkTo(x.id); } })));
            });
          }).catch(fail);
        }, 250);
      });
      add(card, [h('div', { class: 'grid2', style: 'margin-top:10px;align-items:end' }, field('Find their profile', q),
        h('div', { class: 'atc-actions' }, h('button', { class: 'btn', type: 'button', text: 'Make a new profile', onclick: function () {
          api('POST', '/providers/' + id + '/directory', {}).then(function (nd) { toast(nd.url ? 'Profile made and public.' : 'Profile made, as a draft until they are live.'); draw(nd); }).catch(fail);
        } }))), hits]);
    }
    function linkTo(pid) { api('POST', '/providers/' + id + '/directory/link', { post_id: pid }).then(function (nd) { toast('Linked.'); draw(nd); }).catch(fail); }
    api('GET', '/providers/' + id + '/directory').then(draw).catch(function (e) { clear(card); add(card, [h('h2', { text: 'Directory profile' }), h('p', { class: 'chip red', text: e.message })]); });
    return card;
  }

  function viewProviderDirectory(id) {
    clear(app);
    var box = h('div', { class: 'stack' }, h('p', { class: 'atc-muted', text: 'Loading…' }));
    add(app, [h('a', { class: 'back', href: C.base + 'providers/' + id + '/', 'data-go': 'providers/' + id, text: '← Provider' }),
      h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Their directory profile' }), h('p', { class: 'atc-muted', text: 'The same page they edit in their portal. Their website shows while their site links back to us (checked daily); you can force it on or off here.' }))), box]);
    api('GET', '/providers/' + id + '/directory').then(function (d) {
      dirEditor(box, d, {
        save: function (b) { return api('POST', '/providers/' + id + '/directory', b); },
        logo: function (fd) { return api('POST', '/providers/' + id + '/directory/logo', fd, true); },
        check: function () { return api('POST', '/providers/' + id + '/directory/check'); },
        staff: true
      });
    }).catch(fail);
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

  function viewProvider(id) {
    clear(app);
    add(app, h('p', { class: 'atc-muted', text: 'Loading…' }));
    Promise.all([api('GET', '/providers/' + id), api('GET', '/zones?airport_id=' + state.airportId + '&party_id=' + id)]).then(function (res) {
      var d = res[0], zones = res[1], p = d.provider;
      clear(app);

      var f = {
        name: h('input', { type: 'text', value: p.name }),
        legal_name: h('input', { type: 'text', value: p.legal_name || '' }),
        email: h('input', { type: 'email', value: p.email || '' }),
        phone: h('input', { type: 'tel', value: p.phone || '' }),
        country: h('input', { type: 'text', value: p.country || '', maxlength: 2 }),
        languages: h('input', { type: 'text', value: p.languages || '', placeholder: 'Spanish, English' }),
        commission: h('input', { type: 'number', min: 0, max: 90, step: '0.5', value: p.commission_bp === null ? '' : p.commission_bp / 100, placeholder: String(state.boot.commission_bp / 100) }),
        min_notice: h('input', { type: 'number', min: 0, step: 1, value: p.min_notice_minutes === null ? '' : p.min_notice_minutes / 60, placeholder: '24' }),
        payout_method: h('select', null, ['manual', 'wise', 'bank', 'stripe'].map(function (m) { return h('option', { value: m, selected: p.payout_method === m, text: { manual: 'Manual', wise: 'Wise', bank: 'Bank transfer', stripe: 'Stripe Connect' }[m] }); })),
        payout_currency: h('select', null, ['MXN', 'USD', 'EUR'].map(function (c) { return h('option', { value: c, selected: p.payout_currency === c, text: c }); })),
        status: h('select', null, ['pending', 'live', 'paused', 'suspended', 'archived'].map(function (s) { return h('option', { value: s, selected: p.status === s, text: s.charAt(0).toUpperCase() + s.slice(1) }); }))
      };
      var saveBtn = h('button', { class: 'btn dark', type: 'submit', text: 'Save provider' });
      var form = h('form', { class: 'card pad', onsubmit: function (e) {
        e.preventDefault();
        saveBtn.disabled = true;
        api('PUT', '/providers/' + id, {
          name: f.name.value, legal_name: f.legal_name.value, email: f.email.value, phone: f.phone.value,
          country: f.country.value, languages: f.languages.value,
          commission_bp: f.commission.value === '' ? null : Math.round(parseFloat(f.commission.value) * 100),
          min_notice_minutes: f.min_notice.value === '' ? null : Math.round(parseFloat(f.min_notice.value) * 60),
          payout_method: f.payout_method.value, payout_currency: f.payout_currency.value, status: f.status.value
        }).then(function () { toast('Saved.'); viewProvider(id); }).catch(fail).then(function () { saveBtn.disabled = false; });
      } },
        h('h2', { text: 'Details' }),
        h('div', { class: 'grid3' },
          field('Name travelers see', f.name), field('Legal name', f.legal_name), field('Status', f.status, 'Live providers can be booked once they have live services with prices.'),
          field('Email', f.email), field('Phone / WhatsApp', f.phone), field('Country (2 letters)', f.country),
          field('Languages', f.languages), field('Commission %', f.commission, 'Empty = default ' + money(state.boot.commission_bp)), field('Minimum notice (hours)', f.min_notice, 'Empty = set per service'),
          field('Payout method', f.payout_method), field('Payout currency', f.payout_currency)),
        h('div', { class: 'atc-actions' }, saveBtn));

      add(app, [
        h('a', { class: 'back', href: C.base + 'providers/', 'data-go': 'providers', text: '← Providers' }),
        h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: p.name }), h('p', { class: 'atc-muted', text: 'Provider since ' + (p.created_at || '').slice(0, 10) + (p.contact_name ? ' · ' + p.contact_name : '') })),
          h('div', { class: 'atc-actions' }, statusChip(p.status),
            p.status !== 'live' ? h('button', { class: 'btn dark', type: 'button', onclick: function () {
              var notReady = ['company', 'vehicles', 'coverage', 'prices'].filter(function (k) { return d.steps[k] && d.steps[k].state !== 'done'; }).map(function (k) { return d.steps[k].name + ' (' + d.steps[k].label + ')'; });
              if (!confirm((notReady.length ? 'Not finished yet: ' + notReady.join(', ') + '.\n\n' : '') + 'Set ' + p.name + ' live? Their offered vehicle categories become bookable and they get an email.')) return;
              api('PUT', '/providers/' + id, { status: 'live' }).then(function () { toast('Live.'); viewProvider(id); }).catch(fail);
            }, text: 'Approve and set live' }) : null)),
        h('div', { class: 'card pad' }, h('div', { class: 'row-flat' }, h('strong', { text: 'Portal' }), portalCell({ id: id, name: p.name, email: p.email, portal: d.portal }, function () { viewProvider(id); }))),
        setupCard(d),
        directoryCard(id),
        p.about ? h('section', { class: 'card pad' }, h('h2', { text: 'About the company (from sign-up)' }), h('p', { class: 'prewrap', text: p.about })) : null,
        documentsCard(id, d),
        form,
        h('h2', { class: 'pe-title', text: 'Prices' }),
        h('div', { class: 'pe-root', id: 'atc-prices' }),
        servicesCard(id, d.services, zones),
        vehiclesCard(id, d.vehicles),
        h('section', { class: 'card pad' }, h('div', { class: 'row-flat' }, h('h2', { text: 'Extras' }), h('a', { class: 'btn', href: C.base + 'providers/' + id + '/extras/', 'data-go': 'providers/' + id + '/extras', text: 'Edit their extras' })))
      ]);
      window.ATCPrices.mount(document.getElementById('atc-prices'), { api: api, base: '/providers/' + id + '/prices', who: 'staff', toast: toast, fail: fail });
    }).catch(fail);
  }

  function setupCard(d) {
    var keys = ['company', 'directory', 'vehicles', 'coverage', 'prices', 'documents', 'payout', 'agreement', 'approval'];
    var pay = d.payout;
    return h('section', { class: 'card pad' },
      h('h2', { text: 'Setup in the provider portal' }),
      h('div', { class: 'ticks' }, keys.map(function (k) {
        var st = d.steps[k];
        return h('span', { class: 'chip ' + ({ done: 'green', waiting: 'orange', todo: 'grey' }[st.state]), text: st.name + ': ' + st.label });
      })),
      h('p', { class: 'small atc-muted', text: 'Payout: ' + (pay.set ? (pay.method === 'wise' ? 'Wise · ' + pay.email : (pay.bank ? pay.bank + ' · ' : '') + 'ending ' + pay.last4) + ' · ' + pay.currency + ' · holder ' + pay.holder : 'not given yet') +
        (d.provider.agreement_accepted_at ? ' · Agreement ' + d.provider.agreement_version + ' accepted ' + d.provider.agreement_accepted_at.slice(0, 16) + ' UTC' : '') }));
  }

  function documentsCard(partyId, d) {
    var card = h('section', { class: 'card pad' + (d.documents.some(function (x) { return x.status === 'pending'; }) ? ' warn' : '') }, h('h2', { text: 'Documents' }));
    if (!d.documents.length) { add(card, h('p', { class: 'atc-muted', text: 'Nothing uploaded yet.' })); return card; }
    d.documents.forEach(function (doc) {
      var name = (d.doc_types[doc.type] || [doc.type])[0];
      var note = h('input', { type: 'text', placeholder: 'Reason, if not accepted' });
      function review(status) {
        if (status === 'rejected' && !note.value.trim()) { note.focus(); toast('Say why, so the provider knows what to send.', true); return; }
        api('POST', '/documents/' + doc.id + '/review', { status: status, note: note.value }).then(function () { toast('Saved.'); viewProvider(partyId); }).catch(fail);
      }
      add(card, h('div', { class: 'row' },
        h('div', null, h('div', { class: 'strong', text: name }),
          h('div', { class: 'atc-muted small' }, h('a', { href: doc.url, target: '_blank', rel: 'noopener', text: doc.original_name }),
            ' · sent ' + doc.created_at.slice(0, 10) + (doc.expires_on ? ' · expires ' + doc.expires_on : '') + (doc.note ? ' · ' + doc.note : ''))),
        h('div', { class: 'atc-actions' },
          h('span', { class: 'chip ' + ({ approved: 'green', rejected: 'red', pending: 'orange' }[doc.status] || 'grey'), text: doc.status }),
          doc.status === 'pending' ? [
            h('button', { class: 'btn dark', type: 'button', onclick: function () { review('approved'); }, text: 'Approve' }),
            note,
            h('button', { class: 'btn danger', type: 'button', onclick: function () { review('rejected'); }, text: 'Ask for a new one' })
          ] : null)));
    });
    return card;
  }

  function servicesCard(partyId, services, zones) {
    var airport = state.boot.airports.find(function (a) { return a.id == state.airportId; });
    var card = h('section', { class: 'card pad' },
      h('h2', { text: 'Vehicles and zones at ' + (airport ? (airport.iata || airport.name) : 'the airport') }),
      h('p', { class: 'atc-muted', text: 'Tick each vehicle category this provider sells, then the zones it covers. Prices come next (milestone 3).' }));
    if (!zones.length) add(card, h('p', { class: 'chip orange', text: 'Draw zones first in Zones and hotels.' }));

    state.boot.vehicle_types.forEach(function (t) {
      var s = services.find(function (x) { return x.vehicle_type_id == t.id; });
      var offered = h('input', { type: 'checkbox', checked: !!s && s.status !== 'paused' });
      var status = h('select', null, ['draft', 'live', 'paused'].map(function (v) { return h('option', { value: v, selected: (s ? s.status : 'draft') === v, text: v.charAt(0).toUpperCase() + v.slice(1) }); }));
      var pax = h('input', { type: 'number', min: 1, max: 60, value: s ? s.max_passengers : t.max_passengers });
      var bags = h('input', { type: 'number', min: 0, max: 60, value: s ? s.max_bags : t.max_bags });
      var wait = h('input', { type: 'number', min: 0, max: 240, value: s ? s.free_wait_minutes : 60 });
      var notice = h('input', { type: 'number', min: 0, step: '0.5', value: s ? s.min_notice_minutes / 60 : 24 });
      var zoneBoxes = zones.map(function (z) {
        var cb = h('input', { type: 'checkbox', value: z.id, checked: !!s && s.zone_ids.indexOf(z.id) !== -1 });
        return h('label', { class: 'tick' }, cb, z.name + (z.own ? ' (drawn by them)' : ''));
      });
      var body = h('div', { class: 'svc-body' + (offered.checked ? '' : ' hidden') },
        h('div', { class: 'grid5' },
          field('Status', status), field('Max passengers', pax), field('Max bags', bags), field('Free waiting (min)', wait), field('Min notice (hours)', notice)),
        h('p', { class: 'label', text: 'Zones served' }),
        h('div', { class: 'ticks' }, zoneBoxes,
          zones.length ? h('button', { class: 'link', type: 'button', onclick: function () { zoneBoxes.forEach(function (l) { l.firstChild.checked = true; }); }, text: 'All' }) : null),
        h('div', { class: 'atc-actions' }, h('button', { class: 'btn dark', type: 'button', onclick: save, text: 'Save ' + t.name })));
      offered.addEventListener('change', function () {
        body.classList.toggle('hidden', !offered.checked);
        if (!offered.checked && s) { status.value = 'paused'; save(); }
      });
      function save() {
        api('POST', '/providers/' + partyId + '/services', {
          airport_id: state.airportId, vehicle_type_id: t.id, status: offered.checked ? status.value : 'paused',
          max_passengers: pax.value, max_bags: bags.value, free_wait_minutes: wait.value,
          min_notice_minutes: Math.round(parseFloat(notice.value || '0') * 60),
          zone_ids: zoneBoxes.filter(function (l) { return l.firstChild.checked; }).map(function (l) { return parseInt(l.firstChild.value, 10); })
        }).then(function () { toast(t.name + ' saved.'); }).catch(fail);
      }
      add(card, h('div', { class: 'svc' },
        h('label', { class: 'svc-head' }, offered, h('strong', { text: t.name }), h('span', { class: 'atc-muted', text: 'up to ' + t.max_passengers + ' passengers · ' + t.max_bags + ' bags' })),
        body));
    });
    return card;
  }

  function vehiclesCard(partyId, vehicles) {
    var tbody = h('tbody');
    var types = state.boot.vehicle_types;
    vehicles.forEach(function (v) {
      add(tbody, h('tr', null,
        h('td', { class: 'strong', text: v.make_model + (v.year ? ' ' + v.year : '') }),
        h('td', { text: v.vehicle_type }), h('td', { text: v.plate || '' }), h('td', { text: v.seats || '' }), h('td', { text: v.insurance_until || '' }),
        h('td', null, h('button', { class: 'link danger', type: 'button', onclick: function () {
          if (!confirm('Remove this vehicle?')) return;
          api('DELETE', '/providers/' + partyId + '/vehicles/' + v.id).then(function () { toast('Removed.'); viewProvider(partyId); }).catch(fail);
        }, text: 'Remove' }))));
    });
    if (!vehicles.length) add(tbody, h('tr', null, h('td', { colspan: 6, class: 'atc-muted', text: 'No vehicles yet.' })));

    var f = {
      model: h('input', { type: 'text', placeholder: 'Toyota Sienna' }),
      year: h('input', { type: 'number', min: 1990, max: 2100, placeholder: '2024' }),
      type: h('select', null, types.map(function (t) { return h('option', { value: t.id, text: t.name }); })),
      plate: h('input', { type: 'text' }),
      seats: h('input', { type: 'number', min: 1, max: 60 }),
      ins: h('input', { type: 'date' })
    };
    var form = h('form', { class: 'grid6', onsubmit: function (e) {
      e.preventDefault();
      api('POST', '/providers/' + partyId + '/vehicles', { make_model: f.model.value, year: f.year.value, vehicle_type_id: f.type.value, plate: f.plate.value, seats: f.seats.value || null, insurance_until: f.ins.value })
        .then(function () { toast('Vehicle added.'); viewProvider(partyId); }).catch(fail);
    } },
      field('Make and model', f.model), field('Year', f.year), field('Category', f.type), field('Plate', f.plate), field('Seats', f.seats), field('Insurance until', f.ins),
      h('div', { class: 'atc-actions' }, h('button', { class: 'btn dark', type: 'submit', text: 'Add vehicle' })));

    return h('section', { class: 'card pad' },
      h('h2', { text: 'Vehicles' }),
      h('div', { class: 'scroll' }, h('table', null,
        h('thead', null, h('tr', null, ['Vehicle', 'Category', 'Plate', 'Seats', 'Insurance until', ''].map(function (t) { return h('th', { scope: 'col', text: t }); }))),
        tbody)),
      h('p', { class: 'label', text: 'Add a vehicle' }), form);
  }

  /* ---------- start ---------- */

  api('GET', '/bootstrap').then(function (boot) {
    state.boot = boot;
    var cun = boot.airports.find(function (a) { return a.iata === 'CUN'; }) || boot.airports[0];
    state.airportId = cun ? parseInt(cun.id, 10) : null;
    if (!state.airportId) { clear(app); add(app, h('p', { text: 'No airport set up yet.' })); return; }
    route();
  }).catch(function (e) {
    clear(app);
    add(app, h('p', { class: 'chip red', text: 'Could not load the control panel: ' + e.message }));
  });
})();
