<?php
/**
 * Cron. Three tasks:
 *   1. sync   - Google Ads data into MySQL (hourly is enough)
 *   2. rules  - Automation rules (hourly)
 *   3. suffix - Suffix Rotator (run every 5 minutes so the 10-min interval fires on time)
 *
 * On Hostinger Cron Jobs, set up TWO crons:
 *   Hourly     :   /usr/bin/php /home/USER/public_html/ads/cron.php KEY all
 *   Every 5 min:   /usr/bin/php /home/USER/public_html/ads/cron.php KEY suffix
 *   (sync only: ... KEY sync   |  rules only: ... KEY rules)
 * or via URL:  https://yoursite.com/ads/cron.php?key=KEY&task=suffix
 *
 * KEY = cron_key from config.php
 */
require __DIR__ . '/lib/bootstrap.php';

$cli  = PHP_SAPI === 'cli';
$key  = $cli ? ($argv[1] ?? '') : ($_GET['key'] ?? '');
$task = $cli ? ($argv[2] ?? 'all') : ($_GET['task'] ?? 'all');
if (($CONFIG['cron_key'] ?? '') === '' || $CONFIG['cron_key'] === 'change-this-random-cron-key'
    || !hash_equals((string)$CONFIG['cron_key'], (string)$key)) {
    http_response_code(403);
    exit("Forbidden: set cron_key in config.php and pass the same key.\n");
}
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}
@set_time_limit(0);
$log = function (string $m) {
    echo $m . "\n";
    @ob_flush();
    flush();
};

/** Separate lock per task - the same task never runs twice at once */
$locks = [];
$lockFor = function (string $name) use (&$locks): bool {
    $fh = fopen(sys_get_temp_dir() . '/adhook_cron_' . md5(__DIR__ . $name) . '.lock', 'c');
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        return false;
    }
    $locks[$name] = $fh;
    return true;
};

// ---------- 0. Suffix rotator (first - time sensitive) ----------
if (in_array($task, ['all', 'suffix'], true) && $lockFor('suffix')) {
    $log(date('c') . ' === Suffix rotator ===');
    if (empty($CONFIG['allow_changes'])) {
        $log('allow_changes = false, skipping.');
    } else {
        $n = rotators_run_due($log);
        $log("$n rotator(s) updated.");
    }
}

// ---------- 1. Data sync ----------
if (in_array($task, ['all', 'sync'], true) && $lockFor('sync')) {
    // Once every ~20 hours, do a DEEP sync (full backfill window) to catch delayed conversions;
    // otherwise a light recent-days refresh.
    $lastDeep = (int)(q("SELECT value FROM app_settings WHERE name='last_deep_sync'")->fetchColumn() ?: 0);
    $deep = $lastDeep < time() - 72000;
    $log(date('c') . ' === Google Ads data sync' . ($deep ? ' (DEEP backfill)' : '') . ' ===');
    $owners = q('SELECT DISTINCT owner FROM connections')->fetchAll(PDO::FETCH_COLUMN);
    $deepOk = 0;
    $deepFailed = 0;
    foreach ($owners as $owner) {
        $log("[$owner]");
        $r = sync_owner($owner, $log, $deep);
        $deepOk += $r['ok'];
        $deepFailed += $r['failed'];
        $log("  -> {$r['ok']} synced, {$r['failed']} failed");
    }
    if ($deep) {
        // Only mark the deep backfill complete when it fully succeeded. If any account failed
        // its backfill, don't push the marker a full 20h out (that would delay catching delayed
        // conversions for the failed accounts) - instead retry the deep pass in ~2h. We avoid
        // retrying every single hour so a permanently-broken account can't hammer the API.
        if ($deepFailed === 0) {
            q("REPLACE INTO app_settings (name, value) VALUES ('last_deep_sync', ?)", [(string)time()]);
            $log("Deep backfill complete ($deepOk ok) - next in ~20h.");
        } else {
            q("REPLACE INTO app_settings (name, value) VALUES ('last_deep_sync', ?)", [(string)(time() - 72000 + 7200)]);
            $log("Deep backfill had $deepFailed failure(s) - will retry the deep pass in ~2h.");
        }
    }
    // Clean data older than 2 years (keep the DB small)
    q('DELETE FROM campaign_daily WHERE date < ?', [date('Y-m-d', strtotime('-730 days'))]);
    q('DELETE FROM sync_runs WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime('-30 days'))]);
}

// ---------- 1b. Affiliate network conversions (Impact / AWIN) ----------
if (in_array($task, ['all', 'sync'], true) && $lockFor('affiliate')) {
    $log(date('c') . ' === Affiliate conversions sync ===');
    foreach (q('SELECT DISTINCT owner FROM network_accounts')->fetchAll(PDO::FETCH_COLUMN) as $owner) {
        $log("[$owner]");
        $r = affiliate_sync($owner, $log);
        $log("  -> {$r['rows']} conversions, {$r['ok']} network(s) ok, {$r['failed']} failed");
    }
}

// ---------- 2. Automation rules ----------
if (in_array($task, ['all', 'rules'], true) && $lockFor('rules')) {
    $log(date('c') . ' === Automation rules ===');
    if (empty($CONFIG['allow_changes'])) {
        $log('allow_changes = false, skipping rules.');
    } else {
        $ran = 0;
        foreach (rules_all() as $rule) {
            if (empty($rule['enabled']) || str_starts_with($rule['conn'], 'demo')) {
                continue;
            }
            try {
                $r = rule_run($rule, true);
                $log("[{$rule['owner']}] {$rule['name']}: {$r['applied']} campaign(s) changed");
            } catch (Throwable $e) {
                $log("[{$rule['owner']}] {$rule['name']}: ERROR " . $e->getMessage());
                rule_mark($rule['id'], 'Error: ' . mb_strimwidth($e->getMessage(), 0, 150, '…'));
            }
            $ran++;
        }
        $log("$ran rule(s) checked.");
    }
}
$log('Done.');
