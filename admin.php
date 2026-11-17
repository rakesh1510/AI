<?php
require_once __DIR__ . '/helpers.php';
$title = "Admin — AIHubpedia";
require_once __DIR__ . '/admin_auth.php';
admin_require('Admin');   // exits with a sign-in form (or a "disabled" notice) unless authenticated

$tiers = pricing_tiers();
$subs = read_json('submissions.json', []);

// Actions: mark_paid, approve, reject
$action = '';
$idx = -1;
if(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['admin_action'] ?? '') !== 'login'){
  if(!admin_csrf_ok()){ sec_log('admin_csrf_rejected'); http_response_code(403); exit('Security check failed.'); }
  if(($_POST['admin_action'] ?? '') === 'logout'){ admin_logout(); header('Location: /admin.php', true, 303); exit; }
  $action = (string)($_POST['admin_action'] ?? '');
  $idx = isset($_POST['i']) ? (int)$_POST['i'] : -1;
}

function featured_settings_for_plan($plan){
  $today = date('Y-m-d');
  if($plan === 'featured'){
    return ['featured_level'=>1, 'featured_until'=>date('Y-m-d', strtotime($today . ' +7 days'))];
  }
  if($plan === 'homepage'){
    return ['featured_level'=>2, 'featured_until'=>date('Y-m-d', strtotime($today . ' +14 days'))];
  }
  return ['featured_level'=>0, 'featured_until'=>''];
}

if($action && $idx >= 0 && $idx < count($subs)){
  if($action === 'mark_paid'){
    $subs[$idx]['payment_status'] = 'paid';
    write_json('submissions.json', $subs);
  }

  if($action === 'reject'){
    $subs[$idx]['status'] = 'rejected';
    write_json('submissions.json', $subs);
  }

  if($action === 'approve'){
    $s = $subs[$idx];
    $safeSite = sec_safe_external_url((string)($s['website'] ?? ''));
    if($safeSite === null){ header('Location: /admin.php?msg=bad_url', true, 303); exit; }
    $s['website'] = $safeSite;
    $plan = $s['plan'] ?? 'free';
    $price = (int)($s['price'] ?? 0);
    $paid_ok = ($price <= 0) || (($s['payment_status'] ?? '') === 'paid');

    if(!$paid_ok){
      header('Location: /admin.php?msg=need_payment', true, 303);
      exit;
    }

    $feat = featured_settings_for_plan($plan);

    $new = [
      'id' => strtolower(preg_replace('/[^a-z0-9]+/','-', $s['name'])),
      'name' => $s['name'],
      'category' => $s['category'],
      'short' => $s['short'],
      'description' => $s['short'],
      'pricing' => 'Varies',
      'pricing_type' => 'Varies',
      'website' => $s['website'],
      'tags' => [$s['category'], 'AI'],
      'features' => ['Fast onboarding','AI-assisted workflow','Export/share options (plan dependent)'],
      'pros' => ['Saves time','Easy to start','Good for iterative work'],
      'cons' => ['Quality depends on input','Pricing/features vary by plan'],
      'use_cases' => ['Create drafts faster','Automate repetitive tasks','Improve consistency'],
      'logo' => '',
      'affiliate' => '',
      'seo_title' => $s['name'] . ' — AI Tool',
      'seo_description' => $s['short'],
      'added' => date('Y-m-d'),
      'featured_level' => $feat['featured_level'],
      'featured_until' => $feat['featured_until']
    ];

    $tools = read_json('tools.json', ['tools'=>[],'categories'=>[]]);
    $tools['tools'][] = $new;
    write_json('tools.json', $tools);

    $subs[$idx]['status'] = 'approved';
    write_json('submissions.json', $subs);
  }

  sec_log('admin_action', ['action' => $action, 'index' => $idx]);
  header('Location: /admin.php', true, 303);
  exit;
}

require __DIR__ . '/layout_top.php';
$msg = $_GET['msg'] ?? '';
if($msg === 'bad_url'){
  echo "<div class='card'>❌ The submitted website address is not a valid public http(s) URL, so it was not approved.</div>";
}
if($msg === 'need_payment'){
  echo "<div class='card'>❌ This submission requires payment. First click <b>Mark paid</b>, then approve.</div>";
}
?>

<h1>Admin</h1>
<form method="post" style="float:right"><?= admin_csrf_field() ?><input type="hidden" name="admin_action" value="logout"><button class="btn secondary sm">Sign out</button></form>
<p class="small">Approve submissions, mark payments, and manage paid placements.</p>

<div class="card" style="background:rgba(255,255,255,.03)">
  <h2 style="margin-top:0">Pricing tiers</h2>
  <ul>
    <?php foreach($tiers as $k=>$t): ?>
      <li><b><?= h($t['label'] ?? $k) ?></b> — $<?= (int)($t['price'] ?? 0) ?> • <?= h($t['desc'] ?? '') ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="small">Edit PayPal/Stripe URLs and pricing in <code>data/site.json</code>.</p>
</div>

<h2>Tool submissions</h2>
<div class="grid">
<?php foreach($subs as $i=>$s):
  $plan = $s['plan'] ?? 'free';
  $tier = $tiers[$plan] ?? ['label'=>$plan,'price'=>0];
  $price = (int)($s['price'] ?? ($tier['price'] ?? 0));
  $payStatus = $s['payment_status'] ?? ($price>0 ? 'unpaid' : 'not_required');
?>
  <div class="card">
    <h3 style="margin:0 0 6px"><?= h($s['name'] ?? '') ?></h3>
    <div class="small">
      Category: <?= h($s['category'] ?? '') ?> •
      Plan: <b><?= h($tier['label'] ?? $plan) ?></b> •
      Price: <b>$<?= (int)$price ?></b> •
      Payment: <b><?= h($payStatus) ?></b> •
      Status: <b><?= h($s['status'] ?? '') ?></b>
    </div>
    <p style="margin-top:10px"><?= h($s['short'] ?? '') ?></p>
    <div class="small"><?= h($s['website'] ?? '') ?></div>

    <div class="actions">
      <?php if(($s['status'] ?? '') === 'pending'): ?>
        <?php if($price > 0 && $payStatus !== 'paid'): ?>
          <form method="post" style="display:inline"><?= admin_csrf_field() ?><input type="hidden" name="admin_action" value="mark_paid"><input type="hidden" name="i" value="<?= (int)$i ?>"><button class="btn">Mark paid</button></form>
        <?php endif; ?>
        <form method="post" style="display:inline"><?= admin_csrf_field() ?><input type="hidden" name="admin_action" value="approve"><input type="hidden" name="i" value="<?= (int)$i ?>"><button class="btn secondary">Approve</button></form>
        <form method="post" style="display:inline"><?= admin_csrf_field() ?><input type="hidden" name="admin_action" value="reject"><input type="hidden" name="i" value="<?= (int)$i ?>"><button class="btn secondary">Reject</button></form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
