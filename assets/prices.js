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
    var g = null;            // grid data
    var dir = 'one_way';     // which direction the grid shows
    var sel = null;          // {zone, type} being edited
    var onlyGaps = false;
    var extraZones = [], extraVehicles = [];
    var uploadBox = h('div');
    var uploadOpen = false;

    function load(airportId) {
      clear(root); add(root, h('p', { class: 'atc-muted', text: 'Loading prices…' }));
      return opts.api('GET', opts.base + (airportId ? '?airport_id=' + airportId : '')).then(function (d) { g = d; sel = null; extraZones = []; extraVehicles = []; render(); }).catch(opts.fail);
    }

    function cell(zid, vid, d) {
      var c = g.cells[zid + ':' + vid];
      return c && c[d || dir] ? c[d || dir] : null;
    }
    function shownZones() { return g.zones.filter(function (z) { return z.served || z.status === 'proposed' || extraZones.indexOf(z.id) > -1; }); }
    function shownTypes(zones) { return g.vehicle_types.filter(function (t) { return t.offered || extraVehicles.indexOf(t.id) > -1 || zones.some(function (z) { return g.cells[z.id + ':' + t.id]; }); }); }

    function render() {
      clear(root);
      if (!g || !g.airport) { add(root, h('p', { class: 'atc-muted', text: 'Add your airport and the zones you serve first.' })); return; }
      var bp = g.commission_bp;
      var zones = shownZones();
      var types = shownTypes(zones);
      var gaps = 0;
      zones.forEach(function (z) { types.forEach(function (t) { if (!cell(z.id, t.id)) gaps++; }); });
      var rows = onlyGaps ? zones.filter(function (z) { return types.some(function (t) { return !cell(z.id, t.id); }); }) : zones;
      if (!sel && zones.length && types.length) sel = { zone: zones[0], type: types[0] };

      var pill = function (d, label) {
        return h('button', { type: 'button', class: 'pe-pill' + (dir === d ? ' on' : ''), 'aria-pressed': dir === d ? 'true' : 'false', text: label, onclick: function () { dir = d; render(); } });
      };
      var airportSel = g.airports.length > 1 ? h('select', { 'aria-label': 'Airport', onchange: function () { load(+airportSel.value); } },
        g.airports.map(function (a) { return h('option', { value: a.id, selected: a.id === g.airport.id, text: a.label }); })) : null;

      var zoneAdd = h('select', { 'aria-label': 'Add a zone', onchange: function () { if (zoneAdd.value) { var z = g.zones.filter(function (x) { return x.id === +zoneAdd.value; })[0]; extraZones.push(z.id); sel = { zone: z, type: sel ? sel.type : types[0] }; render(); } } },
        h('option', { value: '', text: '+ Add a zone' }),
        g.zones.filter(function (z) { return zones.indexOf(z) === -1; }).map(function (z) { return h('option', { value: z.id, text: z.name }); }));
      var vehAdd = h('select', { 'aria-label': 'Add a vehicle category', onchange: function () { if (vehAdd.value) { var t = g.vehicle_types.filter(function (x) { return x.id === +vehAdd.value; })[0]; extraVehicles.push(t.id); sel = { zone: sel ? sel.zone : zones[0], type: t }; render(); } } },
        h('option', { value: '', text: '+ Add a vehicle category' }),
        g.vehicle_types.filter(function (t) { return types.indexOf(t) === -1; }).map(function (t) { return h('option', { value: t.id, text: t.name + ' (up to ' + t.max_passengers + ')' }); }));

      var table = zones.length && types.length ? h('div', { class: 'pe-grid' }, h('table', { class: 'pe' },
        h('thead', null, h('tr', null, h('th', { scope: 'col', text: 'Zone' }), types.map(function (t) { return h('th', { scope: 'col' }, h('div', { class: 'strong', text: t.name }), h('small', { class: 'atc-muted', text: 'up to ' + t.max_passengers + (t.offered && t.offered !== 'live' ? ' · ' + t.offered : '') })); }))),
        h('tbody', null, rows.map(function (z) {
          return h('tr', null,
            h('th', { scope: 'row' }, h('div', { text: z.name }), z.status === 'proposed' ? h('span', { class: 'chip orange', text: 'Zone pending' }) : (!z.shaped ? h('span', { class: 'chip grey', text: 'Shape coming' }) : null)),
            types.map(function (t) {
              var c = cell(z.id, t.id);
              var on = sel && sel.zone.id === z.id && sel.type.id === t.id;
              return h('td', null, h('button', { type: 'button', class: 'pe-c' + (on ? ' on' : '') + (c ? '' : ' empty'), 'aria-label': z.name + ', ' + t.name + (c ? ', USD ' + dollars(c.retail) : ', no price'),
                onclick: function () { sel = { zone: z, type: t }; render(); } },
                h('span', { class: 'pe-r', text: c ? 'USD ' + dollars(c.retail) : 'Add price' }),
                h('span', { class: 'pe-p', text: c ? (who === 'provider' ? 'you get ' : 'provider gets ') + dollars(c.provider) : 'not offered' })));
            }));
        })))) : h('p', { class: 'atc-muted pad-x', text: 'Add the zones you serve and a vehicle category to start.' });

      add(root, [
        h('div', { class: 'atc-head' },
          h('div', null,
            h(opts.h1 ? 'h1' : 'h2', { class: 'pe-h', text: 'Prices · ' + g.airport.label }),
            h('p', { class: 'atc-muted', text: 'Price per vehicle, not per person, in US dollars. Commission ' + (bp / 100) + '% of the Suggested retail price.' })),
          h('div', { class: 'atc-actions' },
            airportSel,
            h('button', { class: 'btn', type: 'button', text: 'Download Excel', onclick: download }),
            h('button', { class: 'btn', type: 'button', text: 'Upload Excel or CSV', onclick: function () { uploadOpen = true; renderUpload(); uploadBox.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }))),
        h('div', { class: 'pe-bar' },
          h('div', { class: 'pe-pills', role: 'group', 'aria-label': 'Direction' }, pill('one_way', 'One way'), pill('round_trip', 'Round trip')),
          gaps ? h('button', { type: 'button', class: 'pe-gaps' + (onlyGaps ? ' on' : ''), 'aria-pressed': onlyGaps ? 'true' : 'false', text: 'Only zones with missing prices (' + gaps + ' cells)', onclick: function () { onlyGaps = !onlyGaps; render(); } }) : null),
        h('div', { class: 'pe-split' },
          h('div', { class: 'pe-left' }, h('div', { class: 'card' }, table), h('div', { class: 'atc-actions' }, zoneAdd, vehAdd)),
          sel ? editor(sel.zone, sel.type, bp) : null),
        uploadBox
      ]);
      renderUpload();
    }

    function editor(z, t, bp) {
      var c = cell(z.id, t.id);
      var retail = h('input', { type: 'text', inputmode: 'decimal', id: 'pe-retail', value: c ? dollars(c.retail) : '' });
      var paid = h('input', { type: 'text', inputmode: 'decimal', id: 'pe-paid', value: c ? dollars(c.provider) : '' });
      var typed = null; // which box the person typed in last
      var note = h('p', { class: 'small', role: 'status' });
      retail.addEventListener('input', function () {
        typed = 'retail';
        var v = cents(retail.value);
        paid.value = v === null ? '' : dollars(providerFromRetail(v, bp));
        warn(v);
      });
      paid.addEventListener('input', function () {
        typed = 'provider';
        var v = cents(paid.value);
        var r = v === null ? null : retailFromProvider(v, bp);
        retail.value = r === null ? '' : dollars(r);
        warn(r);
      });
      function warn(r) {
        var lim = g.limits || { min: 1000, max: 500000 };
        note.textContent = r !== null && (r < lim.min || r > lim.max) ? 'That looks unusual. Check it is in US dollars, per vehicle.' : '';
        note.className = 'small' + (note.textContent ? ' pe-warn' : '');
      }
      var saveBtn = h('button', { class: 'btn dark grow', type: 'button', text: 'Save price', onclick: function () {
        var cellIn = { zone_id: z.id, vehicle_type_id: t.id, direction: dir };
        if (typed === 'provider') cellIn.provider = cents(paid.value); else cellIn.retail = cents(retail.value);
        if ((cellIn.retail === null || cellIn.retail === undefined) && (cellIn.provider === null || cellIn.provider === undefined)) { opts.toast('Type a price first.', true); return; }
        send([cellIn], 'Saved.');
      } });
      var removeBtn = c ? h('button', { class: 'btn', type: 'button', text: 'Remove', onclick: function () { send([{ zone_id: z.id, vehicle_type_id: t.id, direction: dir, clear: true }], 'Removed.'); } }) : null;
      var other = cell(z.id, t.id, dir === 'one_way' ? 'round_trip' : 'one_way');
      return h('aside', { class: 'pe-edit', 'aria-label': 'Edit price' },
        h('div', null, h('div', { class: 'pe-kicker', text: 'EDIT PRICE' }), h('div', { class: 'pe-title2', text: z.name }), h('div', { class: 'atc-muted small', text: t.name + ' · ' + (dir === 'one_way' ? 'One way' : 'Round trip') })),
        h('div', { class: 'pe-two' },
          h('label', { class: 'field', for: 'pe-retail' }, h('span', { text: 'Suggested retail price' }), h('span', { class: 'pe-money' }, h('span', { text: 'USD' }), retail)),
          h('label', { class: 'field', for: 'pe-paid' }, h('span', { text: who === 'provider' ? 'Amount you will get paid' : 'Amount the provider gets paid' }), h('span', { class: 'pe-money' }, h('span', { text: 'USD' }), paid))),
        h('div', { class: 'pe-rate' }, h('span', { text: 'Commission rate' }), h('strong', { text: (bp / 100).toFixed(2) + '%' })),
        h('p', { class: 'small atc-muted', text: 'Type either box; the other fills in. The traveler pays the Suggested retail price, with no fees added.' + (other ? ' ' + (dir === 'one_way' ? 'Round trip' : 'One way') + ' here: USD ' + dollars(other.retail) + '.' : '') }),
        note,
        h('div', { class: 'atc-actions' }, saveBtn, removeBtn));
    }

    function send(cells, msg) {
      opts.api('POST', opts.base, { airport_id: g.airport.id, cells: cells }).then(function (r) {
        var keep = sel;
        g = r.grid;
        if (keep) {
          var z = g.zones.filter(function (x) { return x.id === keep.zone.id; })[0];
          var t = g.vehicle_types.filter(function (x) { return x.id === keep.type.id; })[0];
          sel = z && t ? { zone: z, type: t } : null;
        }
        render();
        opts.toast(msg);
        if (r.flagged && r.flagged.length) showFlags(r.flagged);
        if (opts.onSaved) opts.onSaved();
      }).catch(opts.fail);
    }

    function download() {
      var zones = shownZones(), types = shownTypes(zones);
      var key = who === 'provider' ? 'provider' : 'retail';
      var head = ['Zone'];
      types.forEach(function (t) { head.push(t.name, t.name + ' round trip'); });
      var rows = [[key === 'provider' ? 'Prices in USD per vehicle: what you get paid. Change them and upload this file again.' : 'Prices in USD per vehicle: what travelers pay (Suggested retail price).'], head];
      zones.forEach(function (z) {
        var r = [z.name];
        types.forEach(function (t) {
          ['one_way', 'round_trip'].forEach(function (d) { var c = cell(z.id, t.id, d); r.push(c ? c[key] / 100 : ''); });
        });
        rows.push(r);
      });
      loadSheetJS().then(function (X) {
        var wb = X.utils.book_new();
        X.utils.book_append_sheet(wb, X.utils.aoa_to_sheet(rows), 'Prices');
        X.writeFile(wb, 'prices-' + (g.airport.iata || 'airport').toLowerCase() + '.xlsx');
      }).catch(opts.fail);
    }

    function showFlags(list) {
      add(root, h('section', { class: 'card pad warn' }, h('h2', { text: 'Check these' }), h('ul', null, list.map(function (t) { return h('li', { class: 'small', text: t }); }))));
    }

    /* ----- upload ----- */

    function renderUpload(result) {
      clear(uploadBox);
      if (!uploadOpen && !result) return;
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
        return h('tr', null, h('td', { class: 'strong', text: p.name }), h('td', null, s), h('td', { class: 'small atc-muted', text: how + ' · ' + p.prices + (p.prices === 1 ? ' price' : ' prices') }));
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
          g = r.grid; render();
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
        h('div', { class: 'atc-actions' }, saveBtn, h('button', { class: 'btn', type: 'button', text: 'Start over', onclick: function () { renderUpload(); } }), h('button', { class: 'btn', type: 'button', text: 'Close', onclick: function () { uploadOpen = false; renderUpload(); } }))));
      uploadBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    load(opts.airportId || 0);
    return { reload: load };
  }

  window.ATCPrices = { mount: mount };
})();
