(function () {
  "use strict";
  var KEY = @json($key);
  var API = @json($apiBase);
  var SOCKET = @json($socketBase ?? "");   // realtime socket server (empty → polling only)
  if (window.__wgt_loaded_ && window.__wgt_loaded_[KEY]) return;
  window.__wgt_loaded_ = window.__wgt_loaded_ || {};
  window.__wgt_loaded_[KEY] = true;

  var LS = "wgt_session_" + KEY;
  var cfg = { agent_name: "Assistant", greeting: "Hi! How can I help?", branding: {} };
  var open = false, booted = false, sending = false;
  var LSV = "wgt_visitor_" + KEY;          // name + mobile remembered in this browser
  var live = { ident: false, status: null, lastId: 0, timer: null, formEl: null, replyTo: null, rec: null, unread: 0, canLive: false, ai: false };
  var LSS = "wgt_seen_" + KEY;            // last staff message id the visitor has actually seen (survives page changes)
  function seenId() { try { return parseInt(localStorage.getItem(LSS), 10) || 0; } catch (e) { return 0; } }
  function markSeen() { try { localStorage.setItem(LSS, String(live.lastId)); } catch (e) {} }
  var MAX_BYTES = 2 * 1024 * 1024;
  var EMOJIS = "😀 😁 😂 🤣 😊 😍 😘 😎 🤔 😢 😭 😡 👍 👎 👏 🙏 🙌 💪 🎉 🔥 ❤️ 💯 ✅ ❌ 👋 🤝 😅 😉 🙂 😇".split(" ");

  function h(tag, attrs, html) {
    var el = document.createElement(tag);
    for (var k in attrs) el.setAttribute(k, attrs[k]);
    if (html != null) el.innerHTML = html;
    return el;
  }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
    });
  }

  var color = "#4f46e5", pos = "right", vpos = "bottom";   // vpos: "bottom" or "center"

  var root = h("div", { id: "wgt-root-" + KEY });
  var shadow = root.attachShadow ? root.attachShadow({ mode: "open" }) : root;

  var GAP_BOTTOM = 20, GAP_TOP = 20, GAP_CENTER_BOTTOM = 20, GAP_SIDE = 20;   // px defaults; override via branding.bottom_offset / branding.center_gap
  function styleBlock() {
    var center = vpos === "center";
    var launcherV = center ? "top: calc(50% - 25px);" : "bottom: " + GAP_BOTTOM + "px;";
    var panelV = center ? "top: " + GAP_TOP + "px; bottom: " + GAP_CENTER_BOTTOM + "px; margin: auto 0;" : "bottom: " + (GAP_BOTTOM + 64) + "px;";
    var panelMax = center ? "calc(100vh - " + (GAP_TOP + GAP_CENTER_BOTTOM) + "px)" : "calc(100vh - " + (GAP_BOTTOM + 100) + "px)";
    return `
      :host, * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
      .launcher { position: fixed; ${pos}: ${GAP_SIDE}px; ${launcherV} z-index: 2147483000;
        background: ${color}; color: #fff; border: none; border-radius: 999px; cursor: pointer;
        padding: 14px 18px; font-size: 15px; font-weight: 600; box-shadow: 0 8px 30px rgba(0,0,0,.22); display: flex; align-items: center; gap: 8px; }
      .launcher { position: fixed; }
      .badge { position: absolute; top: -6px; right: -4px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 12px; font-weight: 700; display: none; align-items: center; justify-content: center; border: 2px solid #fff; }
      .badge.on { display: flex; }
      .launcher.ping { animation: wping 1.2s ease-in-out 3; }
      @keyframes wping { 50% { transform: scale(1.08); } }
      .launcher svg { width: 20px; height: 20px; }
      .panel { position: fixed; ${pos}: ${GAP_SIDE}px; ${panelV} z-index: 2147483000; width: 370px; max-width: calc(100vw - 32px);
        height: 540px; max-height: ${panelMax}; background: #fff; border-radius: 16px; overflow: hidden;
        box-shadow: 0 20px 60px rgba(0,0,0,.28); display: flex; flex-direction: column; opacity: 0; transform: translateY(12px);
        transition: opacity .18s, transform .18s; pointer-events: none; }
      .panel.show { opacity: 1; transform: none; pointer-events: auto; }
      .hd { background: ${color}; color: #fff; padding: 14px 16px; font-weight: 700; font-size: 15px; display:flex; align-items:center; justify-content:space-between; }
      .hd button { background: transparent; border: none; color: #fff; font-size: 20px; cursor: pointer; line-height: 1; }
      .body { flex: 1; overflow-y: auto; padding: 14px; background: #f6f7f9; display: flex; flex-direction: column; gap: 8px; }
      .msg { max-width: 82%; padding: 9px 12px; border-radius: 14px; font-size: 14px; line-height: 1.4; white-space: pre-wrap; word-wrap: break-word; }
      .msg.a { background: #fff; color: #1f2937; align-self: flex-start; border: 1px solid #e5e7eb; }
      .msg.v { background: ${color}; color: #fff; align-self: flex-end; }
      .opts { align-self: flex-start; display: flex; flex-wrap: wrap; gap: 6px; max-width: 92%; }
      .opt { background: #fff; color: ${color}; border: 1px solid ${color}; border-radius: 999px; padding: 6px 12px; font-size: 13px; cursor: pointer; }
      .opt:hover { background: ${color}; color: #fff; }
      .ctl { align-self: flex-start; display: flex; flex-wrap: wrap; gap: 6px; max-width: 92%; margin-top: 2px; }
      .ctl button { background: transparent; color: #6b7280; border: 1px solid #d1d5db; border-radius: 999px; padding: 4px 10px; font-size: 12px; cursor: pointer; }
      .ctl button:hover { background: #e5e7eb; }
      .form { align-self: stretch; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 8px; }
      .form label { font-size: 12px; color: #6b7280; }
      .form input { border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 10px; font-size: 14px; outline: none; width: 100%; }
      .form .err { color: #dc2626; font-size: 12px; }
      .form .go { background: ${color}; color: #fff; border: none; border-radius: 8px; padding: 9px; font-size: 14px; font-weight: 600; cursor: pointer; }
      .form .lnk { background: none; border: none; color: ${color}; font-size: 12px; cursor: pointer; text-align: left; padding: 0; }
      .msg.s { background: #eef2ff; color: #3730a3; align-self: flex-start; border: 1px solid #c7d2fe; }
      .msg.sys { background: transparent; color: #6b7280; align-self: center; font-size: 12px; text-align: center; border: none; }
      .tools { display: none; gap: 2px; }
      .tools.on { display: flex; }
      .tools button { background: transparent; border: none; cursor: pointer; font-size: 18px; width: 30px; padding: 0; color: #6b7280; }
      .tools button.rec { color: #dc2626; }
      .quote { border-left: 3px solid ${color}; background: rgba(0,0,0,.05); padding: 3px 8px; margin-bottom: 4px; font-size: 12px; border-radius: 4px; color: #4b5563; }
      .msg img.att { max-width: 100%; border-radius: 8px; display: block; }
      .msg audio { max-width: 100%; height: 36px; }
      .msg a.file { color: inherit; font-weight: 600; }
      .rp { background: none; border: none; color: #9ca3af; font-size: 11px; cursor: pointer; padding: 0 0 0 6px; }
      .rbar { display: none; align-items: center; gap: 8px; background: #f3f4f6; padding: 6px 12px; font-size: 12px; color: #4b5563; border-top: 1px solid #eee; }
      .rbar.on { display: flex; }
      .rbar span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      .rbar button { background: none; border: none; cursor: pointer; font-size: 16px; }
      .emo { display: none; position: absolute; bottom: 58px; left: 8px; right: 8px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 6px; flex-wrap: wrap; gap: 2px; box-shadow: 0 8px 24px rgba(0,0,0,.15); z-index: 2; }
      .emo.on { display: flex; }
      .emo button { background: none; border: none; font-size: 20px; cursor: pointer; width: 34px; height: 34px; }
      .hd .acts { display: flex; gap: 4px; }
      .hd .acts button { font-size: 16px; padding: 2px 6px; }
      .typing { align-self: flex-start; color: #9ca3af; font-size: 13px; padding: 4px 6px; }
      .ft { padding: 10px; border-top: 1px solid #eee; display: flex; gap: 8px; background: #fff; }
      .ft input { flex: 1; border: 1px solid #d1d5db; border-radius: 999px; padding: 10px 14px; font-size: 14px; outline: none; }
      .ft button { color: #3bc987; border: 1px solid #3bc987; border-radius: 999px; width: 40px; cursor: pointer; font-size: 16px; }
      .ft button:disabled { opacity: .5; }
      .pw a { color: inherit; font-weight: 600; text-decoration: none; }
      .pw a:hover { text-decoration: underline; }
      .pw { text-align: center; font-size: 10px; color: #b0b6bf; padding: 4px; background:#fff; }
    `;
  }

  var badgeEl, launcher, panel, bodyEl, inputEl, sendBtn, rbarEl, emoEl, toolsEl, fileEl;

  function build() {
    var st = h("style"); st.textContent = styleBlock(); shadow.appendChild(st);

    launcher = h("button", { class: "launcher" },
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>' +
      esc(cfg.branding.launcher_text || "Chat with us") + "</span>");
    badgeEl = h("span", { class: "badge" }); launcher.appendChild(badgeEl);
    launcher.addEventListener("click", toggle);
    shadow.appendChild(launcher);

    panel = h("div", { class: "panel" }); panel.style.position = "fixed";
    panel.appendChild(h("div", { class: "hd" }, "<span>" + esc(cfg.agent_name) + "</span>"));
    var acts = h("div", { class: "acts" });
    var rb = h("button", { title: "Reset conversation", "aria-label": "Reset conversation" }, "&#8635;");
    rb.addEventListener("click", resetChat);
    var xb = h("button", { title: "Close", "aria-label": "Close" }, "×");
    xb.addEventListener("click", toggle);
    acts.appendChild(rb); acts.appendChild(xb);
    panel.querySelector(".hd").appendChild(acts);
    bodyEl = h("div", { class: "body" });
    panel.appendChild(bodyEl);
    rbarEl = h("div", { class: "rbar" }, "<span></span>");
    var rx = h("button", { title: "Cancel reply" }, "×"); rx.addEventListener("click", function () { setReply(null); });
    rbarEl.appendChild(rx);
    panel.appendChild(rbarEl);
    emoEl = h("div", { class: "emo" });
    EMOJIS.forEach(function (e) {
      var b = h("button", {}, e);
      b.addEventListener("click", function () { inputEl.value += e; inputEl.focus(); });
      emoEl.appendChild(b);
    });
    panel.appendChild(emoEl);
    var ft = h("div", { class: "ft" });
    toolsEl = h("div", { class: "tools" });
    var eb = h("button", { title: "Emoji" }, "😊"); eb.addEventListener("click", function () { emoEl.classList.toggle("on"); });
    var ab = h("button", { title: "Attach photo or file (max 2 MB)" }, "📎");
    fileEl = h("input", { type: "file", accept: "image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.zip", style: "display:none" });
    ab.addEventListener("click", function () { fileEl.click(); });
    fileEl.addEventListener("change", function () { if (fileEl.files[0]) uploadFile(fileEl.files[0]); fileEl.value = ""; });
    toolsEl.appendChild(eb); toolsEl.appendChild(ab); toolsEl.appendChild(fileEl);
    ft.appendChild(toolsEl);
    inputEl = h("input", { type: "text", placeholder: "Type a message…" });
    inputEl.addEventListener("keydown", function (e) { if (e.key === "Enter") send(); });
    sendBtn = h("button", {}, "➤");
    sendBtn.addEventListener("click", function () { send(); });
    ft.appendChild(inputEl); ft.appendChild(sendBtn);
    panel.appendChild(ft);
    panel.appendChild(h("div", { class: "pw" }, 'Powered by <a href="https://teamzo.io" target="_blank" rel="noopener">TeamZo</a>'));
    shadow.appendChild(panel);
  }

  function addMsg(role, text, cls) {
    var m = h("div", { class: "msg " + (cls || (role === "visitor" ? "v" : "a")) }, esc(text));
    bodyEl.appendChild(m);
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }
  // Rich message (quote + photo / voice / file + text) from the live chat.
  function addRich(m, cls) {
    var el = h("div", { class: "msg " + cls });
    if (m.reply_to) el.appendChild(h("div", { class: "quote" }, esc(m.reply_to.text)));
    if (m.type === "image" && m.url) el.appendChild(h("img", { class: "att", src: m.url, alt: "photo" }));
    else if (m.type === "audio" && m.url) { var a = h("audio", { controls: "controls", src: m.url }); el.appendChild(a); }
    else if (m.type === "file" && m.url) el.appendChild(h("a", { class: "file", href: m.url, target: "_blank", rel: "noopener" }, "📎 " + esc(m.file_name || "File")));
    if (m.text) el.appendChild(h("div", {}, esc(m.text)));
    if (m.id && cls === "s") {
      var r = h("button", { class: "rp", title: "Reply" }, "↩ Reply");
      r.addEventListener("click", function () { setReply({ id: m.id, text: m.text || (m.type === "image" ? "📷 Photo" : m.type === "audio" ? "🎤 Voice message" : "📎 File") }); });
      el.appendChild(r);
    }
    bodyEl.appendChild(el); bodyEl.scrollTop = bodyEl.scrollHeight;
  }
  function setReply(r) {
    live.replyTo = r;
    rbarEl.classList.toggle("on", !!r);
    if (r) { rbarEl.firstChild.textContent = "↩ " + r.text; inputEl.focus(); }
  }
  var AI_CTL = [{ action: "yes", label: "✅ Issue solved" }, { action: "no", label: "❌ Not solved" }];
  function setBadge() {
    badgeEl.textContent = live.unread > 99 ? "99+" : String(live.unread);
    badgeEl.classList.toggle("on", live.unread > 0 && !open);
  }
  function liveOn() { return ["waiting", "live", "missed"].indexOf(live.status) >= 0; }
  function syncTools() { toolsEl.classList.add("on"); if (!liveOn()) { setReply(null); emoEl.classList.remove("on"); } }

  function uploadFile(file, caption) {
    if (!liveOn() && !live.ident) { addMsg("agent", "Please tap Start and share your name and mobile number first.", "sys"); return; }
    if (file.size > MAX_BYTES) { addMsg("agent", "That file is too large — the limit is 2 MB.", "sys"); return; }
    var fd = new FormData();
    fd.append("session_token", localStorage.getItem(LS) || "");
    if (live.name) { fd.append("name", live.name); fd.append("phone", live.phone || ""); }
    fd.append("page_url", location.href);
    fd.append("file", file, file.name || "voice.webm");
    if (caption) fd.append("text", caption);
    if (live.replyTo) fd.append("reply_to_id", live.replyTo.id);
    var echo = { type: file.type.indexOf("image") === 0 ? "image" : file.type.indexOf("audio") === 0 || file.type.indexOf("video/webm") === 0 ? "audio" : "file",
      url: URL.createObjectURL(file), file_name: file.name, text: "", reply_to: live.replyTo ? { text: live.replyTo.text } : null };
    setReply(null);
    fetch(API + "/" + KEY + "/live/upload", { method: "POST", headers: { "Accept": "application/json" }, body: fd })
      .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw d; return d; }); })
      .then(function (d) {
        if (d.session_token) localStorage.setItem(LS, d.session_token);
        addRich(echo, "v");
        if (!liveOn()) addMsg("agent", "Thanks — we've received your file. Our team can view it.", "sys");
      })
      .catch(function (e) { addMsg("agent", (e && e.message) || "Could not send the file.", "sys"); });
  }
  function clearOpts() { var o = shadow.querySelectorAll(".opts"); for (var i = 0; i < o.length; i++) o[i].remove(); }
  function addOpts(list) {
    if (!list || !list.length) return;
    var wrap = h("div", { class: "opts" });
    list.forEach(function (label) {
      var b = h("button", { class: "opt" }, esc(label));
      b.addEventListener("click", function () { send(label); });
      wrap.appendChild(b);
    });
    bodyEl.appendChild(wrap);
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }
  function clearCtl() { var o = shadow.querySelectorAll(".ctl"); for (var i = 0; i < o.length; i++) o[i].remove(); }
  function addControls(list) {
    if (!list || !list.length) return;
    var wrap = h("div", { class: "ctl" });
    list.forEach(function (c) {
      var b = h("button", {}, esc(c.label));
      b.addEventListener("click", function () {
        if (c.action === "reset") resetChat();
        else if (c.action === "live") startLive();
        else if (c.action === "editlive") { addMsg("agent", "Update your details:"); showForm({ cta: "Save", submit: function (n, p, err) {
          post("live/start", { session_token: localStorage.getItem(LS), name: n, phone: p, page_url: location.href })
            .then(function (d) { removeForm(); addMsg("agent", "Details updated."); applyLive(d); })
            .catch(function (e) { err.textContent = (e && e.message) || "Could not save."; }); } }); }
        else send(null, c.action, c.label);
      });
      wrap.appendChild(b);
    });
    bodyEl.appendChild(wrap);
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }
  function greet() {
    addMsg("agent", cfg.greeting);
    addOpts(["Start"]);   // "Chat with a person" only appears after the bot has replied (see send)
  }

  // ── Live chat with staff ──────────────────────────────────────────────────
  function savedVisitor() { try { return JSON.parse(localStorage.getItem(LSV)) || {}; } catch (e) { return {}; } }
  function saveVisitor(v) { try { localStorage.setItem(LSV, JSON.stringify(v)); } catch (e) {} }
  function post(path, body) {
    return fetch(API + "/" + KEY + "/" + path, {
      method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json" }, body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (d) { if (!r.ok) throw d; return d; }); });
  }
  function removeForm() { if (live.formEl) { live.formEl.remove(); live.formEl = null; } }

  // Name + mobile form. Pre-filled from this browser so a returning visitor only checks / edits, then confirms.
  function showForm(opts) {
    removeForm(); clearOpts(); clearCtl();
    var v = savedVisitor();
    var f = h("div", { class: "form" },
      '<label>Your name</label><input class="n" type="text" maxlength="80" value="' + esc(opts.name || v.name || "") + '">' +
      '<label>Mobile number</label><input class="p" type="tel" maxlength="20" value="' + esc(opts.phone || v.phone || "") + '">' +
      '<div class="err"></div><button class="go">' + esc(opts.cta) + "</button>");
    f.querySelector(".go").addEventListener("click", function () {
      var name = f.querySelector(".n").value.trim(), phone = f.querySelector(".p").value.trim();
      var err = f.querySelector(".err");
      if (name.length < 2) { err.textContent = "Please enter your name."; return; }
      if (!/^\+?[0-9 ()-]{7,20}$/.test(phone)) { err.textContent = "Please enter a valid mobile number."; return; }
      err.textContent = "";
      saveVisitor({ name: name, phone: phone });
      opts.submit(name, phone, err);
    });
    live.formEl = f; bodyEl.appendChild(f); bodyEl.scrollTop = bodyEl.scrollHeight;
  }

  var PHONE_RE = /^\+?[0-9 ()-]{7,20}$/;
  function requestLive(name, phone, onError) {
    post("live/start", { session_token: localStorage.getItem(LS) || null, name: name, phone: phone, page_url: location.href })
      .then(function (d) {
        removeForm();
        if (d.session_token) localStorage.setItem(LS, d.session_token);
        addMsg("agent", d.repeat ? "We already have your request, " + name + ". Our staff will connect with you shortly — no need to ask again."
          : "Thanks " + name + "! Waiting for your assistance — a staff member will join shortly.");
        addControls([{ action: "editlive", label: "✏️ Edit my details" }]);
        applyLive(d); startPolling();
      })
      .catch(function (e) { onError((e && (e.message || (e.errors && Object.values(e.errors)[0][0]))) || "Could not start chat."); });
  }

  // Details already collected in this browser: show them and let the visitor continue or edit. Otherwise ask.
  function startLive() {
    if (live.ident && live.name && live.phone) { clearOpts(); clearCtl(); requestLive(live.name, live.phone, function (m) { addMsg("agent", m); }); return; }
    var v = savedVisitor();
    if (v.name && v.name.length >= 2 && PHONE_RE.test(v.phone || "")) {
      clearOpts(); clearCtl(); removeForm();
      addMsg("agent", "Continue with these details?");
      var c = h("div", { class: "form" },
        '<div><b>' + esc(v.name) + '</b><br>' + esc(v.phone) + '</div><div class="err"></div>' +
        '<button class="go">Continue</button><button class="lnk">✏️ Edit details</button>');
      var err = c.querySelector(".err");
      c.querySelector(".go").addEventListener("click", function () {
        requestLive(v.name, v.phone, function (m) { err.textContent = m; });
      });
      c.querySelector(".lnk").addEventListener("click", function () { removeForm(); showEditForm(); });
      live.formEl = c; bodyEl.appendChild(c); bodyEl.scrollTop = bodyEl.scrollHeight;
      return;
    }
    addMsg("agent", "Please enter your details so our team can reach you.");
    showEditForm();
  }
  function showEditForm() {
    showForm({
      cta: "Start chat",
      submit: function (name, phone, err) { requestLive(name, phone, function (m) { err.textContent = m; }); }
    });
  }

  function askConfirm(name, phone) {
    addMsg("agent", "Our staff will connect with you. Please check and confirm your name and mobile number:");
    showForm({
      name: name, phone: phone, cta: "Confirm",
      submit: function (n, p, err) {
        post("live/confirm", { session_token: localStorage.getItem(LS), name: n, phone: p, after: live.lastId })
          .then(function (d) { removeForm(); applyLive(d); })
          .catch(function (e) { err.textContent = (e && e.message) || "Could not save. Try again."; });
      }
    });
  }

  function applyLive(d) {
    (d.messages || []).forEach(function (m) {
      if (m.id > live.lastId) live.lastId = m.id;
      if (m.role === "system") { var el = h("div", { class: "msg sys" }, esc(m.text)); bodyEl.appendChild(el); }
      else { addRich(m, "s"); if (!open && m.id > seenId()) { live.unread++; launcher.classList.add("ping"); setTimeout(function () { launcher.classList.remove("ping"); }, 4000); } }
    });
    setBadge(); if (open) markSeen();
    bodyEl.scrollTop = bodyEl.scrollHeight;
    if (d.closed) {   // final message shown (staff will contact the visitor) → end this chat in the browser
      stopPolling(); try { localStorage.removeItem(LS); } catch (e) {} clearCtl(); live.status = null; live.ai = false; syncTools();
      addOpts(["Start new chat"]); return;
    }
    var prev = live.status; live.status = d.live_status; live.ai = !!d.ai_active; syncTools();
    if (d.ai_active && prev !== "missed") { clearCtl(); addControls(AI_CTL); }
    if (d.live_status === "missed" && prev !== "missed" && !d.callback_confirmed) {
      var sv = savedVisitor(), nm = d.name || sv.name, ph = d.phone || sv.phone;
      if (nm && PHONE_RE.test(ph || "")) {
        // Details already given at the start — no form, just tell them and register the callback.
        addMsg("agent", "Our staff are not available right now. They will connect with you soon. 🙏");
        post("live/confirm", { session_token: localStorage.getItem(LS), name: nm, phone: ph, after: live.lastId }).then(applyLive).catch(function () {});
      } else askConfirm(d.name, d.phone);
    }
    if (d.live_status === "ended") { stopPolling(); try { localStorage.removeItem(LS); } catch (e) {} clearCtl(); addOpts(["Start new chat"]); live.status = null; syncTools(); }
    if (d.live_status === "live" && prev !== "live") clearCtl();
  }
  // Adaptive polling (keeps server load low): only while a live request/chat exists; fast when the panel is open
  // and the tab visible, slow when the panel is closed, very slow in a background tab or once we only owe a callback.
  function pollDelay() {
    if (rt.up) return 60000;   // realtime socket is connected → it pings us on changes; this is only a safety net
    if (document.hidden) return 30000;
    if (live.status === "missed") return open && live.ai ? 8000 : 30000;   // AI is answering; just watch for a staff member joining
    // open panel: 3s right after activity, easing to 8s once the chat has been quiet (idle resets on any new message)
    var base = open ? Math.min(8000, 3000 + (live.idle || 0) * 500) : 10000;
    // back off hard on errors / 429 / server trouble so a struggling server isn't hammered by every open widget
    return live.fail ? Math.min(60000, base * Math.pow(2, live.fail)) : base;
  }
  function poll() {
    var t = localStorage.getItem(LS); if (!t || !liveOn()) return stopPolling();
    var before = live.lastId;
    fetch(API + "/" + KEY + "/live/poll?session_token=" + encodeURIComponent(t) + "&after=" + live.lastId, { headers: { "Accept": "application/json" } })
      .then(function (r) { if (!r.ok) throw 0; return r.json(); })
      .then(function (d) { live.fail = 0; applyLive(d); live.idle = live.lastId !== before ? 0 : (live.idle || 0) + 1; })
      .catch(function () { live.fail = Math.min((live.fail || 0) + 1, 4); })
      .then(function () { if (live.timer) schedulePoll(); });
  }
  function schedulePoll() { clearTimeout(live.timer); live.timer = setTimeout(poll, pollDelay()); }
  function startPolling() { stopPolling(); schedulePoll(); connectRt(); }
  function stopPolling() { if (live.timer) { clearTimeout(live.timer); live.timer = null; } closeRt(); }

  // Realtime: the server pings this visitor's own conversation room when staff/AI reply or the status changes;
  // we then run the normal /live/poll once. Falls back to adaptive polling whenever the socket is down.
  var rt = { sock: null, up: false, token: null, loading: false, debounce: null };
  function connectRt() {
    var t = localStorage.getItem(LS);
    if (!SOCKET || !t) return;
    if (rt.sock && rt.token === t) return;
    closeRt();
    if (typeof window.io !== "function") {
      if (rt.loading) return;
      rt.loading = true;
      var sc = document.createElement("script");
      sc.src = SOCKET + "/socket.io/socket.io.js"; sc.async = true;
      sc.onload = function () { rt.loading = false; if (live.timer) connectRt(); };
      sc.onerror = function () { rt.loading = false; };
      document.head.appendChild(sc);
      return;
    }
    rt.token = t;
    rt.sock = window.io(SOCKET + "/widget", { transports: ["websocket"], auth: { key: KEY, session_token: t }, reconnectionDelayMax: 30000 });
    rt.sock.on("connect", function () { rt.up = true; if (live.timer) { clearTimeout(live.timer); poll(); } });   // resync after (re)connect
    rt.sock.on("disconnect", function () { rt.up = false; if (live.timer) schedulePoll(); });
    rt.sock.on("connect_error", function () { rt.up = false; });
    rt.sock.on("widget_update", function () {
      clearTimeout(rt.debounce);
      rt.debounce = setTimeout(function () { if (live.timer) { clearTimeout(live.timer); poll(); } }, 100);
    });
  }
  function closeRt() {
    if (rt.sock) { try { rt.sock.disconnect(); } catch (e) {} }
    rt.sock = null; rt.up = false; rt.token = null;
  }
  document.addEventListener("visibilitychange", function () { if (!document.hidden && live.timer) { clearTimeout(live.timer); poll(); } });

  // After a refresh: redraw this browser's whole conversation (bot + staff) and reconnect to a live chat if there is one.
  function resumeLive() {
    var token = localStorage.getItem(LS);
    if (!token) return;
    fetch(API + "/" + KEY + "/history?session_token=" + encodeURIComponent(token), { headers: { "Accept": "application/json" } })
      .then(function (r) { if (!r.ok) throw 0; return r.json(); })
      .then(function (d) {
        if (!d.active) { try { localStorage.removeItem(LS); } catch (e) {} return; }   // closed / gone → fresh start
        bodyEl.innerHTML = ""; addMsg("agent", cfg.greeting);
        var seen = seenId();
        d.messages.forEach(function (m) {
          if (m.id > live.lastId) live.lastId = m.id;
          if (m.role === "staff" && m.id > seen && !open) live.unread++;
          if (m.role === "system") bodyEl.appendChild(h("div", { class: "msg sys" }, esc(m.text)));
          else if (m.role === "staff") addRich(m, "s");
          else if (m.role === "visitor") { if (m.type && m.type !== "text") addRich(m, "v"); else addMsg("visitor", m.text); }
          else addMsg("agent", m.text);
        });
        if (d.name && d.phone) { live.ident = true; live.name = d.name; live.phone = d.phone; }
        live.status = d.live_status; live.ai = !!d.ai_active; live.canLive = !!d.can_request_live; syncTools(); setBadge(); if (open) markSeen();
        if (liveOn()) {
          startPolling();
          if (d.live_status === "waiting") addMsg("agent", "Waiting for your assistance — a staff member will join shortly.");
          else if (d.live_status === "missed" && !d.callback_confirmed) askConfirm(d.name, d.phone);
          if (d.ai_active) addControls(AI_CTL);
        } else {
          var ctl = [{ action: "menu", label: "🏠 Main menu" }, { action: "reset", label: "🔄 Reset" }];
          if (cfg.live_chat && live.canLive) ctl = [{ action: "live", label: "💬 Chat with a person" }];   // only after "issue not solved"
          addControls(ctl);
        }
        bodyEl.scrollTop = bodyEl.scrollHeight;
      }).catch(function () {});
  }
  function resetChat() {
    if (sending) return;
    var token = localStorage.getItem(LS);
    try { localStorage.removeItem(LS); } catch (e) {}
    stopPolling(); live.status = null; live.lastId = 0; live.formEl = null; live.ident = false; syncTools();
    bodyEl.innerHTML = "";
    greet();
    if (token) {
      fetch(API + "/" + KEY + "/chat", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify({ session_token: token, action: "reset" })
      }).catch(function () {});
    }
  }
  function typing(on) {
    var t = shadow.querySelector(".typing");
    if (on && !t) { bodyEl.appendChild(h("div", { class: "typing" }, "typing…")); bodyEl.scrollTop = bodyEl.scrollHeight; }
    if (!on && t) t.remove();
  }

  function toggle() {
    open = !open;
    panel.classList.toggle("show", open);
    if (open && bodyEl.childElementCount === 0) greet();
    if (open) { live.unread = 0; markSeen(); if (live.timer) schedulePoll(); }
    setBadge();
    if (open) setTimeout(function () { inputEl.focus(); }, 100);
  }

  // preset: option label tapped; action: back | menu (control chips); label: what to echo for an action
  function send(preset, action, label) {
    var text = (typeof preset === "string" ? preset : (inputEl.value || "")).trim();
    if (action) text = "";
    if ((!text && !action) || sending) return;
    // Ask for name + mobile before the conversation starts (prefilled from this browser; confirm or edit).
    if (!action && !live.ident && !liveOn()) {
      addMsg("agent", "Before we start, please share your name and mobile number.");
      showForm({ cta: "Start", submit: function (n, p) {
        live.ident = true; live.name = n; live.phone = p; removeForm(); send(typeof preset === "string" ? preset : null);
      } });
      return;
    }
    inputEl.value = "";
    clearOpts(); clearCtl();
    if (liveOn() && !action && live.replyTo) { addRich({ text: text, reply_to: { text: live.replyTo.text } }, "v"); }
    else addMsg("visitor", action ? label : text);
    sending = true; sendBtn.disabled = true; typing(true);
    fetch(API + "/" + KEY + "/chat", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({
        session_token: localStorage.getItem(LS) || null,
        message: text || null,
        action: action || null,
        reply_to_id: liveOn() && live.replyTo ? live.replyTo.id : null,
        name: live.name || null, phone: live.phone || null,
        page_url: location.href
      })
    }).then(function (r) { return r.json(); }).then(function (d) {
      typing(false);
      if (d.session_token) localStorage.setItem(LS, d.session_token);
      if (d.live_status) { setReply(null); applyLive({ live_status: d.live_status, messages: [] }); return; }
      addMsg("agent", d.reply || "Thanks!");
      addOpts(d.options);
      if (d.start_live && cfg.live_chat) { requestLive(live.name || savedVisitor().name, live.phone || savedVisitor().phone, function (m) { addMsg("agent", m); }); }
      else if (d.closed) {
        if (live.status) { stopPolling(); try { localStorage.removeItem(LS); } catch (e) {} live.status = null; live.ai = false; syncTools(); }
        addOpts(["Start new chat"]);
      }
      else {
        // "Chat with a person" arrives from the server only after the visitor says the issue was not solved.
        addControls(d.controls || []);
      }
    }).catch(function () {
      typing(false);
      addMsg("agent", "Sorry, something went wrong. Please try again.");
    }).finally(function () {
      sending = false; sendBtn.disabled = false;
    });
  }

  fetch(API + "/" + KEY + "/bootstrap", { headers: { "Accept": "application/json" } })
    .then(function (r) { if (!r.ok) throw 0; return r.json(); })
    .then(function (d) {
      cfg = d || cfg;
      cfg.branding = cfg.branding || {};
      if (cfg.branding.primary_color) color = cfg.branding.primary_color;
      var bp = String(cfg.branding.position || "");   // right | left | right-center | left-center
      if (bp.indexOf("left") === 0) pos = "left";
      if (bp.indexOf("center") > -1) vpos = "center";
      var bo = parseInt(cfg.branding.bottom_offset, 10), cg = parseInt(cfg.branding.center_gap, 10);
      if (!isNaN(bo) && bo >= 0) GAP_BOTTOM = bo;
      var ct = parseInt(cfg.branding.center_top, 10), cb = parseInt(cfg.branding.center_bottom, 10), so = parseInt(cfg.branding.side_offset, 10);
      if (!isNaN(cg) && cg >= 0) { GAP_TOP = cg; GAP_CENTER_BOTTOM = cg; }
      if (!isNaN(ct) && ct >= 0) GAP_TOP = ct;
      if (!isNaN(cb) && cb >= 0) GAP_CENTER_BOTTOM = cb;
      if (!isNaN(so) && so >= 0) GAP_SIDE = so;
      build();
      booted = true;
      if (localStorage.getItem(LS)) resumeLive();   // reconnect on every page, panel closed or not
    })
    .catch(function () { /* widget inactive or domain not allowed — render nothing */ });

  document.addEventListener("DOMContentLoaded", function () { document.body.appendChild(root); });
  if (document.readyState !== "loading") document.body.appendChild(root);
})();
