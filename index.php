<?php
require __DIR__ . '/lib/bootstrap.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}

// Exit impersonation: return to the super-admin session.
if (isset($_GET['stop_impersonate'])) {
    if (!empty($_SESSION['impersonator'])) {
        $_SESSION['auth'] = $_SESSION['impersonator'];
        unset($_SESSION['impersonator']);
        $_SESSION['auth_checked_at'] = time();
    }
    header('Location: ./');
    exit;
}

// One-time "login as user" token (super-admin generated). Opened in an incognito window,
// it signs that browser in as the user — no password, super-admin's own session untouched.
if (isset($_GET['as'])) {
    $asUser = impersonate_token_consume((string)$_GET['as']);
    if ($asUser) {
        session_regenerate_id(true);
        $_SESSION['auth'] = $asUser;
        $_SESSION['auth_checked_at'] = time();
        $_SESSION['tries'] = 0;
        unset($_SESSION['2fa'], $_SESSION['demo']);
    }
    header('Location: ./');
    exit;
}

/** Finalize a successful login and redirect in. */
function finish_login(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = $u;
    $_SESSION['auth_checked_at'] = time(); // login itself is a fresh DB validation
    $_SESSION['tries'] = 0;
    unset($_SESSION['2fa'], $_SESSION['demo']);
    login_throttle_clear();
    header('Location: ./');
    exit;
}

$loginError = '';
$otpStage   = false; // are we asking for the 6-digit code?

// A pending 2FA challenge (password already verified) survives a reload for 5 minutes.
if (!empty($_SESSION['2fa']) && ($_SESSION['2fa']['at'] ?? 0) >= time() - 300) {
    $otpStage = true;
} else {
    unset($_SESSION['2fa']);
}

// ---- Stage 2: verify the authenticator code ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_verify'])) {
    $pending = $_SESSION['2fa'] ?? null;
    if (!$pending || ($pending['at'] ?? 0) < time() - 300) {
        unset($_SESSION['2fa']);
        $otpStage = false;
        $loginError = 'Your login timed out. Please sign in again.';
    } elseif (($left = login_locked()) > 0) {
        $loginError = 'Too many attempts. Try again in ' . ceil($left / 60) . ' minute(s).';
    } elseif (totp_verify((string)$pending['secret'], (string)($_POST['otp'] ?? ''))) {
        finish_login($pending['user']);
    } else {
        login_note_fail();
        $loginError = 'That code is not right. Open your authenticator app and enter the current 6-digit code.';
    }
}

