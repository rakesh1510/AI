const { chromium } = require('playwright');
const [,, out, ...rest] = process.argv;
const pages = {home:'/',categories:'/categories.php',compare:'/compare.php?a=chatgpt&b=claude',compare0:'/compare.php',guides:'/guides.php',business:'/business.php',advertise:'/advertise.php'};
(async()=>{
 const b=await chromium.launch();
 for(const [w,h] of [[1440,900],[390,844]]) for(const s of ['light','dark']){
  const c=await b.newContext({viewport:{width:w,height:h},colorScheme:s,reducedMotion:'reduce'}); const p=await c.newPage();
  for(const [k,u] of Object.entries(pages)){ if(rest.length&&!rest.includes(k))continue;
   await p.goto('http://127.0.0.1:8123'+u,{waitUntil:'load'}); await p.waitForTimeout(500);
   await p.screenshot({path:`${out}/${k}-${w}-${s}.png`,fullPage:true}); }
  await c.close();}
 await b.close();})();
