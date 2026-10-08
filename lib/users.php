<?php
/**
 * Users (MySQL: users table)
 * - Super admin: app_user / app_password from config.php (always exists)
 * - Other users live in the DB (hashed passwords)
 * - Role: admin (can manage users) | user
 */

/**
 * All DB users, or - when $createdBy is given - only the users that owner created.
 * The config super-admin is never a DB row, so it never appears here.
 */
function users_all(?string $createdBy = null): array
{
    $out = [];
    $sql = 'SELECT * FROM users';
    $args = [];
    if ($createdBy !== null) {
        $sql .= ' WHERE created_by = ?';
        $args[] = strtolower($createdBy);
    }
    $sql .= ' ORDER BY created_at';
    foreach (q($sql, $args) as $u) {
        $out[$u['username']] = [
            'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'],
            'password_hash' => $u['password_hash'], 'disabled' => (bool)$u['disabled'], 'created' => $u['created_at'],
            'created_by' => $u['created_by'] ?? '',
        ];
    }
    return $out;
}

/** Who created this DB user ('' if unknown/legacy). */
function user_created_by(string $username): string
{
    $v = q('SELECT created_by FROM users WHERE username = ?', [strtolower($username)])->fetchColumn();
    return $v === false ? '' : (string)$v;
}

/**
 * Create a one-time "login as this user" token (super-admin only, enforced by the caller).
 * Short-lived + single use, so it can be opened in an incognito window to inspect the account
 * without the user's password and without disturbing the super-admin's own session.
 */
