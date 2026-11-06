// Node-based Instagram flows: list, create, switch on/off. The canvas lives in InstagramFlowBuilderPage.
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button, Input, Badge, EmptyState, Modal, ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { instagramApi, type IgAccount, type IgFlowSummary } from './api/instagram'

export default function InstagramFlowsPage() {
  const navigate = useNavigate()
  const [accounts, setAccounts] = useState<IgAccount[]>([])
  const [accountId, setAccountId] = useState<number | null>(null)
  const [flows, setFlows] = useState<IgFlowSummary[]>([])
  const [loading, setLoading] = useState(true)
  const [creating, setCreating] = useState(false)
  const [name, setName] = useState('')
  const [deleteId, setDeleteId] = useState<number | null>(null)

  const load = async (accId?: number) => {
    setLoading(true)
    try {
      const accs: IgAccount[] = (await instagramApi.accounts()).data.accounts ?? []
      setAccounts(accs)
      const active = accId ?? accountId ?? accs[0]?.id ?? null
      setAccountId(active)
      if (active) setFlows((await instagramApi.flows(active)).data.flows ?? [])
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const create = async () => {
    if (!accountId || !name.trim()) return
    try {
      const r = await instagramApi.createFlow({ instagram_account_id: accountId, name: name.trim() })
      navigate(`/instagram/flows/${r.data.flow.id}`)
    } catch (e) { toast.error(getError(e)) }
  }

  const toggle = async (f: IgFlowSummary) => {
    try {
      await instagramApi.toggleFlow(f.id)
      setFlows(list => list.map(x => x.id === f.id ? { ...x, is_active: !x.is_active } : x))
    } catch (e: any) {
      const errs: string[] | undefined = e?.response?.data?.errors
      toast.error(errs?.length ? errs[0] : getError(e))
    }
  }

  const remove = async () => {
    if (!deleteId) return
    try { await instagramApi.deleteFlow(deleteId); toast.success('Flow deleted.'); setDeleteId(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h1 className="page-title">Instagram Flows</h1>
          <p className="page-sub">
            Build comment → reply → DM → follower-check journeys on a canvas. Flows run before the simple Auto-DM rules.
          </p>
        </div>
        {accounts.length > 1 && (
          <select className="select max-w-[180px]" value={accountId ?? ''} onChange={e => { setAccountId(+e.target.value); void load(+e.target.value) }}>
            {accounts.map(a => <option key={a.id} value={a.id}>@{a.username}</option>)}
          </select>
        )}
        {accountId && <Button onClick={() => { setName(''); setCreating(true) }}>+ New flow</Button>}
      </div>

      {loading ? (
        <p className="text-sm text-gray-400">Loading…</p>
      ) : !accountId ? (
        <EmptyState icon="📸" title="Connect an Instagram account first" desc="Flows run on a connected account" />
      ) : flows.length === 0 ? (
        <EmptyState icon="🧩" title="No flows yet" desc={'e.g. comment "price" → public reply → DM with a button → check they follow you → send the price list'}
          action={<Button onClick={() => setCreating(true)}>Create your first flow</Button>} />
      ) : (
        <div className="grid gap-3">
          {flows.map(f => (
            <div key={f.id} className="card p-4 flex items-center gap-3">
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2">
                  <button onClick={() => navigate(`/instagram/flows/${f.id}`)} className="font-medium text-gray-900 hover:underline truncate">{f.name}</button>
                  <Badge variant={f.is_active ? 'green' : 'gray'}>{f.is_active ? 'On' : 'Off'}</Badge>
                  <Badge variant="blue">{f.trigger_type === 'comment' ? 'Comment' : 'DM keyword'}</Badge>
                </div>
                <p className="text-xs text-gray-500 mt-1">
                  Started {f.triggered_count}×{(f.waiting_runs_count ?? 0) > 0 && ` · ${f.waiting_runs_count} waiting on a reply`}
                </p>
              </div>
              <div className="flex gap-3 text-xs whitespace-nowrap">
                <button onClick={() => toggle(f)} className="text-gray-500 hover:underline">{f.is_active ? 'Turn off' : 'Turn on'}</button>
                <button onClick={() => navigate(`/instagram/flows/${f.id}`)} className="text-brand-600 hover:underline">Open builder</button>
                <button onClick={() => setDeleteId(f.id)} className="text-red-500 hover:underline">Delete</button>
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={creating} onClose={() => setCreating(false)} title="New flow"
        footer={<div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setCreating(false)}>Cancel</Button>
          <Button onClick={create} disabled={!name.trim()}>Create &amp; open builder</Button>
        </div>}>
        <Input label="Flow name *" value={name} onChange={e => setName(e.target.value)} placeholder="Reel → price list (followers only)" />
      </Modal>

      <ConfirmModal open={deleteId !== null} title="Delete flow?" message="People already mid-flow will stop receiving its messages."
        confirmLabel="Delete" onConfirm={remove} onCancel={() => setDeleteId(null)} />
    </div>
  )
}
