CREATE TABLE IF NOT EXISTS project_reminders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 period_number INTEGER NOT NULL,
 scheduled_for DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 UNIQUE (project_id, period_number),
 FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS reminder_deliveries (
 mail_id BIGINT UNSIGNED PRIMARY KEY,
 reminder_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY (mail_id) REFERENCES mail_outbox(id),
 FOREIGN KEY (reminder_id) REFERENCES project_reminders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
