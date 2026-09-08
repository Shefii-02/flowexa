import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '@/api/client'
import { useCurrentUser } from '@/store'

type HealthSession = {
  id: string
  name: string
  phone: string | null
  status: string
  connected: boolean
}

type Health = {
  data: HealthSession[]
  summary: { total: number; connected: number; disconnected: number; reachable: boolean }
}

// Live WhatsApp session status in the dashboard header. Polls
// GET /waha/sessions/health once a minute (plain fetch — this renders outside
// the wa-chat QueryClientProvider) and shows a pill; clicking it lists every
// session one by one with a Reconnect link for the disconnected ones.
export function SessionStatusIndicator() {
  const hasWaChat = !!useCurrentUser()?.company?.wa_chat_token
  const [health, setHealth] = useState<Health | null>(null)
  const [open, setOpen] = useState(false)
  const boxRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!hasWaChat) return
    let cancelled = false

    const check = async () => {
      try {
        const res = await api.get('/waha/sessions/health')
        if (!cancelled) setHealth(res.data)
      } catch { /* keep last known state */ }
    }

    check()
    const id = window.setInterval(check, 60_000)
    return () => { cancelled = true; window.clearInterval(id) }
  }, [hasWaChat])

  useEffect(() => {
    if (!open) return
    const onDoc = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [open])

  if (!hasWaChat || !health) return null

  const { data: sessions, summary } = health
  const down = summary.disconnected
  const tone = summary.total === 0 ? 'gray' : down > 0 ? 'amber' : 'green'
  const dot  = { gray: 'bg-gray-400', amber: 'bg-amber-500', green: 'bg-green-500' }[tone]
  const text = summary.total === 0
    ? 'No sessions'
    : down > 0
      ? `${down} disconnected`
      : `${summary.connected} online`

  return (
    <div ref={boxRef} className="relative">
      <button
        onClick={() => setOpen(o => !o)}
        className={`flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors ${
          down > 0 ? 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100'
                   : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
        }`}
        title="WhatsApp session status"
      >
        <span className={`h-2 w-2 rounded-full ${dot} ${down > 0 ? 'animate-pulse' : ''}`} />
        <span className="hidden sm:inline">{text}</span>
        <span className="sm:hidden">{summary.connected}/{summary.total}</span>
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-2 w-72 rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg z-50">
          <div className="px-2.5 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
            WhatsApp Sessions
          </div>

          {sessions.length === 0 ? (
            <div className="px-2.5 py-3 text-sm text-gray-500">
              {summary.reachable ? 'No sessions yet.' : 'Session gateway unreachable.'}
            </div>
          ) : (
            <div className="max-h-72 overflow-y-auto">
              {sessions.map(s => (
                <div key={s.id} className="flex items-center gap-2.5 rounded-lg px-2.5 py-2 hover:bg-gray-50">
                  <span className={`h-2 w-2 flex-shrink-0 rounded-full ${s.connected ? 'bg-green-500' : 'bg-red-500'}`} />
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium text-gray-800">{s.name}</div>
                    <div className="truncate text-[11px] text-gray-400">
                      {s.phone || s.id} · {s.status}
                    </div>
                  </div>
                  {!s.connected && (
                    <Link to="/wa-chat/sessions" onClick={() => setOpen(false)}
                      className="flex-shrink-0 text-[11px] font-semibold text-blue-600 hover:underline">
                      Reconnect
                    </Link>
                  )}
                </div>
              ))}
            </div>
          )}

          <Link to="/wa-chat/sessions" onClick={() => setOpen(false)}
            className="mt-1 block rounded-lg px-2.5 py-2 text-[11px] font-medium text-gray-500 hover:bg-gray-50">
            Manage sessions →
          </Link>
        </div>
      )}
    </div>
  )
}
