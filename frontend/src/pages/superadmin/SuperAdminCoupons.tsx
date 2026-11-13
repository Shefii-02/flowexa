// Superadmin: coupon codes — create, restrict, switch off, and see exactly how each one is being used.
import { useEffect, useState } from 'react'
import toast from 'react-hot-toast'
import { planApi } from '@/api'
import { Badge, Button, Input, Modal, Spinner, StatCard } from '@/components/ui'
import { fmt, getError } from '@/utils'

type Coupon = {
  id: number; code: string; name: string; description: string | null; discount_type: 'percent' | 'flat'; discount_value: string | number
  max_discount: string | number | null; min_amount: string | number | null; applies_to: string[] | null; plan_ids: number[] | null; addon_ids: number[] | null
  durations: string[] | null; first_purchase_only: boolean; company_id: number | null; max_redemptions: number | null; per_company_limit: number
  starts_at: string | null; expires_at: string | null; is_active: boolean; deleted_at?: string | null
  uses: number; discount_given: number; revenue: number
}
type Opt = { id: number; name: string }

const KINDS = [{ k: 'plan', l: 'Plans' }, { k: 'addon', l: 'Add-ons' }, { k: 'topup', l: 'Top-ups' }]
const DURS = [{ k: 'monthly', l: '1 month' }, { k: '3month', l: '3 months' }, { k: '6month', l: '6 months' }, { k: 'yearly', l: '12 months' }]
const EMPTY = {
  code: '', name: '', description: '', discount_type: 'percent', discount_value: '10', max_discount: '', min_amount: '',
  applies_to: [] as string[], plan_ids: [] as number[], addon_ids: [] as number[], durations: [] as string[],
  first_purchase_only: false, company_id: '', max_redemptions: '', per_company_limit: '1', starts_at: '', expires_at: '', is_active: true,
}
const day = (d: string | null) => (d ? d.slice(0, 10) : '')
const num = (v: string) => (v === '' ? null : Number(v))

function Chips<T extends string | number>({ options, value, onChange }: { options: { k: T; l: string }[]; value: T[]; onChange: (v: T[]) => void }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {options.map(o => {
        const on = value.includes(o.k)
        return <button type="button" key={String(o.k)} aria-pressed={on} onClick={() => onChange(on ? value.filter(x => x !== o.k) : [...value, o.k])}
          className={`rounded-full border px-3 py-1 text-xs font-medium ${on ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-200 text-gray-600 hover:border-brand-300'}`}>{o.l}</button>
      })}
    </div>
  )
}

