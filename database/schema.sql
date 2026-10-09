CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(100) NOT NULL UNIQUE,
 full_name VARCHAR(191) NOT NULL,
 email VARCHAR(191) NOT NULL UNIQUE,
 role VARCHAR(20) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 must_reset TINYINT(1) NOT NULL DEFAULT 1,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 session_version INTEGER NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE password_resets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash VARCHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE rate_limits (
 bucket VARCHAR(64) PRIMARY KEY,
 attempts INTEGER NOT NULL,
 expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE stakeholders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(191) NOT NULL,
 department VARCHAR(50) NOT NULL,
 email VARCHAR(191) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO stakeholders (full_name, department) VALUES ('Andrew Peters', 'Design'), ('Andrew Bates', 'Traffic Safety'), ('Jeremy Miller', 'Traffic Safety'), ('Jacob Mayich', 'Design');
CREATE TABLE projects (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 rcoc_number VARCHAR(100) NOT NULL UNIQUE,
 name VARCHAR(200) NOT NULL,
 needed_by DATE NOT NULL,
 bid_date DATE NOT NULL,
 design_exception TINYINT(1) NOT NULL,
 exception_details TEXT NOT NULL,
 project_type VARCHAR(50) NOT NULL,
 type_details TEXT NOT NULL,
 construction_year INTEGER NOT NULL,
 scope TEXT NOT NULL,
 limits_text TEXT NOT NULL,
 mdot_number VARCHAR(100) NOT NULL,
 safety_improvements TEXT NOT NULL,
 safety_details TEXT NOT NULL,
 requester_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(30) NOT NULL,
 folder_path VARCHAR(1000) NOT NULL DEFAULT '',
 version INTEGER NOT NULL DEFAULT 1,
 submission_token VARCHAR(64) NOT NULL UNIQUE,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 FOREIGN KEY (requester_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_projects_deadline ON projects(status, needed_by);
CREATE TABLE project_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(30) NOT NULL,
 from_status VARCHAR(30) NULL,
 to_status VARCHAR(30) NULL,
 changes_json TEXT NOT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (project_id) REFERENCES projects(id),
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_events_project ON project_events(project_id, id);
CREATE TABLE mail_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_id BIGINT UNSIGNED NULL,
 recipient VARCHAR(191) NOT NULL,
 recipient_name VARCHAR(191) NOT NULL,
 subject VARCHAR(255) NOT NULL,
 html_body TEXT NOT NULL,
 text_body TEXT NOT NULL,
 state VARCHAR(20) NOT NULL DEFAULT 'pending',
 attempts INTEGER NOT NULL DEFAULT 0,
 last_error VARCHAR(500) NULL,
 locked_at DATETIME NULL,
 sent_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 UNIQUE (event_id, recipient),
 FOREIGN KEY (event_id) REFERENCES project_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_mail_state ON mail_outbox(state, id);
