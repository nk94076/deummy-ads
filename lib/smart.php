<?php
/**
 * Smart Campaign Builder
 * Website URL -> page fetch -> business/category detect -> keywords, headlines, descriptions,
 * location, language, bidding and budget suggestions.
 *
 * Sources:
 *  1. Website content (title, meta, headings, prices, language)               - always
 *  2. Google Keyword Planner (search volume + CPC) - real accounts            - when the API allows it
 *  3. AI (Claude) - better headlines/descriptions                            - only if anthropic_api_key is set
 */

const SMART_CATEGORIES = [
    'telecom' => ['label' => 'Telecom / Mobile plans',
        'terms' => ['sim', 'prepaid', 'postpaid', 'unlimited', 'data', 'plan', 'plans', 'mobile', '5g', '4g', 'talk', 'text', 'esim', 'network', 'carrier', 'recharge', 'roaming', 'international calling'],
        'heads' => ['Keep Your Number', 'Unlimited Talk & Text', 'No Contract Required', 'Order Your SIM Today', 'Nationwide 5G Coverage', 'Free SIM Kit'],
        'descs' => ['Unlimited talk, text & data on a reliable network. No contract, cancel anytime.', 'Keep your number and switch in minutes. Order your SIM online today.'],
        'paths' => ['plans', 'unlimited']],
    'fashion' => ['label' => 'Fashion / Clothing',
        'terms' => ['dress', 'dresses', 'shirt', 'saree', 'sarees', 'kurta', 'kurti', 'fashion', 'clothing', 'apparel', 'shoes', 'collection', 'wear', 'lehenga', 'jeans', 'tops', 'ethnic'],
        'heads' => ['Shop The Latest Collection', 'New Arrivals Every Week', 'Easy Returns & Exchange', 'Shop Now & Save', 'Trending Styles Online'],
        'descs' => ['Discover the latest styles at great prices. Easy returns and fast delivery.', 'Shop new arrivals online. Quality fabrics, trending designs and secure checkout.'],
        'paths' => ['shop', 'new']],
    'ecommerce' => ['label' => 'Online store / E-commerce',
        'terms' => ['shop', 'cart', 'buy', 'sale', 'discount', 'shipping', 'products', 'store', 'order', 'offer', 'deals', 'price', 'checkout'],
        'heads' => ['Shop Now & Save', 'Best Prices Online', 'Fast & Secure Checkout', 'Great Deals Every Day', 'Order Online Today'],
        'descs' => ['Shop quality products at great prices. Fast delivery and secure checkout.', 'Find great deals online. Easy ordering, secure payments and quick shipping.'],
        'paths' => ['shop', 'deals']],
    'travel' => ['label' => 'Travel / Hotels',
        'terms' => ['hotel', 'hotels', 'flight', 'flights', 'travel', 'booking', 'holiday', 'tour', 'tours', 'resort', 'trip', 'vacation', 'package', 'packages', 'stay'],
        'heads' => ['Book Your Trip Today', 'Best Price Guarantee', 'Handpicked Holiday Deals', 'Easy Online Booking', 'Plan Your Next Getaway'],
        'descs' => ['Compare and book trips at the best prices. Easy booking and 24/7 support.', 'Handpicked stays and tours for every budget. Book online in minutes.'],
        'paths' => ['deals', 'book']],
    'finance' => ['label' => 'Finance / Insurance',
        'terms' => ['loan', 'loans', 'credit', 'insurance', 'invest', 'investment', 'bank', 'card', 'mortgage', 'finance', 'mutual', 'fund', 'emi', 'interest', 'policy'],
        'heads' => ['Apply Online In Minutes', 'Quick & Easy Approval', 'Compare Plans Online', 'Transparent Pricing', 'Get A Free Quote'],
        'descs' => ['Compare options and apply online in minutes. Transparent terms, no hidden fees.', 'Get expert guidance and a free quote today. Simple process, quick decisions.'],
        'paths' => ['apply', 'quote']],
    'education' => ['label' => 'Education / Courses',
        'terms' => ['course', 'courses', 'learn', 'learning', 'training', 'certification', 'class', 'classes', 'academy', 'university', 'admission', 'online course', 'students', 'exam'],
        'heads' => ['Enroll Now', 'Learn From Experts', 'Get Certified Online', 'Flexible Online Classes', 'Start Learning Today'],
        'descs' => ['Learn at your own pace with expert instructors. Enroll today and get certified.', 'Practical courses designed for real careers. Flexible schedules, online access.'],
        'paths' => ['courses', 'enroll']],
    'health' => ['label' => 'Health / Wellness',
        'terms' => ['health', 'clinic', 'doctor', 'supplement', 'wellness', 'treatment', 'dental', 'care', 'vitamin', 'hospital', 'therapy', 'skin', 'hair', 'weight'],
        'heads' => ['Book An Appointment', 'Trusted Care Experts', 'Quality You Can Trust', 'Order Online Today', 'Feel Your Best'],
        'descs' => ['Trusted care from experienced professionals. Book your appointment online today.', 'Quality products and expert advice for your wellness goals. Order online.'],
        'paths' => ['care', 'book']],
    'software' => ['label' => 'Software / SaaS',
        'terms' => ['software', 'saas', 'app', 'platform', 'api', 'dashboard', 'cloud', 'integration', 'trial', 'sign up', 'automation', 'tool', 'crm', 'analytics'],
        'heads' => ['Start Your Free Trial', 'Try It Free Today', 'Easy Setup In Minutes', 'Trusted By Teams', 'See Plans & Pricing'],
        'descs' => ['Powerful tools that save time. Start your free trial today, no card required.', 'Set up in minutes and scale as you grow. See plans and pricing.'],
        'paths' => ['pricing', 'trial']],
    'real_estate' => ['label' => 'Real estate',
        'terms' => ['property', 'properties', 'apartment', 'apartments', 'real estate', 'flats', 'villa', 'rent', 'homes', 'plots', 'bhk', 'residential', 'commercial'],
        'heads' => ['Book A Site Visit', 'Homes In Prime Locations', 'Verified Listings', 'Easy Payment Plans', 'Find Your Dream Home'],
        'descs' => ['Explore verified homes in prime locations. Book a free site visit today.', 'Modern homes with great amenities and easy payment plans. Enquire now.'],
        'paths' => ['homes', 'visit']],
    'food' => ['label' => 'Food / Restaurant',
        'terms' => ['restaurant', 'food', 'menu', 'delivery', 'pizza', 'cafe', 'recipe', 'meal', 'meals', 'kitchen', 'dine', 'takeaway'],
        'heads' => ['Order Online Now', 'Fresh & Delicious', 'Fast Home Delivery', 'See Our Menu', 'Book A Table Today'],
        'descs' => ['Fresh food made to order. Order online for fast delivery or book a table.', 'Delicious meals at great prices. See our menu and order in minutes.'],
        'paths' => ['menu', 'order']],
    'auto' => ['label' => 'Automotive',
        'terms' => ['car', 'cars', 'bike', 'bikes', 'auto', 'vehicle', 'dealer', 'tyres', 'tires', 'ev', 'showroom', 'test drive'],
        'heads' => ['Book A Test Drive', 'Best Deals On Cars', 'Easy Finance Options', 'Visit Our Showroom', 'Get On-Road Price'],
        'descs' => ['Explore the latest models with easy finance options. Book a test drive today.', 'Great prices and trusted service. Get your on-road price in minutes.'],
        'paths' => ['models', 'offers']],
    'local' => ['label' => 'Local services',
        'terms' => ['repair', 'plumber', 'cleaning', 'service', 'services', 'near me', 'installation', 'contractor', 'maintenance', 'electrician', 'pest', 'movers'],
        'heads' => ['Book A Service Today', 'Same-Day Service', 'Licensed Professionals', 'Get A Free Estimate', 'Call Us Now'],
        'descs' => ['Reliable, licensed professionals near you. Book online and get a free estimate.', 'Fast, affordable service with upfront pricing. Same-day appointments available.'],
        'paths' => ['services', 'book']],
];

