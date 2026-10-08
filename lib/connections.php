<?php
/**
 * Google account connections - separate for every tool user
 * ---------------------------------------------------------------------------
 * MySQL: connections table (refresh_token encrypted), account_cache, ads_accounts
 * Shows the Google Ads accounts the connected Google account can access
 * (for a manager account, all client accounts at every level).
 */

const ACCOUNTS_TTL = 1800; // accounts list 30 min cache

function conn_row(array $r): array
{
    return ['id' => $r['id'], 'owner' => $r['owner'], 'email' => $r['email'],
            'refresh_token' => dec($r['refresh_token']), 'created' => $r['created_at']];
}

function conns_all(): array
{
    $out = [];
    foreach (q('SELECT * FROM connections ORDER BY created_at') as $r) {
        $out[$r['id']] = conn_row($r);
    }
    return $out;
}

/** Connections of this user only */
function conns_load(string $owner): array
{
    $out = [];
    foreach (q('SELECT * FROM connections WHERE owner = ? ORDER BY created_at', [strtolower($owner)]) as $r) {
        $out[$r['id']] = conn_row($r);
    }
    return $out;
}

function conns_count(string $owner): int
{
    return (int)q('SELECT COUNT(*) FROM connections WHERE owner = ?', [strtolower($owner)])->fetchColumn();
}

function conns_add(string $owner, string $email, string $refreshToken): array
{
    $owner = strtolower($owner);
    $id = substr(md5($owner . '|' . strtolower($email)), 0, 12);
    q('REPLACE INTO connections (id, owner, email, refresh_token, created_at) VALUES (?,?,?,?,?)',
      [$id, $owner, $email, enc($refreshToken), now()]);
    q('DELETE FROM account_cache WHERE conn_id = ?', [$id]);
    return ['id' => $id, 'owner' => $owner, 'email' => $email, 'refresh_token' => $refreshToken, 'created' => now()];
}

function conns_remove(string $owner, string $id): ?array
{
    $c = conns_load($owner)[$id] ?? null;
    if (!$c) {
        return null;
    }
    q('DELETE FROM connections WHERE id = ?', [$id]);
    q('DELETE FROM account_cache WHERE conn_id = ?', [$id]);
    q('DELETE FROM ads_accounts WHERE conn_id = ?', [$id]);
    q('DELETE FROM account_shares WHERE conn_id = ?', [$id]);
    // Revoke access at Google
    $ch = curl_init('https://oauth2.googleapis.com/revoke');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query(['token' => $c['refresh_token']]),
    ]);
    curl_exec($ch);
    curl_close($ch);
    return $c;
}

/** Read cached account list - no API call */
function accounts_cached(string $connId): ?array
{
    $raw = q('SELECT data FROM account_cache WHERE conn_id = ?', [$connId])->fetchColumn();
    return $raw ? json_decode($raw, true) : null;
}

function client_for(array $conn): GoogleAdsClient
{
    global $CONFIG;
    return new GoogleAdsClient(array_merge($CONFIG, [
        'refresh_token' => $conn['refresh_token'], 'login_customer_id' => '',
    ]));
}

function fmt_cid(string $id): string
{
    return preg_replace('/(\d{3})(\d{3})(\d+)/', '$1-$2-$3', $id);
}

/**
 * All accounts of this Google account + diagnostics
 * return ['accounts' => [...], 'roots' => [...]]
 * status: ENABLED | CANCELED | SUSPENDED | CLOSED | NOT_ENABLED
 */
