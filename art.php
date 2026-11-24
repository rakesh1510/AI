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
  $img = $src !== '' ? '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="" width="64" height="64" loading="lazy" decoding="async" fetchpriority="low" data-logo>' : '';
  return '<span class="lt lt--' . $size . '" style="--c1:' . $c[0] . ';--c2:' . $c[1] . '" aria-hidden="true"><b>' . $mono . '</b>' . $img . '</span>';
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
  // Studio-render cover when available: one of four compositions, tinted per category with a hue rotation (cheap, GPU-side).
  $f = 'thumb' . ($variant % 4); $path = __DIR__ . '/assets/art/' . $f . '.webp';
  if (is_file($path) && ($sz = @getimagesize($path))) {
    $hex = ltrim(cat_theme($cat)[0], '#'); $r = hexdec(substr($hex, 0, 2)) / 255; $g = hexdec(substr($hex, 2, 2)) / 255; $b = hexdec(substr($hex, 4, 2)) / 255;
    $mx = max($r, $g, $b); $mn = min($r, $g, $b); $d = $mx - $mn; $h = $d == 0 ? 0 : ($mx == $r ? fmod(($g - $b) / $d, 6) : ($mx == $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4)); $h = fmod($h * 60 + 360, 360);
    $rot = (int)round($h - 262);
    return '<span class="artThumbFrame"><img class="artThumb artImg" src="/assets/art/' . $f . '.webp?v=' . @filemtime($path) . '" width="' . (int)$sz[0] . '" height="' . (int)$sz[1] . '" alt="" aria-hidden="true" loading="lazy" decoding="async" style="filter:hue-rotate(' . $rot . 'deg)"></span>';
  }
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
  static $n = 0; $pv = 'pv' . (++$n);   // unique gradient id per instance: several previews share one page
  $hl = 'fill="url(#' . $pv . ')" stroke="#fff" stroke-opacity=".6"';
  $row = '<rect x="14" y="%d" width="%d" height="%d" rx="6" fill="#fff" opacity=".12"/>';
  $o = '<svg class="artPlace" viewBox="0 0 240 150" aria-hidden="true" focusable="false"><defs><linearGradient id="' . $pv . '" x1="0" x2="1"><stop offset="0" stop-color="#8b5cf6"/><stop offset="1" stop-color="#3b82f6"/></linearGradient></defs>'
    . '<rect x="1" y="1" width="238" height="148" rx="12" fill="#0f1236" stroke="#fff" stroke-opacity=".18"/><rect x="1" y="1" width="238" height="16" rx="12" fill="#fff" opacity=".1"/><circle cx="12" cy="9" r="2.5" fill="#fff" opacity=".5"/><circle cx="21" cy="9" r="2.5" fill="#fff" opacity=".5"/>';
  if ($slot === 'spotlight') $o .= '<rect x="14" y="26" width="212" height="42" rx="8" ' . $hl . '/><text x="22" y="52" font-size="10" font-weight="700" fill="#fff" font-family="sans-serif">SPONSORED · Spotlight</text>';
  else $o .= '<rect x="14" y="26" width="212" height="42" rx="8" fill="#fff" opacity=".1"/>';
  for ($i = 0; $i < 3; $i++) {
    $x = 14 + $i * 72;
    $o .= ($slot === 'featured' && $i === 1) ? '<rect x="' . $x . '" y="80" width="68" height="56" rx="8" ' . $hl . '/><text x="' . ($x + 6) . '" y="112" font-size="8" font-weight="700" fill="#fff" font-family="sans-serif">SPONSORED</text>'
      : '<rect x="' . $x . '" y="80" width="68" height="56" rx="8" fill="#fff" opacity=".1"/>';
  }
  if ($slot === 'listing') $o = str_replace('<rect x="14" y="26" width="212" height="42" rx="8" fill="#fff" opacity=".1"/>', '<rect x="14" y="26" width="212" height="42" rx="8" fill="#fff" opacity=".1"/><rect x="20" y="32" width="60" height="30" rx="6" fill="url(#' . $pv . ')"/>', $o);
  return $o . '</svg>';
}

