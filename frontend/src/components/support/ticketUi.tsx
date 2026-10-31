// Shared bits of the support-ticket screens (company side and platform side).
import { useRef, useState } from 'react'
import { Badge, Button, Textarea } from '@/components/ui'
import toast from 'react-hot-toast'

export const PRIORITIES = [
  { value: 'low',    label: 'Low',    variant: 'gray' },
  { value: 'medium', label: 'Medium', variant: 'blue' },
  { value: 'high',   label: 'High',   variant: 'yellow' },
  { value: 'urgent', label: 'Urgent', variant: 'red' },
] as const

export const STATUSES = [
  { value: 'open',             label: 'Open',              variant: 'blue' },
  { value: 'in_progress',      label: 'In progress',       variant: 'purple' },
  { value: 'waiting_customer', label: 'Waiting for reply', variant: 'yellow' },
  { value: 'resolved',         label: 'Resolved',          variant: 'green' },
  { value: 'closed',           label: 'Closed',            variant: 'gray' },
] as const

export const PriorityBadge = ({ value }: { value: string }) => {
  const p = PRIORITIES.find((x) => x.value === value)
  return <Badge variant={(p?.variant ?? 'gray') as any}>{p?.label ?? value}</Badge>
}
export const StatusBadge = ({ value }: { value: string }) => {
  const s = STATUSES.find((x) => x.value === value)
  return <Badge variant={(s?.variant ?? 'gray') as any}>{s?.label ?? value}</Badge>
}

const size = (n: number) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

/** Save a downloaded blob (the file route needs the auth header, so a plain link would not work). */
export async function saveBlob(request: Promise<{ data: Blob }>, filename: string) {
  try {
    const { data } = await request
    const url = URL.createObjectURL(data)
    const a = document.createElement('a')
    a.href = url; a.download = filename; a.click()
    URL.revokeObjectURL(url)
  } catch { toast.error('Could not download the file.') }
}

export function TicketThread({ messages, onDownload }: { messages: any[]; onDownload: (att: any) => void }) {
  return (
    <div className="space-y-3">
      {messages.map((m) => m.sender_type === 'system' ? (
        <p key={m.id} className="text-center text-[11px] text-gray-400">{m.body} · {new Date(m.created_at).toLocaleString()}</p>
      ) : (
        <div key={m.id} className={`flex ${m.sender_type === 'company' ? 'justify-start' : 'justify-end'}`}>
          <div className={`max-w-[85%] rounded-xl px-3 py-2 text-sm border
            ${m.is_internal ? 'bg-amber-50 border-amber-200' : m.sender_type === 'company' ? 'bg-white border-gray-200' : 'bg-brand-50 border-brand-100'}`}>
            <p className="text-[11px] text-gray-500 mb-1">
              <b className="text-gray-700">{m.author}</b>{m.is_internal && ' · 🔒 internal note'} · {new Date(m.created_at).toLocaleString()}
            </p>
            <p className="whitespace-pre-wrap text-gray-800">{m.body}</p>
            {m.attachments?.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-2">
                {m.attachments.map((a: any) => (
                  <button key={a.id} onClick={() => onDownload(a)} className="text-xs px-2 py-1 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700">
                    📎 {a.filename} <span className="text-gray-400">({size(a.size)})</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>
      ))}
    </div>
  )
}

/** Reply box with attachments. `staff` adds the internal-note switch and a "set status" choice. */
export function TicketComposer({ onSend, staff, disabled, placeholder }: {
  onSend: (fd: FormData) => Promise<void>; staff?: boolean; disabled?: boolean; placeholder?: string
}) {
  const [body, setBody] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [internal, setInternal] = useState(false)
  const [status, setStatus] = useState('')
  const [busy, setBusy] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  const pick = (list: FileList | null) => {
    const next = [...files, ...Array.from(list ?? [])].slice(0, 5)
    if (next.some((f) => f.size > 5 * 1024 * 1024)) { toast.error('Each file must be 5 MB or smaller.'); return }
    setFiles(next)
  }

  const send = async () => {
    if (!body.trim()) return
    const fd = new FormData()
    fd.append('body', body.trim())
    files.forEach((f) => fd.append('files[]', f))
    if (staff) { fd.append('is_internal', internal ? '1' : '0'); if (status && !internal) fd.append('status', status) }
    setBusy(true)
    try { await onSend(fd); setBody(''); setFiles([]); setInternal(false); setStatus('') } finally { setBusy(false) }
  }

  return (
    <div className={`border rounded-xl p-3 space-y-2 ${internal ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white'}`}>
      <Textarea rows={3} value={body} onChange={(e) => setBody(e.target.value)} disabled={disabled}
        placeholder={internal ? 'Internal note — only platform staff can see this' : (placeholder ?? 'Write your reply…')} />
      {files.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {files.map((f, i) => (
            <span key={i} className="text-xs bg-gray-100 rounded-md px-2 py-1">
              📎 {f.name} <button className="text-gray-400 hover:text-red-500 ml-1" onClick={() => setFiles(files.filter((_, j) => j !== i))}>✕</button>
            </span>
          ))}
        </div>
      )}
      <div className="flex flex-wrap items-center gap-3">
        <input ref={input} type="file" multiple hidden onChange={(e) => { pick(e.target.files); e.target.value = '' }} />
        <button type="button" className="text-xs text-gray-600 hover:text-gray-900" disabled={files.length >= 5} onClick={() => input.current?.click()}>📎 Attach (max 5, 5 MB each)</button>
        {staff && (
          <>
            <label className="text-xs text-gray-600 flex items-center gap-1"><input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} /> Internal note</label>
            {!internal && (
              <select className="input !py-1 !text-xs w-44" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">After sending: waiting for customer</option>
                <option value="in_progress">After sending: in progress</option>
                <option value="resolved">After sending: resolved</option>
              </select>
            )}
          </>
        )}
        <div className="ml-auto"><Button loading={busy} disabled={disabled || !body.trim()} onClick={send}>{internal ? 'Add note' : 'Send reply'}</Button></div>
      </div>
    </div>
  )
}
