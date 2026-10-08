<?php
/**
 * Suffix Rotator
 * ---------------------------------------------------------------------------
 * Take URLs (or bare suffixes) from Excel / CSV / paste -> the part after "?"
 * -> apply them one by one to the Final URL suffix on a schedule.
 * The final URL stays the same. Changing an account/campaign suffix does not send ads to review.
 *
 * Run cron.php (task "suffix") every 5 minutes - only due rotators are updated.
 */

// ================= File parsing =================

/** Extract a suffix from a cell/line: full URL -> part after "?", or an existing "a=b&c=d" */
function suffix_from_text(string $t): ?string
{
    $t = trim($t, " \t\r\n\"'");
    if ($t === '') {
        return null;
    }
    $t = html_entity_decode($t, ENT_QUOTES);
    if (($p = strpos($t, '?')) !== false) {
        $t = substr($t, $p + 1);
    } elseif (preg_match('#^https?://#i', $t) || !str_contains($t, '=')) {
        return null; // URL without a query, or other text (header etc.)
    }
    $t = explode('#', $t, 2)[0];
    $t = trim($t, "?& \t");
    return ($t !== '' && str_contains($t, '=')) ? $t : null;
}

/** First cell in the row that contains a suffix */
function suffix_from_row(array $cells): ?string
{
    foreach ($cells as $c) {
        if (($s = suffix_from_text((string)$c)) !== null) {
            return $s;
        }
    }
    return null;
}

/** Final URL base (host + path) - for preview/warnings */
function base_from_text(string $t): ?string
{
    return preg_match('#^(https?://[^?\s"]+)#i', trim($t, " \"'"), $m) ? $m[1] : null;
}

/** Minimal .xlsx reader (no Composer): all rows of the first sheet */
function xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is missing on the server. Save the file as CSV and upload that.');
    }
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        throw new RuntimeException('Could not open the Excel file. Is it .xlsx? (.xls is not supported - save as CSV)');
    }
    $shared = [];
    if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($x);
        foreach ($sx->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $s = '';
                foreach ($si->r as $r) {
                    $s .= (string)$r->t;
                }
                $shared[] = $s;
            }
        }
    }
    // Pehli sheet dhoondo
    $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet === false) {
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) {
                $sheet = $z->getFromName($n);
                break;
            }
        }
    }
    // Hyperlinks (cell shows text while the URL is in the hyperlink)
    $links = [];
    if ($sheet !== false) {
        $sx = simplexml_load_string($sheet);
        $rels = $z->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
        $relMap = [];
        if ($rels) {
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                $relMap[(string)$rel['Id']] = (string)$rel['Target'];
            }
        }
        if (isset($sx->hyperlinks)) {
            foreach ($sx->hyperlinks->hyperlink as $h) {
                $rid = (string)$h->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if ($rid && isset($relMap[$rid])) {
                    $links[(string)$h['ref']] = $relMap[$rid];
                }
            }
        }
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $t = (string)$c['t'];
                if ($t === 's') {
                    $v = $shared[(int)$c->v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $v = (string)$c->is->t;
                } else {
                    $v = (string)$c->v;
                }
                $ref = (string)$c['r'];
                if (isset($links[$ref]) && !str_contains($v, '?')) {
                    $cells[] = $links[$ref];
                }
                $cells[] = $v;
            }
            $rows[] = $cells;
        }
        $z->close();
        return $rows;
    }
    $z->close();
    throw new RuntimeException('No sheet found in the Excel file.');
}

