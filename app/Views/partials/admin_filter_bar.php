<?php
declare(strict_types=1);
/**
 * Reusable admin filter/search bar — unifies servers (client), ping & alert (server GET).
 * Vars:
 *  $mode: 'client' | 'get' (default 'get')
 *  $action: string form action URL (get mode)
 *  $name: string input name (default 'q')
 *  $value: string current value
 *  $placeholder: string
 *  $inputId: string id attribute
 *  $inputAttrs: string extra raw attributes for <input> (e.g. data-server-search)
 *  $preserve: array<string,mixed> hidden fields to keep (get mode)
 *  $resetUrl: string URL for clear button (get mode; auto-built if empty)
 *  $wrapClass: string wrapper class (client vs get wrapper)
 *  $showSubmit: bool show Filter button (get mode)
 * @var string $mode
 * @var string $action
 * @var string $name
 * @var string $value
 * @var string $placeholder
 * @var string $inputId
 * @var string $inputAttrs
 * @var array<string,mixed> $preserve
 * @var string $resetUrl
 * @var string $wrapClass
 * @var bool $showSubmit
 */
$mode = $mode ?? 'get';
$name = $name ?? 'q';
$value = (string) ($value ?? '');
$placeholder = $placeholder ?? 'Search...';
$inputId = $inputId ?? 'filter-' . $name;
$inputAttrs = $inputAttrs ?? '';
$preserve = $preserve ?? [];
$action = $action ?? '';
$resetUrl = $resetUrl ?? '';
$wrapClass = $wrapClass ?? '';
$showSubmit = $showSubmit ?? ($mode === 'get');

if ($mode === 'client') {
    $wrapClass = $wrapClass !== '' ? $wrapClass : 'server-list-search';
    echo '<div class="' . e($wrapClass) . '">';
    echo '<i class="ti ti-search" aria-hidden="true"></i>';
    echo '<input class="form-control" type="search" id="' . e($inputId) . '" name="' . e($name) . '" value="' . e($value) . '" placeholder="' . e($placeholder) . '" ' . $inputAttrs . '>';
    echo '</div>';
    return;
}
if ($mode === 'inline') {
    $wrapClass = $wrapClass !== '' ? $wrapClass : 'admin-search-wrap';
    $isAlertStyle = str_contains($wrapClass, 'alert-search');
    $iconClass = $isAlertStyle ? 'alert-search-icon' : '';
    $inputClass = $isAlertStyle ? 'form-control form-control-sm alert-search-input' : 'form-control';
    echo '<div class="' . e($wrapClass) . '" style="position:relative;">';
    echo '<i class="ti ti-search ' . e($iconClass) . '" aria-hidden="true" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--sv-muted);pointer-events:none;"></i>';
    echo '<input class="' . e($inputClass) . '" type="search" id="' . e($inputId) . '" name="' . e($name) . '" value="' . e($value) . '" placeholder="' . e($placeholder) . '" style="padding-left:2.1rem;" ' . $inputAttrs . '>';
    echo '</div>';
    return;
}

// GET mode: unified alert-search / dashboard-search style with preserves and clear
$wrapClass = $wrapClass !== '' ? $wrapClass : 'alert-search-form';
$inputWrapClass = 'alert-search-wrap';
if ($resetUrl === '' && $action !== '') {
    // build reset: action without q
    $resetUrl = $action;
}
if ($resetUrl === '' && !empty($preserve)) {
    // fallback: rebuild without current search
    $filtered = array_filter($preserve, static fn($v) => $v !== '' && $v !== null && $v !== 'all' && $v !== 0 && $v !== '0');
    $resetUrl = $action . ($filtered ? '?' . http_build_query($filtered) : '');
}
?>
<form method="get" action="<?= e($action) ?>" class="<?= e($wrapClass) ?>" role="search" aria-label="Filter">
    <?php foreach ($preserve as $k => $v): ?>
        <?php if ($v === '' || $v === null || $v === 'all') continue; ?>
        <?php if ($k === $name) continue; ?>
        <input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>">
    <?php endforeach; ?>
    <div class="<?= e($inputWrapClass) ?>">
        <i class="ti ti-search alert-search-icon" aria-hidden="true"></i>
        <input type="search" name="<?= e($name) ?>" id="<?= e($inputId) ?>" value="<?= e($value) ?>" placeholder="<?= e($placeholder) ?>" class="form-control form-control-sm alert-search-input" <?= $inputAttrs ?> aria-label="<?= e($placeholder) ?>">
        <?php if ($value !== ''): ?>
            <a href="<?= e($resetUrl !== '' ? $resetUrl : $action) ?>" class="alert-search-clear" aria-label="Clear search"><i class="ti ti-x"></i></a>
        <?php endif; ?>
    </div>
    <?php if ($showSubmit): ?>
        <button class="btn btn-info btn-sm ms-2 d-none d-lg-inline-flex" type="submit">Filter</button>
    <?php endif; ?>
</form>
