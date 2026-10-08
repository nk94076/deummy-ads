<?php
/**
 * Automation rules
 * Example: "If cost > 2000 and conversions < 1 in the last 7 days, pause the campaign"
 * - Rules are per user (MySQL: rules table)
 * - "Preview" (changes nothing) or "Run now" from the dashboard
 * - cron.php runs all enabled rules every hour
 */

const RULE_METRICS = ['cost' => 'Cost', 'clicks' => 'Clicks', 'impr' => 'Impressions', 'conv' => 'Conversions',
                      'ctr' => 'CTR %', 'cpc' => 'Avg CPC', 'cpa' => 'CPA', 'roas' => 'ROAS'];

/** Build the service: demo or real (with access check) */
function service_for(string $owner, string $connId, string $cid, bool $write = false)
{
    if (str_starts_with($connId, 'demo')) {
        return new AdsDemo($cid);
    }
    [$client, $acc] = resolve_account($owner, $connId, $cid);
    if ($write && ($acc['access'] ?? 'owner') === 'view') {
        throw new RuntimeException('You have view-only access to account ' . fmt_cid($cid) . ' (shared by ' . ($acc['shared_by'] ?? '?') . '). Changes are not allowed.');
    }
    return new AdsReal($client, $cid);
}

function rule_from_row(array $r): array
{
    $d = json_decode($r['data'], true) ?: [];
    return array_merge($d, ['id' => $r['id'], 'owner' => $r['owner'], 'enabled' => (bool)$r['enabled'],
                            'last_run' => $r['last_run'], 'last_result' => $r['last_result']]);
}

function rules_all(): array
{
    $out = [];
    foreach (q('SELECT * FROM rules') as $r) {
        $out[$r['id']] = rule_from_row($r);
    }
    return $out;
}

function rules_for(string $owner): array
{
    return array_map('rule_from_row', q('SELECT * FROM rules WHERE owner = ? ORDER BY id', [$owner])->fetchAll());
}

function rule_mark(string $id, string $result): void
{
    q('UPDATE rules SET last_run = ?, last_result = ? WHERE id = ?', [now(), mb_substr($result, 0, 250), $id]);
}

function rule_save(string $owner, array $in): array
{
    $id = preg_replace('/\W/', '', $in['id'] ?? '') ?: substr(bin2hex(random_bytes(6)), 0, 10);
    $existing = q('SELECT owner FROM rules WHERE id = ?', [$id])->fetchColumn();
    if ($existing !== false && $existing !== $owner) {
        throw new RuntimeException('Rule not found.');
    }
    $conds = [];
    foreach ((array)($in['conditions'] ?? []) as $c) {
        if (isset(RULE_METRICS[$c['metric'] ?? '']) && in_array($c['op'] ?? '', ['gt', 'lt'], true) && is_numeric($c['value'] ?? null)) {
            $conds[] = ['metric' => $c['metric'], 'op' => $c['op'], 'value' => (float)$c['value']];
        }
    }
    if (!$conds) {
        throw new RuntimeException('Add at least one valid condition.');
    }
    $rule = [
        'id' => $id, 'owner' => $owner,
        'name' => trim($in['name'] ?? '') ?: 'Rule ' . $id,
        'conn' => preg_replace('/\W/', '', $in['conn'] ?? ''),
        'cid' => preg_replace('/\D/', '', $in['cid'] ?? ''),
        'account_name' => (string)($in['account_name'] ?? ''),
        'days' => max(1, min(90, (int)($in['days'] ?? 7))),
        'conditions' => $conds,
        'action' => ($in['action'] ?? 'PAUSE') === 'ENABLE' ? 'ENABLE' : 'PAUSE',
        'campaign_ids' => array_values(array_map('strval', (array)($in['campaign_ids'] ?? []))),
        'enabled' => !empty($in['enabled']),
    ];
    if (!$rule['conn'] || !$rule['cid']) {
        throw new RuntimeException('Select an account.');
    }
    q('INSERT INTO rules (id, owner, data, enabled) VALUES (?,?,?,?)
       ON DUPLICATE KEY UPDATE data = VALUES(data), enabled = VALUES(enabled)',
      [$id, $owner, json_encode($rule), (int)$rule['enabled']]);
    return rule_from_row(q('SELECT * FROM rules WHERE id = ?', [$id])->fetch());
}

function rule_delete(string $owner, string $id): void
{
    q('DELETE FROM rules WHERE id = ? AND owner = ?', [$id, $owner]);
}

function derive_metrics(array $m): array
{
    $m['ctr'] = $m['impr'] ? $m['clicks'] / $m['impr'] * 100 : 0;
    $m['cpc'] = $m['clicks'] ? $m['cost'] / $m['clicks'] : 0;
    // CPA/ROAS are undefined without conversions/cost - null, never 0, so a rule like "CPA < 100"
    // does NOT match a spend-but-zero-conversion campaign.
    $m['cpa'] = $m['conv'] ? $m['cost'] / $m['conv'] : null;
    $m['roas'] = $m['cost'] ? $m['value'] / $m['cost'] : null;
    return $m;
}

/** Run a rule. $apply=false => preview only */
function rule_run(array $rule, bool $apply): array
{
    $svc  = service_for($rule['owner'], $rule['conn'], $rule['cid'], $apply);
    $to   = date('Y-m-d', strtotime('-1 day'));
    $from = date('Y-m-d', strtotime('-' . $rule['days'] . ' days'));
    $need = $rule['action'] === 'PAUSE' ? 'ENABLED' : 'PAUSED';
    $hits = [];
    foreach ($svc->campaigns($from, $to) as $c) {
        if ($c['status'] !== $need) {
            continue;
        }
        if ($rule['campaign_ids'] && !in_array($c['id'], $rule['campaign_ids'], true)) {
            continue;
        }
        $m = derive_metrics($c);
        $ok = true;
        foreach ($rule['conditions'] as $cond) {
            $v = $m[$cond['metric']] ?? null;
            // An undefined metric (e.g. CPA/ROAS with no conversions) never satisfies a numeric condition
            if ($v === null || ($cond['op'] === 'gt' ? !($v > $cond['value']) : !($v < $cond['value']))) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $hits[] = ['id' => $c['id'], 'name' => $c['name'], 'cost' => round($m['cost'], 2),
                       'conv' => $m['conv'], 'roas' => $m['roas'] === null ? null : round($m['roas'], 2),
                       'cpa' => $m['cpa'] === null ? null : round($m['cpa'], 2)];
        }
    }
    $done = 0;
    if ($apply) {
        $status = $rule['action'] === 'PAUSE' ? 'PAUSED' : 'ENABLED';
        foreach ($hits as $h) {
            $svc->setCampaignStatus($h['id'], $status);
            log_change($rule['owner'], $rule['cid'], "Rule \"{$rule['name']}\": campaign \"{$h['name']}\" -> $status",
                       str_starts_with($rule['conn'], 'demo'));
            $done++;
        }
        rule_mark($rule['id'], "$done campaign(s) " . strtolower($status));
    }
    return ['from' => $from, 'to' => $to, 'matches' => $hits, 'applied' => $done];
}
