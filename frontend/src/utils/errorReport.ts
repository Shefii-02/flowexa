// src/utils/errorReport.ts
//
// Builds a structured, shareable diagnostic report from any caught error so an
// admin can hand it to the support team. Used by the error boundary and the
// access-denied screen.
import { store } from '@/store'

export interface ErrorReport {
  id: string
  kind: 'render' | 'permission' | 'network' | 'unknown'
  title: string
  message: string
  when: string
  page: { url: string; path: string }
  session: {
    userId?: number
    email?: string
    role?: string | null
    companyId?: number
    companyName?: string | null
  }
  request?: {
    method?: string
    url?: string
    status?: number
    code?: string
    requiredPermission?: string | string[]
    requestId?: string
  }
  technical: {
    error?: string
    stack?: string
    componentStack?: string
  }
  env: {
    appUrl: string
    userAgent: string
    viewport: string
    buildMode: string
  }
}

function rid() {
  return 'ERR-' + Date.now().toString(36).toUpperCase() + '-' + Math.random().toString(36).slice(2, 6).toUpperCase()
}

export function buildErrorReport(input: {
  kind: ErrorReport['kind']
  title: string
  message: string
  error?: unknown
  componentStack?: string
  request?: ErrorReport['request']
}): ErrorReport {
  const user = store.getState().auth.user
  const err = input.error as { stack?: string; message?: string } | undefined

  return {
    id: rid(),
    kind: input.kind,
    title: input.title,
    message: input.message,
    when: new Date().toISOString(),
    page: { url: window.location.href, path: window.location.pathname + window.location.search },
    session: {
      userId: user?.id,
      email: user?.email,
      role: user?.role?.name ?? null,
      companyId: user?.company?.id,
      companyName: user?.company?.name ?? null,
    },
    request: input.request,
    technical: {
      error: err?.message,
      stack: err?.stack,
      componentStack: input.componentStack,
    },
    env: {
      appUrl: window.location.origin,
      userAgent: navigator.userAgent,
      viewport: `${window.innerWidth}x${window.innerHeight}`,
      buildMode: import.meta.env.MODE,
    },
  }
}

export function reportToText(r: ErrorReport): string {
  const L: string[] = []
  L.push('FLOWEXA ERROR REPORT')
  L.push('='.repeat(60))
  L.push(`Reference   : ${r.id}`)
  L.push(`Type        : ${r.kind}`)
  L.push(`Title       : ${r.title}`)
  L.push(`Message     : ${r.message}`)
  L.push(`When        : ${r.when}`)
  L.push('')
  L.push('PAGE')
  L.push(`  URL       : ${r.page.url}`)
  L.push('')
  L.push('ACCOUNT')
  L.push(`  User      : #${r.session.userId ?? '-'} ${r.session.email ?? ''}`)
  L.push(`  Role      : ${r.session.role ?? '-'}`)
  L.push(`  Company   : #${r.session.companyId ?? '-'} ${r.session.companyName ?? ''}`)
  if (r.request) {
    L.push('')
    L.push('REQUEST')
    L.push(`  ${r.request.method ?? ''} ${r.request.url ?? ''}`)
    L.push(`  Status    : ${r.request.status ?? '-'}${r.request.code ? ` (${r.request.code})` : ''}`)
    if (r.request.requiredPermission)
      L.push(`  Needs     : ${[r.request.requiredPermission].flat().join(', ')}`)
    if (r.request.requestId) L.push(`  Request ID: ${r.request.requestId}`)
  }
  if (r.technical.error || r.technical.stack || r.technical.componentStack) {
    L.push('')
    L.push('TECHNICAL')
    if (r.technical.error) L.push(`  Error     : ${r.technical.error}`)
    if (r.technical.stack) {
      L.push('  Stack:')
      L.push(r.technical.stack.split('\n').map((s) => '    ' + s).join('\n'))
    }
    if (r.technical.componentStack) {
      L.push('  Component stack:')
      L.push(r.technical.componentStack.split('\n').map((s) => '    ' + s.trim()).join('\n'))
    }
  }
  L.push('')
  L.push('ENVIRONMENT')
  L.push(`  App       : ${r.env.appUrl}  (${r.env.buildMode})`)
  L.push(`  Viewport  : ${r.env.viewport}`)
  L.push(`  UA        : ${r.env.userAgent}`)
  return L.join('\n')
}

/** Opens a clean, printable window (Ctrl/Cmd-P → "Save as PDF"). */
export function printReport(r: ErrorReport) {
  const w = window.open('', '_blank', 'width=800,height=900')
  if (!w) {
    // Popup blocked — fall back to a download so the admin still gets the report.
    downloadReport(r)
    return
  }
  const esc = (s: string) =>
    s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c] as string))
  w.document.write(`<!doctype html><html><head><meta charset="utf-8">
<title>${esc(r.id)} — Flowexa error report</title>
<style>
  body{font:12px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#111;margin:32px;}
  h1{font-size:16px;margin:0 0 4px;}
  .muted{color:#666;font-size:11px;margin-bottom:18px;}
  pre{white-space:pre-wrap;word-break:break-word;font:11px/1.5 ui-monospace,Menlo,Consolas,monospace;
      background:#f6f7f9;border:1px solid #e5e7eb;border-radius:8px;padding:14px;}
  @media print{body{margin:12mm;} pre{border:none;background:transparent;padding:0;}}
</style></head><body>
<h1>Flowexa error report</h1>
<div class="muted">Reference ${esc(r.id)} · generated ${esc(new Date().toLocaleString())}</div>
<pre>${esc(reportToText(r))}</pre>
<script>window.onload=function(){window.focus();window.print();}</script>
</body></html>`)
  w.document.close()
}

export function downloadReport(r: ErrorReport) {
  const blob = new Blob([reportToText(r)], { type: 'text/plain;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `${r.id}.txt`
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

export async function copyReport(r: ErrorReport): Promise<boolean> {
  const text = reportToText(r)
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    // Fallback for non-secure contexts.
    const ta = document.createElement('textarea')
    ta.value = text
    ta.style.position = 'fixed'
    ta.style.opacity = '0'
    document.body.appendChild(ta)
    ta.select()
    let ok = false
    try {
      ok = document.execCommand('copy')
    } catch {
      ok = false
    }
    ta.remove()
    return ok
  }
}

export async function shareReport(r: ErrorReport): Promise<'shared' | 'copied' | 'failed'> {
  const text = reportToText(r)
  const nav = navigator as Navigator & { share?: (d: ShareData) => Promise<void> }
  if (nav.share) {
    try {
      await nav.share({ title: `Flowexa error ${r.id}`, text })
      return 'shared'
    } catch {
      /* user cancelled or unsupported — fall through to copy */
    }
  }
  return (await copyReport(r)) ? 'copied' : 'failed'
}

export function supportMailto(r: ErrorReport): string {
  const subject = encodeURIComponent(`[Flowexa] ${r.kind} error ${r.id}`)
  const body = encodeURIComponent(
    `Hi Support team,\n\nI hit an error in Flowexa. Details below.\n\n${reportToText(r)}\n`,
  )
  return `mailto:support@flowexa.app?subject=${subject}&body=${body}`
}
