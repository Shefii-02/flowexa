// src/pages/meta-ads/MediaLibraryPage.tsx
import { useEffect, useState, useCallback } from 'react'
import { metaAdsApi } from '../api/meta-ads'
import { Button, Badge, EmptyState } from '@/components/ui'
import { fmt, getError } from '@/utils'
import toast from 'react-hot-toast'

export const MediaLibrary = ({ accountId, onSelect }: { accountId?: number; onSelect?: (m: any) => void }) => {
  const [media,    setMedia]    = useState<any[]>([])
  const [tab,      setTab]      = useState<'image'|'video'>('image')
  const [uploading,setUploading]= useState(false)

  const load = useCallback(() => {
    metaAdsApi.media({ type: tab, per_page: 40 }).then(r => setMedia(r.data.data))
  }, [tab])

  useEffect(() => { load() }, [load])

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]; if (!file || !accountId) return
    setUploading(true)
    try {
      if (tab === 'image') { await metaAdsApi.uploadImage(accountId, file); toast.success('Image uploaded and sent to Meta.') }
      else                 { await metaAdsApi.uploadVideo(accountId, file, file.name); toast.success('Video uploaded and sent to Meta.') }
      load()
    } catch (err) { toast.error(getError(err)) }
    finally       { setUploading(false) }
  }

  const handleDelete = async (id: number) => {
    try { await metaAdsApi.deleteMedia(id); toast.success('Deleted.'); load() }
    catch (e) { toast.error(getError(e)) }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <div className="flex gap-1 border border-gray-200 rounded-lg p-0.5">
          {(['image','video'] as const).map(t => (
            <button key={t} onClick={() => setTab(t)}
              className={`px-4 py-1.5 text-sm rounded-md transition-colors capitalize ${tab===t ? 'bg-brand-500 text-white' : 'text-gray-600 hover:bg-gray-50'}`}>
              {t === 'image' ? '🖼️ Images' : '🎬 Videos'}
            </button>
          ))}
        </div>
        {accountId && (
          <label className={`btn btn-secondary btn-sm cursor-pointer ${uploading ? 'opacity-50' : ''}`}>
            {uploading ? 'Uploading...' : `↑ Upload ${tab}`}
            <input type="file" className="hidden" accept={tab === 'image' ? '.jpg,.jpeg,.png,.gif' : '.mp4,.mov,.avi'} onChange={handleUpload} disabled={uploading} />
          </label>
        )}
      </div>

      {media.length === 0 ? (
        <EmptyState icon={tab === 'image' ? '🖼️' : '🎬'} title={`No ${tab}s yet`} desc={`Upload ${tab}s to use in your ads`} />
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
          {media.map((m: any) => (
            <div key={m.id} onClick={() => onSelect?.(m)}
              className={`relative group rounded-xl overflow-hidden border border-gray-200 ${onSelect ? 'cursor-pointer hover:border-brand-400' : ''}`}>
              <div className="aspect-square bg-gray-100 flex items-center justify-center">
                {m.cdn_url
                  ? <img src={m.cdn_url} alt={m.name} className="w-full h-full object-cover" />
                  : <span className="text-3xl">{tab === 'image' ? '🖼️' : '🎬'}</span>}
              </div>
              <div className="p-2">
                <p className="text-xs text-gray-700 truncate">{m.original_filename}</p>
                <div className="flex items-center justify-between mt-1">
                  <Badge variant={m.upload_status === 'ready' ? 'green' : m.upload_status === 'failed' ? 'red' : 'yellow'}>
                    {m.upload_status}
                  </Badge>
                  {!onSelect && (
                    <button onClick={() => handleDelete(m.id)} className="text-xs text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100">✕</button>
                  )}
                </div>
                {m.meta_image_hash && <p className="text-xs text-gray-400 font-mono truncate mt-0.5">{m.meta_image_hash.slice(0,12)}...</p>}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

export default function MediaLibraryPage() {
  const [accounts, setAccounts] = useState<any[]>([])
  const [accountId, setAccountId] = useState<number | undefined>()
  useEffect(() => { metaAdsApi.accounts().then(r => { setAccounts(r.data.accounts); setAccountId(r.data.accounts[0]?.id) }) }, [])
  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div><h1 className="page-title">Media Library</h1><p className="page-sub">Images and videos uploaded to Meta</p></div>
        {accounts.length > 1 && (
          <select className="select max-w-[200px]" value={accountId} onChange={e => setAccountId(+e.target.value)}>
            {accounts.map((a: any) => <option key={a.id} value={a.id}>{a.ad_account_name || a.ad_account_id}</option>)}
          </select>
        )}
      </div>
      <div className="card p-5"><MediaLibrary accountId={accountId} /></div>
    </div>
  )
}
