<?php
/**
 * Trivago demo dataset.
 * Reads lib/demo_data/trivago.csv (daily campaign sheet) and turns it into the
 * structures the demo account uses: campaigns, ad groups, RSA ads, keywords and
 * daily metrics. Spend, clicks, conversions and revenue come straight from the sheet;
 * only impressions (not in the sheet) are derived from clicks with a stable CTR.
 *
 * To change the demo data: replace lib/demo_data/trivago.csv with a new export
 * of the same columns (Campaign Name, Advertiser, Network, ..., Live Date, Status,
 * Daily Spend, Revenue, ..., CPC, Clicks, Conversions, Budget).
 */
final class TrivagoData
{
    public const ACCOUNT_ID   = '4817256093';
    public const ACCOUNT_NAME = 'Trivago - Click Orbits';
    public const EMAIL        = 'pankaj@clickorbits.com';
    public const MCC_ID       = '6203917748';
    public const MCC_NAME     = 'Click Orbits MCC';

    /** Country code => [country name, final URL, language id, language code, geo id] */
    private const COUNTRIES = [
        'DE' => ['Germany', 'https://www.trivago.de/', '1001', 'de', '2276'],
        'CH' => ['Switzerland', 'https://www.trivago.ch/', '1001', 'de', '2756'],
        'UK' => ['United Kingdom', 'https://www.trivago.co.uk/', '1000', 'en', '2826'],
        'NZ' => ['New Zealand', 'https://www.trivago.co.nz/', '1000', 'en', '2554'],
        'CA' => ['Canada', 'https://www.trivago.ca/', '1000', 'en', '2124'],
        'US' => ['United States', 'https://www.trivago.com/', '1000', 'en', '2840'],
    ];

    private static ?array $cache = null;

