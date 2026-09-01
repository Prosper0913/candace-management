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
define('STORE_ADDRESS', 'Maasin City, Southern Leyte'); // printed on POS receipts
define('ASSET_VERSION', '1.4.0'); // bump this after editing CSS/JS to bust browser cache

// ---- Store location (for the shipment map) -------------------------------
// Approximate coordinates for Tomas Oppus Street, Maasin City - geocoded to
// street/city-center precision only. For an exact pin: open Google Maps,
// right-click your actual storefront, click the lat/lng shown at the top of
// the menu, and paste those two numbers in here instead.
define('STORE_FULL_ADDRESS', '311 Tomas Oppus Street, Maasin City, Southern Leyte, Philippines');
define('STORE_LAT', 10.1328);
define('STORE_LNG', 124.8385);

// Required by OpenStreetMap Nominatim's usage policy: identify your app with
// a real contact so they can reach you if something's wrong, instead of a
// generic string that looks like abuse. Edit the email before going live.
define('NOMINATIM_USER_AGENT', 'CandaceManagementSystem/1.0 (contact: youremail@example.com)');

// ---- LocationIQ (address search + routing) --------------------------------
// The actual API key lives in config/secrets.php, NOT here - that file is
// git-ignored (see .gitignore) so your key never gets committed to a public
// repo. This file only defines a safe empty fallback so the app doesn't
// break for anyone who clones the repo without that file.
//
// One-time setup:
//   1. Copy config/secrets.example.php to config/secrets.php
//   2. Get a free key at https://locationiq.com/ -> Dashboard -> Access Tokens
//   3. Paste it into config/secrets.php
// config/secrets.php is in .gitignore, so this step never needs repeating
// after a `git pull` - it only lives on your own machine.
if (file_exists(__DIR__ . '/secrets.php')) {
    require_once __DIR__ . '/secrets.php';
}
if (!defined('LOCATIONIQ_API_KEY')) {
    define('LOCATIONIQ_API_KEY', '');
}

// ---- Inventory ------------------------------------------------------------
// Default "low stock" warning line for newly registered products (owner can
// override per product on the Products page).
define('DEFAULT_LOW_STOCK_THRESHOLD', 5);

// ---- Receipt printer settings --------------------------------------------
// PRINTER_MODE: 'network' (printer has its own IP address, connects via WiFi/Ethernet)
//            or 'windows_usb' (printer is plugged into this PC via USB)
define('PRINTER_MODE', 'network');

// Only used when PRINTER_MODE = 'network'. Set this to your printer's IP address
// (check the printer's self-test page or its menu/app for this). 9100 is the
// standard "raw print" port almost every network thermal printer listens on.
define('PRINTER_IP', '192.168.1.87');
define('PRINTER_PORT', 9100);

// Only used when PRINTER_MODE = 'windows_usb'. This must match the exact Windows
// share name you give the printer when you share it (see the USB setup guide
// in includes/printer.php). Example: if you share it as "ThermalPrinter" on
// this same PC, leave this as-is.
define('PRINTER_SHARE_NAME', '\\\\localhost\\ThermalPrinter');

// How many characters fit on one printed line. 58mm paper = 32, 80mm paper = 48.
// Check your printer/paper's spec sheet - this determines how receipts wrap.
define('PRINTER_PAPER_WIDTH', 32);

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