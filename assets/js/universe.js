/* AI Universe public pages: progressive enhancement only. Every page works without this file (server-rendered, plain links and forms).
 * No innerHTML and no inline handlers: DOM APIs and textContent only; markup from the server is adopted node by node after parsing. */
(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  /* copy-link buttons (shown only with JavaScript): they copy the address held in a read-only text field */
  $$('[data-copy-from]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-copy-from'));
      var status = btn.parentNode.parentNode.querySelector('[data-copy-status]');
      if (!input) return;
      var done = function (ok) { if (status) status.textContent = ok ? 'Link copied to the clipboard.' : 'Copying was not possible. Select the address and copy it yourself.'; };
      input.focus(); input.select();
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(input.value).then(function () { done(true); }, function () { done(false); });
      else { try { done(document.execCommand('copy')); } catch (e) { done(false); } }
    });
  });

  /* ---- heatmap: arrow keys inside a calendar, and opening a day without a page load ---- */
  var heat = $('.uhPage');
  if (heat) {
    var addDays = function (iso, n) { var p = iso.split('-'); var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n)); return d.toISOString().slice(0, 10); };

    $$('table.uhCal').forEach(function (table) {
      var cells = function () { return $$('a.uhCell', table); };
      var setTab = function (active) { cells().forEach(function (c) { c.tabIndex = c === active ? 0 : -1; }); };
      var first = $('a.uhSel', table) || $('a.uhCell', table);
      if (first) setTab(first);
      table.addEventListener('focusin', function (e) { if (e.target.classList && e.target.classList.contains('uhCell') && e.target.tagName === 'A') setTab(e.target); });
      table.addEventListener('keydown', function (e) {
        var t = e.target; if (!t.classList || !t.classList.contains('uhCell')) return;
        var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
        if (!step) return;
        e.preventDefault();
        var date = t.getAttribute('data-date'); var found = null;
        for (var i = 0; i < 40 && !found; i++) {
          date = addDays(date, step);
          var c = table.querySelector('a.uhCell[data-date="' + date + '"]');
          if (c) found = c;
          if (!table.querySelector('[data-date="' + date + '"]') && i > 6) break;
        }
        if (found) found.focus();
      });
    });

    var swap = function (doc, url, push) {
      var newPanel = doc.getElementById('day-panel'); var oldPanel = document.getElementById('day-panel');
      if (!newPanel || !oldPanel) return false;
      oldPanel.parentNode.replaceChild(document.importNode(newPanel, true), oldPanel);
      $$('a.uhCell.uhSel').forEach(function (c) { c.classList.remove('uhSel'); c.removeAttribute('aria-current'); });
      var m = /[?&]day=(\d{4}-\d{2}-\d{2})/.exec(url);
      if (m) { var cell = $('a.uhCell[data-date="' + m[1] + '"]'); if (cell) { cell.classList.add('uhSel'); cell.setAttribute('aria-current', 'date'); } }
      var share = document.getElementById('uh-share'); var newShare = doc.getElementById('uh-share'); if (share && newShare) share.value = newShare.value;
      if (doc.title) document.title = doc.title;
      if (push) history.pushState({ uh: 1 }, '', url);
      if (push) { var panel = document.getElementById('day-panel'); panel.scrollIntoView({ block: 'start' }); panel.focus({ preventScroll: true }); }
      return true;
    };
    var current = location.pathname + location.search;
    var load = function (url, push) {
      current = url;
      return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } }).then(function (r) { if (!r.ok) throw new Error('status'); return r.text(); }).then(function (text) {
        var doc = new DOMParser().parseFromString(text, 'text/html'); if (!swap(doc, url, push)) throw new Error('markup');
      });
    };
    heat.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a.uhCell') : null;
      if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0 || !window.fetch || !window.DOMParser) return;
      e.preventDefault();
      var url = a.getAttribute('href').replace(/#.*$/, '');
      load(url, true).catch(function () { window.location.href = a.href; });
    });
    window.addEventListener('popstate', function () { var now = location.pathname + location.search; if (now === current) return; load(now, false).catch(function () { location.reload(); }); });
  }

  /* ---- lineage: show or hide one relationship type on the graph and in the tables ---- */
  $$('[data-ul-toggle]').forEach(function (box) {
    box.addEventListener('change', function () {
      var kind = box.getAttribute('data-ul-toggle');
      $$('[data-kind="' + kind + '"]').forEach(function (el) { el.classList.toggle('isOff', !box.checked); });
    });
  });

  /* ---- arena: stop the same entity being picked twice ---- */
  var slots = $$('select[data-ua-slot]');
  if (slots.length) {
    var msg = $('[data-ua-msg]');
    var check = function () {
      var seen = {}; var dup = false;
      slots.forEach(function (s) { if (s.value) { if (seen[s.value]) dup = true; seen[s.value] = true; } });
      slots.forEach(function (s) { s.setCustomValidity(''); });
      if (dup) slots[1].setCustomValidity('Choose different entities.');
      if (msg) msg.textContent = dup ? 'Each entity can only be compared once. Please choose different entities.' : '';
    };
    slots.forEach(function (s) { s.addEventListener('change', check); });
  }
}());
