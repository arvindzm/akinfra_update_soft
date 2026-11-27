-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: akinfralead
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `ad_campaigns`
--

DROP TABLE IF EXISTS `ad_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ad_campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_name` varchar(150) NOT NULL,
  `platform` enum('meta','google','other') NOT NULL DEFAULT 'meta',
  `meta_campaign_id` varchar(100) DEFAULT NULL,
  `meta_ad_id` varchar(100) DEFAULT NULL,
  `form_id` varchar(100) DEFAULT NULL,
  `project_name` varchar(100) NOT NULL,
  `daily_budget` decimal(10,2) DEFAULT 0.00,
  `total_spend` decimal(10,2) DEFAULT 0.00,
  `status` enum('Active','Paused','Completed','Draft') NOT NULL DEFAULT 'Active',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `target_audience` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ad_campaigns`
--

LOCK TABLES `ad_campaigns` WRITE;
/*!40000 ALTER TABLE `ad_campaigns` DISABLE KEYS */;
INSERT INTO `ad_campaigns` VALUES (1,'Emerald Park Signature Greens - Meta Lead Ads','meta',NULL,NULL,NULL,'Emerald Park Signature Greens',500.00,0.00,'Active','2026-09-22',NULL,NULL,NULL,NULL,'2026-09-22 11:59:32','2026-09-23 05:39:02'),(2,'Green Valley Eco Villas - Meta Lead Ads','meta',NULL,NULL,NULL,'Green Valley Eco Villas',500.00,0.00,'Active','2026-09-22',NULL,NULL,NULL,NULL,'2026-09-22 11:59:32','2026-09-22 11:59:32'),(3,'Royal Imperial Bayfront - Meta Lead Ads','meta',NULL,NULL,NULL,'Royal Imperial Bayfront',500.00,0.00,'Active','2026-09-22',NULL,NULL,NULL,NULL,'2026-09-22 11:59:32','2026-09-22 11:59:32'),(4,'Skyline Heights Luxury Towers - Meta Lead Ads','meta',NULL,NULL,NULL,'Skyline Heights Luxury Towers',500.00,0.00,'Active','2026-09-22',NULL,NULL,NULL,NULL,'2026-09-22 11:59:32','2026-09-22 11:59:32'),(5,'The Grand Palm Residences - Meta Lead Ads','meta',NULL,NULL,NULL,'The Grand Palm Residences',500.00,0.00,'Active','2026-09-22',NULL,NULL,NULL,NULL,'2026-09-22 11:59:32','2026-09-22 11:59:32');
/*!40000 ALTER TABLE `ad_campaigns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_settings`
--

DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_settings`
--