export default function SuperAdminCoupons() {
  const [rows, setRows] = useState<Coupon[]>([])
  const [plans, setPlans] = useState<Opt[]>([])
  const [addons, setAddons] = useState<Opt[]>([])
  const [totals, setTotals] = useState<{ redemptions: number; discount_given: number; revenue: number; active_coupons: number } | null>(null)
  const [loading, setLoading] = useState(true)
  const [edit, setEdit] = useState<Coupon | 'new' | null>(null)
  const [form, setForm] = useState(EMPTY)
  const [saving, setSaving] = useState(false)
  const [usage, setUsage] = useState<any>(null)
  const set = <K extends keyof typeof EMPTY>(k: K, v: (typeof EMPTY)[K]) => setForm(f => ({ ...f, [k]: v }))

  const load = () => Promise.all([planApi.saCoupons(), planApi.saCouponAnalytics()])
    .then(([c, a]) => { setRows(c.data.coupons); setPlans(c.data.plans); setAddons(c.data.addons); setTotals(a.data.totals) })
    .catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  useEffect(() => { load() }, [])

  const open = (c: Coupon | 'new') => {
    setEdit(c)
    setForm(c === 'new' ? EMPTY : {
      code: c.code, name: c.name, description: c.description ?? '', discount_type: c.discount_type, discount_value: String(c.discount_value),
      max_discount: c.max_discount != null ? String(c.max_discount) : '', min_amount: c.min_amount != null ? String(c.min_amount) : '',
      applies_to: c.applies_to ?? [], plan_ids: c.plan_ids ?? [], addon_ids: c.addon_ids ?? [], durations: c.durations ?? [],
      first_purchase_only: c.first_purchase_only, company_id: c.company_id ? String(c.company_id) : '', max_redemptions: c.max_redemptions ? String(c.max_redemptions) : '',
      per_company_limit: String(c.per_company_limit), starts_at: day(c.starts_at), expires_at: day(c.expires_at), is_active: c.is_active,
    })
  }

  const save = async () => {
    setSaving(true)
    const payload = {
      code: form.code.trim().toUpperCase(), name: form.name, description: form.description || null,
      discount_type: form.discount_type, discount_value: +form.discount_value, max_discount: num(form.max_discount), min_amount: num(form.min_amount),
      applies_to: form.applies_to, plan_ids: form.plan_ids, addon_ids: form.addon_ids, durations: form.durations,
      first_purchase_only: form.first_purchase_only, company_id: num(form.company_id), max_redemptions: num(form.max_redemptions),
      per_company_limit: +form.per_company_limit || 1, starts_at: form.starts_at || null, expires_at: form.expires_at || null, is_active: form.is_active,
    }
    try {
      if (edit === 'new') await planApi.saCreateCoupon(payload); else if (edit) await planApi.saUpdateCoupon(edit.id, payload)
      toast.success('Coupon saved'); setEdit(null); load()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  const toggle = async (c: Coupon) => {
    try { await planApi.saUpdateCoupon(c.id, { ...c, applies_to: c.applies_to ?? [], plan_ids: c.plan_ids ?? [], addon_ids: c.addon_ids ?? [], durations: c.durations ?? [], is_active: !c.is_active }); load() }
    catch (e) { toast.error(getError(e)) }
  }
  const remove = async (c: Coupon) => {
    if (!confirm(`Delete coupon ${c.code}? Its usage history is kept.`)) return
    try { await planApi.saDeleteCoupon(c.id); toast.success('Deleted'); load() } catch (e) { toast.error(getError(e)) }
  }
  const showUsage = (c: Coupon) => planApi.saCouponUsage(c.id).then(r => setUsage(r.data)).catch(e => toast.error(getError(e)))

  const status = (c: Coupon) => {
    if (c.deleted_at) return <Badge variant="red">deleted</Badge>
    if (!c.is_active) return <Badge variant="gray">off</Badge>
    if (c.expires_at && new Date(c.expires_at) < new Date()) return <Badge variant="yellow">expired</Badge>
    if (c.max_redemptions && c.uses >= c.max_redemptions) return <Badge variant="yellow">used up</Badge>
    return <Badge variant="green">live</Badge>
  }

  if (loading) return <div className="flex justify-center p-10"><Spinner /></div>
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="page-title">Coupons</h1><p className="page-sub">Discount codes for plans, add-ons and top-ups — at sign-up, at checkout and when you create a company.</p></div>
        <Button onClick={() => open('new')}>+ New coupon</Button>
      </div>

      {totals && (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          <StatCard label="Live coupons" value={totals.active_coupons} icon="🎟️" />
          <StatCard label="Times used" value={fmt.number(totals.redemptions)} icon="🧾" />
          <StatCard label="Discount given" value={`₹${fmt.number(Math.round(totals.discount_given))}`} icon="🏷️" color="text-amber-600" />
          <StatCard label="Revenue through coupons" value={`₹${fmt.number(Math.round(totals.revenue))}`} icon="💰" color="text-green-600" />
        </div>)}

      <div className="card"><div className="table-wrapper"><table className="table">
        <thead><tr><th>Code</th><th>Discount</th><th>Applies to</th><th>Valid</th><th>Used</th><th>Discount given</th><th>Status</th><th /></tr></thead>
        <tbody>
          {rows.map(c => (
            <tr key={c.id}>
              <td><p className="font-mono font-semibold">{c.code}</p><p className="text-xs text-gray-400">{c.name}</p></td>
              <td>{c.discount_type === 'percent' ? `${Number(c.discount_value)}%${c.max_discount ? ` (max ₹${fmt.number(Number(c.max_discount))})` : ''}` : `₹${fmt.number(Number(c.discount_value))} off`}</td>
              <td className="text-xs text-gray-600">{c.applies_to?.length ? c.applies_to.join(', ') : 'everything'}{c.plan_ids?.length ? ` · ${c.plan_ids.length} plan(s)` : ''}{c.first_purchase_only ? ' · first purchase' : ''}{c.company_id ? ` · company #${c.company_id}` : ''}</td>
              <td className="text-xs text-gray-500">{c.starts_at || c.expires_at ? `${c.starts_at ? fmt.date(c.starts_at) : '…'} → ${c.expires_at ? fmt.date(c.expires_at) : 'no end'}` : 'always'}</td>
              <td><button className="text-brand-600 underline" onClick={() => showUsage(c)}>{c.uses}{c.max_redemptions ? ` / ${c.max_redemptions}` : ''}</button></td>
              <td>₹{fmt.number(Math.round(c.discount_given))}</td>
              <td>{status(c)}</td>
              <td className="space-x-1 text-right whitespace-nowrap">
                {!c.deleted_at && <>
                  <Button size="sm" variant="secondary" onClick={() => open(c)}>Edit</Button>
                  <Button size="sm" variant="secondary" onClick={() => toggle(c)}>{c.is_active ? 'Turn off' : 'Turn on'}</Button>
                  <Button size="sm" variant="danger" onClick={() => remove(c)}>Delete</Button></>}
              </td>
            </tr>))}
          {!rows.length && <tr><td colSpan={8} className="py-8 text-center text-sm text-gray-500">No coupons yet.</td></tr>}
        </tbody></table></div></div>

      <Modal open={!!edit} onClose={() => setEdit(null)} title={edit === 'new' ? 'New coupon' : 'Edit coupon'}>
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <Input label="Code" value={form.code} onChange={e => set('code', e.target.value.toUpperCase())} placeholder="LAUNCH20" />
            <Input label="Name (internal)" value={form.name} onChange={e => set('name', e.target.value)} />
          </div>
          <Input label="Description" value={form.description} onChange={e => set('description', e.target.value)} />
          <div className="grid grid-cols-3 gap-3">
            <label className="block text-sm font-medium text-gray-700">Type
              <select className="select mt-1 w-full" value={form.discount_type} onChange={e => set('discount_type', e.target.value)}><option value="percent">Percent %</option><option value="flat">Flat ₹</option></select></label>
            <Input label={form.discount_type === 'percent' ? 'Percent off' : '₹ off'} type="number" min={0} value={form.discount_value} onChange={e => set('discount_value', e.target.value)} />
            {form.discount_type === 'percent' && <Input label="Max ₹ off" type="number" min={0} value={form.max_discount} onChange={e => set('max_discount', e.target.value)} placeholder="no cap" />}
          </div>
          <div><p className="mb-1 text-sm font-medium text-gray-700">Applies to <span className="font-normal text-gray-400">(none selected = everything)</span></p>
            <Chips options={KINDS.map(x => ({ k: x.k, l: x.l }))} value={form.applies_to} onChange={v => set('applies_to', v)} /></div>
          {(form.applies_to.length === 0 || form.applies_to.includes('plan')) && (
            <div><p className="mb-1 text-sm font-medium text-gray-700">Only these plans <span className="font-normal text-gray-400">(none = any)</span></p>
              <Chips options={plans.map(p => ({ k: p.id, l: p.name }))} value={form.plan_ids} onChange={v => set('plan_ids', v)} /></div>)}
          {(form.applies_to.length === 0 || form.applies_to.includes('addon')) && addons.length > 0 && (
            <div><p className="mb-1 text-sm font-medium text-gray-700">Only these add-ons <span className="font-normal text-gray-400">(none = any)</span></p>
              <Chips options={addons.map(p => ({ k: p.id, l: p.name }))} value={form.addon_ids} onChange={v => set('addon_ids', v)} /></div>)}
          <div><p className="mb-1 text-sm font-medium text-gray-700">Billing periods <span className="font-normal text-gray-400">(none = any)</span></p>
            <Chips options={DURS} value={form.durations} onChange={v => set('durations', v)} /></div>
          <div className="grid grid-cols-3 gap-3">
            <Input label="Minimum order ₹" type="number" min={0} value={form.min_amount} onChange={e => set('min_amount', e.target.value)} />
            <Input label="Total uses" type="number" min={1} value={form.max_redemptions} onChange={e => set('max_redemptions', e.target.value)} placeholder="unlimited" />
            <Input label="Uses per company" type="number" min={1} value={form.per_company_limit} onChange={e => set('per_company_limit', e.target.value)} />
          </div>
          <div className="grid grid-cols-3 gap-3">
            <Input label="Starts" type="date" value={form.starts_at} onChange={e => set('starts_at', e.target.value)} />
            <Input label="Ends" type="date" value={form.expires_at} onChange={e => set('expires_at', e.target.value)} />
            <Input label="Only company #" type="number" value={form.company_id} onChange={e => set('company_id', e.target.value)} placeholder="anyone" />
          </div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.first_purchase_only} onChange={e => set('first_purchase_only', e.target.checked)} /> First purchase only</label>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={e => set('is_active', e.target.checked)} /> Active</label>
          <div className="flex justify-end gap-2 pt-2"><Button variant="secondary" onClick={() => setEdit(null)}>Cancel</Button><Button onClick={save} loading={saving}>Save</Button></div>
        </div>
      </Modal>

      <Modal open={!!usage} onClose={() => setUsage(null)} title={usage ? `Usage — ${usage.coupon.code}` : ''}>
        {usage && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
              <StatCard label="Times used" value={usage.totals.uses} />
              <StatCard label="Companies" value={usage.totals.unique_companies} />
              <StatCard label="Discount given" value={`₹${fmt.number(Math.round(usage.totals.discount_given))}`} />
              <StatCard label="Revenue" value={`₹${fmt.number(Math.round(usage.totals.revenue))}`} />
            </div>
            {usage.totals.remaining !== null && <p className="text-sm text-gray-500">{usage.totals.remaining} use(s) left.</p>}
            {usage.monthly.length > 0 && (
              <table className="table"><thead><tr><th>Month</th><th>Uses</th><th>Discount</th><th>Revenue</th></tr></thead>
                <tbody>{usage.monthly.map((m: any) => <tr key={m.month}><td>{m.month}</td><td>{m.uses}</td><td>₹{fmt.number(Math.round(Number(m.discount)))}</td><td>₹{fmt.number(Math.round(Number(m.revenue)))}</td></tr>)}</tbody></table>)}
            <div className="table-wrapper"><table className="table"><thead><tr><th>When</th><th>Company / email</th><th>For</th><th>Order</th><th>Discount</th><th>Paid</th></tr></thead>
              <tbody>{usage.redemptions.map((r: any) => (
                <tr key={r.id}><td className="text-xs">{fmt.date(r.created_at)}</td><td>{r.company?.name ?? r.email ?? '—'}</td><td>{r.purpose}</td>
                  <td>₹{fmt.number(Math.round(Number(r.subtotal)))}</td><td className="text-amber-600">-₹{fmt.number(Math.round(Number(r.discount)))}</td><td>₹{fmt.number(Math.round(Number(r.final_amount)))}</td></tr>))}
                {!usage.redemptions.length && <tr><td colSpan={6} className="py-6 text-center text-sm text-gray-500">Not used yet.</td></tr>}</tbody></table></div>
          </div>)}
      </Modal>
    </div>
  )
}
