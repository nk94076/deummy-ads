<?php
/**
 * AI Optimizer engine.
 * Combines Google Ads spend (campaign_daily) with REAL affiliate commission (Impact/AWIN)
 * to compute true ROAS per campaign, then produces ranked, explainable recommendations.
 * Heuristic + deterministic - no external LLM needed. Every recommendation is reviewable.
 */

/** Round money. */
function opt_m($v): float
{
    return round((float)$v, 2);
}

/**
 * Build the optimizer report for one account.
 * Returns ['campaigns'=>[...], 'recommendations'=>[...], 'totals'=>[...], 'networks'=>int, 'has_real'=>bool]
 */
function optimizer_report(string $owner, string $customerId, string $from, string $to, bool $demo, ?array $liveCampaigns = null): array
{
    $networks = (int)q('SELECT COUNT(*) FROM network_accounts WHERE owner = ?', [$owner])->fetchColumn();

    if ($demo) {
        return optimizer_demo();
    }

    // Google spend + conversions per campaign. Prefer LIVE data (so spend shows immediately,
    // without waiting for a sync); fall back to the synced campaign_daily table.
    $camps = [];
    if ($liveCampaigns !== null) {
        foreach ($liveCampaigns as $c) {
            $id = (string)($c['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $camps[$id] = [
                'campaign_id' => $id, 'name' => $c['name'] ?: ('Campaign ' . $id),
                'status' => $c['status'] ?: 'ENABLED',
                'spend' => opt_m($c['cost'] ?? 0), 'clicks' => (int)($c['clicks'] ?? 0),
                'gconv' => opt_m($c['conv'] ?? 0), 'gvalue' => opt_m($c['value'] ?? 0),
            ];
        }
    } else {
        foreach (q("SELECT campaign_id, MAX(campaign_name) name, MAX(status) status,
                           SUM(cost) spend, SUM(clicks) clicks, SUM(conversions) gconv, SUM(conv_value) gvalue
                    FROM campaign_daily WHERE customer_id = ? AND date BETWEEN ? AND ?
                    GROUP BY campaign_id", [$customerId, $from, $to]) as $r) {
            $camps[(string)$r['campaign_id']] = [
                'campaign_id' => (string)$r['campaign_id'], 'name' => $r['name'] ?: ('Campaign ' . $r['campaign_id']),
                'status' => $r['status'] ?: 'ENABLED',
                'spend' => opt_m($r['spend']), 'clicks' => (int)$r['clicks'],
                'gconv' => opt_m($r['gconv']), 'gvalue' => opt_m($r['gvalue']),
            ];
        }
    }
    $rev = affiliate_revenue_by_campaign($owner, $from, $to);

    $rows = [];
    foreach ($camps as $id => $c) {
        $r = $rev[$id] ?? ['approved' => 0, 'pending' => 0, 'reversed' => 0, 'live' => 0, 'sales' => 0, 'conversions' => 0];
        $comm = opt_m($r['live']);              // approved + pending (not reversed)
        $spend = $c['spend'];
        $rows[] = $c + [
            'commission' => $comm, 'approved' => opt_m($r['approved']), 'pending' => opt_m($r['pending']),
            'reversed' => opt_m($r['reversed']), 'aff_conv' => (int)$r['conversions'], 'sales' => opt_m($r['sales']),
            'real_roas' => $spend > 0 ? round($comm / $spend, 2) : null,
            'profit' => opt_m($comm - $spend),
        ];
    }
    usort($rows, fn($a, $b) => $b['spend'] <=> $a['spend']);

    $recs = optimizer_recommendations($rows, $networks > 0);
    $tot = [
        'spend' => opt_m(array_sum(array_column($rows, 'spend'))),
        'commission' => opt_m(array_sum(array_column($rows, 'commission'))),
        'pending' => opt_m(array_sum(array_column($rows, 'pending'))),
    ];
    $tot['profit'] = opt_m($tot['commission'] - $tot['spend']);
    $tot['real_roas'] = $tot['spend'] > 0 ? round($tot['commission'] / $tot['spend'], 2) : null;

    return ['campaigns' => $rows, 'recommendations' => $recs, 'totals' => $tot, 'networks' => $networks, 'has_real' => (bool)array_filter($rows, fn($x) => $x['commission'] > 0)];
}

/** Turn per-campaign real economics into ranked recommendations. */
function optimizer_recommendations(array $rows, bool $networksConnected): array
{
    $recs = [];
    foreach ($rows as $c) {
        $spend = $c['spend'];
        $comm = $c['commission'];
        $roas = $c['real_roas'];
        if ($spend < 200) {
            continue; // too little spend to judge
        }
        // Tracking gap: real money spent but no revenue signal at all.
        if ($networksConnected && $comm <= 0 && $c['gconv'] <= 0) {
            $recs[] = opt_rec('tracking', 'high', $c,
                'No revenue tracked despite ₹' . opt_m($spend) . ' spend',
                'This campaign spent money but no affiliate commission or Google conversion came back. Usually the sub-id / tracking template is missing, so conversions can\'t be matched. Fix tracking before judging performance.',
                $spend, ['type' => 'none']);
            continue;
        }
        if ($roas === null) {
            continue;
        }
        // Losing money on real commission.
        if ($roas < 0.9) {
            $loss = opt_m($spend - $comm);
            $recs[] = opt_rec('waste', $loss >= 1000 ? 'high' : 'medium', $c,
                'Losing money — real ROAS ' . number_format($roas, 2),
                'Spent ₹' . opt_m($spend) . ' and earned only ₹' . $comm . ' in commission (a ₹' . $loss . ' loss this window). Pause it, or cut the budget and tighten keywords/negatives.',
                $loss, ['type' => 'pause']);
        } elseif ($roas >= 1.6 && $comm >= 500) {
            $extra = opt_m(($comm - $spend) * 0.3);
            $recs[] = opt_rec('scale', $roas >= 3 ? 'high' : 'medium', $c,
                'Winner — real ROAS ' . number_format($roas, 2) . ', scale it',
                'Earning ₹' . $comm . ' on ₹' . opt_m($spend) . ' spend (profit ₹' . $c['profit'] . '). Raise the daily budget ~30% to capture more — projected extra profit about ₹' . $extra . '.',
                $extra, ['type' => 'raise_budget', 'pct' => 30]);
        } elseif ($roas < 1.1) {
            $recs[] = opt_rec('watch', 'low', $c,
                'Break-even — real ROAS ' . number_format($roas, 2),
                'Roughly breaking even. Add negative keywords from wasteful search terms and trim low-converting keywords to push it into profit.',
                0, ['type' => 'none']);
        }
        // Pending revenue note (affiliate revenue is delayed).
        if ($c['pending'] >= 500 && $c['pending'] > $c['approved']) {
            $recs[] = opt_rec('pending', 'low', $c,
                '₹' . $c['pending'] . ' commission still pending',
                'A lot of this campaign\'s commission is still pending approval, so real ROAS may improve once it clears. Don\'t cut it purely on today\'s numbers.',
                0, ['type' => 'none']);
        }
    }
    // Rank: severity then impact.
    $sev = ['high' => 3, 'medium' => 2, 'low' => 1];
    usort($recs, fn($a, $b) => [$sev[$b['severity']], $b['impact']] <=> [$sev[$a['severity']], $a['impact']]);
    return $recs;
}

function opt_rec(string $type, string $severity, array $c, string $title, string $detail, $impact, array $action): array
{
    return ['type' => $type, 'severity' => $severity, 'campaign_id' => $c['campaign_id'],
            'campaign_name' => $c['name'], 'title' => $title, 'detail' => $detail,
            'impact' => opt_m($impact), 'action' => $action];
}

/** Synthetic optimizer report for the no-connection demo. */
function optimizer_demo(): array
{
    $rows = [
        ['campaign_id' => '21000000000', 'name' => 'Flipkart Home Decor — Brand', 'status' => 'ENABLED', 'spend' => 4200, 'clicks' => 860, 'gconv' => 12, 'gvalue' => 0, 'commission' => 12800, 'approved' => 9800, 'pending' => 3000, 'reversed' => 900, 'aff_conv' => 41, 'sales' => 142000, 'real_roas' => 3.05, 'profit' => 8600],
        ['campaign_id' => '21000000001', 'name' => 'Lelaha Fashion — Generic', 'status' => 'ENABLED', 'spend' => 3800, 'clicks' => 540, 'gconv' => 3, 'gvalue' => 0, 'commission' => 1500, 'approved' => 900, 'pending' => 600, 'reversed' => 1200, 'aff_conv' => 9, 'sales' => 21000, 'real_roas' => 0.39, 'profit' => -2300],
        ['campaign_id' => '21000000002', 'name' => 'ProvaDent US — Search', 'status' => 'ENABLED', 'spend' => 2600, 'clicks' => 410, 'gconv' => 0, 'gvalue' => 0, 'commission' => 0, 'approved' => 0, 'pending' => 0, 'reversed' => 0, 'aff_conv' => 0, 'sales' => 0, 'real_roas' => 0.0, 'profit' => -2600],
        ['campaign_id' => '21000000003', 'name' => 'Home Decor — Broad', 'status' => 'ENABLED', 'spend' => 1500, 'clicks' => 300, 'gconv' => 4, 'gvalue' => 0, 'commission' => 1550, 'approved' => 1400, 'pending' => 150, 'reversed' => 0, 'aff_conv' => 6, 'sales' => 18000, 'real_roas' => 1.03, 'profit' => 50],
    ];
    $recs = optimizer_recommendations($rows, true);
    $tot = ['spend' => 12100, 'commission' => 15850, 'pending' => 3750, 'profit' => 3750, 'real_roas' => 1.31];
    return ['campaigns' => $rows, 'recommendations' => $recs, 'totals' => $tot, 'networks' => 1, 'has_real' => true, 'demo' => true];
}