function accounts_data(array $conn, bool $refresh = false): array
{
    if (!$refresh) {
        $row = q('SELECT data, updated_at FROM account_cache WHERE conn_id = ?', [$conn['id']])->fetch();
        if ($row && strtotime($row['updated_at']) > time() - ACCOUNTS_TTL) {
            $d = json_decode($row['data'], true);
            if (isset($d['accounts'])) {
                return $d;
            }
        }
    }

    $ads = client_for($conn);
    $out = [];
    $roots = [];
    $put = function (array $acc, bool $weak = false) use (&$out) {
        $id = $acc['id'];
        if (!isset($out[$id]) || (!empty($out[$id]['weak']) && !$weak)) {
            $out[$id] = $acc + ['weak' => $weak];
        }
    };

    $ads->setLoginCustomerId(null);
    foreach ($ads->listAccessibleCustomers() as $rootId) {
        $rootId = (string)$rootId;
        try {
            $ads->setLoginCustomerId($rootId);
            $row = $ads->query($rootId, "
                SELECT customer.id, customer.descriptive_name, customer.currency_code,
                       customer.manager, customer.status
                FROM customer LIMIT 1")[0]['customer'] ?? [];
            $rootName = $row['descriptiveName'] ?? ('Account ' . fmt_cid($rootId));

            if (!empty($row['manager'])) {
                $count = 0;
                foreach ($ads->query($rootId, "
                    SELECT customer_client.id, customer_client.descriptive_name,
                           customer_client.currency_code, customer_client.status
                    FROM customer_client
                    WHERE customer_client.manager = FALSE") as $r) {
                    $c = $r['customerClient'];
                    $put([
                        'id' => (string)$c['id'],
                        'name' => $c['descriptiveName'] ?? ('Account ' . fmt_cid((string)$c['id'])),
                        'currency' => $c['currencyCode'] ?? '',
                        'status' => $c['status'] ?? 'ENABLED',
                        'login' => $rootId, 'via' => $rootName,
                    ]);
                    $count++;
                }
                $roots[] = ['id' => $rootId, 'name' => $rootName, 'type' => 'MCC', 'count' => $count];
            } else {
                $put([
                    'id' => $rootId, 'name' => $rootName, 'currency' => $row['currencyCode'] ?? '',
                    'status' => $row['status'] ?? 'ENABLED', 'login' => $rootId, 'via' => '',
                ], true);
                $roots[] = ['id' => $rootId, 'name' => $rootName, 'type' => 'Account'];
            }
        } catch (Throwable $e) {
            $m = $e->getMessage();
            if (str_contains($m, 'CUSTOMER_NOT_ENABLED')) {
                $put(['id' => $rootId, 'name' => 'Account ' . fmt_cid($rootId), 'currency' => '',
                      'status' => 'NOT_ENABLED', 'login' => $rootId, 'via' => ''], true);
                $roots[] = ['id' => $rootId, 'name' => 'Account ' . fmt_cid($rootId), 'type' => 'Inactive'];
            } else {
                $roots[] = ['id' => $rootId, 'name' => 'Account ' . fmt_cid($rootId), 'type' => 'Error',
                            'error' => mb_strimwidth($m, 0, 220, '…')];
            }
        }
    }

    $list = array_map(function ($a) { unset($a['weak']); return $a; }, array_values($out));
    usort($list, function ($a, $b) {
        return (($a['status'] === 'ENABLED') ? 0 : 1) <=> (($b['status'] === 'ENABLED') ? 0 : 1)
            ?: strcasecmp($a['name'], $b['name']);
    });
    $data = ['accounts' => $list, 'roots' => $roots, 'updated' => now()];
    q('REPLACE INTO account_cache (conn_id, data, updated_at) VALUES (?,?,?)', [$conn['id'], json_encode($data), now()]);
    // Update ads_accounts table for overview/sync
    foreach ($list as $a) {
        q('INSERT INTO ads_accounts (conn_id, customer_id, owner, name, currency, status, login_id, via)
           VALUES (?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE name=VALUES(name), currency=VALUES(currency), status=VALUES(status),
                                   login_id=VALUES(login_id), via=VALUES(via), owner=VALUES(owner)',
          [$conn['id'], $a['id'], $conn['owner'], $a['name'], $a['currency'], $a['status'], $a['login'], $a['via']]);
    }
    return $data;
}

/** By connection id (any owner) - used for sharing only */
function conn_by_id(string $id): ?array
{
    $r = q('SELECT * FROM connections WHERE id = ?', [$id])->fetch();
    return $r ? conn_row($r) : null;
}

/** Has this account been shared with the user? (exact account or all accounts '*') */
function share_find(string $user, string $connId, string $cid): ?array
{
    $r = q("SELECT * FROM account_shares WHERE shared_with = ? AND conn_id = ? AND customer_id IN (?, '*')
            ORDER BY (role = 'edit') DESC LIMIT 1", [strtolower($user), $connId, $cid])->fetch();
    return $r ?: null;
}

/**
 * Verify conn + cid and return a ready client.
 * Own connection -> access 'owner'. Shared -> access 'view' or 'edit'.
 */
function resolve_account(string $owner, string $connId, string $cid): array
{
    $conns = conns_load($owner);
    $conn = $conns[$connId] ?? null;
    $access = 'owner';
    $share = null;
    if (!$conn) {
        $share = share_find($owner, $connId, $cid);
        $conn = $share ? conn_by_id($connId) : null;
        if (!$conn || $conn['owner'] !== $share['owner']) {
            throw new RuntimeException('Google connection not found (or sharing was removed). Refresh the page.');
        }
        $access = $share['role'];
    }
    foreach (accounts_data($conn)['accounts'] as $a) {
        if ($a['id'] === $cid) {
            $a['access'] = $access;
            if ($share) {
                $a['shared_by'] = $share['owner'];
            }
            return [client_for($conn)->setLoginCustomerId($a['login']), $a];
        }
    }
    throw new RuntimeException('This Google account has no access to account ' . fmt_cid($cid) . '. Refresh the account list.');
}

// ---------- Sharing ----------
/** Accounts shared with me (for the account picker) */
function shared_accounts(string $user, array &$errors = []): array
{
    $out = [];
    foreach (q('SELECT * FROM account_shares WHERE shared_with = ? ORDER BY id', [strtolower($user)])->fetchAll() as $s) {
        $conn = conn_by_id($s['conn_id']);
        if (!$conn || $conn['owner'] !== $s['owner']) {
            continue;
        }
        try {
            foreach (accounts_data($conn)['accounts'] as $a) {
                if ($s['customer_id'] === '*' || $s['customer_id'] === $a['id']) {
                    $k = $conn['id'] . ':' . $a['id'];
                    if (!isset($out[$k]) || $s['role'] === 'edit') {
                        $out[$k] = $a + ['conn' => $conn['id'], 'email' => $conn['email'], 'access' => $s['role'], 'shared_by' => $s['owner']];
                    }
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Shared by ' . $s['owner'] . ': ' . mb_strimwidth($e->getMessage(), 0, 150, '…');
        }
    }
    return array_values($out);
}

function shares_count_for(string $user): int
{
    try {
        return (int)q('SELECT COUNT(*) FROM account_shares WHERE shared_with = ?', [strtolower($user)])->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function shares_given(string $owner): array
{
    return q('SELECT s.*, c.email FROM account_shares s LEFT JOIN connections c ON c.id = s.conn_id
              WHERE s.owner = ? ORDER BY s.id DESC', [strtolower($owner)])->fetchAll();
}

function shares_received(string $user): array
{
    return q('SELECT s.*, c.email FROM account_shares s LEFT JOIN connections c ON c.id = s.conn_id
              WHERE s.shared_with = ? ORDER BY s.id DESC', [strtolower($user)])->fetchAll();
}

function share_add(string $owner, string $connId, string $cid, string $with, string $role): array
{
    $owner = strtolower($owner);
    $with = strtolower(trim($with));
    $role = $role === 'edit' ? 'edit' : 'view';
    $conn = conns_load($owner)[$connId] ?? null;
    if (!$conn) {
        throw new RuntimeException('You can only share your own connected Google accounts.');
    }
    if ($with === $owner) {
        throw new RuntimeException('You cannot share with yourself.');
    }
    global $CONFIG;
    $exists = $with === strtolower((string)($CONFIG['app_user'] ?? ''));
    foreach (users_all() as $u) {
        if (strtolower($u['username']) === $with) {
            $exists = empty($u['disabled']);
        }
    }
    if (!$exists) {
        throw new RuntimeException("User \"$with\" not found (or disabled). Create the user in Settings > Users first.");
    }
    $name = 'All accounts (' . $conn['email'] . ')';
    if ($cid !== '*') {
        $name = '';
        foreach (accounts_data($conn)['accounts'] as $a) {
            if ($a['id'] === $cid) {
                $name = $a['name'];
            }
        }
        if ($name === '') {
            throw new RuntimeException('Account not found under this Google account.');
        }
    }
    q('INSERT INTO account_shares (owner, conn_id, customer_id, account_name, shared_with, role, created_at) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE role = VALUES(role), account_name = VALUES(account_name)',
      [$owner, $connId, $cid, $name, $with, $role, now()]);
    return ['account_name' => $name, 'shared_with' => $with, 'role' => $role];
}

/** Remove a share: both the owner and the recipient can remove it */
function share_remove(string $user, int $id): ?array
{
    $user = strtolower($user);
    $r = q('SELECT * FROM account_shares WHERE id = ? AND (owner = ? OR shared_with = ?)', [$id, $user, $user])->fetch();
    if ($r) {
        q('DELETE FROM account_shares WHERE id = ?', [$id]);
    }
    return $r ?: null;
}

function account_set_status(string $connId, string $cid, string $status): void
{
    $d = accounts_cached($connId);
    if (isset($d['accounts'])) {
        foreach ($d['accounts'] as &$a) {
            if ($a['id'] === $cid) {
                $a['status'] = $status;
            }
        }
        unset($a);
        q('UPDATE account_cache SET data = ? WHERE conn_id = ?', [json_encode($d), $connId]);
    }
    q('UPDATE ads_accounts SET status = ? WHERE conn_id = ? AND customer_id = ?', [$status, $connId, $cid]);
}
