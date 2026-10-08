<?php
/**
 * Google Ads Scripts generator (sidebar > Scripts).
 * Builds a ready-to-paste Google Ads script for the chosen campaign(s):
 *   ROTATE - rotate the campaign's Final URL suffix by clicks (every N clicks today -> next suffix)
 *   SET    - set the campaign's tracking template and/or Final URL suffix once
 * The script only uses official AdsApp calls (campaign.urls(), getStatsFor) and runs in
 * Google Ads > Tools > Bulk actions > Scripts. Changing a campaign suffix/template does not
 * send ads for review.
 */

const SCRIPT_MODES = ['ROTATE', 'SET'];

/** Auto-build a rotation suffix list from the campaign itself (no manual input needed) */
function script_auto_suffixes(array $camps, int $count = 5): string
{
    $c = $camps[0] ?? null;
    $slug = $c ? preg_replace('/[^a-z0-9]+/', '', strtolower(preg_replace('/^trivago\s*/i', '', (string)$c['name']))) : '';
    if ($slug === '') $slug = 'cmp';
    $lines = [];
    for ($i = 1; $i <= max(2, $count); $i++) {
        $lines[] = sprintf('clickref=th_%s_%02d&utm_source=google&utm_medium=cpc&utm_campaign={campaignid}', $slug, $i);
    }
    return implode("\n", $lines);
}

/** Fill in the data a script needs straight from the selected campaign(s) */
function script_autofill(array $in, array $camps): array
{
    $mode = in_array($in['mode'] ?? '', SCRIPT_MODES, true) ? $in['mode'] : 'ROTATE';
    if ($mode === 'SET') {
        if (trim((string)($in['tracking_template'] ?? '')) === '' && trim((string)($in['final_url_suffix'] ?? '')) === '') {
            $in['tracking_template'] = '{lpurl}?utm_source=google&utm_medium=cpc&utm_campaign={campaignid}';
            $in['final_url_suffix'] = 'clickref={campaignid}&utm_term={keyword}';
        }
    } else {
        if (trim((string)($in['suffixes'] ?? '')) === '') {
            $in['suffixes'] = script_auto_suffixes($camps);
        }
        if (trim((string)($in['clicks_per'] ?? '')) === '') {
            $in['clicks_per'] = 50;
        }
    }
    return $in;
}

