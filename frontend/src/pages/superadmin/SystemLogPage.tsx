// src/pages/superadmin/SystemLogPage.tsx
// Raw Laravel log file viewer — tails storage/logs/*.log without shelling in.
import { useEffect, useState, useCallback } from 'react'
import { superadminApi } from '@/api'
import { Button, EmptyState, Spinner } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const levelColor = (entry: string) => {
  if (/\.ERROR:|\.CRITICAL:|\.EMERGENCY:|\.ALERT:/.test(entry)) return 'border-red-300 bg-red-50'
  if (/\.WARNING:/.test(entry)) return 'border-yellow-300 bg-yellow-50'
  return 'border-gray-200 bg-white'
}

export default function SystemLogPage() {
  const [files, setFiles] = useState<any[]>([])
  const [file, setFile] = useState('')
  const [lines, setLines] = useState(300)
  const [search, setSearch] = useState('')
  const [entries, setEntries] = useState<string[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    superadminApi.systemLogFiles().then((r) => {
      setFiles(r.data.files ?? [])
      if (r.data.files?.length) setFile(r.data.files[0].name)
    }).catch((e) => toast.error(getError(e)))
  }, [])

  const load = useCallback(() => {
    if (!file) return
    setLoading(true)
    superadminApi.systemLog({ file, lines, search: search || undefined })
      .then((r) => setEntries(r.data.entries ?? []))
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }, [file, lines, search])

  useEffect(() => { load() }, [load])

  return (
    <div className="space-y-5">
      <div>
        <h1 className="page-title">System Log</h1>
        <p className="page-sub">Raw laravel.log — the last {lines} entries, newest last.</p>
      </div>

      <div className="card">
        <div className="card-header gap-3 flex-wrap">
          <select className="select max-w-[200px]" value={file} onChange={(e) => setFile(e.target.value)}>
            {files.map((f) => <option key={f.name} value={f.name}>{f.name} ({Math.round(f.size / 1024)} KB)</option>)}
          </select>
          <select className="select max-w-[140px]" value={lines} onChange={(e) => setLines(Number(e.target.value))}>
            {[100, 300, 500, 1000, 2000].map((n) => <option key={n} value={n}>Last {n}</option>)}
          </select>
          <input className="input max-w-xs" placeholder="Search (e.g. ERROR, company id)..." value={search} onChange={(e) => setSearch(e.target.value)} />
          <Button variant="secondary" size="sm" onClick={load}>↻ Refresh</Button>
        </div>

        <div className="card-body">
          {loading ? (
            <div className="flex justify-center py-10"><Spinner /></div>
          ) : entries.length === 0 ? (
            <EmptyState icon="📜" title="No matching entries" />
          ) : (
            <div className="space-y-2 max-h-[70vh] overflow-y-auto">
              {entries.map((e, i) => (
                <pre key={i} className={`text-xs font-mono rounded-lg border p-3 overflow-x-auto whitespace-pre-wrap ${levelColor(e)}`}>{e}</pre>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