const SMART_STOP = ['a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'for', 'with', 'at', 'by', 'from', 'is', 'are', 'was', 'be', 'your',
    'you', 'our', 'we', 'us', 'it', 'its', 'this', 'that', 'these', 'those', 'as', 'all', 'any', 'more', 'most', 'can', 'will', 'just', 'get', 'now',
    'new', 'best', 'top', 'home', 'page', 'site', 'website', 'official', 'online', 'click', 'here', 'read', 'learn', 'about', 'contact', 'login',
    'sign', 'up', 'menu', 'search', 'cookie', 'cookies', 'privacy', 'policy', 'terms', 'accept', 'skip', 'content', 'main', 'my', 'account', 'cart',
    'en', 'www', 'com', 'http', 'https', 'html', 'php', 'amp', 'nbsp', 'quot', 'what', 'how', 'why', 'when', 'where', 'who', 'which', 'if', 'no',
    'not', 'so', 'do', 'does', 'have', 'has', 'had', 'than', 'then', 'also', 'into', 'out', 'over', 'per', 'via', 'each', 'every', 'one', 'two',
    'ka', 'ki', 'ke', 'hai', 'aur', 'se', 'me', 'ko', 'par', 'pe'];

/** Private / local IP pe request mat jane do (SSRF protection) */
function smart_public_ip(string $ip): bool
{
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** Fetch website HTML (follows redirects manually, IP check at each hop) */
function smart_fetch(string $url): array
{
    global $CONFIG;
    $allowPrivate = !empty($CONFIG['smart_allow_private']); // local testing only
    for ($hop = 0; $hop < 5; $hop++) {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            throw new RuntimeException('Enter a valid website URL (starting with https://).');
        }
        $host = strtolower($p['host']);
        $port = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            throw new RuntimeException("Could not resolve website domain: $host");
        }
        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (!smart_public_ip($ip)) {
                    throw new RuntimeException('This address is not allowed (private/local network).');
                }
            }
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_RESOLVE => ["$host:$port:{$ips[0]}"],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.8'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 AdHookAdsBot',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > 3000000 ? 0 : strlen($chunk); // max 3 MB
            },
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc) {
            $url = $loc;
            continue;
        }
        if ($body === '' && $err) {
            throw new RuntimeException("Could not open website: $err");
        }
        if ($code >= 400) {
            throw new RuntimeException("Website returned an error (HTTP $code). Some sites block bots; fill in the details manually.");
        }
        return ['url' => $url, 'html' => $body];
    }
    throw new RuntimeException('Too many redirects.');
}

