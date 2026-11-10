/* ATCOM Booking Engine · price editor and price-list upload (provider portal and control panel). */
(function () {
  'use strict';

  var SHEETJS = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';

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
  function dollars(c) { return c === null || c === undefined ? '' : (c / 100).toFixed(c % 100 ? 2 : 0); }
  function cents(v) {
    v = String(v).trim().replace(/[^0-9.,]/g, '');
    if (v === '') return null;
    if (v.indexOf(',') > -1 && v.indexOf('.') > -1) v = v.lastIndexOf(',') > v.lastIndexOf('.') ? v.replace(/\./g, '').replace(',', '.') : v.replace(/,/g, '');
    else if (v.indexOf(',') > -1) v = /,\d{3}$/.test(v) ? v.replace(/,/g, '') : v.replace(',', '.');
    var n = parseFloat(v);
    return isNaN(n) ? null : Math.round(n * 100);
  }
  function providerFromRetail(r, bp) { return Math.floor(r * (10000 - bp) / 10000); }
  function retailFromProvider(p, bp) { return Math.ceil(Math.round(p * 10000 / (10000 - bp) * 1e6) / 1e6 / 100) * 100; }

  var sheetLib;
  function loadSheetJS() {
    if (window.XLSX) return Promise.resolve(window.XLSX);
    if (sheetLib) return sheetLib;
    sheetLib = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = SHEETJS; s.onload = function () { resolve(window.XLSX); };
      s.onerror = function () { sheetLib = null; reject(new Error('The spreadsheet reader did not load. Paste the table instead, or save it as CSV.')); };
      document.head.appendChild(s);
    });
    return sheetLib;
  }
  function readFile(file) {
    return loadSheetJS().then(function (X) {
      return file.arrayBuffer().then(function (buf) {
        var wb = X.read(buf, { type: 'array' });
        var ws = wb.Sheets[wb.SheetNames[0]];
        return X.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '' });
      });
    });
  }
  function readPaste(text) {
    var tab = text.indexOf('\t') > -1;
    return text.split(/\r?\n/).map(function (line) {
      if (tab) return line.split('\t');
      // CSV with quotes
      var out = [], f = '', q = false;
      for (var i = 0; i < line.length; i++) {
        var c = line[i];
        if (q) { if (c === '"') { if (line[i + 1] === '"') { f += '"'; i++; } else q = false; } else f += c; }
        else if (c === '"') q = true;
        else if (c === ',' || c === ';') { out.push(f); f = ''; }
        else f += c;
      }
      out.push(f);
      return out;
    });
  }

  /**
   * opts: api(method, path, body) → Promise; base: REST path for this provider's prices;
   * who: 'provider' | 'staff'; toast(msg, bad); fail(err).
   */
  function mount(root, opts) {
    var who = opts.who || 'provider';
    var basis = who === 'provider' ? 'provider' : 'retail';
    var g = null;           // grid data
    var edits = {};          // key zone:vehicle:dir → cents typed (or '' to clear)
    var extraZones = [], extraVehicles = [];
    var gridBox = h('div');
    var uploadBox = h('div');

    function load(airportId) {
      clear(gridBox); add(gridBox, h('p', { class: 'atc-muted', text: 'Loading prices…' }));
      return opts.api('GET', opts.base + (airportId ? '?airport_id=' + airportId : '')).then(function (d) { g = d; edits = {}; extraZones = []; extraVehicles = []; render(); }).catch(opts.fail);
    }

    function cell(zid, vid, dir) {
      var c = g.cells[zid + ':' + vid];
      return c && c[dir] ? c[dir] : null;
    }

    function render() {
      clear(root);
      if (!g || !g.airport) { add(root, h('p', { class: 'atc-muted', text: 'Add your airport and the zones you serve first.' })); return; }
      var bp = g.commission_bp;
      var zones = g.zones.filter(function (z) { return z.served || z.status === 'proposed' || extraZones.indexOf(z.id) > -1; });
      var types = g.vehicle_types.filter(function (t) { return t.offered || extraVehicles.indexOf(t.id) > -1 || zones.some(function (z) { return g.cells[z.id + ':' + t.id]; }); });

      var airportSel = g.airports.length > 1 ? h('select', { 'aria-label': 'Airport', onchange: function () { load(+airportSel.value); } },
        g.airports.map(function (a) { return h('option', { value: a.id, selected: a.id === g.airport.id, text: a.label }); })) : h('strong', { text: g.airport.label });

      var basisSel = h('select', { 'aria-label': 'What you type', onchange: function () { basis = basisSel.value; edits = {}; render(); } },
        h('option', { value: 'provider', selected: basis === 'provider', text: who === 'provider' ? 'What I get paid' : 'What the provider gets paid' }),
        h('option', { value: 'retail', selected: basis === 'retail', text: 'What travelers pay (Suggested retail price)' }));

      var zoneAdd = h('select', { 'aria-label': 'Add a zone', onchange: function () { if (zoneAdd.value) { extraZones.push(+zoneAdd.value); render(); } } },
        h('option', { value: '', text: '+ Add a zone' }),
        g.zones.filter(function (z) { return zones.indexOf(z) === -1; }).map(function (z) { return h('option', { value: z.id, text: z.name }); }));
      var vehAdd = h('select', { 'aria-label': 'Add a vehicle category', onchange: function () { if (vehAdd.value) { extraVehicles.push(+vehAdd.value); render(); } } },
        h('option', { value: '', text: '+ Add a vehicle category' }),
        g.vehicle_types.filter(function (t) { return types.indexOf(t) === -1; }).map(function (t) { return h('option', { value: t.id, text: t.name + ' (up to ' + t.max_passengers + ')' }); }));

      function input(z, t, dir) {
        var key = z.id + ':' + t.id + ':' + dir;
        var c = cell(z.id, t.id, dir);
        var shown = key in edits ? edits[key] : (c ? c[basis] : null);
        var other = h('small', { class: 'atc-muted pe-other' });
        function hint(v) {
          if (v === null || v === '') { other.textContent = ''; return; }
          var o = basis === 'retail' ? providerFromRetail(v, bp) : retailFromProvider(v, bp);
          other.textContent = (basis === 'retail' ? 'paid ' : 'sells ') + dollars(o);
        }
        var el = h('input', { type: 'text', inputmode: 'decimal', class: 'pe-in' + (key in edits ? ' changed' : ''), value: dollars(shown), placeholder: '–',
          'aria-label': z.name + ', ' + t.name + ', ' + (dir === 'one_way' ? 'one way' : 'round trip') });
        el.addEventListener('input', function () {
          var v = cents(el.value);
          var orig = c ? c[basis] : null;
          if (el.value.trim() === '') { if (c) edits[key] = ''; else delete edits[key]; }
          else if (v !== null && v !== orig) edits[key] = v;
          else delete edits[key];
          el.classList.toggle('changed', key in edits);
          hint(v);
          count();
        });
        hint(shown);
        return h('label', { class: 'pe-cell' }, h('span', { class: 'pe-dir', text: dir === 'one_way' ? 'One way' : 'Round trip' }), el, other);
      }

      var saveBtn = h('button', { class: 'btn dark', type: 'button', text: 'Save prices', disabled: true, onclick: save });
      var changes = h('span', { class: 'small atc-muted' });
      function count() {
        var n = Object.keys(edits).length;
        saveBtn.disabled = !n;
        changes.textContent = n ? n + ' change' + (n > 1 ? 's' : '') + ' not saved' : '';
      }

      add(root, [
        h('section', { class: 'card pad' },
          h('div', { class: 'pe-bar' },
            h('div', { class: 'atc-actions' }, airportSel),
            h('label', { class: 'atc-inline' }, h('span', { text: 'I type' }), basisSel)),
          h('p', { class: 'small atc-muted', text: 'All prices in US dollars, per vehicle (not per person). Our commission is ' + (bp / 100) + '% of what travelers pay; the other amount fills in by itself. Leave a box empty where you do not go.' }),
          zones.length && types.length ? h('div', { class: 'scroll' }, h('table', { class: 'pe' },
            h('thead', null, h('tr', null, h('th', { scope: 'col', text: 'Zone' }), types.map(function (t) { return h('th', { scope: 'col' }, h('div', { text: t.name }), h('small', { class: 'atc-muted', text: 'up to ' + t.max_passengers + ' · ' + (t.offered ? (t.offered === 'live' ? 'live' : t.offered) : 'new') })); }))),
            h('tbody', null, zones.map(function (z) {
              return h('tr', null,
                h('th', { scope: 'row' }, h('div', { text: z.name }), z.status === 'proposed' ? h('span', { class: 'chip orange', text: 'Zone pending: our team approves it' }) : (!z.shaped ? h('span', { class: 'chip grey', text: 'Shape coming' }) : null)),
                types.map(function (t) { return h('td', null, input(z, t, 'one_way'), input(z, t, 'round_trip')); }));
            })))) : h('p', { class: 'atc-muted', text: 'Add the zones you serve and a vehicle category to start.' }),
          h('div', { class: 'atc-actions' }, zoneAdd, vehAdd),
          h('div', { class: 'atc-actions' }, saveBtn, changes)),
        uploadBox
      ]);
      renderUpload();
    }

    function save() {
      var cells = Object.keys(edits).map(function (k) {
        var p = k.split(':'), v = edits[k], c = { zone_id: +p[0], vehicle_type_id: +p[1], direction: p[2] };
        if (v === '') c.clear = true; else c[basis] = v;
        return c;
      });
      opts.api('POST', opts.base, { airport_id: g.airport.id, cells: cells }).then(function (r) {
        g = r.grid; edits = {}; extraZones = []; extraVehicles = []; render();
        opts.toast((r.saved ? r.saved + ' prices saved' : 'Saved') + (r.cleared ? ', ' + r.cleared + ' removed' : '') + '.');
        if (r.flagged && r.flagged.length) showFlags(r.flagged);
        if (opts.onSaved) opts.onSaved();
      }).catch(opts.fail);
    }

    function showFlags(list) {
      add(root, h('section', { class: 'card pad warn' }, h('h2', { text: 'Check these' }), h('ul', null, list.map(function (t) { return h('li', { class: 'small', text: t }); }))));
    }

    /* ----- upload ----- */

    function renderUpload(result) {
      clear(uploadBox);
      var file = h('input', { type: 'file', accept: '.xlsx,.xls,.csv,.ods,text/csv' });
      var paste = h('textarea', { rows: 4, placeholder: 'Or paste the table here (copy it from Excel or Google Sheets)' });
      var go = h('button', { class: 'btn dark', type: 'button', text: 'Read my price list' });
      var status = h('p', { class: 'small atc-muted', role: 'status' });
      go.addEventListener('click', function () {
        var p = file.files.length ? readFile(file.files[0]) : (paste.value.trim() ? Promise.resolve(readPaste(paste.value)) : Promise.reject(new Error('Choose a file or paste your table first.')));
        go.disabled = true; status.textContent = 'Reading and matching… places we do not know are looked up on Google.';
        p.then(function (rows) {
          return opts.api('POST', opts.base + '/preview', { airport_id: g.airport.id, rows: rows.slice(0, 2000) });
        }).then(function (pv) { renderPreview(pv); }).catch(function (e) { status.textContent = ''; opts.fail(e); }).then(function () { go.disabled = false; });
      });
      add(uploadBox, h('section', { class: 'card pad' },
        h('h2', { text: 'Upload your price list' }),
        h('p', { class: 'small atc-muted', text: 'Excel, CSV or Google Sheets, in your own words: one row per place, with a column per vehicle (and "round trip" columns if you have them), or columns for place, vehicle, one way and round trip. We match your vehicles to our categories and your place names to our zones, and remember your names for next time. Travelers see our zone names.' }),
        result || null,
        h('div', { class: 'pe-up' }, file, paste),
        h('div', { class: 'atc-actions' }, go), status));
    }

    function renderPreview(pv) {
      clear(uploadBox);
      var placeSel = {}, vehSel = {}, found = {};
      var zoneOpts = function (cur) {
        return [h('option', { value: 'skip', text: 'Leave out' }), h('option', { value: 'new', selected: cur === 'new', text: 'New zone (our team approves it)' })]
          .concat(pv.zones.map(function (z) { return h('option', { value: z.id, selected: cur === z.id, text: z.name + (z.status === 'proposed' ? ' (pending)' : '') }); }));
      };
      var places = pv.places.map(function (p) {
        var cur = p.zone_id ? p.zone_id : (p.place ? 'new' : 'skip');
        if (p.place) found[p.name] = p.place;
        var s = h('select', { 'aria-label': 'Our zone for ' + p.name }, zoneOpts(cur));
        placeSel[p.name] = s;
        var how = p.zone_id ? p.how : (p.place ? 'Found on the map ' + (p.km ? p.km + ' km from the airport' : '') + ', outside our zones' : p.how || 'Not found');
        return h('tr', null, h('td', { class: 'strong', text: p.name }), h('td', null, s), h('td', { class: 'small atc-muted', text: how + ' · ' + p.prices + ' prices' }));
      });
      var vehicles = pv.vehicles.map(function (v) {
        var s = h('select', { 'aria-label': 'Our category for ' + (v.name || 'unnamed') },
          [h('option', { value: 'skip', text: v.vehicle_type_id ? 'Leave out' : 'Pick our category…' })].concat(pv.vehicle_types.map(function (t) { return h('option', { value: t.id, selected: +t.id === v.vehicle_type_id, text: t.name + ' (up to ' + t.max_passengers + ')' }); })));
        vehSel[v.name] = s;
        return h('tr', null, h('td', { class: 'strong', text: v.name || '(no vehicle named)' }), h('td', null, s), h('td', { class: 'small atc-muted', text: v.how || 'Pick one' }));
      });
      var basisSel = h('select', { 'aria-label': 'What the amounts are' },
        h('option', { value: 'provider', selected: who === 'provider', text: who === 'provider' ? 'What I get paid' : 'What the provider gets paid' }),
        h('option', { value: 'retail', selected: who !== 'provider', text: 'What travelers pay' }));
      var saveBtn = h('button', { class: 'btn dark', type: 'button', text: 'Save ' + pv.lines.length + ' prices' });
      saveBtn.addEventListener('click', function () {
        var p = {}, v = {};
        Object.keys(placeSel).forEach(function (k) { p[k] = placeSel[k].value; });
        Object.keys(vehSel).forEach(function (k) { v[k] = vehSel[k].value; });
        saveBtn.disabled = true;
        opts.api('POST', opts.base + '/apply', { airport_id: g.airport.id, lines: pv.lines, places: p, vehicles: v, basis: basisSel.value, found: found }).then(function (r) {
          g = r.grid; edits = {}; render();
          var msg = r.saved + ' prices saved.' + (r.new_zones.length ? ' New zones waiting for our team: ' + r.new_zones.join(', ') + '. Their prices count once approved.' : '');
          renderUpload(h('p', { class: 'chip green', text: msg }));
          if (r.flagged && r.flagged.length) showFlags(r.flagged);
          opts.toast(r.saved + ' prices saved.');
          if (opts.onSaved) opts.onSaved();
        }).catch(opts.fail).then(function () { saveBtn.disabled = false; });
      });
      add(uploadBox, h('section', { class: 'card pad' },
        h('h2', { text: 'Check how we read it' }),
        h('p', { class: 'small atc-muted', text: pv.lines.length + ' prices found. Change any match that is wrong; nothing is saved until you click Save.' }),
        h('label', { class: 'atc-inline' }, h('span', { text: 'The amounts in my list are' }), basisSel),
        h('h3', { class: 'label', text: 'Your places → our zones' }),
        h('div', { class: 'scroll' }, h('table', { class: 'narrow' }, h('tbody', null, places))),
        h('h3', { class: 'label', text: 'Your vehicles → our categories' }),
        h('div', { class: 'scroll' }, h('table', { class: 'narrow' }, h('tbody', null, vehicles))),
        h('div', { class: 'atc-actions' }, saveBtn, h('button', { class: 'btn', type: 'button', text: 'Start over', onclick: function () { renderUpload(); } }))));
      uploadBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    load(opts.airportId || 0);
    return { reload: load };
  }

  window.ATCPrices = { mount: mount };
})();
