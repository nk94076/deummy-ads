AdHook Ads Manager v3 - Google Ads tool (PHP 8+ + MySQL, no Composer)
=========================================================================

v3.5 - TRIVAGO DEMO ACCOUNT + BILLING PAGE
------------------------------------------
DEMO ACCOUNT (shown when the logged-in user has no Google account connected)
- One account "Trivago - Click Orbits" (Customer ID 481-725-6093) built from lib/demo_data/trivago.csv
- Campaigns: Trivago DE, UK, NZ, CA, CH, UK - 2, NZ - 2, US with their real daily spend, clicks,
  conversions and revenue (conv. value) from the sheet - every date filter shows that period's numbers
- Each campaign: 2 ad groups (Hotels - <country>, trivago Brand), 2 trivago RSA ads per group
  (German copy for DE/CH, English for the rest), keywords, search terms, location + language targeting
- Ads tab > "Preview ad": Google-style search ad preview + all headlines/descriptions
- Impressions are not in the sheet, so they are derived from clicks (CTR 7-13%)
- To change the data: replace lib/demo_data/trivago.csv with a new export (same columns)
- config.php 'demo_banner' => false hides the "Demo account" note at the top

BILLING (sidebar > Billing)
- Payments profile (config.php 'billing_profile': name, payments account ID, GSTIN, address, payment
  setting / method - empty fields are hidden). Default name: Click Orbits Private Limited
- Ad spend for the selected dates + GST (config 'billing_tax_rate', default 18) + total incl. GST
- Demo account: total spend to date, monthly statements for the full history
- Daily spend chart, spend by campaign with share %, CSV export of the monthly statements

v3.4 - CONVERSION TRACKING + KEYWORD-LEVEL FINAL URL
----------------------------------------------------
CONVERSIONS (sidebar > Conversions)
- List every conversion action with status, category, count type, window and recorded conversions
- Create a new website (gtag.js) conversion action: category, counting (One / Every),
  default value + currency, click-through window (7-90 days), attribution (Data-driven / Last click),
  primary (used for bidding) or secondary