/** Studio-render image for a scene (Blender/Cycles stills in assets/art/*.webp, see assets3d/). Returns '' when the file is missing so the inline SVG artwork is used as a fallback. */
function _art_img(string $file, string $class, bool $eager = false): string {
  static $cache = [];
  $path = __DIR__ . '/assets/art/' . $file . '.webp';
  if (!isset($cache[$path])) { $cache[$path] = is_file($path) ? (@getimagesize($path) ?: null) : null; }
  $sz = $cache[$path]; if (!$sz) return '';
  $v = (string)@filemtime($path);
  $small = __DIR__ . '/assets/art/' . $file . '-sm.webp';
  $srcset = is_file($small) ? ' srcset="/assets/art/' . $file . '-sm.webp?v=' . $v . ' 560w, /assets/art/' . $file . '.webp?v=' . $v . ' ' . (int)$sz[0] . 'w" sizes="(max-width:900px) 220px, 520px"' : '';
  return '<img class="art ' . $class . ' artImg" src="/assets/art/' . $file . '.webp?v=' . $v . '"' . $srcset . ' width="' . (int)$sz[0] . '" height="' . (int)$sz[1] . '" alt="" aria-hidden="true" decoding="async" ' . ($eager ? 'fetchpriority="high"' : 'loading="lazy"') . '>';
}

/* =====================================================================================================
   V3 artwork kit: chrome, glass and lit-sphere primitives composed into scenes. Original, inline, no network.
   Every scene takes no arguments, is aria-hidden and uses its own gradient id prefix so several can share a page.
   ===================================================================================================== */
