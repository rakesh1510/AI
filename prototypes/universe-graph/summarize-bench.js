/* summarize-bench.js - prints a markdown table (per configuration: every repeat's frame p95, median, pass count) from bench result files.
 * Usage: node summarize-bench.js before.json after.json [more.json ...]
 */
'use strict';
const fs = require('fs');
const med = a => { const s = a.slice().sort((x, y) => x - y); return s.length ? (s.length % 2 ? s[(s.length - 1) / 2] : (s[s.length / 2 - 1] + s[s.length / 2]) / 2) : NaN; };
const r1 = x => Math.round(x * 10) / 10;
for (const f of process.argv.slice(2)) {
  const d = JSON.parse(fs.readFileSync(f, 'utf8')), g = {};
  for (const r of d.runs) if (!r.error) (g[[r.profile, r.size, r.variant].join('|')] = g[[r.profile, r.size, r.variant].join('|')] || []).push(r);
  console.log(`\n### ${f}\n\n| Profile | Nodes | Variant | runs | frame p95 per run (ms) | median p95 | median avg (ms) | first paint median (ms) | layout settle median (ms) | idle redraw fit / k=2 median (ms) | verdict |\n|---|---|---|---|---|---|---|---|---|---|---|`);
  for (const [k, rs] of Object.entries(g)) {
    const [p, n, v] = k.split('|'), thr = rs[0].verdict.frameP95Threshold, pass = rs.filter(r => r.verdict.pass).length;
    console.log(`| ${p} | ${(+n).toLocaleString('en-US')} | ${v} | ${rs.length} | ${rs.map(r => r.frame.p95Ms).join(' / ')} | ${r1(med(rs.map(r => r.frame.p95Ms)))} (limit ${thr}) | ${r1(med(rs.map(r => r.frame.avgMs)))} | ${r1(med(rs.map(r => r.firstPaintMs)))} | ${r1(med(rs.map(r => r.layoutSettleMs)))} | ${r1(med(rs.map(r => r.settledRedrawAtFit.ms)))} / ${r1(med(rs.map(r => r.settledRedrawAtDetail.ms)))} | ${pass}/${rs.length} pass |`);
  }
  const errs = d.runs.filter(r => r.error); if (errs.length) console.log('\nerrors: ' + JSON.stringify(errs.map(e => [e.profile, e.size, e.variant, e.error])));
}
