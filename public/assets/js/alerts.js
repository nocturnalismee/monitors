const STORAGE_KEY = "servmon_last_alert_id";
const MAX_POPUPS_PER_POLL = 3;
const MAX_VISIBLE_TOASTS = 3;
const FRESH_ALERT_WINDOW_MS = 2 * 60 * 1000;

let servmonLastAlertId = Number(localStorage.getItem(STORAGE_KEY)) || 0;
let servmonAlertsInitialized = servmonLastAlertId > 0;

function playAlertBeep() {
  if (window.SERVMON_ALERT_SOUND_ENABLED === false) return;
  const url = window.SERVMON_ALERT_SOUND_URL || "";
  if (!url) return;
  try {
    const audio = new Audio(url);
    const volume = Math.max(0, Math.min(10, Number(window.SERVMON_ALERT_SOUND_VOLUME ?? 8))) / 10;
    audio.volume = volume;
    const playPromise = audio.play();
    if (playPromise && typeof playPromise.catch === "function") {
      playPromise.catch((err) => console.error(err));
    }
  } catch (err) {
    console.error(err);
  }
}

function updateLastAlertId(id) {
  const numericId = Number(id);
  if (numericId > servmonLastAlertId) {
    servmonLastAlertId = numericId;
    localStorage.setItem(STORAGE_KEY, servmonLastAlertId);
  }
}

function showAlertToast(alert) {
  const container = document.getElementById("servmon-alert-toast-container");
  if (!container) return;

  while (container.children.length >= MAX_VISIBLE_TOASTS) {
    container.firstElementChild?.remove();
  }

  const div = document.createElement("div");
  const severity =
    alert.severity === "danger"
      ? "danger"
      : alert.severity === "warning"
        ? "warning"
        : alert.severity === "success"
          ? "success"
          : "info";
  const closeBtnClass =
    severity === "warning" || severity === "info"
      ? "btn-close"
      : "btn-close btn-close-white";
  div.className = `toast align-items-center border-0 toast-severity toast-severity-${severity}`;
  div.setAttribute("role", "alert");
  div.setAttribute("aria-live", "assertive");
  div.setAttribute("aria-atomic", "true");
  div.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">
        <strong>${escapeHtml(alert.title || "Alert")}</strong><br>
        ${escapeHtml(alert.message || "")}
      </div>
      <button type="button" class="${closeBtnClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  `;
  container.appendChild(div);
  const toast = new bootstrap.Toast(div, { delay: 12000 });
  toast.show();
}

function escapeHtml(input) {
  const el = document.createElement("div");
  el.textContent = input ?? "";
  return el.innerHTML;
}

function isFreshAlert(alert) {
  const rawTimestamp = String(alert.created_at || "").trim();
  if (!rawTimestamp) return false;

  const timestamp = Date.parse(rawTimestamp.replace(" ", "T"));
  if (Number.isNaN(timestamp)) return false;

  const age = Date.now() - timestamp;
  return age >= -5000 && age <= FRESH_ALERT_WINDOW_MS;
}

async function pollAlerts() {
  const endpoint = window.SERVMON_API_ALERTS;
  if (!endpoint) return;
  try {
    // Attempt to grab any missed alerts since the browser last checked in
    const response = await fetch(`${endpoint}?since_id=${servmonLastAlertId}&limit=20`, { headers: { Accept: "application/json" } });
    if (!response.ok) return false;
    const rows = await response.json();
    if (!Array.isArray(rows)) return false;
    if (rows.length === 0) return true;

    consumeAlertRows(rows);
    return true;
  } catch (err) {
    console.error(err);
    return false;
  }
}

function consumeAlertRows(rows) {

    if (!servmonAlertsInitialized) {
      // First ever page load for a brand new browser - fast forward silently
      rows.forEach((row) => {
        updateLastAlertId(row.id);
      });
      servmonAlertsInitialized = true;
      return;
    }

    // Sort to handle oldest first so popup stacking makes temporal sense
    rows.sort((a, b) => Number(a.id || 0) - Number(b.id || 0));

    // Do not replay stale alerts after the browser was closed or suspended.
    // All rows still advance the watermark below, so they are treated as read.
    const freshRows = rows.filter(isFreshAlert);
    const popupsToShow = freshRows.slice(-MAX_POPUPS_PER_POLL);

    popupsToShow.forEach((row) => {
      showAlertToast(row);
    });

    // Crucially, update the high watermark ID for ALL rows retrieved (even the ones we muted)
    rows.forEach((row) => {
      updateLastAlertId(row.id);
    });

    if (popupsToShow.length > 0) {
      playAlertBeep();
    }
}

let servmonStreamFallbackStarted = false;
function startAlertPollingFallback() {
  if (servmonStreamFallbackStarted) return;
  servmonStreamFallbackStarted = true;
  pollAlerts();
  if (window.ServMon?.startPoller) {
    window.ServMon.startPoller(pollAlerts, { baseMs: 15000, maxMs: 120000 });
  }
}

const ALERT_STREAM_RETRY_MS = [2000, 4000, 8000, 16000, 30000, 60000];

function startAlertStream() {
  const streamEndpoint = window.SERVMON_ALERT_STREAM;
  if (!streamEndpoint || typeof EventSource === "undefined") {
    startAlertPollingFallback();
    return;
  }
  const stream = new EventSource(`${streamEndpoint}?since_id=${servmonLastAlertId}`, { withCredentials: true });
  let retries = 0;
  stream.addEventListener("alert", (event) => {
    try {
      consumeAlertRows([JSON.parse(event.data)]);
    } catch (err) {
      console.error(err);
    }
  });
  stream.addEventListener("open", () => {
    retries = 0;
  });
  stream.addEventListener("error", () => {
    stream.close();
    if (retries >= ALERT_STREAM_RETRY_MS.length) {
      startAlertPollingFallback();
      return;
    }
    const delay = ALERT_STREAM_RETRY_MS[retries++];
    const retryAlertStream = () => {
      if (document.visibilityState !== "visible") {
        setTimeout(retryAlertStream, delay);
        return;
      }
      startAlertStream();
    };
    setTimeout(retryAlertStream, delay);
  });
}

startAlertStream();
