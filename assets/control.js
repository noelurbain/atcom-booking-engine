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
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-go]');
    if (a) { e.preventDefault(); go(a.getAttribute('data-go')); }
  });
  document.querySelectorAll('.atc-nav a[data-view]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); go(a.getAttribute('data-view')); });
  });

  function route() {
    var parts = path().split('/');
    document.querySelectorAll('.atc-nav a[data-view]').forEach(function (a) {
      a.classList.toggle('on', a.getAttribute('data-view') === (parts[0] || 'zones'));
    });
    if (parts[0] === 'providers' && parts[1]) return viewProvider(parseInt(parts[1], 10));
    if (parts[0] === 'providers') return viewProviders();
    if (parts[0] === 'import') return viewImport();
    return viewZones();
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
            r.prices_held ? h('span', { class: 'chip orange', text: r.prices_held + ' prices left out (wrong currency or typo)' }) : null),
          r.issues.length ? h('details', null, h('summary', { class: 'strong', text: r.issues.length + ' things to look at' }), h('ul', null, r.issues.map(function (i) { return h('li', { class: 'small', text: i }); }))) : null,
          dry ? h('div', { class: 'atc-actions' }, h('button', { class: 'btn dark', type: 'button', onclick: function () { run(false); }, text: 'Looks right: import now' })) : h('div', { class: 'atc-actions' }, h('a', { class: 'btn', href: C.base + 'providers/', 'data-go': 'providers', text: 'See the providers' }))));
      }).catch(fail);
    }
    add(app, [
      h('a', { class: 'back', href: C.base + 'providers/', 'data-go': 'providers', text: '← Providers' }),
      h('div', { class: 'atc-head' }, h('div', null, h('h1', { text: 'Import from the old site' }), h('p', { class: 'atc-muted', text: 'Providers, the airports they serve, their coverage areas (as zones) and their prices. Travelers keep paying the same; providers get 75% under the new 25% commission. Safe to run again: nothing is doubled, and shapes you drew here are never replaced.' }))),
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
        pricesCard(d.prices),
        servicesCard(id, d.services, zones),
        vehiclesCard(id, d.vehicles)
      ]);
    }).catch(fail);
  }

  function pricesCard(tables) {
    var card = h('section', { class: 'card pad' }, h('h2', { text: 'Prices' }),
      h('p', { class: 'atc-muted small', text: 'Suggested retail price (what travelers pay) · Amount the provider gets paid. One way, then round trip. Editing comes in the next version.' }));
    if (!tables.length) { add(card, h('p', { class: 'atc-muted', text: 'No prices yet.' })); return card; }
    tables.forEach(function (t) { add(card, priceTable(t)); });
    return card;
  }
  function usd(c) { return (c / 100).toFixed(c % 100 ? 2 : 0); }
  function priceTable(t) {
    return h('div', null, h('p', { class: 'label', text: t.airport + (t.iata ? ' (' + t.iata + ')' : '') + ' · ' + t.zones.length + ' zones' }),
      h('div', { class: 'scroll' }, h('table', { class: 'prices' },
        h('thead', null, h('tr', null, h('th', { scope: 'col', text: 'Zone' }), t.vehicles.map(function (v) { return h('th', { scope: 'col', text: v.name + (v.status !== 'live' ? ' (' + v.status + ')' : '') }); }))),
        h('tbody', null, t.zones.map(function (z) {
          return h('tr', null, h('td', { class: 'strong', text: z.name }), t.vehicles.map(function (v) {
            var c = z.cells[v.id];
            if (!c) return h('td', { class: 'atc-muted', text: '–' });
            var ow = c.one_way, rt = c.round_trip;
            return h('td', null,
              ow ? h('div', null, h('strong', { text: 'USD ' + usd(ow.retail) }), h('span', { class: 'atc-muted small', text: ' · ' + usd(ow.provider) })) : null,
              rt ? h('div', { class: 'small' }, 'RT ' + usd(rt.retail), h('span', { class: 'atc-muted', text: ' · ' + usd(rt.provider) })) : null);
          }));
        })))));
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