function smart_clean(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** HTML -> useful parts */
function smart_parse(string $html, string $url): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $meta = function (string $attr, string $val) use ($xp) {
        $n = $xp->query("//meta[translate(@$attr,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='$val']/@content")->item(0);
        return $n ? smart_clean($n->nodeValue) : '';
    };
    $texts = function (string $tag, int $max) use ($xp) {
        $out = [];
        foreach ($xp->query("//$tag") as $n) {
            $t = smart_clean($n->textContent);
            if ($t !== '' && mb_strlen($t) <= 120 && !in_array($t, $out, true)) {
                $out[] = $t;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    };
    $jsonld = [];
    foreach ($xp->query('//script[@type="application/ld+json"]') as $n) {
        $j = json_decode($n->textContent, true);
        foreach ((isset($j['@graph']) ? $j['@graph'] : (isset($j[0]) ? $j : [$j])) as $o) {
            if (is_array($o) && !empty($o['@type'])) {
                $jsonld[] = ['type' => is_array($o['@type']) ? implode(',', $o['@type']) : $o['@type'], 'name' => is_string($o['name'] ?? null) ? $o['name'] : ''];
            }
        }
    }
    $hreflang = [];
    foreach ($xp->query('//link[@hreflang]/@hreflang') as $n) {
        $hreflang[] = strtolower($n->nodeValue);
    }
    foreach ($xp->query('//script|//style|//noscript|//svg|//nav|//footer|//header') as $n) {
        $n->parentNode->removeChild($n);
    }
    $body = $xp->query('//body')->item(0);
    $text = $body ? smart_clean($body->textContent) : '';
    $htmlNode = $xp->query('//html/@lang')->item(0);
    return [
        'url' => $url,
        'title' => smart_clean($xp->query('//title')->item(0)->textContent ?? ''),
        'description' => $meta('name', 'description') ?: $meta('property', 'og:description'),
        'og_title' => $meta('property', 'og:title'),
        'site_name' => $meta('property', 'og:site_name') ?: $meta('name', 'application-name'),
        'h1' => $texts('h1', 5), 'h2' => $texts('h2', 15), 'h3' => $texts('h3', 10),
        'lang' => strtolower(substr($htmlNode ? $htmlNode->nodeValue : '', 0, 5)),
        'hreflang' => array_slice(array_unique($hreflang), 0, 30),
        'jsonld' => array_slice($jsonld, 0, 10),
        'text' => mb_substr($text, 0, 20000),
    ];
}

function smart_domain_brand(string $url): string
{
    $host = preg_replace('/^www\./', '', strtolower(parse_url($url, PHP_URL_HOST) ?: ''));
    $parts = explode('.', $host);
    $root = count($parts) >= 3 && in_array($parts[count($parts) - 2], ['co', 'com', 'net', 'org', 'gov', 'ac'], true)
        ? $parts[count($parts) - 3] : ($parts[count($parts) - 2] ?? $parts[0]);
    return ucfirst($root);
}

function smart_fit(string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s), " \t\n\r\0\x0B-|:,.·–—");
    if (mb_strlen($s) <= $max) {
        return $s;
    }
    $cut = mb_substr($s, 0, $max + 1);
    $sp = mb_strrpos($cut, ' ');
    return rtrim($sp > $max * 0.5 ? mb_substr($cut, 0, $sp) : '', ' ,-–|:');
}

