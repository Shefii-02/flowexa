// Account-level Instagram insights — reach, engagement, profile views, followers.
import { useEffect, useMemo, useState } from 'react'
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts'
import { StatCard, Spinner, EmptyState } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { instagramApi, type IgAccount } from './api/instagram'

interface InsightMetric {
  name: string
  title?: string
  period?: string
  values: { value: number; end_time: string }[]
}

const METRIC_LABEL: Record<string, string> = {
  reach:             'Reach',
  accounts_engaged:  'Accounts engaged',
  profile_views:     'Profile views',
  follower_count:    'Follower change',
}
const METRIC_ICON: Record<string, string> = {
  reach: '👁️', accounts_engaged: '🤝', profile_views: '👤', follower_count: '📈',
}

export default function InstagramInsightsPage() {
  const [accounts, setAccounts] = useState<IgAccount[]>([])
  const [accountId, setAccountId] = useState<number | null>(null)
  const [metrics, setMetrics] = useState<InsightMetric[] | null>(null)
  const [loading, setLoading] = useState(false)
  const [loadingAccounts, setLoadingAccounts] = useState(true)
  const [chartMetric, setChartMetric] = useState('reach')

  useEffect(() => {
    instagramApi.accounts()
      .then((r) => {
        const list: IgAccount[] = r.data.accounts ?? []
        setAccounts(list)
        if (list.length) setAccountId(list[0].id)
      })
      .catch((e) => toast.error(getError(e)))
      .finally(() => setLoadingAccounts(false))
  }, [])

  useEffect(() => {
    if (!accountId) return
    setLoading(true)
    instagramApi.accountInsights(accountId)
      .then((r) => setMetrics(r.data.insights?.data ?? []))
      .catch((e) => { toast.error(getError(e)); setMetrics([]) })
      .finally(() => setLoading(false))
  }, [accountId])

  const account = accounts.find((a) => a.id === accountId)

  const metricByName = useMemo(() => {
    const m: Record<string, InsightMetric> = {}
    metrics?.forEach((row) => { m[row.name] = row })
    return m
  }, [metrics])

  const summaryFor = (name: string) => {
    const values = metricByName[name]?.values ?? []
    return values.reduce((sum, v) => sum + (v.value ?? 0), 0)
  }

  const chartData = (metricByName[chartMetric]?.values ?? []).map((v) => ({
    date: new Date(v.end_time).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }),
    value: v.value,
  }))

  if (loadingAccounts) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>

  if (accounts.length === 0) {
    return <EmptyState icon="📊" title="Connect an Instagram account first"
      desc="Insights need instagram_manage_insights on a connected account." />
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h1 className="page-title">Insights</h1>
          <p className="page-sub">Reach, engagement and profile activity over the last 14 days</p>
        </div>
        {accounts.length > 1 && (
          <select className="select w-56" value={accountId ?? ''} onChange={(e) => setAccountId(+e.target.value)}>
            {accounts.map((a) => <option key={a.id} value={a.id}>@{a.username || a.ig_user_id}</option>)}
          </select>
        )}
      </div>

      {/* Account snapshot */}
      {account && (
        <div className="card p-5 flex items-center gap-4">
          {account.profile_picture_url
            ? <img src={account.profile_picture_url} alt="" className="w-14 h-14 rounded-full object-cover" />
            : <div className="w-14 h-14 rounded-full bg-gradient-to-br from-pink-500 to-orange-400 flex items-center justify-center text-white text-xl">📸</div>}
          <div>
            <p className="font-semibold text-gray-900">@{account.username || account.ig_user_id}</p>
            <p className="text-sm text-gray-500">{fmt.number(account.followers_count)} followers</p>
          </div>
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-12"><Spinner size="lg" /></div>
      ) : (
        <>
          {/* Metric summary cards */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <StatCard label="Followers" value={fmt.number(account?.followers_count ?? 0)} icon="👥" color="text-brand-600" />
            {(['reach', 'accounts_engaged', 'profile_views'] as const).map((name) => (
              <StatCard key={name} label={METRIC_LABEL[name]} value={fmt.number(summaryFor(name))} icon={METRIC_ICON[name]} />
            ))}
          </div>

          {/* Trend chart */}
          <div className="card p-5">
            <div className="flex items-center justify-between mb-4">
              <p className="text-sm font-semibold text-gray-800">Trend</p>
              <select className="select max-w-[200px]" value={chartMetric} onChange={(e) => setChartMetric(e.target.value)}>
                {Object.entries(METRIC_LABEL).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
              </select>
            </div>
            {chartData.length === 0 ? (
              <p className="text-sm text-gray-400 py-8 text-center">No data for this metric yet — Meta backfills a day or two after connecting.</p>
            ) : (
              <ResponsiveContainer width="100%" height={260}>
                <LineChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="date" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Line type="monotone" dataKey="value" stroke="#1D9E75" strokeWidth={2} dot={{ r: 3 }} name={METRIC_LABEL[chartMetric]} />
                </LineChart>
              </ResponsiveContainer>
            )}
          </div>
        </>
      )}
    </div>
  )
}
