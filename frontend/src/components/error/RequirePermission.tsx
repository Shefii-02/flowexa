import type { ReactNode } from 'react'
import { usePermission } from '@/store'
import { AccessDeniedScreen } from './AccessDeniedScreen'

/**
 * Client-side permission gate for a route element. Renders the Access Denied
 * screen (with the required permission spelled out) instead of the page, so the
 * user gets a clear message + a support path rather than a doomed API call.
 *
 *   <Route path="staff" element={<RequirePermission perm="staff.view"><StaffPage/></RequirePermission>} />
 */
export function RequirePermission({
  perm,
  children,
}: {
  perm: string
  children: ReactNode
}) {
  const allowed = usePermission(perm)
  if (allowed) return <>{children}</>

  return (
    <AccessDeniedScreen
      forbidden={{
        message: 'You do not have permission to open this page.',
        requiredPermission: perm,
        status: 403,
        at: new Date().toISOString(),
      }}
    />
  )
}