function smart_title_case(string $s): string
{
    $small = ['a', 'an', 'and', 'or', 'the', 'of', 'for', 'to', 'in', 'on', 'at', 'by', 'with'];
    $w = explode(' ', trim($s));
    foreach ($w as $i => &$x) {
        // keep words like USA, 5G, eSIM, iPhone as they are
        if (preg_match('/\p{Lu}.*\p{Lu}|\d|\p{Ll}\p{Lu}/u', $x) || in_array(mb_strtolower($x), ['usa', 'uk', 'uae', 'sim', 'esim', '5g', '4g', 'emi', 'bhk', 'ev'], true)) {
            $x = in_array(mb_strtolower($x), ['usa', 'uk', 'uae', 'sim', 'emi', 'bhk', 'ev'], true) ? mb_strtoupper($x) : ($x === mb_strtolower($x) && mb_strtolower($x) === 'esim' ? 'eSIM' : $x);
            continue;
        }
        $l = mb_strtolower($x);
        $x = ($i > 0 && in_array($l, $small, true)) ? $l : mb_strtoupper(mb_substr($l, 0, 1)) . mb_substr($l, 1);
    }
    return implode(' ', $w);
}

/** Most important 1-3 word phrases in the text */
function smart_phrases(array $p, string $brand): array
{
    $sources = [[$p['title'], 5], [$p['og_title'], 3], [implode(' . ', $p['h1']), 4], [$p['description'], 3],
                [implode(' . ', $p['h2']), 2], [implode(' . ', $p['h3']), 1], [mb_substr($p['text'], 0, 6000), 1]];
    $score = [];
    $brandL = mb_strtolower($brand);
    foreach ($sources as [$txt, $w]) {
        foreach (preg_split('/[.!?|:;,()\[\]\/•·–—"“”]+/u', mb_strtolower($txt)) as $sent) {
            $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}&+\']+/u', $sent), fn($x) => $x !== '' && mb_strlen($x) > 1));
            $n = count($words);
            for ($i = 0; $i < $n; $i++) {
                for ($len = 2; $len <= 3 && $i + $len <= $n; $len++) {
                    $g = array_slice($words, $i, $len);
                    if (in_array($g[0], SMART_STOP, true) || in_array($g[$len - 1], SMART_STOP, true)) {
                        continue;
                    }
                    if (preg_match('/^\d/', $g[0]) || (preg_match('/\d/', $g[$len - 1]) && !preg_match('/^[45]g$/', $g[$len - 1]))
                        || ($len === 3 && in_array($g[1], SMART_STOP, true))) {
                        continue;
                    }
                    $ph = implode(' ', $g);
                    $score[$ph] = ($score[$ph] ?? 0) + $w * ($len === 2 ? 1 : 1.3);
                }
            }
        }
    }
    arsort($score);
    $out = [];
    foreach ($score as $ph => $sc) {
        if ($sc < 2 || $ph === $brandL) {
            continue;
        }
        // skip a short phrase already contained in a longer one
        foreach ($out as $o) {
            if (str_contains($o, $ph) || str_contains($ph, $o)) {
                continue 2;
            }
        }
        $out[] = $ph;
        if (count($out) >= 25) {
            break;
        }
    }
    return $out;
}

function smart_category(array $p): array
{
    $hay = mb_strtolower(implode(' ', [$p['title'], $p['title'], $p['description'], implode(' ', $p['h1']), implode(' ', $p['h2']), mb_substr($p['text'], 0, 8000)]));
    $best = ['ecommerce', 0];
    $scores = [];
    foreach (SMART_CATEGORIES as $k => $c) {
        $s = 0;
        foreach ($c['terms'] as $t) {
            $s += min(15, preg_match_all('/\b' . preg_quote($t, '/') . '\b/u', $hay));
        }
        $scores[$k] = $s;
        if ($s > $best[1]) {
            $best = [$k, $s];
        }
    }
    arsort($scores);
    return ['key' => $best[0], 'label' => SMART_CATEGORIES[$best[0]]['label'], 'confidence' => $best[1] >= 12 ? 'high' : ($best[1] >= 5 ? 'medium' : 'low')];
}

