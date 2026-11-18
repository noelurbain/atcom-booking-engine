/* ATCOM Booking Engine · affiliate panel (/portal for affiliates). Plain JS; server text always goes in with textContent. */
(function () {
  'use strict';

  var C = window.ATC || {};
  var app = document.getElementById('atc-app');
  var side = document.getElementById('atc-side');
  var me = null;

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
  function api(method, path, body) {
    var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
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
  function field(label, input, help) { return h('label', { class: 'field' }, h('span', { text: label }), input, help ? h('small', { text: help }) : null); }
  function money(c) { return 'USD ' + (c / 100).toFixed(2); }
  function pct(bp) { return (bp / 100).toString().replace(/\.0+$/, '') + '%'; }
  function head(title, sub) { return h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: title }), sub ? h('p', { class: 'atc-muted', text: sub }) : null)); }
  function stat(label, value, sub) { return h('div', { class: 'card pad stat' }, h('span', { class: 'small atc-muted strong', text: label }), h('strong', { class: 'big', text: value }), sub ? h('span', { class: 'small atc-muted', text: sub }) : null); }
  function copyBox(value, label) {
    var input = h('input', { type: 'text', value: value, readonly: true, onclick: function () { input.select(); } });
    var btn = h('button', { class: 'btn dark', type: 'button', text: label || 'Copy', onclick: function () {
      (navigator.clipboard ? navigator.clipboard.writeText(value) : Promise.reject()).then(function () { toast('Copied.'); }).catch(function () { input.select(); document.execCommand('copy'); toast('Copied.'); });
    } });
    return h('div', { class: 'copyrow' }, input, btn);
  }

  /* ---------- routing ---------- */

  var VIEWS = [['', 'Overview'], ['links', 'Links'], ['coupons', 'Discount codes'], ['book', 'Book for a client'], ['bookings', 'Bookings'], ['referrals', 'Invite affiliates'], ['payouts', 'Payouts'], ['account', 'Account']];
  function path() {
    var base = new URL(C.base).pathname;
    var p = location.pathname.indexOf(base) === 0 ? location.pathname.slice(base.length) : '';
    return p.replace(/^\/+|\/+$/g, '');
  }
  function go(p) { history.pushState({}, '', C.base + p + (p ? '/' : '')); route(); window.scrollTo(0, 0); }
  window.addEventListener('popstate', route);
  document.addEventListener('click', function (e) { var a = e.target.closest('a[data-go]'); if (a) { e.preventDefault(); go(a.getAttribute('data-go')); } });
  function nav() {
    clear(side);
    var cur = path();
    VIEWS.forEach(function (v) { add(side, h('a', { href: C.base + v[0] + (v[0] ? '/' : ''), 'data-go': v[0], class: cur === v[0] ? 'on' : '' }, h('span', { text: v[1] }))); });
  }
  function route() {
    if (!me) return;
    nav(); clear(app);
    ({ '': viewHome, links: viewLinks, coupons: viewCoupons, book: viewBook, bookings: viewBookings, referrals: viewReferrals, payouts: viewPayouts, account: viewAccount }[path()] || viewHome)();
  }

  /* ---------- Overview ---------- */

  function viewHome() {
    var m = me.month, all = me.all;
    add(app, [
      head('Hi ' + (me.contact || me.name), 'You earn ' + pct(me.rate) + ' of every completed booking made through your links, for ' + me.days + ' days after each click.'),
      me.status === 'paused' ? h('p', { class: 'chip orange', text: 'Your account is paused: your links still work for travelers, but new bookings don’t earn commission. Write to ' + me.support + '.' }) : null,
      !me.payout.set ? h('a', { class: 'card pad warn row', href: C.base + 'payouts/', 'data-go': 'payouts' }, h('span', { text: 'Add your payout details so we can pay your commissions.' }), h('span', { class: 'chip orange', text: 'Add' })) : null,
      h('section', { class: 'card pad' }, h('h2', { text: 'Your link' }), copyBox(me.link, 'Copy link'), h('p', { class: 'small atc-muted', text: 'Make a link to any page of our site under Links.' })),
      h('h2', { class: 'section', text: 'This month' }),
      h('div', { class: 'stats' },
        stat('Clicks', String(m.clicks)),
        stat('Bookings', String(m.bookings), m.clicks ? m.conversion + '% of clicks' : null),
        stat('Sales', money(m.sales)),
        stat('Commission waiting', money(all.pending), 'until the trips are completed'),
        stat('Earned, to be paid', money(all.earned), 'paid on the ' + me.pay_day + 'th from ' + money(me.minimum)),
        stat('Paid so far', money(all.paid))),
      chartCard()
    ]);
  }

  function chartCard() {
    var card = h('section', { class: 'card pad' }, h('h2', { text: 'Clicks, last 30 days' }), h('p', { class: 'atc-muted', text: 'Loading…' }));
    api('GET', '/clicks').then(function (d) {
      clear(card); add(card, h('h2', { text: 'Clicks, last 30 days' }));
      var max = Math.max.apply(null, d.days.map(function (x) { return x.clicks; }).concat([1]));
      add(card, h('div', { class: 'bars', role: 'img', 'aria-label': 'Clicks per day' }, d.days.map(function (x) {
        return h('span', { title: x.day + ': ' + x.clicks, style: 'height:' + Math.max(2, Math.round(100 * x.clicks / max)) + '%' });
      })));
      if (d.pages.length) add(card, [h('h3', { text: 'Pages people landed on' }), h('table', { class: 'bare' }, h('tbody', null, d.pages.map(function (p) { return h('tr', null, h('td', { text: p.page }), h('td', { class: 'num', text: String(p.clicks) })); })))]);
    }).catch(function () { clear(card); });
    return card;
  }

  /* ---------- Links ---------- */

  function viewLinks() {
    var url = h('input', { type: 'url', placeholder: me.home + 'airports/cun/' });
    var out = h('div');
    var btn = h('button', { class: 'btn dark', type: 'submit', text: 'Make link' });
    add(app, [
      head('Links', 'Any page of our site works: airports, destinations, routes, blog posts. Bookings within ' + me.days + ' days of a click are yours (the last click counts).'),
      h('section', { class: 'card pad' }, h('h2', { text: 'Your main link' }), copyBox(me.link), h('p', { class: 'small atc-muted' }, 'Or add ', h('code', { text: '?ref=' + me.code }), ' to the end of any address on our site.')),
      h('form', { class: 'card pad', onsubmit: function (e) {
        e.preventDefault(); btn.disabled = true;
        api('POST', '/link', { url: url.value }).then(function (d) { clear(out); add(out, copyBox(d.link)); }).catch(fail).then(function () { btn.disabled = false; });
      } }, h('h2', { text: 'Link to a page' }), field('Paste the page address', url), h('div', { class: 'atc-actions' }, btn), out),
      h('section', { class: 'card pad' }, h('h2', { text: 'Booking widget' }), h('p', { class: 'atc-muted', text: 'A booking box for your own site is coming soon. Until then, link to any of our airport or destination pages.' }))
    ]);
  }

  /* ---------- Discount codes ---------- */

  function viewCoupons() {
    var max = me.rate / 100;
    var code = h('input', { type: 'text', maxlength: 30, style: 'text-transform:uppercase', placeholder: 'e.g. ' + (me.name || 'MYSITE').replace(/[^A-Za-z0-9]/g, '').slice(0, 10).toUpperCase() + '5' });
    var pct = h('input', { type: 'number', min: 1, max: max, step: '0.5', value: Math.min(5, max), style: 'max-width:120px' });
    var left = h('strong');
    function sync() { var p = +pct.value || 0; left.textContent = 'With a ' + p + '% code you keep ' + Math.max(0, max - p) + '% of each booking.'; }
    pct.addEventListener('input', sync); sync();
    var btn = h('button', { class: 'btn dark', type: 'submit', text: 'Create code' });
    var list = h('section', { class: 'card pad' }, h('p', { class: 'atc-muted', text: 'Loading…' }));
    add(app, [
      head('Discount codes', 'Give your audience a discount of up to ' + max + '%. It comes out of your commission, and every booking with your code is yours, even without a click on your link.'),
      h('form', { class: 'card pad', onsubmit: function (e) {
        e.preventDefault(); btn.disabled = true;
        api('POST', '/coupons', { code: code.value, percent: pct.value }).then(function () { toast('Code created.'); route(); }).catch(function (x) { btn.disabled = false; fail(x); });
      } }, h('h2', { text: 'New code' }), h('div', { class: 'grid2' }, field('Code', code), field('Discount (%)', pct)), h('p', null, left), h('div', { class: 'atc-actions' }, btn)),
      list
    ]);
    api('GET', '/coupons').then(function (rows) {
      clear(list); add(list, h('h2', { text: 'Your codes' }));
      if (!rows.length) { add(list, h('p', { class: 'atc-muted', text: 'No codes yet.' })); return; }
      add(list, h('table', { class: 'bare' }, h('tbody', null, rows.map(function (c) {
        return h('tr', null, h('td', null, h('code', { class: 'strong', text: c.code }), h('div', { class: 'small atc-muted', text: c.percent + '% off · used ' + c.uses + ' times' })),
          h('td', null, h('span', { class: 'chip ' + (c.state === 'Active' ? 'green' : 'grey'), text: c.state }), ' ',
            h('button', { class: 'link', type: 'button', text: c.status === 'active' ? 'Turn off' : 'Turn on', onclick: function () { api('POST', '/coupons/' + c.id, { status: c.status === 'active' ? 'off' : 'active', percent: c.percent }).then(function () { route(); }).catch(fail); } })));
      }))));
    }).catch(fail);
  }

  /* ---------- Book for a client ---------- */

  function viewBook() {
    add(app, [
      head('Book for a client', 'Book on our site as a traveler would, with your client’s name, email and phone. The booking is yours: you earn ' + pct(me.rate) + ' when the trip is completed.'),
      h('section', { class: 'card pad' },
        h('ol', { class: 'steps-list' },
          h('li', { text: 'Open the booking site (you stay signed in) and search the trip.' }),
          h('li', { text: 'Choose the vehicle and fill in your client’s details, flight and hotel.' }),
          h('li', { text: 'On the payment step, either pay with your client’s card now, or press “Send the payment link to the client”: they get an email and pay themselves.' }),
          h('li', { text: 'Your client gets the confirmation, the voucher and the driver’s details by email. You see the booking here under Bookings.' })),
        h('div', { class: 'atc-actions' }, h('a', { class: 'btn dark', href: me.book_url, target: '_blank', rel: 'noopener', text: 'Open the booking site ↗' })))
    ]);
  }

  /* ---------- Bookings ---------- */

  var page = 1;
  function viewBookings() {
    add(app, head('Bookings', 'Bookings made through your links, by you for a client, and by affiliates you invited. Commission becomes yours once the trip is completed.'));
    var box = h('section', { class: 'card pad' }, h('p', { class: 'atc-muted', text: 'Loading…' }));
    add(app, box);
    api('GET', '/bookings?page=' + page).then(function (d) {
      clear(box);
      if (!d.rows.length) { add(box, h('p', { class: 'atc-muted', text: 'No bookings yet. Share your link to start.' })); return; }
      add(box, h('div', { class: 'table-wrap' }, h('table', { class: 'list' },
        h('thead', null, h('tr', null, ['Booked', 'Reservation', 'Trip', 'Traveler', 'Status', 'Total', 'Your commission'].map(function (t) { return h('th', { text: t }); }))),
        h('tbody', null, d.rows.map(function (r) {
          return h('tr', null, h('td', { text: r.booked }), h('td', null, h('strong', { text: r.number }), r.for_client ? h('div', { class: 'small atc-muted', text: 'Booked by you' }) : null, r.referral ? h('div', { class: 'small atc-muted', text: 'Referral' }) : null),
            h('td', null, h('div', { text: r.where }), h('div', { class: 'small atc-muted', text: r.trip })), h('td', { text: r.traveler }),
            h('td', null, h('span', { class: 'chip ' + ({ confirmed: 'blue', completed: 'green', cancelled: 'grey' }[r.status] || 'grey'), text: r.status })),
            h('td', { class: 'num', text: money(r.total) }), h('td', { class: 'num' }, h('strong', { text: money(r.commission) }), h('div', { class: 'small atc-muted', text: r.cstatus })));
        })))));
      if (d.pages > 1) add(box, h('div', { class: 'atc-actions' },
        page > 1 ? h('button', { class: 'btn', type: 'button', text: '← Newer', onclick: function () { page--; route(); } }) : null,
        h('span', { class: 'small atc-muted', text: 'Page ' + d.page + ' of ' + d.pages }),
        page < d.pages ? h('button', { class: 'btn', type: 'button', text: 'Older →', onclick: function () { page++; route(); } }) : null));
    }).catch(fail);
  }

  /* ---------- Referrals ---------- */

  function viewReferrals() {
    add(app, [
      head('Invite affiliates', 'Invite other sites, agencies or creators. When we approve them, you earn ' + pct(me.ref_rate) + ' of their completed bookings, on top of their own commission.'),
      h('section', { class: 'card pad' }, h('h2', { text: 'Your invitation link' }), copyBox(me.invite), h('p', { class: 'small atc-muted', text: 'It opens our affiliate sign-up page. Applications sent within ' + me.days + ' days of the click count as invited by you.' })),
      h('section', { class: 'card pad' }, h('h2', { text: 'Who you invited' }),
        me.referred.length ? h('table', { class: 'bare' }, h('tbody', null, me.referred.map(function (r) {
          return h('tr', null, h('td', { text: r.name }), h('td', { text: 'since ' + r.since }), h('td', null, h('span', { class: 'chip ' + ({ live: 'green', pending: 'orange' }[r.status] || 'grey'), text: r.status === 'live' ? 'approved' : r.status })));
        }))) : h('p', { class: 'atc-muted', text: 'Nobody yet.' }))
    ]);
  }

  /* ---------- Payouts ---------- */

  function viewPayouts() {
    var p = me.payout;
    var method = h('select', null, [['paypal', 'PayPal'], ['wise', 'Wise'], ['bank', 'Bank transfer']].map(function (o) { return h('option', { value: o[0], selected: p.set ? p.method === o[0] : o[0] === 'paypal', text: o[1] }); }));
    var currency = h('select', null, h('option', { value: 'USD', selected: p.currency !== 'MXN', text: 'USD' }), h('option', { value: 'MXN', selected: p.currency === 'MXN', text: 'MXN (Mexican bank)' }));
    var holder = h('input', { type: 'text', value: p.holder || '', autocomplete: 'off' });
    var bank = h('input', { type: 'text', value: p.bank || '' });
    var account = h('input', { type: 'text', autocomplete: 'off', placeholder: p.set && p.last4 ? 'ending ' + p.last4 + ' (type again to change)' : 'Account number or IBAN' });
    var email = h('input', { type: 'email', value: p.email || '' });
    var bankRow = h('div', { class: 'grid2' }, field('Bank', bank), field('Account number, IBAN or CLABE', account));
    var emailRow = h('div', { class: 'grid2' }, field('Email of the account', email));
    function sync() { var b = method.value === 'bank'; bankRow.hidden = !b; emailRow.hidden = b; }
    method.addEventListener('change', sync); sync();
    var btn = h('button', { class: 'btn dark', type: 'submit', text: 'Save payout details' });
    var list = h('section', { class: 'card pad' }, h('h2', { text: 'Payouts' }),
      me.payouts.length ? h('table', { class: 'bare' }, h('tbody', null, me.payouts.map(function (o) {
        return h('tr', null, h('td', { text: o.period }), h('td', { class: 'num', text: money(o.amount) }), h('td', null, h('span', { class: 'chip ' + (o.status === 'paid' ? 'green' : 'blue'), text: o.status === 'paid' ? 'Paid ' + o.paid_at : 'Approved' })), h('td', { class: 'small atc-muted', text: o.reference }));
      }))) : h('p', { class: 'atc-muted', text: 'No payouts yet.' }));
    add(app, [
      head('Payouts', 'We pay on the ' + me.pay_day + 'th of each month the commissions of trips completed the month before, once you have at least ' + money(me.minimum) + '. Smaller balances carry over.'),
      p.set ? h('p', { class: 'chip green', text: 'Saved: ' + (p.method === 'bank' ? (p.bank ? p.bank + ' · ' : '') + 'ending ' + p.last4 : (p.method === 'paypal' ? 'PayPal' : 'Wise') + ' · ' + p.email) }) : null,
      h('form', { class: 'card pad', autocomplete: 'off', onsubmit: function (e) {
        e.preventDefault();
        if (method.value === 'bank' && !account.value && p.set) { toast('Type the full account number again to change your details.', true); return; }
        btn.disabled = true;
        api('POST', '/payout', { method: method.value, currency: currency.value, holder: holder.value, bank: bank.value, account: account.value, email: email.value })
          .then(function (d) { me.payout = d; toast('Payout details saved.'); route(); }).catch(fail).then(function () { btn.disabled = false; });
      } },
        h('h2', { text: 'Payout details' }),
        h('div', { class: 'grid2' }, field('How we pay you', method), field('Currency', currency)),
        h('div', { class: 'grid2' }, field('Account holder name', holder)),
        bankRow, emailRow,
        h('p', { class: 'small atc-muted', text: 'Stored encrypted. After saving, we only show the last 4 digits.' }),
        h('div', { class: 'atc-actions' }, btn)),
      list
    ]);
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
      head('Account', 'How you sign in.'),
      h('form', { class: 'card pad', onsubmit: function (e) {
        e.preventDefault(); eBtn.disabled = true;
        api('POST', '/account/email', { email: newEmail.value, current: pwE.value })
          .then(function () { toast('Check ' + newEmail.value + ' and click the link to confirm.'); }).catch(fail).then(function () { eBtn.disabled = false; });
      } },
        h('h2', { text: 'Sign-in email' }),
        h('p', null, 'You sign in with ', h('strong', { text: a.email }), '.'),
        h('div', { class: 'grid2' }, field('New sign-in email', newEmail), field('Your current password', pwE)),
        h('div', { class: 'atc-actions' }, eBtn)),
      h('form', { class: 'card pad', onsubmit: function (e) {
        e.preventDefault(); pBtn.disabled = true;
        api('POST', '/account/password', { current: cur.value, new: nw.value })
          .then(function () { cur.value = ''; nw.value = ''; toast('Password changed.'); }).catch(fail).then(function () { pBtn.disabled = false; });
      } },
        h('h2', { text: 'Password' }),
        h('div', { class: 'grid2' }, field('Current password', cur), field('New password (at least 10 characters)', nw)),
        h('div', { class: 'atc-actions' }, pBtn)),
      h('p', null, h('a', { class: 'btn', href: C.logout, text: 'Sign out' }))
    ]);
  }

  api('GET', '/me').then(function (d) { me = d; route(); }).catch(function (e) {
    clear(app); add(app, h('p', { class: 'chip red', text: 'Could not load your panel: ' + e.message }));
  });
})();
