<?php
/**
 * New Search campaign / RSA / targeting - validation and Google Ads API payloads
 * (shared by AdsReal and AdsDemo so the rules live in one place)
 */

/** Common locations (geo target constant IDs) for quick selection */
const COMMON_GEOS = [
    ['id' => '2840', 'name' => 'United States', 'type' => 'Country', 'country' => 'US'],
    ['id' => '2356', 'name' => 'India', 'type' => 'Country', 'country' => 'IN'],
    ['id' => '2826', 'name' => 'United Kingdom', 'type' => 'Country', 'country' => 'GB'],
    ['id' => '2124', 'name' => 'Canada', 'type' => 'Country', 'country' => 'CA'],
    ['id' => '2036', 'name' => 'Australia', 'type' => 'Country', 'country' => 'AU'],
    ['id' => '2276', 'name' => 'Germany', 'type' => 'Country', 'country' => 'DE'],
    ['id' => '2250', 'name' => 'France', 'type' => 'Country', 'country' => 'FR'],
    ['id' => '2784', 'name' => 'United Arab Emirates', 'type' => 'Country', 'country' => 'AE'],
    ['id' => '2702', 'name' => 'Singapore', 'type' => 'Country', 'country' => 'SG'],
    ['id' => '2554', 'name' => 'New Zealand', 'type' => 'Country', 'country' => 'NZ'],
];

/** Demo / fallback languages (real accounts load the full list from the API) */
const COMMON_LANGS = [
    ['id' => '1000', 'name' => 'English', 'code' => 'en'],
    ['id' => '1023', 'name' => 'Hindi', 'code' => 'hi'],
    ['id' => '1003', 'name' => 'Spanish', 'code' => 'es'],
    ['id' => '1002', 'name' => 'French', 'code' => 'fr'],
    ['id' => '1001', 'name' => 'German', 'code' => 'de'],
    ['id' => '1004', 'name' => 'Italian', 'code' => 'it'],
    ['id' => '1014', 'name' => 'Portuguese', 'code' => 'pt'],
    ['id' => '1010', 'name' => 'Dutch', 'code' => 'nl'],
    ['id' => '1019', 'name' => 'Arabic', 'code' => 'ar'],
    ['id' => '1005', 'name' => 'Japanese', 'code' => 'ja'],
];

const SCHED_DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
const SCHED_MIN = [0 => 'ZERO', 15 => 'FIFTEEN', 30 => 'THIRTY', 45 => 'FORTY_FIVE'];

/**
 * Ad schedule rows [{day, start:"09:00", end:"18:00"}] -> normalized list (key => row)
 * Google rules: minutes 00/15/30/45, end > start, max 6 per day, no overlaps.
 */
function parse_schedule($rows): array
{
    $out = [];
    $perDay = [];
    foreach ((array)$rows as $r) {
        $day = strtoupper((string)($r['day'] ?? ''));
        $days = $day === 'ALL' ? SCHED_DAYS : ($day === 'WEEKDAYS' ? array_slice(SCHED_DAYS, 0, 5) : ($day === 'WEEKEND' ? array_slice(SCHED_DAYS, 5) : [$day]));
        foreach ($days as $d) {
            if (!in_array($d, SCHED_DAYS, true)) {
                throw new RuntimeException("Ad schedule: invalid day \"$d\"");
            }
            $t = [];
            foreach (['start', 'end'] as $k) {
                if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string)($r[$k] ?? '')), $m) || (int)$m[1] > 24 || !isset(SCHED_MIN[(int)$m[2]])
                    || ((int)$m[1] === 24 && (int)$m[2] !== 0)) {
                    throw new RuntimeException('Ad schedule: times must be in 15-minute steps (e.g. 09:00, 18:30). Got: ' . ($r[$k] ?? ''));
                }
                $t[$k] = (int)$m[1] * 60 + (int)$m[2];
            }
            if ($t['end'] <= $t['start']) {
                throw new RuntimeException("Ad schedule ($d): end time must be after start time. For overnight, add two slots (e.g. 22:00-24:00 and 00:00-02:00 next day).");
            }
            foreach ($perDay[$d] ?? [] as [$a, $b]) {
                if ($t['start'] < $b && $a < $t['end']) {
                    throw new RuntimeException("Ad schedule ($d): time slots overlap.");
                }
            }
            $perDay[$d][] = [$t['start'], $t['end']];
            if (count($perDay[$d]) > 6) {
                throw new RuntimeException("Ad schedule ($d): maximum 6 time slots per day.");
            }
            $row = ['day' => $d, 'start' => sprintf('%02d:%02d', intdiv($t['start'], 60), $t['start'] % 60),
                    'end' => sprintf('%02d:%02d', intdiv($t['end'], 60), $t['end'] % 60)];
            $out[sched_key($row)] = $row;
        }
    }
    return $out;
}

