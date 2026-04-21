-- ============================================================
-- Hive Music - Social Music Network
-- Database Schema v1.0
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `hivemusic` 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `hivemusic`;

-- ------------------------------------------------------------
-- TABLE: users
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(50)  NOT NULL UNIQUE,
  `email`         VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name`  VARCHAR(100) NOT NULL DEFAULT '',
  `bio`           TEXT,
  `avatar_url`    VARCHAR(500) DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_username` (`username`),
  INDEX `idx_email`    (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE: albums  (cache locale dei dati Spotify)
-- ------------------------------------------------------------
CREATE TABLE `albums` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `spotify_id`   VARCHAR(50)  NOT NULL UNIQUE,
  `title`        VARCHAR(255) NOT NULL,
  `artist`       VARCHAR(255) NOT NULL,
  `cover_url`    VARCHAR(500) DEFAULT NULL,
  `release_year` YEAR         DEFAULT NULL,
  `genre`        VARCHAR(100) DEFAULT NULL,
  `tracks_json`  JSON         DEFAULT NULL COMMENT 'Lista tracce serializzata',
  `cached_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_spotify_id` (`spotify_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE: reviews
-- ------------------------------------------------------------
CREATE TABLE `reviews` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`          INT UNSIGNED NOT NULL,
  `album_id`         INT UNSIGNED NOT NULL,
  `rating`           TINYINT UNSIGNED NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
  `body`             TEXT NOT NULL,
  `fav_tracks_json`  JSON DEFAULT NULL COMMENT 'Tracce preferite selezionate',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`album_id`) REFERENCES `albums`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_user_album` (`user_id`, `album_id`),
  INDEX `idx_user_id`    (`user_id`),
  INDEX `idx_album_id`   (`album_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE: drafts
-- ------------------------------------------------------------
CREATE TABLE `drafts` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`          INT UNSIGNED NOT NULL,
  `spotify_id`       VARCHAR(50)  DEFAULT NULL,
  `rating`           TINYINT UNSIGNED DEFAULT NULL,
  `body`             TEXT,
  `fav_tracks_json`  JSON DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE: comments
-- ------------------------------------------------------------
CREATE TABLE `comments` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `review_id`   INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `body`        TEXT NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`review_id`) REFERENCES `reviews`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)   ON DELETE CASCADE,
  INDEX `idx_review_id` (`review_id`),
  INDEX `idx_user_id`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE: likes  (like = 1, dislike = -1)
-- ------------------------------------------------------------
CREATE TABLE `likes` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `review_id`   INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `value`       TINYINT NOT NULL DEFAULT 1 COMMENT '1 = like, -1 = dislike',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`review_id`) REFERENCES `reviews`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)   ON DELETE CASCADE,
  UNIQUE KEY `uq_like` (`review_id`, `user_id`),
  INDEX `idx_review_id` (`review_id`),
  INDEX `idx_user_id`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE: followers
-- ------------------------------------------------------------
CREATE TABLE `followers` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `follower_id`  INT UNSIGNED NOT NULL COMMENT 'Chi segue',
  `following_id` INT UNSIGNED NOT NULL COMMENT 'Chi viene seguito',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`follower_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`following_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_follow` (`follower_id`, `following_id`),
  INDEX `idx_follower_id`  (`follower_id`),
  INDEX `idx_following_id` (`following_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLE: messages (chat privata)
-- ------------------------------------------------------------
CREATE TABLE `messages` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sender_id`   INT UNSIGNED NOT NULL,
  `receiver_id` INT UNSIGNED NOT NULL,
  `body`        TEXT NOT NULL,
  `read_at`     DATETIME DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_sender_id`   (`sender_id`),
  INDEX `idx_receiver_id` (`receiver_id`),
  INDEX `idx_conversation` (`sender_id`, `receiver_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
