<?php
/** Common setup: config, session, auth */

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    exit('config.php not found. Copy config.sample.php to config.php and fill in the values.');
}
$CONFIG = require __DIR__ . '/../config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Kolkata');

require __DIR__ . '/store.php';
require __DIR__ . '/GoogleAdsClient.php';
require __DIR__ . '/connections.php';
require __DIR__ . '/users.php';
require __DIR__ . '/demo.php';
require __DIR__ . '/ads.php';
require __DIR__ . '/builder.php';
require __DIR__ . '/smart.php';
require __DIR__ . '/mcc.php';
require __DIR__ . '/rules.php';
require __DIR__ . '/sync.php';
require __DIR__ . '/rotator.php';
require __DIR__ . '/throttle.php';
require __DIR__ . '/totp.php';
require __DIR__ . '/brand.php';
require __DIR__ . '/networks.php';
require __DIR__ . '/optimizer.php';
require __DIR__ . '/presets.php';
require __DIR__ . '/scripts.php';

if (PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('ADHOOKADS');
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $GLOBALS['__auth_verified'] = true; // did we confirm this user against the DB on THIS request?
    $u = $_SESSION['auth'] ?? null;
    if (!$u) {
        return $cache = null;
    }
    // Super admin comes from config.php and always stays valid
    if (!empty($u['super'])) {
        return $cache = $u;
    }
    // DB user: re-check every request so a disabled/demoted user loses access immediately
    try {
        $row = q('SELECT name, role, disabled, session_epoch FROM users WHERE username = ?', [$u['username']])->fetch();
    } catch (Throwable $e) {
        // DB unreachable. Trust the existing session only for a short grace window since the
        // last successful check, so a transient blip doesn't log everyone out - but a sustained
        // outage can NOT keep a disabled/revoked user signed in indefinitely.
        $lastOk = (int)($_SESSION['auth_checked_at'] ?? 0);
        if ($lastOk && (time() - $lastOk) <= 120) {
            $GLOBALS['__auth_verified'] = false; // served without a DB check - reads only, no writes
            return $cache = $u;
        }
        return $cache = null; // stale beyond grace -> deny this request until the DB recovers
    }
    // Deny if the user is gone, disabled, or their password changed since this session started
    // (session_epoch is bumped on password reset, which revokes all of that user's sessions).
    if (!$row || !empty($row['disabled']) || (int)$row['session_epoch'] !== (int)($u['epoch'] ?? 0)) {
        session_destroy();
        $_SESSION = [];
        return $cache = null;
    }
    // Pick up name/role changes made by an admin since login
    $u['name'] = $row['name'];
    $u['role'] = $row['role'];
    $_SESSION['auth'] = $u;
    $_SESSION['auth_checked_at'] = time(); // last successful DB re-validation
    return $cache = $u;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * Was the current user confirmed against the DB on this request?
 * False only during a DB outage, when a recent session is trusted briefly for READS.
 * Write actions must refuse when this is false, so an unverifiable (possibly disabled/
 * demoted) user can never perform a state change while we can't check them.
 */
function auth_verified(): bool
{
    current_user();
    return $GLOBALS['__auth_verified'] ?? true;
}

function is_admin(): bool
{
    $u = current_user();
    return $u && ($u['role'] ?? '') === 'admin';
}

/** The config super-admin (app_user). Sees and manages everything; others never see it. */
function is_super(): bool
{
    $u = current_user();
    return $u && !empty($u['super']);
}

function me(): string
{
    $u = current_user();
    return $u ? strtolower($u['username']) : '';
}

/** Demo mode when this user has no Google account connected */
function is_demo(): bool
{
    return conns_count(me()) === 0 && shares_count_for(me()) === 0;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
