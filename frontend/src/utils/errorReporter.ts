// src/utils/errorReporter.ts
//
// Ships a built ErrorReport (see errorReport.ts) to the backend so it shows up
// in SuperAdmin → Error Logs (source: frontend), alongside Laravel's own and
// backend-node's own. Fire-and-forget: a failed report must never itself throw,
// show a toast, or otherwise become a second error on top of the first one.
import api from '@/api/client'
import type { ErrorReport } from './errorReport'

// Same crash repeating in a render loop shouldn't flood the endpoint (which also
// throttles server-side at 30/min) — report each distinct title+message once per tab.
const reported = new Set<string>()

export function reportErrorToServer(report: ErrorReport): void {
  const key = `${report.title}::${report.message}`
  if (reported.has(key)) return
  reported.add(key)

  api.post('/frontend-errors', {
    message: `${report.title}: ${report.message}`,
    stack: report.technical.stack,
    component_stack: report.technical.componentStack,
    url: report.page.url,
  }).catch(() => {
    // best-effort — never surface a failure to report a failure
  })
}
