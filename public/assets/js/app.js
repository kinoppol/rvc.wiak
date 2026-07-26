(function () {
  "use strict";

  var BASE = document.body.getAttribute("data-base") || "";
  var CSRF = document.body.getAttribute("data-csrf") || "";

  // ---------- theme ----------
  var mq = window.matchMedia("(prefers-color-scheme: dark)");
  function effectiveDark(theme) { return theme === "dark" || (theme === "system" && mq.matches); }
  function applyTheme(theme) {
    document.documentElement.setAttribute("data-bs-theme", effectiveDark(theme) ? "dark" : "light");
    document.querySelectorAll("[data-theme-btn]").forEach(function (b) {
      var active = b.getAttribute("data-theme-btn") === theme;
      b.classList.toggle("btn-primary", active);
      b.classList.toggle("btn-outline-secondary", !active);
    });
  }
  function setTheme(theme) {
    localStorage.setItem("edutask-theme", theme);
    applyTheme(theme);
  }
  mq.addEventListener("change", function () { applyTheme(localStorage.getItem("edutask-theme") || "system"); });
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-theme-btn]");
    if (btn) setTheme(btn.getAttribute("data-theme-btn"));
  });
  applyTheme(localStorage.getItem("edutask-theme") || "system");

  // ---------- mobile sidebar ----------
  function toggleSidebar(open) {
    document.querySelector(".sidebar").classList.toggle("open", open);
    document.querySelector(".sidebar-backdrop").classList.toggle("open", open);
  }
  document.addEventListener("click", function (e) {
    if (e.target.closest("[data-sidebar-open]")) toggleSidebar(true);
    if (e.target.closest("[data-sidebar-close]") || e.target.classList.contains("sidebar-backdrop")) toggleSidebar(false);
  });

  // ---------- generic overlay helpers ----------
  function openOverlay(html) {
    var host = document.getElementById("overlay-root");
    host.innerHTML = html;
  }
  function closeOverlay() {
    document.getElementById("overlay-root").innerHTML = "";
  }
  // Modals/drawers close only via an explicit [data-close-overlay] button —
  // never by clicking the backdrop or pressing Escape (by design).
  document.addEventListener("click", function (e) {
    if (e.target.closest("[data-close-overlay]")) closeOverlay();
  });

  // `url` may be app-relative ("/tickets/5") or already base-prefixed
  // (a PHP-rendered form `action`, e.g. "/rvc.wiak/tickets") — never prefix twice.
  function resolveUrl(url) {
    if (BASE === "" || url.indexOf(BASE + "/") === 0 || url === BASE) return url;
    return BASE + url;
  }

  function fetchHtml(url, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ "X-Requested-With": "XMLHttpRequest" }, opts.headers || {});
    return fetch(resolveUrl(url), opts).then(function (r) { return r.text().then(function (t) { return { ok: r.ok, status: r.status, text: t }; }); });
  }

  function postJson(url, data) {
    var fd = data instanceof FormData ? data : new FormData();
    if (data && !(data instanceof FormData)) {
      Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    }
    fd.append("csrf", CSRF);
    return fetch(resolveUrl(url), {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" },
    }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); });
  }

  // ---------- board (stats + ticket table) ----------
  var boardTimer = null;
  function refreshBoard() {
    var form = document.getElementById("board-filters");
    var params = new URLSearchParams(new FormData(form)).toString();
    fetchHtml("/board?" + params).then(function (res) {
      document.getElementById("board-root").innerHTML = res.text;
    });
  }
  document.addEventListener("submit", function (e) {
    if (e.target.id === "board-filters") { e.preventDefault(); refreshBoard(); }
  });
  document.addEventListener("input", function (e) {
    if (e.target.id === "board-query") {
      clearTimeout(boardTimer);
      boardTimer = setTimeout(refreshBoard, 300);
    }
  });
  document.addEventListener("click", function (e) {
    var f = e.target.closest("[data-board-filter]");
    if (f) {
      document.querySelectorAll("[data-board-filter]").forEach(function (b) {
        b.classList.remove("btn-primary");
        b.classList.add("btn-outline-secondary");
      });
      f.classList.remove("btn-outline-secondary");
      f.classList.add("btn-primary");
      document.getElementById("board-filter-input").value = f.getAttribute("data-board-filter");
      refreshBoard();
    }
  });

  // ---------- ticket detail ----------
  function openTicket(id) {
    fetchHtml("/tickets/" + id).then(function (res) {
      openOverlay(res.text);
    });
  }
  document.addEventListener("click", function (e) {
    var row = e.target.closest("[data-open-ticket]");
    if (row) openTicket(row.getAttribute("data-open-ticket"));
    var newBtn = e.target.closest("[data-open-new-ticket]");
    if (newBtn) {
      fetchHtml("/tickets/new").then(function (res) { openOverlay(res.text); });
    }
    var editRoles = e.target.closest("[data-open-user-roles]");
    if (editRoles) {
      fetchHtml("/admin/users/" + editRoles.getAttribute("data-open-user-roles") + "/roles").then(function (res) { openOverlay(res.text); });
    }
  });

  // Generic "action button" — POST to data-post, then refresh whatever's open.
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-post]");
    if (!btn) return;
    var confirmMsg = btn.getAttribute("data-confirm");
    if (confirmMsg && !window.confirm(confirmMsg)) return;
    btn.disabled = true;
    postJson(btn.getAttribute("data-post"), {}).then(function (res) {
      btn.disabled = false;
      if (!res.body.ok) { alert(res.body.error || "เกิดข้อผิดพลาด"); return; }
      if (btn.getAttribute("data-reload-on-success")) { location.reload(); return; }
      var ticketId = btn.getAttribute("data-refresh-ticket");
      if (ticketId) openTicket(ticketId);
      refreshBoard();
    });
  });

  // Generic AJAX form submit.
  document.addEventListener("submit", function (e) {
    var form = e.target.closest("[data-ajax-form]");
    if (!form) return;
    e.preventDefault();
    var fd = new FormData(form);
    var submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    postJson(form.getAttribute("action"), fd).then(function (res) {
      if (submitBtn) submitBtn.disabled = false;
      if (!res.body.ok) { alert(res.body.error || "เกิดข้อผิดพลาด"); return; }
      var ticketId = form.getAttribute("data-refresh-ticket");
      if (ticketId) { openTicket(ticketId); } else { closeOverlay(); }
      refreshBoard();
      if (form.getAttribute("data-reload-on-success")) location.reload();
    });
  });

  // Impersonate pick.
  document.addEventListener("click", function (e) {
    var pick = e.target.closest("[data-impersonate-user]");
    if (pick) {
      postJson("/admin/impersonate/" + pick.getAttribute("data-impersonate-user"), {}).then(function (res) {
        if (res.body.ok) location.reload(); else alert(res.body.error || "ไม่สามารถสวมสิทธิ์ได้");
      });
    }
  });

  // Roles modal: show a role's org-unit picker only while that role is ticked.
  // Delegated (not an inline script in the partial) because scripts injected
  // via innerHTML never execute.
  document.addEventListener("change", function (e) {
    var toggle = e.target.closest("[data-role-toggle]");
    if (!toggle) return;
    var box = document.querySelector('[data-role-units="' + toggle.getAttribute("data-role-toggle") + '"]');
    if (box) box.style.display = toggle.checked ? "" : "none";
  });

  // Role switch.
  document.addEventListener("change", function (e) {
    if (e.target.id === "role-switcher") {
      postJson("/role-switch", { role: e.target.value }).then(function () { location.reload(); });
    }
  });
})();
