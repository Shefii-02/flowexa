// src/pages/meta-ads/AdsInsightsDashboard.tsx
import { useEffect, useState } from 'react'
import { metaAdsApi } from '../api/meta-ads'
import { StatCard, Spinner } from '@/components/ui'
import { fmt } from '@/utils'
import { LineChart, Line, BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts'

export default function AdsInsightsDashboard() {
  const [data,    setData]    = useState<any>(null)
  const [loading, setLoading] = useState(true)
  const [from,    setFrom]    = useState(() => new Date(Date.now() - 30*24*60*60*1000).toISOString().slice(0,10))
  const [to,      setTo]      = useState(() => new Date().toISOString().slice(0,10))
  const [metric,  setMetric]  = useState<'spend'|'clicks'|'leads'|'impressions'>('spend')
  const [campaigns,setCampaigns] = useState<any[]>([])
  const [selCampaign,setSelCampaign] = useState<number|null>(null)
  const [campaignData,setCampaignData] = useState<any>(null)

  const load = () => {
    setLoading(true)
    Promise.all([
      metaAdsApi.insightsOverview({ from, to }),
      metaAdsApi.campaigns({ per_page: 50 }),
    ]).then(([ins, c]) => { setData(ins.data); setCampaigns(c.data.data) })
    .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [])

  useEffect(() => {
    if (!selCampaign) { setCampaignData(null); return }
    metaAdsApi.campaignInsights(selCampaign, { from, to }).then(r => setCampaignData(r.data))
  }, [selCampaign, from, to])

  const summary = data?.summary
  const roas    = summary?.purchase_value > 0 && summary?.spend > 0
    ? (summary.purchase_value / summary.spend).toFixed(2) : '—'
  const ctr     = summary?.impressions > 0 ? ((summary.clicks / summary.impressions) * 100).toFixed(2) : '0'

  const daily   = (selCampaign ? campaignData?.insights : data?.daily) || []
  const chartData = daily.map((d: any) => ({
    date:        d.date?.slice(5),
    spend:       parseFloat(d.spend || 0),
    clicks:      parseInt(d.clicks || 0),
    leads:       parseInt(d.leads || 0),
    impressions: parseInt(d.impressions || 0),
  }))

  if (loading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>

  return (
    <div className="space-y-5">
      <div><h1 className="page-title">Ads Insights</h1><p className="page-sub">Performance across all Meta campaigns</p></div>

      {/* Filters */}
      <div className="flex gap-3 items-center flex-wrap">
        <div className="flex items-center gap-2 text-sm">
          <label className="text-gray-500">From</label>
          <input type="date" className="input max-w-[150px]" value={from} onChange={e => setFrom(e.target.value)} />
          <label className="text-gray-500">To</label>
          <input type="date" className="input max-w-[150px]" value={to} onChange={e => setTo(e.target.value)} />
        </div>
        <button onClick={load} className="btn btn-secondary btn-sm">Apply</button>
        <select className="select max-w-[200px]" value={selCampaign || ''} onChange={e => setSelCampaign(e.target.value ? +e.target.value : null)}>
          <option value="">All campaigns</option>
          {campaigns.map((c: any) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </div>

      {/* Summary stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Total spend"   value={`₹${fmt.number(Math.round(summary?.spend ?? 0))}`}       icon="💰" color="text-brand-600" />
        <StatCard label="Reach"         value={fmt.number(summary?.reach ?? 0)}                          icon="👁️" />
        <StatCard label="Clicks"        value={fmt.number(summary?.clicks ?? 0)}                         icon="🖱️" />
        <StatCard label="CTR"           value={`${ctr}%`}                                                icon="📊" />
        <StatCard label="Impressions"   value={fmt.number(summary?.impressions ?? 0)}                    icon="📺" />
        <StatCard label="Leads"         value={fmt.number(summary?.leads ?? 0)}                          icon="🎯" color="text-green-600" />
        <StatCard label="Purchases"     value={fmt.number(summary?.purchases ?? 0)}                      icon="🛒" />
        <StatCard label="ROAS"          value={typeof roas === 'string' ? roas : `${roas}x`}             icon="📈" color="text-green-600" />
      </div>

      {/* Chart */}
      <div className="card p-5">
        <div className="flex items-center justify-between mb-4">
          <h3 className="card-title">Daily performance</h3>
          <div className="flex gap-2">
            {(['spend','clicks','leads','impressions'] as const).map(m => (
              <button key={m} onClick={() => setMetric(m)}
                className={`px-3 py-1 text-xs rounded-lg border transition-colors capitalize ${metric===m ? 'bg-brand-500 text-white border-brand-500' : 'border-gray-200 text-gray-500 hover:border-brand-300'}`}>
                {m}
              </button>
            ))}
          </div>
        </div>
        {chartData.length > 0 ? (
          <ResponsiveContainer width="100%" height={260}>
            <LineChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip formatter={(v: any) => metric === 'spend' ? `₹${fmt.number(v)}` : fmt.number(v)} />
              <Line type="monotone" dataKey={metric} stroke="#1D9E75" strokeWidth={2} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        ) : (
          <div className="h-[260px] flex items-center justify-center text-gray-400 text-sm">No data for selected period</div>
        )}
      </div>

      {/* Spend vs Clicks */}
      {chartData.length > 0 && (
        <div className="card p-5">
          <h3 className="card-title mb-4">Spend vs Leads</h3>
          <ResponsiveContainer width="100%" height={220}>
            <BarChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} />
              <YAxis yAxisId="left" tick={{ fontSize: 11 }} />
              <YAxis yAxisId="right" orientation="right" tick={{ fontSize: 11 }} />
              <Tooltip />
              <Legend />
              <Bar yAxisId="left"  dataKey="spend" fill="#1D9E75" name="Spend (₹)" radius={[4,4,0,0]} />
              <Bar yAxisId="right" dataKey="leads" fill="#3b82f6" name="Leads"     radius={[4,4,0,0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}

      {/* Campaign breakdown */}
      {!selCampaign && campaigns.length > 0 && (
        <div className="card">
          <div className="card-header"><h3 className="card-title">Campaign breakdown</h3></div>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Campaign</th><th>Objective</th><th>Status</th><th>Spend</th><th>Clicks</th><th>Leads</th><th>ROAS</th></tr></thead>
              <tbody>
                {campaigns.map((c: any) => (
                  <tr key={c.id}>
                    <td>
                      <button onClick={() => setSelCampaign(c.id)} className="font-medium text-brand-600 hover:underline text-left">
                        {c.name}
                      </button>
                    </td>
                    <td className="text-xs text-gray-500">{c.objective?.replace(/_/g,' ')}</td>
                    <td><span className={`text-xs font-medium ${c.status==='ACTIVE'?'text-green-600':'text-gray-400'}`}>{c.status}</span></td>
                    <td className="text-brand-600 font-medium">—</td>
                    <td>—</td><td>—</td><td>—</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
