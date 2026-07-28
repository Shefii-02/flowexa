// src/pages/meta-ads/components/AudienceTemplatePicker.tsx
import { useEffect, useState } from 'react'

import { Modal, Badge, Input } from '@/components/ui'
import { fmt } from '@/utils'
import { metaAdsApi } from '../api/meta-ads';

interface AudienceTemplate { id: number; name: string; industry: string; description: string; objective: string; age_min: number; age_max: number; genders: string; targeting_json: any; suggested_daily_budget: number; estimated_reach_min: number; estimated_reach_max: number }

export const AudienceTemplatePicker = ({ onSelect }: { onSelect: (t: AudienceTemplate) => void }) => {
  const [open,      setOpen]      = useState(false)
  const [templates, setTemplates] = useState<AudienceTemplate[]>([])
  const [search,    setSearch]    = useState('')
  const [industry,  setIndustry]  = useState('')
  const industries = [...new Set(templates.map(t => t.industry).filter(Boolean))]

  useEffect(() => { metaAdsApi.audienceTemplates().then(r => setTemplates(r.data.templates)) }, [])

  const filtered = templates.filter(t =>
    (!search   || t.name.toLowerCase().includes(search.toLowerCase()) || t.industry?.toLowerCase().includes(search.toLowerCase())) &&
    (!industry || t.industry === industry)
  )

  const pick = (t: AudienceTemplate) => { onSelect(t); setOpen(false) }

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}
        className="w-full border border-dashed border-brand-300 rounded-xl p-4 text-center hover:bg-brand-50 transition-colors">
        <p className="text-sm font-medium text-brand-600">🎯 Choose pre-built audience template</p>
        <p className="text-xs text-gray-400 mt-1">20+ templates: Students, Job seekers, Real estate, Fitness and more</p>
      </button>

      <Modal open={open} onClose={() => setOpen(false)} title="Audience templates" size="xl">
        <div className="flex gap-3 mb-4">
          <Input placeholder="Search audiences..." value={search} onChange={e => setSearch(e.target.value)} className="flex-1" />
          <select className="select max-w-[160px]" value={industry} onChange={e => setIndustry(e.target.value)}>
            <option value="">All industries</option>
            {industries.map(i => <option key={i} value={i}>{i}</option>)}
          </select>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-[420px] overflow-y-auto">
          {filtered.map(t => (
            <div key={t.id} onClick={() => pick(t)}
              className="border border-gray-200 rounded-xl p-4 cursor-pointer hover:border-brand-400 hover:bg-brand-50 transition-all">
              <div className="flex items-start justify-between mb-2">
                <p className="font-medium text-gray-900 text-sm">{t.name}</p>
                <Badge variant="blue">{t.industry}</Badge>
              </div>
              <p className="text-xs text-gray-500 mb-2">{t.description}</p>
              <div className="flex gap-2 text-xs text-gray-400 flex-wrap">
                <span>👤 {t.age_min}–{t.age_max} yrs</span>
                <span>⚥ {t.genders === 'all' ? 'All genders' : t.genders}</span>
                {t.estimated_reach_max > 0 && <span>📊 {fmt.number(t.estimated_reach_min)}–{fmt.number(t.estimated_reach_max)} reach</span>}
                <span>💰 ₹{fmt.number(t.suggested_daily_budget)}/day suggested</span>
              </div>
              <p className="text-xs text-brand-600 mt-2 font-medium">Best for: {t.objective.replace(/_/g,' ').toLowerCase()}</p>
            </div>
          ))}
        </div>
      </Modal>
    </>
  )
}
