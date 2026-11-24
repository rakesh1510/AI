<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }   // include-only: never executable directly
// Original inline-SVG / CSS illustrations for the premium UI. Pure presentation: no data, no network, no scripts.

/** Accent theme for a category: two gradient colours and an icon. Unknown categories fall back to a neutral violet. */
function cat_theme(string $cat): array {
  static $m = [
    'Audio'        => ['#f43f9a', '#7c3aed', 'mic'],
    'Automation'   => ['#06b6d4', '#2563eb', 'flow'],
    'Business'     => ['#3b82f6', '#4f46e5', 'briefcase'],
    'Chatbots'     => ['#8b5cf6', '#ec4899', 'chat'],
    'Coding'       => ['#10b981', '#0ea5e9', 'code'],
    'Design'       => ['#f97316', '#e11d48', 'layers'],
    'Image'        => ['#d946ef', '#6366f1', 'image'],
    'Lifestyle'    => ['#f59e0b', '#ef4444', 'heart'],
    'Marketing'    => ['#ef4444', '#f97316', 'megaphone'],
    'Productivity' => ['#22c55e', '#0d9488', 'check'],
    'Research'     => ['#0ea5e9', '#7c3aed', 'book'],
    'Video'        => ['#6366f1', '#06b6d4', 'play'],
    'Writing'      => ['#a855f7', '#3b82f6', 'pen'],
  ];
  return $m[$cat] ?? ['#7c6cf0', '#4a35e8', 'sparkle'];
}

/** Monogram tile with the logo laid over it. If the logo cannot load, app.js adds .noimg and the monogram shows. */
function logo_tile(array $t, string $size = 'md'): string {
  $name = (string)($t['name'] ?? '?');
  $c = cat_theme((string)($t['category'] ?? ''));
  $src = function_exists('tool_logo') ? (string)tool_logo($t) : '';
  $mono = htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1)), ENT_QUOTES);
  $img = $src !== '' ? '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="" width="64" height="64" loading="lazy" decoding="async" data-logo>' : '';
  return '<span class="lt lt--' . $size . '" style="--c1:' . $c[0] . ';--c2:' . $c[1] . '" aria-hidden="true"><b>' . $mono . '</b>' . $img . '</span>';
}

/** Hero artwork: glossy interlocked rings and a sphere, lit violet/blue. Decorative. */
function art_orb(): string {
  return '<svg class="artOrb" viewBox="0 0 520 460" role="img" aria-label="" aria-hidden="true" focusable="false"><defs>'
    . '<linearGradient id="oA" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c4b5fd"/><stop offset=".45" stop-color="#6d5dfc"/><stop offset="1" stop-color="#1d4ed8"/></linearGradient>'
    . '<linearGradient id="oB" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#67e8f9"/><stop offset=".5" stop-color="#6366f1"/><stop offset="1" stop-color="#7e22ce"/></linearGradient>'
    . '<linearGradient id="oC" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#f0abfc"/><stop offset=".55" stop-color="#8b5cf6"/><stop offset="1" stop-color="#312e81"/></linearGradient>'
    . '<radialGradient id="oS" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="#fff"/><stop offset=".25" stop-color="#a5b4fc"/><stop offset="1" stop-color="#4338ca"/></radialGradient>'
    . '<radialGradient id="oG" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#7c6cf0" stop-opacity=".55"/><stop offset="1" stop-color="#7c6cf0" stop-opacity="0"/></radialGradient>'
    . '<filter id="oBl"><feGaussianBlur stdDeviation="6"/></filter></defs>'
    . '<ellipse cx="260" cy="230" rx="250" ry="215" fill="url(#oG)"/>'
    . '<g fill="none" stroke-linecap="round">'
    . '<ellipse cx="260" cy="232" rx="170" ry="78" transform="rotate(-28 260 232)" stroke="url(#oA)" stroke-width="46"/>'
    . '<ellipse cx="260" cy="232" rx="170" ry="78" transform="rotate(-28 260 232)" stroke="#fff" stroke-opacity=".28" stroke-width="5" stroke-dasharray="120 560" stroke-dashoffset="-20"/>'
    . '<ellipse cx="260" cy="232" rx="150" ry="70" transform="rotate(34 260 232)" stroke="url(#oB)" stroke-width="40"/>'
    . '<ellipse cx="260" cy="232" rx="150" ry="70" transform="rotate(34 260 232)" stroke="#fff" stroke-opacity=".3" stroke-width="4" stroke-dasharray="90 600" stroke-dashoffset="-260"/>'
    . '<ellipse cx="260" cy="232" rx="100" ry="100" stroke="url(#oC)" stroke-width="30" opacity=".92"/>'
    . '</g>'
    . '<circle cx="388" cy="96" r="30" fill="url(#oS)"/><circle cx="132" cy="352" r="16" fill="url(#oS)" opacity=".9"/><circle cx="430" cy="330" r="9" fill="#67e8f9" opacity=".85"/>'
    . '<ellipse cx="260" cy="420" rx="140" ry="16" fill="#000" opacity=".35" filter="url(#oBl)"/></svg>';
}

