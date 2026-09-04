(function () {
  "use strict";

  var pendingTarget = null;
  var modalEl = null;
  var modal = null;

  function getModal() {
    if (modal) return modal;
    modalEl = document.getElementById("servmonConfirmModal");
    if (!modalEl || typeof bootstrap === "undefined") return null;
    modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    return modal;
  }

  /* --- Destructive-action confirmation via Bootstrap modal --- */
  document.addEventListener("click", function (event) {
    var target = event.target.closest("[data-confirm]");
    if (!target) return;

    var bsModal = getModal();
    if (!bsModal) {
      /* Fallback to native confirm if modal element is missing. */
      var msg = target.getAttribute("data-confirm") || "Continue with this action?";
      if (!window.confirm(msg)) event.preventDefault();
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    pendingTarget = target;

    var message = target.getAttribute("data-confirm") || "Are you sure you want to continue?";
    var bodyEl = document.getElementById("servmonConfirmModalBody");
    if (bodyEl) bodyEl.textContent = message;

    /* Determine proceed button label from the triggering element. */
    var proceedBtn = document.getElementById("servmonConfirmModalProceed");
    if (proceedBtn) {
      var btnText = (target.textContent || "").trim();
      var label = "Confirm";
      if (/delete/i.test(btnText)) { label = "Delete"; proceedBtn.className = "btn btn-danger"; }
      else if (/disable/i.test(btnText)) { label = "Disable"; proceedBtn.className = "btn btn-warning"; }
      else if (/remove/i.test(btnText)) { label = "Remove"; proceedBtn.className = "btn btn-danger"; }
      else { proceedBtn.className = "btn btn-danger"; }
      proceedBtn.textContent = label;
    }

    bsModal.show();
  });

  /* When the user clicks the proceed button inside the modal. */
  document.addEventListener("click", function (event) {
    if (!event.target.closest("#servmonConfirmModalProceed")) return;
    if (!pendingTarget) return;

    var bsModal = getModal();
    if (bsModal) bsModal.hide();

    /* Remove the data-confirm so re-click won't re-trigger the modal. */
    var confirmMsg = pendingTarget.getAttribute("data-confirm");
    pendingTarget.removeAttribute("data-confirm");
    pendingTarget.click();
    /* Restore the attribute in case the click didn't navigate away. */
    if (confirmMsg) pendingTarget.setAttribute("data-confirm", confirmMsg);
    pendingTarget = null;
  });

  /* Reset state when modal is dismissed without confirming. */
  document.addEventListener("hidden.bs.modal", function (event) {
    if (event.target && event.target.id === "servmonConfirmModal") {
      pendingTarget = null;
    }
  });

  /* --- Server token reveal toggle (extracted from inline script) --- */
  function toggleToken() {
    var display = document.getElementById("server-token-display");
    var full = document.getElementById("server-token-full");
    var btn = document.getElementById("server-token-toggle");
    if (!display || !full || !btn) return;
    var isHidden = display.classList.contains("d-none") === false;
    display.classList.toggle("d-none", !isHidden);
    full.classList.toggle("d-none", isHidden);
    btn.textContent = isHidden ? "Hide Token" : "Reveal Token";
  }
  window.toggleToken = toggleToken;
  document.addEventListener("click", function (event) {
    if (event.target.closest("#server-token-toggle")) toggleToken();
  });

  /* --- Submit-button loading state --- */
  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    var button = event.submitter instanceof HTMLElement
      ? event.submitter.closest("[data-submit-loading]")
      : form.querySelector("[data-submit-loading]");
    if (!button) return;
    var original = button.getAttribute("data-loading-text") || "Processing...";
    if (!button.hasAttribute("data-original-text")) {
      button.setAttribute("data-original-text", button.textContent || "");
    }
    button.disabled = true;
    button.textContent = original;
  });
})();
