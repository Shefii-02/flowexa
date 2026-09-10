import { useState } from 'react'
import {
  type ErrorReport,
  downloadReport,
  printReport,
  shareReport,
  copyReport,
  supportMailto,
} from '@/utils/errorReport'

/**
 * Action row for an ErrorReport — lets an admin hand the failure to support.
 *  • Share      — Web Share sheet where available, otherwise copies to clipboard
 *  • Copy       — full report text to clipboard
 *  • Download   — <reference>.txt file
 *  • Save as PDF — opens the browser print dialog (Save as PDF)
 *  • Contact support — pre-filled email
 */
export function ErrorReportActions({ report }: { report: ErrorReport }) {
  const [note, setNote] = useState('')

  const flash = (msg: string) => {
    setNote(msg)
    window.setTimeout(() => setNote(''), 2500)
  }

  return (
    <div className="flex flex-col items-center gap-2">
      <div className="flex flex-wrap items-center justify-center gap-2">
        <button
          onClick={async () => {
            const r = await shareReport(report)
            flash(r === 'shared' ? 'Shared' : r === 'copied' ? 'Copied to clipboard' : 'Could not share')
          }}
          className="px-3 py-2 text-sm rounded-lg bg-indigo-600 text-white hover:bg-indigo-700"
        >
          Share with support
        </button>
        <button
          onClick={async () => flash((await copyReport(report)) ? 'Copied' : 'Copy failed')}
          className="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50"
        >
          Copy details
        </button>
        <button
          onClick={() => downloadReport(report)}
          className="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50"
        >
          Download report
        </button>
        <button
          onClick={() => printReport(report)}
          className="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50"
        >
          Save as PDF
        </button>
        <a
          href={supportMailto(report)}
          className="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50"
        >
          Contact support team
        </a>
      </div>
      <p className="text-xs text-gray-400">
        Reference <span className="font-mono text-gray-600">{report.id}</span>
        {note && <span className="ml-2 text-green-600">· {note}</span>}
      </p>
    </div>
  )
}