/** Business hero artwork: a friendly cube-robot on a lit isometric platform, with glass gate. Decorative. */
function art_agent(): string {
  return '<svg class="artAgent" viewBox="0 0 560 480" aria-hidden="true" focusable="false"><defs>'
    . '<linearGradient id="aP" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3b3f8f"/><stop offset="1" stop-color="#171a4d"/></linearGradient>'
    . '<linearGradient id="aT" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8ea2ff"/><stop offset="1" stop-color="#4b3df0"/></linearGradient>'
    . '<linearGradient id="aL" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4f55d8"/><stop offset="1" stop-color="#2a2a8f"/></linearGradient>'
    . '<linearGradient id="aR" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3a3ab8"/><stop offset="1" stop-color="#1b1b6a"/></linearGradient>'
    . '<linearGradient id="aGl" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#67e8f9" stop-opacity=".75"/><stop offset="1" stop-color="#3b82f6" stop-opacity=".25"/></linearGradient>'
    . '<radialGradient id="aE" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#fff"/><stop offset=".4" stop-color="#ff4fd8"/><stop offset="1" stop-color="#ff4fd8" stop-opacity="0"/></radialGradient></defs>'
    . '<polygon points="280,170 540,300 280,430 20,300" fill="url(#aP)"/><polygon points="280,170 540,300 280,430 20,300" fill="none" stroke="#6d7bff" stroke-opacity=".45" stroke-width="2"/>'
    . '<g stroke="#6d7bff" stroke-opacity=".22"><path d="M150 235L410 365M215 202L475 332M345 202L85 332M410 235L150 365"/></g>'
    . '<polygon points="90,300 150,270 150,215 90,245" fill="url(#aGl)" stroke="#a5f3fc" stroke-opacity=".5"/>'
    . '<g><polygon points="280,200 350,235 280,270 210,235" fill="url(#aT)"/><polygon points="210,235 280,270 280,350 210,315" fill="url(#aL)"/><polygon points="350,235 280,270 280,350 350,315" fill="url(#aR)"/>'
    . '<circle cx="253" cy="302" r="22" fill="url(#aE)"/><circle cx="253" cy="302" r="8" fill="#fff"/><circle cx="313" cy="302" r="22" fill="url(#aE)"/><circle cx="313" cy="302" r="8" fill="#fff"/>'
    . '<rect x="268" y="150" width="8" height="52" rx="4" fill="#9aa8ff"/><circle cx="272" cy="146" r="10" fill="#ff4fd8"/></g>'
    . '<g fill="#a5b4fc"><circle cx="470" cy="150" r="7" opacity=".8"/><circle cx="90" cy="150" r="5" opacity=".6"/><circle cx="500" cy="230" r="4" opacity=".7"/></g>'
    . '<rect x="438" y="280" width="26" height="86" rx="8" fill="#c7d2fe"/><rect x="438" y="268" width="26" height="14" rx="7" fill="#e0e7ff"/></svg>';
}

