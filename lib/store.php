<?php
/**
 * MySQL layer
 * - db(): PDO connection
 * - Tables are created automatically on first run (schema.sql)
 * - Old v2 JSON files (lib/data/*.json) are imported into the DB once
 * - Refresh tokens are encrypted with AES-256-GCM (config: app_key)
 */

const SCHEMA_VERSION = '14';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    global $CONFIG;
    if (empty($CONFIG['db_name']) || empty($CONFIG['db_user'])) {
        throw new RuntimeException('Fill in the MySQL details (db_host, db_name, db_user, db_pass) in config.php.');
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . ($CONFIG['db_host'] ?? 'localhost') . ';port=' . ($CONFIG['db_port'] ?? 3306)
            . ';dbname=' . $CONFIG['db_name'] . ';charset=utf8mb4',
            $CONFIG['db_user'], $CONFIG['db_pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (PDOException $e) {
        throw new RuntimeException('Could not connect to MySQL: ' . $e->getMessage() . ' (check the db_* values in config.php)');
    }
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    db_migrate($pdo);
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** Create tables (if missing) + import old JSON data */
/** Add a column if it isn't there yet (idempotent, works on MySQL + MariaDB) */
/** Does a column exist right now? Safe if the table itself is missing. */
function column_exists(PDO $pdo, string $table, string $col): bool
{
    try {
        return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch();
    } catch (PDOException $e) {
        return false;
    }
}

/** Ensure a column exists. Returns TRUE only if it is present after the call (verified). */
function add_column(PDO $pdo, string $table, string $col, string $ddl): bool
{
    if (column_exists($pdo, $table, $col)) {
        return true;
    }
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    } catch (PDOException $e) {
        // A concurrent migration may have added it, or the table may not exist yet.
        // Don't trust that this failed - re-check below rather than assuming success.
    }
    return column_exists($pdo, $table, $col); // the real answer: is it there now?
}

/** Columns that versions after the base schema introduce. Verified on every migration run. */
function schema_columns(): array
{
    return [
        ['suffix_rotators', 'single_use', "TINYINT(1) NOT NULL DEFAULT 1"],
        ['users', 'totp_secret', "VARCHAR(64) NOT NULL DEFAULT ''"],
        ['users', 'brand', "TEXT NULL"],
        // Bumped on password change to revoke all of that user's existing sessions
        ['users', 'session_epoch', "INT NOT NULL DEFAULT 0"],
        // 0 = must finish first-login setup (profile + 2FA). Existing users default to 1 (done).
        ['users', 'onboarded', "TINYINT(1) NOT NULL DEFAULT 1"],
        // Network's click / shared id stored alongside each affiliate conversion.
        ['affiliate_conversions', 'click_id', "VARCHAR(190) NOT NULL DEFAULT ''"],
        // Which user created this user (for per-creator isolation; super sees all, '' = legacy).
        ['users', 'created_by', "VARCHAR(60) NOT NULL DEFAULT ''"],
        // Rotator suffix got a click -> retired to trash, never rotated again (even on loop).
        ['suffix_items', 'clicked', "TINYINT(1) NOT NULL DEFAULT 0"],
        ['suffix_items', 'clicked_at', "DATETIME NULL"],
    ];
}

/** Names of required columns that are still missing. Empty array = schema is complete. */
function schema_missing_columns(PDO $pdo): array
{
    $out = [];
    foreach (schema_columns() as [$t, $c, $d]) {
        if (!column_exists($pdo, $t, $c)) {
            $out[] = "$t.$c";
        }
    }
    return $out;
}

