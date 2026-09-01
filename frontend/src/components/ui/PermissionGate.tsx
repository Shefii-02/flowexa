import { usePermission } from '@/store'
import type { ReactNode } from 'react'

interface Props {
  permission: string
  fallback?: ReactNode
  children: ReactNode
}

export const PermissionGate = ({ permission, fallback = null, children }: Props) => {
  const allowed = usePermission(permission)
  return allowed ? <>{children}</> : <>{fallback}</>
}