/** Validate the form and return the script text + meta */
function script_generate(array $acc, array $camps, array $in): array
{
    $mode = in_array($in['mode'] ?? '', SCRIPT_MODES, true) ? $in['mode'] : 'ROTATE';
    if (!$camps) {
        throw new RuntimeException('Select a campaign.');
    }
    $clean = fn($s) => ltrim(trim((string)$s), "?& \t");
    $suffixes = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)($in['suffixes'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('~^https?://~i', $line)) { // full URL pasted: keep the part after "?"
            $q = parse_url($line, PHP_URL_QUERY);
            $line = $q ?: '';
        }
        $line = $clean($line);
        if ($line !== '' && !in_array($line, $suffixes, true)) $suffixes[] = mb_substr($line, 0, 2000);
    }
    $every = max(1, min(100000, (int)($in['clicks_per'] ?? 50)));
    $tpl = trim((string)($in['tracking_template'] ?? ''));
    $sfx = $clean($in['final_url_suffix'] ?? '');
    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email for the run report (or leave it empty).');
    }
    if ($mode === 'ROTATE' && count($suffixes) < 2) {
        throw new RuntimeException('Add at least 2 URL suffixes (one per line) to rotate.');
    }
    if ($mode === 'SET' && $tpl === '' && $sfx === '') {
        throw new RuntimeException('Enter a tracking template or a Final URL suffix.');
    }
    if ($tpl !== '' && !preg_match('~^(\{lpurl\}|https?://)~i', $tpl)) {
        throw new RuntimeException('Tracking template must start with {lpurl} or http(s)://');
    }

    $ids = array_map(fn($c) => (string)$c['id'], $camps);
    $names = array_map(fn($c) => (string)$c['name'], $camps);
    $now = date('Y-m-d H:i');
    $tz = date_default_timezone_get();
    $cfg = [
        'ACCOUNT_ID' => fmt_cid($acc['id']),
        'CAMPAIGN_IDS' => $ids,
        'CAMPAIGN_NAMES' => $names,
        'MODE' => $mode,
        'CLICKS_PER_SUFFIX' => $every,
        'SUFFIXES' => $mode === 'ROTATE' ? $suffixes : [],
        'TRACKING_TEMPLATE' => $mode === 'SET' ? $tpl : '',
        'FINAL_URL_SUFFIX' => $mode === 'SET' ? $sfx : '',
        'REPORT_EMAIL' => $email,
    ];
    $build = 'TH-' . strtoupper(substr(hash('sha256', json_encode($cfg) . $now), 0, 10));
    $json = fn($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $campList = implode("\n", array_map(fn($c) => ' *   - ' . $c['name'] . ' (ID ' . $c['id'] . ')', $camps));
    $modeLine = $mode === 'ROTATE'
        ? "Rotate Final URL suffix: next suffix every $every clicks (today's clicks), " . count($suffixes) . ' suffixes'
        : 'Set campaign tracking template / Final URL suffix';

    $code = <<<JS
/**
 * TrakrHub - Clicks & URL Manager (Google Ads script)
 * Account : {$acc['name']} ({$cfg['ACCOUNT_ID']})
 * Campaign(s):
$campList
 * Mode    : $modeLine
 * Build   : $build  ·  generated $now ($tz)
 *
 * Install : Google Ads > Tools > Bulk actions > Scripts > (+) New script
 *           paste everything, Authorize, Preview, then Save.
 *           Frequency: Hourly (rotation follows today's clicks).
 * Note    : only campaign-level URL options change, so ads are NOT sent for review.
 */

var CONFIG = {
  ACCOUNT_ID: {$json($cfg['ACCOUNT_ID'])},
  CAMPAIGN_IDS: {$json($ids)},
  CAMPAIGN_NAMES: {$json($names)},
  MODE: {$json($mode)},                 // ROTATE | SET
  CLICKS_PER_SUFFIX: $every,
  SUFFIXES: {$json($cfg['SUFFIXES'])},
  TRACKING_TEMPLATE: {$json($cfg['TRACKING_TEMPLATE'])},
  FINAL_URL_SUFFIX: {$json($cfg['FINAL_URL_SUFFIX'])},
  REPORT_EMAIL: {$json($email)},
  BUILD: {$json($build)}
};

function main() {
  var account = AdsApp.currentAccount();
  var log = [];
  var say = function (m) { Logger.log(m); log.push(m); };
  say('TrakrHub ' + CONFIG.BUILD + ' · ' + account.getName() + ' (' + account.getCustomerId() + ') · mode ' + CONFIG.MODE);
  if (account.getCustomerId() !== CONFIG.ACCOUNT_ID) {
    say('Note: script was generated for ' + CONFIG.ACCOUNT_ID + '; matching campaigns by ID, then by name.');
  }

  var campaigns = findCampaigns_();
  if (!campaigns.length) {
    say('No matching campaign found. Check the campaign IDs / names in CONFIG.');
    report_(log);
    return;
  }

  campaigns.forEach(function (c) {
    var stats = c.getStatsFor('TODAY');
    var clicks = stats.getClicks();
    var urls = c.urls();
    var label = c.getName() + ' [' + c.getId() + ']';
    say(label + ' · today: ' + clicks + ' clicks, ' + stats.getImpressions() + ' impr., cost ' + stats.getCost().toFixed(2));

    if (CONFIG.MODE === 'ROTATE') {
      var n = CONFIG.SUFFIXES.length;
      var idx = Math.floor(clicks / CONFIG.CLICKS_PER_SUFFIX) % n;
      var want = CONFIG.SUFFIXES[idx];
      var have = urls.getFinalUrlSuffix() || '';
      if (have === want) {
        say('  suffix #' + (idx + 1) + '/' + n + ' already live - no change');
      } else {
        if (!AdsApp.getExecutionInfo().isPreview()) urls.setFinalUrlSuffix(want);
        say('  suffix -> #' + (idx + 1) + '/' + n + ': ' + want + (AdsApp.getExecutionInfo().isPreview() ? '  (preview only)' : ''));
      }
      var next = (Math.floor(clicks / CONFIG.CLICKS_PER_SUFFIX) + 1) * CONFIG.CLICKS_PER_SUFFIX;
      say('  next rotation at ' + next + ' clicks today');
    } else {
      if (CONFIG.TRACKING_TEMPLATE) {
        if (urls.getTrackingTemplate() !== CONFIG.TRACKING_TEMPLATE) {
          if (!AdsApp.getExecutionInfo().isPreview()) urls.setTrackingTemplate(CONFIG.TRACKING_TEMPLATE);
          say('  tracking template -> ' + CONFIG.TRACKING_TEMPLATE);
        } else say('  tracking template already set');
      }
      if (CONFIG.FINAL_URL_SUFFIX) {
        if ((urls.getFinalUrlSuffix() || '') !== CONFIG.FINAL_URL_SUFFIX) {
          if (!AdsApp.getExecutionInfo().isPreview()) urls.setFinalUrlSuffix(CONFIG.FINAL_URL_SUFFIX);
          say('  final URL suffix -> ' + CONFIG.FINAL_URL_SUFFIX);
        } else say('  final URL suffix already set');
      }
    }
  });
  report_(log);
}

/** Campaigns by ID; falls back to exact name match (e.g. after copying to another account) */
function findCampaigns_() {
  var out = [], seen = {};
  var it = AdsApp.campaigns().withIds(CONFIG.CAMPAIGN_IDS.map(Number)).get();
  while (it.hasNext()) { var c = it.next(); seen[c.getId()] = 1; out.push(c); }
  if (out.length < CONFIG.CAMPAIGN_IDS.length) {
    CONFIG.CAMPAIGN_NAMES.forEach(function (name) {
      var byName = AdsApp.campaigns().withCondition("campaign.name = '" + name.replace(/'/g, "\\\\'") + "'").get();
      while (byName.hasNext()) { var c = byName.next(); if (!seen[c.getId()]) { seen[c.getId()] = 1; out.push(c); } }
    });
  }
  return out;
}

function report_(log) {
  if (!CONFIG.REPORT_EMAIL || AdsApp.getExecutionInfo().isPreview()) return;
  MailApp.sendEmail(CONFIG.REPORT_EMAIL, 'TrakrHub script run · ' + AdsApp.currentAccount().getName(), log.join('\\n'));
}

JS;
    $protect = ($in['protect'] ?? '1') !== '0' && ($in['protect'] ?? true) !== false;
    if ($protect) {
        $code = script_obfuscate($cfg, $acc, $build, $mode === 'ROTATE' ? count($suffixes) : 0);
    }

    return ['script' => $code, 'build' => $build, 'mode' => $mode, 'campaigns' => count($camps),
            'suffixes' => count($cfg['SUFFIXES']), 'generated' => $now, 'protected' => $protect,
            'filename' => 'trakrhub-' . strtolower($mode) . '-' . (count($names) > 1 ? 'all-campaigns' : trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($names[0] ?? 'campaign')), '-')) . ($protect ? '.min.js' : '.js')];
}

