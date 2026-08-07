// src/pages/templates/TemplateDetailPage.tsx
import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { templateApi } from '@/api'
import { Button, Badge, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

const statusColor: Record<string,string> = {
  approved: 'green', pending: 'amber', rejected: 'red',
  error: 'red', draft: 'gray', pending_deletion: 'gray', disabled: 'gray',
}
const statusIcon: Record<string,string> = {
  approved: '✅', pending: '⏳', rejected: '❌', error: '⚠️', draft: '📝',
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  if (value === null || value === undefined || value === '') return null
  return (
    <div className="grid grid-cols-3 gap-3 py-2 border-b border-gray-50 last:border-0">
      <span className="text-xs text-gray-400">{label}</span>
      <span className="col-span-2 text-sm text-gray-800 break-words">{value}</span>
    </div>
  )
}

export default function TemplateDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [template, setTemplate] = useState<any>(null)
  const [loading,  setLoading]  = useState(true)
  const [syncing,  setSyncing]  = useState(false)

  const load = () => {
    if (!id) return
    setLoading(true)
    templateApi.show(Number(id))
      .then(r => setTemplate(r.data.template))
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  const handleSyncSingle = async () => {
    setSyncing(true)
    try {
      const { data } = await templateApi.syncSingle(Number(id))
      setTemplate(data.template)
      toast.success('Status refreshed from Meta.')
    } catch (e) { toast.error(getError(e)) }
    finally { setSyncing(false) }
  }

  if (loading) return <div className="p-8 text-center text-gray-400">Loading template...</div>
  if (!template) return <EmptyState icon="📄" title="Template not found" desc="It may have been deleted." />

  const isAuth = template.category === 'AUTHENTICATION'
  const headerType = (template.header_format || 'TEXT').toUpperCase()

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate(-1)} className="text-gray-400 hover:text-gray-600 text-sm">← Back</button>
          <div>
            <h1 className="page-title font-mono">{template.name}</h1>
            <p className="page-sub">
              <Badge variant={statusColor[template.status] as any}>{statusIcon[template.status]} {template.status}</Badge>
              <span className="ml-2">{template.category} · {template.language}</span>
            </p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={handleSyncSingle} loading={syncing}>🔄 Refresh status</Button>
          <Button onClick={() => navigate(`/templates?edit=${template.id}`)}>Edit</Button>
        </div>
      </div>

      {template.status === 'rejected' && template.rejection_reason && (
        <div className="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700">
          <strong>Rejection reason:</strong> {template.rejection_reason}
        </div>
      )}
      {template.status === 'error' && template.rejection_reason && (
        <div className="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700">
          <strong>Error:</strong> {template.rejection_reason}
        </div>
      )}

      <div className="grid grid-cols-5 gap-5">
        {/* Details — left col */}
        <div className="col-span-3 card p-5 space-y-1">
          <p className="text-sm font-semibold text-gray-700 mb-2">Details</p>
          <Row label="Meta template ID" value={<span className="font-mono text-xs">{template.wa_template_id || '—'}</span>} />
          <Row label="Category" value={template.category} />
          <Row label="Language" value={template.language} />
          <Row label="Sending number" value={template.wa_phone_number?.display_phone_number} />
          <Row label="Created" value={template.created_at?.slice(0, 19).replace('T', ' ')} />
          <Row label="Last updated" value={template.updated_at?.slice(0, 19).replace('T', ' ')} />

          {!isAuth && (
            <>
              <div className="pt-3 mt-3 border-t border-gray-100">
                <p className="text-sm font-semibold text-gray-700 mb-2">Content</p>
              </div>
              <Row label="Header format" value={headerType} />
              {headerType === 'TEXT' && <Row label="Header text" value={template.header} />}
              {headerType !== 'TEXT' && (
                <Row label="Sample media" value={
                  template.header_sample_url
                    ? <a href={template.header_sample_url} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">View sample →</a>
                    : '—'
                } />
              )}
              <Row label="Body" value={<span className="whitespace-pre-wrap">{template.body}</span>} />
              {Array.isArray(template.body_examples) && template.body_examples.length > 0 && (
                <Row label="Body variable samples" value={template.body_examples.join(' · ')} />
              )}
              <Row label="Footer" value={template.footer} />
              {Array.isArray(template.buttons) && template.buttons.length > 0 && (
                <Row label="Buttons" value={
                  <div className="space-y-1">
                    {template.buttons.map((b: any, i: number) => (
                      <div key={i} className="text-xs">
                        <Badge variant="blue">{b.type}</Badge>{' '}
                        {b.text}{b.url ? ` → ${b.url}` : ''}{b.phone_number ? ` → ${b.phone_number}` : ''}
                      </div>
                    ))}
                  </div>
                } />
              )}
            </>
          )}

          {isAuth && (
            <>
              <div className="pt-3 mt-3 border-t border-gray-100">
                <p className="text-sm font-semibold text-gray-700 mb-2">Code delivery setup</p>
              </div>
              <Row label="Delivery method" value={
                template.auth_delivery_method === 'zero_tap' ? 'Zero-tap auto-fill'
                  : template.auth_delivery_method === 'one_tap' ? 'One-tap auto-fill'
                  : 'Copy code'
              } />
              {['zero_tap', 'one_tap'].includes(template.auth_delivery_method) && (
                <Row label="Registered apps" value={
                  <div className="space-y-1">
                    {(template.auth_apps || []).map((a: any, i: number) => (
                      <div key={i} className="text-xs font-mono">{a.package_name} · {a.signature_hash}</div>
                    ))}
                  </div>
                } />
              )}
              <Row label="Code expiry" value={template.auth_add_expiry ? 'Enabled (20 min)' : 'Disabled'} />
              <Row label="Security recommendation" value={template.auth_add_security_recommendation ? 'Enabled' : 'Disabled'} />
            </>
          )}
        </div>

        {/* Preview — right col */}
        <div className="col-span-2">
          <p className="text-sm font-semibold text-gray-700 mb-3">Preview</p>
          <div className="bg-[#e5ddd5] rounded-xl p-3 min-h-[200px]">
            <div className="bg-white rounded-xl rounded-tl-none shadow-sm p-3 max-w-[90%] overflow-hidden">
              {isAuth ? (
                <>
                  <p className="text-sm text-gray-800 leading-relaxed">
                    <span className="font-mono bg-gray-100 px-1.5 py-0.5 rounded">123456</span> is your verification code.
                    {template.auth_add_security_recommendation && ' For your security, do not share this code.'}
                  </p>
                  {template.auth_add_expiry && (
                    <p className="text-xs text-gray-400 mt-2 pt-2 border-t border-gray-100">This code expires in 20 minutes.</p>
                  )}
                </>
              ) : (
                <>
                  {headerType === 'IMAGE' && template.header_sample_url && (
                    <img src={template.header_sample_url} alt="" className="w-full h-36 object-cover rounded-lg -mt-3 -mx-3 mb-2" style={{ width: 'calc(100% + 1.5rem)' }} />
                  )}
                  {headerType === 'TEXT' && template.header && (
                    <p className="font-bold text-sm text-gray-900 mb-2">{template.header}</p>
                  )}
                  <p className="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{template.body}</p>
                  {template.footer && <p className="text-xs text-gray-400 mt-2 pt-2 border-t border-gray-100">{template.footer}</p>}
                </>
              )}
              <p className="text-xs text-gray-300 text-right mt-1">12:30 PM ✓✓</p>
            </div>
            {!isAuth && Array.isArray(template.buttons) && template.buttons.length > 0 && (
              <div className="mt-1 space-y-1">
                {template.buttons.map((b: any, i: number) => (
                  <div key={i} className="bg-white rounded-xl p-2.5 text-center text-sm text-[#00a5f4] font-medium shadow-sm">
                    {b.type === 'URL' ? '🔗 ' : b.type === 'PHONE_NUMBER' ? '📞 ' : ''}{b.text}
                  </div>
                ))}
              </div>
            )}
            {isAuth && (
              <div className="mt-1 space-y-1">
                <div className="bg-white rounded-xl p-2.5 text-center text-sm text-[#00a5f4] font-medium shadow-sm">
                  {template.auth_delivery_method === 'zero_tap' ? '⚡ Auto-filled' : template.auth_delivery_method === 'one_tap' ? '👆 Autofill' : '📋 Copy Code'}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}