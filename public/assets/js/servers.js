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
  if (!searchInput || !statusFilter || !pagination) return;

  const pageSize = 20;
  let currentPage = 1;

  function refreshBulkBar() {
    // Bulk selection (check-all, counter bar, confirm submit) is handled by
    // bulk-select.js; filtering only enables/disables row checkboxes here.
    document.dispatchEvent(new CustomEvent("monitors:bulk-refresh"));
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
    row.querySelectorAll("[data-bulk-checkbox]").forEach((cb) => {
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
    refreshBulkBar();
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

  render();
})();
