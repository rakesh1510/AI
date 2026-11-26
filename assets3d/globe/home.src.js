/**
 * PROTOTYPE (not integrated): photoreal Earth + premium dark-glass AI application tiles. 80% realism / 20% restrained futuristic light.
 * Earth: NASA Blue Marble 4K, Black Marble city lights, real cloud layer with cloud shadows, ocean sun glint, thin physically-shaped atmosphere, warm limb flare.
 * Tiles: smoked dark glass, official marks (sharp, 512 px) + names. Orbits: thin blue/cyan/violet paths. No floor, halo or nebula.
 */
import {
  Shape, ExtrudeGeometry, MeshPhysicalMaterial, AmbientLight, DirectionalLight, WebGLRenderer, Scene, PerspectiveCamera, Group, Mesh, SphereGeometry, PlaneGeometry, TorusGeometry, ShaderMaterial, TextureLoader, SRGBColorSpace, NoColorSpace, AdditiveBlending, CustomBlending, OneFactor, BackSide, Vector3, Quaternion, Euler,
  CanvasTexture, Sprite, SpriteMaterial, BufferGeometry, Float32BufferAttribute, Line, LineBasicMaterial, Points, PointsMaterial, MathUtils, LinearFilter, LinearMipmapLinearFilter, DynamicDrawUsage, RepeatWrapping, WebGLRenderTarget, HalfFloatType, Vector2, Color
} from 'three';
import { EffectComposer } from 'three/examples/jsm/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/examples/jsm/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/examples/jsm/postprocessing/UnrealBloomPass.js';
import { ShaderPass } from 'three/examples/jsm/postprocessing/ShaderPass.js';
import { OutputPass } from 'three/examples/jsm/postprocessing/OutputPass.js';

const TAU = Math.PI * 2, clamp = MathUtils.clamp, smooth = MathUtils.smoothstep;
const HUBS = [[37.8, -122.4], [40.7, -74], [51.5, -0.1], [48.9, 2.3], [50.1, 8.7], [25.2, 55.3], [12.97, 77.6], [1.35, 103.8], [35.7, 139.7], [-33.9, 151.2], [-26.2, 28], [6.5, 3.4], [-23.5, -46.6], [30, 31], [28.6, 77.2], [55.7, 37.6]];
const ARCS = [[0, 1], [1, 2], [2, 5], [5, 6], [6, 7], [7, 8], [2, 4], [4, 13], [13, 10], [11, 2], [7, 9], [3, 15], [14, 8], [12, 11], [5, 14], [15, 6], [1, 12], [13, 5]];
const FAMOUS = ['chatgpt', 'claude', 'gemini', 'perplexity', 'copilot', 'deepseek', 'notebooklm', 'microsoft-bing', 'grammarly', 'deepl-translator'];
const ll = (lat, lon, r) => { const a = lat * Math.PI / 180, o = lon * Math.PI / 180; return new Vector3(r * Math.cos(a) * Math.cos(o), r * Math.sin(a), -r * Math.cos(a) * Math.sin(o)); };

const EARTH_V = 'varying vec2 vUv;varying vec3 vN;varying vec3 vP;void main(){vUv=uv;vec4 mv=modelViewMatrix*vec4(position,1.);vN=normalize(normalMatrix*normal);vP=mv.xyz;gl_Position=projectionMatrix*mv;}';
const EARTH_F = `uniform sampler2D dayMap;uniform sampler2D nightMap;uniform sampler2D waterMap;uniform sampler2D cloudMap;uniform vec3 L;
varying vec2 vUv;varying vec3 vN;varying vec3 vP;
void main(){
  vec3 N=normalize(vN),V=normalize(-vP);float ndl=dot(N,L),ndv=max(dot(N,V),0.);
  vec3 d=texture2D(dayMap,vUv).rgb;float water=texture2D(waterMap,vUv).r;d=mix(d,d*vec3(.78,.91,1.12),water*.45);
  float cl=texture2D(cloudMap,vUv).r;float cs=texture2D(cloudMap,vUv+vec2(-.0035,.0025)).r;
  float lit=smoothstep(-.04,.55,ndl);float day=smoothstep(-.02,.20,ndl);
  vec3 col=d*(.11+1.24*lit)*(1.-.40*cs*day);                       // sunlit surface, cloud shadows
  col+=d*vec3(.075,.14,.31)*(1.-day);                              // faint earthshine on the night side, keeps continents readable
  float term=exp(-pow((ndl-.02)/.12,2.));col+=vec3(.90,.42,.16)*term*.07*(1.-water*.6);   // warm dusk band at the terminator
  vec3 R=reflect(-L,N);float sp=max(dot(R,V),0.);col+=vec3(.85,.93,1.)*(pow(sp,90.)*.55+pow(sp,14.)*.06)*water*day*(1.-cl*.9);   // ocean sun glint
  float n=texture2D(nightMap,vUv).r;float nb=texture2D(nightMap,vUv,3.0).r;float lights=n*1.35+pow(n,2.)*.65+nb*.20;
  col+=vec3(1.,.53,.16)*lights*(1.-day)*(1.-cl*.4)*1.05;            // golden city lights only on the dark side
  float f=pow(1.-ndv,4.2);vec3 sky=mix(vec3(.18,.36,.80),vec3(.52,.72,1.),day);
  col=mix(col,sky,f*(.10+.30*day));col+=sky*f*.12*(.25+.75*day);col+=vec3(1.,.52,.20)*f*pow(max(ndl,0.),2.2)*.34;   // warm sunrise limb    // thin atmospheric scattering at the limb (strongest on the sunlit side)
  gl_FragColor=vec4(col,1.);
  #include <colorspace_fragment>
}`;
const CLOUD_F = `uniform sampler2D cloudMap;uniform vec3 L;varying vec2 vUv;varying vec3 vN;varying vec3 vP;
void main(){vec3 N=normalize(vN);float ndl=dot(N,L);float day=smoothstep(-.02,.20,ndl);float lit=smoothstep(-.06,.6,ndl);
  float c=texture2D(cloudMap,vUv).r;vec3 col=vec3(.66,.78,.98)*(.16+.85*lit)+vec3(.95,.55,.30)*exp(-pow(ndl/.14,2.))*.06;
  gl_FragColor=vec4(col,c*.78*mix(.20,1.,day));
  #include <colorspace_fragment>
}`;
const ATMO_V = 'varying vec3 vN;void main(){vN=normalize(normalMatrix*normal);gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.);}';
const ATMO_F = `uniform vec3 L;varying vec3 vN;void main(){
  float rim=max(0.,.80-dot(vN,vec3(0.,0.,1.)));float i=clamp(pow(rim,5.5)*1.6,0.,.42);
  float sun=smoothstep(-.35,.75,dot(vN,L));
  vec3 c=mix(vec3(.12,.36,.95),vec3(.30,.62,1.),sun);c*=c;gl_FragColor=vec4(c*i*(.40+.60*sun),i*(.40+.60*sun));}`;   // premultiplied, no sRGB re-encode