LOCK TABLES `app_settings` WRITE;
/*!40000 ALTER TABLE `app_settings` DISABLE KEYS */;
INSERT INTO `app_settings` VALUES (1,'net_erp_webhook_url','https://akinfrarealestate.auctechitsolutions.in/api/crm_lead_receive','2026-09-22 07:59:23'),(2,'net_erp_api_key','AK_INFRA_NET_ERP_SECRET_TOKEN_2026','2026-09-22 07:59:23'),(3,'net_erp_auto_sync','1','2026-09-22 07:59:23'),(4,'crm_api_inbound_key','AK_CRM_INBOUND_API_KEY_SECURE_999','2026-09-22 07:59:23');
/*!40000 ALTER TABLE `app_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `call_recordings`
--

DROP TABLE IF EXISTS `call_recordings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `call_recordings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `call_type` varchar(20) DEFAULT 'Outgoing',
  `duration_seconds` int(11) DEFAULT 0,
  `audio_file` varchar(255) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `call_recordings`
--

LOCK TABLES `call_recordings` WRITE;
/*!40000 ALTER TABLE `call_recordings` DISABLE KEYS */;
INSERT INTO `call_recordings` VALUES (1,1,1,'9876543210','Outgoing',2,'call_1_20260811_092747_975.ogg','call note','2026-08-11 12:57:47');
/*!40000 ALTER TABLE `call_recordings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lead_notes`
--

DROP TABLE IF EXISTS `lead_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lead_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `note` text NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `lead_notes_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_notes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lead_notes`
--

LOCK TABLES `lead_notes` WRITE;
/*!40000 ALTER TABLE `lead_notes` DISABLE KEYS */;
INSERT INTO `lead_notes` VALUES (1,1,1,'📞 <strong>Call Recorded</strong> (Outgoing, Duration: 2s)<br><em>Notes: call note</em>','2026-08-11 07:27:47'),(2,1,1,'📞 <strong>Call Recorded</strong> (Outgoing, Duration: 2s)<br><em>Notes: call note</em>','2026-08-11 07:28:02'),(3,1,1,'👑 ADMIN INSTRUCTION: hello','2026-08-11 07:40:00'),(4,1,1,'👑 ADMIN INSTRUCTION: hello','2026-08-11 07:40:11'),(5,1,1,'👑 ADMIN INSTRUCTION: hello','2026-08-11 07:40:23'),(6,1,2,'hello','2026-08-11 07:44:02'),(7,1,2,'Status updated to [Site Visit Scheduled] | Site Visit Scheduled for: 12 Aug 2026 01:14 PM [Transport: Self Visit / Direct Site]\nNote: site visit done hai','2026-08-11 07:44:31'),(8,2,2,'hii','2026-08-11 07:45:09'),(9,1,1,'👑 ADMIN INSTRUCTION: hi','2026-08-11 07:45:40'),(10,1,1,'👑 ADMIN INSTRUCTION: 25% of site visti','2026-08-11 07:46:14'),(11,1,2,'hello','2026-08-11 07:49:50'),(12,2,1,'hello','2026-08-11 07:57:35'),(13,3,2,'Status updated to [In Progress] directly via dropdown','2026-08-31 05:35:03'),(14,1,2,'Status updated to [Site Visit Scheduled] directly via dropdown | Scheduled for: 12 Aug 2026 01:14 PM [Transport: Self Visit / Direct Site]','2026-08-31 05:35:08'),(15,3,1,'Status updated to [Follow Up] directly via dropdown','2026-08-31 06:06:33'),(16,3,1,'Status updated to [Site Visit Scheduled] directly via dropdown','2026-08-31 06:06:36'),(17,3,1,'Status updated to [Site Visit Done] directly via dropdown | Site Visit Completed!','2026-08-31 06:06:38'),(18,3,1,'Status updated to [Assigned to Sales] directly via dropdown','2026-08-31 06:06:41'),(19,3,1,'Status updated to [Converted] directly via dropdown','2026-08-31 06:06:45'),(20,3,1,'Status updated to [Dead] directly via dropdown','2026-08-31 06:06:48'),(21,3,1,'Status updated to [New] directly via dropdown','2026-08-31 06:06:51'),(22,2,1,'Status updated to [Site Visit Scheduled] directly via dropdown','2026-08-31 06:07:00'),(23,1,2,'Status updated to [In Progress] directly via dropdown','2026-08-31 06:21:40'),(24,1,2,'Status updated to [Site Visit Done] directly via dropdown | Site Visit Completed!','2026-08-31 06:21:42'),(25,1,2,'Status updated to [Site Visit Scheduled] directly via dropdown | Scheduled for: 12 Aug 2026 01:14 PM [Transport: Self Visit / Direct Site]','2026-08-31 06:21:44'),(26,1,2,'Status updated to [Converted] directly via dropdown','2026-08-31 06:21:50'),(27,1,2,'Status updated to [Negotiation] directly via dropdown','2026-08-31 06:21:52'),(28,4,2,'Client is looking for immediate 200 sq.yd residential plot. Budget approx 45L. Scheduled callback for today afternoon.','2026-08-31 07:08:07'),(29,5,2,'Confirmed Site Visit for tomorrow 11:30 AM with family. Requested car pickup from metro station.','2026-08-31 07:08:07'),(30,6,2,'Shared brochure on WhatsApp. Client interested in 3BHK corner unit.','2026-08-31 07:08:07'),(31,7,2,'Commercial investor looking for rental yield. Wants detailed ROI breakdown.','2026-08-31 07:08:07'),(32,8,2,'Need bank loan eligibility check with SBI/HDFC before site visit.','2026-08-31 07:08:07'),(33,9,2,'Called twice, line busy. Sent greeting message on WhatsApp.','2026-08-31 07:08:07'),(34,10,2,'Site visit completed yesterday with sales team. Client liked the park facing villa. Discussing payment plan.','2026-08-31 07:08:07'),(35,11,2,'Final price negotiation going on. Expected booking token this weekend.','2026-08-31 07:08:07'),(36,12,2,'Fresh lead received from FB campaign.','2026-08-31 07:08:07'),(37,13,2,'Inquiry for ground floor retail showroom space.','2026-08-31 07:08:07'),(38,14,2,'Customer requested video walkthrough of the site development.','2026-08-31 07:08:07'),(39,15,2,'Deal closed successfully! Booking amount received 2,00,000 INR.','2026-08-31 07:08:07'),(40,16,2,'Client looking for resale property only, out of our project scope.','2026-08-31 07:08:07'),(41,17,3,'Assigned to Pooja Sharma. Customer wants east facing plot only.','2026-08-31 07:08:07'),(42,18,3,'Fresh inquiry from official website contact form.','2026-08-31 07:08:07'),(43,19,3,'Doctor looking for clinic setup space with parking facility.','2026-08-31 07:08:07'),(44,20,3,'Site visit booked for Sunday morning.','2026-08-31 07:08:07'),(45,21,2,'Callback scheduled regarding payment installment milestones.','2026-08-31 07:08:07'),(46,22,2,'Looking for corner plot near main clubhouse.','2026-08-31 07:08:07'),(47,23,2,'High intent buyer. Wants quotation with registry and clubhouse charges.','2026-08-31 07:08:07'),(48,85,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:15:54'),(49,84,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:15:59'),(50,83,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:04'),(51,82,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:19'),(52,81,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:23'),(53,80,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:28'),(54,79,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:33'),(55,78,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:38'),(56,77,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:42'),(57,76,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:47'),(58,75,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:52'),(59,74,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:16:56'),(60,73,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:01'),(61,72,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:06'),(62,71,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:10'),(63,70,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:15'),(64,69,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:20'),(65,68,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:25'),(66,67,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:32'),(67,66,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:36'),(68,65,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:41'),(69,64,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:46'),(70,63,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:50'),(71,62,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:17:55'),(72,61,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:00'),(73,60,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:04'),(74,59,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:09'),(75,58,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:14'),(76,57,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:18'),(77,56,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:23'),(78,55,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:27'),(79,54,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:32'),(80,53,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:36'),(81,52,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:41'),(82,51,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:46'),(83,50,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:50'),(84,49,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:55'),(85,48,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:18:59'),(86,47,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:04'),(87,46,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:08'),(88,45,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:13'),(89,44,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:17'),(90,43,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:22'),(91,42,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:26'),(92,41,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:31'),(93,40,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:36'),(94,39,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:40'),(95,38,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:45'),(96,37,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:49'),(97,36,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:54'),(98,35,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:19:59'),(99,34,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:03'),(100,33,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:08'),(101,32,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:13'),(102,31,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:17'),(103,30,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:22'),(104,29,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:26'),(105,28,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:32'),(106,27,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:37'),(107,26,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:42'),(108,25,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:46'),(109,24,2,'⏰ Auto-Reassigned (24h Inactivity): Lead was untouched for 24+ hours by previous telecaller and has been reassigned to keep workflow active.','2026-08-31 11:20:51'),(110,5,4,'Status updated to [Site Visit Done] directly via dropdown | Site Visit Completed!','2026-08-31 11:45:25'),(111,5,4,'Status updated to [Converted] directly via dropdown | Unit: Villa A-12','2026-08-31 11:45:30'),(112,85,1,'📝 Updated Registry Status: Cancelled','2026-09-21 10:40:23'),(113,85,1,'📝 Updated Registry Status: Pending','2026-09-21 10:40:26'),(114,85,1,'🎯 ASSIGNED TO SALES: Lead forwarded to Sales Person [Rahul Verma] by Admin for closing/site visit.\n📝 Admin Briefing: Test Admin Instruction: VIP Client for 3BHK','2026-09-21 11:58:10'),(115,85,4,'📞 SALES CALL / DISCUSSION by Rahul Verma: Spoke with client. Highly interested, budget 60L. Wants site visit tomorrow morning. (Next Followup: 22 Sep 2026, 10:00 AM)','2026-09-21 11:58:15'),(116,85,4,'🚗 SITE VISIT SCHEDULED for 22 Sep 2026, 11:00 AM [Transport: Pickup Required (Cab/Car)] at Location: DGC-I Green Valley | Notes: Arrange cab at Noida Sec 18 metro station','2026-09-21 11:58:15'),(117,85,4,'🏠 SITE VISIT COMPLETED by Rahul Verma. Feedback: Highly Interested (Ready for Token) | Unit/Plot Shown: Plot #A-108 | Quoted Price: ₹5,200,000.00','2026-09-21 11:58:15'),(118,85,4,'💰 BOOKING TOKEN RECEIVED: ₹51,000.00 on 21 Sep 2026 | Unit/Plot: Plot #A-108 | Total Deal Value: ₹5,200,000.00 | Notes: Received ₹51,000 via UPI (Txn: 9876543210)','2026-09-21 11:58:15'),(122,85,1,'🌐 .NET REAL ESTATE ERP SYNC: Lead successfully synchronized to .NET ERP [ID: ERP-CUST-00085]. Ready for Plot Allotment.','2026-09-22 08:17:38'),(123,85,1,'🌐 .NET REAL ESTATE ERP SYNC: Lead successfully synchronized to .NET ERP [ID: ERP-CUST-00085]. Ready for Plot Allotment.','2026-09-22 09:40:13'),(124,85,4,'🌐 .NET REAL ESTATE ERP SYNC: Lead successfully synchronized to .NET ERP [ID: ERP-CUST-00085]. Ready for Plot Allotment.','2026-09-22 09:42:20'),(125,85,4,'🌐 .NET REAL ESTATE ERP SYNC: Lead successfully synchronized to .NET ERP [ID: ERP-CUST-00085]. Ready for Plot Allotment.','2026-09-22 10:49:35'),(126,5,4,'🌐 .NET REAL ESTATE ERP SYNC: Lead successfully synchronized to .NET ERP [ID: ERP-CUST-00005]. Ready for Plot Allotment.','2026-09-22 10:49:43'),(127,22,2,'🌐 .NET REAL ESTATE ERP SYNC: Lead successfully synchronized to .NET ERP [ID: ERP-CUST-00022]. Ready for Plot Allotment.','2026-09-23 07:20:29');
/*!40000 ALTER TABLE `lead_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leads`
--

DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_code` varchar(50) DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'manual',
  `project` varchar(100) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `status` varchar(100) NOT NULL DEFAULT 'New',
  `os_sub_reason` varchar(255) DEFAULT NULL,
  `follow_up_at` datetime DEFAULT NULL,
  `site_visit_at` datetime DEFAULT NULL,
  `site_visit_status` varchar(50) DEFAULT 'None',
  `site_visit_pickup` varchar(100) DEFAULT 'Self Visit',
  `site_visit_feedback` varchar(255) DEFAULT NULL,
  `deal_value` decimal(10,2) DEFAULT NULL,
  `booking_amount` decimal(10,2) DEFAULT NULL,
  `unit_number` varchar(100) DEFAULT NULL,
  `dead_reason` varchar(255) DEFAULT NULL,
  `telecaller_id` int(11) DEFAULT NULL,
  `salesperson_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `followup_date` datetime DEFAULT NULL,
  `priority` enum('Hot','Warm','Cold') DEFAULT 'Warm',
  `campaign_number` varchar(100) DEFAULT NULL,
  `influencer` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `site_location` varchar(150) DEFAULT NULL,
  `budget` varchar(100) DEFAULT NULL,
  `remark_1` text DEFAULT NULL,
  `followup_1` datetime DEFAULT NULL,
  `remark_2` text DEFAULT NULL,
  `followup_2` datetime DEFAULT NULL,
  `remark_3` text DEFAULT NULL,
  `visit_status` varchar(50) DEFAULT 'No',
  `visit_date` datetime DEFAULT NULL,
  `visit_done` varchar(20) DEFAULT 'No',
  `visit_remark` text DEFAULT NULL,
  `booking_date` date DEFAULT NULL,
  `registry_name` varchar(150) DEFAULT NULL,
  `registry_unit_number` varchar(100) DEFAULT NULL,
  `registry_area` varchar(100) DEFAULT NULL,
  `registry_date` date DEFAULT NULL,
  `registry_status` varchar(50) DEFAULT 'Pending',
  `sales_assigned_at` datetime DEFAULT NULL,
  `sales_assigned_by` int(11) DEFAULT NULL,
  `sales_notes` text DEFAULT NULL,
  `sales_pipeline_stage` varchar(50) DEFAULT NULL,
  `erp_synced` tinyint(1) DEFAULT 0,
  `erp_synced_at` datetime DEFAULT NULL,
  `erp_customer_id` varchar(100) DEFAULT NULL,
  `erp_booking_id` varchar(100) DEFAULT NULL,
  `erp_sync_status` varchar(50) DEFAULT 'Pending',
  `erp_sync_response` text DEFAULT NULL,
  `meta_ad_id` varchar(100) DEFAULT NULL,
  `meta_form_id` varchar(100) DEFAULT NULL,
  `meta_campaign_name` varchar(150) DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `ad_name` varchar(150) DEFAULT NULL,
  `lead_type` varchar(20) DEFAULT NULL,
  `sales_manager_id` int(11) DEFAULT NULL,
  `sales_manager_assigned_at` datetime DEFAULT NULL,
  `sales_manager_note` text DEFAULT NULL,
  `sales_executive_assigned_at` datetime DEFAULT NULL,
  `site_visit_attended_by` int(11) DEFAULT NULL,
  `upcoming_follow_up` datetime DEFAULT NULL,
  `team_head` varchar(150) DEFAULT NULL,
  `manager_name` varchar(150) DEFAULT NULL,
  `visit_done_by` varchar(150) DEFAULT NULL,
  `happy_calling_date` date DEFAULT NULL,
  `happy_calling_status` varchar(100) DEFAULT NULL,
  `happy_calling_remark` text DEFAULT NULL,
  `final_status_manager` varchar(100) DEFAULT NULL,
  `final_status_date_manager` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `telecaller_id` (`telecaller_id`),
  KEY `salesperson_id` (`salesperson_id`),
  KEY `idx_leads_phone` (`phone`),
  KEY `idx_leads_created_at` (`created_at`),
  KEY `idx_leads_status` (`status`),
  KEY `idx_leads_lead_code` (`lead_code`),
  CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`telecaller_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_ibfk_2` FOREIGN KEY (`salesperson_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shuffled_leads_history`
--

CREATE TABLE IF NOT EXISTS `shuffled_leads_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `from_telecaller_id` int(11) DEFAULT NULL,
  `to_telecaller_id` int(11) DEFAULT NULL,
  `shuffled_by` int(11) DEFAULT NULL,
  `previous_status` varchar(100) DEFAULT NULL,
  `previous_remarks` text DEFAULT NULL,
  `previous_call_at` datetime DEFAULT NULL,
  `new_status` varchar(100) DEFAULT 'New',
  `new_remarks` text DEFAULT NULL,
  `new_call_at` datetime DEFAULT NULL,
  `shuffled_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `from_telecaller_id` (`from_telecaller_id`),
  KEY `to_telecaller_id` (`to_telecaller_id`),
  KEY `shuffled_at` (`shuffled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leads`
--

LOCK TABLES `leads` WRITE;
/*!40000 ALTER TABLE `leads` DISABLE KEYS */;
INSERT INTO `leads` VALUES (1,'OL1108260001','Offline Bulk Import','The Grand Palm Residences','Rahul Sharma','','9876543210','Negotiation',NULL,'2026-08-12 13:14:00','Scheduled','Self Visit / Direct Site',NULL,NULL,NULL,NULL,NULL,2,2,'2026-08-11 05:28:47','2026-09-22 11:59:32',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(2,'OL1108260002','Exhibition Event','Skyline Heights Luxury Towers','Priya Verma','','9123456789','Site Visit Scheduled',NULL,NULL,'Scheduled','Self Visit',NULL,NULL,NULL,NULL,NULL,2,1,'2026-08-11 05:28:47','2026-09-22 11:59:32',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(3,'FL1108260003','Facebook Ads','Green Valley Eco Villas','Amit Patel','','9988776655','New',NULL,NULL,'Completed','Self Visit',NULL,NULL,NULL,NULL,NULL,3,1,'2026-08-11 05:28:47','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(4,'FL3108260004','Facebook Ads','The Grand Palm Residences','Rajesh Malhotra','rajesh.malhotra@gmail.com','9823456781','Follow Up','2026-08-31 15:30:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Plot #45',NULL,2,NULL,'2026-08-31 07:08:07','2026-09-23 06:39:58','2026-08-31 15:30:00','Hot',NULL,NULL,NULL,'DGC-II',NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(5,'OL3108260005','Google Ads','Skyline Heights Luxury Towers','Priya Sharma','priya.sharma99@yahoo.com','9871234560','Converted','2026-09-01 11:30:00','2026-09-01 11:30:00','Completed','Self Visit',NULL,NULL,NULL,'Villa A-12',NULL,2,4,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-09-01 11:30:00','Hot',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'2026-09-22 16:19:43','ERP-CUST-00005',NULL,'Synced','{\"Message\":\"No HTTP resource was found that matches the request URI \'http://akinfrarealestate.auctechitsolutions.in/api/crm_lead_receive\'.\",\"MessageDetail\":\"No type was found that matches the controller named \'crm_lead_receive\'.\"}',NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(6,'MS3108260006','99acres','Green Valley Eco Villas','Vikram Singhania','vikram.singh@outlook.com','9811223344','In Progress','2026-08-31 17:00:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Tower B-302',NULL,2,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-08-31 17:00:00','Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(7,'MS3108260007','MagicBricks','Royal Imperial Bayfront','Amitabh Roy','amitabh.roy@gmail.com','9834567890','Follow Up','2026-08-31 18:15:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Shop G-05',NULL,2,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-08-31 18:15:00','Hot',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(8,'OL3108260008','Website','Emerald Park Signature Greens','Neha Agarwal','neha.agarwal@hotmail.com','9765432109','Follow Up','2026-08-30 11:00:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Plot #118',NULL,2,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-08-30 11:00:00','Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(9,'FL3108260009','Facebook Ads','The Grand Palm Residences','Sunil Deshmukh','sunil.deshmukh@gmail.com','9890123456','Follow Up','2026-08-29 14:00:00',NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,2,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-08-29 14:00:00','Cold',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(10,'OL3108260010','Referral','Skyline Heights Luxury Towers','Ananya Sengupta','ananya.sen@gmail.com','9845012345','Site Visit Done','2026-09-02 16:00:00','2026-08-30 11:00:00','None','Self Visit',NULL,NULL,NULL,'Villa C-04',NULL,2,4,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-09-02 16:00:00','Hot',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(11,'OL3108260011','Google Ads','Green Valley Eco Villas','Manish Gupta','manish.gupta77@gmail.com','9820123456','Negotiation','2026-09-02 16:00:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Plot #210',NULL,2,4,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-09-02 16:00:00','Hot',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(12,'FL3108260012','Facebook Ads','The Grand Palm Residences','Deepak Verma','deepak.verma@tcs.com','9819876543','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-08-31 07:08:07','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(13,'MS3108260013','MagicBricks','Royal Imperial Bayfront','Sanjay Patel','sanjay.patel@rediffmail.com','9825098765','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-08-31 07:08:07','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(14,'OL3108260014','Website','Emerald Park Signature Greens','Rohan Mehra','rohan.mehra@gmail.com','9810123789','In Progress','2026-09-03 10:00:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Plot #55',NULL,2,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-09-03 10:00:00','Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(15,'MS3108260015','99acres','Skyline Heights Luxury Towers','Kavita Joshi','kavita.joshi@infosys.com','9822334455','Converted',NULL,'2026-08-28 16:00:00','None','Self Visit',NULL,NULL,NULL,'Villa B-08',NULL,2,4,'2026-08-31 07:08:07','2026-09-22 11:59:32',NULL,'Hot',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(16,'OL3108260016','Google Ads','Green Valley Eco Villas','Gaurav Dubey','gaurav.dubey@gmail.com','9839012345','Dead',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,2,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32',NULL,'Cold',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(17,'FL3108260017','Facebook Ads','The Grand Palm Residences','Anjali Rao','anjali.rao@wipro.com','9849123456','Follow Up','2026-08-31 15:30:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Plot #88',NULL,3,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-08-31 15:30:00','Hot',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(18,'OL3108260018','Website','Skyline Heights Luxury Towers','Pooja Tiwari','pooja.tiwari@gmail.com','9867123450','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(19,'MS3108260019','99acres','Royal Imperial Bayfront','Kunal Kapoor','kunal.kapoor@gmail.com','9818765432','In Progress','2026-09-02 16:00:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Office Suite 204',NULL,3,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-09-02 16:00:00','Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(20,'OL3108260020','Google Ads','Green Valley Eco Villas','Harshvardhan Rathore','harsh.rathore@gmail.com','9829012345','Site Visit Scheduled','2026-09-03 10:00:00','2026-09-03 10:00:00','None','Self Visit',NULL,NULL,NULL,'Plot #301',NULL,3,4,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-09-03 10:00:00','Hot',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(21,'FL3108260021','Facebook Ads','Emerald Park Signature Greens','Meenakshi Iyer','meenakshi.iyer@gmail.com','9840123456','Follow Up','2026-08-31 17:00:00',NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,2,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-08-31 17:00:00','Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(22,'MS3108260022','MagicBricks','The Grand Palm Residences','Alok Srivastava','alok.sri@gmail.com','9838123456','In Progress','2026-09-01 11:30:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Plot #15',NULL,2,NULL,'2026-08-31 07:08:07','2026-09-23 07:20:29','2026-09-01 11:30:00','Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'2026-09-23 12:50:29','ERP-CUST-00022',NULL,'Synced','{\"Message\":\"No HTTP resource was found that matches the request URI \'http://akinfrarealestate.auctechitsolutions.in/api/crm_lead_receive\'.\",\"MessageDetail\":\"No type was found that matches the controller named \'crm_lead_receive\'.\"}',NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(23,'OL3108260023','Referral','Skyline Heights Luxury Towers','Tanya Bhatia','tanya.bhatia@gmail.com','9811987654','Follow Up','2026-08-31 18:15:00',NULL,'None','Self Visit',NULL,NULL,NULL,'Villa A-05',NULL,2,NULL,'2026-08-31 07:08:07','2026-09-22 11:59:32','2026-08-31 18:15:00','Hot',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(24,'FL2106260024','Facebook Ads','The Grand Palm Residences','Santosh  Hake','santosh.ivrck@gmail.com','+919763301503','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-21 10:45:20','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(25,'FL2006260025','Facebook Ads','Skyline Heights Luxury Towers','Milind Utpat','milindutpat1978@gmail.com','+919011009270','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-20 06:58:12','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(26,'FL2006260026','Facebook Ads','Green Valley Eco Villas','Priya Ghodake Gaikwad','priya.ghodake1304@gmail.com','+917030599503','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-20 04:02:37','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(27,'FL2006260027','Facebook Ads','Royal Imperial Bayfront','Sitanshu','sitanshut2202@gmail.com','+919875958758','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-19 23:03:45','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(28,'FL1906260028','Facebook Ads','Emerald Park Signature Greens','Vinayak More','vinyakm2016@gmail.com','+919922189238','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-19 10:57:00','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(29,'FL1906260029','Facebook Ads','The Grand Palm Residences','Sunilkumar Karbhari','sunil.kishanrao@gmail.com','+917276269541','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-19 08:06:19','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(30,'FL1906260030','Facebook Ads','Skyline Heights Luxury Towers','pallavi sapkal','pallavi_s_sapkal@yahoo.com','+919579464625','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,4,NULL,'2026-06-18 19:09:33','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(31,'FL1806260031','Facebook Ads','Green Valley Eco Villas','Akram Shaik','sitaraenterprises2@gmail.com','+919860703958','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-18 14:31:22','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(32,'FL1806260032','Facebook Ads','Royal Imperial Bayfront','Aadinath Ramdas Kekan','kekanaadinath@gmail.com','+919689690696','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-18 14:01:47','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(33,'FL1806260033','Facebook Ads','Emerald Park Signature Greens','Govind Waghmare','gwaghmare@sumo-india.com','+919604608336','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-18 02:50:48','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(34,'FL1806260034','Facebook Ads','The Grand Palm Residences','Bhamare Chandrakant','chandrakantbhamare195@gamil.com','+917972783402','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-18 01:27:25','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(35,'FL1806260035','Facebook Ads','Skyline Heights Luxury Towers','Santosh koli','santosh.koli04@gmail.com','+918888814327','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-17 23:29:10','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(36,'FL1706260036','Facebook Ads','Green Valley Eco Villas','Javir Sayyad','javirsayyadkgn@gmail.com','+18668916713','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-17 10:16:53','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(37,'FL1706260037','Facebook Ads','Royal Imperial Bayfront','+Soni Sahu','sonishahu69840@gmail.com','+18668572407','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-17 07:27:04','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(38,'FL1706260038','Facebook Ads','Emerald Park Signature Greens','Santosh Suwarnkar','suwarnkar@gmail.com','+918446903505','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-16 20:12:21','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(39,'FL1606260039','Facebook Ads','The Grand Palm Residences','मावळ शाहीर मंगेश साळुंके','melodies.mangesh@gmail.com','+919028587508','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-16 12:48:04','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(40,'FL1506260040','Facebook Ads','Skyline Heights Luxury Towers','vikram.kshirsagar','vikram.kshirsagar85@rediffmail.com','9763246472','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,4,NULL,'2026-06-15 13:09:47','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(41,'FL1506260041','Facebook Ads','Green Valley Eco Villas','shubham k','kshub1234@gmail.com','+919767878549','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-15 12:21:55','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(42,'FL1506260042','Facebook Ads','Royal Imperial Bayfront','Shravan Kumar','shravan.kumar8546@gmail.com','+919657067124','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-15 12:15:35','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(43,'FL1506260043','Facebook Ads','Emerald Park Signature Greens','Dr Vijay Keswani','cryptomagic199621147@gmail.com','+919323415999','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-15 00:52:31','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(44,'FL1406260044','Facebook Ads','The Grand Palm Residences','Balasaheb Balshankar','balasahebbalashankar46@gamil.com','+19022049375','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-14 00:39:46','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(45,'FL1406260045','Facebook Ads','Skyline Heights Luxury Towers','Kishor Kadam','Kishor.kadam1986@gmail.com','9028872045','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-13 22:55:33','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(46,'FL1406260046','Facebook Ads','Green Valley Eco Villas','Dattatraya Jagtap','dattajagtap4444@gmail.com','+18482929997','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-13 22:26:12','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(47,'FL1406260047','Facebook Ads','Royal Imperial Bayfront','Feroz Shaikh','shaikhferoz5422@gmail.com','+919890287596','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-13 22:00:39','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(48,'FL1306260048','Facebook Ads','Emerald Park Signature Greens','Amruta Kulkarni','amruta14999@gmail.com','+919595654515','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-13 13:36:51','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(49,'FL1206260049','Facebook Ads','The Grand Palm Residences','Sanjay Jadhav','sanjay0jadhav@gmail.com','9552626735','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-12 07:22:48','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(50,'FL1206260050','Facebook Ads','Skyline Heights Luxury Towers','Dadasaheb Ghodke','ghodkedadasheb204@gmail.com','+919028902441','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,4,NULL,'2026-06-12 06:21:36','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(51,'FL1206260051','Facebook Ads','Green Valley Eco Villas','Sachin Khade','sachinkhade1803@gmail.com','+917720842003','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-12 03:29:37','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(52,'FL1106260052','Facebook Ads','Royal Imperial Bayfront','Pemaram Choudhary','premaramrchoudhary5657@gmail.com','+918600020353','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-11 14:19:07','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(53,'FL1106260053','Facebook Ads','Emerald Park Signature Greens','Prasanna Parshuram','skyfarms.pune@gmail.com','+919822520848','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-11 03:50:31','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(54,'FL1006260054','Facebook Ads','The Grand Palm Residences','Ashutosh Sonawane','durgakoli282@gmail.com','+918237323212','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-10 14:56:38','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(55,'FL1006260055','Facebook Ads','Skyline Heights Luxury Towers','Nitin Vishwanath Chavan','c_nitin27@rediffmail.com','+919029588388','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-10 14:41:53','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(56,'FL1006260056','Facebook Ads','Green Valley Eco Villas','Arif Khan','ari_fkhan@yahoo.co.in','+919823595989','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-10 13:13:22','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(57,'FL1006260057','Facebook Ads','Royal Imperial Bayfront','Arun Aavhad','ashokavhad186@gmail.com','+919511773794','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-10 10:33:44','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(58,'FL1006260058','Facebook Ads','Emerald Park Signature Greens','Uttam Soori','uttam_soori@yahoo.co.in','+917721868177','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-10 06:02:56','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(59,'FL1006260059','Facebook Ads','The Grand Palm Residences','Suresh Saundane','sureshsaundane13@gmail.com','+918600242823','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-10 05:04:21','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(60,'FL0906260060','Facebook Ads','Skyline Heights Luxury Towers','Poonam Singh','poonam19911207@gmail.com','+919179035889','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,4,NULL,'2026-06-09 14:46:53','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(61,'FL0906260061','Facebook Ads','Green Valley Eco Villas','Dinesh Yadav','dk.yadav005@gmail.com','+918983665526','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-09 13:22:10','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(62,'FL0906260062','Facebook Ads','Royal Imperial Bayfront','Abhishek Kumar Choubey','abhishekbhsb96@gmail.com','+918574617085','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-09 07:23:45','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(63,'FL0806260063','Facebook Ads','Emerald Park Signature Greens','Ikram','feelingfor1@gmail.com','+919766135786','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-08 11:57:36','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(64,'FL0806260064','Facebook Ads','The Grand Palm Residences','Rajendra Koshti','koshtirajendrav@gmail.com','+919423204910','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-08 11:40:05','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(65,'FL0806260065','Facebook Ads','Skyline Heights Luxury Towers','Avinash Khutawd','avi173894@gmail.com','+919403976975','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-08 11:26:08','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(66,'FL0806260066','Facebook Ads','Green Valley Eco Villas','Bhavana Bhingare','bhingareb31@gmail.com','+919373835930','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-08 07:13:55','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(67,'FL0706260067','Facebook Ads','Royal Imperial Bayfront','Mukesh Kanojiya','mrkanojiya88@gmail.com','+919673788916','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-07 04:57:31','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(68,'FL0706260068','Facebook Ads','Emerald Park Signature Greens','Dhiraj Tirpude','dhirajtirpude@gmail.com','+918698454400','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-07 03:27:57','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(69,'FL0606260069','Facebook Ads','The Grand Palm Residences','Subrat Majhi','subrat.majhi212200@gmail.com','+918600445589','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-06 13:54:13','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(70,'FL0606260070','Facebook Ads','Skyline Heights Luxury Towers','Jameer Sindh','jameersindh1983@gmail.com','+919881520172','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,4,NULL,'2026-06-06 11:59:26','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(71,'FL0606260071','Facebook Ads','Green Valley Eco Villas','Amardeep jaydeep jagdale','amardeepjagdale02@gmail.com','+919637264958','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-06 01:06:46','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(72,'FL0606260072','Facebook Ads','Royal Imperial Bayfront','rajkumar Shikhare','rajkumarshikhare45@gmail.com','+917972966571','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-05 23:16:51','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(73,'FL0606260073','Facebook Ads','Emerald Park Signature Greens','Vinod K','vk9029@gmail.com','+918624027546','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-05 23:14:38','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(74,'FL0506260074','Facebook Ads','The Grand Palm Residences','Vikas jaiswal','vikashjaiswal43@gmail.com','+917709703246','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-05 11:28:31','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(75,'FL0506260075','Facebook Ads','Skyline Heights Luxury Towers','Rabiya Khan','khanbanderubina1234@gmail.com','+919022269510','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-05 08:56:21','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(76,'FL0506260076','Facebook Ads','Green Valley Eco Villas','Chhaya choudhary','chayachoudhary@1970gmail.com','+918823043156','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-04 22:10:45','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(77,'FL0506260077','Facebook Ads','Royal Imperial Bayfront','Sudhir Tambe','saishivestate@gmail.com','+919892478680','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-04 21:44:47','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(78,'FL0406260078','Facebook Ads','Emerald Park Signature Greens','umakant Shukla','umkant10031976@gmail.com','+919325768658','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-04 09:59:11','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(79,'FL0406260079','Facebook Ads','The Grand Palm Residences','Anamika  Jambhulkar','anamikajambhulkar8980@gmail.com','+919511218344','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-04 03:05:16','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(80,'FL0306260080','Facebook Ads','Skyline Heights Luxury Towers','Sandeep Shende','sandeepshende@yahoo.com','+919730183499','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,4,NULL,'2026-06-03 12:49:04','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads'),(81,'FL0306260081','Facebook Ads','Green Valley Eco Villas','Lekha Kapinappa','lekhakapinappa@gmail.com','+919372910289','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-03 06:01:55','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,2,'Green Valley Eco Villas - Meta Lead Ads'),(82,'FL0306260082','Facebook Ads','Royal Imperial Bayfront','A AG','ynt4aag@gmail.com','9011019516','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-02 23:23:38','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,3,'Royal Imperial Bayfront - Meta Lead Ads'),(83,'FL0306260083','Facebook Ads','Emerald Park Signature Greens','Sandeep','sandeep.rathod@bremboindia.com','+917768076667','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-02 21:10:21','2026-09-23 07:13:45',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,1,'Emerald Park Signature Greens - Meta Lead Ads'),(84,'FL0206260084','Facebook Ads','The Grand Palm Residences','Prashant Deshmukh','p_deshmukh1@rediffmail.com','+919011678929','New',NULL,NULL,'None','Self Visit',NULL,NULL,NULL,NULL,NULL,3,NULL,'2026-06-02 12:42:46','2026-09-23 09:35:25',NULL,'Warm',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No',NULL,'No',NULL,NULL,NULL,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,'Pending',NULL,NULL,NULL,NULL,5,'The Grand Palm Residences - Meta Lead Ads'),(85,'FL0206260085','Facebook Ads','Skyline Heights Luxury Towers','sujata thorat','sujatathorat48@gmail.com','+919527735731','Booked / Token Paid',NULL,'2026-09-22 11:00:00','Completed','Pickup Required (Cab/Car)','Highly Interested (Ready for Token)',5200000.00,51000.00,'Plot #A-108',NULL,2,4,'2026-06-02 12:11:40','2026-09-22 11:59:32','2026-09-22 10:00:00','Warm',NULL,NULL,NULL,'DGC-I Green Valley',NULL,NULL,NULL,NULL,NULL,'No',NULL,'Yes',NULL,'2026-09-21',NULL,NULL,NULL,NULL,'Pending','2026-09-21 17:28:10',1,'Spoke with client. Highly interested, budget 60L. Wants site visit tomorrow morning.','Booked / Token Paid',1,'2026-09-22 16:19:35','ERP-CUST-00085',NULL,'Synced','{\"Message\":\"No HTTP resource was found that matches the request URI \'http://akinfrarealestate.auctechitsolutions.in/api/crm_lead_receive\'.\",\"MessageDetail\":\"No type was found that matches the controller named \'crm_lead_receive\'.\"}',NULL,NULL,NULL,4,'Skyline Heights Luxury Towers - Meta Lead Ads');
/*!40000 ALTER TABLE `leads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `project_assignments`
--

DROP TABLE IF EXISTS `project_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `project_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_name` varchar(100) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `proj_user` (`project_name`,`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `project_assignments`
--

LOCK TABLES `project_assignments` WRITE;
/*!40000 ALTER TABLE `project_assignments` DISABLE KEYS */;
INSERT INTO `project_assignments` VALUES (7,'Emerald Park Signature Greens',3),(5,'Green Valley Eco Villas',3),(16,'Project A',2),(15,'Project A',3),(17,'Project B',2),(18,'Project C',3),(20,'Project D',2),(19,'Project D',3),(21,'Project E',3),(3,'Royal Imperial Bayfront',2),(6,'Royal Imperial Bayfront',3),(2,'Skyline Heights Luxury Towers',2),(1,'The Grand Palm Residences',2),(4,'The Grand Palm Residences',3);
/*!40000 ALTER TABLE `project_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `projects`
--

DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `location` varchar(200) DEFAULT NULL,
  `type` varchar(50) DEFAULT 'Residential',
  `status` enum('Active','Upcoming','Sold Out') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_project_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `projects`
--

LOCK TABLES `projects` WRITE;
/*!40000 ALTER TABLE `projects` DISABLE KEYS */;
INSERT INTO `projects` VALUES (1,'The Grand Palm Residences',NULL,NULL,'Residential','Active','2026-09-22 11:59:32','2026-09-22 11:59:32'),(2,'Skyline Heights Luxury Towers',NULL,NULL,'Residential','Active','2026-09-22 11:59:32','2026-09-22 11:59:32'),(3,'Green Valley Eco Villas',NULL,NULL,'Residential','Active','2026-09-22 11:59:32','2026-09-22 11:59:32'),(4,'Royal Imperial Bayfront',NULL,NULL,'Residential','Active','2026-09-22 11:59:32','2026-09-22 11:59:32'),(5,'Emerald Park Signature Greens',NULL,NULL,'Residential','Active','2026-09-22 11:59:32','2026-09-22 11:59:32');
/*!40000 ALTER TABLE `projects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_activity_logs`
--

DROP TABLE IF EXISTS `user_activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `device_info` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_action_type` (`action_type`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_activity_logs`
--

LOCK TABLES `user_activity_logs` WRITE;
/*!40000 ALTER TABLE `user_activity_logs` DISABLE KEYS */;
INSERT INTO `user_activity_logs` VALUES (1,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 05:51:27'),(2,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 06:06:29'),(3,1,'logout','User logged out of CRM portal','::1','Windows (Chrome)','2026-09-22 06:18:26'),(4,4,'login','User logged into CRM portal','::1','Windows (Chrome)','2026-09-22 06:18:34'),(5,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 06:20:50'),(6,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 06:36:14'),(7,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 07:14:55'),(8,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 07:38:41'),(9,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 07:54:32'),(10,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 08:10:28'),(11,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 08:25:43'),(12,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 09:18:03'),(13,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 09:33:27'),(14,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 09:48:28'),(15,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 10:03:52'),(16,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 10:19:28'),(17,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 10:40:59'),(18,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 10:56:29'),(19,4,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 11:12:14'),(20,4,'logout','User logged out of CRM portal','::1','Windows (Chrome)','2026-09-22 11:18:20'),(21,1,'login','User logged into CRM portal','::1','Windows (Chrome)','2026-09-22 11:18:22'),(22,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 11:18:27'),(23,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 11:33:30'),(24,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 11:49:28'),(25,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 12:05:24'),(26,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 12:21:01'),(27,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 12:38:53'),(28,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-22 12:54:03'),(29,1,'login','User logged into CRM portal','::1','Windows (Chrome)','2026-09-23 05:31:35'),(30,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 05:31:40'),(31,1,'logout','User logged out of CRM portal','::1','Windows (Chrome)','2026-09-23 05:39:20'),(32,1,'login','User logged into CRM portal','::1','Windows (Chrome)','2026-09-23 05:45:45'),(33,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 05:46:46'),(34,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 06:02:31'),(35,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 06:17:46'),(36,1,'logout','User logged out of CRM portal','::1','Windows (Chrome)','2026-09-23 06:29:38'),(37,1,'login','User logged into CRM portal','::1','Windows (Chrome)','2026-09-23 06:35:52'),(38,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 06:35:55'),(39,1,'lead_update','Updated [Site Location] on Lead #4 to \'DGC-II\'','::1','Windows (Chrome)','2026-09-23 06:39:58'),(40,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 06:51:17'),(41,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 07:07:07'),(42,1,'logout','User logged out of CRM portal','::1','Windows (Chrome)','2026-09-23 07:13:00'),(43,2,'login','User logged into CRM portal','::1','Windows (Chrome)','2026-09-23 07:13:36'),(44,2,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 07:13:40'),(45,2,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 07:29:10'),(46,2,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 07:56:15'),(47,2,'logout','User logged out of CRM portal','::1','Windows (Chrome)','2026-09-23 07:57:20'),(48,1,'login','User logged into CRM portal','::1','Windows (Chrome)','2026-09-23 07:57:23'),(49,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 07:57:27'),(50,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 08:12:31'),(51,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 08:27:36'),(52,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 08:43:31'),(53,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 08:59:31'),(54,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 09:15:35'),(55,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 09:31:31'),(56,1,'lead_update','Updated [Site Location] on Lead #84 to \'DGC-I\'','::1','Windows (Chrome)','2026-09-23 09:35:21'),(57,1,'lead_update','Updated [Site Location] on Lead #84 to \'\'','::1','Windows (Chrome)','2026-09-23 09:35:25'),(58,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 09:47:16'),(59,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 10:03:10'),(60,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 10:18:31'),(61,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 10:34:31'),(62,1,'heartbeat_active','Active on CRM workspace','::1','Windows (Chrome)','2026-09-23 10:50:14');
/*!40000 ALTER TABLE `user_activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `plain_password` varchar(255) DEFAULT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'telecaller',
  `manager_id` int(11) DEFAULT NULL,
  `branch_name` varchar(100) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `is_present` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `assigned_ip` varchar(45) DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `device_info` varchar(255) DEFAULT NULL,
  `today_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `allow_after_hours` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Admin','admin@crmleads.com',NULL,NULL,NULL,NULL,'$2y$10$M.4/78ZIYErpPQ3icJP7c.vO8QLqvsHAJor1DwBKXcBMnRaiQrngG','password','admin',1,1,NULL,'::1','2026-09-23 16:25:13','Windows (Chrome)','2026-09-23 11:01:35','2026-08-11 05:03:13'),(2,'Shahabuddin','support@akinfracrm.com','6394129118','telecaller','lucknow','6a7ad250f35b3.jpeg','$2y$10$N/BEREjDKi9RNzvZc.692eW6xi6SNVFudY3/s8M/pJW3Emvs.jFw6','hashmi786','telecaller',0,1,NULL,'::1','2026-09-23 13:27:20','Windows (Chrome)','2026-09-23 12:43:36','2026-08-11 07:42:09'),(3,'Pooja Sharma','pooja@crmleads.com',NULL,NULL,NULL,NULL,'$2y$10$3Ser8OZhM3IzYND5qJFo7e2E/xqo5QhwSeA74OeZ3pLYPy5JHswQO','password','telecaller',1,1,NULL,NULL,NULL,NULL,NULL,'2026-08-31 07:08:07'),(4,'Rahul Verma','rahul.sales@crmleads.com',NULL,NULL,NULL,NULL,'$2y$10$vottRWtYJlRt98h8qA4F4.vzRKK7jMHlqqEjh/LjNyHHrVJ/PxOC.','password','sales',1,1,NULL,'::1','2026-09-22 16:48:20','Windows (Chrome)','2026-09-22 11:48:34','2026-08-31 07:08:07');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branches`
--
CREATE TABLE IF NOT EXISTS `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `city` varchar(100) DEFAULT 'Lucknow',
  `address` text DEFAULT NULL,
  `head_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `branches` (`id`, `name`, `city`, `address`, `head_id`, `is_active`) VALUES
(2, 'Gomti Nagar Branch', 'Lucknow', '', NULL, 1),
(3, 'Alambagh / Kanpur Road Branch', 'Lucknow', '', 6, 1);

--
-- Table structure for table `site_locations`
--
CREATE TABLE IF NOT EXISTS `site_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `budgets`
--
CREATE TABLE IF NOT EXISTS `budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `os_locations`
--
CREATE TABLE IF NOT EXISTS `os_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `office_settings`
--
CREATE TABLE IF NOT EXISTS `office_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` longtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-22 17:40:00
