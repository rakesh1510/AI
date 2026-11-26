// Minimal glue only: feeds the supplied globe.js (unmodified) with the existing textures and official-logo tools.
import { mountGlobe } from './globe.js';
const data = JSON.parse(document.getElementById('globeData').textContent);
const order = ['chatgpt', 'claude', 'gemini', 'perplexity', 'copilot', 'deepseek', 'notebooklm', 'microsoft-bing'];
const tools = order.map((id) => data.tools.find((t) => t.id === id)).filter(Boolean).map((t) => ({ name: t.label || t.name, logo: t.svg, url: t.url }));
mountGlobe(document.querySelector('#globe'), { day: '/assets/globe/proto/day4k.webp', night: '/assets/globe/proto/night4k.webp', clouds: '/assets/globe/proto/clouds4k.webp', tools }).then(() => { document.querySelector('#globe').dataset.ready = '1'; });