/**
 * Copy-protection: emit a functional but obfuscated version of the script.
 * Every build is unique - identifiers are random, strings are XOR-encoded and
 * decoded at runtime (no eval, so it still runs in the Google Ads runtime),
 * numbers are split into arithmetic, and only the branch actually used is kept.
 * The Google Ads API method names stay visible (the script must call them to run),
 * so the campaigns, suffix list, thresholds and tracking strategy are what gets hidden.
 */
function script_obfuscate(array $cfg, array $acc, string $build, int $sufCount): string
{
    $key = mt_rand(23, 239);
    $used = [];
    $rid = function () use (&$used): string {
        do {
            $n = '_' . substr('abcdefghijklmnopqrstuvwxyz', mt_rand(0, 25), 1) . bin2hex(random_bytes(mt_rand(2, 4)));
        } while (isset($used[$n]));
        $used[$n] = 1;
        return $n;
    };
    // string -> JS array literal of XOR-encoded code points
    $enc = function (string $s) use ($key): string {
        $out = [];
        $n = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $ch = function_exists('mb_substr') ? mb_substr($s, $i, 1, 'UTF-8') : $s[$i];
            $cp = function_exists('mb_ord') ? mb_ord($ch, 'UTF-8') : ord($ch);
            $out[] = $cp ^ $key;
        }
        return '[' . implode(',', $out) . ']';
    };
    // integer -> arithmetic expression
    $numx = function (int $v) use (&$numx): string {
        if ($v <= 1) return (string)$v;
        $r = mt_rand(1, $v - 1);
        return '(' . $r . '+' . ($v - $r) . ')';
    };

    $M = [
        'D' => $rid(), 'F' => $rid(), 'C' => $rid(), 'pv' => $rid(), 'cs' => $rid(),
        'i' => $rid(), 'c' => $rid(), 'u' => $rid(), 'cl' => $rid(), 'n' => $rid(),
        'idx' => $rid(), 'want' => $rid(), 'have' => $rid(), 'o' => $rid(), 'sn' => $rid(),
        'it' => $rid(), 'bn' => $rid(), 'k' => $rid(), 'nm' => $rid(), 'arr' => $rid(),
        's' => $rid(), 'j' => $rid(),
        'K' => (string)$key,
        'IDS' => '[' . implode(',', array_map('intval', $cfg['CAMPAIGN_IDS'])) . ']',
        'NAMES' => '[' . implode(',', array_map(fn($x) => $enc((string)$x), $cfg['CAMPAIGN_NAMES'])) . ']',
        'COND1' => $enc("campaign.name = '"),
        'COND2' => $enc("'"),
        'ESC' => $enc("\\'"),
        'TODAY' => $enc('TODAY'),
        'EVERY' => $numx((int)$cfg['CLICKS_PER_SUFFIX']),
        'SUF' => '[' . implode(',', array_map(fn($x) => $enc((string)$x), $cfg['SUFFIXES'])) . ']',
    ];

    if ($cfg['MODE'] === 'ROTATE') {
        $M['BODYVARS'] = ',@n@,@idx@,@want@,@have@';
        $M['LOGIC'] = '@n@=@SUF@.length;@idx@=Math.floor(@cl@/@EVERY@)%@n@;@want@=@D@(@SUF@[@idx@]);'
            . '@have@=@u@.getFinalUrlSuffix()||"";if(@have@!==@want@&&!@pv@){@u@.setFinalUrlSuffix(@want@);}';
    } else {
        $M['BODYVARS'] = '';
        $stmts = [];
        if (trim((string)$cfg['TRACKING_TEMPLATE']) !== '') {
            $M['TPL'] = $enc((string)$cfg['TRACKING_TEMPLATE']);
            $stmts[] = 'if(@u@.getTrackingTemplate()!==@D@(@TPL@)&&!@pv@){@u@.setTrackingTemplate(@D@(@TPL@));}';
        }
        if (trim((string)$cfg['FINAL_URL_SUFFIX']) !== '') {
            $M['FS'] = $enc((string)$cfg['FINAL_URL_SUFFIX']);
            $stmts[] = 'if((@u@.getFinalUrlSuffix()||"")!==@D@(@FS@)&&!@pv@){@u@.setFinalUrlSuffix(@D@(@FS@));}';
        }
        $M['LOGIC'] = implode('', $stmts);
    }

    $mail = '';
    if (trim((string)$cfg['REPORT_EMAIL']) !== '') {
        $M['EMAIL'] = $enc((string)$cfg['REPORT_EMAIL']);
        $M['SUBJ'] = $enc('URL manager run');
        $M['BODY'] = $enc('campaigns updated: ');
        $mail = 'if(!@pv@&&@cs@.length){MailApp.sendEmail(@D@(@EMAIL@),@D@(@SUBJ@),@D@(@BODY@)+@cs@.length);}';
    }
    $M['MAIL'] = $mail;

    $tpl = <<<'JS'