const TILE_V = 'varying vec2 vUv;void main(){vUv=uv;gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.);}';
const TILE_F = `uniform sampler2D map;uniform float uTime;uniform float uSeed;uniform float uHover;uniform float uOpacity;varying vec2 vUv;
void main(){vec4 t=texture2D(map,vUv);
  float p=fract(uTime*.07+uSeed);float x=(vUv.x*.9+vUv.y*.5);float band=smoothstep(.09,.0,abs(x-(p*2.2-.55)));
  t.rgb+=vec3(.75,.88,1.)*band*t.a*(.14+.4*uHover);t.rgb+=vec3(.30,.50,1.)*uHover*.12*t.a;
  gl_FragColor=t*uOpacity;}`;
const EDGE_F = 'uniform sampler2D map;uniform float uOpacity;varying vec2 vUv;void main(){gl_FragColor=texture2D(map,vUv)*uOpacity;}';
const RING_V = 'varying vec2 vUv;varying vec3 vN;varying vec3 vV;void main(){vUv=uv;vec4 mv=modelViewMatrix*vec4(position,1.);vN=normalize(normalMatrix*normal);vV=normalize(-mv.xyz);gl_Position=projectionMatrix*mv;}';
const RING_F = `uniform float uTime;uniform float uHue;uniform float uAlpha;uniform float uFres;varying vec2 vUv;varying vec3 vN;varying vec3 vV;
void main(){float a=vUv.x*6.2831853;vec3 blue=vec3(.25,.50,1.),cyan=vec3(.14,.80,1.),vio=vec3(.62,.42,1.);
  vec3 c=mix(mix(blue,cyan,.5+.5*sin(a*2.+uTime*.4+uHue)),vio,.30+.55*(.5+.5*sin(a*3.-uTime*.3+uHue*2.)));
  float pulse=pow(.5+.5*sin(a-uTime*.6+uHue*3.),4.);float fr=pow(abs(dot(normalize(vN),normalize(vV))),uFres);
  float al=uAlpha*(.55+.9*pulse)*fr;c*=c;gl_FragColor=vec4(c*al,al);}`;