/** Country: domain, URL path, hreflang, currency symbols, account currency */
function smart_country(array $p, string $url, string $accCurrency): array
{
    $tld = ['in' => 'IN', 'uk' => 'GB', 'ca' => 'CA', 'au' => 'AU', 'de' => 'DE', 'fr' => 'FR', 'ae' => 'AE', 'sg' => 'SG', 'nz' => 'NZ', 'us' => 'US'];
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    $votes = [];
    $end = substr($host, strrpos($host, '.') + 1);
    if (isset($tld[$end])) {
        $votes[$tld[$end]] = ($votes[$tld[$end]] ?? 0) + 5;
    }
    if (preg_match('#/(?:[a-z]{2}[-_])?(us|in|uk|gb|ca|au|ae|sg|nz)(?:/|$)#i', parse_url($url, PHP_URL_PATH) ?? '', $m)) {
        $c = strtoupper($m[1]) === 'UK' ? 'GB' : strtoupper($m[1]);
        $votes[$c] = ($votes[$c] ?? 0) + 3;
    }
    if (preg_match('/^[a-z]{2}-([a-z]{2})$/', $p['lang'], $m)) {
        $c = strtoupper($m[1]);
        $votes[$c] = ($votes[$c] ?? 0) + 2;
    }
    $t = $p['title'] . ' ' . mb_substr($p['text'], 0, 8000);
    foreach (['₹' => 'IN', 'Rs.' => 'IN', 'INR' => 'IN', '£' => 'GB', 'AED' => 'AE', 'A$' => 'AU', 'C$' => 'CA', 'S$' => 'SG', 'NZ$' => 'NZ'] as $sym => $c) {
        if (($n = substr_count($t, $sym)) > 0) {
            $votes[$c] = ($votes[$c] ?? 0) + min(4, $n);
        }
    }
    if (preg_match_all('/(?<![A-Z])\$\s?\d/', $t) > 0) {
        $votes['US'] = ($votes['US'] ?? 0) + 1;
    }
    $cur = ['USD' => 'US', 'INR' => 'IN', 'GBP' => 'GB', 'CAD' => 'CA', 'AUD' => 'AU', 'AED' => 'AE', 'SGD' => 'SG', 'NZD' => 'NZ'][$accCurrency] ?? null;
    if ($cur) {
        $votes[$cur] = ($votes[$cur] ?? 0) + 1;
    }
    arsort($votes);
    $code = array_key_first($votes) ?: 'US';
    $geo = ['US' => '2840', 'IN' => '2356', 'GB' => '2826', 'CA' => '2124', 'AU' => '2036', 'DE' => '2276', 'FR' => '2250', 'AE' => '2784', 'SG' => '2702', 'NZ' => '2554'];
    $id = $geo[$code] ?? '2840';
    foreach (COMMON_GEOS as $g) {
        if ($g['id'] === $id) {
            return $g + ['code' => $code];
        }
    }
    return COMMON_GEOS[0] + ['code' => 'US'];
}

function smart_prices(string $text): array
{
    preg_match_all('/(?:(?:US)?\$|₹|Rs\.?\s?|£|€|AED\s?)\s?\d{1,6}(?:[.,]\d{1,2})?/u', mb_substr($text, 0, 15000), $m);
    return array_slice(array_values(array_unique(array_map('trim', $m[0]))), 0, 8);
}

