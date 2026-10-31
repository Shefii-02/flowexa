// "Delivery speed & anti-block" card of the message sender: how fast a campaign goes, and how its texts are varied.
import { useEffect } from 'react'

export interface SenderLimits {
  default_per_min_min: number; default_per_min_max: number; default_per_5min_cap: number
  max_per_min: number; min_per_5min_cap: number; max_per_5min_cap: number
  min_share_numbers: number; max_variants: number
}

export interface SendingOpts {
  perMinMin: number; perMinMax: number; cap5: number
  variationOn: boolean
  variants: string[]        // extra texts; the message being written is always the first one
  shareNumbers: string[]    // numbers rotated into the messages
}

export const FALLBACK_LIMITS: SenderLimits = {
  default_per_min_min: 2, default_per_min_max: 3, default_per_5min_cap: 15,
  max_per_min: 100, min_per_5min_cap: 10, max_per_5min_cap: 500, min_share_numbers: 3, max_variants: 10,
}

export const defaultOpts = (l: SenderLimits): SendingOpts => ({
  perMinMin: l.default_per_min_min, perMinMax: l.default_per_min_max, cap5: l.default_per_5min_cap,
  variationOn: true, variants: [], shareNumbers: Array(l.min_share_numbers).fill(''),
})

const digits = (s: string) => s.replace(/\D/g, '')

/** Why the options cannot be sent yet, or null when they are fine. */
export function sendingOptionsError(o: SendingOpts, l: SenderLimits): string | null {
  if (o.perMinMin < 1 || o.perMinMax < o.perMinMin) return 'Messages per minute: the lowest must be at least 1 and not above the highest.'
  if (o.perMinMax > l.max_per_min) return `Messages per minute cannot be more than ${l.max_per_min}.`
  if (o.cap5 < l.min_per_5min_cap || o.cap5 > l.max_per_5min_cap) return `Messages per 5 minutes must be between ${l.min_per_5min_cap} and ${l.max_per_5min_cap}.`
  if (o.variationOn) {
    const valid = new Set(o.shareNumbers.map(digits).filter(d => d.length >= 7 && d.length <= 15))
    if (o.shareNumbers.some(n => n.trim() && !(digits(n).length >= 7 && digits(n).length <= 15))) return 'One of the numbers to share is not a valid phone number.'
    if (valid.size < l.min_share_numbers) return `Add at least ${l.min_share_numbers} different numbers to share — they are rotated through the messages.`
  }
  return null
}

export const toSendingOptionsPayload = (o: SendingOpts) => ({
  pacing: { per_min_min: o.perMinMin, per_min_max: o.perMinMax, per_5min_cap: o.cap5 },
  variation: {
    enabled: o.variationOn,
    variants: o.variants.map(v => v.trim()).filter(Boolean),
    share_numbers: o.shareNumbers.map(n => n.trim()).filter(Boolean),
    dots: true,
  },
})

/** Roughly how long the campaign runs: the slower of the per-minute pace and the 5-minute cap. */
export function estimateMinutes(count: number, o: SendingOpts): number {
  const perMin = Math.min((o.perMinMin + o.perMinMax) / 2, o.cap5 / 5)
  return perMin > 0 ? Math.ceil(count / perMin) : 0
}
export const formatDuration = (mins: number) =>
  mins < 60 ? `${mins} min` : `${Math.floor(mins / 60)} h ${mins % 60 ? `${mins % 60} min` : ''}`.trim()

const inputCls = 'w-full px-2 py-1.5 text-sm border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-300'

