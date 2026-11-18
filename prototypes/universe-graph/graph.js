/* graph.js - canvas renderer + UI for the AI Universe graph prototype (SYNTHETIC data only).
 * Uses d3-force (in layout-worker.js), d3-zoom, d3-drag, d3-quadtree, d3-selection. No framework.
 */
(function () {
  'use strict';
  var $ = function (s) { return document.querySelector(s); };

  /* ---------- config from URL ---------- */
  var P = new URLSearchParams(location.search);
  var cfg = {
    size: +P.get('size') || 500,
    load: P.get('load') === 'progressive' ? 'progressive' : 'all',
    cluster: P.get('cluster') !== 'off',
    // 'public' never renders unverified edges. Review mode can NOT be switched on from the URL (QA finding): it needs a flag that only an
    // authenticated editorial page may set, and a real deployment must also filter on the server (state='verified' AND is_current=1).
    mode: (window.UNIVERSE_REVIEW_ENABLED === true && P.get('mode') === 'review') ? 'review' : 'public',
    bench: P.has('bench'),
    settleCap: +P.get('settleCap') || 0
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

  /* ---------- visual vocabulary ---------- */
  var TYPES = DataGen.TYPES;
  var COL = { company: '#6d28d9', product: '#c2410c', model: '#0b5cad', family: '#15803d', technology: '#a16207' };
  var BASER = { company: 9, product: 6, model: 4.5, family: 6.5, technology: 5.5 };
  // Only technical_ancestry and product_succession are allowed `branch: true` (elbow/branch-style line).
  var REL = {
    technical_ancestry:     { color: '#0b5cad', width: 2,   dash: [],            branch: true,  directed: true,  desc: 'derived from / built on (branch-style, solid)' },
    product_succession:     { color: '#c2410c', width: 2,   dash: [7, 4],        branch: true,  directed: true,  desc: 'replaced by a later product (branch-style, dashed)' },
    company_ownership:      { color: '#6d28d9', width: 3.2, dash: [],            branch: false, directed: true,  desc: 'owns / acquired (thick straight line)' },
    integration:            { color: '#047857', width: 1.6, dash: [10, 4],       branch: false, directed: true,  desc: 'used by / built into (long dashes, straight)' },
    similarity:             { color: '#6b7280', width: 1.4, dash: [1, 4],        branch: false, directed: false, desc: 'functionally similar, no lineage (dotted, straight)' },
    historical_association: { color: '#a16207', width: 1.6, dash: [10, 3, 2, 3], branch: false, directed: false, desc: 'past link without ownership (dash-dot, straight)' }
  };
  var RELS = DataGen.RELS;
  var CLUSTER_MAX = 2500, EDGE_MAX = 9000, LABEL_NODE_MAX = 700, EDGE_LABEL_MAX = 120;

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  function dur() { return reduced.matches ? 0 : 450; }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(n) { return n.toLocaleString('en-US'); }

  /* ---------- perf / bench record ---------- */
  var perf = { dataGenMs: 0, firstPaint: 0, settled: false, settleMs: 0, layoutTicks: 0, layoutCapped: false, qtBuildMs: 0,
               drawnUnverified: 0, clusterFrames: 0, detailFrames: 0, lastClickMs: 0, expandMs: 0, expandAdded: 0, lastDrawMs: 0 };

  /* ---------- data ---------- */
  var t0 = performance.now();
  var data = DataGen.generate(cfg.size, 42);
  perf.dataGenMs = performance.now() - t0;
  var nodes = data.nodes, N = nodes.length, NC = data.meta.companies;
  var edges = cfg.mode === 'review' ? data.edges : data.edges.filter(function (e) { return e.v === true; });
  var adj = new Array(N), i;
  for (i = 0; i < N; i++) adj[i] = [];
  edges.forEach(function (e, k) { e.k = k; adj[e.s].push(k); adj[e.t].push(k); });
  var typeOn = {}; TYPES.forEach(function (t) { typeOn[t] = true; });

  /* ---------- loading state (worker index space) ---------- */
  var wIdx = new Int32Array(N).fill(-1), loadedList = [], loadedEdges = [], edgeLoaded = new Uint8Array(edges.length);
  var worker = null, layoutStart = 0, initDone = false;
  var visNodes = [], visByType = {}, edgesByRel = {}, edgesUnv = [];
  var qt = null, qtDirty = true, clusters = [], clustersDirty = true;
  var bbox = { x0: 0, y0: 0, x1: 1, y1: 1 }, bboxDirty = true;
  var sel = -1, userMoved = false;

  function centreOf(c) { var a = c * 2.399963, r = 200 * Math.sqrt(c + 0.5); return [r * Math.cos(a), r * Math.sin(a)]; }
  function hash01(i, s) { var h = Math.imul(i + 1, 2654435761) ^ Math.imul(s + 7, 40503); h ^= h >>> 15; h = Math.imul(h, 2246822519); h ^= h >>> 13; return (h >>> 0) / 4294967296; }

  function sendAdd(ids, first) {
    var m = ids.length, cluster = new Int32Array(m), x = new Float32Array(m), y = new Float32Array(m), tx = new Float32Array(m), ty = new Float32Array(m);
    var j, k, id, nd;
    for (j = 0; j < m; j++) {
      id = ids[j]; nd = nodes[id];
      var anchor = wIdx[nd.c] >= 0 && nd.c !== id ? nodes[nd.c] : null;
      var cc = centreOf(nd.c), bx = anchor ? anchor.x : cc[0], by = anchor ? anchor.y : cc[1];
      var jr = nd.type === 'company' ? 0 : 70 * Math.sqrt(hash01(id, 1)), ja = 6.2832 * hash01(id, 2);
      nd.x = bx + jr * Math.cos(ja); nd.y = by + jr * Math.sin(ja);
      wIdx[id] = loadedList.length; loadedList.push(nd);
      cluster[j] = nd.c; x[j] = nd.x; y[j] = nd.y; tx[j] = bx; ty[j] = by;
    }
    var lk = [];
    for (j = 0; j < m; j++) {
      var a = adj[ids[j]];
      for (k = 0; k < a.length; k++) {
        var e = edges[a[k]];
        if (!edgeLoaded[e.k] && wIdx[e.s] >= 0 && wIdx[e.t] >= 0) { edgeLoaded[e.k] = 1; loadedEdges.push(e); lk.push(wIdx[e.s], wIdx[e.t]); }
      }
    }
    var ticks = first ? (cfg.size <= 500 ? 300 : cfg.size <= 5000 ? 220 : 120) : 80;
    var msg = { type: first ? 'init' : 'add', cluster: cluster, x: x, y: y, tx: tx, ty: ty, links: Int32Array.from(lk), ticks: ticks };
    if (first) layoutStart = performance.now();
    layoutBusy = true; updateLowQ();
    worker.postMessage(msg, [cluster.buffer, x.buffer, y.buffer, tx.buffer, ty.buffer, msg.links.buffer]);
  }

  function startWorker() {
    worker = new Worker('layout-worker.js');
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
    if (cfg.load === 'progressive') { for (c = 0; c < NC; c++) ids.push(c); } else { for (c = 0; c < N; c++) ids.push(c); }
    sendAdd(ids, true);
    if (cfg.settleCap) setTimeout(function () { if (!initDone) worker.postMessage({ type: 'stop' }); }, cfg.settleCap);
  }

  function onLayoutDone(m) {
    if (!initDone) {
      initDone = true; perf.settleMs = performance.now() - layoutStart; perf.layoutCapped = !!m.capped;
      if (!hashView && !userMoved) fit(true);
      announce('Layout finished. ' + fmt(loadedList.length) + ' nodes loaded. SYNTHETIC TEST DATA.');
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
    for (i = 0; i < NC; i++) if (cnt[i]) clusters.push({ c: i, n: cnt[i], x: sx[i] / cnt[i], y: sy[i] / cnt[i] });
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
  var dirty = true, rafId = 0, script = null, pendingClick = 0, firstDrawn = false;
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
      case 'family': ctx.moveTo(x, y - r * 1.25); ctx.lineTo(x + r * 1.15, y + r * 0.95); ctx.lineTo(x - r * 1.15, y + r * 0.95); ctx.closePath(); break;
      case 'technology': for (var q = 0; q < 6; q++) { var a = q * 1.0472; var px = x + r * 1.1 * Math.cos(a), py = y + r * 1.1 * Math.sin(a); if (q) ctx.lineTo(px, py); else ctx.moveTo(px, py); } ctx.closePath(); break;
      default: ctx.moveTo(x + r, y); ctx.arc(x, y, r, 0, 6.2832);
    }
  }
  function nodeR(t) { return BASER[t] * Math.max(0.45, Math.min(2.2, Math.sqrt(tr.k))); }
  function edgeText(e) { return e.rel + ' | ' + e.src + (e.date ? ' | verified ' + e.date : '') + (e.v ? '' : ' | UNVERIFIED'); }
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
  var EDGE_BUDGET_LOW = 3500, EDGE_BUDGET_FULL = 6000;

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
    var budget = lowQ ? EDGE_BUDGET_LOW : EDGE_BUDGET_FULL, stride = Math.max(1, Math.ceil(total / budget)), drawn = 0;
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
          if (drawn <= EDGE_LABEL_MAX && k >= 2.5) labelOut.push(e);
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
      ctx.beginPath(); ctx.strokeStyle = '#b91c1c'; ctx.lineWidth = 1; ctx.setLineDash([2, 2]);
      for (var u = 0; u < edgesUnv.length; u++) { var ee = edgesUnv[u], aa = nodes[ee.s], bb = nodes[ee.t]; ctx.moveTo(aa.x * k + tx, aa.y * k + ty); ctx.lineTo(bb.x * k + tx, bb.y * k + ty); }
      ctx.stroke(); ctx.setLineDash([]);
    }
  }

  // Node glyphs are pre-rendered once per (type, radius) and blitted: polygon paths are very slow to rasterise in bulk.
  var sprites = {};
  function sprite(t, r) {
    var rr = Math.max(3, Math.round(r)), key = t + rr + '@' + dpr;
    if (sprites[key]) return sprites[key];
    var size = Math.ceil(rr * 2.9 + 4), c = document.createElement('canvas'); c.width = c.height = Math.ceil(size * dpr);
    var g = c.getContext('2d'); g.scale(dpr, dpr);
    var saved = ctx; ctx = g; g.beginPath(); shape(t, size / 2, size / 2, rr); g.fillStyle = COL[t]; g.fill(); g.lineWidth = 1; g.strokeStyle = '#fff'; g.stroke(); ctx = saved;
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
  function drawLabels() {
    ctx.font = '11px system-ui,sans-serif'; ctx.textBaseline = 'middle'; ctx.lineWidth = 3; ctx.strokeStyle = '#fff'; ctx.fillStyle = '#111';
    var c = 0, k = tr.k;
    for (var q = 0; q < visNodes.length && c < 300; q++) {
      var n = visNodes[q], x = n.x * k + tr.x, y = n.y * k + tr.y;
      if (x < 0 || x > W || y < 0 || y > Hh) continue;
      var tx = x + nodeR(n.type) + 3; ctx.strokeText(n.name, tx, y); ctx.fillText(n.name, tx, y); c++;
    }
  }
  function drawClusters() {
    ensureClusters(); var k = tr.k, labels = 0;
    ctx.font = '11px system-ui,sans-serif'; ctx.textBaseline = 'middle'; ctx.textAlign = 'center';
    for (var q = 0; q < clusters.length; q++) {
      var c = clusters[q], x = c.x * k + tr.x, y = c.y * k + tr.y, r = Math.max(3, 14 * Math.sqrt(c.n) * k);
      if (x < -r || x > W + r || y < -r || y > Hh + r) continue;
      ctx.beginPath(); ctx.arc(x, y, r, 0, 6.2832);
      ctx.fillStyle = 'hsla(' + ((c.c * 47) % 360) + ',55%,48%,0.35)'; ctx.fill();
      ctx.lineWidth = 1; ctx.strokeStyle = 'hsl(' + ((c.c * 47) % 360) + ',55%,32%)'; ctx.stroke();
      if (r >= 16 && labels < 200) { ctx.fillStyle = '#111'; ctx.fillText(nodes[c.c].name + ' (' + c.n + ')', x, y); labels++; }
    }
    ctx.textAlign = 'start';
  }
  function drawSelection() {
    if (sel < 0 || wIdx[sel] < 0) return;
    var n = nodes[sel], k = tr.k, x = n.x * k + tr.x, y = n.y * k + tr.y, a = adj[sel], q;
    ctx.font = '11px system-ui,sans-serif'; ctx.textBaseline = 'middle';
    var labelled = 0;
    for (q = 0; q < a.length; q++) {
      var e = edges[a[q]]; if (wIdx[e.s] < 0 || wIdx[e.t] < 0) continue;
      var s = nodes[e.s], t = nodes[e.t], d = REL[e.rel];
      var ax = s.x * k + tr.x, ay = s.y * k + tr.y, bx = t.x * k + tr.x, by = t.y * k + tr.y;
      ctx.beginPath(); edgePath(e, e.rel, s, t, ax, ay, bx, by);
      ctx.setLineDash([]); ctx.lineWidth = d.width + 2.5; ctx.strokeStyle = 'rgba(255,200,0,.6)'; ctx.stroke();
      ctx.setLineDash(d.dash); ctx.lineWidth = d.width; ctx.strokeStyle = d.color; ctx.stroke(); ctx.setLineDash([]);
      if (labelled < 24) {
        var txt = edgeText(e), mx = (ax + bx) / 2, my = (ay + by) / 2, w = ctx.measureText(txt).width + 8;
        ctx.fillStyle = 'rgba(255,255,255,.92)'; ctx.fillRect(mx - w / 2, my - 8, w, 16); ctx.fillStyle = '#111'; ctx.textAlign = 'center'; ctx.fillText(txt, mx, my); ctx.textAlign = 'start'; labelled++;
      }
      var o = e.s === sel ? t : s; ctx.beginPath(); ctx.arc(o.x * k + tr.x, o.y * k + tr.y, nodeR(o.type) + 4, 0, 6.2832); ctx.lineWidth = 2; ctx.strokeStyle = '#111'; ctx.stroke();
    }
    ctx.beginPath(); ctx.arc(x, y, nodeR(n.type) + 6, 0, 6.2832); ctx.lineWidth = 3; ctx.strokeStyle = '#000'; ctx.stroke();
    ctx.beginPath(); ctx.arc(x, y, nodeR(n.type) + 9, 0, 6.2832); ctx.lineWidth = 2; ctx.strokeStyle = '#f5a623'; ctx.stroke();
  }

  var lastMode = '';
  function draw() {
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.fillStyle = '#fbfbf8'; ctx.fillRect(0, 0, W, Hh);
    var est = estVisible(), clustered = cfg.cluster && est > CLUSTER_MAX, labelEdges = [];
    if (clustered) { drawClusters(); perf.clusterFrames++; }
    else {
      if (est <= EDGE_MAX) drawEdges(labelEdges, !lowQ); else { perf.edgesVisible = perf.edgesDrawn = 0; }
      drawNodes();
      if (tr.k >= 1.6 && est <= LABEL_NODE_MAX) drawLabels();
      if (labelEdges.length) {
        ctx.font = '10px system-ui,sans-serif'; ctx.textBaseline = 'middle'; ctx.textAlign = 'center';
        for (var q = 0; q < labelEdges.length; q++) {
          var e = labelEdges[q], a = nodes[e.s], b = nodes[e.t], txt = edgeText(e), mx = (a.x + b.x) / 2 * tr.k + tr.x, my = (a.y + b.y) / 2 * tr.k + tr.y, w = ctx.measureText(txt).width + 6;
          ctx.fillStyle = 'rgba(255,255,255,.88)'; ctx.fillRect(mx - w / 2, my - 7, w, 14); ctx.fillStyle = REL[e.rel].color; ctx.fillText(txt, mx, my);
        }
        ctx.textAlign = 'start';
      }
      perf.detailFrames++;
    }
    drawSelection();
    ctx.font = 'bold 12px system-ui,sans-serif'; ctx.textBaseline = 'alphabetic'; ctx.fillStyle = 'rgba(138,28,28,.9)'; ctx.fillText('SYNTHETIC TEST DATA', 8, Hh - 26);
    var md = clustered ? 'clustered by company (zoom in or click a cluster)' : (est > EDGE_MAX ? 'nodes only (edges hidden while zoomed out)' : 'full detail');
    if (md !== lastMode) { lastMode = md; refreshStatus(); }
    perf.renderMode = md;
    if (pendingClick) { perf.lastClickMs = performance.now() - pendingClick; pendingClick = 0; }
    if (!firstDrawn && loadedList.length) {
      firstDrawn = true;
      requestAnimationFrame(function () { requestAnimationFrame(function () { perf.firstPaint = performance.now(); }); });
    }
  }

  /* ---------- hit testing ---------- */
  var touchy = window.matchMedia('(pointer: coarse)');
  function hitNodeScreen(sx, sy) {
    ensureQT();
    var p = tr.invert([sx, sy]), r = (touchy.matches ? 22 : 10) / tr.k;
    return qt.find(p[0], p[1], r) || null;
  }
  function hitClusterScreen(sx, sy) {
    ensureClusters(); var best = null, bd = 1e9;
    for (var q = 0; q < clusters.length; q++) {
      var c = clusters[q], x = c.x * tr.k + tr.x - sx, y = c.y * tr.k + tr.y - sy, r = Math.max(10, 14 * Math.sqrt(c.n) * tr.k), d = x * x + y * y;
      if (d <= r * r && d < bd) { bd = d; best = c; }
    }
    return best;
  }
  cv.addEventListener('click', function (ev) {
    pendingClick = performance.now();
    var r = cv.getBoundingClientRect(), sx = ev.clientX - r.left, sy = ev.clientY - r.top;
    var clustered = cfg.cluster && estVisible() > CLUSTER_MAX;
    if (clustered) {
      var c = hitClusterScreen(sx, sy);
      if (c) { userMoved = true; applyTransform(viewTransform(Math.max(tr.k * 4, 0.35), c.x, c.y), true); }
      pendingClick = 0; return;
    }
    var n = hitNodeScreen(sx, sy);
    if (n) select(n.i, {}); else if (sel >= 0) select(-1, {});
    markDirty();
  });
  cv.addEventListener('keydown', function (ev) {
    var k = ev.key, step = 60, t = tr;
    if (k === 'ArrowLeft') t = t.translate(step / t.k, 0); else if (k === 'ArrowRight') t = t.translate(-step / t.k, 0);
    else if (k === 'ArrowUp') t = t.translate(0, step / t.k); else if (k === 'ArrowDown') t = t.translate(0, -step / t.k);
    else if (k === '+' || k === '=') { zoomSel.call(zoomB.scaleBy, 1.4); userMoved = true; ev.preventDefault(); return; }
    else if (k === '-' || k === '_') { zoomSel.call(zoomB.scaleBy, 1 / 1.4); userMoved = true; ev.preventDefault(); return; }
    else if (k === 'Escape') { select(-1, {}); return; }
    else return;
    ev.preventDefault(); userMoved = true; zoomSel.call(zoomB.transform, t);
  });

  /* ---------- selection, panel, progressive loading ---------- */
  var lastFocus = null;
  function expand(id) {
    if (cfg.load !== 'progressive') return;
    var a0 = performance.now(), ids = [], seen = {}, q;
    if (wIdx[id] < 0) { ids.push(id); seen[id] = 1; }
    var a = adj[id];
    for (q = 0; q < a.length && ids.length < 400; q++) {
      var e = edges[a[q]], o = e.s === id ? e.t : e.s;
      if (wIdx[o] < 0 && !seen[o]) { seen[o] = 1; ids.push(o); }
    }
    if (ids.length) { sendAdd(ids, false); rebuildLists(); }
    perf.expandMs = performance.now() - a0; perf.expandAdded = ids.length; refreshStatus();
  }
  function select(id, opts) {
    sel = id;
    if (id >= 0) { expand(id); if (opts.center) { userMoved = true; applyTransform(viewTransform(Math.max(tr.k, 1.6), nodes[id].x, nodes[id].y), true); } }
    renderPanel(opts); updateTableSel(); scheduleHash(); markDirty();
    if (id >= 0) announce('Selected ' + nodes[id].name + ', ' + nodes[id].type + ', ' + adj[id].length + ' verified relationships.');
  }
  function swatch(rel) {
    var d = REL[rel], p = d.branch ? 'M2 16H20V4H54' : 'M2 10H54', da = d.dash.length ? ' stroke-dasharray="' + d.dash.join(' ') + '"' : '';
    return '<svg width="56" height="20" viewBox="0 0 56 20" aria-hidden="true" focusable="false"><path d="' + p + '" fill="none" stroke="' + d.color + '" stroke-width="' + d.width + '"' + da + ' stroke-linecap="' + (rel === 'similarity' ? 'round' : 'butt') + '"/></svg>';
  }
  function typeSwatch(t) {
    var s = 'M10 2h12v12H10z';
    if (t === 'product') s = 'M16 1l9 8-9 8-9-8z'; else if (t === 'family') s = 'M16 1l9 15H7z'; else if (t === 'technology') s = 'M23 9l-3.5 6h-7L9 9l3.5-6h7z'; else if (t === 'model') s = 'M16 2a7 7 0 1 0 .01 0z';
    return '<svg width="32" height="18" viewBox="0 0 32 18" aria-hidden="true" focusable="false"><path d="' + s + '" fill="' + COL[t] + '"/></svg>';
  }
  function renderPanel(opts) {
    var panel = $('#panel'), main = $('#main');
    if (sel < 0) { panel.hidden = true; main.classList.remove('has-sel'); if (lastFocus && opts && opts.fromClose) lastFocus.focus(); return; }
    var n = nodes[sel], a = adj[sel];
    $('#pname').textContent = n.name;
    $('#pdl').innerHTML = '<dt>Type</dt><dd>' + typeSwatch(n.type) + esc(n.type) + '</dd><dt>ID</dt><dd>' + esc(n.id) + '</dd><dt>Company</dt><dd>' + esc(nodes[n.c].name) + '</dd><dt>Relationships</dt><dd>' + a.length + (cfg.mode === 'public' ? ' (verified only)' : ' (review mode)') + '</dd><dt>Data</dt><dd>SYNTHETIC TEST DATA</dd>';
    var html = '', shown = Math.min(a.length, 120);
    for (var q = 0; q < shown; q++) {
      var e = edges[a[q]], out = e.s === sel, o = nodes[out ? e.t : e.s];
      html += '<li><div class="rel">' + swatch(e.rel) + '<span>' + esc(e.rel) + '</span>' + (e.v ? '' : '<span class="badge">UNVERIFIED</span>') + '</div>' +
        '<div>' + (out ? 'to ' : 'from ') + '<button class="linkbtn" type="button" data-go="' + o.i + '">' + esc(o.name) + '</button> <small>(' + esc(o.type) + (wIdx[o.i] < 0 ? ', not loaded yet' : '') + ')</small></div>' +
        '<div class="cite">Source: ' + esc(e.src) + ' (synthetic citation)<br>' + (e.date ? 'Verified: ' + esc(e.date) : 'Verification date: not recorded') + '</div></li>';
    }
    if (a.length > shown) html += '<li>' + (a.length - shown) + ' more relationships not listed.</li>';
    if (!a.length) html += '<li>No verified relationships.</li>';
    $('#edges').innerHTML = html;
    panel.hidden = false; main.classList.add('has-sel');
    if (opts && opts.focus) { lastFocus = document.activeElement; $('#pname').focus(); }
  }
  $('#edges').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-go]'); if (b) select(+b.getAttribute('data-go'), { center: view === 'graph' });
  });
  $('#pclose').addEventListener('click', function () { select(-1, { fromClose: true }); });
  function announce(t) { $('#live').textContent = t; }

  /* ---------- hash deep link ---------- */
  var hashTimer = 0;
  function scheduleHash() { clearTimeout(hashTimer); hashTimer = setTimeout(writeHash, 250); }
  function writeHash() {
    var cx = (W / 2 - tr.x) / tr.k, cy = (Hh / 2 - tr.y) / tr.k, parts = [];
    if (sel >= 0) parts.push('sel=' + nodes[sel].id);
    parts.push('k=' + tr.k.toFixed(3), 'x=' + cx.toFixed(1), 'y=' + cy.toFixed(1));
    if (view === 'table') parts.push('view=table');
    try { history.replaceState(null, '', location.pathname + location.search + '#' + parts.join('&')); } catch (e) { /* ignore */ }
  }

  /* ---------- search ---------- */
  var qEl = $('#q'), resEl = $('#results'), lname = null, resIdx = -1, resItems = [];
  function lowerNames() { if (!lname) lname = nodes.map(function (n) { return n.name.toLowerCase(); }); return lname; }
  function hideResults() { resEl.style.display = 'none'; qEl.setAttribute('aria-expanded', 'false'); qEl.removeAttribute('aria-activedescendant'); resIdx = -1; }
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
    resEl.innerHTML = out.map(function (i, j) { return '<li role="option" id="ro' + j + '" data-i="' + i + '" aria-selected="false">' + esc(nodes[i].name) + ' <small>&nbsp;(' + nodes[i].type + ')</small></li>'; }).join('') || '<li role="option" aria-disabled="true">No matches</li>';
    var r = qEl.getBoundingClientRect(); resEl.style.left = r.left + 'px'; resEl.style.top = (r.bottom + 2) + 'px'; resEl.style.minWidth = r.width + 'px';
    resEl.style.display = 'block'; qEl.setAttribute('aria-expanded', 'true'); resIdx = -1;
  }
  function pickResult(i) { hideResults(); qEl.value = nodes[i].name; refreshTable(); select(i, { center: true, focus: false }); cv.focus(); }
  var deb = 0;
  qEl.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(function () { refreshTable(); showResults(); }, 80); });
  qEl.addEventListener('keydown', function (ev) {
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      if (resEl.style.display !== 'block') return; ev.preventDefault();
      resIdx = (resIdx + (ev.key === 'ArrowDown' ? 1 : -1) + resItems.length) % Math.max(1, resItems.length);
      Array.prototype.forEach.call(resEl.children, function (li, j) { li.setAttribute('aria-selected', j === resIdx ? 'true' : 'false'); });
      qEl.setAttribute('aria-activedescendant', 'ro' + resIdx);
    } else if (ev.key === 'Enter') {
      var pick = resIdx >= 0 ? resItems[resIdx] : resItems[0]; if (pick != null && resEl.style.display === 'block') { ev.preventDefault(); pickResult(pick); }
    } else if (ev.key === 'Escape') hideResults();
  });
  resEl.addEventListener('mousedown', function (ev) { var li = ev.target.closest('li[data-i]'); if (li) { ev.preventDefault(); pickResult(+li.getAttribute('data-i')); } });
  document.addEventListener('click', function (ev) { if (ev.target !== qEl && !resEl.contains(ev.target)) hideResults(); });

  /* ---------- table (accessible alternative) ---------- */
  var tbody = $('#tbody'), PAGE = 50, tpage = 0, tableRows = [];
  function refreshTable() {
    var q = qEl.value.trim().toLowerCase(), L = q ? lowerNames() : null; tableRows = [];
    for (var i = 0; i < N; i++) if (typeOn[nodes[i].type] && (!q || L[i].indexOf(q) >= 0)) tableRows.push(i);
    tpage = Math.min(tpage, Math.max(0, Math.ceil(tableRows.length / PAGE) - 1)); renderTable();
  }
  function renderTable() {
    var from = tpage * PAGE, to = Math.min(tableRows.length, from + PAGE), h = '';
    for (var q = from; q < to; q++) {
      var n = nodes[tableRows[q]];
      h += '<tr role="row" tabindex="' + (q === from ? 0 : -1) + '" data-i="' + n.i + '" aria-rowindex="' + (q + 2) + '" aria-selected="' + (n.i === sel) + '" aria-label="' + esc(n.name) + ', ' + n.type + ', company ' + esc(nodes[n.c].name) + ', ' + adj[n.i].length + ' relationships">' +
        '<td role="gridcell">' + esc(n.name) + '</td><td role="gridcell">' + n.type + '</td><td role="gridcell">' + esc(nodes[n.c].name) + '</td><td role="gridcell">' + adj[n.i].length + '</td></tr>';
    }
    tbody.innerHTML = h || '<tr><td colspan="4">No nodes match.</td></tr>';
    var pages = Math.max(1, Math.ceil(tableRows.length / PAGE));
    $('#pageinfo').textContent = fmt(tableRows.length) + ' nodes, page ' + (tpage + 1) + ' of ' + pages;
    $('#prev').disabled = tpage === 0; $('#next').disabled = tpage >= pages - 1;
    $('#tbl').setAttribute('aria-rowcount', tableRows.length + 1);
  }
  function updateTableSel() { Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-i]'), function (r) { r.setAttribute('aria-selected', String(+r.getAttribute('data-i') === sel)); }); }
  function focusRow(r) { if (!r) return; Array.prototype.forEach.call(tbody.querySelectorAll('tr[tabindex="0"]'), function (x) { x.tabIndex = -1; }); r.tabIndex = 0; r.focus(); }
  tbody.addEventListener('keydown', function (ev) {
    var r = ev.target.closest('tr[data-i]'); if (!r) return;
    var k = ev.key, rows = tbody.querySelectorAll('tr[data-i]');
    if (k === 'ArrowDown') focusRow(r.nextElementSibling);
    else if (k === 'ArrowUp') focusRow(r.previousElementSibling);
    else if (k === 'Home') focusRow(rows[0]); else if (k === 'End') focusRow(rows[rows.length - 1]);
    else if (k === 'PageDown') { if (tpage < Math.ceil(tableRows.length / PAGE) - 1) { tpage++; renderTable(); focusRow(tbody.querySelector('tr[data-i]')); } }
    else if (k === 'PageUp') { if (tpage > 0) { tpage--; renderTable(); focusRow(tbody.querySelector('tr[data-i]')); } }
    else if (k === 'Enter' || k === ' ') { select(+r.getAttribute('data-i'), { focus: true }); }
    else return;
    ev.preventDefault();
  });
  tbody.addEventListener('click', function (ev) { var r = ev.target.closest('tr[data-i]'); if (r) { focusRow(r); select(+r.getAttribute('data-i'), { focus: true }); } });
  $('#prev').addEventListener('click', function () { if (tpage > 0) { tpage--; renderTable(); } });
  $('#next').addEventListener('click', function () { tpage++; renderTable(); });

  /* ---------- view toggle, filters, legend, controls ---------- */
  function setView(v) {
    view = v; var g = v === 'graph';
    cv.hidden = !g; $('#hud').hidden = !g; $('#tablewrap').hidden = g;
    $('#vGraph').setAttribute('aria-pressed', String(g)); $('#vTable').setAttribute('aria-pressed', String(!g));
    hideResults(); if (g) { resize(); markDirty(); } else refreshTable();
    scheduleHash(); announce(g ? 'Graph view' : 'Table view');
  }
  $('#vGraph').addEventListener('click', function () { setView('graph'); });
  $('#vTable').addEventListener('click', function () { setView('table'); });

  var fs = $('#filters');
  TYPES.forEach(function (t) {
    var l = document.createElement('label'); l.className = 'chk';
    l.innerHTML = '<input type="checkbox" checked data-t="' + t + '" aria-label="Show ' + t + ' nodes">' + typeSwatch(t) + t;
    fs.appendChild(l);
  });
  fs.addEventListener('change', function (ev) { typeOn[ev.target.getAttribute('data-t')] = ev.target.checked; rebuildLists(); refreshTable(); markDirty(); refreshStatus(); });

  var sizeSel = $('#size');
  DataGen.SIZES.forEach(function (s) { var o = document.createElement('option'); o.value = s; o.textContent = fmt(s) + ' nodes'; if (s === cfg.size) o.selected = true; sizeSel.appendChild(o); });
  if (DataGen.SIZES.indexOf(cfg.size) < 0) { var o = document.createElement('option'); o.value = cfg.size; o.textContent = fmt(cfg.size) + ' nodes'; o.selected = true; sizeSel.appendChild(o); }
  $('#load').value = cfg.load;
  function reload(changes) { var p = new URLSearchParams(location.search); Object.keys(changes).forEach(function (k) { p.set(k, changes[k]); }); location.href = location.pathname + '?' + p.toString(); }
  sizeSel.addEventListener('change', function () { reload({ size: sizeSel.value }); });
  $('#load').addEventListener('change', function (ev) { reload({ load: ev.target.value }); });

  (function legend() {
    var h = '';
    RELS.forEach(function (r) { h += '<div class="row">' + swatch(r) + '<span><strong>' + r + '</strong><br><small>' + REL[r].desc + '</small></span></div>'; });
    TYPES.forEach(function (t) { h += '<div class="row">' + typeSwatch(t) + '<span><strong>' + t + '</strong> <small>node</small></span></div>'; });
    h += '<div class="row"><span><small>Branch-style (elbow) lines are used only by technical_ancestry and product_succession. Relationships that are not verified are never shown in public mode. Zoomed out, nodes are grouped into company clusters.</small></span></div>';
    $('#legend').innerHTML = h;
  })();
  if (window.innerWidth < 700) $('#controls').open = false;
  if (cfg.mode === 'review') $('#reviewBanner').hidden = false;

  function refreshStatus() {
    var t = fmt(N) + ' nodes, ' + fmt(edges.length) + (cfg.mode === 'public' ? ' verified' : '') + ' relationships (' + fmt(loadedList.length) + ' nodes loaded, ' + cfg.load + '). SYNTHETIC TEST DATA. View: ' + (perf.renderMode || '');
    cv.setAttribute('data-status', t);
    if (!$('#progress').textContent) $('#progress').textContent = t;
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
    cfg: cfg, perf: perf, audit: audit, runScript: runScript, benchHit: benchHit, screenPosOf: screenPosOf,
    select: function (i, o) { select(i, o || {}); },
    setView: function (v) { setView(v); }, getView: function () { return view; },
    getState: function () { return { k: tr.k, x: tr.x, y: tr.y, sel: sel, loaded: loadedList.length, edgesLoaded: loadedEdges.length, N: N, E: edges.length, view: view, W: W, H: Hh, renderMode: perf.renderMode, visible: visNodes.length, tableCount: tableRows.length }; },
    nearestToCentre: function () { ensureQT(); var p = tr.invert([W / 2, Hh / 2]), n = qt.find(p[0], p[1]); return n ? { id: n.i, pos: screenPosOf(n.i) } : null; },
    zoomDuration: dur, hash: function () { writeHash(); return location.hash; },
    nodes: function () { return nodes; }, adj: function () { return adj; }, edges: function () { return edges; },
    setTransformWorld: function (k, wx, wy) { zoomSel.call(zoomB.transform, viewTransform(k, wx, wy)); },
    fit: function () { fit(false); },
    settledFrameCost: function () { return new Promise(function (res) { clearTimeout(settleTimer); interacting = false; layoutBusy = false; updateLowQ(); var t = performance.now(); markDirty(); requestAnimationFrame(function () { requestAnimationFrame(function () { res({ ms: performance.now() - t, edgesDrawn: perf.edgesDrawn, edgesVisible: perf.edgesVisible, mode: perf.renderMode }); }); }); }); }, checksum: function () { return DataGen.checksum(data); }, meta: data.meta
  };
  startWorker(); rebuildLists();
  if (hashView) { var hk = Math.max(0.01, Math.min(16, +H0.k)); applyTransform(viewTransform(hk, +H0.x, +H0.y), false); }
  else fit(false);
  if (H0.sel && /^n\d+$/.test(H0.sel) && +H0.sel.slice(1) < N) select(+H0.sel.slice(1), {});
  if (view === 'table') setView('table'); else { cv.hidden = false; }
  if (P.get('view') === 'table') { $('#vTable').setAttribute('aria-pressed', 'true'); $('#vGraph').setAttribute('aria-pressed', 'false'); }
  refreshTable(); refreshStatus(); markDirty();
  window.addEventListener('hashchange', function () { /* deep link edited by hand: reload view */
    var h = parseHash(); if (h.k && h.x && h.y) applyTransform(viewTransform(+h.k, +h.x, +h.y), false);
    if (h.sel && /^n\d+$/.test(h.sel) && +h.sel.slice(1) < N && +h.sel.slice(1) !== sel) select(+h.sel.slice(1), {});
  });
})();
