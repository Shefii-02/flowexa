import { Outlet } from 'react-router-dom'

export default function WaAgentShell() {
  return (
    <div className="flex-1 overflow-y-auto">
      <Outlet />
    </div>
  )
}