// ---- Stage 1: username + password ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (($left = login_locked()) > 0) {
        $loginError = 'Too many failed attempts. Try again in ' . ceil($left / 60) . ' minute(s).';
    } elseif (($CONFIG['app_password'] ?? '') === 'change-this-strong-password') {
        $loginError = 'Change app_password in config.php first.';
    } else {
        $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
        if ($_SESSION['tries'] > 3) {
            sleep(2); // slow down repeated attempts
        }
        try {
            $u = user_login((string)($_POST['user'] ?? ''), (string)($_POST['pass'] ?? ''));
            if ($u) {
                // Super admin logs in directly (config-based, for quick checking). A DB user
                // with 2FA enabled must pass the authenticator step before the session is set.
                $secret = empty($u['super']) ? user_totp_secret($u['username']) : '';
                if ($secret !== '') {
                    $_SESSION['2fa'] = ['user' => $u, 'secret' => $secret, 'at' => time()];
                    $_SESSION['tries'] = 0;
                    $otpStage = true;
                } else {
                    finish_login($u);
                }
            } else {
                login_note_fail();
                $loginError = 'Incorrect username or password.';
            }
        } catch (Throwable $e) {
            $loginError = $e->getMessage();
        }
    }
}
$u = current_user();
$brand = brand_for_user($u);
$onboard = user_needs_onboarding($u);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($brand['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=13">
<style><?= brand_css($brand) ?></style>
</head>
<body>

<?php if (!$u): ?>
<div class="login-wrap">
  <?php if ($otpStage): ?>
  <form class="login-card" method="post" autocomplete="off">
    <div class="brand"><?= brand_badge_html($brand) ?></div>
    <p class="muted">Two-factor authentication</p>
    <?php if ($loginError): ?><div class="alert err"><?= h($loginError) ?></div><?php endif; ?>
    <p class="muted small" style="margin:0 0 14px">Enter the 6-digit code from your authenticator app.</p>
    <label class="f">Authenticator code<input name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="123456" required autofocus style="letter-spacing:.4em;text-align:center;font-size:20px"></label>
    <button class="btn primary" name="otp_verify" value="1">Verify &amp; sign in</button>
    <a href="?logout=1" class="muted small" style="display:block;text-align:center;margin-top:14px">Cancel and start over</a>
  </form>
  <?php else: ?>
  <form class="login-card" method="post" autocomplete="off">
    <div class="brand"><?= brand_badge_html($brand) ?></div>
    <p class="muted">Sign in to continue.</p>
    <?php if ($loginError): ?><div class="alert err"><?= h($loginError) ?></div><?php endif; ?>
    <label class="f">Username<input name="user" required autofocus></label>
    <label class="f">Password<input name="pass" type="password" required></label>
    <button class="btn primary" name="login" value="1">Sign in</button>
  </form>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand"><?= brand_badge_html($brand) ?></div>
    <nav class="nav" id="nav">
      <a href="#overview" data-v="overview"><svg class="i" data-i="globe"></svg>All accounts</a>
      <a href="#dashboard" data-v="dashboard"><svg class="i" data-i="db"></svg>Accounts</a>
      <a href="#campaigns" data-v="campaigns"><svg class="i" data-i="send"></svg>Campaigns</a>
      <a href="#presets" data-v="presets"><svg class="i" data-i="pin"></svg>Campaign Presets</a>
      <a href="#conversions" data-v="conversions"><svg class="i" data-i="target"></svg>Conversions</a>
      <a href="#reports" data-v="reports"><svg class="i" data-i="line"></svg>Reports</a>
      <a href="#billing" data-v="billing"><svg class="i" data-i="case"></svg>Billing</a>
      <a href="#analytics" data-v="analytics"><svg class="i" data-i="bars"></svg>Analytics</a>
      <a href="#automation" data-v="automation"><svg class="i" data-i="auto"></svg>Automation</a>
      <a href="#optimizer" data-v="optimizer"><svg class="i" data-i="target"></svg>AI Optimizer</a>
      <a href="#affreport" data-v="affreport"><svg class="i" data-i="bars"></svg>Affiliate Report</a>
      <a href="#rotator" data-v="rotator"><svg class="i" data-i="refresh"></svg>URL Rotator</a>
      <a href="#tools" data-v="tools"><svg class="i" data-i="case"></svg>Tools</a>
      <a href="#access" data-v="access"><svg class="i" data-i="shield"></svg>Access &amp; sharing</a>
      <a href="#settings" data-v="settings"><svg class="i" data-i="gear"></svg>Settings</a>
    </nav>
    <div class="side-fill"></div>
    <a class="help" href="connect.php">
      <svg class="i" data-i="link"></svg>
      <div style="flex:1"><b>Link Google account</b><span>Connect an account or manager account</span></div>
      <svg class="i sm" data-i="chev"></svg>
    </a>
    <div class="userrow">
      <div class="avatar"><?= h(strtoupper(substr($u['name'] ?: $u['username'], 0, 1))) ?></div>
      <div class="grow">
        <div class="who">Hi, <?= h($u['name'] ?: $u['username']) ?></div>
        <div class="role"><?= h($u['role']) ?></div>
      </div>
      <a href="?logout=1" title="Logout"><svg class="i" data-i="logout"></svg></a>
    </div>
  </aside>

  <main class="main">
    <?php if (!empty($_SESSION['impersonator'])): ?>
    <div class="imp-bar"><svg class="i" data-i="shield"></svg>
      <span>Viewing as <b><?= h($u['name'] ?: $u['username']) ?></b> <span class="muted">(<?= h($u['username']) ?>)</span> — super-admin view</span>
      <a href="?stop_impersonate=1" class="btn sm">Exit to super admin</a></div>
    <?php endif; ?>
    <header class="topbar" id="topbar">
      <button class="btn icon only-mobile" id="menuBtn" aria-label="Menu"><svg class="i" data-i="menu"></svg></button>
      <div class="title-block">
        <div class="eyebrow" id="eyebrow">Accounts</div>
        <div>
          <button class="acc-switch" id="accSwitch" title="Switch account">
            <h1 id="accTitle">Loading…</h1><svg class="i" data-i="down"></svg>
          </button>
          <button class="copy" id="copyId" title="Copy customer ID"><svg class="i sm" data-i="copy"></svg></button>
        </div>
        <div class="subline" id="accSub"></div>
      </div>
      <div class="controls" id="dateControls">
        <button class="datebtn" id="dateBtn"><svg class="i" data-i="cal"></svg><span id="dateLabel" style="flex:1;text-align:left">Last 7 days</span><svg class="i sm" data-i="down"></svg></button>
        <button class="btn icon" id="refreshBtn" title="Refresh"><svg class="i" data-i="refresh"></svg></button>
        <button class="btn" id="csvBtn"><svg class="i" data-i="dl"></svg>CSV</button>
      </div>
    </header>

    <div id="warnBox" class="alert warn hidden"></div>
    <div id="demoBox" class="alert info hidden"></div>
    <div id="errBox" class="alert err hidden"></div>
    <?php if (isset($_GET['connected'])): ?>
      <div class="alert ok"><b><?= h($_GET['connected']) ?></b> connected. Its accounts are now in the account list.</div>
    <?php endif; ?>

    <div id="view"></div>
  </main>
</div>

<div class="toast hidden" id="toast"></div>
<script>window.APP = { csrf: <?= json_encode($_SESSION['csrf']) ?>, user: <?= json_encode(['username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'super' => !empty($u['super'])]) ?>, canEdit: <?= !empty($CONFIG['allow_changes']) ? 'true' : 'false' ?>, brand: <?= json_encode($brand) ?>, onboard: <?= $onboard ? 'true' : 'false' ?> };</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="assets/js/core.js?v=12"></script>
<script src="assets/js/dashboard.js?v=9"></script>
<script src="assets/js/campaign.js?v=9"></script>
<script src="assets/js/builder.js?v=10"></script>
<script src="assets/js/presets.js?v=1"></script>
<script src="assets/js/reports.js?v=8"></script>
<script src="assets/js/tools.js?v=8"></script>
<script src="assets/js/automation.js?v=8"></script>
<script src="assets/js/settings.js?v=13"></script>
<script src="assets/js/overview.js?v=10"></script>
<script src="assets/js/rotator.js?v=12"></script>
<script src="assets/js/access.js?v=9"></script>
<script src="assets/js/mcc.js?v=9"></script>
<script src="assets/js/conversions.js?v=9"></script>
<script src="assets/js/optimizer.js?v=5"></script>
<script src="assets/js/affreport.js?v=1"></script>
<script src="assets/js/billing.js?v=1"></script>
<script src="assets/js/onboard.js?v=1"></script>
<script src="assets/js/main.js?v=9"></script>
<?php endif; ?>
</body>
</html>
