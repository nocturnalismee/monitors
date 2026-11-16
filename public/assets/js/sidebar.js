(() => {
  const body = document.body;
  const desktopBtn = document.querySelector("[data-sidebar-toggle-desktop]");
  const desktopIcon = document.querySelector("[data-sidebar-toggle-icon]");
  const mobileBtn = document.querySelector("[data-sidebar-toggle-mobile]");
  const overlay = document.querySelector("[data-sidebar-overlay]");
  const stateKey = "monitors_sidebar_collapsed";
  const cookieKey = "monitors_sidebar_collapsed";

  if (!desktopBtn && !mobileBtn) return;

  const getCookie = (name) => {
    const encodedName = `${encodeURIComponent(name)}=`;
    const parts = document.cookie ? document.cookie.split(";") : [];
    for (const part of parts) {
      const trimmed = part.trim();
      if (trimmed.startsWith(encodedName)) {
        return decodeURIComponent(trimmed.slice(encodedName.length));
      }
    }
    return null;
  };

  const getPersistedCollapsed = () => {
    try {
      const saved = localStorage.getItem(stateKey);
      if (saved === "1" || saved === "0") return saved === "1";
    } catch (err) {
      // Ignore storage access errors and fallback to cookie.
    }
    return getCookie(cookieKey) === "1";
  };

  const setPersistedCollapsed = (collapsed) => {
    const value = collapsed ? "1" : "0";
    try {
      localStorage.setItem(stateKey, value);
    } catch (err) {
      // Ignore storage access errors.
    }
    document.cookie = `${encodeURIComponent(cookieKey)}=${value}; path=/; max-age=31536000; samesite=lax`;
  };

  if (getPersistedCollapsed()) {
    body.classList.add("sidebar-collapsed");
  }

  const syncDesktopToggleState = () => {
    if (!desktopBtn) return;
    const collapsed = body.classList.contains("sidebar-collapsed");
    const label = collapsed ? "Expand sidebar" : "Collapse sidebar";
    desktopBtn.setAttribute("title", label);
    desktopBtn.setAttribute("aria-label", label);
    if (desktopIcon) {
      desktopIcon.classList.toggle("ti-chevron-left", !collapsed);
      desktopIcon.classList.toggle("ti-chevron-right", collapsed);
    }
  };

  syncDesktopToggleState();

  desktopBtn?.addEventListener("click", () => {
    body.classList.toggle("sidebar-collapsed");
    setPersistedCollapsed(body.classList.contains("sidebar-collapsed"));
    syncDesktopToggleState();
  });

  let mobileLastFocused = null;
  const sidebar = document.querySelector(".monitors-sidebar");
  const mainRegion = document.getElementById("main-content");

  const isMobileOpen = () => body.classList.contains("sidebar-open-mobile");
  const mobileQuery = typeof window.matchMedia === "function" ? window.matchMedia("(max-width: 767.98px)") : null;

  const focusableIn = (root) =>
    Array.from(
      root.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);

  const setMobileAria = () => {
    if (mobileBtn) mobileBtn.setAttribute("aria-expanded", String(isMobileOpen()));
    const isMobile = !mobileQuery || mobileQuery.matches;
    if (sidebar) {
      if (isMobile) sidebar.setAttribute("aria-hidden", isMobileOpen() ? "false" : "true");
      else sidebar.removeAttribute("aria-hidden");
    }
    // Keep the page behind the drawer out of the tab order and the
    // accessibility tree while the drawer is open (mobile only).
    if (mainRegion) {
      if (isMobile) mainRegion.toggleAttribute("inert", isMobileOpen());
      else mainRegion.removeAttribute("inert");
    }
  };

  const trapFocus = (event) => {
    if (event.key !== "Tab" || !isMobileOpen() || !sidebar) return;
    if (mobileQuery && !mobileQuery.matches) return;
    const focusables = focusableIn(sidebar);
    if (focusables.length === 0) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  const closeMobileSidebar = (restoreFocus) => {
    if (!isMobileOpen()) return;
    body.classList.remove("sidebar-open-mobile");
    setMobileAria();
    if (restoreFocus && mobileLastFocused && typeof mobileLastFocused.focus === "function") {
      mobileLastFocused.focus();
    }
  };

  mobileBtn?.addEventListener("click", () => {
    if (isMobileOpen()) {
      closeMobileSidebar(true);
    } else {
      mobileLastFocused = document.activeElement;
      body.classList.add("sidebar-open-mobile");
      setMobileAria();
      if (sidebar) {
        const focusables = focusableIn(sidebar);
        const target = focusables.find((el) => el.classList.contains("nav-link")) || focusables[0];
        if (target) target.focus();
      }
    }
  });

  overlay?.addEventListener("click", () => {
    closeMobileSidebar(false);
  });

  document.querySelectorAll(".monitors-sidebar .nav-link").forEach((link) => {
    link.addEventListener("click", () => {
      closeMobileSidebar(false);
    });
  });

  document.addEventListener("keydown", (event) => {
    trapFocus(event);
    if (event.key === "Escape" && isMobileOpen()) {
      closeMobileSidebar(true);
    }
  });

  setMobileAria();
  if (mobileQuery) {
    mobileQuery.addEventListener("change", () => {
      closeMobileSidebar(false);
      setMobileAria();
    });
  }
})();