/** Small workflow illustration: input, three steps, outcome. Decorative. */
function art_flow(): string {
  $n = fn($x, $y, $c) => '<rect x="' . $x . '" y="' . $y . '" width="64" height="64" rx="18" fill="' . $c . '"/><rect x="' . ($x + 14) . '" y="' . ($y + 14) . '" width="36" height="36" rx="10" fill="#fff" opacity=".25"/>';
  return '<svg class="artFlow" viewBox="0 0 640 140" aria-hidden="true" focusable="false"><defs><linearGradient id="fl" gradientUnits="userSpaceOnUse" x1="80" y1="0" x2="560" y2="0"><stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#a855f7"/></linearGradient></defs>'
    . '<path d="M80 70H190M270 70H380M460 70H560" stroke="url(#fl)" stroke-width="4" stroke-dasharray="2 9" stroke-linecap="round"/>'
    . $n(16, 38, '#2563eb') . $n(206, 38, '#7c3aed') . $n(396, 38, '#c026d3') . $n(560, 38, '#0d9488')
    . '<g fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M40 72l10-14v10h10l-10 14v-10z"/><ellipse cx="238" cy="60" rx="12" ry="5"/><path d="M226 60v12c0 3 5 5 12 5s12-2 12-5V60"/><path d="M428 56l3 8 8 3-8 3-3 8-3-8-8-3 8-3z"/><path d="M584 70l7 7 13-14"/></g></svg>';
}

/** Abstract 3D-ish thumbnail for editorial cards, tinted by the category theme. $variant (0-3) changes the composition. */
function art_thumb(string $cat, int $variant = 0): string {
  [$a, $b] = cat_theme($cat); $id = 't' . substr(md5($cat . $variant), 0, 6);
  $shapes = [
    '<circle cx="200" cy="110" r="64" fill="url(#' . $id . 's)"/><rect x="60" y="70" width="110" height="110" rx="26" transform="rotate(-14 115 125)" fill="url(#' . $id . 'g)" opacity=".92"/><circle cx="270" cy="60" r="16" fill="#fff" opacity=".7"/>',
    '<rect x="120" y="40" width="130" height="130" rx="34" transform="rotate(18 185 105)" fill="url(#' . $id . 'g)"/><circle cx="90" cy="140" r="44" fill="url(#' . $id . 's)"/><rect x="230" y="120" width="60" height="60" rx="16" transform="rotate(-12 260 150)" fill="#fff" opacity=".35"/>',
    '<ellipse cx="170" cy="110" rx="110" ry="40" transform="rotate(-20 170 110)" fill="none" stroke="url(#' . $id . 'g)" stroke-width="30"/><circle cx="220" cy="90" r="40" fill="url(#' . $id . 's)"/><circle cx="80" cy="150" r="14" fill="#fff" opacity=".7"/>',
    '<polygon points="170,30 270,90 270,160 170,190 70,160 70,90" fill="url(#' . $id . 'g)" opacity=".95"/><circle cx="170" cy="110" r="46" fill="url(#' . $id . 's)"/><circle cx="288" cy="40" r="12" fill="#fff" opacity=".7"/>',
  ];
  return '<svg class="artThumb" viewBox="0 0 340 210" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false"><defs>'
    . '<linearGradient id="' . $id . 'g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $a . '"/><stop offset="1" stop-color="' . $b . '"/></linearGradient>'
    . '<radialGradient id="' . $id . 's" cx=".3" cy=".25" r=".9"><stop offset="0" stop-color="#fff"/><stop offset=".3" stop-color="' . $a . '"/><stop offset="1" stop-color="' . $b . '"/></radialGradient>'
    . '<linearGradient id="' . $id . 'b" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $b . '" stop-opacity=".35"/><stop offset="1" stop-color="#0b0d2e"/></linearGradient></defs>'
    . '<rect width="340" height="210" fill="url(#' . $id . 'b)"/>' . $shapes[$variant % 4] . '</svg>';
}

