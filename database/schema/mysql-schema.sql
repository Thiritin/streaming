/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `chat_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_settings_key_unique` (`key`),
  KEY `chat_settings_key_index` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `emotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `emotes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `s3_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by_user_id` bigint unsigned NOT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT '0',
  `approved_by_user_id` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT '0',
  `usage_count` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `emotes_name_unique` (`name`),
  KEY `emotes_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  KEY `emotes_approved_by_user_id_foreign` (`approved_by_user_id`),
  KEY `emotes_is_approved_index` (`is_approved`),
  KEY `emotes_is_global_index` (`is_global`),
  KEY `emotes_is_approved_is_global_index` (`is_approved`,`is_global`),
  CONSTRAINT `emotes_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `emotes_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `message` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `priority` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `is_command` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `messages_user_id_foreign` (`user_id`),
  KEY `messages_type_index` (`type`),
  KEY `messages_deleted_by_user_id_foreign` (`deleted_by_user_id`),
  CONSTRAINT `messages_deleted_by_user_id_foreign` FOREIGN KEY (`deleted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_user` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `assigned_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_user_role_id_user_id_unique` (`role_id`,`user_id`),
  KEY `role_user_user_id_role_id_index` (`user_id`,`role_id`),
  KEY `role_user_assigned_by_user_id_foreign` (`assigned_by_user_id`),
  CONSTRAINT `role_user_assigned_by_user_id_foreign` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `role_user_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chat_color` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` int NOT NULL DEFAULT '0',
  `assigned_at_login` tinyint(1) NOT NULL DEFAULT '1',
  `is_visible` tinyint(1) NOT NULL DEFAULT '1',
  `permissions` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`),
  UNIQUE KEY `roles_slug_unique` (`slug`),
  KEY `roles_slug_index` (`slug`),
  KEY `roles_priority_index` (`priority`),
  KEY `roles_assigned_at_login_index` (`assigned_at_login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `servers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `servers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'edge',
  `hetzner_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shared_secret` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hls_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hostname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `port` int NOT NULL DEFAULT '8080',
  `ip` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `internal_ip` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'provisioning',
  `health_status` enum('healthy','unhealthy','unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `max_clients` int unsigned NOT NULL DEFAULT '100',
  `viewer_count` int NOT NULL DEFAULT '0',
  `last_heartbeat` timestamp NULL DEFAULT NULL,
  `last_health_check` timestamp NULL DEFAULT NULL,
  `health_check_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `immutable` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `servers_type_status_index` (`type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `show_statistics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `show_statistics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `show_id` bigint unsigned NOT NULL,
  `viewer_count` int NOT NULL,
  `unique_viewers` int NOT NULL DEFAULT '0',
  `recorded_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `viewer_statistics_show_id_recorded_at_index` (`show_id`,`recorded_at`),
  CONSTRAINT `viewer_statistics_show_id_foreign` FOREIGN KEY (`show_id`) REFERENCES `shows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `source_id` bigint unsigned NOT NULL,
  `scheduled_start` datetime NOT NULL,
  `scheduled_end` datetime NOT NULL,
  `actual_start` datetime DEFAULT NULL,
  `actual_end` datetime DEFAULT NULL,
  `status` enum('scheduled','live','ended','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `thumbnail_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_updated_at` timestamp NULL DEFAULT NULL,
  `thumbnail_capture_error` text COLLATE utf8mb4_unicode_ci,
  `viewer_count` int NOT NULL DEFAULT '0',
  `peak_viewer_count` int NOT NULL DEFAULT '0',
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `priority` int NOT NULL DEFAULT '0',
  `tags` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `server_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shows_slug_unique` (`slug`),
  KEY `shows_source_id_foreign` (`source_id`),
  KEY `shows_server_id_foreign` (`server_id`),
  KEY `shows_slug_index` (`slug`),
  KEY `shows_status_index` (`status`),
  KEY `shows_scheduled_start_index` (`scheduled_start`),
  KEY `shows_scheduled_end_index` (`scheduled_end`),
  KEY `shows_actual_start_index` (`actual_start`),
  KEY `shows_is_featured_index` (`is_featured`),
  KEY `shows_priority_index` (`priority`),
  CONSTRAINT `shows_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shows_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `source_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `source_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `joined_at` datetime NOT NULL,
  `left_at` datetime DEFAULT NULL,
  `last_heartbeat_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_users_source_id_user_id_joined_at_unique` (`source_id`,`user_id`,`joined_at`),
  KEY `source_users_user_id_foreign` (`user_id`),
  KEY `source_users_source_id_user_id_index` (`source_id`,`user_id`),
  KEY `source_users_last_heartbeat_at_index` (`last_heartbeat_at`),
  CONSTRAINT `source_users_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `source_users_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'offline',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `stream_key` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sources_slug_unique` (`slug`),
  KEY `sources_slug_index` (`slug`),
  KEY `sources_status_index` (`status`),
  CONSTRAINT `sources_chk_1` CHECK ((`status` in (_utf8mb4'online',_utf8mb4'offline',_utf8mb4'error')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `sent_by_user_id` bigint unsigned DEFAULT NULL,
  `priority` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_messages_sent_by_user_id_foreign` (`sent_by_user_id`),
  KEY `system_messages_type_created_at_index` (`type`,`created_at`),
  KEY `system_messages_priority_index` (`priority`),
  CONSTRAINT `system_messages_sent_by_user_id_foreign` FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `timeouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `timeouts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `issued_by_user_id` bigint unsigned NOT NULL,
  `expires_at` datetime NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `timeouts_issued_by_user_id_foreign` (`issued_by_user_id`),
  KEY `timeouts_user_id_expires_at_index` (`user_id`,`expires_at`),
  CONSTRAINT `timeouts_issued_by_user_id_foreign` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `timeouts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_emote_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_emote_favorites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `emote_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_emote_favorites_user_id_emote_id_unique` (`user_id`,`emote_id`),
  KEY `user_emote_favorites_emote_id_foreign` (`emote_id`),
  KEY `user_emote_favorites_user_id_index` (`user_id`),
  CONSTRAINT `user_emote_favorites_emote_id_foreign` FOREIGN KEY (`emote_id`) REFERENCES `emotes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_emote_favorites_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint unsigned DEFAULT NULL,
  `sub` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reg_id` int unsigned DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `streamkey` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_sub_unique` (`sub`),
  KEY `users_server_id_foreign` (`server_id`),
  CONSTRAINT `users_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `view_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `view_counts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `count` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2014_10_12_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2014_10_12_100000_create_password_reset_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2019_08_19_000000_create_failed_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2023_07_13_030421_create_servers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2023_07_13_032042_server_user',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2023_07_16_153410_create_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2023_07_16_154035_remove_field_server_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2023_07_16_160922_add_deletion_override_to_servers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2023_07_16_185539_add_shared_secret_to_servers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2023_07_16_205037_set_fields_in_servers_table_as_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2023_07_16_220130_add_type_to_servers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2023_07_16_221059_add_internal_ip_to_servers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2023_07_22_172659_add_is_attendee_field_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2023_07_22_231003_create_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2023_07_23_042926_add_timeout_expires_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2023_08_28_011205_add_level_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2023_08_30_235607_create_view_counts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2023_08_31_051525_remove_start_and_stop_from_server_user',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2023_08_31_052141_add_server_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2023_08_31_052337_clients_remove_fk_to_server_user_and_change_it_to_servers_on_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2023_08_31_052434_delete_server_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2023_08_31_055110_add_user_id_to_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2025_08_29_083909_create_sources_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2025_08_29_083916_create_shows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2025_08_29_083949_create_show_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2025_08_29_085723_create_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2025_08_29_085731_create_role_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2025_08_29_095525_update_sources_table_for_hls',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2025_08_29_095739_add_priority_to_shows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2025_08_29_102032_add_thumbnail_fields_to_shows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2025_08_29_141035_update_source_stream_key_to_encrypted',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2025_08_29_170705_simplify_role_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2025_08_29_171750_remove_is_staff_from_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2025_08_29_193108_make_stream_key_nullable_in_sources_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2025_08_29_235825_add_port_to_servers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2025_08_30_003027_remove_location_from_sources_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2025_08_30_031133_remove_priority_from_sources_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2025_08_30_051728_create_source_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2025_08_30_051741_drop_show_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2025_08_30_055139_add_status_to_sources_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2025_08_30_055521_create_emotes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2025_08_30_055631_create_user_emote_favorites_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2025_08_30_145103_rename_thumbnail_url_to_thumbnail_path_in_shows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2025_08_30_153138_create_timeouts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2025_08_30_153154_create_chat_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2025_08_30_153213_create_system_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2025_08_30_155138_remove_unused_columns_from_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2025_08_30_163337_remove_unnecessary_fields_from_sources_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2025_08_30_164024_update_servers_for_origin_edge_architecture',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2025_08_30_164306_create_viewer_statistics_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2025_08_30_172431_drop_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2025_08_30_175447_drop_user_badges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2025_08_30_175516_remove_badge_type_from_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2025_08_30_180818_update_servers_table_nullable_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2025_08_31_055126_drop_model_has_permissions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2025_08_31_055157_drop_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2025_08_31_055229_drop_permissions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2025_08_31_055543_rename_viewer_statistics_to_show_statistics',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2025_08_31_141627_add_type_and_priority_to_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2025_08_31_213554_drop_origin_url_from_servers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2025_08_31_221621_add_deleted_by_user_id_to_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2025_08_31_add_error_status_to_sources_table',1);
