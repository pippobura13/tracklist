-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Creato il: Mag 06, 2026 alle 16:44
-- Versione del server: 10.4.32-MariaDB
-- Versione PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hivemusic`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `albums`
--

CREATE TABLE `albums` (
  `id` int(10) UNSIGNED NOT NULL,
  `spotify_id` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `artist` varchar(255) NOT NULL,
  `cover_url` varchar(500) DEFAULT NULL,
  `release_year` year(4) DEFAULT NULL,
  `genre` varchar(100) DEFAULT NULL,
  `tracks_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Lista tracce serializzata' CHECK (json_valid(`tracks_json`)),
  `cached_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `albums`
--

INSERT INTO `albums` (`id`, `spotify_id`, `title`, `artist`, `cover_url`, `release_year`, `genre`, `tracks_json`, `cached_at`) VALUES
(1, '35UJLpClj5EDrhpNIi4DFg', 'The Bends', 'Radiohead', 'https://i.scdn.co/image/ab67616d0000b2739293c743fa542094336c5e12', '1995', NULL, '[\"Planet Telex\",\"The Bends\",\"High and Dry\",\"Fake Plastic Trees\",\"Bones\",\"(Nice Dream)\",\"Just\",\"My Iron Lung\",\"Bullet Proof ... I Wish I Was\",\"Black Star\",\"Sulk\",\"Street Spirit (Fade Out)\"]', '2026-05-06 16:38:30'),
(2, '78iX7tMceN0FsnmabAtlOC', 'All Eyez On Me', '2Pac', 'https://i.scdn.co/image/ab67616d0000b273073aebff28f79959d2543596', '1996', NULL, '[\"Ambitionz Az A Ridah\",\"All About U (ft. Nate Dogg, Snoop Dogg, Fatal, Yani Hadati)\",\"Skandalouz (ft. Nate Dogg)\",\"Got My Mind Made Up (ft. Dat Nigga Daz, Kurupt, Method Man, Redman)\",\"How Do U Want It (ft. K-Ci & JoJo)\",\"2 Of Amerikaz Most Wanted (ft. Snoop Doggy Dogg)\",\"No More Pain\",\"Heartz Of Men\",\"Life Goes On\",\"Only God Can Judge Me (ft. Rappin\' 4-Tay)\",\"Tradin\' War Stories (ft. C-BO, CPO, Outlawz, The Storm)\",\"California Love (remix) (ft. Dr. Dre, Roger Troutman) - Remix\",\"I Ain\'t Mad At Cha (ft. Danny Boy)\",\"What\'s Ya Phone # (ft. Danny Boy)\",\"Can\'t C Me\",\"Shorty Wanna Be A Thug\",\"Holla At Me\",\"Wonda Why They Call U Bytch\",\"When We Ride (ft. Outlaw Immortals)\",\"Thug Passion (ft. Jewell, Outlawz, The Storm)\",\"Picture Me Rollin\' (ft. Danny Boy, CPO, Big Syke)\",\"Check Out Time\",\"Ratha Be Ya Nigga (ft. Richie Rich)\",\"All Eyez On Me (ft. Big Syke)\",\"Run Tha Streetz (ft. Michel\'le, Storm, Mutah)\",\"Ain\'t Hard 2 Find (ft. B-Legit, C-BO, E-40, Richie Rich)\",\"Heaven Ain\'t Hard 2 Find\"]', '2026-05-06 16:40:39');

-- --------------------------------------------------------

--
-- Struttura della tabella `comments`
--

CREATE TABLE `comments` (
  `id` int(10) UNSIGNED NOT NULL,
  `review_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `drafts`
--

CREATE TABLE `drafts` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `spotify_id` varchar(50) DEFAULT NULL,
  `rating` tinyint(3) UNSIGNED DEFAULT NULL,
  `body` text DEFAULT NULL,
  `fav_tracks_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fav_tracks_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `followers`
--

CREATE TABLE `followers` (
  `id` int(10) UNSIGNED NOT NULL,
  `follower_id` int(10) UNSIGNED NOT NULL COMMENT 'Chi segue',
  `following_id` int(10) UNSIGNED NOT NULL COMMENT 'Chi viene seguito',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `followers`
--

INSERT INTO `followers` (`id`, `follower_id`, `following_id`, `created_at`) VALUES
(1, 1, 2, '2026-05-06 16:42:35'),
(2, 2, 1, '2026-05-06 16:42:56');

-- --------------------------------------------------------

--
-- Struttura della tabella `likes`
--

CREATE TABLE `likes` (
  `id` int(10) UNSIGNED NOT NULL,
  `review_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `value` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1 = like, -1 = dislike',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `messages`
