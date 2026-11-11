/* ATCOM Booking Engine · "Meeting your driver" editor (control panel and provider portal). */
(function () {
  'use strict';

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
      else if (k === 'disabled') el.disabled = !!v;
      else el.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) add(el, arguments[i]);
    return el;
  }
  function add(el, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(el, x); }); return; }
    el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
  }

  var CSS = '.mt-set{border:1px solid var(--line,#E6E2DA);border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:12px;background:#fff}' +
    '.mt-step{display:grid;grid-template-columns:32px 1fr auto;gap:10px;align-items:start}' +
    '.mt-n{width:28px;height:28px;border-radius:999px;background:#15171C;color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;margin-top:6px}' +
    '.mt-fields{display:flex;flex-direction:column;gap:6px}' +
    '.mt-fields input,.mt-fields textarea,.mt-term input{width:100%;border:1px solid #D6D1C7;border-radius:10px;padding:10px 12px;font:inherit;font-size:14px}' +
    '.mt-fields textarea{min-height:60px;resize:vertical}' +
    '.mt-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}' +
    '.mt-x{border:0;background:none;color:#8A1C1C;font-weight:700;cursor:pointer;padding:8px}' +
    '.mt-wrap{display:flex;flex-direction:column;gap:12px}' +
    '.mt-preview{background:#F7F5F1;border-radius:14px;padding:16px;font-size:14px}.mt-preview ol{margin:6px 0 0;padding-left:20px}.mt-preview li{margin:4px 0}';
  function css() {
    if (document.getElementById('mt-css')) return;
    var s = document.createElement('style'); s.id = 'mt-css'; s.textContent = CSS; document.head.appendChild(s);
  }

  function copy(sets) { return JSON.parse(JSON.stringify(sets || [])); }

  /** Read-only list of sets. */
  function preview(sets) {
    return h('div', { class: 'mt-preview' }, (sets || []).map(function (s) {
      return h('div', null,
        s.terminal ? h('strong', { text: s.terminal }) : null,
        h('ol', null, s.steps.map(function (st) { return h('li', null, h('strong', { text: st.title }), st.text ? ' · ' + st.text : ''); })));
    }));
  }

  /**
   * opts: { sets, startFrom (sets to copy when empty), onSave(sets) -> Promise, saveLabel, onRemove (optional) -> Promise }
   */
  function editor(opts) {
    css();
    var sets = copy(opts.sets && opts.sets.length ? opts.sets : opts.startFrom);
    if (!sets.length) sets = [{ terminal: '', steps: [{ title: '', text: '' }] }];
    var root = h('div', { class: 'mt-wrap' });

    function draw() {
      while (root.firstChild) root.removeChild(root.firstChild);
      sets.forEach(function (set, si) {
        var box = h('section', { class: 'mt-set', 'aria-label': set.terminal || 'Steps' });
        add(box, h('div', { class: 'mt-row' },
          h('label', { class: 'field mt-term', style: 'flex:1 1 260px' }, h('span', { text: sets.length > 1 ? 'Terminal' : 'Terminal (only if it differs by terminal)' }),
            h('input', { value: set.terminal, placeholder: 'e.g. Terminal 3', maxlength: 60, oninput: function () { set.terminal = this.value; } })),
          sets.length > 1 ? h('button', { type: 'button', class: 'mt-x', text: 'Remove this terminal', onclick: function () { sets.splice(si, 1); draw(); } }) : null));
        set.steps.forEach(function (st, i) {
          add(box, h('div', { class: 'mt-step' },
            h('span', { class: 'mt-n', 'aria-hidden': 'true', text: String(i + 1) }),
            h('div', { class: 'mt-fields' },
              h('input', { value: st.title, placeholder: 'Short title, e.g. Walk out of Terminal 3', maxlength: 80, 'aria-label': 'Step ' + (i + 1) + ' title', oninput: function () { st.title = this.value; } }),
              h('textarea', { placeholder: 'What exactly to do, e.g. Our driver waits under the “Restaurant Margaritaville” umbrella, holding a sign with your name.', maxlength: 400, 'aria-label': 'Step ' + (i + 1) + ' details', oninput: function () { st.text = this.value; } }, st.text)),
            set.steps.length > 1 ? h('button', { type: 'button', class: 'mt-x', 'aria-label': 'Remove step ' + (i + 1), text: '✕', onclick: function () { set.steps.splice(i, 1); draw(); } }) : h('span')));
        });
        if (set.steps.length < 6) add(box, h('div', null, h('button', { type: 'button', class: 'btn', text: '+ Add a step', onclick: function () { set.steps.push({ title: '', text: '' }); draw(); } })));
        add(root, box);
      });
      var save = h('button', { type: 'button', class: 'btn dark', text: opts.saveLabel || 'Save' });
      save.addEventListener('click', function () {
        save.disabled = true;
        opts.onSave(copy(sets)).then(function (saved) { if (saved) { sets = copy(saved); draw(); } }).catch(function () {}).then(function () { save.disabled = false; });
      });
      add(root, h('div', { class: 'mt-row' },
        sets.length < 6 ? h('button', { type: 'button', class: 'btn', text: '+ Steps for another terminal', onclick: function () { if (sets.length === 1 && !sets[0].terminal) sets[0].terminal = 'Terminal 1'; sets.push({ terminal: '', steps: [{ title: '', text: '' }] }); draw(); } }) : h('span'),
        h('span', { style: 'display:flex;gap:8px;flex-wrap:wrap' },
          opts.onRemove ? h('button', { type: 'button', class: 'btn', text: opts.removeLabel || 'Remove', onclick: function () { opts.onRemove(); } }) : null,
          save)));
    }
    draw();
    return root;
  }

  window.ATCMeeting = { editor: editor, preview: preview };
})();
