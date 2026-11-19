/* layout-worker.js - runs the d3-force simulation off the main thread.
 * Messages in : init / add (progressive loading) / pin / stop
 * Messages out: {type:'pos', pos:Float32Array(x,y interleaved), tick, alpha, done, capped}
 */
importScripts('vendor/d3-dispatch.min.js', 'vendor/d3-timer.min.js',
              'vendor/d3-quadtree.min.js', 'vendor/d3-force.min.js');

var nodes = [], links = [], sim = null, linkF = null, tick = 0, maxTicks = 200;
var running = false, lastPost = 0, ALPHA_MIN = 0.001, capped = false, budgetMs = 0, runStart = 0;   // budgetMs > 0: wall-clock cap per layout run (mobile limit)

function appendNodes(m) {
  for (var i = 0; i < m.cluster.length; i++) {
    nodes.push({ x: m.x[i], y: m.y[i], tx: m.tx[i], ty: m.ty[i], cl: m.cluster[i] });
  }
  for (var j = 0; j < m.links.length; j += 2) {
    var a = m.links[j], b = m.links[j + 1];
    links.push({ source: a, target: b, cross: nodes[a].cl !== nodes[b].cl });
  }
}
function setup() {
  sim = d3.forceSimulation(nodes).stop();
  linkF = d3.forceLink(links)
    .distance(function (l) { return l.cross ? 180 : 28; })
    .strength(function (l) { return l.cross ? 0.004 : 0.4; });
  sim.force('link', linkF)
     .force('charge', d3.forceManyBody().strength(-22).theta(1.1).distanceMax(120))
     .force('x', d3.forceX(function (n) { return n.tx; }).strength(0.06))
     .force('y', d3.forceY(function (n) { return n.ty; }).strength(0.06));
}
function configure(ticks, alpha, ms) {
  maxTicks = ticks; tick = 0; capped = false; budgetMs = ms || 0; runStart = performance.now();
  sim.alphaMin(ALPHA_MIN).alphaDecay(1 - Math.pow(ALPHA_MIN, 1 / ticks)).alpha(alpha);
}
function post(done) {
  var n = nodes.length, pos = new Float32Array(n * 2);
  for (var i = 0; i < n; i++) { pos[2 * i] = nodes[i].x; pos[2 * i + 1] = nodes[i].y; }
  self.postMessage({ type: 'pos', pos: pos, tick: tick, maxTicks: maxTicks, alpha: sim.alpha(), done: done, capped: capped, n: n }, [pos.buffer]);
}
function run() {
  if (!running) return;
  var t0 = performance.now();
  while (performance.now() - t0 < 25 && tick < maxTicks && sim.alpha() > ALPHA_MIN) { sim.tick(); tick++; }
  var done = tick >= maxTicks || sim.alpha() <= ALPHA_MIN;
  if (!done && budgetMs && performance.now() - runStart > budgetMs) { done = true; capped = true; }
  var now = performance.now();
  if (done || now - lastPost >= 150) { post(done); lastPost = now; }
  if (done) { running = false; return; }
  setTimeout(run, 0); // yield so incoming messages (stop/pin) are handled
}
function start() { if (!running) { running = true; setTimeout(run, 0); } }

self.onmessage = function (e) {
  var m = e.data;
  if (m.type === 'init') {
    nodes = []; links = [];
    appendNodes(m); setup(); configure(m.ticks, 1, m.maxMs); start();
  } else if (m.type === 'add') {
    appendNodes(m); sim.nodes(nodes); linkF.links(links);
    configure(m.ticks, 0.35, m.maxMs); start();
  } else if (m.type === 'pin') {
    var n = nodes[m.i]; if (!n) return;
    if (m.release) { n.fx = null; n.fy = null; } else { n.fx = m.x; n.fy = m.y; }
    configure(40, 0.2); start();
  } else if (m.type === 'stop') {
    if (running) { running = false; capped = true; post(true); }
  }
};