--

CREATE TABLE `messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `receiver_id` int(10) UNSIGNED NOT NULL,
  `body` text NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `reviews`
--

CREATE TABLE `reviews` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `album_id` int(10) UNSIGNED NOT NULL,
  `rating` tinyint(3) UNSIGNED NOT NULL CHECK (`rating` between 1 and 5),
  `body` text NOT NULL,
  `fav_tracks_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Tracce preferite selezionate' CHECK (json_valid(`fav_tracks_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `reviews`
--

INSERT INTO `reviews` (`id`, `user_id`, `album_id`, `rating`, `body`, `fav_tracks_json`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 5, 'Davvero bellissimo, uno dei miei album preferiti', '[\"The Bends\",\"(Nice Dream)\",\"Black Star\"]', '2026-05-06 16:38:30', '2026-05-06 16:38:30'),
(2, 2, 2, 5, 'Ultimo capolavoro di una leggenda', '[\"Ambitionz Az A Ridah\",\"All Eyez On Me (ft. Big Syke)\"]', '2026-05-06 16:40:39', '2026-05-06 16:40:39');

-- --------------------------------------------------------

--
-- Struttura della tabella `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(100) NOT NULL DEFAULT '',
  `bio` text DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `display_name`, `bio`, `avatar_url`, `created_at`, `updated_at`) VALUES
(1, 'pippobura', 'filippoburato07@gmail.com', '$2y$10$NhLJcEVONYTaaEGMCV6lAuCuyCHfeyux9QBTmGAvSVyWRfpaGsBj2', 'Filippo Burato', NULL, 'uploads/avatars/1_1778078541.jpg?t=1778078541581', '2026-05-06 16:37:20', '2026-05-06 16:42:26'),
(2, 'atic', 'alessandromodolo@gmail.com', '$2y$10$AE2geAKwsrWjf9gQLwAdnetWd7yBiDP4.m1.KphDWgUsw0Yxbe/52', 'Alessandro Modolo', NULL, 'uploads/avatars/2_1778078522.jpg?t=1778078522276', '2026-05-06 16:39:49', '2026-05-06 16:42:03');

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `albums`
--
ALTER TABLE `albums`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `spotify_id` (`spotify_id`),
  ADD KEY `idx_spotify_id` (`spotify_id`);

--
-- Indici per le tabelle `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_review_id` (`review_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indici per le tabelle `drafts`
--
ALTER TABLE `drafts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indici per le tabelle `followers`
--
ALTER TABLE `followers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_follow` (`follower_id`,`following_id`),
  ADD KEY `idx_follower_id` (`follower_id`),
  ADD KEY `idx_following_id` (`following_id`);

--
-- Indici per le tabelle `likes`
--
ALTER TABLE `likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_like` (`review_id`,`user_id`),
  ADD KEY `idx_review_id` (`review_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indici per le tabelle `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sender_id` (`sender_id`),
  ADD KEY `idx_receiver_id` (`receiver_id`),
  ADD KEY `idx_conversation` (`sender_id`,`receiver_id`,`created_at`);

--
-- Indici per le tabelle `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_album` (`user_id`,`album_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_album_id` (`album_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indici per le tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_email` (`email`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `albums`
--
ALTER TABLE `albums`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT per la tabella `comments`
--
ALTER TABLE `comments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `drafts`
--
ALTER TABLE `drafts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `followers`
--
ALTER TABLE `followers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT per la tabella `likes`
--
ALTER TABLE `likes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT per la tabella `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `drafts`
--
ALTER TABLE `drafts`
  ADD CONSTRAINT `drafts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `followers`
--
ALTER TABLE `followers`
  ADD CONSTRAINT `followers_ibfk_1` FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `followers_ibfk_2` FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `likes`
--
ALTER TABLE `likes`
  ADD CONSTRAINT `likes_ibfk_1` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `likes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Limiti per la tabella `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`album_id`) REFERENCES `albums` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