function _a_defs(string $p): string {
  $s = fn($o, $c, $a = 1) => '<stop offset="' . $o . '" stop-color="' . $c . '"' . ($a < 1 ? ' stop-opacity="' . $a . '"' : '') . '/>';
  return '<defs>'
    // brushed chrome: bright specular bands with dark reflections between them
    . '<linearGradient id="' . $p . 'ch" x1="0" y1="0" x2="1" y2="1">' . $s(0, '#ffffff') . $s(.14, '#c9d2f5') . $s(.3, '#6c78c4') . $s(.46, '#f1f4ff') . $s(.58, '#8f9bd8') . $s(.8, '#3b4390') . $s(1, '#161a4f') . '</linearGradient>'
    . '<linearGradient id="' . $p . 'gl" x1="0" y1="0" x2="1" y2="1">' . $s(0, '#ffffff', .5) . $s(.35, '#b7c4ff', .16) . $s(.7, '#7dd3fc', .08) . $s(1, '#a78bfa', .18) . '</linearGradient>'
    . '<linearGradient id="' . $p . 'ge" x1="0" y1="0" x2="1" y2="1">' . $s(0, '#ffffff', .95) . $s(.35, '#ffffff', .25) . $s(.65, '#ffffff', .05) . $s(1, '#7dd3fc', .55) . '</linearGradient>'
    . '<radialGradient id="' . $p . 'sp" cx=".34" cy=".3" r=".82">' . $s(0, '#ffffff') . $s(.18, '#c7d0ff') . $s(.5, '#6d5dfc') . $s(.82, '#26208a') . $s(1, '#0e0c3a') . '</radialGradient>'
    . '<radialGradient id="' . $p . 'sc" cx=".34" cy=".3" r=".82">' . $s(0, '#ffffff') . $s(.18, '#baf3ff') . $s(.5, '#2f9bf5') . $s(.82, '#13318f') . $s(1, '#081338') . '</radialGradient>'
    . '<radialGradient id="' . $p . 'sm" cx=".34" cy=".3" r=".82">' . $s(0, '#ffffff') . $s(.18, '#ffd0f7') . $s(.5, '#b04df0') . $s(.82, '#46188f') . $s(1, '#1a0a3c') . '</radialGradient>'
    . '<radialGradient id="' . $p . 'rm" cx=".72" cy=".78" r=".62">' . $s(0, '#67e8f9', 0) . $s(.72, '#67e8f9', 0) . $s(1, '#67e8f9', .7) . '</radialGradient>'
    . '<linearGradient id="' . $p . 'vi" x1="0" y1="0" x2="1" y2="1">' . $s(0, '#e0d4ff') . $s(.28, '#8b7bff') . $s(.62, '#4f46e5') . $s(1, '#1d2c9e') . '</linearGradient>'
    . '<linearGradient id="' . $p . 'cy" x1="1" y1="0" x2="0" y2="1">' . $s(0, '#a5f3fc') . $s(.4, '#38bdf8') . $s(.75, '#4f46e5') . $s(1, '#4c1d95') . '</linearGradient>'
    . '<linearGradient id="' . $p . 'mg" x1="0" y1="0" x2="1" y2="1">' . $s(0, '#ffd6fb') . $s(.4, '#e879f9') . $s(.8, '#7c3aed') . $s(1, '#3b1a8f') . '</linearGradient>'
    . '<linearGradient id="' . $p . 'ao" x1="0" y1="0" x2="0" y2="1">' . $s(0, '#000', 0) . $s(1, '#06061f', .55) . '</linearGradient>'
    . '<radialGradient id="' . $p . 'gw" cx=".5" cy=".5" r=".5">' . $s(0, '#8b7bff', .55) . $s(.5, '#5b6bff', .16) . $s(1, '#7c6cf0', 0) . '</radialGradient>'
    . '<filter id="' . $p . 'bl" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="9"/></filter>'
    . '<filter id="' . $p . 'sb" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="2.2"/></filter>'
    . '<filter id="' . $p . 'ds" x="-30%" y="-30%" width="160%" height="170%"><feDropShadow dx="0" dy="10" stdDeviation="9" flood-color="#05052a" flood-opacity=".55"/></filter>'
    . '</defs>';
}
function _a_svg(string $cls, string $vb, string $p, string $inner): string {
  return '<svg class="art ' . $cls . '" viewBox="' . $vb . '" aria-hidden="true" focusable="false">' . _a_defs($p) . $inner . '</svg>';
}
/** Lit sphere with rim light, soft specular and a hot spot. $g: sp (violet), sc (cyan), sm (magenta), ch (chrome). */
function _a_sph(string $p, float $cx, float $cy, float $r, string $g = 'sp'): string {
  $hx = $cx - $r * .32; $hy = $cy - $r * .4;
  return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="url(#' . $p . $g . ')" filter="url(#' . $p . 'ds)"/>'
    . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="url(#' . $p . 'rm)"/>'
    . '<ellipse cx="' . $hx . '" cy="' . $hy . '" rx="' . ($r * .38) . '" ry="' . ($r * .2) . '" fill="#fff" opacity=".6" transform="rotate(-28 ' . $hx . ' ' . $hy . ')" filter="url(#' . $p . 'sb)"/>'
    . '<circle cx="' . ($hx - $r * .06) . '" cy="' . ($hy - $r * .02) . '" r="' . max(1.5, $r * .07) . '" fill="#fff"/>';
}
/** Frosted glass slab: tint, bevelled edge, diagonal sheen, soft shadow. */
function _a_slab(string $p, float $x, float $y, float $w, float $h, float $r = 22, string $tr = ''): string {
  static $n = 0; $id = $p . 'cl' . (++$n);
  return '<g' . ($tr ? ' transform="' . $tr . '"' : '') . '>'
    . '<rect x="' . ($x + 4) . '" y="' . ($y + 14) . '" width="' . ($w - 8) . '" height="' . $h . '" rx="' . $r . '" fill="#05052a" opacity=".38" filter="url(#' . $p . 'bl)"/>'
    . '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="' . $r . '" fill="url(#' . $p . 'gl)"/>'
    . '<clipPath id="' . $id . '"><rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="' . $r . '"/></clipPath>'
    . '<g clip-path="url(#' . $id . ')"><polygon points="' . ($x - 10) . ',' . ($y + $h * .65) . ' ' . ($x + $w * .62) . ',' . ($y - 10) . ' ' . ($x + $w * .86) . ',' . ($y - 10) . ' ' . ($x + 10) . ',' . ($y + $h + 10) . '" fill="#fff" opacity=".1"/></g>'
    . '<rect x="' . ($x + .75) . '" y="' . ($y + .75) . '" width="' . ($w - 1.5) . '" height="' . ($h - 1.5) . '" rx="' . $r . '" fill="none" stroke="url(#' . $p . 'ge)" stroke-width="1.5"/>'
    . '</g>';
}
/** Glossy tube/ring: dark edge, gradient body, inner sheen and specular arcs. */
function _a_ring(string $p, float $cx, float $cy, float $rx, float $ry, float $rot, float $w, string $g = 'vi'): string {
  $t = ' transform="rotate(' . $rot . ' ' . $cx . ' ' . $cy . ')"';
  $e = '<ellipse cx="' . $cx . '" cy="' . $cy . '" rx="' . $rx . '" ry="' . $ry . '"' . $t . ' fill="none" ';
  return $e . 'stroke="#07072e" stroke-opacity=".55" stroke-width="' . ($w + 5) . '" filter="url(#' . $p . 'sb)"/>'
    . $e . 'stroke="url(#' . $p . $g . ')" stroke-width="' . $w . '"/>'
    . $e . 'stroke="#fff" stroke-opacity=".22" stroke-width="' . max(2, $w * .34) . '" stroke-dasharray="' . ($rx * 1.5) . ' ' . ($rx * 5) . '" stroke-dashoffset="-' . ($rx * .1) . '" stroke-linecap="round" filter="url(#' . $p . 'sb)"/>'
    . $e . 'stroke="#fff" stroke-opacity=".85" stroke-width="' . max(1.6, $w / 10) . '" stroke-dasharray="' . ($rx * .34) . ' ' . ($rx * 6) . '" stroke-dashoffset="-' . ($rx * .22) . '" stroke-linecap="round"/>'
    . $e . 'stroke="#7dd3fc" stroke-opacity=".55" stroke-width="' . max(1.5, $w / 12) . '" stroke-dasharray="' . ($rx * .5) . ' ' . ($rx * 5.5) . '" stroke-dashoffset="-' . ($rx * 3.1) . '" stroke-linecap="round"/>';
}
function _a_glow(string $p, float $cx, float $cy, float $r): string { return '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="url(#' . $p . 'gw)"/>'; }
function _a_shadow(string $p, float $cx, float $cy, float $rx): string { return '<ellipse cx="' . $cx . '" cy="' . $cy . '" rx="' . $rx . '" ry="' . ($rx * .1) . '" fill="#000" opacity=".45" filter="url(#' . $p . 'bl)"/>'; }
/** Isometric block with gloss on the top face, ambient occlusion on the sides and bright edges. */
function _a_cube(string $p, float $cx, float $y, float $s, string $top = 'ch', string $left = 'vi', string $right = 'cy'): string {
  $h = $s * .5; $d = $s * .58; $b = $y + 2 * $h;
  $face = fn($pts, $g) => '<polygon points="' . $pts . '" fill="url(#' . $p . $g . ')"/>';
  $L = ($cx - $s) . ',' . ($y + $h) . ' ' . $cx . ',' . $b . ' ' . $cx . ',' . ($b + $d) . ' ' . ($cx - $s) . ',' . ($y + $h + $d);
  $R = ($cx + $s) . ',' . ($y + $h) . ' ' . $cx . ',' . $b . ' ' . $cx . ',' . ($b + $d) . ' ' . ($cx + $s) . ',' . ($y + $h + $d);
  $T = $cx . ',' . $y . ' ' . ($cx + $s) . ',' . ($y + $h) . ' ' . $cx . ',' . $b . ' ' . ($cx - $s) . ',' . ($y + $h);
  return $face($L, $left) . '<polygon points="' . $L . '" fill="url(#' . $p . 'ao)" opacity=".7"/>'
    . $face($R, $right) . '<polygon points="' . $R . '" fill="url(#' . $p . 'ao)" opacity=".35"/>'
    . $face($T, $top)
    . '<polygon points="' . $T . '" fill="url(#' . $p . 'ge)" opacity=".28"/>'
    . '<path d="M' . ($cx - $s) . ' ' . ($y + $h) . 'L' . $cx . ' ' . $y . 'L' . ($cx + $s) . ' ' . ($y + $h) . '" fill="none" stroke="#fff" stroke-opacity=".85" stroke-width="1.6" stroke-linejoin="round"/>'
    . '<path d="M' . $cx . ' ' . $b . 'L' . $cx . ' ' . ($b + $d) . '" stroke="#fff" stroke-opacity=".4" stroke-width="1.2"/>';
}

