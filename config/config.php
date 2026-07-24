<?php
/**
 * Candace Management System
 * Central configuration file.
 * Edit the DB_* constants below to match your local MySQL setup.
 */

// ---- Database connection settings -------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'candace_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// ---- App settings -------------------------------------------------------
define('APP_NAME', 'Candace Management System');
define('STORE_NAME', 'Candace');
define('ASSET_VERSION', '1.0.0'); // bump this after editing CSS/JS to bust browser cache

// ---- Default expense categories created for every new user ------------
define('DEFAULT_CATEGORIES', [
    'Food & Groceries',
    'Transportation',
    'Utilities',
    'Rent',
    'Education',
    'Health',
    'Entertainment',
    'Others',
]);

// ---- Session ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Error reporting (turn off display_errors in production) ---------
error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('Asia/Manila');
