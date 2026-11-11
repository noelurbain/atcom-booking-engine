/* ATCOM Booking Engine · traveler search box, results page and trip map. */
(function () {
  'use strict';
  var C = window.ATCBook || {};

  /* ---------- Google Maps (Website key) ---------- */
  var mapsReady;
  function loadMaps() {
    if (mapsReady) return mapsReady;
    mapsReady = new Promise(function (resolve, reject) {
      if (!C.mapsKey) { reject(new Error('nokey')); return; }
      if (window.google && google.maps && google.maps.importLibrary) { resolve(); return; }
      window.abMapsReady = function () { resolve(); };
      var s = document.createElement('script');
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(C.mapsKey) + '&v=weekly&loading=async&callback=abMapsReady';
      s.async = true;
      s.onerror = function () { reject(new Error('maps')); };
      document.head.appendChild(s);
    });
    return mapsReady;
  }

  /* ---------- A small accessible suggestion list ---------- */
  function suggest(input, list, fetcher, onPick) {
    var items = [], active = -1, timer, seq = 0;
    function close() { list.hidden = true; list.textContent = ''; items = []; active = -1; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
    function mark(i) {
      var opts = list.querySelectorAll('[role=option]');
      if (!opts.length) return;
      active = (i + opts.length) % opts.length;
      for (var k = 0; k < opts.length; k++) opts[k].classList.toggle('is-active', k === active);
      input.setAttribute('aria-activedescendant', opts[active].id);
    }
    function show(rows, note) {
      list.textContent = ''; items = rows; active = -1;
      rows.forEach(function (r, i) {
        var li = document.createElement('li');
        li.id = list.id + '-' + i; li.setAttribute('role', 'option');
        var b = document.createElement('strong'); b.textContent = r.label; li.appendChild(b);
        if (r.sub) { var s = document.createElement('small'); s.textContent = r.sub; li.appendChild(s); }
        li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(i); });
        list.appendChild(li);
      });
      if (note) { var n = document.createElement('li'); n.className = 'ab-note'; n.textContent = note; list.appendChild(n); }
      list.hidden = !rows.length && !note;
      input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
    }
    function pick(i) { var r = items[i]; if (!r) return; close(); onPick(r); }
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var v = input.value.trim(), my = ++seq;
      if (v.length < 2) { close(); return; }
      timer = setTimeout(function () {
        fetcher(v).then(function (res) { if (my === seq) show(res.rows, res.note); }).catch(function () { if (my === seq) close(); });
      }, 220);
    });
    input.addEventListener('keydown', function (e) {
      if (list.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); mark(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); mark(active - 1); }
      else if (e.key === 'Enter' && active > -1) { e.preventDefault(); pick(active); }
      else if (e.key === 'Escape') { close(); }
    });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
  }

  /* ---------- Search box ---------- */
  function initBox(form) {
    var trip = form.querySelector('input[name=trip]');
    var ret = form.querySelector('.ab-return');
    var retInput = ret ? ret.querySelector('input') : null;
    form.querySelectorAll('[data-trip]').forEach(function (b) {
      b.addEventListener('click', function () {
        trip.value = b.getAttribute('data-trip');
        form.querySelectorAll('[data-trip]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        var round = trip.value === 'round_trip';
        if (ret) ret.hidden = !round;
        if (retInput) { retInput.disabled = !round; retInput.required = round; }
      });
    });

    // Airport: only airports we serve.
    var from = form.querySelector('[data-ab-airport]');
    var fromCode = form.querySelector('input[name=airport]');
    var center = (form.getAttribute('data-center') || '').split(',');
    var country = form.getAttribute('data-country') || '';
    suggest(from, from.parentNode.querySelector('.ab-list'), function (v) {
      return fetch(C.airports + (C.airports.indexOf('?') > -1 ? '&' : '?') + 'local=1&q=' + encodeURIComponent(v)).then(function (r) { return r.json(); }).then(function (d) {
        return { rows: (d.results || []).map(function (a) { return { label: a.label, sub: a.sub, id: a.id, iata: a.iata }; }), note: (d.results || []).length ? '' : 'We don’t serve that airport yet.' };
      });
    }, function (a) {
      from.value = a.label; fromCode.value = a.iata || a.id; from.setCustomValidity('');
      center = []; country = '';
    });
    // Typing again clears any old warning; the results page finds a typed airport anyway.
    from.addEventListener('input', function () { fromCode.value = ''; from.setCustomValidity(''); });

    // Destination: Google places near the airport; typing without picking still works (looked up on the next page).
    var to = form.querySelector('[data-ab-to]');
    var lat = form.querySelector('input[name=lat]'), lng = form.querySelector('input[name=lng]'), pid = form.querySelector('input[name=pid]');
    var token = null;
    to.addEventListener('input', function () { lat.value = ''; lng.value = ''; pid.value = ''; });
    suggest(to, to.parentNode.querySelector('.ab-list'), function (v) {
      return loadMaps().then(function () { return google.maps.importLibrary('places'); }).then(function (lib) {
        if (!token) token = new lib.AutocompleteSessionToken();
        var req = { input: v, sessionToken: token };
        if (center.length === 2 && center[0]) req.locationBias = { center: { lat: +center[0], lng: +center[1] }, radius: 50000 };
        if (country) req.includedRegionCodes = [country];
        return lib.AutocompleteSuggestion.fetchAutocompleteSuggestions(req).then(function (r) {
          return { rows: (r.suggestions || []).filter(function (s) { return s.placePrediction; }).slice(0, 6).map(function (s) {
            var p = s.placePrediction;
            return { label: p.mainText ? p.mainText.text : p.text.text, sub: p.secondaryText ? p.secondaryText.text : '', pred: p };
          }), note: 'Can’t find it? Type the full address.' };
        });
      });
    }, function (r) {
      to.value = r.label + (r.sub ? ', ' + r.sub : '');
      var place = r.pred.toPlace();
      place.fetchFields({ fields: ['location', 'id'] }).then(function () {
        lat.value = place.location.lat(); lng.value = place.location.lng(); pid.value = place.id;
        token = null;
      }).catch(function () {});
    });

    // Passengers: Adults and Children.
    var btn = form.querySelector('[data-ab-paxbtn]'), pop = form.querySelector('.ab-paxpop');
    var label = btn.querySelector('span');
    var count = { adults: form.querySelector('input[name=adults]'), children: form.querySelector('input[name=children]') };
    function paint() {
      var a = +count.adults.value, c = +count.children.value, t = a + c;
      label.textContent = t + (t === 1 ? ' passenger' : ' passengers') + (c ? ' (' + c + (c === 1 ? ' child)' : ' children)') : '');
      form.querySelector('[data-ab-out=adults]').textContent = a;
      form.querySelector('[data-ab-out=children]').textContent = c;
    }
    function toggle(open) { pop.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); if (open) pop.querySelector('button').focus(); }
    btn.addEventListener('click', function () { toggle(pop.hidden); });
    pop.querySelectorAll('[data-ab-step]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = b.getAttribute('data-ab-step').split(':'), f = count[p[0]];
        var v = +f.value + +p[1];
        f.value = Math.max(p[0] === 'adults' ? 1 : 0, Math.min(p[0] === 'adults' ? 60 : 40, v));
        paint();
      });
    });
    form.querySelector('[data-ab-paxdone]').addEventListener('click', function () { toggle(false); btn.focus(); });
    document.addEventListener('click', function (e) { if (!pop.hidden && !pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) toggle(false); });
    pop.addEventListener('keydown', function (e) { if (e.key === 'Escape') { toggle(false); btn.focus(); } });
    // The first time the field is used, open the picker so Children is seen.
    paint();

    form.addEventListener('submit', function (e) {
      // Typed but not picked: send the text, the results page finds the airport.
      if (!fromCode.value) fromCode.value = from.value.trim();
      if (!lat.value) { lat.disabled = lng.disabled = pid.disabled = true; }
      if (+count.children.value === 0) count.children.disabled = true;
    });
  }

  document.querySelectorAll('[data-ab-search]').forEach(initBox);

  /* ---------- Results page ---------- */
  var editBox = document.querySelector('[data-ab-editbox]');
  document.querySelectorAll('[data-ab-edit]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!editBox) return;
      editBox.hidden = !editBox.hidden;
      b.setAttribute('aria-expanded', editBox.hidden ? 'false' : 'true');
      if (!editBox.hidden) { editBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); var i = editBox.querySelector('[data-ab-to]'); if (i) i.focus(); }
    });
  });

  // Select goes straight to step 2; a click anywhere on the card does the same.
  document.querySelectorAll('[data-ab-offer]').forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.closest('a, button')) return;
      window.location.href = card.getAttribute('data-href');
    });
  });
  document.querySelectorAll('[data-ab-vt]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var vt = chip.getAttribute('data-ab-vt');
      document.querySelectorAll('[data-ab-vt]').forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
      document.querySelectorAll('[data-ab-offer]').forEach(function (card) { card.hidden = !!vt && card.getAttribute('data-vt') !== vt; });
    });
  });

  /* ---------- Trip map ---------- */
  var mapEl = document.querySelector('[data-ab-map]');
  if (mapEl) {
    var a = mapEl.getAttribute('data-from').split(','), b = mapEl.getAttribute('data-to').split(',');
    var A = { lat: +a[0], lng: +a[1] }, B = { lat: +b[0], lng: +b[1] };
    loadMaps().then(function () {
      return Promise.all([google.maps.importLibrary('maps'), google.maps.importLibrary('routes'), google.maps.importLibrary('marker')]);
    }).then(function (libs) {
      var map = new libs[0].Map(mapEl, { center: A, zoom: 10, disableDefaultUI: true, zoomControl: true, gestureHandling: 'cooperative' });
      var bounds = new google.maps.LatLngBounds(); bounds.extend(A); bounds.extend(B); map.fitBounds(bounds, 40);
      new google.maps.Marker({ map: map, position: A, title: mapEl.getAttribute('data-from-label'), label: { text: '✈', color: '#FFFFFF' } });
      new google.maps.Marker({ map: map, position: B, title: mapEl.getAttribute('data-to-label') });
      function say(mins, km) {
        var txt = 'About ' + (mins >= 60 ? Math.floor(mins / 60) + ' h ' + (mins % 60) + ' min' : mins + ' min') + ' · ' + km + ' km';
        var d = document.querySelector('[data-ab-dist]'); if (d) d.textContent = txt;
      }
      function straight() { new google.maps.Polyline({ map: map, path: [A, B], strokeColor: '#15171C', strokeWeight: 3, strokeOpacity: 0.6 }); }
      // Routes API (new Google projects), then the older Directions API, then a straight line.
      function legacy() {
        if (!libs[1].DirectionsService) { straight(); return; }
        new libs[1].DirectionsService().route({ origin: A, destination: B, travelMode: 'DRIVING' }).then(function (r) {
          new libs[1].DirectionsRenderer({ map: map, directions: r, suppressMarkers: true, polylineOptions: { strokeColor: '#15171C', strokeWeight: 5 } });
          var leg = r.routes[0].legs[0];
          say(Math.round(leg.duration.value / 60), Math.round(leg.distance.value / 1000));
        }).catch(straight);
      }
      if (libs[1].Route && libs[1].Route.computeRoutes) {
        libs[1].Route.computeRoutes({ origin: A, destination: B, travelMode: 'DRIVING', fields: ['path', 'distanceMeters', 'durationMillis'] }).then(function (r) {
          var route = r.routes && r.routes[0];
          if (!route) throw new Error('none');
          route.createPolylines().forEach(function (l) { l.setOptions({ strokeColor: '#15171C', strokeWeight: 5 }); l.setMap(map); });
          say(Math.round(route.durationMillis / 60000), Math.round(route.distanceMeters / 1000));
        }).catch(legacy);
      } else {
        legacy();
      }
    }).catch(function () {
      mapEl.classList.add('is-off');
      mapEl.textContent = 'Map not available right now.';
    });
  }

  /* ---------- Shared bits for steps 2 and 3 ---------- */
  function post(url, body) {
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) { var e = new Error(d.message || 'Something went wrong. Please try again.'); e.data = d; throw e; } return d; }); });
  }
  function showError(box, msg) {
    if (!box) return;
    box.textContent = msg || ''; box.hidden = !msg;
    if (msg) { box.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  }
  document.querySelectorAll('[data-ab-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

  /* ---------- Step 2: contact, flight and trip details ---------- */
  var det = document.querySelector('[data-ab-checkout]');
  if (det) {
    var cfg = JSON.parse(det.getAttribute('data-ab-checkout'));
    var err = det.querySelector('[data-ab-error]');
    var f = function (n) { return det.elements[n]; };
    var starting = null;

    // Country code: a searchable list built from the select (which stays the form field).
    var ccSel = det.querySelector('select[name=phone_code]');
    if (ccSel) countryPicker(ccSel);

    // Steppers for suitcases and carry-ons.
    det.querySelectorAll('[data-ab-step]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = b.getAttribute('data-ab-step').split(':'), input = f(p[0]);
        input.value = Math.max(0, Math.min(60, +input.value + +p[1]));
        det.querySelector('[data-ab-out=' + p[0] + ']').textContent = input.value;
      });
    });

    // No flight yet: the flight number is not needed.
    var nf = det.querySelector('[data-ab-noflight]');
    function flightState() {
      var off = nf && nf.checked;
      f('flight').required = !off; f('airline').disabled = f('flight').disabled = off;
      det.querySelector('[data-ab-flight]').classList.toggle('is-off', !!off);
    }
    if (nf) { nf.addEventListener('change', flightState); flightState(); }

    // Round trip: suggest the hotel pickup from the flight time (3 h international, 2 h domestic).
    var rtime = det.querySelector('[data-ab-rtime]'), rpick = det.querySelector('[data-ab-rpick]'), dom = det.querySelector('[data-ab-domestic]');
    var touched = rpick && !!rpick.value;
    function suggestPickup() {
      if (!rtime || !rtime.value || touched) return;
      var t = rtime.value.split(':'), mins = (+t[0] * 60 + +t[1]) - (dom && dom.checked ? 120 : 180);
      if (mins < 0) mins += 1440; // the night before
      rpick.value = ('0' + Math.floor(mins / 60)).slice(-2) + ':' + ('0' + (mins % 60)).slice(-2);
      var note = det.querySelector('[data-ab-suggest-note]');
      if (note) note.textContent = 'Suggested: ' + (dom && dom.checked ? '2 hours before a domestic flight' : '3 hours before an international flight') + ', including the drive. You can change it.';
    }
    if (rpick) {
      rpick.addEventListener('input', function () { touched = !!rpick.value; });
      rtime.addEventListener('change', function () { touched = false; suggestPickup(); });
      if (dom) dom.addEventListener('change', function () { touched = false; suggestPickup(); });
    }

    function contact() {
      return { first_name: f('first_name').value.trim(), last_name: f('last_name').value.trim(), email: f('email').value.trim(), phone_code: f('phone_code').value, phone: f('phone').value.trim(), whatsapp: f('whatsapp').checked ? 1 : 0 };
    }
    function contactReady() {
      var c = contact();
      return c.first_name && c.last_name && /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(c.email) && c.phone.replace(/\D/g, '').length >= 6;
    }
    // As soon as the contact details are in, the booking exists (so we can help if the traveler leaves).
    function start() {
      if (cfg.number) return Promise.resolve();
      if (starting) return starting;
      var body = contact(); body.search = cfg.search; body.service = cfg.service;
      starting = post(cfg.start, body).then(function (d) {
        cfg.number = d.number; cfg.key = d.key;
        try { history.replaceState(null, '', location.pathname + '?b=' + encodeURIComponent(d.number) + '&k=' + encodeURIComponent(d.key)); } catch (e) {}
      }).catch(function (e) { starting = null; throw e; });
      return starting;
    }
    ['first_name', 'last_name', 'email', 'phone'].forEach(function (n) {
      f(n).addEventListener('change', function () { if (contactReady()) start().catch(function () {}); });
    });

    det.addEventListener('submit', function (e) {
      e.preventDefault();
      showError(err, '');
      // The browser's own checks first, on the fields that are on.
      var bad = Array.prototype.find.call(det.elements, function (el) { return el.willValidate && !el.checkValidity(); });
      if (bad) { bad.reportValidity ? bad.reportValidity() : bad.focus(); return; }
      if (!contactReady()) { showError(err, 'Please check your name, email and mobile number.'); return; }
      var btns = document.querySelectorAll('[form=ab-details], [data-ab-submit]');
      btns.forEach(function (b) { b.disabled = true; });
      start().then(function () {
        var body = contact();
        Array.prototype.forEach.call(det.elements, function (el) {
          if (!el.name || el.disabled || body[el.name] !== undefined) return;
          body[el.name] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
        });
        body.number = cfg.number; body.key = cfg.key;
        return post(cfg.details, body);
      }).then(function (d) {
        window.location.href = d.pay_url;
      }).catch(function (x) {
        btns.forEach(function (b) { b.disabled = false; });
        showError(err, x.message);
      });
    });
  }

  /* ---------- Step 3: Stripe payment ---------- */
  var payForm = document.querySelector('[data-ab-pay]');
  var P = window.ATCPay;
  if (payForm && P) {
    var perr = payForm.querySelector('[data-ab-error]');
    var payBtn = payForm.querySelector('[data-ab-submit]');
    var agree = payForm.querySelector('[data-ab-agree]');
    var holdEl = payForm.querySelector('[data-ab-hold]');
    var stripe, elements, ready = false, expired = false;

    // The price is held for 15 minutes; after that, reload to hold it again.
    var ends = Date.now() + P.left * 1000;
    var tick = setInterval(function () {
      var s = Math.max(0, Math.round((ends - Date.now()) / 1000));
      if (holdEl) holdEl.textContent = s ? 'Price held for ' + Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2) : '';
      if (!s) {
        clearInterval(tick); expired = true;
        payBtn.disabled = true;
        showError(perr, '');
        var box = document.createElement('div');
        box.className = 'ab-alert is-info'; box.setAttribute('role', 'status');
        box.innerHTML = 'Your 15-minute hold ended. <a href="#">Hold the price again</a>';
        box.querySelector('a').addEventListener('click', function (e) { e.preventDefault(); window.location.href = P.reload; });
        payBtn.parentNode.insertBefore(box, payBtn);
      }
    }, 1000);

    function canPay() { payBtn.disabled = !(ready && !expired); }

    function mount() {
      stripe = window.Stripe(P.pk);
      var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches && document.documentElement.getAttribute('data-theme') !== 'light';
      elements = stripe.elements({
        clientSecret: P.secret,
        appearance: { theme: dark ? 'night' : 'stripe', variables: { colorPrimary: '#15171C', borderRadius: '10px', fontFamily: 'inherit' } }
      });
      var el = payForm.querySelector('[data-ab-element]');
      el.textContent = '';
      var pe = elements.create('payment', { layout: 'tabs', defaultValues: { billingDetails: { name: P.name, email: P.email } } });
      pe.on('ready', function () { ready = true; canPay(); });
      pe.mount(el);
    }
    if (window.Stripe) mount(); else window.addEventListener('load', function () {
      if (window.Stripe) mount(); else showError(perr, 'The payment form could not load. Check your connection and refresh the page.');
    });

    payForm.addEventListener('submit', function (e) {
      e.preventDefault();
      showError(perr, '');
      if (!agree.checked) { agree.focus(); showError(perr, 'Please agree to the reservation policies and the privacy policy to book.'); return; }
      if (!ready || expired) return;
      payBtn.disabled = true;
      var label = payBtn.textContent; payBtn.textContent = 'Paying…';
      stripe.confirmPayment({
        elements: elements,
        confirmParams: { return_url: P.return, payment_method_data: { billing_details: { name: P.name, email: P.email, phone: P.phone } } }
      }).then(function (r) {
        // Only reached when the payment failed or needs another try; success leaves the page.
        if (r.error) { showError(perr, r.error.message || 'The payment did not go through. You were not charged.'); }
        payBtn.textContent = label; canPay();
      });
    });
  }

  /* ---------- Policies in a pop-up, so the traveler stays on the payment page ---------- */
  var dlg = null, lastLink = null, cache = {};
  function policyDialog() {
    if (dlg) return dlg;
    dlg = document.createElement('dialog');
    dlg.className = 'ab-dialog';
    dlg.setAttribute('aria-labelledby', 'ab-dlg-title');
    dlg.innerHTML = '<div class="ab-dlghead"><h2 id="ab-dlg-title"></h2><button type="button" class="ab-dlgx" aria-label="Close">✕</button></div><div class="ab-dlgbody" tabindex="0"></div><div class="ab-dlgfoot"><a target="_blank" rel="noopener">Open in a new tab ↗</a><button type="button" class="ab-btn is-dark">Close</button></div>';
    dlg.querySelectorAll('.ab-dlgx, .ab-dlgfoot button').forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', function () { if (lastLink) lastLink.focus(); });
    document.body.appendChild(dlg);
    return dlg;
  }
  document.querySelectorAll('[data-ab-policy]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (!C.pages || !window.HTMLDialogElement || e.metaKey || e.ctrlKey || e.shiftKey) return; // new tab as usual
      e.preventDefault();
      lastLink = a;
      var d = policyDialog(), slug = a.getAttribute('data-ab-policy');
      var title = d.querySelector('h2'), body = d.querySelector('.ab-dlgbody');
      title.textContent = a.textContent.charAt(0).toUpperCase() + a.textContent.slice(1);
      d.querySelector('.ab-dlgfoot a').href = a.href;
      body.textContent = 'Loading…';
      d.showModal();
      var p = cache[slug] || (cache[slug] = fetch(C.pages + (C.pages.indexOf('?') > -1 ? '&' : '?') + 'slug=' + encodeURIComponent(slug) + '&_fields=title,content').then(function (r) { return r.json(); }));
      p.then(function (list) {
        var page = list && list[0];
        if (!page) throw new Error('none');
        title.innerHTML = page.title.rendered; // our own page title
        body.innerHTML = page.content.rendered; // our own page, written by our team
        body.scrollTop = 0;
      }).catch(function () {
        delete cache[slug];
        body.textContent = 'This page could not load here. ';
        var l = document.createElement('a'); l.href = a.href; l.target = '_blank'; l.rel = 'noopener'; l.textContent = 'Open it in a new tab.';
        body.appendChild(l);
      });
    });
  });

  /* ---------- Searchable country code ---------- */
  function flag(iso) {
    return iso && iso.length === 2 ? String.fromCodePoint(0x1F1E6 + iso.charCodeAt(0) - 65, 0x1F1E6 + iso.charCodeAt(1) - 65) : '';
  }
  function fold(t) { return t.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function countryPicker(sel) {
    var rows = [];
    Array.prototype.forEach.call(sel.options, function (o) {
      var iso = o.value.split('|')[1] || '', code = o.value.split('|')[0];
      var top = o.parentNode && o.parentNode.label === 'Most used';
      var name = o.textContent.replace(/\s*\+\d+$/, '');
      rows.push({ value: o.value, iso: iso, code: code, name: name, top: top, key: fold(name + ' ' + code + ' ' + code.replace('+', '') + ' ' + iso) });
    });
    var wrap = document.createElement('div'); wrap.className = 'ab-cc';
    var uid = 'abcc' + Math.floor(Math.random() * 1e6);
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'ab-ccbtn'; btn.setAttribute('aria-haspopup', 'dialog'); btn.setAttribute('aria-expanded', 'false');
    var pop = document.createElement('div'); pop.className = 'ab-ccpop'; pop.hidden = true; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Country code');
    var q = document.createElement('input'); q.type = 'search'; q.placeholder = 'Search a country or code'; q.setAttribute('aria-label', 'Search a country or code'); q.setAttribute('role', 'combobox'); q.setAttribute('aria-autocomplete', 'list'); q.setAttribute('aria-expanded', 'true'); q.setAttribute('aria-controls', uid); q.autocomplete = 'off';
    var ul = document.createElement('ul'); ul.id = uid; ul.setAttribute('role', 'listbox'); ul.setAttribute('aria-label', 'Countries');
    pop.appendChild(q); pop.appendChild(ul);
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(btn); wrap.appendChild(pop); wrap.appendChild(sel);
    sel.hidden = true; sel.tabIndex = -1;
    var shown = [], active = -1;
    function paint() {
      var r = rows.find(function (x) { return x.value === sel.value; }) || rows[0];
      btn.innerHTML = '';
      var f = document.createElement('span'); f.className = 'ab-ccflag'; f.setAttribute('aria-hidden', 'true'); f.textContent = flag(r.iso);
      var c = document.createElement('span'); c.textContent = r.code;
      var v = document.createElement('span'); v.setAttribute('aria-hidden', 'true'); v.className = 'ab-ccv'; v.textContent = '▾';
      btn.appendChild(f); btn.appendChild(c); btn.appendChild(v);
      btn.setAttribute('aria-label', 'Country code: ' + r.name + ' ' + r.code + '. Change');
    }
    function list() {
      var t = fold(q.value.trim());
      var dup = {};
      shown = t ? rows.filter(function (r) { if (dup[r.value] || r.key.indexOf(t) < 0) return false; dup[r.value] = true; return true; }) : rows;
      // Names that start with what was typed come first.
      if (t) shown.sort(function (a, b) { return (fold(a.name).indexOf(t) === 0 ? 0 : 1) - (fold(b.name).indexOf(t) === 0 ? 0 : 1); });
      ul.innerHTML = ''; active = -1;
      var lastTop = null;
      shown.forEach(function (r, i) {
        if (!t && lastTop === true && !r.top) { var sep = document.createElement('li'); sep.className = 'ab-ccsep'; sep.setAttribute('role', 'presentation'); sep.textContent = 'All countries'; ul.appendChild(sep); }
        lastTop = r.top;
        var li = document.createElement('li'); li.id = uid + '-' + i; li.setAttribute('role', 'option'); li.setAttribute('aria-selected', r.value === sel.value ? 'true' : 'false');
        var f = document.createElement('span'); f.className = 'ab-ccflag'; f.setAttribute('aria-hidden', 'true'); f.textContent = flag(r.iso);
        var n = document.createElement('span'); n.className = 'ab-ccname'; n.textContent = r.name;
        var c = document.createElement('span'); c.className = 'ab-muted'; c.textContent = r.code;
        li.appendChild(f); li.appendChild(n); li.appendChild(c);
        li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(r); });
        ul.appendChild(li);
      });
      if (!shown.length) { var none = document.createElement('li'); none.className = 'ab-ccsep'; none.textContent = 'No country matches.'; ul.appendChild(none); }
    }
    function mark(i) {
      var opts = ul.querySelectorAll('[role=option]'); if (!opts.length) return;
      active = (i + opts.length) % opts.length;
      opts.forEach(function (o, k) { o.classList.toggle('is-active', k === active); });
      opts[active].scrollIntoView({ block: 'nearest' });
      q.setAttribute('aria-activedescendant', opts[active].id);
    }
    function open() { pop.hidden = false; btn.setAttribute('aria-expanded', 'true'); q.value = ''; list(); q.focus(); var on = ul.querySelector('[aria-selected=true]'); if (on) on.scrollIntoView({ block: 'center' }); }
    function close(back) { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); q.removeAttribute('aria-activedescendant'); if (back) btn.focus(); }
    function pick(r) { sel.value = r.value; sel.dispatchEvent(new Event('change', { bubbles: true })); paint(); close(true); }
    btn.addEventListener('click', function () { if (pop.hidden) open(); else close(true); });
    q.addEventListener('input', list);
    q.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); mark(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); mark(active - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); var r = shown[active > -1 ? active : 0]; if (r && (active > -1 || q.value.trim())) pick(r); }
      else if (e.key === 'Escape') { e.preventDefault(); close(true); }
      else if (e.key === 'Tab') { close(false); }
    });
    document.addEventListener('mousedown', function (e) { if (!pop.hidden && !wrap.contains(e.target)) close(false); });
    paint();
  }
})();