/** Communication: two glass chat bubbles, a chrome sphere and a ring. */
function art_chat(bool $eager = false): string { if ($i = _art_img('chat', 'artScene', $eager)) return $i; $p = 'ac'; return _a_svg('artScene', '0 0 520 440', $p,
  _a_glow($p, 260, 220, 230) . _a_ring($p, 262, 232, 190, 66, -24, 30) . _a_shadow($p, 260, 410, 150)
  . _a_slab($p, 70, 90, 250, 130, 34) . '<path d="M110 220l-6 46 52-36z" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".38"/>'
  . '<g fill="#fff" opacity=".85"><rect x="104" y="128" width="150" height="12" rx="6"/><rect x="104" y="156" width="190" height="12" rx="6" opacity=".6"/><rect x="104" y="184" width="110" height="12" rx="6" opacity=".4"/></g>'
  . _a_slab($p, 230, 230, 230, 120, 34) . '<path d="M420 350l8 42-48-30z" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".38"/>'
  . '<circle cx="290" cy="290" r="10" fill="#a5f3fc"/><circle cx="326" cy="290" r="10" fill="#c4b5fd"/><circle cx="362" cy="290" r="10" fill="#f5c2ff"/>'
  . _a_sph($p, 410, 120, 46, 'ch') . _a_sph($p, 90, 330, 22, 'sc') . _a_sph($p, 470, 260, 12, 'sm')); }

