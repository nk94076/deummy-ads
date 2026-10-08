<?php
/**
 * DEMO MODE - used when the user has no Google account connected.
 * Same methods as AdsReal with sample data. Edits are stored in the session
 * (so the UI reflects them); nothing changes in Google Ads.
 */
require_once __DIR__ . '/demo_trivago.php';

class DemoData
{
    public static function accounts(): array
    {
        return [
            ['id' => TrivagoData::ACCOUNT_ID, 'name' => TrivagoData::ACCOUNT_NAME, 'currency' => 'INR', 'status' => 'ENABLED',
             'login' => TrivagoData::MCC_ID, 'via' => TrivagoData::MCC_NAME, 'conn' => 'demo1', 'email' => TrivagoData::EMAIL],
        ];
    }
}

class AdsDemo
{
    private const STATE_VERSION = 3;
    private array $st;

    public function __construct(private string $cid)
    {
        if (($_SESSION['demo'][$cid]['_v'] ?? 0) !== self::STATE_VERSION) {
            $_SESSION['demo'][$cid] = $this->seedState() + ['_v' => self::STATE_VERSION];
        }
        $this->st = &$_SESSION['demo'][$cid];
    }

    /** Campaigns, ad groups, ads and keywords built from the Trivago sheet */
    private function seedState(): array
    {
        $camps = [];
        $ags = [];
        $ads = [];
        $kws = [];
        $targeting = [];
        foreach (TrivagoData::load()['camps'] as $id => $c) {
            $id = (string)$id;
            $camps[$id] = ['id' => $id, 'name' => $c['name'], 'status' => $c['status'], 'type' => 'SEARCH',
                'bidding' => $c['bidding'], 'budget' => $c['budget'], 'share' => 1.0,
                'tracking_url_template' => '{lpurl}?utm_source=google&utm_medium=cpc&utm_campaign={campaignid}',
                'final_url_suffix' => '', 'custom_params' => [], 'target_cpa' => null, 'target_roas' => null,
                'advertiser' => $c['advertiser'], 'network' => $c['network']];
            $targeting[$id] = ['locations' => [$c['geo']], 'excluded' => [], 'languages' => array_values(array_unique([$c['lang_id'], '1000'])),
                               'schedule' => [], 'geo_type' => 'PRESENCE'];
            foreach (TrivagoData::adGroups($c) as $gi => [$gName, $gW, $words]) {
                $agId = (string)(150000000000 + crc32($id . $gi) % 100000000);
                $ags[$agId] = ['id' => $agId, 'camp' => $id, 'name' => $gName, 'status' => 'ENABLED', 'type' => 'SEARCH_STANDARD',
                               'cpc_bid' => round($c['cpc'] * ($gi ? 0.8 : 1.15), 2), 'share' => $gW,
                               'tracking_url_template' => '', 'final_url_suffix' => ''];
                foreach ([0.58, 0.42] as $ai => $aW) {
                    $copy = TrivagoData::adCopy($c['lang'], $c['country'], $ai + $gi);
                    $adId = (string)(700000000000 + crc32($agId . $ai) % 100000000);
                    $ads[$adId] = ['id' => $adId, 'ag_id' => $agId, 'camp' => $id, 'type' => 'RESPONSIVE_SEARCH_AD',
                                   'title' => implode(' | ', array_slice($copy['headlines'], 0, 3)),
                                   'status' => 'ENABLED', 'approval' => 'APPROVED', 'share' => $gW * $aW,
                                   'final_urls' => [$c['url']], 'tracking_url_template' => '', 'final_url_suffix' => ''] + $copy;
                }
                $wSum = array_sum(array_column($words, 2));
                foreach ($words as $k => [$text, $match, $w]) {
                    $kid = (string)(300000 + crc32($agId . $k) % 900000);
                    $kws["$agId~$kid"] = ['id' => $kid, 'ag_id' => $agId, 'camp' => $id, 'text' => $text, 'match' => $match,
                                          'status' => 'ENABLED', 'cpc_bid' => 0, 'qs' => 6 + crc32($text . $id) % 5,
                                          'share' => $gW * $w / $wSum];
                }
            }
        }
        return [
            'camps' => $camps, 'ags' => $ags, 'ads' => $ads, 'kws' => $kws, 'negs' => [], 'asset_groups' => [],
            'targeting' => $targeting,
            'account' => ['tracking_url_template' => '', 'final_url_suffix' => '', 'auto_tagging' => true],
        ];
    }

    /** Metrics for a campaign's child (share of the campaign's real sheet totals) */
    private function childMetrics(array $x, string $from, string $to): array
    {
        $w = (float)($x['share'] ?? 0);
        return $w > 0 ? TrivagoData::share(TrivagoData::range((string)$x['camp'], $from, $to), $w) : ZERO_METRICS;
    }

    private function days(string $from, string $to): int
    {
        return (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
    }

    public function report(string $from, string $to, string $prevFrom, string $prevTo): array
    {
        $ids = array_keys($this->st['camps']);
        $daily = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $day = date('Y-m-d', $d);
            $daily[] = ['date' => $day] + sum_metrics(array_map(fn($id) => TrivagoData::day((string)$id, $day), $ids));
        }
        $prev = sum_metrics(array_map(fn($id) => TrivagoData::range((string)$id, $prevFrom, $prevTo), $ids));
        return ['currency' => 'INR', 'daily' => $daily, 'previous' => $prev, 'campaigns' => $this->campaigns($from, $to)];
    }

