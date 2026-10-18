<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Laravel Logs</title>
<style>
 body{font-family:system-ui,sans-serif;background:#f3f4f6;margin:0;color:#111827}
 header{position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:10px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;z-index:1}
 header h1{font-size:16px;margin:0 8px 0 0}
 input,select,button{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;background:#fff}
 button.clear{background:#dc2626;color:#fff;border-color:#dc2626;margin-left:auto}
 #list{padding:16px;display:grid;gap:10px;max-width:1100px;margin:auto}
 .box{background:#fff;border:1px solid #e5e7eb;border-left:4px solid #9ca3af;border-radius:8px;padding:10px 12px}
 .box .meta{display:flex;gap:8px;align-items:center;font-size:12px;color:#6b7280;margin-bottom:6px}
 .lvl{font-weight:700;font-size:11px;padding:1px 6px;border-radius:4px;background:#e5e7eb;color:#374151}
 .ERROR,.CRITICAL,.ALERT,.EMERGENCY{border-left-color:#dc2626}.ERROR .lvl,.CRITICAL .lvl{background:#fee2e2;color:#b91c1c}
 .WARNING{border-left-color:#d97706}.WARNING .lvl{background:#fef3c7;color:#b45309}
 .INFO{border-left-color:#2563eb}.INFO .lvl{background:#dbeafe;color:#1d4ed8}
 pre{margin:0;white-space:pre-wrap;word-break:break-word;font-size:12px;max-height:220px;overflow:auto}
 .empty{text-align:center;color:#6b7280;padding:40px}
</style></head><body>
<header>
 <h1>Laravel Logs</h1>
 <input id="q" placeholder="Search (e.g. campaign-retry)">
 <select id="level"><option value="">All levels</option><option>error</option><option>warning</option><option>info</option><option>debug</option></select>
 <label style="font-size:13px"><input type="checkbox" id="auto" checked> Auto-refresh 5s</label>
 <span id="info" style="font-size:12px;color:#6b7280"></span>
 <button class="clear" id="clear">Clear logs</button>
</header>
<div id="list"></div>
<script>
const KEY=@json($key), H={'X-Log-Key':KEY,'Accept':'application/json'};
const $=id=>document.getElementById(id), esc=s=>s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
async function load(){
  const u=new URL('/backend/logs/entries',location.origin);
  u.searchParams.set('q',$('q').value);u.searchParams.set('level',$('level').value);
  const r=await fetch(u,{headers:H}); if(!r.ok){$('list').innerHTML='<div class="empty">Not authorised.</div>';return;}
  const d=await r.json();
  $('info').textContent=d.entries.length+' entries · '+(d.size/1024).toFixed(0)+' KB';
  $('list').innerHTML=d.entries.length?d.entries.map(e=>`<div class="box ${e.level}"><div class="meta"><span class="lvl">${e.level}</span><b>${e.time}</b><span>${e.env}</span></div><pre>${esc(e.message)}</pre></div>`).join(''):'<div class="empty">No log entries.</div>';
}
$('q').oninput=$('level').onchange=load;
$('clear').onclick=async()=>{if(!confirm('Clear the whole laravel.log file?'))return;await fetch('/backend/logs/clear',{method:'POST',headers:H});load();};
setInterval(()=>{if($('auto').checked)load()},5000);load();
</script></body></html>