function sched_key(array $r): string
{
    return $r['day'] . '|' . $r['start'] . '|' . $r['end'];
}

function sched_payload(array $r): array
{
    [$sh, $sm] = array_map('intval', explode(':', $r['start']));
    [$eh, $em] = array_map('intval', explode(':', $r['end']));
    return ['dayOfWeek' => $r['day'], 'startHour' => $sh, 'startMinute' => SCHED_MIN[$sm], 'endHour' => $eh, 'endMinute' => SCHED_MIN[$em]];
}

/** Google adSchedule -> row */
function sched_from_api(array $a): array
{
    $mins = array_flip(SCHED_MIN);
    return ['day' => $a['dayOfWeek'] ?? '',
            'start' => sprintf('%02d:%02d', (int)($a['startHour'] ?? 0), $mins[$a['startMinute'] ?? 'ZERO'] ?? 0),
            'end' => sprintf('%02d:%02d', (int)($a['endHour'] ?? 0), $mins[$a['endMinute'] ?? 'ZERO'] ?? 0)];
}

const BIDDING_CHOICES = ['MAXIMIZE_CLICKS', 'MAXIMIZE_CONVERSIONS', 'MANUAL_CPC'];

function b_len(string $s): int
{
    return mb_strlen($s, 'UTF-8');
}

function b_ids($v): array
{
    $out = [];
    foreach ((array)$v as $x) {
        $x = preg_replace('/\D/', '', (string)(is_array($x) ? ($x['id'] ?? '') : $x));
        if ($x !== '') {
            $out[$x] = $x;
        }
    }
    return array_values($out);
}

/**
 * Keyword lines -> [['text'=>..,'match'=>..]]
 *   [keyword]  = Exact,  "keyword" = Phrase,  keyword = default match type
 */
function parse_keyword_lines($text, string $default = 'PHRASE'): array
{
    $default = in_array($default, ['EXACT', 'PHRASE', 'BROAD'], true) ? $default : 'PHRASE';
    $lines = is_array($text) ? $text : preg_split('/\r?\n/', (string)$text);
    $out = [];
    $bad = [];
    foreach ($lines as $l) {
        $l = trim((string)$l);
        if ($l === '') {
            continue;
        }
        $m = $default;
        if (preg_match('/^\[(.+)\]$/u', $l, $x)) {
            $m = 'EXACT';
            $l = $x[1];
        } elseif (preg_match('/^"(.+)"$/u', $l, $x)) {
            $m = 'PHRASE';
            $l = $x[1];
        }
        $l = trim(preg_replace('/\s+/u', ' ', str_replace(['+', '[', ']', '"'], '', $l)));
        if ($l === '') {
            continue;
        }
        if (b_len($l) > 80 || count(explode(' ', $l)) > 10) {
            $bad[] = $l;
            continue;
        }
        $out[mb_strtolower($l) . '|' . $m] = ['text' => $l, 'match' => $m];
    }
    if ($bad) {
        throw new RuntimeException('Keyword too long (max 80 characters / 10 words): ' . implode(', ', array_slice($bad, 0, 3)));
    }
    return array_values($out);
}

