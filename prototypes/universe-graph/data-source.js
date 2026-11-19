/* data-source.js - data access adapter for the graph prototype.
 *
 * graph.js never touches DataGen or fetch directly: it only talks to a "source" with the interface below, so the
 * SYNTHETIC implementation can later be replaced by an HTTP implementation without changing the renderer.
 *
 * CANONICAL WIRE FORMAT (what an HTTP implementation must return; synthetic data is converted to it too)
 *   node: { ref, slug, type, name, company? }
 *         ref      opaque stable id (string), used by edges
 *         slug     URL-safe id used in deep links (#sel=<slug>)
 *         type     company | product | model | family | technology
 *         company  ref of the owning company node (used for clustering; companies do not need it).
 *                  A non-company node without it is clustered under the first company and counted in stats.badCompany.
 *   edge: { from, to, type, valid_from, valid_to, sources: [{ url, publisher, retrieved_at }], verified? }
 *         from/to  node refs; type is one of the six relationship types
 *         verified optional boolean; absent means true. Public mode drops edges with verified === false.
 *         Real endpoints return verified+current edges only, so they omit the field.
 *   page: { nodes: [...], edges: [...], next: <cursor string | null> }
 *         Edges are returned with the page that contains the later of their two endpoints, so an edge never
 *         arrives before both of its nodes were served. next === null ends the scan.
 *
 * SOURCE INTERFACE (all methods return Promises)
 *   source.kind                          'synthetic' | 'http'
 *   source.open()                        -> { label, nodeCount, edgeCount }   (label must be shown to the user;
 *                                           the synthetic source returns 'SYNTHETIC TEST DATA')
 *   source.page(cursor, limit)           -> page      cursor null = first page; limit = max nodes per page.
 *                                           Paged scan used for the initial load (UniverseData.loadAll).
 *   source.neighbourhood(ref, {limit})   -> { nodes, edges }   1-hop neighbours of ref (at most `limit`) and
 *                                           the edges among them and ref. Used by progressive loading.
 *
 * HTTP IMPLEMENTATION (NOT written yet, deliberately: no endpoint exists to test against). It would map
 *   open()          -> GET {base}/summary
 *   page(c, n)      -> GET {base}/graph?cursor=<c>&limit=<n>
 *   neighbourhood   -> GET {base}/nodes/<ref>/neighbourhood?limit=<n>
 * and must (1) send no credentials for public mode, (2) validate every field the way ingest() in graph.js does
 * (unknown type values are dropped and counted), (3) never add unverified edges, (4) honour the mobile limits by
 * requesting small limits, and (5) keep the SYNTHETIC label off real data. createHttpSource below throws until then.
 */
(function (root) {
  'use strict';
  var DG = root.DataGen || (typeof require !== 'undefined' ? require('./data-gen.js') : null);

  function createSyntheticSource(opts) {
    var size = opts.size, seed = opts.seed == null ? 42 : opts.seed;
    var raw = null;
    function gen() { if (!raw) raw = DG.generate(size, seed); return raw; }
    function toNode(n, d) {
      var o = { ref: 'syn:' + n.id, slug: n.id, type: n.type, name: n.name };
      if (n.type !== 'company') o.company = 'syn:' + d.nodes[n.c].id;
      return o;
    }
    function toEdge(e, d) {
      return {
        from: 'syn:' + d.nodes[e.s].id, to: 'syn:' + d.nodes[e.t].id, type: e.rel,
        valid_from: null, valid_to: null, verified: e.v,
        sources: [{ url: 'https://synthetic.invalid/cite/' + e.src, publisher: 'SYNTHETIC', retrieved_at: e.date }]
      };
    }
    var adjIdx = null;
    function adjacency(d) {
      if (adjIdx) return adjIdx;
      adjIdx = d.nodes.map(function () { return []; });
      d.edges.forEach(function (e, k) { adjIdx[e.s].push(k); adjIdx[e.t].push(k); });
      return adjIdx;
    }
    return {
      kind: 'synthetic',
      open: function () { var d = gen(); return Promise.resolve({ label: d.label, nodeCount: d.nodes.length, edgeCount: d.edges.length }); },
      page: function (cursor, limit) {
        var d = gen(), from = cursor ? +cursor : 0, to = Math.min(d.nodes.length, from + limit), nodes = [], edges = [], i;
        for (i = from; i < to; i++) nodes.push(toNode(d.nodes[i], d));
        for (i = 0; i < d.edges.length; i++) { var e = d.edges[i], m = Math.max(e.s, e.t); if (m >= from && m < to) edges.push(toEdge(e, d)); }
        return Promise.resolve({ nodes: nodes, edges: edges, next: to < d.nodes.length ? String(to) : null });
      },
      neighbourhood: function (ref, o) {
        var d = gen(), id = +String(ref).replace('syn:n', ''), a = adjacency(d)[id] || [], limit = (o && o.limit) || 400, seen = {}, nodes = [], edges = [];
        seen[id] = 1; nodes.push(toNode(d.nodes[id], d));
        a.forEach(function (k) { var e = d.edges[k], other = e.s === id ? e.t : e.s; if (!seen[other] && nodes.length < limit + 1) { seen[other] = 1; nodes.push(toNode(d.nodes[other], d)); } });
        a.forEach(function (k) { var e = d.edges[k]; if (seen[e.s] && seen[e.t]) edges.push(toEdge(e, d)); });
        return Promise.resolve({ nodes: nodes, edges: edges });
      },
      checksum: function () { return DG.checksum(gen()); }
    };
  }

  function createHttpSource() { throw new Error('HTTP data source not implemented yet; see the contract at the top of data-source.js'); }

  function create(opts) { return opts.kind === 'http' ? createHttpSource(opts) : createSyntheticSource(opts); }

  /* Scan every page of a source; resolves with { label, nodes, edges, pages }. onPage(n) is called after each page. */
  function loadAll(source, o) {
    o = o || {}; var limit = o.pageSize || 10000, nodes = [], edges = [], pages = 0, label = '';
    return source.open().then(function (info) {
      label = info.label;
      function step(cursor) {
        return source.page(cursor, limit).then(function (p) {
          pages++; Array.prototype.push.apply(nodes, p.nodes); Array.prototype.push.apply(edges, p.edges);
          if (o.onPage) o.onPage(pages, nodes.length, info.nodeCount);
          return p.next ? step(p.next) : { label: label, nodes: nodes, edges: edges, pages: pages };
        });
      }
      return step(null);
    });
  }

  var api = { create: create, loadAll: loadAll, createSyntheticSource: createSyntheticSource, createHttpSource: createHttpSource };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.UniverseData = api;
})(typeof self !== 'undefined' ? self : this);
