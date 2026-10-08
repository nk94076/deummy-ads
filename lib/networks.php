<?php
/**
 * Affiliate network integration (Impact.com + AWIN).
 * Pulls real conversion/commission data so campaigns can be optimized on actual revenue,
 * not just Google's (often incomplete) conversion tracking.
 *
 *  - Credentials are AES-256-GCM encrypted (same app_key as Google tokens).
 *  - A network account can use DEMO data (no keys) so the flow is testable before real keys.
 *  - Matching: the affiliate sub-id carries the Google campaign id (set via the tracking helper),
 *    so each conversion maps back to the campaign that produced it. gclid/keyword-level
 *    matching via click_view is a later step.
 */

const NETWORKS = ['impact', 'awin'];

/** Required credential fields per network (for the UI + validation). */
function network_fields(string $network): array
{
    return $network === 'impact'
        ? ['account_sid' => 'Account SID', 'auth_token' => 'Auth Token']
        : ['api_token' => 'API token', 'publisher_id' => 'Publisher ID'];
}

/** Connected network accounts for the owner (no secrets). */
function networks_list(string $owner): array
{
    $out = [];
    foreach (q('SELECT id, network, label, status, last_error, last_sync, creds, created_at FROM network_accounts WHERE owner = ? ORDER BY id', [$owner]) as $r) {
        $creds = json_decode(dec_safe($r['creds']), true) ?: [];
        $out[] = ['id' => (int)$r['id'], 'network' => $r['network'], 'label' => $r['label'],
                  'status' => $r['status'], 'last_error' => $r['last_error'], 'last_sync' => $r['last_sync'],
                  'demo' => !empty($creds['demo']), 'created' => $r['created_at']];
    }
    return $out;
}

/** One network account with decrypted creds (owner-scoped). */
function network_get(string $owner, int $id): ?array
{
    $r = q('SELECT * FROM network_accounts WHERE id = ? AND owner = ?', [$id, $owner])->fetch();
    if (!$r) {
        return null;
    }
    $r['creds'] = json_decode(dec_safe($r['creds']), true) ?: [];
    return $r;
}

function dec_safe(string $s): string
{
    try {
        return dec($s);
    } catch (Throwable $e) {
        return '';
    }
}

/** Create/update a network account. $creds is plain (encrypted here). */
function network_save(string $owner, string $network, string $label, array $creds, ?int $id = null): int
{
    if (!in_array($network, NETWORKS, true)) {
        throw new RuntimeException('Unknown network.');
    }
    // Keep only known fields (+ demo flag)
    $clean = [];
    foreach (array_keys(network_fields($network)) as $f) {
        $clean[$f] = trim((string)($creds[$f] ?? ''));
    }
    if (!empty($creds['demo'])) {
        $clean = ['demo' => true];
    } else {
        foreach ($clean as $f => $v) {
            if ($v === '') {
                throw new RuntimeException(network_fields($network)[$f] . ' is required.');
            }
        }
    }
    $enc = enc(json_encode($clean));
    if ($id) {
        q('UPDATE network_accounts SET label = ?, creds = ?, status = ?, last_error = NULL WHERE id = ? AND owner = ?',
          [mb_substr($label, 0, 120), $enc, 'ok', $id, $owner]);
        return $id;
    }
    q('INSERT INTO network_accounts (owner, network, label, creds, status, created_at) VALUES (?,?,?,?,?,?)',
      [$owner, $network, mb_substr($label, 0, 120), $enc, 'ok', now()]);
    return (int)db()->lastInsertId();
}

function network_remove(string $owner, int $id): bool
{
    $n = q('DELETE FROM network_accounts WHERE id = ? AND owner = ?', [$id, $owner])->rowCount();
    return $n > 0;
}

/** Small cURL JSON GET helper for the network REST APIs. */
function network_http(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException("Network request failed: $err");
    }
    $data = json_decode($raw, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['Message'] ?? $data['message'] ?? json_encode($data)) : (string)$raw;
        throw new RuntimeException("API error ($code): " . mb_strimwidth((string)$msg, 0, 200, '…'));
    }
    return is_array($data) ? $data : [];
}

