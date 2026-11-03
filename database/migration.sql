-- Import into existing fr84tvk69sit_fawftracking schema; do NOT reimport original dump over production.
-- New tables only, original data remains unchanged.
CREATE TABLE IF NOT EXISTS task_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  work_id INT NULL,
  type ENUM('assigned','status','due_soon','overdue','comment') NOT NULL,
  message VARCHAR(500) NOT NULL,
  event_key VARCHAR(191) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_event (event_key),
  KEY idx_user_unread (user_id,is_read,created_at),
  CONSTRAINT fk_tn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tn_work FOREIGN KEY (work_id) REFERENCES works(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  work_id INT NOT NULL,
  user_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_task_time (work_id,created_at),
  CONSTRAINT fk_tc_work FOREIGN KEY (work_id) REFERENCES works(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