function glowTexture(r, g, b) { const c = document.createElement('canvas'); c.width = c.height = 64; const x = c.getContext('2d'), gr = x.createRadialGradient(32, 32, 0, 32, 32, 32); gr.addColorStop(0, 'rgba(255,255,255,1)'); gr.addColorStop(.25, `rgba(${r},${g},${b},.5)`); gr.addColorStop(1, `rgba(${r},${g},${b},0)`); x.fillStyle = gr; x.fillRect(0, 0, 64, 64); return new CanvasTexture(c); }
function rr(x, a, b, w, h, r) { x.beginPath(); if (x.roundRect) x.roundRect(a, b, w, h, r); else { x.moveTo(a + r, b); x.arcTo(a + w, b, a + w, b + h, r); x.arcTo(a + w, b + h, a, b + h, r); x.arcTo(a, b + h, a, b, r); x.arcTo(a, b, a + w, b, r); x.closePath(); } }
const ACC = [[96, 165, 255], [60, 215, 245], [168, 130, 255]];
const GEO = (S) => { const w = S * .78; return { w, x0: (S - w) / 2, y0: (S - w) / 2, r: w * .24 }; };
const rgba = (c, o) => `rgba(${c[0]},${c[1]},${c[2]},${o})`;
function drawLogo(x, img, cx, cy, size) {   // very dark marks become white silhouettes; coloured marks keep their colours
  const o = document.createElement('canvas'); o.width = o.height = 256; const ox = o.getContext('2d'); ox.imageSmoothingQuality = 'high'; ox.drawImage(img, 0, 0, 256, 256);
  let lum = 1; try { const d = ox.getImageData(0, 0, 256, 256).data; let sum = 0, n = 0; for (let i = 0; i < d.length; i += 32) { if (d[i + 3] > 128) { sum += (d[i] * .2126 + d[i + 1] * .7152 + d[i + 2] * .0722) / 255; n++; } } if (n) lum = sum / n; } catch (e) {}
  if (lum < .5) { ox.globalCompositeOperation = 'source-in'; ox.fillStyle = '#fff'; ox.fillRect(0, 0, 256, 256); }
  x.imageSmoothingQuality = 'high'; x.drawImage(o, cx - size / 2, cy - size / 2, size, size);
}
/** Smoked dark-glass tile: thin bevel, soft gloss, subtle accent edge light, sharp mark and name. */
function paintTile(tex, t, img, idx) {
  const c = tex.image, x = c.getContext('2d'), S = c.width, { w, x0, y0, r } = GEO(S), A = ACC[(idx || 0) % 3], hex = (t.hex || '5b6bff').replace('#', '');
  x.clearRect(0, 0, S, S);
  x.save(); x.shadowColor = 'rgba(0,0,0,.55)'; x.shadowBlur = S * .06; x.shadowOffsetY = S * .025; x.fillStyle = 'rgba(8,12,30,.96)'; rr(x, x0, y0, w, w, r); x.fill(); x.restore();
  let g = x.createLinearGradient(x0, y0, x0 + w * .8, y0 + w); g.addColorStop(0, 'rgba(44,62,118,.82)'); g.addColorStop(.55, 'rgba(14,20,50,.86)'); g.addColorStop(1, 'rgba(8,12,32,.90)'); x.fillStyle = g; rr(x, x0, y0, w, w, r); x.fill();
  x.save(); rr(x, x0, y0, w, w, r); x.clip();
  g = x.createLinearGradient(0, y0 + w * .55, 0, y0 + w); g.addColorStop(0, rgba(A, 0)); g.addColorStop(1, rgba(A, .16)); x.fillStyle = g; x.fillRect(x0, y0, w, w);
  g = x.createLinearGradient(x0, y0, x0 + w * .25, y0 + w * .6); g.addColorStop(0, 'rgba(255,255,255,.20)'); g.addColorStop(.6, 'rgba(255,255,255,.04)'); g.addColorStop(1, 'rgba(255,255,255,0)');
  x.beginPath(); x.ellipse(x0 + w * .35, y0 - w * .14, w * .85, w * .6, -.1, 0, TAU); x.fillStyle = g; x.fill(); x.restore();
  g = x.createRadialGradient(x0 + w * .12, y0 + w * .92, 0, x0 + w * .12, y0 + w * .92, w * .55); g.addColorStop(0, rgba(A, .26)); g.addColorStop(1, rgba(A, 0)); x.save(); rr(x, x0, y0, w, w, r); x.clip(); x.fillStyle = g; x.fillRect(x0, y0, w, w);
  x.lineWidth = S * .006; x.strokeStyle = 'rgba(255,255,255,.30)'; rr(x, x0 + S * .028, y0 + S * .028, w - S * .056, w - S * .056, r * .86); x.stroke();
  g = x.createLinearGradient(0, y0, 0, y0 + w * .18); g.addColorStop(0, 'rgba(255,255,255,.34)'); g.addColorStop(1, 'rgba(255,255,255,0)'); x.fillStyle = g; x.fillRect(x0, y0, w, w * .18); x.restore();
  g = x.createLinearGradient(x0, y0, x0 + w, y0 + w); g.addColorStop(0, 'rgba(240,248,255,1)'); g.addColorStop(.5, rgba(A, .55)); g.addColorStop(1, rgba(A, .95));
  x.save(); x.shadowColor = rgba(A, .8); x.shadowBlur = S * .035; x.lineWidth = S * .013; x.strokeStyle = t.sp ? 'rgba(245,176,65,.95)' : g; rr(x, x0 + S * .004, y0 + S * .004, w - S * .008, w - S * .008, r); x.stroke(); x.restore();
  const cy = y0 + w * .37, gs = w * .46;
  if (img) drawLogo(x, img, S / 2, cy, gs);
  else { const rad = gs * .52; g = x.createLinearGradient(S / 2 - rad, cy - rad, S / 2 + rad, cy + rad); g.addColorStop(0, '#' + hex); g.addColorStop(1, rgba(A, .8)); x.fillStyle = g; rr(x, S / 2 - rad, cy - rad, rad * 2, rad * 2, rad * .5); x.fill();
    x.fillStyle = '#fff'; x.font = '700 ' + rad * 1.1 + 'px "Space Grotesk Var","Inter Var",system-ui,sans-serif'; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText((t.label || t.name || '?').charAt(0).toUpperCase(), S / 2, cy + rad * .05); }
  let nm = String(t.label || t.name || ''); x.font = '700 ' + S * .106 + 'px "Inter Var",system-ui,sans-serif'; x.textAlign = 'center'; x.textBaseline = 'middle';
  let fs = S * .106; while (x.measureText(nm).width > w * .92 && fs > S * .05) { fs -= 1; x.font = `700 ${fs}px system-ui,sans-serif`; }
  x.save(); x.shadowColor = 'rgba(0,0,20,.7)'; x.shadowBlur = S * .012; x.fillStyle = 'rgba(255,255,255,.98)'; x.fillText(nm, S / 2, y0 + w * .81); x.restore();
  if (t.sp) { const pw = S * .42, ph = S * .085, px = S / 2 - pw / 2, py = y0 - ph * .4; x.fillStyle = '#f5b041'; rr(x, px, py, pw, ph, ph / 2); x.fill(); x.fillStyle = '#3b2400'; x.font = '800 ' + ph * .56 + 'px system-ui,sans-serif'; x.fillText('SPONSORED', S / 2, py + ph / 2 + 1); }
  tex.needsUpdate = true;
}
function edgeTexture(S) { const c = document.createElement('canvas'); c.width = c.height = S; const x = c.getContext('2d'), { w, x0, y0, r } = GEO(S); x.fillStyle = 'rgb(20,30,66)'; rr(x, x0, y0, w, w, r); x.fill(); const t = new CanvasTexture(c); t.colorSpace = SRGBColorSpace; t.premultiplyAlpha = true; return t; }