/** Validate RSA -> normalized data */
function validate_rsa(array $in): array
{
    $clean = function ($list, int $max, string $what) {
        $out = [];
        $seen = [];
        foreach ((array)$list as $t) {
            $t = trim(preg_replace('/\s+/u', ' ', (string)$t));
            if ($t === '') {
                continue;
            }
            if (b_len($t) > $max) {
                throw new RuntimeException("$what can be at most $max characters: \"$t\" (" . b_len($t) . ')');
            }
            $k = mb_strtolower($t);
            if (isset($seen[$k])) {
                throw new RuntimeException("Duplicate $what: \"$t\" - each one must be unique.");
            }
            $seen[$k] = 1;
            $out[] = $t;
        }
        return $out;
    };
    $h = $clean($in['headlines'] ?? [], 30, 'Headline');
    $d = $clean($in['descriptions'] ?? [], 90, 'Description');
    if (count($h) < 3 || count($h) > 15) {
        throw new RuntimeException('Add 3 to 15 headlines (currently ' . count($h) . ').');
    }
    if (count($d) < 2 || count($d) > 4) {
        throw new RuntimeException('Add 2 to 4 descriptions (currently ' . count($d) . ').');
    }
    $url = trim((string)($in['final_url'] ?? ''));
    if (!preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url)) {
        throw new RuntimeException('Invalid final URL (must start with https://).');
    }
    $p1 = trim((string)($in['path1'] ?? ''), " /");
    $p2 = trim((string)($in['path2'] ?? ''), " /");
    foreach ([$p1, $p2] as $p) {
        if (b_len($p) > 15 || str_contains($p, ' ')) {
            throw new RuntimeException("Display path max 15 characters, bina space: \"$p\"");
        }
    }
    if ($p2 !== '' && $p1 === '') {
        [$p1, $p2] = [$p2, ''];
    }
    return ['headlines' => $h, 'descriptions' => $d, 'final_url' => $url, 'path1' => $p1, 'path2' => $p2,
            'final_url_suffix' => ltrim(trim((string)($in['final_url_suffix'] ?? '')), '?&')];
}

/** Google Ads API "ad" object (RSA) */
function rsa_ad_payload(array $v): array
{
    $ad = [
        'finalUrls' => [$v['final_url']],
        'responsiveSearchAd' => [
            'headlines' => array_map(fn($t) => ['text' => $t], $v['headlines']),
            'descriptions' => array_map(fn($t) => ['text' => $t], $v['descriptions']),
        ],
    ];
    if ($v['path1'] !== '') {
        $ad['responsiveSearchAd']['path1'] = $v['path1'];
    }
    if ($v['path2'] !== '') {
        $ad['responsiveSearchAd']['path2'] = $v['path2'];
    }
    if ($v['final_url_suffix'] !== '') {
        $ad['finalUrlSuffix'] = $v['final_url_suffix'];
    }
    return $ad;
}

/** Validate the full wizard form */
function validate_new_campaign(array $in): array
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
    if ($name === '' || b_len($name) > 255) {
        throw new RuntimeException('Campaign name is required (max 255 characters).');
    }
    $budget = (float)($in['budget'] ?? 0);
    if ($budget <= 0) {
        throw new RuntimeException('Daily budget must be greater than 0.');
    }
    $bidding = in_array($in['bidding'] ?? '', BIDDING_CHOICES, true) ? $in['bidding'] : 'MAXIMIZE_CLICKS';
    $cpc = (float)($in['cpc_bid'] ?? 0);
    if ($bidding === 'MANUAL_CPC' && $cpc <= 0) {
        throw new RuntimeException('Manual CPC requires a default max CPC bid.');
    }
    $locs = b_ids($in['locations'] ?? []);
    $langs = b_ids($in['languages'] ?? []);
    $excl = b_ids($in['excluded'] ?? []);
    if ($both = array_intersect($locs, $excl)) {
        throw new RuntimeException('A location cannot be both targeted and excluded (ID ' . implode(', ', $both) . ').');
    }
    $sched = parse_schedule($in['schedule'] ?? []);
    if (!$locs) {
        throw new RuntimeException('Select at least one location (e.g. United States).');
    }
    if (!$langs) {
        throw new RuntimeException('Select at least one language (e.g. English).');
    }
    $agName = trim((string)($in['ad_group'] ?? '')) ?: 'Ad group 1';
    $kws = parse_keyword_lines($in['keywords'] ?? '', (string)($in['match'] ?? 'PHRASE'));
    if (!$kws) {
        throw new RuntimeException('Add at least one keyword.');
    }
    if (count($kws) > 2000) {
        throw new RuntimeException('Maximum 2,000 keywords at a time.');
    }
    $negs = parse_keyword_lines($in['negatives'] ?? '', 'PHRASE');
    $tpl = trim((string)($in['tracking_url_template'] ?? ''));
    if ($tpl !== '' && !preg_match('#^(https?://|\{lpurl)#i', $tpl)) {
        throw new RuntimeException('Tracking template must start with {lpurl} or http(s)://');
    }
    return [
        'name' => $name, 'budget' => $budget, 'bidding' => $bidding,
        'max_cpc_limit' => (float)($in['max_cpc_limit'] ?? 0), 'target_cpa' => (float)($in['target_cpa'] ?? 0),
        'cpc_bid' => $cpc, 'search_partners' => !empty($in['search_partners']),
        'geo_type' => ($in['geo_type'] ?? '') === 'PRESENCE_OR_INTEREST' ? 'PRESENCE_OR_INTEREST' : 'PRESENCE',
        'locations' => $locs, 'languages' => $langs, 'excluded' => $excl, 'schedule' => array_values($sched),
        'ad_group' => $agName, 'keywords' => $kws, 'negatives' => $negs,
        'ad' => validate_rsa((array)($in['ad'] ?? [])),
        'tracking_url_template' => $tpl,
        'final_url_suffix' => ltrim(trim((string)($in['final_url_suffix'] ?? '')), '?&'),
    ];
}

