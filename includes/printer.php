<?php
/**
 * Sends raw ESC/POS bytes to the receipt printer.
 *
 * Two supported modes, set via PRINTER_MODE in config/config.php:
 *
 * 'network' - the printer has its own IP address (WiFi or Ethernet). We just
 *   open a plain TCP socket to it on port 9100 and write the bytes. This is
 *   the standard "raw print" port almost every network thermal printer opens
 *   by default (originally popularized by HP JetDirect, now universal).
 *
 * 'windows_usb' - the printer is plugged into this PC via USB. Windows needs
 *   to see it as a SHARED printer using the "Generic / Text Only" driver in
 *   raw passthrough mode, so that bytes we send arrive at the printer exactly
 *   as-is instead of Windows trying to "help" by reformatting them.
 *
 *   One-time Windows setup for USB mode:
 *     1. Plug in the printer, let Windows install it (or install the
 *        manufacturer's basic driver).
 *     2. Settings > Printers & Scanners > find it > Printer properties.
 *     3. Change Driver: pick "Generic" / "Generic Text-Only" (NOT the
 *        manufacturer's driver - that one tries to interpret formatting).
 *     4. Sharing tab > check "Share this printer" > give it a share name,
 *        e.g. ThermalPrinter.
 *     5. Ports tab > confirm it's set to the printer's real USB port
 *        (e.g. USB001), and note "Print directly to the printer" is enabled
 *        under Advanced tab (disables spooling delays).
 *     6. Set PRINTER_SHARE_NAME in config.php to match, e.g.
 *        '\\\\localhost\\ThermalPrinter' (share name only needs to match
 *        step 4 - PC name stays "localhost" since it's the same machine).
 *
 * Returns [bool $success, ?string $errorMessage].
 */
function send_to_printer(string $bytes): array
{
    if (PRINTER_MODE === 'network') {
        return send_to_printer_network($bytes);
    }
    if (PRINTER_MODE === 'windows_usb') {
        return send_to_printer_windows_usb($bytes);
    }
    return [false, 'Unknown PRINTER_MODE in config.php: ' . PRINTER_MODE];
}

function send_to_printer_network(string $bytes): array
{
    $fp = @fsockopen(PRINTER_IP, PRINTER_PORT, $errno, $errstr, 5);
    if (!$fp) {
        return [false, "Could not reach printer at " . PRINTER_IP . ":" . PRINTER_PORT . " ({$errstr})"];
    }
    $written = fwrite($fp, $bytes);
    fclose($fp);

    if ($written === false || $written < strlen($bytes)) {
        return [false, 'Connected to printer but the data did not send fully.'];
    }
    return [true, null];
}

function send_to_printer_windows_usb(string $bytes): array
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'receipt_') . '.prn';
    if (file_put_contents($tmpFile, $bytes) === false) {
        return [false, 'Could not write temporary print file.'];
    }

    // "copy /b" sends the file byte-for-byte with no reinterpretation - this
    // is the standard trick for raw-printing to a shared Windows printer.
    $cmd = 'copy /b ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg(PRINTER_SHARE_NAME) . ' 2>&1';
    $output = [];
    $returnVar = 0;
    exec('cmd /c ' . $cmd, $output, $returnVar);
    @unlink($tmpFile);

    if ($returnVar !== 0) {
        return [false, 'Print command failed: ' . implode(' ', $output)];
    }
    return [true, null];
}