/** First non-empty value among the given keys of an array (for varying API field names). */
function first_nonempty(array $a, array $keys): ?string
{
    foreach ($keys as $k) {
        $v = trim((string)($a[$k] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return null;
}

/** Map a raw status word to PENDING | APPROVED | REVERSED. */
function network_status(string $s): string
{
    $s = strtolower(trim($s));
    if (in_array($s, ['approved', 'confirmed', 'locked'], true)) {
        return 'APPROVED';
    }
    if (in_array($s, ['reversed', 'declined', 'rejected', 'void'], true)) {
        return 'REVERSED';
    }
    return 'PENDING';
}

/** Pull conversions for a window. Returns normalized rows. */
function network_pull(array $acct, string $from, string $to): array
{
    $creds = $acct['creds'];
    if (!empty($creds['demo'])) {
        return network_demo_conversions($acct['owner'], $from, $to);
    }
    $rows = [];
    if ($acct['network'] === 'impact') {
        $sid = $creds['account_sid'];
        $tok = $creds['auth_token'];
        $auth = 'Authorization: Basic ' . base64_encode("$sid:$tok");
        $url = "https://api.impact.com/Mediapartners/$sid/Actions?ActionDateStart={$from}T00:00:00Z&ActionDateEnd={$to}T23:59:59Z&PageSize=1000";
        $data = network_http($url, [$auth]);
        foreach ($data['Actions'] ?? [] as $a) {
            $rows[] = [
                'txn_id' => (string)($a['Id'] ?? ''),
                // Impact's click identifier lives under different keys depending on the report;
                // take the first one present so the click can be traced back in the Impact UI.
                'click_id' => (string)(first_nonempty($a, ['SharedId', 'ClickId', 'ReferringClickId', 'Oid']) ?? ''),
                'sub_id' => (string)($a['SubId1'] ?? ''),
                'gclid' => (string)($a['SubId2'] ?? ''),
                'sale_amount' => (float)($a['Amount'] ?? 0),
                'commission' => (float)($a['Payout'] ?? 0),
                'currency' => (string)($a['CurrencyCode'] ?? ''),
                'status' => network_status((string)($a['State'] ?? '')),
                'conv_date' => substr((string)($a['EventDate'] ?? $to), 0, 10),
            ];
        }
    } else { // awin
        $pid = $creds['publisher_id'];
        $tok = $creds['api_token'];
        $url = "https://api.awin.com/publishers/$pid/transactions/?startDate={$from}T00%3A00%3A00&endDate={$to}T23%3A59%3A59&timezone=UTC&dateType=transaction";
        $data = network_http($url, ["Authorization: Bearer $tok"]);
        foreach ((array)$data as $t) {
            if (!is_array($t)) {
                continue;
            }
            $rows[] = [
                'txn_id' => (string)($t['id'] ?? ''),
                'click_id' => (string)(first_nonempty($t, ['orderRef', 'transactionReference', 'clickRef3']) ?? ''),
                'sub_id' => (string)($t['clickRef'] ?? ''),
                'gclid' => (string)($t['clickRef2'] ?? ''),
                'sale_amount' => (float)($t['saleAmount']['amount'] ?? 0),
                'commission' => (float)($t['commissionAmount']['amount'] ?? 0),
                'currency' => (string)($t['saleAmount']['currency'] ?? ''),
                'status' => network_status((string)($t['commissionStatus'] ?? '')),
                'conv_date' => substr((string)($t['transactionDate'] ?? $to), 0, 10),
            ];
        }
    }
    return $rows;
}

/** Demo conversions tied to the owner's real campaigns (or placeholders) so matching works. */
function network_demo_conversions(string $owner, string $from, string $to): array
{
    // Pick campaigns to attribute demo revenue to: the owner's synced campaigns, else demo ids.
    $camps = [];
    try {
        $camps = q("SELECT DISTINCT d.campaign_id FROM campaign_daily d
                    JOIN ads_accounts a ON a.customer_id = d.customer_id
                    JOIN connections c ON c.id = a.conn_id AND c.owner = ?
                    WHERE d.cost > 0 LIMIT 12", [$owner])->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
    }
    // Only real-looking campaign ids (numeric, 8+ digits); otherwise use placeholders.
    $camps = array_values(array_filter($camps, fn($c) => preg_match('/^\d{8,}$/', (string)$c)));
    if (!$camps) {
        $camps = ['21000000000', '21000000001', '21000000002'];
    }
    mt_srand(crc32($owner . $from)); // stable demo per window
    $rows = [];
    $days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
    $n = min(120, $days * 3);
    for ($i = 0; $i < $n; $i++) {
        $cid = $camps[array_rand($camps)];
        $sale = mt_rand(300, 6000);
        $comm = round($sale * (mt_rand(4, 12) / 100), 2);
        $st = ['APPROVED', 'APPROVED', 'PENDING', 'REVERSED'][mt_rand(0, 3)];
        $rows[] = [
            'txn_id' => 'demo-' . $owner . '-' . $from . '-' . $i,
            'click_id' => 'clk-' . substr(md5($owner . $from . $i), 0, 12),
            'sub_id' => (string)$cid, 'gclid' => '',
            'sale_amount' => (float)$sale, 'commission' => $comm, 'currency' => 'INR',
            'status' => $st, 'conv_date' => date('Y-m-d', strtotime($from) + mt_rand(0, $days - 1) * 86400),
        ];
    }
    return $rows;
}

/**
 * Map a sub-id to a Google campaign id.
 * The sub-id carries the campaign id (set via the tracking template, e.g. {campaignid}).
 * $known = map of the owner's real campaign ids (['24290461372'=>true,...]); when given, a
 * digit-run that matches a known id wins, so a sub-id like "us_24290461372_v2" resolves
 * correctly instead of concatenating its numbers. Falls back to the longest 8+ digit run.
 */
function affiliate_match_campaign(string $subId, ?array $known = null): string
{
    if (!preg_match_all('/\d{8,}/', $subId, $m)) {
        return '';
    }
    $runs = $m[0];
    if ($known) {
        foreach ($runs as $run) {
            if (isset($known[$run])) {
                return $run; // confident: matches a real campaign of this owner
            }
        }
    }
    // No known-id match (campaign maybe not synced yet): take the longest digit run.
    usort($runs, fn($a, $b) => strlen($b) <=> strlen($a));
    return $runs[0];
}

/** Map of the owner's real Google campaign ids (for confident sub-id matching). */
function affiliate_known_campaigns(string $owner): array
{
    $known = [];
    try {
        foreach (q("SELECT DISTINCT d.campaign_id FROM campaign_daily d
                    JOIN ads_accounts a ON a.customer_id = d.customer_id
                    WHERE a.owner = ?", [$owner])->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $known[(string)$cid] = true;
        }
    } catch (Throwable $e) {
    }
    return $known;
}

/** Sync all of the owner's network accounts into affiliate_conversions. */
function affiliate_sync(string $owner, ?callable $log = null, int $days = 45): array
{
    $to = date('Y-m-d');
    $from = date('Y-m-d', strtotime("-" . max(1, $days - 1) . " days"));
    $ok = 0;
    $fail = 0;
    $rows = 0;
    $known = affiliate_known_campaigns($owner); // real campaign ids for confident matching
    foreach (q('SELECT id FROM network_accounts WHERE owner = ?', [$owner])->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $acct = network_get($owner, (int)$id);
        if (!$acct) {
            continue;
        }
        try {
            $conv = network_pull($acct, $from, $to);
            $st = db()->prepare('INSERT INTO affiliate_conversions
                (owner, network, txn_id, click_id, sub_id, gclid, campaign_id, customer_id, sale_amount, commission, currency, status, conv_date, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE click_id=VALUES(click_id), sub_id=VALUES(sub_id), gclid=VALUES(gclid), campaign_id=VALUES(campaign_id),
                  sale_amount=VALUES(sale_amount), commission=VALUES(commission), currency=VALUES(currency),
                  status=VALUES(status), conv_date=VALUES(conv_date), updated_at=VALUES(updated_at)');
            foreach ($conv as $c) {
                $campId = affiliate_match_campaign($c['sub_id'], $known);
                $st->execute([$owner, $acct['network'], $c['txn_id'], $c['click_id'] ?? '', $c['sub_id'], $c['gclid'], $campId, '',
                              $c['sale_amount'], $c['commission'], $c['currency'], $c['status'], $c['conv_date'], now()]);
                $rows++;
            }
            q('UPDATE network_accounts SET last_sync = ?, status = ?, last_error = NULL WHERE id = ?', [now(), 'ok', $id]);
            $log && $log("  {$acct['network']} #{$id}: " . count($conv) . ' conversions');
            $ok++;
        } catch (Throwable $e) {
            $msg = mb_strimwidth($e->getMessage(), 0, 250, '…');
            q('UPDATE network_accounts SET status = ?, last_error = ? WHERE id = ?', ['error', $msg, $id]);
            $log && $log("  {$acct['network']} #{$id}: ERROR $msg");
            $fail++;
        }
    }
    return ['ok' => $ok, 'failed' => $fail, 'rows' => $rows, 'from' => $from, 'to' => $to];
}

/** Totals of pulled conversions over a window (matched vs unmatched to a campaign). */
function affiliate_summary(string $owner, int $days = 60): array
{
    $from = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days'));
    $to = date('Y-m-d');
    $by = [];
    $tot = ['approved' => 0, 'pending' => 0, 'reversed' => 0, 'matched' => 0, 'unmatched' => 0, 'count' => 0, 'currency' => ''];
    foreach (q("SELECT network, currency,
                       SUM(CASE WHEN status='APPROVED' THEN commission ELSE 0 END) approved,
                       SUM(CASE WHEN status='PENDING'  THEN commission ELSE 0 END) pending,
                       SUM(CASE WHEN status='REVERSED' THEN commission ELSE 0 END) reversed,
                       SUM(CASE WHEN campaign_id<>'' AND status<>'REVERSED' THEN commission ELSE 0 END) matched,
                       SUM(CASE WHEN campaign_id='' AND status<>'REVERSED' THEN commission ELSE 0 END) unmatched,
                       COUNT(*) cnt
                FROM affiliate_conversions WHERE owner = ? AND conv_date BETWEEN ? AND ?
                GROUP BY network, currency", [$owner, $from, $to]) as $r) {
        $by[] = ['network' => $r['network'], 'currency' => $r['currency'] ?: '',
                 'approved' => round((float)$r['approved'], 2), 'pending' => round((float)$r['pending'], 2),
                 'reversed' => round((float)$r['reversed'], 2), 'matched' => round((float)$r['matched'], 2),
                 'unmatched' => round((float)$r['unmatched'], 2), 'count' => (int)$r['cnt']];
        $tot['approved'] += (float)$r['approved'];
        $tot['pending'] += (float)$r['pending'];
        $tot['reversed'] += (float)$r['reversed'];
        $tot['matched'] += (float)$r['matched'];
        $tot['unmatched'] += (float)$r['unmatched'];
        $tot['count'] += (int)$r['cnt'];
        $tot['currency'] = $tot['currency'] ?: ($r['currency'] ?: '');
    }
    foreach (['approved', 'pending', 'reversed', 'matched', 'unmatched'] as $k) {
        $tot[$k] = round($tot[$k], 2);
    }
    return ['by_network' => $by, 'totals' => $tot, 'from' => $from, 'to' => $to];
}

/** Most recent pulled conversions (for the user to verify data landed). */
function affiliate_recent(string $owner, int $limit = 60): array
{
    $limit = max(1, min(200, $limit));
    $out = [];
    foreach (q("SELECT network, txn_id, click_id, sub_id, campaign_id, sale_amount, commission, currency, status, conv_date
                FROM affiliate_conversions WHERE owner = ? ORDER BY conv_date DESC, id DESC LIMIT $limit", [$owner]) as $r) {
        $out[] = ['network' => $r['network'], 'txn_id' => $r['txn_id'], 'click_id' => $r['click_id'] ?: '',
                  'sub_id' => $r['sub_id'], 'campaign_id' => $r['campaign_id'],
                  'sale_amount' => round((float)$r['sale_amount'], 2), 'commission' => round((float)$r['commission'], 2),
                  'currency' => $r['currency'] ?: '', 'status' => $r['status'], 'date' => $r['conv_date']];
    }
    return $out;
}

/**
 * One combined affiliate report for a window: a daily rollup + every conversion row
 * (old & new, matched & unmatched). Amounts are grouped by currency so USD and INR
 * are never summed together.
 * Returns ['rows'=>[...], 'daily'=>[...], 'totals'=>[per-currency], 'from','to'].
 */
function affiliate_report(string $owner, string $from, string $to): array
{
    // Detailed rows (newest first).
    $rows = [];
    foreach (q("SELECT network, txn_id, click_id, sub_id, campaign_id, sale_amount, commission, currency, status, conv_date
                FROM affiliate_conversions WHERE owner = ? AND conv_date BETWEEN ? AND ?
                ORDER BY conv_date DESC, id DESC", [$owner, $from, $to]) as $r) {
        $rows[] = [
            'date' => $r['conv_date'], 'network' => $r['network'],
            'campaign_id' => $r['campaign_id'], 'sub_id' => $r['sub_id'],
            'click_id' => $r['click_id'] ?: '', 'txn_id' => $r['txn_id'],
            'sale' => round((float)$r['sale_amount'], 2), 'commission' => round((float)$r['commission'], 2),
            'currency' => $r['currency'] ?: '', 'status' => $r['status'],
        ];
    }
    // Daily rollup (grouped by date + currency so mixed currencies stay separate).
    $daily = [];
    foreach (q("SELECT conv_date, currency,
                       COUNT(*) cnt,
                       SUM(CASE WHEN status<>'REVERSED' THEN commission ELSE 0 END) commission,
                       SUM(CASE WHEN status='APPROVED' THEN commission ELSE 0 END) approved,
                       SUM(CASE WHEN status='PENDING'  THEN commission ELSE 0 END) pending,
                       SUM(CASE WHEN status='REVERSED' THEN commission ELSE 0 END) reversed,
                       SUM(CASE WHEN status<>'REVERSED' THEN sale_amount ELSE 0 END) sale,
                       SUM(CASE WHEN campaign_id<>'' THEN 1 ELSE 0 END) matched,
                       SUM(CASE WHEN campaign_id='' THEN 1 ELSE 0 END) unmatched
                FROM affiliate_conversions WHERE owner = ? AND conv_date BETWEEN ? AND ?
                GROUP BY conv_date, currency ORDER BY conv_date DESC", [$owner, $from, $to]) as $r) {
        $daily[] = [
            'date' => $r['conv_date'], 'currency' => $r['currency'] ?: '', 'count' => (int)$r['cnt'],
            'commission' => round((float)$r['commission'], 2), 'sale' => round((float)$r['sale'], 2),
            'approved' => round((float)$r['approved'], 2), 'pending' => round((float)$r['pending'], 2),
            'reversed' => round((float)$r['reversed'], 2),
            'matched' => (int)$r['matched'], 'unmatched' => (int)$r['unmatched'],
        ];
    }
    // Totals per currency.
    $totals = [];
    foreach ($daily as $d) {
        $c = $d['currency'];
        $t = $totals[$c] ?? ['currency' => $c, 'count' => 0, 'commission' => 0, 'sale' => 0,
                             'approved' => 0, 'pending' => 0, 'reversed' => 0, 'matched' => 0, 'unmatched' => 0];
        foreach (['count', 'commission', 'sale', 'approved', 'pending', 'reversed', 'matched', 'unmatched'] as $k) {
            $t[$k] += $d[$k];
        }
        $totals[$c] = $t;
    }
    foreach ($totals as &$t) {
        foreach (['commission', 'sale', 'approved', 'pending', 'reversed'] as $k) {
            $t[$k] = round($t[$k], 2);
        }
    }
    unset($t);
    return ['rows' => $rows, 'daily' => $daily, 'totals' => array_values($totals), 'from' => $from, 'to' => $to];
}

/** Real commission per campaign for a window, split by status. */
function affiliate_revenue_by_campaign(string $owner, string $from, string $to): array
{
    $out = [];
    foreach (q("SELECT campaign_id,
                       SUM(CASE WHEN status='APPROVED' THEN commission ELSE 0 END) approved,
                       SUM(CASE WHEN status='PENDING'  THEN commission ELSE 0 END) pending,
                       SUM(CASE WHEN status='REVERSED' THEN commission ELSE 0 END) reversed,
                       SUM(CASE WHEN status<>'REVERSED' THEN commission ELSE 0 END) live,
                       SUM(CASE WHEN status='APPROVED' THEN sale_amount ELSE 0 END) sales,
                       SUM(CASE WHEN status<>'REVERSED' THEN 1 ELSE 0 END) conversions
                FROM affiliate_conversions
                WHERE owner = ? AND campaign_id <> '' AND conv_date BETWEEN ? AND ?
                GROUP BY campaign_id", [$owner, $from, $to]) as $r) {
        $out[(string)$r['campaign_id']] = [
            'approved' => round((float)$r['approved'], 2), 'pending' => round((float)$r['pending'], 2),
            'reversed' => round((float)$r['reversed'], 2), 'live' => round((float)$r['live'], 2),
            'sales' => round((float)$r['sales'], 2), 'conversions' => (int)$r['conversions'],
        ];
    }
    return $out;
}
