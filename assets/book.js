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
    from.addEventListener('input', function () { fromCode.value = ''; });
    from.addEventListener('change', function () { from.setCustomValidity(fromCode.value || !from.value ? '' : 'Pick your airport from the list.'); });

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
      if (!fromCode.value) { e.preventDefault(); from.setCustomValidity('Pick your airport from the list.'); from.reportValidity(); from.setCustomValidity(''); return; }
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

  var cont = document.querySelector('[data-ab-continue]');
  document.querySelectorAll('[data-ab-offer]').forEach(function (card) {
    card.addEventListener('click', function (e) {
      if (e.target.closest('[data-ab-select]') && window.matchMedia('(max-width: 900px)').matches) return; // phones go straight on
      if (e.target.closest('[data-ab-select]')) e.preventDefault();
      document.querySelectorAll('[data-ab-offer]').forEach(function (c) {
        var on = c === card;
        c.classList.toggle('is-on', on);
        var s = c.querySelector('[data-ab-select]');
        s.classList.toggle('is-accent', on); s.classList.toggle('is-dark', !on);
        s.textContent = on ? 'Selected' : 'Select';
      });
      var n = document.querySelector('[data-ab-selname]'), p = document.querySelector('[data-ab-selprice]');
      if (n) n.textContent = card.getAttribute('data-name') + n.textContent.replace(/^[^,]*/, '');
      var by = document.querySelector('[data-ab-selby]'); if (by) by.textContent = 'by ' + card.getAttribute('data-provider');
      if (p) p.textContent = card.getAttribute('data-price');
      if (cont) { cont.href = card.getAttribute('data-href'); cont.focus(); }
    });
  });
  document.querySelectorAll('[data-ab-vt]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var vt = chip.getAttribute('data-ab-vt');
      document.querySelectorAll('[data-ab-vt]').forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
      document.querySelectorAll('[data-ab-offer]').forEach(function (card) { card.hidden = !!vt && card.getAttribute('data-vt') !== vt; });
    });
  });
  var first = document.querySelector('[data-ab-offer] [data-ab-select]');
  if (first && !window.matchMedia('(max-width: 900px)').matches) first.textContent = 'Selected';

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
      var svc = new libs[1].DirectionsService();
      svc.route({ origin: A, destination: B, travelMode: 'DRIVING' }).then(function (r) {
        new libs[1].DirectionsRenderer({ map: map, directions: r, suppressMarkers: true, preserveViewport: false, polylineOptions: { strokeColor: '#15171C', strokeWeight: 5 } });
        var leg = r.routes[0].legs[0];
        var txt = 'About ' + leg.duration.text + ' · ' + leg.distance.text;
        var d = document.querySelector('[data-ab-dist]'); if (d) d.textContent = txt;
        var t = document.querySelector('[data-ab-time]'); if (t) t.textContent = txt + ' by road';
      }).catch(function () {
        new google.maps.Polyline({ map: map, path: [A, B], strokeColor: '#15171C', strokeWeight: 3, strokeOpacity: 0.6 });
      });
    }).catch(function () {
      mapEl.classList.add('is-off');
      mapEl.textContent = 'Map not available right now.';
    });
  }
})();