function impersonate_token_create(string $username, string $issuedBy, int $ttl = 300): string
{
    $key = user_clean_name($username);
    if (!q('SELECT 1 FROM users WHERE username = ?', [$key])->fetchColumn()) {
        throw new RuntimeException('User not found.');
    }
    $token = bin2hex(random_bytes(24));
    q('INSERT INTO login_tokens (token, username, issued_by, expires_at, used, created_at) VALUES (?,?,?,?,0,?)',
      [$token, $key, strtolower($issuedBy), date('Y-m-d H:i:s', time() + max(60, $ttl)), now()]);
    // Opportunistic cleanup of old tokens.
    q('DELETE FROM login_tokens WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 3600)]);
    return $token;
}

/** Consume a login token. Returns the user array to sign in as, or null if invalid/expired/used. */
function impersonate_token_consume(string $token): ?array
{
    $token = preg_replace('/[^a-f0-9]/', '', $token);
    if ($token === '') {
        return null;
    }
    $r = q('SELECT * FROM login_tokens WHERE token = ?', [$token])->fetch();
    if (!$r || (int)$r['used'] === 1 || $r['expires_at'] < now()) {
        return null;
    }
    q('UPDATE login_tokens SET used = 1 WHERE token = ?', [$token]);
    $u = q('SELECT * FROM users WHERE username = ?', [$r['username']])->fetch();
    if (!$u || !empty($u['disabled'])) {
        return null;
    }
    return ['username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'super' => false,
            'epoch' => (int)($u['session_epoch'] ?? 0), 'impersonated_by' => $r['issued_by']];
}

function user_valid_name(string $u): bool
{
    return (bool)preg_match('/^[a-z0-9._@+-]{3,60}$/', $u);
}

/** "Naveen Kumar" -> "naveen.kumar" (space -> dot, lowercase) */
function user_clean_name(string $u): string
{
    $u = strtolower(trim($u));
    $u = preg_replace('/\s+/', '.', $u);
    return trim(preg_replace('/\.{2,}/', '.', $u), '.');
}

/** Login check. Return user array ya null */
function user_login(string $username, string $password): ?array
{
    global $CONFIG;
    $username = trim($username);
    if (hash_equals(strtolower((string)$CONFIG['app_user']), strtolower($username))) {
        if ($CONFIG['app_password'] !== 'change-this-strong-password'
            && hash_equals((string)$CONFIG['app_password'], $password)) {
            db(); // create tables on first login
            return ['username' => strtolower($username), 'name' => 'Admin', 'role' => 'admin', 'super' => true];
        }
        return null;
    }
    $u = q('SELECT * FROM users WHERE username = ?', [user_clean_name($username)])->fetch();
    if ($u && !$u['disabled'] && password_verify($password, $u['password_hash'])) {
        return ['username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'super' => false,
                'epoch' => (int)($u['session_epoch'] ?? 0)];
    }
    return null;
}

function user_create(string $username, string $name, string $password, string $role, string $createdBy = ''): void
{
    global $CONFIG;
    $key = user_clean_name($username);
    if ($key === '') {
        throw new RuntimeException('Username is required, e.g. rahul or rahul@gmail.com');
    }
    if (strlen($key) < 3) {
        throw new RuntimeException("Username \"$key\" is too short (minimum 3 characters).");
    }
    if (!user_valid_name($key)) {
        $bad = implode(' ', array_unique(mb_str_split(preg_replace('/[a-z0-9._@+-]/u', '', $key))));
        throw new RuntimeException("Username contains characters that are not allowed: $bad  (use a-z, 0-9 and . _ @ + -)");
    }
    if ($key === strtolower($CONFIG['app_user'])) {
        throw new RuntimeException('This username is reserved.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }
    if (q('SELECT 1 FROM users WHERE username = ?', [$key])->fetchColumn()) {
        throw new RuntimeException('This username already exists.');
    }
    // onboarded = 0 -> the user must set their own profile + 2FA on first login
    q('INSERT INTO users (username, name, role, password_hash, disabled, onboarded, created_by, created_at) VALUES (?,?,?,?,0,0,?,?)',
      [$key, trim($name) ?: $key, $role === 'admin' ? 'admin' : 'user', password_hash($password, PASSWORD_DEFAULT), strtolower($createdBy), now()]);
}

/** Does this DB user still need first-login setup (profile + 2FA)? Super admin never does. */
function user_needs_onboarding(?array $u): bool
{
    if (!$u || !empty($u['super'])) {
        return false;
    }
    try {
        $v = q('SELECT onboarded FROM users WHERE username = ?', [strtolower($u['username'])])->fetchColumn();
    } catch (Throwable $e) {
        return false; // DB hiccup - don't trap the user in onboarding
    }
    return $v !== false && $v !== null && (int)$v === 0;
}

/** Finish onboarding: set the user's own name + password, requires 2FA already on. */
function user_finish_onboarding(string $username, string $name, string $password): void
{
    $key = strtolower($username);
    if (!user_has_totp($key)) {
        throw new RuntimeException('Turn on two-factor authentication before finishing.');
    }
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        throw new RuntimeException('Enter your name.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Choose a password of at least 8 characters.');
    }
    q('UPDATE users SET name = ?, password_hash = ?, session_epoch = session_epoch + 1, onboarded = 1 WHERE username = ?',
      [mb_substr($name, 0, 120), password_hash($password, PASSWORD_DEFAULT), $key]);
}

function user_update(string $username, array $fields): void
{
    $key = strtolower($username);
    if (!q('SELECT 1 FROM users WHERE username = ?', [$key])->fetchColumn()) {
        throw new RuntimeException('User not found.');
    }
    if (isset($fields['password'])) {
        if (strlen($fields['password']) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        // Bump session_epoch so every existing session for this user is revoked on its next request
        q('UPDATE users SET password_hash = ?, session_epoch = session_epoch + 1 WHERE username = ?',
          [password_hash($fields['password'], PASSWORD_DEFAULT), $key]);
    }
    if (array_key_exists('name', $fields)) {
        q('UPDATE users SET name = ? WHERE username = ?', [$fields['name'], $key]);
    }
    if (array_key_exists('role', $fields)) {
        q('UPDATE users SET role = ? WHERE username = ?', [$fields['role'] === 'admin' ? 'admin' : 'user', $key]);
    }
    if (array_key_exists('disabled', $fields)) {
        q('UPDATE users SET disabled = ? WHERE username = ?', [(int)$fields['disabled'], $key]);
    }
}

/** ---- Two-factor (TOTP) helpers ---- */
function user_totp_secret(string $username): string
{
    $r = q('SELECT totp_secret FROM users WHERE username = ?', [strtolower($username)])->fetch();
    return $r ? (string)($r['totp_secret'] ?? '') : '';
}

function user_has_totp(string $username): bool
{
    return user_totp_secret($username) !== '';
}

function user_set_totp(string $username, string $secretB32): void
{
    q('UPDATE users SET totp_secret = ? WHERE username = ?', [$secretB32, strtolower($username)]);
}

function user_clear_totp(string $username): void
{
    q("UPDATE users SET totp_secret = '' WHERE username = ?", [strtolower($username)]);
}

function user_delete(string $username): void
{
    $key = strtolower($username);
    foreach (conns_load($key) as $c) {
        conns_remove($key, $c['id']); // also revokes access at Google
    }
    q('DELETE FROM rules WHERE owner = ?', [$key]);
    q('DELETE FROM ads_accounts WHERE owner = ?', [$key]);
    q('DELETE FROM users WHERE username = ?', [$key]);
}
