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
      .typing { align-self: flex-start; color: #9ca3af; font-size: 13px; padding: 4px 6px; }
      .ft { padding: 10px; border-top: 1px solid #eee; display: flex; gap: 8px; background: #fff; }
      .ft input { flex: 1; border: 1px solid #d1d5db; border-radius: 999px; padding: 10px 14px; font-size: 14px; outline: none; }
      .ft button { background: ${color}; color: #fff; border: none; border-radius: 999px; width: 40px; cursor: pointer; font-size: 16px; }
      .ft button:disabled { opacity: .5; }
      .pw { text-align: center; font-size: 10px; color: #b0b6bf; padding: 4px; background:#fff; }
    `;
  }

  var launcher, panel, bodyEl, inputEl, sendBtn;

  function build() {
    var st = h("style"); st.textContent = styleBlock(); shadow.appendChild(st);

    launcher = h("button", { class: "launcher" },
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span>' +
      esc(cfg.branding.launcher_text || "Chat with us") + "</span>");
    launcher.addEventListener("click", toggle);
    shadow.appendChild(launcher);

    panel = h("div", { class: "panel" });
    panel.appendChild(h("div", { class: "hd" }, "<span>" + esc(cfg.agent_name) + "</span>"));
    panel.querySelector(".hd").appendChild((function () { var b = h("button", {}, "×"); b.addEventListener("click", toggle); return b; })());
    bodyEl = h("div", { class: "body" });
    panel.appendChild(bodyEl);
    var ft = h("div", { class: "ft" });
    inputEl = h("input", { type: "text", placeholder: "Type a message…" });
    inputEl.addEventListener("keydown", function (e) { if (e.key === "Enter") send(); });
    sendBtn = h("button", {}, "➤");
    sendBtn.addEventListener("click", send);
    ft.appendChild(inputEl); ft.appendChild(sendBtn);
    panel.appendChild(ft);
    panel.appendChild(h("div", { class: "pw" }, "Powered by AI"));
    shadow.appendChild(panel);
  }

  function addMsg(role, text) {
    var m = h("div", { class: "msg " + (role === "visitor" ? "v" : "a") }, esc(text));
    bodyEl.appendChild(m);
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }
  function typing(on) {
    var t = shadow.querySelector(".typing");
    if (on && !t) { bodyEl.appendChild(h("div", { class: "typing" }, "typing…")); bodyEl.scrollTop = bodyEl.scrollHeight; }
    if (!on && t) t.remove();
  }

  function toggle() {
    open = !open;
    panel.classList.toggle("show", open);
    if (open && bodyEl.childElementCount === 0) addMsg("agent", cfg.greeting);
    if (open) setTimeout(function () { inputEl.focus(); }, 100);
  }

  function send() {
    var text = (inputEl.value || "").trim();
    if (!text || sending) return;
    inputEl.value = "";
    addMsg("visitor", text);
    sending = true; sendBtn.disabled = true; typing(true);
    fetch(API + "/" + KEY + "/chat", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({
        session_token: localStorage.getItem(LS) || null,
        message: text,
        page_url: location.href
      })
    }).then(function (r) { return r.json(); }).then(function (d) {
      typing(false);
      if (d.session_token) localStorage.setItem(LS, d.session_token);
      addMsg("agent", d.reply || "Thanks!");
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
