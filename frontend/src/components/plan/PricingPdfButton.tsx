// Superadmin: download the price list as a PDF, generated from the live plans and add-ons.
import { useState } from 'react'
import toast from 'react-hot-toast'
import { planApi } from '@/api'
import { Button } from '@/components/ui'
import { getError } from '@/utils'

export default function PricingPdfButton() {
  const [open, setOpen] = useState(false)
  const [scope, setScope] = useState<'all' | 'plans' | 'addons'>('all')
  const [cycle, setCycle] = useState<'both' | 'monthly' | 'yearly'>('both')
  const [busy, setBusy] = useState(false)

  const download = async () => {
    setBusy(true)
    try {
      const r = await planApi.saPricingPdf(scope, cycle)
      const url = URL.createObjectURL(r.data as Blob)
      const a = document.createElement('a'); a.href = url; a.download = `teamzo-pricing-${new Date().toISOString().slice(0, 10)}.pdf`; a.click()
      setTimeout(() => URL.revokeObjectURL(url), 5000)
      setOpen(false)
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  return (
    <div className="relative">
      <Button variant="secondary" onClick={() => setOpen(o => !o)} aria-expanded={open}>⬇ Price list (PDF)</Button>
      {open && (
        <div className="absolute right-0 z-20 mt-2 w-64 space-y-3 rounded-xl border border-gray-200 bg-white p-4 shadow-lg">
          <label className="block text-xs font-medium text-gray-600">Include
            <select className="select mt-1 w-full" value={scope} onChange={e => setScope(e.target.value as typeof scope)}>
              <option value="all">Plans and add-ons</option><option value="plans">Plans only</option><option value="addons">Add-ons only</option>
            </select></label>
          <label className="block text-xs font-medium text-gray-600">Prices
            <select className="select mt-1 w-full" value={cycle} onChange={e => setCycle(e.target.value as typeof cycle)}>
              <option value="both">Monthly and yearly</option><option value="monthly">Monthly only</option><option value="yearly">Yearly only</option>
            </select></label>
          <p className="text-[11px] text-gray-400">Built from the current plans and add-ons each time you download.</p>
          <Button className="w-full justify-center" onClick={download} loading={busy}>Download PDF</Button>
        </div>)}
    </div>
  )
}