/** Uploaded file / pasted text -> suffixes */
function parse_suffix_source(?array $file, string $pasted = ''): array
{
    $rows = [];
    if ($file && is_uploaded_file($file['tmp_name'])) {
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('File is larger than 10 MB.');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            $rows = xlsx_rows($file['tmp_name']);
        } elseif (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            $fh = fopen($file['tmp_name'], 'r');
            $first = fgets($fh);
            rewind($fh);
            $delim = substr_count((string)$first, "\t") > substr_count((string)$first, ',') ? "\t" : (substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ',');
            while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
                $rows[] = $r;
            }
            fclose($fh);
        } elseif ($ext === 'xls') {
            throw new RuntimeException('.xls is not supported. In Excel use "Save As" > .xlsx or CSV.');
        } else {
            throw new RuntimeException('Upload a .xlsx, .csv or .txt file.');
        }
    }
    foreach (preg_split('/\r?\n/', $pasted) as $l) {
        if (trim($l) !== '') {
            $rows[] = [$l];
        }
    }
    $items = [];
    $bases = [];
    $skipped = 0;
    foreach ($rows as $r) {
        $s = suffix_from_row($r);
        if ($s === null) {
            if (array_filter($r, fn($c) => trim((string)$c) !== '')) {
                $skipped++;
            }
            continue;
        }
        $items[] = $s;
        foreach ($r as $c) {
            if ($b = base_from_text((string)$c)) {
                $bases[$b] = ($bases[$b] ?? 0) + 1;
                break;
            }
        }
    }
    $unique = array_values(array_unique($items));
    arsort($bases);
    return [
        'items' => $unique, 'duplicates' => count($items) - count($unique), 'skipped' => $skipped,
        'bases' => array_slice($bases, 0, 5, true),
    ];
}

/** Set extra params (e.g. subid1={campaignid}) in the suffix - replaces an existing key */
function apply_extra_params(string $suffix, string $extra): string
{
    $extra = trim($extra, "?& \t");
    if ($extra === '') {
        return $suffix;
    }
    $pairs = [];
    $order = [];
    foreach (explode('&', $suffix) as $p) {
        if ($p === '') continue;
        $k = explode('=', $p, 2)[0];
        if (!isset($pairs[$k])) $order[] = $k;
        $pairs[$k] = $p;
    }
    foreach (explode('&', $extra) as $p) {
        if ($p === '') continue;
        $k = explode('=', $p, 2)[0];
        if (!isset($pairs[$k])) $order[] = $k;
        $pairs[$k] = $p;
    }
    return implode('&', array_map(fn($k) => $pairs[$k], $order));
}

// ================= Storage =================

function rotator_row(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['active'] = (bool)$r['active'];
    $r['single_use'] = (bool)($r['single_use'] ?? 1);
    foreach (['pos', 'total', 'interval_min'] as $k) {
        $r[$k] = (int)$r[$k];
    }
    $r['campaign_ids'] = $r['campaign_ids'] ? (json_decode($r['campaign_ids'], true) ?: []) : [];
    // used = rotated at least once; clicked = got a click and retired to trash;
    // remaining = active pool still waiting (not used, not clicked).
    $r['used'] = (int)(q('SELECT COUNT(*) FROM suffix_items WHERE rotator_id = ? AND used_count > 0', [$r['id']])->fetchColumn());
    $r['clicked'] = (int)(q('SELECT COUNT(*) FROM suffix_items WHERE rotator_id = ? AND clicked = 1', [$r['id']])->fetchColumn());
    $r['remaining'] = (int)(q('SELECT COUNT(*) FROM suffix_items WHERE rotator_id = ? AND used_count = 0 AND clicked = 0', [$r['id']])->fetchColumn());
    return $r;
}

/**
 * Mark suffixes clicked (retire to trash) or restore them. Owner-scoped.
 * A clicked suffix is never rotated again, even when the loop restarts.
 */
function rotator_items_set_clicked(string $owner, int $rotatorId, array $seqs, bool $clicked): int
{
    rotator_get($owner, $rotatorId); // throws unless the owner owns this rotator
    $seqs = array_values(array_unique(array_map('intval', $seqs)));
    if (!$seqs) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($seqs), '?'));
    $st = q("UPDATE suffix_items SET clicked = ?, clicked_at = ? WHERE rotator_id = ? AND seq IN ($ph)",
            array_merge([$clicked ? 1 : 0, $clicked ? now() : null, $rotatorId], $seqs));
    return $st->rowCount();
}

