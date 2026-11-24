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
