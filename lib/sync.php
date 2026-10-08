<?php
/**
 * Google Ads data -> MySQL sync
 * - campaign_daily: daily impressions/clicks/cost/conversions/value per campaign
 * - First run: sync_backfill_days from config (default 30)
 * - After that: only the last sync_recent_days (default 3), because Google keeps
 *   updating conversions for a few days
 */

/** Sync one account. Returns ['rows'=>, 'from'=>, 'to'=>] */
function sync_account(string $owner, string $connId, string $cid, ?int $days = null): array
{
    global $CONFIG;
    $svc = service_for($owner, $connId, $cid);
    $has = q('SELECT MAX(date) FROM campaign_daily WHERE customer_id = ?', [$cid])->fetchColumn();
    if ($days === null) {
        $days = $has ? (int)($CONFIG['sync_recent_days'] ?? 3) : (int)($CONFIG['sync_backfill_days'] ?? 30);
    }
    $days = max(1, min(365, $days));
    $to   = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

    try {
        $rows = $svc->campaignDaily($from, $to);
        $pdo = db();
        $pdo->beginTransaction();
        // Delete the range first (so removed/zero rows are correct), then insert fresh data
        q('DELETE FROM campaign_daily WHERE customer_id = ? AND date BETWEEN ? AND ?', [$cid, $from, $to]);
        $st = $pdo->prepare('INSERT INTO campaign_daily
            (customer_id, campaign_id, date, campaign_name, status, channel, impressions, clicks, cost, conversions, conv_value, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE campaign_name=VALUES(campaign_name), status=VALUES(status), channel=VALUES(channel),
              impressions=VALUES(impressions), clicks=VALUES(clicks), cost=VALUES(cost), conversions=VALUES(conversions),
              conv_value=VALUES(conv_value), updated_at=VALUES(updated_at)');
        $now = now();
        foreach ($rows as $r) {
            $st->execute([$cid, $r['campaign_id'], $r['date'], mb_substr($r['name'], 0, 255), $r['status'], $r['channel'],
                          $r['impr'], $r['clicks'], $r['cost'], $r['conv'], $r['value'], $now]);
        }
        $pdo->commit();
        q('UPDATE ads_accounts SET last_sync = ?, sync_error = NULL WHERE conn_id = ? AND customer_id = ?', [$now, $connId, $cid]);
        q('INSERT INTO sync_runs (owner, customer_id, date_from, date_to, rows_saved, ok, created_at) VALUES (?,?,?,?,?,1,?)',
          [$owner, $cid, $from, $to, count($rows), $now]);
        return ['rows' => count($rows), 'from' => $from, 'to' => $to];
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $msg = mb_strimwidth($e->getMessage(), 0, 250, '…');
        if (str_contains($msg, 'CUSTOMER_NOT_ENABLED')) {
            account_set_status($connId, $cid, 'NOT_ENABLED');
        }
        q('UPDATE ads_accounts SET sync_error = ? WHERE conn_id = ? AND customer_id = ?', [$msg, $connId, $cid]);
        q('INSERT INTO sync_runs (owner, customer_id, date_from, date_to, rows_saved, ok, message, created_at) VALUES (?,?,?,?,0,0,?,?)',
          [$owner, $cid, $from, $to, $msg, now()]);
        throw $e;
    }
}

/** Sync all enabled accounts of one user (used by cron) */
function sync_owner(string $owner, ?callable $log = null, bool $deep = false): array
{
    global $CONFIG;
    // Deep pass re-pulls the full backfill window to catch delayed conversions Google attributes late
    $days = $deep ? (int)($CONFIG['sync_backfill_days'] ?? 30) : null;
    $ok = 0;
    $fail = 0;
    foreach (conns_load($owner) as $c) {
        try {
            $accs = accounts_data($c, true)['accounts'];
        } catch (Throwable $e) {
            $log && $log("  {$c['email']}: account list error - " . $e->getMessage());
            $fail++;
            continue;
        }
        foreach ($accs as $a) {
            if ($a['status'] !== 'ENABLED') {
                continue;
            }
            try {
                $r = sync_account($owner, $c['id'], $a['id'], $days);
                $log && $log("  {$a['name']} (" . fmt_cid($a['id']) . "): {$r['rows']} rows ({$r['from']} - {$r['to']})");
                $ok++;
            } catch (Throwable $e) {
                $log && $log("  {$a['name']}: ERROR " . mb_strimwidth($e->getMessage(), 0, 150, '…'));
                $fail++;
            }
        }
    }
    return ['ok' => $ok, 'failed' => $fail];
}

/** Overview: summary of all the user's accounts from the DB */
function overview_data(string $owner, string $from, string $to): array
{
    // Visible accounts = the user's OWN accounts + accounts SHARED with the user.
    // A '*' share means "every account under that connection", so it expands against ads_accounts.
    // Built once as a derived table and reused for both the per-account list and the daily trend.
    $visible = "
        SELECT a.conn_id, a.customer_id, a.name, a.currency, a.status, a.via, a.last_sync, a.sync_error,
               c.email, 'owner' AS access, NULL AS shared_by
          FROM ads_accounts a
          JOIN connections c ON c.id = a.conn_id AND c.owner = ?
        UNION ALL
        SELECT a.conn_id, a.customer_id, a.name, a.currency, a.status, a.via, a.last_sync, a.sync_error,
               c.email, s.role AS access, s.owner AS shared_by
          FROM account_shares s
          JOIN connections c ON c.id = s.conn_id AND c.owner = s.owner
          JOIN ads_accounts a ON a.conn_id = s.conn_id AND (s.customer_id = '*' OR a.customer_id = s.customer_id)
         WHERE s.shared_with = ?";

    $rows = q("SELECT v.conn_id, v.customer_id, v.name, v.currency, v.status, v.via, v.last_sync, v.sync_error,
                      v.email, v.access, v.shared_by,
                      COALESCE(m.impr,0) impr, COALESCE(m.clicks,0) clicks, COALESCE(m.cost,0) cost,
                      COALESCE(m.conv,0) conv, COALESCE(m.value,0) value, COALESCE(m.active_campaigns,0) active_campaigns
               FROM ($visible) v
               LEFT JOIN (SELECT customer_id, SUM(impressions) impr, SUM(clicks) clicks, SUM(cost) cost,
                                 SUM(conversions) conv, SUM(conv_value) value,
                                 COUNT(DISTINCT CASE WHEN cost > 0 THEN campaign_id END) active_campaigns
                          FROM campaign_daily WHERE date BETWEEN ? AND ? GROUP BY customer_id) m
                 ON m.customer_id = v.customer_id", [$owner, $owner, $from, $to])->fetchAll();

    // Dedup by customer_id: the same account can be owned AND shared, or shared twice.
    // Keep one row per customer, preferring the strongest access (owner > edit > view).
    // Metrics are per-customer, so collapsing never double-counts them.
    $rank = ['owner' => 3, 'edit' => 2, 'view' => 1];
    $byCid = [];
    foreach ($rows as $r) {
        $cid = $r['customer_id'];
        if (!isset($byCid[$cid]) || ($rank[$r['access']] ?? 0) > ($rank[$byCid[$cid]['access']] ?? 0)) {
            $byCid[$cid] = $r;
        }
    }
    $rows = array_values($byCid);
    usort($rows, fn($a, $b) => ((float)$b['cost'] <=> (float)$a['cost']) ?: strcmp((string)$a['name'], (string)$b['name']));

    // Daily trend across the same visible set of customers (deduped, so no double counting).
    $daily = q("SELECT d.date, v.currency, SUM(d.impressions) impr, SUM(d.clicks) clicks, SUM(d.cost) cost,
                       SUM(d.conversions) conv, SUM(d.conv_value) value
                FROM campaign_daily d
                JOIN (SELECT DISTINCT customer_id, currency FROM ($visible) v0) v ON v.customer_id = d.customer_id
                WHERE d.date BETWEEN ? AND ?
                GROUP BY d.date, v.currency ORDER BY d.date", [$owner, $owner, $from, $to])->fetchAll();

    $cast = function (array $r) {
        foreach (['impr', 'clicks'] as $k) $r[$k] = (int)$r[$k];
        foreach (['cost', 'conv', 'value'] as $k) $r[$k] = round((float)$r[$k], 2);
        return $r;
    };
    return ['accounts' => array_map($cast, $rows), 'daily' => array_map($cast, $daily)];
}