/** Podium: metallic 2-1-3 blocks with lit spheres. */
function art_podium(bool $eager = false): string { if ($i = _art_img('podium', 'artScene', $eager)) return $i; $p = 'ap'; return _a_svg('artScene', '0 0 560 430', $p,
  _a_glow($p, 280, 200, 250) . _a_shadow($p, 280, 398, 220)
  . _a_cube($p, 150, 232, 92, 'ch', 'vi', 'cy') . _a_cube($p, 280, 170, 92, 'ch', 'vi', 'cy') . _a_cube($p, 410, 262, 92, 'ch', 'vi', 'cy')
  . '<g font-family="Space Grotesk,Inter,sans-serif" font-weight="800" font-size="40" fill="#fff" opacity=".92" text-anchor="middle"><text x="150" y="346" transform="skewY(0)">2</text><text x="280" y="284">1</text><text x="410" y="376">3</text></g>'
  . _a_sph($p, 280, 118, 40, 'sm') . _a_sph($p, 150, 178, 28, 'sc') . _a_sph($p, 410, 208, 24, 'sp')
  . _a_ring($p, 280, 120, 74, 18, -14, 9, 'cy')); }

/** Trust: a glass shield with a check, orbited by a ring and spheres. */
function art_shield(bool $eager = false): string { if ($i = _art_img('shield', 'artScene', $eager)) return $i; $p = 'as'; return _a_svg('artScene', '0 0 520 440', $p,
  _a_glow($p, 260, 215, 220) . _a_shadow($p, 260, 408, 130) . _a_ring($p, 260, 230, 200, 60, 22, 24, 'cy')
  . '<path d="M260 50l124 44v104c0 86-52 140-124 172-72-32-124-86-124-172V94z" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".45" stroke-width="2"/>'
  . '<path d="M260 82l94 34v86c0 66-38 108-94 134-56-26-94-68-94-134v-86z" fill="url(#' . $p . 'vi)" opacity=".92"/>'
  . '<path d="M212 214l34 34 66-72" fill="none" stroke="#fff" stroke-width="18" stroke-linecap="round" stroke-linejoin="round"/>'
  . _a_sph($p, 410, 90, 30, 'sc') . _a_sph($p, 100, 320, 20, 'sm') . _a_sph($p, 440, 330, 12, 'sp')); }

/** Ecosystem: a chrome hub connected to orbiting nodes. */
function art_network(bool $eager = false): string { if ($i = _art_img('network', 'artScene', $eager)) return $i; $p = 'an'; $pts = [[90, 130], [430, 110], [70, 300], [450, 300], [260, 60], [260, 390]];
  $lines = ''; $nodes = ''; $gs = ['sp', 'sc', 'sm', 'sp', 'sc', 'sm'];
  foreach ($pts as $i => [$x, $y]) { $lines .= '<path d="M260 225L' . $x . ' ' . $y . '" stroke="url(#' . $p . 'cy)" stroke-width="3" stroke-dasharray="3 9" stroke-linecap="round" opacity=".8"/>'; $nodes .= _a_slab($p, $x - 34, $y - 34, 68, 68, 22) . _a_sph($p, $x, $y, 17, $gs[$i]); }
  return _a_svg('artScene', '0 0 520 450', $p, _a_glow($p, 260, 225, 240) . _a_ring($p, 260, 225, 200, 80, -20, 4, 'vi') . $lines . _a_shadow($p, 260, 428, 120) . $nodes . _a_sph($p, 260, 225, 64, 'ch') . '<circle cx="260" cy="225" r="30" fill="url(#' . $p . 'vi)"/>'); }

/** Method: stacked glass layers (evidence levels). */
function art_layers(bool $eager = false): string { if ($i = _art_img('layers', 'artScene', $eager)) return $i; $p = 'al'; $o = _a_glow($p, 260, 210, 230) . _a_shadow($p, 260, 400, 160);
  foreach ([[300, 'cy'], [236, 'vi'], [172, 'ch']] as [$y, $g]) { $o .= '<g><polygon points="260,' . $y . ' 430,' . ($y + 62) . ' 260,' . ($y + 124) . ' 90,' . ($y + 62) . '" fill="url(#' . $p . $g . ')" opacity=".9"/><polygon points="260,' . $y . ' 430,' . ($y + 62) . ' 260,' . ($y + 124) . ' 90,' . ($y + 62) . '" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".5"/></g>'; }
  $o .= '<polygon points="260,108 430,170 260,232 90,170" fill="url(#' . $p . 'gl)" stroke="#fff" stroke-opacity=".6" stroke-width="2"/>' . _a_sph($p, 260, 150, 30, 'sc') . _a_sph($p, 410, 90, 16, 'sm');
  return _a_svg('artScene', '0 0 520 430', $p, $o); }

