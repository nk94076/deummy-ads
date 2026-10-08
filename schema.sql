-- AdHook Ads Manager - MySQL schema
-- Tables are created automatically on first load.
-- If the DB user has no CREATE permission, import this file in phpMyAdmin > Import.

CREATE TABLE IF NOT EXISTS app_settings (
  name  VARCHAR(64) PRIMARY KEY,
  value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  username      VARCHAR(60) PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  password_hash VARCHAR(255) NOT NULL,
  disabled      TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Connected Google accounts per user (refresh_token encrypted)
CREATE TABLE IF NOT EXISTS connections (
  id            VARCHAR(32) PRIMARY KEY,
  owner         VARCHAR(60) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  refresh_token TEXT NOT NULL,
  created_at    DATETIME NOT NULL,
  KEY idx_owner (owner)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Google account -> accessible Google Ads accounts (cache)
CREATE TABLE IF NOT EXISTS account_cache (
  conn_id    VARCHAR(32) PRIMARY KEY,
  data       LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rules (
  id          VARCHAR(20) PRIMARY KEY,
  owner       VARCHAR(60) NOT NULL,
  data        TEXT NOT NULL,
  enabled     TINYINT(1) NOT NULL DEFAULT 0,
  last_run    DATETIME NULL,
  last_result VARCHAR(255) NULL,
  KEY idx_owner (owner)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS change_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(60) NOT NULL,
  customer_id VARCHAR(20) NOT NULL,
  what        TEXT NOT NULL,
  demo        TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  KEY idx_user (username, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Google Ads data (synced) =====
CREATE TABLE IF NOT EXISTS ads_accounts (
  conn_id     VARCHAR(32) NOT NULL,
  customer_id VARCHAR(20) NOT NULL,
  owner       VARCHAR(60) NOT NULL,
  name        VARCHAR(255) NOT NULL,
  currency    VARCHAR(8) NOT NULL DEFAULT '',
  status      VARCHAR(20) NOT NULL DEFAULT 'ENABLED',
  login_id    VARCHAR(20) NOT NULL DEFAULT '',
  via         VARCHAR(255) NOT NULL DEFAULT '',
  last_sync   DATETIME NULL,
  sync_error  VARCHAR(255) NULL,
  PRIMARY KEY (conn_id, customer_id),
  KEY idx_owner (owner),
  KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily data per campaign
CREATE TABLE IF NOT EXISTS campaign_daily (
  customer_id   VARCHAR(20) NOT NULL,
  campaign_id   VARCHAR(20) NOT NULL,
  date          DATE NOT NULL,
  campaign_name VARCHAR(255) NOT NULL,
  status        VARCHAR(20) NOT NULL DEFAULT '',
  channel       VARCHAR(40) NOT NULL DEFAULT '',
  impressions   BIGINT NOT NULL DEFAULT 0,
  clicks        BIGINT NOT NULL DEFAULT 0,
  cost          DECIMAL(16,2) NOT NULL DEFAULT 0,
  conversions   DECIMAL(16,2) NOT NULL DEFAULT 0,
  conv_value    DECIMAL(16,2) NOT NULL DEFAULT 0,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (customer_id, campaign_id, date),
  KEY idx_customer_date (customer_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner       VARCHAR(60) NOT NULL,
  customer_id VARCHAR(20) NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NOT NULL,
  rows_saved  INT NOT NULL DEFAULT 0,
  ok          TINYINT(1) NOT NULL DEFAULT 1,
  message     VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_owner (owner, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Suffix Rotator =====
CREATE TABLE IF NOT EXISTS suffix_rotators (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner          VARCHAR(60) NOT NULL,
  name           VARCHAR(120) NOT NULL,
  conn_id        VARCHAR(32) NOT NULL,
  customer_id    VARCHAR(20) NOT NULL,
  account_name   VARCHAR(255) NOT NULL DEFAULT '',
  target         ENUM('account','campaigns') NOT NULL DEFAULT 'campaigns',
  campaign_ids   TEXT NULL,
  interval_min   INT NOT NULL DEFAULT 60,
  window_start   CHAR(5) NOT NULL DEFAULT '00:00',
  window_end     CHAR(5) NOT NULL DEFAULT '23:59',
  days           VARCHAR(7) NOT NULL DEFAULT '1234567',
  mode           ENUM('sequential','random') NOT NULL DEFAULT 'sequential',
  on_end         ENUM('loop','stop') NOT NULL DEFAULT 'loop',
  single_use     TINYINT(1) NOT NULL DEFAULT 1,
  extra_params   VARCHAR(500) NOT NULL DEFAULT '',
  active         TINYINT(1) NOT NULL DEFAULT 0,
  pos            INT NOT NULL DEFAULT 0,
  total          INT NOT NULL DEFAULT 0,
  next_run_at    DATETIME NULL,
  last_run_at    DATETIME NULL,
  last_suffix    TEXT NULL,
  last_error     VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL,
  KEY idx_owner (owner),
  KEY idx_due (active, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suffix_items (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rotator_id   INT UNSIGNED NOT NULL,
  seq          INT NOT NULL,
  suffix       TEXT NOT NULL,
  used_count   INT NOT NULL DEFAULT 0,
  last_used_at DATETIME NULL,
  KEY idx_rot (rotator_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suffix_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rotator_id  INT UNSIGNED NOT NULL,
  item_seq    INT NOT NULL,
  suffix      TEXT NOT NULL,
  applied_at  DATETIME NOT NULL,
  ok          TINYINT(1) NOT NULL DEFAULT 1,
  message     VARCHAR(255) NULL,
  source      VARCHAR(10) NOT NULL DEFAULT 'cron',
  KEY idx_rot (rotator_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Account sharing (access for other tool users) =====
-- customer_id = '*' means all accounts of that Google account
CREATE TABLE IF NOT EXISTS account_shares (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner        VARCHAR(60) NOT NULL,
  conn_id      VARCHAR(32) NOT NULL,
  customer_id  VARCHAR(20) NOT NULL,
  account_name VARCHAR(255) NOT NULL DEFAULT '',
  shared_with  VARCHAR(60) NOT NULL,
  role         ENUM('view','edit') NOT NULL DEFAULT 'view',
  created_at   DATETIME NOT NULL,
  UNIQUE KEY uq_share (conn_id, customer_id, shared_with),
  KEY idx_with (shared_with)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Affiliate network connections (Impact / AWIN) - credentials encrypted =====
CREATE TABLE IF NOT EXISTS network_accounts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner       VARCHAR(60) NOT NULL,
  network     VARCHAR(20) NOT NULL,            -- impact | awin
  label       VARCHAR(120) NOT NULL DEFAULT '',
  creds       TEXT NOT NULL,                   -- AES-256-GCM encrypted JSON
  status      VARCHAR(20) NOT NULL DEFAULT 'ok',
  last_error  VARCHAR(255) NULL,
  last_sync   DATETIME NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_owner (owner, network)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== One-time login tokens (super-admin "login as user" / impersonation) =====
CREATE TABLE IF NOT EXISTS login_tokens (
  token       VARCHAR(64) PRIMARY KEY,
  username    VARCHAR(60) NOT NULL,          -- the user to log in as
  issued_by   VARCHAR(60) NOT NULL,          -- which super-admin created it
  expires_at  DATETIME NOT NULL,
  used        TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  KEY idx_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Campaign presets (reusable excluded locations + negative keywords) =====
CREATE TABLE IF NOT EXISTS campaign_presets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner       VARCHAR(60) NOT NULL,
  name        VARCHAR(120) NOT NULL,
  data        TEXT NOT NULL,                   -- JSON: {excluded:[{id,name,type}], negatives:""}
  created_at  DATETIME NOT NULL,
  KEY idx_owner (owner)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Affiliate conversions pulled from the networks (real revenue) =====
CREATE TABLE IF NOT EXISTS affiliate_conversions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner        VARCHAR(60) NOT NULL,
  network      VARCHAR(20) NOT NULL,
  txn_id       VARCHAR(80) NOT NULL,           -- network's transaction id (for dedup)
  click_id     VARCHAR(190) NOT NULL DEFAULT '', -- network's click / shared id (for drill-down)
  sub_id       VARCHAR(190) NOT NULL DEFAULT '',
  gclid        VARCHAR(190) NOT NULL DEFAULT '',
  campaign_id  VARCHAR(20) NOT NULL DEFAULT '',
  customer_id  VARCHAR(20) NOT NULL DEFAULT '',
  sale_amount  DECIMAL(14,2) NOT NULL DEFAULT 0,
  commission   DECIMAL(14,2) NOT NULL DEFAULT 0,
  currency     VARCHAR(8) NOT NULL DEFAULT '',
  status       VARCHAR(20) NOT NULL DEFAULT 'PENDING',  -- PENDING | APPROVED | REVERSED
  conv_date    DATE NOT NULL,
  updated_at   DATETIME NOT NULL,
  UNIQUE KEY uq_txn (owner, network, txn_id),
  KEY idx_owner_date (owner, conv_date),
  KEY idx_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
