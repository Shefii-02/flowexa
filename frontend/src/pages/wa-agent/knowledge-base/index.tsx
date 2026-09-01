import { useState, useEffect, useCallback } from 'react'
import { api } from '@/api/client'

type KB = {
  id: number
  name: string
  description?: string
  document_type: 'text' | 'url' | 'file'
  status: 'pending' | 'processing' | 'ready' | 'failed'
  word_count: number
  chunk_count: number
  error_message?: string
  source_url?: string
  created_at: string
}

const STATUS_COLORS: Record<string, string> = {
  ready:      'bg-green-50 text-green-700',
  processing: 'bg-yellow-50 text-yellow-700',
  pending:    'bg-blue-50 text-blue-700',
  failed:     'bg-red-50 text-red-700',
}

export default function KnowledgeBasePage() {
  const [items, setItems]     = useState<KB[]>([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal]     = useState<'text' | 'url' | 'file' | null>(null)
  const [form, setForm]       = useState({ name: '', description: '', content: '', url: '' })
  const [file, setFile]       = useState<File | null>(null)
  const [saving, setSaving]   = useState(false)
  const [err, setErr]         = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.get('/wa-agent/knowledge-base')
      setItems(res.data)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const handleSave = async () => {
    setErr('')
    if (!form.name) { setErr('Name is required.'); return }

    setSaving(true)
    try {
      if (modal === 'text') {
        if (!form.content) { setErr('Content is required.'); setSaving(false); return }
        await api.post('/wa-agent/knowledge-base', {
          name: form.name,
          description: form.description,
          document_type: 'text',
          raw_content: form.content,
        })
      } else if (modal === 'url') {
        if (!form.url) { setErr('URL is required.'); setSaving(false); return }
        await api.post('/wa-agent/knowledge-base', {
          name: form.name,
          description: form.description,
          document_type: 'url',
          source_url: form.url,
        })
      } else if (modal === 'file') {
        if (!file) { setErr('File is required.'); setSaving(false); return }
        const fd = new FormData()
        fd.append('name', form.name)
        fd.append('file', file)
        await api.post('/wa-agent/knowledge-base/upload', fd, {
          headers: { 'Content-Type': 'multipart/form-data' },
        })
      }
      setModal(null)
      load()
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setErr(msg ?? 'Failed to save.')
    } finally {
      setSaving(false)
    }
  }

  const reprocess = async (id: number) => {
    await api.post(`/wa-agent/knowledge-base/${id}/reprocess`)
    load()
  }

  const deleteKb = async (id: number) => {
    if (!confirm('Delete this knowledge base? This will remove all chunks.')) return
    await api.delete(`/wa-agent/knowledge-base/${id}`)
    load()
  }

  const openModal = (type: 'text' | 'url' | 'file') => {
    setForm({ name: '', description: '', content: '', url: '' })
    setFile(null)
    setErr('')
    setModal(type)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Knowledge Base</h1>
          <p className="text-sm text-gray-500 mt-1">Documents used by the AI agent to answer questions</p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => openModal('text')}
            className="px-3 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50"
          >
            + Text
          </button>
          <button
            onClick={() => openModal('url')}
            className="px-3 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50"
          >
            + URL
          </button>
          <button
            onClick={() => openModal('file')}
            className="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700"
          >
            + Upload File
          </button>
        </div>
      </div>

      {loading ? (
        <div className="text-center py-16 text-gray-400">Loading...</div>
      ) : items.length === 0 ? (
        <div className="text-center py-16 text-gray-400">
          <div className="text-4xl mb-3">📚</div>
          <p>No knowledge base documents yet. Add your first document to enable AI responses.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {items.map((kb) => (
            <div
              key={kb.id}
              className="bg-white border border-gray-200 rounded-xl p-4 flex items-center gap-4"
            >
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  <span className="font-medium text-gray-900">{kb.name}</span>
                  <span className={`px-2 py-0.5 rounded-full text-xs capitalize ${STATUS_COLORS[kb.status] ?? 'bg-gray-100 text-gray-600'}`}>
                    {kb.status}
                  </span>
                  <span className="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600 capitalize">
                    {kb.document_type}
                  </span>
                </div>
                <p className="text-xs text-gray-500">
                  {kb.chunk_count} chunks • {kb.word_count.toLocaleString()} words
                  {kb.error_message && <span className="text-red-500 ml-2">{kb.error_message}</span>}
                </p>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                {kb.status === 'failed' && (
                  <button
                    onClick={() => reprocess(kb.id)}
                    className="px-3 py-1.5 rounded text-xs font-medium border border-yellow-300 text-yellow-700 hover:bg-yellow-50"
                  >
                    Retry
                  </button>
                )}
                <button
                  onClick={() => reprocess(kb.id)}
                  className="px-3 py-1.5 rounded text-xs font-medium border border-indigo-200 text-indigo-700 hover:bg-indigo-50"
                >
                  Re-index
                </button>
                <button
                  onClick={() => deleteKb(kb.id)}
                  className="px-3 py-1.5 rounded text-xs font-medium border border-red-200 text-red-600 hover:bg-red-50"
                >
                  Delete
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4">
            <div className="p-5 border-b flex items-center justify-between">
              <h2 className="font-semibold text-gray-900">
                Add {modal === 'text' ? 'Text' : modal === 'url' ? 'URL' : 'File'} Document
              </h2>
              <button onClick={() => setModal(null)} className="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <div className="p-5 space-y-4">
              {err && <div className="p-3 bg-red-50 text-red-700 text-sm rounded-lg">{err}</div>}

              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Name *</label>
                <input
                  value={form.name}
                  onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
                  className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  placeholder="e.g. Product FAQ"
                />
              </div>

              {modal === 'text' && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Content *</label>
                  <textarea
                    rows={8}
                    value={form.content}
                    onChange={(e) => setForm((p) => ({ ...p, content: e.target.value }))}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
                    placeholder="Paste your FAQ, product description, or any knowledge content here..."
                  />
                </div>
              )}

              {modal === 'url' && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">URL *</label>
                  <input
                    type="url"
                    value={form.url}
                    onChange={(e) => setForm((p) => ({ ...p, url: e.target.value }))}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    placeholder="https://yoursite.com/faq"
                  />
                </div>
              )}

              {modal === 'file' && (
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">File (txt, pdf, doc, docx) *</label>
                  <input
                    type="file"
                    accept=".txt,.pdf,.doc,.docx"
                    onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                    className="w-full text-sm text-gray-600"
                  />
                </div>
              )}
            </div>
            <div className="p-5 border-t flex justify-end gap-3">
              <button
                onClick={() => setModal(null)}
                className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handleSave}
                disabled={saving}
                className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
              >
                {saving ? 'Processing...' : 'Add Document'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
