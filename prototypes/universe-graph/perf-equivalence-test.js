/* perf-equivalence-test.js - the first-paint optimisations must not change data. Usage: node perf-equivalence-test.js
 * Compares the synthetic source (bucketed paging, cached refs) against an independent reference scan that follows the original
 * algorithm, for several page sizes (page-aligned and not), plus determinism checksums and graph.js helper equivalences.
 */
'use strict';
const assert = require('assert');
const DG = require('./data-gen.js');
const UD = require('./data-source.js');
let pass = 0, fail = 0;
function t(name, fn) { try { fn(); pass++; console.log('ok   ' + name); } catch (e) { fail++; console.log('FAIL ' + name + ': ' + e.message); } }
async function ta(name, fn) { try { await fn(); pass++; console.log('ok   ' + name); } catch (e) { fail++; console.log('FAIL ' + name + ': ' + e.message); } }

function refPage(d, from, limit) {   // the original (pre-optimisation) algorithm, written out independently
  const to = Math.min(d.nodes.length, from + limit), nodes = [], edges = [];
  for (let i = from; i < to; i++) { const n = d.nodes[i]; const o = { ref: 'syn:' + n.id, slug: n.id, type: n.type, name: n.name }; if (n.type !== 'company') o.company = 'syn:' + d.nodes[n.c].id; nodes.push(o); }
  for (const e of d.edges) { const m = Math.max(e.s, e.t); if (m >= from && m < to) edges.push({ from: 'syn:' + d.nodes[e.s].id, to: 'syn:' + d.nodes[e.t].id, type: e.rel, valid_from: null, valid_to: null, verified: e.v, sources: [{ url: 'https://synthetic.invalid/cite/' + e.src, publisher: 'SYNTHETIC', retrieved_at: e.date }] }); }
  return { nodes, edges, next: to < d.nodes.length ? String(to) : null };
}

(async () => {
  for (const size of [500, 5000]) for (const limit of [10000, 100, 777, 64]) {
    await ta(`synthetic pages identical to reference scan: size ${size}, page size ${limit}`, async () => {
      const src = UD.createSyntheticSource({ size, seed: 42 }), d = DG.generate(size, 42);
      await src.open();
      let cursor = null, pages = 0;
      do {
        const p = await src.page(cursor, limit), from = cursor ? +cursor : 0;
        assert.deepStrictEqual(p, refPage(d, from, limit)); cursor = p.next; pages++;
      } while (cursor);
      assert.ok(pages >= 1);
    });
  }
  await ta('non page-aligned cursor falls back to the full scan and still matches', async () => {
    const src = UD.createSyntheticSource({ size: 500, seed: 42 }), d = DG.generate(500, 42);
    for (const [c, l] of [[150, 100], [37, 64], [499, 10]]) assert.deepStrictEqual(await src.page(String(c), l), refPage(d, c, l));
  });
  await ta('loadAll returns every node and every edge exactly once (50,000)', async () => {
    const src = UD.createSyntheticSource({ size: 50000, seed: 42 }), d = DG.generate(50000, 42);
    const c = await UD.loadAll(src, { pageSize: 10000 });
    assert.strictEqual(c.nodes.length, d.nodes.length); assert.strictEqual(c.edges.length, d.edges.length); assert.strictEqual(c.label, 'SYNTHETIC TEST DATA');
    assert.strictEqual(c.edges.filter(e => e.verified === false).length, d.edges.filter(e => !e.v).length);
  });
  t('determinism: same seed gives the same checksum', () => { for (const s of DG.SIZES) assert.strictEqual(DG.checksum(DG.generate(s, 42)), DG.checksum(DG.generate(s, 42))); });
  t('adapter checksum equals generator checksum', () => { assert.strictEqual(UD.createSyntheticSource({ size: 5000, seed: 42 }).checksum(), DG.checksum(DG.generate(5000, 42))); });
  await ta('neighbourhood still works after paging', async () => {
    const src = UD.createSyntheticSource({ size: 500, seed: 42 }); await src.open(); await src.page(null, 100);
    const r = await src.neighbourhood('syn:n10', { limit: 50 });
    assert.ok(r.nodes.length >= 1 && r.nodes[0].ref === 'syn:n10');
  });

  // graph.js helpers (copied from the page code under test by extraction, so a drift fails here)
  const src = require('fs').readFileSync(__dirname + '/graph.js', 'utf8');
  const ls = /var LASTSEG_RE[\s\S]*?\n  }\n/.exec(src), fm = /function fmt\(n\) \{[\s\S]*?\n  }\n/.exec(src);
  assert.ok(ls && fm, 'helpers found in graph.js');
  const lastSeg = new Function(ls[0] + '; return lastSeg;')(), fmt = new Function(fm[0] + '; return fmt;')();
  const RE = /([^\/?#]+)\/?(?:[?#].*)?$/, orig = u => { const m = RE.exec(String(u || '')); return m ? m[1] : ''; };
  t('lastSeg equals the original regex for every string up to length 6 over a hostile alphabet', () => {
    const al = 'ab/ ?#.:x'; let n = 0;
    (function walk(prefix, left) { if (!left) return; for (const c of al) { const u = prefix + c; n++; assert.strictEqual(lastSeg(u), orig(u), JSON.stringify(u)); walk(u, left - 1); } })('', 6);
    for (const u of [undefined, null, 0, '', 'https://synthetic.invalid/cite/SYN-CITE-00012', 'https://x.test/a/b/?q=1#f', 'a//', '/']) assert.strictEqual(lastSeg(u), orig(u), String(u));
    assert.ok(n > 500000);
  });
  t('fmt equals toLocaleString(en-US) for integers, negatives, fractions and non-finite values', () => {
    for (const n of [0, 1, 12, 123, 1234, 12345, 123456, 1234567, 50000, 999999999999999, -1, -1234, 1.5, 1234.5678, NaN, Infinity, -Infinity, -0, 1e15, 1e21]) assert.strictEqual(fmt(n), n.toLocaleString('en-US'), String(n));
    let s = 1; for (let i = 0; i < 100000; i++) { s = (Math.imul(s, 1664525) + 1013904223) | 0; assert.strictEqual(fmt(s), s.toLocaleString('en-US')); }
  });
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