/** All suggestions (without AI) */
function smart_suggest(array $p, string $accCurrency): array
{
    $brand = $p['site_name'] ?: '';
    if ($brand === '' || mb_strlen($brand) > 25) {
        $parts = preg_split('/\s[|\-–—:]\s/u', $p['title']);
        $cand = array_filter(array_map('trim', $parts), fn($x) => $x !== '' && mb_strlen($x) <= 25);
        usort($cand, fn($a, $b) => mb_strlen($a) <=> mb_strlen($b));
        $dom = smart_domain_brand($p['url']);
        $brand = '';
        foreach ($cand as $c) {
            if (stripos(str_replace(' ', '', $c), $dom) !== false) {
                $brand = $c;
                break;
            }
        }
        $brand = $brand ?: $dom;
    }
    $cat = smart_category($p);
    $C = SMART_CATEGORIES[$cat['key']];
    $country = smart_country($p, $p['url'], $accCurrency);
    $prices = smart_prices($p['title'] . ' ' . implode(' ', $p['h1']) . ' ' . implode(' ', $p['h2']) . ' ' . $p['text']);
    $phrases = smart_phrases($p, $brand);

    // ---- keywords ----
    $bl = mb_strtolower($brand);
    $kw = [$bl];
    foreach (array_slice($phrases, 0, 3) as $ph) {
        if (!str_contains($ph, $bl)) {
            $kw[] = "$bl $ph";
        }
    }
    foreach ($phrases as $ph) {
        $kw[] = $ph;
    }
    $kw = array_values(array_unique(array_filter($kw, fn($k) => mb_strlen($k) <= 80 && count(explode(' ', $k)) <= 10)));
    $keywords = array_map(fn($k, $i) => ['text' => $k, 'match' => $i === 0 ? 'EXACT' : 'PHRASE', 'source' => 'website'], $kw, array_keys($kw));

    // ---- headlines ----
    $H = [];
    $add = function ($s) use (&$H) {
        $s = smart_fit((string)$s, 30);
        if (mb_strlen($s) >= 3 && !preg_grep('/^' . preg_quote($s, '/') . '$/iu', $H)) {
            $H[] = $s;
        }
    };
    $add($brand);
    $add("$brand Official Site");
    $good = fn($t) => mb_strlen(trim($t)) >= 8 && mb_strlen(trim($t)) <= 30 && count(preg_split('/\s+/', trim($t))) >= 2
                      && !preg_match('/^\d+\s+\S+$/', trim($t));
    foreach (array_merge($p['h1'], preg_split('/\s[|\-–—:]\s/u', $p['title']), [$p['og_title']]) as $t) {
        if ($good($t)) {
            $add(smart_title_case($t));
        }
    }
    if ($prices) {
        $add('Starting At ' . $prices[0]);
    }
    $low = mb_strtolower($p['text'] . ' ' . $p['description']);
    if (str_contains($low, 'free shipping') || str_contains($low, 'free delivery')) {
        $add('Free Shipping Available');
    }
    if (str_contains($low, 'no contract')) {
        $add('No Contract Required');
    }
    foreach (array_slice($p['h2'], 0, 6) as $t) {
        if ($good($t)) {
            $add(smart_title_case($t));
        }
    }
    foreach ($C['heads'] as $h) {
        $add($h);
    }
    foreach (array_slice($phrases, 0, 8) as $ph) {
        if ($good($ph)) {
            $add(smart_title_case($ph));
        }
    }
    $H = array_slice($H, 0, 15);

    // ---- descriptions ----
    $D = [];
    $addD = function ($s) use (&$D) {
        $s = smart_fit((string)$s, 90);
        if (mb_strlen($s) >= 20 && !in_array(rtrim($s, '.') . '.', $D, true)) {
            $D[] = preg_match('/[.!?]$/', $s) ? $s : (mb_strlen($s) < 90 ? "$s." : $s);
        }
    };
    if ($p['description']) {
        // add whole sentences while they fit in 90 characters
        $sents = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/u', $p['description']))));
        $cur = '';
        foreach ($sents as $sent) {
            if (mb_strlen($sent) > 90) {
                if ($cur !== '') { $addD($cur); $cur = ''; }
                $addD($sent);
                continue;
            }
            if ($cur === '' || mb_strlen("$cur $sent") <= 90) {
                $cur = trim("$cur $sent");
            } else {
                $addD($cur);
                $cur = $sent;
            }
        }
        if ($cur !== '') {
            $addD($cur);
        }
    }
    foreach ($C['descs'] as $d) {
        $addD($d);
    }
    if ($phrases) {
        $addD("$brand: " . smart_title_case($phrases[0]) . '. ' . ($C['heads'][0] ?? 'Order online today') . '.');
    }
    $D = array_slice($D, 0, 4);

    // ---- display paths ----
    $paths = $C['paths'];
    if ($phrases) {
        $w = explode(' ', $phrases[0]);
        if (mb_strlen($w[0]) <= 15 && !in_array($w[0], $paths, true) && $w[0] !== mb_strtolower($brand)) {
            $paths[0] = $w[0];
        }
    }

    // ---- language ----
    $code = substr($p['lang'], 0, 2) ?: 'en';
    $langId = '1000';
    foreach (COMMON_LANGS as $l) {
        if ($l['code'] === $code) {
            $langId = $l['id'];
        }
    }
    $langs = array_values(array_unique([$langId, '1000']));

    $budget = ['USD' => 30, 'INR' => 1000, 'GBP' => 25, 'EUR' => 25, 'CAD' => 40, 'AUD' => 40, 'AED' => 100, 'SGD' => 40, 'NZD' => 40][$accCurrency] ?? 30;
    return [
        'site' => ['url' => $p['url'], 'title' => $p['title'], 'brand' => $brand, 'description' => $p['description'],
                   'category' => $cat, 'country' => $country, 'lang' => $p['lang'] ?: 'en', 'prices' => $prices,
                   'schema' => array_values(array_unique(array_column($p['jsonld'], 'type')))],
        'suggest' => [
            'campaign_name' => mb_substr("$brand - {$country['code']} - Search - " . explode(' /', $C['label'])[0], 0, 120),
            'ad_group' => smart_title_case($phrases[0] ?? $brand),
            'keywords' => array_slice($keywords, 0, 25),
            'negatives' => array_values(array_filter(['free', 'jobs', 'careers', 'salary', 'login', 'customer care number', 'complaint'],
                // skip negatives that appear in our own keywords/brand (e.g. "free shipping")
                fn($n) => !preg_grep('/\\b' . preg_quote($n, '/') . '\\b/u', array_merge($kw, array_map('mb_strtolower', $H))))),
            'headlines' => $H, 'descriptions' => $D,
            'path1' => smart_fit($paths[0] ?? '', 15), 'path2' => smart_fit($paths[1] ?? '', 15),
            'final_url' => $p['url'],
            'locations' => [$country], 'languages' => $langs,
            'bidding' => 'MAXIMIZE_CLICKS', 'budget' => $budget, 'budget_note' => 'Estimate (no Keyword Planner data)',
            'max_cpc_limit' => null,
        ],
    ];
}

