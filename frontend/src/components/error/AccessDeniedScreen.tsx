import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAppSelector } from '@/store'
import { buildErrorReport } from '@/utils/errorReport'
import { ErrorScreen } from './ErrorScreen'
import type { ForbiddenError } from '@/store/slices'

/**
 * Rendered in place of the page when the API rejects a request with 403, or by
 * <RequirePermission> for a client-side check. Reads details from the appError
 * slice (populated by the axios interceptor) but can also take an explicit
 * `forbidden` prop for the client-side case.
 */
export function AccessDeniedScreen({ forbidden }: { forbidden?: ForbiddenError }) {
  const navigate = useNavigate()
  const fromStore = useAppSelector((s) => s.appError.forbidden)
  const f = forbidden ?? fromStore ?? undefined

  const report = useMemo(
    () =>
      buildErrorReport({
        kind: 'permission',
        title: 'Access denied',
        message: f?.message || 'You do not have permission to view this page.',
        request: {
          method: f?.method,
          url: f?.url,
          status: f?.status ?? 403,
          code: f?.code,
          requiredPermission: f?.requiredPermission,
          requestId: f?.requestId,
        },
      }),
    [f],
  )

  return (
    <ErrorScreen
      report={report}
      variant="denied"
      primaryAction={{ label: 'Back to dashboard', onClick: () => navigate('/dashboard') }}
    />
  )
}
