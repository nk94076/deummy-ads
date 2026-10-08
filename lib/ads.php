<?php
/**
 * Google Ads service layer (REAL) - all reads and edits.
 * AdsDemo (lib/demo.php) has the same methods with sample data.
 *
 * Money: the API uses "micros" (1 unit of currency = 1,000,000 micros).
 */

function micros_to($v): float
{
    return round(((float)($v ?? 0)) / 1e6, 2);
}

/** Currency -> micros (rounded to Google's billable unit) */
function to_micros($v): string
{
    $m = (int)round(((float)$v) * 1e6 / 10000) * 10000;
    return (string)max(0, $m);
}

function metrics_row(array $m): array
{
    return [
        'impr'   => (int)($m['impressions'] ?? 0),
        'clicks' => (int)($m['clicks'] ?? 0),
        'cost'   => micros_to($m['costMicros'] ?? 0),
        'conv'   => round((float)($m['conversions'] ?? 0), 2),
        'value'  => round((float)($m['conversionsValue'] ?? 0), 2),
    ];
}

const ACCESS_ROLES = ['ADMIN', 'STANDARD', 'READ_ONLY', 'EMAIL_ONLY'];

// Conversion action types the tool can create (webpage tag based)
const CONV_TYPES = [
    ['id' => 'WEBPAGE',    'label' => 'Website'],
    ['id' => 'GOOGLE_ANALYTICS_4_CUSTOM', 'label' => 'Google Analytics 4 (import)'],
];
// Google Ads conversion categories
const CONV_CATEGORIES = ['PURCHASE', 'ADD_TO_CART', 'BEGIN_CHECKOUT', 'SUBSCRIBE_PAID', 'PHONE_CALL_LEAD',
    'IMPORTED_LEAD', 'SUBMIT_LEAD_FORM', 'BOOK_APPOINTMENT', 'REQUEST_QUOTE', 'GET_DIRECTIONS',
    'OUTBOUND_CLICK', 'CONTACT', 'ENGAGEMENT', 'PAGE_VIEW', 'SIGN_UP', 'DOWNLOAD', 'DEFAULT'];

// UI attribution token -> real Google Ads AttributionModel enum value
const CONV_ATTR_ENUM = [
    'DATA_DRIVEN' => 'GOOGLE_SEARCH_ATTRIBUTION_DATA_DRIVEN',
    'LAST_CLICK'  => 'GOOGLE_ADS_LAST_CLICK',
];

/** Normalize a Google AttributionModel enum back to the short UI token */
function conv_attr_token(string $enum): string
{
    if (str_contains($enum, 'DATA_DRIVEN')) {
        return 'DATA_DRIVEN';
    }
    if (str_contains($enum, 'LAST_CLICK')) {
        return 'LAST_CLICK';
    }
    return $enum;
}

const ZERO_METRICS = ['impr' => 0, 'clicks' => 0, 'cost' => 0, 'conv' => 0, 'value' => 0];

const METRICS_GAQL = 'metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value';

function sum_metrics(array $rows): array
{
    $t = ZERO_METRICS;
    foreach ($rows as $r) {
        foreach ($t as $k => $_) {
            $t[$k] += $r[$k] ?? 0;
        }
    }
    $t['cost'] = round($t['cost'], 2);
    $t['conv'] = round($t['conv'], 2);
    $t['value'] = round($t['value'], 2);
    return $t;
}

/** Validate a URL list: each URL must start with http(s) */
function clean_urls($urls): array
{
    $out = [];
    foreach ((array)$urls as $u) {
        $u = trim((string)$u);
        if ($u === '') {
            continue;
        }
        if (!preg_match('#^https?://#i', $u)) {
            throw new RuntimeException("URL must start with http:// or https://: $u");
        }
        $out[] = $u;
    }
    return $out;
}

/** Shared update part for tracking template / suffix / custom parameters */
function url_fields(array $in, array &$update, array &$mask): void
{
    if (array_key_exists('tracking_url_template', $in)) {
        $t = trim((string)$in['tracking_url_template']);
        if ($t !== '' && !preg_match('#^(https?://|\{lpurl)#i', $t)) {
            throw new RuntimeException('Tracking template must start with {lpurl} or http(s)://');
        }
        $update['trackingUrlTemplate'] = $t;
        $mask[] = 'trackingUrlTemplate';
    }
    if (array_key_exists('final_url_suffix', $in)) {
        $update['finalUrlSuffix'] = ltrim(trim((string)$in['final_url_suffix']), '?&');
        $mask[] = 'finalUrlSuffix';
    }
    if (array_key_exists('custom_params', $in) && is_array($in['custom_params'])) {
        $params = [];
        foreach ($in['custom_params'] as $p) {
            $k = trim((string)($p['key'] ?? ''));
            if ($k === '') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9]{1,16}$/', $k)) {
                throw new RuntimeException("Custom parameter keys must be letters/numbers only (max 16): $k");
            }
            $params[] = ['key' => $k, 'value' => (string)($p['value'] ?? '')];
        }
        $update['urlCustomParameters'] = $params;
        $mask[] = 'urlCustomParameters';
    }
}

class AdsReal
{
    public function __construct(private GoogleAdsClient $ads, private string $cid)
    {
    }

    private function q(string $gaql): array
    {
        return $this->ads->query($this->cid, $gaql);
    }

    private function rn(string $type, string $id): string
    {
        return "customers/{$this->cid}/$type/$id";
    }

