<?php
declare(strict_types=1);
$flashes = flash_get_all();
if (!empty($flashes)):
?>
<div id="monitors-flash-toast-container" class="monitors-flash-toast-container toast-container position-fixed top-0 end-0 p-3">
<?php foreach ($flashes as $flash): ?>
    <?php
    $type = (string) ($flash['type'] ?? 'info');
    $toastClass = match ($type) {
        'success' => 'toast-severity toast-severity-success',
        'warning' => 'toast-severity toast-severity-warning',
        'danger' => 'toast-severity toast-severity-danger',
        default => 'toast-severity toast-severity-info',
    };
    $closeClass = in_array($type, ['warning', 'info'], true) ? 'btn-close' : 'btn-close btn-close-white';
    $title = match ($type) {
        'success' => 'Success',
        'warning' => 'Warning',
        'danger' => 'Error',
        default => 'Info',
    };
    $autoDismissMs = match ($type) {
        'success' => 4500,
        'info' => 5000,
        'warning' => 7000,
        default => 9000,
    };
    ?>
    <div
        class="toast align-items-center border-0 <?= e($toastClass) ?>"
        role="alert"
        aria-live="assertive"
        aria-atomic="true"
        data-monitors-flash-toast="1"
        data-bs-delay="<?= e((string) $autoDismissMs) ?>"
        data-bs-autohide="true"
    >
        <div class="d-flex">
            <div class="toast-body">
                <strong class="me-1"><?= e($title) ?>:</strong>
                <?= e((string) ($flash['message'] ?? '')) ?>
            </div>
            <button type="button" class="<?= e($closeClass) ?> me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
