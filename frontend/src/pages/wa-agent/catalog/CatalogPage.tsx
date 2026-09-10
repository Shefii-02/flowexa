// Listings & Product Management — the live catalog the AI agent answers about and matches leads
// against (properties / clinic services / courses / products). Fields adapt to the chosen industry.
import { useEffect, useMemo, useState } from 'react'
import { Button, Input, Textarea, Badge, EmptyState, Modal, ConfirmModal } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'
import { catalogApi, type IndustryTemplate, type Listing, type AttributeField, type DriveFile } from './api'

type Draft = {
  id?: number
  type: string
  title: string
  description: string
  status: Listing['status']
  price: string
  price_unit: string
  incentive_percentage: string
  currency: string
  location: string
  attributes: Record<string, unknown>
  media: { url: string }[]
}

const emptyDraft = (type: string): Draft => ({
  type, title: '', description: '', status: 'active',
  price: '', price_unit: '', incentive_percentage: '', currency: 'INR', location: '', attributes: {}, media: [],
})

function AttrInput({ field, value, onChange }: { field: AttributeField; value: unknown; onChange: (v: unknown) => void }) {
  if (field.type === 'boolean') {
    return (
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" checked={!!value} onChange={e => onChange(e.target.checked)} />
        {field.label}
      </label>
    )
  }
  if (field.type === 'enum') {
    return (
      <div>
        <label className="label">{field.label}{field.matchable && <span className="text-brand-500"> ·match</span>}</label>
        <select className="select" value={(value as string) ?? ''} onChange={e => onChange(e.target.value || undefined)}>
          <option value="">—</option>
          {(field.options ?? []).map(o => <option key={o} value={o}>{o}</option>)}
        </select>
      </div>
    )
  }
  return (
    <Input
      label={`${field.label}${field.matchable ? ' ·match' : ''}`}
      type={field.type === 'number' ? 'number' : 'text'}
      value={(value as string) ?? ''}
      onChange={e => onChange(field.type === 'number' ? (e.target.value === '' ? undefined : +e.target.value) : e.target.value || undefined)}
    />
  )
}

