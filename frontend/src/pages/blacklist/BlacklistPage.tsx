// src/pages/blacklist/BlacklistPage.tsx
import { useEffect, useState, useCallback } from 'react'

import { Button, Input, Modal, Badge, EmptyState, Pagination, ConfirmModal } from '@/components/ui'
import { formatPhone, getError, fmt } from '@/utils'
import toast from 'react-hot-toast'
import { blacklistApi } from '@/api'

export default function BlacklistPage() {
  const [list,      setList]      = useState<any[]>([])
  const [total,     setTotal]     = useState(0)
  const [page,      setPage]      = useState(1)
  const [search,    setSearch]    = useState('')
  const [showAdd,   setShowAdd]   = useState(false)
  const [showImport,setShowImport]= useState(false)
  const [delItem,   setDelItem]   = useState<any>(null)
  const [saving,    setSaving]    = useState(false)
  const [importing, setImporting] = useState(false)
  const [csvFile,   setCsvFile]   = useState<File | null>(null)
  const [checkPhone,setCheckPhone]= useState('')
  const [checkResult,setCheckResult] = useState<{blocked: boolean; phone: string} | null>(null)
  const [form, setForm] = useState({ phone: '', reason: '' })

  const load = useCallback(() => {
    blacklistApi.list({ page, search: search || undefined, per_page: 20 })
      .then(r => { setList(r.data.data); setTotal(r.data.total) })
  }, [page, search])

  useEffect(() => { load() }, [load])

  const handleAdd = async () => {
    setSaving(true)
    try {
      await blacklistApi.add(form.phone, form.reason)
      toast.success('Number blacklisted.')
      setShowAdd(false); setForm({ phone: '', reason: '' }); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const handleImport = async () => {
    if (!csvFile) return
    setImporting(true)
    try {
      const { data } = await blacklistApi.import(csvFile)
      toast.success(data.message)
      setShowImport(false); setCsvFile(null); load()
    } catch (e) { toast.error(getError(e)) }
    finally     { setImporting(false) }
  }

  const handleRemove = async () => {
    try { await blacklistApi.remove(delItem.id); toast.success('Removed.'); setDelItem(null); load() }
    catch (e) { toast.error(getError(e)) }
  }

  const handleCheck = async () => {
    if (!checkPhone) return
    try {
      const { data } = await blacklistApi.check(checkPhone)
      setCheckResult(data)
    } catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Blacklist</h1><p className="page-sub">Blocked phone numbers — no messages sent</p></div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setShowImport(true)}>↑ Import CSV</Button>
          <Button onClick={() => setShowAdd(true)}>+ Block number</Button>
        </div>
      </div>

      {/* Quick check */}
      <div className="card p-4">
        <p className="text-sm font-medium text-gray-700 mb-3">Check if a number is blocked</p>
        <div className="flex gap-2">
          <Input placeholder="918086544828" value={checkPhone} onChange={e => setCheckPhone(e.target.value)} className="max-w-xs" />
          <Button variant="secondary" onClick={handleCheck}>Check</Button>
        </div>
        {checkResult && (
          <div className={`mt-3 px-4 py-2 rounded-lg text-sm font-medium inline-flex items-center gap-2 ${checkResult.blocked ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>
            {checkResult.blocked ? '🚫 Blocked' : '✅ Not blocked'} — {formatPhone(checkResult.phone)}
          </div>
        )}
      </div>

      <div className="card">
        <div className="card-header">
          <Input placeholder="Search numbers..." value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} className="max-w-xs" />
          <p className="text-sm text-gray-500 ml-auto">{fmt.number(total)} blocked numbers</p>
        </div>

        {list.length === 0 ? (
          <EmptyState icon="🚫" title="No blocked numbers" desc="Numbers you block will not receive any messages from your campaigns or flows" />
        ) : (
          <>
            <div className="table-wrapper">
              <table className="table">
                <thead><tr><th>Phone</th><th>Reason</th><th>Blocked by</th><th>Date</th><th></th></tr></thead>
                <tbody>
                  {list.map((b: any) => (
                    <tr key={b.id}>
                      <td className="font-mono text-sm">{formatPhone(b.phone)}</td>
                      <td className="text-gray-500 text-sm">{b.reason || '—'}</td>
                      <td className="text-gray-500 text-xs">{b.creator?.name || 'System'}</td>
                      <td className="text-xs text-gray-400">{fmt.relative(b.created_at)}</td>
                      <td><button onClick={() => setDelItem(b)} className="text-xs text-red-500 hover:underline">Remove</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={page} lastPage={Math.ceil(total / 20)} total={total} perPage={20} onChange={setPage} />
          </>
        )}
      </div>

      {/* Add modal */}
      <Modal open={showAdd} onClose={() => setShowAdd(false)} title="Block phone number" size="sm"
        footer={<><Button variant="secondary" onClick={() => setShowAdd(false)}>Cancel</Button><Button onClick={handleAdd} loading={saving}>Block number</Button></>}>
        <Input label="Phone number *" placeholder="918086544828" value={form.phone} onChange={e => setForm(f => ({ ...f, phone: e.target.value }))} />
        <div className="mt-3"><label className="label">Reason (optional)</label>
          <input className="input" value={form.reason} onChange={e => setForm(f => ({ ...f, reason: e.target.value }))} placeholder="Spam, opted out, etc." />
        </div>
        <p className="text-xs text-gray-400 mt-2">Messages to this number will be silently skipped. No wallet deduction for blocked numbers.</p>
      </Modal>

      {/* Import modal */}
      <Modal open={showImport} onClose={() => setShowImport(false)} title="Import blacklist CSV" size="sm"
        footer={<><Button variant="secondary" onClick={() => setShowImport(false)}>Cancel</Button><Button onClick={handleImport} loading={importing} disabled={!csvFile}>Import</Button></>}>
        <div className="bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl p-6 text-center">
          <input type="file" accept=".csv,.txt" onChange={e => setCsvFile(e.target.files?.[0] || null)} className="hidden" id="bl-csv" />
          <label htmlFor="bl-csv" className="cursor-pointer">
            <p className="text-2xl mb-2">📂</p>
            <p className="text-sm font-medium text-gray-700">{csvFile ? csvFile.name : 'Click to select CSV'}</p>
            <p className="text-xs text-gray-400 mt-1">CSV with phone numbers in first column</p>
          </label>
        </div>
      </Modal>

      <ConfirmModal open={!!delItem} title="Remove from blacklist?"
        message={`Allow messages to ${formatPhone(delItem?.phone || '')} again?`}
        onConfirm={handleRemove} onCancel={() => setDelItem(null)} danger={false} />
    </div>
  )
}
