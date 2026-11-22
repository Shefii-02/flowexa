import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ResponsiveContainer, AreaChart, Area, XAxis, YAxis, Tooltip, CartesianGrid } from 'recharts';
import api from '@/api/client';
import './DashboardExtras.css';

interface Extras {
  conversation: {
    received_today: number; sent_today: number; received_30d: number; sent_30d: number; contacts_today: number
    delivered_today: number; read_today: number; failed_today: number; delivery_rate: number | null; avg_delivery_seconds: number
    topics: { topic: string; label: string; count: number }[]
  }
  wallet: {
    balance: number; billing_mode: 'wallet' | 'included'; credits_per_message: number; charged_today: number; charged_month: number
    messages_billed_month: number; pending: number; unbilled: number; by_source: { source: string; messages: number; credits: number }[]
  }
  api: {
    services: number; requests_today: number; requests_30d: number; sent_30d: number; verified_30d: number; failed_30d: number
    avg_response_ms: number; billed_messages_30d: number; daily: { date: string; value: number }[]
  }
  campaigns: {
    running: number; scheduled: number; done_30d: number; messages_sent_30d: number; messages_failed_30d: number
    recent: { id: number; name: string; status: string; total: number; sent: number; failed: number; at: string }[]
  }
  activity: { kind: string; text: string; at: string }[]
  team: { id: number; name: string; campaigns: number; campaign_messages: number; leads: number; enrolled: number }[]
}

const n = (v: number) => v.toLocaleString();
const ago = (iso: string) => {
  const s = (Date.now() - new Date(iso).getTime()) / 1000;
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return new Date(iso).toLocaleDateString();
};
const ICON: Record<string, string> = { failed_send: '⚠️', session: '📵', campaign: '📣', billing: '💳' };

function Tile({ label, value, detail, tone }: { label: string; value: string | number; detail?: string; tone?: 'good' | 'bad' | 'warn' }) {
  return (
    <div className="stat-card">
      <div className="stat-header"><span className="stat-label">{label}</span></div>
      <div className={`stat-value ${tone ? `dx-${tone}` : ''}`}>{typeof value === 'number' ? n(value) : value}</div>
      {detail && <div className="stat-detail">{detail}</div>}
    </div>
  );
}