/**
 * Operations for one atomic request (googleAds:mutate).
 * Temp IDs: budget -1, campaign -2, ad group -3. Any failure = nothing is created.
 */
function new_campaign_operations(string $cid, array $v): array
{
    $budgetRn = "customers/$cid/campaignBudgets/-1";
    $campRn = "customers/$cid/campaigns/-2";
    $agRn = "customers/$cid/adGroups/-3";

    $camp = [
        'resourceName' => $campRn,
        'name' => $v['name'],
        'status' => 'PAUSED', // safety: user reviews and enables it
        'advertisingChannelType' => 'SEARCH',
        'campaignBudget' => $budgetRn,
        'networkSettings' => [
            'targetGoogleSearch' => true,
            'targetSearchNetwork' => $v['search_partners'],
            'targetContentNetwork' => false,
            'targetPartnerSearchNetwork' => false,
        ],
        'geoTargetTypeSetting' => ['positiveGeoTargetType' => $v['geo_type']],
        'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
    ];
    switch ($v['bidding']) {
        case 'MANUAL_CPC':
            $camp['manualCpc'] = ['enhancedCpcEnabled' => false];
            break;
        case 'MAXIMIZE_CONVERSIONS':
            $camp['maximizeConversions'] = $v['target_cpa'] > 0 ? ['targetCpaMicros' => to_micros($v['target_cpa'])] : new stdClass();
            break;
        default: // Maximize clicks = targetSpend
            $camp['targetSpend'] = $v['max_cpc_limit'] > 0 ? ['cpcBidCeilingMicros' => to_micros($v['max_cpc_limit'])] : new stdClass();
    }
    if ($v['tracking_url_template'] !== '') {
        $camp['trackingUrlTemplate'] = $v['tracking_url_template'];
    }
    if ($v['final_url_suffix'] !== '') {
        $camp['finalUrlSuffix'] = $v['final_url_suffix'];
    }

    $ops = [];
    $ops[] = ['campaignBudgetOperation' => ['create' => [
        'resourceName' => $budgetRn,
        'name' => mb_substr($v['name'], 0, 200) . ' - budget ' . date('YmdHis'),
        'amountMicros' => to_micros($v['budget']),
        'deliveryMethod' => 'STANDARD',
        'explicitlyShared' => false,
    ]]];
    $ops[] = ['campaignOperation' => ['create' => $camp]];
    foreach ($v['locations'] as $id) {
        $ops[] = ['campaignCriterionOperation' => ['create' => [
            'campaign' => $campRn, 'location' => ['geoTargetConstant' => "geoTargetConstants/$id"],
        ]]];
    }
    foreach ($v['excluded'] as $id) {
        $ops[] = ['campaignCriterionOperation' => ['create' => [
            'campaign' => $campRn, 'negative' => true, 'location' => ['geoTargetConstant' => "geoTargetConstants/$id"],
        ]]];
    }
    foreach ($v['schedule'] as $r) {
        $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $campRn, 'adSchedule' => sched_payload($r)]]];
    }
    foreach ($v['languages'] as $id) {
        $ops[] = ['campaignCriterionOperation' => ['create' => [
            'campaign' => $campRn, 'language' => ['languageConstant' => "languageConstants/$id"],
        ]]];
    }
    foreach ($v['negatives'] as $k) {
        $ops[] = ['campaignCriterionOperation' => ['create' => [
            'campaign' => $campRn, 'negative' => true, 'keyword' => ['text' => $k['text'], 'matchType' => $k['match']],
        ]]];
    }
    $ag = ['resourceName' => $agRn, 'campaign' => $campRn, 'name' => $v['ad_group'],
           'status' => 'ENABLED', 'type' => 'SEARCH_STANDARD'];
    if ($v['cpc_bid'] > 0) {
        $ag['cpcBidMicros'] = to_micros($v['cpc_bid']);
    }
    $ops[] = ['adGroupOperation' => ['create' => $ag]];
    foreach ($v['keywords'] as $k) {
        $ops[] = ['adGroupCriterionOperation' => ['create' => [
            'adGroup' => $agRn, 'status' => 'ENABLED', 'keyword' => ['text' => $k['text'], 'matchType' => $k['match']],
        ]]];
    }
    $ops[] = ['adGroupAdOperation' => ['create' => [
        'adGroup' => $agRn, 'status' => 'ENABLED', 'ad' => rsa_ad_payload($v['ad']),
    ]]];
    return $ops;
}

