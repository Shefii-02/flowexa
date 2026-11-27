// A lead's WhatsApp campaign history: how many campaigns were sent to this number, how many got a reply, and each campaign.
import { useEffect, useState } from 'react'
import { leadActivityApi } from '@/api'
import { Spinner } from '@/components/ui'
import { fmt, getError } from '@/utils'

const STATUS: Record<string, string> = { sent: 'bg-blue-50 text-blue-700', delivered: 'bg-green-50 text-green-700', read: 'bg-emerald-50 text-emerald-700', failed: 'bg-red-50 text-red-700', pending: 'bg-gray-100 text-gray-600' }

export default function LeadCampaigns({ leadId }: { leadId: number }) {
  const [data, setData] = useState<any>(null)
  const [err, setErr] = useState('')
  useEffect(() => {
    setData(null); setErr('')
    leadActivityApi.campaigns(leadId).then(r => setData(r.data)).catch(e => setErr(getError(e)))
  }, [leadId])

  if (err) return <p className="card p-5 text-sm text-red-600">{err}</p>
  if (!data) return <div className="card flex justify-center p-10"><Spinner /></div>
  const s = data.summary ?? {}
  const items: any[] = data.items ?? []
  const stat: [string, string | number, string?][] = [
    ['Campaigns sent to this number', s.campaigns_sent ?? 0], ['Messages sent', s.messages_sent ?? 0], ['Delivered', s.delivered ?? 0], ['Read', s.read ?? 0],
    ['Campaigns responded', s.responded ?? 0, 'text-green-700'], ['Response rate', `${s.response_rate ?? 0}%`],
  ]
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {stat.map(([l, v, c]) => (
          <div key={l} className="card p-4"><div className="text-[11px] leading-tight text-gray-500">{l}</div><div className={`mt-1 text-2xl font-bold ${c ?? 'text-gray-900'}`}>{v}</div></div>
        ))}
      </div>
      <div className="card p-5">
        <div className="mb-3 flex items-center justify-between">
          <p className="text-sm font-semibold text-gray-800">Campaign history</p>
          {s.last_sent_at && <span className="text-xs text-gray-400">Last sent {fmt.relative(s.last_sent_at)}</span>}
        </div>
        {items.length === 0 ? <p className="py-6 text-center text-sm text-gray-400">No campaign has been sent to this contact yet.</p> : (
          <div className="overflow-x-auto"><table className="table">
            <thead><tr><th>Campaign</th><th>Sent</th><th>Status</th><th>Read</th><th>Replied</th></tr></thead>
            <tbody>{items.map(i => (
              <tr key={i.id}>
                <td className="font-medium text-gray-900">{i.name}{i.category && <span className="ml-2 text-[10px] uppercase text-gray-400">{i.category}</span>}</td>
                <td className="whitespace-nowrap text-xs text-gray-500">{i.sent_at ? fmt.date(i.sent_at) : '—'}</td>
                <td><span className={`badge ${STATUS[i.status] ?? STATUS.pending}`} title={i.failed_reason ?? ''}>{i.status}</span></td>
                <td className="text-xs text-gray-500">{i.read_at ? '✓ ' + fmt.relative(i.read_at) : '—'}</td>
                <td>{i.responded ? <span className="text-green-700">✓ {i.replies} repl{i.replies === 1 ? 'y' : 'ies'}<span className="ml-1 text-xs text-gray-400">first {fmt.relative(i.first_reply_at)}</span></span> : <span className="text-gray-300">—</span>}</td>
              </tr>
            ))}</tbody></table></div>
        )}
        {data.note && <p className="mt-3 text-[11px] text-gray-400">{data.note}</p>}
      </div>
    </div>
  )
}
