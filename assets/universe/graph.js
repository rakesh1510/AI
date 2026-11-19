/* graph.js - canvas renderer + UI for the AI Universe graph (prototype page with SYNTHETIC data, production page universe-graph.php with the real read API).
 * Uses d3-force (in layout-worker.js), d3-zoom, d3-drag, d3-quadtree, d3-selection. No framework.
 * Data comes through data-source.js (adapter); this file only sees the canonical node/edge format.
 * Source selection: window.UNIVERSE_GRAPH_CONFIG (set by the production page) forces the HTTP source and can NOT be changed by URL parameters.
 * Without it (prototype page) ?source=http picks the HTTP source, anything else the labelled SYNTHETIC source.
 * Untrusted text (entity names, summaries, source titles) is only ever written with textContent / createTextNode, never innerHTML.
 */
(function () {
  'use strict';
  var $ = function (s) { return document.querySelector(s); };

  /* ---------- config from URL ---------- */
  var P = new URLSearchParams(location.search);
  var CFG = window.UNIVERSE_GRAPH_CONFIG || null;
  var IS_HTTP = CFG ? true : P.get('source') === 'http';
  var SCRIPT_URL = (document.currentScript && document.currentScript.src) || '';
  var BASE = SCRIPT_URL.replace(/[?#].*$/, '').replace(/[^\/]*$/, ''), BASEQ = (SCRIPT_URL.match(/\?[^#]*/) || [''])[0];
  var LABEL = IS_HTTP ? '' : 'SYNTHETIC TEST DATA';
  var cfg = {
    size: +P.get('size') || 500,
    load: IS_HTTP || P.get('load') === 'progressive' ? 'progressive' : 'all',
    cluster: IS_HTTP ? false : P.get('cluster') !== 'off',
    // 'public' never renders unverified edges. Review mode can NOT be switched on from the URL (QA finding): it needs a flag that only an
    // authenticated editorial page may set, and a real deployment must also filter on the server (state='verified' AND is_current=1).
    mode: (!IS_HTTP && window.UNIVERSE_REVIEW_ENABLED === true && P.get('mode') === 'review') ? 'review' : 'public',   // the HTTP source only ever serves verified rows, so it is always public
    bench: P.has('bench'),
    settleCap: +P.get('settleCap') || 0,
    labels: P.get('labels') === 'legacy' ? 'legacy' : 'placed',   // 'legacy' = old midpoint labels, kept only to measure the overlap before/after
    autoProgressive: false
  };
  var view = P.get('view') === 'table' ? 'table' : 'graph';
  function parseHash() {
    var o = {};
    location.hash.replace(/^#/, '').split('&').forEach(function (p) {
      var kv = p.split('='); if (kv[0]) o[kv[0]] = decodeURIComponent(kv[1] || '');
    });
    return o;
  }
  var H0 = parseHash();

  /* ---------- hard limits per view (mobile vs desktop) ---------- */
  var isMobile = window.matchMedia('(pointer: coarse)').matches || Math.min(window.innerWidth, screen.width) < 700;
  var LIMITS = {
    desktop: { name: 'desktop', clusterMax: 2500, edgeMax: 9000, budgetLow: 3500, budgetFull: 6000, labelNodeMax: 700, genLabels: 40, selLabels: 40, hoverLabels: 14,
               maxLoaded: 1e9, layoutMs: 0, expandMax: 400, companyMax: 600, bubbleMax: 1e9, initialMax: 400, edgePage: 100 },
    mobile:  { name: 'mobile', clusterMax: 1200, edgeMax: 3000, budgetLow: 600, budgetFull: 1800, labelNodeMax: 300, genLabels: 15, selLabels: 20, hoverLabels: 8,
               maxLoaded: 6000, layoutMs: 8000, expandMax: 150, companyMax: 200, bubbleMax: 350, initialMax: 150, edgePage: 50 }
  };
  var limMode = P.get('limits') || 'auto';
  var LIM = limMode === 'mobile' ? LIMITS.mobile : (limMode === 'desktop' || limMode === 'off') ? LIMITS.desktop : (isMobile ? LIMITS.mobile : LIMITS.desktop);

  /* ---------- visual vocabulary (colours come from the CSS variables, so light and dark share one source) ---------- */
  function apiBase() {
    var a = CFG ? CFG.api : (P.get('api') || '/universe-api.php');
    return /^\/[A-Za-z0-9_.\/-]{1,80}$/.test(a) && a.indexOf('//') < 0 ? a : '/universe-api.php';
  }
  var source = IS_HTTP ? UniverseData.create({ kind: 'http', api: apiBase() }) : UniverseData.create({ kind: 'synthetic', size: cfg.size, seed: 42 });
  var TYPES = source.taxonomy.types;
  var COL = { company: '#6d28d9', product: '#c2410c', model: '#0b5cad', family: '#15803d', technology: '#a16207', version: '#0e7490' };
  var BASER = { company: 9, product: 6, model: 4.5, family: 6.5, technology: 5.5, version: 4.2 };
  // Only technical_ancestry and product_succession are allowed `branch: true` (elbow/branch-style line).
  var REL = {
    technical_ancestry:     { color: '#0b5cad', width: 2,   dash: [],            branch: true,  directed: true,  desc: 'derived from / built on (branch-style, solid)' },
    product_succession:     { color: '#c2410c', width: 2,   dash: [7, 4],        branch: true,  directed: true,  desc: 'replaced by a later product (branch-style, dashed)' },
    company_ownership:      { color: '#6d28d9', width: 3.2, dash: [],            branch: false, directed: true,  desc: 'owns / acquired (thick straight line)' },
    integration:            { color: '#047857', width: 1.6, dash: [10, 4],       branch: false, directed: true,  desc: 'used by / built into (long dashes, straight)' },
    similarity:             { color: '#6b7280', width: 1.4, dash: [1, 4],        branch: false, directed: false, desc: 'functionally similar, no lineage (dotted, straight)' },
    historical_association: { color: '#a16207', width: 1.6, dash: [10, 3, 2, 3], branch: false, directed: false, desc: 'past link without ownership (dash-dot, straight)' },
    // The nine relationship types of the verified ledger (universe-api.php). Each keeps its own colour, width and dash; none is merged into another.
    developed_by:    { color: '#6d28d9', width: 3.2, dash: [],            branch: false, directed: true, desc: 'developed by a company (thick solid, straight)' },
    acquired_by:     { color: '#9d174d', width: 3.2, dash: [14, 5],       branch: false, directed: true, desc: 'acquired by a company (thick dashed, straight)' },
    version_of:      { color: '#0e7490', width: 2.4, dash: [],            branch: false, directed: true, desc: 'a version of a model or product (solid, straight)' },
    derived_from:    { color: '#0b5cad', width: 2,   dash: [],            branch: true,  directed: true, desc: 'derived from another model (solid, branch-style)' },
    built_on:        { color: '#4338ca', width: 2.4, dash: [2, 3],        branch: false, directed: true, desc: 'built on another model or product (dotted, straight)' },
    successor_of:    { color: '#c2410c', width: 2,   dash: [7, 4],        branch: true,  directed: true, desc: 'succeeds an earlier product (dashed, branch-style)' },
    renamed_from:    { color: '#a16207', width: 1.6, dash: [10, 3, 2, 3], branch: false, directed: true, desc: 'renamed from an earlier name (dash-dot, straight)' },
    integrates_with: { color: '#047857', width: 1.6, dash: [10, 4],       branch: false, directed: true, desc: 'integrates with (long dashes, straight)' },
    hosted_on:       { color: '#6b7280', width: 1.4, dash: [1, 4],        branch: false, directed: true, desc: 'hosted on a platform (fine dots, straight)' }
  };
  var RELS = source.taxonomy.rels;
  var PAL = { bg: '#fbfbf8', fg: '#111', halo: '#fff', lblbg: 'rgba(255,255,255,.92)', ring: '#000', ring2: '#f5a623', hi: 'rgba(255,200,0,.6)', syn: 'rgba(138,28,28,.9)' };
  var sprites = {};
  function readTheme() {
    var cs = getComputedStyle($('#ugapp') || document.documentElement), g = function (n, d) { return (cs.getPropertyValue(n) || '').trim() || d; };
    PAL.bg = g('--cv-bg', PAL.bg); PAL.fg = g('--cv-fg', PAL.fg); PAL.halo = g('--cv-halo', PAL.halo); PAL.lblbg = g('--cv-lblbg', PAL.lblbg);
    PAL.ring = g('--cv-ring', PAL.ring); PAL.ring2 = g('--cv-ring2', PAL.ring2); PAL.hi = g('--cv-hi', PAL.hi); PAL.syn = g('--cv-syn', PAL.syn);
    TYPES.forEach(function (t) { COL[t] = g('--c-' + t, COL[t]); });
    RELS.forEach(function (r) { REL[r].color = g('--r-' + r, REL[r].color); });
    var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(PAL.bg); PAL.dark = m ? (0.3 * parseInt(m[1], 16) + 0.59 * parseInt(m[2], 16) + 0.11 * parseInt(m[3], 16)) < 110 : false;
    sprites = {};
  }
  readTheme();

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  function dur() { return reduced.matches ? 0 : 450; }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(n) { return n.toLocaleString('en-US'); }

  /* ---------- perf / bench record ---------- */
  var perf = { dataGenMs: 0, firstPaint: 0, settled: false, settleMs: 0, layoutTicks: 0, layoutCapped: false, qtBuildMs: 0,
               drawnUnverified: 0, clusterFrames: 0, detailFrames: 0, lastClickMs: 0, expandMs: 0, expandAdded: 0, lastDrawMs: 0, bubblesDrawn: 0 };

  /* ---------- canonical data -> internal arrays ---------- */
  function lastSeg(u) { var m = /([^\/?#]+)\/?(?:[?#].*)?$/.exec(String(u || '')); return m ? m[1] : ''; }
  function ingest(canon) {
    var valid = {}, comps = [], rest = [], stats = { droppedNodes: 0, droppedEdges: 0, badCompany: 0 }, i;
    TYPES.forEach(function (t) { valid[t] = 1; });
    canon.nodes.forEach(function (n) { if (!valid[n.type] || !n.ref) { stats.droppedNodes++; return; } (n.type === 'company' ? comps : rest).push(n); });
    if (!comps.length && !IS_HTTP) throw new Error('data has no company nodes');
    var all = comps.concat(rest), byRef = Object.create(null), bySlug = Object.create(null), NCc = comps.length;
    var nodes = all.map(function (n, k) { byRef[n.ref] = k; var id = n.slug || ('n' + k); bySlug[id] = k; return { i: k, id: id, ref: n.ref, name: String(n.name), type: n.type, c: k, sum: n.summary || '', vat: n.verified_at || '', net: null, detail: null }; });
    for (i = NCc; i < nodes.length; i++) {
      var ci = byRef[all[i].company];
      if (ci !== undefined && ci < NCc) nodes[i].c = ci;
      else if (IS_HTTP) nodes[i].c = -1;   // the real API does not name a company; it is learned from loaded developed_by relationships and never guessed
      else { nodes[i].c = 0; stats.badCompany++; }
    }
    var edges = [], memTotal = new Int32Array(NCc);
    canon.edges.forEach(function (e) {
      var s = byRef[e.from], t = byRef[e.to];
      if (s === undefined || t === undefined || !REL[e.type]) { stats.droppedEdges++; return; }
      var srcs = e.sources || [];
      edges.push({ s: s, t: t, rel: e.type, src: srcs[0] ? (IS_HTTP ? (srcs[0].publisher || lastSeg(srcs[0].url) || '') : (lastSeg(srcs[0].url) || srcs[0].publisher || '')) : '', date: (srcs[0] && srcs[0].retrieved_at) || null,
                   vf: e.valid_from || null, vt: e.valid_to || null, v: e.verified !== false, srcs: srcs });
    });
    nodes.forEach(function (n) { if (n.c >= 0) memTotal[n.c]++; });
    return { nodes: nodes, edges: edges, NC: NCc, byRef: byRef, bySlug: bySlug, memTotal: memTotal, stats: stats, label: canon.label, pages: canon.pages, total: canon.total, truncated: !!canon.truncated };
  }

  /* ---------- DOM helpers (untrusted text only ever goes through textContent / text nodes) ---------- */
  function el(tag, attrs, kids) {
    var e = document.createElement(tag), k;
    if (attrs) for (k in attrs) { if (k === 'text') e.textContent = attrs[k]; else if (k === 'cls') e.className = attrs[k]; else e.setAttribute(k, attrs[k]); }
    (kids || []).forEach(function (c) { if (c == null || c === false) return; e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function clear(e) { while (e.firstChild) e.removeChild(e.firstChild); }
  function safeHref(u) { return typeof u === 'string' && u.length < 2000 && /^https?:\/\//i.test(u) ? u : null; }
  function day(s) { return s ? String(s).slice(0, 10) : ''; }
  function errText(err) {
    var st = err && err.status;
    if (st === 429) return 'too many requests, wait a minute and try again';
    if (st === 404) return 'not found (this entity may no longer be verified)';
    if (st === 503) return 'the data service is temporarily unavailable';
    if (st >= 400) return 'the server answered with an error (' + st + ')';
    if (err && err.code === 'bad_response') return 'the server sent data in an unexpected format';
    if (err && err.code === 'timeout') return 'the request timed out';
    return 'network error';
  }
  function showState(kind, text, retry) {
    var st = $('#ugstate'), stage0 = $('#stage');
    if (!st) { st = el('div', { id: 'ugstate' }); stage0.appendChild(st); }
    clear(st); st.className = 'ugmsg' + (kind === 'error' ? ' err' : ''); st.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    st.appendChild(el('p', { text: text }));
    if (retry) { var b = el('button', { type: 'button', text: 'Try again' }); b.addEventListener('click', retry); st.appendChild(b); }
    st.hidden = false;
    ['#cv', '#zoomctl', '#hud', '#tablewrap'].forEach(function (q) { var x = $(q); if (x) x.hidden = true; });
    $('#progress').textContent = '';
    var lv = $('#live'); if (lv) lv.textContent = text;
  }
  function hideState() { var st = $('#ugstate'); if (st) st.hidden = true; }

  /* ---------- data (through the adapter) ---------- */
  var t0 = performance.now(), lifeCtl = typeof AbortController !== 'undefined' ? new AbortController() : null, booted = false;
  window.addEventListener('pagehide', function () { if (lifeCtl) lifeCtl.abort(); });
  var appEl = $('#ugapp'); if (appEl && CFG) appEl.hidden = false;   // production page: the app is shown only when scripts run; without JS the server-rendered list stays
  if (IS_HTTP) {
    var banner = $('#synBanner');
    if (banner) { banner.className = 'verified-note'; banner.textContent = 'Live verified data from the read API: only verified, current entities and relationships, each with its source.'; }
    ['#lblSize', '#lblLoad'].forEach(function (q) { var x = $(q); if (x) x.hidden = true; });
    var tcap0 = $('#tcap'); if (tcap0) tcap0.textContent = 'Verified entities (same entities as the graph)';
    var thc = $('#thCo'); if (thc) thc.textContent = 'Developed by (once loaded)';
    var kn = $('#cv'); if (kn) kn.setAttribute('aria-label', 'Interactive graph of verified AI entities and their sourced relationships');
  }
  function startLoad() {
    $('#progress').textContent = 'Loading data...'; hideState();
    ['#cv', '#zoomctl', '#hud'].forEach(function (q) { var x = $(q); if (x && view === 'graph') x.hidden = false; });
    UniverseData.loadAll(source, { pageSize: IS_HTTP ? 100 : 10000, maxNodes: IS_HTTP ? 2000 : 0, signal: lifeCtl && lifeCtl.signal }).then(function (canon) {
      perf.dataGenMs = performance.now() - t0;
      if (!canon.nodes.length) { showState('empty', 'No verified data yet. Entities and relationships appear here only after an editor has entered them with a source and a different person has verified them.'); return; }
      booted = true; boot(ingest(canon));
    }).catch(function (err) {
      if (err && err.name === 'AbortError') return;
      if (booted) { $('#progress').textContent = 'Data load failed: ' + (err && err.message); return; }
      showState('error', 'Could not load verified data: ' + errText(err) + '.', startLoad);
    });
  }
  startLoad();

  function boot(ing) {
  var nodes = ing.nodes, N = nodes.length, NC = ing.NC, memTotal = ing.memTotal;
  var edges = cfg.mode === 'review' ? ing.edges : ing.edges.filter(function (e) { return e.v === true; });
  var adj = new Array(N), i;
  for (i = 0; i < N; i++) adj[i] = [];
  edges.forEach(function (e, k) { e.k = k; adj[e.s].push(k); adj[e.t].push(k); });
  var typeOn = {}; TYPES.forEach(function (t) { typeOn[t] = true; });
  if (LIM.maxLoaded < N && cfg.load === 'all') { cfg.load = 'progressive'; cfg.autoProgressive = true; }   // hard limit: never push more than maxLoaded nodes to the layout/draw path at once
  /* ---------- loading state (worker index space) ---------- */
  var wIdx = new Int32Array(N).fill(-1), loadedList = [], loadedEdges = [], unsent = [], edgeKeys = Object.create(null);
  if (IS_HTTP && CFG && CFG.staticId) { var sd = document.getElementById(CFG.staticId); if (sd && sd.tagName === 'DETAILS') sd.open = false; }   // the app is up: collapse the plain list
  var worker = null, layoutStart = 0, initDone = false;
  var visNodes = [], visByType = {}, edgesByRel = {}, edgesUnv = [];
  var qt = null, qtDirty = true, clusters = [], clustersDirty = true;
  var bbox = { x0: 0, y0: 0, x1: 1, y1: 1 }, bboxDirty = true;
  var sel = -1, userMoved = false;

  function centreOf(c) { var a = c * 2.399963, r = 200 * Math.sqrt(c + 0.5); return [r * Math.cos(a), r * Math.sin(a)]; }
  function hash01(i, s) { var h = Math.imul(i + 1, 2654435761) ^ Math.imul(s + 7, 40503); h ^= h >>> 15; h = Math.imul(h, 2246822519); h ^= h >>> 13; return (h >>> 0) / 4294967296; }

  function sendAdd(ids, first, near) {
    var m = ids.length, cluster = new Int32Array(m), x = new Float32Array(m), y = new Float32Array(m), tx = new Float32Array(m), ty = new Float32Array(m);
    var j, k, id, nd;
    for (j = 0; j < m; j++) {
      id = ids[j]; nd = nodes[id];
      var hasNear = near != null && near >= 0 && wIdx[near] >= 0;
      var anchor = hasNear ? nodes[near] : (nd.c >= 0 && wIdx[nd.c] >= 0 && nd.c !== id ? nodes[nd.c] : null);
      var cc = centreOf(nd.c >= 0 ? nd.c : NC), bx = anchor ? anchor.x : cc[0], by = anchor ? anchor.y : cc[1];
      var jr = (nd.type === 'company' && !hasNear ? 0 : (nd.c < 0 && !hasNear ? 24 * Math.sqrt(m) : 70)) * Math.sqrt(hash01(id, 1)), ja = 6.2832 * hash01(id, 2);
      nd.x = bx + jr * Math.cos(ja); nd.y = by + jr * Math.sin(ja);
      wIdx[id] = loadedList.length; loadedList.push(nd);
      cluster[j] = nd.c; x[j] = nd.x; y[j] = nd.y; tx[j] = bx; ty[j] = by;
    }
    var lk = [];
    for (j = 0; j < m; j++) {
      var a = adj[ids[j]];
      for (k = 0; k < a.length; k++) {
        var e = edges[a[k]];
        if (!e.ld && wIdx[e.s] >= 0 && wIdx[e.t] >= 0) { e.ld = 1; loadedEdges.push(e); lk.push(wIdx[e.s], wIdx[e.t]); }
      }
    }
    for (j = unsent.length - 1; j >= 0; j--) {   // edges that arrived later (HTTP source) and now have both ends in the layout
      var ue = unsent[j];
      if (ue.ld) { unsent.splice(j, 1); continue; }
      if (wIdx[ue.s] >= 0 && wIdx[ue.t] >= 0) { ue.ld = 1; loadedEdges.push(ue); lk.push(wIdx[ue.s], wIdx[ue.t]); unsent.splice(j, 1); }
    }
    if (!m && !lk.length) return;
    var ticks = first ? (cfg.size <= 500 ? 300 : cfg.size <= 5000 ? 220 : 120) : 80;
    var msg = { type: first ? 'init' : 'add', cluster: cluster, x: x, y: y, tx: tx, ty: ty, links: Int32Array.from(lk), ticks: ticks, maxMs: LIM.layoutMs };
    if (first) layoutStart = performance.now();
    layoutBusy = true; updateLowQ();
    worker.postMessage(msg, [cluster.buffer, x.buffer, y.buffer, tx.buffer, ty.buffer, msg.links.buffer]);
  }

  function startWorker() {
    worker = new Worker((BASE ? BASE : '') + 'layout-worker.js' + (BASE ? BASEQ : ''));   // next to this script (the production page serves it from /assets/universe/)
    worker.onmessage = function (ev) {
      var m = ev.data; if (m.type !== 'pos') return;
      var p = m.pos, n = Math.min(m.n, loadedList.length);
      perf.layoutTicks = m.tick;
      if (!reduced.matches || m.done) {
        for (var q = 0; q < n; q++) { var nd = loadedList[q]; nd.x = p[2 * q]; nd.y = p[2 * q + 1]; }
        qtDirty = clustersDirty = bboxDirty = true; markDirty();
      }
      $('#progress').textContent = m.done ? '' : 'Layout running in worker: tick ' + m.tick + '/' + m.maxTicks;
      layoutBusy = !m.done; updateLowQ();
      if (m.done) onLayoutDone(m);
    };
    var ids = [], c;
    if (IS_HTTP) { for (c = 0; c < N && c < LIM.initialMax; c++) ids.push(c); }   // companies first (ingest order), then the first entities of the list; the rest load when selected
    else if (cfg.load === 'progressive') { for (c = 0; c < NC; c++) ids.push(c); } else { for (c = 0; c < N; c++) ids.push(c); }
    sendAdd(ids, true);
    if (cfg.settleCap) setTimeout(function () { if (!initDone) worker.postMessage({ type: 'stop' }); }, cfg.settleCap);
  }

  function onLayoutDone(m) {
    if (!initDone) {
      initDone = true; perf.settleMs = performance.now() - layoutStart; perf.layoutCapped = !!m.capped;
      if (!hashView && !userMoved) fit(true);
      announce('Layout finished. ' + fmt(loadedList.length) + ' nodes loaded.' + (LABEL ? ' ' + LABEL + '.' : '') + (IS_HTTP ? ' Select an entity to load its verified relationships.' : '') + (cfg.autoProgressive ? ' Large data set on a small screen: overview of companies only; select or zoom into a company to load its nodes.' : ''));
    }
    var a = performance.now(); ensureQT(); perf.qtBuildMs = performance.now() - a;
    perf.settled = true;
    refreshStatus();
  }

  function rebuildLists() {
    visNodes = []; TYPES.forEach(function (t) { visByType[t] = []; });
    var i, nd;
    for (i = 0; i < loadedList.length; i++) { nd = loadedList[i]; if (typeOn[nd.type]) { visNodes.push(nd); visByType[nd.type].push(nd); } }
    edgesByRel = {}; RELS.forEach(function (r) { edgesByRel[r] = []; }); edgesUnv = [];
    for (i = 0; i < loadedEdges.length; i++) {
      var e = loadedEdges[i];
      if (!typeOn[nodes[e.s].type] || !typeOn[nodes[e.t].type]) continue;
      if (!e.v) edgesUnv.push(e); else edgesByRel[e.rel].push(e);
    }
    qtDirty = clustersDirty = bboxDirty = true;
  }
  function ensureQT() {
    if (!qtDirty && qt) return;
    qt = d3.quadtree().x(function (d) { return d.x; }).y(function (d) { return d.y; }).addAll(visNodes); qtDirty = false;
  }
  function ensureBBox() {
    if (!bboxDirty) return;
    var x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
    for (var i = 0; i < visNodes.length; i++) { var n = visNodes[i]; if (n.x < x0) x0 = n.x; if (n.x > x1) x1 = n.x; if (n.y < y0) y0 = n.y; if (n.y > y1) y1 = n.y; }
    if (x0 === Infinity) { x0 = y0 = 0; x1 = y1 = 1; }
    bbox = { x0: x0, y0: y0, x1: x1, y1: y1 }; bboxDirty = false;
  }
  function ensureClusters() {
    if (!clustersDirty) return;
    var cnt = new Int32Array(NC), sx = new Float64Array(NC), sy = new Float64Array(NC), i;
    for (i = 0; i < visNodes.length; i++) { var n = visNodes[i]; cnt[n.c]++; sx[n.c] += n.x; sy[n.c] += n.y; }
    clusters = [];
    for (i = 0; i < NC; i++) if (cnt[i]) clusters.push({ c: i, n: cnt[i], tot: memTotal[i], x: sx[i] / cnt[i], y: sy[i] / cnt[i] });
    clustersDirty = false;
  }
  /* ---------- canvas, zoom, drag ---------- */
  var cv = $('#cv'), ctx = cv.getContext('2d'), stage = $('#stage');
  var W = 300, Hh = 300, dpr = 1, tr = d3.zoomIdentity, hashView = false;
  var zoomSel = d3.select(cv);
  var zoomB = d3.zoom().scaleExtent([0.01, 16])
    .filter(function (ev) { return !ev.shiftKey && (!ev.ctrlKey || ev.type === 'wheel') && !ev.button; })
    .on('zoom', function (ev) { tr = ev.transform; noteMotion(); if (ev.sourceEvent) userMoved = true; markDirty(); scheduleHash(); });
  zoomSel.call(zoomB).on('dblclick.zoom', null);
  zoomSel.on('dblclick.zoom', function (ev) { userMoved = true; zoomSel.transition().duration(dur()).call(zoomB.scaleBy, ev.shiftKey ? 0.5 : 2, d3.pointer(ev, cv)); });

  var dragging = null;
  d3.drag().container(cv)
    .filter(function (ev) { return ev.shiftKey && !ev.button; })       // Shift+drag moves (pins) a node
    .subject(function (ev) { var n = hitNodeScreen(ev.x, ev.y); return n ? { x: tr.applyX(n.x), y: tr.applyY(n.y), n: n } : null; })
    .on('start', function (ev) { dragging = ev.subject.n; })
    .on('drag', function (ev) { var p = tr.invert([ev.x, ev.y]); dragging.x = p[0]; dragging.y = p[1]; qtDirty = true; markDirty(); worker.postMessage({ type: 'pin', i: wIdx[dragging.i], x: p[0], y: p[1] }); })
    .on('end', function () { if (dragging) worker.postMessage({ type: 'pin', i: wIdx[dragging.i], release: true }); dragging = null; })(zoomSel);

  function resize() {
    W = stage.clientWidth; Hh = stage.clientHeight; dpr = Math.min(2, window.devicePixelRatio || 1);
    cv.width = Math.max(1, Math.round(W * dpr)); cv.height = Math.max(1, Math.round(Hh * dpr)); markDirty();
  }
  new ResizeObserver(resize).observe(stage);

  function applyTransform(t, animate) {
    if (animate && dur() > 0) zoomSel.transition().duration(dur()).call(zoomB.transform, t);
    else zoomSel.call(zoomB.transform, t);
  }
  function viewTransform(k, wx, wy) { return d3.zoomIdentity.translate(W / 2 - wx * k, Hh / 2 - wy * k).scale(k); }
  function fitTransform() {
    ensureBBox(); var bw = bbox.x1 - bbox.x0 + 80, bh = bbox.y1 - bbox.y0 + 80;
    var k = Math.max(0.011, Math.min(3, W / bw, Hh / bh));
    return viewTransform(k, (bbox.x0 + bbox.x1) / 2, (bbox.y0 + bbox.y1) / 2);
  }
  function fit(animate) { applyTransform(fitTransform(), animate); }

  /* ---------- drawing ---------- */
  var dirty = true, rafId = 0, script = null, pendingClick = 0, firstDrawn = false, hover = -1;
  function markDirty() { dirty = true; requestFrame(); }
  function requestFrame() { if (!rafId) rafId = requestAnimationFrame(frame); }
  function frame(ts) {
    rafId = 0; var dm = 0;
    if (script) script.pre();
    if (dirty) { dirty = false; var a = performance.now(); draw(); dm = performance.now() - a; perf.lastDrawMs = dm; }
    if (script) script.post(ts, dm);
    if (script || dirty) requestFrame();
  }
  function estVisible() {
    ensureBBox();
    var area = Math.max(1, (bbox.x1 - bbox.x0) * (bbox.y1 - bbox.y0));
    return visNodes.length * Math.min(1, (W * Hh / (tr.k * tr.k)) / area);
  }
  function shape(t, x, y, r) {
    switch (t) {
      case 'company': ctx.rect(x - r, y - r, 2 * r, 2 * r); break;
      case 'product': ctx.moveTo(x, y - r * 1.3); ctx.lineTo(x + r * 1.3, y); ctx.lineTo(x, y + r * 1.3); ctx.lineTo(x - r * 1.3, y); ctx.closePath(); break;
      case 'version': case 'family': ctx.moveTo(x, y - r * 1.25); ctx.lineTo(x + r * 1.15, y + r * 0.95); ctx.lineTo(x - r * 1.15, y + r * 0.95); ctx.closePath(); break;
      case 'technology': for (var q = 0; q < 6; q++) { var a = q * 1.0472; var px = x + r * 1.1 * Math.cos(a), py = y + r * 1.1 * Math.sin(a); if (q) ctx.lineTo(px, py); else ctx.moveTo(px, py); } ctx.closePath(); break;
      default: ctx.moveTo(x + r, y); ctx.arc(x, y, r, 0, 6.2832);
    }
  }
  function nodeR(t) { return BASER[t] * Math.max(0.45, Math.min(2.2, Math.sqrt(tr.k))); }
  function edgeText(e) { return e.rel + ' | ' + e.src + (e.date ? (IS_HTTP ? ' | retrieved ' : ' | verified ') + String(e.date).slice(0, 10) : '') + (e.v ? '' : ' | UNVERIFIED'); }
  function arrow(x, y, ang, s) {
    ctx.moveTo(x, y); ctx.lineTo(x - s * Math.cos(ang - 0.45), y - s * Math.sin(ang - 0.45)); ctx.lineTo(x - s * Math.cos(ang + 0.45), y - s * Math.sin(ang + 0.45)); ctx.closePath();
  }
  // Segments are clipped to the viewport (+margin) before stroking: dashing a line that is thousands of px long is very slow.
  var cx0, cy0, cx1, cy1;
  function clip(x0, y0, x1, y1) {
    var xmin = -40, ymin = -40, xmax = W + 40, ymax = Hh + 40, dx = x1 - x0, dy = y1 - y0, t0 = 0, t1 = 1, t;
    if (dx === 0) { if (x0 < xmin || x0 > xmax) return false; } else {
      t = (xmin - x0) / dx; var u = (xmax - x0) / dx; if (t > u) { var w = t; t = u; u = w; }
      if (t > t0) t0 = t; if (u < t1) t1 = u; if (t0 > t1) return false;
    }
    if (dy === 0) { if (y0 < ymin || y0 > ymax) return false; } else {
      t = (ymin - y0) / dy; var v = (ymax - y0) / dy; if (t > v) { var w2 = t; t = v; v = w2; }
      if (t > t0) t0 = t; if (v < t1) t1 = v; if (t0 > t1) return false;
    }
    cx0 = x0 + t0 * dx; cy0 = y0 + t0 * dy; cx1 = x0 + t1 * dx; cy1 = y0 + t1 * dy; return true;
  }
  function seg(x0, y0, x1, y1) { if (clip(x0, y0, x1, y1)) { ctx.moveTo(cx0, cy0); ctx.lineTo(cx1, cy1); } }
  function edgePath(e, rel, a, b, ax, ay, bx, by) {
    if (REL[rel].branch) { var mx = (ax + bx) / 2; seg(ax, ay, mx, ay); seg(mx, ay, mx, by); seg(mx, by, bx, by); }
    else seg(ax, ay, bx, by);
  }

  var visE = {}; RELS.forEach(function (r) { visE[r] = []; });
  var lowQ = false, interacting = false, settleTimer = 0, layoutBusy = true;
  function updateLowQ() { lowQ = interacting || layoutBusy; }
  function noteMotion() { interacting = true; updateLowQ(); clearTimeout(settleTimer); settleTimer = setTimeout(function () { interacting = false; updateLowQ(); markDirty(); }, 180); }

  function drawEdges(labelOut, quality) {
    var k = tr.k, tx = tr.x, ty = tr.y, nodesA = nodes, ri, q, total = 0;
    // pass 1: viewport culling into reusable per-relation arrays
    for (ri = 0; ri < RELS.length; ri++) {
      var rel0 = RELS[ri], arr0 = edgesByRel[rel0], out = visE[rel0]; out.length = 0;
      for (q = 0; q < arr0.length; q++) {
        var e0 = arr0[q], a0 = nodesA[e0.s], b0 = nodesA[e0.t];
        var ax0 = a0.x * k + tx, ay0 = a0.y * k + ty, bx0 = b0.x * k + tx, by0 = b0.y * k + ty;
        if ((ax0 < 0 && bx0 < 0) || (ax0 > W && bx0 > W) || (ay0 < 0 && by0 < 0) || (ay0 > Hh && by0 > Hh)) continue;
        out.push(e0);
      }
      total += out.length;
    }
    var budget = lowQ ? LIM.budgetLow : LIM.budgetFull, stride = Math.max(1, Math.ceil(total / budget)), drawn = 0;
    for (ri = 0; ri < RELS.length; ri++) {
      var rel = RELS[ri], arr = visE[rel], d = REL[rel]; if (!arr.length) continue;
      ctx.beginPath(); var arrows = [];
      for (q = 0; q < arr.length; q++) {
        var e = arr[q]; if (stride > 1 && e.k % stride) continue;
        var a = nodesA[e.s], b = nodesA[e.t];
        var ax = a.x * k + tx, ay = a.y * k + ty, bx = b.x * k + tx, by = b.y * k + ty;
        if (!e.v) perf.drawnUnverified++;
        edgePath(e, rel, a, b, ax, ay, bx, by); drawn++;
        if (quality) {
          if (k >= 2.5 && labelOut.length < (cfg.labels === 'legacy' ? 120 : 400) && ax > 0 && ax < W && bx > 0 && bx < W && ay > 0 && ay < Hh && by > 0 && by < Hh) labelOut.push(e);
          if (d.directed && k >= 1.5 && drawn <= 1500) arrows.push(ax, ay, bx, by);
        }
      }
      if (quality) { ctx.setLineDash(d.dash); ctx.lineWidth = d.width; ctx.lineCap = rel === 'similarity' ? 'round' : 'butt'; ctx.globalAlpha = k < 0.5 ? 0.5 : 0.8; }
      else { ctx.setLineDash([]); ctx.lineWidth = 1; ctx.lineCap = 'butt'; ctx.globalAlpha = 0.6; } // hairlines: much cheaper to rasterise
      ctx.strokeStyle = d.color; ctx.stroke();
      if (arrows.length) {
        ctx.setLineDash([]); ctx.fillStyle = d.color; ctx.beginPath();
        for (var z = 0; z < arrows.length; z += 4) {
          var ang = d.branch ? (arrows[z + 2] >= (arrows[z] + arrows[z + 2]) / 2 ? 0 : Math.PI) : Math.atan2(arrows[z + 3] - arrows[z + 1], arrows[z + 2] - arrows[z]);
          arrow(arrows[z + 2], arrows[z + 3], ang, 7);
        }
        ctx.fill();
      }
    }
    ctx.setLineDash([]); ctx.globalAlpha = 1; ctx.lineCap = 'butt';
    perf.edgesVisible = total; perf.edgesDrawn = drawn;
    if (edgesUnv.length) { // review mode only
      ctx.beginPath(); ctx.strokeStyle = '#ef4444'; ctx.lineWidth = 1; ctx.setLineDash([2, 2]);
      for (var u = 0; u < edgesUnv.length; u++) { var ee = edgesUnv[u], aa = nodes[ee.s], bb = nodes[ee.t]; ctx.moveTo(aa.x * k + tx, aa.y * k + ty); ctx.lineTo(bb.x * k + tx, bb.y * k + ty); }
      ctx.stroke(); ctx.setLineDash([]);
    }
  }

  // Node glyphs are pre-rendered once per (type, radius) and blitted: polygon paths are very slow to rasterise in bulk.
  function sprite(t, r) {
    var rr = Math.max(3, Math.round(r)), key = t + rr + '@' + dpr;
    if (sprites[key]) return sprites[key];
    var size = Math.ceil(rr * 2.9 + 4), c = document.createElement('canvas'); c.width = c.height = Math.ceil(size * dpr);
    var g = c.getContext('2d'); g.scale(dpr, dpr);
    var saved = ctx; ctx = g; g.beginPath(); shape(t, size / 2, size / 2, rr); g.fillStyle = COL[t]; g.fill(); g.lineWidth = 1; g.strokeStyle = PAL.halo; g.stroke(); ctx = saved;
    return (sprites[key] = { c: c, size: size });
  }
  function drawNodes() {
    var k = tr.k, tx = tr.x, ty = tr.y;
    for (var ti = 0; ti < TYPES.length; ti++) {
      var t = TYPES[ti], arr = visByType[t]; if (!arr || !arr.length) continue;
      var r = nodeR(t), tiny = r < 2.6, sp = tiny ? null : sprite(t, r), h = sp ? sp.size / 2 : 0;
      if (tiny) ctx.beginPath();
      for (var q = 0; q < arr.length; q++) {
        var n = arr[q], x = n.x * k + tx, y = n.y * k + ty;
        if (x < -12 || x > W + 12 || y < -12 || y > Hh + 12) continue;
        if (tiny) ctx.rect(x - 1.1, y - 1.1, 2.2, 2.2); else ctx.drawImage(sp.c, Math.round(x - h), Math.round(y - h), sp.size, sp.size);
      }
      if (tiny) { ctx.fillStyle = COL[t]; ctx.fill(); }
    }
  }

  /* ---------- label placement: grid occupancy (spatial hash), priority order, leader lines, hidden-count pill ---------- */
  var CELL = 40, occ = new Map(), wcache = Object.create(null), wcacheN = 0;
  function gk(cx, cy) { return (cx + 64) * 4096 + (cy + 64); }
  function pReset() { occ.clear(); }
  function pHit(r) {
    var x0 = Math.floor(r.x / CELL), x1 = Math.floor((r.x + r.w) / CELL), y0 = Math.floor(r.y / CELL), y1 = Math.floor((r.y + r.h) / CELL), cx, cy, a, j, o;
    for (cx = x0; cx <= x1; cx++) for (cy = y0; cy <= y1; cy++) {
      a = occ.get(gk(cx, cy)); if (!a) continue;
      for (j = 0; j < a.length; j++) { o = a[j]; if (r.x < o.x + o.w && o.x < r.x + r.w && r.y < o.y + o.h && o.y < r.y + r.h) return true; }
    }
    return false;
  }
  function pAdd(r) {
    var x0 = Math.floor(r.x / CELL), x1 = Math.floor((r.x + r.w) / CELL), y0 = Math.floor(r.y / CELL), y1 = Math.floor((r.y + r.h) / CELL), cx, cy, a, key;
    for (cx = x0; cx <= x1; cx++) for (cy = y0; cy <= y1; cy++) { key = gk(cx, cy); a = occ.get(key); if (!a) { a = []; occ.set(key, a); } a.push(r); }
  }
  function tw(txt, font) {
    var key = font + txt, w = wcache[key];
    if (w === undefined) { if (wcacheN > 4000) { wcache = Object.create(null); wcacheN = 0; } ctx.font = font; w = wcache[key] = ctx.measureText(txt).width; wcacheN++; }
    return w;
  }
  var LFONT = '10px system-ui,sans-serif', NFONT = '11px system-ui,sans-serif', OFFS = [[0, 0], [0, -1], [0, 1], [0, -2], [0, 2], [-1, 0], [1, 0]], TS = [0.5, 0.36, 0.64, 0.22, 0.78];
  var frameLabels = { edge: [], nodeObs: [], hidden: 0, cand: 0, placed: 0, mode: 'placed' };
  // Try to place the label for edge e between screen points a and b. Full text first, then the compact (relationship type only) text.
  function placeEdgeLabel(e, ax, ay, bx, by, out, compactOnly) {
    var d = REL[e.rel], h = 16, ti, ci, oi;
    for (ti = compactOnly ? 1 : 0; ti < 2; ti++) {
      var txt = ti === 0 ? edgeText(e) : e.rel, w = tw(txt, LFONT) + 8;
      for (ci = 0; ci < TS.length; ci++) {
        var px = d.branch ? (ax + bx) / 2 : ax + (bx - ax) * TS[ci], py = ay + (by - ay) * TS[ci];
        for (oi = 0; oi < OFFS.length; oi++) {
          var rx = px - w / 2 + OFFS[oi][0] * (w / 2 + 6), ry = py - h / 2 + OFFS[oi][1] * (h + 2);
          if (rx < 2 || ry < 2 || rx + w > W - 2 || ry + h > Hh - 2) continue;
          var r = { x: rx, y: ry, w: w, h: h };
          if (pHit(r)) continue;
          pAdd(r);
          var lead = OFFS[oi][0] !== 0 || OFFS[oi][1] !== 0;
          out.push({ e: e, r: r, txt: txt, px: px, py: py, lead: lead, compact: ti === 1 });
          return true;
        }
      }
    }
    return false;
  }
  function neighbourhoodEdges(id) {
    var a = adj[id], out = [], q;
    for (q = 0; q < a.length; q++) { var e = edges[a[q]]; if (wIdx[e.s] >= 0 && wIdx[e.t] >= 0 && typeOn[nodes[e.s].type] && typeOn[nodes[e.t].type]) out.push(e); }
    out.sort(function (p, r) { var op = nodes[p.s === id ? p.t : p.s].i, or = nodes[r.s === id ? r.t : r.s].i; return (adj[or].length - adj[op].length) || (op - or); });
    return out;
  }
  function sp2(n) { return [n.x * tr.k + tr.x, n.y * tr.k + tr.y]; }
  function planNeighbourhood(id, cap, compact, out, rec) {
    var list = neighbourhoodEdges(id), placed = 0, q, e, s, t, a, b;
    rec.cand += list.length;
    for (q = 0; q < list.length && placed < cap; q++) {
      e = list[q]; s = nodes[e.s]; t = nodes[e.t]; a = sp2(s); b = sp2(t);
      if (placeEdgeLabel(e, a[0], a[1], b[0], b[1], out, compact)) placed++;
    }
    rec.placed += placed; rec.hidden += list.length - placed;
    return list.length - placed;
  }
  function recordObstacle(n) {
    var p = sp2(n), r = nodeR(n.type) + 3, o = { x: p[0] - r, y: p[1] - r, w: 2 * r, h: 2 * r };
    pAdd(o); frameLabels.nodeObs.push(o);
  }
  function drawEdgeLabels(list) {
    ctx.font = LFONT; ctx.textBaseline = 'middle'; ctx.textAlign = 'center';
    for (var q = 0; q < list.length; q++) {
      var L = list[q], r = L.r, col = REL[L.e.rel].color;
      if (L.lead) {
        var lx = Math.max(r.x, Math.min(r.x + r.w, L.px)), ly = Math.max(r.y, Math.min(r.y + r.h, L.py));
        ctx.beginPath(); ctx.moveTo(lx, ly); ctx.lineTo(L.px, L.py); ctx.lineWidth = 1; ctx.strokeStyle = col; ctx.setLineDash([]); ctx.stroke();
        ctx.beginPath(); ctx.arc(L.px, L.py, 2.5, 0, 6.2832); ctx.fillStyle = col; ctx.fill();
      }
      ctx.fillStyle = PAL.lblbg; ctx.fillRect(r.x, r.y, r.w, r.h);
      ctx.lineWidth = 1; ctx.strokeStyle = col; ctx.strokeRect(r.x + 0.5, r.y + 0.5, r.w - 1, r.h - 1);
      ctx.fillStyle = PAL.fg; ctx.fillText(L.txt, r.x + r.w / 2, r.y + r.h / 2 + 0.5);
    }
    ctx.textAlign = 'start';
  }
  function drawNodeNames(list) {
    ctx.font = NFONT; ctx.textBaseline = 'middle'; ctx.lineWidth = 3; ctx.lineJoin = 'round'; ctx.strokeStyle = PAL.halo; ctx.fillStyle = PAL.fg;
    for (var q = 0; q < list.length; q++) { var L = list[q]; ctx.strokeText(L.n.name, L.x, L.y); ctx.fillText(L.n.name, L.x, L.y); }
  }
  function planNodeNames(out, cap) {
    var k = tr.k, c = 0;
    for (var q = 0; q < visNodes.length && c < cap; q++) {
      var n = visNodes[q], x = n.x * k + tr.x, y = n.y * k + tr.y;
      if (x < 0 || x > W || y < 0 || y > Hh) continue;
      var tx = x + nodeR(n.type) + 3, w = tw(n.name, NFONT), r = { x: tx - 1, y: y - 7, w: w + 2, h: 14 };
      if (r.x + r.w > W || pHit(r)) continue;
      pAdd(r); out.push({ n: n, x: tx, y: y }); c++;
    }
  }
  function drawHighlight(id) { // selected node's incident edges (halo + styled line) and rings around the neighbours
    if (id < 0 || wIdx[id] < 0) return;
    var n = nodes[id], k = tr.k, a = adj[id], q;
    for (q = 0; q < a.length; q++) {
      var e = edges[a[q]]; if (wIdx[e.s] < 0 || wIdx[e.t] < 0) continue;
      var s = nodes[e.s], t = nodes[e.t], d = REL[e.rel];
      var ax = s.x * k + tr.x, ay = s.y * k + tr.y, bx = t.x * k + tr.x, by = t.y * k + tr.y;
      ctx.beginPath(); edgePath(e, e.rel, s, t, ax, ay, bx, by);
      ctx.setLineDash([]); ctx.lineWidth = d.width + 2.5; ctx.strokeStyle = PAL.hi; ctx.stroke();
      ctx.setLineDash(d.dash); ctx.lineWidth = d.width; ctx.strokeStyle = d.color; ctx.stroke(); ctx.setLineDash([]);
      var o = e.s === id ? t : s; ctx.beginPath(); ctx.arc(o.x * k + tr.x, o.y * k + tr.y, nodeR(o.type) + 4, 0, 6.2832); ctx.lineWidth = 2; ctx.strokeStyle = PAL.ring; ctx.stroke();
    }
  }
  function drawRings(id, strong) {
    if (id < 0 || wIdx[id] < 0) return;
    var n = nodes[id], x = n.x * tr.k + tr.x, y = n.y * tr.k + tr.y, r = nodeR(n.type);
    ctx.beginPath(); ctx.arc(x, y, r + 6, 0, 6.2832); ctx.lineWidth = 3; ctx.strokeStyle = PAL.ring; ctx.stroke();
    if (strong) { ctx.beginPath(); ctx.arc(x, y, r + 9, 0, 6.2832); ctx.lineWidth = 2; ctx.strokeStyle = PAL.ring2; ctx.stroke(); }
  }

  /* legacy placement (the pre-fix behaviour: labels at the edge midpoint, no collision test), kept only to measure the overlap before/after */
  function legacyLabels(labelEdges) {
    var rec = frameLabels, k = tr.k, q, e, s, t, a, b, mx, my, w; pReset();
    ctx.font = '11px system-ui,sans-serif'; ctx.textBaseline = 'middle';
    if (sel >= 0 && wIdx[sel] >= 0) {
      var ad = adj[sel], labelled = 0; recordObstacle(nodes[sel]);
      for (q = 0; q < ad.length; q++) {
        e = edges[ad[q]]; if (wIdx[e.s] < 0 || wIdx[e.t] < 0) continue; rec.cand++;
        s = nodes[e.s]; t = nodes[e.t]; recordObstacle(e.s === sel ? t : s);
        if (labelled < 24) {
          a = sp2(s); b = sp2(t); var txt = edgeText(e); mx = (a[0] + b[0]) / 2; my = (a[1] + b[1]) / 2; w = ctx.measureText(txt).width + 8;
          ctx.fillStyle = PAL.lblbg; ctx.fillRect(mx - w / 2, my - 8, w, 16); ctx.fillStyle = PAL.fg; ctx.textAlign = 'center'; ctx.fillText(txt, mx, my); ctx.textAlign = 'start';
          rec.edge.push({ x: mx - w / 2, y: my - 8, w: w, h: 16 }); labelled++; rec.placed++;
        } else rec.hidden++;
      }
    }
    ctx.font = '10px system-ui,sans-serif'; ctx.textAlign = 'center';
    for (q = 0; q < labelEdges.length; q++) {
      e = labelEdges[q]; a = sp2(nodes[e.s]); b = sp2(nodes[e.t]); var tx2 = edgeText(e); mx = (a[0] + b[0]) / 2; my = (a[1] + b[1]) / 2; w = ctx.measureText(tx2).width + 6;
      ctx.fillStyle = PAL.lblbg; ctx.fillRect(mx - w / 2, my - 7, w, 14); ctx.fillStyle = REL[e.rel].color; ctx.fillText(tx2, mx, my);
      rec.edge.push({ x: mx - w / 2, y: my - 7, w: w, h: 14 }); rec.cand++; rec.placed++;
    }
    ctx.textAlign = 'start';
  }
  function placedLabels(labelEdges, showNames) {
    var rec = frameLabels, k = tr.k, names = [], gen = [], hov = [], selL = [], q;
    pReset();
    // 1. obstacles: the glyphs of the focused node and its neighbours; 2. reserve the hidden-count pill next to the selected node
    var pill = null;
    if (sel >= 0 && wIdx[sel] >= 0) {
      var sn = nodes[sel], sp = sp2(sn), ne = neighbourhoodEdges(sel);
      recordObstacle(sn); ne.forEach(function (e) { recordObstacle(e.s === sel ? nodes[e.t] : nodes[e.s]); });
      var pw = tw('+999 edge labels hidden, see details', NFONT) + 12;
      pill = { x: Math.max(4, Math.min(W - pw - 4, sp[0] - pw / 2)), y: Math.max(4, Math.min(Hh - 60, sp[1] - nodeR(sn.type) - 34)), w: pw, h: 18 }; pAdd(pill);
      var hid = planNeighbourhood(sel, LIM.selLabels, false, selL, rec);
      if (hid > 0) pill.text = '+' + hid + ' edge labels hidden, see details'; else pill = null;
    }
    if (hover >= 0 && hover !== sel && wIdx[hover] >= 0) { recordObstacle(nodes[hover]); planNeighbourhood(hover, LIM.hoverLabels, true, hov, { cand: 0, placed: 0, hidden: 0 }); }
    if (labelEdges.length) {
      labelEdges.sort(function (p, r) { return (adj[r.s].length + adj[r.t].length) - (adj[p.s].length + adj[p.t].length); });
      var placed = 0;
      for (q = 0; q < labelEdges.length && placed < LIM.genLabels; q++) {
        var e = labelEdges[q], a = sp2(nodes[e.s]), b = sp2(nodes[e.t]);
        rec.cand++; if (placeEdgeLabel(e, a[0], a[1], b[0], b[1], gen, true)) { placed++; rec.placed++; }
      }
    }
    if (showNames) planNodeNames(names, 300);
    drawNodeNames(names); drawEdgeLabels(gen); drawEdgeLabels(hov); drawEdgeLabels(selL);
    selL.concat(gen, hov).forEach(function (L) { rec.edge.push(L.r); });
    if (pill) {
      ctx.fillStyle = PAL.fg; ctx.fillRect(pill.x, pill.y, pill.w, pill.h); ctx.fillStyle = PAL.bg; ctx.font = NFONT; ctx.textBaseline = 'middle'; ctx.fillText(pill.text, pill.x + 6, pill.y + pill.h / 2 + 0.5);
    }
  }

  /* ---------- clusters (company bubbles) with super-node aggregation ---------- */
  var bubbles = [];
  function bubbleR(m, k) { return Math.max(3, 14 * Math.sqrt(m) * k); }
  function computeBubbles() {
    ensureClusters(); var k = tr.k, vis = [], q, c, prog = cfg.load === 'progressive';
    for (q = 0; q < clusters.length; q++) {
      c = clusters[q]; var m = prog ? c.tot : c.n, x = c.x * k + tr.x, y = c.y * k + tr.y, r = bubbleR(m, k);
      if (x < -r || x > W + r || y < -r || y > Hh + r) continue;
      vis.push({ c: c.c, m: m, x: x, y: y, r: r, cnt: 1, agg: false });
    }
    if (vis.length <= LIM.bubbleMax) return vis;
    // hard cap on drawn bubbles: merge into grid cells; each merged bubble is an expandable super-node (zoom in to split it)
    var cell = Math.sqrt(W * Hh / LIM.bubbleMax), g = new Map(), o;
    for (q = 0; q < vis.length; q++) {
      c = vis[q]; var key = Math.floor(c.x / cell) * 8192 + Math.floor(c.y / cell); o = g.get(key);
      if (!o) { o = { c: -1, m: 0, x: 0, y: 0, r: 0, cnt: 0, agg: true, first: c.c }; g.set(key, o); }
      o.m += c.m; o.x += c.x * c.m; o.y += c.y * c.m; o.cnt++;
    }
    var out = [];
    g.forEach(function (b) { b.x /= b.m; b.y /= b.m; b.r = Math.min(cell * 0.6, bubbleR(b.m, k)); if (b.cnt === 1) { b.agg = false; b.c = b.first; } out.push(b); });
    return out;
  }
  function drawClusters() {
    bubbles = computeBubbles(); var k = tr.k, labels = 0, labelMax = LIM.bubbleMax < 1e9 ? 60 : 200, q, dark = PAL.dark;
    ctx.font = NFONT; ctx.textBaseline = 'middle'; ctx.textAlign = 'center';
    for (q = 0; q < bubbles.length; q++) {
      var b = bubbles[q], hue = b.agg ? 0 : (b.c * 47) % 360;
      ctx.beginPath(); ctx.arc(b.x, b.y, b.r, 0, 6.2832);
      ctx.fillStyle = b.agg ? (dark ? 'rgba(220,225,235,.22)' : 'rgba(40,50,70,.2)') : 'hsla(' + hue + ',55%,' + (dark ? 62 : 48) + '%,' + (dark ? 0.4 : 0.35) + ')'; ctx.fill();
      if (!lowQ) {
        ctx.lineWidth = b.agg ? 2 : 1; ctx.setLineDash(b.agg ? [5, 3] : []); ctx.strokeStyle = b.agg ? PAL.fg : 'hsl(' + hue + ',55%,' + (dark ? 78 : 32) + '%)'; ctx.stroke(); ctx.setLineDash([]);
        if (b.r >= 16 && labels < labelMax) {
          ctx.fillStyle = PAL.fg;
          ctx.fillText(b.agg ? b.cnt + ' companies, ' + fmt(b.m) + ' nodes' : nodes[b.c].name + ' (' + fmt(b.m) + ')', b.x, b.y); labels++;
        }
      }
    }
    ctx.textAlign = 'start'; perf.bubblesDrawn = bubbles.length;
  }

  var lastMode = '';
  function draw() {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.fillStyle = PAL.bg; ctx.fillRect(0, 0, W, Hh);
    var est = estVisible(), clustered = cfg.cluster && est > LIM.clusterMax, labelEdges = [];
    frameLabels = { edge: [], nodeObs: [], hidden: 0, cand: 0, placed: 0, mode: cfg.labels };
    if (clustered) { drawClusters(); perf.clusterFrames++; }
    else {
      if (est <= LIM.edgeMax) drawEdges(labelEdges, !lowQ); else { perf.edgesVisible = perf.edgesDrawn = 0; }
      drawNodes();
      drawHighlight(sel);
      if (!lowQ) {   // text passes are skipped while moving or while the layout streams (hidden-while-moving)
        if (cfg.labels === 'legacy') {
          if (tr.k >= 1.6 && est <= LIM.labelNodeMax) { ctx.font = NFONT; ctx.textBaseline = 'middle'; ctx.lineWidth = 3; ctx.strokeStyle = PAL.halo; ctx.fillStyle = PAL.fg; var c0 = 0; for (var q0 = 0; q0 < visNodes.length && c0 < 300; q0++) { var n0 = visNodes[q0], x0 = n0.x * tr.k + tr.x, y0 = n0.y * tr.k + tr.y; if (x0 < 0 || x0 > W || y0 < 0 || y0 > Hh) continue; var t0x = x0 + nodeR(n0.type) + 3; ctx.strokeText(n0.name, t0x, y0); ctx.fillText(n0.name, t0x, y0); c0++; } }
          legacyLabels(labelEdges);
        } else placedLabels(labelEdges, tr.k >= 1.6 && est <= LIM.labelNodeMax);
      }
      perf.detailFrames++;
    }
    if (hover >= 0 && hover !== sel && !clustered) drawRings(hover, false);
    if (!clustered) drawRings(sel, true);
    ctx.font = 'bold 12px system-ui,sans-serif'; ctx.textBaseline = 'alphabetic'; if (LABEL) { ctx.fillStyle = PAL.syn; ctx.fillText(LABEL, 8, Hh - 42); }
    var md = clustered ? 'clustered by company (zoom in or click a cluster)' : (est > LIM.edgeMax ? 'nodes only (edges hidden while zoomed out)' : 'full detail');
    if (md !== lastMode) { lastMode = md; refreshStatus(); }
    perf.renderMode = md;
    if (pendingClick) { perf.lastClickMs = performance.now() - pendingClick; pendingClick = 0; }
    if (!firstDrawn && loadedList.length) {
      firstDrawn = true;
      requestAnimationFrame(function () { requestAnimationFrame(function () { perf.firstPaint = performance.now(); }); });
    }
  }
  /* label overlap metric over the labels of the last drawn frame (edge-label rectangles) */
  function labelMetrics() {
    var L = frameLabels.edge, n = L.length, i, j, pairs = 0, area = 0, tot = 0, inv = new Uint8Array(n), nodeHits = 0, hitSet = new Uint8Array(n);
    for (i = 0; i < n; i++) {
      tot += L[i].w * L[i].h;
      for (j = i + 1; j < n; j++) {
        var iw = Math.min(L[i].x + L[i].w, L[j].x + L[j].w) - Math.max(L[i].x, L[j].x), ih = Math.min(L[i].y + L[i].h, L[j].y + L[j].h) - Math.max(L[i].y, L[j].y);
        if (iw > 0 && ih > 0) { pairs++; area += iw * ih; inv[i] = inv[j] = 1; }
      }
      frameLabels.nodeObs.forEach(function (o) { var iw2 = Math.min(L[i].x + L[i].w, o.x + o.w) - Math.max(L[i].x, o.x), ih2 = Math.min(L[i].y + L[i].h, o.y + o.h) - Math.max(L[i].y, o.y); if (iw2 > 0 && ih2 > 0) { nodeHits++; hitSet[i] = 1; } });
    }
    var involved = 0, nodeLabels = 0; for (i = 0; i < n; i++) { involved += inv[i]; nodeLabels += hitSet[i]; }
    return { mode: frameLabels.mode, labelsDrawn: n, candidates: frameLabels.cand, hidden: frameLabels.hidden, overlappingPairs: pairs, labelsInAnyOverlap: involved, overlapAreaPx: Math.round(area),
             overlapAreaPctOfLabelArea: tot ? Math.round(1000 * area / tot) / 10 : 0, labelNodeOverlaps: nodeHits, labelsCoveringANode: nodeLabels };
  }

  /* ---------- hit testing, pointer and keyboard ---------- */
  var touchy = window.matchMedia('(pointer: coarse)');
  function hitNodeScreen(sx, sy) {
    ensureQT();
    var p = tr.invert([sx, sy]), r = (touchy.matches ? 22 : 10) / tr.k;
    return qt.find(p[0], p[1], r) || null;
  }
  function hitBubbleScreen(sx, sy) {
    var list = computeBubbles(), best = null, bd = 1e12;
    for (var q = 0; q < list.length; q++) {
      var c = list[q], x = c.x - sx, y = c.y - sy, r = Math.max(14, c.r), d = x * x + y * y;
      if (d <= r * r && d < bd) { bd = d; best = c; }
    }
    return best;
  }
  function isClustered() { return cfg.cluster && estVisible() > LIM.clusterMax; }
  function expandBubble(b) {   // zoom into a (super-)node; in progressive mode a single company also loads its members (capped)
    userMoved = true;
    var wx = (b.x - tr.x) / tr.k, wy = (b.y - tr.y) / tr.k;
    if (!b.agg && cfg.load === 'progressive') expandCompany(b.c);
    applyTransform(viewTransform(Math.max(tr.k * (b.agg ? 3 : 4), 0.35), wx, wy), true);
    announce(b.agg ? 'Zoomed into a group of ' + b.cnt + ' companies.' : 'Zoomed into ' + nodes[b.c].name + '.');
  }
  cv.addEventListener('click', function (ev) {
    pendingClick = performance.now();
    var r = cv.getBoundingClientRect(), sx = ev.clientX - r.left, sy = ev.clientY - r.top;
    if (isClustered()) { var c = hitBubbleScreen(sx, sy); if (c) expandBubble(c); pendingClick = 0; return; }
    var n = hitNodeScreen(sx, sy);
    if (n) select(n.i, {}); else if (sel >= 0) select(-1, {});
    markDirty();
  });
  var hoverRaf = 0, hoverPt = null;
  cv.addEventListener('pointermove', function (ev) {
    if (ev.pointerType !== 'mouse' || ev.buttons) return;
    var r = cv.getBoundingClientRect(); hoverPt = [ev.clientX - r.left, ev.clientY - r.top];
    if (hoverRaf) return;
    hoverRaf = requestAnimationFrame(function () {
      hoverRaf = 0; if (!hoverPt || interacting || isClustered()) return;
      var n = hitNodeScreen(hoverPt[0], hoverPt[1]), id = n ? n.i : -1;
      cv.style.cursor = n ? 'pointer' : '';
      if (id !== hover) { hover = id; markDirty(); }
    });
  });
  cv.addEventListener('pointerleave', function () { hoverPt = null; if (hover >= 0) { hover = -1; markDirty(); } });

  // Keyboard alternatives to pointer gestures: pan, zoom, fit, select, cycle nodes / related nodes, back.
  var backStack = [];
  function nearestToCentreNode() { ensureQT(); var p = tr.invert([W / 2, Hh / 2]); return qt.find(p[0], p[1]) || null; }
  function viewportNodes() {   // loaded, visible-type nodes inside the viewport, nearest the centre first (max 150)
    var out = [], cx = W / 2, cy = Hh / 2, q;
    for (q = 0; q < visNodes.length; q++) { var n = visNodes[q], x = n.x * tr.k + tr.x, y = n.y * tr.k + tr.y; if (x >= 0 && x <= W && y >= 0 && y <= Hh) out.push([(x - cx) * (x - cx) + (y - cy) * (y - cy), n]); }
    out.sort(function (a, b) { return a[0] - b[0] || a[1].i - b[1].i; });
    return out.slice(0, 150).map(function (o) { return o[1]; });
  }
  function pickNearest() {
    if (isClustered()) { var b = hitBubbleScreen(W / 2, Hh / 2) || nearestBubble(); if (b) expandBubble(b); else announce('Nothing in view. Press 0 to fit all.'); return; }
    var n = nearestToCentreNode(); if (n) select(n.i, {}); else announce('No node near the centre. Press 0 to fit all or zoom out.');
  }
  function nearestBubble() { var list = computeBubbles(), best = null, bd = 1e12; list.forEach(function (c) { var d = (c.x - W / 2) * (c.x - W / 2) + (c.y - Hh / 2) * (c.y - Hh / 2); if (d < bd) { bd = d; best = c; } }); return best; }
  function cycleNodes(dir) {
    if (isClustered()) { announce('Too many nodes in view. Zoom in with plus, or press Enter to open the nearest cluster, or use the Table view.'); return; }
    var list = viewportNodes(); if (!list.length) { announce('No nodes in view.'); return; }
    var idx = -1; for (var q = 0; q < list.length; q++) if (list[q].i === sel) idx = q;
    var next = list[(idx < 0 ? (dir > 0 ? 0 : list.length - 1) : (idx + dir + list.length) % list.length)];
    select(next.i, {});
  }
  function cycleRelated(dir) {
    if (sel < 0) { announce('Select a node first.'); return; }
    var ne = neighbourhoodEdges(sel); if (!ne.length) { announce('This node has no loaded relationships.'); return; }
    var ids = ne.map(function (e) { return e.s === sel ? e.t : e.s; }), idx = ids.indexOf(relCursor);
    var nid = ids[(idx < 0 ? (dir > 0 ? 0 : ids.length - 1) : (idx + dir + ids.length) % ids.length)];
    var from = sel; relCursor = nid; select(nid, { center: true, keepRel: true, from: from });
  }
  var relCursor = -1;
  function goBack() { var p = backStack.pop(); if (p == null) { announce('No earlier selection.'); return; } select(p, { center: true, noPush: true }); }
  function keyZoom(f) { zoomSel.call(zoomB.scaleBy, f); userMoved = true; }
  cv.addEventListener('keydown', function (ev) {
    if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
    var k = ev.key, step = 60, t = tr;
    if (k === 'ArrowLeft') t = t.translate(step / t.k, 0); else if (k === 'ArrowRight') t = t.translate(-step / t.k, 0);
    else if (k === 'ArrowUp') t = t.translate(0, step / t.k); else if (k === 'ArrowDown') t = t.translate(0, -step / t.k);
    else if (k === '+' || k === '=') { keyZoom(1.4); ev.preventDefault(); return; }
    else if (k === '-' || k === '_') { keyZoom(1 / 1.4); ev.preventDefault(); return; }
    else if (k === '0') { userMoved = true; fit(false); announce('Fitted all nodes in view.'); ev.preventDefault(); return; }
    else if (k === 'Escape') { select(-1, { fromKey: true }); return; }
    else if (k === 'Enter') { if (sel >= 0 && !isClustered()) { $('#pname').focus(); } else pickNearest(); ev.preventDefault(); return; }
    else if (k === 'n' || k === 'N') { cycleNodes(ev.shiftKey ? -1 : 1); ev.preventDefault(); return; }
    else if (k === ']') { cycleRelated(1); ev.preventDefault(); return; }
    else if (k === '[') { cycleRelated(-1); ev.preventDefault(); return; }
    else if (k === 'b' || k === 'B') { goBack(); ev.preventDefault(); return; }
    else return;
    ev.preventDefault(); userMoved = true; zoomSel.call(zoomB.transform, t);
  });
  $('#zIn').addEventListener('click', function () { keyZoom(1.4); });
  $('#zOut').addEventListener('click', function () { keyZoom(1 / 1.4); });
  $('#zFit').addEventListener('click', function () { userMoved = true; fit(false); announce('Fitted all nodes in view.'); });
  $('#zPick').addEventListener('click', pickNearest);
  $('#zNext').addEventListener('click', function () { cycleRelated(1); });

  /* ---------- selection, panel, progressive loading ---------- */
  var lastFocus = null, limitHit = false, members = null;
  function loadIds(ids, near) {   // hard limit: never more than LIM.maxLoaded nodes in the layout/draw path
    var room = LIM.maxLoaded - loadedList.length;
    if (ids.length > room) { limitHit = true; ids = ids.slice(0, Math.max(0, room)); announce('Loaded-node limit for this screen reached (' + fmt(LIM.maxLoaded) + '). Reload the page to start from the overview again.'); }
    if (ids.length || unsent.length) { sendAdd(ids, false, near); rebuildLists(); }
    return ids.length;
  }

  /* ---------- HTTP source: progressive neighbourhood loading (paged, abortable, honest about errors) ---------- */
  function addEdges(list) {   // canonical edges of one neighbourhood response; unknown endpoints or relationship types are dropped and counted
    var added = [], dropped = 0;
    list.forEach(function (ce) {
      var s = ing.byRef[ce.from], t = ing.byRef[ce.to];
      if (s === undefined || t === undefined || !REL[ce.type] || RELS.indexOf(ce.type) < 0) { dropped++; return; }
      var srcs = ce.sources || [], key = s + '|' + t + '|' + ce.type + '|' + (ce.valid_from || '') + '|' + (ce.valid_to || '') + '|' + (srcs[0] ? srcs[0].url : '');
      if (edgeKeys[key]) return; edgeKeys[key] = 1;
      var e = { s: s, t: t, rel: ce.type, src: srcs[0] ? (srcs[0].publisher || lastSeg(srcs[0].url) || '') : '', date: (srcs[0] && srcs[0].retrieved_at) || null,
                vf: ce.valid_from || null, vt: ce.valid_to || null, v: true, srcs: srcs, k: edges.length };
      edges.push(e); adj[s].push(e.k); adj[t].push(e.k); unsent.push(e); added.push(e);
      if (ce.type === 'developed_by' && nodes[t].type === 'company' && nodes[s].type !== 'company' && nodes[s].c < 0) nodes[s].c = t;
    });
    return { added: added, dropped: dropped };
  }
  function companyName(n) {
    if (!IS_HTTP) return nodes[n.c].name;
    if (n.type === 'company') return 'n/a (is a company)';
    var a = adj[n.i];
    for (var q = 0; q < a.length; q++) { var e = edges[a[q]]; if (e.rel === 'developed_by' && e.s === n.i) return nodes[e.t].name; }
    return 'not shown (no loaded developed_by relationship)';
  }
  function repaintSel(id) { if (sel === id) { renderPanel({}); updateTableSel(); } }
  function loadDetail(id) {
    var n = nodes[id]; if (n.detail || n.detailCtl) return;
    n.detailCtl = lifeCtl ? new AbortController() : null;
    source.entity(n.id, { signal: n.detailCtl && n.detailCtl.signal }).then(function (d) { n.detail = d; n.detailCtl = null; repaintSel(id); })
      .catch(function (err) { n.detailCtl = null; if (err && err.name === 'AbortError') return; n.detail = { error: errText(err) }; repaintSel(id); });
  }
  function expandHttp(id, more) {
    var n = nodes[id]; if (!n || !n.id) return;
    if (wIdx[id] < 0) loadIds([id], -1);
    loadDetail(id);
    var net = n.net || (n.net = { state: 'idle', total: null, next: 0, dropped: 0, err: '', ctl: null });
    if (net.state === 'loading') return;
    if (net.state !== 'idle' && net.state !== 'error' && !more) return;
    if (net.state === 'done') return;
    var a0 = performance.now();
    net.state = 'loading'; net.ctl = lifeCtl ? new AbortController() : null; repaintSel(id);
    source.neighbourhood(n.ref, { slug: n.id, offset: net.next, limit: LIM.edgePage, signal: net.ctl && net.ctl.signal }).then(function (r) {
      net.ctl = null; net.total = r.total_edges; if (r.next_offset != null) net.next = r.next_offset;
      var res = addEdges(r.edges); net.dropped += res.dropped; net.err = '';
      var ids = [], seen = {};
      res.added.forEach(function (e) { [e.s, e.t].forEach(function (o) { if (wIdx[o] < 0 && !seen[o] && ids.length < LIM.expandMax) { seen[o] = 1; ids.push(o); } }); });
      net.state = r.has_more ? 'more' : 'done';
      perf.expandAdded = loadIds(ids, id); perf.expandMs = performance.now() - a0;
      repaintSel(id); refreshTable(); refreshStatus(); markDirty();
      announce(res.added.length + ' relationships loaded for ' + n.name + (net.state === 'more' ? '. More are available.' : '.'));
    }).catch(function (err) {
      net.ctl = null;
      if (err && err.name === 'AbortError') { net.state = 'idle'; return; }
      net.state = 'error'; net.err = errText(err); repaintSel(id); announce('Could not load relationships for ' + n.name + ': ' + net.err + '.');
    });
  }
  function expand(id) {
    if (IS_HTTP) { expandHttp(id, false); return; }
    if (cfg.load !== 'progressive') return;
    var a0 = performance.now(), ids = [], seen = {}, q;
    if (wIdx[id] < 0) { ids.push(id); seen[id] = 1; }
    var a = adj[id];
    for (q = 0; q < a.length && ids.length < LIM.expandMax; q++) {
      var e = edges[a[q]], o = e.s === id ? e.t : e.s;
      if (wIdx[o] < 0 && !seen[o]) { seen[o] = 1; ids.push(o); }
    }
    perf.expandAdded = loadIds(ids); perf.expandMs = performance.now() - a0; refreshStatus();
  }
  function expandCompany(cid) {
    if (!members) { members = new Array(NC); for (var z = 0; z < NC; z++) members[z] = []; for (var q = 0; q < N; q++) if (nodes[q].c >= 0 && members[nodes[q].c]) members[nodes[q].c].push(q); }
    var ids = [], m = members[cid];
    for (var j = 0; j < m.length && ids.length < LIM.companyMax; j++) if (wIdx[m[j]] < 0) ids.push(m[j]);
    perf.expandAdded = loadIds(ids); refreshStatus();
  }
  function select(id, opts) {
    opts = opts || {};
    if (IS_HTTP && id !== sel && sel >= 0) { var pn0 = nodes[sel]; if (pn0.net && pn0.net.ctl) pn0.net.ctl.abort(); if (pn0.detailCtl) pn0.detailCtl.abort(); }
    if (id !== sel && sel >= 0 && !opts.noPush) { backStack.push(sel); if (backStack.length > 50) backStack.shift(); }
    if (!opts.keepRel) relCursor = -1;
    sel = id; $('#zNext').disabled = id < 0;
    if (id >= 0) { expand(id); if (opts.center) { userMoved = true; applyTransform(viewTransform(Math.max(tr.k, 1.6), nodes[id].x, nodes[id].y), true); } }
    renderPanel(opts); updateTableSel(); scheduleHash(); markDirty();
    if (id >= 0) announce('Selected ' + nodes[id].name + ', ' + nodes[id].type + ', ' + adj[id].length + (IS_HTTP ? ' relationships loaded so far.' : ' verified relationships.') + (view === 'graph' ? ' Press Enter to open details.' : ''));
    else if (opts.fromClose || opts.fromKey) announce('Selection cleared.');
  }
  function swatch(rel) {
    var d = REL[rel], p = d.branch ? 'M2 16H20V4H54' : 'M2 10H54', da = d.dash.length ? ' stroke-dasharray="' + d.dash.join(' ') + '"' : '';
    return '<svg width="56" height="20" viewBox="0 0 56 20" aria-hidden="true" focusable="false"><path d="' + p + '" fill="none" style="stroke:var(--r-' + rel + ')" stroke-width="' + d.width + '"' + da + ' stroke-linecap="' + (rel === 'similarity' ? 'round' : 'butt') + '"/></svg>';
  }
  function typeSwatch(t) {
    var s = 'M10 2h12v12H10z';
    if (t === 'product') s = 'M16 1l9 8-9 8-9-8z'; else if (t === 'family' || t === 'version') s = 'M16 1l9 15H7z'; else if (t === 'technology') s = 'M23 9l-3.5 6h-7L9 9l3.5-6h7z'; else if (t === 'model') s = 'M16 2a7 7 0 1 0 .01 0z';
    return '<svg width="32" height="18" viewBox="0 0 32 18" aria-hidden="true" focusable="false"><path d="' + s + '" style="fill:var(--c-' + t + ')"/></svg>';
  }
  function swatchNode(rel) { var t = document.createElement('span'); t.innerHTML = swatch(rel); return t.firstChild; }   // static SVG built only from whitelisted relationship keys
  function typeSwatchNode(ty) { var t = document.createElement('span'); t.innerHTML = typeSwatch(ty); return t.firstChild; }
  function dlRow(dl, k, v) {
    dl.appendChild(el('dt', { text: k })); var d = el('dd');
    (Array.isArray(v) ? v : [v]).forEach(function (x) { if (x != null) d.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); });
    dl.appendChild(d); return d;
  }
  function sourceNodes(s0) {   // one citation: publisher as an external link (http/https only) plus the retrieval date
    var href = safeHref(s0.url), label = s0.publisher || s0.url || 'source';
    var a = href ? el('a', { href: href, rel: 'nofollow noopener', target: '_blank' }, [label, el('span', { cls: 'sr', text: ' (opens in a new tab)' })]) : document.createTextNode(String(label));
    return [a, ' (retrieved ' + (day(s0.retrieved_at) || 'date not recorded') + ')'];
  }
  function citeNode(e) {
    var c = el('div', { cls: 'cite' });
    if (!IS_HTTP) {
      c.appendChild(document.createTextNode('Source: ' + e.src + ' (synthetic citation)')); c.appendChild(el('br'));
      c.appendChild(document.createTextNode(e.date ? 'Verified: ' + e.date : 'Verification date: not recorded'));
      if (e.vf || e.vt) { c.appendChild(el('br')); c.appendChild(document.createTextNode('Valid ' + (e.vf || '?') + ' to ' + (e.vt || 'present'))); }
      return c;
    }
    var srcs = (e.srcs || []).slice(0, 5);
    if (!srcs.length) c.appendChild(document.createTextNode('No source recorded.'));
    srcs.forEach(function (s0, i) { if (i) c.appendChild(el('br')); c.appendChild(document.createTextNode('Source: ')); sourceNodes(s0).forEach(function (x) { c.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); }); });
    if ((e.srcs || []).length > 5) { c.appendChild(el('br')); c.appendChild(document.createTextNode((e.srcs.length - 5) + ' more sources not shown.')); }
    if (e.vf || e.vt) { c.appendChild(el('br')); c.appendChild(document.createTextNode('Valid ' + (e.vf || '?') + ' to ' + (e.vt || 'present'))); }
    return c;
  }
  function renderPanel(opts) {
    var panel = $('#panel'), lay = $('#layout');
    if (sel < 0) { panel.hidden = true; lay.classList.remove('has-sel'); $('#skipPanel').hidden = true; if (opts && opts.fromClose) { if (lastFocus && lastFocus.isConnected) lastFocus.focus(); if (!document.activeElement || document.activeElement === document.body) { if (view === 'graph') $('#cv').focus(); else $('#tcap').focus(); } } return; }
    var n = nodes[sel], a = adj[sel], dl = $('#pdl'), ul = $('#edges'), net = n.net, pm = $('#pmore'), pnet = $('#pnet'), hadFocus = document.activeElement === pm, q;
    $('#pname').textContent = n.name; clear(dl); clear(ul);
    dlRow(dl, 'Type', [typeSwatchNode(n.type), n.type]);
    dlRow(dl, 'ID', n.id);
    if (IS_HTTP) {
      dlRow(dl, 'Developed by', companyName(n));
      dlRow(dl, 'Verified', day(n.vat) || 'date not recorded');
      dlRow(dl, 'Relationships', a.length + ' loaded' + (net && net.total != null ? ' of ' + net.total + ' verified' : ''));
      var d = n.detail, sd = dlRow(dl, 'Sources', []);
      if (d && d.sources) {
        if (!d.sources.length) sd.textContent = 'No source recorded for this entity.';
        else { var sl = el('ul', { cls: 'srclist' }); d.sources.slice(0, 5).forEach(function (s0) { sl.appendChild(el('li', null, sourceNodes(s0))); }); sd.appendChild(sl); }
      } else if (d && d.error) sd.textContent = 'Could not load: ' + d.error + '.';
      else sd.textContent = 'Loading...';
      dlRow(dl, 'Data', 'Verified, current data from the read API');
    } else {
      dlRow(dl, 'Company', companyName(n));
      dlRow(dl, 'Relationships', a.length + (cfg.mode === 'public' ? ' (verified only)' : ' (review mode)'));
      dlRow(dl, 'Data', LABEL);
    }
    var sum = $('#psum'); if (sum) { sum.textContent = IS_HTTP ? (((n.detail && n.detail.summary) || n.sum || '')) : ''; sum.hidden = !sum.textContent; }
    var note = $('#relnote'); if (note) { note.hidden = !IS_HTTP; note.textContent = IS_HTTP ? 'Only verified, current relationships are listed. The API does not publish a verification date per relationship, so the retrieval date of each source is shown.' : ''; }
    var order = a.slice();
    if (IS_HTTP) order.sort(function (x, y) { return (RELS.indexOf(edges[x].rel) - RELS.indexOf(edges[y].rel)) || (x - y); });   // grouped by relationship type, types stay separate
    var shown = Math.min(order.length, 120);
    for (q = 0; q < shown; q++) {
      var e = edges[order[q]], out = e.s === sel, o = nodes[out ? e.t : e.s];
      ul.appendChild(el('li', null, [
        el('div', { cls: 'rel' }, [swatchNode(e.rel), el('span', { text: e.rel }), e.v ? null : el('span', { cls: 'badge', text: 'UNVERIFIED' })]),
        el('div', null, [out ? 'to ' : 'from ', el('button', { cls: 'linkbtn', type: 'button', 'data-go': String(o.i), text: o.name }), ' ', el('small', { text: '(' + o.type + (wIdx[o.i] < 0 ? ', not loaded yet' : '') + ')' })]),
        citeNode(e)
      ]));
    }
    if (a.length > shown) ul.appendChild(el('li', { text: (a.length - shown) + ' more relationships not listed.' }));
    var msg = '';
    if (IS_HTTP && net) {
      if (net.state === 'loading') msg = 'Loading relationships...';
      else if (net.state === 'error') msg = 'Could not load relationships: ' + net.err + '.';
      else if (net.state === 'more') msg = 'Showing ' + a.length + ' loaded relationships of ' + net.total + '.';
      if (net.dropped) msg += ' ' + net.dropped + ' relationships involve entities that are not in the loaded list and are not shown.';
    } else if (IS_HTTP) msg = 'Loading relationships...';
    if (pnet) pnet.textContent = msg;
    if (!a.length && (!IS_HTTP || (net && net.state === 'done'))) ul.appendChild(el('li', { text: 'No verified relationships.' }));
    if (pm) {
      var st = IS_HTTP && net ? net.state : '';
      pm.hidden = !(st === 'more' || st === 'error' || (st === 'loading' && net.next > 0));
      pm.textContent = st === 'error' ? 'Try again' : (st === 'loading' ? 'Loading...' : 'Load more relationships');
      pm.setAttribute('aria-disabled', st === 'loading' ? 'true' : 'false');
      if (hadFocus && pm.hidden) $('#pname').focus();
    }
    panel.hidden = false; lay.classList.add('has-sel'); $('#skipPanel').hidden = false;
    if (opts && opts.focus) { lastFocus = document.activeElement; $('#pname').focus(); }
  }
  if ($('#pmore')) $('#pmore').addEventListener('click', function (ev) { if (ev.currentTarget.getAttribute('aria-disabled') === 'true' || sel < 0) return; expandHttp(sel, true); });
  $('#edges').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-go]'); if (b) select(+b.getAttribute('data-go'), { center: view === 'graph' });
  });
  $('#pclose').addEventListener('click', function () { select(-1, { fromClose: true }); });
  function announce(t) { var l = $('#live'); l.textContent = (l.textContent === t ? t + ' ' : t); }

  /* ---------- hash deep link ---------- */
  var hashTimer = 0;
  function scheduleHash() { clearTimeout(hashTimer); hashTimer = setTimeout(writeHash, 250); }
  function writeHash() {
    var cx = (W / 2 - tr.x) / tr.k, cy = (Hh / 2 - tr.y) / tr.k, parts = [];
    if (sel >= 0) parts.push('sel=' + nodes[sel].id);
    parts.push('k=' + tr.k.toFixed(3), 'x=' + cx.toFixed(1), 'y=' + cy.toFixed(1));
    if (view === 'table') parts.push('view=table');
    var url = location.pathname + location.search;
    if (CFG && CFG.syncQuery) {   // production page: the selected entity is a shareable ?slug= deep link (the server renders it too)
      var qp = new URLSearchParams(location.search); if (sel >= 0) qp.set('slug', nodes[sel].id); else qp.delete('slug');
      var qs = qp.toString(); url = location.pathname + (qs ? '?' + qs : '');
    }
    try { history.replaceState(null, '', url + '#' + parts.join('&')); } catch (e) { /* ignore */ }
  }

  /* ---------- search ---------- */
  var qEl = $('#q'), resEl = $('#results'), resWrap = $('#reswrap'), resMsg = $('#resmsg'), lname = null, resIdx = -1, resItems = [];
  function lowerNames() { if (!lname) lname = nodes.map(function (n) { return n.name.toLowerCase(); }); return lname; }
  function hideResults() { resWrap.style.display = 'none'; qEl.setAttribute('aria-expanded', 'false'); qEl.removeAttribute('aria-activedescendant'); resIdx = -1; }
  function showResults() {
    var q = qEl.value.trim().toLowerCase();
    if (!q || view !== 'graph') { hideResults(); return; }
    var L = lowerNames(), out = [], i, pass;
    for (pass = 0; pass < 2 && out.length < 8; pass++)
      for (i = 0; i < N && out.length < 8; i++) {
        if (!typeOn[nodes[i].type]) continue;
        var idx = L[i].indexOf(q); if (idx < 0 || (pass === 0) !== (idx === 0)) continue; out.push(i);
      }
    resItems = out;
    clear(resEl);
    out.forEach(function (i, j) { resEl.appendChild(el('li', { role: 'option', id: 'ro' + j, 'data-i': String(i), 'aria-selected': 'false' }, [nodes[i].name + ' ', el('small', { text: '\u00a0(' + nodes[i].type + ')' })])); });
    resEl.hidden = !out.length; resMsg.hidden = !!out.length;
    var r = qEl.getBoundingClientRect(); resWrap.style.left = r.left + 'px'; resWrap.style.top = (r.bottom + 2) + 'px'; resWrap.style.minWidth = r.width + 'px';
    resWrap.style.display = 'block'; qEl.setAttribute('aria-expanded', out.length ? 'true' : 'false'); resIdx = -1;
    announce(out.length ? out.length + (out.length === 8 ? ' or more' : '') + ' matches. Use the down arrow to choose.' : 'No matches.');
  }
  function pickResult(i) { hideResults(); qEl.value = nodes[i].name; refreshTable(); select(i, { center: true, focus: false }); cv.focus(); }
  var deb = 0;
  qEl.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(function () { refreshTable(); if (view === 'graph') showResults(); else announceCount(''); }, 80); });
  qEl.addEventListener('keydown', function (ev) {
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      if (resWrap.style.display !== 'block' || !resItems.length) return; ev.preventDefault();
      resIdx = (resIdx + (ev.key === 'ArrowDown' ? 1 : -1) + resItems.length) % Math.max(1, resItems.length);
      Array.prototype.forEach.call(resEl.children, function (li, j) { li.setAttribute('aria-selected', j === resIdx ? 'true' : 'false'); });
      qEl.setAttribute('aria-activedescendant', 'ro' + resIdx);
    } else if (ev.key === 'Enter') {
      var pick = resIdx >= 0 ? resItems[resIdx] : resItems[0]; if (pick != null && resWrap.style.display === 'block') { ev.preventDefault(); pickResult(pick); }
    } else if (ev.key === 'Escape') hideResults();
  });
  resEl.addEventListener('mousedown', function (ev) { var li = ev.target.closest('li[data-i]'); if (li) { ev.preventDefault(); pickResult(+li.getAttribute('data-i')); } });
  document.addEventListener('click', function (ev) { if (ev.target !== qEl && !resWrap.contains(ev.target)) hideResults(); });

  /* ---------- table (accessible alternative): native <table>, row headers, one button per row ---------- */
  var tbody = $('#tbody'), PAGE = 50, tpage = 0, tableRows = [], annTimer = 0;
  function pageCount() { return Math.max(1, Math.ceil(tableRows.length / PAGE)); }
  function refreshTable(o) {
    var q = qEl.value.trim().toLowerCase(), L = q ? lowerNames() : null; tableRows = [];
    for (var i = 0; i < N; i++) if (typeOn[nodes[i].type] && (!q || L[i].indexOf(q) >= 0)) tableRows.push(i);
    tpage = Math.min(tpage, pageCount() - 1);
    if (o && o.followSel && sel >= 0) { var ix = tableRows.indexOf(sel); if (ix >= 0) tpage = Math.floor(ix / PAGE); }
    renderTable();
  }
  function renderTable() {
    var from = tpage * PAGE, to = Math.min(tableRows.length, from + PAGE);
    clear(tbody);
    for (var q = from; q < to; q++) {
      var n = nodes[tableRows[q]], on = n.i === sel;
      var btn = el('button', { cls: 'rowbtn', type: 'button', 'data-i': String(n.i), text: n.name }); if (on) btn.setAttribute('aria-current', 'true');
      var th = el('th', { scope: 'row' }, [btn, on ? el('span', { cls: 'selmark', text: ' (selected)' }) : null]);
      tbody.appendChild(el('tr', on ? { 'data-i': String(n.i), cls: 'sel' } : { 'data-i': String(n.i) }, [th, el('td', { text: n.type }), el('td', { text: companyName(n) }), el('td', { text: String(adj[n.i].length) })]));
    }
    if (!tableRows.length) tbody.appendChild(el('tr', { cls: 'empty' }, [el('td', { colspan: '4', text: 'No nodes match the current search and filters.' })]));
    var tw0 = $('#tablewrap'); if (tableRows.length) tw0.removeAttribute('tabindex'); else tw0.tabIndex = 0;   // a scrollable region with nothing focusable inside must itself be focusable
    $('#thRel').textContent = IS_HTTP ? 'Verified relationships loaded so far (count)' : (cfg.mode === 'public' ? 'Verified relationships (count)' : 'Relationships (count, includes unverified)');
    var pages = pageCount();
    $('#pageinfo').textContent = tableRows.length ? fmt(tableRows.length) + ' nodes, showing ' + (from + 1) + ' to ' + to + ', page ' + (tpage + 1) + ' of ' + pages : 'No nodes match';
    $('#prev').disabled = tpage === 0; $('#next').disabled = tpage >= pages - 1;
  }
  function updateTableSel() {
    Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-i]'), function (r) {
      var on = +r.getAttribute('data-i') === sel, b = r.querySelector('.rowbtn'), m = r.querySelector('.selmark');
      r.classList.toggle('sel', on);
      if (on) { b.setAttribute('aria-current', 'true'); if (!m) { m = document.createElement('span'); m.className = 'selmark'; m.textContent = ' (selected)'; b.parentNode.appendChild(m); } }
      else { b.removeAttribute('aria-current'); if (m) m.remove(); }
    });
  }
  function announceCount(prefix) {   // debounced: result counts for the live region
    clearTimeout(annTimer);
    annTimer = setTimeout(function () {
      announce(prefix + (tableRows.length ? fmt(tableRows.length) + ' nodes match. Page ' + (tpage + 1) + ' of ' + pageCount() + '.' : 'No nodes match the current search and filters.'));
    }, 450);
  }
  function rowButtons() { return tbody.querySelectorAll('.rowbtn'); }
  function focusRowBtn(b) { if (b) b.focus(); }
  function goPage(delta, from) {
    var np = tpage + delta; if (np < 0 || np >= pageCount()) return;
    tpage = np; renderTable();
    var from0 = tpage * PAGE + 1, to0 = Math.min(tableRows.length, (tpage + 1) * PAGE);
    announce('Page ' + (tpage + 1) + ' of ' + pageCount() + ', rows ' + from0 + ' to ' + to0 + ' of ' + fmt(tableRows.length) + '.');
    var scroller = $('#tablewrap'); scroller.scrollTop = 0;
    if (from === 'key') focusRowBtn(rowButtons()[0]);
    else if (from && from.disabled) { var other = from.id === 'next' ? $('#prev') : $('#next'); if (!other.disabled) other.focus(); else $('#tcap').focus(); }
  }
  tbody.addEventListener('keydown', function (ev) {
    var b = ev.target.closest('.rowbtn'); if (!b) return;
    var k = ev.key, btns = Array.prototype.slice.call(rowButtons()), ix = btns.indexOf(b);
    if (k === 'ArrowDown') focusRowBtn(btns[ix + 1]); else if (k === 'ArrowUp') focusRowBtn(btns[ix - 1]);
    else if (k === 'Home') focusRowBtn(btns[0]); else if (k === 'End') focusRowBtn(btns[btns.length - 1]);
    else if (k === 'PageDown') goPage(1, 'key'); else if (k === 'PageUp') goPage(-1, 'key');
    else return;
    ev.preventDefault();
  });
  tbody.addEventListener('click', function (ev) { var b = ev.target.closest('.rowbtn'); if (b) select(+b.getAttribute('data-i'), { focus: true }); });
  $('#prev').addEventListener('click', function (ev) { goPage(-1, ev.currentTarget); });
  $('#next').addEventListener('click', function (ev) { goPage(1, ev.currentTarget); });

  /* ---------- view toggle, filters, legend, controls ---------- */
  function setView(v) {
    view = v; var g = v === 'graph', nm = sel >= 0 ? ' Selected: ' + nodes[sel].name + '.' : '';
    cv.hidden = !g; $('#hud').hidden = !g; $('#zoomctl').hidden = !g; $('#tablewrap').hidden = g;
    $('#vGraph').setAttribute('aria-pressed', String(g)); $('#vTable').setAttribute('aria-pressed', String(!g));
    hideResults();
    if (g) {
      resize();
      if (sel >= 0 && wIdx[sel] >= 0) { var n = nodes[sel], x = n.x * tr.k + tr.x, y = n.y * tr.k + tr.y; if (x < 0 || x > W || y < 0 || y > Hh) applyTransform(viewTransform(tr.k, n.x, n.y), false); }
      markDirty(); announce('Graph view.' + nm);
    } else { refreshTable({ followSel: true }); announce('Table view. ' + fmt(tableRows.length) + ' nodes, page ' + (tpage + 1) + ' of ' + pageCount() + '.' + nm); }
    scheduleHash();
  }
  $('#vGraph').addEventListener('click', function () { setView('graph'); });
  $('#vTable').addEventListener('click', function () { setView('table'); });

  var fs = $('#filters');
  TYPES.forEach(function (t) {
    var l = document.createElement('label'); l.className = 'chk';
    l.innerHTML = '<input type="checkbox" checked data-t="' + t + '">' + typeSwatch(t) + t;
    fs.appendChild(l);
  });
  fs.addEventListener('change', function (ev) {
    typeOn[ev.target.getAttribute('data-t')] = ev.target.checked; rebuildLists(); refreshTable(); markDirty(); refreshStatus();
    announce('Showing ' + fmt(visNodes.length) + ' loaded nodes of the selected types.' + (view === 'table' ? ' Table: ' + fmt(tableRows.length) + ' nodes, page ' + (tpage + 1) + ' of ' + pageCount() + '.' : ''));
  });

  var sizeSel = $('#size');
  function reload(changes) { var p = new URLSearchParams(location.search); Object.keys(changes).forEach(function (k) { p.set(k, changes[k]); }); location.href = location.pathname + '?' + p.toString(); }
  if (sizeSel && !IS_HTTP) {   // synthetic-only controls (the prototype page); the production page has neither
    DataGen.SIZES.forEach(function (s) { var o = document.createElement('option'); o.value = s; o.textContent = fmt(s) + ' nodes'; if (s === cfg.size) o.selected = true; sizeSel.appendChild(o); });
    if (DataGen.SIZES.indexOf(cfg.size) < 0) { var o = document.createElement('option'); o.value = cfg.size; o.textContent = fmt(cfg.size) + ' nodes'; o.selected = true; sizeSel.appendChild(o); }
    $('#load').value = cfg.load;
    sizeSel.addEventListener('change', function () { reload({ size: sizeSel.value }); });
    $('#load').addEventListener('change', function (ev) { reload({ load: ev.target.value }); });
  }
  var srcSel = $('#srcSel');   // prototype page only: choose between the labelled synthetic data and the real read API
  if (srcSel && !CFG) { srcSel.value = IS_HTTP ? 'http' : 'synthetic'; srcSel.addEventListener('change', function () { reload({ source: srcSel.value }); }); }

  (function legend() {
    var h = '';
    RELS.forEach(function (r) { h += '<div class="row">' + swatch(r) + '<span><strong>' + r + '</strong><br><small>' + REL[r].desc + '</small></span></div>'; });
    TYPES.forEach(function (t) { h += '<div class="row">' + typeSwatch(t) + '<span><strong>' + t + '</strong> <small>node</small></span></div>'; });
    var br = RELS.filter(function (r) { return REL[r].branch; });
    h += '<div class="row"><span><small>Branch-style (elbow) lines are used only by ' + br.join(' and ') + '. Relationships that are not verified are never shown in public mode.' + (cfg.cluster ? ' Zoomed out, nodes are grouped into company clusters; dashed grey bubbles are groups of companies that split when you zoom in.' : '') + '</small></span></div>';
    $('#legend').innerHTML = h;
  })();
  if (window.innerWidth < 700) $('#controls').open = false;
  if (cfg.mode === 'review') $('#reviewBanner').hidden = false;

  // skip links: focus the target without touching the URL hash (the hash carries the deep link)
  Array.prototype.forEach.call(document.querySelectorAll('[data-skip]'), function (a) {
    a.addEventListener('click', function (ev) {
      ev.preventDefault(); var w = a.getAttribute('data-skip');
      if (w === 'controls') { $('#controls').open = true; $('#controls summary').focus(); }
      else if (w === 'panel') $('#pname').focus();
      else if (view === 'graph') cv.focus(); else $('#tcap').focus();
    });
  });
  // colour scheme changes while the page is open
  var dm = window.matchMedia('(prefers-color-scheme: dark)');
  (dm.addEventListener ? dm.addEventListener.bind(dm, 'change') : dm.addListener.bind(dm))(function () { readTheme(); markDirty(); });

  function refreshStatus() {
    var t = IS_HTTP
      ? fmt(N) + ' verified entities listed' + (ing.truncated ? ' (first ' + fmt(N) + ' of ' + fmt(ing.total) + ', the rest is not loaded)' : '') + ', ' + fmt(edges.length) + ' verified relationships loaded so far (' + fmt(loadedList.length) + ' entities drawn). Select an entity to load its relationships. View: ' + (perf.renderMode || '') + '.' + (limitHit ? ' Load limit reached.' : '')
      : fmt(N) + ' nodes, ' + fmt(edges.length) + (cfg.mode === 'public' ? ' verified' : '') + ' relationships (' + fmt(loadedList.length) + ' nodes loaded, ' + cfg.load + (cfg.autoProgressive ? ', automatic on a small screen' : '') + '). SYNTHETIC TEST DATA. View: ' + (perf.renderMode || '') + '.' + (limitHit ? ' Load limit reached.' : '');
    cv.setAttribute('data-status', t);
    if (!$('#progress').textContent || /nodes, .* relationships \(/.test($('#progress').textContent)) $('#progress').textContent = t;
  }
  /* ---------- scripted zoom/pan for the benchmark ---------- */
  function runScript() {
    return new Promise(function (resolve) {
      ensureQT(); ensureBBox();
      var f = fitTransform(), kf = f.k, fc = [(W / 2 - f.x) / f.k, (Hh / 2 - f.y) / f.k];
      var kd = Math.max(2, kf * 2), tn = qt.find(fc[0], fc[1]) || visNodes[0], tc = [tn.x, tn.y];
      var phases = [['pan-fit', 90], ['zoom-in', 120], ['pan-detail', 90], ['zoom-out', 90]], pi = 0, fi = 0, rec = [], last = 0;
      function setV(k, cx, cy) { var t = viewTransform(k, cx, cy); zoomSel.call(zoomB.transform, t); }
      script = {
        pre: function () {
          var ph = phases[pi], u = fi / ph[1], k, cx, cy;
          if (ph[0] === 'pan-fit') { k = kf; cx = fc[0] + 0.2 * W / k * Math.sin(6.2832 * u); cy = fc[1] + 0.15 * Hh / k * Math.sin(12.566 * u); }
          else if (ph[0] === 'zoom-in') { k = kf * Math.pow(kd / kf, u); cx = fc[0] + (tc[0] - fc[0]) * u; cy = fc[1] + (tc[1] - fc[1]) * u; }
          else if (ph[0] === 'pan-detail') { k = kd; cx = tc[0] + 0.25 * W / k * Math.sin(6.2832 * u); cy = tc[1] + 0.2 * Hh / k * Math.sin(12.566 * u); }
          else { k = kf * Math.pow(kd / kf, 1 - u); cx = tc[0] + (fc[0] - tc[0]) * u; cy = tc[1] + (fc[1] - tc[1]) * u; }
          setV(k, cx, cy);
        },
        post: function (ts, dm) {
          if (last) rec.push({ phase: phases[pi][0], dt: ts - last, draw: dm, mode: perf.renderMode });
          last = ts; fi++;
          if (fi >= phases[pi][1]) { pi++; fi = 0; }
          if (pi >= phases.length) { script = null; resolve({ frames: rec, kf: kf, kd: kd }); }
        }
      };
      requestFrame();
    });
  }
  function benchHit(count) {
    ensureQT(); var k = 2, nn = qt.find(0, 0) || visNodes[0]; ensureBBox();
    var t = viewTransform(k, nn.x, nn.y); zoomSel.call(zoomB.transform, t); ensureQT();
    var s = 12345, rnd = function () { s = (Math.imul(s, 1664525) + 1013904223) | 0; return (s >>> 0) / 4294967296; };
    var pts = [], i;
    for (i = 0; i < count; i++) {
      if (i % 2) pts.push([rnd() * W, rnd() * Hh]);
      else { var v = visNodes[(rnd() * visNodes.length) | 0]; pts.push([tr.applyX(v.x) + (rnd() - 0.5) * 8, tr.applyY(v.y) + (rnd() - 0.5) * 8]); }
    }
    for (i = 0; i < 50; i++) hitNodeScreen(pts[i][0], pts[i][1]);
    var samples = [], hits = 0, a, b;
    for (i = 0; i < count; i++) { a = performance.now(); var h = hitNodeScreen(pts[i][0], pts[i][1]); b = performance.now(); samples.push(b - a); if (h) hits++; }
    a = performance.now(); for (var rep = 0; rep < 5; rep++) for (i = 0; i < count; i++) hitNodeScreen(pts[i][0], pts[i][1]); b = performance.now();
    return { samples: samples, batchMeanMs: (b - a) / (5 * count), hits: hits, count: count, k: k };
  }
  function screenPosOf(id) { var r = cv.getBoundingClientRect(); return { x: r.left + tr.applyX(nodes[id].x), y: r.top + tr.applyY(nodes[id].y) }; }
  function audit() {
    var brancher = RELS.filter(function (r) { return REL[r].branch; });
    return {
      mode: cfg.mode, relTypes: RELS.slice(), branchStyleRels: brancher,
      edgesInUi: edges.length, unverifiedEdgesInUiArray: edges.filter(function (e) { return !e.v; }).length,
      edgesMissingCitation: edges.filter(function (e) { return !e.src; }).length,
      edgesWithoutDate: edges.filter(function (e) { return !e.date; }).length,
      unverifiedDrawnOnCanvas: perf.drawnUnverified,
      distinctDashOrWidth: RELS.map(function (r) { return REL[r].width + '/' + REL[r].dash.join(','); }).filter(function (v, i, a) { return a.indexOf(v) === i; }).length
    };
  }

  /* ---------- init ---------- */
  resize();
  hashView = H0.k && H0.x && H0.y;
  window.GraphApp = {
    cfg: cfg, perf: perf, audit: audit, runScript: runScript, benchHit: benchHit, screenPosOf: screenPosOf, limits: LIM, ingestStats: ing.stats, labelMetrics: labelMetrics,
    select: function (i, o) { select(i, o || {}); },
    setView: function (v) { setView(v); }, getView: function () { return view; },
    getState: function () { return { k: tr.k, x: tr.x, y: tr.y, sel: sel, loaded: loadedList.length, edgesLoaded: loadedEdges.length, N: N, E: edges.length, view: view, W: W, H: Hh, renderMode: perf.renderMode, visible: visNodes.length, tableCount: tableRows.length, tablePage: tpage, bubbles: perf.bubblesDrawn, limits: LIM.name, autoProgressive: cfg.autoProgressive, load: cfg.load }; },
    nearestToCentre: function () { ensureQT(); var p = tr.invert([W / 2, Hh / 2]), n = qt.find(p[0], p[1]); return n ? { id: n.i, pos: screenPosOf(n.i) } : null; },
    zoomDuration: dur, hash: function () { writeHash(); return location.hash; },
    nodes: function () { return nodes; }, adj: function () { return adj; }, edges: function () { return edges; },
    source: source,
    setTransformWorld: function (k, wx, wy) { zoomSel.call(zoomB.transform, viewTransform(k, wx, wy)); },
    fit: function () { fit(false); },
    settledFrameCost: function () { return new Promise(function (res) { clearTimeout(settleTimer); interacting = false; layoutBusy = false; updateLowQ(); var t = performance.now(); markDirty(); requestAnimationFrame(function () { requestAnimationFrame(function () { res({ ms: performance.now() - t, edgesDrawn: perf.edgesDrawn, edgesVisible: perf.edgesVisible, mode: perf.renderMode }); }); }); }); }, checksum: function () { return source.checksum ? source.checksum() : null; }, isHttp: IS_HTTP, expandHttp: function (i, more) { expandHttp(i, !!more); }, ingestTotal: { total: ing.total, truncated: ing.truncated }, meta: { size: N, companies: NC }
  };
  $('#progress').textContent = '';
  startWorker(); rebuildLists();
  if (hashView) { var hk = Math.max(0.01, Math.min(16, +H0.k)); applyTransform(viewTransform(hk, +H0.x, +H0.y), false); }
  else fit(false);
  var wantSel = CFG && CFG.slug && ing.bySlug[CFG.slug] !== undefined ? CFG.slug : H0.sel;
  if (wantSel && ing.bySlug[wantSel] !== undefined) select(ing.bySlug[wantSel], {});
  if (view === 'table') setView('table'); else { cv.hidden = false; }
  if (P.get('view') === 'table') { $('#vTable').setAttribute('aria-pressed', 'true'); $('#vGraph').setAttribute('aria-pressed', 'false'); }
  refreshTable(); refreshStatus(); markDirty();
  window.addEventListener('hashchange', function () { /* deep link edited by hand: reload view */
    var h = parseHash(); if (h.k && h.x && h.y) applyTransform(viewTransform(+h.k, +h.x, +h.y), false);
    if (h.sel && ing.bySlug[h.sel] !== undefined && ing.bySlug[h.sel] !== sel) select(ing.bySlug[h.sel], {});
  });
  } /* end boot */
})();
