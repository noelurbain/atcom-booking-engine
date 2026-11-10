/* Provider sign-up: search the main airport. Our airports first, then any airport from Google. */
(function () {
  'use strict';
  var box = document.querySelector('[data-atc-airport]');
  if (!box || !window.fetch) { return; }

  var input = box.querySelector('input[name="airport_q"]');
  var idField = box.querySelector('input[name="airport_id"]');
  var placeField = box.querySelector('input[name="airport_place_id"]');
  var list = box.querySelector('.atc-airport-list');
  var notice = box.querySelector('.atc-airport-new');
  var url = box.getAttribute('data-search');
  var PICK = 'Pick your main airport from the list.';
  var timer = null;
  var seq = 0;
  var active = -1;

  function picked() { return idField.value !== '' || placeField.value !== ''; }
  function validity() { input.setCustomValidity(picked() || input.value.trim() === '' ? '' : PICK); }

  function close() {
    list.hidden = true;
    list.textContent = '';
    active = -1;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
  }

  function options() { return list.querySelectorAll('button'); }

  function highlight(i) {
    var opts = options();
    if (!opts.length) { return; }
    active = (i + opts.length) % opts.length;
    for (var k = 0; k < opts.length; k++) { opts[k].classList.toggle('is-active', k === active); }
    input.setAttribute('aria-activedescendant', opts[active].id);
    opts[active].scrollIntoView({ block: 'nearest' });
  }

  function choose(item) {
    idField.value = item.id ? String(item.id) : '';
    placeField.value = item.place_id || '';
    input.value = item.label;
    notice.hidden = !item.place_id;
    validity();
    close();
  }

  function message(text) {
    var li = document.createElement('li');
    li.className = 'atc-airport-msg';
    li.textContent = text;
    list.appendChild(li);
  }

  function button(cls, n) {
    var li = document.createElement('li');
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'atc-airport-opt' + (cls ? ' ' + cls : '');
    b.id = 'atc-airport-opt-' + n;
    b.setAttribute('role', 'option');
    li.appendChild(b);
    list.appendChild(li);
    return b;
  }

  function render(data, q) {
    list.textContent = '';
    active = -1;
    var items = data.results || [];
    items.forEach(function (item, n) {
      var b = button('', n);
      var name = document.createElement('strong');
      name.textContent = item.label;
      var sub = document.createElement('span');
      sub.textContent = item.sub || '';
      if (item['new']) {
        var tag = document.createElement('em');
        tag.textContent = 'New airport · ';
        sub.insertBefore(tag, sub.firstChild);
      }
      b.appendChild(name);
      b.appendChild(sub);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () { choose(item); });
    });
    if (data.google === 'no') {
      var more = button('is-more', items.length);
      more.textContent = 'Not your airport? Search all airports';
      more.addEventListener('mousedown', function (e) { e.preventDefault(); });
      more.addEventListener('click', function () { search(q, true); });
    } else if (data.google === 'failed') {
      message('We could not search more airports right now. Please try again in a few minutes.');
    } else if (!items.length) {
      message('No airport found. Check the spelling, or try the city name.');
    }
    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function search(q, more) {
    var mine = ++seq;
    if (more) {
      list.textContent = '';
      message('Searching all airports…');
    }
    fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q) + (more ? '&more=1' : ''), { credentials: 'omit' })
      .then(function (r) { return r.ok ? r.json() : { results: [], google: 'failed' }; })
      .catch(function () { return { results: [], google: 'failed' }; })
      .then(function (data) {
        if (mine === seq && document.activeElement === input) { render(data, q); }
      });
  }

  input.addEventListener('input', function () {
    idField.value = '';
    placeField.value = '';
    notice.hidden = true;
    validity();
    clearTimeout(timer);
    var q = input.value.trim();
    if (q.length < 2) { seq++; close(); return; }
    timer = setTimeout(function () { search(q, false); }, 300);
  });

  input.addEventListener('keydown', function (e) {
    if (list.hidden) { return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); highlight(active + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(active - 1); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      var opts = options();
      if (opts.length) { opts[active < 0 ? 0 : active].click(); }
    } else if (e.key === 'Escape') { close(); }
  });

  input.addEventListener('blur', function () { setTimeout(close, 150); });
  validity();
})();
