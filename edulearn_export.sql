-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: edulearn
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
-- Table structure for table `academic_years`
--

DROP TABLE IF EXISTS `academic_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_years`
--

LOCK TABLES `academic_years` WRITE;
/*!40000 ALTER TABLE `academic_years` DISABLE KEYS */;
INSERT INTO `academic_years` VALUES (1,'2026-2027','2026-05-01','2027-03-04',0,'2026-05-01 12:15:46',NULL,'inactive','2026-11-08 16:36:34'),(2,'2025-2026','2025-09-01','2026-06-30',0,'2026-05-05 16:16:50',NULL,'inactive','2026-11-08 16:36:27'),(3,'2025-2026','2025-09-01','2026-06-30',0,'2026-05-05 16:25:13',NULL,'inactive','2026-11-08 16:36:31'),(4,'AY 2026/2027','2026-09-01','2027-06-30',1,'2026-05-31 14:55:17',NULL,'active','2026-11-08 16:29:34'),(5,'2021','2025-02-06','2026-06-04',0,'2026-05-31 14:58:43',NULL,'inactive','2026-11-08 16:36:24'),(7,'2027-2028','2027-01-01','2028-02-10',0,'2026-11-10 01:54:11',NULL,'active','2026-11-10 01:54:11');
/*!40000 ALTER TABLE `academic_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignment_annotations`
--

DROP TABLE IF EXISTS `assignment_annotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assignment_annotations` (
  `annotation_id` int(11) NOT NULL AUTO_INCREMENT,
  `submission_id` int(11) NOT NULL,
  `instructor_id` int(11) NOT NULL,
  `page_number` int(11) NOT NULL DEFAULT 1,
  `annotation_type` varchar(32) NOT NULL,
  `annotation_content` text DEFAULT NULL,
  `annotation_data` longtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`annotation_id`),
  KEY `idx_assignment_annotations_submission` (`submission_id`),
  KEY `instructor_id` (`instructor_id`),
  CONSTRAINT `assignment_annotations_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignment_annotations_ibfk_2` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignment_annotations`
--

LOCK TABLES `assignment_annotations` WRITE;
/*!40000 ALTER TABLE `assignment_annotations` DISABLE KEYS */;
/*!40000 ALTER TABLE `assignment_annotations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignment_feedback`
--

DROP TABLE IF EXISTS `assignment_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assignment_feedback` (
  `feedback_id` int(11) NOT NULL AUTO_INCREMENT,
  `submission_id` int(11) NOT NULL,
  `instructor_id` int(11) NOT NULL,
  `comments` text DEFAULT NULL,
  `score` decimal(8,2) DEFAULT NULL,
  `maximum_score` decimal(8,2) NOT NULL DEFAULT 100.00,
  `status` enum('under_review','checked','returned_for_revision') NOT NULL DEFAULT 'under_review',
  `allow_resubmission` tinyint(1) NOT NULL DEFAULT 0,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`feedback_id`),
  UNIQUE KEY `uq_assignment_feedback_submission` (`submission_id`),
  KEY `instructor_id` (`instructor_id`),
  CONSTRAINT `assignment_feedback_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignment_feedback_ibfk_2` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignment_feedback`
--

LOCK TABLES `assignment_feedback` WRITE;
/*!40000 ALTER TABLE `assignment_feedback` DISABLE KEYS */;
/*!40000 ALTER TABLE `assignment_feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignments`
--

DROP TABLE IF EXISTS `assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `points` int(11) DEFAULT 100,
  `status` enum('active','draft','completed','archived') DEFAULT 'active',
  `file_path` varchar(255) DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `allow_resubmission` tinyint(1) NOT NULL DEFAULT 1,
  `max_resubmissions` int(11) NOT NULL DEFAULT 3,
  `allow_late_submission` tinyint(1) NOT NULL DEFAULT 0,
  `allow_late_resubmission` tinyint(1) NOT NULL DEFAULT 0,
  `late_grace_period_hours` int(11) NOT NULL DEFAULT 0,
  `require_teacher_approval` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `course_id` (`subject_id`),
  CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignments`
--

LOCK TABLES `assignments` WRITE;
/*!40000 ALTER TABLE `assignments` DISABLE KEYS */;
INSERT INTO `assignments` VALUES (26,49,'Assignment 1: Database Design ERD','Course assignment project',100,'completed',NULL,'2026-08-30 23:59:00','2026-08-23 15:59:00',1,3,0,0,0,0),(27,49,'Assignment 2: Stored Procedures & Triggers','Course assignment project',100,'completed',NULL,'2026-09-20 23:59:00','2026-09-13 15:59:00',1,3,0,0,0,0),(28,50,'Assignment 1: Responsive Dashboard UI','Course assignment project',100,'completed',NULL,'2026-09-10 23:59:00','2026-09-03 15:59:00',1,3,0,0,0,0),(36,48,'csc','',100,'active',NULL,'2026-10-21 15:32:00','2026-10-09 07:32:07',1,3,0,0,0,0);
/*!40000 ALTER TABLE `assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent','late','excused') DEFAULT 'absent',
  `marked_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attendance` (`subject_id`,`student_id`,`attendance_date`),
  KEY `student_id` (`student_id`),
  KEY `marked_by` (`marked_by`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=760 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (507,49,50,'2026-08-10','absent',NULL,NULL,'2026-08-10 01:00:00'),(508,50,50,'2026-08-11','present',NULL,NULL,'2026-08-11 01:00:00'),(510,49,50,'2026-08-13','present',NULL,NULL,'2026-08-13 01:00:00'),(511,50,50,'2026-08-14','present',NULL,NULL,'2026-08-14 01:00:00'),(513,49,50,'2026-08-18','present',NULL,NULL,'2026-08-18 01:00:00'),(514,50,50,'2026-08-19','late',NULL,NULL,'2026-08-19 01:00:00'),(516,49,50,'2026-08-21','present',NULL,NULL,'2026-08-21 01:00:00'),(517,50,50,'2026-08-24','absent',NULL,NULL,'2026-08-24 01:00:00'),(519,49,50,'2026-08-26','present',NULL,NULL,'2026-08-26 01:00:00'),(520,50,50,'2026-08-27','present',NULL,NULL,'2026-08-27 01:00:00'),(522,49,50,'2026-08-31','absent',NULL,NULL,'2026-08-31 01:00:00'),(523,50,50,'2026-09-01','present',NULL,NULL,'2026-09-01 01:00:00'),(525,49,50,'2026-09-03','present',NULL,NULL,'2026-09-03 01:00:00'),(526,50,50,'2026-09-04','present',NULL,NULL,'2026-09-04 01:00:00'),(528,49,50,'2026-09-08','late',NULL,NULL,'2026-09-08 01:00:00'),(529,50,50,'2026-09-09','present',NULL,NULL,'2026-09-09 01:00:00'),(531,49,50,'2026-09-11','present',NULL,NULL,'2026-09-11 01:00:00'),(532,50,50,'2026-09-14','absent',NULL,NULL,'2026-09-14 01:00:00'),(534,49,50,'2026-09-16','present',NULL,NULL,'2026-09-16 01:00:00'),(535,50,50,'2026-09-17','late',NULL,NULL,'2026-09-17 01:00:00'),(537,49,50,'2026-09-21','absent',NULL,NULL,'2026-09-21 01:00:00'),(538,50,50,'2026-09-22','present',NULL,NULL,'2026-09-22 01:00:00'),(540,49,50,'2026-09-24','present',NULL,NULL,'2026-09-24 01:00:00'),(541,50,50,'2026-09-25','present',NULL,NULL,'2026-09-25 01:00:00'),(543,49,50,'2026-09-29','present',NULL,NULL,'2026-09-29 01:00:00'),(544,50,50,'2026-09-30','present',NULL,NULL,'2026-09-30 01:00:00'),(546,49,50,'2026-10-02','present',NULL,NULL,'2026-10-02 01:00:00'),(547,50,50,'2026-10-05','absent',NULL,NULL,'2026-10-05 01:00:00'),(549,49,50,'2026-10-07','present',NULL,NULL,'2026-10-07 01:00:00'),(550,50,50,'2026-10-08','present',NULL,NULL,'2026-10-08 01:00:00'),(552,49,50,'2026-10-12','absent',NULL,NULL,'2026-10-12 01:00:00'),(553,50,50,'2026-10-13','present',NULL,NULL,'2026-10-13 01:00:00'),(555,49,50,'2026-10-15','present',NULL,NULL,'2026-10-15 01:00:00'),(556,50,50,'2026-10-16','present',NULL,NULL,'2026-10-16 01:00:00'),(558,49,50,'2026-10-20','present',NULL,NULL,'2026-10-20 01:00:00'),(559,50,50,'2026-10-21','present',NULL,NULL,'2026-10-21 01:00:00'),(561,49,51,'2026-08-10','absent',NULL,NULL,'2026-08-10 01:00:00'),(562,50,51,'2026-08-11','present',NULL,NULL,'2026-08-11 01:00:00'),(564,49,51,'2026-08-13','present',NULL,NULL,'2026-08-13 01:00:00'),(565,50,51,'2026-08-14','present',NULL,NULL,'2026-08-14 01:00:00'),(567,49,51,'2026-08-18','absent',NULL,NULL,'2026-08-18 01:00:00'),(568,50,51,'2026-08-19','present',NULL,NULL,'2026-08-19 01:00:00'),(570,49,51,'2026-08-21','present',NULL,NULL,'2026-08-21 01:00:00'),(571,50,51,'2026-08-24','present',NULL,NULL,'2026-08-24 01:00:00'),(573,49,51,'2026-08-26','absent',NULL,NULL,'2026-08-26 01:00:00'),(574,50,51,'2026-08-27','present',NULL,NULL,'2026-08-27 01:00:00'),(576,49,51,'2026-08-31','present',NULL,NULL,'2026-08-31 01:00:00'),(577,50,51,'2026-09-01','present',NULL,NULL,'2026-09-01 01:00:00'),(579,49,51,'2026-09-03','absent',NULL,NULL,'2026-09-03 01:00:00'),(580,50,51,'2026-09-04','present',NULL,NULL,'2026-09-04 01:00:00'),(582,49,51,'2026-09-08','present',NULL,NULL,'2026-09-08 01:00:00'),(583,50,51,'2026-09-09','late',NULL,NULL,'2026-09-09 01:00:00'),(585,49,51,'2026-09-11','absent',NULL,NULL,'2026-09-11 01:00:00'),(586,50,51,'2026-09-14','present',NULL,NULL,'2026-09-14 01:00:00'),(588,49,51,'2026-09-16','present',NULL,NULL,'2026-09-16 01:00:00'),(589,50,51,'2026-09-17','present',NULL,NULL,'2026-09-17 01:00:00'),(591,49,51,'2026-09-21','absent',NULL,NULL,'2026-09-21 01:00:00'),(592,50,51,'2026-09-22','present',NULL,NULL,'2026-09-22 01:00:00'),(594,49,51,'2026-09-24','late',NULL,NULL,'2026-09-24 01:00:00'),(595,50,51,'2026-09-25','present',NULL,NULL,'2026-09-25 01:00:00'),(597,49,51,'2026-09-29','absent',NULL,NULL,'2026-09-29 01:00:00'),(598,50,51,'2026-09-30','present',NULL,NULL,'2026-09-30 01:00:00'),(600,49,51,'2026-10-02','present',NULL,NULL,'2026-10-02 01:00:00'),(601,50,51,'2026-10-05','present',NULL,NULL,'2026-10-05 01:00:00'),(603,49,51,'2026-10-07','absent',NULL,NULL,'2026-10-07 01:00:00'),(604,50,51,'2026-10-08','present',NULL,NULL,'2026-10-08 01:00:00'),(605,49,52,'2026-08-10','absent',NULL,NULL,'2026-08-10 01:00:00'),(606,50,52,'2026-08-11','present',NULL,NULL,'2026-08-11 01:00:00'),(608,49,52,'2026-08-13','present',NULL,NULL,'2026-08-13 01:00:00'),(609,50,52,'2026-08-14','present',NULL,NULL,'2026-08-14 01:00:00'),(611,49,52,'2026-08-18','present',NULL,NULL,'2026-08-18 01:00:00'),(612,50,52,'2026-08-19','present',NULL,NULL,'2026-08-19 01:00:00'),(614,49,52,'2026-08-21','present',NULL,NULL,'2026-08-21 01:00:00'),(615,50,52,'2026-08-24','present',NULL,NULL,'2026-08-24 01:00:00'),(617,49,52,'2026-08-26','present',NULL,NULL,'2026-08-26 01:00:00'),(618,50,52,'2026-08-27','present',NULL,NULL,'2026-08-27 01:00:00'),(620,49,52,'2026-08-31','present',NULL,NULL,'2026-08-31 01:00:00'),(621,50,52,'2026-09-01','absent',NULL,NULL,'2026-09-01 01:00:00'),(623,49,52,'2026-09-03','present',NULL,NULL,'2026-09-03 01:00:00'),(624,50,52,'2026-09-04','present',NULL,NULL,'2026-09-04 01:00:00'),(626,49,52,'2026-09-08','present',NULL,NULL,'2026-09-08 01:00:00'),(627,50,52,'2026-09-09','present',NULL,NULL,'2026-09-09 01:00:00'),(629,49,52,'2026-09-11','absent',NULL,NULL,'2026-09-11 01:00:00'),(630,50,52,'2026-09-14','present',NULL,NULL,'2026-09-14 01:00:00'),(632,49,52,'2026-09-16','present',NULL,NULL,'2026-09-16 01:00:00'),(633,50,52,'2026-09-17','present',NULL,NULL,'2026-09-17 01:00:00'),(635,49,53,'2026-08-10','absent',NULL,NULL,'2026-08-10 01:00:00'),(636,50,53,'2026-08-11','present',NULL,NULL,'2026-08-11 01:00:00'),(638,49,53,'2026-08-13','present',NULL,NULL,'2026-08-13 01:00:00'),(639,50,53,'2026-08-14','present',NULL,NULL,'2026-08-14 01:00:00'),(641,49,53,'2026-08-18','present',NULL,NULL,'2026-08-18 01:00:00'),(642,50,53,'2026-08-19','present',NULL,NULL,'2026-08-19 01:00:00'),(644,49,53,'2026-08-21','present',NULL,NULL,'2026-08-21 01:00:00'),(645,50,53,'2026-08-24','present',NULL,NULL,'2026-08-24 01:00:00'),(647,49,53,'2026-08-26','present',NULL,NULL,'2026-08-26 01:00:00'),(648,50,53,'2026-08-27','present',NULL,NULL,'2026-08-27 01:00:00'),(650,49,53,'2026-08-31','present',NULL,NULL,'2026-08-31 01:00:00'),(651,50,53,'2026-09-01','absent',NULL,NULL,'2026-09-01 01:00:00'),(653,49,53,'2026-09-03','present',NULL,NULL,'2026-09-03 01:00:00'),(654,50,53,'2026-09-04','present',NULL,NULL,'2026-09-04 01:00:00'),(656,49,53,'2026-09-08','present',NULL,NULL,'2026-09-08 01:00:00'),(657,50,53,'2026-09-09','present',NULL,NULL,'2026-09-09 01:00:00'),(659,49,53,'2026-09-11','absent',NULL,NULL,'2026-09-11 01:00:00'),(660,50,53,'2026-09-14','present',NULL,NULL,'2026-09-14 01:00:00'),(662,49,53,'2026-09-16','present',NULL,NULL,'2026-09-16 01:00:00'),(663,50,53,'2026-09-17','present',NULL,NULL,'2026-09-17 01:00:00'),(665,49,54,'2026-08-10','present',NULL,NULL,'2026-08-10 01:00:00'),(666,50,54,'2026-08-11','present',NULL,NULL,'2026-08-11 01:00:00'),(668,49,54,'2026-08-13','present',NULL,NULL,'2026-08-13 01:00:00'),(669,50,54,'2026-08-14','present',NULL,NULL,'2026-08-14 01:00:00'),(671,49,54,'2026-08-18','present',NULL,NULL,'2026-08-18 01:00:00'),(672,50,54,'2026-08-19','present',NULL,NULL,'2026-08-19 01:00:00'),(674,49,54,'2026-08-21','present',NULL,NULL,'2026-08-21 01:00:00'),(675,50,54,'2026-08-24','present',NULL,NULL,'2026-08-24 01:00:00'),(677,49,54,'2026-08-26','present',NULL,NULL,'2026-08-26 01:00:00'),(678,50,54,'2026-08-27','present',NULL,NULL,'2026-08-27 01:00:00'),(680,49,54,'2026-08-31','late',NULL,NULL,'2026-08-31 01:00:00'),(681,50,54,'2026-09-01','present',NULL,NULL,'2026-09-01 01:00:00'),(683,49,54,'2026-09-03','present',NULL,NULL,'2026-09-03 01:00:00'),(684,50,54,'2026-09-04','present',NULL,NULL,'2026-09-04 01:00:00'),(686,49,54,'2026-09-08','present',NULL,NULL,'2026-09-08 01:00:00'),(687,50,54,'2026-09-09','present',NULL,NULL,'2026-09-09 01:00:00'),(689,49,54,'2026-09-11','present',NULL,NULL,'2026-09-11 01:00:00'),(690,50,54,'2026-09-14','present',NULL,NULL,'2026-09-14 01:00:00'),(692,49,54,'2026-09-16','present',NULL,NULL,'2026-09-16 01:00:00'),(693,50,54,'2026-09-17','present',NULL,NULL,'2026-09-17 01:00:00'),(695,49,55,'2026-08-10','present',NULL,NULL,'2026-08-10 01:00:00'),(696,50,55,'2026-08-11','present',NULL,NULL,'2026-08-11 01:00:00'),(698,49,55,'2026-08-13','present',NULL,NULL,'2026-08-13 01:00:00'),(699,50,55,'2026-08-14','present',NULL,NULL,'2026-08-14 01:00:00'),(701,49,55,'2026-08-18','present',NULL,NULL,'2026-08-18 01:00:00'),(702,50,55,'2026-08-19','present',NULL,NULL,'2026-08-19 01:00:00'),(704,49,55,'2026-08-21','present',NULL,NULL,'2026-08-21 01:00:00'),(705,50,55,'2026-08-24','present',NULL,NULL,'2026-08-24 01:00:00'),(707,49,55,'2026-08-26','present',NULL,NULL,'2026-08-26 01:00:00'),(708,50,55,'2026-08-27','present',NULL,NULL,'2026-08-27 01:00:00'),(710,49,55,'2026-08-31','late',NULL,NULL,'2026-08-31 01:00:00'),(711,50,55,'2026-09-01','present',NULL,NULL,'2026-09-01 01:00:00'),(713,49,55,'2026-09-03','present',NULL,NULL,'2026-09-03 01:00:00'),(714,50,55,'2026-09-04','present',NULL,NULL,'2026-09-04 01:00:00'),(716,49,55,'2026-09-08','present',NULL,NULL,'2026-09-08 01:00:00'),(717,50,55,'2026-09-09','present',NULL,NULL,'2026-09-09 01:00:00'),(719,49,55,'2026-09-11','present',NULL,NULL,'2026-09-11 01:00:00'),(720,50,55,'2026-09-14','present',NULL,NULL,'2026-09-14 01:00:00'),(722,49,55,'2026-09-16','present',NULL,NULL,'2026-09-16 01:00:00'),(723,50,55,'2026-09-17','present',NULL,NULL,'2026-09-17 01:00:00');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_sessions`
--

DROP TABLE IF EXISTS `attendance_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `session_date` date NOT NULL,
  `session_time` time DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subject_id` (`subject_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `attendance_sessions_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_sessions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_sessions`
--

LOCK TABLES `attendance_sessions` WRITE;
/*!40000 ALTER TABLE `attendance_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_schedules`
--

DROP TABLE IF EXISTS `class_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `days` varchar(255) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `room` varchar(255) DEFAULT NULL,
  `meeting_link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `class_schedules_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_schedules`
--

LOCK TABLES `class_schedules` WRITE;
/*!40000 ALTER TABLE `class_schedules` DISABLE KEYS */;
INSERT INTO `class_schedules` VALUES (6,48,NULL,NULL,NULL,NULL,NULL,'2026-10-05 16:10:44'),(19,65,NULL,NULL,NULL,NULL,'learnalytics-class-65-c979bea664f808c8a7492e94cc596417','2026-11-12 16:36:05');
/*!40000 ALTER TABLE `class_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classroom_participants`
--

DROP TABLE IF EXISTS `classroom_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `classroom_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `classroom_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_participant` (`classroom_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `classroom_participants_ibfk_1` FOREIGN KEY (`classroom_id`) REFERENCES `virtual_classrooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `classroom_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classroom_participants`
--

LOCK TABLES `classroom_participants` WRITE;
/*!40000 ALTER TABLE `classroom_participants` DISABLE KEYS */;
INSERT INTO `classroom_participants` VALUES (1,1,11,'2026-05-01 11:43:46');
/*!40000 ALTER TABLE `classroom_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_group_chats`
--

DROP TABLE IF EXISTS `course_group_chats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_group_chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `course_group_chats_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_group_chats`
--

LOCK TABLES `course_group_chats` WRITE;
/*!40000 ALTER TABLE `course_group_chats` DISABLE KEYS */;
/*!40000 ALTER TABLE `course_group_chats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_group_members`
--

DROP TABLE IF EXISTS `course_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_group_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_member` (`group_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `course_group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `course_group_chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_group_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_group_members`
--

LOCK TABLES `course_group_members` WRITE;
/*!40000 ALTER TABLE `course_group_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `course_group_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_group_messages`
--

DROP TABLE IF EXISTS `course_group_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_group_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `course_group_messages_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `course_group_chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_group_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_group_messages`
--

LOCK TABLES `course_group_messages` WRITE;
/*!40000 ALTER TABLE `course_group_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `course_group_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'College of Management, Accountancy and Technology','','active','2026-05-27 06:55:38'),(3,'College of Education, Arts and Sciences','','active','2026-05-31 14:40:44'),(5,'College of Criminology','','active','2026-05-31 14:42:52');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enrollment_requests`
--

DROP TABLE IF EXISTS `enrollment_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollment_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_subject_request` (`student_id`,`subject_id`),
  KEY `idx_subject_status` (`subject_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enrollment_requests`
--

LOCK TABLES `enrollment_requests` WRITE;
/*!40000 ALTER TABLE `enrollment_requests` DISABLE KEYS */;
INSERT INTO `enrollment_requests` VALUES (14,56,65,'approved','2026-11-12 16:40:08','2026-11-12 16:40:43');
/*!40000 ALTER TABLE `enrollment_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enrollments`
--

DROP TABLE IF EXISTS `enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `course_id` (`subject_id`),
  CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=149 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enrollments`
--

LOCK TABLES `enrollments` WRITE;
/*!40000 ALTER TABLE `enrollments` DISABLE KEYS */;
INSERT INTO `enrollments` VALUES (104,50,49,'2026-08-06 17:02:01'),(105,50,50,'2026-08-06 17:02:01'),(107,51,49,'2026-08-06 17:02:01'),(108,51,50,'2026-08-06 17:02:01'),(110,51,52,'2026-08-06 17:02:01'),(111,52,49,'2026-08-06 17:02:01'),(112,52,50,'2026-08-06 17:02:01'),(114,52,52,'2026-08-06 17:02:01'),(115,53,49,'2026-08-06 17:02:01'),(116,53,50,'2026-08-06 17:02:01'),(118,53,52,'2026-08-06 17:02:01'),(119,54,49,'2026-08-06 17:02:01'),(120,54,50,'2026-08-06 17:02:01'),(122,54,52,'2026-08-06 17:02:01'),(123,55,49,'2026-08-06 17:02:01'),(124,55,50,'2026-08-06 17:02:01'),(126,55,52,'2026-08-06 17:02:01'),(148,56,65,'2026-11-12 16:40:43');
/*!40000 ALTER TABLE `enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_records`
--

DROP TABLE IF EXISTS `exam_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instructor_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `exam_date` date NOT NULL,
  `total_items` int(11) NOT NULL DEFAULT 100,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `section_id` (`section_id`),
  KEY `idx_exam_records_instructor` (`instructor_id`),
  KEY `idx_exam_records_subject` (`subject_id`),
  CONSTRAINT `exam_records_ibfk_1` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_records_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_records_ibfk_3` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_records`
--

LOCK TABLES `exam_records` WRITE;
/*!40000 ALTER TABLE `exam_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_scores`
--

DROP TABLE IF EXISTS `exam_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `score` decimal(10,2) NOT NULL DEFAULT 0.00,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_exam_student` (`exam_id`,`student_id`),
  KEY `idx_exam_scores_exam` (`exam_id`),
  KEY `idx_exam_scores_student` (`student_id`),
  CONSTRAINT `exam_scores_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exam_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_scores_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_scores`
--

LOCK TABLES `exam_scores` WRITE;
/*!40000 ALTER TABLE `exam_scores` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_scores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lesson_attachments`
--

DROP TABLE IF EXISTS `lesson_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lesson_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lesson_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `file_type` varchar(50) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `lesson_id` (`lesson_id`),
  CONSTRAINT `lesson_attachments_ibfk_1` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lesson_attachments`
--

LOCK TABLES `lesson_attachments` WRITE;
/*!40000 ALTER TABLE `lesson_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `lesson_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lessons`
--

DROP TABLE IF EXISTS `lessons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lessons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `video_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `file_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `course_id` (`subject_id`),
  CONSTRAINT `lessons_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lessons`
--

LOCK TABLES `lessons` WRITE;
/*!40000 ALTER TABLE `lessons` DISABLE KEYS */;
/*!40000 ALTER TABLE `lessons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
INSERT INTO `messages` VALUES (8,56,65,'VDHJAshcsahchsahchasvsavkhd',1,'2026-11-12 03:21:36'),(9,65,56,'Fhhhh',1,'2026-11-12 03:22:03'),(10,65,56,'Fhhhh',1,'2026-11-12 03:22:04'),(11,56,60,'bdfjghfjdhvjksdhvjS',0,'2026-11-12 03:22:49');
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (7,50,'[Dept Announcement] ;,l,l',0,'2026-10-15 16:14:36'),(8,51,'[Dept Announcement] ;,l,l',0,'2026-10-15 16:14:37'),(9,52,'[Dept Announcement] ;,l,l',0,'2026-10-15 16:14:37'),(10,53,'[Dept Announcement] ;,l,l',1,'2026-10-15 16:14:37'),(11,54,'[Dept Announcement] ;,l,l',0,'2026-10-15 16:14:37'),(12,55,'[Dept Announcement] ;,l,l',0,'2026-10-15 16:14:37'),(15,50,'[Dept Announcement] b nmb',0,'2026-10-15 16:14:57'),(16,51,'[Dept Announcement] b nmb',0,'2026-10-15 16:14:57'),(17,52,'[Dept Announcement] b nmb',0,'2026-10-15 16:14:57'),(18,53,'[Dept Announcement] b nmb',1,'2026-10-15 16:14:57'),(19,54,'[Dept Announcement] b nmb',0,'2026-10-15 16:14:57'),(20,55,'[Dept Announcement] b nmb',0,'2026-10-15 16:14:57'),(22,50,'[Dept Announcement] sdf',0,'2026-10-16 10:21:37'),(23,51,'[Dept Announcement] sdf',0,'2026-10-16 10:21:37'),(24,52,'[Dept Announcement] sdf',0,'2026-10-16 10:21:37'),(25,53,'[Dept Announcement] sdf',0,'2026-10-16 10:21:37'),(26,54,'[Dept Announcement] sdf',0,'2026-10-16 10:21:37'),(27,55,'[Dept Announcement] sdf',0,'2026-10-16 10:21:37'),(28,50,'[Dept Announcement] sdf',0,'2026-10-19 04:49:43'),(29,51,'[Dept Announcement] sdf',0,'2026-10-19 04:49:43'),(30,52,'[Dept Announcement] sdf',0,'2026-10-19 04:49:43'),(31,53,'[Dept Announcement] sdf',0,'2026-10-19 04:49:43'),(32,54,'[Dept Announcement] sdf',0,'2026-10-19 04:49:43'),(33,55,'[Dept Announcement] sdf',0,'2026-10-19 04:49:43'),(34,50,'[Dept Announcement] sdf',0,'2026-10-19 05:06:08'),(35,51,'[Dept Announcement] sdf',0,'2026-10-19 05:06:08'),(36,52,'[Dept Announcement] sdf',0,'2026-10-19 05:06:08'),(37,53,'[Dept Announcement] sdf',0,'2026-10-19 05:06:08'),(38,54,'[Dept Announcement] sdf',0,'2026-10-19 05:06:08'),(39,55,'[Dept Announcement] sdf',0,'2026-10-19 05:06:08'),(40,60,'[Class Announcement] IT 201: no meeting tommorow',1,'2026-10-20 06:35:50'),(41,58,'[Class Announcement] IT 201: no meeting tommorow',0,'2026-10-20 06:35:50'),(42,59,'[Class Announcement] IT 201: no meeting tommorow',0,'2026-10-20 06:35:50'),(43,61,'[Class Announcement] GE 12: indi ta danay mag klaseee haaa sowwy',0,'2026-10-21 09:57:38'),(44,56,'[Class Announcement] ITE 101: R5TGYHIJOKL,',1,'2026-10-22 06:59:28'),(45,56,'Christian Earl L Pacol posted a message in the group chat.',0,'2026-11-12 03:21:14'),(46,65,'Christian Earl L Pacol posted a message in the group chat.',0,'2026-11-12 03:21:14'),(47,56,'Marielle C Morales posted a message in the group chat.',0,'2026-11-12 03:21:21'),(48,60,'Marielle C Morales posted a message in the group chat.',0,'2026-11-12 03:21:21'),(49,65,'Rojhina Grampon sent you a message',0,'2026-11-12 03:21:36'),(50,56,'Marielle C Morales sent you a message',0,'2026-11-12 03:22:03'),(51,56,'Marielle C Morales sent you a message',0,'2026-11-12 03:22:04'),(52,60,'Rojhina Grampon sent you a message',0,'2026-11-12 03:22:49'),(53,56,'Ro Ann Mae A Jalapa posted a message in the group chat.',0,'2026-11-12 03:23:11'),(54,60,'Ro Ann Mae A Jalapa posted a message in the group chat.',0,'2026-11-12 03:23:11'),(55,65,'Ro Ann Mae A Jalapa posted a message in the group chat.',0,'2026-11-12 03:23:11');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `programs`
--

DROP TABLE IF EXISTS `programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `programs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `department_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_programs_department_required` (`department_id`),
  CONSTRAINT `fk_programs_department_required` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `programs`
--

LOCK TABLES `programs` WRITE;
/*!40000 ALTER TABLE `programs` DISABLE KEYS */;
INSERT INTO `programs` VALUES (1,'Information  Technology','','active','2026-05-01 11:22:56',NULL),(2,'Bachelor of Science in Computer Science','A comprehensive program covering software development, algorithms, and systems design.','active','2026-05-05 16:16:50',NULL),(3,'Certificate in Web Development','Learn to build modern web applications with latest frameworks and technologies.','active','2026-05-05 16:16:50',NULL),(4,'Bachelor of Science in Computer Science','A comprehensive program covering software development, algorithms, and systems design.','active','2026-05-05 16:25:13',NULL),(5,'Certificate in Web Development','Learn to build modern web applications with latest frameworks and technologies.','active','2026-05-05 16:25:13',NULL),(6,'Test Program 1780239317','Created by test script','active','2026-05-31 14:55:17',NULL),(7,'deqgfef','','active','2026-05-31 14:57:22',5),(10,'Information Tech',NULL,'active','2026-10-05 16:19:03',1),(11,'BS Information Technology','Bachelor of Science in Information Technology','active','2026-10-05 16:50:46',1),(12,'Bachelor of Science in Business Administration - Financial Management',NULL,'active','2026-10-21 09:15:37',1);
/*!40000 ALTER TABLE `programs` ENABLE KEYS */;
UNLOCK TABLES;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER programs_require_department_insert BEFORE INSERT ON programs FOR EACH ROW BEGIN IF NEW.department_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A department is required for every program'; END IF; END */;;
DELIMITER ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER programs_require_department_update BEFORE UPDATE ON programs FOR EACH ROW BEGIN IF NEW.department_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A department is required for every program'; END IF; END */;;
DELIMITER ;

