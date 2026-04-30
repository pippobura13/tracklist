-- ============================================================
-- Hive Music - Schema Migration v2.0
-- Notifications system: like, comment, follow, review
-- ============================================================
-- Applicare DOPO lo schema v1.0 già esistente
-- ============================================================

USE `hivemusic`;

-- ------------------------------------------------------------
-- TABLE: notifications
-- ------------------------------------------------------------
-- Un record per ogni evento che deve avvisare un utente.
-- `read_at` NULL = non letta; valorizzato = letta.
-- `dismissed_at` NULL = non rimossa; valorizzato = azzerata dall'utente.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT UNSIGNED NOT NULL COMMENT 'Destinatario',
  `type`         ENUM('like','comment','follow','review') NOT NULL,
  `actor_id`     INT UNSIGNED NOT NULL COMMENT 'Chi ha generato l evento',
  `review_id`    INT UNSIGNED DEFAULT NULL COMMENT 'Recensione coinvolta (NULL per follow)',
  `read_at`      DATETIME DEFAULT NULL,
  `dismissed_at` DATETIME DEFAULT NULL COMMENT 'Azzera notifiche',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)   ON DELETE CASCADE,
  FOREIGN KEY (`actor_id`)  REFERENCES `users`(`id`)   ON DELETE CASCADE,
  FOREIGN KEY (`review_id`) REFERENCES `reviews`(`id`) ON DELETE CASCADE,

  -- Una sola notifica per coppia (tipo + attore + revisione) per evitare duplicati
  UNIQUE KEY `uq_notif` (`user_id`, `type`, `actor_id`, `review_id`),

  INDEX `idx_user_unread`  (`user_id`, `read_at`, `created_at`),
  INDEX `idx_created_at`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TRIGGERS
-- ============================================================
-- Ogni trigger crea la notifica solo se:
--   1. L'attore non è il destinatario (niente auto-notifiche)
--   2. La notifica non esiste già (INSERT IGNORE)
-- Per i trigger "review" la condizione aggiuntiva è:
--   3. Il follower seguiva l'autore PRIMA che la recensione fosse pubblicata
--      → fix "notifiche retroattive"
-- ============================================================

DELIMITER $$

-- ----------------------------------------------------------
-- TRIGGER: nuova RECENSIONE → notifica ai follower
-- FIX RETROATTIVO: f.created_at <= NEW.created_at
-- Così chi inizia a seguire dopo la pubblicazione NON riceve
-- notifiche per recensioni già esistenti.
-- ----------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS `trg_review_insert`
AFTER INSERT ON `reviews`
FOR EACH ROW
BEGIN
  INSERT IGNORE INTO `notifications`
    (`user_id`, `type`, `actor_id`, `review_id`, `created_at`)
  SELECT
    f.`follower_id`,
    'review',
    NEW.`user_id`,
    NEW.`id`,
    NEW.`created_at`
  FROM `followers` f
  WHERE f.`following_id`  = NEW.`user_id`
    AND f.`follower_id`  != NEW.`user_id`
    AND f.`created_at`   <= NEW.`created_at`; -- ← CUORE del fix retroattivo
END$$


-- ----------------------------------------------------------
-- TRIGGER: nuovo LIKE (value = 1) → notifica all'autore
-- Non notifica per i dislike (value = -1)
-- ----------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS `trg_like_insert`
AFTER INSERT ON `likes`
FOR EACH ROW
BEGIN
  DECLARE v_owner_id INT UNSIGNED;

  IF NEW.`value` = 1 THEN
    SELECT `user_id` INTO v_owner_id
    FROM `reviews`
    WHERE `id` = NEW.`review_id`;

    IF v_owner_id IS NOT NULL AND v_owner_id != NEW.`user_id` THEN
      INSERT IGNORE INTO `notifications`
        (`user_id`, `type`, `actor_id`, `review_id`)
      VALUES
        (v_owner_id, 'like', NEW.`user_id`, NEW.`review_id`);
    END IF;
  END IF;
END$$


-- ----------------------------------------------------------
-- TRIGGER: like aggiornato da dislike → like
-- (UPDATE su likes.value da -1 a 1)
-- ----------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS `trg_like_update`
AFTER UPDATE ON `likes`
FOR EACH ROW
BEGIN
  DECLARE v_owner_id INT UNSIGNED;

  IF OLD.`value` != 1 AND NEW.`value` = 1 THEN
    SELECT `user_id` INTO v_owner_id
    FROM `reviews`
    WHERE `id` = NEW.`review_id`;

    IF v_owner_id IS NOT NULL AND v_owner_id != NEW.`user_id` THEN
      INSERT IGNORE INTO `notifications`
        (`user_id`, `type`, `actor_id`, `review_id`)
      VALUES
        (v_owner_id, 'like', NEW.`user_id`, NEW.`review_id`)
      ON DUPLICATE KEY UPDATE `read_at` = NULL; -- riporta come non letta
    END IF;
  END IF;
END$$


