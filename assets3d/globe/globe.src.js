/**
 * AIHubpedia "Global AI Ecosystem" - homepage artwork (Three.js, WebGL). Decorative: every tool is also a real link in the page's HTML list.
 * Source of assets/globe/globe.min.js (see assets3d/globe/README.md). Loaded lazily by assets/js/app.js only when WebGL is available, the visitor has not asked
 * for reduced motion / data saving, and the hero is near the viewport. Without it the static poster stays.
 * Data comes from <script type="application/json" id="globeData"> rendered by index.php. Everything shown is written with textContent (no HTML injection).
 * Composition follows the approved reference: a luminous blue Earth with gold city lights and a network of light arcs, wide glowing violet/blue orbit rings carrying
 * thick dark-glass application slabs (official brand marks + names), a sun flare on the limb, nebula/stars and a glossy violet-blue floor.
 */
import {
  WebGLRenderer, Scene, PerspectiveCamera, Group, Mesh, SphereGeometry, PlaneGeometry, TorusGeometry, ShaderMaterial, TextureLoader, SRGBColorSpace, NoColorSpace, AdditiveBlending, CustomBlending, OneFactor, BackSide, Vector3, Quaternion, Euler,
  CanvasTexture, Sprite, SpriteMaterial, BufferGeometry, Float32BufferAttribute, Line, LineSegments, LineBasicMaterial, Points, PointsMaterial, MathUtils, LinearFilter, LinearMipmapLinearFilter, DynamicDrawUsage, RepeatWrapping
} from 'three';

const TAU = Math.PI * 2, clamp = MathUtils.clamp, smooth = MathUtils.smoothstep;
const HUBS = [[37.8, -122.4], [40.7, -74], [43.7, -79.4], [-23.5, -46.6], [51.5, -0.1], [48.9, 2.3], [50.1, 8.7], [25.2, 55.3], [12.97, 77.6], [1.35, 103.8], [37.6, 127], [35.7, 139.7], [-33.9, 151.2], [-26.2, 28], [6.5, 3.4], [19.4, -99.1], [30, 31], [28.6, 77.2]];
const ARCS = [[0, 1], [1, 4], [4, 7], [7, 8], [8, 9], [9, 11], [11, 0], [4, 6], [6, 13], [3, 14], [9, 12], [2, 5], [10, 0], [15, 3], [5, 7], [14, 4], [6, 7], [1, 5], [8, 11], [13, 12], [0, 3], [2, 4], [16, 7], [16, 4], [17, 9], [17, 6], [14, 16], [13, 16]];
const FAMOUS = ['chatgpt', 'claude', 'gemini', 'perplexity', 'copilot', 'deepseek', 'notebooklm', 'microsoft-bing', 'grammarly', 'deepl-translator', 'zoom-ai-companion', 'tensorflow'];
const ll = (lat, lon, r) => { const a = lat * Math.PI / 180, o = lon * Math.PI / 180; return new Vector3(r * Math.cos(a) * Math.cos(o), r * Math.sin(a), -r * Math.cos(a) * Math.sin(o)); };

// ---------------------------------------------------------------- shaders
const EARTH_V = 'varying vec2 vUv;varying vec3 vN;varying vec3 vP;void main(){vUv=uv;vec4 mv=modelViewMatrix*vec4(position,1.);vN=normalize(normalMatrix*normal);vP=mv.xyz;gl_Position=projectionMatrix*mv;}';
const EARTH_F = `uniform sampler2D dayMap;uniform sampler2D nightMap;uniform sampler2D waterMap;uniform sampler2D bumpMap;uniform sampler2D cloudMap;uniform vec3 L;
varying vec2 vUv;varying vec3 vN;varying vec3 vP;
void main(){
  vec3 N=normalize(vN);vec3 V=normalize(-vP);float h=texture2D(bumpMap,vUv).r;
  float ndl=dot(N,L);float day=smoothstep(-.09,.30,ndl);
  vec3 d=texture2D(dayMap,vUv).rgb;float lum=dot(d,vec3(.2126,.7152,.0722));d=mix(vec3(lum),d,.92)*vec3(.88,1.0,1.14);
  float water=texture2D(waterMap,vUv).r;d=mix(d,d*vec3(.50,.78,1.42),water*.72);
  float cl=texture2D(cloudMap,vUv).r;float cs=texture2D(cloudMap,vUv+vec2(-.0045,.003)).r;
  float shade=.12+.88*smoothstep(-.25,.72,ndl);vec3 col=d*(.93+.14*h)*shade*(1.-.30*cs*day);
  col+=d*vec3(.04,.14,.55)*(1.-day)*1.9;col+=vec3(.08,.16,.40)*cl*(1.-day)*.6;
  float term=exp(-pow((ndl-.03)/.17,2.));col+=vec3(.95,.46,.20)*term*.08*(1.-water*.5);
  float n=texture2D(nightMap,vUv).r;float nb=texture2D(nightMap,vUv,3.2).r;float lights=pow(n,1.0)*4.0+pow(n,2.2)*4.5+nb*5.5;col+=vec3(1.,.72,.30)*lights*(1.-day*.85)*(1.-cl*.45)*1.3;
  vec3 R=reflect(-L,N);float sp=max(dot(R,V),0.);col+=vec3(.78,.90,1.)*(pow(sp,150.)*.42+pow(sp,22.)*.07)*water*day*(1.-cl*.9);
  float f=pow(1.-max(dot(N,V),0.),2.7);col+=mix(vec3(.16,.46,1.),vec3(.30,.95,1.),.5)*f*1.5;
  col+=vec3(.02,.05,.16);
  gl_FragColor=vec4(col,1.);
  #include <colorspace_fragment>
}`;
const CLOUD_F = `uniform sampler2D cloudMap;uniform vec3 L;varying vec2 vUv;varying vec3 vN;varying vec3 vP;
void main(){vec3 N=normalize(vN);float ndl=dot(N,L);float day=smoothstep(-.12,.35,ndl);float shade=.35+.65*smoothstep(-.25,.75,ndl);
  float c=texture2D(cloudMap,vUv).r;vec3 col=vec3(.50,.66,1.)*shade+vec3(.95,.55,.32)*exp(-pow(ndl/.2,2.))*.07;
  gl_FragColor=vec4(col,c*.34*mix(.50,1.,day));
  #include <colorspace_fragment>
}`;
const ATMO_V = 'varying vec3 vN;void main(){vN=normalize(normalMatrix*normal);gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.);}';
const ATMO_F = `uniform vec3 L;varying vec3 vN;void main(){
  float rim=max(0.,.80-dot(vN,vec3(0.,0.,1.)));float i=clamp(pow(rim,3.1)*5.6,0.,.95);
  float sun=.72+.28*smoothstep(-.5,.7,dot(vN,L));
  vec3 c=mix(vec3(.10,.42,1.),vec3(.22,.92,1.),smoothstep(.0,.6,rim));gl_FragColor=vec4(c*i*sun,i*sun);}`;   // premultiplied output: deliberately no sRGB re-encode