function db_migrate(PDO $pdo): void
{
    try {
        $v = $pdo->query("SELECT value FROM app_settings WHERE name='schema_version'")->fetchColumn();
        // Version says up to date - but verify the columns REALLY exist. A previous buggy run
        // (or an ALTER that failed) could have stamped the version with a column still missing;
        // if so, fall through and repair instead of trusting the stamp.
        if ($v === SCHEMA_VERSION && !schema_missing_columns($pdo)) {
            return;
        }
    } catch (PDOException $e) {
        // app_settings table does not exist yet - full create below
    }
    // (Re)create any missing tables (CREATE IF NOT EXISTS skips existing ones), then (re)add
    // every version column.
    $sql = file_get_contents(__DIR__ . '/../schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    foreach (schema_columns() as [$t, $c, $d]) {
        add_column($pdo, $t, $c, $d);
    }
    import_json_data($pdo);
    // Only stamp the version when EVERY required column is confirmed present. If an ALTER
    // failed, leave the version behind so the next request retries the migration instead of
    // silently marking a broken schema as done.
    $missing = schema_missing_columns($pdo);
    if (!$missing) {
        $pdo->prepare("REPLACE INTO app_settings (name, value) VALUES ('schema_version', ?)")->execute([SCHEMA_VERSION]);
    } else {
        error_log('TrakrHub migration incomplete - columns still missing: ' . implode(', ', $missing)
            . '. schema_version NOT updated; will retry next request.');
    }
}

/** Import v2 lib/data/*.json files into the DB (once) */
function import_json_data(PDO $pdo): void
{
    global $CONFIG;
    $dir = __DIR__ . '/data';
    $read = fn($f) => is_file("$dir/$f") ? (json_decode((string)file_get_contents("$dir/$f"), true) ?: []) : [];

    foreach ($read('users.json') as $u) {
        $pdo->prepare('INSERT IGNORE INTO users (username, name, role, password_hash, disabled, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$u['username'], $u['name'] ?? $u['username'], $u['role'] ?? 'user', $u['password_hash'], (int)!empty($u['disabled']), $u['created'] ?? now()]);
    }
    foreach ($read('connections.json') as $c) {
        $pdo->prepare('INSERT IGNORE INTO connections (id, owner, email, refresh_token, created_at) VALUES (?,?,?,?,?)')
            ->execute([$c['id'], $c['owner'] ?? strtolower($CONFIG['app_user']), $c['email'], enc($c['refresh_token']), $c['created'] ?: now()]);
    }
    foreach ($read('rules.json') as $r) {
        $pdo->prepare('INSERT IGNORE INTO rules (id, owner, data, enabled, last_run, last_result) VALUES (?,?,?,?,?,?)')
            ->execute([$r['id'], $r['owner'], json_encode($r), (int)!empty($r['enabled']), $r['last_run'] ?? null, $r['last_result'] ?? null]);
    }
}

// ---------- token encryption ----------
function enc_key(): ?string
{
    global $CONFIG;
    $k = (string)($CONFIG['app_key'] ?? '');
    return ($k === '' || $k === 'change-this-to-a-long-random-string') ? null : hash('sha256', $k, true);
}

function enc(string $plain): string
{
    $key = enc_key();
    if (!$key) {
        return $plain;
    }
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $ct);
}

function dec(string $stored): string
{
    if (!str_starts_with($stored, 'enc:')) {
        return $stored;
    }
    $key = enc_key();
    $raw = base64_decode(substr($stored, 4));
    $pt = $key ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
    if ($pt === false) {
        throw new RuntimeException('Could not decrypt the Google token. Was app_key in config.php changed? Reconnect the Google account.');
    }
    return $pt;
}

// ---------- change log ----------
function log_change(string $user, string $cid, string $what, bool $demo = false): void
{
    try {
        q('INSERT INTO change_log (username, customer_id, what, demo, created_at) VALUES (?,?,?,?,?)',
          [$user, $cid, mb_substr($what, 0, 2000), (int)$demo, now()]);
    } catch (Throwable $e) {
        // a failed log write must not stop the request
    }
}

function read_changes(string $user, int $limit = 200): array
{
    $rows = q('SELECT created_at AS t, username AS user, customer_id AS cid, what, demo FROM change_log
               WHERE username = ? ORDER BY id DESC LIMIT ' . (int)$limit, [$user])->fetchAll();
    foreach ($rows as &$r) {
        $r['demo'] = (bool)$r['demo'];
    }
    return $rows;
}
