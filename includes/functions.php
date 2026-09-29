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
    if (empty($_SESSION['full_name']) || empty($_SESSION['username']) || !isset($_SESSION['role'])) {
        global $pdo;
        $stmt = $pdo->prepare('SELECT full_name, username, role, owner_id FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        if ($user = $stmt->fetch()) {
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['store_owner_id'] = $user['role'] === 'cashier' ? (int) $user['owner_id'] : (int) $_SESSION['user_id'];
        }
    }
}

/**
 * Redirect away unless the logged-in user is the store owner. Call at the
 * top of any page a cashier account shouldn't be able to reach.
 */
function require_owner(): void
{
    require_login();
    if (!is_owner()) {
        set_flash('error', 'That page is only available to the store owner.');
        header('Location: pos.php');
        exit;
    }
}

/** Returns the currently logged-in user's own id, or null. Use for "who is logged in" - NOT for scoping data (see store_scope_id()). */
function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Returns the id that owns the data being worked with - use this (not
 * current_user_id()) everywhere a query is scoped to "this store". For an
 * owner account this is their own id; for a cashier account it's the owner
 * they belong to, so owner and cashier share one single inventory/ledger.
 */
function store_scope_id(): int
{
    return (int) ($_SESSION['store_owner_id'] ?? $_SESSION['user_id'] ?? 0);
}

/** 'owner' or 'cashier' for the current session. */
function current_role(): string
{
    return $_SESSION['role'] ?? 'owner';
}

function is_owner(): bool
{
    return current_role() === 'owner';
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

/**
 * Records a stock addition for the history log. $source is a short machine
 * tag ('manual' from the Products page, 'shipment' from a received
 * shipment); $reference is a human-readable note (a supplier name, a typed
 * note, etc). Logs who actually performed the action (current_user_id()),
 * separate from which store it belongs to (store_scope_id()) - useful since
 * a cashier could restock too and it's worth knowing who did it.
 */
function log_stock_addition(PDO $pdo, int $store_id, int $product_id, int $quantity_added, string $source, ?string $reference = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO stock_history (user_id, product_id, quantity_added, source, reference, performed_by) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$store_id, $product_id, $quantity_added, $source, $reference, current_user_id()]);
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