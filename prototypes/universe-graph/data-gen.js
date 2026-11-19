/* data-gen.js - deterministic (seeded) SYNTHETIC graph generator.
 * Every name here is a random syllable string. No real company, product or claim is used.
 * Usable from a page (window.DataGen) and from Node (module.exports).
 */
(function (root) {
  'use strict';
  var TYPES = ['company', 'product', 'model', 'family', 'technology'];
  var RELS = ['technical_ancestry', 'product_succession', 'company_ownership',
              'integration', 'similarity', 'historical_association'];
  var LABEL = 'SYNTHETIC TEST DATA';

  function mulberry32(a) {
    return function () {
      a |= 0; a = a + 0x6D2B79F5 | 0;
      var t = Math.imul(a ^ a >>> 15, 1 | a);
      t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }
  var SYL = ['vel', 'kor', 'ana', 'zun', 'tha', 'mir', 'qua', 'lex', 'dro', 'sil', 'nox', 'pra',
             'fen', 'gal', 'ori', 'uk', 'bel', 'tro', 'ven', 'sa', 'mu', 'di', 'ko', 'ra', 'xi', 'lo'];
  var SUFFIX = ['Labs', 'Works', 'Systems', 'Group', 'Holdings', 'Collective'];
  function word(r, n) {
    var s = '';
    for (var i = 0; i < n; i++) s += SYL[(r() * SYL.length) | 0];
    return s.charAt(0).toUpperCase() + s.slice(1);
  }
  function pad(n, w) { var s = String(n); while (s.length < w) s = '0' + s; return s; }
  function pick(r, arr) { return arr[(r() * arr.length) | 0]; }

  function generate(size, seed) {
    size = Math.max(20, size | 0);
    seed = seed == null ? 42 : seed;
    var r = mulberry32(seed * 7919 + size);
    var nC = Math.max(4, Math.round(size * 0.04));
    var nF = Math.round(size * 0.06);
    var nT = Math.round(size * 0.06);
    var nP = Math.round(size * 0.34);
    var nM = size - nC - nF - nT - nP;
    var nodes = [], edges = [];
    var famByC = [], modByC = [], prodByC = [], techByC = [], modByF = [];
    var i, c;
    for (c = 0; c < nC; c++) { famByC.push([]); modByC.push([]); prodByC.push([]); techByC.push([]); }

    function addNode(type, name, comp) {
      var n = { i: nodes.length, id: 'n' + nodes.length, name: name, type: type, c: comp };
      nodes.push(n); return n;
    }
    function addEdge(s, t, rel) {
      var code = 'SYN-CITE-' + pad(1 + ((r() * 9000) | 0), 5);
      var date = null;
      if (r() < 0.9) date = (2019 + ((r() * 8) | 0)) + '-' + pad(1 + ((r() * 12) | 0), 2) + '-' + pad(1 + ((r() * 28) | 0), 2);
      var v = r() > 0.07; // ~7% unverified; the public mode drops them
      edges.push({ s: s, t: t, rel: rel, src: code, date: date, v: v });
    }

    for (c = 0; c < nC; c++) addNode('company', word(r, 2 + ((r() * 2) | 0)) + ' ' + pick(r, SUFFIX), c);
    var f0 = nodes.length;
    for (i = 0; i < nF; i++) {
      c = i < nC ? i : (r() * nC) | 0;
      var fam = addNode('family', word(r, 2) + ' series', c);
      famByC[c].push(fam.i); modByF[fam.i] = [];
      addEdge(c, fam.i, 'company_ownership');
    }
    var t0 = nodes.length;
    for (i = 0; i < nT; i++) { c = i < nC ? i : (r() * nC) | 0; var te = addNode('technology', word(r, 3) + ' method', c); techByC[c].push(te.i); }
    for (i = 0; i < nP; i++) {
      c = (r() * nC) | 0;
      var p = addNode('product', word(r, 2) + '-' + (1 + ((r() * 40) | 0)), c);
      prodByC[c].push(p.i);
      addEdge(c, p.i, 'company_ownership');
    }
    for (i = 0; i < nM; i++) {
      var fl = famByC[(r() * nC) | 0]; var f;
      f = fl.length ? pick(r, fl) : f0;
      var comp = nodes[f].c;
      var prev = modByF[f].length ? modByF[f][modByF[f].length - 1] : -1;
      var m = addNode('model', word(r, 2) + '-' + (1 + ((r() * 99) | 0)), comp);
      modByF[f].push(m.i); modByC[comp].push(m.i);
      addEdge(f, m.i, 'technical_ancestry');
      if (prev >= 0 && r() < 0.6) addEdge(prev, m.i, 'technical_ancestry');
      if (r() < 0.2 && techByC[comp].length) addEdge(pick(r, techByC[comp]), m.i, 'technical_ancestry');
    }
    // company acquisitions and associations
    for (c = 1; c < nC; c++) if (r() < 0.1) addEdge((r() * c) | 0, c, 'company_ownership');
    for (i = 0; i < Math.round(nC * 0.5); i++) {
      var a = (r() * nC) | 0, b = (r() * nC) | 0; if (a !== b) addEdge(a, b, 'historical_association');
    }
    for (i = 0; i < Math.round(nT * 0.3); i++) addEdge((r() * nC) | 0, t0 + ((r() * nT) | 0), 'historical_association');
    // product chains and integrations
    for (c = 0; c < nC; c++) {
      var pl = prodByC[c], ml = modByC[c];
      for (i = 0; i + 1 < pl.length; i++) if (r() < 0.7) addEdge(pl[i], pl[i + 1], 'product_succession');
      for (i = 0; i < pl.length; i++) {
        if (ml.length) addEdge(pl[i], pick(r, ml), 'integration');
        if (r() < 0.2 && techByC[c].length) addEdge(pl[i], pick(r, techByC[c]), 'integration');
      }
    }
    // cross-company similarity between models
    var firstM = nodes.length - nM;
    for (i = 0; i < Math.round(size * 0.1) && nM > 1; i++) {
      var x = firstM + ((r() * nM) | 0), y = firstM + ((r() * nM) | 0);
      if (x !== y) addEdge(x, y, 'similarity');
    }
    return {
      label: LABEL, nodes: nodes, edges: edges,
      meta: { size: nodes.length, seed: seed, companies: nC, families: nF, technologies: nT, products: nP, models: nM }
    };
  }

  function checksum(d) { // FNV-1a over names/types/edges, to prove determinism
    var h = 0x811c9dc5;
    function mix(s) { for (var k = 0; k < s.length; k++) { h ^= s.charCodeAt(k); h = Math.imul(h, 16777619) >>> 0; } }
    for (var i = 0; i < d.nodes.length; i++) mix(d.nodes[i].name + d.nodes[i].type + d.nodes[i].c);
    for (var j = 0; j < d.edges.length; j++) { var e = d.edges[j]; mix(e.s + ':' + e.t + e.rel + e.src + e.date + e.v); }
    return h.toString(16);
  }

  var api = { generate: generate, checksum: checksum, TYPES: TYPES, RELS: RELS, LABEL: LABEL, SIZES: [500, 5000, 50000] };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.DataGen = api;
})(typeof self !== 'undefined' ? self : this);