export default function SendingOptionsCard({ value, onChange, limits, recipientCount, textOnly, disabled }: {
  value: SendingOpts; onChange: (v: SendingOpts) => void; limits: SenderLimits
  recipientCount: number; textOnly: boolean; disabled?: boolean
}) {
  const set = (patch: Partial<SendingOpts>) => onChange({ ...value, ...patch })
  const num = (s: string) => Math.max(0, Math.floor(Number(s) || 0))

  // keep at least the required number of share boxes on screen
  useEffect(() => {
    if (value.variationOn && value.shareNumbers.length < limits.min_share_numbers) {
      set({ shareNumbers: [...value.shareNumbers, ...Array(limits.min_share_numbers - value.shareNumbers.length).fill('')] })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value.variationOn, limits.min_share_numbers])

  const err = sendingOptionsError(value, limits)
  const mins = estimateMinutes(recipientCount, value)

  return (
    <div className="space-y-3">
      <div className="rounded-lg bg-gray-50 border border-gray-100 p-3 space-y-2">
        <p className="text-xs font-medium text-gray-600">Delivery speed</p>
        <div className="grid grid-cols-3 gap-2">
          <label className="text-[11px] text-gray-500">Per minute (from)
            <input type="number" min={1} max={limits.max_per_min} disabled={disabled} className={inputCls} value={value.perMinMin} onChange={e => set({ perMinMin: num(e.target.value) })} />
          </label>
          <label className="text-[11px] text-gray-500">Per minute (to)
            <input type="number" min={1} max={limits.max_per_min} disabled={disabled} className={inputCls} value={value.perMinMax} onChange={e => set({ perMinMax: num(e.target.value) })} />
          </label>
          <label className="text-[11px] text-gray-500">Max per 5 min
            <input type="number" min={limits.min_per_5min_cap} max={limits.max_per_5min_cap} disabled={disabled} className={inputCls} value={value.cap5} onChange={e => set({ cap5: num(e.target.value) })} />
          </label>
        </div>
        <p className="text-[11px] text-gray-500">
          Each minute a random {value.perMinMin}–{value.perMinMax} messages go out, spread across the minute, and never more than {value.cap5} in any 5 minutes
          (allowed: up to {limits.max_per_min}/min, {limits.min_per_5min_cap}–{limits.max_per_5min_cap} per 5 min).
          {recipientCount > 0 && !err && <> <b className="text-gray-700">{recipientCount} recipients ≈ {formatDuration(mins)}.</b></>}
        </p>
      </div>

      <div className="rounded-lg border border-gray-200 p-3 space-y-3">
        <label className="flex items-start gap-2 cursor-pointer">
          <input type="checkbox" className="mt-0.5" disabled={disabled} checked={value.variationOn} onChange={e => set({ variationOn: e.target.checked })} />
          <span>
            <span className="text-xs font-semibold text-gray-700">Anti-block message variation</span>
            <span className="block text-[11px] text-gray-500">Every recipient gets a slightly different message, so WhatsApp's bot detection does not see one text sent to many people.</span>
          </span>
        </label>

        {value.variationOn && (
          <>
            <div className="space-y-1.5">
              <p className="text-xs font-medium text-gray-600">Numbers to share <span className="text-gray-400 font-normal">(at least {limits.min_share_numbers}, rotated through the messages)</span></p>
              {value.shareNumbers.map((n, i) => (
                <div key={i} className="flex gap-2">
                  <input className={inputCls} disabled={disabled} placeholder="+91 98765 43210" value={n}
                    onChange={e => set({ shareNumbers: value.shareNumbers.map((x, j) => j === i ? e.target.value : x) })} />
                  {value.shareNumbers.length > limits.min_share_numbers && (
                    <button type="button" className="text-gray-400 hover:text-red-500 px-1" onClick={() => set({ shareNumbers: value.shareNumbers.filter((_, j) => j !== i) })}>✕</button>
                  )}
                </div>
              ))}
              <button type="button" disabled={disabled || value.shareNumbers.length >= 20} className="text-xs text-brand-600 hover:underline" onClick={() => set({ shareNumbers: [...value.shareNumbers, ''] })}>+ Add number</button>
              <p className="text-[11px] text-gray-400">Put <code className="bg-gray-100 px-1 rounded">{'{{share_number}}'}</code> in your message where the number should appear. Without it, a “Contact:” line is added at the end.</p>
            </div>

            <div className="space-y-1.5">
              <p className="text-xs font-medium text-gray-600">Other versions of your message <span className="text-gray-400 font-normal">(optional — 3 to 5 total works well)</span></p>
              {!textOnly && <p className="text-[11px] text-amber-600">Message versions apply to text messages only. For media, only the shared number and the dots are added.</p>}
              {value.variants.map((v, i) => (
                <div key={i} className="flex gap-2">
                  <textarea rows={2} className={inputCls} disabled={disabled || !textOnly} placeholder={`Version ${i + 2} — same meaning, different words`} value={v}
                    onChange={e => set({ variants: value.variants.map((x, j) => j === i ? e.target.value : x) })} />
                  <button type="button" className="text-gray-400 hover:text-red-500 px-1" onClick={() => set({ variants: value.variants.filter((_, j) => j !== i) })}>✕</button>
                </div>
              ))}
              <button type="button" disabled={disabled || !textOnly || value.variants.length >= limits.max_variants - 1} className="text-xs text-brand-600 hover:underline disabled:opacity-40"
                onClick={() => set({ variants: [...value.variants, ''] })}>+ Add another version</button>
              <p className="text-[11px] text-gray-400">Use {'{{name}}'} in each version to keep it personal.</p>
            </div>

            <p className="text-[11px] text-gray-500 bg-gray-50 rounded-md px-2 py-1.5">
              Each message also ends with a line of <code>......</code>: 1 line on the first message, 2 on the next, 3 on the next, then back to 1 and round again.
            </p>
          </>
        )}
      </div>
      {err && <p className="text-xs text-red-500">{err}</p>}
    </div>
  )
}
