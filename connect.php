<?php
/**
 * Connect Google Ads - connect any Google account from the browser (no terminal)
 * ---------------------------------------------------------------------------
 * - The dashboard shows the accounts the connected Google account can access
 * - You can connect more than one account (your manager account + a client's, etc.)
 * - Reconnecting the same account replaces the old token
 * - Removing also revokes access at Google
 *
 * Uses the "Desktop app" OAuth client (redirect: http://127.0.0.1:8080)
 */
require __DIR__ . '/lib/bootstrap.php';

if (!is_logged_in()) {
    header('Location: ./');
    exit;
}

const REDIRECT_URI = 'http://127.0.0.1:8080';
$msg = $err = '';

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function new_oauth_state(): void
{
    $_SESSION['oauth_verifier'] = b64url(random_bytes(64));
    $_SESSION['oauth_state']    = bin2hex(random_bytes(16));
}
function email_from_id_token(?string $jwt): string
{
    $parts = explode('.', (string)$jwt);
    if (count($parts) < 2) {
        return '';
    }
    $p = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    return (string)($p['email'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    new_oauth_state();
    if (isset($_GET['removed'])) {
        $msg = 'Connection removed.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $err = 'Session expired. Refresh the page and try again.';

    // During a DB outage a recent session is trusted for reads only. Refuse connection
    // add/remove (writes) by an unverifiable user - fail closed and STOP here (the rest of
    // the page needs the DB anyway), same intent as the API guard.
    } elseif (!auth_verified()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Account verification is temporarily unavailable (database issue). Please retry in a moment.');

    // ---- Pasted URL -> token -> connection save ----
    } elseif (isset($_POST['exchange'])) {
        $pasted = trim($_POST['redirect_url'] ?? '');
        parse_str((string)parse_url($pasted, PHP_URL_QUERY), $q);

        if (!empty($q['error'])) {
            $err = 'Google refused: ' . $q['error'];
        } elseif (empty($q['code'])) {
            $err = 'No "code=" in the URL. Copy the FULL URL from the address bar (http://127.0.0.1:8080/?state=...&code=...).';
        } elseif (($q['state'] ?? '') !== ($_SESSION['oauth_state'] ?? '')) {
            $err = 'This URL is from an old login link. Use the NEW link below to log in again.';
        } elseif (!str_contains($q['scope'] ?? 'adwords', 'adwords')) {
            $err = 'Google Ads permission was not granted. Tick the Google Ads checkbox during login.';
        } else {
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'code'          => $q['code'],
                    'client_id'     => $CONFIG['client_id'],
                    'client_secret' => $CONFIG['client_secret'],
                    'redirect_uri'  => REDIRECT_URI,
                    'grant_type'    => 'authorization_code',
                    'code_verifier' => $_SESSION['oauth_verifier'] ?? '',
                ]),
            ]);
            $res = json_decode((string)curl_exec($ch), true) ?: [];
            curl_close($ch);

            if (!empty($res['refresh_token'])) {
                $email = email_from_id_token($res['id_token'] ?? null) ?: ('google-' . substr(md5($res['refresh_token']), 0, 6));
                try {
                    conns_add(me(), $email, $res['refresh_token']);
                    header('Location: ./?connected=' . urlencode($email));
                    exit;
                } catch (Throwable $e) {
                    $err = $e->getMessage();
                }
            } else {
                $e = ($res['error'] ?? 'unknown') . (isset($res['error_description']) ? ' - ' . $res['error_description'] : '');
                $err = 'Could not get a token: ' . $e;
                if (($res['error'] ?? '') === 'invalid_grant') {
                    $err .= ' (The code works only once and expires in a few minutes. Log in again with a new link.)';
                } elseif (($res['error'] ?? '') === 'invalid_client') {
                    $err .= ' (Check client_id / client_secret in config.php.)';
                }
            }
        }
        new_oauth_state();

    // ---- Remove connection (+ revoke at Google) ----
    } elseif (isset($_POST['remove'])) {
        $id = preg_replace('/\W/', '', $_POST['remove']);
        if (conns_remove(me(), $id)) {
            header('Location: connect.php?removed=1');
            exit;
        }
        $err = 'Connection not found.';
        new_oauth_state();
    }
}

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'             => $CONFIG['client_id'],
    'redirect_uri'          => REDIRECT_URI,
    'response_type'         => 'code',
    'scope'                 => 'openid email https://www.googleapis.com/auth/adwords',
    'access_type'           => 'offline',
    'prompt'                => 'consent select_account', // let the user pick an account each time
    'state'                 => $_SESSION['oauth_state'] ?? '',
    'code_challenge'        => b64url(hash('sha256', $_SESSION['oauth_verifier'] ?? '', true)),
    'code_challenge_method' => 'S256',
]);
$clientOk = !str_starts_with($CONFIG['client_id'], 'XXXX') && !str_starts_with($CONFIG['client_secret'], 'GOCSPX-XXXX');
$conns    = conns_load(me());
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Connect Google Ads | AdHook</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=1">
</head>
<body>
<div class="connect-wrap">
  <div class="connect-card">
    <div class="brand"><span class="logo">A</span> AdHook <b>Ads Manager</b></div>
    <h1 style="margin-top:18px">Google accounts</h1>
    <p class="muted">The dashboard shows the Google Ads accounts of whichever Google account you connect
      (for a manager account, all of its accounts too). You can connect more than one. These connections are visible only to <b><?= h(me()) ?></b>.</p>

    <?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert ok"><?= h($msg) ?></div><?php endif; ?>

    <!-- Connected emails -->
    <div class="conn-list">
      <?php if (!$conns): ?>
        <div class="muted small">No Google account connected yet<?= ($CONFIG['demo_banner'] ?? true) !== false ? ' (the dashboard is in demo mode)' : '' ?>.</div>
      <?php endif; ?>
      <?php foreach ($conns as $c): ?>
        <div class="conn-item">
          <span class="avatar"><?= h(strtoupper(substr($c['email'], 0, 1))) ?></span>
          <div class="grow">
            <div class="email"><?= h($c['email']) ?></div>
            <div class="muted small">Connected <?= h($c['created']) ?></div>
            <?php
              $ad = accounts_cached($c['id']);
            ?>
            <?php if (!empty($ad['roots'])): ?>
              <ul class="roots">
                <?php foreach ($ad['roots'] as $r): ?>
                  <li class="<?= h(strtolower($r['type'])) ?>">
                    <b><?= h($r['type'] === 'MCC' ? 'MCC' : ($r['type'] === 'Error' ? 'Error' : ($r['type'] === 'Inactive' ? 'Inactive' : 'Account'))) ?></b>
                    <?= h($r['name']) ?> <span class="muted">(<?= h(fmt_cid($r['id'])) ?>)</span>
                    <?php if ($r['type'] === 'MCC'): ?> → <?= (int)$r['count'] ?> accounts<?php endif; ?>
                    <?php if (!empty($r['error'])): ?><div class="small err-t"><?= h($r['error']) ?></div><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php elseif ($ad === null): ?>
              <div class="muted small">Accounts not loaded yet. Open the dashboard.</div>
            <?php endif; ?>
          </div>
          <form method="post" onsubmit="return confirm('Disconnect <?= h($c['email']) ?>? Access at Google will be revoked too.')">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <button class="btn sm" name="remove" value="<?= h($c['id']) ?>">Remove</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>

    <h2 style="margin:26px 0 4px"><?= $conns ? '+ Add another Google account' : 'Connect Google account' ?></h2>

    <?php if (!$clientOk): ?>
      <div class="alert warn" style="margin-top:12px">Fill in <code>client_id</code> and <code>client_secret</code> in <code>config.php</code> first (Google Auth Platform &gt; Clients &gt; Desktop app).</div>
    <?php else: ?>
      <div class="step">
        <div class="num">1</div>
        <div>
          <h2>Log in with Google</h2>
          <p class="muted">Log in with the account whose data you want and click <b>Allow</b> (keep the Google Ads checkbox ticked).
            "Google hasn't verified this app" aaye to <b>Advanced → Go to app</b>.</p>
          <a class="btn primary" href="<?= h($authUrl) ?>" target="_blank" rel="noopener">Login with Google ↗</a>
        </div>
      </div>

      <div class="step">
        <div class="num">2</div>
        <div>
          <h2>You'll see an error page — that's fine</h2>
          <p class="muted">After Allow, the browser shows <b>"This site can't be reached / 127.0.0.1 refused to connect"</b>.
            That's normal. Copy the <b>full URL from that tab's address bar</b>.</p>
          <div class="example">http://127.0.0.1:8080/?state=…&amp;code=4/0Ab…&amp;scope=…</div>
        </div>
      </div>

      <div class="step">
        <div class="num">3</div>
        <div>
          <h2>Paste the URL here</h2>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <textarea name="redirect_url" class="tok" required placeholder="http://127.0.0.1:8080/?state=...&code=..."></textarea>
            <button class="btn primary" name="exchange" value="1">Connect</button>
          </form>
          <p class="muted small">The code is valid for only a few minutes, so paste it right after logging in.</p>
        </div>
      </div>
    <?php endif; ?>

    <p style="margin-top:22px"><a class="link" href="./#settings">← Back to dashboard</a></p>
  </div>
</div>
</body>
</html>