/** Discovery: a glass lens with a sphere inside. */
function art_lens(bool $eager = false): string { if ($i = _art_img('lens', 'artScene', $eager)) return $i; $p = 'ae'; return _a_svg('artScene', '0 0 520 440', $p,
  _a_glow($p, 240, 210, 230) . _a_shadow($p, 260, 410, 140)
  . '<circle cx="230" cy="200" r="130" fill="url(#' . $p . 'gl)" stroke="url(#' . $p . 'ch)" stroke-width="26"/>'
  . '<path d="M330 300l92 96" stroke="url(#' . $p . 'ch)" stroke-width="40" stroke-linecap="round"/><path d="M330 300l92 96" stroke="#fff" stroke-opacity=".35" stroke-width="6" stroke-linecap="round"/>'
  . _a_sph($p, 215, 190, 52, 'sp') . _a_sph($p, 290, 130, 18, 'sc') . _a_sph($p, 160, 255, 14, 'sm')
  . _a_ring($p, 230, 200, 170, 44, 18, 6, 'cy')); }

/** Guided steps: ascending glass blocks with a sphere on the top step. */
function art_steps(bool $eager = false): string { if ($i = _art_img('steps', 'artScene', $eager)) return $i; $p = 'at'; return _a_svg('artScene', '0 0 520 420', $p,
  _a_glow($p, 270, 200, 230) . _a_shadow($p, 270, 392, 200)
  . _a_cube($p, 110, 250, 72, 'ch', 'cy', 'vi') . _a_cube($p, 220, 200, 72, 'ch', 'cy', 'vi') . _a_cube($p, 330, 150, 72, 'ch', 'cy', 'vi') . _a_cube($p, 440, 100, 72, 'ch', 'cy', 'vi')
  . _a_sph($p, 440, 62, 30, 'sm') . '<path d="M110 232L220 182L330 132L440 82" stroke="#fff" stroke-opacity=".5" stroke-width="3" stroke-dasharray="4 10" fill="none" stroke-linecap="round"/>'); }

/** Compass for problem-based discovery. */
function art_compass(bool $eager = false): string { if ($i = _art_img('compass', 'artScene', $eager)) return $i; $p = 'ak'; return _a_svg('artScene', '0 0 520 440', $p,
  _a_glow($p, 260, 215, 220) . _a_shadow($p, 260, 410, 130)
  . '<circle cx="260" cy="215" r="150" fill="url(#' . $p . 'gl)" stroke="url(#' . $p . 'ch)" stroke-width="22"/><circle cx="260" cy="215" r="112" fill="none" stroke="#fff" stroke-opacity=".3" stroke-dasharray="2 10" stroke-width="4"/>'
  . '<polygon points="260,100 292,215 260,330 228,215" fill="url(#' . $p . 'vi)" transform="rotate(38 260 215)"/><polygon points="260,100 292,215 228,215" fill="#fff" opacity=".55" transform="rotate(38 260 215)"/>'
  . _a_sph($p, 260, 215, 20, 'ch') . _a_sph($p, 420, 100, 22, 'sc') . _a_sph($p, 100, 330, 16, 'sm')); }

/** Mail / confirmation: envelope slab with a check sphere. */
function art_mail(bool $eager = false): string { if ($i = _art_img('mail', 'artScene', $eager)) return $i; $p = 'am'; return _a_svg('artScene', '0 0 520 400', $p,
  _a_glow($p, 260, 200, 220) . _a_shadow($p, 260, 372, 150) . _a_ring($p, 260, 200, 200, 60, -18, 18, 'cy')
  . _a_slab($p, 90, 110, 340, 220, 30) . '<path d="M100 132l160 110 160-110" fill="none" stroke="#fff" stroke-opacity=".7" stroke-width="5" stroke-linejoin="round"/>'
  . _a_sph($p, 410, 120, 48, 'sc') . '<path d="M388 120l16 16 30-34" fill="none" stroke="#fff" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>' . _a_sph($p, 90, 340, 16, 'sm')); }

/** Page not found: a lit orb with a broken ring. */
function art_lost(bool $eager = false): string { if ($i = _art_img('lost', 'artScene', $eager)) return $i; $p = 'ao'; return _a_svg('artScene', '0 0 520 400', $p,
  _a_glow($p, 260, 190, 220) . _a_shadow($p, 260, 372, 120)
  . '<path d="M110 200a150 60 0 0 1 130-58" fill="none" stroke="url(#' . $p . 'vi)" stroke-width="26" stroke-linecap="round"/><path d="M300 140a150 60 0 0 1 112 62" fill="none" stroke="url(#' . $p . 'cy)" stroke-width="26" stroke-linecap="round"/><path d="M130 240a150 60 0 0 0 270 6" fill="none" stroke="url(#' . $p . 'vi)" stroke-width="26" stroke-linecap="round" opacity=".7"/>'
  . _a_sph($p, 260, 190, 70, 'sp') . _a_sph($p, 410, 90, 20, 'sc') . _a_sph($p, 100, 300, 14, 'sm') . '<text x="260" y="206" text-anchor="middle" font-family="Space Grotesk,Inter,sans-serif" font-weight="800" font-size="46" fill="#fff" opacity=".9">?</text>'); }