export default function CatalogPage() {
  const [templates, setTemplates] = useState<Record<string, IndustryTemplate>>({})
  const [activeKey, setActiveKey] = useState('generic')
  const [listings, setListings] = useState<Listing[]>([])
  const [loading, setLoading] = useState(true)
  const [editor, setEditor] = useState<Draft | null>(null)
  const [saving, setSaving] = useState(false)
  const [deleteId, setDeleteId] = useState<number | null>(null)
  const [mediaUrl, setMediaUrl] = useState('')
  const [driveOn, setDriveOn] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [drivePicker, setDrivePicker] = useState<DriveFile[] | null>(null)

  const template = templates[activeKey]
  const listingType = template?.listing_type ?? 'product'

  const load = async () => {
    setLoading(true)
    try {
      const [t, l] = await Promise.all([catalogApi.templates(), catalogApi.list()])
      setTemplates(t.data.templates)
      setActiveKey(t.data.active)
      setListings(l.data.data ?? l.data ?? [])
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])
  useEffect(() => {
    catalogApi.googleStatus().then(r => setDriveOn(!!r.data.integration)).catch(() => setDriveOn(false))
  }, [])

  const addMedia = (url: string) => {
    if (url.trim() && editor) setEditor({ ...editor, media: [...editor.media, { url: url.trim() }] })
  }

  const uploadToDrive = async (file: File) => {
    setUploading(true)
    try {
      const r = await catalogApi.driveUpload(file)
      addMedia(r.data.file.download_url)
      toast.success('Uploaded to your Drive.')
    } catch (e) { toast.error(getError(e)) }
    finally { setUploading(false) }
  }

  const openDrivePicker = async () => {
    try { setDrivePicker((await catalogApi.driveFiles()).data.files) }
    catch (e) { toast.error(getError(e)) }
  }

  const changeIndustry = async (key: string) => {
    setActiveKey(key)
    try { await catalogApi.setTemplate(key); toast.success(`Switched to ${templates[key]?.name}.`) }
    catch (e) { toast.error(getError(e)) }
  }

  const save = async () => {
    if (!editor) return
    if (!editor.title.trim()) { toast.error('Give the listing a title.'); return }
    setSaving(true)
    try {
      const payload = {
        ...editor,
        price: editor.price === '' ? null : +editor.price,
        price_unit: editor.price_unit || null,
        incentive_percentage: editor.incentive_percentage === '' ? null : +editor.incentive_percentage,
        media: editor.media.filter(m => m.url.trim()),
      }
      if (editor.id) { await catalogApi.update(editor.id, payload); toast.success('Updated.') }
      else { await catalogApi.create(payload); toast.success('Added.') }
      setEditor(null); void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const remove = async () => {
    if (!deleteId) return
    try { await catalogApi.remove(deleteId); toast.success('Deleted.'); setDeleteId(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const toDraft = (l: Listing): Draft => ({
    id: l.id, type: l.type, title: l.title, description: l.description ?? '', status: l.status,
    price: l.price ?? '', price_unit: l.price_unit ?? '', incentive_percentage: (l as any).incentive_percentage ?? '', currency: l.currency, location: l.location ?? '',
    attributes: l.attributes ?? {}, media: (l.media ?? []).map(m => ({ url: m.url })),
  })

  const setAttr = (k: string, v: unknown) => setEditor(d => d && ({ ...d, attributes: { ...d.attributes, [k]: v } }))

  const shown = useMemo(() => listings.filter(l => l.type === listingType), [listings, listingType])

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h1 className="page-title">Listings & Products</h1>
          <p className="page-sub">The live catalog your AI agent uses to answer queries and match leads</p>
        </div>
        <select className="select max-w-[190px]" value={activeKey} onChange={e => changeIndustry(e.target.value)}>
          {Object.entries(templates).map(([k, t]) => <option key={k} value={k}>{t.name}</option>)}
        </select>
        <Button onClick={() => setEditor(emptyDraft(listingType))}>+ Add {listingType}</Button>
      </div>

      {template && (
        <div className="card p-3 text-xs text-gray-500">
          <b className="text-gray-700">{template.name} template.</b> The agent asks: {template.question_flow.slice(0, 3).join(' → ')}…
          {' '}Qualifies a lead once it has: {template.qualification_fields.filter(f => f.required).map(f => f.label).join(', ')}.
        </div>
      )}

      {loading ? (
        <p className="text-sm text-gray-400">Loading…</p>
      ) : shown.length === 0 ? (
        <EmptyState icon="📦" title={`No ${listingType}s yet`}
          desc="Add your catalog so the AI can answer accurately and match customer requirements"
          action={<Button onClick={() => setEditor(emptyDraft(listingType))}>Add {listingType}</Button>} />
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {shown.map(l => (
            <div key={l.id} className="card p-4 flex flex-col">
              {l.media?.[0]?.url && <img src={l.media[0].url} alt="" className="w-full h-32 object-cover rounded-lg mb-2" />}
              <div className="flex items-start justify-between gap-2">
                <p className="font-medium text-sm text-gray-900">{l.title}</p>
                <Badge variant={l.status === 'active' ? 'green' : l.status === 'sold' ? 'red' : 'gray'}>{l.status}</Badge>
              </div>
              {l.location && <p className="text-xs text-gray-400 mt-0.5">{l.location}</p>}
              {l.price && <p className="text-sm text-brand-600 mt-1">{fmt.number(+l.price)} {l.currency}{l.price_unit ? ` · ${l.price_unit}` : ''}</p>}
              <div className="flex flex-wrap gap-1 mt-2">
                {Object.entries(l.attributes ?? {}).slice(0, 4).map(([k, v]) => (
                  <span key={k} className="text-[11px] bg-gray-100 text-gray-500 rounded px-1.5 py-0.5">{k}: {String(v)}</span>
                ))}
              </div>
              <div className="flex-1" />
              <div className="flex gap-3 mt-3 pt-2 border-t border-gray-100 text-xs">
                <button onClick={() => setEditor(toDraft(l))} className="text-brand-600 hover:underline">Edit</button>
                <button onClick={() => setDeleteId(l.id)} className="text-red-500 hover:underline ml-auto">Delete</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Editor */}
      <Modal open={editor !== null} onClose={() => setEditor(null)} size="lg"
        title={editor?.id ? 'Edit listing' : `New ${listingType}`}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setEditor(null)}>Cancel</Button>
            <Button onClick={save} loading={saving}>Save</Button>
          </div>
        }>
        {editor && (
          <div className="space-y-3">
            <Input label="Title *" value={editor.title} onChange={e => setEditor({ ...editor, title: e.target.value })} />
            <Textarea label="Description" rows={2} value={editor.description} onChange={e => setEditor({ ...editor, description: e.target.value })} />
            <div className="grid grid-cols-2 gap-3">
              <Input label="Price" type="number" value={editor.price} onChange={e => setEditor({ ...editor, price: e.target.value })} />
              <Input label="Unit" value={editor.price_unit} onChange={e => setEditor({ ...editor, price_unit: e.target.value })} placeholder="total / per_month" />
              <Input label="Staff incentive %" type="number" value={editor.incentive_percentage} onChange={e => setEditor({ ...editor, incentive_percentage: e.target.value })} placeholder="e.g. 5 — overrides the rule" />
              <div>
                <label className="label">Status</label>
                <select className="select" value={editor.status} onChange={e => setEditor({ ...editor, status: e.target.value as Listing['status'] })}>
                  <option value="active">Active</option><option value="draft">Draft</option>
                  <option value="inactive">Inactive</option><option value="sold">Sold / filled</option>
                </select>
              </div>
            </div>
            <Input label="Location / area" value={editor.location} onChange={e => setEditor({ ...editor, location: e.target.value })} />

            {template && template.attribute_schema.length > 0 && (
              <div className="rounded-lg border border-gray-200 p-3">
                <p className="text-xs font-medium text-gray-600 mb-2">{template.name} details</p>
                <div className="grid grid-cols-2 gap-3">
                  {template.attribute_schema.map(f => (
                    <AttrInput key={f.key} field={f} value={editor.attributes[f.key]} onChange={v => setAttr(f.key, v)} />
                  ))}
                </div>
              </div>
            )}

            <div>
              <label className="label">Photos / videos</label>
              <div className="flex flex-wrap gap-1.5 mb-1.5">
                {editor.media.map((m, i) => (
                  <span key={i} className="inline-flex items-center gap-1 bg-gray-100 text-xs rounded px-2 py-1">
                    {m.url.slice(0, 34)}…
                    <button onClick={() => setEditor({ ...editor, media: editor.media.filter((_, x) => x !== i) })}>×</button>
                  </span>
                ))}
                {editor.media.length === 0 && <span className="text-xs text-gray-400">None</span>}
              </div>
              <div className="flex gap-2">
                <Input value={mediaUrl} onChange={e => setMediaUrl(e.target.value)} placeholder="Paste an image/video URL" className="flex-1" />
                <Button variant="secondary" onClick={() => { addMedia(mediaUrl); setMediaUrl('') }}>Add URL</Button>
              </div>
              {driveOn && (
                <div className="flex items-center gap-3 mt-2 text-xs">
                  <label className="text-brand-600 hover:underline cursor-pointer">
                    {uploading ? 'Uploading…' : '⬆ Upload to Google Drive'}
                    <input type="file" accept="image/*,video/*" className="hidden" disabled={uploading}
                      onChange={e => { const f = e.target.files?.[0]; if (f) void uploadToDrive(f); e.target.value = '' }} />
                  </label>
                  <button onClick={openDrivePicker} className="text-gray-500 hover:underline">Pick from Drive</button>
                  <span className="text-gray-400">files stored in your Drive, not on our servers</span>
                </div>
              )}
            </div>
          </div>
        )}
      </Modal>

      <ConfirmModal open={deleteId !== null} title="Delete listing?" message="The AI will no longer surface it."
        onConfirm={remove} onCancel={() => setDeleteId(null)} />

      <Modal open={drivePicker !== null} onClose={() => setDrivePicker(null)} size="lg" title="Pick from Google Drive">
        {drivePicker && drivePicker.length === 0 ? (
          <p className="text-sm text-gray-400">No files in your Drive folder yet.</p>
        ) : (
          <div className="grid grid-cols-4 gap-2 max-h-[400px] overflow-y-auto">
            {(drivePicker ?? []).map(f => (
              <button key={f.id} type="button"
                onClick={() => { addMedia(f.download_url); setDrivePicker(null); toast.success('Added.') }}
                className="border border-gray-200 rounded-lg overflow-hidden hover:border-brand-400 text-left">
                {f.thumbnail
                  ? <img src={f.thumbnail} alt="" className="w-full h-20 object-cover" />
                  : <div className="w-full h-20 bg-gray-100 flex items-center justify-center text-xs text-gray-400">{f.mime?.split('/')[0]}</div>}
                <p className="text-[10px] p-1 truncate text-gray-500">{f.name}</p>
              </button>
            ))}
          </div>
        )}
      </Modal>
    </div>
  )
}
