/* AIHubpedia Control Center shell: mobile menu drawer and remembered group collapse. Progressive enhancement only: without this file the menu is a normal stacked list. No libraries, no network calls. */
(function () {
  'use strict';
  var doc = document, root = doc.documentElement, body = doc.body;
  var btn = doc.getElementById('admMenuBtn'), nav = doc.getElementById('admNav'), scrim = doc.getElementById('admScrim'), closeBtn = doc.getElementById('admMenuClose');
  var mq = window.matchMedia ? window.matchMedia('(max-width: 900px)') : { matches: false };
  function isOpen() { return body.classList.contains('admNavOpen'); }
  function setOpen(open, returnFocus) {
    body.classList.toggle('admNavOpen', open);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (nav) { if (mq.matches && !open) nav.setAttribute('inert', ''); else nav.removeAttribute('inert'); }
    if (open && nav) { var first = nav.querySelector('summary, a'); if (first) first.focus(); }
    if (!open && returnFocus && btn) btn.focus();
  }
  function sync() { if (!mq.matches) { body.classList.remove('admNavOpen'); if (btn) btn.setAttribute('aria-expanded', 'false'); if (nav) nav.removeAttribute('inert'); } else if (!isOpen() && nav) { nav.setAttribute('inert', ''); } }
  if (btn && nav) {
    btn.addEventListener('click', function () { setOpen(!isOpen(), false); });
    if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false, true); });
    if (scrim) scrim.addEventListener('click', function () { setOpen(false, true); });
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen()) { setOpen(false, true); } });
    nav.addEventListener('keydown', function (e) {   // keep Tab inside the open drawer
      if (e.key !== 'Tab' || !isOpen()) return;
      var f = nav.querySelectorAll('a[href], button, summary'); if (!f.length) return;
      var a = f[0], z = f[f.length - 1];
      if (e.shiftKey && doc.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && doc.activeElement === z) { e.preventDefault(); a.focus(); }
    });
    if (mq.addEventListener) mq.addEventListener('change', sync); else if (mq.addListener) mq.addListener(sync);
    sync();
  }
  // remembered collapsed groups (this browser only; nothing is sent anywhere)
  var KEY = 'aihubAdmGroups', saved = {};
  try { saved = JSON.parse(window.localStorage.getItem(KEY) || '{}') || {}; } catch (e) { saved = {}; }
  var groups = doc.querySelectorAll('details.admGroup[data-group]');
  Array.prototype.forEach.call(groups, function (g) {
    var id = g.getAttribute('data-group'), hasActive = !!g.querySelector('[aria-current], .is-active');
    if (saved[id] === false && !hasActive) g.removeAttribute('open');
    g.addEventListener('toggle', function () { saved[id] = g.open; try { window.localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) { /* storage may be blocked */ } });
  });
})();