/** Ad-placement preview: a miniature homepage with the sponsored slot highlighted. $slot: featured | spotlight | listing. */
function art_placement(string $slot): string {
  $hl = 'fill="url(#pv)" stroke="#fff" stroke-opacity=".6"';
  $row = '<rect x="14" y="%d" width="%d" height="%d" rx="6" fill="#fff" opacity=".12"/>';
  $o = '<svg class="artPlace" viewBox="0 0 240 150" aria-hidden="true" focusable="false"><defs><linearGradient id="pv" x1="0" x2="1"><stop offset="0" stop-color="#8b5cf6"/><stop offset="1" stop-color="#3b82f6"/></linearGradient></defs>'
    . '<rect x="1" y="1" width="238" height="148" rx="12" fill="#0f1236" stroke="#fff" stroke-opacity=".18"/><rect x="1" y="1" width="238" height="16" rx="12" fill="#fff" opacity=".1"/><circle cx="12" cy="9" r="2.5" fill="#fff" opacity=".5"/><circle cx="21" cy="9" r="2.5" fill="#fff" opacity=".5"/>';
  if ($slot === 'spotlight') $o .= '<rect x="14" y="26" width="212" height="42" rx="8" ' . $hl . '/><text x="22" y="52" font-size="10" font-weight="700" fill="#fff" font-family="sans-serif">SPONSORED · Spotlight</text>';
  else $o .= '<rect x="14" y="26" width="212" height="42" rx="8" fill="#fff" opacity=".1"/>';
  for ($i = 0; $i < 3; $i++) {
    $x = 14 + $i * 72;
    $o .= ($slot === 'featured' && $i === 1) ? '<rect x="' . $x . '" y="80" width="68" height="56" rx="8" ' . $hl . '/><text x="' . ($x + 6) . '" y="112" font-size="8" font-weight="700" fill="#fff" font-family="sans-serif">SPONSORED</text>'
      : '<rect x="' . $x . '" y="80" width="68" height="56" rx="8" fill="#fff" opacity=".1"/>';
  }
  if ($slot === 'listing') $o = str_replace('<rect x="14" y="26" width="212" height="42" rx="8" fill="#fff" opacity=".1"/>', '<rect x="14" y="26" width="212" height="42" rx="8" fill="#fff" opacity=".1"/><rect x="20" y="32" width="60" height="30" rx="6" fill="url(#pv)"/>', $o);
  return $o . '</svg>';
}

/* =====================================================================================================
   V3 artwork kit: chrome, glass and lit-sphere primitives composed into scenes. Original, inline, no network.
   Every scene takes no arguments, is aria-hidden and uses its own gradient id prefix so several can share a page.
   ===================================================================================================== */
