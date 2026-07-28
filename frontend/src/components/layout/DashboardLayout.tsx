import { Outlet, useNavigate } from 'react-router-dom'
import { Toaster } from 'react-hot-toast'
import { useAppDispatch, useAppSelector } from '@/store'
import { toggleSidebar } from '@/store/slices'
import { Sidebar } from './Sidebar'

export const DashboardLayout = () => {
  const dispatch    = useAppDispatch()
  const sidebarOpen = useAppSelector((s) => s.ui.sidebarOpen)

  return (
    <div className="flex h-screen overflow-hidden bg-gray-50">
      <Toaster position="top-right" toastOptions={{
        style: { fontSize: '13px', borderRadius: '10px', padding: '10px 14px' },
        success: { iconTheme: { primary: '#1D9E75', secondary: '#fff' } },
      }} />

      <Sidebar />

      {/* Main */}
      <div className="flex-1 flex flex-col overflow-hidden">
        {/* Topbar */}
        <header className="bg-white border-b border-gray-200 px-4 py-3 flex items-center gap-3 flex-shrink-0">
          <button
            onClick={() => dispatch(toggleSidebar())}
            className="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 transition-colors"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
          <div className="flex-1" />
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-y-auto p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
