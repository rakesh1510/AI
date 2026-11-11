<?php
/**
 * AIHubpedia Admin Management Portal
 *
 * Secure administration panel:
 * - Session-based authentication with bcrypt password verification
 * - Rate-limited login attempts with IP throttling & verified reverse proxy support
 * - Strict CSRF protection on all state-changing actions
 * - Stable, unique submission IDs for reliable dual-layer storage synchronization
 * - Transactional database writes with explicit error handling (zero silent failures)
 * - Zero plaintext credentials in URLs or responses
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/src/Auth.php';

Auth::startSession();

$title = "Admin Portal — AIHubpedia";
$loginError = '';
$flashMessage = '';
$flashType = 'info';

// ==============================================================================
// 1. AUTHENTICATION CONTROLLER (LOGIN & LOGOUT)
// ==============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Admin Login Attempt
    if (isset($_POST['admin_login_action'])) {
        if (!csrf_validate()) {
            $loginError = 'Security verification failed (CSRF token mismatch). Please refresh and try again.';
        } else {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            $authResult = Auth::adminLogin($username, $password);
            if ($authResult['success']) {
                header('Location: admin.php');
                exit;
            } else {
                $loginError = $authResult['error'] ?? 'Authentication failed.';
            }
        }
    }

    // Admin Logout Attempt
    if (isset($_POST['admin_logout_action'])) {
        if (csrf_validate()) {
            Auth::adminLogout();
        }
        header('Location: admin.php');
        exit;
    }
}

// ==============================================================================
// 2. UNAUTHENTICATED VIEW: SECURE LOGIN FORM
// ==============================================================================

if (!Auth::isAdminLoggedIn()) {
    $rateLimit = Auth::checkRateLimit();
    require __DIR__ . '/layout_top.php';
    ?>
    <style>
      .adminLoginWrap {
        max-width: 440px;
        margin: 40px auto;
        padding: 0 16px;
      }
      .adminLoginCard {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 32px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
      }
      .adminLoginHeader {
        text-align: center;
        margin-bottom: 24px;
      }
      .adminLoginHeader h1 {
        font-size: 24px;
        font-weight: 800;
        margin: 0 0 8px;
        color: #111827;
      }
      .adminLoginHeader p {
        color: #6b7280;
        font-size: 14px;
        margin: 0;
      }
      .adminFormGroup {
        margin-bottom: 18px;
      }
      .adminFormGroup label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 6px;
      }
      .adminInput {
        width: 100%;
        padding: 10px 14px;
        border-radius: 10px;
        border: 1px solid #d1d5db;
        font-size: 14px;
        box-sizing: border-box;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
      }
      .adminInput:focus {
        outline: none;
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
      }
      .adminAlert {
        padding: 12px 14px;
        border-radius: 10px;
        font-size: 13px;
        margin-bottom: 18px;
        line-height: 1.4;
      }
      .adminAlertError {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
      }
      .adminAlertWarning {
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
      }
      .adminSubmitBtn {
        width: 100%;
        padding: 12px;
        background: #4f46e5;
        color: #ffffff;
        font-weight: 600;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-size: 15px;
        transition: background 0.15s ease;
      }
      .adminSubmitBtn:hover:not(:disabled) {
        background: #4338ca;
      }
      .adminSubmitBtn:disabled {
        background: #9ca3af;
        cursor: not-allowed;
      }
      .securityNotice {
        margin-top: 20px;
        font-size: 12px;
        color: #9ca3af;
        text-align: center;
        line-height: 1.4;
      }
    </style>

    <div class="adminLoginWrap">
      <div class="adminLoginCard">
        <div class="adminLoginHeader">
          <h1>Admin Portal</h1>
          <p>Sign in to manage AIHubpedia tool submissions</p>
        </div>

        <?php if ($loginError !== ''): ?>
          <div class="adminAlert adminAlertError">
            ⚠️ <?= h($loginError) ?>
          </div>
        <?php endif; ?>

        <?php if ($rateLimit['is_blocked']): ?>
          <div class="adminAlert adminAlertWarning">
            🔒 IP temporarily locked due to repeated failed attempts. Please try again in <?= ceil($rateLimit['remaining_seconds'] / 60) ?> minute(s).
          </div>
        <?php endif; ?>

        <form method="post" action="admin.php">
          <?= csrf_field() ?>
          <input type="hidden" name="admin_login_action" value="1">

          <div class="adminFormGroup">
            <label for="adminUser">Username</label>
            <input
              id="adminUser"
              class="adminInput"
              type="text"
              name="username"
              required
              autocomplete="username"
              placeholder="e.g. admin"
              <?= $rateLimit['is_blocked'] ? 'disabled' : '' ?>
            >
          </div>

          <div class="adminFormGroup">
            <label for="adminPass">Password</label>
            <input
              id="adminPass"
              class="adminInput"
              type="password"
              name="password"
              required
              autocomplete="current-password"
              placeholder="Enter admin password"
              <?= $rateLimit['is_blocked'] ? 'disabled' : '' ?>
            >
          </div>

          <button
            class="adminSubmitBtn"
            type="submit"
            <?= $rateLimit['is_blocked'] ? 'disabled' : '' ?>
          >
            <?= $rateLimit['is_blocked'] ? 'Locked (Rate Limited)' : 'Authenticate & Sign In' ?>
          </button>
        </form>

        <div class="securityNotice">
          🛡️ Protected by session encryption, bcrypt hashing, and rate limiting.
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/layout_bottom.php';
    exit;
}

// ==============================================================================
// 3. AUTHENTICATED ACTIONS (POST ONLY + CSRF VALIDATED + STABLE IDs)
// ==============================================================================

$tiers = pricing_tiers();

function featured_settings_for_plan(string $plan): array {
    $today = date('Y-m-d');
    if ($plan === 'featured') {
        return ['featured_level' => 1, 'featured_until' => date('Y-m-d', strtotime($today . ' +7 days'))];
    }
    if ($plan === 'homepage') {
        return ['featured_level' => 2, 'featured_until' => date('Y-m-d', strtotime($today . ' +14 days'))];
    }
    return ['featured_level' => 0, 'featured_until' => ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $subId = trim((string)($_POST['submission_id'] ?? ''));

    if ($action !== '' && !csrf_validate()) {
        header('Location: admin.php?msg=csrf_error');
        exit;
    }

    if ($action && $subId !== '') {
        $sub = find_submission_dual($subId);

        if (!$sub) {
            header('Location: admin.php?msg=not_found');
            exit;
        }

        $subName = (string)($sub['name'] ?? '');

        if ($action === 'mark_paid') {
            $paidRes = update_submission_status_dual($subId, $sub['status'] ?? 'pending', 'paid');
            if (!$paidRes['success']) {
                $err = urlencode($paidRes['error'] ?? 'Failed updating payment status.');
                header("Location: admin.php?msg=db_error&err={$err}");
                exit;
            }
            header('Location: admin.php?msg=marked_paid');
            exit;
        }

        if ($action === 'reject') {
            $rejectRes = update_submission_status_dual($subId, 'rejected', null);
            if (!$rejectRes['success']) {
                $err = urlencode($rejectRes['error'] ?? 'Failed rejecting submission.');
                header("Location: admin.php?msg=db_error&err={$err}");
                exit;
            }
            header('Location: admin.php?msg=rejected');
            exit;
        }

        if ($action === 'approve') {
            $plan = (string)($sub['plan'] ?? 'free');
            $price = (int)($sub['price'] ?? 0);
            $paidOk = ($price <= 0) || (($sub['payment_status'] ?? '') === 'paid');

            if (!$paidOk) {
                header('Location: admin.php?msg=need_payment');
                exit;
            }

            $feat = featured_settings_for_plan($plan);
            $cleanSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $subName), '-'));
            if ($cleanSlug === '') {
                $cleanSlug = 'tool-' . time();
            }

            $shortDesc = (string)($sub['short'] ?? $sub['short_desc'] ?? '');
            $category = (string)($sub['category'] ?? 'Other');

            $newTool = [
                'id' => $cleanSlug,
                'name' => $subName,
                'category' => $category,
                'short' => $shortDesc,
                'description' => $shortDesc,
                'pricing' => 'Varies',
                'pricing_type' => 'Varies',
                'website' => (string)($sub['website'] ?? ''),
                'tags' => [$category, 'AI'],
                'features' => ['Fast onboarding', 'AI-assisted workflow', 'Export and share options'],
                'pros' => ['Saves time', 'Easy to start', 'Good for iterative work'],
                'cons' => ['Quality depends on input', 'Pricing and features vary by plan'],
                'use_cases' => ['Create drafts faster', 'Automate repetitive tasks', 'Improve workflow efficiency'],
                'logo' => '',
                'affiliate' => '',
                'seo_title' => $subName . ' — AI Tool',
                'seo_description' => $shortDesc,
                'added' => date('Y-m-d'),
                'featured_level' => (int)$feat['featured_level'],
                'featured_until' => (string)$feat['featured_until']
            ];

            // Transactional Dual Sync to MySQL and JSON
            $saveResult = save_tool_dual($newTool);
            if (!$saveResult['success']) {
                $err = urlencode($saveResult['error'] ?? 'Database save failed.');
                header("Location: admin.php?msg=db_error&err={$err}");
                exit;
            }

            $statusRes = update_submission_status_dual($subId, 'approved', null);
            if (!$statusRes['success']) {
                $err = urlencode($statusRes['error'] ?? 'Failed updating submission status.');
                header("Location: admin.php?msg=db_error&err={$err}");
                exit;
            }

            header('Location: admin.php?msg=approved');
            exit;
        }
    }
}

// Handle flash messages
$msgCode = (string)($_GET['msg'] ?? '');
$errMsg = (string)($_GET['err'] ?? '');
$flashMap = [
    'approved' => ['Tool approved and published to MySQL & JSON catalog successfully.', 'success'],
    'rejected' => ['Submission has been marked as rejected.', 'info'],
    'marked_paid' => ['Submission marked as paid.', 'success'],
    'need_payment' => ['This submission requires payment before it can be approved.', 'error'],
    'not_found' => ['Submission record not found.', 'error'],
    'csrf_error' => ['Security verification failed (CSRF token mismatch). Please retry.', 'error'],
    'db_error' => ['Database write failed: ' . $errMsg, 'error'],
];

if (isset($flashMap[$msgCode])) {
    [$flashMessage, $flashType] = $flashMap[$msgCode];
}

// Refresh submissions after state changes
$subs = load_submissions_dual();
$totalSubs = count($subs);
$pendingCount = 0;
$approvedCount = 0;
$paidCount = 0;

foreach ($subs as $s) {
    $st = $s['status'] ?? 'pending';
    $ps = $s['payment_status'] ?? 'not_required';
    if ($st === 'pending') {
        $pendingCount++;
    }
    if ($st === 'approved') {
        $approvedCount++;
    }
    if ($ps === 'paid') {
        $paidCount++;
    }
}

require __DIR__ . '/layout_top.php';
?>

<style>
  .adminDashboardHeader {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e5e7eb;
  }
  .adminStatsGrid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
  }
  .adminStatCard {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
  }
  .adminStatLabel {
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .adminStatNum {
    font-size: 28px;
    font-weight: 800;
    color: #111827;
    margin-top: 6px;
  }
  .submissionCard {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 16px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    transition: box-shadow 0.15s ease;
  }
  .submissionCard:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
  }
  .submissionHeader {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 12px;
  }
  .statusBadge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
  }
  .statusPending { background: #fef3c7; color: #92400e; }
  .statusApproved { background: #dcfce7; color: #166534; }
  .statusRejected { background: #fee2e2; color: #991b1b; }
  .statusPaid { background: #e0e7ff; color: #3730a3; }
  .statusUnpaid { background: #f3f4f6; color: #4b5563; }
  .submissionActions {
    display: flex;
    gap: 8px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px dashed #e5e7eb;
    flex-wrap: wrap;
  }
  .actionBtn {
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .btnApprove { background: #16a34a; color: #ffffff; }
  .btnApprove:hover { background: #15803d; }
  .btnReject { background: #ffffff; border-color: #d1d5db; color: #dc2626; }
  .btnReject:hover { background: #fee2e2; }
  .btnPaid { background: #4f46e5; color: #ffffff; }
  .btnPaid:hover { background: #4338ca; }
  .btnLogout {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .btnLogout:hover { background: #e5e7eb; }
  .alertOk { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
  .alertErr { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
</style>

<div class="adminWrap" style="max-width:1100px;margin:30px auto;padding:0 16px">
  <div class="adminDashboardHeader">
    <div>
      <h1 style="margin:0 0 6px;font-size:26px;font-weight:800;color:#111827">Admin Submissions Portal</h1>
      <p style="margin:0;color:#6b7280;font-size:14px">
        Logged in as <b><?= h(Auth::getAdminUsername()) ?></b> • Session Active
      </p>
    </div>
    <form method="post" action="admin.php" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="admin_logout_action" value="1">
      <button class="btnLogout" type="submit">Log Out</button>
    </form>
  </div>

  <?php if ($flashMessage !== ''): ?>
    <div class="adminAlert <?= $flashType === 'success' ? 'alertOk' : ($flashType === 'error' ? 'alertErr' : 'card') ?>" style="margin-bottom:20px">
      <?= $flashType === 'success' ? '✅' : ($flashType === 'error' ? '❌' : 'ℹ️') ?> <?= h($flashMessage) ?>
    </div>
  <?php endif; ?>

  <div class="adminStatsGrid">
    <div class="adminStatCard">
      <div class="adminStatLabel">Pending Submissions</div>
      <div class="adminStatNum" style="color:#d97706"><?= (int)$pendingCount ?></div>
    </div>
    <div class="adminStatCard">
      <div class="adminStatLabel">Approved Submissions</div>
      <div class="adminStatNum" style="color:#16a34a"><?= (int)$approvedCount ?></div>
    </div>
    <div class="adminStatCard">
      <div class="adminStatLabel">Paid Placements</div>
      <div class="adminStatNum" style="color:#4f46e5"><?= (int)$paidCount ?></div>
    </div>
    <div class="adminStatCard">
      <div class="adminStatLabel">Total Submissions</div>
      <div class="adminStatNum"><?= (int)$totalSubs ?></div>
    </div>
  </div>

  <div class="card" style="margin-bottom:28px">
    <h2 style="margin-top:0;font-size:18px;font-weight:700">Listing Plans & Pricing Tiers</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:12px">
      <?php foreach ($tiers as $k => $t): ?>
        <div style="background:rgba(0,0,0,0.02);border:1px solid #e5e7eb;border-radius:10px;padding:12px">
          <div style="font-weight:700;color:#111827"><?= h($t['label'] ?? $k) ?></div>
          <div style="font-size:18px;font-weight:800;color:#4f46e5;margin:4px 0">$<?= (int)($t['price'] ?? 0) ?></div>
          <div style="font-size:12px;color:#6b7280"><?= h($t['desc'] ?? '') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <h2 style="font-size:20px;font-weight:700;margin-bottom:16px">Tool Submissions Queue</h2>

  <?php if (empty($subs)): ?>
    <div class="card" style="text-align:center;padding:40px 20px;color:#6b7280">
      🎉 No submissions in queue.
    </div>
  <?php else: ?>
    <?php foreach ($subs as $s):
      $subId = (string)($s['id'] ?? '');
      $plan = (string)($s['plan'] ?? 'free');
      $tier = $tiers[$plan] ?? ['label' => $plan, 'price' => 0];
      $price = (int)($s['price'] ?? ($tier['price'] ?? 0));
      $payStatus = (string)($s['payment_status'] ?? ($price > 0 ? 'unpaid' : 'not_required'));
      $status = (string)($s['status'] ?? 'pending');
      $statusClass = $status === 'approved' ? 'statusApproved' : ($status === 'rejected' ? 'statusRejected' : 'statusPending');
      $payClass = $payStatus === 'paid' ? 'statusPaid' : 'statusUnpaid';
    ?>
      <div class="submissionCard">
        <div class="submissionHeader">
          <div>
            <h3 style="margin:0 0 6px;font-size:18px;font-weight:700;color:#111827"><?= h($s['name'] ?? 'Unnamed Tool') ?></h3>
            <div style="font-size:13px;color:#6b7280">
              ID: <code><?= h($subId) ?></code> •
              Category: <b><?= h($s['category'] ?? 'General') ?></b> •
              Plan: <b><?= h($tier['label'] ?? $plan) ?></b> ($<?= (int)$price ?>) •
              Submitted: <?= h($s['created_at'] ?? 'Recently') ?>
            </div>
          </div>
          <div style="display:flex;gap:6px;align-items:center">
            <span class="statusBadge <?= $statusClass ?>">Status: <?= h(ucfirst($status)) ?></span>
            <span class="statusBadge <?= $payClass ?>">Payment: <?= h(ucfirst($payStatus)) ?></span>
          </div>
        </div>

        <p style="margin:12px 0 8px;font-size:14px;color:#374151;line-height:1.5">
          <?= h($s['short'] ?? $s['short_desc'] ?? '') ?>
        </p>

        <div style="font-size:13px">
          🌐 Website: <a href="<?= h($s['website'] ?? '#') ?>" target="_blank" rel="noopener noreferrer" style="color:#4f46e5;text-decoration:none"><?= h($s['website'] ?? '') ?></a>
        </div>

        <?php if ($status === 'pending'): ?>
          <div class="submissionActions">
            <?php if ($price > 0 && $payStatus !== 'paid'): ?>
              <form method="post" action="admin.php" style="margin:0;display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="submission_id" value="<?= h($subId) ?>">
                <button class="actionBtn btnPaid" type="submit">Mark Paid ($<?= (int)$price ?>)</button>
              </form>
            <?php endif; ?>

            <form method="post" action="admin.php" style="margin:0;display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="submission_id" value="<?= h($subId) ?>">
              <button class="actionBtn btnApprove" type="submit">Approve & Publish Tool</button>
            </form>

            <form method="post" action="admin.php" style="margin:0;display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="submission_id" value="<?= h($subId) ?>">
              <button class="actionBtn btnReject" type="submit">Reject</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/layout_bottom.php'; ?>
