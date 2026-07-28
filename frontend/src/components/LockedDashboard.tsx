// src/components/shared/LockedDashboard.tsx
import { useNavigate } from 'react-router-dom'
import { useAppSelector } from '@/store'
import { Button } from '@/components/ui'

interface LockedDashboardProps {
  status: 'suspended' | 'expired' | 'cancelled'
}

const config = {
  suspended: {
    icon:    '🔒',
    title:   'Account Suspended',
    message: 'Your account has been suspended. Please contact support to resolve this issue.',
    color:   'bg-red-50 border-red-200',
    btnText: 'Contact support',
    btnAction: () => window.open('mailto:support@waapi.com', '_blank'),
    showRenew: false,
  },
  expired: {
    icon:    '⏰',
    title:   'Plan Expired',
    message: 'Your subscription plan has expired. Renew your plan to continue using all features.',
    color:   'bg-amber-50 border-amber-200',
    btnText: 'Renew plan',
    btnAction: null,
    showRenew: true,
  },
  cancelled: {
    icon:    '❌',
    title:   'Subscription Cancelled',
    message: 'Your subscription has been cancelled. Purchase a new plan to reactivate your account.',
    color:   'bg-gray-50 border-gray-200',
    btnText: 'Choose a plan',
    btnAction: null,
    showRenew: true,
  },
}

export const LockedDashboard = ({ status }: LockedDashboardProps) => {
  const navigate = useNavigate()
  const user     = useAppSelector(s => s.auth.user)
  const cfg      = config[status]

  return (
    <div className="min-h-[60vh] flex flex-col items-center justify-center text-center px-6">
      <div className={`border rounded-2xl p-10 max-w-md w-full ${cfg.color}`}>
        <div className="text-5xl mb-4">{cfg.icon}</div>
        <h2 className="text-xl font-bold text-gray-900 mb-2">{cfg.title}</h2>
        <p className="text-gray-600 text-sm mb-6">{cfg.message}</p>

        {user?.company && (
          <div className="bg-white rounded-xl p-4 text-sm text-left mb-6 border border-gray-100">
            <div className="flex justify-between mb-2">
              <span className="text-gray-500">Company</span>
              <span className="font-medium">{user.company.name}</span>
            </div>
            <div className="flex justify-between mb-2">
              <span className="text-gray-500">Plan</span>
              <span className="font-medium">{user.company.plan?.name || 'None'}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-gray-500">Status</span>
              <span className={`font-medium capitalize ${status === 'suspended' ? 'text-red-600' : 'text-amber-600'}`}>{status}</span>
            </div>
          </div>
        )}

        <div className="flex flex-col gap-2">
          {cfg.showRenew && (
            <Button className="w-full justify-center" onClick={() => navigate('/plan-purchase')}>
              {cfg.btnText}
            </Button>
          )}
          {!cfg.showRenew && (
            <Button className="w-full justify-center" onClick={cfg.btnAction || (() => {})}>
              {cfg.btnText}
            </Button>
          )}
          {status !== 'suspended' && (
            <Button variant="secondary" className="w-full justify-center" onClick={() => navigate('/wallet')}>
              Top up wallet
            </Button>
          )}
        </div>
      </div>

      {/* Disabled features preview */}
      <div className="mt-8 max-w-md w-full">
        <p className="text-xs text-gray-400 mb-3">The following features are locked:</p>
        <div className="grid grid-cols-3 gap-2 opacity-40 pointer-events-none">
          {['📢 Campaigns','🌿 Flow Builder','👥 Contacts','🎯 Leads','📊 Analytics','👤 Staff'].map(f => (
            <div key={f} className="bg-gray-100 rounded-lg py-3 text-xs text-center text-gray-500">{f}</div>
          ))}
        </div>
      </div>
    </div>
  )
}
