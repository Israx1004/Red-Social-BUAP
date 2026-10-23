-- UniLink — tabla de mensajes directos (chat).
-- OPCIONAL: la aplicación la crea sola la primera vez que se usa el chat.
USE red_social;
CREATE TABLE IF NOT EXISTS messages (
  message_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id   INT UNSIGNED NOT NULL,
  receiver_id INT UNSIGNED NOT NULL,
  body        VARCHAR(1000) NOT NULL,
  context     VARCHAR(200) DEFAULT NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pair (sender_id, receiver_id, message_id),
  KEY idx_receiver (receiver_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
