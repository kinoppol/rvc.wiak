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
    // The board only exists on the dashboard. Every generic data-post /
    // data-ajax-form handler calls this unconditionally, so bail out quietly
    // elsewhere — throwing here would abort the rest of the success handler
    // (including the data-reload-on-success reload).
    if (!form || !document.getElementById("board-root")) return;
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
  // Deep link from a desktop notification (/open/ticket/{id} → /?open_ticket={id}).
  // The parameter is dropped so a reload doesn't reopen the ticket.
  var deepLink = new URLSearchParams(location.search);
  var deepLinkId = deepLink.get("open_ticket");
  if (deepLinkId !== null) {
    deepLink.delete("open_ticket");
    var rest = deepLink.toString();
    history.replaceState(null, "", location.pathname + (rest ? "?" + rest : "") + location.hash);
    if (/^\d+$/.test(deepLinkId) && document.getElementById("overlay-root")) openTicket(deepLinkId);
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
    // A submit button may post the same fields elsewhere via formaction
    // (e.g. "ส่งงานพร้อมไฟล์แนบ" in the attachment form) and ask first.
    var submitter = e.submitter || null;
    var confirmMsg = submitter && submitter.getAttribute("data-confirm");
    if (confirmMsg && !confirm(confirmMsg)) return;
    var action = (submitter && submitter.getAttribute("formaction")) || form.getAttribute("action");
    var fd = new FormData(form);
    var submitBtn = submitter || form.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    postJson(action, fd).then(function (res) {
      if (submitBtn) submitBtn.disabled = false;
      if (!res.body.ok) { alert(res.body.error || "เกิดข้อผิดพลาด"); return; }
      var ticketId = form.getAttribute("data-refresh-ticket");
      if (ticketId) { openTicket(ticketId); } else { closeOverlay(); }
      refreshBoard();
      if (form.getAttribute("data-reload-on-success")) location.reload();
    });
  });

  // ---------- desktop installer upload (in chunks) ----------
  document.addEventListener("submit", function (e) {
    var form = e.target.closest("[data-release-upload]");
    if (!form) return;
    e.preventDefault();
    var file = form.elements.file.files[0];
    var pkg = form.elements.package.files[0];
    if (!file || !pkg) return;
    var maxBytes = parseInt(form.getAttribute("data-max-bytes"), 10);
    if (file.size > maxBytes || pkg.size > maxBytes) { alert("ไฟล์มีขนาดเกินที่กำหนด"); return; }
    var expectedPkg = "EduTaskNotifier-" + form.elements.version.value.trim() + "-full.nupkg";
    if (pkg.name !== expectedPkg) { alert("แพ็กเกจอัปเดตต้องชื่อ " + expectedPkg); return; }
    var chunkSize = parseInt(form.getAttribute("data-chunk-size"), 10);
    var grandTotal = file.size + pkg.size;
    var btn = form.querySelector('[type="submit"]');
    var wrap = form.querySelector("[data-release-progress]");
    var bar = wrap.querySelector(".progress-bar");
    function progress(pct) {
      bar.style.width = pct + "%";
      bar.textContent = pct + "%";
      wrap.firstElementChild.setAttribute("aria-valuenow", pct);
    }
    function stop(message) {
      btn.disabled = false;
      wrap.classList.add("d-none");
      alert(message);
    }
    // One file, chunk by chunk. `first` holds the fields sent with chunk 0.
    function sendFile(f, first, offset, onDone, onFail) {
      var total = Math.max(1, Math.ceil(f.size / chunkSize));
      function send(i) {
        var fd = new FormData();
        fd.append("index", i);
        fd.append("total", total);
        fd.append("chunk", f.slice(i * chunkSize, (i + 1) * chunkSize), "chunk");
        if (i === 0) {
          fd.append("file_name", f.name);
          fd.append("size", f.size);
          Object.keys(first).forEach(function (k) { fd.append(k, first[k]); });
        }
        postJson(form.getAttribute("action"), fd).then(function (res) {
          if (!res.body.ok) { onFail(res.body.error || "อัปโหลดไม่สำเร็จ"); return; }
          progress(Math.round(((offset + Math.min(f.size, (i + 1) * chunkSize)) / grandTotal) * 100));
          if (res.body.done) { onDone(res.body.id); return; }
          send(i + 1);
        }).catch(function () { onFail("การเชื่อมต่อขัดข้อง กรุณาอัปโหลดใหม่"); });
      }
      send(0);
    }
    btn.disabled = true;
    wrap.classList.remove("d-none");
    progress(0);
    // The release is published only after its update package has arrived.
    sendFile(file, {
      kind: "installer",
      version: form.elements.version.value,
      notes: form.elements.notes.value,
      make_current: "0",
    }, 0, function (id) {
      sendFile(pkg, {
        kind: "package",
        release_id: id,
        make_current: form.elements.make_current.checked ? "1" : "0",
      }, file.size, function () { location.reload(); }, function (message) {
        var cleanup = function () { stop(message); };
        postJson("/admin/desktop-releases/" + id + "/delete", {}).then(cleanup, cleanup);
      });
    }, stop);
  });

  // ---------- todo overlay ----------
  document.addEventListener("click", function (e) {
    var newBtn = e.target.closest("[data-open-new-todo]");
    if (newBtn) {
      fetchHtml("/todos/new").then(function (res) { openOverlay(res.text); });
    }
    var editBtn = e.target.closest("[data-open-todo]");
    if (editBtn) {
      fetchHtml("/todos/" + editBtn.getAttribute("data-open-todo") + "/edit").then(function (res) { openOverlay(res.text); });
    }
  });

  // Show/hide conditional recurrence fields in the todo form.
  // Initial visibility is set by PHP; this handles user changes.
  function applyRecurVisibility(type) {
    ["weekly", "monthly_date", "yearly", "interval"].forEach(function (t) {
      var el = document.getElementById("todo-recur-" + t);
      if (el) el.style.display = t === type ? "" : "none";
    });
    var endEl = document.getElementById("todo-recur-end");
    if (endEl) endEl.style.display = type !== "none" ? "" : "none";
  }
  document.addEventListener("change", function (e) {
    var sel = e.target.closest("#todo-recur-type");
    if (sel) applyRecurVisibility(sel.value);
  });

  // ---------- clipboard paste into the attachment file input ----------
  // Clipboard images arrive with no useful filename (browsers report
  // "image.png" or an empty name for every screenshot), so synthesise a
  // timestamped one — Upload::store() keeps the original name in the DB and
  // it is what the recipient sees in the attachment list.
  function clipboardFileName(file) {
    if (file.name && file.name !== "image.png" && file.name !== "blob") return file.name;
    var ext = ((file.type || "").split("/")[1] || "png").replace(/[^a-z0-9]/gi, "");
    var d = new Date();
    function pad(n) { return String(n).padStart(2, "0"); }
    return "clipboard-" + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate())
         + "-" + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds()) + "." + ext;
  }

  function showPasteHint(input) {
    var hint = document.querySelector("[data-paste-hint]");
    if (!hint) return;
    var f = input.files && input.files[0];
    hint.textContent = f ? "เลือกไฟล์แล้ว: " + f.name + " (" + Math.max(1, Math.round(f.size / 1024)) + " KB)" : "";
  }

  document.addEventListener("paste", function (e) {
    var input = document.querySelector("[data-paste-file]");
    if (!input) return;
    var files = e.clipboardData && e.clipboardData.files;
    if (!files || !files.length) return; // plain-text paste — leave it alone
    e.preventDefault();
    var src = files[0];
    var dt = new DataTransfer();
    dt.items.add(new File([src], clipboardFileName(src), { type: src.type }));
    input.files = dt.files;
    showPasteHint(input);
  });

  document.addEventListener("change", function (e) {
    if (e.target.matches("[data-paste-file]")) showPasteHint(e.target);
  });

  // ---------- repeatable attachment rows ----------
  // "เพิ่มไฟล์" / "เพิ่มลิงก์" buttons carry data-add-row="<key>" and clone the
  // first [data-row] inside the matching [data-rows="<key>"] container, so
  // several files (files[]) or links (links[]/link_names[]) can be attached in
  // one submit — PHP reads the repeated names as arrays.
  document.addEventListener("click", function (e) {
    var add = e.target.closest("[data-add-row]");
    if (!add) return;
    var key = add.getAttribute("data-add-row");
    var form = add.closest("form") || document;
    var rows = form.querySelector('[data-rows="' + key + '"]');
    if (!rows) return;
    var row = rows.querySelector("[data-row]");
    if (!row) return;
    var clone = row.cloneNode(true);
    clone.querySelectorAll("input").forEach(function (i) { i.value = ""; });
    rows.appendChild(clone);
    var first = clone.querySelector("input");
    if (first) first.focus();
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

  // Copy-to-clipboard buttons: <button data-copy="#selector"> copies that
  // element's value/text; <button data-copy-text="..."> copies a literal.
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-copy], [data-copy-text]");
    if (!btn) return;
    var sel = btn.getAttribute("data-copy");
    var el = sel ? document.querySelector(sel) : null;
    var text = el ? (el.value != null ? el.value : el.textContent) : btn.getAttribute("data-copy-text");
    if (text == null) return;
    var flash = function () {
      var old = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-check2"></i> คัดลอกแล้ว';
      setTimeout(function () { btn.innerHTML = old; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(flash, function () {});
    } else if (el && el.select) {
      el.select();
      try { document.execCommand("copy"); flash(); } catch (err) {}
    }
  });

  // Autocomplete fields: <div data-combo> (see app/Views/partials/combo.php).
  // Items come from the embedded <script type="application/json">, so this
  // works inside overlays injected with innerHTML. A [data-combo-strict]
  // field must be chosen from the list — its hidden input gets the item's
  // value, and typing again clears it.
  var comboItems = function (box) {
    if (!box._items) {
      try { box._items = JSON.parse(box.querySelector("[data-combo-items]").textContent || "[]"); }
      catch (err) { box._items = []; }
    }
    return box._items;
  };
  var comboEsc = function (s) {
    return String(s).replace(/[&<>"']/g, function (c) { return "&#" + c.charCodeAt(0) + ";"; });
  };
  var comboMark = function (text, q) {
    var t = String(text), i = q ? t.toLowerCase().indexOf(q) : -1;
    if (i < 0) return comboEsc(t);
    return comboEsc(t.slice(0, i)) + "<mark>" + comboEsc(t.slice(i, i + q.length)) + "</mark>" + comboEsc(t.slice(i + q.length));
  };
  var comboClose = function (box) {
    var menu = box.querySelector(".combo-menu");
    if (menu) menu.hidden = true;
    var input = box.querySelector("[data-combo-input]");
    if (input) input.setAttribute("aria-expanded", "false");
    box._active = -1;
  };
  // People lists carry rank (2 = directly under the viewer, 1 = further down
  // that line) and units; members of the unit named in the form's "หน่วยงาน"
  // field get +2, so they and subordinates come before everyone else.
  var comboContext = function (box) {
    var name = box.getAttribute("data-combo-context-input");
    if (name) {
      var form = box.closest("form");
      var field = form && form.querySelector('[name="' + name + '"]');
      return field ? field.value.trim() : "";
    }
    return (box.getAttribute("data-combo-context") || "").trim();
  };
  var comboInUnit = function (it, ctx) {
    if (!ctx || !it.units) return "";
    var c = ctx.toLowerCase();
    for (var i = 0; i < it.units.length; i++) {
      var u = String(it.units[i]).toLowerCase();
      if (u === c || (c.length >= 3 && (u.indexOf(c) >= 0 || c.indexOf(u) >= 0))) return it.units[i];
    }
    return "";
  };
  var comboRender = function (box) {
    var input = box.querySelector("[data-combo-input]");
    var menu = box.querySelector(".combo-menu");
    var q = input.value.trim().toLowerCase();
    var terms = q.split(/\s+/).filter(Boolean);
    var ctx = comboContext(box);
    var hits = comboItems(box).filter(function (it) {
      var hay = (it.label + " " + (it.sub || "")).toLowerCase();
      return terms.every(function (t) { return hay.indexOf(t) >= 0; });
    }).map(function (it, i) {
      var unit = comboInUnit(it, ctx);
      return { it: it, i: i, unit: unit, score: (it.rank || 0) + (unit ? 2 : 0) };
    }).sort(function (a, b) {
      return b.score - a.score || a.i - b.i;
    }).slice(0, 60).map(function (h) {
      h.it._unit = h.unit;
      return h.it;
    });
    box._hits = hits;
    // Strict (pick-from-list) fields preselect the first hit so Enter picks it.
    // Free-text fields don't, so Enter keeps what was typed.
    var strict = box.hasAttribute("data-combo-strict");
    box._active = strict && hits.length ? 0 : -1;
    if (!hits.length) {
      menu.innerHTML = '<div class="combo-empty">' + (box.hasAttribute("data-combo-strict") ? "ไม่พบรายชื่อที่ตรงกัน" : "ไม่พบในรายการ — ใช้ข้อความที่พิมพ์ได้") + "</div>";
    } else {
      menu.innerHTML = hits.map(function (it, i) {
        return '<div class="combo-item' + (i === box._active ? " is-active" : "") + '" role="option" data-i="' + i + '">' +
          '<div class="combo-label">' + comboMark(it.label, terms[0] || "") +
          (it.rank ? ' <span class="combo-tag combo-tag-chain">' + (it.rank === 2 ? "ใต้บังคับบัญชาโดยตรง" : "ในสายงาน") + "</span>" : "") +
          (it._unit ? ' <span class="combo-tag combo-tag-unit">อยู่ใน ' + comboEsc(it._unit) + "</span>" : "") + "</div>" +
          (it.sub ? '<div class="combo-sub">' + comboMark(it.sub, terms[0] || "") + "</div>" : "") + "</div>";
      }).join("");
    }
    menu.hidden = false;
    input.setAttribute("aria-expanded", "true");
  };
  var comboPick = function (box, i) {
    var it = (box._hits || [])[i];
    if (!it) return;
    var input = box.querySelector("[data-combo-input]");
    var hidden = box.querySelector("[data-combo-value]");
    input.value = it.label;
    if (hidden) hidden.value = it.v;
    input.classList.remove("is-invalid");
    box.classList.add("is-picked");
    comboClose(box);
  };
  var comboMove = function (box, d) {
    var items = box.querySelectorAll(".combo-item");
    if (!items.length) return;
    box._active = (box._active + d + items.length) % items.length;
    items.forEach(function (el, i) { el.classList.toggle("is-active", i === box._active); });
    items[box._active].scrollIntoView({ block: "nearest" });
  };
  document.addEventListener("focusin", function (e) {
    var input = e.target.closest && e.target.closest("[data-combo-input]");
    if (input) comboRender(input.closest("[data-combo]"));
  });
  document.addEventListener("input", function (e) {
    var input = e.target.closest && e.target.closest("[data-combo-input]");
    if (!input) return;
    var box = input.closest("[data-combo]");
    var hidden = box.querySelector("[data-combo-value]");
    if (hidden) hidden.value = "";
    box.classList.remove("is-picked");
    comboRender(box);
  });
  document.addEventListener("keydown", function (e) {
    var input = e.target.closest && e.target.closest("[data-combo-input]");
    if (!input) return;
    var box = input.closest("[data-combo]");
    var open = !box.querySelector(".combo-menu").hidden;
    if (e.key === "ArrowDown") { e.preventDefault(); if (!open) comboRender(box); else comboMove(box, 1); }
    else if (e.key === "ArrowUp") { e.preventDefault(); if (open) comboMove(box, -1); }
    else if (e.key === "Enter" && open && box._active >= 0) { e.preventDefault(); comboPick(box, box._active); }
    else if (e.key === "Escape" && open) { e.preventDefault(); comboClose(box); }
    else if (e.key === "Tab" && open && box.hasAttribute("data-combo-strict") && box._active >= 0 && !box.classList.contains("is-picked")) { comboPick(box, box._active); }
  });
  document.addEventListener("mousedown", function (e) {
    var item = e.target.closest && e.target.closest(".combo-item");
    if (item) { e.preventDefault(); comboPick(item.closest("[data-combo]"), +item.getAttribute("data-i")); return; }
    document.querySelectorAll("[data-combo]").forEach(function (box) {
      if (!box.contains(e.target)) comboClose(box);
    });
  });
  document.addEventListener("focusout", function (e) {
    var input = e.target.closest && e.target.closest("[data-combo-input]");
    if (!input) return;
    var box = input.closest("[data-combo]");
    setTimeout(function () { if (!box.contains(document.activeElement)) comboClose(box); }, 120);
  });
  // Capture phase: runs before the [data-ajax-form] submit handler, so a strict
  // field left without a choice stops the submit there.
  document.addEventListener("submit", function (e) {
    var bad = null;
    e.target.querySelectorAll("[data-combo-strict]").forEach(function (box) {
      var hidden = box.querySelector("[data-combo-value]");
      var input = box.querySelector("[data-combo-input]");
      if (hidden && !hidden.value) {
        // An exact typed name still counts as a choice.
        var typed = input.value.trim();
        var exact = comboItems(box).filter(function (it) { return it.label === typed; });
        if (exact.length === 1) { hidden.value = exact[0].v; return; }
        input.classList.add("is-invalid");
        bad = bad || input;
      }
    });
    if (bad) {
      e.preventDefault();
      e.stopImmediatePropagation();
      bad.focus();
    }
  }, true);

  // Tab sets marked [data-tab-hash] (e.g. /admin/settings) open the tab named
  // by the URL hash (#rms, or the id of something inside a tab pane), so a
  // form POST that redirects back with "#rms" lands on the tab it came from.
  // Switching tabs rewrites the hash so a reload stays on the same tab.
  var tabSet = document.querySelector("[data-tab-hash]");
  if (tabSet && window.bootstrap && bootstrap.Tab) {
    var showFromHash = function () {
      var h = decodeURIComponent((location.hash || "").slice(1));
      if (!h) return;
      var btn = tabSet.querySelector('[data-hash="' + h.replace(/"/g, "") + '"]');
      if (!btn) {
        var target = document.getElementById(h);
        var pane = target && target.closest(".tab-pane");
        if (pane) btn = tabSet.querySelector('[data-bs-target="#' + pane.id + '"]');
      }
      if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
    };
    showFromHash();
    window.addEventListener("hashchange", showFromHash);
    tabSet.addEventListener("shown.bs.tab", function (e) {
      var h = e.target.getAttribute("data-hash");
      if (h && history.replaceState) history.replaceState(null, "", "#" + h);
    });
  }
  // /admin/org: pencil toggles a row's inline edit form, the add form's ฝ่าย
  // select only applies to งาน/แผนก, and the search box filters the tree.
  document.addEventListener("click", function (e) {
    var t = e.target.closest && e.target.closest("[data-org-edit], [data-org-edit-group]");
    if (!t) return;
    e.preventDefault();
    var scope = t.hasAttribute("data-org-edit") ? t.closest("[data-org-row]") : t.closest("[data-org-group]");
    var view = scope.querySelector(t.hasAttribute("data-org-edit") ? ".org-row-view" : ".org-group-head");
    var form = scope.querySelector(t.hasAttribute("data-org-edit") ? ".org-row-edit" : ".org-group-edit");
    var opening = form.hidden;
    form.hidden = !opening;
    if (t.hasAttribute("data-org-edit")) view.hidden = opening;
    if (opening) { scope.open = true; var f = form.querySelector("input[name=name]"); f.focus(); f.select(); }
  });
  var orgAdd = document.querySelector("[data-org-add]");
  if (orgAdd) {
    var syncAdd = function () {
      var type = orgAdd.querySelector("[data-org-add-type]").value;
      var div = orgAdd.querySelector("[data-org-add-division]");
      var noDiv = type === "division" || (type === "department" && orgAdd.hasAttribute("data-dept-no-division"));
      div.disabled = noDiv;
      div.hidden = noDiv;
      orgAdd.querySelector("input[name=name]").placeholder = "ชื่อ" + orgAdd.querySelector("[data-org-add-type]").selectedOptions[0].text + "ใหม่";
    };
    orgAdd.querySelector("[data-org-add-type]").addEventListener("change", syncAdd);
    syncAdd();
  }
  var orgFilter = document.querySelector("[data-org-filter]");
  if (orgFilter) {
    orgFilter.addEventListener("input", function () {
      var q = orgFilter.value.trim().toLowerCase();
      var any = false;
      document.querySelectorAll("[data-org-group]").forEach(function (g) {
        var groupHit = q && g.getAttribute("data-org-name").indexOf(q) >= 0;
        var rows = 0;
        g.querySelectorAll("[data-org-row]").forEach(function (r) {
          var hit = !q || groupHit || r.getAttribute("data-org-name").indexOf(q) >= 0;
          r.hidden = !hit;
          if (hit) rows++;
        });
        var show = !q || groupHit || rows > 0;
        g.hidden = !show;
        if (q && show) g.open = true;
        if (show) any = true;
      });
      var none = document.querySelector("[data-org-nohit]");
      if (none) none.hidden = any;
    });
  }
  // Land on the row a save/add redirected to, briefly highlighted.
  if (location.hash.indexOf("#u-") === 0) {
    var landed = document.getElementById(location.hash.slice(1));
    if (landed) {
      landed.classList.add("org-flash");
      landed.scrollIntoView({ block: "center" });
    }
  }
})();
