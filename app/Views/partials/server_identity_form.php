<?php
declare(strict_types=1);
/**
 * Shared Server Identity fields (Add + Edit) — DRY for 6-field set.
 * Expected vars: $values array(name,location,host,type,provider,label), $idPrefix string, $providerPlaceholder string|null, $labelPlaceholder string|null
 * @var array<string,string> $values
 * @var string $idPrefix
 * @var string $providerPlaceholder
 * @var string $labelPlaceholder
 */
$values = $values ?? [];
$idPrefix = $idPrefix ?? 'server';
$providerPlaceholder = $providerPlaceholder ?? 'DigitalOcean, Hetzner, AWS, etc.';
$labelPlaceholder = $labelPlaceholder ?? 'Production, Staging, Core API, etc.';
$v = static fn(string $k): string => e((string)($values[$k] ?? ''));
?>
<div class="col-md-6">
    <label class="form-label" for="<?= e($idPrefix) ?>-name">Name</label>
    <input class="form-control" name="name" id="<?= e($idPrefix) ?>-name" value="<?= $v('name') ?>" required>
</div>
<div class="col-md-6">
    <label class="form-label" for="<?= e($idPrefix) ?>-location">Location</label>
    <input class="form-control" name="location" id="<?= e($idPrefix) ?>-location" value="<?= $v('location') ?>">
</div>
<div class="col-md-6">
    <label class="form-label" for="<?= e($idPrefix) ?>-host">Host/IP</label>
    <input class="form-control" name="host" id="<?= e($idPrefix) ?>-host" value="<?= $v('host') ?>">
</div>
<div class="col-md-6">
    <label class="form-label" for="<?= e($idPrefix) ?>-type">Type</label>
    <input class="form-control" name="type" id="<?= e($idPrefix) ?>-type" value="<?= $v('type') ?>">
</div>
<div class="col-md-6">
    <label class="form-label" for="<?= e($idPrefix) ?>-provider">Provider<?= str_contains($idPrefix, 'edit') ? '' : ' (optional)' ?></label>
    <input class="form-control" name="provider" id="<?= e($idPrefix) ?>-provider" value="<?= $v('provider') ?>" placeholder="<?= e($providerPlaceholder) ?>">
</div>
<div class="col-md-6">
    <label class="form-label" for="<?= e($idPrefix) ?>-label">Label<?= str_contains($idPrefix, 'edit') ? '' : ' (optional)' ?></label>
    <input class="form-control" name="label" id="<?= e($idPrefix) ?>-label" value="<?= $v('label') ?>" placeholder="<?= e($labelPlaceholder) ?>">
</div>
