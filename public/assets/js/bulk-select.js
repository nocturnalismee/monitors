(() => {
  // Generic bulk checkbox selection for admin tables (alert logs, audit logs).
  // Scope element: [data-bulk-select], with optional config attributes:
  //   data-bulk-input-name  hidden input name for selected ids (default "ids[]")
  //   data-bulk-confirm     confirm message, {n} replaced with selection count
  // Children inside the scope:
  //   [data-bulk-checkall]  "select all" checkbox
  //   [data-bulk-checkbox]  per-row checkbox
  //   [data-bulk-bar]       bar shown when >= 1 row selected
  //   [data-bulk-count]     label inside the bar ("N selected")
  //   [data-bulk-form]      POST form; [data-bulk-ids] receives hidden inputs
  //   [data-bulk-submit]    submit button(s) triggering confirmation
  const scopes = Array.from(document.querySelectorAll("[data-bulk-select]"));
  if (scopes.length === 0) return;

  function selectedBoxes(scope) {
    return Array.from(scope.querySelectorAll("[data-bulk-checkbox]:checked"));
  }

  function updateBar(scope) {
    const bar = scope.querySelector("[data-bulk-bar]");
    if (!bar) return;
    const count = selectedBoxes(scope).length;
    bar.hidden = count < 1;
    const label = scope.querySelector("[data-bulk-count]");
    if (label) label.textContent = `${count} selected`;
    const checkAll = scope.querySelector("[data-bulk-checkall]");
    if (checkAll) {
      const boxes = Array.from(scope.querySelectorAll("[data-bulk-checkbox]"));
      const checked = boxes.filter((cb) => cb.checked).length;
      checkAll.checked = boxes.length > 0 && checked === boxes.length;
      checkAll.indeterminate = checked > 0 && checked < boxes.length;
    }
  }

  function showConfirm(message, onProceed) {
    const modalEl = document.getElementById("servmonConfirmModal");
    if (!modalEl || typeof bootstrap === "undefined") {
      if (window.confirm(message)) onProceed();
      return;
    }
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const bodyEl = document.getElementById("servmonConfirmModalBody");
    if (bodyEl) bodyEl.textContent = message;
    const proceedBtn = document.getElementById("servmonConfirmModalProceed");
    if (proceedBtn) {
      proceedBtn.textContent = "Delete";
      proceedBtn.className = "btn btn-danger";
    }
    const handler = function () {
      proceedBtn.removeEventListener("click", handler);
      bsModal.hide();
      onProceed();
    };
    proceedBtn.addEventListener("click", handler);
    modalEl.addEventListener("hidden.bs.modal", function cleanup() {
      modalEl.removeEventListener("hidden.bs.modal", cleanup);
      proceedBtn.removeEventListener("click", handler);
    });
    bsModal.show();
  }

  scopes.forEach((scope) => {
    const form = scope.querySelector("[data-bulk-form]");
    const inputName = scope.getAttribute("data-bulk-input-name") || "ids[]";
    const confirmTpl = scope.getAttribute("data-bulk-confirm") || "Delete {n} selected row(s)?";

    scope.querySelector("[data-bulk-checkall]")?.addEventListener("change", (event) => {
      scope.querySelectorAll("[data-bulk-checkbox]").forEach((cb) => {
        cb.checked = event.target.checked;
      });
      updateBar(scope);
    });

    scope.addEventListener("change", (event) => {
      if (event.target.closest("[data-bulk-checkbox]")) updateBar(scope);
    });

    form?.addEventListener("submit", (event) => {
      const ids = selectedBoxes(scope)
        .map((cb) => cb.value)
        .filter((value) => value);
      if (ids.length === 0) {
        event.preventDefault();
        return;
      }
      event.preventDefault();
      showConfirm(confirmTpl.replace("{n}", String(ids.length)), () => {
        const container = form.querySelector("[data-bulk-ids]");
        container.replaceChildren();
        ids.forEach((id) => {
          const input = document.createElement("input");
          input.type = "hidden";
          input.name = inputName;
          input.value = id;
          container.appendChild(input);
        });
        form.submit();
      });
    });

    updateBar(scope);
  });
})();