function _a_defs(string $p): string {
  $s = fn($o, $c, $a = 1) => '<stop offset="' . $o . '" stop-color="' . $c . '"' . ($a < 1 ? ' stop-opacity="' . $a . '"' : '') . '/>';
  return '<defs>'
    . '<linearGradient id="' . $p . 'ch" x1="0" y1="0" x2="1" y2="1">' . $s(0, '#f7f8ff') . $s(.2, '#a8b3e6') . $s(.46, '#eef1ff') . $s(.74, '#6a76bd') . $s(1, '#2a2e6e') . '</linearGradient>'
    . '<linearGradient id="' . $p . 'gl" x1="0" y1="0" x2="1" y2="1">' . $s(0, '#ffffff', .42) . $s(.5, '#a5b4fc', .14) . $s(1, '#22d3ee', .10) . '</linearGradient>'
    . '<radialGradient id="' . $p . 'sp" cx=".32" cy=".28" r=".85">' . $s(0, '#ffffff') . $s(.22, '#b9c3ff') . $s(.6, '#5a4df0') . $s(1, '#14123f') . '</radialGradient>'
    . '<radialGradient id="' . $p . 'sc" cx=".32" cy=".28" r=".85">' . $s(0, '#ffffff') . $s(.22, '#a5f3fc') . $s(.6, '#2a8cf0') . $s(1, '#10205a') . '</radialGradient>'
    . '<radialGradient id="' . $p . 'sm" cx=".32" cy=".28" r=".85">' . $s(0, '#ffffff') . $s(.22, '#f5c2ff') . $s(.6, '#a24df0') . $s(1, '#2a1259') . '</radialGradient>'
    . '<linearGradient id="' . $p . 'vi" x1="0" y1="0" x2="1" y2="1">' . $s(0, '#c4b5fd') . $s(.5, '#6d5dfc') . $s(1, '#1d4ed8') . '</linearGradient>'
    . '<linearGradient id="' . $p . 'cy" x1="1" y1="0" x2="0" y2="1">' . $s(0, '#67e8f9') . $s(.55, '#3b82f6') . $s(1, '#4c1d95') . '</linearGradient>'
    . '<radialGradient id="' . $p . 'gw" cx=".5" cy=".5" r=".5">' . $s(0, '#7c6cf0', .5) . $s(1, '#7c6cf0', 0) . '</radialGradient>'
    . '<filter id="' . $p . 'bl" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="9"/></filter></defs>';
}
function _a_svg(string $cls, string $vb, string $p, string $inner): string {
  return '<svg class="art ' . $cls . '" viewBox="' . $vb . '" aria-hidden="true" focusable="false">' . _a_defs($p) . $inner . '</svg>';
}
/** Lit sphere. $g: sp (violet), sc (cyan), sm (magenta), ch (chrome). */
function _a_sph(string $p, float $cx, float $cy, float $r, string $g = 'sp'): string {
  return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="url(#' . $p . $g . ')"/><ellipse cx="' . ($cx - $r * .3) . '" cy="' . ($cy - $r * .42) . '" rx="' . ($r * .34) . '" ry="' . ($r * .18) . '" fill="#fff" opacity=".55" transform="rotate(-24 ' . ($cx - $r * .3) . ' ' . ($cy - $r * .42) . ')"/>';
}
/** Frosted glass slab with a bright top edge. */
function _a_slab(string $p, float $x, float $y, float $w, float $h, float $r = 22, string $tr = ''): string {
  return '<g' . ($tr ? ' transform="' . $tr . '"' : '') . '><rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="' . $r . '" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".38"/><path d="M' . ($x + $r) . ' ' . ($y + 1.5) . 'H' . ($x + $w - $r) . '" stroke="#fff" stroke-opacity=".75" stroke-width="2" stroke-linecap="round"/></g>';
}
function _a_ring(string $p, float $cx, float $cy, float $rx, float $ry, float $rot, float $w, string $g = 'vi'): string {
  return '<ellipse cx="' . $cx . '" cy="' . $cy . '" rx="' . $rx . '" ry="' . $ry . '" transform="rotate(' . $rot . ' ' . $cx . ' ' . $cy . ')" fill="none" stroke="url(#' . $p . $g . ')" stroke-width="' . $w . '"/>'
    . '<ellipse cx="' . $cx . '" cy="' . $cy . '" rx="' . $rx . '" ry="' . $ry . '" transform="rotate(' . $rot . ' ' . $cx . ' ' . $cy . ')" fill="none" stroke="#fff" stroke-opacity=".3" stroke-width="' . max(2, $w / 9) . '" stroke-dasharray="' . ($rx * .5) . ' ' . ($rx * 4) . '" stroke-dashoffset="-' . ($rx * .25) . '"/>';
}
function _a_glow(string $p, float $cx, float $cy, float $r): string { return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="url(#' . $p . 'gw)"/>'; }
function _a_shadow(string $p, float $cx, float $cy, float $rx): string { return '<ellipse cx="' . $cx . '" cy="' . $cy . '" rx="' . $rx . '" ry="' . ($rx * .1) . '" fill="#000" opacity=".4" filter="url(#' . $p . 'bl)"/>'; }
/** Isometric cube. $top/$left/$right are gradient ids (without prefix). */
function _a_cube(string $p, float $cx, float $y, float $s, string $top = 'ch', string $left = 'vi', string $right = 'cy'): string {
  $h = $s * .5; $d = $s * .58;
  return '<polygon points="' . $cx . ',' . $y . ' ' . ($cx + $s) . ',' . ($y + $h) . ' ' . $cx . ',' . ($y + 2 * $h) . ' ' . ($cx - $s) . ',' . ($y + $h) . '" fill="url(#' . $p . $top . ')"/>'
    . '<polygon points="' . ($cx - $s) . ',' . ($y + $h) . ' ' . $cx . ',' . ($y + 2 * $h) . ' ' . $cx . ',' . ($y + 2 * $h + $d) . ' ' . ($cx - $s) . ',' . ($y + $h + $d) . '" fill="url(#' . $p . $left . ')"/>'
    . '<polygon points="' . ($cx + $s) . ',' . ($y + $h) . ' ' . $cx . ',' . ($y + 2 * $h) . ' ' . $cx . ',' . ($y + 2 * $h + $d) . ' ' . ($cx + $s) . ',' . ($y + $h + $d) . '" fill="url(#' . $p . $right . ')"/>'
    . '<path d="M' . ($cx - $s) . ' ' . ($y + $h) . 'L' . $cx . ' ' . $y . 'L' . ($cx + $s) . ' ' . ($y + $h) . '" fill="none" stroke="#fff" stroke-opacity=".6" stroke-width="1.5"/>';
}

/** Communication: two glass chat bubbles, a chrome sphere and a ring. */
function art_chat(): string { $p = 'ac'; return _a_svg('artScene', '0 0 520 440', $p,
  _a_glow($p, 260, 220, 230) . _a_ring($p, 262, 232, 190, 66, -24, 30) . _a_shadow($p, 260, 410, 150)
  . _a_slab($p, 70, 90, 250, 130, 34) . '<path d="M110 220l-6 46 52-36z" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".38"/>'
  . '<g fill="#fff" opacity=".85"><rect x="104" y="128" width="150" height="12" rx="6"/><rect x="104" y="156" width="190" height="12" rx="6" opacity=".6"/><rect x="104" y="184" width="110" height="12" rx="6" opacity=".4"/></g>'
  . _a_slab($p, 230, 230, 230, 120, 34) . '<path d="M420 350l8 42-48-30z" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".38"/>'
  . '<circle cx="290" cy="290" r="10" fill="#a5f3fc"/><circle cx="326" cy="290" r="10" fill="#c4b5fd"/><circle cx="362" cy="290" r="10" fill="#f5c2ff"/>'
  . _a_sph($p, 410, 120, 46, 'ch') . _a_sph($p, 90, 330, 22, 'sc') . _a_sph($p, 470, 260, 12, 'sm')); }

/** Podium: metallic 2-1-3 blocks with lit spheres. */
function art_podium(): string { $p = 'ap'; return _a_svg('artScene', '0 0 560 430', $p,
  _a_glow($p, 280, 200, 250) . _a_shadow($p, 280, 398, 220)
  . _a_cube($p, 150, 232, 92, 'ch', 'vi', 'cy') . _a_cube($p, 280, 170, 92, 'ch', 'vi', 'cy') . _a_cube($p, 410, 262, 92, 'ch', 'vi', 'cy')
  . '<g font-family="Space Grotesk,Inter,sans-serif" font-weight="800" font-size="40" fill="#fff" opacity=".92" text-anchor="middle"><text x="150" y="346" transform="skewY(0)">2</text><text x="280" y="284">1</text><text x="410" y="376">3</text></g>'
  . _a_sph($p, 280, 118, 40, 'sm') . _a_sph($p, 150, 178, 28, 'sc') . _a_sph($p, 410, 208, 24, 'sp')
  . _a_ring($p, 280, 120, 74, 18, -14, 9, 'cy')); }

/** Trust: a glass shield with a check, orbited by a ring and spheres. */
function art_shield(): string { $p = 'as'; return _a_svg('artScene', '0 0 520 440', $p,
  _a_glow($p, 260, 215, 220) . _a_shadow($p, 260, 408, 130) . _a_ring($p, 260, 230, 200, 60, 22, 24, 'cy')
  . '<path d="M260 50l124 44v104c0 86-52 140-124 172-72-32-124-86-124-172V94z" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".45" stroke-width="2"/>'
  . '<path d="M260 82l94 34v86c0 66-38 108-94 134-56-26-94-68-94-134v-86z" fill="url(#' . $p . 'vi)" opacity=".92"/>'
  . '<path d="M212 214l34 34 66-72" fill="none" stroke="#fff" stroke-width="18" stroke-linecap="round" stroke-linejoin="round"/>'
  . _a_sph($p, 410, 90, 30, 'sc') . _a_sph($p, 100, 320, 20, 'sm') . _a_sph($p, 440, 330, 12, 'sp')); }

/** Ecosystem: a chrome hub connected to orbiting nodes. */
function art_network(): string { $p = 'an'; $pts = [[90, 130], [430, 110], [70, 300], [450, 300], [260, 60], [260, 390]];
  $lines = ''; $nodes = ''; $gs = ['sp', 'sc', 'sm', 'sp', 'sc', 'sm'];
  foreach ($pts as $i => [$x, $y]) { $lines .= '<path d="M260 225L' . $x . ' ' . $y . '" stroke="url(#' . $p . 'cy)" stroke-width="3" stroke-dasharray="3 9" stroke-linecap="round" opacity=".8"/>'; $nodes .= _a_slab($p, $x - 34, $y - 34, 68, 68, 22) . _a_sph($p, $x, $y, 17, $gs[$i]); }
  return _a_svg('artScene', '0 0 520 450', $p, _a_glow($p, 260, 225, 240) . _a_ring($p, 260, 225, 200, 80, -20, 4, 'vi') . $lines . _a_shadow($p, 260, 428, 120) . $nodes . _a_sph($p, 260, 225, 64, 'ch') . '<circle cx="260" cy="225" r="30" fill="url(#' . $p . 'vi)"/>'); }

/** Method: stacked glass layers (evidence levels). */
function art_layers(): string { $p = 'al'; $o = _a_glow($p, 260, 210, 230) . _a_shadow($p, 260, 400, 160);
  foreach ([[300, 'cy'], [236, 'vi'], [172, 'ch']] as [$y, $g]) { $o .= '<g><polygon points="260,' . $y . ' 430,' . ($y + 62) . ' 260,' . ($y + 124) . ' 90,' . ($y + 62) . '" fill="url(#' . $p . $g . ')" opacity=".9"/><polygon points="260,' . $y . ' 430,' . ($y + 62) . ' 260,' . ($y + 124) . ' 90,' . ($y + 62) . '" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".5"/></g>'; }
  $o .= '<polygon points="260,108 430,170 260,232 90,170" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".6" stroke-width="2"/>' . _a_sph($p, 260, 150, 30, 'sc') . _a_sph($p, 410, 90, 16, 'sm');
  return _a_svg('artScene', '0 0 520 430', $p, $o); }

/** Discovery: a glass lens with a sphere inside. */
function art_lens(): string { $p = 'ae'; return _a_svg('artScene', '0 0 520 440', $p,
  _a_glow($p, 240, 210, 230) . _a_shadow($p, 260, 410, 140)
  . '<circle cx="230" cy="200" r="130" fill="url(#' . $p . 'gl)" stroke="url(#' . $p . 'ch)" stroke-width="26"/>'
  . '<path d="M330 300l92 96" stroke="url(#' . $p . 'ch)" stroke-width="40" stroke-linecap="round"/><path d="M330 300l92 96" stroke="#fff" stroke-opacity=".35" stroke-width="6" stroke-linecap="round"/>'
  . _a_sph($p, 215, 190, 52, 'sp') . _a_sph($p, 290, 130, 18, 'sc') . _a_sph($p, 160, 255, 14, 'sm')
  . _a_ring($p, 230, 200, 170, 44, 18, 6, 'cy')); }

/** Guided steps: ascending glass blocks with a sphere on the top step. */
function art_steps(): string { $p = 'at'; return _a_svg('artScene', '0 0 520 420', $p,
  _a_glow($p, 270, 200, 230) . _a_shadow($p, 270, 392, 200)
  . _a_cube($p, 110, 250, 72, 'ch', 'cy', 'vi') . _a_cube($p, 220, 200, 72, 'ch', 'cy', 'vi') . _a_cube($p, 330, 150, 72, 'ch', 'cy', 'vi') . _a_cube($p, 440, 100, 72, 'ch', 'cy', 'vi')
  . _a_sph($p, 440, 62, 30, 'sm') . '<path d="M110 232L220 182L330 132L440 82" stroke="#fff" stroke-opacity=".5" stroke-width="3" stroke-dasharray="4 10" fill="none" stroke-linecap="round"/>'); }

/** Compass for problem-based discovery. */
function art_compass(): string { $p = 'ak'; return _a_svg('artScene', '0 0 520 440', $p,
  _a_glow($p, 260, 215, 220) . _a_shadow($p, 260, 410, 130)
  . '<circle cx="260" cy="215" r="150" fill="url(#' . $p . 'gl)" stroke="url(#' . $p . 'ch)" stroke-width="22"/><circle cx="260" cy="215" r="112" fill="none" stroke="#fff" stroke-opacity=".3" stroke-dasharray="2 10" stroke-width="4"/>'
  . '<polygon points="260,100 292,215 260,330 228,215" fill="url(#' . $p . 'vi)" transform="rotate(38 260 215)"/><polygon points="260,100 292,215 228,215" fill="#fff" opacity=".55" transform="rotate(38 260 215)"/>'
  . _a_sph($p, 260, 215, 20, 'ch') . _a_sph($p, 420, 100, 22, 'sc') . _a_sph($p, 100, 330, 16, 'sm')); }

/** Mail / confirmation: envelope slab with a check sphere. */
function art_mail(): string { $p = 'am'; return _a_svg('artScene', '0 0 520 400', $p,
  _a_glow($p, 260, 200, 220) . _a_shadow($p, 260, 372, 150) . _a_ring($p, 260, 200, 200, 60, -18, 18, 'cy')
  . _a_slab($p, 90, 110, 340, 220, 30) . '<path d="M100 132l160 110 160-110" fill="none" stroke="#fff" stroke-opacity=".7" stroke-width="5" stroke-linejoin="round"/>'
  . _a_sph($p, 410, 120, 48, 'sc') . '<path d="M388 120l16 16 30-34" fill="none" stroke="#fff" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>' . _a_sph($p, 90, 340, 16, 'sm')); }

/** Page not found: a lit orb with a broken ring. */
function art_lost(): string { $p = 'ao'; return _a_svg('artScene', '0 0 520 400', $p,
  _a_glow($p, 260, 190, 220) . _a_shadow($p, 260, 372, 120)
  . '<path d="M110 200a150 60 0 0 1 130-58" fill="none" stroke="url(#' . $p . 'vi)" stroke-width="26" stroke-linecap="round"/><path d="M300 140a150 60 0 0 1 112 62" fill="none" stroke="url(#' . $p . 'cy)" stroke-width="26" stroke-linecap="round"/><path d="M130 240a150 60 0 0 0 270 6" fill="none" stroke="url(#' . $p . 'vi)" stroke-width="26" stroke-linecap="round" opacity=".7"/>'
  . _a_sph($p, 260, 190, 70, 'sp') . _a_sph($p, 410, 90, 20, 'sc') . _a_sph($p, 100, 300, 14, 'sm') . '<text x="260" y="206" text-anchor="middle" font-family="Space Grotesk,Inter,sans-serif" font-weight="800" font-size="46" fill="#fff" opacity=".9">?</text>'); }

/** Calm document stack for legal and policy pages. */
function art_doc(): string { $p = 'ad'; return _a_svg('artScene', '0 0 520 400', $p,
  _a_glow($p, 260, 200, 210) . _a_shadow($p, 260, 372, 130)
  . _a_slab($p, 150, 70, 220, 270, 26, 'rotate(-8 260 200)') . _a_slab($p, 150, 70, 220, 270, 26, 'rotate(6 260 200)')
  . '<g fill="#fff" opacity=".8"><rect x="190" y="130" width="140" height="12" rx="6"/><rect x="190" y="164" width="120" height="12" rx="6" opacity=".6"/><rect x="190" y="198" width="140" height="12" rx="6" opacity=".6"/><rect x="190" y="232" width="90" height="12" rx="6" opacity=".4"/></g>'
  . _a_sph($p, 380, 110, 30, 'sc') . _a_sph($p, 130, 320, 18, 'sm')); }

/** Generic hero scene chooser used by page_hero(). */
function art_scene(string $name): string {
  static $m = ['chat' => 'art_chat', 'podium' => 'art_podium', 'shield' => 'art_shield', 'network' => 'art_network', 'layers' => 'art_layers', 'lens' => 'art_lens', 'steps' => 'art_steps', 'compass' => 'art_compass', 'mail' => 'art_mail', 'lost' => 'art_lost', 'doc' => 'art_doc', 'orb' => 'art_orb', 'agent' => 'art_agent'];
  return isset($m[$name]) ? ($m[$name])() : '';
}
