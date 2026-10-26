(function () {
  "use strict";
  var KEY = @json($key);
  var API = @json($apiBase);
  if (window.__wgt_loaded_ && window.__wgt_loaded_[KEY]) return;
  window.__wgt_loaded_ = window.__wgt_loaded_ || {};
  window.__wgt_loaded_[KEY] = true;

  var LS = "wgt_session_" + KEY;
  var cfg = { agent_name: "Assistant", greeting: "Hi! How can I help?", branding: {} };
  var open = false, booted = false, sending = false;
  var LSV = "wgt_visitor_" + KEY;          // name + mobile remembered in this browser
  var live = { status: null, lastId: 0, timer: null, formEl: null, replyTo: null, rec: null };
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

  var color = "#4f46e5", pos = "right";

  var root = h("div", { id: "wgt-root-" + KEY });
  var shadow = root.attachShadow ? root.attachShadow({ mode: "open" }) : root;

  function styleBlock() {
    return `
      :host, * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
      .launcher { position: fixed; ${pos}: 20px; bottom: 20px; z-index: 2147483000;
        background: ${color}; color: #fff; border: none; border-radius: 999px; cursor: pointer;
        padding: 14px 18px; font-size: 15px; font-weight: 600; box-shadow: 0 8px 30px rgba(0,0,0,.22); display: flex; align-items: center; gap: 8px; }
      .launcher svg { width: 20px; height: 20px; }
      .panel { position: fixed; ${pos}: 20px; bottom: 84px; z-index: 2147483000; width: 370px; max-width: calc(100vw - 32px);
        height: 540px; max-height: calc(100vh - 120px); background: #fff; border-radius: 16px; overflow: hidden;
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
      .ft button { background: ${color}; color: #fff; border: none; border-radius: 999px; width: 40px; cursor: pointer; font-size: 16px; }
      .ft button:disabled { opacity: .5; }
      .pw a { color: inherit; font-weight: 600; text-decoration: none; }
      .pw a:hover { text-decoration: underline; }
      .pw { text-align: center; font-size: 10px; color: #b0b6bf; padding: 4px; background:#fff; }
    `;
  }

  var launcher, panel, bodyEl, inputEl, sendBtn, rbarEl, emoEl, toolsEl, fileEl;

  function build() {
    var st = h("style"); st.textContent = styleBlock(); shadow.appendChild(st);

    launcher = h("button", { class: "launcher" },
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>' +
      esc(cfg.branding.launcher_text || "Chat with us") + "</span>");
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
  function liveOn() { return ["waiting", "live", "missed"].indexOf(live.status) >= 0; }
  function syncTools() { toolsEl.classList.toggle("on", liveOn()); if (!liveOn()) { setReply(null); emoEl.classList.remove("on"); } }

  function uploadFile(file, caption) {
    if (!liveOn()) return;
    if (file.size > MAX_BYTES) { addMsg("agent", "That file is too large — the limit is 2 MB.", "sys"); return; }
    var fd = new FormData();
    fd.append("session_token", localStorage.getItem(LS) || "");
    fd.append("file", file, file.name || "voice.webm");
    if (caption) fd.append("text", caption);
    if (live.replyTo) fd.append("reply_to_id", live.replyTo.id);
    var echo = { type: file.type.indexOf("image") === 0 ? "image" : file.type.indexOf("audio") === 0 || file.type.indexOf("video/webm") === 0 ? "audio" : "file",
      url: URL.createObjectURL(file), file_name: file.name, text: "", reply_to: live.replyTo ? { text: live.replyTo.text } : null };
    setReply(null);
    fetch(API + "/" + KEY + "/live/upload", { method: "POST", headers: { "Accept": "application/json" }, body: fd })
      .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw d; return d; }); })
      .then(function () { addRich(echo, "v"); })
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
    if (cfg.has_flow) addOpts(["Start"]);
    if (cfg.live_chat) addControls([{ action: "live", label: "💬 Chat with a person" }]);
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

  function startLive() {
    addMsg("agent", "Please confirm your details so our team can reach you.");
    showForm({
      cta: "Start chat",
      submit: function (name, phone, err) {
        post("live/start", { session_token: localStorage.getItem(LS) || null, name: name, phone: phone, page_url: location.href })
          .then(function (d) {
            removeForm();
            if (d.session_token) localStorage.setItem(LS, d.session_token);
            addMsg("agent", "Thanks " + name + "! Waiting for your assistance — a staff member will join shortly.");
            addControls([{ action: "editlive", label: "✏️ Edit my details" }]);
            applyLive(d); startPolling();
          })
          .catch(function (e) { err.textContent = (e && (e.message || (e.errors && Object.values(e.errors)[0][0]))) || "Could not start chat."; });
      }
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
      else addRich(m, "s");
    });
    bodyEl.scrollTop = bodyEl.scrollHeight;
    var prev = live.status; live.status = d.live_status; syncTools();
    if (d.live_status === "missed" && prev !== "missed" && !d.callback_confirmed) askConfirm(d.name, d.phone);
    if (d.live_status === "ended") { stopPolling(); try { localStorage.removeItem(LS); } catch (e) {} clearCtl(); addOpts(["Start new chat"]); live.status = null; syncTools(); }
    if (d.live_status === "live" && prev !== "live") clearCtl();
  }
  function poll() {
    var t = localStorage.getItem(LS); if (!t) return stopPolling();
    fetch(API + "/" + KEY + "/live/poll?session_token=" + encodeURIComponent(t) + "&after=" + live.lastId, { headers: { "Accept": "application/json" } })
      .then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(applyLive).catch(function () {});
  }
  function startPolling() { stopPolling(); live.timer = setInterval(poll, 3000); }
  function stopPolling() { if (live.timer) { clearInterval(live.timer); live.timer = null; } }
  function resumeLive() {
    if (!cfg.live_chat || !localStorage.getItem(LS)) return;
    fetch(API + "/" + KEY + "/live/poll?session_token=" + encodeURIComponent(localStorage.getItem(LS)) + "&after=0", { headers: { "Accept": "application/json" } })
      .then(function (r) { if (!r.ok) throw 0; return r.json(); })
      .then(function (d) {
        if (["waiting", "live", "missed"].indexOf(d.live_status) < 0) return;
        bodyEl.innerHTML = ""; addMsg("agent", cfg.greeting);
        applyLive(d); startPolling();
        if (d.live_status === "waiting") addMsg("agent", "Waiting for your assistance — a staff member will join shortly.");
      }).catch(function () {});
  }
  function resetChat() {
    if (sending) return;
    var token = localStorage.getItem(LS);
    try { localStorage.removeItem(LS); } catch (e) {}
    stopPolling(); live.status = null; live.lastId = 0; live.formEl = null; syncTools();
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
    if (open && bodyEl.childElementCount === 0) {
      greet(); resumeLive();
    }
    if (open) setTimeout(function () { inputEl.focus(); }, 100);
  }

  // preset: option label tapped; action: back | menu (control chips); label: what to echo for an action
  function send(preset, action, label) {
    var text = (typeof preset === "string" ? preset : (inputEl.value || "")).trim();
    if (action) text = "";
    if ((!text && !action) || sending) return;
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
        page_url: location.href
      })
    }).then(function (r) { return r.json(); }).then(function (d) {
      typing(false);
      if (d.session_token) localStorage.setItem(LS, d.session_token);
      if (d.live_status) { setReply(null); applyLive({ live_status: d.live_status, messages: [] }); return; }
      addMsg("agent", d.reply || "Thanks!");
      addOpts(d.options);
      if (d.closed) { addOpts(["Start new chat"]); }
      else addControls(d.controls);
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
      if (cfg.branding.position === "left") pos = "left";
      build();
      booted = true;
    })
    .catch(function () { /* widget inactive or domain not allowed — render nothing */ });

  document.addEventListener("DOMContentLoaded", function () { document.body.appendChild(root); });
  if (document.readyState !== "loading") document.body.appendChild(root);
})();
