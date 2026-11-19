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
 * source.taxonomy                      { types: [...], rels: [...] }   node types and relationship types this source can deliver
 * source.open(o)                       o = { signal }
 * source.page(cursor, limit, o)        o = { signal } (HTTP source only; ignored by the synthetic one)
 * source.neighbourhood(ref, o)         HTTP: o = { slug, offset, limit, type?, signal } -> { nodes, edges, total_edges, has_more, next_offset }
 * source.entity(slug, o)               HTTP only: -> { summary, verified_at, sources: [{ url, publisher, retrieved_at }] }
 *
 * HTTP IMPLEMENTATION (createHttpSource below) talks to universe-api.php, GET only, credentials omitted:
 *   open()          -> GET ?r=entities&page=1&per_page=100            label 'Verified data', nodeCount = total verified entities (the page is reused by page())
 *   page(c, n)      -> GET ?r=entities&page=<c>&per_page=<min(n,100)>   nodes only (edges are not part of that route), next = page + 1 while has_more
 *   neighbourhood   -> GET ?r=graph&slug=<slug>&limit=<1..200>&offset=<n>[&type=<edge type>]   1-hop, paged; each edge keeps its own type and sources
 *   entity          -> GET ?r=entity&slug=<slug>   summary, verification date and sources
 * Every response is validated field by field: unknown node types or relationship types, malformed slugs/refs and non-string text are dropped and counted in
 * source.stats; the server already returns verified and current rows only, and nothing here can ask for anything else. Errors reject with
 * { status, code } (HTTP status and the API error code; status 0 / no status = network); aborts reject with an AbortError; a 15 s timeout rejects with code 'timeout'.
 * The label of the HTTP source is never 'SYNTHETIC TEST DATA'. createHttpSource refuses an API path that is not a same-origin absolute path.
 */
