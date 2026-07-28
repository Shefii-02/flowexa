// src/pages/meta-ads/CreativeStudioPage.tsx
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { metaAdsApi } from '../api/meta-ads'
import { Button, Input, Badge, Modal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { MediaLibrary } from '../media-library/MediaLibraryPage'

const CTAS = ['LEARN_MORE','SIGN_UP','SHOP_NOW','GET_QUOTE','CONTACT_US','BOOK_NOW','DOWNLOAD','WATCH_MORE','APPLY_NOW','GET_OFFER']

const formatTabs = [
  { key: 'image',    label: '🖼️ Image',    desc: 'Single image ad' },
  { key: 'video',    label: '🎬 Video',    desc: 'Video ad' },
  { key: 'carousel', label: '🎠 Carousel', desc: 'Multi-card swipeable ad' },
]

export default function CreativeStudioPage() {
  const navigate    = useNavigate()
  const [accounts,  setAccounts]  = useState<any[]>([])
  const [creatives, setCreatives] = useState<any[]>([])
  const [format,    setFormat]    = useState('image')
  const [showCreate,setShowCreate]= useState(false)
  const [showMedia, setShowMedia] = useState(false)
  const [saving,    setSaving]    = useState(false)
  const [selectedMedia, setSelectedMedia] = useState<any>(null)
  const [carouselCards, setCards] = useState([
    { image_id: null as any, headline: '', description: '', url: '', cta: 'LEARN_MORE' },
    { image_id: null as any, headline: '', description: '', url: '', cta: 'LEARN_MORE' },
  ])
  const [form, setForm] = useState({
    account_id: '', name: '', primary_text: '',
    headline: '', description: '', call_to_action: 'LEARN_MORE', destination_url: '',
  })
  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }))

  useEffect(() => {
    Promise.all([metaAdsApi.accounts(), metaAdsApi.creatives()])
      .then(([a, c]) => { setAccounts(a.data.accounts); setCreatives(c.data.creatives) })
  }, [])

  const handlePickMedia = (m: any) => { setSelectedMedia(m); setShowMedia(false) }

  const handleCreate = async () => {
    if (!form.account_id) { toast.error('Select an ad account.'); return }
    setSaving(true)
    try {
      const payload: any = { ...form, account_id: +form.account_id, format }
      if (format === 'image')    payload.image_id      = selectedMedia?.id
      if (format === 'video')    payload.video_id      = selectedMedia?.id
      if (format === 'carousel') payload.carousel_cards = carouselCards
      await metaAdsApi.createCreative(payload)
      toast.success('Creative created.'); setShowCreate(false)
      metaAdsApi.creatives().then(r => setCreatives(r.data.creatives))
    } catch (e) { toast.error(getError(e)) }
    finally     { setSaving(false) }
  }

  const addCard = () => setCards(c => [...c, { image_id: null, headline: '', description: '', url: '', cta: 'LEARN_MORE' }])
  const updateCard = (i: number, k: string, v: any) => setCards(c => c.map((card, idx) => idx === i ? { ...card, [k]: v } : card))

  const formatBadge: Record<string,string> = { image: 'blue', video: 'purple', carousel: 'green' }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Creative Studio</h1><p className="page-sub">Build image, video, and carousel ad creatives</p></div>
        <Button onClick={() => setShowCreate(s => !s)}>{showCreate ? 'Cancel' : '+ New creative'}</Button>
      </div>

      {/* Create form */}
      {showCreate && (
        <div className="card p-5 border-brand-200">
          <h3 className="font-semibold text-gray-900 mb-4">New creative</h3>

          {/* Format tabs */}
          <div className="flex gap-2 mb-5">
            {formatTabs.map(f => (
              <button key={f.key} onClick={() => { setFormat(f.key); setSelectedMedia(null) }}
                className={`flex-1 p-3 rounded-xl border text-center transition-all ${format === f.key ? 'border-brand-500 bg-brand-50' : 'border-gray-200 hover:border-gray-300'}`}>
                <p className="font-medium text-sm">{f.label}</p>
                <p className="text-xs text-gray-400">{f.desc}</p>
              </button>
            ))}
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Ad account *</label>
              <select className="select" value={form.account_id} onChange={e => set('account_id', e.target.value)}>
                <option value="">Select account...</option>
                {accounts.map((a: any) => <option key={a.id} value={a.id}>{a.ad_account_name || a.ad_account_id}</option>)}
              </select>
            </div>
            <Input label="Creative name" value={form.name} onChange={e => set('name', e.target.value)} placeholder="July Promo Image" />
          </div>

          {/* Media picker (image + video) */}
          {(format === 'image' || format === 'video') && (
            <div className="mt-4">
              <label className="label">{format === 'image' ? 'Image' : 'Video'} *</label>
              {selectedMedia ? (
                <div className="flex items-center gap-3 p-3 bg-gray-50 rounded-xl border">
                  <span className="text-2xl">{format === 'image' ? '🖼️' : '🎬'}</span>
                  <p className="text-sm text-gray-700 flex-1">{selectedMedia.original_filename}</p>
                  <button onClick={() => setSelectedMedia(null)} className="text-xs text-red-400">Remove</button>
                </div>
              ) : (
                <button onClick={() => setShowMedia(true)} className="w-full border border-dashed border-gray-300 rounded-xl p-4 text-sm text-gray-500 hover:border-brand-400 hover:text-brand-600 transition-colors">
                  📂 Browse media library or upload new
                </button>
              )}
            </div>
          )}

          {/* Carousel cards */}
          {format === 'carousel' && (
            <div className="mt-4 space-y-3">
              <div className="flex items-center justify-between">
                <label className="label mb-0">Cards (2–10) *</label>
                {carouselCards.length < 10 && <button onClick={addCard} className="text-xs text-brand-600 hover:underline">+ Add card</button>}
              </div>
              {carouselCards.map((card, i) => (
                <div key={i} className="border border-gray-200 rounded-xl p-3 space-y-2">
                  <div className="flex items-center justify-between">
                    <p className="text-xs font-medium text-gray-500">Card {i+1}</p>
                    {i >= 2 && <button onClick={() => setCards(c => c.filter((_,idx) => idx !== i))} className="text-xs text-red-400">Remove</button>}
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <Input label="Headline" value={card.headline} onChange={e => updateCard(i,'headline',e.target.value)} />
                    <Input label="URL" value={card.url} onChange={e => updateCard(i,'url',e.target.value)} placeholder="https://..." />
                    <Input label="Description" value={card.description} onChange={e => updateCard(i,'description',e.target.value)} />
                    <div><label className="label text-xs">CTA</label>
                      <select className="select text-xs" value={card.cta} onChange={e => updateCard(i,'cta',e.target.value)}>
                        {CTAS.map(c => <option key={c} value={c}>{c.replace(/_/g,' ')}</option>)}
                      </select>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Common fields */}
          <div className="mt-4 space-y-3">
            <div>
              <label className="label">Primary text (ad body) *</label>
              <textarea className="textarea" rows={3} value={form.primary_text} onChange={e => set('primary_text', e.target.value)} placeholder="Your ad message here..." />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Input label="Headline" value={form.headline} onChange={e => set('headline', e.target.value)} />
              <Input label="Description" value={form.description} onChange={e => set('description', e.target.value)} />
              <Input label="Destination URL" value={form.destination_url} onChange={e => set('destination_url', e.target.value)} placeholder="https://yoursite.com" />
              <div><label className="label">Call to action</label>
                <select className="select" value={form.call_to_action} onChange={e => set('call_to_action', e.target.value)}>
                  {CTAS.map(c => <option key={c} value={c}>{c.replace(/_/g,' ')}</option>)}
                </select>
              </div>
            </div>
          </div>

          <div className="flex justify-end gap-2 mt-4">
            <Button variant="secondary" onClick={() => setShowCreate(false)}>Cancel</Button>
            <Button onClick={handleCreate} loading={saving}>Create creative</Button>
          </div>
        </div>
      )}

      {/* Creatives list */}
      {creatives.length > 0 && (
        <div className="card">
          <div className="card-header"><h3 className="card-title">Saved creatives</h3></div>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr><th>Name</th><th>Format</th><th>Headline</th><th>CTA</th><th>Meta ID</th></tr></thead>
              <tbody>
                {creatives.map((c: any) => (
                  <tr key={c.id}>
                    <td className="font-medium">{c.name || '—'}</td>
                    <td><Badge variant={formatBadge[c.format] as any}>{c.format}</Badge></td>
                    <td className="text-gray-500 text-sm max-w-xs truncate">{c.headline || c.primary_text?.slice(0,50) || '—'}</td>
                    <td className="text-xs">{c.call_to_action?.replace(/_/g,' ')}</td>
                    <td className="font-mono text-xs text-gray-400">{c.meta_creative_id || 'Not synced'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Media picker modal */}
      <Modal open={showMedia} onClose={() => setShowMedia(false)} title="Select media" size="xl">
        <MediaLibrary accountId={form.account_id ? +form.account_id : undefined} onSelect={handlePickMedia} />
      </Modal>
    </div>
  )
}