const TILE_V = 'varying vec2 vUv;void main(){vUv=uv;gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.);}';
const TILE_F = `uniform sampler2D map;uniform float uTime;uniform float uSeed;uniform float uHover;uniform float uOpacity;varying vec2 vUv;
void main(){vec4 t=texture2D(map,vUv);
  float p=fract(uTime*.085+uSeed);float x=(vUv.x*.9+vUv.y*.5);float band=smoothstep(.11,.0,abs(x-(p*2.2-.55)));
  t.rgb+=vec3(.75,.88,1.)*band*t.a*(.26+.5*uHover);t.rgb+=vec3(.35,.55,1.)*uHover*.16*t.a;
  gl_FragColor=t*uOpacity;}`;
const EDGE_F = 'uniform sampler2D map;uniform float uOpacity;varying vec2 vUv;void main(){gl_FragColor=texture2D(map,vUv)*uOpacity;}';
const RING_V = 'varying vec2 vUv;varying vec3 vN;varying vec3 vV;void main(){vUv=uv;vec4 mv=modelViewMatrix*vec4(position,1.);vN=normalize(normalMatrix*normal);vV=normalize(-mv.xyz);gl_Position=projectionMatrix*mv;}';
const RING_F = `uniform float uTime;uniform float uHue;uniform float uAlpha;uniform float uFres;varying vec2 vUv;varying vec3 vN;varying vec3 vV;
void main(){float a=vUv.x*6.2831853;vec3 blue=vec3(.22,.46,1.),cyan=vec3(.10,.86,1.),vio=vec3(.70,.38,1.);
  vec3 c=mix(mix(blue,cyan,.5+.5*sin(a*2.+uTime*.5+uHue)),vio,.35+.65*(.5+.5*sin(a*3.-uTime*.4+uHue*2.)));
  float pulse=pow(.5+.5*sin(a-uTime*.8+uHue*3.),4.);float fr=pow(abs(dot(normalize(vN),normalize(vV))),uFres);
  float al=uAlpha*(.62+1.1*pulse)*fr;gl_FragColor=vec4(c*al,al);}`;
const FLOOR_F = `varying vec2 vP;void main(){vec2 p=vP;float r=length(p*vec2(.78,1.25));
  float refl=exp(-r*r*.45)*.46;float wide=0.;
  float ring=smoothstep(.07,.0,abs(length(p)-1.96))*.55+smoothstep(.06,.0,abs(length(p)-1.70))*.38;
  float streak=(.5+.5*sin(p.y*46.))*.07*exp(-r*r*.12);
  vec3 c=mix(vec3(.34,.20,1.),vec3(.12,.52,1.),clamp(.5+p.x*.16,0.,1.));float al=(refl+wide+ring+streak)*smoothstep(3.6,2.2,length(p));gl_FragColor=vec4(c*al,al);}`;

function glowTexture(r, g, b) { const c = document.createElement('canvas'); c.width = c.height = 64; const x = c.getContext('2d'), gr = x.createRadialGradient(32, 32, 0, 32, 32, 32); gr.addColorStop(0, 'rgba(255,255,255,1)'); gr.addColorStop(.25, `rgba(${r},${g},${b},.55)`); gr.addColorStop(1, `rgba(${r},${g},${b},0)`); x.fillStyle = gr; x.fillRect(0, 0, 64, 64); return new CanvasTexture(c); }
function softTexture(stops) { const c = document.createElement('canvas'); c.width = c.height = 128; const x = c.getContext('2d'), g = x.createRadialGradient(64, 64, 0, 64, 64, 64); stops.forEach(([o, col]) => g.addColorStop(o, col)); x.fillStyle = g; x.fillRect(0, 0, 128, 128); return new CanvasTexture(c); }

// ---------------------------------------------------------------- tiles
function rr(x, a, b, w, h, r) { x.beginPath(); if (x.roundRect) x.roundRect(a, b, w, h, r); else { x.moveTo(a + r, b); x.arcTo(a + w, b, a + w, b + h, r); x.arcTo(a + w, b + h, a, b + h, r); x.arcTo(a, b + h, a, b, r); x.arcTo(a, b, a + w, b, r); x.closePath(); } }
const shade = (hex, f) => { const n = parseInt(hex, 16), c = (s) => Math.round(clamp(((n >> s) & 255) * f, 0, 255)); return `rgb(${c(16)},${c(8)},${c(0)})`; };
const mix = (hex, f) => { const n = parseInt(hex, 16), c = (s) => Math.round(((n >> s) & 255) * (1 - f) + 255 * f); return `rgb(${c(16)},${c(8)},${c(0)})`; };
// three palettes, as in the reference: navy-blue, violet and teal glass
const PAL = [{ a: [70, 150, 255], f0: [46, 92, 214], f1: [14, 28, 124], hi: [170, 220, 255] }, { a: [176, 108, 255], f0: [112, 58, 226], f1: [44, 22, 138], hi: [215, 180, 255] }, { a: [40, 215, 255], f0: [18, 118, 184], f1: [10, 58, 128], hi: [160, 240, 255] }];
const GEO = (S) => { const w = S * .76; return { w, x0: (S - w) / 2, y0: (S - w) / 2 - S * .01, r: w * .25 }; };
const rgba = (c, o) => `rgba(${c[0]},${c[1]},${c[2]},${o})`;

