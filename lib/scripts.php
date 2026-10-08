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
 * Copy-protection (layered obfuscation). Goal: resist casual inspection and
 * stop a competitor copy-pasting the strategy. NOT uncrackable - a determined
 * reverse-engineer or AI can still recover it, because any self-contained
 * script ships its own decoder. Layers: random identifiers; no plaintext
 * strings (every string lives as encoded bytes); two-key encoding; the byte
 * pool is split across several arrays and interleaved; strings are rebuilt at
 * runtime; the rotation step is decoded, not a literal; dead/noise code; the
 * decode key is split across several scattered variables; minified; and no
 * configuration left in comments. Each build is unique.
 */
function script_obfuscate(array $cfg, array $acc, string $build, int $sufCount): string
{
    $va = mt_rand(3, 250); $vb = mt_rand(3, 250);
    $vc = mt_rand(2, 120); $vd = mt_rand(2, 120);
    $ve = mt_rand(1, 999); $vf = mt_rand(1, 999);
    $K1 = $va ^ $vb; $K2 = $vc + $vd;

    $used = [];
    $rid = function () use (&$used): string {
        do { $n = '_' . substr('abcdefghijklmnopqrstuvwxyz', mt_rand(0, 25), 1) . bin2hex(random_bytes(mt_rand(2, 4))); }
        while (isset($used[$n]));
        $used[$n] = 1; return $n;
    };

    $seq = [];
    $cps = function (string $str): array {
        $o = []; $n = function_exists('mb_strlen') ? mb_strlen($str, 'UTF-8') : strlen($str);
        for ($i = 0; $i < $n; $i++) {
            $ch = function_exists('mb_substr') ? mb_substr($str, $i, 1, 'UTF-8') : $str[$i];
            $o[] = function_exists('mb_ord') ? mb_ord($ch, 'UTF-8') : ord($ch);
        }
        return $o;
    };
    $pad = function () use (&$seq) { $m = mt_rand(1, 4); for ($i = 0; $i < $m; $i++) $seq[] = mt_rand(0, 500); };
    // add one string -> returns "start,len" into the logical byte sequence
    $add = function (string $str) use (&$seq, $cps, $pad, $K1, $K2): string {
        $pad();
        $start = count($seq);
        foreach ($cps($str) as $cp) $seq[] = (($cp ^ $K1) + $K2);
        return $start . ',' . (count($seq) - $start);
    };
    $addA = function (array $arr) use ($add): string {
        $p = []; foreach ($arr as $x) $p[] = '[' . $add((string)$x) . ']';
        return '[' . implode(',', $p) . ']';
    };

    // decoys (noise) + real strings, interleaved
    $decoy = ['utm_content', 'gclid', 'device', 'network', 'legacy', 'v2', 'referrer'];
    shuffle($decoy);
    $DEC = $addA(array_slice($decoy, 0, 4));

    $T_C1 = $add("campaign.name = '");
    $T_C2 = $add("'");
    $T_ESC = $add("\\'");
    $add($decoy[4] ?? 'x');            // extra noise string, unreferenced
    $T_TODAY = $add('TODAY');
    $T_NMP = $addA($cfg['CAMPAIGN_NAMES']);
    $T_IDP = $addA(array_map('strval', $cfg['CAMPAIGN_IDS']));

    $M = [
        'P0' => $rid(), 'P1' => $rid(), 'P2' => $rid(), 'NP' => $rid(),
        'va' => $rid(), 'vb' => $rid(), 'vc' => $rid(), 'vd' => $rid(), 've' => $rid(), 'vf' => $rid(),
        'gb' => $rid(), 'g' => $rid(), 'dc' => $rid(), 's' => $rid(), 'l' => $rid(), 'r' => $rid(), 'i' => $rid(),
        'da' => $rid(), 'a' => $rid(), 'o' => $rid(), 'noise' => $rid(), 'x' => $rid(), 'y' => $rid(),
        'cmps' => $rid(), 'sn' => $rid(), 'it' => $rid(), 'c' => $rid(), 'bn' => $rid(), 'k' => $rid(),
        'nm' => $rid(), 'ids' => $rid(), 'idp' => $rid(), 'j' => $rid(), 'run' => $rid(), 'cs' => $rid(),
        'pv' => $rid(), 'u' => $rid(), 'cl' => $rid(),
        'VA' => (string)$va, 'VB' => (string)$vb, 'VC' => (string)$vc, 'VD' => (string)$vd, 'VE' => (string)$ve, 'VF' => (string)$vf,
        'C1' => $T_C1, 'C2' => $T_C2, 'ESC' => $T_ESC, 'TODAY' => $T_TODAY, 'NMP' => $T_NMP, 'IDP' => $T_IDP, 'DEC' => $DEC,
    ];

    if ($cfg['MODE'] === 'ROTATE') {
        $T_SFP = $addA($cfg['SUFFIXES']);
        $T_EV = $add((string)(int)$cfg['CLICKS_PER_SUFFIX']);
        $M['sf'] = $rid(); $M['ev'] = $rid(); $M['n'] = $rid(); $M['ix'] = $rid(); $M['w'] = $rid(); $M['h'] = $rid();
        $M['SFP'] = $T_SFP; $M['EV'] = $T_EV;
        $M['EXTRA'] = ',@sf@=@da@(@SFP@),@ev@=Number(@dc@(@EV@)),@n@=@sf@.length,@ix@,@w@,@h@';
        $M['LOGIC'] = '@ix@=Math.floor(@cl@/@ev@)%@n@;@w@=@sf@[@ix@];@h@=@u@.getFinalUrlSuffix()||"";if(@h@!==@w@&&!@pv@){@u@.setFinalUrlSuffix(@w@);}';
    } else {
        $M['EXTRA'] = '';
        $stmts = [];
        if (trim((string)$cfg['TRACKING_TEMPLATE']) !== '') {
            $M['TPL'] = $add((string)$cfg['TRACKING_TEMPLATE']); $M['tp'] = $rid();
            $stmts[] = 'var @tp@=@dc@(@TPL@);if(@u@.getTrackingTemplate()!==@tp@&&!@pv@){@u@.setTrackingTemplate(@tp@);}';
        }
        if (trim((string)$cfg['FINAL_URL_SUFFIX']) !== '') {
            $M['FS'] = $add((string)$cfg['FINAL_URL_SUFFIX']); $M['fsx'] = $rid();
            $stmts[] = 'var @fsx@=@dc@(@FS@);if((@u@.getFinalUrlSuffix()||"")!==@fsx@&&!@pv@){@u@.setFinalUrlSuffix(@fsx@);}';
        }
        $M['LOGIC'] = implode('', $stmts);
    }

    if (trim((string)$cfg['REPORT_EMAIL']) !== '') {
        $M['EM'] = $add((string)$cfg['REPORT_EMAIL']);
        $M['SU'] = $add('URL manager run');
        $M['BO'] = $add('campaigns updated: ');
        $M['MAIL'] = 'if(!@pv@&&@cs@.length){MailApp.sendEmail(@dc@(@EM@),@dc@(@SU@),@dc@(@BO@)+@cs@.length);}';
    } else {
        $M['MAIL'] = '';
    }

    // split the byte sequence across 3 interleaved pools
    $p0 = $p1 = $p2 = [];
    foreach ($seq as $idx => $v) { ${'p' . ($idx % 3)}[] = $v; }
    $M['A0'] = '[' . implode(',', $p0) . ']';
    $M['A1'] = '[' . implode(',', $p1) . ']';
    $M['A2'] = '[' . implode(',', $p2) . ']';

    $tpl = <<<'JS'
var @P0@=@A0@;var @P1@=@A1@;var @P2@=@A2@;var @NP@=[@P0@,@P1@,@P2@];var @va@=@VA@,@vb@=@VB@,@vc@=@VC@,@vd@=@VD@,@ve@=@VE@,@vf@=@VF@;
function @gb@(@g@){return @NP@[@g@%3][(@g@-@g@%3)/3];}
function @dc@(@s@,@l@){var @r@="",@i@;for(@i@=0;@i@<@l@;@i@++){@r@+=String.fromCharCode((@gb@(@s@+@i@)-(@vc@+@vd@))^(@va@^@vb@));}return @r@;}
function @da@(@a@){var @o@=[],@i@;for(@i@=0;@i@<@a@.length;@i@++){@o@.push(@dc@(@a@[@i@][0],@a@[@i@][1]));}return @o@;}
function @noise@(){var @x@=@da@(@DEC@),@y@=0,@i@;for(@i@=0;@i@<@x@.length;@i@++){@y@+=@x@[@i@].length;}return @y@*(@ve@^@vf@);}
function @cmps@(){var @o@=[],@sn@={},@it@,@c@,@bn@,@k@,@nm@=@da@(@NMP@),@ids@=[],@idp@=@da@(@IDP@),@j@;for(@j@=0;@j@<@idp@.length;@j@++){@ids@.push(Number(@idp@[@j@]));}@it@=AdsApp.campaigns().withIds(@ids@).get();while(@it@.hasNext()){@c@=@it@.next();@sn@[@c@.getId()]=1;@o@.push(@c@);}if(@o@.length<@ids@.length){for(@k@=0;@k@<@nm@.length;@k@++){@bn@=AdsApp.campaigns().withCondition(@dc@(@C1@)+@nm@[@k@].replace(/'/g,@dc@(@ESC@))+@dc@(@C2@)).get();while(@bn@.hasNext()){@c@=@bn@.next();if(!@sn@[@c@.getId()]){@sn@[@c@.getId()]=1;@o@.push(@c@);}}}}return @o@;}
function @run@(){var @cs@=@cmps@(),@pv@=AdsApp.getExecutionInfo().isPreview(),@i@,@c@,@u@,@cl@@EXTRA@;@noise@();for(@i@=0;@i@<@cs@.length;@i@++){@c@=@cs@[@i@];@u@=@c@.urls();@cl@=@c@.getStatsFor(@dc@(@TODAY@)).getClicks();@LOGIC@}@MAIL@}
function main(){@run@();}
JS;

    $map = [];
    foreach ($M as $k => $v) $map['@' . $k . '@'] = $v;
    $out = $tpl;
    for ($pass = 0; $pass < 8 && strpos($out, '@') !== false; $pass++) $out = strtr($out, $map);
    if (strpos($out, '@') !== false) throw new RuntimeException('Script build failed. Try again.');

    $header = "/* Google Ads script - paste into Tools > Bulk actions > Scripts, then Authorize, Preview, Save. */\n";
    return $header . $out . "\n";
}
