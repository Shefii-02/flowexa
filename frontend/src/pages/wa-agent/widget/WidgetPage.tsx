// Website Chat Widget — configure the branded AI chat, grab the one-line embed, watch leads land.
import { useEffect, useState } from 'react'
import { Button, Input, Textarea, Badge, EmptyState, Modal, ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { widgetApi, type ChatWidget, type WidgetConversation, type WidgetMessage } from './api'

type Draft = {
  id?: number
  name: string
  is_active: boolean
  industry_template: string
  agent_name: string
  greeting: string
  primary_color: string
  position: 'right' | 'left'
  launcher_text: string
  allowed_origins: string
  notify_emails: string
  notify_whatsapp: string
  wa_session_id: string
}

const emptyDraft = (): Draft => ({
  name: 'Website chat', is_active: true, industry_template: '', agent_name: 'Assistant',
  greeting: 'Hi! 👋 How can I help you today?', primary_color: '#4f46e5', position: 'right',
  launcher_text: 'Chat with us', allowed_origins: '', notify_emails: '', notify_whatsapp: '', wa_session_id: '',
})

export default function WidgetPage() {
  const [widgets, setWidgets] = useState<ChatWidget[]>([])
  const [templates, setTemplates] = useState<Record<string, { name: string }>>({})
  const [loading, setLoading] = useState(true)
  const [editor, setEditor] = useState<Draft | null>(null)
  const [saving, setSaving] = useState(false)
  const [deleteId, setDeleteId] = useState<number | null>(null)
  const [inboxFor, setInboxFor] = useState<ChatWidget | null>(null)

  const load = async () => {
    setLoading(true)
    try {
      const r = await widgetApi.list()
      setWidgets(r.data.widgets ?? [])
      setTemplates(r.data.templates ?? {})
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  const toDraft = (w: ChatWidget): Draft => ({
    id: w.id, name: w.name, is_active: w.is_active, industry_template: w.industry_template ?? '',
    agent_name: w.agent_name, greeting: w.greeting,
    primary_color: w.branding?.primary_color ?? '#4f46e5',
    position: w.branding?.position ?? 'right',
    launcher_text: w.branding?.launcher_text ?? 'Chat with us',
    allowed_origins: (w.allowed_origins ?? []).join('\n'),
    notify_emails: (w.notify_emails ?? []).join(', '),
    notify_whatsapp: (w.notify_whatsapp ?? []).join(', '),
    wa_session_id: w.wa_session_id ?? '',
  })

  const save = async () => {
    if (!editor) return
    setSaving(true)
    try {
      const payload = {
        name: editor.name,
        is_active: editor.is_active,
        industry_template: editor.industry_template || null,
        agent_name: editor.agent_name,
        greeting: editor.greeting,
        branding: { primary_color: editor.primary_color, position: editor.position, launcher_text: editor.launcher_text },
        allowed_origins: editor.allowed_origins.split(/[\s,]+/).map(s => s.trim()).filter(Boolean),
        notify_emails: editor.notify_emails.split(/[\s,]+/).map(s => s.trim()).filter(Boolean),
        notify_whatsapp: editor.notify_whatsapp.split(/[\s,]+/).map(s => s.trim()).filter(Boolean),
        wa_session_id: editor.wa_session_id || null,
      }
      if (editor.id) { await widgetApi.update(editor.id, payload); toast.success('Saved.') }
      else { await widgetApi.create(payload); toast.success('Widget created.') }
      setEditor(null); void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const remove = async () => {
    if (!deleteId) return
    try { await widgetApi.remove(deleteId); toast.success('Deleted.'); setDeleteId(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const copy = (text: string) => { navigator.clipboard.writeText(text); toast.success('Copied to clipboard') }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h1 className="page-title">Website Chat Widget</h1>
          <p className="page-sub">One line of code. Visitors chat, the AI qualifies the lead, your team gets alerted.</p>
        </div>
        <Button onClick={() => setEditor(emptyDraft())}>+ New widget</Button>
      </div>

      {loading ? (
        <p className="text-sm text-gray-400">Loading…</p>
      ) : widgets.length === 0 ? (
        <EmptyState icon="💬" title="No widget yet"
          desc="Create one, paste the snippet on your site, and start capturing leads"
          action={<Button onClick={() => setEditor(emptyDraft())}>Create widget</Button>} />
      ) : (
        <div className="grid gap-4">
          {widgets.map(w => (
            <div key={w.id} className="card p-5">
              <div className="flex items-start gap-3">
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <p className="font-semibold text-gray-900">{w.name}</p>
                    <Badge variant={w.is_active ? 'green' : 'gray'}>{w.is_active ? 'Live' : 'Off'}</Badge>
                  </div>
                  <p className="text-xs text-gray-400 mt-0.5">
                    Agent “{w.agent_name}” · {w.conversations_count} chats · <span className="text-brand-600 font-medium">{w.leads_count} leads</span>
                  </p>
                </div>
                <div className="flex gap-3 text-xs">
                  <button onClick={() => setInboxFor(w)} className="text-brand-600 hover:underline">Conversations</button>
                  <button onClick={() => setEditor(toDraft(w))} className="text-gray-500 hover:underline">Edit</button>
                  <button onClick={() => setDeleteId(w.id)} className="text-red-500 hover:underline">Delete</button>
                </div>
              </div>
              <div className="mt-3 bg-gray-900 text-gray-100 rounded-lg p-3 flex items-center gap-2">
                <code className="text-xs flex-1 overflow-x-auto whitespace-nowrap">{w.embed_snippet}</code>
                <button onClick={() => copy(w.embed_snippet)} className="text-xs bg-white/10 hover:bg-white/20 rounded px-2 py-1">Copy</button>
              </div>
              <p className="text-xs text-gray-400 mt-1.5">Paste this just before <code>&lt;/body&gt;</code> on every page you want the chat on.</p>
            </div>
          ))}
        </div>
      )}

      {/* Editor */}
      <Modal open={editor !== null} onClose={() => setEditor(null)} size="lg"
        title={editor?.id ? 'Edit widget' : 'New widget'}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setEditor(null)}>Cancel</Button>
            <Button onClick={save} loading={saving}>Save</Button>
          </div>
        }>
        {editor && (
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <Input label="Internal name" value={editor.name} onChange={e => setEditor({ ...editor, name: e.target.value })} />
              <div>
                <label className="label">Industry</label>
                <select className="select" value={editor.industry_template} onChange={e => setEditor({ ...editor, industry_template: e.target.value })}>
                  <option value="">Company default</option>
                  {Object.entries(templates).map(([k, t]) => <option key={k} value={k}>{t.name}</option>)}
                </select>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <Input label="Agent display name" value={editor.agent_name} onChange={e => setEditor({ ...editor, agent_name: e.target.value })} />
              <Input label="Launcher button text" value={editor.launcher_text} onChange={e => setEditor({ ...editor, launcher_text: e.target.value })} />
            </div>
            <Textarea label="Greeting (first message)" rows={2} value={editor.greeting} onChange={e => setEditor({ ...editor, greeting: e.target.value })} />
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Brand color</label>
                <input type="color" className="w-full h-9 rounded border border-gray-300" value={editor.primary_color}
                  onChange={e => setEditor({ ...editor, primary_color: e.target.value })} />
              </div>
              <div>
                <label className="label">Position</label>
                <select className="select" value={editor.position} onChange={e => setEditor({ ...editor, position: e.target.value as 'right' | 'left' })}>
                  <option value="right">Bottom right</option>
                  <option value="left">Bottom left</option>
                </select>
              </div>
            </div>
            <Textarea label="Allowed website domains (one per line, blank = any)" rows={2}
              placeholder={'acme.com\nwww.acme.com'}
              value={editor.allowed_origins} onChange={e => setEditor({ ...editor, allowed_origins: e.target.value })} />

            <div className="rounded-lg border border-gray-200 p-3 space-y-2">
              <p className="text-xs font-medium text-gray-600">Lead alerts (fired the moment a lead qualifies)</p>
              <Input label="Notify emails (comma separated)" value={editor.notify_emails} onChange={e => setEditor({ ...editor, notify_emails: e.target.value })} placeholder="sales@acme.com, owner@acme.com" />
              <div className="grid grid-cols-2 gap-3">
                <Input label="Notify WhatsApp numbers" value={editor.notify_whatsapp} onChange={e => setEditor({ ...editor, notify_whatsapp: e.target.value })} placeholder="9198xxxxxxx" />
                <Input label="WA session id (to send from)" value={editor.wa_session_id} onChange={e => setEditor({ ...editor, wa_session_id: e.target.value })} />
              </div>
            </div>

            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={editor.is_active} onChange={e => setEditor({ ...editor, is_active: e.target.checked })} />
              Widget is live
            </label>
          </div>
        )}
      </Modal>

      {inboxFor && <WidgetInbox widget={inboxFor} onClose={() => setInboxFor(null)} />}

      <ConfirmModal open={deleteId !== null} title="Delete widget?" message="The embed code will stop working immediately."
        onConfirm={remove} onCancel={() => setDeleteId(null)} />
    </div>
  )
}

function WidgetInbox({ widget, onClose }: { widget: ChatWidget; onClose: () => void }) {
  const [convos, setConvos] = useState<WidgetConversation[]>([])
  const [active, setActive] = useState<WidgetConversation | null>(null)
  const [messages, setMessages] = useState<WidgetMessage[]>([])
  const [filter, setFilter] = useState('')

  useEffect(() => {
    widgetApi.conversations(widget.id, filter ? { status: filter } : {})
      .then(r => setConvos(r.data.data ?? r.data ?? []))
      .catch(e => toast.error(getError(e)))
  }, [widget.id, filter])

  const open = async (c: WidgetConversation) => {
    setActive(c)
    const r = await widgetApi.conversation(widget.id, c.id)
    setMessages(r.data.messages ?? [])
    setActive(r.data.conversation)
  }

  return (
    <Modal open onClose={onClose} size="xl" title={`${widget.name} — conversations`}>
      <div className="flex gap-2 mb-3">
        {['', 'qualified', 'active', 'closed'].map(s => (
          <button key={s} onClick={() => setFilter(s)}
            className={`text-xs px-3 py-1 rounded-lg ${filter === s ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'}`}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <div className="grid grid-cols-[240px_1fr] gap-3 h-[440px]">
        <div className="border border-gray-200 rounded-lg overflow-y-auto">
          {convos.length === 0 ? <p className="p-3 text-xs text-gray-400">No conversations.</p> : convos.map(c => (
            <button key={c.id} onClick={() => open(c)}
              className={`w-full text-left px-3 py-2 border-b border-gray-100 hover:bg-gray-50 ${active?.id === c.id ? 'bg-brand-50' : ''}`}>
              <div className="flex items-center gap-1.5">
                <span className="text-sm font-medium flex-1 truncate">{c.visitor_name || c.visitor_phone || 'Visitor'}</span>
                {c.status === 'qualified' && <Badge variant="green">lead</Badge>}
              </div>
              <p className="text-[11px] text-gray-400 truncate">{c.page_url}</p>
            </button>
          ))}
        </div>
        <div className="border border-gray-200 rounded-lg flex flex-col">
          {!active ? (
            <div className="flex-1 flex items-center justify-center text-sm text-gray-400">Select a conversation</div>
          ) : (
            <>
              {active.collected && Object.keys(active.collected).length > 0 && (
                <div className="p-2 border-b border-gray-100 bg-gray-50 text-xs flex flex-wrap gap-1.5">
                  {Object.entries(active.collected).map(([k, v]) => (
                    <span key={k} className="bg-white border border-gray-200 rounded px-1.5 py-0.5">{k}: {String(v)}</span>
                  ))}
                  {active.lead && <span className="text-brand-600">→ CRM lead #{active.lead.id}</span>}
                </div>
              )}
              <div className="flex-1 overflow-y-auto p-3 space-y-2">
                {messages.map(m => (
                  <div key={m.id} className={`max-w-[80%] rounded-xl px-3 py-2 text-sm ${m.role === 'agent' ? 'bg-gray-100 text-gray-800' : 'bg-brand-600 text-white ml-auto'}`}>
                    {m.text}
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      </div>
    </Modal>
  )
}