function drawLogo(x, img, cx, cy, size) {   // dark marks are shown as white silhouettes so every logo reads on dark glass; coloured marks keep their colours
  const o = document.createElement('canvas'); o.width = o.height = 128; const ox = o.getContext('2d'); ox.drawImage(img, 0, 0, 128, 128);
  let lum = 1; try { const d = ox.getImageData(0, 0, 128, 128).data; let sum = 0, n = 0; for (let i = 0; i < d.length; i += 16) { if (d[i + 3] > 128) { sum += (d[i] * .2126 + d[i + 1] * .7152 + d[i + 2] * .0722) / 255; n++; } } if (n) lum = sum / n; } catch (e) {}
  if (lum < .5) { ox.globalCompositeOperation = 'source-in'; ox.fillStyle = '#fff'; ox.fillRect(0, 0, 128, 128); }
  x.save(); x.shadowColor = 'rgba(140,190,255,.6)'; x.shadowBlur = size * .2; x.drawImage(o, cx - size / 2, cy - size / 2, size, size); x.restore();
}
/** Dark-glass slab: neon edge glow, vivid palette gradient, gloss, bevel, big brand mark (or monogram badge) and the tool name. */
function paintTile(tex, t, img, idx) {
  const c = tex.image, x = c.getContext('2d'), S = c.width, { w, x0, y0, r } = GEO(S), P = PAL[(idx || 0) % 3], hex = (t.hex || '4f46e5').replace('#', '');
  x.clearRect(0, 0, S, S);
  x.save(); x.shadowColor = t.sp ? 'rgba(245,176,65,.85)' : rgba(P.a, .9); x.shadowBlur = S * .09; x.fillStyle = 'rgba(10,14,60,.98)'; rr(x, x0, y0, w, w, r); x.fill(); x.restore();                     // neon edge glow
  let g = x.createLinearGradient(x0, y0, x0 + w * .75, y0 + w); g.addColorStop(0, rgba(P.f0, .93)); g.addColorStop(.5, rgba(P.f1, .95)); g.addColorStop(1, rgba(P.f0, .78)); x.fillStyle = g; rr(x, x0, y0, w, w, r); x.fill();
  x.save(); rr(x, x0, y0, w, w, r); x.clip();
  g = x.createRadialGradient(x0 + w * .98, y0 + w * 1.02, 0, x0 + w * .98, y0 + w * 1.02, w * .75); g.addColorStop(0, rgba(P.a, .62)); g.addColorStop(1, rgba(P.a, 0)); x.fillStyle = g; x.fillRect(x0, y0, w, w);       // corner bounce light
  g = x.createRadialGradient(x0, y0, 0, x0, y0, w * .7); g.addColorStop(0, rgba(P.hi, .40)); g.addColorStop(1, rgba(P.hi, 0)); x.fillStyle = g; x.fillRect(x0, y0, w, w);
  g = x.createLinearGradient(x0, y0, x0 + w * .2, y0 + w * .62); g.addColorStop(0, 'rgba(255,255,255,.34)'); g.addColorStop(.6, 'rgba(255,255,255,.07)'); g.addColorStop(1, 'rgba(255,255,255,0)');
  x.beginPath(); x.ellipse(x0 + w * .36, y0 - w * .12, w * .82, w * .62, -.12, 0, TAU); x.fillStyle = g; x.fill(); x.restore();                                                                      // gloss
  g = x.createLinearGradient(x0, y0, x0 + w, y0 + w); g.addColorStop(0, t.sp ? 'rgba(255,214,120,1)' : rgba(P.hi, 1)); g.addColorStop(.5, t.sp ? 'rgba(245,176,65,.6)' : rgba(P.a, .62)); g.addColorStop(1, t.sp ? 'rgba(245,176,65,.95)' : 'rgba(200,130,255,1)');
  x.lineWidth = S * .017; x.strokeStyle = g; rr(x, x0 + S * .006, y0 + S * .006, w - S * .012, w - S * .012, r); x.stroke();
  x.lineWidth = S * .006; x.strokeStyle = 'rgba(255,255,255,.35)'; rr(x, x0 + S * .022, y0 + S * .022, w - S * .044, w - S * .044, r * .86); x.stroke();                                              // inner bevel line
  const cy = y0 + w * .385, gs = w * .46;
  if (img) drawLogo(x, img, S / 2, cy, gs);
  else { const rad = gs * .56; g = x.createLinearGradient(S / 2 - rad, cy - rad, S / 2 + rad, cy + rad); g.addColorStop(0, mix(hex, .25)); g.addColorStop(1, shade(hex, .7)); x.save(); x.shadowColor = rgba(P.a, .8); x.shadowBlur = S * .045; x.fillStyle = g; rr(x, S / 2 - rad, cy - rad, rad * 2, rad * 2, rad * .5); x.fill(); x.restore();
    x.fillStyle = '#fff'; x.font = '700 ' + rad * 1.15 + 'px "Space Grotesk Var","Inter Var",system-ui,sans-serif'; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText((t.label || t.name || '?').charAt(0).toUpperCase(), S / 2, cy + rad * .06); }
  let nm = String(t.label || t.name || ''); x.font = '700 ' + S * .092 + 'px "Inter Var",system-ui,sans-serif'; x.textAlign = 'center'; x.textBaseline = 'middle';
  if (x.measureText(nm).width > w * .86) { while (nm.length > 3 && x.measureText(nm + '…').width > w * .86) nm = nm.slice(0, -1); nm = nm.trimEnd() + '…'; }
  x.fillStyle = 'rgba(255,255,255,.98)'; x.shadowColor = 'rgba(0,0,30,.65)'; x.shadowBlur = S * .014; x.fillText(nm, S / 2, y0 + w * .79); x.shadowColor = 'transparent';
  if (t.sp) { const pw = S * .42, ph = S * .095, px = S / 2 - pw / 2, py = y0 - ph * .45; x.fillStyle = '#f5b041'; rr(x, px, py, pw, ph, ph / 2); x.fill(); x.fillStyle = '#3b2400'; x.font = '800 ' + ph * .56 + 'px system-ui,sans-serif'; x.fillText('SPONSORED', S / 2, py + ph / 2 + 1); }
  tex.needsUpdate = true;
}
function edgeTexture(S, i) {   // the slab's side/back: same silhouette, deep gradient with a violet/blue glow, drawn behind the face to give real thickness when tilted
  const c = document.createElement('canvas'); c.width = c.height = S; const x = c.getContext('2d'), { w, x0, y0, r } = GEO(S), P = PAL[i];
  const g = x.createLinearGradient(x0, y0, x0 + w, y0 + w); g.addColorStop(0, rgba(P.a, 1)); g.addColorStop(1, 'rgba(120,60,230,1)'); x.fillStyle = g; rr(x, x0, y0, w, w, r); x.fill();
  const t = new CanvasTexture(c); t.colorSpace = NoColorSpace; t.premultiplyAlpha = true; return t;
}

