<?php
declare(strict_types=1);
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset_url('assets/js/theme.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/sidebar.js')) ?>"></script>
<?php
$isAdminPage = $isAdminPage ?? false;
$isPublicPage = $isPublicPage ?? false;
if ($isPublicPage) {
?>
<footer class="public-footer" aria-label="Footer">
    <div class="public-footer-brand">
        <i class="ti ti-server text-cyan" aria-hidden="true"></i>
        <span><?= e(APP_NAME) ?></span>
    </div>
    <div class="public-copyright">
        <span>&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?> <span class="public-copyright-separator">&middot;</span> Created with <i class="ti ti-heart text-danger" aria-hidden="true"></i> <strong>Arief</strong></span>
    </div>
</footer>
<?php } ?>
<?php
if ($isAdminPage || $isPublicPage) {
    $alertEndpoint = $isAdminPage ? app_url('api/alerts') : app_url('api/public-alerts');
    $alertSoundEnabled = setting_get('alert_sound_enabled') === '1';
    $alertSoundVolume = max(0, min(10, (int) setting_get('alert_sound_volume')));
?>
<div id="servmon-alert-toast-container" class="servmon-alert-toast-container toast-container position-fixed end-0 p-3"></div>
  <script>window.SERVMON_API_ALERTS = <?= json_encode($alertEndpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.SERVMON_ALERT_STREAM = <?= json_encode($isAdminPage ? app_url('api/events') : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.SERVMON_ALERT_SOUND_ENABLED = <?= $alertSoundEnabled ? 'true' : 'false' ?>; window.SERVMON_ALERT_SOUND_VOLUME = <?= (int) $alertSoundVolume ?>;</script>
<script src="<?= e(asset_url('assets/js/alerts.js')) ?>"></script>
<?php } ?>
<script>
document.querySelectorAll('[data-servmon-flash-toast="1"]').forEach(function (toastEl) {
    if (typeof bootstrap === 'undefined' || !bootstrap.Toast) {
        return;
    }

    var toast = bootstrap.Toast.getOrCreateInstance(toastEl);
    toast.show();
});
</script>
<div class="modal fade" id="servmonConfirmModal" tabindex="-1" aria-labelledby="servmonConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header border-soft">
        <h5 class="modal-title" id="servmonConfirmModalLabel">Confirm Action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="servmonConfirmModalBody">
        Are you sure?
      </div>
      <div class="modal-footer border-soft">
        <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="servmonConfirmModalProceed">Delete</button>
      </div>
    </div>
  </div>
</div>
</body>
</html>