/** Calm document stack for legal and policy pages. */
function art_doc(bool $eager = false): string { if ($i = _art_img('doc', 'artScene', $eager)) return $i; $p = 'ad'; return _a_svg('artScene', '0 0 520 400', $p,
  _a_glow($p, 260, 200, 210) . _a_shadow($p, 260, 372, 130)
  . _a_slab($p, 150, 70, 220, 270, 26, 'rotate(-8 260 200)') . _a_slab($p, 150, 70, 220, 270, 26, 'rotate(6 260 200)')
  . '<g fill="#fff" opacity=".8"><rect x="190" y="130" width="140" height="12" rx="6"/><rect x="190" y="164" width="120" height="12" rx="6" opacity=".6"/><rect x="190" y="198" width="140" height="12" rx="6" opacity=".6"/><rect x="190" y="232" width="90" height="12" rx="6" opacity=".4"/></g>'
  . _a_sph($p, 380, 110, 30, 'sc') . _a_sph($p, 130, 320, 18, 'sm')); }

/** Hero artwork: a woven chrome-and-iridescent ring knot with lit spheres. Decorative. */
function art_orb(bool $eager = false): string { if ($i = _art_img('hero_knot', 'artOrb', $eager)) return $i;
  $p = 'ao2';
  // weave: draw ring A, ring B, then redraw a clipped half of A on top so the rings interlock
  return _a_svg('artOrb', '0 0 520 460', $p,
    _a_glow($p, 260, 230, 250) . _a_shadow($p, 260, 428, 150)
    . _a_ring($p, 260, 232, 176, 82, -28, 48, 'vi')
    . _a_ring($p, 260, 232, 156, 74, 34, 42, 'cy')
    . '<clipPath id="' . $p . 'wv"><path clip-rule="evenodd" d="M0 0H260V232H0ZM260 232H520V460H260ZM378 232a118 118 0 1 0 -236 0a118 118 0 1 0 236 0Z"/></clipPath>'
    . '<g clip-path="url(#' . $p . 'wv)">' . _a_ring($p, 260, 232, 176, 82, -28, 48, 'vi') . '</g>'
    . _a_ring($p, 260, 232, 104, 104, 0, 30, 'mg')
    . _a_ring($p, 260, 232, 214, 40, 12, 7, 'ch')
    . _a_sph($p, 398, 98, 32, 'sc') . _a_sph($p, 128, 354, 18, 'sm') . _a_sph($p, 436, 330, 10, 'sp') . _a_sph($p, 82, 138, 9, 'sc'));
}

