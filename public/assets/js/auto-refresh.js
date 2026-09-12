/**
 * Generic auto-reload poller for slow-changing admin pages.
 *
 * Configuration via globals (set inline per page before including):
 *   window.MONITORS_AUTO_REFRESH_MS            reload interval, min 5000 (default 30000)
 *   window.MONITORS_AUTO_REFRESH_SKIP_TERMINAL skip reload while the ping
 *                                             terminal modal is open (ping pages)
 */
document.addEventListener("DOMContentLoaded", () => {
  const intervalMs = Number(window.MONITORS_AUTO_REFRESH_MS || 30000);
  if (!Number.isFinite(intervalMs) || intervalMs < 5000) return;
  const skipTerminal = window.MONITORS_AUTO_REFRESH_SKIP_TERMINAL === true;

  const hasActiveFormFocus = () => {
    const el = document.activeElement;
    if (!(el instanceof HTMLElement)) return false;
    const tag = el.tagName.toLowerCase();
    return tag === "input" || tag === "select" || tag === "textarea";
  };

  const hasActiveTerminal = () => {
    const modal = document.getElementById("pingTerminalModal");
    return !!modal && modal.classList.contains("show");
  };

  window.setInterval(() => {
    if (document.hidden) return;
    if (hasActiveFormFocus()) return;
    if (skipTerminal && hasActiveTerminal()) return;
    window.location.reload();
  }, intervalMs);
});