export async function init(box, data, opts = {}) {
  const canvas = box.querySelector('canvas'), base = box.dataset.base, mobile = box.clientWidth < 600, freeze = typeof opts.freezeAt === 'number';
  const renderer = new WebGLRenderer({ canvas, alpha: false, antialias: false, powerPreference: 'high-performance' }); // Software rasterisers (SwiftShader/llvmpipe: no GPU) cannot run this scene smoothly; the static poster is kept instead. ?globeforce overrides, for testing.
  try { const gl = renderer.getContext(), ex = gl.getExtension('WEBGL_debug_renderer_info'), rn = ex ? String(gl.getParameter(ex.UNMASKED_RENDERER_WEBGL)) : ''; if (/swiftshader|llvmpipe|softpipe|software/i.test(rn) && !/[?&]globeforce/.test(location.search)) { renderer.dispose(); throw new Error('software-gl'); } } catch (e) { if (e && e.message === 'software-gl') throw e; }
  renderer.setClearColor(0x02040c, 1); renderer.setPixelRatio(Math.min(devicePixelRatio || 1, mobile ? 1.5 : 2));
  const scene = new Scene(), camera = new PerspectiveCamera(30, 1, .1, 60), rig = new Group(), world = new Group(), orbit = new Group(); scene.add(rig); rig.add(world, orbit); scene.background = new Color(0x02040c);
  const rt = new WebGLRenderTarget(2, 2, { type: HalfFloatType, samples: 0 }), composer = new EffectComposer(renderer, rt), bloomComposer = new EffectComposer(renderer, new WebGLRenderTarget(2, 2, { type: HalfFloatType })), bloom = new UnrealBloomPass(new Vector2(256, 256), mobile ? .32 : .42, .18, .18), bloomSwap = [], bloomHide = [];
  bloomComposer.renderToScreen = false;
  world.rotation.z = -.2; world.rotation.y = -Math.PI / 2 - 18 * Math.PI / 180; rig.rotation.x = .34;
  bloomComposer.addPass(new RenderPass(scene, camera)); bloomComposer.addPass(bloom);
  const mix = new ShaderPass({ uniforms: { tDiffuse: { value: null }, bloomTexture: { value: null } }, vertexShader: 'varying vec2 vUv;void main(){vUv=uv;gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.);}', fragmentShader: 'uniform sampler2D tDiffuse;uniform sampler2D bloomTexture;varying vec2 vUv;void main(){gl_FragColor=vec4(texture2D(tDiffuse,vUv).rgb+texture2D(bloomTexture,vUv).rgb,1.);}' }, 'tDiffuse');
  composer.addPass(new RenderPass(scene, camera)); composer.addPass(mix); composer.addPass(new OutputPass());
  const SUN = new Vector3(.74, .52, -.22).normalize();
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const ambient = new AmbientLight(0xb5caff, 1.1); scene.add(ambient);
  const key = new DirectionalLight(0xdceaff, 2.0); key.position.set(3,4,5); scene.add(key);
  const rim = new DirectionalLight(0x657aff, 1.5); rim.position.set(-4,1,-2); scene.add(rim);
  // stars (very few, small)
  const sp = []; for (let i = 0; i < 520; i++) { const u = Math.random() * TAU, v = Math.random() * 2 - 1, q = Math.sqrt(1 - v * v), rd = 7 + Math.random() * 3; sp.push(Math.cos(u) * q * rd * 1.2, v * rd * .7, -Math.abs(Math.sin(u) * q) * rd - 3); }
  const sg = new BufferGeometry(); sg.setAttribute('position', new Float32BufferAttribute(sp, 3)); scene.add(new Points(sg, new PointsMaterial({ map: glowTexture(200, 215, 255), size: .07, transparent: true, opacity: .85, depthWrite: false, blending: AdditiveBlending, premultipliedAlpha: true, color: 0xcfdcff })));
  bloomHide.push(scene.children[scene.children.length - 1]);
  // Earth
  const loader = new TextureLoader(), ready = []; const tex = (f, srgb, aniso) => new Promise((res) => { const t = loader.load(base + f, () => res(t), undefined, () => res(null)); if (srgb) t.colorSpace = SRGBColorSpace; t.anisotropy = aniso || 8; t.wrapS = RepeatWrapping; ready.push(t); });
  const [day, night, water, clouds] = await Promise.all([tex('v4/day4k.webp', true), tex('v4/night4k.webp'), tex('earth-water.webp'), tex('v4/clouds4k.webp')]);
  if (![day, night, water, clouds].every(Boolean)) { renderer.dispose(); throw new Error('Globe texture load failed'); }
  world.add(new Mesh(new SphereGeometry(1, mobile ? 96 : 160, mobile ? 72 : 120), new ShaderMaterial({ vertexShader: EARTH_V, fragmentShader: EARTH_F, uniforms: { dayMap: { value: day }, nightMap: { value: night }, waterMap: { value: water }, cloudMap: { value: clouds }, L: { value: SUN } } })));
  const earthMesh = world.children[0];
  const cloudMesh = new Mesh(new SphereGeometry(1.010, mobile ? 72 : 128, mobile ? 54 : 96), new ShaderMaterial({ vertexShader: EARTH_V, fragmentShader: CLOUD_F, transparent: true, depthWrite: false, uniforms: { cloudMap: { value: clouds }, L: { value: SUN } } })); world.add(cloudMesh);
  const lightsMat = new ShaderMaterial({ vertexShader: EARTH_V, fragmentShader: 'uniform sampler2D nightMap;uniform sampler2D cloudMap;uniform vec3 L;varying vec2 vUv;varying vec3 vN;varying vec3 vP;void main(){vec3 N=normalize(vN);float day=smoothstep(-.02,.20,dot(N,L));float n=texture2D(nightMap,vUv).r;float cl=texture2D(cloudMap,vUv).r;float l=pow(n,1.5)*(1.-day)*(1.-cl*.5);gl_FragColor=vec4(vec3(1.,.53,.16)*l*.85,1.);\n#include <colorspace_fragment>\n}', uniforms: { nightMap: { value: night }, cloudMap: { value: clouds }, L: { value: SUN } } });
  bloomSwap.push([earthMesh, earthMesh.material, lightsMat]); bloomHide.push(cloudMesh);
  scene.add(new Mesh(new SphereGeometry(1.018, 64, 48), new ShaderMaterial({ vertexShader: ATMO_V, fragmentShader: ATMO_F, side: BackSide, transparent: true, premultipliedAlpha: true, depthWrite: false, uniforms: { L: { value: SUN } } })));
  bloomHide.push(scene.children[scene.children.length - 1]);
  // sun flare at the limb (cinematic, warm, small)
  const fc = document.createElement('canvas'); fc.width = fc.height = 256; const fx = fc.getContext('2d'); let fg = fx.createRadialGradient(128, 128, 0, 128, 128, 128); fg.addColorStop(0, 'rgba(255,246,220,1)'); fg.addColorStop(.05, 'rgba(255,200,120,.9)'); fg.addColorStop(.16, 'rgba(255,150,70,.34)'); fg.addColorStop(.34, 'rgba(255,120,60,.05)'); fg.addColorStop(.55, 'rgba(255,120,60,0)'); fg.addColorStop(1, 'rgba(255,110,60,0)'); fx.fillStyle = fg; fx.fillRect(0, 0, 256, 256);
  fx.globalCompositeOperation = 'lighter'; for (const [ang, len, a] of [[0, .55, .45], [Math.PI / 2, .45, .32]]) { fx.save(); fx.translate(128, 128); fx.rotate(ang); const lg = fx.createLinearGradient(-128 * len, 0, 128 * len, 0); lg.addColorStop(0, 'rgba(255,210,140,0)'); lg.addColorStop(.5, `rgba(255,235,190,${a})`); lg.addColorStop(1, 'rgba(255,210,140,0)'); fx.fillStyle = lg; fx.fillRect(-128 * len, -1.6, 256 * len, 3.2); fx.restore(); }
  const flare = new Sprite(new SpriteMaterial({ map: new CanvasTexture(fc), transparent: true, depthWrite: false, depthTest: false, blending: AdditiveBlending, opacity: 1 })); flare.position.copy(new Vector3(.71, .67, -.12).normalize()).multiplyScalar(1.0); flare.scale.setScalar(.90); flare.renderOrder = 20; scene.add(flare);
  // thin orbit paths
  const rings = [{ r: mobile ? 1.46 : 1.62, tilt: .10, sp: .060, n: 4, hue: 0 }, { r: mobile ? 1.62 : 1.86, tilt: -.24, sp: -.045, n: 4, hue: 2.4 }];
  const glowMats = []; const ringMat = (a, f) => { const m = new ShaderMaterial({ vertexShader: RING_V, fragmentShader: RING_F, transparent: true, depthWrite: false, blending: CustomBlending, blendSrc: OneFactor, blendDst: OneFactor, premultipliedAlpha: true, uniforms: { uTime: { value: 0 }, uHue: { value: 0 }, uAlpha: { value: a }, uFres: { value: f } } }); glowMats.push(m); return m; };
  const ringObjs = rings.map((R) => { const g = new Group(); g.rotation.z = R.tilt; [[.0038, 1.05, 1.0], [.018, .19, 2.0]].forEach(([tube, al, fr]) => { const tg = new TorusGeometry(R.r, tube, 8, 260); tg.rotateX(Math.PI / 2); const m = ringMat(al, fr); m.uniforms.uHue.value = R.hue; g.add(new Mesh(tg, m)); }); orbit.add(g); return g; });
  ringObjs.forEach((g) => bloomHide.push(g.children[0], g.children[1]));
  ringObjs.forEach((g, i) => { const R = rings[i], pts = []; for (let k = 0; k < 9; k++) { const a = (k / 9) * TAU + i * .7; pts.push(Math.cos(a) * R.r, 0, Math.sin(a) * R.r); } const ng = new BufferGeometry(); ng.setAttribute('position', new Float32BufferAttribute(pts, 3)); g.add(new Points(ng, new PointsMaterial({ map: glowTexture(190, 170, 255), size: .11, transparent: true, depthWrite: false, blending: AdditiveBlending, premultipliedAlpha: true, color: i ? 0xb9a6ff : 0x9fd0ff }))); });
  // network
  const glow = glowTexture(150, 205, 255), hubPos = HUBS.map(([a, b]) => ll(a, b, 1.006));
  const hg = new BufferGeometry(); hg.setAttribute('position', new Float32BufferAttribute(hubPos.flatMap((v) => [v.x, v.y, v.z]), 3)); world.add(new Points(hg, new PointsMaterial({ map: glow, size: .11, transparent: true, depthWrite: false, blending: AdditiveBlending, premultipliedAlpha: true, color: 0xdff0ff, opacity: .95 })));
  const arcs = ARCS.map(([i, j]) => { const a = hubPos[i], b = hubPos[j], m = a.clone().add(b).multiplyScalar(.5), d = a.distanceTo(b); m.setLength(1 + d * .30); const pts = []; for (let k = 0; k <= 48; k++) { const t = k / 48, u = 1 - t; pts.push(a.clone().multiplyScalar(u * u).add(m.clone().multiplyScalar(2 * u * t)).add(b.clone().multiplyScalar(t * t))); } return pts; });
  arcs.forEach((pts, i) => world.add(new Line(new BufferGeometry().setFromPoints(pts), new LineBasicMaterial({ color: [0x8fc8ff, 0x9aa8ff, 0x7fe8ff][i % 3], transparent: true, opacity: .34, blending: AdditiveBlending, premultipliedAlpha: true, depthWrite: false }))));
  const pulseN = mobile ? 8 : 14, pulses = [], pGeo = new BufferGeometry(); pGeo.setAttribute('position', new Float32BufferAttribute(new Float32Array(pulseN * 3), 3)); pGeo.attributes.position.setUsage(DynamicDrawUsage);
  for (let i = 0; i < pulseN; i++) pulses.push({ arc: i % arcs.length, t: (i * .37) % 1, v: .06 + (i % 5) * .01 });
  world.add(new Points(pGeo, new PointsMaterial({ map: glow, size: .09, transparent: true, depthWrite: false, blending: AdditiveBlending, premultipliedAlpha: true, color: 0xeaf6ff })));
  // tiles
  const all = (data.tools || []).slice(0, 40), rank = (t) => { const i = FAMOUS.indexOf(t.id); return i < 0 ? 99 : i; };
  const spons = (data.sponsors || []).slice(0, 2).map((t) => Object.assign({}, t, { sp: true })), withLogo = spons.concat(all.filter((t) => t.svg && !spons.some((q) => q.id === t.id)).sort((a, b) => rank(a) - rank(b))), without = all.filter((t) => !t.svg && !spons.some((q) => q.id === t.id)), pool = []; let li = 0, wi = 0; while (li < withLogo.length || wi < without.length) { for (let k = 0; k < 3 && li < withLogo.length; k++) pool.push(withLogo[li++]); if (wi < without.length) pool.push(without[wi++]); }
  const imgCache = {}, loadImg = (s) => imgCache[s] || (imgCache[s] = new Promise((res) => { const im = new Image(); im.onload = () => res(im); im.onerror = () => res(null); im.src = s; }));
  const shape = new Shape(); const h=.35, r=.14;
  shape.moveTo(-h+r,-h); shape.lineTo(h-r,-h); shape.quadraticCurveTo(h,-h,h,-h+r); shape.lineTo(h,h-r); shape.quadraticCurveTo(h,h,h-r,h); shape.lineTo(-h+r,h); shape.quadraticCurveTo(-h,h,-h,h-r); shape.lineTo(-h,-h+r); shape.quadraticCurveTo(-h,-h,-h+r,-h);
  const bodyGeo = new ExtrudeGeometry(shape,{depth:.065,bevelEnabled:true,bevelSize:.025,bevelThickness:.025,bevelSegments:3,steps:1,curveSegments:8}); bodyGeo.translate(0,0,-.10);
  const bodyMat = new MeshPhysicalMaterial({color:0x172744,metalness:.28,roughness:.24,clearcoat:1,clearcoatRoughness:.15});
  const TS = 512, edgeTex = edgeTexture(TS), planeGeo = new PlaneGeometry(1, 1), slots = []; let order = 0, firstFill = 0, poolPtr = 0;
  rings.forEach((R, ri) => { for (let k = 0; k < R.n; k++) {
    const t2 = new CanvasTexture(Object.assign(document.createElement('canvas'), { width: TS, height: TS })); t2.generateMipmaps = true; t2.minFilter = LinearMipmapLinearFilter; t2.magFilter = LinearFilter; t2.colorSpace = SRGBColorSpace; t2.premultiplyAlpha = true; t2.anisotropy = 8;
    const node = new Group(), face = new Mesh(planeGeo, new ShaderMaterial({ vertexShader: TILE_V, fragmentShader: TILE_F, transparent: true, depthWrite: false, premultipliedAlpha: true, uniforms: { map: { value: t2 }, uTime: { value: 0 }, uSeed: { value: (order * .37) % 1 }, uHover: { value: 0 }, uOpacity: { value: 1 } } }));
    const edgeMats = []; node.add(new Mesh(bodyGeo,bodyMat));
    node.add(face); orbit.children[ri].add(node);
    slots.push({ ring: ri, a: (k / R.n) * TAU + ri * 1.25, node, face, edgeMats, tex: t2, tool: null, idx: order++, hv: 0, target: 1, scale: 0, off: 0, sx: 0, sy: 0, sz: 0, size: .2, tx: 0, ty: 0 });
  } });
  slots.forEach((s) => { const bm = new ShaderMaterial({ vertexShader: TILE_V, fragmentShader: 'uniform sampler2D map;uniform float uOpacity;varying vec2 vUv;void main(){gl_FragColor=vec4(0.,0.,0.,texture2D(map,vUv).a*uOpacity);}', transparent: true, depthWrite: false, uniforms: { map: s.face.material.uniforms.map, uOpacity: s.face.material.uniforms.uOpacity } }); bloomSwap.push([s.face, s.face.material, bm]); s.node.children.forEach((c) => { if (c !== s.face) bloomHide.push(c); }); });
  const nextPool = () => { for (let n = 0; n < pool.length; n++) { const t = pool[poolPtr++ % pool.length]; if (!slots.some((s) => s.tool && s.tool.id === t.id)) return t; } return pool[0]; };
  const assign = (s, t) => { s.tool = t; paintTile(s.tex, t, null, s.idx); if (t.svg) loadImg(t.svg).then((im) => { if (s.tool === t && im) paintTile(s.tex, t, im, s.idx); }); };
  slots.forEach((s) => assign(s, withLogo[firstFill] ? withLogo[firstFill++] : nextPool()));
  // frame
  let W = 1, H = 1, last = 0, simT = 0, mx = 0, my = 0, tx = 0, ty = 0, hover = null, running = false;
  const pos = new Vector3(), pos2 = new Vector3(), proj2 = new Vector3(), proj = new Vector3(), qP = new Quaternion(), qT = new Quaternion(), eul = new Euler(), arcP = new Vector3();
  const resize = () => { const r = box.getBoundingClientRect(); W = Math.max(1, r.width | 0); H = Math.max(1, r.height | 0); renderer.setSize(W, H, false); composer.setPixelRatio(renderer.getPixelRatio()); composer.setSize(W, H); bloomComposer.setPixelRatio(renderer.getPixelRatio() * .5); bloomComposer.setSize(W, H); camera.aspect = W / H; camera.updateProjectionMatrix(); const t = Math.tan(camera.fov * Math.PI / 360); camera.position.z = Math.max((mobile ? 2.12 : 2.48) / (t * camera.aspect), (mobile ? 1.85 : 1.70) / t); };
  const tick = (t) => {
    const dt = (freeze || reduced.matches || hover) ? 0 : Math.max(0, Math.min(.05, t - last)); last = t; simT = freeze ? opts.freezeAt : simT + dt;
    if (!freeze && !reduced.matches) { world.rotation.y += dt * .075; cloudMesh.rotation.y += dt * .004; orbit.rotation.y += dt * .015; }
    ringObjs.forEach((g, i) => { g.rotation.y += dt * rings[i].sp; }); glowMats.forEach((m) => { m.uniforms.uTime.value = simT; });
    tx += (mx * .10 - tx) * Math.min(1, dt * 2.5); ty += (my * .06 - ty) * Math.min(1, dt * 2.5); scene.rotation.y = tx; scene.rotation.x = ty; camera.position.x += (mx * .14 - camera.position.x) * Math.min(1, dt * 3); camera.position.y += (-my * .10 - camera.position.y) * Math.min(1, dt * 3); camera.lookAt(0, 0, 0);
    scene.updateMatrixWorld(true);
    // Presentation orbit: equal angular spacing in screen space, outside the Earth silhouette.
    // Physical network and rings remain in 3D; cards deliberately use a foreground orbit.
    const tan = Math.tan(camera.fov * Math.PI / 360);
    const earthRadius = 1.02 / Math.sqrt(camera.position.lengthSq() - 1.02 * 1.02) / tan;
    const rx = Math.min(camera.aspect * .79, Math.max(earthRadius + .18, camera.aspect * .70));
    const ry = Math.min(.77, Math.max(earthRadius + .14, .65));
    const cardHalf = Math.min(.125, rx * .23, ry * .23, camera.aspect * .16);
    const distance = camera.position.length();
    slots.forEach((s, i) => {
      const angle = TAU * i / slots.length + simT * .11 + .22;
      const x = rx * Math.cos(angle), y = ry * Math.sin(angle);
      // Depth-varying presentation orbit: back half recedes behind globe center.
      // Screen-space separation is retained; not a Keplerian or ring-following orbit.
      const distance = camera.position.length() - 1.15 * Math.sin(angle);
      pos.set(x * tan * distance, y * tan * distance, -distance).applyQuaternion(camera.quaternion).add(camera.position);
      s.node.parent.worldToLocal(pos); s.node.position.copy(pos);
      s.node.parent.getWorldQuaternion(qP);
      qT.setFromEuler(eul.set(-y * .13, x / camera.aspect * .24, 0));
      s.node.quaternion.copy(qP.invert()).multiply(camera.quaternion).multiply(qT);
      s.node.scale.setScalar(2 * cardHalf * tan * distance / .78);
      s.sx = x; s.sy = y; s.size = cardHalf * 2;
      const u = s.face.material.uniforms; u.uOpacity.value = 1; u.uTime.value = simT;
      u.uHover.value = hover === s ? 1 : 0;
      s.edgeMats.forEach(m => { m.uniforms.uOpacity.value = .65; });
    });
    const pp = pGeo.attributes.position.array; pulses.forEach((p, i) => { if (!freeze) { p.t += dt * p.v; if (p.t > 1) { p.t -= 1; p.arc = (p.arc + 5) % arcs.length; } } const pts = arcs[p.arc], f = p.t * (pts.length - 1), a = Math.floor(f), b = Math.min(pts.length - 1, a + 1); arcP.copy(pts[a]).lerp(pts[b], f - a); pp[i * 3] = arcP.x; pp[i * 3 + 1] = arcP.y; pp[i * 3 + 2] = arcP.z; }); pGeo.attributes.position.needsUpdate = true;
    const bg = scene.background; scene.background = new Color(0x000000); bloomSwap.forEach((e) => { e[0].material = e[2]; }); bloomHide.forEach((o) => { o.visible = false; });
    bloomComposer.render();
    bloomSwap.forEach((e) => { e[0].material = e[1]; }); bloomHide.forEach((o) => { o.visible = true; }); scene.background = bg;
    mix.uniforms.bloomTexture.value = bloom.renderTargetsHorizontal[0].texture; composer.render();
  };
  resize(); new ResizeObserver(() => { resize(); if (!running) tick(last); }).observe(box);
  let down = null, dragged = false;
  canvas.style.touchAction = 'pan-y';
  const panel = document.createElement('div'); panel.style.cssText='position:absolute;z-index:3;pointer-events:none;max-width:230px;padding:10px 12px;border:1px solid #64748b;border-radius:12px;background:#101827;color:#fff;font:13px system-ui;box-shadow:0 8px 24px #0008;'; panel.hidden=true; box.append(panel);
  const safeURL = tool => {try {const u=new URL(tool.url,location.origin);return u.origin===location.origin && u.pathname==='/tool.php'?u.href:null;}catch{return null;}};
  const showInfo=(s,e)=>{panel.hidden=!s;if(!s)return;panel.replaceChildren();const strong=document.createElement('strong');strong.textContent=s.tool.name;const detail=document.createElement('div');detail.textContent=[s.tool.cat,s.tool.note].filter(Boolean).join(' · ');panel.append(strong,detail);if(s.tool.sp){const sp=document.createElement('div');sp.textContent='Sponsored placement · paid, never changes a tool\'s facts';sp.style.cssText='color:#fcd34d;font-weight:700;font-size:12px;margin-top:4px';panel.append(sp);}const r=box.getBoundingClientRect();panel.style.left=Math.max(4,Math.min(r.width-panel.offsetWidth-4,e.clientX-r.left+12))+'px';panel.style.top=Math.max(4,Math.min(r.height-panel.offsetHeight-4,e.clientY-r.top+12))+'px';};
  const hit = e => { const r = canvas.getBoundingClientRect(); const x = ((e.clientX-r.left)/r.width*2-1)*camera.aspect, y = 1-(e.clientY-r.top)/r.height*2; return slots.find(s => Math.abs(x-s.sx)<s.size*.5 && Math.abs(y-s.sy)<s.size*.5); };
  canvas.addEventListener('pointerdown', e => { if (e.button !== 0) return; down = {x:e.clientX,y:e.clientY}; dragged=false;hover=null;panel.hidden=true; canvas.setPointerCapture(e.pointerId); });
  canvas.addEventListener('pointermove', e => { const r=canvas.getBoundingClientRect(); mx=((e.clientX-r.left)/r.width-.5)*2; my=((e.clientY-r.top)/r.height-.5)*2; hover=down?null:hit(e); showInfo(hover,e); canvas.style.cursor=hover?'pointer':'grab'; if(down && !freeze){ const dx=e.clientX-down.x; if(Math.abs(dx)>3 || Math.abs(e.clientY-down.y)>5) dragged=true; if(dragged){world.rotation.y+=dx*.008; down={x:e.clientX,y:e.clientY};} } });
  canvas.addEventListener('pointercancel',()=>{down=null;dragged=true;hover=null;panel.hidden=true;});
  canvas.addEventListener('pointerup',e=>{ if(down && !dragged && !freeze){const s=hit(e); if(s?.tool?.url){const url=new URL(s.tool.url,location.origin); if(url.origin===location.origin && url.pathname==='/tool.php') location.assign(url.href);}} down=null; });
  canvas.addEventListener('pointerleave',()=>{hover=null;panel.hidden=true;mx=0;my=0;});
  box.snapshotFrames = (n) => { simT = 0; for (let i = 0; i < n; i++) tick(i * .03); };
  if (freeze) { tick(opts.freezeAt); box.dataset.ready = '1'; return; }
  let vis = true; const loop = (ms) => { if (!running) return; const t = ms / 1000; if (!last) last = t; tick(t); requestAnimationFrame(loop); };
  const go = () => { if (running || !vis || document.hidden) return; running = true; last = 0; requestAnimationFrame(loop); }, halt = () => { running = false; };
  new IntersectionObserver((en) => { vis = en[0].isIntersecting; vis ? go() : halt(); }, { threshold: .05 }).observe(box); document.addEventListener('visibilitychange', () => { (!document.hidden && vis) ? go() : halt(); });
  go(); box.classList.add('is-live'); box.dataset.ready = '1';
}
(async () => { const box = document.querySelector('[data-globe]'); if (!box) return; const data = JSON.parse(document.getElementById('globeData').textContent); await new Promise((r) => (document.fonts ? document.fonts.ready.then(r) : r())); if (box.dataset.ready) return; try { await init(box, data, new URLSearchParams(location.search).has('freeze') ? { freezeAt: 0 } : {}); } catch (e) { /* no WebGL / textures: the static poster stays */ } })();
