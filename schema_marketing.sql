-- schema_marketing.sql — یه‌بار از phpMyAdmin (روی همون دیتابیسی که schema.sql رو ایمپورت کردی) اجرا کن
-- برای سیستم «بازاریابی خودکار»

ALTER TABLE content_platforms ADD COLUMN external_id VARCHAR(255) NULL AFTER platform;

ALTER TABLE content_items
  ADD COLUMN origin ENUM('manual','marketing') NOT NULL DEFAULT 'manual' AFTER status,
  ADD COLUMN plan_id INT NULL AFTER origin;

CREATE TABLE IF NOT EXISTS marketing_post_metrics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  content_id INT NULL,
  platform VARCHAR(30) NOT NULL,
  external_id VARCHAR(255) NULL,
  reach INT DEFAULT 0,
  views INT DEFAULT 0,
  likes INT DEFAULT 0,
  comments INT DEFAULT 0,
  saves INT DEFAULT 0,
  shares INT DEFAULT 0,
  clicks INT DEFAULT 0,
  raw_json TEXT,
  fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  -- کلید یکتا روی (platform, external_id) نه content_id، چون آمار لیستینگ‌های Etsy
  -- اصلاً به content_items وصل نیستن (content_id برای اونا NULL می‌مونه)
  UNIQUE KEY uniq_platform_external (platform, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_account_metrics (
  id INT AUTO_INCREMENT PRIMARY KEY,
  platform VARCHAR(30) NOT NULL,
  metric VARCHAR(50) NOT NULL,
  value INT DEFAULT 0,
  recorded_on DATE NOT NULL,
  UNIQUE KEY uniq_platform_metric_date (platform, metric, recorded_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  trigger_source ENUM('manual','cron') NOT NULL DEFAULT 'manual',
  period_days INT NOT NULL DEFAULT 30,
  summary TEXT,
  plan_json LONGTEXT,
  stats_json LONGTEXT,
  model VARCHAR(60),
  drafts_created INT DEFAULT 0,
  applied_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