function @D@(@arr@){var @s@="",@j@;for(@j@=0;@j@<@arr@.length;@j@++){@s@+=String.fromCharCode(@arr@[@j@]^@K@);}return @s@;}
function @C@(){var @o@=[],@sn@={},@it@,@c@,@bn@,@k@,@nm@=@NAMES@;@it@=AdsApp.campaigns().withIds(@IDS@).get();while(@it@.hasNext()){@c@=@it@.next();@sn@[@c@.getId()]=1;@o@.push(@c@);}if(@o@.length<@IDS@.length){for(@k@=0;@k@<@nm@.length;@k@++){@bn@=AdsApp.campaigns().withCondition(@D@(@COND1@)+@D@(@nm@[@k@]).replace(/'/g,@D@(@ESC@))+@D@(@COND2@)).get();while(@bn@.hasNext()){@c@=@bn@.next();if(!@sn@[@c@.getId()]){@sn@[@c@.getId()]=1;@o@.push(@c@);}}}}return @o@;}
function @F@(){var @cs@=@C@(),@i@,@c@,@u@,@cl@,@pv@=AdsApp.getExecutionInfo().isPreview()@BODYVARS@;for(@i@=0;@i@<@cs@.length;@i@++){@c@=@cs@[@i@];@u@=@c@.urls();@cl@=@c@.getStatsFor(@D@(@TODAY@)).getClicks();@LOGIC@}@MAIL@}
function main(){@F@();}
JS;

    $map = array_combine(array_map(fn($k) => '@' . $k . '@', array_keys($M)), array_values($M));
    $out = $tpl;
    for ($pass = 0; $pass < 6 && strpos($out, '@') !== false; $pass++) {
        $out = strtr($out, $map);
    }
    if (strpos($out, '@') !== false) {
        throw new RuntimeException('Script build failed. Try again.');
    }
    $sufLine = $sufCount ? ' · ' . $sufCount . '-step rotation' : '';
    $header = "/* TrakrHub URL Manager · protected build $build$sufLine\n"
        . "   Paste into Google Ads > Tools > Bulk actions > Scripts, then Authorize > Preview > Save. */\n";
    return $header . $out . "\n";
}
