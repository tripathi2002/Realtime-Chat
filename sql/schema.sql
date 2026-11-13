-- Run once in Hostinger phpMyAdmin (or MySQL) for the realtime-chat DB.

CREATE TABLE IF NOT EXISTS chat_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token CHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_chat_users_token (token),
  KEY idx_chat_users_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type ENUM('join', 'message', 'leave') NOT NULL,
  user_name VARCHAR(100) NOT NULL,
  message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_events_id (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
