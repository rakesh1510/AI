import * as THREE from 'three';

// Supply local, licensed equirectangular textures and official tool logo URLs.
export async function mountGlobe(host, {day, night, clouds, tools = []}) {
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(36, 1, .1, 100);
  camera.position.set(0, 0, 8.8);
  const renderer = new THREE.WebGLRenderer({alpha:true, antialias:true});
  renderer.setClearColor(0x000000,0);
  renderer.setPixelRatio(Math.min(devicePixelRatio,1.5));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.NoToneMapping;
  host.appendChild(renderer.domElement);
  const loader = new THREE.TextureLoader();
  const load = async url => { const t = await loader.loadAsync(url); t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = Math.min(8,renderer.capabilities.getMaxAnisotropy()); return t; };
  const owned = [];
  let dayMap,nightMap,cloudMap;
  try { [dayMap,nightMap,cloudMap] = await Promise.all([day,night,clouds].map(load)); owned.push(dayMap,nightMap,cloudMap); } catch(e) { renderer.dispose(); renderer.domElement.remove(); throw e; }
  const sun = new THREE.Vector3(.85,.4,.25).normalize();
  const vertex = `varying vec2 vUv; varying vec3 n; varying vec3 p; void main(){vUv=uv; n=normalize(mat3(modelMatrix)*normal); p=(modelMatrix*vec4(position,1.)).xyz; gl_Position=projectionMatrix*viewMatrix*vec4(p,1.);}`;
  const earth = new THREE.Mesh(new THREE.SphereGeometry(1.48,96,64),new THREE.ShaderMaterial({vertexShader:vertex,uniforms:{dayMap:{value:dayMap},nightMap:{value:nightMap},sun:{value:sun}},fragmentShader:`uniform sampler2D dayMap,nightMap; uniform vec3 sun; varying vec2 vUv; varying vec3 n,p; void main(){vec3 N=normalize(n); float d=dot(N,sun); float lit=smoothstep(-.16,.28,d); vec3 day=texture2D(dayMap,vUv).rgb; vec3 night=texture2D(nightMap,vUv).rgb; float city=pow(max(max(night.r,night.g),night.b),1.35); vec3 col=day*(.065+.94*lit)+vec3(1.,.55,.12)*city*(1.-lit)*1.35; float rim=pow(1.-max(dot(N,normalize(cameraPosition-p)),0.),5.); col+=vec3(.035,.14,.38)*rim*(.25+.75*lit); gl_FragColor=vec4(col,1.); #include <colorspace_fragment>\n}`.replace('; #include',';\n#include')}));
  scene.add(earth);
  const cloud = new THREE.Mesh(new THREE.SphereGeometry(1.495,96,64),new THREE.ShaderMaterial({vertexShader:vertex,transparent:true,depthWrite:false,uniforms:{map:{value:cloudMap},sun:{value:sun}},fragmentShader:`uniform sampler2D map; uniform vec3 sun; varying vec2 vUv; varying vec3 n,p; void main(){float c=texture2D(map,vUv).r; float l=smoothstep(-.2,.4,dot(normalize(n),sun)); gl_FragColor=vec4(vec3(.8,.87,1.)*(.12+.88*l),c*.65);\n#include <colorspace_fragment>\n}`})); scene.add(cloud);
  const atmosphere = new THREE.Mesh(new THREE.SphereGeometry(1.515,96,64),new THREE.ShaderMaterial({vertexShader:vertex,transparent:true,depthWrite:false,side:THREE.BackSide,blending:THREE.AdditiveBlending,fragmentShader:`varying vec3 n,p; void main(){float f=pow(1.-abs(dot(normalize(n),normalize(cameraPosition-p))),5.); gl_FragColor=vec4(.06,.32,1.,f*.38);\n#include <colorspace_fragment>\n}`})); scene.add(atmosphere);
  const rings = [new THREE.Euler(.65,0,.12),new THREE.Euler(1.02,0,-.22)];
  function orbit(a,i){return new THREE.Vector3(2.45*Math.cos(a),0,1.95*Math.sin(a)).applyEuler(rings[i]);}
  for(let i=0;i<2;i++){const pts=Array.from({length:193},(_,j)=>orbit(j/192*Math.PI*2,i)); scene.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(pts),new THREE.LineBasicMaterial({color:i?0x9b65ff:0x35aaff,transparent:true,opacity:.65})));}
  const tiles=[];
  const rounded=(ctx,x,y,w,h,r)=>{ctx.beginPath();ctx.roundRect(x,y,w,h,r);};
  for(const [i,tool] of tools.slice(0,8).entries()){
    const c=document.createElement('canvas');c.width=c.height=512;const ctx=c.getContext('2d');
    rounded(ctx,12,12,488,488,70);const g=ctx.createLinearGradient(0,0,512,512);g.addColorStop(0,'#263c59');g.addColorStop(.45,'#0a1428');g.addColorStop(1,'#161b39');ctx.fillStyle=g;ctx.fill();ctx.lineWidth=5;ctx.strokeStyle=i%2?'#9278e0':'#75c6f0';ctx.stroke();
    try {const img=new Image();img.crossOrigin='anonymous';img.src=tool.logo;await img.decode();const s=230/Math.max(img.width,img.height);ctx.drawImage(img,256-img.width*s/2,205-img.height*s/2,img.width*s,img.height*s);}catch{ctx.fillStyle='white';ctx.font='bold 120px sans-serif';ctx.textAlign='center';ctx.fillText(tool.name.slice(0,2),256,245);}
    ctx.fillStyle='#fff';ctx.font='600 42px sans-serif';ctx.textAlign='center';ctx.fillText(tool.name,256,410,440);
    const tex=new THREE.CanvasTexture(c);tex.colorSpace=THREE.SRGBColorSpace;owned.push(tex);
    const mesh=new THREE.Mesh(new THREE.PlaneGeometry(.78,.78),new THREE.MeshBasicMaterial({map:tex,transparent:true,alphaTest:.05}));scene.add(mesh);tiles.push({mesh,tool,i});
  }
  const ray=new THREE.Raycaster();const pointer=new THREE.Vector2();let target=0,drag=0,down=null;
  const canvas=renderer.domElement;canvas.style.cssText='display:block;width:100%;height:100%;touch-action:pan-y';
  const move=e=>{const r=canvas.getBoundingClientRect();target=((e.clientX-r.left)/r.width-.5)*.22;if(down!==null){drag+=(e.clientX-down)*.005;down=e.clientX;}};
  const start=e=>{down=e.clientX;};const end=()=>{down=null;};
  const click=e=>{const r=canvas.getBoundingClientRect();pointer.set((e.clientX-r.left)/r.width*2-1,-(e.clientY-r.top)/r.height*2+1);ray.setFromCamera(pointer,camera);const hit=ray.intersectObjects([earth,...tiles.map(t=>t.mesh)])[0];const t=tiles.find(t=>t.mesh===hit?.object);if(t?.tool.url){const u=new URL(t.tool.url,location.href);if(u.origin===location.origin)location.assign(u.href);}};
  canvas.addEventListener('pointermove',move);canvas.addEventListener('pointerdown',start);window.addEventListener('pointerup',end);canvas.addEventListener('click',click);
  const resize=()=>{const w=host.clientWidth,h=host.clientHeight;if(!w||!h)return;camera.aspect=w/h;camera.position.z=Math.max(8.8, 3.15/(Math.tan(THREE.MathUtils.degToRad(18))*camera.aspect));camera.updateProjectionMatrix();renderer.setSize(w,h,false);};const ro=new ResizeObserver(resize);ro.observe(host);resize();
  let visible=true;const io=new IntersectionObserver(([e])=>{visible=e.isIntersecting;});io.observe(host);let frame,last=0,time=0;const reduced=matchMedia('(prefers-reduced-motion: reduce)').matches;
  function tick(now){frame=requestAnimationFrame(tick);const dt=Math.min(.05,(now-(last||now))/1000);last=now;if(!visible||document.hidden)return;if(!reduced)time+=dt;earth.rotation.y=time*.065+drag;cloud.rotation.y=time*.078+drag;camera.position.x+=(target-camera.position.x)*.04;camera.lookAt(0,0,0);for(const {mesh,i} of tiles){mesh.position.copy(orbit(time*.17+i*Math.PI*2/Math.max(1,tiles.length),i%2));mesh.quaternion.copy(camera.quaternion);}renderer.render(scene,camera);}frame=requestAnimationFrame(tick);
  return ()=>{cancelAnimationFrame(frame);ro.disconnect();io.disconnect();canvas.removeEventListener('pointermove',move);canvas.removeEventListener('pointerdown',start);window.removeEventListener('pointerup',end);canvas.removeEventListener('click',click);scene.traverse(o=>{o.geometry?.dispose();o.material?.dispose();});owned.forEach(t=>t.dispose());renderer.dispose();canvas.remove();};
}
