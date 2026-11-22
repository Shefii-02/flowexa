// Assignment Rules → Lead sources → Lead sheet: every new lead is saved in Teamzo as always AND added to a Google Sheet, one row, as it arrives.
import { useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { leadSheetApi, type LeadSheetState } from '@/api'
import { getError } from '@/utils'

export default function LeadSheetPanel() {
  const [s, setS] = useState<LeadSheetState | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { leadSheetApi.get().then(r => setS(r.data.data)).catch(() => {}) }, [])
  if (!s) return null

  const toggle = async () => {
    setBusy(true)
    try { const r = await leadSheetApi.set(!s.enabled); setS(r.data.data); toast.success(r.data.message) }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  return (
    <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-3" data-testid="lead-sheet-panel">
      <div className="flex flex-wrap items-center gap-3">
        <span className="text-xl w-7 text-center">📊</span>
        <div className="min-w-0 flex-1">
          <h2 className="font-semibold text-gray-800">Also save leads to a Google Sheet</h2>
          <p className="text-sm text-gray-500">Each new lead is saved in Teamzo and added as a row to your sheet the moment it arrives (name, phone, email, stage, source, category, who it is assigned to). Imported leads are not added row by row.</p>
        </div>
        <button type="button" role="switch" aria-checked={s.enabled} disabled={busy || (!s.available && !s.enabled)} onClick={toggle}
          title={!s.available && !s.enabled ? (s.reason ?? '') : s.enabled ? 'Turn off' : 'Turn on'}
          className={`relative h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 ${s.enabled ? 'bg-green-500' : 'bg-gray-300'}`}>
          <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${s.enabled ? 'left-[22px]' : 'left-0.5'}`} />
        </button>
      </div>
      {!s.available && <p data-testid="lead-sheet-reason" className="text-xs text-amber-700">🔒 {s.reason}</p>}
      {s.available && s.email && <p className="text-xs text-gray-400">Google account: {s.email}</p>}
      {s.enabled && s.url && <a data-testid="lead-sheet-link" href={s.url} target="_blank" rel="noreferrer" className="text-sm text-blue-600 hover:underline">Open the lead sheet ↗</a>}
    </section>
  )
}