/** The extra dashboard blocks, all from one Laravel call (GET /wa-chat/dashboard); a failure hides the section rather than breaking the page. */
export function DashboardExtras() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['wa-chat-dashboard-extras'],
    queryFn: async () => (await api.get<Extras>('/wa-chat/dashboard')).data,
    refetchInterval: 60_000,
    staleTime: 30_000,
  });
  if (isLoading || isError || !data) return null;
  const { conversation: c, wallet: w, api: a, campaigns: k, activity, team } = data;
  const included = w.billing_mode === 'included';

  return (
    <>
      <h2 className="dx-title">Conversations today</h2>
      <div className="stats-grid">
        <Tile label="Received" value={c.received_today} detail={`${n(c.received_30d)} in 30 days`} />
        <Tile label="Sent" value={c.sent_today} detail={`${n(c.sent_30d)} in 30 days`} />
        <Tile label="Contacts messaged" value={c.contacts_today} />
        <Tile label="Delivered" value={c.delivery_rate === null ? '—' : `${c.delivery_rate}%`} tone="good"
          detail={`${n(c.read_today)} read · ${n(c.failed_today)} failed${c.avg_delivery_seconds ? ` · ~${c.avg_delivery_seconds}s to deliver` : ''}`} />
      </div>
      {c.topics.some(t => t.count > 0) && (
        <section className="sessions-section dx-block">
          <div className="section-header"><h2>What chats are about</h2><span className="section-subtitle">AI + rule labels</span></div>
          <div className="dx-chips">{c.topics.map(t => <span key={t.topic} className="dx-chip">{t.label} <b>{n(t.count)}</b></span>)}</div>
        </section>
      )}

      <div className="dx-grid">
        <section className="sessions-section dx-block">
          <div className="section-header"><h2>Wallet & billing</h2><Link to="/plan-purchase?section=wallet" className="btn-sm">Open</Link></div>
          <div className="dx-kv"><span>Balance</span><b>{w.balance.toLocaleString(undefined, { maximumFractionDigits: 2 })}</b></div>
          <div className="dx-kv"><span>Billing</span><b>{included ? 'Included in plan' : `${w.credits_per_message} credit / delivered message`}</b></div>
          {!included && (<>
            <div className="dx-kv"><span>Charged today</span><b>{n(w.charged_today)}</b></div>
            <div className="dx-kv"><span>Charged this month</span><b>{n(w.charged_month)} <small>({n(w.messages_billed_month)} messages)</small></b></div>
            <div className="dx-kv"><span>Awaiting delivery</span><b>{n(w.pending)}</b></div>
            {w.unbilled > 0 && <div className="dx-kv"><span>Not billed (wallet empty)</span><b className="dx-bad">{n(w.unbilled)}</b></div>}
            {w.by_source.map(s => <div key={s.source} className="dx-kv dx-sub"><span>{s.source}</span><b>{n(s.credits)} credits · {n(s.messages)}</b></div>)}
          </>)}
        </section>

        <section className="sessions-section dx-block">
          <div className="section-header"><h2>API usage</h2><span className="section-subtitle">last 30 days</span></div>
          <div className="dx-kv"><span>Requests</span><b>{n(a.requests_30d)} <small>({n(a.requests_today)} today)</small></b></div>
          <div className="dx-kv"><span>Sent / verified</span><b>{n(a.sent_30d)} / {n(a.verified_30d)}</b></div>
          <div className="dx-kv"><span>Failed</span><b className={a.failed_30d ? 'dx-bad' : ''}>{n(a.failed_30d)}</b></div>
          <div className="dx-kv"><span>Avg response</span><b>{a.avg_response_ms ? `${n(a.avg_response_ms)} ms` : '—'}</b></div>
          <div className="dx-kv"><span>Services · billed messages</span><b>{n(a.services)} · {n(a.billed_messages_30d)}</b></div>
          <div style={{ width: '100%', height: 90, marginTop: 8 }}>
            <ResponsiveContainer>
              <AreaChart data={a.daily.map(d => ({ date: d.date.slice(5), requests: d.value }))}>
                <CartesianGrid strokeDasharray="3 3" stroke="#00000010" />
                <XAxis dataKey="date" tick={{ fontSize: 10 }} interval={6} />
                <YAxis hide allowDecimals={false} />
                <Tooltip />
                <Area type="monotone" dataKey="requests" stroke="#25d366" fill="#25d36633" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </section>
      </div>

      <section className="sessions-section dx-block">
        <div className="section-header">
          <h2>Campaigns</h2>
          <span className="section-subtitle">{k.running} running · {k.scheduled} scheduled · {k.done_30d} done (30 d) · {n(k.messages_sent_30d)} sent · {n(k.messages_failed_30d)} failed</span>
        </div>
        {k.recent.length === 0 ? <p className="dx-empty">No campaigns yet.</p> : (
          <table className="dx-table"><thead><tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Failed</th><th>When</th></tr></thead>
            <tbody>{k.recent.map(r => (
              <tr key={r.id}><td>{r.name}</td><td><span className={`status-pill ${r.status === 'running' ? 'ready' : r.status === 'stopped' ? 'failed' : 'disconnected'}`}>{r.status}</span></td>
                <td>{n(r.sent)} / {n(r.total)}</td><td>{n(r.failed)}</td><td>{ago(r.at)}</td></tr>
            ))}</tbody></table>
        )}
      </section>

      <div className="dx-grid">
        <section className="sessions-section dx-block">
          <div className="section-header"><h2>Recent activity</h2></div>
          {activity.length === 0 ? <p className="dx-empty">All quiet — no failures or session problems.</p> : (
            <ul className="dx-feed">{activity.map((x, i) => <li key={i}><span>{ICON[x.kind] ?? '•'}</span><span>{x.text}</span><small>{ago(x.at)}</small></li>)}</ul>
          )}
        </section>

        <section className="sessions-section dx-block">
          <div className="section-header"><h2>Team</h2><span className="section-subtitle">last 30 days</span></div>
          {team.length === 0 ? <p className="dx-empty">No team data.</p> : (
            <table className="dx-table"><thead><tr><th>Person</th><th>Campaigns</th><th>Msgs sent</th><th>Leads</th><th>Enrolled</th></tr></thead>
              <tbody>{team.map(p => <tr key={p.id}><td>{p.name}</td><td>{n(p.campaigns)}</td><td>{n(p.campaign_messages)}</td><td>{n(p.leads)}</td><td>{n(p.enrolled)}</td></tr>)}</tbody></table>
          )}
        </section>
      </div>
    </>
  );
}