function rotators_for(string $owner): array
{
    return array_map('rotator_row', q('SELECT * FROM suffix_rotators WHERE owner = ? ORDER BY id DESC', [$owner])->fetchAll());
}

function rotator_get(string $owner, int $id): array
{
    $r = q('SELECT * FROM suffix_rotators WHERE id = ? AND owner = ?', [$id, $owner])->fetch();
    if (!$r) {
        throw new RuntimeException('Rotator not found.');
    }
    return rotator_row($r);
}

function rotator_save(string $owner, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    $existing = $id ? rotator_get($owner, $id) : null;
    $time = fn($v, $d) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v) ? $v : $d;
    $f = [
        'name' => mb_substr(trim($in['name'] ?? '') ?: 'Suffix rotator', 0, 120),
        'conn_id' => preg_replace('/\W/', '', $in['conn'] ?? ($existing['conn_id'] ?? '')),
        'customer_id' => preg_replace('/\D/', '', $in['cid'] ?? ($existing['customer_id'] ?? '')),
        'account_name' => mb_substr((string)($in['account_name'] ?? ($existing['account_name'] ?? '')), 0, 255),
        'target' => ($in['target'] ?? 'campaigns') === 'account' ? 'account' : 'campaigns',
        'campaign_ids' => json_encode(array_values(array_map('strval', array_filter((array)($in['campaign_ids'] ?? []), 'is_numeric')))),
        'interval_min' => max(5, min(1440, (int)($in['interval_min'] ?? 60))),
        'window_start' => $time($in['window_start'] ?? '', '00:00'),
        'window_end' => $time($in['window_end'] ?? '', '23:59'),
        'days' => implode('', array_unique(array_filter(str_split(preg_replace('/[^1-7]/', '', (string)($in['days'] ?? '1234567')))))) ?: '1234567',
        'mode' => ($in['mode'] ?? '') === 'random' ? 'random' : 'sequential',
        'on_end' => ($in['on_end'] ?? '') === 'stop' ? 'stop' : 'loop',
        'single_use' => array_key_exists('single_use', $in) ? (!empty($in['single_use']) ? 1 : 0) : ($existing['single_use'] ?? 1),
        'extra_params' => mb_substr(trim((string)($in['extra_params'] ?? ''), "?& "), 0, 500),
        'active' => !empty($in['active']) ? 1 : 0,
    ];
    if (!$f['conn_id'] || !$f['customer_id']) {
        throw new RuntimeException('Select an account.');
    }
    if ($f['target'] === 'campaigns' && $f['campaign_ids'] === '[]') {
        throw new RuntimeException('Select at least one campaign (or target the whole account).');
    }
    $items = isset($in['items']) && is_array($in['items']) ? array_values(array_filter(array_map(fn($s) => trim((string)$s, "?& \t"), $in['items']))) : null;
    if (!$existing && !$items) {
        throw new RuntimeException('The suffix list is empty. Upload a file or paste URLs.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($existing) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            q("UPDATE suffix_rotators SET $sets WHERE id = ? AND owner = ?", [...array_values($f), $id, $owner]);
        } else {
            $cols = implode(', ', array_keys($f));
            $qs = implode(',', array_fill(0, count($f), '?'));
            q("INSERT INTO suffix_rotators ($cols, owner, created_at) VALUES ($qs, ?, ?)", [...array_values($f), $owner, now()]);
            $id = (int)$pdo->lastInsertId();
        }
        if ($items) {
            if ($f['single_use']) {
                // Append new suffixes, skipping any already in the list or already used (dedup)
                rotator_append_items($id, $items);
            } else {
                // Loop mode: replace the whole list
                q('DELETE FROM suffix_items WHERE rotator_id = ?', [$id]);
                $st = $pdo->prepare('INSERT INTO suffix_items (rotator_id, seq, suffix) VALUES (?,?,?)');
                foreach ($items as $i => $s) {
                    $st->execute([$id, $i, mb_substr($s, 0, 2000)]);
                }
                q('UPDATE suffix_rotators SET total = ?, pos = 0 WHERE id = ?', [count($items), $id]);
            }
        }
        // Just enabled with no next_run: due immediately
        q('UPDATE suffix_rotators SET next_run_at = COALESCE(next_run_at, ?) WHERE id = ? AND active = 1', [now(), $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return rotator_get($owner, $id);
}

function rotator_delete(string $owner, int $id): void
{
    rotator_get($owner, $id);
    q('DELETE FROM suffix_items WHERE rotator_id = ?', [$id]);
    q('DELETE FROM suffix_log WHERE rotator_id = ?', [$id]);
    q('DELETE FROM suffix_rotators WHERE id = ?', [$id]);
}

/** Append suffixes, skipping ones already in the list (used or unused). Returns counts. */
function rotator_append_items(int $id, array $items): array
{
    $existing = q('SELECT suffix FROM suffix_items WHERE rotator_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    $have = array_fill_keys(array_map('strval', $existing), true);
    $maxSeq = (int)q('SELECT COALESCE(MAX(seq), -1) FROM suffix_items WHERE rotator_id = ?', [$id])->fetchColumn();
    $pdo = db();
    $st = $pdo->prepare('INSERT INTO suffix_items (rotator_id, seq, suffix) VALUES (?,?,?)');
    $added = 0;
    $dupes = 0;
    $seq = $maxSeq + 1;
    foreach ($items as $s) {
        $s = mb_substr($s, 0, 2000);
        if (isset($have[$s])) {
            $dupes++;
            continue;
        }
        $have[$s] = true;
        $st->execute([$id, $seq++, $s]);
        $added++;
    }
    q('UPDATE suffix_rotators SET total = (SELECT COUNT(*) FROM suffix_items WHERE rotator_id = ?) WHERE id = ?', [$id, $id]);
    return ['added' => $added, 'dupes' => $dupes];
}

/** Preview how many of the incoming suffixes are new vs already in the list vs already used (no write). */
function rotator_dedup_preview(string $owner, int $id, array $items): array
{
    rotator_get($owner, $id);
    $rows = q('SELECT suffix, used_count FROM suffix_items WHERE rotator_id = ?', [$id])->fetchAll();
    $used = $unused = [];
    foreach ($rows as $r) {
        if ((int)$r['used_count'] > 0) {
            $used[(string)$r['suffix']] = true;
        } else {
            $unused[(string)$r['suffix']] = true;
        }
    }
    $new = $dupUsed = $dupPool = 0;
    $seen = [];
    foreach ($items as $s) {
        $s = (string)$s;
        if (isset($seen[$s])) {
            continue;
        }
        $seen[$s] = true;
        if (isset($used[$s])) {
            $dupUsed++;
        } elseif (isset($unused[$s])) {
            $dupPool++;
        } else {
            $new++;
        }
    }
    return ['new' => $new, 'dup_used' => $dupUsed, 'dup_pool' => $dupPool];
}

/**
 * Per-suffix report: when each suffix was live, for how long, and an estimated click count
 * (from the account/campaign hourly clicks over that window - Google can't report clicks per suffix).
 */
function rotator_report(string $owner, int $id): array
{
    $r = rotator_get($owner, $id);
    $logs = q("SELECT item_seq, suffix, applied_at, ok, source FROM suffix_log
               WHERE rotator_id = ? AND ok = 1 ORDER BY applied_at ASC", [$id])->fetchAll();

    // Estimated clicks per hour bucket for the rotator's target (best effort)
    $hourly = [];
    $note = '';
    if ($logs) {
        $from = substr($logs[0]['applied_at'], 0, 10);
        $to = date('Y-m-d');
        try {
            // service_for returns an AdsReal/AdsDemo (with hourlyClicks), not the raw GoogleAdsClient
            $svc = service_for($owner, $r['conn_id'], $r['customer_id'], false);
            $hourly = $svc->hourlyClicks($from, $to, $r['target'] === 'campaigns' ? $r['campaign_ids'] : null);
        } catch (Throwable $e) {
            $note = 'Estimated clicks unavailable: ' . mb_strimwidth($e->getMessage(), 0, 120, '…');
        }
    }

    // Which suffixes are already clicked/trashed (seq -> 1).
    $clickedMap = [];
    foreach (q('SELECT seq, clicked FROM suffix_items WHERE rotator_id = ?', [$id]) as $it) {
        $clickedMap[(int)$it['seq']] = (int)$it['clicked'];
    }

    $rows = [];
    $totalClicks = 0.0;
    $n = count($logs);
    for ($i = 0; $i < $n; $i++) {
        $start = strtotime($logs[$i]['applied_at']);
        $end = $i + 1 < $n ? strtotime($logs[$i + 1]['applied_at']) : time();
        $mins = max(0, (int)round(($end - $start) / 60));
        $itemSeq = (int)$logs[$i]['item_seq'];
        $rows[] = [
            'item_seq' => $itemSeq,
            'seq' => $itemSeq + 1,
            'suffix' => $logs[$i]['suffix'],
            'applied_at' => $logs[$i]['applied_at'],
            'minutes' => $mins,
            'est_clicks' => est_clicks_in_window($hourly, $start, $end),
            'source' => $logs[$i]['source'],
            'clicked' => !empty($clickedMap[$itemSeq]),
        ];
        $totalClicks += $rows[count($rows) - 1]['est_clicks'];
    }
    $rows = array_reverse($rows); // newest first
    return [
        'rows' => $rows,
        'total_applied' => $n,
        'est_total_clicks' => round($totalClicks, 1),
        'remaining' => $r['remaining'],
        'used' => $r['used'],
        'clicked_count' => array_sum($clickedMap),
        'total' => $r['total'],
        'has_estimate' => !empty($hourly),
        'note' => $note,
    ];
}

/** Sum estimated clicks for [start,end] from an hour->clicks map, weighting partial hours. */
function est_clicks_in_window(array $hourly, int $start, int $end): float
{
    if (!$hourly || $end <= $start) {
        return 0.0;
    }
    $sum = 0.0;
    for ($h = $start - ($start % 3600); $h < $end; $h += 3600) {
        $key = date('Y-m-d H', $h);
        $clicks = $hourly[$key] ?? 0;
        if (!$clicks) {
            continue;
        }
        $overlap = min($end, $h + 3600) - max($start, $h);
        $sum += $clicks * max(0, $overlap) / 3600;
    }
    return round($sum, 1);
}

// ================= Schedule =================

function rotator_in_window(array $r, ?int $ts = null): bool
{
    $ts = $ts ?? time();
    if (!str_contains($r['days'], date('N', $ts))) {
        return false;
    }
    $now = date('H:i', $ts);
    $s = $r['window_start'];
    $e = $r['window_end'];
    return $s <= $e ? ($now >= $s && $now <= $e) : ($now >= $s || $now <= $e); // also handles overnight windows
}

/** Agla suffix lagao. $source: cron | manual */
function rotator_apply(array $r, string $source = 'cron'): array
{
    if ($r['total'] < 1) {
        throw new RuntimeException('The suffix list is empty.');
    }
    // Per-rotator lock so cron and a manual "Run now" can never apply the same suffix at once
    $lockName = 'adhook_rot_' . $r['id'];
    $got = q('SELECT GET_LOCK(?, 3)', [$lockName])->fetchColumn();
    if (!$got) {
        throw new RuntimeException('This rotator is already running - try again in a moment.');
    }
    try {
        // Double-checked locking: the $r we were handed was read BEFORE we got the lock,
        // so pos / next_run_at / used counts may be stale if another process (a manual
        // "Run now" racing cron, or an overlapping cron) applied a suffix in between.
        // Re-load the row fresh under the lock and act on that.
        $fresh = q('SELECT * FROM suffix_rotators WHERE id = ?', [$r['id']])->fetch();
        if (!$fresh) {
            throw new RuntimeException('This rotator no longer exists.');
        }
        $r = rotator_row($fresh);
        if ($r['total'] < 1) {
            throw new RuntimeException('The suffix list is empty.');
        }
        if (!$r['active']) {
            return ['skipped' => true, 'reason' => 'This rotator is off - turn it ON first, then Run now.'];
        }
        // Cron only fires on schedule. If it was already applied since this run was picked
        // as "due" (next_run_at pushed into the future), skip instead of double-applying.
        // A manual "Run now" is an explicit override, so it always applies - but off the
        // freshly-loaded pos, so it advances correctly instead of repeating a stale suffix.
        if ($source === 'cron' && !empty($r['next_run_at']) && $r['next_run_at'] > now()) {
            return ['skipped' => true, 'reason' => 'Already applied - not due yet.'];
        }
        return rotator_apply_locked($r, $source);
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$lockName]);
    }
}

function rotator_apply_locked(array $r, string $source): array
{
    if (!empty($r['single_use'])) {
        // We hold the per-rotator lock, so a plain "next unused" select is race-free.
        // used_count is bumped only after a successful apply (below), so a failed API call
        // does NOT consume the suffix - it retries next time.
        $order = $r['mode'] === 'random' ? 'RAND()' : 'seq ASC';
        // Clicked suffixes are retired (trash) and never selected again.
        $item = q("SELECT seq, suffix FROM suffix_items WHERE rotator_id = ? AND used_count = 0 AND clicked = 0 ORDER BY $order LIMIT 1", [$r['id']])->fetch();
        if (!$item && $r['on_end'] === 'loop') {
            // Loop: the active (non-clicked) suffixes have all been used once - start the cycle
            // over, but only revive non-clicked ones so clicked/trashed suffixes stay retired.
            q('UPDATE suffix_items SET used_count = 0 WHERE rotator_id = ? AND clicked = 0', [$r['id']]);
            q('UPDATE suffix_rotators SET pos = 0 WHERE id = ?', [$r['id']]);
            $r['pos'] = 0;
            $item = q("SELECT seq, suffix FROM suffix_items WHERE rotator_id = ? AND used_count = 0 AND clicked = 0 ORDER BY $order LIMIT 1", [$r['id']])->fetch();
        }
        if (!$item) {
            $msg = 'No active suffixes left (all used or clicked) - rotator turned off. Upload new URLs to continue.';
            q('UPDATE suffix_rotators SET active = 0, last_error = ? WHERE id = ?', [$msg, $r['id']]);
            throw new RuntimeException($msg);
        }
        $seq = (int)$item['seq'];
    } else {
        if ($r['on_end'] === 'stop' && $r['pos'] >= $r['total'] && $r['mode'] === 'sequential') {
            $msg = 'All suffixes used - rotator turned off. Upload a new list or choose "Loop".';
            q('UPDATE suffix_rotators SET active = 0, last_error = ? WHERE id = ?', [$msg, $r['id']]);
            throw new RuntimeException($msg);
        }
        // Skip clicked (retired) suffixes - never rotate them again.
        if ($r['mode'] === 'random') {
            $item = q("SELECT seq, suffix FROM suffix_items WHERE rotator_id = ? AND clicked = 0 ORDER BY RAND() LIMIT 1", [$r['id']])->fetch();
        } else {
            $wantSeq = $r['pos'] % max(1, $r['total']);
            $item = q("SELECT seq, suffix FROM suffix_items WHERE rotator_id = ? AND clicked = 0 AND seq >= ? ORDER BY seq ASC LIMIT 1", [$r['id'], $wantSeq])->fetch()
                 ?: q("SELECT seq, suffix FROM suffix_items WHERE rotator_id = ? AND clicked = 0 ORDER BY seq ASC LIMIT 1", [$r['id']])->fetch();
        }
        if (!$item) {
            $msg = 'No active suffixes left (all clicked/retired) - rotator turned off. Upload new URLs to continue.';
            q('UPDATE suffix_rotators SET active = 0, last_error = ? WHERE id = ?', [$msg, $r['id']]);
            throw new RuntimeException($msg);
        }
        $seq = (int)$item['seq'];
    }
    $suffix = apply_extra_params($item['suffix'], $r['extra_params']);
    $next = date('Y-m-d H:i:s', time() + $r['interval_min'] * 60);
    try {
        $svc = service_for($r['owner'], $r['conn_id'], $r['customer_id'], true);
        $data = ['final_url_suffix' => $suffix];
        if ($r['target'] === 'campaigns') {
            $data['campaign_ids'] = $r['campaign_ids'];
        }
        $res = $svc->bulkTracking($r['target'], $data);
    } catch (Throwable $e) {
        $msg = mb_strimwidth($e->getMessage(), 0, 250, '…');
        q('INSERT INTO suffix_log (rotator_id, item_seq, suffix, applied_at, ok, message, source) VALUES (?,?,?,?,0,?,?)',
          [$r['id'], $seq, $suffix, now(), $msg, $source]);
        // On failure retry in 5 minutes (not the full interval)
        q('UPDATE suffix_rotators SET last_error = ?, next_run_at = ? WHERE id = ?', [$msg, date('Y-m-d H:i:s', time() + 300), $r['id']]);
        throw $e;
    }
    $newPos = $r['mode'] === 'sequential' ? $r['pos'] + 1 : $r['pos'];
    if ($r['on_end'] === 'loop' && $newPos >= $r['total']) {
        $newPos = 0;
    }
    q('UPDATE suffix_items SET used_count = used_count + 1, last_used_at = ? WHERE rotator_id = ? AND seq = ?', [now(), $r['id'], $seq]);
    q('UPDATE suffix_rotators SET pos = ?, last_run_at = ?, next_run_at = ?, last_suffix = ?, last_error = NULL WHERE id = ?',
      [$newPos, now(), $next, $suffix, $r['id']]);
    q('INSERT INTO suffix_log (rotator_id, item_seq, suffix, applied_at, ok, message, source) VALUES (?,?,?,?,1,?,?)',
      [$r['id'], $seq, $suffix, now(), ($res['updated'] ?? 0) . ' ' . ($r['target'] === 'account' ? 'account' : 'campaign(s)') . ' updated', $source]);
    log_change($r['owner'], $r['customer_id'], "Suffix rotator \"{$r['name']}\" #" . ($seq + 1) . " applied", str_starts_with($r['conn_id'], 'demo'));
    return ['seq' => $seq, 'suffix' => $suffix, 'next_run_at' => $next, 'updated' => $res['updated'] ?? 0];
}

/** Cron: all due rotators */
function rotators_run_due(?callable $log = null): int
{
    $n = 0;
    $due = q("SELECT * FROM suffix_rotators WHERE active = 1 AND (next_run_at IS NULL OR next_run_at <= ?) AND conn_id NOT LIKE 'demo%'", [now()])->fetchAll();
    foreach (array_map('rotator_row', $due) as $r) {
        if (!rotator_in_window($r)) {
            continue; // outside the window - runs when the window opens
        }
        try {
            $x = rotator_apply($r, 'cron');
            if (!empty($x['skipped'])) {
                $log && $log("[{$r['owner']}] {$r['name']}: skipped ({$x['reason']})");
                continue;
            }
            $log && $log("[{$r['owner']}] {$r['name']}: #" . ($x['seq'] + 1) . " applied, next {$x['next_run_at']}");
            $n++;
        } catch (Throwable $e) {
            $log && $log("[{$r['owner']}] {$r['name']}: ERROR " . $e->getMessage());
        }
    }
    q('DELETE FROM suffix_log WHERE applied_at < ?', [date('Y-m-d H:i:s', strtotime('-60 days'))]);
    return $n;
}
