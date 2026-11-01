import { useCallback, useEffect, useState } from 'react'
import { accountsApi } from '@/api'
import { Button, ConfirmModal, EmptyState, Input, Modal, Select, TableSkeleton } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { inr, Stat, StatusPill, today, useCanManage } from './shared'

const CATS: Record<string, string> = { property: '🏢 Property', equipment: '🖥️ Equipment', vehicle: '🚗 Vehicle', intangible: '📜 Intangible' }
const blank = () => ({ name: '', tag: '', category: 'equipment', purchase_date: today(), cost: '', salvage_value: '', useful_life_months: 60, method: 'sl', wdv_rate: '',
  acquisition: 'purchase', paid_from_ledger_id: '', opening_accumulated: '', depreciated_upto: '', location: '', notes: '' })

/** Fixed-asset register: property, equipment, vehicles, intangibles — with monthly depreciation and disposal. */
export default function AssetsTab() {
  const canManage = useCanManage()
  const [d, setD] = useState<any>(null)
  const [ledgers, setLedgers] = useState<any[]>([])
  const [form, setForm] = useState<any>(null)
  const [dispose, setDispose] = useState<any>(null)
  const [del, setDel] = useState<any>(null)
  const [plan, setPlan] = useState<any>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => { accountsApi.assets().then(r => setD(r.data)).catch(e => toast.error(getError(e))) }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { accountsApi.ledgers().then(r => setLedgers(r.data.ledgers)).catch(() => {}) }, [])
  const set = (k: string, v: any) => setForm((f: any) => ({ ...f, [k]: v }))
  const payFrom = ledgers.filter(l => ['cash', 'bank', 'creditor'].includes(l.group) && l.is_active !== false)
  const receiveIn = ledgers.filter(l => ['cash', 'bank'].includes(l.group))

  const save = async () => {
    setBusy(true)
    try {
      const f = form
      const body: any = { ...f, cost: Number(f.cost), salvage_value: Number(f.salvage_value) || 0, useful_life_months: Number(f.useful_life_months) || 60,
        wdv_rate: f.method === 'wdv' ? Number(f.wdv_rate) || null : null, paid_from_ledger_id: f.acquisition === 'purchase' ? f.paid_from_ledger_id || null : null,
        opening_accumulated: f.acquisition === 'existing' ? Number(f.opening_accumulated) || 0 : 0, depreciated_upto: f.acquisition === 'existing' && f.depreciated_upto ? f.depreciated_upto : null }
      if (f.id) { ['acquisition', 'paid_from_ledger_id', 'opening_accumulated', 'depreciated_upto'].forEach(k => delete body[k]); await accountsApi.updateAsset(f.id, body) } else await accountsApi.createAsset(body)
      toast.success('Saved.'); setForm(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const openPlan = async () => { try { setPlan((await accountsApi.depreciationPreview()).data) } catch (e) { toast.error(getError(e)) } }
  const run = async () => {
    setBusy(true)
    try { const { data } = await accountsApi.runDepreciation({ upto: plan.upto }); toast.success(data.message); setPlan(null); load() } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }
  const undoLast = async () => {
    const last = d.rows.reduce((m: string, a: any) => (a.last_depreciated_to && String(a.last_depreciated_to).slice(0, 10) > m ? String(a.last_depreciated_to).slice(0, 10) : m), '')
    if (!last || !window.confirm(`Remove the depreciation posted for the month ending ${last}?`)) return
    try { await accountsApi.undoDepreciation(last); toast.success('Removed.'); load() } catch (e) { toast.error(getError(e)) }
  }
  const doDispose = async () => {
    setBusy(true)
    try { const { data } = await accountsApi.disposeAsset(dispose.id, { date: dispose.date, proceeds: Number(dispose.proceeds) || 0, account_ledger_id: dispose.account_ledger_id || null }); toast.success(data.message); setDispose(null); load() }
    catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  if (!d) return <TableSkeleton rows={5} cols={7} />
  const diff = d.ledger_check.difference
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Stat label="Cost of active assets" value={inr(d.totals.cost)} />
        <Stat label="Accumulated depreciation" value={inr(d.totals.accumulated)} />
        <Stat label="Net book value" value={inr(d.totals.net_book_value)} tone="text-green-600" />
        <Stat label="Depreciation pending" value={inr(d.pending_depreciation)} tone={d.pending_depreciation ? 'text-amber-600' : ''} sub="for completed months" />
      </div>
      {Math.abs(diff) > 0.01 && <p className="text-sm text-amber-700 bg-amber-50 rounded-lg p-3">The Accumulated Depreciation ledger ({inr(d.ledger_check.accumulated_in_ledger)}) differs from the register ({inr(d.ledger_check.accumulated_in_register)}) by {inr(diff)}. This usually means an opening balance was keyed into the ledger but not the register (or vice versa).</p>}
      <div className="card">
        <div className="card-header gap-2 flex-wrap">
          {canManage && <><Button variant="secondary" onClick={openPlan}>Run depreciation…</Button>
          <button className="text-xs text-gray-500 hover:underline" onClick={undoLast}>Undo last month</button></>}
          {canManage && <div className="ml-auto"><Button onClick={() => setForm(blank())}>+ Add asset</Button></div>}
        </div>
        {d.rows.length === 0 ? <EmptyState icon="🏢" title="No fixed assets" desc="Record property, equipment, vehicles and intangibles to depreciate them month by month." /> : (
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Asset</th><th>Category</th><th>Bought</th><th className="text-right">Cost</th><th className="text-right">Depreciated</th><th className="text-right">Book value</th><th className="text-right">Per month</th><th>Status</th><th /></tr></thead>
            <tbody>{d.rows.map((a: any) => (
              <tr key={a.id}>
                <td><p className="font-medium">{a.name}</p><p className="text-xs text-gray-400">{[a.tag, a.location].filter(Boolean).join(' · ')}</p></td>
                <td className="text-sm">{CATS[a.category]}<p className="text-xs text-gray-400">{a.method === 'wdv' ? `${a.wdv_rate}% WDV` : `${a.useful_life_months} mo straight-line`}</p></td>
                <td className="text-sm">{String(a.purchase_date).slice(0, 10)}</td>
                <td className="text-right">{inr(a.cost)}</td><td className="text-right text-gray-500">{inr(a.accumulated)}</td>
                <td className="text-right font-medium">{a.status === 'disposed' ? '—' : inr(a.net_book_value)}</td>
                <td className="text-right text-sm">{a.monthly_charge ? inr(a.monthly_charge) : '—'}</td>
                <td><StatusPill status={a.status} label={a.fully_depreciated ? 'fully depreciated' : a.status} />
                  {a.status === 'disposed' && <p className="text-xs text-gray-400">{a.disposal_gain_loss >= 0 ? 'Gain' : 'Loss'} {inr(Math.abs(a.disposal_gain_loss))}</p>}</td>
                <td className="text-right whitespace-nowrap text-xs space-x-2">{canManage && <>
                  <button className="text-gray-600 hover:underline" onClick={() => setForm({ ...blank(), ...a, purchase_date: String(a.purchase_date).slice(0, 10), tag: a.tag || '', location: a.location || '', notes: a.notes || '', wdv_rate: a.wdv_rate || '', salvage_value: a.salvage_value || '' })}>Edit</button>
                  {a.status === 'active' && <button className="text-purple-700 hover:underline" onClick={() => setDispose({ id: a.id, name: a.name, date: today(), proceeds: '', account_ledger_id: '' })}>Sell / write off</button>}
                  {a.status === 'active' && !a.last_depreciated_to && <button className="text-red-500 hover:underline" onClick={() => setDel(a)}>Delete</button>}
                </>}</td>
              </tr>
            ))}</tbody>
          </table></div>
        )}
      </div>

      {form && (
        <Modal open onClose={() => setForm(null)} size="lg" title={form.id ? 'Edit asset' : 'Add fixed asset'}
          footer={<><Button variant="secondary" onClick={() => setForm(null)}>Cancel</Button><Button onClick={save} loading={busy}>Save</Button></>}>
          <div className="grid sm:grid-cols-2 gap-4">
            <Input label="Name" value={form.name} onChange={e => set('name', e.target.value)} />
            <Select label="Category" value={form.category} onChange={e => { set('category', e.target.value); if (e.target.value === 'intangible') set('method', 'sl') }} options={Object.entries(CATS).map(([v, l]) => ({ value: v, label: l }))} />
            <Input label="Asset tag / serial (optional)" value={form.tag} onChange={e => set('tag', e.target.value)} />
            <Input label="Location (optional)" value={form.location} onChange={e => set('location', e.target.value)} />
            <Input label="Purchase date" type="date" value={form.purchase_date} onChange={e => set('purchase_date', e.target.value)} />
            <Input label="Cost" type="number" min="0" value={form.cost} onChange={e => set('cost', e.target.value)} />
            <Select label="Depreciation method" value={form.method} onChange={e => set('method', e.target.value)} options={[{ value: 'sl', label: 'Straight line' }, ...(form.category === 'intangible' ? [] : [{ value: 'wdv', label: 'Written-down value' }])]} />
            {form.method === 'wdv' ? <Input label="Rate % per year" type="number" min="0" max="100" value={form.wdv_rate} onChange={e => set('wdv_rate', e.target.value)} />
              : <Input label="Useful life (months)" type="number" min="1" value={form.useful_life_months} onChange={e => set('useful_life_months', e.target.value)} />}
            <Input label="Salvage value" type="number" min="0" value={form.salvage_value} onChange={e => set('salvage_value', e.target.value)} />
            {!form.id && <>
              <Select label="How is it entering the books?" value={form.acquisition} onChange={e => set('acquisition', e.target.value)}
                options={[{ value: 'purchase', label: 'New purchase — post it now' }, { value: 'existing', label: 'Existing asset — already in my opening balances' }]} />
              {form.acquisition === 'purchase'
                ? <Select label="Paid from" value={form.paid_from_ledger_id} onChange={e => set('paid_from_ledger_id', e.target.value)} options={[{ value: '', label: 'Select account…' }, ...payFrom.map(l => ({ value: l.id, label: l.name }))]} />
                : <>
                  <Input label="Depreciation already charged" type="number" min="0" value={form.opening_accumulated} onChange={e => set('opening_accumulated', e.target.value)} />
                  <Input label="…up to (any date in that month)" type="date" value={form.depreciated_upto} onChange={e => set('depreciated_upto', e.target.value)} />
                </>}
            </>}
            <p className="text-xs text-gray-500 sm:col-span-2">Depreciation is charged for each completed month, starting with the month of purchase, as one journal per month. {form.id && 'Cost and depreciation terms lock once depreciation has been posted.'}</p>
          </div>
        </Modal>
      )}
      {plan && (
        <Modal open onClose={() => setPlan(null)} title={`Post depreciation up to ${plan.upto}`}
          footer={<><Button variant="secondary" onClick={() => setPlan(null)}>Cancel</Button><Button onClick={run} loading={busy} disabled={!plan.months.length}>Post {plan.months.length} month(s)</Button></>}>
          {plan.months.length === 0 ? <p className="text-sm text-gray-500">Depreciation is up to date for every completed month.</p> : (
            <table className="table text-sm"><thead><tr><th>Month ending</th><th>Assets</th><th className="text-right">Charge</th></tr></thead>
              <tbody>{plan.months.map((m: any) => <tr key={m.period_end}><td>{m.period_end}</td><td>{m.assets.length}</td><td className="text-right">{inr(m.total)}</td></tr>)}
                <tr className="font-semibold"><td colSpan={2}>Total</td><td className="text-right">{inr(plan.total)}</td></tr></tbody></table>)}
        </Modal>
      )}
      {dispose && (
        <Modal open onClose={() => setDispose(null)} title={`Sell / write off — ${dispose.name}`}
          footer={<><Button variant="secondary" onClick={() => setDispose(null)}>Cancel</Button><Button onClick={doDispose} loading={busy}>Dispose</Button></>}>
          <div className="grid sm:grid-cols-2 gap-4">
            <Input label="Date" type="date" value={dispose.date} onChange={e => setDispose({ ...dispose, date: e.target.value })} />
            <Input label="Sale proceeds (0 = write-off)" type="number" min="0" value={dispose.proceeds} onChange={e => setDispose({ ...dispose, proceeds: e.target.value })} />
            {Number(dispose.proceeds) > 0 && <Select label="Received in" value={dispose.account_ledger_id} onChange={e => setDispose({ ...dispose, account_ledger_id: e.target.value })} options={[{ value: '', label: 'Select account…' }, ...receiveIn.map(l => ({ value: l.id, label: l.name }))]} />}
            <p className="text-xs text-gray-500 sm:col-span-2">Depreciation must be posted up to the month before the sale. The gain or loss against book value goes to Non-Operating items.</p>
          </div>
        </Modal>
      )}
      <ConfirmModal open={!!del} title="Delete asset?" message={`Delete ${del?.name}? Its purchase entry is removed too.`} onConfirm={async () => { try { await accountsApi.deleteAsset(del.id); toast.success('Deleted.'); load() } catch (e) { toast.error(getError(e)) } setDel(null) }} onCancel={() => setDel(null)} />
    </div>
  )
}