--
-- Table structure for table `questions`
--

DROP TABLE IF EXISTS `questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `type` enum('multiple_choice','checkboxes','short_answer','paragraph','true_false','dropdown') NOT NULL,
  `correct_answer` text NOT NULL,
  `options` text DEFAULT NULL,
  `points` int(11) NOT NULL DEFAULT 1,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `explanation` text DEFAULT NULL,
  `image_url` varchar(2048) DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `feedback_correct` text DEFAULT NULL,
  `feedback_incorrect` text DEFAULT NULL,
  `manual_grading` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `quiz_id` (`quiz_id`),
  CONSTRAINT `questions_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=217 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `questions`
--

LOCK TABLES `questions` WRITE;
/*!40000 ALTER TABLE `questions` DISABLE KEYS */;
INSERT INTO `questions` VALUES (214,127,'what?','true_false','True','[\"True\",\"False\"]',1,1,'dgchbj',NULL,1,'Excellent','Good',0),(215,127,'Ha?','true_false','True','[\"True\",\"False\"]',1,1,'fxdcgbj',NULL,2,'Excellent','Good',0),(216,127,'Ha?','true_false','True','[\"True\",\"False\"]',1,1,'dghjk',NULL,3,'Excellent','Good',0);
/*!40000 ALTER TABLE `questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary table structure for view `quiz_analytics`
--

DROP TABLE IF EXISTS `quiz_analytics`;
/*!50001 DROP VIEW IF EXISTS `quiz_analytics`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `quiz_analytics` AS SELECT
 1 AS `quiz_id`,
  1 AS `quiz_title`,
  1 AS `status`,
  1 AS `due_date`,
  1 AS `subject_title`,
  1 AS `instructor_name`,
  1 AS `instructor_id`,
  1 AS `total_attempts`,
  1 AS `total_submissions`,
  1 AS `average_score`,
  1 AS `highest_score`,
  1 AS `lowest_score`,
  1 AS `created_at`,
  1 AS `published_at` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `quiz_assignments`
--

DROP TABLE IF EXISTS `quiz_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quiz_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `assignment_type` enum('subject','section','program','year_level','student') NOT NULL DEFAULT 'subject',
  `target_id` int(11) DEFAULT NULL COMMENT 'ID of the target (user_id, section_id, program_id) - NULL for subject-wide',
  `year_level` varchar(50) DEFAULT NULL COMMENT 'Year level for year_level assignment type',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quiz_assignments_quiz` (`quiz_id`),
  KEY `idx_quiz_assignments_type` (`assignment_type`,`target_id`),
  CONSTRAINT `quiz_assignments_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_assignments`
--

LOCK TABLES `quiz_assignments` WRITE;
/*!40000 ALTER TABLE `quiz_assignments` DISABLE KEYS */;
INSERT INTO `quiz_assignments` VALUES (44,99,'subject',NULL,NULL,'2026-11-12 14:21:03'),(45,100,'subject',NULL,NULL,'2026-11-12 14:21:03'),(46,101,'subject',NULL,NULL,'2026-11-12 14:21:03'),(47,102,'subject',NULL,NULL,'2026-11-12 14:21:03'),(48,103,'subject',NULL,NULL,'2026-11-12 14:21:03'),(68,127,'subject',NULL,NULL,'2026-11-12 16:37:08'),(69,127,'student',56,NULL,'2026-11-12 16:47:19');
/*!40000 ALTER TABLE `quiz_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_results`
--

DROP TABLE IF EXISTS `quiz_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quiz_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `score` decimal(10,2) NOT NULL DEFAULT 0.00,
  `attempt_number` int(11) DEFAULT 1,
  `time_spent` int(11) DEFAULT NULL COMMENT 'Time spent in seconds',
  `ip_address` varchar(45) DEFAULT NULL,
  `answers` text DEFAULT NULL COMMENT 'JSON of student answers',
  `taken_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `max_score` decimal(10,2) NOT NULL DEFAULT 0.00,
  `grading_status` enum('pending','graded') NOT NULL DEFAULT 'graded',
  `response_scores` text DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quiz_id` (`quiz_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `quiz_results_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quiz_results_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=126 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_results`
--

LOCK TABLES `quiz_results` WRITE;
/*!40000 ALTER TABLE `quiz_results` DISABLE KEYS */;
INSERT INTO `quiz_results` VALUES (81,102,50,84.00,1,NULL,NULL,NULL,'2026-08-15 06:40:00',0.00,'graded',NULL,NULL),(83,99,50,70.00,1,NULL,NULL,NULL,'2026-09-02 02:45:00',0.00,'graded',NULL,NULL),(84,100,50,60.00,1,NULL,NULL,NULL,'2026-09-12 02:50:00',0.00,'graded',NULL,NULL),(86,101,50,90.00,1,NULL,NULL,NULL,'2026-09-30 02:35:00',0.00,'graded',NULL,NULL),(87,102,51,80.00,1,NULL,NULL,NULL,'2026-08-16 07:00:00',0.00,'graded',NULL,NULL),(89,99,51,78.00,1,NULL,NULL,NULL,'2026-08-29 03:00:00',0.00,'graded',NULL,NULL),(90,100,51,72.00,1,NULL,NULL,NULL,'2026-09-12 03:00:00',0.00,'graded',NULL,NULL),(92,103,51,74.00,1,NULL,NULL,NULL,'2026-09-25 07:00:00',0.00,'graded',NULL,NULL),(93,101,51,72.00,1,NULL,NULL,NULL,'2026-09-28 03:00:00',0.00,'graded',NULL,NULL),(94,99,52,76.00,1,NULL,NULL,NULL,'2026-08-25 02:00:00',0.00,'graded',NULL,NULL),(95,100,52,78.00,1,NULL,NULL,NULL,'2026-09-12 02:00:00',0.00,'graded',NULL,NULL),(96,101,52,80.00,1,NULL,NULL,NULL,'2026-09-28 02:00:00',0.00,'graded',NULL,NULL),(97,102,52,78.00,1,NULL,NULL,NULL,'2026-08-28 06:00:00',0.00,'graded',NULL,NULL),(98,103,52,76.00,1,NULL,NULL,NULL,'2026-09-18 06:00:00',0.00,'graded',NULL,NULL),(99,99,53,76.00,1,NULL,NULL,NULL,'2026-08-25 02:00:00',0.00,'graded',NULL,NULL),(100,100,53,78.00,1,NULL,NULL,NULL,'2026-09-12 02:00:00',0.00,'graded',NULL,NULL),(101,101,53,80.00,1,NULL,NULL,NULL,'2026-09-28 02:00:00',0.00,'graded',NULL,NULL),(102,102,53,78.00,1,NULL,NULL,NULL,'2026-08-28 06:00:00',0.00,'graded',NULL,NULL),(103,103,53,76.00,1,NULL,NULL,NULL,'2026-09-18 06:00:00',0.00,'graded',NULL,NULL),(104,99,54,90.00,1,NULL,NULL,NULL,'2026-08-25 02:00:00',0.00,'graded',NULL,NULL),(105,100,54,92.00,1,NULL,NULL,NULL,'2026-09-12 02:00:00',0.00,'graded',NULL,NULL),(106,101,54,95.00,1,NULL,NULL,NULL,'2026-09-28 02:00:00',0.00,'graded',NULL,NULL),(107,102,54,92.00,1,NULL,NULL,NULL,'2026-08-28 06:00:00',0.00,'graded',NULL,NULL),(108,103,54,88.00,1,NULL,NULL,NULL,'2026-09-18 06:00:00',0.00,'graded',NULL,NULL),(109,99,55,90.00,1,NULL,NULL,NULL,'2026-08-25 02:00:00',0.00,'graded',NULL,NULL),(110,100,55,92.00,1,NULL,NULL,NULL,'2026-09-12 02:00:00',0.00,'graded',NULL,NULL),(111,101,55,95.00,1,NULL,NULL,NULL,'2026-09-28 02:00:00',0.00,'graded',NULL,NULL),(112,102,55,92.00,1,NULL,NULL,NULL,'2026-08-28 06:00:00',0.00,'graded',NULL,NULL),(113,103,55,88.00,1,NULL,NULL,NULL,'2026-09-18 06:00:00',0.00,'graded',NULL,NULL),(125,127,56,3.00,1,7,'::1','{\"214\":\"True\",\"215\":\"True\",\"216\":\"True\"}','2026-11-12 17:18:48',3.00,'graded','{\"214\":1,\"215\":1,\"216\":1}',NULL);
/*!40000 ALTER TABLE `quiz_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quizzes`
--

DROP TABLE IF EXISTS `quizzes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `instructor_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('draft','published','closed','archived') DEFAULT 'draft',
  `due_date` datetime DEFAULT NULL,
  `time_limit` int(11) DEFAULT NULL COMMENT 'Time limit in minutes',
  `attempt_limit` int(11) DEFAULT 1 COMMENT 'Number of attempts allowed (0 = unlimited)',
  `show_results` tinyint(1) DEFAULT 1 COMMENT 'Show results to students after submission',
  `shuffle_questions` tinyint(1) DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `instructions` text DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  `semester` varchar(30) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `availability_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `opens_at` datetime DEFAULT NULL,
  `timer_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `results_after_review` tinyint(1) NOT NULL DEFAULT 0,
  `show_correct_answers` tinyint(1) NOT NULL DEFAULT 0,
  `show_feedback` tinyint(1) NOT NULL DEFAULT 0,
  `results_release_at` datetime DEFAULT NULL,
  `shuffle_choices` tinyint(1) NOT NULL DEFAULT 0,
  `one_question_per_page` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `course_id` (`subject_id`),
  KEY `fk_quizzes_instructor` (`instructor_id`),
  CONSTRAINT `fk_quizzes_instructor` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quizzes_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quizzes`
--

LOCK TABLES `quizzes` WRITE;
/*!40000 ALTER TABLE `quizzes` DISABLE KEYS */;
INSERT INTO `quizzes` VALUES (99,49,47,'Quiz 1: Relational Schema & Normalization','Regular assessment quiz','published',NULL,NULL,1,1,0,'2026-08-25 10:00:00','2026-08-25 02:00:00',NULL,NULL,NULL,NULL,1,0,NULL,0,0,0,0,NULL,0,0),(100,49,47,'Quiz 2: Complex SQL Queries & Joins','Regular assessment quiz','published',NULL,NULL,1,1,0,'2026-09-12 10:00:00','2026-09-12 02:00:00',NULL,NULL,NULL,NULL,1,0,NULL,0,0,0,0,NULL,0,0),(101,49,47,'Quiz 3: Indexing and Transactions','Regular assessment quiz','published',NULL,NULL,1,1,0,'2026-09-28 10:00:00','2026-09-28 02:00:00',NULL,NULL,NULL,NULL,1,0,NULL,0,0,0,0,NULL,0,0),(102,50,47,'Quiz 1: HTML5 & Modern CSS Grid','Regular assessment quiz','published',NULL,NULL,1,1,0,'2026-08-28 14:00:00','2026-08-28 06:00:00',NULL,NULL,NULL,NULL,1,0,NULL,0,0,0,0,NULL,0,0),(103,50,47,'Quiz 2: JavaScript ES6 & DOM Manipulation','Regular assessment quiz','published',NULL,NULL,1,1,0,'2026-09-18 14:00:00','2026-09-18 06:00:00',NULL,NULL,NULL,NULL,1,0,NULL,0,0,0,0,NULL,0,0),(127,65,49,'Quiz 1','','published','2026-11-16 00:36:00',NULL,1,0,0,'2026-11-13 01:18:20','2026-11-12 16:37:08','',NULL,4,NULL,1,0,NULL,0,0,0,0,NULL,0,0);
/*!40000 ALTER TABLE `quizzes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `risk_assessments`
--

DROP TABLE IF EXISTS `risk_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `risk_assessments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `assessed_at` datetime NOT NULL,
  `overall_score` int(11) NOT NULL DEFAULT 0,
  `risk_level` enum('low','moderate','at_risk') NOT NULL DEFAULT 'low',
  `grade_avg` decimal(5,2) DEFAULT NULL,
  `previous_grade_avg` decimal(5,2) DEFAULT NULL,
  `grade_change_pct` decimal(5,2) DEFAULT 0.00,
  `quiz_avg` decimal(5,2) DEFAULT NULL,
  `attendance_rate` decimal(5,2) DEFAULT NULL,
  `absent_count` int(11) DEFAULT 0,
  `late_count` int(11) DEFAULT 0,
  `present_count` int(11) DEFAULT 0,
  `failing_courses_count` int(11) DEFAULT 0,
  `trend_direction` enum('improving','stable','declining') DEFAULT 'stable',
  `reasons` text DEFAULT NULL,
  `recommended_intervention` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student_assessed` (`student_id`,`assessed_at`),
  KEY `idx_dept_risk` (`department_id`,`risk_level`),
  CONSTRAINT `risk_assessments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `risk_assessments_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `risk_assessments`
--

LOCK TABLES `risk_assessments` WRITE;
/*!40000 ALTER TABLE `risk_assessments` DISABLE KEYS */;
INSERT INTO `risk_assessments` VALUES (9,50,1,'2026-09-01 08:00:00',45,'moderate',78.00,80.00,-2.00,70.00,84.00,4,1,21,0,'stable','Low quiz scores in IT301 (70%). Overall grade is moderate.','Study Plan, Tutoring','2026-10-05 17:02:01'),(10,50,1,'2026-09-15 08:00:00',78,'at_risk',72.00,80.00,-8.00,65.00,78.00,9,3,32,2,'declining','Overall grade (72%) is below threshold (75%).\nAttendance (78%) below threshold (80%).\nAcademic performance declined by 8%.\n2 courses below passing threshold.','Academic Advising, Tutoring, Attendance Intervention','2026-10-05 17:02:01'),(11,50,1,'2026-10-01 08:00:00',68,'at_risk',76.00,72.00,4.00,75.00,81.00,10,4,36,1,'improving','Improving after advising. 1 course still under passing threshold.','Tutoring, Academic Monitoring','2026-10-05 17:02:01'),(12,50,1,'2026-10-06 00:00:00',75,'at_risk',72.00,80.00,-8.00,73.30,78.00,11,4,39,2,'declining','Overall grade is below configured threshold (75%).\nQuiz average is below configured threshold (75%).\nAttendance is below configured threshold (80%).\nAcademic performance declined by 8% compared with previous period.','Academic Advising, Tutoring, Attendance Intervention','2026-10-05 17:02:01'),(13,53,1,'2026-10-06 01:13:11',25,'moderate',77.50,78.00,-0.80,77.60,86.70,4,0,26,0,'stable','Student is performing consistently within or above academic thresholds.','Regular Academic Monitoring','2026-10-05 17:13:11'),(14,54,1,'2026-10-06 01:13:19',0,'low',91.50,92.00,-1.00,91.40,98.30,0,1,29,0,'stable','Student is performing consistently within or above academic thresholds.','Regular Academic Monitoring','2026-10-05 17:13:19'),(15,51,1,'2026-10-06 01:13:38',67,'at_risk',75.00,78.80,-7.10,74.90,78.40,8,3,33,2,'stable','Quiz average (74.9%) is below the configured threshold (75%).\nAttendance rate (78.4%) is below the configured threshold (80%), with 8 absence(s) and 3 late record(s).\n2 course(s) currently below the passing threshold (75%).','Tutoring, Study Plan, Attendance Intervention, Parent/Guardian Notification, Faculty Consultation','2026-10-05 17:13:38'),(16,53,1,'2026-10-19 13:50:46',100,'at_risk',69.00,77.70,-17.00,65.00,84.40,5,0,27,2,'declining','Overall academic average (69%) is below the configured threshold (75%).\nQuiz average (65%) is below the configured threshold (75%).\nAcademic performance declined by 17% compared with previous period.\n2 course(s) currently below the passing threshold (75%).','Academic Advising, Tutoring, Study Plan, Faculty Consultation, Academic Monitoring','2026-10-19 05:50:46'),(17,50,1,'2026-10-19 13:50:54',100,'at_risk',64.10,77.20,-19.20,74.70,76.40,11,4,40,2,'declining','Overall academic average (64.1%) is below the configured threshold (75%).\nQuiz average (74.7%) is below the configured threshold (75%).\nAttendance rate (76.4%) is below the configured threshold (80%), with 11 absence(s) and 4 late record(s).\nAcademic performance declined by 19.2% compared with previous period.\n2 course(s) currently below the passing threshold (75%).','Academic Advising, Tutoring, Study Plan, Attendance Intervention, Parent/Guardian Notification, Faculty Consultation, Academic Monitoring','2026-10-19 05:50:54');
/*!40000 ALTER TABLE `risk_assessments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `risk_configurations`
--

DROP TABLE IF EXISTS `risk_configurations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `risk_configurations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department_id` int(11) DEFAULT NULL,
  `grade_threshold` decimal(5,2) DEFAULT 75.00,
  `attendance_threshold` decimal(5,2) DEFAULT 80.00,
  `quiz_threshold` decimal(5,2) DEFAULT 75.00,
  `decline_threshold` decimal(5,2) DEFAULT 10.00,
  `passing_grade` decimal(5,2) DEFAULT 75.00,
  `moderate_grade_threshold` decimal(5,2) DEFAULT 80.00,
  `moderate_attendance_threshold` decimal(5,2) DEFAULT 85.00,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dept_config` (`department_id`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `risk_configurations_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `risk_configurations_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `risk_configurations`
--

LOCK TABLES `risk_configurations` WRITE;
/*!40000 ALTER TABLE `risk_configurations` DISABLE KEYS */;
INSERT INTO `risk_configurations` VALUES (1,NULL,75.00,80.00,75.00,10.00,75.00,80.00,85.00,NULL,'2026-10-05 16:48:06'),(2,1,75.00,80.00,75.00,10.00,75.00,80.00,85.00,34,'2026-10-05 17:13:57'),(3,3,75.00,80.00,75.00,10.00,75.00,80.00,85.00,35,'2026-10-15 17:04:43');
/*!40000 ALTER TABLE `risk_configurations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sections`
--

DROP TABLE IF EXISTS `sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `semester_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `program_id` (`program_id`),
  KEY `academic_year_id` (`academic_year_id`),
  KEY `fk_sections_semester` (`semester_id`),
  CONSTRAINT `fk_sections_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`semester_id`) ON DELETE SET NULL,
  CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sections_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sections`
--

LOCK TABLES `sections` WRITE;
/*!40000 ALTER TABLE `sections` DISABLE KEYS */;
INSERT INTO `sections` VALUES (1,1,1,'B','2026-05-01 12:16:00',NULL),(2,2,2,'Section A','2026-05-05 16:16:50',NULL),(3,2,2,'Section B','2026-05-05 16:16:50',NULL),(4,3,2,'Web Dev Batch 1','2026-05-05 16:16:50',NULL),(5,4,3,'Section A','2026-05-05 16:25:13',NULL),(6,4,3,'Section B','2026-05-05 16:25:13',NULL),(7,5,3,'Web Dev Batch 1','2026-05-05 16:25:13',NULL),(8,6,4,'Test Section 1780239317','2026-05-31 14:55:17',NULL),(9,2,4,'b','2026-05-31 14:58:57',NULL),(10,11,5,'BSIT 3-A','2026-10-05 16:50:46',NULL);
/*!40000 ALTER TABLE `sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `semesters`
--

DROP TABLE IF EXISTS `semesters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `semesters` (
  `semester_id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year_id` int(11) NOT NULL,
  `semester_name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`semester_id`),
  UNIQUE KEY `uq_semester_year_name` (`academic_year_id`,`semester_name`),
  KEY `idx_semester_dates` (`start_date`,`end_date`),
  CONSTRAINT `semesters_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `semesters`
--

LOCK TABLES `semesters` WRITE;
/*!40000 ALTER TABLE `semesters` DISABLE KEYS */;
/*!40000 ALTER TABLE `semesters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_archived_subjects`
--

DROP TABLE IF EXISTS `student_archived_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_archived_subjects` (
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`student_id`,`subject_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `student_archived_subjects_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_archived_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_archived_subjects`
--

LOCK TABLES `student_archived_subjects` WRITE;
/*!40000 ALTER TABLE `student_archived_subjects` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_archived_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_grades`
--

DROP TABLE IF EXISTS `student_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_grades` (
  `grade_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `instructor_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `prelim_grade` decimal(5,2) DEFAULT NULL,
  `midterm_grade` decimal(5,2) DEFAULT NULL,
  `final_grade` decimal(5,2) DEFAULT NULL,
  `final_average` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grade_id`),
  UNIQUE KEY `uq_student_grades_subject_student` (`subject_id`,`student_id`),
  KEY `idx_student_grades_student` (`student_id`),
  KEY `instructor_id` (`instructor_id`),
  CONSTRAINT `student_grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_grades_ibfk_2` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_grades_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_grades`
--

LOCK TABLES `student_grades` WRITE;
/*!40000 ALTER TABLE `student_grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_interventions`
--

DROP TABLE IF EXISTS `student_interventions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_interventions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `intervention_type` varchar(100) NOT NULL,
  `status` enum('recommended','scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'recommended',
  `scheduled_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `grade_before` decimal(5,2) DEFAULT NULL,
  `grade_after_2wk` decimal(5,2) DEFAULT NULL,
  `grade_current` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_student_intervention` (`student_id`),
  KEY `idx_intervention_status` (`status`),
  KEY `assigned_by` (`assigned_by`),
  CONSTRAINT `student_interventions_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_interventions_ibfk_2` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_interventions`
--

LOCK TABLES `student_interventions` WRITE;
/*!40000 ALTER TABLE `student_interventions` DISABLE KEYS */;
INSERT INTO `student_interventions` VALUES (5,50,34,'Academic Advising','completed','2026-10-01','2026-10-06','Student advised to attend additional tutoring sessions and consult with Database Management instructor.',72.00,76.00,81.00,'2026-10-01 01:30:00','2026-10-05 17:02:01'),(6,50,34,'Tutoring','in_progress','2026-10-08',NULL,'Weekly peer tutoring arranged with IT Department Honor Society for IT301 and IT303.',72.00,76.00,81.00,'2026-10-03 06:00:00','2026-10-05 17:02:01');
/*!40000 ALTER TABLE `student_interventions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_profiles`
--

DROP TABLE IF EXISTS `student_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `student_id_number` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_initial` varchar(5) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(50) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `program_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `advisor_id` int(11) DEFAULT NULL,
  `year_level` enum('1st Year','2nd Year','3rd Year','4th Year','Other') DEFAULT '1st Year',
  `enrollment_status` enum('Active','Inactive','Graduated','Suspended','Dropped') DEFAULT 'Active',
  `bio` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`),
  UNIQUE KEY `student_id_number` (`student_id_number`),
  KEY `department_id` (`department_id`),
  KEY `section_id` (`section_id`),
  KEY `advisor_id` (`advisor_id`),
  KEY `idx_student_search` (`student_id_number`,`year_level`,`enrollment_status`),
  KEY `idx_program_department` (`program_id`,`department_id`),
  CONSTRAINT `student_profiles_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_profiles_ibfk_2` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_profiles_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_profiles_ibfk_4` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_profiles_ibfk_5` FOREIGN KEY (`advisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_profiles`
--

LOCK TABLES `student_profiles` WRITE;
/*!40000 ALTER TABLE `student_profiles` DISABLE KEYS */;
INSERT INTO `student_profiles` VALUES (13,50,'20240001','Juan','Cruz',NULL,'2003-05-15','Other','juan.delacruz@learnalytics.com','09123456789',11,1,10,NULL,'3rd Year','Active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(14,51,'20240002','Maria','Santos',NULL,'2003-05-15','Other','maria.santos@learnalytics.com','09123456789',11,1,10,NULL,'2nd Year','Active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(15,52,'20240003','Carlos','Mendoza',NULL,'2003-05-15','Other','carlos.mendoza@learnalytics.com','09123456789',11,1,10,NULL,'3rd Year','Active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(16,53,'20240004','Bea','Dizon',NULL,'2003-05-15','Other','bea.dizon@learnalytics.com','09123456789',11,1,10,NULL,'3rd Year','Active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(17,54,'20240005','Alex','Reyes',NULL,'2003-05-15','Other','alex.reyes@learnalytics.com','09123456789',11,1,10,NULL,'3rd Year','Active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(18,55,'20240006','Patricia','Lim',NULL,'2003-05-15','Other','patricia.lim@learnalytics.com','09123456789',11,1,10,NULL,'3rd Year','Active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(19,56,'234567','Rojhina','Grampon',NULL,'2005-09-24','Female','rojhina@gmail.com','09665796741',11,1,NULL,NULL,'4th Year','Active',NULL,NULL,'2026-10-20 04:30:15','2026-10-20 04:30:15'),(21,58,'73939276','Marielle','Morales','C','2004-08-26','Female','mariellecordero26@gmail.com','09630085365',11,1,NULL,NULL,'4th Year','Active',NULL,NULL,'2026-10-20 05:11:26','2026-10-20 05:11:26'),(22,59,'2023731022','RO ANN MAE','JALAPA','A','2004-10-02','Female','jalaparoann245@gmail.com','09500323044',11,1,NULL,NULL,'4th Year','Active',NULL,NULL,'2026-10-20 05:39:32','2026-10-20 05:39:32'),(23,60,'2023727062','Christian Earl','Pacol','L','2004-11-03','Male','pacolchristianearl18@gmail.com','09275366250',11,1,NULL,NULL,'4th Year','Active',NULL,NULL,'2026-10-20 05:46:54','2026-10-20 05:46:54'),(24,61,'123456789076534567865','Koykoy','Gicos','V.','2003-09-01','Male','koykoy@gmail.com','09098711905',12,1,NULL,NULL,'1st Year','Active',NULL,NULL,'2026-10-21 09:51:42','2026-10-21 09:51:42'),(25,62,'8529645','John','Doe','O.','2005-06-07','Male','palagpat@gmail.com','123456789',12,1,NULL,NULL,'1st Year','Active',NULL,NULL,'2026-10-22 06:31:20','2026-10-22 06:31:20'),(26,63,'26722','Sdh','Bshh',NULL,'2005-11-17','Female','abcd@gmail.com','09543431122',11,1,NULL,NULL,'4th Year','Active',NULL,NULL,'2026-11-12 03:00:45','2026-11-12 03:00:45'),(27,64,'74829282','Ro Ann Mae','Jalapa','A','2004-10-02','Female','jalaparoann@gmail.com','09500323044',11,1,NULL,NULL,'4th Year','Active',NULL,NULL,'2026-11-12 03:06:15','2026-11-12 03:06:15'),(28,65,'20230756','Marielle','Morales','C','2004-08-26','Female','maryel26@gmail.com','09630085365',11,1,NULL,NULL,'4th Year','Active',NULL,NULL,'2026-11-12 03:08:06','2026-11-12 03:08:06'),(29,66,'8675431q32r','JOHN','CANETE',NULL,'1991-12-23','Male','johncanetekcc@gmail.com','09123456789',11,1,NULL,NULL,'4th Year','Active',NULL,NULL,'2026-11-12 06:00:32','2026-11-12 06:00:32');
/*!40000 ALTER TABLE `student_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary table structure for view `student_quiz_access`
--

DROP TABLE IF EXISTS `student_quiz_access`;
/*!50001 DROP VIEW IF EXISTS `student_quiz_access`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `student_quiz_access` AS SELECT
 1 AS `quiz_id`,
  1 AS `title`,
  1 AS `description`,
  1 AS `status`,
  1 AS `due_date`,
  1 AS `time_limit`,
  1 AS `attempt_limit`,
  1 AS `show_results`,
  1 AS `subject_id`,
  1 AS `subject_title`,
  1 AS `instructor_id`,
  1 AS `instructor_name` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `subject_group_chats`
--

DROP TABLE IF EXISTS `subject_group_chats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subject_group_chats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `subject_group_chats_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subject_group_chats`
--

LOCK TABLES `subject_group_chats` WRITE;
/*!40000 ALTER TABLE `subject_group_chats` DISABLE KEYS */;
INSERT INTO `subject_group_chats` VALUES (30,48,'csadfghjj Group Chat','2026-11-12 03:28:20'),(32,52,'Data Structures and Algorithms Group Chat','2026-11-12 03:28:20'),(33,49,'Database Management Group Chat','2026-11-12 03:28:20'),(36,50,'Web Development Group Chat','2026-11-12 03:28:20');
/*!40000 ALTER TABLE `subject_group_chats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subject_group_members`
--

DROP TABLE IF EXISTS `subject_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subject_group_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_member` (`group_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `subject_group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `subject_group_chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subject_group_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subject_group_members`
--

LOCK TABLES `subject_group_members` WRITE;
/*!40000 ALTER TABLE `subject_group_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `subject_group_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subject_group_messages`
--

DROP TABLE IF EXISTS `subject_group_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subject_group_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `subject_group_messages_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `subject_group_chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subject_group_messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subject_group_messages`
--

LOCK TABLES `subject_group_messages` WRITE;
/*!40000 ALTER TABLE `subject_group_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `subject_group_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subjects`
--

DROP TABLE IF EXISTS `subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instructor_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive','completed','draft','archived') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `program_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `class_code` varchar(50) DEFAULT NULL,
  `department_id` int(11) NOT NULL,
  `program_type` varchar(50) DEFAULT NULL,
  `units` decimal(6,2) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `academic_year_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subject_department_code` (`department_id`,`class_code`),
  KEY `instructor_id` (`instructor_id`),
  KEY `program_id` (`program_id`),
  KEY `section_id` (`section_id`),
  KEY `fk_subjects_semester` (`semester_id`),
  KEY `idx_subjects_academic_year` (`academic_year_id`),
  CONSTRAINT `fk_subjects_academic_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_subjects_department_required` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  CONSTRAINT `fk_subjects_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`semester_id`) ON DELETE SET NULL,
  CONSTRAINT `subjects_ibfk_1` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subjects_ibfk_2` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subjects_ibfk_3` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subjects`
--

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
INSERT INTO `subjects` VALUES (48,11,'csadfghjj','\n\nAcademic Year: 2026-2027\nSemester: Second','active','2026-10-05 16:10:44',NULL,NULL,'EJ5XZ3',1,NULL,NULL,NULL,NULL,NULL,NULL),(49,47,'Database Management',NULL,'active','2026-10-05 16:50:46',11,10,'IT301',1,NULL,3.00,'3rd Year','1st Semester',NULL,5),(50,48,'Web Development',NULL,'active','2026-10-05 16:50:46',11,10,'IT302',1,NULL,3.00,'3rd Year','1st Semester',NULL,5),(52,47,'Data Structures and Algorithms',NULL,'active','2026-10-05 16:50:46',11,10,'IT201',1,NULL,3.00,'2nd Year','1st Semester',NULL,5),(65,49,'CC 101','\n\nAcademic Year: AY 2026/2027\nSemester: First','active','2026-11-12 16:36:05',11,NULL,'2K422Q',1,NULL,NULL,NULL,'First',NULL,4);
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER subjects_require_department_insert BEFORE INSERT ON subjects FOR EACH ROW BEGIN IF NEW.department_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A department is required for every course'; END IF; END */;;
DELIMITER ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER subjects_require_department_update BEFORE UPDATE ON subjects FOR EACH ROW BEGIN IF NEW.department_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A department is required for every course'; END IF; END */;;
DELIMITER ;

--
-- Table structure for table `submission_overrides`
--

DROP TABLE IF EXISTS `submission_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `submission_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `allowed_attempts` int(11) NOT NULL DEFAULT 3,
  `new_due_date` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `assignment_id` (`assignment_id`),
  KEY `student_id` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `submission_overrides`
--

LOCK TABLES `submission_overrides` WRITE;
/*!40000 ALTER TABLE `submission_overrides` DISABLE KEYS */;
INSERT INTO `submission_overrides` VALUES (1,22,33,5,'2026-08-12 00:33:00','','2026-08-10 16:33:17'),(2,24,32,5,'2026-09-07 15:22:00','','2026-09-04 07:22:18');
/*!40000 ALTER TABLE `submission_overrides` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `submissions`
--

DROP TABLE IF EXISTS `submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `content` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `grade` varchar(10) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `attempt_number` int(11) NOT NULL DEFAULT 1,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `assignment_id` (`assignment_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=113 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `submissions`
--

LOCK TABLES `submissions` WRITE;
/*!40000 ALTER TABLE `submissions` DISABLE KEYS */;
INSERT INTO `submissions` VALUES (64,26,50,NULL,NULL,'72','Entity-relationship schema is partially normalized.','2026-08-20 06:20:00',1,1),(65,28,50,NULL,NULL,'84','Good responsive UI layout.','2026-08-28 12:10:00',1,1),(67,27,50,NULL,NULL,'72','Stored procedures need optimization and error handling.','2026-09-29 10:30:00',1,1),(68,26,51,NULL,NULL,'80','Good initial database design.','2026-08-20 08:00:00',1,1),(69,28,51,NULL,NULL,'78','Adequate UI layout.','2026-08-28 13:00:00',1,1),(70,27,51,NULL,NULL,'72','Procedure logic works but slow.','2026-09-20 11:00:00',1,1),(72,26,52,NULL,NULL,'78','Good work.','2026-08-29 07:00:00',1,1),(73,27,52,NULL,NULL,'76','Well structured.','2026-09-19 09:00:00',1,1),(74,28,52,NULL,NULL,'80','Clean interface.','2026-09-09 11:00:00',1,1),(76,26,53,NULL,NULL,'78','Good work.','2026-08-29 07:00:00',1,1),(77,27,53,NULL,NULL,'76','Well structured.','2026-09-19 09:00:00',1,1),(78,28,53,NULL,NULL,'80','Clean interface.','2026-09-09 11:00:00',1,1),(80,26,54,NULL,NULL,'92','Excellent schema design!','2026-08-29 03:00:00',1,1),(81,27,54,NULL,NULL,'90','Great indexing & transaction control.','2026-09-19 06:00:00',1,1),(82,28,54,NULL,NULL,'94','Superb responsive UX and performance.','2026-09-09 08:00:00',1,1),(84,26,55,NULL,NULL,'92','Excellent schema design!','2026-08-29 03:00:00',1,1),(85,27,55,NULL,NULL,'90','Great indexing & transaction control.','2026-09-19 06:00:00',1,1),(86,28,55,NULL,NULL,'94','Superb responsive UX and performance.','2026-09-09 08:00:00',1,1);
/*!40000 ALTER TABLE `submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usability_evaluations`
--

DROP TABLE IF EXISTS `usability_evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usability_evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `evaluator_role` varchar(50) NOT NULL DEFAULT 'program_chair',
  `evaluator_name` varchar(255) NOT NULL,
  `position` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `evaluation_date` date DEFAULT NULL,
  `ratings_json` longtext DEFAULT NULL,
  `section_summary_json` longtext DEFAULT NULL,
  `overall_mean` decimal(4,2) NOT NULL DEFAULT 0.00,
  `interpretation` varchar(100) NOT NULL DEFAULT '',
  `comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_role` (`evaluator_role`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usability_evaluations`
--

LOCK TABLES `usability_evaluations` WRITE;
/*!40000 ALTER TABLE `usability_evaluations` DISABLE KEYS */;
/*!40000 ALTER TABLE `usability_evaluations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','instructor','dean','student','faculty','staff') DEFAULT 'student',
  `department_id` int(11) DEFAULT NULL,
  `student_id_number` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive','pending','suspended','archived') DEFAULT 'active',
  `bio` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (11,'John Doe','johndoe@gmail.com','$2y$10$JISZXUrMagOEX0QTvReySePAZuboogg8RtucsfdUJ8VWzRmqcVyVq','instructor',NULL,NULL,'active',NULL,NULL,'2026-04-16 05:56:28','2026-05-31 16:43:49'),(13,'Admin User','admin@gmail.com','$2y$10$cErnEby95BV8sYHLvyww5ORN7vDcUptU.0/F0bmAw//MdoBf8nT5a','admin',NULL,NULL,'active',NULL,NULL,'2026-05-05 02:02:55','2026-05-31 16:43:49'),(34,'Maria Manalo','dean@learnalytics.com','$2y$10$JQmRDfl4gYqx2/BDo/BuxuRTk4o1/95EpqcRZU62AvXZgQ4xNMLxO','dean',1,'23456','active',NULL,NULL,'2026-08-10 15:43:05','2026-10-06 01:55:42'),(35,'Raul Macapagal','dean1@learnalytics.com','$2y$10$FdD9TTSBRV0/tPD81BtEDu.X5W9kbrJGILTn5wAmyyMvtsJFESqWG','dean',3,'255264','active',NULL,NULL,'2026-08-10 15:54:44','2026-09-04 07:09:02'),(37,'dzsgfghj','df@gmai.com','$2y$10$o1b1Hx58bWN9aQqsquEDO.D7.2B8jKPpP2lXPGC4Y5bJWcJbsjLVq','student',1,'23456','active',NULL,NULL,'2026-09-30 07:17:05','2026-09-30 07:17:05'),(47,'John Doe','johndoe@learnalytics.com','$2y$10$3RxRftImP8/2bf.cccX8feJplVpaq54bfOWL2m9lH9CTw2S7RkK.u','instructor',1,NULL,'active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(48,'Jane Smith','janesmith@learnalytics.com','$2y$10$PNJiiPPJAlOVexiJZUtiL.RGPUECJGITtP581AzwTdlJjHDFhc/Oq','instructor',1,NULL,'active',NULL,NULL,'2026-10-05 16:50:46','2026-10-21 04:06:42'),(49,'Mark Cruz','markcruz@learnalytics.com','$2y$10$bsxTqC05iIqKbCjbyh9AZuvJ0SAg8i8fl1UbVvzv/5Ewgr4FQ5ANC','instructor',1,NULL,'active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 17:32:48'),(50,'Juan Dela Cruz','juan.delacruz@learnalytics.com','$2y$10$vnZcus2LGlEwpOC9SH22Ee4oF7WRQSHRFSabWqA1qiTrAMDPLA.H2','student',1,NULL,'active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(51,'Maria Santos','maria.santos@learnalytics.com','$2y$10$2vtewL.Uz7yiTRDpH9pIIOqYSGp3wM6kgbzyM/qG4/VZey6z2nOgC','student',1,NULL,'active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(52,'Carlos Mendoza','carlos.mendoza@learnalytics.com','$2y$10$0sF.6If5AzD3zirMwXPJCORo/QqUZgyUrA9zPsm4a16AEWHmZMdDi','student',1,NULL,'active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(53,'Bea Dizon','bea.dizon@learnalytics.com','$2y$10$MVXQ8LEFHq2H/vfmX9qNde07QOMwV8v.KbvBiEur7dMT0rHW8FLg6','student',1,NULL,'active',NULL,NULL,'2026-10-05 16:50:46','2026-10-07 09:28:09'),(54,'Alex Reyes','alex.reyes@learnalytics.com','$2y$10$oGt85grkd9MIdLJ8eBKxuujIprR831dN/GqzaPvnRbAY8/P4/9Io2','student',1,NULL,'active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(55,'Patricia Lim','patricia.lim@learnalytics.com','$2y$10$g33BAE1bIEboVHuTupbc5uwfo92UIsDo3jl6MgCdJmBsUrUHq1x.6','student',1,NULL,'active',NULL,NULL,'2026-10-05 16:50:46','2026-10-05 16:50:46'),(56,'Rojhina Grampon','rojhina@gmail.com','$2y$10$hljp2Gw4iPWVhl9ee5JB..KWrkSvpXemLKZlw8zJ.U9t41NkTvGtm','student',1,'234567','active',NULL,NULL,'2026-10-20 04:30:15','2026-10-20 04:30:15'),(58,'Marielle C Morales','mariellecordero26@gmail.com','$2y$10$jtxxIIMjke1CjJCqAtW5AuviD0iAHlDJYVoIVERTKusT8rqBbKI0.','student',1,'73939276','active',NULL,NULL,'2026-10-20 05:11:26','2026-10-20 05:11:26'),(59,'RO ANN MAE A JALAPA','jalaparoann245@gmail.com','$2y$10$pxGp40bXqtFsHdcAUrFSle68YDDa0R697gq4x.y31ncJGq.UDKOAW','student',1,'2023731022','active',NULL,NULL,'2026-10-20 05:39:32','2026-10-20 05:39:32'),(60,'Christian Earl L Pacol','pacolchristianearl18@gmail.com','$2y$10$imUP.8M9l7WT6E0rS5OPS.NAHZPnE3S9zJFQp97VgIeleNKsQvSyG','student',1,'2023727062','active','Im just a kid with a big dream',NULL,'2026-10-20 05:46:54','2026-11-12 02:32:07'),(61,'Koykoy V. Gicos','koykoy@gmail.com','$2y$10$OIvf9xRXKL5H8lViIeelWOJfZlifkdaHcJX9svPod0D1fINTfMVay','student',1,'123456789076534567865','active','Ikaw lang, sapat kaaa :PP',NULL,'2026-10-21 09:51:42','2026-10-21 09:55:57'),(62,'John O. Doe','palagpat@gmail.com','$2y$10$gN7DsnVYoyA/OInUsAyLZOzlKw8vj1qk1.lHrB8DKJR4ajvz0ubo6','student',1,'8529645','active',NULL,NULL,'2026-10-22 06:31:20','2026-10-22 06:31:20'),(63,'Sdh Bshh','abcd@gmail.com','$2y$10$79JtQ/z8rLGBStDQxJ9.3e/yvxOl4ZNbQBzRaR5dYegMXmbjiFMAy','student',1,'26722','active',NULL,NULL,'2026-11-12 03:00:45','2026-11-12 03:00:45'),(64,'Ro Ann Mae A Jalapa','jalaparoann@gmail.com','$2y$10$v9ROdPo4rLFR1coLbSKneuzxDk4wC3bhIxCLc55gtJ.ZToSiREQTq','student',1,'74829282','active',NULL,NULL,'2026-11-12 03:06:15','2026-11-12 03:06:15'),(65,'Marielle C Morales','maryel26@gmail.com','$2y$10$avK3dpWuccUBTafB4LCVvOsuaot3ZXXQ7LGzNF41g5nQAHMpgsmF6','student',1,'20230756','active',NULL,NULL,'2026-11-12 03:08:06','2026-11-12 03:08:06'),(66,'JOHN CANETE','johncanetekcc@gmail.com','$2y$10$1CxPw4amKR3W4lH74YlRTOQbfexh9ba8RfzI44ChAe0sTjrdkhc3e','student',1,'8675431q32r','active',NULL,NULL,'2026-11-12 06:00:32','2026-11-12 06:00:32');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `virtual_classrooms`
--

DROP TABLE IF EXISTS `virtual_classrooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `virtual_classrooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) DEFAULT NULL,
  `instructor_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `meeting_link` varchar(500) DEFAULT NULL,
  `meeting_id` varchar(100) DEFAULT NULL,
  `meeting_password` varchar(100) DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT 60,
  `status` enum('scheduled','live','completed','cancelled') DEFAULT 'scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subject_id` (`subject_id`),
  KEY `instructor_id` (`instructor_id`),
  CONSTRAINT `virtual_classrooms_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `virtual_classrooms_ibfk_2` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `virtual_classrooms`
--

LOCK TABLES `virtual_classrooms` WRITE;
/*!40000 ALTER TABLE `virtual_classrooms` DISABLE KEYS */;
INSERT INTO `virtual_classrooms` VALUES (1,NULL,11,'JFJDSD','SCASCSAC','https://dcd.com','dfvusodf','dbuvdwv','2026-05-02 12:00:00',60,'scheduled','2026-05-01 11:43:38');
/*!40000 ALTER TABLE `virtual_classrooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Final view structure for view `quiz_analytics`
--

/*!50001 DROP VIEW IF EXISTS `quiz_analytics`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `quiz_analytics` AS select `q`.`id` AS `quiz_id`,`q`.`title` AS `quiz_title`,`q`.`status` AS `status`,`q`.`due_date` AS `due_date`,`s`.`title` AS `subject_title`,`u`.`name` AS `instructor_name`,`u`.`id` AS `instructor_id`,count(distinct `qr`.`student_id`) AS `total_attempts`,count(distinct `qr`.`id`) AS `total_submissions`,avg(`qr`.`score`) AS `average_score`,max(`qr`.`score`) AS `highest_score`,min(`qr`.`score`) AS `lowest_score`,`q`.`created_at` AS `created_at`,`q`.`published_at` AS `published_at` from (((`quizzes` `q` left join `subjects` `s` on(`q`.`subject_id` = `s`.`id`)) left join `users` `u` on(`q`.`instructor_id` = `u`.`id`)) left join `quiz_results` `qr` on(`q`.`id` = `qr`.`quiz_id`)) group by `q`.`id`,`q`.`title`,`q`.`status`,`q`.`due_date`,`s`.`title`,`u`.`name`,`u`.`id`,`q`.`created_at`,`q`.`published_at` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `student_quiz_access`
--

/*!50001 DROP VIEW IF EXISTS `student_quiz_access`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY DEFINER */
/*!50001 VIEW `student_quiz_access` AS select distinct `q`.`id` AS `quiz_id`,`q`.`title` AS `title`,`q`.`description` AS `description`,`q`.`status` AS `status`,`q`.`due_date` AS `due_date`,`q`.`time_limit` AS `time_limit`,`q`.`attempt_limit` AS `attempt_limit`,`q`.`show_results` AS `show_results`,`s`.`id` AS `subject_id`,`s`.`title` AS `subject_title`,`s`.`instructor_id` AS `instructor_id`,`u`.`name` AS `instructor_name` from ((((((((`quizzes` `q` join `subjects` `s` on(`q`.`subject_id` = `s`.`id`)) join `users` `u` on(`s`.`instructor_id` = `u`.`id`)) join `enrollments` `e` on(`s`.`id` = `e`.`subject_id`)) join `users` `student` on(`e`.`student_id` = `student`.`id`)) left join `quiz_assignments` `qa` on(`q`.`id` = `qa`.`quiz_id`)) left join `sections` `sec` on(`qa`.`target_id` = `sec`.`id` and `qa`.`assignment_type` = 'section')) left join `programs` `p` on(`qa`.`target_id` = `p`.`id` and `qa`.`assignment_type` = 'program')) left join `student_profiles` `sp` on(`qa`.`target_id` = `sp`.`student_id` and `qa`.`assignment_type` = 'student')) where `student`.`role` = 'student' and `q`.`status` = 'published' and (`qa`.`id` is null or `qa`.`assignment_type` = 'section' and `sec`.`id` = `sp`.`section_id` or `qa`.`assignment_type` = 'program' and `p`.`id` = `sp`.`program_id` or `qa`.`assignment_type` = 'year_level' and `q`.`id` in (select `q2`.`id` from (`quizzes` `q2` join `quiz_assignments` `qa2` on(`q2`.`id` = `qa2`.`quiz_id`)) where `qa2`.`assignment_type` = 'year_level' and `qa2`.`year_level` = `sp`.`year_level`) or `qa`.`assignment_type` = 'student' and `sp`.`student_id` = `student`.`id`) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-13  3:02:25