/** Business hero artwork: a glossy cube-robot on a neon-edged platform with a glass gate and floating chips. Decorative. */
function art_agent(bool $eager = false): string { if ($i = _art_img('agent', 'artAgent', $eager)) return $i;
  $p = 'aa2';
  $plat = '280,190 540,318 280,446 20,318';
  return _a_svg('artAgent', '0 0 560 480', $p,
    _a_glow($p, 280, 250, 270)
    // platform: dark glass slab, neon rim, grid
    . '<polygon points="' . $plat . '" fill="#0c0f3f"/><polygon points="20,318 280,446 280,462 20,334" fill="#070a2e"/><polygon points="540,318 280,446 280,462 540,334" fill="#0a0d38"/>'
    . '<polygon points="' . $plat . '" fill="url(#' . $p . 'gl)" opacity=".5"/>'
    . '<g stroke="#6d8bff" stroke-opacity=".28"><path d="M150 254L410 382M215 222L475 350M345 222L85 350M410 254L150 382M280 190V446"/></g>'
    . '<polygon points="' . $plat . '" fill="none" stroke="#22d3ee" stroke-width="2.5" stroke-opacity=".9" filter="url(#' . $p . 'sb)"/><polygon points="' . $plat . '" fill="none" stroke="#a5f3fc" stroke-width="1.2"/>'
    . '<ellipse cx="280" cy="326" rx="120" ry="40" fill="#22d3ee" opacity=".18" filter="url(#' . $p . 'bl)"/>'
    // glass gate
    . '<polygon points="82,306 148,274 148,214 82,246" fill="url(#' . $p . 'gl)" stroke="#a5f3fc" stroke-opacity=".7"/><polygon points="82,306 148,274 148,214 82,246" fill="url(#' . $p . 'ge)" opacity=".18"/>'
    . '<rect x="76" y="238" width="10" height="72" rx="5" fill="url(#' . $p . 'ch)"/><rect x="144" y="206" width="10" height="72" rx="5" fill="url(#' . $p . 'ch)"/>'
    // robot: body block
    . _a_shadow($p, 280, 396, 74)
    . _a_cube($p, 280, 262, 56, 'ch', 'vi', 'cy')
    // head: rounded glossy shell with dark visor and glowing eyes
    . '<g filter="url(#' . $p . 'ds)"><rect x="214" y="150" width="132" height="104" rx="34" fill="url(#' . $p . 'ch)"/></g>'
    . '<rect x="214" y="150" width="132" height="104" rx="34" fill="url(#' . $p . 'ge)" opacity=".22"/>'
    . '<rect x="226" y="166" width="108" height="64" rx="26" fill="#0a0b30"/><rect x="226" y="166" width="108" height="64" rx="26" fill="url(#' . $p . 'gl)" opacity=".35"/>'
    . '<ellipse cx="252" cy="200" rx="15" ry="19" fill="#ff4fd8" opacity=".35" filter="url(#' . $p . 'sb)"/><ellipse cx="308" cy="200" rx="15" ry="19" fill="#ff4fd8" opacity=".35" filter="url(#' . $p . 'sb)"/>'
    . '<rect x="244" y="186" width="16" height="28" rx="8" fill="#ff7be8"/><rect x="300" y="186" width="16" height="28" rx="8" fill="#ff7be8"/><rect x="249" y="190" width="5" height="9" rx="2.5" fill="#fff"/><rect x="305" y="190" width="5" height="9" rx="2.5" fill="#fff"/>'
    . '<path d="M240 168Q280 156 322 168" fill="none" stroke="#fff" stroke-opacity=".5" stroke-width="2" stroke-linecap="round"/>'
    // ears and antenna
    . '<rect x="204" y="186" width="14" height="36" rx="7" fill="url(#' . $p . 'vi)"/><rect x="342" y="186" width="14" height="36" rx="7" fill="url(#' . $p . 'vi)"/>'
    . '<rect x="276" y="122" width="8" height="32" rx="4" fill="url(#' . $p . 'ch)"/>' . _a_sph($p, 280, 116, 12, 'sm')
    // floating chips
    . _a_slab($p, 410, 150, 64, 64, 18, 'rotate(8 442 182)') . '<path d="M428 172l12 12 20-22" fill="none" stroke="#5eead4" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" transform="rotate(8 442 182)"/>'
    . _a_slab($p, 92, 118, 56, 56, 16, 'rotate(-10 120 146)') . '<path d="M106 132h28M106 146h28M106 160h16" stroke="#c4b5fd" stroke-width="5" stroke-linecap="round" transform="rotate(-10 120 146)"/>'
    . _a_sph($p, 480, 270, 14, 'sc') . _a_sph($p, 60, 200, 9, 'sm') . _a_sph($p, 498, 96, 8, 'sp'));
}

/** Render-only scenes (no inline SVG twin): they fall back to the closest SVG scene when the image is missing. */
function art_ads(bool $eager = false): string { return _art_img('ads', 'artScene', $eager) ?: art_podium(); }
function art_versus(bool $eager = false): string { return _art_img('versus', 'artScene', $eager) ?: art_podium(); }
function art_cubes(bool $eager = false): string { return _art_img('cubes', 'artScene', $eager) ?: art_layers(); }
function art_book(bool $eager = false): string { return _art_img('book', 'artScene', $eager) ?: art_lens(); }
function art_workflow(bool $eager = false): string { return _art_img('workflow', 'artScene', $eager) ?: art_network(); }

/** Generic hero scene chooser used by page_hero(). */
function art_scene(string $name, bool $eager = false): string {
  static $m = ['chat' => 'art_chat', 'podium' => 'art_podium', 'shield' => 'art_shield', 'network' => 'art_network', 'layers' => 'art_layers', 'lens' => 'art_lens', 'steps' => 'art_steps', 'compass' => 'art_compass', 'mail' => 'art_mail', 'lost' => 'art_lost', 'doc' => 'art_doc', 'orb' => 'art_orb', 'agent' => 'art_agent', 'ads' => 'art_ads', 'versus' => 'art_versus', 'cubes' => 'art_cubes', 'book' => 'art_book', 'workflow' => 'art_workflow'];
  return isset($m[$name]) ? ($m[$name])($eager) : '';
}