export async function init(box, data, opts = {}) {
  const canvas = box.querySelector('canvas') || box.appendChild(document.createElement('canvas'));
  const base = box.dataset.base || '/assets/globe/';
  const mobile = innerWidth < 700, freeze = typeof opts.freezeAt === 'number';
  const Y = () => freeze ? null : new Promise((res) => { if (window.scheduler && scheduler.yield) scheduler.yield().then(res); else setTimeout(res, 0); });   // keeps every initialisation slice short so the page stays responsive
  let renderer;
  try { renderer = new WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'high-performance' }); } catch (e) { return false; }
  renderer.setClearColor(0x000000, 0);
  renderer.setPixelRatio(Math.min(devicePixelRatio || 1, mobile ? 1.5 : 2));
  const scene = new Scene(), camera = new PerspectiveCamera(30, 1, .1, 60); camera.position.set(0, 0, 8.5);
  const rig = new Group(), world = new Group(), orbit = new Group(); scene.add(rig); rig.add(world, orbit);
  const L0 = 14;   // longitude facing the viewer at load (Europe / Africa / Middle East: the same view as the reference)
  world.rotation.z = -.16; world.rotation.y = -Math.PI / 2 - L0 * Math.PI / 180; rig.rotation.x = .40;
  const SUN = new Vector3(.62, .50, -.30).normalize();
  const RS = mobile ? .9 : 1;   // phones use slightly tighter orbits so the Earth stays large

  // --- Backdrop that blends into the page gradient: nebula glows, stars, outer halo ---------------------------------------------------------------
  const neb = (col, x, y, z, sc) => { const s = new Sprite(new SpriteMaterial({ map: softTexture([[0, col + '.10)'], [.45, col + '.015)'], [1, col + '0)']]), transparent: true, depthWrite: false, blending: AdditiveBlending })); s.position.set(x, y, z); s.scale.setScalar(sc); scene.add(s); };
  const halo = new Sprite(new SpriteMaterial({ map: softTexture([[0, 'rgba(70,140,255,.26)'], [.22, 'rgba(60,110,255,.07)'], [.5, 'rgba(60,90,255,0)'], [1, 'rgba(60,90,255,0)']]), transparent: true, depthWrite: false, blending: AdditiveBlending })); halo.position.set(0, 0, -1.2); halo.scale.setScalar(4.2); scene.add(halo);
  const starPos = []; for (let i = 0; i < 260; i++) { const u = Math.random() * TAU, v = Math.random() * 2 - 1, rr2 = 6 + Math.random() * 4, q = Math.sqrt(1 - v * v); starPos.push(Math.cos(u) * q * rr2 * 1.2, v * rr2 * .7, -Math.abs(Math.sin(u) * q) * rr2 - 2.5); }
  const starGeo = new BufferGeometry(); starGeo.setAttribute('position', new Float32BufferAttribute(starPos, 3));
  scene.add(new Points(starGeo, new PointsMaterial({ map: glowTexture(190, 205, 255), size: .12, transparent: true, opacity: .85, depthWrite: false, blending: AdditiveBlending, premultipliedAlpha: true, color: 0xc4d4ff })));

  await Y();
  // --- Earth, cloud shell, luminous atmosphere --------------------------------------------------------------------------------------------------
  const loader = new TextureLoader(), tex = (f, srgb) => { const t = loader.load(base + f, () => { box.dataset.tex = String((+box.dataset.tex || 0) + 1); if (freeze) tick(opts.freezeAt); }); if (srgb) t.colorSpace = SRGBColorSpace; t.anisotropy = 4; t.wrapS = RepeatWrapping; return t; };
  const cloudTex = tex('earth-clouds.webp', false);
  const earthMat = new ShaderMaterial({ vertexShader: EARTH_V, fragmentShader: EARTH_F, uniforms: { dayMap: { value: tex('earth-day.webp', true) }, nightMap: { value: tex('earth-night.webp', false) }, waterMap: { value: tex('earth-water.webp', false) }, bumpMap: { value: tex('earth-bump.webp', false) }, cloudMap: { value: cloudTex }, L: { value: SUN } } });
  world.add(new Mesh(new SphereGeometry(1, mobile ? 72 : 112, mobile ? 54 : 84), earthMat));
  world.add(new Mesh(new SphereGeometry(1.012, mobile ? 56 : 88, mobile ? 42 : 66), new ShaderMaterial({ vertexShader: EARTH_V, fragmentShader: CLOUD_F, transparent: true, depthWrite: false, uniforms: { cloudMap: { value: cloudTex }, L: { value: SUN } } })));
  const atmo = new Mesh(new SphereGeometry(1.10, 64, 48), new ShaderMaterial({ vertexShader: ATMO_V, fragmentShader: ATMO_F, side: BackSide, transparent: true, premultipliedAlpha: true, depthWrite: false, uniforms: { L: { value: SUN } } })); scene.add(atmo);
  // sun flare on the upper-right limb (as in the reference)
  const flare = (() => { const c = document.createElement('canvas'); c.width = c.height = 256; const x = c.getContext('2d'); let g = x.createRadialGradient(128, 128, 0, 128, 128, 128); g.addColorStop(0, 'rgba(255,246,215,1)'); g.addColorStop(.05, 'rgba(255,190,100,.95)'); g.addColorStop(.16, 'rgba(255,140,50,.55)'); g.addColorStop(.45, 'rgba(255,110,40,.12)'); g.addColorStop(1, 'rgba(255,100,60,0)'); x.fillStyle = g; x.fillRect(0, 0, 256, 256);
    x.globalCompositeOperation = 'lighter'; for (const [ang, len, a] of [[0, 1, .75], [Math.PI / 2, 1, .6], [Math.PI / 4, .6, .35], [-Math.PI / 4, .6, .35]]) { x.save(); x.translate(128, 128); x.rotate(ang); const lg = x.createLinearGradient(-128 * len, 0, 128 * len, 0); lg.addColorStop(0, 'rgba(255,200,120,0)'); lg.addColorStop(.5, `rgba(255,230,180,${a})`); lg.addColorStop(1, 'rgba(255,200,120,0)'); x.fillStyle = lg; x.fillRect(-128 * len, -2.2, 256 * len, 4.4); x.restore(); } return new CanvasTexture(c); })();
  const sunSpr = new Sprite(new SpriteMaterial({ map: flare, transparent: true, depthWrite: false, depthTest: false, blending: AdditiveBlending, opacity: .8 })); sunSpr.position.copy(SUN).multiplyScalar(1.03); sunSpr.scale.setScalar(1.25); sunSpr.renderOrder = 20; scene.add(sunSpr);

  await Y();
  // --- Glossy reflective floor with violet/blue glow --------------------------------------------------------------------------------------------
  const floor = new Mesh(new PlaneGeometry(9, 9), new ShaderMaterial({ transparent: true, depthWrite: false, blending: CustomBlending, blendSrc: OneFactor, blendDst: OneFactor, premultipliedAlpha: true, vertexShader: 'varying vec2 vP;void main(){vP=position.xy;gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.);}', fragmentShader: FLOOR_F }));
  floor.rotation.x = -Math.PI / 2; floor.position.y = -1.62 * RS; scene.add(floor);

  // --- Glowing orbit rings (blue / cyan / violet). Two carry the tiles; one bright ring crosses in front of the Earth -----------------------------------
  const rings = [{ r: 1.70 * RS, tilt: .06, sp: .07, n: mobile ? 4 : 5, hue: 0 }, { r: 1.96 * RS, tilt: -.14, sp: -.05, n: mobile ? 4 : 5, hue: 2.1 }, { r: 1.30, tilt: .50, sp: .11, n: 0, hue: 4.2 }];
  const glowMats = []; const ringMat = (a, f) => { const m = new ShaderMaterial({ vertexShader: RING_V, fragmentShader: RING_F, transparent: true, depthWrite: false, blending: CustomBlending, blendSrc: OneFactor, blendDst: OneFactor, premultipliedAlpha: true, uniforms: { uTime: { value: 0 }, uHue: { value: 0 }, uAlpha: { value: a }, uFres: { value: f } } }); glowMats.push(m); return m; };
  const ringObjs = rings.map((R) => { const grp = new Group(); grp.rotation.z = R.tilt;
    [[.016, 1.15, 1.0], [.055, .36, 1.8], [.13, .12, 2.4]].forEach(([tube, alpha, fres]) => { const g = new TorusGeometry(R.r, tube, 8, 240); g.rotateX(Math.PI / 2); const m = ringMat(alpha, fres); m.uniforms.uHue.value = R.hue; grp.add(new Mesh(g, m)); });
    orbit.add(grp); return grp; });

  await Y();
  // --- Network: hubs, arcs and travelling light particles (attached to the Earth) ---------------------------------------------------------------------
  const glow = glowTexture(130, 215, 255); const hubPos = HUBS.map(([a, b]) => ll(a, b, 1.008));
  const hubGeo = new BufferGeometry(); hubGeo.setAttribute('position', new Float32BufferAttribute(hubPos.flatMap((v) => [v.x, v.y, v.z]), 3));
  world.add(new Points(hubGeo, new PointsMaterial({ map: glow, size: .12, transparent: true, depthWrite: false, blending: AdditiveBlending, premultipliedAlpha: true, color: 0xbfeaff })));
  const arcCurves = ARCS.map(([i, j]) => { const a = hubPos[i], b = hubPos[j], m = a.clone().add(b).multiplyScalar(.5), d = a.distanceTo(b); m.setLength(1 + d * .38);
    const pts = []; for (let k = 0; k <= 56; k++) { const t = k / 56, u = 1 - t; pts.push(a.clone().multiplyScalar(u * u).add(m.clone().multiplyScalar(2 * u * t)).add(b.clone().multiplyScalar(t * t))); } return pts; });
  arcCurves.forEach((pts, i) => { world.add(new Line(new BufferGeometry().setFromPoints(pts), new LineBasicMaterial({ color: [0x7fd0ff, 0xa59bff, 0x6ff0ff][i % 3], transparent: true, opacity: .42, blending: AdditiveBlending, premultipliedAlpha: true, depthWrite: false }))); });
  const pulseN = mobile ? 14 : 26, pulses = [], pGeo = new BufferGeometry(); pGeo.setAttribute('position', new Float32BufferAttribute(new Float32Array(pulseN * 3), 3)); pGeo.attributes.position.setUsage(DynamicDrawUsage);
  for (let i = 0; i < pulseN; i++) pulses.push({ arc: i % arcCurves.length, t: (i * .37) % 1, v: .07 + (i % 5) * .012 });
  world.add(new Points(pGeo, new PointsMaterial({ map: glow, size: .15, transparent: true, depthWrite: false, blending: AdditiveBlending, premultipliedAlpha: true, color: 0xe8f8ff })));

  await Y();
  // --- Tool tiles ------------------------------------------------------------------------------------------------------------------------------------
  const all = (data.tools || []).slice(0, 48), sponsors = (data.sponsors || []).slice(0, 6);
  const rank = (t) => { const i = FAMOUS.indexOf(t.id); return i < 0 ? 99 : i; };
  const withLogo = all.filter((t) => t.svg).sort((a, b) => rank(a) - rank(b)), without = all.filter((t) => !t.svg), pool = [];
  let li = 0, wi = 0; while (li < withLogo.length || wi < without.length) { for (let k = 0; k < 2 && li < withLogo.length; k++) pool.push(withLogo[li++]); if (wi < without.length) pool.push(without[wi++]); }   // two official-logo tools for every monogram tool
  const imgCache = {}; const loadImg = (src) => imgCache[src] || (imgCache[src] = new Promise((res) => { const im = new Image(); im.onload = () => res(im); im.onerror = () => res(null); im.src = src; }));
  const TS = mobile ? 320 : 384, edgeTex = [0, 1, 2].map((i) => edgeTexture(TS, i)), planeGeo = new PlaneGeometry(1, 1);
  const slots = []; let order = 0;
  rings.forEach((R, ri) => { for (let k = 0; k < R.n; k++) {
    const t2 = new CanvasTexture(Object.assign(document.createElement('canvas'), { width: TS, height: TS })); t2.generateMipmaps = true; t2.minFilter = LinearMipmapLinearFilter; t2.magFilter = LinearFilter; t2.colorSpace = NoColorSpace; t2.premultiplyAlpha = true; t2.anisotropy = 4;
    const idx = order, node = new Group();
    const face = new Mesh(planeGeo, new ShaderMaterial({ vertexShader: TILE_V, fragmentShader: TILE_F, transparent: true, depthWrite: false, premultipliedAlpha: true, uniforms: { map: { value: t2 }, uTime: { value: 0 }, uSeed: { value: (order * .37) % 1 }, uHover: { value: 0 }, uOpacity: { value: 1 } } }));
    const edgeMats = []; for (let e = 1; e <= 4; e++) { const em = new ShaderMaterial({ vertexShader: TILE_V, fragmentShader: EDGE_F, transparent: true, depthWrite: false, premultipliedAlpha: true, uniforms: { map: { value: edgeTex[idx % 3] }, uOpacity: { value: .9 - e * .12 } } }); edgeMats.push(em); const m = new Mesh(planeGeo, em); m.position.z = -e * .02; node.add(m); }
    node.add(face); orbit.children[ri].add(node);
    slots.push({ ring: ri, a: (k / R.n) * TAU + [0, 0.6, 0][ri], spr: node, face, edgeMats, tex: t2, tool: null, scale: 0, target: 1, hub: (order * 5 + 3) % HUBS.length, sponsor: false, idx: order++, hv: 0 });
  } });
  if (sponsors.length) { slots[1].sponsor = true; slots[rings[0].n + 1].sponsor = true; }   // premium positions exist only when there are genuinely sponsored listings; no placeholder ads
  let poolPtr = 0, sponsorPtr = 0, firstFill = 0;
  const nextPool = () => { for (let n = 0; n < pool.length; n++) { const t = pool[poolPtr++ % pool.length]; if (!slots.some((s) => s.tool && s.tool.id === t.id)) return t; } return pool[0]; };
  const nextSponsor = (slot) => { for (let n = 0; n < sponsors.length; n++) { const t = Object.assign({ sp: true }, sponsors[sponsorPtr++ % sponsors.length]); if (!slots.some((s) => s !== slot && s.tool && s.tool.id === t.id)) return t; } return Object.assign({ sp: true }, sponsors[0]); };
  function assign(slot, t) { slot.tool = t; paintTile(slot.tex, t, null, slot.idx); if (t.svg) loadImg(t.svg).then((im) => { if (slot.tool === t && im) paintTile(slot.tex, t, im, slot.idx); }); }
  let paintN = 0; for (const s of slots) { if (s.sponsor) assign(s, nextSponsor(s)); else assign(s, withLogo[firstFill] ? withLogo[firstFill++] : nextPool()); if (++paintN % 2 === 0) await Y(); }   // the first view shows the best-known official logos
  const tGeo = new BufferGeometry(); tGeo.setAttribute('position', new Float32BufferAttribute(new Float32Array(slots.length * 6), 3)); tGeo.attributes.position.setUsage(DynamicDrawUsage);
  const tethers = new LineSegments(tGeo, new LineBasicMaterial({ color: 0x9db4ff, transparent: true, opacity: .18, depthWrite: false, blending: AdditiveBlending, premultipliedAlpha: true })); scene.add(tethers);

  // --- Interaction -----------------------------------------------------------------------------------------------------------------------------
  const tip = box.querySelector('.globeTip'); const tipEls = tip ? { n: tip.querySelector('[data-n]'), c: tip.querySelector('[data-c]'), p: tip.querySelector('[data-p]'), a: tip.querySelector('[data-a]') } : null;
  let hover = null, drag = null, vel = 0, velX = 0, mx = 0, my = 0, tx = 0, ty = 0, running = false, visible = true, last = 0, timeScale = 1, swapAt = 3.2, sponsorAt = 9, simT = 0, tapped = null, W = 0, H = 0, camZ = 8.5;
  const pos = new Vector3(), proj = new Vector3(), qP = new Quaternion(), qT = new Quaternion(), eul = new Euler();
  function resize() {
    const r = box.getBoundingClientRect(); W = Math.max(1, Math.round(r.width)); H = Math.max(1, Math.round(r.height)); renderer.setSize(W, H, false);
    camera.aspect = W / H; camera.fov = 30; camera.updateProjectionMatrix();
    const t = Math.tan(camera.fov * Math.PI / 360); camZ = Math.max((mobile ? 2.2 : 2.38) / (t * camera.aspect), 1.95 / t); camera.position.z = camZ;
  }
  function screenOf(s) { s.spr.getWorldPosition(pos); proj.copy(pos).project(camera); return { x: (proj.x * .5 + .5) * W, y: (-proj.y * .5 + .5) * H, z: pos.z }; }
  function pick(px, py) { let best = null, bd = 1e9; for (const s of slots) { if (!s.tool || s.scale < .5) continue; const p = screenOf(s); if (p.z < -.35) continue; const rad = Math.max(30, W * .07) * s.scale, d = Math.hypot(p.x - px, p.y - py); if (d < rad && d < bd) { bd = d; best = s; } } return best; }
  function showTip(s) {
    if (!tip || !tipEls) return; if (!s) { tip.hidden = true; return; }
    const t = s.tool, p = screenOf(s); tipEls.n.textContent = t.name; tipEls.c.textContent = [t.cat, t.note].filter(Boolean).join(' · '); tipEls.p.textContent = t.prov || '';
    tipEls.a.hidden = !t.sp; tip.dataset.sp = t.sp ? '1' : '0'; tip.hidden = false;
    const tw = tip.offsetWidth, th = tip.offsetHeight; tip.style.left = clamp(p.x - tw / 2, 6, W - tw - 6) + 'px'; tip.style.top = clamp(p.y - th - 34, 6, H - th - 6) + 'px';
  }
  function go(t) { const a = document.createElement('a'); a.href = t.url; a.style.display = 'none'; a.setAttribute('data-cta', t.sp ? 'globe_sponsored' : 'globe_tool'); if (t.sp) a.rel = 'sponsored nofollow'; box.appendChild(a); a.click(); }
  canvas.addEventListener('pointerdown', (e) => { drag = { x: e.clientX, y: e.clientY, moved: 0, id: e.pointerId, type: e.pointerType }; vel = velX = 0; try { canvas.setPointerCapture(e.pointerId); } catch (_) {} box.classList.add('is-drag'); });
  canvas.addEventListener('pointermove', (e) => {
    const r = canvas.getBoundingClientRect(), px = e.clientX - r.left, py = e.clientY - r.top;
    if (drag && drag.id === e.pointerId) { const dx = e.clientX - drag.x, dy = e.clientY - drag.y; drag.x = e.clientX; drag.y = e.clientY; drag.moved += Math.abs(dx) + Math.abs(dy); rig.rotation.y += dx * .0058; rig.rotation.x = clamp(rig.rotation.x + dy * .0036, -.3, .9); vel = dx * .0058 * 60; velX = dy * .0036 * 60; if (tip) tip.hidden = true; return; }
    if (e.pointerType === 'mouse') { mx = (px / W - .5) * 2; my = (py / H - .5) * 2; const s = pick(px, py); if (s !== hover) { hover = s; canvas.style.cursor = s ? 'pointer' : 'grab'; showTip(s); } }
  });
  const end = (e) => { if (!drag) return; const d = drag; drag = null; box.classList.remove('is-drag'); const r = canvas.getBoundingClientRect(), s = d.moved < 8 ? pick(e.clientX - r.left, e.clientY - r.top) : null;
    if (s) { if (d.type === 'touch' || d.type === 'pen') { if (tapped === s) { go(s.tool); } else { tapped = s; hover = s; showTip(s); } } else go(s.tool); } else if (d.moved < 8) { tapped = null; showTip(null); } };
  canvas.addEventListener('pointerup', end); canvas.addEventListener('pointercancel', () => { drag = null; box.classList.remove('is-drag'); });
  canvas.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') { hover = null; mx = my = 0; showTip(null); } });
  canvas.style.cursor = 'grab'; canvas.style.touchAction = 'pan-y';

  // --- Frame -----------------------------------------------------------------------------------------------------------------------------------
  const arcP = new Vector3();
  function tick(t) {
    const dt = freeze ? 0 : Math.min(.05, t - last); last = t; if (!freeze) simT += dt; else simT = opts.freezeAt;
    timeScale += ((hover || drag ? .25 : 1) - timeScale) * Math.min(1, dt * 4);
    const k = dt * timeScale;
    if (!freeze) { world.rotation.y += k * .10; orbit.rotation.y += k * .02; rig.rotation.y += vel * dt; vel *= Math.pow(.04, dt); rig.rotation.x = clamp(rig.rotation.x + velX * dt, -.3, .9); velX *= Math.pow(.04, dt); rig.rotation.x += (.40 - rig.rotation.x) * Math.min(1, dt * .25); }
    ringObjs.forEach((g, i) => { g.rotation.y += k * rings[i].sp; }); glowMats.forEach((m) => { m.uniforms.uTime.value = simT; });
    tx += (mx * .13 - tx) * Math.min(1, dt * 2.5); ty += (my * .07 - ty) * Math.min(1, dt * 2.5); scene.rotation.y = tx; scene.rotation.x = ty;   // cursor-responsive perspective
    camera.position.x += (mx * .18 - camera.position.x) * Math.min(1, dt * 3); camera.position.y += (-my * .12 - camera.position.y) * Math.min(1, dt * 3); camera.lookAt(0, 0, 0);
    if (!freeze) {
      if (simT > swapAt) { swapAt = simT + (mobile ? 3.6 : 3.0); const free = slots.filter((s) => !s.sponsor && s.target === 1 && s !== hover); if (free.length) { const s = free[Math.floor((order++ * 7) % free.length)]; s.target = 0; s.next = nextPool(); } }
      if (simT > sponsorAt) { sponsorAt = simT + 10; const sp = slots.filter((s) => s.sponsor && s.target === 1 && s !== hover); if (sp.length && sponsors.length > 1) { const s = sp[sponsorPtr % sp.length]; s.target = 0; s.next = nextSponsor(s); } }
    }
    scene.updateMatrixWorld(true);
    const pa = tGeo.attributes.position.array; const hubW = hubPos.map((v) => v.clone().applyMatrix4(world.matrixWorld));
    slots.forEach((s, i) => {
      s.scale += (s.target - s.scale) * Math.min(1, dt * 5); if (freeze) s.scale = 1;
      if (s.target === 0 && s.scale < .04) { assign(s, s.next); s.target = 1; if (hover === s) { hover = null; showTip(null); } }
      const R = rings[s.ring]; s.spr.position.set(Math.cos(s.a) * R.r, 0, Math.sin(s.a) * R.r); s.spr.updateWorldMatrix(true, false); s.spr.getWorldPosition(pos);
      const zn = clamp(pos.z / 2.4, -1, 1), front = smooth(zn, -.75, .3); proj.copy(pos).project(camera);
      s.hv += ((hover === s ? 1 : 0) - s.hv) * Math.min(1, dt * 8);
      // 3D slab: faces the camera, then leans according to where it sits on screen (stronger tilt toward the edges, as in the reference)
      s.spr.parent.getWorldQuaternion(qP); qT.setFromEuler(eul.set(-proj.y * .34, proj.x * .62, -proj.x * .10)); s.spr.quaternion.copy(qP.invert()).multiply(camera.quaternion).multiply(qT);
      s.spr.scale.setScalar((mobile ? .86 : .72) * s.scale * (1 + .2 * s.hv) * (1 + .11 * zn));
      const op = (.62 + .38 * front) * Math.min(1, s.scale * 1.4), u = s.face.material.uniforms; u.uOpacity.value = op; u.uHover.value = s.hv; u.uTime.value = simT; s.edgeMats.forEach((m, e) => { m.uniforms.uOpacity.value = op * (.9 - (e + 1) * .12); });
      let bi = s.hub, bd = hubW[bi].distanceToSquared(pos); for (let h = 0; h < hubW.length; h++) { const d = hubW[h].distanceToSquared(pos); if (d < bd * .72) { bd = d; bi = h; } } s.hub = bi; const hp = hubW[bi];
      pa[i * 6] = pos.x; pa[i * 6 + 1] = pos.y; pa[i * 6 + 2] = pos.z; pa[i * 6 + 3] = hp.x; pa[i * 6 + 4] = hp.y; pa[i * 6 + 5] = hp.z;
    });
    tGeo.attributes.position.needsUpdate = true;
    const pp = pGeo.attributes.position.array; pulses.forEach((p, i) => { if (!freeze) { p.t += dt * p.v * timeScale; if (p.t > 1) { p.t -= 1; p.arc = (p.arc + 5) % arcCurves.length; } } const pts = arcCurves[p.arc], f = p.t * (pts.length - 1), a = Math.floor(f), b = Math.min(pts.length - 1, a + 1); arcP.copy(pts[a]).lerp(pts[b], f - a); pp[i * 3] = arcP.x; pp[i * 3 + 1] = arcP.y; pp[i * 3 + 2] = arcP.z; });
    pGeo.attributes.position.needsUpdate = true;
    if (hover && tip && !tip.hidden) showTip(hover);
    renderer.render(scene, camera);
  }
  function loop(ms) { if (!running) return; const t = ms / 1000; if (!last) last = t; tick(t); requestAnimationFrame(loop); }
  function start() { if (running || freeze) return; running = true; last = 0; requestAnimationFrame(loop); }
  function stop() { running = false; }
  resize(); new ResizeObserver(() => { resize(); if (!running) tick(last); }).observe(box);
  if (freeze) { tick(opts.freezeAt); box.dataset.ready = '1'; box.snapshot = () => { tick(opts.freezeAt); return canvas.toDataURL('image/png'); }; return true; }   // used only to render assets/globe/earth-poster*.webp
  if (renderer.compileAsync) { try { await renderer.compileAsync(scene, camera); } catch (e) {} }   // shader compilation off the main thread where the browser supports it
  const io = new IntersectionObserver((en) => { visible = en[0].isIntersecting; (visible && !document.hidden) ? start() : stop(); }, { threshold: .05 }); io.observe(box);
  document.addEventListener('visibilitychange', () => { (visible && !document.hidden) ? start() : stop(); });
  start(); box.classList.add('is-live'); box.dataset.ready = '1';
  return true;
}

// auto-start for the homepage
(function () { const box = document.querySelector('[data-globe]'); if (!box || box.dataset.ready) return; let data = {}; try { data = JSON.parse(document.getElementById('globeData').textContent); } catch (e) { return; }
  const q = new URLSearchParams(location.search); init(box, data, q.has('globefreeze') ? { freezeAt: parseFloat(q.get('globefreeze')) || 0 } : {}); })();
