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
    return ['script' => $code, 'build' => $build, 'mode' => $mode, 'campaigns' => count($camps),
            'suffixes' => count($cfg['SUFFIXES']), 'generated' => $now,
            'filename' => 'trakrhub-' . strtolower($mode) . '-' . (count($names) > 1 ? 'all-campaigns' : trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($names[0] ?? 'campaign')), '-')) . '.js'];
}