-- ----------------------------------------------------------
-- TRIGGER: nuovo COMMENTO → notifica all'autore recensione
-- ----------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS `trg_comment_insert`
AFTER INSERT ON `comments`
FOR EACH ROW
BEGIN
  DECLARE v_owner_id INT UNSIGNED;

  SELECT `user_id` INTO v_owner_id
  FROM `reviews`
  WHERE `id` = NEW.`review_id`;

  IF v_owner_id IS NOT NULL AND v_owner_id != NEW.`user_id` THEN
    INSERT IGNORE INTO `notifications`
      (`user_id`, `type`, `actor_id`, `review_id`)
    VALUES
      (v_owner_id, 'comment', NEW.`user_id`, NEW.`review_id`);
  END IF;
END$$


-- ----------------------------------------------------------
-- TRIGGER: nuovo FOLLOWER → notifica all'utente seguito
-- ----------------------------------------------------------
CREATE TRIGGER IF NOT EXISTS `trg_follow_insert`
AFTER INSERT ON `followers`
FOR EACH ROW
BEGIN
  INSERT IGNORE INTO `notifications`
    (`user_id`, `type`, `actor_id`, `review_id`)
  VALUES
    (NEW.`following_id`, 'follow', NEW.`follower_id`, NULL);
END$$


DELIMITER ;


-- ============================================================
-- VIEW: notifiche non lette per utente (comoda per le API)
-- ============================================================
CREATE OR REPLACE VIEW `v_notifications` AS
SELECT
  n.`id`,
  n.`user_id`,
  n.`type`,
  n.`review_id`,
  n.`read_at`,
  n.`dismissed_at`,
  n.`created_at`,
  -- Attore
  a.`id`           AS `actor_id`,
  a.`username`     AS `actor_username`,
  a.`display_name` AS `actor_display_name`,
  a.`avatar_url`   AS `actor_avatar_url`,
  -- Album / recensione (nullable per tipo follow)
  al.`title`       AS `album_title`,
  al.`artist`      AS `album_artist`,
  al.`cover_url`   AS `cover_url`
FROM `notifications` n
JOIN `users`  a  ON a.`id`  = n.`actor_id`
LEFT JOIN `reviews` r  ON r.`id`  = n.`review_id`
LEFT JOIN `albums`  al ON al.`id` = r.`album_id`;


-- ============================================================
-- STORED PROCEDURE: GET notifiche attive per un utente
-- Usata dall'API endpoint GET /api/notifications
-- ============================================================
DROP PROCEDURE IF EXISTS `sp_get_notifications`;
DELIMITER $$
CREATE PROCEDURE `sp_get_notifications`(
  IN  p_user_id     INT UNSIGNED,
  IN  p_dismissed_after DATETIME,   -- filtra le notifiche azzerate (può essere NULL)
  IN  p_limit       INT
)
BEGIN
  SELECT *
  FROM `v_notifications`
  WHERE `user_id` = p_user_id
    AND `dismissed_at` IS NULL
    AND (p_dismissed_after IS NULL OR `created_at` > p_dismissed_after)
  ORDER BY `created_at` DESC
  LIMIT p_limit;
END$$
DELIMITER ;


-- ============================================================
-- STORED PROCEDURE: MARK READ (segna come lette)
-- Usata dall'API endpoint POST /api/notifications/read
-- ============================================================
DROP PROCEDURE IF EXISTS `sp_mark_notifications_read`;
DELIMITER $$
CREATE PROCEDURE `sp_mark_notifications_read`(IN p_user_id INT UNSIGNED)
BEGIN
  UPDATE `notifications`
  SET    `read_at` = NOW()
  WHERE  `user_id` = p_user_id
    AND  `read_at` IS NULL;
END$$
DELIMITER ;


-- ============================================================
-- STORED PROCEDURE: DISMISS ALL (azzera notifiche)
-- Usata dall'API endpoint POST /api/notifications/dismiss
-- ============================================================
DROP PROCEDURE IF EXISTS `sp_dismiss_notifications`;
DELIMITER $$
CREATE PROCEDURE `sp_dismiss_notifications`(IN p_user_id INT UNSIGNED)
BEGIN
  UPDATE `notifications`
  SET    `dismissed_at` = NOW(),
         `read_at`      = IFNULL(`read_at`, NOW())
  WHERE  `user_id` = p_user_id
    AND  `dismissed_at` IS NULL;
END$$
DELIMITER ;


-- ============================================================
-- STORED PROCEDURE: COUNT UNREAD (per il badge)
-- Usata dall'API endpoint GET /api/notifications/count
-- ============================================================
DROP PROCEDURE IF EXISTS `sp_count_unread_notifications`;
DELIMITER $$
CREATE PROCEDURE `sp_count_unread_notifications`(IN p_user_id INT UNSIGNED)
BEGIN
  SELECT COUNT(*) AS `unread_count`
  FROM `notifications`
  WHERE `user_id`      = p_user_id
    AND `read_at`      IS NULL
    AND `dismissed_at` IS NULL;
END$$
DELIMITER ;


-- ============================================================
-- PULIZIA AUTOMATICA: elimina notifiche più vecchie di 30 giorni
-- Da schedulare con MySQL Event Scheduler (o cron)
-- ============================================================
DROP EVENT IF EXISTS `evt_cleanup_old_notifications`;
DELIMITER $$
CREATE EVENT IF NOT EXISTS `evt_cleanup_old_notifications`
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
  DELETE FROM `notifications`
  WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);
END$$
DELIMITER ;

-- Attivare l'event scheduler se non è già attivo:
-- SET GLOBAL event_scheduler = ON;
