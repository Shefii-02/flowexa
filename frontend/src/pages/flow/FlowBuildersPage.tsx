// src/pages/flow/FlowBuildersPage.tsx
// Multiple flow builders — only one active at a time
// Season flows, keyword triggers, activate/switch

import { useEffect, useState, useCallback } from 'react'
import { flowBuilderApi } from '@/api'
import { Button, Input, Modal, ConfirmModal, Badge, EmptyState } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

const TRIGGER_TYPES = [
  { value:'default', label:'Default',  desc:'Active as fallback when no other flow matches', icon:'🌿' },
  { value:'keyword', label:'Keyword',  desc:'Triggered when customer sends a specific word', icon:'🔑' },
  { value:'season',  label:'Season',   desc:'Active only between specific start and end dates', icon:'📅' },
]

const DEFAULT_FORM = {
  name:'', description:'', trigger_type:'default',
  trigger_keywords:[] as Array<string>, active_from:'', active_until:'',
}

export default function FlowBuildersPage() {
  const [builders,  setBuilders]  = useState<any[]>([])
  const [loading,   setLoading]   = useState(true)
  const [showCreate,setShowCreate]= useState(false)
  const [editB,     setEditB]     = useState<any>(null)
  const [delB,      setDelB]      = useState<any>(null)
  const [activating,setActivating]= useState<number|null>(null)
  const [saving,    setSaving]    = useState(false)
  const [form,      setForm]      = useState(DEFAULT_FORM)
  const [kwInput,   setKwInput]   = useState('')
  const set = (k:string,v:any) => setForm(f=>({...f,[k]:v}))

  const load = useCallback(() => {
    setLoading(true)
    flowBuilderApi.list()
      .then(r => setBuilders(r.data.builders || []))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => { load() }, [load])

  const openCreate = () => { setEditB(null); setForm(DEFAULT_FORM); setKwInput(''); setShowCreate(true) }
  const openEdit   = (b:any) => {
    setEditB(b)
    setForm({
      name: b.name, description: b.description||'',
      trigger_type: b.trigger_type||'default',
      trigger_keywords: b.trigger_keywords||[],
      active_from: b.active_from?.slice(0,16)||'',
      active_until: b.active_until?.slice(0,16)||'',
    })
    setKwInput('')
    setShowCreate(true)
  }

  const addKeyword = () => {
    const kw = kwInput.trim().toLowerCase()
    if (!kw) return
    if (form.trigger_keywords.includes(kw)) { toast.error('Keyword already added'); return }
    set('trigger_keywords', [...form.trigger_keywords, kw])
    setKwInput('')
  }

  const removeKeyword = (kw:string) =>
    set('trigger_keywords', form.trigger_keywords.filter((k:string) => k !== kw))

  const handleSave = async () => {
    if (!form.name.trim()) { toast.error('Name required'); return }
    if (form.trigger_type === 'keyword' && form.trigger_keywords.length === 0) {
      toast.error('Add at least one trigger keyword'); return
    }
    if (form.trigger_type === 'season' && (!form.active_from || !form.active_until)) {
      toast.error('Set active from and until dates for season flow'); return
    }
    setSaving(true)
    try {
      if (editB) { await flowBuilderApi.update(editB.id, form); toast.success('Flow builder updated.') }
      else       { await flowBuilderApi.create(form);           toast.success('Flow builder created.') }
      setShowCreate(false); load()
    } catch(e) { toast.error(getError(e)) }
    finally    { setSaving(false) }
  }

  const handleActivate = async (id:number) => {
    setActivating(id)
    try {
      await flowBuilderApi.activate(id)
      toast.success('Flow builder activated. This is now the active flow.')
      load()
    } catch(e) { toast.error(getError(e)) }
    finally    { setActivating(null) }
  }

   const handleDeactivate = async (id:number) => {
    setActivating(id)
    try {
      await flowBuilderApi.deactivate(id)
      toast.success('Flow builder deactivated.')
      load()
    } catch(e) { toast.error(getError(e)) }
    finally    { setActivating(null) }
  }

  const handleDelete = async () => {
    try {
      await flowBuilderApi.delete(delB.id)
      toast.success('Flow builder deleted.')
      setDelB(null); load()
    } catch(e) { toast.error(getError(e)) }
  }

  const activeBuilder = builders.find(b => b.is_active)

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">Flow Builders</h1>
          <p className="page-sub">{builders.length} builders — only 1 active at a time</p>
        </div>
        <Button onClick={openCreate}>+ New flow builder</Button>
      </div>

      {/* How it works */}
      <div className="bg-brand-50 border border-brand-200 rounded-xl p-4 text-sm text-brand-700 space-y-1">
        <p className="font-semibold">How flow priority works:</p>
        <div className="grid grid-cols-3 gap-3 mt-2 text-xs">
          {[
            ['🔑 Keyword flow','Customer sends matching keyword → triggers that flow immediately'],
            ['📅 Season flow','Active date range matches today → uses season flow over default'],
            ['🌿 Default flow','No other flow matched → uses the default active flow'],
          ].map(([t,d]) => (
            <div key={t} className="bg-white rounded-lg p-3 border border-brand-100">
              <p className="font-semibold mb-1">{t}</p>
              <p className="text-brand-600">{d}</p>
            </div>
          ))}
        </div>
      </div>

      {/* Currently active */}
      {/* {activeBuilder && (
        <div className="bg-green-50 border border-green-300 rounded-xl px-4 py-3 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-2.5 h-2.5 rounded-full bg-green-500 animate-pulse" />
            <div>
              <span className="font-semibold text-green-800">Active: {activeBuilder.name}</span>
              <span className="text-xs text-green-600 ml-2">({activeBuilder.trigger_type})</span>
            </div>
          </div>
          <a href="/flow" className="text-xs text-green-700 hover:underline font-medium">Edit nodes →</a>
        </div>
      )} */}

      {loading ? (
        <div className="card p-8 text-center text-gray-400">Loading...</div>
      ) : builders.length === 0 ? (
        <EmptyState icon="🌿" title="No flow builders" desc="Create your first WhatsApp chatbot flow"
          action={<Button onClick={openCreate}>Create flow builder</Button>} />
      ) : (
        <div className="space-y-3">
          {builders.map(b => (
            <div key={b.id} className={`card p-5 border-2 ${b.is_active ? 'border-green-300' : 'border-transparent'}`}>
              <div className="flex items-start justify-between gap-4">
                <div className="flex items-start gap-3 flex-1">
                  <div className={`w-10 h-10 rounded-xl flex items-center justify-center text-xl flex-shrink-0 ${
                    b.is_active ? 'bg-green-100' : 'bg-gray-100'
                  }`}>
                    {TRIGGER_TYPES.find(t => t.value === b.trigger_type)?.icon || '🌿'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h3 className="font-bold text-gray-900">{b.name}</h3>
                      {b.is_active && <Badge variant="green">● Active</Badge>}
                      <Badge variant="blue">{b.trigger_type}</Badge>
                    </div>
                    {b.description && <p className="text-xs text-gray-400 mt-0.5">{b.description}</p>}

                    {/* Keywords */}
                    
                    {b.trigger_type === 'keyword' && (b.trigger_keywords||[]).length > 0 && (
                      <div className="flex gap-1.5 flex-wrap mt-2">
                        {b.trigger_keywords.map((kw:string) => (
                          <span key={kw} className="bg-blue-50 text-blue-600 text-xs px-2 py-0.5 rounded-full border border-blue-200 font-mono">
                            {kw}
                          </span>
                        ))}
                      </div>
                    )}

                    {/* Season dates */}
                    {b.trigger_type === 'season' && (
                      <p className="text-xs text-gray-500 mt-1">
                        📅 {b.active_from?.slice(0,16)?.replace('T',' ')} → {b.active_until?.slice(0,16)?.replace('T',' ')}
                      </p>
                    )}

                    {/* Stats */}
                    <div className="flex gap-4 mt-2 text-xs text-gray-400">
                      <span>🔥 {b.total_sessions||0} sessions</span>
                      <span>🎯 {b.total_leads||0} leads</span>
                      <span>🌿 {b.nodes_count||0} nodes</span>
                      <span>Created {b.created_at?.slice(0,10)}</span>
                    </div>
                  </div>
                </div>

                {/* Actions */}
                <div className="flex gap-2 flex-wrap flex-shrink-0">
                  {!b.is_active && (
                    <Button
                      variant="secondary"
                      onClick={() => handleActivate(b.id)}
                      loading={activating === b.id}
                      className="text-xs"
                    >
                      ▶ Activate
                    </Button>
                  )}
                  {b.is_active && (
                    <Button
                      variant="secondary"
                      onClick={() => handleDeactivate(b.id)}
                      loading={activating === b.id}
                      className="text-xs"
                    >
                      ⏸ Deactivate
                    </Button>
                  )}
                  <a href={`/flow?builder=${b.id}`} className="btn btn-outline text-xs">Edit nodes</a>
                  <button onClick={() => openEdit(b)} className="text-xs text-blue-600 hover:underline px-1">Edit</button>
                  {!b.is_active && (
                    <button onClick={() => setDelB(b)} className="text-xs text-red-500 hover:underline px-1">Delete</button>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Create / Edit Modal */}
      <Modal
        open={showCreate}
        onClose={() => setShowCreate(false)}
        title={editB ? `Edit — ${editB.name}` : 'New flow builder'}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowCreate(false)}>Cancel</Button>
            <Button onClick={handleSave} loading={saving}>
              {editB ? 'Save changes' : 'Create flow builder'}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <Input
              label="Flow builder name *"
              placeholder="Onam Special 2024"
              value={form.name}
              onChange={e => set('name', e.target.value)}
              className="col-span-2"
            />
            <Input
              label="Description"
              placeholder="Seasonal flow for Onam offers"
              value={form.description}
              onChange={e => set('description', e.target.value)}
              className="col-span-2"
            />
          </div>

          {/* Trigger type */}
          <div>
            <label className="label">Trigger type *</label>
            <div className="grid grid-cols-3 gap-2">
              {TRIGGER_TYPES.map(t => (
                <button
                  key={t.value}
                  type="button"
                  onClick={() => set('trigger_type', t.value)}
                  className={`p-3 rounded-xl border text-left transition-all ${
                    form.trigger_type === t.value
                      ? 'border-brand-500 bg-brand-50'
                      : 'border-gray-200 hover:border-gray-300'
                  }`}
                >
                  <div className="text-xl mb-1">{t.icon}</div>
                  <div className="text-sm font-semibold">{t.label}</div>
                  <div className="text-xs text-gray-400 mt-0.5">{t.desc}</div>
                </button>
              ))}
            </div>
          </div>

          {/* Keywords — shown for keyword type */}
          {form.trigger_type === 'keyword' && (
            <div>
              <label className="label">Trigger keywords *</label>
              <p className="text-xs text-gray-400 mb-2">Customer sending any of these words will trigger this flow</p>
              <div className="flex gap-2">
                <Input
                  placeholder="e.g. onam, offer, promo"
                  value={kwInput}
                  onChange={e => setKwInput(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && addKeyword()}
                />
                <Button variant="secondary" onClick={addKeyword}>Add</Button>
              </div>
              {form.trigger_keywords.length > 0 && (
                <div className="flex gap-2 flex-wrap mt-2">
                  {form.trigger_keywords.map((kw:string) => (
                    <span key={kw} className="inline-flex items-center gap-1 bg-blue-50 text-blue-700 text-xs px-2.5 py-1 rounded-full border border-blue-200 font-mono">
                      {kw}
                      <button onClick={() => removeKeyword(kw)} className="hover:text-blue-900 ml-0.5">×</button>
                    </span>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Season dates — shown for season type */}
          {form.trigger_type === 'season' && (
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="label">Active from *</label>
                <input
                  type="datetime-local"
                  className="form-control"
                  value={form.active_from}
                  onChange={e => set('active_from', e.target.value)}
                />
              </div>
              <div>
                <label className="label">Active until *</label>
                <input
                  type="datetime-local"
                  className="form-control"
                  value={form.active_until}
                  onChange={e => set('active_until', e.target.value)}
                />
              </div>
              <div className="col-span-2 bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
                ⏰ Outside this date range, the default active flow will be used instead.
              </div>
            </div>
          )}

          {/* Info */}
          {form.trigger_type === 'default' && (
            <div className="bg-brand-50 border border-brand-200 rounded-lg p-3 text-xs text-brand-700">
              🌿 This flow will be used as the fallback when no keyword or season flow matches. Only one default flow can be active at a time.
            </div>
          )}
        </div>
      </Modal>

      <ConfirmModal
        open={!!delB}
        title="Delete flow builder?"
        message={`Delete "${delB?.name}"? All ${delB?.nodes_count || 0} nodes in this builder will also be deleted. This cannot be undone.`}
        onConfirm={handleDelete}
        onCancel={() => setDelB(null)}
        confirmLabel="Delete builder"
        confirmVariant="danger"
      />
    </div>
  )
}