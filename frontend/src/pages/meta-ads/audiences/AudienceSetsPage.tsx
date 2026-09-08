// Audience Sets — the platform USP: save an audience once, then reuse it in one click on any
// ad set, or open a copy and tweak it. Also seeds from the system starter templates.
import { useEffect, useMemo, useState } from 'react'
import { Button, Badge, EmptyState, Modal, ConfirmModal, Input } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { metaAdsApi } from '../api/meta-ads'
import { AudienceSetForm } from './AudienceSetForm'
import { emptyAudienceDraft, toDraft, type AudienceSet, type AudienceSetDraft, type AudienceTemplate } from './types'

type EditorState =
  | { mode: 'closed' }
  | { mode: 'create'; draft: AudienceSetDraft }
  | { mode: 'edit'; id: number; draft: AudienceSetDraft }

export default function AudienceSetsPage() {
  const [sets, setSets] = useState<AudienceSet[]>([])
  const [templates, setTemplates] = useState<AudienceTemplate[]>([])
  const [loading, setLoading] = useState(true)
  const [accountId, setAccountId] = useState<number | null>(null)
  const [editor, setEditor] = useState<EditorState>({ mode: 'closed' })
  const [saving, setSaving] = useState(false)
  const [deleteId, setDeleteId] = useState<number | null>(null)
  const [showTemplates, setShowTemplates] = useState(false)
  const [industry, setIndustry] = useState('')

  const load = async () => {
    setLoading(true)
    try {
      const [r, acc] = await Promise.all([metaAdsApi.audienceSets(true), metaAdsApi.accounts()])
      setSets(r.data.audience_sets ?? [])
      setTemplates(r.data.templates ?? [])
      const accounts = acc.data.accounts ?? []
      setAccountId((accounts.find((a: { is_default: boolean }) => a.is_default) ?? accounts[0])?.id ?? null)
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  const industries = useMemo(() => [...new Set(templates.map(t => t.industry).filter(Boolean))] as string[], [templates])
  const shownTemplates = industry ? templates.filter(t => t.industry === industry) : templates

  const save = async () => {
    if (editor.mode === 'closed') return
    if (!editor.draft.name.trim()) { toast.error('Give the audience a name.'); return }
    setSaving(true)
    try {
      if (editor.mode === 'create') {
        await metaAdsApi.createAudienceSet(editor.draft as unknown as Record<string, unknown>)
        toast.success('Audience set saved.')
      } else {
        await metaAdsApi.updateAudienceSet(editor.id, editor.draft as unknown as Record<string, unknown>)
        toast.success('Audience set updated.')
      }
      setEditor({ mode: 'closed' })
      void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const openEdit = async (s: AudienceSet) => {
    try {
      const r = await metaAdsApi.audienceSet(s.id)
      setEditor({ mode: 'edit', id: s.id, draft: toDraft(r.data.audience_set) })
    } catch (e) { toast.error(getError(e)) }
  }

  const duplicate = async (s: AudienceSet) => {
    try { await metaAdsApi.duplicateAudienceSet(s.id); toast.success('Copy created.'); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const fromTemplate = async (t: AudienceTemplate) => {
    try {
      const r = await metaAdsApi.audienceSetFromTemplate(t.id)
      setShowTemplates(false)
      setEditor({ mode: 'edit', id: r.data.audience_set.id, draft: toDraft(r.data.audience_set) })
      toast.success(`Started from "${t.name}" — customise and save.`)
      void load()
    } catch (e) { toast.error(getError(e)) }
  }

  const toggleFavorite = async (s: AudienceSet) => {
    try { await metaAdsApi.updateAudienceSet(s.id, { is_favorite: !s.is_favorite }); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const remove = async () => {
    if (!deleteId) return
    try { await metaAdsApi.deleteAudienceSet(deleteId); toast.success('Deleted.'); setDeleteId(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h1 className="page-title">Audience Sets</h1>
          <p className="page-sub">Save your targeting once — reuse it in one click, or open a copy to tweak</p>
        </div>
        <Button variant="secondary" onClick={() => setShowTemplates(true)}>Start from template</Button>
        <Button onClick={() => setEditor({ mode: 'create', draft: emptyAudienceDraft() })}>+ New audience</Button>
      </div>

      {loading ? (
        <div className="text-sm text-gray-400">Loading…</div>
      ) : sets.length === 0 ? (
        <EmptyState
          icon="🎯"
          title="No saved audiences yet"
          desc="Build one now, or start from a ready-made template for your industry"
          action={<Button onClick={() => setShowTemplates(true)}>Browse templates</Button>}
        />
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {sets.map(s => (
            <div key={s.id} className="card p-4 flex flex-col">
              <div className="flex items-start justify-between gap-2">
                <p className="font-medium text-gray-900 text-sm">{s.name}</p>
                <button onClick={() => toggleFavorite(s)} title="Favourite" className={s.is_favorite ? 'text-amber-400' : 'text-gray-300 hover:text-amber-400'}>★</button>
              </div>
              {s.description && <p className="text-xs text-gray-500 mt-0.5 line-clamp-2">{s.description}</p>}
              <div className="flex flex-wrap gap-1.5 mt-2 text-xs text-gray-400">
                <span>👤 {s.age_min}–{s.age_max}</span>
                <span>⚥ {s.genders === 'all' ? 'All' : s.genders === 'male' ? 'Men' : 'Women'}</span>
                {(s.interests?.length ?? 0) > 0 && <span>❤ {s.interests.length} interests</span>}
                {s.use_count > 0 && <span>♻ used {s.use_count}×</span>}
              </div>
              {s.reach_max ? (
                <p className="text-xs text-brand-600 mt-2">≈ {fmt.number(s.reach_min ?? 0)}–{fmt.number(s.reach_max)} reach</p>
              ) : null}
              <div className="flex-1" />
              <div className="flex items-center gap-3 mt-3 pt-2 border-t border-gray-100 text-xs">
                <button onClick={() => openEdit(s)} className="text-brand-600 hover:underline">Edit</button>
                <button onClick={() => duplicate(s)} className="text-gray-500 hover:underline">Duplicate</button>
                <button onClick={() => setDeleteId(s.id)} className="text-red-500 hover:underline ml-auto">Delete</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Editor */}
      <Modal
        open={editor.mode !== 'closed'}
        onClose={() => setEditor({ mode: 'closed' })}
        title={editor.mode === 'edit' ? 'Edit audience set' : 'New audience set'}
        size="xl"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setEditor({ mode: 'closed' })}>Cancel</Button>
            <Button onClick={save} loading={saving}>{editor.mode === 'edit' ? 'Save changes' : 'Save audience'}</Button>
          </div>
        }
      >
        {editor.mode !== 'closed' && (
          <AudienceSetForm
            value={editor.draft}
            onChange={draft => setEditor({ ...editor, draft } as EditorState)}
            accountId={accountId}
          />
        )}
      </Modal>

      {/* Template gallery */}
      <Modal open={showTemplates} onClose={() => setShowTemplates(false)} title="Audience templates" size="xl">
        <div className="flex gap-3 mb-4">
          <Input placeholder="Filter…" value={industry} readOnly className="hidden" />
          <select className="select max-w-[200px]" value={industry} onChange={e => setIndustry(e.target.value)}>
            <option value="">All industries</option>
            {industries.map(i => <option key={i} value={i}>{i}</option>)}
          </select>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-[460px] overflow-y-auto">
          {shownTemplates.map(t => (
            <button key={t.id} type="button" onClick={() => fromTemplate(t)}
              className="text-left border border-gray-200 rounded-xl p-4 hover:border-brand-400 hover:bg-brand-50 transition-all">
              <div className="flex items-start justify-between mb-1">
                <p className="font-medium text-gray-900 text-sm">{t.name}</p>
                {t.industry && <Badge variant="blue">{t.industry}</Badge>}
              </div>
              <p className="text-xs text-gray-500 mb-2">{t.description}</p>
              <div className="flex gap-2 text-xs text-gray-400 flex-wrap">
                <span>👤 {t.age_min}–{t.age_max}</span>
                <span>💰 ₹{fmt.number(t.suggested_daily_budget)}/day</span>
                {t.estimated_reach_max > 0 && <span>📊 {fmt.number(t.estimated_reach_min)}–{fmt.number(t.estimated_reach_max)}</span>}
              </div>
            </button>
          ))}
        </div>
      </Modal>

      <ConfirmModal
        open={deleteId !== null}
        title="Delete audience set?"
        message="Ad sets already using it keep their targeting. This only removes the saved definition."
        onConfirm={remove}
        onCancel={() => setDeleteId(null)}
      />
    </div>
  )
}
