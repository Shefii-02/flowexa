import { useCallback, useEffect, useRef, useState } from 'react'
import QRCode from 'qrcode'
import { Modal, Button } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

// ── Types ─────────────────────────────────────────────────────────────────────

export interface LinkedDevice {
  id: number
  device_name: string
  platform: string
  app_version?: string | null
  ip?: string | null
  last_active_at?: string | null
  linked_at?: string
  active: boolean
  revoked_at?: string | null
}

export interface DeviceListResponse {
  max: number
  active: number
  devices: LinkedDevice[]
  user?: { id: number; name: string; email?: string }
}

export interface DeviceChallenge {
  id: number
  token: string
  pin: string
  qr_payload: { v: number; t: string }
  expires_at: string
  ttl: number
}

/** Backend adapter — company mode and superadmin mode plug different endpoints in. */
export interface DeviceService {
  list: () => Promise<DeviceListResponse>
  createChallenge: () => Promise<DeviceChallenge>
  challengeStatus?: (id: number) => Promise<{ status: string; device?: { device_name?: string } | null }>
  revoke: (deviceId: number) => Promise<void>
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const PLATFORM_ICON: Record<string, string> = { ios: '', android: '🤖', web: '🌐', other: '📱' }

function timeAgo(iso?: string | null): string {
  if (!iso) return '—'
  const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}

// ── Panel (the reusable guts — used inline and inside the modal) ──────────────

interface PanelProps {
  service: DeviceService
  /** When false the panel stops polling / rendering the QR (used by the modal when closed). */
  active?: boolean
  subtitle?: string
}

export function LinkedDevicesPanel({ service, active = true, subtitle }: PanelProps) {
  const [data, setData] = useState<DeviceListResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [tab, setTab] = useState<'qr' | 'pin'>('qr')
  const [challenge, setChallenge] = useState<DeviceChallenge | null>(null)
  const [starting, setStarting] = useState(false)
  const [secondsLeft, setSecondsLeft] = useState(0)
  const [revokingId, setRevokingId] = useState<number | null>(null)

  const canvasRef = useRef<HTMLCanvasElement | null>(null)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const baselineActive = useRef<number>(0)

  const stopPolling = () => {
    if (pollRef.current) {
      clearInterval(pollRef.current)
      pollRef.current = null
    }
  }

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await service.list())
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setLoading(false)
    }
  }, [service])

  useEffect(() => {
    if (active) {
      setChallenge(null)
      setTab('qr')
      void load()
    } else {
      stopPolling()
      setChallenge(null)
      setData(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active, service])

  const atLimit = !!data && data.active >= data.max

  const startChallenge = useCallback(async () => {
    setStarting(true)
    try {
      baselineActive.current = data?.active ?? 0
      const ch = await service.createChallenge()
      setChallenge(ch)
      setSecondsLeft(ch.ttl)
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setStarting(false)
    }
  }, [service, data])

  useEffect(() => {
    if (challenge && tab === 'qr' && canvasRef.current) {
      QRCode.toCanvas(canvasRef.current, JSON.stringify(challenge.qr_payload), {
        width: 232,
        margin: 1,
        errorCorrectionLevel: 'M',
      }).catch(() => undefined)
    }
  }, [challenge, tab])

  const onClaimed = useCallback((name?: string) => {
    stopPolling()
    setChallenge(null)
    toast.success(name ? `${name} linked` : 'Device linked')
    void load()
  }, [load])

  useEffect(() => {
    if (!challenge) {
      stopPolling()
      return
    }

    const tick = setInterval(() => setSecondsLeft(s => Math.max(0, s - 1)), 1000)

    pollRef.current = setInterval(async () => {
      try {
        if (service.challengeStatus) {
          const r = await service.challengeStatus(challenge.id)
          if (r.status === 'claimed') { onClaimed(r.device?.device_name); return }
          if (['expired', 'cancelled'].includes(r.status)) void startChallenge()
        } else {
          const fresh = await service.list()
          setData(fresh)
          if (fresh.active > baselineActive.current) onClaimed()
        }
      } catch {
        /* transient — keep polling */
      }
    }, service.challengeStatus ? 2500 : 3000)

    const rotate = setInterval(() => void startChallenge(), (challenge.ttl || 45) * 1000)

    return () => {
      clearInterval(tick)
      clearInterval(rotate)
      stopPolling()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [challenge])

  const revoke = async (id: number) => {
    setRevokingId(id)
    try {
      await service.revoke(id)
      toast.success('Device removed')
      await load()
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setRevokingId(null)
    }
  }

  return (
    <div className="space-y-5">
      {subtitle && <p className="text-sm text-gray-500">{subtitle}</p>}

      {challenge ? (
        <div className="rounded-xl border border-gray-200 p-4">
          <div className="flex gap-1 border-b border-gray-100 mb-4">
            {(['qr', 'pin'] as const).map(t => (
              <button
                key={t}
                onClick={() => setTab(t)}
                className={[
                  'px-4 py-2 text-sm font-medium border-b-2 -mb-px',
                  tab === t ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-400',
                ].join(' ')}
              >
                {t === 'qr' ? 'QR code' : 'PIN code'}
              </button>
            ))}
          </div>

          {tab === 'qr' ? (
            <div className="flex flex-col items-center gap-3">
              <canvas ref={canvasRef} className="rounded-lg" />
              <p className="text-xs text-gray-500 text-center max-w-xs">
                On the phone app open <strong>Link a device</strong> and scan this code.
              </p>
            </div>
          ) : (
            <div className="flex flex-col items-center gap-3">
              <div className="text-3xl font-mono font-bold tracking-[0.3em] text-gray-900 bg-gray-50 rounded-lg px-5 py-3">
                {challenge.pin}
              </div>
              <p className="text-xs text-gray-500 text-center max-w-xs">
                In the phone app choose <strong>Enter code instead</strong> and type this PIN.
              </p>
            </div>
          )}

          <div className="flex items-center justify-between mt-4 pt-3 border-t border-gray-100">
            <span className="text-xs text-gray-400">
              {secondsLeft > 0 ? `Refreshes in ${secondsLeft}s` : 'Refreshing…'}
            </span>
            <div className="flex gap-2">
              <button onClick={() => void startChallenge()} className="text-xs text-indigo-600 hover:underline">New code</button>
              <button onClick={() => setChallenge(null)} className="text-xs text-gray-400 hover:underline">Cancel</button>
            </div>
          </div>
        </div>
      ) : (
        <div className="flex items-center justify-between rounded-xl bg-gray-50 px-4 py-3">
          <div className="text-sm">
            <span className="font-medium text-gray-900">{data ? `${data.active} of ${data.max}` : '…'}</span>{' '}
            <span className="text-gray-500">device slots used</span>
          </div>
          <Button size="sm" onClick={startChallenge} loading={starting} disabled={atLimit}>Link a device</Button>
        </div>
      )}

      {atLimit && !challenge && (
        <p className="text-xs text-amber-600">The device limit is reached. Remove a device below to link a new one.</p>
      )}

      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Devices</p>
        {loading && !data ? (
          <p className="text-sm text-gray-400 py-4 text-center">Loading…</p>
        ) : !data?.devices.length ? (
          <p className="text-sm text-gray-400 py-4 text-center">No devices linked yet.</p>
        ) : (
          <ul className="divide-y divide-gray-50">
            {data.devices.map(d => (
              <li key={d.id} className="flex items-center justify-between py-2.5">
                <div className="flex items-center gap-3 min-w-0">
                  <span className="text-lg">{PLATFORM_ICON[d.platform] ?? '📱'}</span>
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-gray-900 truncate">
                      {d.device_name}
                      {!d.active && <span className="ml-2 text-xs text-gray-400">(removed)</span>}
                    </p>
                    <p className="text-xs text-gray-400 truncate">
                      {d.platform}{d.app_version ? ` · v${d.app_version}` : ''} · last active {timeAgo(d.last_active_at)}
                    </p>
                  </div>
                </div>
                {d.active && (
                  <button
                    onClick={() => revoke(d.id)}
                    disabled={revokingId === d.id}
                    className="text-xs text-red-500 hover:underline disabled:opacity-40 flex-shrink-0 ml-3"
                  >
                    {revokingId === d.id ? 'Removing…' : 'Remove'}
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}

// ── Modal wrapper ────────────────────────────────────────────────────────────

interface ModalProps {
  open: boolean
  onClose: () => void
  title?: string
  subtitle?: string
  service: DeviceService
}

export default function LinkedDevicesModal({ open, onClose, title, subtitle, service }: ModalProps) {
  return (
    <Modal open={open} onClose={onClose} title={title || 'Linked devices'} size="lg">
      <LinkedDevicesPanel service={service} active={open} subtitle={subtitle} />
    </Modal>
  )
}
