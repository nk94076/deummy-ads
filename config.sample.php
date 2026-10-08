<?php
/**
 * AdHook Ads Manager - Config
 * Copy this file to "config.php" and fill in the values.
 * Never commit config.php to Git.
 */
return [
    // ---- Super admin login (manages users and all settings) ----
    'app_user'          => 'admin',
    'app_password'      => 'change-this-strong-password',   // must be changed

    // ---- MySQL (Hostinger > Databases > MySQL Databases) ----
    'db_host'           => 'localhost',
    'db_name'           => 'u123456789_adhook',
    'db_user'           => 'u123456789_adhook',
    'db_pass'           => 'YOUR_DB_PASSWORD',

    // Key used to encrypt Google tokens - long random text (40+ characters).
    // Set it once and never change it, or every connected Google account must be reconnected.
    'app_key'           => 'change-this-to-a-long-random-string',

    // ---- Google OAuth (Google Auth Platform > Clients > Desktop app) ----
    'client_id'         => 'XXXXXXXX.apps.googleusercontent.com',
    'client_secret'     => 'GOCSPX-XXXXXXXXXXXXXXXX',

    // Optional - ignored by Google since Sept 2026
    'developer_token'   => '',

    'api_version'       => 'v25',

    // Data sync: days to load on the first sync, and days to refresh on every cron run
    'sync_backfill_days' => 30,
    'sync_recent_days'   => 3,

    // false = whole tool is read-only (no edits, no pausing)
    'allow_changes'     => true,

    // Secret key for cron (automation rules + data sync) - any long random text
    // Cron URL: https://yoursite.com/ads/cron.php?key=YOUR_CRON_KEY
    'cron_key'          => 'change-this-random-cron-key',

    'timezone'          => 'Asia/Kolkata',

    // (Optional) AI-written headlines/descriptions in the Smart Campaign Builder.
    // Anthropic API key (console.anthropic.com). Empty = website analysis + Keyword Planner only.
    'anthropic_api_key' => '',
    'ai_model'          => 'claude-sonnet-5',
];
