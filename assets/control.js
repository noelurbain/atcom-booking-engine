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
    if (parts[0] === 'providers' && parts[1]) return viewProvider(parseInt(parts[1], 10));
    if (parts[0] === 'providers') return viewProviders();
    if (parts[0] === 'import') return viewImport();
    if (parts[0] === 'zones') return viewZones();
    if (parts[0] === 'meeting') return viewMeeting();
    if (parts[0] === 'bookings' && parts[1]) return viewBooking(parts[1]);
    if (parts[0] === 'bookings') return viewBookings();
    return viewDashboard();
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
        h('div', null, h('h1', { text: 'Bookings' }), h('p', { class: 'atc-muted', text: 'Every booking, newest first. Pick-up times in the airport\'s local time.' }))),
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
          h('p', { class: 'small atc-muted', text: b.legacy ? 'This trip is run from the old site: change the provider, the trip or refunds there.' : 'Changing the provider, editing the trip, refunds and resending the voucher come with checkout.' })),
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
                l.notes ? h('p', { class: 'note', text: 'Note from the traveler: ' + l.notes }) : null);
            }),
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
                h('div', null, h('span', { text: 'Affiliate' }), h('strong', { text: b.source.affiliate || 'none' })),
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
    api('GET', '/control/meeting?airport_id=' + state.airportId).then(function (d) {
      clear(body);
      add(body, h('section', { class: 'card pad' },
        h('h2', { text: 'Our steps for this airport' }),
        h('p', { class: 'small atc-muted', text: d.ours.length ? 'Shown to every traveler whose provider has not written their own.' : 'Nothing written yet: travelers see the general steps below until you save.' }),
        window.ATCMeeting.editor({
          sets: d.ours, startFrom: d.fallback, saveLabel: 'Save for this airport',
          onSave: function (sets) { return api('POST', '/control/meeting', { airport_id: state.airportId, sets: sets }).then(function (r) { toast('Saved. Travelers see these steps from now on.'); return r; }).catch(function (e) { fail(e); throw e; }); }
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
            r.prices_held ? h('span', { class: 'chip orange', text: r.prices_held + ' prices left out (wrong currency or typo)' }) : null),
          r.issues.length ? h('details', null, h('summary', { class: 'strong', text: r.issues.length + ' things to look at' }), h('ul', null, r.issues.map(function (i) { return h('li', { class: 'small', text: i }); }))) : null,
          dry ? h('div', { class: 'atc-actions' }, h('button', { class: 'btn dark', type: 'button', onclick: function () { run(false); }, text: 'Looks right: import now' })) : h('div', { class: 'atc-actions' }, h('a', { class: 'btn', href: C.base + 'providers/', 'data-go': 'providers', text: 'See the providers' }))));
      }).catch(fail);
    }
    add(app, [
      h('a', { class: 'back', href: C.base + 'providers/', 'data-go': 'providers', text: '← Providers' }),
      h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Import from the old site' }), h('p', { class: 'atc-muted', text: 'Providers, the airports they serve, their coverage areas (as zones), their prices and the old bookings (without traveler names or contacts). Travelers keep paying the same; providers get 75% under the new 25% commission. Safe to run again: nothing is doubled, and shapes you drew here are never replaced.' }))),
      h('section', { class: 'card pad' },
        h('label', { class: 'field' }, h('span', { text: 'Import file (atcom-migration.json)' }), file),
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

  function viewProvider(id) {
    clear(app);
    add(app, h('p', { class: 'atc-muted', text: 'Loading…' }));
    Promise.all([api('GET', '/providers/' + id), api('GET', '/zones?airport_id=' + state.airportId)]).then(function (res) {
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
              if (!confirm('Set ' + p.name + ' live? Their offered vehicle categories become bookable and they get an email.')) return;
              api('PUT', '/providers/' + id, { status: 'live' }).then(function () { toast('Live.'); viewProvider(id); }).catch(fail);
            }, text: 'Approve and set live' }) : null)),
        h('div', { class: 'card pad' }, h('div', { class: 'row-flat' }, h('strong', { text: 'Portal' }), portalCell({ id: id, name: p.name, email: p.email, portal: d.portal }, function () { viewProvider(id); }))),
        setupCard(d),
        p.about ? h('section', { class: 'card pad' }, h('h2', { text: 'About the company (from sign-up)' }), h('p', { class: 'prewrap', text: p.about })) : null,
        documentsCard(id, d),
        form,
        h('h2', { class: 'pe-title', text: 'Prices' }),
        h('div', { class: 'pe-root', id: 'atc-prices' }),
        servicesCard(id, d.services, zones),
        vehiclesCard(id, d.vehicles)
      ]);
      window.ATCPrices.mount(document.getElementById('atc-prices'), { api: api, base: '/providers/' + id + '/prices', who: 'staff', toast: toast, fail: fail });
    }).catch(fail);
  }

  function setupCard(d) {
    var keys = ['company', 'vehicles', 'coverage', 'documents', 'payout', 'agreement', 'approval'];
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
        return h('label', { class: 'tick' }, cb, z.name);
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