    // ================= Dashboard =================
    public function report(string $from, string $to, string $prevFrom, string $prevTo): array
    {
        $where = "segments.date BETWEEN '$from' AND '$to'";
        $currency = '';
        $daily = [];
        foreach ($this->q("SELECT customer.currency_code, segments.date, " . METRICS_GAQL . "
                            FROM customer WHERE $where ORDER BY segments.date") as $r) {
            $currency = $r['customer']['currencyCode'] ?? $currency;
            $daily[] = ['date' => $r['segments']['date']] + metrics_row($r['metrics'] ?? []);
        }
        if (!$currency) {
            $currency = $this->q("SELECT customer.currency_code FROM customer LIMIT 1")[0]['customer']['currencyCode'] ?? 'INR';
        }
        $prev = [];
        foreach ($this->q("SELECT " . METRICS_GAQL . " FROM customer
                            WHERE segments.date BETWEEN '$prevFrom' AND '$prevTo'") as $r) {
            $prev[] = metrics_row($r['metrics'] ?? []);
        }
        return [
            'currency'  => $currency,
            'daily'     => $daily,
            'previous'  => sum_metrics($prev),
            'campaigns' => $this->campaigns($from, $to),
        ];
    }

    /** For DB sync: daily data per campaign */
    public function campaignDaily(string $from, string $to): array
    {
        $out = [];
        foreach ($this->q("SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                                  segments.date, " . METRICS_GAQL . "
                           FROM campaign
                           WHERE segments.date BETWEEN '$from' AND '$to' AND campaign.status != 'REMOVED'") as $r) {
            $c = $r['campaign'] ?? null;
            if (!$c || empty($c['id']) || empty($r['segments']['date'])) {
                continue;
            }
            $out[] = ['campaign_id' => (string)$c['id'], 'name' => $c['name'] ?? '', 'status' => $c['status'] ?? '',
                      'channel' => $c['advertisingChannelType'] ?? '', 'date' => $r['segments']['date']]
                   + metrics_row($r['metrics'] ?? []);
        }
        return $out;
    }

    /** All campaigns (including ones without data) + date range metrics */
    public function campaigns(string $from, string $to): array
    {
        $list = [];
        foreach ($this->q("SELECT campaign.id, campaign.name, campaign.status,
                                  campaign.advertising_channel_type, campaign.bidding_strategy_type,
                                  campaign_budget.amount_micros
                           FROM campaign WHERE campaign.status != 'REMOVED'") as $r) {
            $c = $r['campaign'] ?? null;
            if (!$c || empty($c['id'])) {
                continue;
            }
            $list[(string)$c['id']] = [
                'id' => (string)$c['id'], 'name' => $c['name'] ?? ('Campaign ' . $c['id']),
                'status' => $c['status'] ?? '', 'type' => $c['advertisingChannelType'] ?? '',
                'bidding' => $c['biddingStrategyType'] ?? '',
                'budget' => micros_to($r['campaignBudget']['amountMicros'] ?? 0),
            ] + ZERO_METRICS;
        }
        foreach ($this->q("SELECT campaign.id, " . METRICS_GAQL . " FROM campaign
                           WHERE segments.date BETWEEN '$from' AND '$to' AND campaign.status != 'REMOVED'") as $r) {
            $id = (string)($r['campaign']['id'] ?? '');
            if (isset($list[$id])) {
                $list[$id] = array_merge($list[$id], metrics_row($r['metrics'] ?? []));
            }
        }
        return array_values($list);
    }

    // ================= Campaign settings =================
    public function campaign(string $id): array
    {
        $r = $this->q("SELECT campaign.id, campaign.name, campaign.status, campaign.serving_status,
                              campaign.advertising_channel_type, campaign.bidding_strategy_type,
                              campaign.tracking_url_template, campaign.final_url_suffix,
                              campaign.url_custom_parameters,
                              campaign.target_cpa.target_cpa_micros, campaign.target_roas.target_roas,
                              campaign.maximize_conversions.target_cpa_micros,
                              campaign.maximize_conversion_value.target_roas,
                              campaign_budget.resource_name, campaign_budget.amount_micros,
                              campaign_budget.explicitly_shared
                       FROM campaign WHERE campaign.id = $id")[0] ?? null;
        if (!$r) {
            throw new RuntimeException('Campaign not found.');
        }
        $c = $r['campaign'];
        $bid = $c['biddingStrategyType'] ?? '';
        $tcpa = $c['targetCpa']['targetCpaMicros'] ?? $c['maximizeConversions']['targetCpaMicros'] ?? null;
        $troas = $c['targetRoas']['targetRoas'] ?? $c['maximizeConversionValue']['targetRoas'] ?? null;
        return [
            'id' => (string)$c['id'], 'name' => $c['name'] ?? '', 'status' => $c['status'] ?? '',
            'serving_status' => $c['servingStatus'] ?? '', 'type' => $c['advertisingChannelType'] ?? '',
            'bidding' => $bid,
            'target_cpa' => $tcpa !== null ? micros_to($tcpa) : null,
            'target_roas' => $troas !== null ? round((float)$troas, 2) : null,
            'can_target_cpa' => in_array($bid, ['TARGET_CPA', 'MAXIMIZE_CONVERSIONS'], true),
            'can_target_roas' => in_array($bid, ['TARGET_ROAS', 'MAXIMIZE_CONVERSION_VALUE'], true),
            'tracking_url_template' => $c['trackingUrlTemplate'] ?? '',
            'final_url_suffix' => $c['finalUrlSuffix'] ?? '',
            'custom_params' => array_map(fn($p) => ['key' => $p['key'] ?? '', 'value' => $p['value'] ?? ''], $c['urlCustomParameters'] ?? []),
            'budget' => micros_to($r['campaignBudget']['amountMicros'] ?? 0),
            'budget_shared' => !empty($r['campaignBudget']['explicitlyShared']),
            'budget_rn' => $r['campaignBudget']['resourceName'] ?? '',
        ];
    }

    public function updateCampaign(string $id, array $in): array
    {
        $cur = $this->campaign($id);
        $update = ['resourceName' => $this->rn('campaigns', $id)];
        $mask = [];
        $done = [];

        if (isset($in['name']) && trim($in['name']) !== '' && $in['name'] !== $cur['name']) {
            $update['name'] = trim($in['name']);
            $mask[] = 'name';
        }
        if (isset($in['status']) && in_array($in['status'], ['ENABLED', 'PAUSED'], true) && $in['status'] !== $cur['status']) {
            $update['status'] = $in['status'];
            $mask[] = 'status';
        }
        url_fields($in, $update, $mask);

        // Target CPA / ROAS (field depends on the bid strategy)
        if (isset($in['target_cpa']) && $in['target_cpa'] !== '' && $cur['can_target_cpa']) {
            $f = $cur['bidding'] === 'TARGET_CPA' ? 'targetCpa' : 'maximizeConversions';
            $update[$f] = ['targetCpaMicros' => to_micros($in['target_cpa'])];
            $mask[] = "$f.targetCpaMicros";
        }
        if (isset($in['target_roas']) && $in['target_roas'] !== '' && $cur['can_target_roas']) {
            $f = $cur['bidding'] === 'TARGET_ROAS' ? 'targetRoas' : 'maximizeConversionValue';
            $update[$f] = ['targetRoas' => (float)$in['target_roas']];
            $mask[] = "$f.targetRoas";
        }

        if ($mask) {
            $this->ads->mutate($this->cid, 'campaigns', [['update' => $update, 'updateMask' => implode(',', $mask)]]);
            $done = array_merge($done, $mask);
        }

        // Budget is a separate resource
        if (isset($in['budget']) && $in['budget'] !== '' && (float)$in['budget'] != $cur['budget']) {
            if ((float)$in['budget'] <= 0) {
                throw new RuntimeException('Budget must be greater than 0.');
            }
            if ($cur['budget_shared']) {
                throw new RuntimeException('This is a shared budget (used by several campaigns). Change it in Google Ads > Shared library.');
            }
            $this->ads->mutate($this->cid, 'campaignBudgets', [[
                'update' => ['resourceName' => $cur['budget_rn'], 'amountMicros' => to_micros($in['budget'])],
                'updateMask' => 'amountMicros',
            ]]);
            $done[] = 'budget';
        }
        return $done;
    }

    public function setCampaignStatus(string $id, string $status): void
    {
        $this->ads->mutate($this->cid, 'campaigns', [[
            'update' => ['resourceName' => $this->rn('campaigns', $id), 'status' => $status],
            'updateMask' => 'status',
        ]]);
    }

    // ================= Ad groups =================
    public function adGroups(string $campId, string $from, string $to): array
    {
        $list = [];
        foreach ($this->q("SELECT ad_group.id, ad_group.name, ad_group.status, ad_group.type,
                                  ad_group.cpc_bid_micros, ad_group.tracking_url_template,
                                  ad_group.final_url_suffix
                           FROM ad_group
                           WHERE campaign.id = $campId AND ad_group.status != 'REMOVED'") as $r) {
            $g = $r['adGroup'] ?? null;
            if (!$g || empty($g['id'])) {
                continue;
            }
            $list[(string)$g['id']] = [
                'id' => (string)$g['id'], 'name' => $g['name'] ?? '', 'status' => $g['status'] ?? '',
                'type' => $g['type'] ?? '', 'cpc_bid' => micros_to($g['cpcBidMicros'] ?? 0),
                'tracking_url_template' => $g['trackingUrlTemplate'] ?? '',
                'final_url_suffix' => $g['finalUrlSuffix'] ?? '',
            ] + ZERO_METRICS;
        }
        foreach ($this->q("SELECT ad_group.id, " . METRICS_GAQL . " FROM ad_group
                           WHERE campaign.id = $campId AND ad_group.status != 'REMOVED'
                             AND segments.date BETWEEN '$from' AND '$to'") as $r) {
            $id = (string)($r['adGroup']['id'] ?? '');
            if (isset($list[$id])) {
                $list[$id] = array_merge($list[$id], metrics_row($r['metrics'] ?? []));
            }
        }
        return array_values($list);
    }

    public function createAdGroup(string $campId, array $in): string
    {
        $camp = $this->campaign($campId);
        $typeMap = ['SEARCH' => 'SEARCH_STANDARD', 'DISPLAY' => 'DISPLAY_STANDARD', 'SHOPPING' => 'SHOPPING_PRODUCT_ADS'];
        if (!isset($typeMap[$camp['type']])) {
            throw new RuntimeException('Ad groups cannot be created here for this campaign type (' . $camp['type'] . '). Use a Search, Display or Shopping campaign.');
        }
        $name = trim($in['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Ad group name is required.');
        }
        $create = [
            'campaign' => $this->rn('campaigns', $campId),
            'name' => $name,
            'status' => ($in['status'] ?? 'ENABLED') === 'PAUSED' ? 'PAUSED' : 'ENABLED',
            'type' => $typeMap[$camp['type']],
        ];
        if (!empty($in['cpc_bid'])) {
            $create['cpcBidMicros'] = to_micros($in['cpc_bid']);
        }
        $u = [];
        $m = [];
        url_fields($in, $u, $m);
        $create += array_filter($u, fn($v) => $v !== '' && $v !== []);
        $res = $this->ads->mutate($this->cid, 'adGroups', [['create' => $create]]);
        $rn = $res['results'][0]['resourceName'] ?? '';
        return substr($rn, strrpos($rn, '/') + 1);
    }

    public function updateAdGroup(string $agId, array $in): array
    {
        $update = ['resourceName' => $this->rn('adGroups', $agId)];
        $mask = [];
        if (isset($in['name']) && trim($in['name']) !== '') {
            $update['name'] = trim($in['name']);
            $mask[] = 'name';
        }
        if (isset($in['status']) && in_array($in['status'], ['ENABLED', 'PAUSED'], true)) {
            $update['status'] = $in['status'];
            $mask[] = 'status';
        }
        if (isset($in['cpc_bid']) && $in['cpc_bid'] !== '') {
            $update['cpcBidMicros'] = to_micros($in['cpc_bid']);
            $mask[] = 'cpcBidMicros';
        }
        url_fields($in, $update, $mask);
        if ($mask) {
            $this->ads->mutate($this->cid, 'adGroups', [['update' => $update, 'updateMask' => implode(',', $mask)]]);
        }
        return $mask;
    }

    // ================= Ads (+ PMax asset groups) =================
    public function ads(string $campId, string $from, string $to): array
    {
        $list = [];
        foreach ($this->q("SELECT ad_group_ad.ad.id, ad_group_ad.ad.type, ad_group_ad.ad.name,
                                  ad_group_ad.ad.final_urls, ad_group_ad.ad.tracking_url_template,
                                  ad_group_ad.ad.final_url_suffix, ad_group_ad.status,
                                  ad_group_ad.ad.responsive_search_ad.headlines,
                                  ad_group_ad.ad.responsive_search_ad.descriptions,
                                  ad_group_ad.ad.responsive_search_ad.path1,
                                  ad_group_ad.ad.responsive_search_ad.path2,
                                  ad_group_ad.policy_summary.approval_status,
                                  ad_group.id, ad_group.name
                           FROM ad_group_ad
                           WHERE campaign.id = $campId AND ad_group_ad.status != 'REMOVED'") as $r) {
            $a = $r['adGroupAd']['ad'] ?? null;
            if (!$a || empty($a['id'])) {
                continue;
            }
            $allHeads = array_values(array_filter(array_map(fn($h) => $h['text'] ?? '', $a['responsiveSearchAd']['headlines'] ?? [])));
            $heads = array_slice($allHeads, 0, 3);
            $list[(string)$a['id']] = [
                'id' => (string)$a['id'], 'ag_id' => (string)($r['adGroup']['id'] ?? ''),
                'ag_name' => $r['adGroup']['name'] ?? '', 'type' => $a['type'] ?? '',
                'title' => $heads ? implode(' | ', $heads) : ($a['name'] ?? ('Ad ' . $a['id'])),
                'status' => $r['adGroupAd']['status'] ?? '',
                'approval' => $r['adGroupAd']['policySummary']['approvalStatus'] ?? '',
                'final_urls' => $a['finalUrls'] ?? [],
                'tracking_url_template' => $a['trackingUrlTemplate'] ?? '',
                'final_url_suffix' => $a['finalUrlSuffix'] ?? '',
                'headlines' => $allHeads,
                'descriptions' => array_values(array_filter(array_map(fn($d) => $d['text'] ?? '', $a['responsiveSearchAd']['descriptions'] ?? []))),
                'path1' => $a['responsiveSearchAd']['path1'] ?? '', 'path2' => $a['responsiveSearchAd']['path2'] ?? '',
            ] + ZERO_METRICS;
        }
        if ($list) {
            foreach ($this->q("SELECT ad_group_ad.ad.id, " . METRICS_GAQL . " FROM ad_group_ad
                               WHERE campaign.id = $campId AND ad_group_ad.status != 'REMOVED'
                                 AND segments.date BETWEEN '$from' AND '$to'") as $r) {
                $id = (string)($r['adGroupAd']['ad']['id'] ?? '');
                if (isset($list[$id])) {
                    $list[$id] = array_merge($list[$id], metrics_row($r['metrics'] ?? []));
                }
            }
        }

        // Performance Max: asset groups instead of ads
        $groups = [];
        foreach ($this->q("SELECT asset_group.id, asset_group.name, asset_group.status,
                                  asset_group.final_urls
                           FROM asset_group
                           WHERE campaign.id = $campId AND asset_group.status != 'REMOVED'") as $r) {
            $g = $r['assetGroup'] ?? null;
            if ($g && !empty($g['id'])) {
                $groups[] = [
                    'id' => (string)$g['id'], 'name' => $g['name'] ?? '', 'status' => $g['status'] ?? '',
                    'final_urls' => $g['finalUrls'] ?? [],
                ];
            }
        }
        return ['ads' => array_values($list), 'asset_groups' => $groups];
    }

    public function updateAd(string $agId, string $adId, array $in): array
    {
        $done = [];
        $update = ['resourceName' => $this->rn('ads', $adId)];
        $mask = [];
        if (array_key_exists('final_urls', $in)) {
            $urls = clean_urls($in['final_urls']);
            if (!$urls) {
                throw new RuntimeException('At least one final URL is required.');
            }
            $update['finalUrls'] = $urls;
            $mask[] = 'finalUrls';
        }
        url_fields($in, $update, $mask);
        if ($mask) {
            $this->ads->mutate($this->cid, 'ads', [['update' => $update, 'updateMask' => implode(',', $mask)]]);
            $done = $mask;
        }
        if (isset($in['status']) && in_array($in['status'], ['ENABLED', 'PAUSED'], true)) {
            $this->ads->mutate($this->cid, 'adGroupAds', [[
                'update' => ['resourceName' => $this->rn('adGroupAds', "$agId~$adId"), 'status' => $in['status']],
                'updateMask' => 'status',
            ]]);
            $done[] = 'status';
        }
        return $done;
    }

    public function updateAssetGroup(string $id, array $in): array
    {
        $update = ['resourceName' => $this->rn('assetGroups', $id)];
        $mask = [];
        if (array_key_exists('final_urls', $in)) {
            $urls = clean_urls($in['final_urls']);
            if (!$urls) {
                throw new RuntimeException('At least one final URL is required.');
            }
            $update['finalUrls'] = $urls;
            $mask[] = 'finalUrls';
        }
        if (isset($in['status']) && in_array($in['status'], ['ENABLED', 'PAUSED'], true)) {
            $update['status'] = $in['status'];
            $mask[] = 'status';
        }
        if ($mask) {
            $this->ads->mutate($this->cid, 'assetGroups', [['update' => $update, 'updateMask' => implode(',', $mask)]]);
        }
        return $mask;
    }

    // ================= Keywords =================
    public function keywords(string $campId, string $from, string $to): array
    {
        $list = [];
        foreach ($this->q("SELECT ad_group_criterion.criterion_id, ad_group_criterion.keyword.text,
                                  ad_group_criterion.keyword.match_type, ad_group_criterion.status,
                                  ad_group_criterion.cpc_bid_micros,
                                  ad_group_criterion.quality_info.quality_score,
                                  ad_group_criterion.final_urls, ad_group_criterion.final_mobile_urls,
                                  ad_group_criterion.tracking_url_template, ad_group_criterion.final_url_suffix,
                                  ad_group_criterion.url_custom_parameters,
                                  ad_group.id, ad_group.name
                           FROM ad_group_criterion
                           WHERE campaign.id = $campId AND ad_group_criterion.type = 'KEYWORD'
                             AND ad_group_criterion.negative = FALSE
                             AND ad_group_criterion.status != 'REMOVED'") as $r) {
            $k = $r['adGroupCriterion'] ?? null;
            if (!$k || empty($k['criterionId'])) {
                continue;
            }
            $key = ($r['adGroup']['id'] ?? '') . '~' . $k['criterionId'];
            $list[$key] = [
                'id' => (string)$k['criterionId'], 'ag_id' => (string)($r['adGroup']['id'] ?? ''),
                'ag_name' => $r['adGroup']['name'] ?? '',
                'text' => $k['keyword']['text'] ?? '', 'match' => $k['keyword']['matchType'] ?? '',
                'status' => $k['status'] ?? '', 'cpc_bid' => micros_to($k['cpcBidMicros'] ?? 0),
                'qs' => $k['qualityInfo']['qualityScore'] ?? null,
                'final_url' => $k['finalUrls'][0] ?? '', 'final_mobile_url' => $k['finalMobileUrls'][0] ?? '',
                'tracking_url_template' => $k['trackingUrlTemplate'] ?? '', 'final_url_suffix' => $k['finalUrlSuffix'] ?? '',
                'custom_params' => array_map(fn($p) => ['key' => $p['key'] ?? '', 'value' => $p['value'] ?? ''], $k['urlCustomParameters'] ?? []),
            ] + ZERO_METRICS;
        }
        if ($list) {
            foreach ($this->q("SELECT ad_group_criterion.criterion_id, ad_group.id, " . METRICS_GAQL . "
                               FROM keyword_view
                               WHERE campaign.id = $campId AND segments.date BETWEEN '$from' AND '$to'") as $r) {
                $key = ($r['adGroup']['id'] ?? '') . '~' . ($r['adGroupCriterion']['criterionId'] ?? '');
                if (isset($list[$key])) {
                    $list[$key] = array_merge($list[$key], metrics_row($r['metrics'] ?? []));
                }
            }
        }
        return array_values($list);
    }

    public function addKeywords(string $agId, array $lines, string $match, $cpc = null, string $finalUrl = ''): int
    {
        $match = in_array($match, ['EXACT', 'PHRASE', 'BROAD'], true) ? $match : 'PHRASE';
        $urls = clean_urls([$finalUrl]);
        $ops = [];
        foreach ($lines as $t) {
            $t = trim(preg_replace('/^[\["+]+|[\]"]+$/', '', trim($t)));
            if ($t === '') {
                continue;
            }
            $create = [
                'adGroup' => $this->rn('adGroups', $agId), 'status' => 'ENABLED',
                'keyword' => ['text' => $t, 'matchType' => $match],
            ];
            if ($cpc) {
                $create['cpcBidMicros'] = to_micros($cpc);
            }
            if ($urls) {
                $create['finalUrls'] = $urls;
            }
            $ops[] = ['create' => $create];
        }
        if (!$ops) {
            throw new RuntimeException('No keywords entered.');
        }
        $this->ads->mutate($this->cid, 'adGroupCriteria', $ops);
        return count($ops);
    }

    public function updateKeyword(string $agId, string $critId, array $in): array
    {
        $update = ['resourceName' => $this->rn('adGroupCriteria', "$agId~$critId")];
        $mask = [];
        if (isset($in['status']) && in_array($in['status'], ['ENABLED', 'PAUSED'], true)) {
            $update['status'] = $in['status'];
            $mask[] = 'status';
        }
        if (isset($in['cpc_bid']) && $in['cpc_bid'] !== '') {
            $update['cpcBidMicros'] = to_micros($in['cpc_bid']);
            $mask[] = 'cpcBidMicros';
        }
        // Keyword-level URLs override the ad's URLs for clicks on this keyword. Empty = use the ad's final URL.
        if (array_key_exists('final_url', $in)) {
            $update['finalUrls'] = clean_urls([$in['final_url']]);
            $mask[] = 'finalUrls';
        }
        if (array_key_exists('final_mobile_url', $in)) {
            $update['finalMobileUrls'] = clean_urls([$in['final_mobile_url']]);
            $mask[] = 'finalMobileUrls';
        }
        url_fields($in, $update, $mask);
        if ($mask) {
            $this->ads->mutate($this->cid, 'adGroupCriteria', [['update' => $update, 'updateMask' => implode(',', $mask)]]);
        }
        return $mask;
    }

    // ================= Search terms + negatives =================
    public function searchTerms(string $campId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->q("SELECT search_term_view.search_term, search_term_view.status,
                                  ad_group.name, " . METRICS_GAQL . "
                           FROM search_term_view
                           WHERE campaign.id = $campId AND segments.date BETWEEN '$from' AND '$to'
                           ORDER BY metrics.cost_micros DESC LIMIT 300") as $r) {
            $out[] = [
                'term' => $r['searchTermView']['searchTerm'] ?? '',
                'status' => $r['searchTermView']['status'] ?? '',
                'ag_name' => $r['adGroup']['name'] ?? '',
            ] + metrics_row($r['metrics'] ?? []);
        }
        return $out;
    }

    public function negatives(string $campId): array
    {
        $out = [];
        foreach ($this->q("SELECT campaign_criterion.criterion_id, campaign_criterion.keyword.text,
                                  campaign_criterion.keyword.match_type
                           FROM campaign_criterion
                           WHERE campaign.id = $campId AND campaign_criterion.negative = TRUE
                             AND campaign_criterion.type = 'KEYWORD'") as $r) {
            $c = $r['campaignCriterion'] ?? [];
            $out[] = ['id' => (string)($c['criterionId'] ?? ''), 'text' => $c['keyword']['text'] ?? '',
                      'match' => $c['keyword']['matchType'] ?? ''];
        }
        return $out;
    }

    public function addNegatives(string $campId, array $terms, string $match): int
    {
        $match = in_array($match, ['EXACT', 'PHRASE', 'BROAD'], true) ? $match : 'EXACT';
        $ops = [];
        foreach ($terms as $t) {
            $t = trim($t);
            if ($t !== '') {
                $ops[] = ['create' => [
                    'campaign' => $this->rn('campaigns', $campId), 'negative' => true,
                    'keyword' => ['text' => $t, 'matchType' => $match],
                ]];
            }
        }
        if (!$ops) {
            throw new RuntimeException('No negative keywords entered.');
        }
        $this->ads->mutate($this->cid, 'campaignCriteria', $ops);
        return count($ops);
    }

    public function removeNegative(string $campId, string $critId): void
    {
        $this->ads->mutate($this->cid, 'campaignCriteria', [['remove' => $this->rn('campaignCriteria', "$campId~$critId")]]);
    }

    // ================= Account settings =================
    public function accountSettings(): array
    {
        $c = $this->q("SELECT customer.id, customer.descriptive_name, customer.currency_code,
                              customer.time_zone, customer.tracking_url_template,
                              customer.final_url_suffix, customer.auto_tagging_enabled
                       FROM customer LIMIT 1")[0]['customer'] ?? [];
        return [
            'name' => $c['descriptiveName'] ?? '', 'currency' => $c['currencyCode'] ?? '',
            'time_zone' => $c['timeZone'] ?? '',
            'tracking_url_template' => $c['trackingUrlTemplate'] ?? '',
            'final_url_suffix' => $c['finalUrlSuffix'] ?? '',
            'auto_tagging' => !empty($c['autoTaggingEnabled']),
        ];
    }

    public function updateAccountSettings(array $in): array
    {
        $u = [];
        $m = [];
        url_fields($in, $u, $m);
        unset($u['urlCustomParameters']);
        $m = array_values(array_diff($m, ['urlCustomParameters']));
        if (isset($in['auto_tagging'])) {
            $u['autoTaggingEnabled'] = (bool)$in['auto_tagging'];
        }
        if ($u) {
            $this->ads->mutateCustomer($this->cid, $u);
        }
        return array_keys($u);
    }

    // ================= New campaign / RSA / targeting =================
    /** New Search campaign (budget + campaign + locations + languages + ad group + keywords + RSA) - one atomic request */
    public function createSearchCampaign(array $in, bool $validateOnly = false): array
    {
        $v = validate_new_campaign($in);
        $res = $this->ads->mutateAll($this->cid, new_campaign_operations($this->cid, $v), $validateOnly);
        if ($validateOnly) {
            return ['validated' => true, 'keywords' => count($v['keywords'])];
        }
        $campId = $agId = '';
        foreach ($res['mutateOperationResponses'] ?? [] as $r) {
            if (isset($r['campaignResult']['resourceName'])) {
                $campId = substr(strrchr($r['campaignResult']['resourceName'], '/'), 1);
            }
            if (isset($r['adGroupResult']['resourceName'])) {
                $agId = substr(strrchr($r['adGroupResult']['resourceName'], '/'), 1);
            }
        }
        return ['campaign_id' => $campId, 'ad_group_id' => $agId, 'name' => $v['name'], 'keywords' => count($v['keywords'])];
    }

    /** New responsive search ad in an existing ad group */
    public function createRsa(string $agId, array $in): string
    {
        $v = validate_rsa($in);
        $res = $this->ads->mutate($this->cid, 'adGroupAds', [['create' => [
            'adGroup' => $this->rn('adGroups', $agId),
            'status' => ($in['status'] ?? '') === 'PAUSED' ? 'PAUSED' : 'ENABLED',
            'ad' => rsa_ad_payload($v),
        ]]]);
        $rn = $res['results'][0]['resourceName'] ?? '';
        return (string)preg_replace('#^.*[/~]#', '', $rn);
    }

    /** Location search (Google geo target constants) */
    public function searchGeo(string $q): array
    {
        $q = trim(preg_replace("/['\"\\\\%]/", '', $q));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $q = mb_convert_case($q, MB_CASE_TITLE);
        $out = [];
        foreach ($this->q("SELECT geo_target_constant.id, geo_target_constant.name, geo_target_constant.canonical_name,
                                  geo_target_constant.target_type, geo_target_constant.country_code
                           FROM geo_target_constant
                           WHERE geo_target_constant.name LIKE '%$q%' AND geo_target_constant.status = 'ENABLED'
                           LIMIT 40") as $r) {
            $g = $r['geoTargetConstant'] ?? [];
            if (!empty($g['id'])) {
                $out[] = ['id' => (string)$g['id'], 'name' => $g['canonicalName'] ?? $g['name'] ?? '',
                          'type' => $g['targetType'] ?? '', 'country' => $g['countryCode'] ?? ''];
            }
        }
        // Countries first, then shorter names
        usort($out, fn($a, $b) => [($a['type'] !== 'Country'), strlen($a['name'])] <=> [($b['type'] !== 'Country'), strlen($b['name'])]);
        return array_slice($out, 0, 25);
    }

    public function languages(): array
    {
        $out = [];
        foreach ($this->q("SELECT language_constant.id, language_constant.name, language_constant.code
                           FROM language_constant WHERE language_constant.targetable = TRUE") as $r) {
            $l = $r['languageConstant'] ?? [];
            if (!empty($l['id'])) {
                $out[] = ['id' => (string)$l['id'], 'name' => $l['name'] ?? '', 'code' => $l['code'] ?? ''];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out ?: COMMON_LANGS;
    }

    /** Campaign locations (targeted + excluded) + languages + ad schedule */
    public function targeting(string $campId): array
    {
        $locs = $excl = $langs = $sched = [];
        foreach ($this->q("SELECT campaign_criterion.criterion_id, campaign_criterion.type, campaign_criterion.negative,
                                  campaign_criterion.location.geo_target_constant,
                                  campaign_criterion.language.language_constant,
                                  campaign_criterion.ad_schedule.day_of_week,
                                  campaign_criterion.ad_schedule.start_hour, campaign_criterion.ad_schedule.start_minute,
                                  campaign_criterion.ad_schedule.end_hour, campaign_criterion.ad_schedule.end_minute
                           FROM campaign_criterion
                           WHERE campaign.id = $campId
                             AND campaign_criterion.type IN ('LOCATION', 'LANGUAGE', 'AD_SCHEDULE')") as $r) {
            $c = $r['campaignCriterion'] ?? [];
            $crit = (string)($c['criterionId'] ?? '');
            $type = $c['type'] ?? '';
            if ($type === 'LOCATION' && !empty($c['location']['geoTargetConstant'])) {
                $id = substr(strrchr($c['location']['geoTargetConstant'], '/'), 1);
                $row = ['criterion_id' => $crit, 'id' => $id, 'name' => "Location $id", 'type' => ''];
                if (!empty($c['negative'])) {
                    $excl[$id] = $row;
                } else {
                    $locs[$id] = $row;
                }
            } elseif ($type === 'LANGUAGE' && !empty($c['language']['languageConstant'])) {
                $id = substr(strrchr($c['language']['languageConstant'], '/'), 1);
                $langs[$id] = ['criterion_id' => $crit, 'id' => $id];
            } elseif ($type === 'AD_SCHEDULE' && !empty($c['adSchedule'])) {
                $sched[] = ['criterion_id' => $crit] + sched_from_api($c['adSchedule']);
            }
        }
        $g = $this->q("SELECT campaign.geo_target_type_setting.positive_geo_target_type FROM campaign WHERE campaign.id = $campId")[0] ?? [];
        $geoType = $g['campaign']['geoTargetTypeSetting']['positiveGeoTargetType'] ?? 'PRESENCE_OR_INTEREST';
        $all = array_keys($locs + $excl);
        if ($all) {
            $in = implode(',', array_map(fn($id) => "'geoTargetConstants/$id'", $all));
            foreach ($this->q("SELECT geo_target_constant.id, geo_target_constant.canonical_name, geo_target_constant.target_type
                               FROM geo_target_constant WHERE geo_target_constant.resource_name IN ($in)") as $r) {
                $x = $r['geoTargetConstant'] ?? [];
                $id = (string)($x['id'] ?? '');
                foreach ([&$locs, &$excl] as &$list) {
                    if (isset($list[$id])) {
                        $list[$id]['name'] = $x['canonicalName'] ?? $list[$id]['name'];
                        $list[$id]['type'] = $x['targetType'] ?? '';
                    }
                }
                unset($list);
            }
        }
        $order = array_flip(SCHED_DAYS);
        usort($sched, fn($a, $b) => [$order[$a['day']] ?? 9, $a['start']] <=> [$order[$b['day']] ?? 9, $b['start']]);
        return ['locations' => array_values($locs), 'excluded' => array_values($excl), 'languages' => array_values($langs),
                'schedule' => $sched, 'geo_type' => $geoType];
    }

    /** Make targeting match the given lists (missing = remove, new = create) */
    public function updateTargeting(string $campId, array $in): array
    {
        $cur = $this->targeting($campId);
        $locs = b_ids($in['locations'] ?? []);
        $excl = b_ids($in['excluded'] ?? []);
        if (!$locs) {
            throw new RuntimeException('Keep at least one location.');
        }
        if ($both = array_intersect($locs, $excl)) {
            throw new RuntimeException('A location cannot be both targeted and excluded.');
        }
        $campRn = $this->rn('campaigns', $campId);
        $want = [];
        foreach ($locs as $id) {
            $want["L:$id"] = ['campaign' => $campRn, 'location' => ['geoTargetConstant' => "geoTargetConstants/$id"]];
        }
        foreach ($excl as $id) {
            $want["X:$id"] = ['campaign' => $campRn, 'negative' => true, 'location' => ['geoTargetConstant' => "geoTargetConstants/$id"]];
        }
        foreach (b_ids($in['languages'] ?? []) as $id) {
            $want["G:$id"] = ['campaign' => $campRn, 'language' => ['languageConstant' => "languageConstants/$id"]];
        }
        $schedTouched = array_key_exists('schedule', $in);
        foreach ($schedTouched ? parse_schedule($in['schedule']) : [] as $k => $r) {
            $want["S:$k"] = ['campaign' => $campRn, 'adSchedule' => sched_payload($r)];
        }
        $have = [];
        foreach ($cur['locations'] as $x) $have['L:' . $x['id']] = $x['criterion_id'];
        foreach ($cur['excluded'] as $x) $have['X:' . $x['id']] = $x['criterion_id'];
        foreach ($cur['languages'] as $x) $have['G:' . $x['id']] = $x['criterion_id'];
        if ($schedTouched) {
            foreach ($cur['schedule'] as $x) $have['S:' . sched_key($x)] = $x['criterion_id'];
        }
        $removes = $creates = [];
        foreach ($have as $k => $crit) {
            if (!isset($want[$k])) {
                $removes[] = ['remove' => $this->rn('campaignCriteria', "$campId~$crit")];
            }
        }
        foreach ($want as $k => $create) {
            if (!isset($have[$k])) {
                $creates[] = ['create' => $create];
            }
        }
        // One atomic request: removes first, then creates - any failure changes nothing
        if ($removes || $creates) {
            $this->ads->mutate($this->cid, 'campaignCriteria', array_merge($removes, $creates));
        }
        $gt = in_array($in['geo_type'] ?? '', ['PRESENCE', 'PRESENCE_OR_INTEREST'], true) ? $in['geo_type'] : null;
        $gtChanged = $gt && $gt !== $cur['geo_type'];
        if ($gtChanged) {
            $this->ads->mutate($this->cid, 'campaigns', [[
                'update' => ['resourceName' => $campRn, 'geoTargetTypeSetting' => ['positiveGeoTargetType' => $gt]],
                'updateMask' => 'geoTargetTypeSetting.positiveGeoTargetType',
            ]]);
        }
        return ['added' => count($creates), 'removed' => count($removes), 'geo_type_changed' => $gtChanged];
    }

    // ================= Google Ads account access (users + invites) =================
    public function userAccess(): array
    {
        $users = [];
        foreach ($this->q("SELECT customer_user_access.user_id, customer_user_access.email_address,
                                  customer_user_access.access_role, customer_user_access.access_creation_date_time,
                                  customer_user_access.inviter_user_email_address
                           FROM customer_user_access") as $r) {
            $u = $r['customerUserAccess'] ?? [];
            $users[] = ['id' => (string)($u['userId'] ?? ''), 'email' => $u['emailAddress'] ?? '', 'role' => $u['accessRole'] ?? '',
                        'since' => substr((string)($u['accessCreationDateTime'] ?? ''), 0, 10), 'by' => $u['inviterUserEmailAddress'] ?? ''];
        }
        $inv = [];
        foreach ($this->q("SELECT customer_user_access_invitation.invitation_id, customer_user_access_invitation.email_address,
                                  customer_user_access_invitation.access_role, customer_user_access_invitation.invitation_status,
                                  customer_user_access_invitation.creation_date_time
                           FROM customer_user_access_invitation") as $r) {
            $i = $r['customerUserAccessInvitation'] ?? [];
            if (($i['invitationStatus'] ?? '') === 'PENDING') {
                $inv[] = ['id' => (string)($i['invitationId'] ?? ''), 'email' => $i['emailAddress'] ?? '', 'role' => $i['accessRole'] ?? '',
                          'status' => $i['invitationStatus'] ?? '', 'sent' => substr((string)($i['creationDateTime'] ?? ''), 0, 10)];
            }
        }
        return ['users' => $users, 'invites' => $inv];
    }

    public function inviteUser(string $email, string $role): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email.');
        }
        if (!in_array($role, ACCESS_ROLES, true)) {
            throw new RuntimeException('Invalid access level.');
        }
        $this->ads->api('POST', "/customers/{$this->cid}/customerUserAccessInvitations:mutate", [
            'operation' => ['create' => ['emailAddress' => $email, 'accessRole' => $role]],
        ]);
    }

    public function revokeInvite(string $invId): void
    {
        $this->ads->api('POST', "/customers/{$this->cid}/customerUserAccessInvitations:mutate", [
            'operation' => ['remove' => $this->rn('customerUserAccessInvitations', $invId)],
        ]);
    }

    public function setUserRole(string $userId, string $role): void
    {
        if (!in_array($role, ACCESS_ROLES, true)) {
            throw new RuntimeException('Invalid access level.');
        }
        $this->ads->api('POST', "/customers/{$this->cid}/customerUserAccesses:mutate", [
            'operation' => ['update' => ['resourceName' => $this->rn('customerUserAccesses', $userId), 'accessRole' => $role],
                            'updateMask' => 'accessRole'],
        ]);
    }

    public function removeUser(string $userId): void
    {
        $this->ads->api('POST', "/customers/{$this->cid}/customerUserAccesses:mutate", [
            'operation' => ['remove' => $this->rn('customerUserAccesses', $userId)],
        ]);
    }

    // ================= Smart builder helpers =================
    /** Keyword Planner: ideas from URL + seed keywords (volume, CPC) */
    public function keywordIdeas(string $url, array $geoIds, string $langId, array $seeds = []): array
    {
        $body = [
            'language' => "languageConstants/$langId",
            'geoTargetConstants' => array_map(fn($g) => "geoTargetConstants/$g", $geoIds ?: ['2840']),
            'keywordPlanNetwork' => 'GOOGLE_SEARCH',
            'includeAdultKeywords' => false,
            'pageSize' => 60,
        ];
        $seeds = array_slice(array_values(array_filter($seeds)), 0, 10);
        if ($seeds) {
            $body['keywordAndUrlSeed'] = ['url' => $url, 'keywords' => $seeds];
        } else {
            $body['urlSeed'] = ['url' => $url];
        }
        $res = $this->ads->api('POST', "/customers/{$this->cid}:generateKeywordIdeas", $body);
        $out = [];
        foreach ($res['results'] ?? [] as $r) {
            $m = $r['keywordIdeaMetrics'] ?? [];
            $out[] = ['text' => mb_strtolower($r['text'] ?? ''), 'volume' => (int)($m['avgMonthlySearches'] ?? 0),
                      'competition' => $m['competition'] ?? '', 'cpc_low' => micros_to($m['lowTopOfPageBidMicros'] ?? 0),
                      'cpc_high' => micros_to($m['highTopOfPageBidMicros'] ?? 0)];
        }
        return array_values(array_filter($out, fn($x) => $x['text'] !== ''));
    }

    /** Is conversion tracking set up? (enabled conversion actions) */
    public function conversionStatus(): array
    {
        $names = [];
        foreach ($this->q("SELECT conversion_action.name, conversion_action.status, conversion_action.category
                           FROM conversion_action WHERE conversion_action.status = 'ENABLED' LIMIT 50") as $r) {
            $names[] = $r['conversionAction']['name'] ?? '';
        }
        return ['count' => count($names), 'names' => array_slice($names, 0, 10)];
    }

    // ================= Conversion tracking =================
    /** All conversion actions with settings, tag ids and recent conversions */
    public function conversions(string $from, string $to): array
    {
        $out = [];
        foreach ($this->q("SELECT conversion_action.id, conversion_action.name, conversion_action.status,
                                  conversion_action.type, conversion_action.category,
                                  conversion_action.primary_for_goal,
                                  conversion_action.include_in_conversions_metric,
                                  conversion_action.counting_type, conversion_action.click_through_lookback_window_days,
                                  conversion_action.value_settings.default_value,
                                  conversion_action.value_settings.default_currency_code,
                                  conversion_action.value_settings.always_use_default_value,
                                  conversion_action.attribution_model_settings.attribution_model,
                                  conversion_action.tag_snippets
                           FROM conversion_action
                           WHERE conversion_action.status != 'REMOVED'
                           ORDER BY conversion_action.id DESC LIMIT 200") as $r) {
            $c = $r['conversionAction'] ?? null;
            if (!$c || empty($c['id'])) {
                continue;
            }
            $v = $c['valueSettings'] ?? [];
            $out[] = [
                'id' => (string)$c['id'], 'name' => $c['name'] ?? '', 'status' => $c['status'] ?? '',
                'type' => $c['type'] ?? '', 'category' => $c['category'] ?? '',
                'primary' => !empty($c['primaryForGoal']),
                'in_conversions' => !empty($c['includeInConversionsMetric']),
                'counting' => $c['countingType'] ?? '',
                'window_days' => (int)($c['clickThroughLookbackWindowDays'] ?? 0),
                'default_value' => (float)($v['defaultValue'] ?? 0),
                'currency' => $v['defaultCurrencyCode'] ?? '',
                'always_default' => !empty($v['alwaysUseDefaultValue']),
                'attribution' => conv_attr_token($c['attributionModelSettings']['attributionModel'] ?? ''),
                'has_tag' => !empty($c['tagSnippets']),
            ];
        }
        // conversions recorded in the date range, per action
        $stats = [];
        foreach ($this->q("SELECT conversion_action.id, metrics.all_conversions, metrics.all_conversions_value
                           FROM conversion_action WHERE segments.date BETWEEN '$from' AND '$to'") as $r) {
            $id = (string)($r['conversionAction']['id'] ?? '');
            $stats[$id] = ['conv' => round((float)($r['metrics']['allConversions'] ?? 0), 2),
                           'value' => round((float)($r['metrics']['allConversionsValue'] ?? 0), 2)];
        }
        foreach ($out as &$c) {
            $c += $stats[$c['id']] ?? ['conv' => 0, 'value' => 0];
        }
        unset($c);
        return ['conversions' => $out, 'customer_id' => $this->cid, 'from' => $from, 'to' => $to];
    }

    /** Create a website conversion action. Returns id, name and the tag snippet. */
    public function createConversion(array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Conversion name is required.');
        }
        $category = in_array($in['category'] ?? '', CONV_CATEGORIES, true) ? $in['category'] : 'DEFAULT';
        $counting = ($in['counting'] ?? 'ONE_PER_CLICK') === 'MANY_PER_CLICK' ? 'MANY_PER_CLICK' : 'ONE_PER_CLICK';
        $window = (int)($in['window_days'] ?? 30);
        $window = max(1, min(90, $window));
        $create = [
            'name' => $name,
            'type' => 'WEBPAGE',
            'category' => $category,
            'status' => 'ENABLED',
            'primaryForGoal' => !empty($in['primary']),
            'includeInConversionsMetric' => !isset($in['in_conversions']) || !empty($in['in_conversions']),
            'countingType' => $counting,
            'clickThroughLookbackWindowDays' => $window,
            'valueSettings' => [
                'defaultValue' => (float)($in['default_value'] ?? 0),
                'defaultCurrencyCode' => strtoupper(trim((string)($in['currency'] ?? 'INR'))) ?: 'INR',
                'alwaysUseDefaultValue' => !empty($in['always_default']),
            ],
        ];
        // Only send an attribution model when the user picked Last click. Data-driven can't be set on a
        // brand-new action (no history yet) - Google rejects it - so we let Google default it, and it
        // switches to data-driven automatically once there is enough data.
        if (($in['attribution'] ?? '') === 'LAST_CLICK') {
            $create['attributionModelSettings'] = ['attributionModel' => CONV_ATTR_ENUM['LAST_CLICK']];
        }
        $res = $this->ads->mutate($this->cid, 'conversionActions', [['create' => $create]]);
        $rn = $res['results'][0]['resourceName'] ?? '';
        $id = (string)substr(strrchr($rn, '/') ?: '', 1);
        $tag = $id ? $this->conversionTag($id) : ['conversion_id' => '', 'label' => '', 'global_tag' => '', 'event_snippet' => ''];
        return ['id' => $id, 'name' => $name] + $tag;
    }

    /** Update an existing conversion action (name, status, category, counting, window, value, attribution). */
    public function updateConversion(string $id, array $in): array
    {
        $update = ['resourceName' => $this->rn('conversionActions', $id)];
        $mask = [];
        if (isset($in['name']) && trim((string)$in['name']) !== '') {
            $update['name'] = trim((string)$in['name']);
            $mask[] = 'name';
        }
        if (isset($in['status']) && in_array($in['status'], ['ENABLED', 'REMOVED', 'HIDDEN'], true)) {
            $update['status'] = $in['status'];
            $mask[] = 'status';
        }
        if (isset($in['category']) && in_array($in['category'], CONV_CATEGORIES, true)) {
            $update['category'] = $in['category'];
            $mask[] = 'category';
        }
        if (array_key_exists('primary', $in)) {
            $update['primaryForGoal'] = !empty($in['primary']);
            $mask[] = 'primaryForGoal';
        }
        if (array_key_exists('in_conversions', $in)) {
            $update['includeInConversionsMetric'] = !empty($in['in_conversions']);
            $mask[] = 'includeInConversionsMetric';
        }
        if (isset($in['counting']) && in_array($in['counting'], ['ONE_PER_CLICK', 'MANY_PER_CLICK'], true)) {
            $update['countingType'] = $in['counting'];
            $mask[] = 'countingType';
        }
        if (isset($in['window_days']) && $in['window_days'] !== '') {
            $update['clickThroughLookbackWindowDays'] = max(1, min(90, (int)$in['window_days']));
            $mask[] = 'clickThroughLookbackWindowDays';
        }
        $vs = [];
        $vmask = [];
        if (array_key_exists('default_value', $in)) {
            $vs['defaultValue'] = (float)$in['default_value'];
            $vmask[] = 'value_settings.default_value';
        }
        if (isset($in['currency']) && trim((string)$in['currency']) !== '') {
            $vs['defaultCurrencyCode'] = strtoupper(trim((string)$in['currency']));
            $vmask[] = 'value_settings.default_currency_code';
        }
        if (array_key_exists('always_default', $in)) {
            $vs['alwaysUseDefaultValue'] = !empty($in['always_default']);
            $vmask[] = 'value_settings.always_use_default_value';
        }
        if ($vs) {
            $update['valueSettings'] = $vs;
            $mask = array_merge($mask, $vmask);
        }
        if (isset($in['attribution']) && isset(CONV_ATTR_ENUM[$in['attribution']])) {
            $update['attributionModelSettings'] = ['attributionModel' => CONV_ATTR_ENUM[$in['attribution']]];
            $mask[] = 'attribution_model_settings.attribution_model';
        }
        if ($mask) {
            $this->ads->mutate($this->cid, 'conversionActions', [['update' => $update, 'updateMask' => implode(',', $mask)]]);
        }
        return $mask;
    }

    /** Global site tag + event snippet for one conversion action, ready to paste on the website. */
    public function conversionTag(string $id): array
    {
        $row = $this->q("SELECT conversion_action.name, conversion_action.tag_snippets
                         FROM conversion_action WHERE conversion_action.id = $id")[0]['conversionAction'] ?? [];
        $convId = '';
        $label = '';
        $global = $event = '';
        foreach ($row['tagSnippets'] ?? [] as $t) {
            // Use the webpage (gtag.js) snippets
            if (($t['type'] ?? '') !== 'WEBPAGE') {
                continue;
            }
            $page = $t['pageFormat'] ?? '';
            if ($page === 'HTML' && $global === '') {
                $global = $t['globalSiteTag'] ?? '';
                $event = $t['eventSnippet'] ?? '';
            }
        }
        // Pull AW-xxxxx / label out of the event snippet: send_to: 'AW-123/abcDEF'
        if (preg_match("#send_to['\"]?\s*:\s*['\"](AW-[0-9]+)/([A-Za-z0-9_-]+)#", $event, $m)) {
            $convId = $m[1];
            $label = $m[2];
        } elseif (preg_match('#(AW-[0-9]+)#', $global, $m)) {
            $convId = $m[1];
        }
        return [
            'conversion_id' => $convId, 'label' => $label,
            'global_tag' => $global, 'event_snippet' => $event,
            'name' => $row['name'] ?? '',
        ];
    }

    /** Hourly clicks map "Y-m-d H" => clicks, for the account or a set of campaigns (Suffix Rotator report). */
    public function hourlyClicks(string $from, string $to, ?array $campaignIds = null): array
    {
        $where = "segments.date BETWEEN '$from' AND '$to'";
        if ($campaignIds) {
            $ids = implode(',', array_map(fn($c) => (int)$c, $campaignIds));
            if ($ids === '') {
                return [];
            }
            $gaql = "SELECT segments.date, segments.hour, metrics.clicks FROM campaign
                     WHERE $where AND campaign.id IN ($ids)";
        } else {
            $gaql = "SELECT segments.date, segments.hour, metrics.clicks FROM customer WHERE $where";
        }
        $out = [];
        foreach ($this->q($gaql) as $r) {
            $d = $r['segments']['date'] ?? '';
            $h = (int)($r['segments']['hour'] ?? 0);
            if ($d === '') {
                continue;
            }
            $key = $d . ' ' . str_pad((string)$h, 2, '0', STR_PAD_LEFT);
            $out[$key] = ($out[$key] ?? 0) + (int)($r['metrics']['clicks'] ?? 0);
        }
        return $out;
    }

    // ================= Reports =================
    public function breakdown(string $type, string $from, string $to): array
    {
        $seg = ['device' => 'segments.device', 'dow' => 'segments.day_of_week',
                'hour' => 'segments.hour', 'network' => 'segments.ad_network_type'][$type] ?? null;
        if (!$seg) {
            throw new RuntimeException('Unknown report');
        }
        $key = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', substr($seg, 9)))));
        $agg = [];
        foreach ($this->q("SELECT $seg, " . METRICS_GAQL . " FROM customer
                           WHERE segments.date BETWEEN '$from' AND '$to'") as $r) {
            $k = (string)($r['segments'][$key] ?? 'UNKNOWN');
            $agg[$k] = sum_metrics([$agg[$k] ?? ZERO_METRICS, metrics_row($r['metrics'] ?? [])]);
        }
        $out = [];
        foreach ($agg as $k => $v) {
            $out[] = ['label' => $k] + $v;
        }
        return $out;
    }

    // ================= Bulk tools =================
    /** Final URLs of all ads + asset groups (for bulk replace) */
    private function allFinalUrls(?string $campId): array
    {
        $w = $campId ? "AND campaign.id = $campId" : '';
        $items = [];
        foreach ($this->q("SELECT ad_group_ad.ad.id, ad_group_ad.ad.final_urls, ad_group.id,
                                  campaign.id, campaign.name
                           FROM ad_group_ad
                           WHERE ad_group_ad.status != 'REMOVED' AND campaign.status != 'REMOVED' $w") as $r) {
            $a = $r['adGroupAd']['ad'] ?? [];
            if (!empty($a['finalUrls'])) {
                $items[] = ['kind' => 'ad', 'id' => (string)$a['id'], 'campaign' => $r['campaign']['name'] ?? '',
                            'urls' => $a['finalUrls']];
            }
        }
        foreach ($this->q("SELECT asset_group.id, asset_group.final_urls, campaign.name
                           FROM asset_group
                           WHERE asset_group.status != 'REMOVED' AND campaign.status != 'REMOVED' $w") as $r) {
            $g = $r['assetGroup'] ?? [];
            if (!empty($g['finalUrls'])) {
                $items[] = ['kind' => 'asset_group', 'id' => (string)$g['id'], 'campaign' => $r['campaign']['name'] ?? '',
                            'urls' => $g['finalUrls']];
            }
        }
        return $items;
    }

    public function bulkUrlReplace(string $find, string $replace, ?string $campId, bool $apply): array
    {
        if ($find === '') {
            throw new RuntimeException('"Find" cannot be empty.');
        }
        $changes = [];
        foreach ($this->allFinalUrls($campId) as $it) {
            $new = array_map(fn($u) => str_replace($find, $replace, $u), $it['urls']);
            if ($new !== $it['urls']) {
                clean_urls($new);
                $changes[] = $it + ['new' => $new];
            }
        }
        $result = ['count' => count($changes), 'preview' => array_slice($changes, 0, 200), 'applied' => 0, 'failed' => 0, 'errors' => []];
        if (!$apply || !$changes) {
            return $result;
        }
        foreach (['ad' => 'ads', 'asset_group' => 'assetGroups'] as $kind => $svc) {
            $ops = [];
            foreach ($changes as $c) {
                if ($c['kind'] === $kind) {
                    $ops[] = ['update' => ['resourceName' => $this->rn($svc, $c['id']), 'finalUrls' => $c['new']],
                              'updateMask' => 'finalUrls'];
                }
            }
            foreach (array_chunk($ops, 1000) as $chunk) {
                $res = $this->ads->mutate($this->cid, $svc, $chunk, true);
                $failed = 0;
                if (!empty($res['partialFailureError'])) {
                    foreach ($res['partialFailureError']['details'] ?? [] as $d) {
                        foreach ($d['errors'] ?? [] as $e) {
                            $failed++;
                            if (count($result['errors']) < 10) {
                                $result['errors'][] = $e['message'] ?? 'error';
                            }
                        }
                    }
                }
                $result['failed'] += $failed;
                $result['applied'] += count($chunk) - $failed;
            }
        }
        return $result;
    }

    /** Tracking template / suffix: account level or all campaigns */
    public function bulkTracking(string $scope, array $in): array
    {
        if ($scope === 'account') {
            return ['updated' => count($this->updateAccountSettings($in)) ? 1 : 0, 'scope' => 'account'];
        }
        $u = [];
        $m = [];
        url_fields($in, $u, $m);
        if (!$m) {
            throw new RuntimeException('Nothing to update.');
        }
        $ids = [];
        foreach ($this->q("SELECT campaign.id FROM campaign WHERE campaign.status != 'REMOVED'") as $r) {
            $ids[] = (string)$r['campaign']['id'];
        }
        if (!empty($in['campaign_ids']) && is_array($in['campaign_ids'])) {
            $ids = array_values(array_intersect($ids, array_map('strval', $in['campaign_ids'])));
        }
        $ops = array_map(fn($id) => ['update' => ['resourceName' => $this->rn('campaigns', $id)] + $u,
                                     'updateMask' => implode(',', $m)], $ids);
        foreach (array_chunk($ops, 1000) as $chunk) {
            $this->ads->mutate($this->cid, 'campaigns', $chunk);
        }
        return ['updated' => count($ops), 'scope' => 'campaigns'];
    }

    // ================= Campaign clone =================
    /**
     * Read a portable, self-contained copy of a Search campaign: settings, budget, targeting,
     * negatives, and every ad group with its keywords and full responsive search ads.
     * This is the source side of a clone - the result can be recreated in any account.
     */
    public function campaignBlueprint(string $campId): array
    {
        $c = $this->campaign($campId);
        if (($c['type'] ?? '') !== 'SEARCH') {
            throw new RuntimeException('Only Search campaigns can be cloned right now (this one is ' . ($c['type'] ?: 'unknown') . ').');
        }
        $t = $this->targeting($campId);

        $groups = [];
        foreach ($this->q("SELECT ad_group.id, ad_group.name, ad_group.cpc_bid_micros,
                                  ad_group.tracking_url_template, ad_group.final_url_suffix
                           FROM ad_group WHERE campaign.id = $campId AND ad_group.status != 'REMOVED'") as $r) {
            $g = $r['adGroup'] ?? null;
            if (!$g || empty($g['id'])) {
                continue;
            }
            $groups[(string)$g['id']] = [
                'name' => $g['name'] ?? '', 'cpc_bid' => micros_to($g['cpcBidMicros'] ?? 0),
                'tracking_url_template' => $g['trackingUrlTemplate'] ?? '', 'final_url_suffix' => $g['finalUrlSuffix'] ?? '',
                'keywords' => [], 'ads' => [],
            ];
        }
        foreach ($this->q("SELECT ad_group.id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type,
                                  ad_group_criterion.cpc_bid_micros, ad_group_criterion.final_urls,
                                  ad_group_criterion.final_url_suffix, ad_group_criterion.tracking_url_template
                           FROM ad_group_criterion
                           WHERE campaign.id = $campId AND ad_group_criterion.type = 'KEYWORD'
                             AND ad_group_criterion.negative = FALSE AND ad_group_criterion.status != 'REMOVED'") as $r) {
            $agId = (string)($r['adGroup']['id'] ?? '');
            $k = $r['adGroupCriterion'] ?? [];
            if (!isset($groups[$agId]) || empty($k['keyword']['text'])) {
                continue;
            }
            $groups[$agId]['keywords'][] = [
                'text' => $k['keyword']['text'], 'match' => $k['keyword']['matchType'] ?? 'BROAD',
                'cpc_bid' => micros_to($k['cpcBidMicros'] ?? 0),
                'final_url' => $k['finalUrls'][0] ?? '', 'final_url_suffix' => $k['finalUrlSuffix'] ?? '',
                'tracking_url_template' => $k['trackingUrlTemplate'] ?? '',
            ];
        }
        foreach ($this->q("SELECT ad_group.id, ad_group_ad.ad.final_urls, ad_group_ad.ad.final_url_suffix,
                                  ad_group_ad.ad.tracking_url_template,
                                  ad_group_ad.ad.responsive_search_ad.headlines,
                                  ad_group_ad.ad.responsive_search_ad.descriptions,
                                  ad_group_ad.ad.responsive_search_ad.path1,
                                  ad_group_ad.ad.responsive_search_ad.path2
                           FROM ad_group_ad
                           WHERE campaign.id = $campId AND ad_group_ad.status != 'REMOVED'
                             AND ad_group_ad.ad.type = 'RESPONSIVE_SEARCH_AD'") as $r) {
            $agId = (string)($r['adGroup']['id'] ?? '');
            $a = $r['adGroupAd']['ad'] ?? [];
            $rsa = $a['responsiveSearchAd'] ?? [];
            if (!isset($groups[$agId]) || empty($rsa)) {
                continue;
            }
            $groups[$agId]['ads'][] = [
                'headlines' => array_values(array_filter(array_map(fn($h) => $h['text'] ?? '', $rsa['headlines'] ?? []), fn($x) => $x !== '')),
                'descriptions' => array_values(array_filter(array_map(fn($d) => $d['text'] ?? '', $rsa['descriptions'] ?? []), fn($x) => $x !== '')),
                'path1' => $rsa['path1'] ?? '', 'path2' => $rsa['path2'] ?? '',
                'final_url' => $a['finalUrls'][0] ?? '', 'final_url_suffix' => $a['finalUrlSuffix'] ?? '',
                'tracking_url_template' => $a['trackingUrlTemplate'] ?? '',
            ];
        }

        return [
            'source_id' => $campId, 'name' => $c['name'], 'budget' => $c['budget'], 'bidding' => $c['bidding'],
            'target_cpa' => $c['target_cpa'], 'target_roas' => $c['target_roas'],
            'tracking_url_template' => $c['tracking_url_template'], 'final_url_suffix' => $c['final_url_suffix'],
            'geo_type' => $t['geo_type'] === 'PRESENCE_OR_INTEREST' ? 'PRESENCE_OR_INTEREST' : 'PRESENCE',
            'locations' => array_column($t['locations'], 'id'),
            'excluded' => array_column($t['excluded'], 'id'),
            'languages' => array_column($t['languages'], 'id'),
            'schedule' => array_map(fn($s) => ['day' => $s['day'], 'start' => $s['start'], 'end' => $s['end']], $t['schedule']),
            'negatives' => array_map(fn($n) => ['text' => $n['text'], 'match' => $n['match']], $this->negatives($campId)),
            'ad_groups' => array_values($groups),
        ];
    }

    /** Create a campaign in THIS account from a blueprint (the destination side of a clone). */
    public function createCampaignFromBlueprint(array $bp, array $opt = []): array
    {
        $build = clone_operations($this->cid, $bp, $opt);
        $res = $this->ads->mutateAll($this->cid, $build['ops']);
        $campId = '';
        foreach ($res['mutateOperationResponses'] ?? [] as $r) {
            if (isset($r['campaignResult']['resourceName'])) {
                $campId = substr(strrchr($r['campaignResult']['resourceName'], '/'), 1);
            }
        }
        return ['campaign_id' => $campId] + $build['counts'] + ['warnings' => $build['warnings']];
    }
}
