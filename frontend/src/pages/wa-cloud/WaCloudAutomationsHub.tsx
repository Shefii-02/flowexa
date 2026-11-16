import { useState } from 'react'
import { AiScheduleSection } from '@/components/ai/AiScheduleSection'
import AutomationAnalyticsTab from './AutomationAnalyticsTab'
import ReplyRoutingTab from './ReplyRoutingTab'
import { RulesTab } from './WaCloudAutomations'

type Tab = 'routing' | 'rules' | 'analytics' | 'schedule'

/** WA Cloud → Automation: reply routing (per number / all), rules that act, and the analytics behind both. */
export default function WaCloudAutomationsHub() {
  const [tab, setTab] = useState<Tab>('routing')
  const tabs: [Tab, string][] = [['routing', '🔀 Reply routing'], ['rules', '⚡ Rules'], ['analytics', '📊 Analytics'], ['schedule', '🕒 AI schedule']]
  return (
    <div className="p-6 max-w-6xl mx-auto">
      <div className="mb-5">
        <h1 className="text-xl font-bold text-gray-900">WA Cloud Automation</h1>
        <p className="text-sm text-gray-500 mt-1">Decide how every incoming WhatsApp message is answered — and watch how it performs.</p>
      </div>
      <div className="flex gap-1 border-b border-gray-200 mb-6 overflow-x-auto">
        {tabs.map(([id, label]) => (
          <button key={id} onClick={() => setTab(id)} className={`px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition-colors ${tab === id ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800'}`}>{label}</button>
        ))}
      </div>
      {tab === 'routing' && <ReplyRoutingTab />}
      {tab === 'rules' && <RulesTab />}
      {tab === 'analytics' && <AutomationAnalyticsTab />}
      {tab === 'schedule' && (
        <div className="bg-white border border-gray-200 rounded-xl p-4">
          <h2 className="text-sm font-semibold text-gray-900 mb-1">🕒 AI Schedule</h2>
          <p className="text-xs text-gray-500 mb-3">Always on, or only during set hours — per number. Used when no reply-routing rule matches.</p>
          <AiScheduleSection channel="wa_cloud" emptyText="No WhatsApp Cloud numbers connected yet." />
        </div>
      )}
    </div>
  )
}