    /** ['camps' => [id => info], 'days' => [id => [Y-m-d => metrics]], 'min' => date, 'max' => date] */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $file = __DIR__ . '/demo_data/trivago.csv';
        $camps = [];
        $days = [];
        $keyToId = [];
        $nameUsed = [];
        $min = null;
        $max = null;
        $fh = is_file($file) ? fopen($file, 'r') : false;
        if ($fh) {
            $head = fgetcsv($fh, 0, ',', '"', '');
            $col = array_flip(array_map(fn($h) => strtolower(trim((string)$h, " \t\n\r\0\x0B\xEF\xBB\xBF")), (array)$head));
            $g = fn(array $r, string $k) => trim((string)($r[$col[$k] ?? -1] ?? ''));
            while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                $name = $g($r, 'campaign name');
                $date = self::date($g($r, 'live date'));
                if ($name === '' || !$date) {
                    continue;
                }
                $cc = strtoupper(trim(preg_replace('/^trivago/i', '', $name)));
                $adv = $g($r, 'advertiser');
                $net = $g($r, 'network');
                $key = strtolower("$cc|$adv|$net");
                if (!isset($keyToId[$key])) {
                    $n = count($keyToId);
                    $id = (string)(21946350000 + $n * 1373);
                    $base = 'Trivago ' . $cc;
                    $nameUsed[$base] = ($nameUsed[$base] ?? 0) + 1;
                    $keyToId[$key] = $id;
                    $camps[$id] = ['id' => $id, 'cc' => $cc, 'name' => $base . ($nameUsed[$base] > 1 ? ' - ' . $nameUsed[$base] : ''),
                                   'advertiser' => $adv, 'network' => $net, 'first' => $date, 'last' => $date,
                                   'last_status' => 'Live', 'budget' => 0.0, 'health' => '', 'cpc' => 0.0];
                }
                $id = $keyToId[$key];
                $c = &$camps[$id];
                $budget = self::money($g($r, 'budget'));
                $cpc = self::money($g($r, 'cpc'));
                if ($date >= $c['last']) {
                    $c['last'] = $date;
                    $c['last_status'] = $g($r, 'status') ?: $c['last_status'];
                    if ($budget > 0) $c['budget'] = $budget;
                    if ($cpc > 0) $c['cpc'] = $cpc;
                    $c['health'] = $g($r, 'campaign health') ?: $c['health'];
                }
                if ($c['budget'] <= 0 && $budget > 0) $c['budget'] = $budget;
                if ($date < $c['first']) $c['first'] = $date;
                unset($c);

                $cost = max(0, self::money($g($r, 'daily spend')));
                $value = max(0, self::money($g($r, 'revenue')));
                $clicksRaw = $g($r, 'clicks');
                $convRaw = $g($r, 'conversions');
                $clicks = $clicksRaw === '' ? null : (int)round((float)str_replace(',', '', $clicksRaw));
                $conv = $convRaw === '' ? null : (float)str_replace(',', '', $convRaw);
                if ($clicks === null) {
                    $clicks = ($cost > 0 && $cpc > 0) ? (int)round($cost / $cpc) : 0;
                }
                $prev = $days[$id][$date] ?? null;
                $days[$id][$date] = [
                    'clicks' => $clicks + ($prev['clicks'] ?? 0),
                    'cost' => round($cost + ($prev['cost'] ?? 0), 2),
                    'conv' => $conv === null ? ($prev['conv'] ?? null) : $conv + ($prev['conv'] ?? 0),
                    'value' => round($value + ($prev['value'] ?? 0), 2),
                ];
                $min = $min === null || $date < $min ? $date : $min;
                $max = $max === null || $date > $max ? $date : $max;
            }
            fclose($fh);
        }

        // Fill gaps the sheet leaves blank: conversions from the campaign's own value per conversion
        foreach ($days as $id => &$byDay) {
            $v = 0;
            $n = 0;
            foreach ($byDay as $m) {
                if (($m['conv'] ?? 0) > 0 && $m['value'] > 0) {
                    $v += $m['value'];
                    $n += $m['conv'];
                }
            }
            $vpc = $n > 0 ? $v / $n : 0;
            foreach ($byDay as $date => &$m) {
                if ($m['conv'] === null) {
                    $m['conv'] = ($vpc > 0 && $m['value'] > 0) ? (float)min($m['clicks'], max(1, round($m['value'] / $vpc))) : 0.0;
                }
                // Impressions are not in the sheet: stable CTR 7-13% per campaign/day (normal for travel search)
                $ctr = (crc32($id . $date) % 600 + 700) / 10000;
                $m['impr'] = $m['clicks'] > 0 ? (int)round($m['clicks'] / $ctr) : 0;
                $m['conv'] = round((float)$m['conv'], 2);
            }
            unset($m);
            ksort($byDay);
        }
        unset($byDay);

        // Status: last row paused, or no data in the last 3 days of the sheet => paused
        foreach ($camps as $id => &$c) {
            $ended = $max && strtotime($c['last']) < strtotime($max) - 3 * 86400;
            $c['status'] = (strcasecmp($c['last_status'], 'Paused') === 0 || $ended) ? 'PAUSED' : 'ENABLED';
            $c['bidding'] = strcasecmp($c['health'], 'Testing') === 0 ? 'MANUAL_CPC' : 'TARGET_SPEND';
            $info = self::COUNTRIES[$c['cc']] ?? self::COUNTRIES['US'];
            [$c['country'], $c['url'], $c['lang_id'], $c['lang'], $c['geo']] = $info;
        }
        unset($c);

        return self::$cache = ['camps' => $camps, 'days' => $days, 'min' => $min, 'max' => $max];
    }

    /** "20-07-26" / "20-07-2026" / "2026-07-20" => "2026-07-20" */
    private static function date(string $s): ?string
    {
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', $s, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
            return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }
        return null;
    }

    /** "₹12,730.28" / "-₹1,048.74" / "#DIV/0!" => float */
    private static function money(string $s): float
    {
        $neg = str_contains($s, '-');
        $n = preg_replace('/[^0-9.]/', '', $s);
        return $n === '' ? 0.0 : ($neg ? -1 : 1) * (float)$n;
    }

    /** Sum the sheet metrics for one campaign over a date range */
    public static function range(string $campId, string $from, string $to): array
    {
        $t = ZERO_METRICS;
        foreach (self::load()['days'][$campId] ?? [] as $date => $m) {
            if ($date >= $from && $date <= $to) {
                foreach ($t as $k => $_) {
                    $t[$k] += $m[$k] ?? 0;
                }
            }
        }
        $t['cost'] = round($t['cost'], 2);
        $t['value'] = round($t['value'], 2);
        $t['conv'] = round($t['conv'], 2);
        return $t;
    }

    public static function day(string $campId, string $date): array
    {
        return (self::load()['days'][$campId][$date] ?? []) + ZERO_METRICS;
    }

    /** Share of a metrics row (ad group / ad / keyword split of a campaign) */
    public static function share(array $m, float $w): array
    {
        return ['impr' => (int)round($m['impr'] * $w), 'clicks' => (int)round($m['clicks'] * $w),
                'cost' => round($m['cost'] * $w, 2), 'conv' => round($m['conv'] * $w, 2), 'value' => round($m['value'] * $w, 2)];
    }

    // ================= Ad copy, ad groups, keywords =================

    /** RSA copy per language */
    public static function adCopy(string $lang, string $country, int $variant = 0): array
    {
        if ($lang === 'de') {
            $in = $country === 'Switzerland' ? 'in der Schweiz' : 'in Deutschland';
            $h = ['trivago® Offizielle Seite', 'Hotel? trivago', 'Hotelpreise vergleichen', "Hotels $in",
                  'Die besten Hoteldeals', 'Günstige Hotels finden', 'Ideales Hotel schnell finden', 'Hotels weltweit vergleichen',
                  'Kostenlose Stornierung', 'Jetzt Hotel buchen'];
            $d = ['Vergleiche Hotelpreise von Hunderten Buchungsseiten und finde dein ideales Hotel.',
                  'Einmal suchen, alle Angebote sehen. Buche dein Hotel zum besten Preis.',
                  'Filtere nach Preis, Bewertung und Lage und finde das passende Hotel für deine Reise.',
                  'Millionen Hotels weltweit. Suchen, vergleichen und sparen mit trivago.'];
            $p = ['hotels', $country === 'Switzerland' ? 'schweiz' : 'deutschland'];
        } else {
            $short = ['United Kingdom' => 'the UK', 'United States' => 'the USA'][$country] ?? $country;
            $h = ['trivago® Official Site', 'Hotel? trivago', 'Compare Hotel Prices', mb_substr("Hotels in $short", 0, 30),
                  'Find Your Ideal Hotel', 'Best Hotel Deals Today', 'Compare Hotels Worldwide', 'Cheap Hotels, Great Reviews',
                  'Free Cancellation Options', 'Book Your Hotel Now'];
            $d = ['Compare prices from hundreds of booking sites and find your ideal hotel in seconds.',
                  'One search, all the deals. Book your perfect stay on the site you prefer.',
                  'Filter by price, rating and location to find the right hotel for your trip.',
                  'Millions of hotels worldwide. Search, compare and save with trivago.'];
            $p = ['hotels', strtolower(str_replace(' ', '-', ['United Kingdom' => 'uk', 'United States' => 'usa', 'New Zealand' => 'nz'][$country] ?? $country))];
        }
        if ($variant % 2) { // second ad in the group: different lead headlines
            $h = array_merge([$h[2], $h[0], $h[4]], array_values(array_diff_key($h, [0 => 1, 2 => 1, 4 => 1])));
            $d = [$d[2], $d[0], $d[3], $d[1]];
        }
        return ['headlines' => $h, 'descriptions' => $d, 'path1' => $p[0], 'path2' => mb_substr($p[1], 0, 15)];
    }

    /** Ad groups with keywords for one campaign: [[name, weight, [[kw, match, weight], ...]], ...] */
    public static function adGroups(array $c): array
    {
        $cc = strtolower($c['cc']);
        if ($c['lang'] === 'de') {
            $ch = $c['country'] === 'Switzerland';
            $generic = [[$ch ? 'hotels schweiz' : 'hotels deutschland', 'PHRASE', 26], ['günstige hotels', 'PHRASE', 20],
                        ['hotel angebote', 'BROAD', 16], ['hotelpreise vergleichen', 'EXACT', 14], ['hotel buchen', 'PHRASE', 14],
                        ['hotel vergleich', 'EXACT', 10]];
        } else {
            $cn = strtolower(['United Kingdom' => 'uk', 'United States' => 'usa'][$c['country']] ?? $c['country']);
            $generic = [["hotels $cn", 'PHRASE', 26], ["cheap hotels $cn", 'PHRASE', 20], ['hotel deals', 'BROAD', 16],
                        ['compare hotel prices', 'EXACT', 14], ['best hotel prices', 'PHRASE', 14], ['book hotel online', 'EXACT', 10]];
        }
        $brand = [['trivago', 'EXACT', 48], ['trivago hotels', 'PHRASE', 30], ["trivago $cc", 'EXACT', 22]];
        $hotels = $c['lang'] === 'de' ? 'Hotels - ' . ($c['country'] === 'Switzerland' ? 'Schweiz' : 'Deutschland') : 'Hotels - ' . $c['country'];
        return [[$hotels, 0.64, $generic], ['trivago Brand', 0.36, $brand]];
    }

    /** Search terms per language (term, weight, added-as-keyword) */
    public static function searchTerms(array $c): array
    {
        $cc = strtolower($c['cc']);
        if ($c['lang'] === 'de') {
            $ch = $c['country'] === 'Switzerland';
            $loc = $ch ? ['zürich', 'genf', 'basel', 'luzern'] : ['berlin', 'münchen', 'hamburg', 'köln'];
            return [['trivago', 22, true], ['trivago hotel', 12, false], ["hotel {$loc[0]}", 11, false], ["hotels {$loc[1]}", 9, false],
                    ['günstige hotels', 8, true], ["hotel {$loc[2]} günstig", 7, false], ['hotelpreise vergleichen', 7, true],
                    ["{$loc[3]} hotel angebote", 6, false], ['hotel buchen', 6, true], ['hotel mit frühstück', 5, false],
                    ['last minute hotel', 4, false], ['hotel vergleich', 3, true]];
        }
        $loc = ['UK' => ['london', 'manchester', 'edinburgh', 'liverpool'], 'NZ' => ['auckland', 'queenstown', 'wellington', 'christchurch'],
                'CA' => ['toronto', 'vancouver', 'montreal', 'calgary'], 'US' => ['new york', 'las vegas', 'orlando', 'miami']][$c['cc']]
               ?? ['london', 'paris', 'rome', 'dubai'];
        return [['trivago', 22, true], ["trivago $cc", 10, true], ["hotels in {$loc[0]}", 11, false], ["cheap hotels {$loc[1]}", 9, false],
                ['hotel deals', 8, true], ["{$loc[2]} hotels", 7, false], ['compare hotel prices', 7, true],
                ["best hotels {$loc[3]}", 6, false], ['last minute hotel deals', 6, false], ['hotels near me', 5, false],
                ['book hotel online', 4, true], ['trivago hotel booking', 3, false]];
    }

    // ================= Billing =================

    /** Monthly spend for the whole sheet (all campaigns) */
    public static function monthly(?string $from = null, ?string $to = null): array
    {
        $d = self::load();
        $out = [];
        foreach ($d['days'] as $id => $byDay) {
            foreach ($byDay as $date => $m) {
                if (($from && $date < $from) || ($to && $date > $to)) continue;
                $k = substr($date, 0, 7);
                $out[$k] ??= ['month' => $k] + ZERO_METRICS + ['campaigns' => []];
                foreach (ZERO_METRICS as $mk => $_) $out[$k][$mk] += $m[$mk];
                if ($m['cost'] > 0) $out[$k]['campaigns'][$id] = 1;
            }
        }
        ksort($out);
        foreach ($out as &$r) {
            $r['campaigns'] = count($r['campaigns']);
            $r['cost'] = round($r['cost'], 2);
            $r['value'] = round($r['value'], 2);
        }
        return array_values($out);
    }
}
