<?php
/**
 * Candace Management System
 * Shared helper functions: auth guard, CSRF tokens, formatting helpers.
 */

require_once __DIR__ . '/../config/db.php';

/** Redirect to login if the user is not authenticated. Call at the top of every protected page. */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    // Self-heal: if a session is missing display info (e.g. an older session
    // from before an update, or any other edge case), refill it from the DB
    // instead of letting pages break on a missing session key.
    if (empty($_SESSION['full_name']) || empty($_SESSION['username'])) {
        global $pdo;
        $stmt = $pdo->prepare('SELECT full_name, username FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        if ($user = $stmt->fetch()) {
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username']  = $user['username'];
        }
    }
}

/** Returns the currently logged-in user's id, or null. */
function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/** Generate (or reuse) a CSRF token for the current session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Validate a submitted CSRF token. */
function csrf_verify(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** Escape output safely for HTML. */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Format a number as Philippine peso currency, e.g. ₱1,234.50 */
function peso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

/** Format a date for display, e.g. Jul 10, 2026 */
function display_date(string $date): string
{
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : $date;
}

/**
 * Returns pending shipments expected within the next 3 days, split into two
 * urgency tiers so the UI can show the closer ones more prominently:
 *  - 'urgent'   : due today, overdue, or arriving within ~24 hours (days_left <= 1)
 *  - 'upcoming' : arriving in 2-3 days
 * Each row includes item_count, total_cost, and days_left (can be negative if overdue).
 */
function get_shipment_alerts(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(
        'SELECT s.*,
                COUNT(si.id) AS item_count,
                COALESCE(SUM(si.line_total), 0) AS total_cost,
                DATEDIFF(s.expected_date, CURDATE()) AS days_left
         FROM shipments s
         LEFT JOIN shipment_items si ON si.shipment_id = s.id
         WHERE s.user_id = ?
           AND s.status = "pending"
           AND DATEDIFF(s.expected_date, CURDATE()) <= 3
         GROUP BY s.id
         ORDER BY s.expected_date ASC'
    );
    $stmt->execute([$user_id]);
    $rows = $stmt->fetchAll();

    $urgent = [];
    $upcoming = [];
    foreach ($rows as $row) {
        if ((int) $row['days_left'] <= 1) {
            $urgent[] = $row;
        } else {
            $upcoming[] = $row;
        }
    }
    return ['urgent' => $urgent, 'upcoming' => $upcoming];
}

/** Human-friendly phrasing for a shipment's days_left value. */
function shipment_due_label(int $daysLeft): string
{
    if ($daysLeft < 0) return abs($daysLeft) . ' day(s) overdue';
    if ($daysLeft === 0) return 'due today';
    if ($daysLeft === 1) return 'due tomorrow';
    return "arriving in {$daysLeft} days";
}

/** Products at or below their own low-stock threshold. */
function get_low_stock_products(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM products
         WHERE user_id = ? AND stock_quantity <= low_stock_threshold
         ORDER BY stock_quantity ASC, name ASC'
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

/** Flash message helpers (simple one-time session messages) */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}