<?php
/**
 * Application Configuration
 * Learnrail API
 */

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Timezone
date_default_timezone_set('UTC');

// Application settings
define('APP_NAME', 'Learnrail API');
define('APP_VERSION', '1.0.0');
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', APP_ENV === 'development');

// JWT Configuration
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'your-super-secret-jwt-key-change-in-production');
define('JWT_EXPIRY', 86400 * 7); // 7 days in seconds
define('JWT_REFRESH_EXPIRY', 86400 * 30); // 30 days

// API Configuration
define('API_VERSION', 'v1');
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'http://localhost/learnrail-api');

// Upload Configuration
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/webm', 'video/ogg']);

// Bunny.net CDN Configuration
define('BUNNY_CDN_URL', getenv('BUNNY_CDN_URL') ?: '');
define('BUNNY_API_KEY', getenv('BUNNY_API_KEY') ?: '');
define('BUNNY_STORAGE_ZONE', getenv('BUNNY_STORAGE_ZONE') ?: '');

// Paystack Configuration
define('PAYSTACK_SECRET_KEY', getenv('PAYSTACK_SECRET_KEY') ?: '');
define('PAYSTACK_PUBLIC_KEY', getenv('PAYSTACK_PUBLIC_KEY') ?: '');

// Email Configuration
define('MAIL_HOST', getenv('MAIL_HOST') ?: 'smtp.mailtrap.io');
define('MAIL_PORT', getenv('MAIL_PORT') ?: 587);
define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: '');
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: 'noreply@learnrail.org');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Learnrail');

// Pagination
define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 100);

// Points Configuration
define('POINTS_LESSON_COMPLETE', 10);
define('POINTS_QUIZ_PASS', 25);
define('POINTS_COURSE_COMPLETE', 100);
define('POINTS_GOAL_COMPLETE', 50);
define('POINTS_MILESTONE_COMPLETE', 15);
define('POINTS_DAILY_LOGIN', 5);
define('POINTS_STREAK_BONUS', 10);
