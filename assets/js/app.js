/* AIHubpedia interactions (Phase 3). No framework. Everything here is progressive enhancement:
   pages are complete server-rendered HTML; this script only adds suggestions, saved tools, theme, filtering and effects.
   Anonymous usage events are sent ONLY when the server has analytics enabled AND the visitor opted in (Privacy choices). */
(function () {
  'use strict';
  var d = document, de = d.documentElement;
  var reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function $(s, r) { return (r || d).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  function on(el, ev, fn, opt) { if (el) el.addEventListener(ev, fn, opt); }
  var store = {
    get: function (k, def) { try { var v = localStorage.getItem(k); return v == null ? def : JSON.parse(v); } catch (e) { return def; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
    getRaw: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    setRaw: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  /* ---------- Consent + events ---------- */
  function consent() { return store.getRaw('ah_consent') === '1'; }
  function eventsOn() { return de.getAttribute('data-events') === '1' && consent(); }
  function track(name, ref, props) {
    if (!eventsOn()) return;
    var body = JSON.stringify({ name: name, ref: ref || '', props: props || {}, page_type: (d.body.getAttribute('data-page') || ''), consent: 1, returning: store.get('ah_visits', 0) > 1 });
    try {
      if (navigator.sendBeacon) navigator.sendBeacon('/event.php', new Blob([body], { type: 'application/json' }));
      else fetch('/event.php', { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true });
    } catch (e) {}
  }
  (function returning() {
    if (!consent()) return;
    var today = new Date().toISOString().slice(0, 10);
    if (store.get('ah_last_day', '') !== today) {
      var v = store.get('ah_visits', 0) + 1;
      store.set('ah_visits', v); store.set('ah_last_day', today);
      if (v > 1) track('returning_visit', '', { visits: v >= 6 ? '6plus' : (v >= 3 ? '3to5' : '2') });
    }
  })();
  (function privacyDialog() {
    var dlg = $('[data-privacy-dialog]'), box = $('[data-consent-toggle]');
    if (!dlg || !box) return;
    $$('[data-privacy-open]').forEach(function (b) {
      on(b, 'click', function () { box.checked = consent(); if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); });
    });
    on(box, 'change', function () { store.setRaw('ah_consent', box.checked ? '1' : '0'); });
  })();

  /* ---------- Theme ---------- */
  (function theme() {
    var btn = $('#themeToggle'); if (!btn) return;
    // A few legacy pages ship their own light-only styling; they lock the theme and hide the toggle.
    if (de.getAttribute('data-theme-lock')) { btn.style.setProperty('display', 'none', 'important'); return; }
    function apply(t, persist) {
      de.setAttribute('data-theme', t);
      btn.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
      btn.setAttribute('aria-label', t === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
      var m = $('meta[name="theme-color"]'); if (m) m.setAttribute('content', t === 'dark' ? '#080b18' : '#f5f6fb');
      if (persist) store.setRaw('ah_theme', t);
    }
    apply(de.getAttribute('data-theme') === 'dark' ? 'dark' : 'light', false);
    on(btn, 'click', function () { var t = de.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'; apply(t, true); track('theme_toggle', '', { theme: t }); });
  })();

  /* ---------- Navigation drawer + More menu ---------- */
  (function nav() {
    var header = $('.siteHeader'), toggle = $('.navToggle'), navEl = $('#primaryNav');
    if (header && toggle && navEl) {
      function setOpen(o) {
        header.classList.toggle('navOpen', o);
        toggle.setAttribute('aria-expanded', o ? 'true' : 'false');
        toggle.setAttribute('aria-label', o ? 'Close menu' : 'Open menu');
      }
      on(toggle, 'click', function () { setOpen(!header.classList.contains('navOpen')); });
      on(d, 'keydown', function (e) { if (e.key === 'Escape' && header.classList.contains('navOpen')) { setOpen(false); toggle.focus(); } });
      on(d, 'click', function (e) { if (header.classList.contains('navOpen') && !header.contains(e.target)) setOpen(false); });
      $$('a', navEl).forEach(function (a) { on(a, 'click', function () { setOpen(false); }); });
    }
    var more = $('.moreMenu__btn');
    if (more) {
      var wrap = more.parentNode;
      on(more, 'click', function () { var o = !wrap.classList.contains('open'); wrap.classList.toggle('open', o); more.setAttribute('aria-expanded', o ? 'true' : 'false'); });
      on(d, 'click', function (e) { if (!wrap.contains(e.target)) { wrap.classList.remove('open'); more.setAttribute('aria-expanded', 'false'); } });
    }
  })();

  /* ---------- Search combobox ---------- */
  (function search() {
    var forms = $$('[data-search-form]'); if (!forms.length) return;
    var cache = {};
    forms.forEach(function (form, fi) {
      var input = $('input[type="search"]', form), panel = $('.suggest', form), list = $('ul', panel), status = $('[data-search-status]', form);
      var items = [], active = -1, ctl = null, timer = null, lastQ = '';
      function close() { panel.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; }
      function open() { panel.hidden = false; input.setAttribute('aria-expanded', 'true'); }
      function setActive(i) {
        var opts = $$('[role="option"]', list); if (!opts.length) return;
        active = (i + opts.length) % opts.length;
        opts.forEach(function (o, k) { o.setAttribute('aria-selected', k === active ? 'true' : 'false'); });
        input.setAttribute('aria-activedescendant', opts[active].id);
        opts[active].scrollIntoView({ block: 'nearest' });
      }
      function hl(text, q) {
        var t = esc(text); var toks = q.toLowerCase().split(/\s+/).filter(function (x) { return x.length > 1; });
        toks.forEach(function (tok) { t = t.replace(new RegExp('(' + tok.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>'); });
        return t;
      }
      var icons = { tool: 'i-search', category: 'i-layers', guide: 'i-book' };
      function render(data, q) {
        items = data.items || []; list.innerHTML = ''; active = -1;
        if (!items.length) { list.innerHTML = '<li class="sg-empty" role="presentation">No quick matches. Press Enter to search everything.</li>'; open(); status.textContent = 'No suggestions'; return; }
        var groups = { tool: 'Tools', category: 'Categories and topics', guide: 'Guides' }, shown = {}, n = 0;
        items.forEach(function (it, i) {
          if (!shown[it.type]) { shown[it.type] = 1; list.insertAdjacentHTML('beforeend', '<li class="sg-head" role="presentation">' + groups[it.type] + '</li>'); }
          var id = 'sg' + fi + '-' + i;
          list.insertAdjacentHTML('beforeend', '<li role="presentation"><a role="option" id="' + id + '" href="' + esc(it.url) + '" data-kind="' + it.type + '" data-pos="' + (++n) + '" aria-selected="false"><span class="sg-ico"><svg class="ic" aria-hidden="true"><use href="#' + icons[it.type] + '"/></svg></span><span class="sg-main"><span class="sg-label">' + hl(it.label, q) + '</span><span class="sg-meta">' + esc(it.meta || '') + (it.pricing ? ' · ' + esc(it.pricing) : '') + '</span></span>' + (it.reviewed ? '<span class="sg-badge">Reviewed</span>' : '') + '</a></li>');
        });
        open(); status.textContent = n + ' suggestions available. Use up and down arrows to review.';
      }
      function recents() {
        var r = store.get('ah_recent_q', []);
        if (!r.length) { close(); return; }
        list.innerHTML = '<li class="sg-head" role="presentation">Recent searches</li>';
        r.slice(0, 5).forEach(function (q, i) { list.insertAdjacentHTML('beforeend', '<li role="presentation"><a role="option" id="rq' + fi + '-' + i + '" href="/search.php?q=' + encodeURIComponent(q) + '" data-kind="recent" aria-selected="false"><span class="sg-ico"><svg class="ic" aria-hidden="true"><use href="#i-search"/></svg></span><span class="sg-main"><span class="sg-label">' + esc(q) + '</span></span></a></li>'); });
        open();
      }
      function fetchSuggest(q) {
        if (cache[q]) { render(cache[q], q); return; }
        if (ctl) ctl.abort();
        ctl = window.AbortController ? new AbortController() : null;
        fetch('/suggest.php?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, signal: ctl ? ctl.signal : undefined })
          .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
          .then(function (data) { cache[q] = data; if (input.value.trim() === q) render(data, q); })
          .catch(function () {});
      }
      on(input, 'input', function () {
        var q = input.value.trim(); lastQ = q; clearTimeout(timer);
        if (q.length < 2) { recents(); return; }
        timer = setTimeout(function () { fetchSuggest(q); }, 110);
      });
      on(input, 'focus', function () { if (input.value.trim().length < 2) recents(); else if (items.length) open(); });
      on(input, 'keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (panel.hidden) { if (items.length) open(); } setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Escape') { if (!panel.hidden) { e.preventDefault(); close(); } }
        else if (e.key === 'Enter' && active >= 0) {
          var a = $$('[role="option"]', list)[active]; if (a) { e.preventDefault(); track('suggest_click', '', { kind: a.getAttribute('data-kind'), pos: a.getAttribute('data-pos') || '0' }); location.href = a.getAttribute('href'); }
        }
      });
      on(list, 'click', function (e) { var a = e.target.closest && e.target.closest('[role="option"]'); if (a) track('suggest_click', '', { kind: a.getAttribute('data-kind'), pos: a.getAttribute('data-pos') || '0' }); });
      on(d, 'click', function (e) { if (!form.contains(e.target)) close(); });
      on(form, 'focusout', function (e) { if (!form.contains(e.relatedTarget)) setTimeout(function () { if (!form.contains(d.activeElement)) close(); }, 100); });
      on(form, 'submit', function () {
        var q = input.value.trim(); if (q) { var r = store.get('ah_recent_q', []).filter(function (x) { return x !== q; }); r.unshift(q); store.set('ah_recent_q', r.slice(0, 8)); }
        var h = d.createElement('input'); h.type = 'hidden'; h.name = 'src'; h.value = form.getAttribute('data-source') || 'header'; form.appendChild(h);
      });
    });
    on(d, 'keydown', function (e) {
      if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test((d.activeElement || {}).tagName || '') && !e.metaKey && !e.ctrlKey) {
        var i = $('input[type="search"]'); if (i) { e.preventDefault(); i.focus(); }
      }
    });
    var res = $('[data-search-results]');
    if (res) { var n = parseInt(res.getAttribute('data-search-results'), 10) || 0; var u = new URLSearchParams(location.search); track('search_submit', '', { source: (u.get('src') || 'direct').replace(/[^a-z]/g, ''), results: n === 0 ? '0' : (n <= 5 ? '1to5' : (n <= 20 ? '6to20' : '21plus')) }); }
  })();

  /* ---------- Favorites ---------- */
  (function favorites() {
    function get() { return store.get('ah_fav', []); }
    function refresh() {
      var f = get();
      $$('[data-fav]').forEach(function (b) { var on_ = f.indexOf(b.getAttribute('data-fav')) !== -1; b.setAttribute('aria-pressed', on_ ? 'true' : 'false'); var n = (b.getAttribute('aria-label') || '').replace(/^(Save|Remove) /, '').replace(/ (to|from) favorites$/, ''); b.setAttribute('aria-label', (on_ ? 'Remove ' : 'Save ') + n + (on_ ? ' from favorites' : ' to favorites')); });
      var c = $('[data-fav-count]'); if (c) { c.textContent = f.length; c.hidden = f.length === 0; }
    }
    on(d, 'click', function (e) {
      var b = e.target.closest && e.target.closest('[data-fav]'); if (!b) return;
      var id = b.getAttribute('data-fav'), f = get(), i = f.indexOf(id);
      if (i === -1) { f.unshift(id); track('favorite_add', id); } else f.splice(i, 1);
      store.set('ah_fav', f.slice(0, 100)); refresh();
      if (b.closest('[data-favorites-app]') && i !== -1) { var card = b.closest('.tCard'); if (card) card.remove(); if (!get().length) renderFavPage(); }
    });
    function renderFavPage() {
      var app = $('[data-favorites-app]'); if (!app) return;
      var ids = get(), out = $('[data-favorites-list]', app), empty = $('[data-favorites-empty]', app);
      if (!ids.length) { out.innerHTML = ''; empty.hidden = false; return; }
      empty.hidden = true;
      fetch('/suggest.php?ids=' + encodeURIComponent(ids.slice(0, 50).join(',')), { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); }).then(function (j) { out.innerHTML = j.cards || ''; refresh(); compareRefresh(); })
        .catch(function () { out.innerHTML = '<p>Could not load your saved tools. Try again later.</p>'; });
    }
    refresh(); renderFavPage();
    on(window, 'storage', refresh);
  })();

  /* ---------- Compare tray ---------- */
  var compareRefresh = (function () {
    var tray = $('[data-compare-tray]'); if (!tray) return function () {};
    var label = $('[data-compare-label]', tray), go = $('[data-compare-go]', tray);
    function get() { try { return JSON.parse(sessionStorage.getItem('ah_cmp') || '[]'); } catch (e) { return []; } }
    function set(v) { try { sessionStorage.setItem('ah_cmp', JSON.stringify(v)); } catch (e) {} }
    function refresh() {
      var c = get();
      $$('[data-compare]').forEach(function (b) { b.setAttribute('aria-pressed', c.some(function (x) { return x.id === b.getAttribute('data-compare'); }) ? 'true' : 'false'); });
      tray.hidden = c.length === 0;
      if (c.length === 1) { label.textContent = c[0].name + ' selected. Pick one more tool to compare.'; go.setAttribute('href', '/compare.php?a=' + encodeURIComponent(c[0].id)); go.setAttribute('aria-disabled', 'true'); }
      if (c.length === 2) { label.textContent = c[0].name + ' vs ' + c[1].name; go.setAttribute('href', '/compare.php?a=' + encodeURIComponent(c[0].id) + '&b=' + encodeURIComponent(c[1].id)); go.removeAttribute('aria-disabled'); }
    }
    on(d, 'click', function (e) {
      var b = e.target.closest && e.target.closest('[data-compare]');
      if (b) {
        var id = b.getAttribute('data-compare'), c = get(), i = c.findIndex(function (x) { return x.id === id; });
        if (i !== -1) c.splice(i, 1); else { c.push({ id: id, name: b.getAttribute('data-name') || id }); if (c.length > 2) c.shift(); track('compare_start', id, { n: String(c.length) }); }
        set(c); refresh(); return;
      }
      if (e.target.closest && e.target.closest('[data-compare-clear]')) { set([]); refresh(); }
      if (e.target.closest && e.target.closest('[data-compare-go]') && get().length === 2) track('compare_open');
    });
    refresh();
    return refresh;
  })();

  /* ---------- Compare form: swap and auto-submit ---------- */
  (function compareForm() {
    var f = $('[data-compare-form]'); if (!f) return;
    var a = $('#toolA', f), b = $('#toolB', f), sw = $('[data-compare-swap]', f);
    on(sw, 'click', function () { var t = a.value; a.value = b.value; b.value = t; if (a.value && b.value) f.submit(); });
    [a, b].forEach(function (el) { on(el, 'change', function () { if (a.value && b.value && a.value !== b.value) { track('compare_open'); f.submit(); } }); });
  })();

  /* ---------- Recently viewed ---------- */
  (function recent() {
    var v = $('[data-tool-view]');
    if (v) {
      var r = store.get('ah_recent', []).filter(function (x) { return x.id !== v.getAttribute('data-id'); });
      r.unshift({ id: v.getAttribute('data-id'), name: v.getAttribute('data-name'), cat: v.getAttribute('data-category') || '' });
      store.set('ah_recent', r.slice(0, 8));
    }
    var box = $('[data-recent-list]');
    if (box) {
      var items = store.get('ah_recent', []);
      if (items.length) {
        var ul = $('ul', box);
        ul.innerHTML = items.slice(0, 6).map(function (x) { return '<li><a class="chipLink" href="/tool.php?id=' + encodeURIComponent(x.id) + '">' + esc(x.name) + '</a></li>'; }).join('');
        box.hidden = false;
      }
    }
  })();

  /* ---------- Client-side filtering ---------- */
  (function filters() {
    var root = $('[data-filter-root]'); if (!root) return;
    var input = $('[data-filter-input]', root), chips = $$('[data-filter-chip]', root), items = $$('[data-filter-item]', root), status = $('[data-filter-status]', root);
    var state = { q: '', free: false, reviewed: false }, used = {};
    function apply() {
      var q = state.q.toLowerCase().trim(), shown = 0;
      items.forEach(function (it) {
        var ok = (!q || (it.getAttribute('data-search') || '').indexOf(q) !== -1) && (!state.free || /^(free|freemium)$/.test(it.getAttribute('data-pricing') || '')) && (!state.reviewed || it.getAttribute('data-reviewed') === '1');
        it.hidden = !ok; if (ok) shown++;
      });
      if (status) status.textContent = 'Showing ' + shown + ' of ' + items.length + ' tools on this page';
    }
    on(input, 'input', function () { state.q = input.value; apply(); if (!used.q) { used.q = 1; track('filter_use', '', { kind: 'text' }); } });
    chips.forEach(function (c) { on(c, 'click', function () { var k = c.getAttribute('data-filter-chip'); state[k] = !state[k]; c.setAttribute('aria-pressed', state[k] ? 'true' : 'false'); apply(); if (!used[k]) { used[k] = 1; track('filter_use', '', { kind: k }); } }); });
    apply();
  })();


  /* ---------- Saved shortlists (recommendation wizard): stored only in this browser ---------- */
  (function () {
    var KEY = 'ah_shortlists';
    function read() { var v = store.get(KEY, []); return Array.isArray(v) ? v.filter(function (x) { return x && typeof x.url === 'string' && x.url.indexOf('/recommend.php?') === 0; }) : []; }
    on(d, 'click', function (e) {
      var b = e.target.closest && e.target.closest('[data-save-shortlist]'); if (!b) return;
      var list = read(), url = b.getAttribute('data-url') || '';
      if (url.indexOf('/recommend.php?') !== 0) return;
      list = list.filter(function (x) { return x.url !== url; });
      list.unshift({ url: url, title: (b.getAttribute('data-title') || 'Shortlist').slice(0, 120), tools: (b.getAttribute('data-tools') || '').slice(0, 200), saved: new Date().toISOString().slice(0, 10) });
      store.set(KEY, list.slice(0, 10));
      var st = $('[data-save-status]'); if (st) st.textContent = 'Saved in this browser. You can find it on the Saved tools page.';
    });
    var box = $('[data-shortlists]'); if (!box) return;
    function render() {
      var list = read(), ul = $('[data-shortlists-list]', box); if (!ul) return;
      ul.textContent = ''; box.hidden = !list.length;
      list.forEach(function (x) {
        var li = document.createElement('li'), a = document.createElement('a'), sm = document.createElement('span'), rm = document.createElement('button');
        a.href = x.url; a.textContent = x.title; sm.className = 'small'; sm.textContent = ' · ' + x.tools + ' · saved ' + x.saved;
        rm.type = 'button'; rm.className = 'btn sm ghost'; rm.textContent = 'Remove'; rm.setAttribute('aria-label', 'Remove saved shortlist ' + x.title);
        on(rm, 'click', function () { store.set(KEY, read().filter(function (y) { return y.url !== x.url; })); render(); });
        li.appendChild(a); li.appendChild(sm); li.appendChild(document.createTextNode(' ')); li.appendChild(rm); ul.appendChild(li);
      });
    }
    render();
  })();

  /* ---------- Outbound click attribution ---------- */
  // Analytics A1: removed. Outbound clicks are counted once on the server (redirect.php); a second browser event double-counted them.

  /* ---------- Motion: tilt, parallax, reveal (skipped for reduced motion / touch) ---------- */
  if (!reduced) {
    if (window.matchMedia && matchMedia('(hover:hover)').matches) {
      $$('.catCard').forEach(function (c) {
        on(c, 'pointermove', function (e) { var r = c.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5; c.style.setProperty('--ry', (x * 8).toFixed(2) + 'deg'); c.style.setProperty('--rx', (-y * 8).toFixed(2) + 'deg'); c.classList.add('tilt'); });
        on(c, 'pointerleave', function () { c.style.setProperty('--ry', '0deg'); c.style.setProperty('--rx', '0deg'); });
      });
      var hero = $('.homeHero'), stack = $('.heroStack');
      if (hero && stack) on(hero, 'pointermove', function (e) { var r = hero.getBoundingClientRect(); stack.style.setProperty('--px', (((e.clientX - r.left) / r.width - .5) * 2).toFixed(3)); stack.style.setProperty('--py', (((e.clientY - r.top) / r.height - .5) * 2).toFixed(3)); });
    }
    var rv = $$('.reveal');
    // Elements already on screen are shown at once. Below-the-fold ones animate in on scroll, and a fallback guarantees that
    // nothing stays hidden for renderers or users that never scroll (content must always be reachable).
    rv.forEach(function (el) { if (el.getBoundingClientRect().top < window.innerHeight) el.classList.add('in'); });
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) { es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } }); }, { rootMargin: '0px 0px -8% 0px' });
      rv.forEach(function (el) { if (!el.classList.contains('in')) io.observe(el); });
    } else rv.forEach(function (el) { el.classList.add('in'); });
    setTimeout(function () { rv.forEach(function (el) { el.classList.add('in'); }); }, 2500);
  } else { $$('.reveal').forEach(function (el) { el.classList.add('in'); }); }
})();
