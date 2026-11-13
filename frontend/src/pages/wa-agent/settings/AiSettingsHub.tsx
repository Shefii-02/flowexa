// AI Agent → Settings: provider & keys and conversation intelligence (formerly the separate "AI Config" page) in one place.
import VerticalTabs, { type VTab } from '@/components/layout/VerticalTabs'
import ProviderSettings from './index'
import MetaAiConfigPage from '../meta-ai-config'

const TABS: VTab[] = [
  { id: 'provider',     icon: '🔑', label: 'AI provider & keys',   desc: 'Which AI the agents run on, and your API keys' },
  { id: 'intelligence', icon: '🧠', label: 'Conversation intelligence', desc: 'Sentiment, buying signals, lead scoring and context' },
]

export default function AiSettingsHub() {
  return (
    <div className="p-4">
      <VerticalTabs tabs={TABS} defaultId="provider" backLabel="AI Agent settings" showTitle={false}>
        {(id) => (id === 'intelligence' ? <MetaAiConfigPage /> : <ProviderSettings />)}
      </VerticalTabs>
    </div>
  )
}
