/* global jQuery, MEADOW_ADMIN_TOOLS */
(function ($) {
  "use strict";

  function apiPost(url, nonce, payload) {
    return $.ajax({
      url: url,
      method: "POST",
      data: JSON.stringify(payload || {}),
      contentType: "application/json; charset=utf-8",
      dataType: "json",
      headers: { "X-WP-Nonce": nonce },
      timeout: 15000
    });
  }

  function apiGet(url, nonce, params) {
    const qs = params ? ("?" + new URLSearchParams(params).toString()) : "";
    return $.ajax({
      url: url + qs,
      method: "GET",
      dataType: "json",
      headers: { "X-WP-Nonce": nonce },
      timeout: 15000
    });
  }

  function setStatus($box, html) {
    $box.html(html);
  }

  function escapeHtml(s) {
    return String(s || "").replace(/[&<>"']/g, (m) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;"
    }[m]));
  }

  $(document).on("click", ".meadow-pi-action", function () {
    const action = $(this).data("action");
    const postId = parseInt($(this).data("kiosk-post-id") || 0, 10);
    const nonce = MEADOW_ADMIN_TOOLS.nonce;

    const $box = $(this).closest(".meadow-admin-tools").find(".meadow-pi-status");

    if (!postId) return;

    setStatus($box, "Working…");

    // Special actions that call dedicated routes
    if (action === "ping") {
  apiGet(MEADOW_ADMIN_TOOLS.rest.piStatus, nonce, { kiosk_post_id: postId })
    .done((r) => {
      const ok = !!(r && r.ok && r.pi && r.pi.ok);
      setStatus($box, ok ? "Pi status OK ✅" : ("Status failed ❌ " + escapeHtml(JSON.stringify(r))));
    })
    .fail((xhr) => {
      setStatus($box, "Status failed ❌ " + escapeHtml(xhr.responseText || xhr.statusText));
    });
  return;
}

    // Default: /admin/pi/control
    apiPost(MEADOW_ADMIN_TOOLS.rest.piControl, nonce, {
      kiosk_post_id: postId,
      action: action,
      payload: {}
    })
      .done((r) => {
        const ok = !!(r && r.ok);
        setStatus($box, ok ? "Done ✅" : ("Failed ❌ " + escapeHtml(JSON.stringify(r))));
      })
      .fail((xhr) => {
        setStatus($box, "Failed ❌ " + escapeHtml(xhr.responseText || xhr.statusText));
      });
  });

  $(document).on("click", ".meadow-vend-test", function () {
    const postId = parseInt($(this).data("kiosk-post-id") || 0, 10);
    const motor = parseInt($(this).data("motor") || 0, 10);
    const nonce = MEADOW_ADMIN_TOOLS.nonce;
    const $box = $(this).closest(".meadow-admin-tools").find(".meadow-pi-status");

    if (!postId || !motor) return;

    setStatus($box, "Spinning motor " + motor + "…");

    apiPost(MEADOW_ADMIN_TOOLS.rest.vendTest, nonce, {
      kiosk_post_id: postId,
      motor: motor
    })
      .done((r) => {
        const ok = !!(r && r.ok);
        setStatus($box, ok ? ("Motor " + motor + " queued ✅") : ("Motor failed ❌ " + escapeHtml(JSON.stringify(r))));
      })
      .fail((xhr) => {
        setStatus($box, "Motor failed ❌ " + escapeHtml(xhr.responseText || xhr.statusText));
      });
  });

  // Optional: auto-fetch Pi status when opening the metabox (small + safe)
  $(function () {
    if (!MEADOW_ADMIN_TOOLS || !MEADOW_ADMIN_TOOLS.rest || !MEADOW_ADMIN_TOOLS.rest.piStatus) return;

    const postId = parseInt(MEADOW_ADMIN_TOOLS.postId || 0, 10);
    const nonce = MEADOW_ADMIN_TOOLS.nonce;
    if (!postId) return;

    const $box = $(".meadow-admin-tools .meadow-pi-status");
    if (!$box.length) return;

    apiGet(MEADOW_ADMIN_TOOLS.rest.piStatus, nonce, { kiosk_post_id: postId })
      .done((r) => {
        if (!r || !r.ok) return;
        const pi = r.pi || {};
        // show a tiny status line
        const line =
          "Pi status: " +
          (pi.ok ? "OK" : "—") +
          (pi.pi_git ? (" | git " + escapeHtml(pi.pi_git)) : "") +
          (typeof pi.kiosk_running !== "undefined" ? (" | kiosk " + (pi.kiosk_running ? "RUNNING" : "STOPPED")) : "");
        setStatus($box, line);
      })
      .fail(() => { /* ignore */ });
  });

})(jQuery);

