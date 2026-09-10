import { useState } from 'react'
import { type ErrorReport, reportToText } from '@/utils/errorReport'
import { ErrorReportActions } from './ErrorReportActions'

interface Props {
  report: ErrorReport
  variant?: 'error' | 'denied'
  /** Optional action shown first, e.g. "Reload" or "Back to dashboard". */
  primaryAction?: { label: string; onClick: () => void }
}

/**
 * Full-page error / access-denied screen. Shows a friendly explanation, a
 * "contact support" path, and a collapsible technical section an admin can
 * share or download to help diagnose the problem.
 */
export function ErrorScreen({ report, variant = 'error', primaryAction }: Props) {
  const [showTech, setShowTech] = useState(variant === 'error')

  const denied = variant === 'denied'
  const needs =
    report.request?.requiredPermission &&
    [report.request.requiredPermission].flat().filter(Boolean)

  return (
    <div className="min-h-[60vh] flex items-center justify-center p-6">
      <div className="w-full max-w-2xl bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
        <div className="p-8 text-center">
          <div className="text-5xl mb-3">{denied ? '🔒' : '⚠️'}</div>
          <h1 className="text-xl font-bold text-gray-900">
            {denied ? 'Permission denied' : 'Something went wrong'}
          </h1>
          <p className="text-sm text-gray-500 mt-2 max-w-md mx-auto">
            {denied ? (
              <>
                Your account doesn’t have access to this page or action.
                {needs && needs.length > 0 && (
                  <>
                    {' '}It requires{' '}
                    {needs.map((p, i) => (
                      <span key={p}>
                        <code className="text-xs bg-gray-100 rounded px-1 py-0.5">{p}</code>
                        {i < needs.length - 1 ? ' or ' : ''}
                      </span>
                    ))}
                    .
                  </>
                )}{' '}
                Ask an admin to update your role, or contact the support team.
              </>
            ) : (
              <>
                The page hit an unexpected error. You can retry, or send this
                report to the support team so we can fix it.
              </>
            )}
          </p>

          <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
            {primaryAction && (
              <button
                onClick={primaryAction.onClick}
                className="px-4 py-2 text-sm rounded-lg bg-gray-900 text-white hover:bg-gray-800"
              >
                {primaryAction.label}
              </button>
            )}
          </div>

          <div className="mt-6 border-t border-gray-100 pt-6">
            <ErrorReportActions report={report} />
          </div>
        </div>

        <div className="border-t border-gray-100 bg-gray-50">
          <button
            onClick={() => setShowTech((v) => !v)}
            className="w-full px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide hover:text-gray-700"
          >
            {showTech ? '▾' : '▸'} Technical details
          </button>
          {showTech && (
            <pre className="px-6 pb-5 text-[11px] leading-relaxed text-gray-600 whitespace-pre-wrap break-words max-h-72 overflow-auto">
              {reportToText(report)}
            </pre>
          )}
        </div>
      </div>
    </div>
  )
}