(function (root) {
  'use strict';
  var DG = root.DataGen || (typeof require !== 'undefined' ? require('./data-gen.js') : null);

  var HTTP_TYPES = ['company', 'product', 'model', 'version'];
  var HTTP_RELS = ['developed_by', 'acquired_by', 'version_of', 'derived_from', 'built_on', 'successor_of', 'renamed_from', 'integrates_with', 'hosted_on'];
  var SLUG_RE = /^[a-z0-9._-]{2,120}$/;
  var DATE_RE = /^\d{4}-\d{2}-\d{2}/;

  function createSyntheticSource(opts) {
    if (!DG) throw new Error('synthetic data generator (data-gen.js) is not loaded');
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
      taxonomy: { types: DG.TYPES.slice(), rels: DG.RELS.slice() },
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

  function str(v, max) { return typeof v === 'string' ? v.slice(0, max) : ''; }
  function httpError(status, code, retryAfter) { var e = new Error('HTTP ' + status + (code ? ' ' + code : '')); e.status = status; e.code = code || ''; if (retryAfter) e.retryAfter = retryAfter; return e; }
  function badResponse() { var e = new Error('unexpected response format'); e.code = 'bad_response'; return e; }

  function createHttpSource(opts) {
    opts = opts || {};
    var api = opts.api || '/universe-api.php';
    if (!/^\/[A-Za-z0-9_.\/-]{1,80}$/.test(api) || api.indexOf('//') >= 0) throw new Error('HTTP data source: api must be a same-origin absolute path');
    var timeoutMs = opts.timeoutMs || 15000;
    var doFetch = opts.fetch || (typeof fetch !== 'undefined' ? fetch.bind(root) : null);
    var stats = { droppedNodes: 0, droppedEdges: 0, droppedSources: 0, requests: 0 };
    var first = null;

    function getJson(params, signal) {
      if (!doFetch) return Promise.reject(httpError(0, 'no_fetch'));
      var qs = Object.keys(params).filter(function (k) { return params[k] != null && params[k] !== ''; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
      var ctl = new AbortController(), timedOut = false, timer;
      if (signal) { if (signal.aborted) ctl.abort(); else signal.addEventListener('abort', function () { ctl.abort(); }, { once: true }); }
      timer = setTimeout(function () { timedOut = true; ctl.abort(); }, timeoutMs);
      stats.requests++;
      return doFetch(api + '?' + qs, { method: 'GET', credentials: 'omit', headers: { Accept: 'application/json' }, signal: ctl.signal }).then(function (resp) {
        return resp.text().then(function (txt) {
          var body = null; try { body = JSON.parse(txt); } catch (e) { body = null; }
          if (!resp.ok) throw httpError(resp.status, body && typeof body.error === 'string' ? body.error.slice(0, 60) : '', resp.headers && resp.headers.get ? resp.headers.get('Retry-After') : null);
          if (!body || typeof body !== 'object' || !body.data || typeof body.data !== 'object') throw badResponse();
          return body.data;
        });
      }).catch(function (err) {
        if (timedOut) { var te = new Error('timeout'); te.code = 'timeout'; throw te; }
        throw err;
      }).then(function (d) { clearTimeout(timer); return d; }, function (err) { clearTimeout(timer); throw err; });
    }
    function cleanNode(n, typeKey) {
      if (!n || typeof n !== 'object' || HTTP_TYPES.indexOf(n[typeKey]) < 0 || typeof n.ref !== 'string' || !n.ref || n.ref.length > 64 ||
          typeof n.slug !== 'string' || !SLUG_RE.test(n.slug) || typeof n.name !== 'string' || !n.name.trim() || n.name.length > 300) { stats.droppedNodes++; return null; }
      return { ref: n.ref, slug: n.slug, type: n[typeKey], name: n.name, summary: str(n.summary, 2000), verified_at: str(n.verified_at, 19) };
    }
    function cleanSources(list) {
      var out = [];
      (Array.isArray(list) ? list : []).slice(0, 20).forEach(function (x) {
        if (!x || typeof x !== 'object' || typeof x.url !== 'string' || !x.url || x.url.length > 2000) { stats.droppedSources++; return; }
        out.push({ url: x.url, publisher: str(x.publisher, 200), retrieved_at: str(x.retrieved_at, 19) });
      });
      return out;
    }
    function cleanEdge(e) {
      if (!e || typeof e !== 'object' || HTTP_RELS.indexOf(e.type) < 0 || typeof e.from !== 'string' || typeof e.to !== 'string' || !e.from || !e.to) { stats.droppedEdges++; return null; }
      var vf = typeof e.valid_from === 'string' && DATE_RE.test(e.valid_from) ? e.valid_from.slice(0, 10) : null, vt = typeof e.valid_to === 'string' && DATE_RE.test(e.valid_to) ? e.valid_to.slice(0, 10) : null;
      return { from: e.from, to: e.to, type: e.type, valid_from: vf, valid_to: vt, sources: cleanSources(e.sources) };   // no `verified` field: the API serves verified rows only
    }
    function mapPage(d, page) {
      if (!Array.isArray(d.items)) throw badResponse();
      var nodes = [];
      d.items.forEach(function (it) { var n = cleanNode(it, 'entity_type'); if (n) nodes.push(n); });
      return { nodes: nodes, edges: [], next: d.has_more === true && page < 10000 ? String(page + 1) : null };
    }
    return {
      kind: 'http',
      taxonomy: { types: HTTP_TYPES.slice(), rels: HTTP_RELS.slice() },
      stats: stats,
      open: function (o) {
        return getJson({ r: 'entities', page: 1, per_page: 100 }, o && o.signal).then(function (d) {
          if (!Array.isArray(d.items) || typeof d.total !== 'number') throw badResponse();
          first = d; return { label: 'Verified data', nodeCount: d.total, edgeCount: null };
        });
      },
      page: function (cursor, limit, o) {
        var pg = cursor ? Math.max(1, parseInt(cursor, 10) || 1) : 1;
        if (pg === 1 && first) { var d0 = first; first = null; return Promise.resolve(mapPage(d0, 1)); }
        return getJson({ r: 'entities', page: pg, per_page: Math.max(1, Math.min(100, limit || 100)) }, o && o.signal).then(function (d) { return mapPage(d, pg); });
      },
      neighbourhood: function (ref, o) {
        o = o || {};
        if (typeof o.slug !== 'string' || !SLUG_RE.test(o.slug)) return Promise.reject(httpError(400, 'slug_invalid'));
        var limit = Math.max(1, Math.min(200, o.limit || 50)), offset = Math.max(0, o.offset | 0);
        var params = { r: 'graph', slug: o.slug, limit: limit, offset: offset };
        if (o.type && HTTP_RELS.indexOf(o.type) >= 0) params.type = o.type;
        return getJson(params, o.signal).then(function (d) {
          if (!Array.isArray(d.nodes) || !Array.isArray(d.edges) || typeof d.total_edges !== 'number') throw badResponse();
          var nodes = [], edges = [];
          d.nodes.forEach(function (n) { var c = cleanNode(n, 'type'); if (c) nodes.push(c); });
          d.edges.forEach(function (e) { var c = cleanEdge(e); if (c) edges.push(c); });
          return { nodes: nodes, edges: edges, total_edges: d.total_edges, has_more: d.has_more === true, next_offset: typeof d.next_offset === 'number' ? d.next_offset : null };
        });
      },
      entity: function (slug, o) {
        if (typeof slug !== 'string' || !SLUG_RE.test(slug)) return Promise.reject(httpError(400, 'slug_invalid'));
        return getJson({ r: 'entity', slug: slug }, o && o.signal).then(function (d) {
          if (typeof d.slug !== 'string') throw badResponse();
          return { summary: str(d.summary, 2000), verified_at: str(d.verified_at, 19), sources: cleanSources(d.sources) };
        });
      }
    };
  }

  function create(opts) { return opts.kind === 'http' ? createHttpSource(opts) : createSyntheticSource(opts); }

  /* Scan the pages of a source; resolves with { label, nodes, edges, pages, total, truncated }. onPage(n) is called after each page.
     o.maxNodes (> 0) stops the scan once that many nodes were read (truncated = true when more existed); o.signal aborts the scan. */
  function loadAll(source, o) {
    o = o || {}; var limit = o.pageSize || 10000, maxNodes = o.maxNodes || 0, nodes = [], edges = [], pages = 0, label = '', total = null, truncated = false;
    return source.open({ signal: o.signal }).then(function (info) {
      label = info.label; total = info.nodeCount;
      function step(cursor) {
        return source.page(cursor, limit, { signal: o.signal }).then(function (p) {
          pages++; Array.prototype.push.apply(nodes, p.nodes); Array.prototype.push.apply(edges, p.edges);
          if (o.onPage) o.onPage(pages, nodes.length, info.nodeCount);
          if (maxNodes && nodes.length >= maxNodes) { truncated = !!p.next || nodes.length > maxNodes; nodes.length = Math.min(nodes.length, maxNodes); return { label: label, nodes: nodes, edges: edges, pages: pages, total: total, truncated: truncated }; }
          return p.next ? step(p.next) : { label: label, nodes: nodes, edges: edges, pages: pages, total: total, truncated: false };
        });
      }
      return step(null);
    });
  }

  var api = { create: create, loadAll: loadAll, createSyntheticSource: createSyntheticSource, createHttpSource: createHttpSource, HTTP_TYPES: HTTP_TYPES, HTTP_RELS: HTTP_RELS };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.UniverseData = api;
})(typeof self !== 'undefined' ? self : this);