- Copy the site-wide tag and the event snippet to add to your website, plus the Conversion ID / Label
- Edit an existing action (name, status, category, counting, window, value, attribution)
KEYWORD-LEVEL FINAL URL (Campaign > Keywords > edit a keyword)
- Set a Final URL per keyword (overrides the ad's URL for clicks on that keyword)
- Optional keyword-level tracking template and Final URL suffix
- The "Add keywords" dialog can also set one Final URL for all the keywords being added

v3.3 - NEW ACCOUNTS IN A MANAGER ACCOUNT (bulk)
-----------------------------------------------
All accounts page (or the account picker) > "New accounts in manager"
- Pick the manager account, default currency + time zone, (optional) invite email + access level,
  tracking template, suffix
- Generate from a name pattern ("Lyca US {n}" x 10), paste a list (name, currency, time zone, email),
  or type rows one by one
- "Validate with Google" = validate only (nothing is created). Up to 25 accounts per batch.
- Result table: each account's new Customer ID, CSV download, account list refresh
- Currency/time zone cannot be changed later. Add billing to each account in Google Ads yourself.
- Only in a manager account of your own connected Google login (not shared accounts).

v3.2
----
SMART CAMPAIGN BUILDER (Campaigns > New campaign, at the top)
- Enter a website URL > Analyze: reads the page and detects business category, brand, country, language, price
- Suggestions: keywords (search volume + CPC from Google Keyword Planner when the API allows),
  headlines (30), descriptions (90), display path, locations, bidding, budget, negatives
- Tick what you want > "Apply selected suggestions" > the form is filled in
- (Optional) AI copy: set anthropic_api_key in config.php (console.anthropic.com)
CAMPAIGN HEALTH SCORE + LIVE VALIDATION
- Targeting / Keywords / Ad strength / Tracking / Budget & bidding % + overall
- Errors (block create): invalid URL, fewer than 3 headlines, location conflict, a negative blocking your
  own keyword, "!" in a headline, duplicate headlines, schedule overlap ...
- Warnings: no conversion tracking, weak ad (fewer than 8 headlines), duplicate keywords, 20+ keywords ...
ACCESS & SHARING (sidebar)
- Google Ads access: invite an email to the current account (Admin/Standard/Read only/Email only),
  cancel pending invitations, change or remove a user's access level. Only the user who connected the
  Google account can do this.
- Share with tool users: give your account (or all accounts of a login) to a tool user as View only or
  Can edit. The user needs no Google login. Remove it any time.
- Database: the account_shares table is created automatically (schema v5).

CREATE A NEW CAMPAIGN (v3.1)
----------------------------
Campaigns page > "New campaign" button:
 1. Name, daily budget, bidding (Maximize clicks / Maximize conversions / Manual CPC), search partners
 2. Locations (search: country/state/city) + EXCLUDE locations + location option + languages
    + Ad schedule (presets like Mon-Fri 9-6, or your own time slots, 15-min steps, account time zone)
 3. Ad group + paste keywords ([exact], "phrase", otherwise default match) + negative keywords
 4. Responsive search ad: 3-15 headlines (30), 2-4 descriptions (90), final URL, display path, live preview
 5. Campaign-level final URL suffix / tracking template
- "Validate with Google" = a validate-only request to Google (nothing is created, only errors checked)
- The campaign is always created PAUSED. Everything in one atomic request: if one part fails, nothing is created.
- The form draft is saved in the browser (survives closing the page).
In an existing campaign:
- "Locations & language" tab: locations, exclude locations, languages, ad schedule, Presence option
  (saved in one atomic request)
- "Ads & final URLs" tab > "New RSA ad"

SUFFIX ROTATOR
--------------
Take the URLs from your affiliate network's Excel (.xlsx) / CSV / paste ->
the tool takes the part after "?" from each URL and applies them one by one to the
Google Ads Final URL suffix on a schedule. The final URL stays the same.
- Target: selected campaigns or the whole account
- Interval: 10 min / 15 / 30 / 1 hour / ... / custom (min 5)
- Time window (e.g. 09:00-23:00, overnight too) + days (Mon-Sun)
- In order or random; when the list ends -> loop or stop
- Extra params: e.g. subid1={campaignid} - set/replaced on every suffix
- Run now, ON/OFF, a log of every change (CSV export) - to reconcile with the network
- Changing an account/campaign suffix does not send ads back for review
REQUIRED: add a cron that runs EVERY 5 MINUTES for the suffix rotator:
   /usr/bin/php /home/USERNAME/public_html/ads/cron.php YOUR_CRON_KEY suffix

WHAT'S NEW (v3)
---------------
- Everything in MySQL: users, Google connections (tokens ENCRYPTED), rules, change log
- Google Ads data stored in the DB: daily cost / clicks / conversions / value per campaign
- "All accounts" page: spend for every account in one table (from the DB, very fast), with a Sync button
- Hourly cron: data sync + automation rules
- Tables are created automatically - nothing to do in phpMyAdmin

SETUP (Hostinger)
-----------------
1. Create a database:
   hPanel > Databases > MySQL Databases > new database + user + password
   (names look like: u123456789_adhook)

2. Upload the folder, e.g. public_html/ads/

3. Copy config.sample.php -> config.php and fill in:
     app_user / app_password   -> super admin login
     db_host                   -> 'localhost' (on Hostinger)
     db_name / db_user / db_pass
     app_key                   -> 40+ random characters (encrypts the Google tokens)
                                  set it once and never change it
     client_id / client_secret -> Google Auth Platform > Clients > Desktop app
     cron_key                  -> any long random text

4. Open https://yoursite.com/ads/ and log in as admin
   -> all tables are created automatically on the first login
   (if the DB user has no CREATE permission, import schema.sql via phpMyAdmin > Import)

5. Users: Settings > Users > "New user"
   Each user logs in and connects their account / manager account from "Link Google account"

6. All accounts page > "Sync all accounts" -> the first sync loads 30 days of data into the DB

7. Cron (Hostinger > Advanced > Cron Jobs) - add TWO crons:
   a) Hourly:      /usr/bin/php /home/USERNAME/public_html/ads/cron.php YOUR_CRON_KEY all
      (data sync + automation rules + suffix rotator)
   b) Every 5 min: /usr/bin/php /home/USERNAME/public_html/ads/cron.php YOUR_CRON_KEY suffix
      (suffix rotator only - so a 10-min interval fires on time)

DATABASE TABLES
---------------
users            login users (hashed passwords)
connections      Google accounts per user (refresh token AES-256 encrypted)
account_cache    Google account -> accessible accounts list (30 min cache)
ads_accounts     all Google Ads accounts + last sync
campaign_daily   daily data per campaign  <-- query this table for reports/BI
sync_runs        sync history (30 days)
rules            automation rules
suffix_rotators  suffix rotator settings
suffix_items     uploaded suffix list
suffix_log       which suffix was applied when (60 days)
change_log       all changes made from the tool

Example SQL (account spend for the last 30 days):
  SELECT a.name, SUM(d.cost) spend, SUM(d.conversions) conv
  FROM campaign_daily d JOIN ads_accounts a ON a.customer_id = d.customer_id
  WHERE d.date >= CURDATE() - INTERVAL 30 DAY
  GROUP BY a.name ORDER BY spend DESC;

UPGRADING FROM v2
-----------------
- Upload the v2 lib/data/ folder alongside - on the first login, users, connections and rules
  are imported into MySQL automatically (tokens get encrypted). You can then delete lib/data.

SETTINGS
--------
sync_backfill_days  (30)  days of data to load on a new account's first sync
sync_recent_days    (3)   days to refresh on every cron (Google updates conversions late)
allow_changes       false = whole tool is read-only
Data older than 2 years is cleaned up by the cron automatically.

FEATURES (all of v2)
--------------------
Accounts dashboard (KPIs, trend, Today filter), campaign settings (budget, target CPA/ROAS,
tracking template, final URL suffix, custom params), add/edit ad groups, edit ad final URLs,
PMax asset group URLs, keywords, search terms -> negatives, reports (device/day/hour/network),
analytics insights, automation rules, bulk tracking, bulk final URL replace, change log, multi-user.

SECURITY
--------
- Run over HTTPS only. Never put config.php in Git.
- .htaccess keeps config.php, schema.sql and the lib/ folder from being public
- An unverified OAuth app can connect up to 100 different Google accounts (lifetime)