/**
 * Build the atomic operation set that recreates a campaign from a blueprint (see
 * AdsReal::campaignBlueprint) into account $cid. Everything is created PAUSED so the user
 * reviews before enabling. Returns ['ops'=>[...], 'warnings'=>[...], 'counts'=>[...]].
 *
 * $opt: ['name' => override name, 'budget' => override daily budget]
 * Geo target constant IDs and language IDs are global, so targeting copies across accounts.
 */
function clone_operations(string $cid, array $bp, array $opt = []): array
{
    $name = trim((string)($opt['name'] ?? ''));
    if ($name === '') {
        $name = mb_substr((string)($bp['name'] ?? 'Campaign'), 0, 240) . ' (copy)';
    }
    $budget = (float)($opt['budget'] ?? 0);
    if ($budget <= 0) {
        $budget = (float)($bp['budget'] ?? 0);
    }
    if ($budget <= 0) {
        throw new RuntimeException('Daily budget must be greater than 0.');
    }

    $budgetRn = "customers/$cid/campaignBudgets/-1";
    $campRn   = "customers/$cid/campaigns/-2";
    $tmp = -10;
    $nextId = function () use (&$tmp): int { return $tmp--; };
    $warnings = [];

    $camp = [
        'resourceName' => $campRn,
        'name' => $name,
        'status' => 'PAUSED', // safety: user reviews and enables it
        'advertisingChannelType' => 'SEARCH',
        'campaignBudget' => $budgetRn,
        'networkSettings' => [
            'targetGoogleSearch' => true,
            'targetSearchNetwork' => !empty($bp['search_partners']),
            'targetContentNetwork' => false,
            'targetPartnerSearchNetwork' => false,
        ],
        'geoTargetTypeSetting' => ['positiveGeoTargetType' => ($bp['geo_type'] ?? '') === 'PRESENCE_OR_INTEREST' ? 'PRESENCE_OR_INTEREST' : 'PRESENCE'],
        'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
    ];
    // Replicate the bidding strategy where it is safely creatable in a fresh account;
    // value-based strategies need conversion data, so fall back to Maximize clicks and warn.
    $bid = (string)($bp['bidding'] ?? '');
    if ($bid === 'MANUAL_CPC') {
        $camp['manualCpc'] = ['enhancedCpcEnabled' => false];
    } elseif (in_array($bid, ['MAXIMIZE_CONVERSIONS', 'TARGET_CPA'], true)) {
        $camp['maximizeConversions'] = ($bp['target_cpa'] ?? 0) > 0 ? ['targetCpaMicros' => to_micros($bp['target_cpa'])] : new stdClass();
    } else {
        if (in_array($bid, ['TARGET_ROAS', 'MAXIMIZE_CONVERSION_VALUE'], true)) {
            $warnings[] = 'Bidding set to Maximize clicks (the source used a value/ROAS strategy, which needs conversion data in the new account). Change it after reviewing.';
        }
        $camp['targetSpend'] = new stdClass();
    }
    if (!empty($bp['tracking_url_template'])) {
        $camp['trackingUrlTemplate'] = $bp['tracking_url_template'];
    }
    if (!empty($bp['final_url_suffix'])) {
        $camp['finalUrlSuffix'] = $bp['final_url_suffix'];
    }

    $ops = [];
    $ops[] = ['campaignBudgetOperation' => ['create' => [
        'resourceName' => $budgetRn,
        'name' => mb_substr($name, 0, 170) . ' - budget ' . date('YmdHis') . '-' . random_int(100, 999),
        'amountMicros' => to_micros($budget),
        'deliveryMethod' => 'STANDARD',
        'explicitlyShared' => false,
    ]]];
    $ops[] = ['campaignOperation' => ['create' => $camp]];

    foreach (b_ids($bp['locations'] ?? []) as $id) {
        $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $campRn, 'location' => ['geoTargetConstant' => "geoTargetConstants/$id"]]]];
    }
    foreach (b_ids($bp['excluded'] ?? []) as $id) {
        $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $campRn, 'negative' => true, 'location' => ['geoTargetConstant' => "geoTargetConstants/$id"]]]];
    }
    foreach (b_ids($bp['languages'] ?? []) as $id) {
        $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $campRn, 'language' => ['languageConstant' => "languageConstants/$id"]]]];
    }
    foreach (parse_schedule($bp['schedule'] ?? []) as $r) {
        $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $campRn, 'adSchedule' => sched_payload($r)]]];
    }
    foreach (($bp['negatives'] ?? []) as $n) {
        $txt = trim((string)($n['text'] ?? ''));
        if ($txt === '') {
            continue;
        }
        $m = in_array($n['match'] ?? '', ['EXACT', 'PHRASE', 'BROAD'], true) ? $n['match'] : 'BROAD';
        $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $campRn, 'negative' => true, 'keyword' => ['text' => $txt, 'matchType' => $m]]]];
    }

    $counts = ['ad_groups' => 0, 'keywords' => 0, 'ads' => 0];
    foreach (($bp['ad_groups'] ?? []) as $g) {
        $agRn = "customers/$cid/adGroups/" . $nextId();
        $ag = ['resourceName' => $agRn, 'campaign' => $campRn, 'name' => (trim((string)($g['name'] ?? '')) ?: 'Ad group'),
               'status' => 'ENABLED', 'type' => 'SEARCH_STANDARD'];
        if (($g['cpc_bid'] ?? 0) > 0) {
            $ag['cpcBidMicros'] = to_micros($g['cpc_bid']);
        }
        if (!empty($g['tracking_url_template'])) {
            $ag['trackingUrlTemplate'] = $g['tracking_url_template'];
        }
        if (!empty($g['final_url_suffix'])) {
            $ag['finalUrlSuffix'] = $g['final_url_suffix'];
        }
        $ops[] = ['adGroupOperation' => ['create' => $ag]];
        $counts['ad_groups']++;

        foreach (($g['keywords'] ?? []) as $k) {
            $txt = trim((string)($k['text'] ?? ''));
            if ($txt === '') {
                continue;
            }
            $m = in_array($k['match'] ?? '', ['EXACT', 'PHRASE', 'BROAD'], true) ? $k['match'] : 'BROAD';
            $crit = ['adGroup' => $agRn, 'status' => 'ENABLED', 'keyword' => ['text' => $txt, 'matchType' => $m]];
            if (($k['cpc_bid'] ?? 0) > 0) {
                $crit['cpcBidMicros'] = to_micros($k['cpc_bid']);
            }
            if (!empty($k['final_url'])) {
                $crit['finalUrls'] = [$k['final_url']];
            }
            if (!empty($k['final_url_suffix'])) {
                $crit['finalUrlSuffix'] = $k['final_url_suffix'];
            }
            if (!empty($k['tracking_url_template'])) {
                $crit['trackingUrlTemplate'] = $k['tracking_url_template'];
            }
            $ops[] = ['adGroupCriterionOperation' => ['create' => $crit]];
            $counts['keywords']++;
        }

        foreach (($g['ads'] ?? []) as $ad) {
            try {
                $v = validate_rsa([
                    'headlines' => $ad['headlines'] ?? [], 'descriptions' => $ad['descriptions'] ?? [],
                    'final_url' => $ad['final_url'] ?? '', 'path1' => $ad['path1'] ?? '', 'path2' => $ad['path2'] ?? '',
                    'final_url_suffix' => $ad['final_url_suffix'] ?? '',
                ]);
            } catch (Throwable $e) {
                $warnings[] = 'Skipped an ad in "' . ($g['name'] ?? '') . '": ' . $e->getMessage();
                continue;
            }
            $ops[] = ['adGroupAdOperation' => ['create' => ['adGroup' => $agRn, 'status' => 'ENABLED', 'ad' => rsa_ad_payload($v)]]];
            $counts['ads']++;
        }
    }
    if ($counts['ad_groups'] === 0) {
        throw new RuntimeException('Nothing to clone - the source campaign has no ad groups.');
    }
    return ['ops' => $ops, 'warnings' => $warnings, 'counts' => $counts];
}