/** Optional: better copy from Claude (config: anthropic_api_key) */
function smart_ai(array $p, array $base): ?array
{
    global $CONFIG;
    $key = trim((string)($CONFIG['anthropic_api_key'] ?? ''));
    if ($key === '') {
        return null;
    }
    $page = "URL: {$p['url']}\nTitle: {$p['title']}\nMeta description: {$p['description']}\nH1: " . implode(' | ', $p['h1'])
          . "\nH2: " . implode(' | ', array_slice($p['h2'], 0, 10)) . "\nPrices: " . implode(', ', $base['site']['prices'])
          . "\nPage text (excerpt): " . mb_substr($p['text'], 0, 5000);
    $prompt = "You write Google Search ads. From this landing page, return ONLY a JSON object with keys:\n"
        . "category (short label), keywords (15-25 search keywords real users would type, lowercase, no match-type symbols), "
        . "headlines (12-15, each MAX 30 characters, varied: brand, offer, price, benefit, CTA), "
        . "descriptions (4, each MAX 90 characters), path1, path2 (each max 15 chars, no spaces).\n"
        . "Follow Google Ads editorial policy: no ALL CAPS, no excessive punctuation, no unverifiable superlatives like #1 or best.\n"
        . "Only use facts from the page.\n\n$page";
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $CONFIG['ai_model'] ?? 'claude-sonnet-5', 'max_tokens' => 1500,
                                           'messages' => [['role' => 'user', 'content' => $prompt]]]),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $res = json_decode((string)$raw, true);
    if ($code !== 200 || empty($res['content'][0]['text'])) {
        throw new RuntimeException('AI suggestions failed (HTTP ' . $code . '): ' . mb_strimwidth((string)($res['error']['message'] ?? $raw), 0, 150, '…'));
    }
    $t = $res['content'][0]['text'];
    $j = json_decode(substr($t, strpos($t, '{'), strrpos($t, '}') - strpos($t, '{') + 1), true);
    if (!is_array($j)) {
        throw new RuntimeException('Could not read the AI response.');
    }
    $fit = fn($list, $max, $n) => array_slice(array_values(array_unique(array_filter(array_map(fn($x) => smart_fit((string)$x, $max), (array)$list), fn($x) => mb_strlen($x) >= 3))), 0, $n);
    return [
        'category' => (string)($j['category'] ?? ''),
        'keywords' => $fit($j['keywords'] ?? [], 80, 25),
        'headlines' => $fit($j['headlines'] ?? [], 30, 15),
        'descriptions' => $fit($j['descriptions'] ?? [], 90, 4),
        'path1' => smart_fit(str_replace(' ', '-', (string)($j['path1'] ?? '')), 15),
        'path2' => smart_fit(str_replace(' ', '-', (string)($j['path2'] ?? '')), 15),
    ];
}

