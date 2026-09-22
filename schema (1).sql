-- schema.sql — روی دیتابیس خالی‌ای که توی cPanel ساختی اجرا کن (phpMyAdmin > Import)

CREATE TABLE IF NOT EXISTS content_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shop VARCHAR(100) DEFAULT '',
  language VARCHAR(10) DEFAULT 'en',
  title VARCHAR(255) NOT NULL,
  tag VARCHAR(100) DEFAULT '',
  keyword VARCHAR(255) DEFAULT '',
  source_content TEXT,
  research_json TEXT,
  caption TEXT,
  hashtags TEXT,
  hero_image_path VARCHAR(255) DEFAULT NULL,
  status ENUM('draft','generated','review','approved','published','rejected','failed') NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  published_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- اگه از قبل جدول content_items رو ساخته بودی، این دو خط رو جدا اجرا کن تا ستون‌های جدید اضافه بشن:
-- ALTER TABLE content_items ADD COLUMN shop VARCHAR(100) DEFAULT '' AFTER id;
-- ALTER TABLE content_items ADD COLUMN language VARCHAR(10) DEFAULT 'en' AFTER shop;

CREATE TABLE IF NOT EXISTS content_platforms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  content_id INT NOT NULL,
  platform VARCHAR(30) NOT NULL,
  status ENUM('pending','published','failed') NOT NULL DEFAULT 'pending',
  published_at DATETIME NULL,
  error_text TEXT,
  UNIQUE KEY uniq_content_platform (content_id, platform),
  CONSTRAINT fk_content_platforms_item FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS publish_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  content_id INT,
  platform VARCHAR(30),
  success TINYINT(1) NOT NULL DEFAULT 0,
  message TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
