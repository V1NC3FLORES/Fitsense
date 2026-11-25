-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 14, 2026 at 11:56 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gym_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `check_in_time` datetime NOT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `date` date GENERATED ALWAYS AS (cast(`check_in_time` as date)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `user_id`, `check_in_time`, `check_out_time`) VALUES
(1, 18, '2026-10-07 16:23:05', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `bmi_history`
--

CREATE TABLE `bmi_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `weight` decimal(5,2) NOT NULL,
  `height` decimal(5,2) NOT NULL,
  `bmi` decimal(4,1) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `recorded_date` date NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `body_measurements`
--

CREATE TABLE `body_measurements` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `weight` decimal(5,2) DEFAULT NULL COMMENT 'in kg',
  `body_fat` decimal(4,1) DEFAULT NULL COMMENT 'percentage',
  `chest` decimal(5,1) DEFAULT NULL COMMENT 'in cm',
  `waist` decimal(5,1) DEFAULT NULL COMMENT 'in cm',
  `hips` decimal(5,1) DEFAULT NULL COMMENT 'in cm',
  `arms` decimal(5,1) DEFAULT NULL COMMENT 'in cm',
  `thighs` decimal(5,1) DEFAULT NULL COMMENT 'in cm',
  `notes` text DEFAULT NULL,
  `measure_date` date NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `body_measurements`
--

INSERT INTO `body_measurements` (`id`, `user_id`, `weight`, `body_fat`, `chest`, `waist`, `hips`, `arms`, `thighs`, `notes`, `measure_date`, `created_at`) VALUES
(1, 18, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '', '2026-10-13', '2026-10-13 16:58:57'),
(2, 18, 72.00, 24.0, 10.0, 23.0, 97.0, 32.0, 55.0, '', '2026-10-13', '2026-10-13 16:59:44');

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) DEFAULT NULL,
  `room` varchar(50) DEFAULT 'community',
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `sender_id`, `receiver_id`, `room`, `message`, `created_at`) VALUES
(1, 18, NULL, 'community', 'hello', '2026-10-07 15:35:10'),
(2, 19, NULL, 'community', 'wassup', '2026-10-07 15:37:46');

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `class_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `duration_minutes` int(11) DEFAULT 60,
  `capacity` int(11) DEFAULT 15,
  `location` varchar(100) DEFAULT NULL,
  `difficulty` enum('Beginner','Intermediate','Advanced') DEFAULT 'Beginner',
  `category` varchar(50) DEFAULT 'General',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `name`, `description`, `class_date`, `start_time`, `end_time`, `duration_minutes`, `capacity`, `location`, `difficulty`, `category`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Strength Training', 'Build muscle & strength with compound lifts.', '2026-10-13', '17:00:00', '18:00:00', 60, 15, 'Weight Room', 'Intermediate', 'Strength', 1, '2026-10-13 17:49:54', '2026-10-13 18:01:46'),
(2, 'HIIT Blast', 'High-intensity interval training for max burn.', '2026-10-13', '19:00:00', '20:00:00', 60, 15, 'Studio A', 'Advanced', 'Cardio', 1, '2026-10-13 17:49:54', '2026-10-13 18:01:46'),
(3, 'Yoga Flow', 'Relaxing yoga session for flexibility and mindfulness.', '2026-10-13', '07:00:00', '08:00:00', 60, 20, 'Studio B', 'Beginner', 'Yoga', 1, '2026-10-13 17:49:54', '2026-10-13 18:01:46'),
(4, 'Zumba Dance', 'Fun dance workout that feels like a party!', '2026-10-14', '18:00:00', '19:00:00', 60, 25, 'Studio A', 'Beginner', 'Dance', 1, '2026-10-13 17:49:54', '2026-10-13 18:01:46'),
(5, 'Spin Class', 'Indoor cycling for cardio endurance.', '2026-10-14', '06:00:00', '07:00:00', 60, 20, 'Cycling Room', 'Intermediate', 'Cardio', 1, '2026-10-13 17:49:54', '2026-10-13 18:01:46'),
(6, 'Boxing Basics', 'Learn boxing fundamentals and technique.', '2026-10-14', '17:30:00', '18:30:00', 60, 15, 'Combat Zone', 'Beginner', 'Combat', 1, '2026-10-13 17:49:54', '2026-10-13 18:01:46'),
(7, 'Pilates Core', 'Strengthen your core with controlled movements.', '2026-10-15', '08:00:00', '09:00:00', 60, 18, 'Studio B', 'Intermediate', 'Pilates', 1, '2026-10-13 17:49:54', '2026-10-13 18:01:46'),
(8, 'HIIT Circuit', 'High-energy interval training combining strength and cardio rounds.', '2026-11-20', '18:00:00', '19:00:00', 60, 15, 'Studio A', 'Advanced', 'Cardio', 1, '2026-11-19 09:50:33', '2026-11-19 09:50:33'),
(9, 'Weekend Yoga Flow', 'A beginner-friendly flow focused on mobility, balance, and relaxation.', '2026-11-21', '08:00:00', '09:00:00', 60, 20, 'Studio B', 'Beginner', 'Yoga', 1, '2026-11-19 09:50:33', '2026-11-19 09:50:33'),
(10, 'Functional Strength', 'Full-body strength training using free weights and functional movements.', '2026-11-22', '10:00:00', '11:00:00', 60, 15, 'Weight Room', 'Intermediate', 'Strength', 1, '2026-11-19 09:50:33', '2026-11-19 09:50:33'),
(11, 'Zumba Fitness', 'A dance-based cardio class with easy-to-follow routines.', '2026-11-23', '18:00:00', '19:00:00', 60, 25, 'Studio A', 'Beginner', 'Dance', 1, '2026-11-19 09:50:33', '2026-11-19 09:50:33'),
(12, 'Spin Endurance', 'Indoor cycling intervals to build cardiovascular endurance.', '2026-11-24', '06:00:00', '07:00:00', 60, 20, 'Cycling Room', 'Intermediate', 'Cardio', 1, '2026-11-19 09:50:33', '2026-11-19 09:50:33');

-- --------------------------------------------------------

--
-- Table structure for table `class_bookings`
--

CREATE TABLE `class_bookings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `status` enum('Confirmed','Waitlist','Cancelled','Attended','No-Show') DEFAULT 'Confirmed',
  `booked_at` datetime DEFAULT current_timestamp(),
  `cancelled_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `class_bookings`
--

INSERT INTO `class_bookings` (`id`, `user_id`, `class_id`, `status`, `booked_at`, `cancelled_at`, `notes`) VALUES
(1, 18, 3, 'Cancelled', '2026-10-13 17:55:06', '2026-10-13 17:55:28', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `coach_assignments`
--

CREATE TABLE `coach_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `coach_id` int(11) NOT NULL,
  `assigned_date` date NOT NULL,
  `is_primary` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `coach_messages`
--

CREATE TABLE `coach_messages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `coach_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sender` enum('user','coach') NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `coach_sessions`
--

CREATE TABLE `coach_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `coach_id` int(11) NOT NULL,
  `session_date` date NOT NULL,
  `session_time` time NOT NULL,
  `duration_minutes` int(11) DEFAULT 60,
  `session_type` varchar(100) DEFAULT 'Personal Training',
  `notes` text DEFAULT NULL,
  `status` enum('Pending','Confirmed','Completed','Cancelled','No-Show') DEFAULT 'Pending',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fitness_goals`
--

CREATE TABLE `fitness_goals` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `goal_type` varchar(50) NOT NULL DEFAULT 'weight',
  `start_value` decimal(6,2) DEFAULT NULL,
  `target_value` decimal(6,2) DEFAULT NULL,
  `target_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `memberships`
--

CREATE TABLE `memberships` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `membership_type` enum('One Day','Monthly','Yearly') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('Pending','Active','Expired','Rejected','Cancelled') DEFAULT 'Pending',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `agreement_accepted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `memberships`
--

INSERT INTO `memberships` (`id`, `user_id`, `membership_type`, `amount`, `status`, `start_date`, `end_date`, `created_at`, `agreement_accepted`) VALUES
(1, 18, 'Monthly', 1500.00, 'Active', '2026-11-14', '2026-12-14', '2026-11-14 10:24:34', 0);

-- --------------------------------------------------------

--
-- Table structure for table `membership_prices`
--

CREATE TABLE `membership_prices` (
  `id` int(11) NOT NULL,
  `membership_type` enum('One Day','Monthly','Yearly') NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `membership_prices`
--

INSERT INTO `membership_prices` (`id`, `membership_type`, `price`, `is_active`, `updated_at`) VALUES
(1, 'One Day', 150.00, 1, '2026-09-09 07:17:39'),
(2, 'Monthly', 1500.00, 1, '2026-09-09 07:17:39'),
(3, 'Yearly', 15000.00, 1, '2026-09-09 07:17:39');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('membership','session','system','general') DEFAULT 'general',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `link`, `is_read`, `created_at`) VALUES
(1, 18, 'general', 'Checked In', 'Welcome! You checked in at 10:23 AM. Have a great workout!', NULL, 0, '2026-10-07 08:23:05'),
(2, 18, 'general', 'Measurement Recorded', 'Your body measurements have been saved successfully.', NULL, 0, '2026-10-13 08:58:57'),
(3, 18, 'general', 'Measurement Recorded', 'Your body measurements have been saved successfully.', NULL, 0, '2026-10-13 08:59:44'),
(4, 18, 'session', 'Class Booked', 'You are confirmed for Yoga Flow on Oct 13.', NULL, 0, '2026-10-13 09:55:06'),
(5, 18, 'membership', 'Membership Activated', 'Your Monthly membership is now active.', NULL, 0, '2026-11-14 10:24:43'),
(6, 18, 'system', 'Payment Received', 'We received your payment of PHP 1,500.00. Thank you!', NULL, 0, '2026-11-14 10:24:43');

-- --------------------------------------------------------

--
-- Table structure for table `nutrition_completions`
--

CREATE TABLE `nutrition_completions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `completion_date` date NOT NULL,
  `completed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `nutrition_completions`
--

INSERT INTO `nutrition_completions` (`id`, `user_id`, `item_id`, `completion_date`, `completed_at`) VALUES
(8, 18, 3, '2026-10-13', '2026-10-13 17:29:16'),
(9, 18, 4, '2026-10-13', '2026-10-13 17:29:23'),
(10, 18, 2, '2026-10-13', '2026-10-13 17:29:28'),
(11, 18, 1, '2026-10-13', '2026-10-13 17:29:40');

-- --------------------------------------------------------

--
-- Table structure for table `nutrition_items`
--

CREATE TABLE `nutrition_items` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `meal_type` enum('Breakfast','Morning Snack','Lunch','Afternoon Snack','Dinner','Evening Snack') NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `portion` varchar(100) DEFAULT NULL,
  `calories` int(11) DEFAULT 0,
  `protein` int(11) DEFAULT 0,
  `carbs` int(11) DEFAULT 0,
  `fats` int(11) DEFAULT 0,
  `image_url` varchar(500) DEFAULT NULL,
  `order_num` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `nutrition_items`
--

INSERT INTO `nutrition_items` (`id`, `plan_id`, `meal_type`, `item_name`, `portion`, `calories`, `protein`, `carbs`, `fats`, `image_url`, `order_num`, `created_at`) VALUES
(1, 1, 'Lunch', 'Turkey Wrap', '1 wrap (280g)', 450, 32, 42, 16, NULL, 10, '2026-10-13 17:17:14'),
(2, 1, 'Breakfast', 'Greek Yogurt Parfait', '1 cup (200g)', 280, 18, 35, 7, 'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=400', 10, '2026-10-13 17:29:03'),
(3, 1, 'Lunch', 'Grilled Chicken & Rice', '150g + 1 cup', 520, 45, 55, 8, 'https://images.unsplash.com/photo-1598103442097-8b74394b95c6?w=500&h=350&fit=crop', 10, '2026-10-13 17:29:16'),
(4, 1, 'Breakfast', 'Oatmeal with Berries', '1 bowl (250g)', 320, 12, 54, 8, 'https://images.unsplash.com/photo-1517673132405-a56a62b18caf?w=400', 10, '2026-10-13 17:29:23');

-- --------------------------------------------------------

--
-- Table structure for table `nutrition_plans`
--

CREATE TABLE `nutrition_plans` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `plan_name` varchar(150) DEFAULT 'My Nutrition Plan',
  `target_calories` int(11) DEFAULT 2000,
  `target_protein` int(11) DEFAULT 150,
  `target_carbs` int(11) DEFAULT 200,
  `target_fats` int(11) DEFAULT 60,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `nutrition_plans`
--

INSERT INTO `nutrition_plans` (`id`, `user_id`, `plan_name`, `target_calories`, `target_protein`, `target_carbs`, `target_fats`, `created_at`, `updated_at`) VALUES
(1, 18, 'My Nutrition Plan', 2000, 150, 200, 60, '2026-10-07 17:44:46', '2026-10-07 17:44:46'),
(2, 17, 'My Nutrition Plan', 2000, 150, 200, 60, '2026-10-13 17:17:14', '2026-10-13 17:17:14');

-- --------------------------------------------------------

--
-- Table structure for table `nutrition_progress`
--

CREATE TABLE `nutrition_progress` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `progress_date` date NOT NULL,
  `calories_logged` int(11) DEFAULT 0,
  `protein_logged` int(11) DEFAULT 0,
  `carbs_logged` int(11) DEFAULT 0,
  `fats_logged` int(11) DEFAULT 0,
  `items_logged` int(11) DEFAULT 0,
  `items_total` int(11) DEFAULT 0,
  `water_glasses` int(11) DEFAULT 0,
  `completion_percent` int(11) DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `nutrition_progress`
--

INSERT INTO `nutrition_progress` (`id`, `user_id`, `progress_date`, `calories_logged`, `protein_logged`, `carbs_logged`, `fats_logged`, `items_logged`, `items_total`, `water_glasses`, `completion_percent`, `updated_at`) VALUES
(1, 18, '2026-10-07', 0, 0, 0, 0, 0, 0, 0, 0, '2026-10-07 17:44:46'),
(2, 18, '2026-10-13', 0, 0, 0, 0, 0, 0, 0, 0, '2026-10-13 16:45:37');

-- --------------------------------------------------------

--
-- Table structure for table `plans`
--

CREATE TABLE `plans` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration_days` int(11) DEFAULT 30,
  `features` text DEFAULT NULL COMMENT 'JSON array or comma-separated',
  `badge` varchar(50) DEFAULT NULL COMMENT 'e.g. Most Popular, Best Value',
  `is_popular` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_type` varchar(100) NOT NULL,
  `session_date` date NOT NULL,
  `session_time` time NOT NULL,
  `status` enum('Pending','Booked','Completed','Cancelled','Rejected') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `session_offers`
--

CREATE TABLE `session_offers` (
  `id` int(11) NOT NULL,
  `session_type` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `session_offers`
--

INSERT INTO `session_offers` (`id`, `session_type`, `price`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Yoga', 0.00, 1, '2026-09-09 07:17:39', '2026-09-09 07:17:39'),
(2, 'HIIT', 0.00, 1, '2026-09-09 07:17:39', '2026-09-09 07:17:39'),
(3, 'Boxing', 0.00, 1, '2026-09-09 07:17:39', '2026-09-09 07:17:39'),
(4, 'Pilates', 250.00, 1, '2026-09-09 07:17:39', '2026-09-09 07:17:39');

-- --------------------------------------------------------

--
-- Table structure for table `session_prices`
--

CREATE TABLE `session_prices` (
  `id` int(11) NOT NULL,
  `session_type` enum('Yoga','HIIT','Boxing') NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suggested_meals`
--

CREATE TABLE `suggested_meals` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `meal_type` enum('Breakfast','Morning Snack','Lunch','Afternoon Snack','Dinner','Evening Snack') NOT NULL,
  `category` varchar(50) DEFAULT 'General',
  `calories` int(11) DEFAULT 0,
  `protein` int(11) DEFAULT 0,
  `carbs` int(11) DEFAULT 0,
  `fats` int(11) DEFAULT 0,
  `portion` varchar(100) DEFAULT NULL,
  `image_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suggested_meals`
--

INSERT INTO `suggested_meals` (`id`, `name`, `description`, `meal_type`, `category`, `calories`, `protein`, `carbs`, `fats`, `portion`, `image_url`, `is_active`, `created_at`) VALUES
(1, 'Scrambled Eggs & Toast', 'High-protein breakfast with whole grain toast', 'Breakfast', 'Protein', 320, 22, 28, 12, '3 eggs + 2 slices', 'https://images.unsplash.com/photo-1525351484163-7529414344d8?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(2, 'Oatmeal with Banana', 'Hearty oatmeal topped with fresh banana', 'Breakfast', 'Carbs', 280, 8, 52, 5, '1 cup cooked', 'https://images.unsplash.com/photo-1517673132405-a56a62b18caf?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(3, 'Greek Yogurt Parfait', 'Creamy yogurt with granola and berries', 'Breakfast', 'Protein', 260, 18, 32, 6, '1 cup', 'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(4, 'Protein Pancakes', 'Fluffy pancakes made with protein powder', 'Breakfast', 'Protein', 380, 28, 42, 8, '3 pancakes', 'https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(5, 'Apple with Peanut Butter', 'Classic snack with healthy fats', 'Morning Snack', 'Snack', 190, 6, 22, 10, '1 apple + 1 tbsp', 'https://images.unsplash.com/photo-1568702846914-96b305d2aaeb?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(6, 'Whey Protein Shake', 'Post-workout recovery shake', 'Morning Snack', 'Protein', 220, 25, 12, 4, '1 scoop + milk', 'https://images.unsplash.com/photo-1593095948071-474c5cc2989d?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(7, 'Mixed Nuts', 'Assorted nuts for healthy fats', 'Morning Snack', 'Snack', 170, 6, 6, 15, '1 handful', 'https://images.unsplash.com/photo-1508061253366-f7da158b6d46?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(8, 'Grilled Chicken & Rice', 'Lean protein with complex carbs', 'Lunch', 'Protein', 520, 45, 55, 8, '150g + 1 cup', 'https://images.unsplash.com/photo-1598103442097-8b74394b95c6?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(9, 'Salmon Bowl', 'Omega-3 rich salmon with quinoa', 'Lunch', 'Protein', 580, 38, 48, 22, '150g + 1 cup', 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(10, 'Turkey Wrap', 'Whole wheat wrap with lean turkey', 'Lunch', 'Protein', 420, 32, 42, 12, '1 large wrap', 'https://images.unsplash.com/photo-1626700051175-6818013e1d4f?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(11, 'Chicken Salad Bowl', 'Fresh greens with grilled chicken', 'Lunch', 'Protein', 380, 38, 18, 16, '1 bowl', 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(12, 'Protein Bar', 'Convenient protein-packed snack', 'Afternoon Snack', 'Snack', 200, 20, 22, 7, '1 bar', 'https://images.unsplash.com/photo-1622484212850-eb596d769edc?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(13, 'Cottage Cheese & Fruit', 'High-protein with fresh fruit', 'Afternoon Snack', 'Protein', 180, 15, 18, 4, '1/2 cup', 'https://images.unsplash.com/photo-1571212515416-fef01fc43637?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(14, 'Grilled Fish & Vegetables', 'Lean fish with steamed veggies', 'Dinner', 'Protein', 420, 42, 22, 16, '150g + 1 cup', 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(15, 'Steak with Sweet Potato', 'Red meat protein with complex carbs', 'Dinner', 'Protein', 620, 48, 42, 22, '150g + 1 medium', 'https://images.unsplash.com/photo-1600891964092-4316c288032e?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(16, 'Chicken Stir-Fry', 'Asian-style chicken with vegetables', 'Dinner', 'Protein', 480, 40, 38, 14, '1 plate', 'https://images.unsplash.com/photo-1603133872878-684f208fb84b?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(17, 'Vegetable Pasta', 'Whole wheat pasta with marinara', 'Dinner', 'Carbs', 450, 18, 68, 10, '1 bowl', 'https://images.unsplash.com/photo-1621996346565-e3dbc353d2e5?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(18, 'Casein Protein Shake', 'Slow-digesting protein for recovery', 'Evening Snack', 'Protein', 180, 24, 8, 4, '1 scoop', 'https://images.unsplash.com/photo-1593095948071-474c5cc2989d?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(19, 'Cottage Cheese Bowl', 'Slow-digesting protein before bed', 'Evening Snack', 'Protein', 150, 14, 8, 5, '1/2 cup', 'https://images.unsplash.com/photo-1571212515416-fef01fc43637?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(20, 'Dark Chocolate', 'Small treat with antioxidants', 'Evening Snack', 'Snack', 120, 2, 12, 8, '2 squares', 'https://images.unsplash.com/photo-1548907040-4d42b13b1d4c?w=500&h=350&fit=crop', 1, '2026-10-07 17:44:33'),
(21, 'Oatmeal with Berries', 'Hearty oatmeal topped with fresh mixed berries and honey', 'Breakfast', 'High Fiber', 320, 12, 54, 8, '1 bowl (250g)', 'https://images.unsplash.com/photo-1517673132405-a56a62b18caf?w=400', 1, '2026-10-13 17:12:12'),
(22, 'Greek Yogurt Parfait', 'Creamy Greek yogurt with granola and strawberries', 'Breakfast', 'High Protein', 280, 18, 35, 7, '1 cup (200g)', 'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=400', 1, '2026-10-13 17:12:12'),
(23, 'Scrambled Eggs & Toast', 'Three scrambled eggs with whole wheat toast', 'Breakfast', 'High Protein', 380, 24, 30, 18, '3 eggs + 2 slices', 'https://images.unsplash.com/photo-1525351484163-7529414344d8?w=400', 1, '2026-10-13 17:12:12'),
(24, 'Grilled Chicken Salad', 'Fresh mixed greens with grilled chicken breast', 'Lunch', 'Low Carb', 420, 38, 18, 22, '1 plate (350g)', 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400', 1, '2026-10-13 17:12:12'),
(25, 'Turkey Wrap', 'Whole wheat wrap with turkey, lettuce, and tomato', 'Lunch', 'Balanced', 450, 32, 42, 16, '1 wrap (280g)', 'https://images.unsplash.com/photo-1626700051175-6818013e1d4f?w=400', 1, '2026-10-13 17:12:12'),
(26, 'Salmon with Quinoa', 'Baked salmon fillet with quinoa and steamed vegetables', 'Dinner', 'High Protein', 520, 42, 38, 22, '1 plate (400g)', 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?w=400', 1, '2026-10-13 17:12:12'),
(27, 'Vegetable Stir Fry', 'Mixed vegetables stir-fried with tofu and brown rice', 'Dinner', 'Vegetarian', 380, 18, 52, 12, '1 plate (380g)', 'https://images.unsplash.com/photo-1512058564366-18510be2db19?w=400', 1, '2026-10-13 17:12:12'),
(28, 'Protein Smoothie', 'Banana, peanut butter, and whey protein smoothie', 'Morning Snack', 'High Protein', 350, 28, 38, 10, '1 glass (400ml)', 'https://images.unsplash.com/photo-1553530666-ba11a7da3888?w=400', 1, '2026-10-13 17:12:12'),
(29, 'Apple with Almond Butter', 'Sliced apple with 2 tbsp almond butter', 'Afternoon Snack', 'Healthy Fats', 220, 6, 28, 12, '1 apple + 2 tbsp', 'https://images.unsplash.com/photo-1568702846914-96b305d2aaeb?w=400', 1, '2026-10-13 17:12:12'),
(30, 'Cottage Cheese Bowl', 'Low-fat cottage cheese with pineapple chunks', 'Evening Snack', 'High Protein', 180, 20, 16, 4, '1 cup (220g)', 'https://images.unsplash.com/photo-1559561853-08451507cbe7?w=400', 1, '2026-10-13 17:12:12');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` varchar(50) DEFAULT 'text',
  `category` varchar(50) DEFAULT 'general',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reference_no` varchar(50) NOT NULL,
  `transaction_type` enum('Membership','Session') NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('Paid','Pending','Cancelled','Failed','Expired') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_provider` varchar(30) DEFAULT NULL,
  `xendit_payment_request_id` varchar(100) DEFAULT NULL,
  `xendit_payment_id` varchar(100) DEFAULT NULL,
  `xendit_capture_id` varchar(100) DEFAULT NULL,
  `xendit_channel_code` varchar(40) DEFAULT NULL,
  `xendit_status` varchar(30) DEFAULT NULL,
  `checkout_url` varchar(500) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `stripe_payment_intent` varchar(255) DEFAULT NULL,
  `transaction_ref` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `user_id`, `reference_no`, `transaction_type`, `description`, `amount`, `status`, `created_at`, `paid_at`, `payment_method`, `payment_provider`, `xendit_payment_request_id`, `xendit_payment_id`, `xendit_capture_id`, `xendit_channel_code`, `xendit_status`, `checkout_url`, `expires_at`, `stripe_payment_intent`, `transaction_ref`) VALUES
(1, 18, 'PAY-20261114-4C48FB', 'Membership', 'Monthly Membership', 1500.00, 'Paid', '2026-11-14 10:24:34', '2026-11-14 18:24:43', 'GCash', 'Xendit', 'pr-f7a2673d-0020-4bd7-aff7-c280ce98c518', 'py-5097c719-aabc-4f46-8c2d-abd0652104b7', NULL, 'GCASH', 'SUCCEEDED', 'https://ewallet-mock-connector.xendit.co/v1/ewallet_connector/checkouts?token=dbs3dovk06gs73fobdv0', '2026-11-14 11:54:35', NULL, 'py-5097c719-aabc-4f46-8c2d-abd0652104b7');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `gender` varchar(30) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('user','admin','super_admin') NOT NULL DEFAULT 'user',
  `account_status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_by` int(11) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `permissions` text DEFAULT NULL,
  `branch_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `zip` varchar(20) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_code` varchar(10) DEFAULT NULL,
  `two_factor_expiry` datetime DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `fitness_goal` varchar(100) DEFAULT NULL,
  `activity_level` varchar(50) DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(30) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `specialization` varchar(150) DEFAULT NULL,
  `years_experience` int(11) DEFAULT NULL,
  `certifications` text DEFAULT NULL,
  `rating` decimal(2,1) DEFAULT 5.0,
  `total_clients` int(11) DEFAULT 0,
  `date_of_birth` date DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(30) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `password_last_changed` datetime DEFAULT NULL,
  `status` enum('Active','Inactive','Suspended') DEFAULT 'Active',
  `last_login` datetime DEFAULT NULL,
  `failed_attempts` int(11) DEFAULT 0,
  `last_failed_login` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `email`, `phone`, `address`, `gender`, `birthdate`, `username`, `password`, `role`, `account_status`, `created_by`, `must_change_password`, `permissions`, `branch_id`, `created_at`, `city`, `state`, `country`, `zip`, `is_verified`, `two_factor_code`, `two_factor_expiry`, `profile_image`, `height`, `weight`, `fitness_goal`, `activity_level`, `emergency_contact`, `emergency_phone`, `bio`, `specialization`, `years_experience`, `certifications`, `rating`, `total_clients`, `date_of_birth`, `emergency_contact_name`, `emergency_contact_phone`, `updated_at`, `password_last_changed`, `status`, `last_login`, `failed_attempts`, `last_failed_login`) VALUES
(18, 'vincent mariano', 'marianovincent43@gmail.com', '09563344652', NULL, NULL, NULL, 'V1NC3', '$2y$10$qUweafXimhyMtxddYr0gWeH01Pp3bzo/JJfkvouLc2mDzYCWuN1Xm', 'user', 'Active', NULL, 0, NULL, 0, '2026-10-07 07:13:46', NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, NULL, 72.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5.0, 0, NULL, NULL, NULL, NULL, NULL, 'Active', NULL, 0, NULL),
(19, 'AJ', 'bountyrushmain01@gmail.com', '09563344652', NULL, NULL, NULL, 'AJ', '$2y$10$Epbd4ocnJd0x6Y3IageTGODKXTprbtbzKqUUWpZKeyzfira91n5IS', 'user', 'Active', NULL, 0, NULL, 0, '2026-10-07 07:36:19', NULL, NULL, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5.0, 0, NULL, NULL, NULL, NULL, NULL, 'Active', NULL, 0, NULL),
(20, 'Vincent Mariano', 'vmariano.k12154115@umak.edu.ph', '09563344652', NULL, NULL, NULL, 'ADMIN', '$2y$12$3a8bB.fXfImsf2r3Rsk1H.b2Z4cwdzWUz95VAOO90MgIWIGBKN/0S', 'admin', 'Active', NULL, 0, 'manage_members,manage_memberships,manage_attendance,manage_classes,manage_coaches,manage_payments,manage_chat,manage_notifications,view_reports,manage_profile', 11, '2026-10-07 08:07:03', NULL, NULL, NULL, NULL, 1, NULL, NULL, 'uploads/admins/admin_20_1794652738.jfif', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5.0, 0, NULL, NULL, NULL, NULL, NULL, 'Active', '2026-11-14 18:36:00', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `water_intake`
--

CREATE TABLE `water_intake` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `intake_date` date NOT NULL,
  `glasses` int(11) DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `water_intake`
--

INSERT INTO `water_intake` (`id`, `user_id`, `intake_date`, `glasses`, `updated_at`) VALUES
(1, 18, '2026-10-07', 5, '2026-10-07 17:48:29');

-- --------------------------------------------------------

--
-- Table structure for table `workout_completions`
--

CREATE TABLE `workout_completions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `exercise_id` int(11) NOT NULL,
  `completion_date` date NOT NULL,
  `completed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `workout_completions`
--

INSERT INTO `workout_completions` (`id`, `user_id`, `exercise_id`, `completion_date`, `completed_at`) VALUES
(1, 18, 10, '2026-10-07', '2026-10-07 17:02:05'),
(2, 18, 11, '2026-10-07', '2026-10-07 17:02:09'),
(3, 18, 12, '2026-10-07', '2026-10-07 17:02:14');

-- --------------------------------------------------------

--
-- Table structure for table `workout_exercises`
--

CREATE TABLE `workout_exercises` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `exercise_name` varchar(150) NOT NULL,
  `muscle_group` varchar(100) DEFAULT NULL,
  `sets` int(11) DEFAULT 0,
  `reps` int(11) DEFAULT 0,
  `rest_seconds` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `order_num` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workout_plans`
--

CREATE TABLE `workout_plans` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `coach_id` int(11) DEFAULT NULL,
  `plan_name` varchar(150) DEFAULT 'My Workout Plan',
  `description` text DEFAULT NULL,
  `assigned_date` date NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_check_in` (`check_in_time`),
  ADD KEY `idx_date` (`date`);

--
-- Indexes for table `bmi_history`
--
ALTER TABLE `bmi_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_date` (`recorded_date`);

--
-- Indexes for table `body_measurements`
--
ALTER TABLE `body_measurements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_date` (`measure_date`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_room` (`room`),
  ADD KEY `idx_sender` (`sender_id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_receiver` (`receiver_id`),
  ADD KEY `idx_room_sender` (`room`,`sender_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date` (`class_date`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `class_bookings`
--
ALTER TABLE `class_bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_class` (`class_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_user_class` (`user_id`,`class_id`),
  ADD KEY `idx_user_class_status` (`user_id`,`class_id`,`status`);

--
-- Indexes for table `coach_assignments`
--
ALTER TABLE `coach_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_coach` (`coach_id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `coach_messages`
--
ALTER TABLE `coach_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_coach` (`coach_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `coach_sessions`
--
ALTER TABLE `coach_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_coach` (`coach_id`),
  ADD KEY `idx_date` (`session_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `fitness_goals`
--
ALTER TABLE `fitness_goals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_type` (`goal_type`);

--
-- Indexes for table `memberships`
--
ALTER TABLE `memberships`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `membership_prices`
--
ALTER TABLE `membership_prices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_membership_type` (`membership_type`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `nutrition_completions`
--
ALTER TABLE `nutrition_completions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_completion` (`user_id`,`item_id`,`completion_date`),
  ADD KEY `idx_user_date` (`user_id`,`completion_date`),
  ADD KEY `idx_item` (`item_id`);

--
-- Indexes for table `nutrition_items`
--
ALTER TABLE `nutrition_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_plan` (`plan_id`),
  ADD KEY `idx_meal` (`meal_type`);

--
-- Indexes for table `nutrition_plans`
--
ALTER TABLE `nutrition_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user` (`user_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `nutrition_progress`
--
ALTER TABLE `nutrition_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_date` (`user_id`,`progress_date`),
  ADD KEY `idx_user_date` (`user_id`,`progress_date`);

--
-- Indexes for table `plans`
--
ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_active` (`is_active`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_permission` (`user_id`,`permission_key`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `session_offers`
--
ALTER TABLE `session_offers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_session_type` (`session_type`);

--
-- Indexes for table `session_prices`
--
ALTER TABLE `session_prices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_session` (`session_type`);

--
-- Indexes for table `suggested_meals`
--
ALTER TABLE `suggested_meals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_meal_type` (`meal_type`),
  ADD KEY `idx_category` (`category`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_key` (`setting_key`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_stripe_intent` (`stripe_payment_intent`),
  ADD KEY `idx_xendit_pr` (`xendit_payment_request_id`),
  ADD KEY `idx_xendit_payment` (`xendit_payment_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `water_intake`
--
ALTER TABLE `water_intake`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_date` (`user_id`,`intake_date`);

--
-- Indexes for table `workout_completions`
--
ALTER TABLE `workout_completions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_completion` (`user_id`,`exercise_id`,`completion_date`),
  ADD KEY `idx_user_date` (`user_id`,`completion_date`);

--
-- Indexes for table `workout_exercises`
--
ALTER TABLE `workout_exercises`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_plan` (`plan_id`),
  ADD KEY `idx_day` (`day_of_week`);

--
-- Indexes for table `workout_plans`
--
ALTER TABLE `workout_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_coach` (`coach_id`),
  ADD KEY `idx_active` (`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `bmi_history`
--
ALTER TABLE `bmi_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `body_measurements`
--
ALTER TABLE `body_measurements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `class_bookings`
--
ALTER TABLE `class_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `coach_assignments`
--
ALTER TABLE `coach_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coach_messages`
--
ALTER TABLE `coach_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coach_sessions`
--
ALTER TABLE `coach_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fitness_goals`
--
ALTER TABLE `fitness_goals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `memberships`
--
ALTER TABLE `memberships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `membership_prices`
--
ALTER TABLE `membership_prices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `nutrition_completions`
--
ALTER TABLE `nutrition_completions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `nutrition_items`
--
ALTER TABLE `nutrition_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `nutrition_plans`
--
ALTER TABLE `nutrition_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `nutrition_progress`
--
ALTER TABLE `nutrition_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `plans`
--
ALTER TABLE `plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `session_offers`
--
ALTER TABLE `session_offers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `session_prices`
--
ALTER TABLE `session_prices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suggested_meals`
--
ALTER TABLE `suggested_meals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `water_intake`
--
ALTER TABLE `water_intake`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `workout_completions`
--
ALTER TABLE `workout_completions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `workout_exercises`
--
ALTER TABLE `workout_exercises`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workout_plans`
--
ALTER TABLE `workout_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bmi_history`
--
ALTER TABLE `bmi_history`
  ADD CONSTRAINT `bmi_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `body_measurements`
--
ALTER TABLE `body_measurements`
  ADD CONSTRAINT `body_measurements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `class_bookings`
--
ALTER TABLE `class_bookings`
  ADD CONSTRAINT `class_bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `class_bookings_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `coach_assignments`
--
ALTER TABLE `coach_assignments`
  ADD CONSTRAINT `coach_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `coach_messages`
--
ALTER TABLE `coach_messages`
  ADD CONSTRAINT `coach_messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `coach_sessions`
--
ALTER TABLE `coach_sessions`
  ADD CONSTRAINT `coach_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fitness_goals`
--
ALTER TABLE `fitness_goals`
  ADD CONSTRAINT `fitness_goals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `memberships`
--
ALTER TABLE `memberships`
  ADD CONSTRAINT `memberships_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `nutrition_completions`
--
ALTER TABLE `nutrition_completions`
  ADD CONSTRAINT `fk_completion_item` FOREIGN KEY (`item_id`) REFERENCES `nutrition_items` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `nutrition_items`
--
ALTER TABLE `nutrition_items`
  ADD CONSTRAINT `fk_items_plan` FOREIGN KEY (`plan_id`) REFERENCES `nutrition_plans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `workout_completions`
--
ALTER TABLE `workout_completions`
  ADD CONSTRAINT `workout_completions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `workout_exercises`
--
ALTER TABLE `workout_exercises`
  ADD CONSTRAINT `workout_exercises_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `workout_plans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `workout_plans`
--
ALTER TABLE `workout_plans`
  ADD CONSTRAINT `workout_plans_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
