<?php
/**
 * JSON API used by the dashboard
 * GET  api.php?action=...&conn=..&cid=..&from=..&to=..
 * POST api.php?action=...   body: JSON, header X-CSRF
 *
 * Every request checks that the logged-in user's own Google connection (or a share)
 * has access to the account (cid) - users can never touch someone else's account.
 */
// Return PHP fatal errors as JSON so the UI shows the real cause
ini_set('display_errors', '0');
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'PHP error: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')',
                          'hint' => 'Re-upload all files (the whole lib/ folder, api.php, index.php, assets/). PHP 8.0+ is required.'],
                         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_logged_in()) {
    out(['error' => 'Login required'], 401);
}

$action = $_GET['action'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$IN     = $isPost ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;
$demo   = is_demo();

if ($isPost && !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) {
    out(['error' => 'Session expired. Refresh the page.'], 403);
}

// Only these read-only actions may run via GET. Every write action must be POST (which enforces CSRF above),
// so a crafted link can't trigger changes in a logged-in user's session.
const GET_SAFE = ['me', 'accounts', 'connections', 'overview', 'report', 'breakdown', 'campaign', 'adgroups',
    'ads', 'geo_search', 'languages', 'targeting', 'keywords', 'search_terms', 'account_settings', 'changes',
    'rules', 'rotators', 'rotator_items', 'rotator_log', 'rotator_report', 'mcc_list', 'mcc_clients', 'shares', 'share_users',
    'access_list', 'users', 'conversions', 'conversion_status', 'conversion_tag', 'smart_analyze', 'twofa_status', 'clone_targets', 'brand_get',
    'networks', 'optimizer', 'affiliate_conversions', 'affiliate_report', 'presets', 'billing'];
$isWrite = !in_array($action, GET_SAFE, true);
// During a DB outage a recent session is trusted for reads only. Any write by an
// unverifiable user is refused (fail closed) - a disabled/demoted user can't slip a change through.
if ($isWrite && !auth_verified()) {
    out(['error' => 'Account verification is temporarily unavailable (database issue). Please retry in a moment.'], 503);
}
if (!$isPost && $isWrite) {
    out(['error' => 'This action requires a POST request.'], 405);
}

// Until a newly-created user finishes first-login setup (profile + 2FA), only the
// onboarding actions are allowed - the rest of the app stays locked.
const ONBOARD_ALLOWED = ['me', 'twofa_status', 'twofa_setup', 'twofa_enable', 'onboard_complete'];
if (user_needs_onboarding(current_user()) && !in_array($action, ONBOARD_ALLOWED, true)) {
    out(['error' => 'Finish setting up your account first (profile and two-factor authentication).', 'onboard' => true], 403);
}

// ---------- helpers ----------
function p(string $k, string $type = 'str')
{
    global $IN;
    $v = $IN[$k] ?? null;
    return match ($type) {
        'id'  => preg_replace('/\D/', '', (string)$v),
        'key' => preg_replace('/\W/', '', (string)$v),
        default => is_string($v) ? trim($v) : $v,
    };
}

function need_id(string $k): string
{
    $v = p($k, 'id');
    if ($v === '') {
        out(['error' => "$k missing"], 400);
    }
    return $v;
}

function dates(): array
{
    $to   = p('to') ?: date('Y-m-d', strtotime('-1 day'));
    $from = p('from') ?: date('Y-m-d', strtotime('-7 days'));
    foreach ([$from, $to] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) {
            out(['error' => 'Invalid date'], 400);
        }
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
    return [$from, $to, $days];
}

/** Service for the current account (with status check) */
function svc(bool $forWrite = false)
{
    global $demo, $CONFIG;
    $conn = p('conn', 'key');
    $cid  = need_id('cid');
    if ($forWrite && empty($CONFIG['allow_changes'])) {
        out(['error' => 'Changes are disabled (config.php: allow_changes = false).'], 403);
    }
    if ($demo || str_starts_with($conn, 'demo')) {
        if (!$demo) {
            out(['error' => 'Invalid account'], 400);
        }
        foreach (DemoData::accounts() as $a) {
            if ($a['id'] === $cid && $a['status'] !== 'ENABLED') {
                out(['blocked' => $a['status'], 'name' => $a['name']]);
            }
        }
        return new AdsDemo($cid);
    }
    global $ACC;
    [$client, $acc] = resolve_account(me(), $conn, $cid);
    $ACC = $acc;
    if (($acc['status'] ?? 'ENABLED') !== 'ENABLED') {
        out(['blocked' => $acc['status'], 'name' => $acc['name']]);
    }
    if ($forWrite && ($acc['access'] ?? 'owner') === 'view') {
        out(['error' => 'You have view-only access to this account (shared by ' . ($acc['shared_by'] ?? '?') . '). Ask the owner for "Can edit" access.'], 403);
    }
    return new AdsReal($client, $cid);
}

/** Only the connection owner can manage Google Ads users/invitations */
function svc_owner()
{
    global $ACC, $demo;
    $s = svc(true);
    if (!$demo && ($ACC['access'] ?? 'owner') !== 'owner') {
        out(['error' => 'Only the user who connected this Google account can manage Google Ads access.'], 403);
    }
    return $s;
}

function logc(string $what): void
{
    global $demo;
    log_change(me(), p('cid', 'id'), $what, $demo);
}

function need_admin(): void
{
    if (!is_admin()) {
        out(['error' => 'Admins only.'], 403);
    }
}

/**
 * Guard a user-management action against the target username.
 * - The config super-admin can never be managed by anyone (it lives in config.php).
 * - A regular admin can only manage the users they created; the super-admin manages all.
 */
function guard_user_target(string $username): void
{
    global $CONFIG;
    $target = strtolower(trim($username));
    if ($target === '' || $target === strtolower((string)$CONFIG['app_user'])) {
        out(['error' => 'This account cannot be managed here.'], 403);
    }
    if (!is_super() && user_created_by($target) !== me()) {
        out(['error' => 'You can only manage users you created.'], 403);
    }
}

try {
    switch ($action) {

        // ================= Session / accounts =================
        case 'me':
            $u = current_user();
            out(['user' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'demo' => $demo,
                 'can_edit' => !empty($CONFIG['allow_changes']), 'onboard' => user_needs_onboarding($u)]);

        case 'accounts':
            if ($demo) {
                out(['demo' => true, 'demo_banner' => ($CONFIG['demo_banner'] ?? true) !== false, 'accounts' => DemoData::accounts(), 'errors' => []]);
            }
            $accounts = [];
            $errors = [];
            foreach (conns_load(me()) as $c) {
                try {
                    $d = accounts_data($c, !empty($IN['refresh']));
                    foreach ($d['accounts'] as $a) {
                        $accounts[] = $a + ['conn' => $c['id'], 'email' => $c['email'], 'access' => 'owner'];
                    }
                    foreach ($d['roots'] as $r) {
                        if ($r['type'] === 'Error') {
                            $errors[] = $c['email'] . ' → ' . fmt_cid($r['id']) . ': ' . $r['error'];
                        }
                    }
                } catch (Throwable $e) {
                    $m = $e->getMessage();
                    $errors[] = $c['email'] . ': ' . (str_contains($m, 'invalid_grant') ? 'access expired or was revoked. Reconnect this Google account.' : $m);
                }
            }
            foreach (shared_accounts(me(), $errors) as $a) {
                $accounts[] = $a;
            }
            out(['demo' => false, 'accounts' => $accounts, 'errors' => $errors]);

        case 'connections':
            $list = [];
            foreach (conns_load(me()) as $c) {
                $d = accounts_cached($c['id']);
                $list[] = ['id' => $c['id'], 'email' => $c['email'], 'created' => $c['created'],
                           'accounts' => isset($d['accounts']) ? count($d['accounts']) : null, 'roots' => $d['roots'] ?? []];
            }
            out(['connections' => $list]);

        // ================= All accounts overview (from MySQL) =================
        case 'overview':
            [$from, $to] = dates();
            if ($demo) {
                $accs = [];
                $dailyAll = [];
                foreach (DemoData::accounts() as $a) {
                    $row = ['conn_id' => $a['conn'], 'customer_id' => $a['id'], 'name' => $a['name'], 'currency' => $a['currency'],
                            'status' => $a['status'], 'via' => $a['via'], 'email' => $a['email'], 'last_sync' => now(), 'sync_error' => null]
                         + ZERO_METRICS + ['active_campaigns' => 0];
                    if ($a['status'] === 'ENABLED') {
                        $cs = (new AdsDemo($a['id']))->campaigns($from, $to);
                        $row = array_merge($row, sum_metrics($cs));
                        $row['active_campaigns'] = count(array_filter($cs, fn($c) => $c['cost'] > 0));
                        foreach ((new AdsDemo($a['id']))->report($from, $to, $from, $to)['daily'] as $d) {
                            $k = $d['date'] . $a['currency'];
                            $dailyAll[$k] = sum_metrics([$dailyAll[$k] ?? ZERO_METRICS, $d]) + ['date' => $d['date'], 'currency' => $a['currency']];
                        }
                    }
                    $accs[] = $row;
                }
                usort($accs, fn($x, $y) => $y['cost'] <=> $x['cost']);
                ksort($dailyAll);
                out(['demo' => true, 'accounts' => $accs, 'daily' => array_values($dailyAll), 'from' => $from, 'to' => $to]);
            }
            out(overview_data(me(), $from, $to) + ['demo' => false, 'from' => $from, 'to' => $to]);

        case 'sync_account':
            $conn = p('conn', 'key');
            $cid  = need_id('cid');
            $days = p('days') ? (int)p('days') : null;
            if ($demo) {
                $d = max(1, min(365, $days ?? 30));
                $n = count((new AdsDemo($cid))->campaignDaily(date('Y-m-d', strtotime('-' . ($d - 1) . ' days')), date('Y-m-d')));
                out(['ok' => true, 'rows' => $n, 'demo' => true]);
            }
            @set_time_limit(120);
            out(['ok' => true] + sync_account(me(), $conn, $cid, $days));

        // ================= Dashboard =================
        case 'report':
            $s = svc();
            [$from, $to, $days] = dates();
            $prevTo = date('Y-m-d', strtotime($from) - 86400);
            $prevFrom = date('Y-m-d', strtotime($from) - 86400 * $days);
            $r = $s->report($from, $to, $prevFrom, $prevTo);
            $byDate = array_column($r['daily'], null, 'date');
            $filled = [];
            for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
                $k = date('Y-m-d', $d);
                $filled[] = $byDate[$k] ?? ['date' => $k] + ZERO_METRICS;
            }
            $r['daily'] = $filled;
            $r['totals'] = sum_metrics($filled);
            out($r + ['from' => $from, 'to' => $to, 'days' => $days]);

        // ================= Billing (spend summary for the payments profile) =================
        case 'billing':
            $s = svc();
            [$from, $to] = dates();
            $prof = array_filter(array_merge(['name' => 'Click Orbits Private Limited'], (array)($CONFIG['billing_profile'] ?? [])),
                fn($v) => trim((string)$v) !== '');
            $rate = (float)($CONFIG['billing_tax_rate'] ?? 18);
            $tax = fn(float $c) => ['tax' => round($c * $rate / 100, 2), 'total' => round($c * (1 + $rate / 100), 2)];
            $sumRows = function (array $rows) use ($tax): array {
                $months = [];
                $camps = [];
                $daily = [];
                foreach ($rows as $r) {
                    $m = substr($r['date'], 0, 7);
                    $months[$m] ??= ['month' => $m] + ZERO_METRICS + ['camp_ids' => []];
                    $camps[$r['campaign_id']] ??= ['id' => (string)$r['campaign_id'], 'name' => $r['name'], 'status' => $r['status']] + ZERO_METRICS;
                    $daily[$r['date']] ??= ['date' => $r['date'], 'cost' => 0];
                    foreach (ZERO_METRICS as $k => $_) {
                        $months[$m][$k] += $r[$k];
                        $camps[$r['campaign_id']][$k] += $r[$k];
                    }
                    $daily[$r['date']]['cost'] += $r['cost'];
                    if ($r['cost'] > 0) $months[$m]['camp_ids'][$r['campaign_id']] = 1;
                }
                ksort($months);
                ksort($daily);
                $months = array_map(function ($x) use ($tax) {
                    $x['campaigns'] = count($x['camp_ids']);
                    unset($x['camp_ids']);
                    $x['cost'] = round($x['cost'], 2);
                    return $x + $tax($x['cost']);
                }, array_values($months));
                $camps = array_values(array_map(fn($c) => array_merge($c, ['cost' => round($c['cost'], 2)]), $camps));
                usort($camps, fn($a, $b) => $b['cost'] <=> $a['cost']);
                $t = sum_metrics($months);
                return ['totals' => $t + $tax($t['cost']), 'months' => $months, 'campaigns' => $camps,
                        'daily' => array_values(array_map(fn($d) => ['date' => $d['date'], 'cost' => round($d['cost'], 2)], $daily))];
            };
            $range = $sumRows($s->campaignDaily($from, $to));
            $life = null;
            if ($demo) { // whole history of the demo account
                $d = TrivagoData::load();
                if ($d['min']) {
                    $l = $sumRows($s->campaignDaily($d['min'], $d['max']));
                    $life = ['from' => $d['min'], 'to' => $d['max'], 'months' => $l['months']] + $l['totals'];
                }
            }
            out(['profile' => $prof, 'tax_rate' => $rate, 'currency' => 'INR', 'from' => $from, 'to' => $to,
                 'range' => $range['totals'], 'months' => $range['months'], 'campaigns' => $range['campaigns'],
                 'daily' => $range['daily'], 'lifetime' => $life]);

        case 'breakdown':
            [$from, $to] = dates();
            out(['rows' => svc()->breakdown(p('type'), $from, $to)]);

        // ================= Campaign =================
        case 'campaign':
            out(['campaign' => svc()->campaign(need_id('campaign_id'))]);

        case 'set_campaign_status':
            $st = p('status') === 'PAUSED' ? 'PAUSED' : 'ENABLED';
            svc(true)->setCampaignStatus(need_id('campaign_id'), $st);
            logc('Campaign ' . p('name') . " -> $st");
            out(['ok' => true, 'status' => $st]);

        case 'update_campaign':
            $done = svc(true)->updateCampaign(need_id('campaign_id'), (array)p('data'));
            logc('Campaign ' . p('name') . ' updated: ' . (implode(', ', $done) ?: 'no change'));
            out(['ok' => true, 'changed' => $done]);

        // ================= Ad groups =================
        case 'adgroups':
            [$from, $to] = dates();
            out(['adgroups' => svc()->adGroups(need_id('campaign_id'), $from, $to)]);

        case 'create_adgroup':
            $id = svc(true)->createAdGroup(need_id('campaign_id'), (array)p('data'));
            logc('Ad group created: ' . (p('data')['name'] ?? '') . " ($id)");
            out(['ok' => true, 'id' => $id]);

        case 'update_adgroup':
            $done = svc(true)->updateAdGroup(need_id('ag_id'), (array)p('data'));
            logc('Ad group ' . p('name') . ' updated: ' . implode(', ', $done));
            out(['ok' => true, 'changed' => $done]);

        // ================= Ads =================
        case 'ads':
            [$from, $to] = dates();
            out(svc()->ads(need_id('campaign_id'), $from, $to));

        case 'update_ad':
            $done = svc(true)->updateAd(need_id('ag_id'), need_id('ad_id'), (array)p('data'));
            logc('Ad ' . p('ad_id', 'id') . ' updated: ' . implode(', ', $done));
            out(['ok' => true, 'changed' => $done]);

        case 'update_asset_group':
            $done = svc(true)->updateAssetGroup(need_id('asset_group_id'), (array)p('data'));
            logc('Asset group ' . p('name') . ' updated: ' . implode(', ', $done));
            out(['ok' => true, 'changed' => $done]);

        // ================= New campaign / RSA / targeting =================
        case 'create_campaign':
            $vo = (bool)p('validate_only');
            $r = svc(!$vo)->createSearchCampaign((array)p('data'), $vo);
            if (!$vo) {
                logc("Campaign created (PAUSED): {$r['name']} ({$r['campaign_id']}), {$r['keywords']} keyword(s)");
            }
            out(['ok' => true] + $r);

        case 'create_rsa':
            $id = svc(true)->createRsa(need_id('ag_id'), (array)p('data'));
            logc('RSA ad created in ad group ' . p('ag_id', 'id') . " (ad $id)");
            out(['ok' => true, 'id' => $id]);

        case 'smart_analyze':
            @set_time_limit(90);
            $s = svc();
            out(smart_analyze((string)p('url'), $s, strtoupper((string)p('currency'))));

        case 'conversion_status':
            out(svc()->conversionStatus());

        // ================= Conversion tracking =================
        case 'conversions':
            [$from, $to] = dates();
            out(svc()->conversions($from, $to) + ['types' => CONV_TYPES, 'categories' => CONV_CATEGORIES]);

        case 'conversion_create':
            $r = svc(true)->createConversion((array)p('data'));
            logc('Conversion action created: ' . $r['name']);
            out(['ok' => true] + $r);

        case 'conversion_update':
            $done = svc(true)->updateConversion(need_id('conversion_id'), (array)p('data'));
            logc('Conversion action "' . p('name') . '" updated: ' . (implode(', ', $done) ?: 'no change'));
            out(['ok' => true, 'changed' => $done]);

        case 'conversion_tag':
            out(svc()->conversionTag(need_id('conversion_id')));

        case 'geo_search':
            out(['results' => svc()->searchGeo((string)p('q'))]);

        case 'languages':
            out(['languages' => svc()->languages(), 'common_geos' => COMMON_GEOS]);

        case 'targeting':
            out(svc()->targeting(need_id('campaign_id')));

        case 'update_targeting':
            $r = svc(true)->updateTargeting(need_id('campaign_id'), (array)p('data'));
            logc('Campaign ' . p('name') . " targeting updated: +{$r['added']} / -{$r['removed']}" . ($r['geo_type_changed'] ? ', location option changed' : ''));
            out(['ok' => true] + $r);

        // ================= Manager account: new accounts =================
        case 'mcc_list':
            $list = $demo ? [['conn' => 'demo1', 'email' => 'demo@adhookmedia.com', 'id' => '9990001111', 'name' => 'Demo MCC', 'count' => 3]] : mcc_list(me());
            out(['mccs' => $list, 'currencies' => MCC_CURRENCIES, 'timezones' => MCC_TIMEZONES, 'max' => MCC_MAX_BATCH]);

        case 'mcc_create':
            $vo = (bool)p('validate_only');
            if (!$vo && empty($CONFIG['allow_changes'])) {
                out(['error' => 'Changes are disabled (config.php: allow_changes = false).'], 403);
            }
            @set_time_limit(300);
            $mcc = need_id('mcc');
            $r = mcc_create_clients(me(), p('conn', 'key'), $mcc, (array)p('rows'), (array)p('defaults'), $vo, $demo);
            if (!$vo && $r['created']) {
                $ids = implode(', ', array_filter(array_map(fn($x) => $x['ok'] ? $x['name'] . ' (' . $x['customer_id'] . ')' : '', $r['results'])));
                log_change(me(), $mcc, "Created {$r['created']} account(s) under manager account: " . mb_strimwidth($ids, 0, 1500, '…'), $demo);
            }
            out(['ok' => true] + $r);

        // ================= Manager account: link / unlink existing accounts =================
        case 'mcc_clients': // accounts currently linked to a manager account
            if ($demo) {
                out(['clients' => [
                    ['customer_id' => '4445556666', 'status' => 'ACTIVE', 'manager_link_id' => '111', 'name' => 'Lelaha Fashion', 'currency' => 'INR', 'hidden' => false],
                    ['customer_id' => '7778889999', 'status' => 'PENDING', 'manager_link_id' => '112', 'name' => 'ProvaDent US', 'currency' => 'USD', 'hidden' => false],
                ]]);
            }
            out(['clients' => mcc_linked_clients(me(), p('conn', 'key'), need_id('mcc'))]);

        case 'mcc_link':
            if (empty($CONFIG['allow_changes'])) {
                out(['error' => 'Changes are disabled (config.php: allow_changes = false).'], 403);
            }
            @set_time_limit(300);
            $mcc = need_id('mcc');
            $r = mcc_link_clients(me(), p('conn', 'key'), $mcc, (array)($IN['client_ids'] ?? []), (bool)($IN['auto_accept'] ?? false), $demo);
            if ($r['linked']) {
                log_change(me(), $mcc, "Linked {$r['linked']} existing account(s) to manager account", $demo);
            }
            out(['ok' => true] + $r);

        case 'mcc_unlink':
            if (empty($CONFIG['allow_changes'])) {
                out(['error' => 'Changes are disabled (config.php: allow_changes = false).'], 403);
            }
            $mcc = need_id('mcc');
            $clientId = need_id('client_id');
            mcc_unlink_client(me(), p('conn', 'key'), $mcc, $clientId, (string)p('manager_link_id'), $demo);
            log_change(me(), $mcc, 'Unlinked account ' . fmt_cid($clientId) . ' from manager account', $demo);
            out(['ok' => true]);

        // ================= Sharing (tool users) =================
        case 'shares':
            out(['given' => shares_given(me()), 'received' => shares_received(me())]);

        case 'share_users':
            // Who you may share an account with. The config super-admin shares with anyone
            // (and appears in its own list); everyone else sees only their own org — the users
            // they created plus their siblings (same creator) — and never the super-admin.
            $list = [];
            if (is_super()) {
                $list[] = ['username' => strtolower($CONFIG['app_user']), 'name' => 'Super admin'];
                $pool = users_all();
            } else {
                $mine = me();
                $parent = user_created_by($mine);
                $allowed = array_filter([$mine, $parent], fn($x) => $x !== '');
                $pool = array_filter(users_all(), fn($u) => in_array($u['created_by'] ?? '', $allowed, true));
            }
            foreach ($pool as $u) {
                if (empty($u['disabled']) && $u['username'] !== me()) {
                    $list[] = ['username' => $u['username'], 'name' => $u['name']];
                }
            }
            out(['users' => array_values($list)]);

        case 'share_add':
            $cidS = p('cid') === '*' ? '*' : need_id('cid');
            $r = share_add(me(), p('conn', 'key'), $cidS, (string)p('username'), (string)p('role'));
            log_change(me(), $cidS === '*' ? '' : $cidS, "Shared {$r['account_name']} with {$r['shared_with']} ({$r['role']})");
            out(['ok' => true] + $r);

        case 'share_remove':
            $r = share_remove(me(), (int)p('id'));
            if ($r) {
                log_change(me(), $r['customer_id'] === '*' ? '' : $r['customer_id'], "Share removed: {$r['account_name']} / {$r['shared_with']}");
            }
            out(['ok' => (bool)$r]);

        // ================= Google Ads account access =================
        case 'access_list':
            out(svc_owner()->userAccess());

        case 'access_invite':
            svc_owner()->inviteUser((string)p('email'), (string)p('role'));
            logc('Google Ads invite sent: ' . p('email') . ' (' . p('role') . ')');
            out(['ok' => true]);

        case 'access_revoke':
            svc_owner()->revokeInvite(need_id('invite_id'));
            logc('Google Ads invite revoked: ' . p('email'));
            out(['ok' => true]);

        case 'access_role':
            svc_owner()->setUserRole(need_id('user_id'), (string)p('role'));
            logc('Google Ads user role: ' . p('email') . ' -> ' . p('role'));
            out(['ok' => true]);

        case 'access_remove':
            svc_owner()->removeUser(need_id('user_id'));
            logc('Google Ads user removed: ' . p('email'));
            out(['ok' => true]);

        // ================= Keywords =================
        case 'keywords':
            [$from, $to] = dates();
            out(['keywords' => svc()->keywords(need_id('campaign_id'), $from, $to)]);

        case 'add_keywords':
            $lines = preg_split('/\r?\n|,/', (string)p('keywords'));
            $n = svc(true)->addKeywords(need_id('ag_id'), $lines, (string)p('match'), p('cpc_bid'), (string)p('final_url'));
            logc("$n keyword(s) added to ad group " . p('ag_id', 'id'));
            out(['ok' => true, 'added' => $n]);

        case 'update_keyword':
            $done = svc(true)->updateKeyword(need_id('ag_id'), need_id('kw_id'), (array)p('data'));
            logc('Keyword "' . p('name') . '" updated: ' . (implode(', ', $done) ?: 'no change'));
            out(['ok' => true, 'changed' => $done]);

        // ================= Search terms / negatives =================
        case 'search_terms':
            [$from, $to] = dates();
            $s = svc();
            $cid = need_id('campaign_id');
            out(['terms' => $s->searchTerms($cid, $from, $to), 'negatives' => $s->negatives($cid)]);

        case 'add_negatives':
            $terms = is_array(p('terms')) ? p('terms') : preg_split('/\r?\n/', (string)p('terms'));
            $n = svc(true)->addNegatives(need_id('campaign_id'), $terms, (string)p('match'));
            logc("$n negative keyword(s) added");
            out(['ok' => true, 'added' => $n]);

        case 'remove_negative':
            svc(true)->removeNegative(need_id('campaign_id'), need_id('neg_id'));
            logc('Negative keyword removed: ' . p('name'));
            out(['ok' => true]);

        // ================= Account + tools =================
        case 'account_settings':
            out(['settings' => svc()->accountSettings()]);

        case 'update_account_settings':
            svc(true)->updateAccountSettings((array)p('data'));
            logc('Account URL settings updated');
            out(['ok' => true]);

        case 'bulk_url':
            $apply = (bool)p('apply');
            $s = svc($apply);
            $r = $s->bulkUrlReplace((string)($IN['find'] ?? ''), (string)($IN['replace'] ?? ''), p('campaign_id', 'id') ?: null, $apply);
            if ($apply) {
                logc("Bulk final URL replace: \"{$IN['find']}\" -> \"{$IN['replace']}\" ({$r['applied']} updated, {$r['failed']} failed)");
            }
            out($r);

        case 'bulk_tracking':
            $r = svc(true)->bulkTracking(p('scope') === 'account' ? 'account' : 'campaigns', (array)p('data'));
            logc("Bulk tracking update ({$r['scope']}): {$r['updated']}");
            out(['ok' => true] + $r);

        case 'changes':
            out(['changes' => read_changes(me())]);

        // ================= Automation =================
        case 'rules':
            out(['rules' => rules_for(me()), 'metrics' => RULE_METRICS]);

        case 'rule_save':
            $data = (array)p('data');
            // Access check for the account
            service_for(me(), preg_replace('/\W/', '', $data['conn'] ?? ''), preg_replace('/\D/', '', $data['cid'] ?? ''), true);
            out(['ok' => true, 'rule' => rule_save(me(), $data)]);

        case 'rule_delete':
            rule_delete(me(), p('id', 'key'));
            out(['ok' => true]);

        case 'rule_run':
            $apply = (bool)p('apply');
            if ($apply && empty($CONFIG['allow_changes'])) {
                out(['error' => 'Changes are disabled (allow_changes = false).'], 403);
            }
            $rule = null;
            foreach (rules_for(me()) as $r) {
                if ($r['id'] === p('id', 'key')) {
                    $rule = $r;
                }
            }
            if (!$rule) {
                out(['error' => 'Rule not found'], 404);
            }
            out(rule_run($rule, $apply));

        // ================= Suffix Rotator =================
        case 'rotators':
            $list = rotators_for(me());
            foreach ($list as &$r) {
                $r['in_window'] = rotator_in_window($r);
            }
            unset($r);
            out(['rotators' => $list, 'now' => now(), 'timezone' => date_default_timezone_get()]);

        case 'rotator_parse':
            // multipart upload (file) + optional pasted text
            $res = parse_suffix_source($_FILES['file'] ?? null, (string)($_POST['text'] ?? ''));
            if (!$res['items']) {
                out(['error' => 'No URL or suffix found in the file. Each row needs a URL like https://site.com/?param=value&...' .
                                ($res['skipped'] ? " ({$res['skipped']} rows skipped)" : '')], 400);
            }
            // When editing an existing rotator, also report duplicates against its current + used list
            $dedup = ($rid = (int)($_POST['rotator_id'] ?? 0)) ? rotator_dedup_preview(me(), $rid, $res['items']) : null;
            out($res + ['count' => count($res['items']), 'dedup' => $dedup]);

        case 'rotator_items':
            $r = rotator_get(me(), (int)p('id'));
            $items = q('SELECT seq, suffix, used_count, last_used_at FROM suffix_items WHERE rotator_id = ? ORDER BY seq LIMIT 2000', [$r['id']])->fetchAll();
            out(['items' => $items]);

        case 'rotator_save':
            $data = (array)p('data');
            if (!empty($data['active']) && empty($CONFIG['allow_changes'])) {
                out(['error' => 'Changes are disabled (allow_changes = false).'], 403);
            }
            $conn = preg_replace('/\W/', '', $data['conn'] ?? '');
            $cid = preg_replace('/\D/', '', $data['cid'] ?? '');
            if ($conn && $cid) {
                service_for(me(), $conn, $cid, true); // access check (edit required)
            }
            out(['ok' => true, 'rotator' => rotator_save(me(), $data)]);

        case 'rotator_toggle':
            $r = rotator_get(me(), (int)p('id'));
            if (p('active') && empty($CONFIG['allow_changes'])) {
                out(['error' => 'Changes are disabled (allow_changes = false).'], 403);
            }
            q('UPDATE suffix_rotators SET active = ?, next_run_at = ? WHERE id = ?', [p('active') ? 1 : 0, p('active') ? now() : $r['next_run_at'], $r['id']]);
            out(['ok' => true]);

        case 'rotator_reset':
            $r = rotator_get(me(), (int)p('id'));
            q('UPDATE suffix_rotators SET pos = 0 WHERE id = ?', [$r['id']]);
            out(['ok' => true]);

        case 'rotator_delete':
            rotator_delete(me(), (int)p('id'));
            out(['ok' => true]);

        case 'rotator_run':
            if (empty($CONFIG['allow_changes'])) {
                out(['error' => 'Changes are disabled (allow_changes = false).'], 403);
            }
            out(['ok' => true] + rotator_apply(rotator_get(me(), (int)p('id')), 'manual'));

        case 'rotator_log':
            $r = rotator_get(me(), (int)p('id'));
            out(['log' => q('SELECT item_seq, suffix, applied_at, ok, message, source FROM suffix_log WHERE rotator_id = ? ORDER BY id DESC LIMIT 500', [$r['id']])->fetchAll()]);

        case 'rotator_report':
            out(rotator_report(me(), (int)p('id')));

        case 'rotator_trash': // retire (clicked=1) or restore suffixes so they stop rotating
            $set = array_key_exists('clicked', $IN) ? (bool)$IN['clicked'] : true;
            $n = rotator_items_set_clicked(me(), (int)p('id'), (array)($IN['seqs'] ?? []), $set);
            out(['ok' => true, 'n' => $n]);

        // ================= Users (admin) =================
        case 'users':
            need_admin();
            $list = [];
            $conns = conns_all();
            // The config super-admin sees every user; a regular admin sees only the users
            // they created. The config super-admin itself is never a DB row, so it is never
            // visible to anyone else.
            $scope = is_super() ? null : me();
            foreach (users_all($scope) as $u) {
                $list[] = ['username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'],
                           'created' => $u['created'], 'disabled' => !empty($u['disabled']),
                           'twofa' => user_has_totp($u['username']),
                           'connections' => count(array_filter($conns, fn($c) => $c['owner'] === $u['username']))];
            }
            out(['users' => $list, 'super' => is_super() ? strtolower($CONFIG['app_user']) : '']);

        case 'user_create':
            need_admin();
            user_create((string)p('username'), (string)p('name'), (string)($IN['password'] ?? ''), (string)p('role'), me());
            out(['ok' => true]);

        case 'user_update':
            need_admin();
            guard_user_target((string)p('username'));
            $f = [];
            if (p('name') !== null) {
                $f['name'] = p('name');
            }
            if (in_array(p('role'), ['admin', 'user'], true)) {
                $f['role'] = p('role');
            }
            if (isset($IN['disabled'])) {
                $f['disabled'] = (bool)$IN['disabled'];
            }
            if (!empty($IN['password'])) {
                $f['password'] = (string)$IN['password'];
            }
            user_update((string)p('username'), $f);
            out(['ok' => true]);

        case 'user_delete':
            need_admin();
            guard_user_target((string)p('username'));
            user_delete((string)p('username'));
            out(['ok' => true]);

        case 'impersonate_link': // super-admin: one-time link to log in as a user (open in incognito)
            if (!is_super()) {
                out(['error' => 'Super admin only.'], 403);
            }
            $target = strtolower((string)p('username'));
            if ($target === strtolower((string)$CONFIG['app_user'])) {
                out(['error' => 'That is the super admin.'], 400);
            }
            $tok = impersonate_token_create($target, me());
            $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $dir . '/';
            out(['ok' => true, 'url' => $base . '?as=' . $tok, 'expires_min' => 5]);

        case 'impersonate_start': // super-admin: view a user's account in-session (one click; Exit restores super)
            if (!is_super()) {
                out(['error' => 'Super admin only.'], 403);
            }
            $target = user_clean_name((string)p('username'));
            if ($target === strtolower((string)$CONFIG['app_user'])) {
                out(['error' => 'That is the super admin.'], 400);
            }
            $row = q('SELECT username, name, role, disabled, session_epoch FROM users WHERE username = ?', [$target])->fetch();
            if (!$row || !empty($row['disabled'])) {
                out(['error' => 'User not found or disabled.'], 404);
            }
            $_SESSION['impersonator'] = $_SESSION['auth']; // remember the super to return to
            $_SESSION['auth'] = ['username' => $row['username'], 'name' => $row['name'], 'role' => $row['role'],
                                 'super' => false, 'epoch' => (int)$row['session_epoch']];
            $_SESSION['auth_checked_at'] = time();
            unset($_SESSION['demo']);
            out(['ok' => true]);

        case 'impersonate_stop': // return to the super-admin session
            if (!empty($_SESSION['impersonator'])) {
                $_SESSION['auth'] = $_SESSION['impersonator'];
                unset($_SESSION['impersonator']);
                $_SESSION['auth_checked_at'] = time();
            }
            out(['ok' => true]);

        case 'change_password':
            $u = current_user();
            if (!empty($u['super'])) {
                out(['error' => 'Change the super admin password in config.php.'], 400);
            }
            if (!user_login($u['username'], (string)($IN['current'] ?? ''))) {
                out(['error' => 'Current password is incorrect.'], 400);
            }
            user_update($u['username'], ['password' => (string)($IN['new'] ?? '')]);
            out(['ok' => true]);

        // ================= Two-factor auth (TOTP) =================
        case 'twofa_status':
            $u = current_user();
            out(['super' => !empty($u['super']), 'on' => empty($u['super']) && user_has_totp($u['username'])]);

        case 'twofa_setup': // generate a secret (held in session until confirmed) + show URI/key
            $u = current_user();
            if (!empty($u['super'])) {
                out(['error' => 'The super admin signs in directly and does not use app 2FA.'], 400);
            }
            $secret = totp_new_secret();
            $_SESSION['totp_setup'] = ['secret' => $secret, 'at' => time()];
            out(['secret' => totp_pretty_secret($secret), 'secret_raw' => $secret,
                 'uri' => totp_uri($secret, $u['username'])]);

        case 'twofa_enable': // confirm a code against the pending secret, then turn 2FA on
            $u = current_user();
            if (!empty($u['super'])) {
                out(['error' => 'Not available for the super admin.'], 400);
            }
            $pending = $_SESSION['totp_setup'] ?? null;
            if (!$pending || ($pending['at'] ?? 0) < time() - 600) {
                out(['error' => 'Setup timed out. Start again.'], 400);
            }
            if (!totp_verify((string)$pending['secret'], (string)p('code'))) {
                out(['error' => 'That code is not right. Check the app and try the current 6-digit code.'], 400);
            }
            user_set_totp($u['username'], (string)$pending['secret']);
            unset($_SESSION['totp_setup']);
            out(['ok' => true]);

        case 'twofa_disable': // requires the current password (confirms it's really the user)
            $u = current_user();
            if (!empty($u['super'])) {
                out(['error' => 'Not available for the super admin.'], 400);
            }
            if (!user_login($u['username'], (string)($IN['password'] ?? ''))) {
                out(['error' => 'Password is incorrect.'], 400);
            }
            user_clear_totp($u['username']);
            out(['ok' => true]);

        case 'twofa_reset': // admin clears a user's 2FA (e.g. lost phone)
            need_admin();
            $target = strtolower((string)p('username'));
            if ($target === '' || !q('SELECT 1 FROM users WHERE username = ?', [$target])->fetchColumn()) {
                out(['error' => 'User not found.'], 404);
            }
            user_clear_totp($target);
            out(['ok' => true]);

        // ================= First-login onboarding =================
        case 'onboard_complete':
            $u = current_user();
            if (!$u || !empty($u['super'])) {
                out(['error' => 'Not applicable to this account.'], 400);
            }
            user_finish_onboarding($u['username'], (string)p('name'), (string)($IN['password'] ?? ''));
            // Keep THIS session alive after the password-change epoch bump.
            $newEpoch = (int)q('SELECT session_epoch FROM users WHERE username = ?', [strtolower($u['username'])])->fetchColumn();
            $_SESSION['auth']['epoch'] = $newEpoch;
            $_SESSION['auth']['name'] = trim(preg_replace('/\s+/u', ' ', (string)p('name')));
            $_SESSION['auth_checked_at'] = time();
            out(['ok' => true]);

        // ================= White-label branding (admin) =================
        case 'brand_get':
            need_admin();
            $scope = (string)p('scope');
            $b = $scope === 'user' ? brand_user_get((string)p('username'))
               : ($scope === 'super' ? brand_setting_get('super') : brand_setting_get('default'));
            out(['brand' => $b, 'builtin' => BRAND_BUILTIN]);

        case 'brand_set':
            need_admin();
            $scope = (string)p('scope');
            $clear = !empty($IN['clear']);
            $brand = (array)($IN['brand'] ?? []);
            if ($scope === 'super') {
                $cu = current_user();
                if (empty($cu['super'])) {
                    out(['error' => 'Only the super admin can change their own branding.'], 403);
                }
                brand_setting_save('super', $clear ? null : $brand);
            } elseif ($scope === 'default') {
                brand_setting_save('default', $clear ? null : $brand);
                log_change(me(), '', 'Updated default branding');
            } elseif ($scope === 'user') {
                $un = strtolower((string)p('username'));
                if ($un === '' || !q('SELECT 1 FROM users WHERE username = ?', [$un])->fetchColumn()) {
                    out(['error' => 'User not found.'], 404);
                }
                brand_user_save($un, $clear ? null : $brand);
                log_change(me(), '', "Set branding for user $un");
            } else {
                out(['error' => 'Invalid scope.'], 400);
            }
            out(['ok' => true, 'brand' => $clear ? null : brand_sanitize($brand)]);

        // ================= AI Optimizer + affiliate networks =================
        case 'networks':
            out(['networks' => networks_list(me()),
                 'fields' => ['impact' => network_fields('impact'), 'awin' => network_fields('awin')]]);

        case 'network_save':
            $creds = (array)($IN['creds'] ?? []);
            $id = network_save(me(), (string)p('network'), (string)p('label'), $creds, ($IN['id'] ?? null) ? (int)$IN['id'] : null);
            out(['ok' => true, 'id' => $id]);

        case 'network_remove':
            out(['ok' => network_remove(me(), (int)p('id'))]);

        // ================= Campaign presets =================
        case 'presets': // reusable excluded-locations + negatives, applied in the campaign wizard
            out(['presets' => presets_list(me())]);

        case 'preset_save':
            $pid = preset_save(me(), (string)p('name'), (array)($IN['excluded'] ?? []),
                               (string)($IN['negatives'] ?? ''), ($IN['id'] ?? null) ? (int)$IN['id'] : null);
            out(['ok' => true, 'id' => $pid]);

        case 'preset_remove':
            out(['ok' => preset_remove(me(), (int)p('id'))]);

        case 'network_sync':
            @set_time_limit(300);
            $r = affiliate_sync(me());
            out(['ok' => true] + $r);

        case 'affiliate_conversions': // pulled commission totals + recent list (matched & unmatched)
            out(['summary' => affiliate_summary(me()), 'recent' => affiliate_recent(me(), 80)]);

        case 'affiliate_report': // combined daily report (old + new, matched & unmatched)
            $rf = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-89 days')));
            $rt = (string)($_GET['to'] ?? date('Y-m-d'));
            // Basic YYYY-MM-DD guard; fall back to a 90-day window if malformed.
            $ok = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
            if (!$ok($rf)) {
                $rf = date('Y-m-d', strtotime('-89 days'));
            }
            if (!$ok($rt)) {
                $rt = date('Y-m-d');
            }
            out(affiliate_report(me(), $rf, $rt));

        case 'optimizer':
            [$from, $to, ] = dates();
            if ($demo) {
                out(optimizer_report(me(), need_id('cid'), $from, $to, true));
            }
            $oSvc = svc(false); // resolves the selected account; also blocks disabled accounts
            $live = $oSvc->campaigns($from, $to); // LIVE spend, so new campaigns show without a sync
            out(optimizer_report(me(), need_id('cid'), $from, $to, false, $live));

        // ================= Campaign clone =================
        case 'clone_targets': // accounts the user can create campaigns in (own + shared-with-edit)
            if ($demo) {
                $out = [];
                foreach (DemoData::accounts() as $a) {
                    if ($a['status'] === 'ENABLED') {
                        $out[] = ['conn' => $a['conn'], 'cid' => $a['id'], 'name' => $a['name'],
                                  'currency' => $a['currency'] ?? '', 'access' => 'owner', 'email' => $a['email'] ?? 'demo'];
                    }
                }
                out(['targets' => $out]);
            }
            $me = me();
            $rows = q("SELECT v.conn_id conn, v.customer_id cid, v.name, v.currency, v.access, v.email FROM (
                    SELECT a.conn_id, a.customer_id, a.name, a.currency, 'owner' access, c.email
                    FROM ads_accounts a JOIN connections c ON c.id = a.conn_id AND c.owner = ?
                    WHERE a.status = 'ENABLED'
                  UNION ALL
                    SELECT a.conn_id, a.customer_id, a.name, a.currency, s.role access, c.email
                    FROM account_shares s JOIN connections c ON c.id = s.conn_id AND c.owner = s.owner
                    JOIN ads_accounts a ON a.conn_id = s.conn_id AND (s.customer_id = '*' OR a.customer_id = s.customer_id)
                    WHERE s.shared_with = ? AND s.role = 'edit' AND a.status = 'ENABLED'
                ) v", [$me, $me])->fetchAll();
            $rank = ['owner' => 2, 'edit' => 1];
            $by = [];
            foreach ($rows as $r) {
                $c = $r['cid'];
                if (!isset($by[$c]) || ($rank[$r['access']] ?? 0) > ($rank[$by[$c]['access']] ?? 0)) {
                    $by[$c] = $r;
                }
            }
            $out = array_values($by);
            usort($out, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
            out(['targets' => $out]);

        case 'campaign_clone':
            if (empty($CONFIG['allow_changes'])) {
                out(['error' => 'Changes are disabled (config.php: allow_changes = false).'], 403);
            }
            $srcSvc = svc(false); // source = the currently selected account (conn + cid), read-only
            $campId = need_id('campaign_id');
            $bp = $srcSvc->campaignBlueprint($campId);
            $dests = $IN['destinations'] ?? [];
            if (!is_array($dests) || !$dests) {
                out(['error' => 'Pick at least one destination account.'], 400);
            }
            if (count($dests) > 20) {
                out(['error' => 'Clone to at most 20 accounts at a time.'], 400);
            }
            $opt = ['name' => (string)p('name'), 'budget' => (float)($IN['budget'] ?? 0)];
            $results = [];
            foreach ($dests as $d) {
                $dConn = preg_replace('/\W/', '', (string)($d['conn'] ?? ''));
                $dCid  = preg_replace('/\D/', '', (string)($d['cid'] ?? $d['customer_id'] ?? ''));
                $row = ['conn' => $dConn, 'cid' => $dCid, 'name' => (string)($d['name'] ?? $dCid)];
                try {
                    if ($dConn === '' || $dCid === '') {
                        throw new RuntimeException('Invalid destination account.');
                    }
                    $dSvc = service_for($me = me(), $dConn, $dCid, true);
                    $r = $dSvc->createCampaignFromBlueprint($bp, $opt);
                    log_change($me, $dCid, 'Cloned campaign "' . $bp['name'] . '"' . ($r['campaign_id'] ? ' -> #' . $r['campaign_id'] : ''), str_starts_with($dConn, 'demo'));
                    $row += ['ok' => true] + $r;
                } catch (Throwable $e) {
                    $row += ['ok' => false, 'error' => mb_strimwidth($e->getMessage(), 0, 300, '…')];
                }
                $results[] = $row;
            }
            out(['source' => ['name' => $bp['name'], 'id' => $campId], 'results' => $results]);

        default:
            out(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'CUSTOMER_NOT_ENABLED')) {
        if (!$demo && p('conn') && p('cid')) {
            account_set_status(p('conn', 'key'), p('cid', 'id'), 'NOT_ENABLED');
        }
        out(['blocked' => 'NOT_ENABLED']);
    }
    $hint = '';
    if (str_contains($msg, 'USER_PERMISSION_DENIED')) {
        $hint = 'This Google account no longer has access to this account (or the manager link was removed). Refresh the account list.';
    } elseif (str_contains($msg, 'invalid_grant')) {
        $hint = 'Access for this Google account expired or was revoked. Reconnect it in Settings > Google accounts.';
    } elseif (str_contains($msg, 'invalid_client')) {
        $hint = 'client_id / client_secret is invalid. Check config.php.';
    } elseif (str_contains($msg, 'AUTHORIZATION_ERROR') || str_contains($msg, 'does not have permission')) {
        $hint = 'Permission issue. Check the API access level of your Google Cloud project.';
    } elseif (str_contains($msg, 'MUTATE_NOT_ALLOWED') || str_contains($msg, 'OPERATION_NOT_PERMITTED')) {
        $hint = 'Google does not allow this change for this item (e.g. URL edits on some ad types). Try it in Google Ads.';
    }
    out(['error' => $msg, 'hint' => $hint], 502);
}
