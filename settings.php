<?php
require_once __DIR__ . '/includes/functions.php';
require_owner();

$owner_id = current_user_id(); // owner's own id IS the store scope id
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: settings.php');
        exit;
    }

    $vat_percent = $_POST['vat_percent'] ?? '';
    if (!is_numeric($vat_percent) || (float) $vat_percent < 0 || (float) $vat_percent > 100) {
        $errors[] = 'VAT percent must be a number between 0 and 100.';
    }

    if (!$errors) {
        $vat_percent = round((float) $vat_percent, 2);
        $stmt = $pdo->prepare(
            'INSERT INTO store_settings (user_id, vat_percent) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE vat_percent = VALUES(vat_percent)'
        );
        $stmt->execute([$owner_id, $vat_percent]);
        set_flash('success', $vat_percent > 0
            ? "VAT set to {$vat_percent}% - it will be added on top of every sale from now on."
            : 'VAT turned off - sales will no longer add VAT on top of the total.');
        header('Location: settings.php');
        exit;
    }
}

$current_vat = get_vat_percent($pdo, $owner_id);

$page_title = 'Settings';
$active_nav = 'settings';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>Settings</h1>
        <p>Store-wide settings that apply to every sale.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="card">
    <div class="card-title">Sales tax (VAT)</div>
    <p class="helper-text" style="margin-bottom:16px;">
        When set above 0%, this percentage is added on top of every sale's subtotal at checkout -
        the customer pays more than the sum of the prices. It shows as a separate line on the
        receipt. Changing this only affects sales made after you save it; past receipts keep
        whatever rate was active when they were made.
    </p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="form-grid">
            <div class="field">
                <label for="vat_percent">VAT percent</label>
                <input type="number" step="0.01" min="0" max="100" id="vat_percent" name="vat_percent"
                       value="<?= h((string) $current_vat) ?>" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Save</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>