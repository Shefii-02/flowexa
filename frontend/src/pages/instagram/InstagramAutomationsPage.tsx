// Keyword auto-DM rules: a comment (or DM) containing a keyword on a post/reel triggers a DM reply.
import { useEffect, useMemo, useState } from 'react'
import { Button, Input, Textarea, Badge, EmptyState, Modal, ConfirmModal } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { instagramApi, type IgAccount, type IgAutomation, type IgMedia } from './api/instagram'

interface RuleDraft {
  id?: number
  instagram_account_id: number
  name: string
  trigger: 'comment' | 'dm' | 'story_reply'
  keywords: string[]
  match_type: 'any' | 'all' | 'exact'
  media_scope: 'all' | 'selected'
  media_ids: string[]
  dm_message: string
  public_reply: string
  reply_once_per_user: boolean
  handoff_to_ai: boolean
  priority: number
}

const emptyRule = (accountId: number): RuleDraft => ({
  instagram_account_id: accountId,
  name: '',
  trigger: 'comment',
  keywords: [],
  match_type: 'any',
  media_scope: 'all',
  media_ids: [],
  dm_message: '',
  public_reply: '',
  reply_once_per_user: true,
  handoff_to_ai: false,
  priority: 0,
})

export default function InstagramAutomationsPage() {
  const [accounts, setAccounts] = useState<IgAccount[]>([])
  const [accountId, setAccountId] = useState<number | null>(null)
  const [rules, setRules] = useState<IgAutomation[]>([])
  const [media, setMedia] = useState<IgMedia[]>([])
  const [loading, setLoading] = useState(true)
  const [editor, setEditor] = useState<RuleDraft | null>(null)
  const [saving, setSaving] = useState(false)
  const [deleteId, setDeleteId] = useState<number | null>(null)
  const [kwInput, setKwInput] = useState('')

  const load = async (accId?: number) => {
    setLoading(true)
    try {
      const accRes = await instagramApi.accounts()
      const accs: IgAccount[] = accRes.data.accounts ?? []
      setAccounts(accs)
      const active = accId ?? accountId ?? accs[0]?.id ?? null
      setAccountId(active)
      if (active) {
        const r = await instagramApi.automations(active)
        setRules(r.data.automations ?? [])
      }
    } catch (e) { toast.error(getError(e)) }
    finally { setLoading(false) }
  }
  useEffect(() => { void load() }, [])

  useEffect(() => {
    // Load media lazily when an editor with "selected media" opens.
    if (editor?.media_scope === 'selected' && accountId && media.length === 0) {
      instagramApi.accountMedia(accountId).then(r => setMedia(r.data.media ?? [])).catch(() => {})
    }
  }, [editor?.media_scope, accountId]) // eslint-disable-line react-hooks/exhaustive-deps

  const account = useMemo(() => accounts.find(a => a.id === accountId), [accounts, accountId])

  const addKeyword = () => {
    const k = kwInput.trim().toLowerCase()
    if (k && editor && !editor.keywords.includes(k)) setEditor({ ...editor, keywords: [...editor.keywords, k] })
    setKwInput('')
  }

  const save = async () => {
    if (!editor) return
    if (!editor.name.trim() || editor.keywords.length === 0 || !editor.dm_message.trim()) {
      toast.error('Name, at least one keyword, and a DM message are required.'); return
    }
    setSaving(true)
    try {
      const payload = { ...editor, public_reply: editor.public_reply || null }
      if (editor.id) {
        const { id: _id, instagram_account_id: _acc, ...rest } = payload
        await instagramApi.updateAutomation(editor.id, rest)
        toast.success('Automation updated.')
      } else {
        await instagramApi.createAutomation(payload)
        toast.success('Automation created.')
      }
      setEditor(null)
      void load()
    } catch (e) { toast.error(getError(e)) }
    finally { setSaving(false) }
  }

  const toggle = async (r: IgAutomation) => {
    try { await instagramApi.toggleAutomation(r.id); setRules(list => list.map(x => x.id === r.id ? { ...x, is_active: !x.is_active } : x)) }
    catch (e) { toast.error(getError(e)) }
  }

  const remove = async () => {
    if (!deleteId) return
    try { await instagramApi.deleteAutomation(deleteId); toast.success('Deleted.'); setDeleteId(null); void load() }
    catch (e) { toast.error(getError(e)) }
  }

  const toDraft = (r: IgAutomation): RuleDraft => ({
    id: r.id,
    instagram_account_id: r.instagram_account_id,
    name: r.name, trigger: r.trigger, keywords: r.keywords ?? [],
    match_type: r.match_type, media_scope: r.media_scope,
    media_ids: r.media_ids ?? [], dm_message: r.dm_message, public_reply: r.public_reply ?? '',
    reply_once_per_user: r.reply_once_per_user, handoff_to_ai: r.handoff_to_ai, priority: r.priority,
  })

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h1 className="page-title">Comment & DM Automations</h1>
          <p className="page-sub">When someone comments a keyword on your post or reel, auto-send them a DM</p>
        </div>
        {accounts.length > 1 && (
          <select className="select max-w-[180px]" value={accountId ?? ''} onChange={e => { setAccountId(+e.target.value); void load(+e.target.value) }}>
            {accounts.map(a => <option key={a.id} value={a.id}>@{a.username}</option>)}
          </select>
        )}
        {account && <Button onClick={() => setEditor(emptyRule(account.id))}>+ New automation</Button>}
      </div>

      {loading ? (
        <p className="text-sm text-gray-400">Loading…</p>
      ) : !account ? (
        <EmptyState icon="📸" title="Connect an Instagram account first"
          desc="Automations run on a connected account" />
      ) : rules.length === 0 ? (
        <EmptyState icon="⚡" title="No automations yet"
          desc={`e.g. comment "price" on a reel → auto-DM your price list`}
          action={<Button onClick={() => setEditor(emptyRule(account.id))}>Create automation</Button>} />
      ) : (
        <div className="grid gap-3">
          {rules.map(r => (
            <div key={r.id} className="card p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <p className="font-medium text-gray-900">{r.name}</p>
                    <Badge variant={r.is_active ? 'green' : 'gray'}>{r.is_active ? 'On' : 'Off'}</Badge>
                    <Badge variant="blue">{r.trigger}</Badge>
                    {r.handoff_to_ai && <Badge variant="purple">→ AI</Badge>}
                  </div>
                  <p className="text-xs text-gray-500 mt-1">
                    {r.match_type} of: {r.keywords.map(k => <code key={k} className="mx-0.5 bg-gray-100 rounded px-1">{k}</code>)}
                    {' · '}{r.media_scope === 'all' ? 'all posts' : `${r.media_ids?.length ?? 0} selected`}
                    {r.triggered_count > 0 && ` · fired ${r.triggered_count}×`}
                  </p>
                  <p className="text-xs text-gray-400 mt-1 line-clamp-2">DM: {r.dm_message}</p>
                </div>
                <div className="flex gap-2 text-xs whitespace-nowrap">
                  <button onClick={() => toggle(r)} className="text-gray-500 hover:underline">{r.is_active ? 'Turn off' : 'Turn on'}</button>
                  <button onClick={() => setEditor(toDraft(r))} className="text-brand-600 hover:underline">Edit</button>
                  <button onClick={() => setDeleteId(r.id)} className="text-red-500 hover:underline">Delete</button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Editor */}
      <Modal open={editor !== null} onClose={() => setEditor(null)} size="lg"
        title={editor?.id ? 'Edit automation' : 'New automation'}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setEditor(null)}>Cancel</Button>
            <Button onClick={save} loading={saving}>Save</Button>
          </div>
        }>
        {editor && (
          <div className="space-y-3">
            <Input label="Name *" value={editor.name} onChange={e => setEditor({ ...editor, name: e.target.value })} placeholder="Reel → price list" />

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Trigger</label>
                <select className="select" value={editor.trigger} onChange={e => setEditor({ ...editor, trigger: e.target.value as 'comment' })}>
                  <option value="comment">Comment on a post/reel</option>
                  <option value="dm">Direct message</option>
                </select>
              </div>
              <div>
                <label className="label">Keyword match</label>
                <select className="select" value={editor.match_type} onChange={e => setEditor({ ...editor, match_type: e.target.value as 'any' })}>
                  <option value="any">Contains any keyword</option>
                  <option value="all">Contains all keywords</option>
                  <option value="exact">Message is exactly the keyword</option>
                </select>
              </div>
            </div>

            <div>
              <label className="label">Keywords *</label>
              <div className="flex flex-wrap gap-1.5 mb-1.5">
                {editor.keywords.map(k => (
                  <span key={k} className="inline-flex items-center gap-1 bg-brand-50 text-brand-700 text-xs rounded-full px-2 py-1">
                    {k}<button onClick={() => setEditor({ ...editor, keywords: editor.keywords.filter(x => x !== k) })}>×</button>
                  </span>
                ))}
              </div>
              <Input value={kwInput} onChange={e => setKwInput(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addKeyword() } }}
                placeholder="Type a keyword and press Enter" />
            </div>

            <div>
              <label className="label">Which posts</label>
              <select className="select" value={editor.media_scope} onChange={e => setEditor({ ...editor, media_scope: e.target.value as 'all' })}>
                <option value="all">All posts and reels</option>
                <option value="selected">Only selected posts</option>
              </select>
              {editor.media_scope === 'selected' && (
                <div className="mt-2 grid grid-cols-4 gap-2 max-h-52 overflow-y-auto">
                  {media.map(m => {
                    const on = editor.media_ids.includes(m.id)
                    return (
                      <button key={m.id} type="button"
                        onClick={() => setEditor({ ...editor, media_ids: on ? editor.media_ids.filter(x => x !== m.id) : [...editor.media_ids, m.id] })}
                        className={`relative rounded-lg overflow-hidden border-2 ${on ? 'border-brand-500' : 'border-transparent'}`}>
                        {m.thumbnail_url || m.media_url
                          ? <img src={m.thumbnail_url || m.media_url} alt="" className="w-full h-20 object-cover" />
                          : <div className="w-full h-20 bg-gray-100 flex items-center justify-center text-xs text-gray-400">{m.media_type}</div>}
                        {on && <span className="absolute top-1 right-1 text-brand-600 bg-white rounded-full text-xs w-4 h-4 flex items-center justify-center">✓</span>}
                      </button>
                    )
                  })}
                  {media.length === 0 && <p className="col-span-4 text-xs text-gray-400">Loading posts… (or none found)</p>}
                </div>
              )}
            </div>

            <Textarea label="DM to send *" rows={3} value={editor.dm_message}
              onChange={e => setEditor({ ...editor, dm_message: e.target.value })}
              placeholder={"Hi {{username}}! Here's the price list 👉 …"} />
            <p className="-mt-2 text-xs text-gray-400">{'{{username}}'} and {'{{name}}'} are replaced with the commenter's handle.</p>

            <Input label="Public comment reply (optional)" value={editor.public_reply}
              onChange={e => setEditor({ ...editor, public_reply: e.target.value })}
              placeholder="Check your DMs! 💌" />

            <div className="flex flex-wrap gap-4 text-sm">
              <label className="flex items-center gap-2">
                <input type="checkbox" checked={editor.reply_once_per_user}
                  onChange={e => setEditor({ ...editor, reply_once_per_user: e.target.checked })} />
                Only DM each person once
              </label>
              <label className="flex items-center gap-2">
                <input type="checkbox" checked={editor.handoff_to_ai}
                  onChange={e => setEditor({ ...editor, handoff_to_ai: e.target.checked })} />
                Let the AI agent handle their replies
              </label>
            </div>
          </div>
        )}
      </Modal>

      <ConfirmModal open={deleteId !== null} title="Delete automation?" message="This can't be undone."
        onConfirm={remove} onCancel={() => setDeleteId(null)} />
    </div>
  )
}
