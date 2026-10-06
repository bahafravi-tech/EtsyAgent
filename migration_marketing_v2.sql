-- migration_marketing_v2.sql — یه‌بار از phpMyAdmin اجرا کن (بعد از schema_marketing.sql)
-- ثبت نتیجه‌ی تبلیغاتی که خودت اجرا می‌کنی (وضعیت / هزینه / نتیجه) — تا تحلیل بعدی بتونه ارزیابیش کنه.
-- اجرای دوباره بی‌خطره (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS marketing_ad_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  ad_index INT NOT NULL,
  status ENUM('not_started','running','done','skipped') NOT NULL DEFAULT 'not_started',
  spent_usd DECIMAL(10,2) NOT NULL DEFAULT 0,
  result_note TEXT,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_plan_ad (plan_id, ad_index),
  CONSTRAINT fk_ad_results_plan FOREIGN KEY (plan_id) REFERENCES marketing_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