    public function campaignDaily(string $from, string $to): array
    {
        $out = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $day = date('Y-m-d', $d);
            foreach ($this->campaigns($day, $day) as $c) {
                if ($c['impr'] || $c['cost'] || $c['conv'] || $c['value']) {
                    $out[] = ['campaign_id' => $c['id'], 'name' => $c['name'], 'status' => $c['status'], 'channel' => $c['type'], 'date' => $day]
                           + array_intersect_key($c, ZERO_METRICS);
                }
            }
        }
        return $out;
    }

    public function campaigns(string $from, string $to): array
    {
        $out = [];
        foreach ($this->st['camps'] as $c) {
            $out[] = ['id' => (string)$c['id'], 'name' => $c['name'], 'status' => $c['status'], 'type' => $c['type'],
                      'bidding' => $c['bidding'], 'budget' => $c['budget']] + TrivagoData::range((string)$c['id'], $from, $to);
        }
        return $out;
    }

    public function campaign(string $id): array
    {
        $c = $this->st['camps'][$id] ?? throw new RuntimeException('Campaign not found.');
        return $c + [
            'serving_status' => 'SERVING', 'budget_shared' => false,
            'can_target_cpa' => in_array($c['bidding'], ['TARGET_CPA', 'MAXIMIZE_CONVERSIONS']),
            'can_target_roas' => in_array($c['bidding'], ['TARGET_ROAS', 'MAXIMIZE_CONVERSION_VALUE']),
        ];
    }

    public function updateCampaign(string $id, array $in): array
    {
        $c = &$this->st['camps'][$id];
        $u = [];
        $m = [];
        url_fields($in, $u, $m);
        foreach (['name', 'status', 'budget', 'target_cpa', 'target_roas'] as $f) {
            if (isset($in[$f]) && $in[$f] !== '') {
                $c[$f] = in_array($f, ['budget', 'target_cpa', 'target_roas']) ? (float)$in[$f] : $in[$f];
                $m[] = $f;
            }
        }
        if (isset($u['trackingUrlTemplate'])) $c['tracking_url_template'] = $u['trackingUrlTemplate'];
        if (isset($u['finalUrlSuffix'])) $c['final_url_suffix'] = $u['finalUrlSuffix'];
        if (isset($u['urlCustomParameters'])) $c['custom_params'] = $u['urlCustomParameters'];
        return $m;
    }

    public function setCampaignStatus(string $id, string $status): void
    {
        $this->st['camps'][$id]['status'] = $status;
    }

    public function adGroups(string $campId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->st['ags'] as $g) {
            if ($g['camp'] === $campId) {
                $out[] = $g + $this->childMetrics($g, $from, $to);
            }
        }
        return $out;
    }

    public function createAdGroup(string $campId, array $in): string
    {
        $c = $this->campaign($campId);
        if (!in_array($c['type'], ['SEARCH', 'DISPLAY', 'SHOPPING'])) {
            throw new RuntimeException('Ad groups cannot be created here for this campaign type (' . $c['type'] . '). Use a Search, Display or Shopping campaign.');
        }
        if (trim($in['name'] ?? '') === '') {
            throw new RuntimeException('Ad group name is required.');
        }
        $id = (string)(160000000000 + mt_rand(1, 99999999));
        $this->st['ags'][$id] = ['id' => $id, 'camp' => $campId, 'name' => trim($in['name']),
            'status' => ($in['status'] ?? 'ENABLED'), 'type' => 'SEARCH_STANDARD', 'cpc_bid' => (float)($in['cpc_bid'] ?? 0),
            'w' => 0, 'tracking_url_template' => $in['tracking_url_template'] ?? '', 'final_url_suffix' => $in['final_url_suffix'] ?? ''];
        return $id;
    }

    public function updateAdGroup(string $agId, array $in): array
    {
        $g = &$this->st['ags'][$agId];
        $m = [];
        foreach (['name', 'status', 'cpc_bid', 'tracking_url_template', 'final_url_suffix'] as $f) {
            if (array_key_exists($f, $in) && !($f === 'cpc_bid' && $in[$f] === '')) {
                $g[$f] = $f === 'cpc_bid' ? (float)$in[$f] : $in[$f];
                $m[] = $f;
            }
        }
        return $m;
    }

    public function ads(string $campId, string $from, string $to): array
    {
        $ads = [];
        foreach ($this->st['ads'] as $a) {
            if ($a['camp'] === $campId) {
                $a['ag_name'] = $this->st['ags'][$a['ag_id']]['name'] ?? '';
                $ads[] = $a + $this->childMetrics($a, $from, $to);
            }
        }
        $groups = array_values(array_filter($this->st['asset_groups'], fn($g) => $g['camp'] === $campId));
        return ['ads' => $ads, 'asset_groups' => $groups];
    }

    public function updateAd(string $agId, string $adId, array $in): array
    {
        $a = &$this->st['ads'][$adId];
        $m = [];
        if (array_key_exists('final_urls', $in)) {
            $urls = clean_urls($in['final_urls']);
            if (!$urls) throw new RuntimeException('At least one final URL is required.');
            $a['final_urls'] = $urls;
            $m[] = 'finalUrls';
        }
        foreach (['status', 'tracking_url_template', 'final_url_suffix'] as $f) {
            if (array_key_exists($f, $in)) {
                $a[$f] = $in[$f];
                $m[] = $f;
            }
        }
        return $m;
    }

    public function updateAssetGroup(string $id, array $in): array
    {
        foreach ($this->st['asset_groups'] as &$g) {
            if ($g['id'] === $id) {
                if (isset($in['final_urls'])) $g['final_urls'] = clean_urls($in['final_urls']);
                if (isset($in['status'])) $g['status'] = $in['status'];
            }
        }
        return ['finalUrls'];
    }

    public function keywords(string $campId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->st['kws'] as $k) {
            if ($k['camp'] === $campId) {
                $k['ag_name'] = $this->st['ags'][$k['ag_id']]['name'] ?? '';
                $k += ['final_url' => '', 'final_mobile_url' => '', 'tracking_url_template' => '', 'final_url_suffix' => '', 'custom_params' => []];
                $out[] = $k + $this->childMetrics($k, $from, $to);
            }
        }
        return $out;
    }

    public function addKeywords(string $agId, array $lines, string $match, $cpc = null, string $finalUrl = ''): int
    {
        $url = clean_urls([$finalUrl])[0] ?? '';
        $n = 0;
        foreach ($lines as $t) {
            $t = trim($t);
            if ($t === '') continue;
            $kid = (string)mt_rand(100000, 999999);
            $this->st['kws']["$agId~$kid"] = ['id' => $kid, 'ag_id' => $agId, 'camp' => $this->st['ags'][$agId]['camp'],
                'text' => $t, 'match' => $match, 'status' => 'ENABLED', 'cpc_bid' => (float)$cpc, 'qs' => null, 'w' => 0,
                'final_url' => $url, 'final_mobile_url' => '', 'tracking_url_template' => '', 'final_url_suffix' => '', 'custom_params' => []];
            $n++;
        }
        if (!$n) throw new RuntimeException('No keywords entered.');
        return $n;
    }

    public function updateKeyword(string $agId, string $critId, array $in): array
    {
        $k = &$this->st['kws']["$agId~$critId"];
        $done = [];
        foreach (['status', 'cpc_bid'] as $f) {
            if (isset($in[$f]) && $in[$f] !== '') {
                $k[$f] = $f === 'cpc_bid' ? (float)$in[$f] : $in[$f];
                $done[] = $f === 'cpc_bid' ? 'cpcBidMicros' : 'status';
            }
        }
        foreach (['final_url' => 'finalUrls', 'final_mobile_url' => 'finalMobileUrls'] as $f => $m) {
            if (array_key_exists($f, $in)) {
                $k[$f] = clean_urls([$in[$f]])[0] ?? '';
                $done[] = $m;
            }
        }
        $u = [];
        $mask = [];
        url_fields($in, $u, $mask);
        foreach (['trackingUrlTemplate' => 'tracking_url_template', 'finalUrlSuffix' => 'final_url_suffix'] as $api => $f) {
            if (array_key_exists($api, $u)) $k[$f] = $u[$api];
        }
        if (isset($u['urlCustomParameters'])) $k['custom_params'] = $u['urlCustomParameters'];
        return array_merge($done, $mask);
    }

    public function searchTerms(string $campId, string $from, string $to): array
    {
        $c = TrivagoData::load()['camps'][$campId] ?? null;
        if (!$c) {
            return [];
        }
        $tot = TrivagoData::range($campId, $from, $to);
        $terms = TrivagoData::searchTerms($c);
        $wSum = array_sum(array_column($terms, 1)) / 0.82; // ~82% of spend is visible as search terms (like Google)
        $groups = array_values(array_filter($this->st['ags'], fn($g) => $g['camp'] === $campId));
        $out = [];
        foreach ($terms as $i => [$t, $w, $added]) {
            $isBrand = str_contains($t, 'trivago');
            $ag = $groups[$isBrand && isset($groups[1]) ? 1 : 0]['name'] ?? '';
            $out[] = ['term' => $t, 'status' => $added ? 'ADDED' : 'NONE', 'ag_name' => $ag] + TrivagoData::share($tot, $w / $wSum);
        }
        usort($out, fn($a, $b) => $b['cost'] <=> $a['cost']);
        return $out;
    }

    public function negatives(string $campId): array
    {
        return array_values(array_filter($this->st['negs'], fn($n) => $n['camp'] === $campId));
    }

    public function addNegatives(string $campId, array $terms, string $match): int
    {
        $n = 0;
        foreach ($terms as $t) {
            if (trim($t) === '') continue;
            $id = (string)mt_rand(100000, 999999);
            $this->st['negs'][$id] = ['id' => $id, 'camp' => $campId, 'text' => trim($t), 'match' => $match];
            $n++;
        }
        return $n;
    }

    public function removeNegative(string $campId, string $critId): void
    {
        unset($this->st['negs'][$critId]);
    }

    public function accountSettings(): array
    {
        $a = DemoData::accounts();
        $name = '';
        foreach ($a as $x) if ($x['id'] === $this->cid) $name = $x['name'];
        return ['name' => $name, 'currency' => 'INR', 'time_zone' => 'Asia/Calcutta'] + $this->st['account'];
    }

    public function updateAccountSettings(array $in): array
    {
        foreach (['tracking_url_template', 'final_url_suffix', 'auto_tagging'] as $f) {
            if (array_key_exists($f, $in)) $this->st['account'][$f] = $f === 'auto_tagging' ? (bool)$in[$f] : $in[$f];
        }
        return ['ok'];
    }

    /** Hour-of-day curve for travel search (share of a day's clicks per hour, 0-23) */
    private const HOUR_CURVE = [1, 0.6, 0.4, 0.3, 0.3, 0.5, 1.2, 2.4, 3.6, 4.6, 5.2, 5.6, 5.8, 5.6, 5.4, 5.3, 5.4, 5.8, 6.4, 7.2, 7.8, 7.4, 5.6, 3.0];

    public function hourlyClicks(string $from, string $to, ?array $campaignIds = null): array
    {
        $ids = $campaignIds ? array_map('strval', $campaignIds) : array_keys($this->st['camps']);
        $sumW = array_sum(self::HOUR_CURVE);
        $out = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $day = date('Y-m-d', $d);
            $clicks = array_sum(array_map(fn($id) => TrivagoData::day((string)$id, $day)['clicks'], $ids));
            foreach (self::HOUR_CURVE as $h => $w) {
                $out[$day . ' ' . str_pad((string)$h, 2, '0', STR_PAD_LEFT)] = (int)round($clicks * $w / $sumW);
            }
        }
        return $out;
    }

    public function breakdown(string $type, string $from, string $to): array
    {
        $ids = array_keys($this->st['camps']);
        if ($type === 'dow') { // real: sum the sheet by weekday
            $out = [];
            foreach (['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'] as $l) {
                $out[$l] = ['label' => $l] + ZERO_METRICS;
            }
            for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
                $l = strtoupper(date('l', $d));
                $out[$l] = ['label' => $l] + sum_metrics([$out[$l], ...array_map(fn($id) => TrivagoData::day((string)$id, date('Y-m-d', $d)), $ids)]);
            }
            return array_values($out);
        }
        $tot = sum_metrics(array_map(fn($id) => TrivagoData::range((string)$id, $from, $to), $ids));
        $split = [
            'device' => ['MOBILE' => 0.61, 'DESKTOP' => 0.34, 'TABLET' => 0.05],
            'network' => ['SEARCH' => 0.93, 'SEARCH_PARTNERS' => 0.07],
            'hour' => array_map(fn($w) => $w / array_sum(self::HOUR_CURVE), self::HOUR_CURVE),
        ][$type] ?? [];
        $out = [];
        foreach ($split as $l => $w) {
            $out[] = ['label' => (string)$l] + TrivagoData::share($tot, $w);
        }
        return $out;
    }

    public function bulkUrlReplace(string $find, string $replace, ?string $campId, bool $apply): array
    {
        if ($find === '') throw new RuntimeException('"Find" cannot be empty.');
        $changes = [];
        foreach ($this->st['ads'] as $id => $a) {
            if ($campId && $a['camp'] !== $campId) continue;
            $new = array_map(fn($u) => str_replace($find, $replace, $u), $a['final_urls']);
            if ($new !== $a['final_urls']) {
                $changes[] = ['kind' => 'ad', 'id' => (string)$id, 'campaign' => $this->st['camps'][$a['camp']]['name'], 'urls' => $a['final_urls'], 'new' => $new];
                if ($apply) $this->st['ads'][$id]['final_urls'] = $new;
            }
        }
        foreach ($this->st['asset_groups'] as $i => $g) {
            if ($campId && $g['camp'] !== $campId) continue;
            $new = array_map(fn($u) => str_replace($find, $replace, $u), $g['final_urls']);
            if ($new !== $g['final_urls']) {
                $changes[] = ['kind' => 'asset_group', 'id' => $g['id'], 'campaign' => $this->st['camps'][$g['camp']]['name'], 'urls' => $g['final_urls'], 'new' => $new];
                if ($apply) $this->st['asset_groups'][$i]['final_urls'] = $new;
            }
        }
        return ['count' => count($changes), 'preview' => $changes, 'applied' => $apply ? count($changes) : 0, 'failed' => 0, 'errors' => []];
    }

    public function bulkTracking(string $scope, array $in): array
    {
        if ($scope === 'account') {
            $this->updateAccountSettings($in);
            return ['updated' => 1, 'scope' => 'account'];
        }
        $n = 0;
        foreach ($this->st['camps'] as $id => $c) {
            if (!empty($in['campaign_ids']) && !in_array($id, $in['campaign_ids'])) continue;
            $this->updateCampaign($id, array_intersect_key($in, array_flip(['tracking_url_template', 'final_url_suffix'])));
            $n++;
        }
        return ['updated' => $n, 'scope' => 'campaigns'];
    }

    // ================= New campaign / RSA / targeting (demo) =================
    public function createSearchCampaign(array $in, bool $validateOnly = false): array
    {
        $v = validate_new_campaign($in);
        new_campaign_operations($this->cid, $v); // also builds and checks the payload
        foreach ($this->st['camps'] as $c) {
            if (mb_strtolower($c['name']) === mb_strtolower($v['name'])) {
                throw new RuntimeException('API error (400): DUPLICATE_CAMPAIGN_NAME - A campaign with this name already exists.');
            }
        }
        if ($validateOnly) {
            return ['validated' => true, 'keywords' => count($v['keywords'])];
        }
        $id = (string)(22000000000 + mt_rand(1, 99999999));
        $agId = (string)(170000000000 + mt_rand(1, 99999999));
        $this->st['camps'][$id] = ['id' => $id, 'name' => $v['name'], 'status' => 'PAUSED', 'type' => 'SEARCH',
            'bidding' => $v['bidding'] === 'MAXIMIZE_CLICKS' ? 'TARGET_SPEND' : $v['bidding'], 'budget' => $v['budget'], 'w' => 0,
            'tracking_url_template' => $v['tracking_url_template'], 'final_url_suffix' => $v['final_url_suffix'], 'custom_params' => [],
            'target_cpa' => $v['target_cpa'] ?: null, 'target_roas' => null];
        $this->st['ags'][$agId] = ['id' => $agId, 'camp' => $id, 'name' => $v['ad_group'], 'status' => 'ENABLED',
            'type' => 'SEARCH_STANDARD', 'cpc_bid' => $v['cpc_bid'], 'w' => 0, 'tracking_url_template' => '', 'final_url_suffix' => ''];
        foreach ($v['keywords'] as $k) {
            $kid = (string)mt_rand(100000, 999999);
            $this->st['kws']["$agId~$kid"] = ['id' => $kid, 'ag_id' => $agId, 'camp' => $id, 'text' => $k['text'],
                'match' => $k['match'], 'status' => 'ENABLED', 'cpc_bid' => 0, 'qs' => null, 'w' => 0];
        }
        foreach ($v['negatives'] as $k) {
            $nid = (string)mt_rand(100000, 999999);
            $this->st['negs'][$nid] = ['id' => $nid, 'camp' => $id, 'text' => $k['text'], 'match' => $k['match']];
        }
        $this->addDemoAd($agId, $id, $v['ad'], 'ENABLED');
        $this->st['targeting'][$id] = ['locations' => $v['locations'], 'excluded' => $v['excluded'], 'languages' => $v['languages'],
                                       'schedule' => $v['schedule'], 'geo_type' => $v['geo_type']];
        return ['campaign_id' => $id, 'ad_group_id' => $agId, 'name' => $v['name'], 'keywords' => count($v['keywords'])];
    }

    private function addDemoAd(string $agId, string $campId, array $v, string $status): string
    {
        $adId = (string)(710000000000 + mt_rand(1, 99999999));
        $this->st['ads'][$adId] = ['id' => $adId, 'ag_id' => $agId, 'camp' => $campId, 'type' => 'RESPONSIVE_SEARCH_AD',
            'title' => implode(' | ', array_slice($v['headlines'], 0, 3)), 'status' => $status, 'approval' => 'UNDER_REVIEW', 'w' => 0,
            'final_urls' => [$v['final_url']], 'tracking_url_template' => '', 'final_url_suffix' => $v['final_url_suffix'],
            'headlines' => array_values($v['headlines']), 'descriptions' => array_values($v['descriptions'] ?? []),
            'path1' => $v['path1'] ?? '', 'path2' => $v['path2'] ?? ''];
        return $adId;
    }

    public function createRsa(string $agId, array $in): string
    {
        $v = validate_rsa($in);
        $g = $this->st['ags'][$agId] ?? throw new RuntimeException('Ad group not found.');
        return $this->addDemoAd($agId, $g['camp'], $v, ($in['status'] ?? '') === 'PAUSED' ? 'PAUSED' : 'ENABLED');
    }

    // ================= Campaign clone (demo) =================
    public function campaignBlueprint(string $campId): array
    {
        $c = $this->st['camps'][$campId] ?? throw new RuntimeException('Campaign not found.');
        if (($c['type'] ?? '') !== 'SEARCH') {
            throw new RuntimeException('Only Search campaigns can be cloned right now (this one is ' . ($c['type'] ?: 'unknown') . ').');
        }
        $t = $this->st['targeting'][$campId] ?? ['locations' => ['2356'], 'excluded' => [], 'languages' => ['1000'], 'schedule' => [], 'geo_type' => 'PRESENCE'];
        $groups = [];
        foreach ($this->st['ags'] as $g) {
            if (($g['camp'] ?? '') !== $campId) {
                continue;
            }
            $kws = [];
            foreach ($this->st['kws'] as $k) {
                if (($k['ag_id'] ?? '') === $g['id']) {
                    $kws[] = ['text' => $k['text'], 'match' => $k['match'], 'cpc_bid' => 0, 'final_url' => '', 'final_url_suffix' => '', 'tracking_url_template' => ''];
                }
            }
            $ads = [];
            foreach ($this->st['ads'] as $a) {
                if (($a['ag_id'] ?? '') === $g['id']) {
                    $ads[] = ['headlines' => $a['headlines'] ?? ['Headline one', 'Headline two', 'Headline three'],
                              'descriptions' => $a['descriptions'] ?? ['Description line one here', 'Description line two here'],
                              'path1' => $a['path1'] ?? '', 'path2' => $a['path2'] ?? '', 'final_url' => $a['final_urls'][0] ?? 'https://example.com',
                              'final_url_suffix' => '', 'tracking_url_template' => ''];
                }
            }
            $groups[] = ['name' => $g['name'], 'cpc_bid' => $g['cpc_bid'] ?? 0, 'tracking_url_template' => '', 'final_url_suffix' => '',
                         'keywords' => $kws, 'ads' => $ads];
        }
        $negs = [];
        foreach ($this->st['negs'] as $n) {
            if (($n['camp'] ?? '') === $campId) {
                $negs[] = ['text' => $n['text'], 'match' => $n['match']];
            }
        }
        return ['source_id' => $campId, 'name' => $c['name'], 'budget' => $c['budget'], 'bidding' => $c['bidding'],
                'target_cpa' => $c['target_cpa'] ?? null, 'target_roas' => $c['target_roas'] ?? null,
                'tracking_url_template' => $c['tracking_url_template'] ?? '', 'final_url_suffix' => $c['final_url_suffix'] ?? '',
                'geo_type' => $t['geo_type'], 'locations' => $t['locations'], 'excluded' => $t['excluded'],
                'languages' => $t['languages'], 'schedule' => $t['schedule'], 'negatives' => $negs, 'ad_groups' => $groups];
    }

    public function createCampaignFromBlueprint(array $bp, array $opt = []): array
    {
        $build = clone_operations($this->cid, $bp, $opt); // build + validate the same way as live
        $id = (string)(23000000000 + mt_rand(1, 99999999));
        $name = trim((string)($opt['name'] ?? '')) ?: (mb_substr((string)($bp['name'] ?? 'Campaign'), 0, 240) . ' (copy)');
        $this->st['camps'][$id] = ['id' => $id, 'name' => $name, 'status' => 'PAUSED', 'type' => 'SEARCH',
            'bidding' => ($bp['bidding'] ?? '') === 'MAXIMIZE_CLICKS' ? 'TARGET_SPEND' : ($bp['bidding'] ?? 'TARGET_SPEND'),
            'budget' => (float)($opt['budget'] ?? 0) > 0 ? (float)$opt['budget'] : (float)($bp['budget'] ?? 0), 'w' => 0,
            'tracking_url_template' => $bp['tracking_url_template'] ?? '', 'final_url_suffix' => $bp['final_url_suffix'] ?? '',
            'custom_params' => [], 'target_cpa' => $bp['target_cpa'] ?? null, 'target_roas' => null];
        return ['campaign_id' => $id] + $build['counts'] + ['warnings' => $build['warnings']];
    }

    public function searchGeo(string $q): array
    {
        $extra = [
            ['id' => '1007751', 'name' => 'Delhi,India', 'type' => 'City', 'country' => 'IN'],
            ['id' => '1007785', 'name' => 'Mumbai,Maharashtra,India', 'type' => 'City', 'country' => 'IN'],
            ['id' => '21167', 'name' => 'New York,United States', 'type' => 'State', 'country' => 'US'],
            ['id' => '1023191', 'name' => 'New York,New York,United States', 'type' => 'City', 'country' => 'US'],
            ['id' => '21137', 'name' => 'California,United States', 'type' => 'State', 'country' => 'US'],
            ['id' => '21176', 'name' => 'Texas,United States', 'type' => 'State', 'country' => 'US'],
            ['id' => '20458', 'name' => 'Uttar Pradesh,India', 'type' => 'State', 'country' => 'IN'],
            ['id' => '2756', 'name' => 'Switzerland', 'type' => 'Country', 'country' => 'CH'],
        ];
        $q = mb_strtolower(trim($q));
        return array_values(array_filter(array_merge(COMMON_GEOS, $extra), fn($g) => $q !== '' && str_contains(mb_strtolower($g['name']), $q)));
    }

    public function languages(): array
    {
        return COMMON_LANGS;
    }

    public function targeting(string $campId): array
    {
        $t = ($this->st['targeting'][$campId] ?? []) + ['locations' => ['2840'], 'excluded' => [], 'languages' => ['1000'],
                                                       'schedule' => [], 'geo_type' => 'PRESENCE_OR_INTEREST'];
        $names = array_column(array_merge(COMMON_GEOS, $this->searchGeo('a'), $this->searchGeo('e'), $this->searchGeo('i')), null, 'id');
        $mk = fn($id) => ['criterion_id' => $id, 'id' => $id, 'name' => $names[$id]['name'] ?? "Location $id", 'type' => $names[$id]['type'] ?? ''];
        return [
            'locations' => array_map($mk, $t['locations']), 'excluded' => array_map($mk, $t['excluded']),
            'languages' => array_map(fn($id) => ['criterion_id' => $id, 'id' => $id], $t['languages']),
            'schedule' => array_map(fn($r) => ['criterion_id' => sched_key($r)] + $r, $t['schedule']),
            'geo_type' => $t['geo_type'],
        ];
    }

    public function updateTargeting(string $campId, array $in): array
    {
        $cur = $this->targeting($campId);
        $locs = b_ids($in['locations'] ?? []);
        $excl = b_ids($in['excluded'] ?? []);
        if (!$locs) throw new RuntimeException('Keep at least one location.');
        if (array_intersect($locs, $excl)) throw new RuntimeException('A location cannot be both targeted and excluded.');
        $langs = b_ids($in['languages'] ?? []);
        $sched = array_key_exists('schedule', $in) ? array_values(parse_schedule($in['schedule'])) : array_map(fn($r) => array_diff_key($r, ['criterion_id' => 1]), $cur['schedule']);
        $key = fn($t) => array_merge(array_map(fn($x) => "L$x", $t[0]), array_map(fn($x) => "X$x", $t[1]), array_map(fn($x) => "G$x", $t[2]), array_map('sched_key', $t[3]));
        $old = $key([array_column($cur['locations'], 'id'), array_column($cur['excluded'], 'id'), array_column($cur['languages'], 'id'),
                     array_map(fn($r) => array_diff_key($r, ['criterion_id' => 1]), $cur['schedule'])]);
        $new = $key([$locs, $excl, $langs, $sched]);
        $gt = in_array($in['geo_type'] ?? '', ['PRESENCE', 'PRESENCE_OR_INTEREST'], true) ? $in['geo_type'] : $cur['geo_type'];
        $this->st['targeting'][$campId] = ['locations' => $locs, 'excluded' => $excl, 'languages' => $langs, 'schedule' => $sched, 'geo_type' => $gt];
        return ['added' => count(array_diff($new, $old)), 'removed' => count(array_diff($old, $new)), 'geo_type_changed' => $gt !== $cur['geo_type']];
    }

    // ================= Google Ads access (demo) =================
    public function userAccess(): array
    {
        $this->st['access'] ??= [
            'users' => [['id' => '9001', 'email' => TrivagoData::EMAIL, 'role' => 'ADMIN', 'since' => '2026-07-18', 'by' => '']],
            'invites' => [],
        ];
        return $this->st['access'];
    }

    public function inviteUser(string $email, string $role): void
    {
        $a = $this->userAccess();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email.');
        if (!in_array($role, ACCESS_ROLES, true)) throw new RuntimeException('Invalid access level.');
        foreach (array_merge($a['users'], $a['invites']) as $x) {
            if (strtolower($x['email']) === strtolower($email)) throw new RuntimeException('API error (400): This email already has access or a pending invitation.');
        }
        $this->st['access']['invites'][] = ['id' => (string)mt_rand(100000, 999999), 'email' => strtolower($email), 'role' => $role, 'status' => 'PENDING', 'sent' => date('Y-m-d')];
    }

    public function revokeInvite(string $invId): void
    {
        $this->userAccess();
        $this->st['access']['invites'] = array_values(array_filter($this->st['access']['invites'], fn($i) => $i['id'] !== $invId));
    }

    public function setUserRole(string $userId, string $role): void
    {
        $this->userAccess();
        foreach ($this->st['access']['users'] as &$u) if ($u['id'] === $userId) $u['role'] = $role;
    }

    public function removeUser(string $userId): void
    {
        $this->userAccess();
        $this->st['access']['users'] = array_values(array_filter($this->st['access']['users'], fn($u) => $u['id'] !== $userId));
    }

    // ================= Smart builder (demo) =================
    public function keywordIdeas(string $url, array $geoIds, string $langId, array $seeds = []): array
    {
        $out = [];
        foreach (array_merge($seeds, array_map(fn($s) => "$s online", array_slice($seeds, 1, 4)), array_map(fn($s) => "best $s", array_slice($seeds, 1, 3))) as $i => $k) {
            mt_srand(crc32($k));
            $v = [10, 20, 50, 90, 170, 320, 590, 1300, 2400, 4400, 8100][mt_rand(0, 10)];
            $lo = mt_rand(20, 150) / 100;
            $out[] = ['text' => mb_strtolower($k), 'volume' => $v, 'competition' => ['LOW', 'MEDIUM', 'HIGH'][mt_rand(0, 2)],
                      'cpc_low' => $lo, 'cpc_high' => round($lo * mt_rand(20, 40) / 10, 2)];
        }
        return $out;
    }

    public function conversionStatus(): array
    {
        $c = array_filter($this->st['conversions'] ?? [], fn($x) => $x['status'] === 'ENABLED');
        return ['count' => count($c), 'names' => array_slice(array_column($c, 'name'), 0, 10)];
    }

    // ================= Conversion tracking (demo) =================
    public function conversions(string $from, string $to): array
    {
        if (!isset($this->st['conversions'])) {
            $this->st['conversions'] = [
                ['id' => '900000001', 'name' => 'Trivago booking (affiliate)', 'status' => 'ENABLED', 'type' => 'UPLOAD_CLICKS', 'category' => 'PURCHASE',
                 'primary' => true, 'in_conversions' => true, 'counting' => 'MANY_PER_CLICK', 'window_days' => 30,
                 'default_value' => 0, 'currency' => 'INR', 'always_default' => false, 'attribution' => 'LAST_CLICK',
                 'has_tag' => false, 'conv' => 0, 'value' => 0],
                ['id' => '900000002', 'name' => 'Outbound click to trivago', 'status' => 'ENABLED', 'type' => 'WEBPAGE', 'category' => 'OUTBOUND_CLICK',
                 'primary' => false, 'in_conversions' => false, 'counting' => 'ONE_PER_CLICK', 'window_days' => 30,
                 'default_value' => 0, 'currency' => 'INR', 'always_default' => false, 'attribution' => 'DATA_DRIVEN',
                 'has_tag' => true, 'conv' => 0, 'value' => 0],
            ];
        }
        if ($from !== '' && $to !== '') { // live numbers for the chosen dates (from the sheet)
            $tot = sum_metrics(array_map(fn($id) => TrivagoData::range((string)$id, $from, $to), array_keys($this->st['camps'])));
            foreach ($this->st['conversions'] as &$cv) {
                if ($cv['id'] === '900000001') { $cv['conv'] = $tot['conv']; $cv['value'] = $tot['value']; }
                if ($cv['id'] === '900000002') { $cv['conv'] = round($tot['clicks'] * 0.71); $cv['value'] = 0; }
            }
            unset($cv);
        }
        $out = array_map(fn($c) => array_diff_key($c, []), $this->st['conversions']);
        return ['conversions' => array_values($out), 'customer_id' => $this->cid, 'from' => $from, 'to' => $to];
    }

    public function createConversion(array $in): array
    {
        $this->conversions('', ''); // seed
        $id = (string)mt_rand(900000100, 900000999);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Conversion name is required.');
        }
        $this->st['conversions'][] = [
            'id' => $id, 'name' => $name, 'status' => 'ENABLED', 'type' => 'WEBPAGE',
            'category' => in_array($in['category'] ?? '', CONV_CATEGORIES, true) ? $in['category'] : 'DEFAULT',
            'primary' => !empty($in['primary']), 'in_conversions' => !isset($in['in_conversions']) || !empty($in['in_conversions']),
            'counting' => ($in['counting'] ?? 'ONE_PER_CLICK') === 'MANY_PER_CLICK' ? 'MANY_PER_CLICK' : 'ONE_PER_CLICK',
            'window_days' => max(1, min(90, (int)($in['window_days'] ?? 30))),
            'default_value' => (float)($in['default_value'] ?? 0), 'currency' => strtoupper((string)($in['currency'] ?? 'INR')) ?: 'INR',
            'always_default' => !empty($in['always_default']), 'attribution' => ($in['attribution'] ?? '') === 'DATA_DRIVEN' ? 'DATA_DRIVEN' : 'LAST_CLICK',
            'has_tag' => true, 'conv' => 0, 'value' => 0,
        ];
        return ['id' => $id, 'name' => $name] + $this->conversionTag($id);
    }

    public function updateConversion(string $id, array $in): array
    {
        $this->conversions('', '');
        $done = [];
        foreach ($this->st['conversions'] as &$c) {
            if ($c['id'] !== $id) {
                continue;
            }
            foreach (['name', 'status', 'category', 'counting', 'attribution'] as $f) {
                if (isset($in[$f]) && $in[$f] !== '') {
                    $c[$f] = $in[$f];
                    $done[] = $f;
                }
            }
            foreach (['primary', 'in_conversions', 'always_default'] as $f) {
                if (array_key_exists($f, $in)) {
                    $c[$f] = !empty($in[$f]);
                    $done[] = $f;
                }
            }
            if (isset($in['window_days']) && $in['window_days'] !== '') {
                $c['window_days'] = max(1, min(90, (int)$in['window_days']));
                $done[] = 'window_days';
            }
            if (array_key_exists('default_value', $in)) {
                $c['default_value'] = (float)$in['default_value'];
                $done[] = 'default_value';
            }
            if (isset($in['currency']) && $in['currency'] !== '') {
                $c['currency'] = strtoupper((string)$in['currency']);
                $done[] = 'currency';
            }
        }
        unset($c);
        return $done;
    }

    public function conversionTag(string $id): array
    {
        $this->conversions('', '');
        $name = '';
        foreach ($this->st['conversions'] as $c) {
            if ($c['id'] === $id) {
                $name = $c['name'];
            }
        }
        $convId = 'AW-123456789';
        $label = 'DemO' . substr(md5($id), 0, 8);
        $global = "<!-- Google tag (gtag.js) -->\n"
            . "<script async src=\"https://www.googletagmanager.com/gtag/js?id=$convId\"></script>\n"
            . "<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n"
            . "  gtag('js', new Date());\n  gtag('config', '$convId');\n</script>";
        $event = "<!-- Event snippet for $name conversion page -->\n"
            . "<script>\n  gtag('event', 'conversion', {\n      'send_to': '$convId/$label',\n"
            . "      'value': 1.0,\n      'currency': 'INR',\n      'transaction_id': ''\n  });\n</script>";
        return ['conversion_id' => $convId, 'label' => $label, 'global_tag' => $global, 'event_snippet' => $event, 'name' => $name];
    }
}
