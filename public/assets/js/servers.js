(() => {
  const rows = Array.from(document.querySelectorAll("[data-server-row]"));
  const searchInput = document.querySelector("[data-server-search]");
  const statusFilter = document.querySelector("[data-server-status-filter]");
  const resetButton = document.querySelector("[data-server-filter-reset]");
  const countLabel = document.querySelector("[data-server-result-count]");
  const summaryLabel = document.querySelector("[data-server-filter-summary]");
  const pagination = document.querySelector("[data-server-pagination]");
  const emptyRow = document.querySelector("[data-server-empty]");
  const filterEmptyRow = document.querySelector("[data-server-filter-empty]");
  const bulkBar = document.querySelector("[data-bulk-bar]");
  const bulkCount = document.querySelector("[data-bulk-count]");
  const bulkForm = document.querySelector("[data-server-bulk-form]");
  const checkAll = document.querySelector("[data-server-checkall]");
  if (!searchInput || !statusFilter || !pagination) return;

  const pageSize = 20;
  let currentPage = 1;

  function getSelectedCheckboxes() {
    return Array.from(document.querySelectorAll("[data-server-checkbox]:checked")).filter((cb) => !cb.disabled);
  }

  function updateBulkBar() {
    if (!bulkBar) return;
    const selected = getSelectedCheckboxes();
    bulkBar.hidden = selected.length < 1;
    if (bulkCount) bulkCount.textContent = `${selected.length} selected`;
    if (checkAll) {
      const visible = Array.from(document.querySelectorAll("[data-server-checkbox]")).filter((cb) => !cb.disabled);
      const checked = visible.filter((cb) => cb.checked).length;
      checkAll.checked = visible.length > 0 && checked === visible.length;
      checkAll.indeterminate = checked > 0 && checked < visible.length;
    }
  }

  function getFilteredRows() {
    const query = String(searchInput.value || "").trim().toLowerCase();
    const status = String(statusFilter.value || "all");
    return rows.filter((row) => {
      const matchesQuery = !query || String(row.dataset.serverSearch || "").includes(query);
      const matchesStatus = status === "all" || row.dataset.serverStatus === status;
      return matchesQuery && matchesStatus;
    });
  }

  function renderPagination(totalPages) {
    pagination.replaceChildren();
    if (totalPages <= 1) return;

    const addButton = (label, page, disabled = false, active = false) => {
      const item = document.createElement("li");
      item.className = `page-item${disabled ? " disabled" : ""}${active ? " active" : ""}`;
      const button = document.createElement("button");
      button.type = "button";
      button.className = "page-link";
      button.textContent = label;
      button.disabled = disabled;
      button.dataset.serverPage = String(page);
      if (active) button.setAttribute("aria-current", "page");
      item.appendChild(button);
      pagination.appendChild(item);
    };

    addButton("‹", Math.max(1, currentPage - 1), currentPage === 1);
    for (let page = 1; page <= totalPages; page += 1) {
      if (totalPages > 7 && page > 2 && page < totalPages - 1 && Math.abs(page - currentPage) > 1) {
        if (!pagination.querySelector("[data-pagination-gap]")) {
          const gap = document.createElement("li");
          gap.className = "page-item disabled";
          gap.dataset.paginationGap = "1";
          gap.innerHTML = '<span class="page-link">…</span>';
          pagination.appendChild(gap);
        }
        continue;
      }
      addButton(String(page), page, false, page === currentPage);
    }
    addButton("›", Math.min(totalPages, currentPage + 1), currentPage === totalPages);
  }

  function render() {
    const filteredRows = getFilteredRows();
    const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
    currentPage = Math.min(currentPage, totalPages);
    const start = (currentPage - 1) * pageSize;
    const visibleRows = new Set(filteredRows.slice(start, start + pageSize));

    rows.forEach((row) => {
      row.hidden = !visibleRows.has(row);
      row.querySelectorAll("[data-server-checkbox]").forEach((cb) => {
        cb.disabled = row.hidden;
      });
    });
    if (emptyRow) emptyRow.hidden = rows.length > 0;
    if (filterEmptyRow) filterEmptyRow.hidden = rows.length === 0 || filteredRows.length > 0;

    const hasFilter = String(searchInput.value || "").trim() !== "" || statusFilter.value !== "all";
    const rangeStart = filteredRows.length === 0 ? 0 : start + 1;
    const rangeEnd = Math.min(start + pageSize, filteredRows.length);
    if (countLabel) countLabel.textContent = `${filteredRows.length} of ${rows.length} servers`;
    if (summaryLabel) summaryLabel.textContent = filteredRows.length === 0
      ? (hasFilter ? "No servers match the current filter." : "No servers available yet.")
      : `Showing ${rangeStart}–${rangeEnd}`;
    renderPagination(totalPages);
    updateBulkBar();
  }

  searchInput.addEventListener("input", () => {
    currentPage = 1;
    render();
  });
  statusFilter.addEventListener("change", () => {
    currentPage = 1;
    render();
  });
  document.querySelectorAll("[data-server-filter-reset]").forEach((btn) => {
    btn.addEventListener("click", () => {
      searchInput.value = "";
      statusFilter.value = "all";
      currentPage = 1;
      render();
      searchInput.focus();
    });
  });
  pagination.addEventListener("click", (event) => {
    const button = event.target.closest("[data-server-page]");
    if (!button || button.disabled) return;
    currentPage = Number(button.dataset.serverPage || 1);
    render();
  });

  checkAll?.addEventListener("change", () => {
    document.querySelectorAll("[data-server-checkbox]").forEach((cb) => {
      if (!cb.disabled) cb.checked = checkAll.checked;
    });
    updateBulkBar();
  });

  document.addEventListener("change", (event) => {
    if (event.target.closest("[data-server-checkbox]")) {
      updateBulkBar();
    }
  });

  bulkForm?.addEventListener("submit", (event) => {
    const button = event.submitter instanceof HTMLElement ? event.submitter.closest("[data-bulk-submit]") : null;
    if (!button) return;
    const ids = getSelectedCheckboxes()
      .map((cb) => cb.value)
      .filter((value) => value);
    if (ids.length === 0) {
      event.preventDefault();
      return;
    }
    const action = button.getAttribute("value") || "";
    let message;
    if (action === "batch_delete") {
      message = `Delete ${ids.length} selected server(s) and all related metrics?`;
    } else {
      const verb = action === "batch_enable" ? "enable" : "disable";
      message = `Turn ${verb} monitoring for ${ids.length} selected server(s)?`;
    }
    event.preventDefault();
    var modalEl = document.getElementById("servmonConfirmModal");
    if (!modalEl || typeof bootstrap === "undefined") {
      if (!window.confirm(message)) return;
      /* Fallback to original sync flow but we must submit manually since we called preventDefault */
      const idsContainer = bulkForm.querySelector("[data-bulk-ids]");
      idsContainer.replaceChildren();
      // form.submit() skips the submitter button, so carry the action explicitly.
      const fallbackAction = document.createElement("input");
      fallbackAction.type = "hidden";
      fallbackAction.name = "action";
      fallbackAction.value = action;
      idsContainer.appendChild(fallbackAction);
      ids.forEach((id) => {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = "server_ids[]";
        input.value = id;
        idsContainer.appendChild(input);
      });
      bulkForm.submit();
    } else {
      var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
      var bodyEl = document.getElementById("servmonConfirmModalBody");
      if (bodyEl) bodyEl.textContent = message;
      var proceedBtn = document.getElementById("servmonConfirmModalProceed");
      if (proceedBtn) {
        var isDelete = action === "batch_delete";
        proceedBtn.textContent = isDelete ? "Delete" : "Disable";
        proceedBtn.className = isDelete ? "btn btn-danger" : "btn btn-warning";
      }
      /* Store a one-time handler for the proceed click. */
      var handler = function () {
        proceedBtn.removeEventListener("click", handler);
        bsModal.hide();
        /* Build the hidden inputs and submit. */
        var idsContainer = bulkForm.querySelector("[data-bulk-ids]");
        idsContainer.replaceChildren();
        // form.submit() skips the submitter button, so carry the action explicitly.
        var actionInput = document.createElement("input");
        actionInput.type = "hidden";
        actionInput.name = "action";
        actionInput.value = action;
        idsContainer.appendChild(actionInput);
        ids.forEach(function (id) {
          var input = document.createElement("input");
          input.type = "hidden";
          input.name = "server_ids[]";
          input.value = id;
          idsContainer.appendChild(input);
        });
        bulkForm.submit();
      };
      proceedBtn.addEventListener("click", handler);
      modalEl.addEventListener("hidden.bs.modal", function cleanup() {
        modalEl.removeEventListener("hidden.bs.modal", cleanup);
        proceedBtn.removeEventListener("click", handler);
      });
      bsModal.show();
      return;
    }
  });

  render();
})();