/**
 * Full analysis. $svc = AdsReal/AdsDemo (for Keyword Planner + conversion check)
 */
function smart_analyze(string $url, $svc, string $accCurrency): array
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $f = smart_fetch($url);
    $p = smart_parse($f['html'], $f['url']);
    if ($p['title'] === '' && mb_strlen($p['text']) < 50) {
        throw new RuntimeException('No content found on the page (it may be rendered with JavaScript). Fill in manually or try another URL.');
    }
    $r = smart_suggest($p, $accCurrency);
    $notes = [];
    $r['sources'] = ['website' => true, 'keyword_planner' => false, 'ai' => false];

    // ---- Google Keyword Planner ----
    try {
        $seeds = array_slice(array_column($r['suggest']['keywords'], 'text'), 0, 10);
        $ideas = $svc->keywordIdeas($f['url'], array_column($r['suggest']['locations'], 'id'), $r['suggest']['languages'][0], $seeds);
        if ($ideas) {
            $r['sources']['keyword_planner'] = true;
            $merged = $r['suggest']['keywords'];
            $idx = array_flip(array_column($merged, 'text'));
            foreach ($ideas as $i) {
                if (isset($idx[$i['text']])) {
                    $merged[$idx[$i['text']]] = array_merge($merged[$idx[$i['text']]],
                        array_intersect_key($i, array_flip(['volume', 'competition', 'cpc_low', 'cpc_high'])));
                } elseif ($i['volume'] >= 10) {
                    $merged[] = $i + ['match' => 'PHRASE', 'source' => 'planner'];
                }
            }
            // highest volume first, brand always first
            $first = array_shift($merged);
            usort($merged, fn($a, $b) => ($b['volume'] ?? -1) <=> ($a['volume'] ?? -1));
            $r['suggest']['keywords'] = array_slice(array_merge([$first], $merged), 0, 30);
            $cpcs = [];
            foreach ($r['suggest']['keywords'] as $k) {
                if (!empty($k['cpc_high'])) {
                    $cpcs[] = ($k['cpc_low'] + $k['cpc_high']) / 2;
                }
            }
            if ($cpcs) {
                sort($cpcs);
                $med = $cpcs[intdiv(count($cpcs), 2)];
                $r['suggest']['max_cpc_limit'] = round(max($cpcs) * 1.1, 2);
                $r['suggest']['budget'] = max(1, round($med * 30, $med * 30 >= 100 ? -1 : 0));
                $r['suggest']['budget_note'] = 'Keyword Planner avg. CPC (' . round($med, 2) . ') × ~30 clicks/day';
            }
        }
    } catch (Throwable $e) {
        $notes[] = 'Keyword Planner data unavailable: ' . mb_strimwidth($e->getMessage(), 0, 160, '…');
    }

    // ---- Conversion tracking ----
    try {
        $r['conversions'] = $svc->conversionStatus();
        if ($r['conversions']['count'] > 0) {
            $r['suggest']['bidding'] = 'MAXIMIZE_CONVERSIONS';
            $r['suggest']['bidding_note'] = $r['conversions']['count'] . ' active conversion action(s), so Maximize conversions.';
        } else {
            $r['suggest']['bidding_note'] = 'No conversion tracking found, so start with Maximize clicks.';
        }
    } catch (Throwable $e) {
        $r['conversions'] = null;
    }

    // ---- AI (optional) ----
    try {
        if ($ai = smart_ai($p, $r)) {
            $r['sources']['ai'] = true;
            if (count($ai['headlines']) >= 3) {
                $r['suggest']['headlines'] = $ai['headlines'];
            }
            if (count($ai['descriptions']) >= 2) {
                $r['suggest']['descriptions'] = $ai['descriptions'];
            }
            $have = array_column($r['suggest']['keywords'], 'text');
            foreach ($ai['keywords'] as $k) {
                if (!in_array(mb_strtolower($k), $have, true)) {
                    $r['suggest']['keywords'][] = ['text' => mb_strtolower($k), 'match' => 'PHRASE', 'source' => 'ai'];
                }
            }
            $r['suggest']['keywords'] = array_slice($r['suggest']['keywords'], 0, 35);
            if ($ai['path1']) {
                $r['suggest']['path1'] = $ai['path1'];
                $r['suggest']['path2'] = $ai['path2'];
            }
            if ($ai['category']) {
                $r['site']['category']['ai_label'] = $ai['category'];
            }
        }
    } catch (Throwable $e) {
        $notes[] = $e->getMessage();
    }
    $r['notes'] = $notes;
    return $r;
}
