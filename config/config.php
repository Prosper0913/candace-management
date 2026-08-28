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
define('ASSET_VERSION', '1.3.0'); // bump this after editing CSS/JS to bust browser cache

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