# AIHubpedia globe prototype

This is a standalone Three.js ES module, not a verified patch for the existing repository. Reuse the project's installed Three.js version and existing local textures. The module expects a day map, a night lights map and a grayscale cloud map, plus tool objects with name, logo and same-origin URL. Mount inside a sized container (e.g. width:100%; aspect-ratio:16/10). Keep the existing poster visible until mount succeeds; skip mounting for Save-Data and reduced motion. Call the returned cleanup function when unmounting.

```js
import { mountGlobe } from './globe.js';
const cleanup = await mountGlobe(document.querySelector('#globe'), {
  day: existingDayTextureUrl,
  night: existingNightTextureUrl,
  clouds: existingCloudTextureUrl,
  tools: existingTools.map(t => ({name:t.name,logo:t.logo,url:t.url}))
});
```

No global bloom is used. The tiles are crisp canvas-textured planes, not physically modeled glass. The prototype includes rotating Earth/clouds, directional day/night shader, narrow atmospheric shell, orbit lines, depth-tested orbiting tiles, drag, responsive camera and click handling. It does not yet implement city-to-city arcs, sun flare, selective bloom, collision avoidance, accessible HTML links, or a complete poster loader. These should be reused from the existing implementation rather than rebuilt. Browser rendering and visual matching have not been tested; JavaScript syntax was checked with Node. Do not deploy to production. Use an isolated prototype first.
