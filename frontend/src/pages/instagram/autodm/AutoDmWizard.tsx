// "Create AutoDM" wizard: Where → What → Send → (conversation starter) → Review & launch.
import { useEffect, useMemo, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { getError } from '@/utils'
import { instagramApi, type AutoDm, type AutoDmFollowup, type AutoDmMessage, type AutoDmWizard, type IgAccount, type IgMedia } from '../api/instagram'
import { Avatar, CountInput, DEFAULT_COMMENT_REPLIES, DM_VARIABLES, InfoTip, KeywordInput, REPLY_TIPS, Segmented, Toggle, emptyWizard, needsStarter, triggerMeta } from './parts'
import type { AutoDmTrigger } from '../api/instagram'

type Step = 1 | 2 | 3 | 'starter' | 'review'
const pill = 'rounded-full px-5 py-2 text-sm font-medium'

interface Props {
  account: IgAccount
  trigger: AutoDmTrigger
  existing?: AutoDm | null
  onClose: () => void
  onSaved: () => void
}

export default function AutoDmWizard({ account, trigger, existing, onClose, onSaved }: Props) {
  const [w, setW] = useState<AutoDmWizard>(existing?.wizard ?? emptyWizard(trigger))
  const [name, setName] = useState(existing?.name ?? '')
  const [saving, setSaving] = useState(false)
  const [showExclude, setShowExclude] = useState((existing?.wizard.exclude_keywords.length ?? 0) > 0)
  const draftId = useRef<number | null>(existing?.id ?? null)
  const meta = triggerMeta(w.trigger_type)
  const isComment = w.trigger_type === 'post_comment' || w.trigger_type === 'live_comment'
  const hasWhere = w.trigger_type === 'post_comment'
  const starter = needsStarter(w)
  const order: Step[] = useMemo(() => [...(hasWhere ? [1 as Step] : []), 2, 3, ...(starter ? ['starter' as Step] : []), 'review'], [hasWhere, starter])
  const [step, setStep] = useState<Step>(hasWhere ? 1 : 2)
  const idx = order.indexOf(step)

  // ── confirm before leaving ──
  const [confirmLeave, setConfirmLeave] = useState(false)
  const askLeave = () => setConfirmLeave(true)
  const closeRef = useRef(onClose)
  closeRef.current = onClose
  useEffect(() => {
    // Browser back: keep the user on the page and ask first.
    window.history.pushState({ autodmWizard: true }, '')
    const onPop = () => { window.history.pushState({ autodmWizard: true }, ''); setConfirmLeave(true) }
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setConfirmLeave(true) }
    const onUnload = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = '' }
    window.addEventListener('popstate', onPop)
    window.addEventListener('keydown', onKey)
    window.addEventListener('beforeunload', onUnload)
    return () => {
      window.removeEventListener('popstate', onPop)
      window.removeEventListener('keydown', onKey)
      window.removeEventListener('beforeunload', onUnload)
    }
  }, [])
  const leave = () => { setConfirmLeave(false); closeRef.current() }
  const set = (patch: Partial<AutoDmWizard>) => setW(p => ({ ...p, ...patch }))

  // ── validation per step ──
  const problems = (s: Step): string | null => {
    if (s === 1 && w.media_scope === 'specific' && w.media_ids.length === 0) return 'Pick the post or reel for this automation.'
    if (s === 2) {
      if (w.keyword_mode === 'specific' && w.include_keywords.length === 0 && w.trigger_type !== 'story_reply') return 'Add at least one keyword (2+ characters) or choose “Any”.'
      if (isComment && w.comment_reply_enabled && w.comment_replies.some(r => !r.trim())) return 'Fill in all 3 public replies.'
    }
    if (s === 3) {
      const p = w.primary_message
      if (!p.text.trim() && !p.attachment?.url && !p.card?.title) return 'Write the primary DM.'
      if (p.buttons.some(b => !b.title.trim() || !/^https?:\/\//i.test(b.url))) return 'Every button needs a label and a valid https:// link.'
      if (w.followups.some(f => !f.text.trim())) return 'Every follow-up needs a message.'
    }
    return null
  }

  // Auto-save a draft after each step (once there is enough to name it).
  const saveDraft = async () => {
    const payload = { name: name || `${meta.title.replace('Auto-reply to ', '')} draft`, wizard: w, draft: true }
    try {
      if (draftId.current) await instagramApi.autodmUpdate(draftId.current, payload)
      else draftId.current = (await instagramApi.autodmCreate({ instagram_account_id: account.id, ...payload })).data.automation.id
    } catch { /* drafts are best-effort */ }
  }

  const next = () => {
    const err = problems(step)
    if (err) return toast.error(err)
    void saveDraft()
    setStep(order[idx + 1])
  }
  const back = () => (idx > 0 ? setStep(order[idx - 1]) : askLeave())

  const launch = async () => {
    setSaving(true)
    const payload = { name: name || `${w.include_keywords[0] ?? 'Any'} · ${meta.title.replace('Auto-reply to ', '')}`, wizard: w, draft: false }
    try {
      if (draftId.current) await instagramApi.autodmUpdate(draftId.current, payload)
      else await instagramApi.autodmCreate({ instagram_account_id: account.id, ...payload })
      toast.success('AutoDM launched 🚀'); onSaved()
    } catch (e) { toast.error(getError(e)) } finally { setSaving(false) }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-0 sm:p-4" role="dialog" aria-modal onMouseDown={e => { if (e.target === e.currentTarget) askLeave() }}>
      <div className="flex max-h-full w-full max-w-2xl flex-col overflow-hidden bg-white sm:rounded-2xl">
        <header className="flex items-center gap-3 border-b border-gray-100 px-5 py-4">
          <span className={`flex h-10 w-10 items-center justify-center rounded-full text-lg ${meta.tone}`}>{meta.icon}</span>
          <div className="min-w-0 flex-1">
            <p className="truncate font-semibold text-gray-900">{meta.title}</p>
            <p className="text-xs text-gray-500">{step === 'review' ? 'Launch AutoDM' : `Step ${Math.min(idx + 1, hasWhere ? 3 : 2)} of ${hasWhere ? 3 : 2}`}</p>
          </div>
          <div className="flex items-center gap-2"><Avatar account={account} size={26} /><span className="hidden text-sm text-gray-600 sm:inline">@{account.username}</span></div>
          <button onClick={askLeave} aria-label="Close" className="ml-1 text-xl text-gray-400 hover:text-gray-700">×</button>
        </header>

        <div className="flex-1 overflow-y-auto px-5 py-5 space-y-5">
          {step === 1 && <WhereStep account={account} w={w} set={set} />}
          {step === 2 && <WhatStep w={w} set={set} isComment={isComment} showExclude={showExclude} setShowExclude={setShowExclude} />}
          {step === 3 && <SendStep w={w} set={set} isComment={isComment} />}
          {step === 'starter' && <StarterStep w={w} set={set} />}
          {step === 'review' && <ReviewStep w={w} set={set} account={account} name={name} setName={setName} />}
        </div>

        <footer className="flex items-center justify-between border-t border-gray-100 px-5 py-3">
          <button onClick={back} className={`${pill} border border-gray-300 bg-white text-gray-800`}>{idx > 0 ? 'Back' : 'Cancel'}</button>
          {step === 'review'
            ? <button onClick={launch} disabled={saving} className={`${pill} bg-black text-white disabled:opacity-50`}>{saving ? 'Launching…' : 'Confirm & launch'}</button>
            : <button onClick={next} className={`${pill} bg-black text-white`}>Next</button>}
        </footer>
      </div>

      {confirmLeave && (
        <div className="absolute inset-0 z-10 flex items-center justify-center bg-black/40 p-4" onMouseDown={e => e.stopPropagation()}>
          <div role="alertdialog" aria-labelledby="leave-title" className="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
            <h4 id="leave-title" className="font-semibold text-gray-900">Leave this process?</h4>
            <p className="mt-1 text-sm text-gray-600">Steps you have already completed are saved as a draft. Changes on this step will be lost.</p>
            <div className="mt-4 flex justify-end gap-2">
              <button autoFocus onClick={() => setConfirmLeave(false)} className={`${pill} border border-gray-300 bg-white text-gray-800`}>No, stay</button>
              <button onClick={leave} className={`${pill} bg-black text-white`}>Yes, leave</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

// ───────────────────────── Step 1 – Where ─────────────────────────
function WhereStep({ account, w, set }: { account: IgAccount; w: AutoDmWizard; set: (p: Partial<AutoDmWizard>) => void }) {
  const [media, setMedia] = useState<IgMedia[]>([])
  const [loading, setLoading] = useState(true)
  const [shown, setShown] = useState(12)
  useEffect(() => {
    instagramApi.accountMedia(account.id).then(r => setMedia(r.data.media ?? [])).catch(e => toast.error(getError(e))).finally(() => setLoading(false))
  }, [account.id])
  const sentinel = useRef<HTMLDivElement>(null)
  useEffect(() => {
    const el = sentinel.current
    if (!el) return
    const io = new IntersectionObserver(es => { if (es[0].isIntersecting) setShown(n => n + 12) })
    io.observe(el); return () => io.disconnect()
  }, [media.length])

  const pick = (m: IgMedia) => set({ media_ids: [m.id], media_preview: { thumbnail_url: m.thumbnail_url ?? m.media_url, caption: m.caption } })
  return (
    <>
      <h3 className="font-semibold text-gray-900">Where should this run?</h3>
      <Segmented value={w.media_scope} onChange={v => set({ media_scope: v })}
        options={[{ v: 'specific', label: 'Specific Post/Reel' }, { v: 'any', label: 'Any Post/Reel' }, { v: 'next', label: 'Next Post/Reel' }]} />
      {w.media_scope === 'any' && <p className="text-sm text-gray-500">Runs on every post and reel on @{account.username}.</p>}
      {w.media_scope === 'next' && <p className="text-sm text-gray-500">Runs on the first post or reel you publish from now on. It locks to that post once it sees a comment.</p>}
      {w.media_scope === 'specific' && (loading ? <p className="text-sm text-gray-400">Loading posts…</p> : (
        <div className="grid grid-cols-3 gap-2">
          {media.slice(0, shown).map(m => {
            const sel = w.media_ids.includes(m.id), video = m.media_type === 'VIDEO'
            return (
              <button key={m.id} type="button" onClick={() => pick(m)} aria-pressed={sel}
                className={`relative aspect-square overflow-hidden rounded-xl border-2 bg-gray-100 ${sel ? 'border-black' : 'border-transparent'}`}>
                {(m.thumbnail_url || m.media_url) && <img src={m.thumbnail_url ?? m.media_url} alt={m.caption?.slice(0, 40) ?? 'Post'} className="h-full w-full object-cover" loading="lazy" />}
                {video && <span className="absolute left-1 top-1 rounded bg-black/70 px-1.5 text-[10px] text-white">▶ Reel</span>}
                {m.permalink && <a href={m.permalink} target="_blank" rel="noreferrer" onClick={e => e.stopPropagation()} aria-label="Open on Instagram"
                  className="absolute right-1 top-1 rounded bg-white/80 px-1.5 text-[11px]">⤢</a>}
                {sel && <span className="absolute inset-0 flex items-center justify-center bg-black/30 text-2xl text-white">✓</span>}
              </button>
            )
          })}
          <div ref={sentinel} className="col-span-3 h-1" />
          {media.length === 0 && <p className="col-span-3 text-sm text-gray-400">No posts found on this account.</p>}
        </div>
      ))}
    </>
  )
}

// ───────────────────────── Step 2 – What ─────────────────────────
function WhatStep({ w, set, isComment, showExclude, setShowExclude }: {
  w: AutoDmWizard; set: (p: Partial<AutoDmWizard>) => void; isComment: boolean; showExclude: boolean; setShowExclude: (v: boolean) => void
}) {
  const noun = w.trigger_type === 'dm' ? 'message' : w.trigger_type === 'story_reply' ? 'story reply' : 'comment'
  return (
    <>
      <h3 className="font-semibold text-gray-900">What should trigger it?</h3>
      <Segmented value={w.keyword_mode} onChange={v => set({ keyword_mode: v })}
        options={[{ v: 'specific', label: 'With a specific keyword' }, { v: 'any', label: `Any ${noun}` }]} />
      {w.keyword_mode === 'specific' && <KeywordInput values={w.include_keywords} onChange={v => set({ include_keywords: v })} />}
      {w.keyword_mode === 'specific' && !showExclude && (
        <button type="button" onClick={() => setShowExclude(true)} className="text-sm text-blue-600">Add excluded keywords?</button>)}
      {showExclude && (<div><p className="mb-1 text-sm text-gray-600">Don’t trigger when the {noun} contains…</p>
        <KeywordInput values={w.exclude_keywords} onChange={v => set({ exclude_keywords: v })} placeholder="Excluded keyword" /></div>)}

      {isComment && (
        <div className="space-y-3 rounded-xl border border-gray-200 p-4">
          <label className="flex items-center gap-2 text-sm text-gray-800">
            <input type="checkbox" checked={w.comment_reply_enabled} onChange={e => {
              const on = e.target.checked
              // Pre-fill any empty reply with a sensible default the user can edit.
              set({ comment_reply_enabled: on, comment_replies: on ? w.comment_replies.map((r, i) => r.trim() ? r : DEFAULT_COMMENT_REPLIES[i]) : w.comment_replies })
            }} />
            Would you like to reply to the comments as well?
          </label>
          {w.comment_reply_enabled && (<>
            {w.comment_replies.map((r, i) => (
              <div key={i}>
                <div className="mb-1 flex items-center justify-between text-xs font-medium text-gray-600">Reply {i + 1}<InfoTip tip={REPLY_TIPS[i]} label={`About reply ${i + 1}`} /></div>
                <CountInput value={r} max={140} placeholder={DEFAULT_COMMENT_REPLIES[i]}
                  onChange={v => set({ comment_replies: w.comment_replies.map((x, j) => (j === i ? v : x)) })} />
                <p className="mt-1 rounded-lg bg-gray-50 px-2 py-1 text-[11px] text-gray-500">Preview: <span className="text-gray-700">@you · {r.trim() || DEFAULT_COMMENT_REPLIES[i]}</span></p>
              </div>))}
            <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800">Repeating the same public reply can look like spam to Instagram. We send one of your 3 replies at random to reduce that risk.</p>
          </>)}
        </div>
      )}
    </>
  )
}

// ───────────────────────── Step 3 – Send ─────────────────────────
function SendStep({ w, set, isComment }: { w: AutoDmWizard; set: (p: Partial<AutoDmWizard>) => void; isComment: boolean }) {
  const setP = (patch: Partial<AutoDmWizard['primary_message']>) => set({ primary_message: { ...w.primary_message, ...patch } })
  const setFollow = (i: number, patch: Partial<AutoDmFollowup>) => set({ followups: w.followups.map((f, j) => (j === i ? { ...f, ...patch } : f)) })
  return (
    <>
      <h3 className="font-semibold text-gray-900">What should we send?</h3>
      <div className="space-y-3">
        <div className="rounded-xl border border-gray-200 p-4 space-y-3">
          <Toggle on={w.follow_gate.enabled} onChange={v => set({ follow_gate: { ...w.follow_gate, enabled: v } })} label="a DM asking to follow you" />
          {w.follow_gate.enabled && <div className="space-y-3">
            <Field label="Opening message"><CountInput multiline max={640} value={w.follow_gate.opening} onChange={v => set({ follow_gate: { ...w.follow_gate, opening: v } })} /></Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label="Profile button"><CountInput max={20} value={w.follow_gate.visit_label} onChange={v => set({ follow_gate: { ...w.follow_gate, visit_label: v } })} /></Field>
              <Field label="Confirm button"><CountInput max={20} value={w.follow_gate.confirm_label} onChange={v => set({ follow_gate: { ...w.follow_gate, confirm_label: v } })} /></Field>
            </div>
            <Field label="Follow Check / Retry message"><CountInput multiline max={640} value={w.follow_gate.retry_message} onChange={v => set({ follow_gate: { ...w.follow_gate, retry_message: v } })} /></Field>
            <FailAction label="Retry 3 times, then" value={w.follow_gate.fail_action} onChange={v => set({ follow_gate: { ...w.follow_gate, fail_action: v } })} />
          </div>}
        </div>
        <div className="rounded-xl border border-gray-200 p-4 space-y-3">
          <Toggle on={w.email_gate.enabled} onChange={v => set({ email_gate: { ...w.email_gate, enabled: v } })} label="a DM asking to share their email" />
          {w.email_gate.enabled && <div className="space-y-3">
            <Field label="Opening message"><CountInput multiline max={640} value={w.email_gate.opening} onChange={v => set({ email_gate: { ...w.email_gate, opening: v } })} /></Field>
            <Field label="Retry message"><CountInput multiline max={640} value={w.email_gate.retry_message} onChange={v => set({ email_gate: { ...w.email_gate, retry_message: v } })} /></Field>
            <FailAction label="Retry 3 times, then" value={w.email_gate.fail_action} onChange={v => set({ email_gate: { ...w.email_gate, fail_action: v } })} />
          </div>}
        </div>
      </div>

      <h4 className="pt-2 text-sm font-semibold text-gray-900">Then send the primary DM…</h4>
      <MessageEditor m={w.primary_message} kind={w.primary_message.kind} onKind={k => setP({ kind: k })} onChange={p => setP(p)} />

      {w.followups.map((f, i) => (
        <div key={i}>
          <div className="my-2 flex items-center gap-2 text-xs text-gray-500"><span className="h-6 w-px bg-gray-300 mx-auto" /></div>
          <div className="space-y-3 rounded-xl border border-gray-200 p-4">
            <div className="flex items-center gap-2">
              <select value={f.condition} onChange={e => setFollow(i, { condition: e.target.value as AutoDmFollowup['condition'] })}
                className="flex-1 rounded-xl border border-gray-200 px-3 py-2 text-sm">
                <option value="after_delay">After the delay</option>
                <option value="link_not_clicked">If the link was not clicked</option>
                <option value="no_response">If they haven’t responded</option>
              </select>
              <span className="text-sm text-gray-500">after</span>
              <input type="number" min={1} max={f.delay_unit === 'hours' ? 23 : 1380} value={f.delay_amount}
                onChange={e => setFollow(i, { delay_amount: Math.max(1, Number(e.target.value)) })} className="w-16 rounded-xl border border-gray-200 px-2 py-2 text-sm" />
              <select value={f.delay_unit} onChange={e => setFollow(i, { delay_unit: e.target.value as 'minutes' | 'hours', delay_amount: 1 })}
                className="rounded-xl border border-gray-200 px-2 py-2 text-sm"><option value="minutes">min</option><option value="hours">hours</option></select>
              <button type="button" aria-label="Delete follow-up" onClick={() => set({ followups: w.followups.filter((_, j) => j !== i) })} className="text-gray-400 hover:text-red-600">🗑</button>
            </div>
            <p className="text-[11px] text-gray-400">Max 23 hours — Instagram only allows messages within 24h of the user’s last message.</p>
            <MessageEditor m={f} kind="text" lockKind onKind={() => {}} onChange={p => setFollow(i, p)} />
          </div>
        </div>
      ))}
      <button type="button" onClick={() => set({ followups: [...w.followups, { condition: 'link_not_clicked', delay_amount: 1, delay_unit: 'hours', text: '', buttons: [] }] })}
        className="w-full rounded-xl border-2 border-dashed border-blue-300 py-3 text-sm text-blue-600">+ Add follow-up message</button>
    </>
  )
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <div><p className="mb-1 text-xs font-medium text-gray-600">{label}</p>{children}</div>
}
function FailAction({ label, value, onChange }: { label: string; value: 'send' | 'stop'; onChange: (v: 'send' | 'stop') => void }) {
  return (<div className="flex items-center gap-2 text-sm text-gray-600">{label}
    <select value={value} onChange={e => onChange(e.target.value as 'send' | 'stop')} className="rounded-xl border border-gray-200 px-2 py-1.5">
      <option value="send">Send them the DM anyway</option><option value="stop">Don’t send</option></select></div>)
}

/** DM content editor: text + buttons, image/video, card, voice. */
function MessageEditor({ m, kind, onKind, onChange, lockKind }: {
  m: AutoDmMessage; kind: 'text' | 'media' | 'card' | 'voice'; onKind: (k: 'text' | 'media' | 'card' | 'voice') => void
  onChange: (p: Partial<AutoDmMessage>) => void; lockKind?: boolean
}) {
  const [sources, setSources] = useState<{ key: string; label: string; items: { label: string; url: string }[] }[]>([])
  useEffect(() => { instagramApi.autodmLinkSources().then(r => setSources(r.data.sources)).catch(() => {}) }, [])
  const [menu, setMenu] = useState(false)
  const insert = (t: string) => { onChange({ text: (m.text + ' ' + t).trim().slice(0, 1000) }); setMenu(false) }
  const setBtn = (i: number, p: Partial<{ title: string; url: string }>) => onChange({ buttons: m.buttons.map((b, j) => (j === i ? { ...b, ...p } : b)) })
  const attType = kind === 'voice' ? 'audio' : (m.attachment?.type ?? 'image')

  return (
    <div className="space-y-3">
      {!lockKind && (
        <select value={kind} onChange={e => onKind(e.target.value as 'text')} className="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
          <option value="text">Text + Button</option><option value="media">Image / Video</option><option value="card">Card</option><option value="voice">Voice message</option>
        </select>)}
      {(kind === 'media' || kind === 'voice') && (
        <div className="flex gap-2">
          {kind === 'media' && <select value={attType} onChange={e => onChange({ attachment: { type: e.target.value as 'image' | 'video', url: m.attachment?.url ?? '' } })}
            className="rounded-xl border border-gray-200 px-2 text-sm"><option value="image">Image</option><option value="video">Video</option></select>}
          <input value={m.attachment?.url ?? ''} onChange={e => onChange({ attachment: { type: attType as 'image', url: e.target.value } })}
            placeholder="Public https:// URL of the file" className="flex-1 rounded-xl border border-gray-200 px-3 py-2 text-sm" />
        </div>)}
      {kind === 'card' && (<div className="space-y-2">
        <CountInput max={80} placeholder="Card title" value={m.card?.title ?? ''} onChange={v => onChange({ card: { subtitle: '', image_url: '', ...m.card, title: v } })} />
        <CountInput max={80} placeholder="Subtitle" value={m.card?.subtitle ?? ''} onChange={v => onChange({ card: { title: '', image_url: '', ...m.card, subtitle: v } })} />
        <input value={m.card?.image_url ?? ''} onChange={e => onChange({ card: { title: '', subtitle: '', ...m.card, image_url: e.target.value } })}
          placeholder="Image URL (https://)" className="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
      </div>)}
      {kind !== 'voice' && kind !== 'card' && (
        <div className="relative rounded-xl border border-dashed border-gray-300 p-3">
          <CountInput multiline max={1000} value={m.text} placeholder="Write your DM…" onChange={v => onChange({ text: v })} />
          <div className="relative mt-2">
            <button type="button" onClick={() => setMenu(v => !v)} className="text-sm text-blue-600"># Add a variable</button>
            {menu && <div className="absolute z-10 mt-1 w-64 rounded-xl border border-gray-200 bg-white p-1 shadow-lg">
              {DM_VARIABLES.map(v => <button key={v.token} type="button" onClick={() => insert(v.token)} className="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-50">{v.label}</button>)}</div>}
          </div>
        </div>)}
      {kind !== 'voice' && m.buttons.map((b, i) => (
        <div key={i} className="space-y-2 rounded-xl border border-dashed border-gray-300 p-3">
          <div className="flex items-center justify-between text-xs font-medium text-gray-600">Button #{i + 1}
            <button type="button" aria-label="Remove button" onClick={() => onChange({ buttons: m.buttons.filter((_, j) => j !== i) })} className="text-gray-400 hover:text-red-600">🗑</button></div>
          <CountInput max={20} placeholder="Button label" value={b.title} onChange={v => setBtn(i, { title: v })} />
          <LinkPicker value={b.url} sources={sources} onChange={url => setBtn(i, { url })} />
        </div>))}
      {kind !== 'voice' && m.buttons.length < 3 && (
        <button type="button" onClick={() => onChange({ buttons: [...m.buttons, { title: '', url: '' }] })} className="text-sm text-blue-600">+ Add another button</button>)}
    </div>
  )
}

function LinkPicker({ value, sources, onChange }: { value: string; sources: { key: string; label: string; items: { label: string; url: string }[] }[]; onChange: (u: string) => void }) {
  const [q, setQ] = useState('')
  const groups = sources.map(g => ({ ...g, items: g.items.filter(i => i.label.toLowerCase().includes(q.toLowerCase())) })).filter(g => g.items.length)
  return (
    <div className="space-y-1.5">
      <input value={value} onChange={e => onChange(e.target.value)} placeholder="https://… (custom URL)" className="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
      {sources.some(g => g.items.length > 0) && (<>
        <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search forms, products, bookings…" className="w-full rounded-xl border border-gray-100 bg-gray-50 px-3 py-1.5 text-xs" />
        <div className="max-h-32 overflow-y-auto">
          {groups.map(g => (<div key={g.key}><p className="px-1 pt-1 text-[10px] font-semibold uppercase text-gray-400">{g.label}</p>
            {g.items.map(i => <button key={i.url} type="button" onClick={() => onChange(i.url)} className="block w-full rounded px-2 py-1 text-left text-xs hover:bg-gray-50">{i.label}</button>)}</div>))}
        </div></>)}
    </div>
  )
}

// ───────────────────────── Conversation starter ─────────────────────────
function StarterStep({ w, set }: { w: AutoDmWizard; set: (p: Partial<AutoDmWizard>) => void }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <h3 className="font-semibold text-gray-900">Conversation starter</h3>
      <p className="text-sm text-gray-600">Using variables and follow-up DMs require you to use a conversation starter DM.</p>
      <div className="space-y-3 rounded-xl border border-dashed border-gray-300 p-3">
        <CountInput multiline max={640} value={w.starter_message.text} onChange={v => set({ starter_message: { ...w.starter_message, text: v } })} />
        <Field label="Button label"><CountInput max={20} value={w.starter_message.button} onChange={v => set({ starter_message: { ...w.starter_message, button: v } })} /></Field>
      </div>
      <button type="button" onClick={() => setOpen(!open)} className="text-sm text-gray-700">{open ? '▾' : '▸'} Why should I send a conversation starter DM?</button>
      {open && <div className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Instagram lets a business send only <b>one</b> private reply to a comment, and further messages only after the person replies or taps a button. The tap on this button opens the 24-hour messaging window that your follow/email steps, variables and follow-ups need.</div>}
    </>
  )
}

// ───────────────────────── Review ─────────────────────────
function ReviewStep({ w, set, account, name, setName }: { w: AutoDmWizard; set: (p: Partial<AutoDmWizard>) => void; account: IgAccount; name: string; setName: (v: string) => void }) {
  const em = w.send_email ?? { enabled: false, subject: '', body: '' }
  const what = { post_comment: 'comments on this specific post', dm: 'sends you a DM', story_reply: 'replies to your story', live_comment: 'comments on your Live' }[w.trigger_type]
  const scope = w.trigger_type === 'post_comment' ? ({ specific: 'comments on this specific post', any: 'comments on any post or reel', next: 'comments on your next post or reel' }[w.media_scope]) : what
  const Bubble = ({ text, btns }: { text: string; btns?: string[] }) => (
    <div className="max-w-sm rounded-2xl bg-gray-100 p-3 text-sm text-gray-800">
      <p className="whitespace-pre-wrap">{text || '—'}</p>
      {btns?.map(b => <div key={b} className="mt-2 rounded-lg bg-white py-1.5 text-center text-xs font-medium text-blue-600 shadow-sm">{b}</div>)}
    </div>)
  const Row = ({ label, children }: { label: string; children: React.ReactNode }) => (
    <div className="relative space-y-1.5 border-l-2 border-gray-200 pl-4 pb-4"><p className="text-xs font-medium uppercase tracking-wide text-gray-400">{label}</p>{children}</div>)
  const p = w.primary_message
  const condSentence = (f: AutoDmFollowup) => ({ after_delay: 'Then', link_not_clicked: 'If the link wasn’t clicked,', no_response: 'If they haven’t responded,' }[f.condition])
  return (
    <>
      <h3 className="font-semibold text-gray-900">Awesome! Let’s review once before we launch!</h3>
      <Field label="Automation name"><CountInput max={150} value={name} onChange={setName} placeholder="e.g. Free guide giveaway" /></Field>
      <div className="pt-2">
        <Row label="When someone…">
          <div className="flex items-center gap-3">
            {w.media_preview?.thumbnail_url && w.trigger_type === 'post_comment' && w.media_scope === 'specific' && <img src={w.media_preview.thumbnail_url} alt="" className="h-12 w-12 rounded-lg object-cover" />}
            <p className="text-sm"><b>{scope}</b> on @{account.username}{w.media_preview?.caption && w.media_scope === 'specific' ? <span className="block max-w-xs truncate text-xs text-gray-500">{w.media_preview.caption}</span> : null}</p>
          </div>
        </Row>
        <Row label={w.keyword_mode === 'any' ? 'With any text' : 'That includes keywords'}>
          <div className="flex flex-wrap gap-1.5">{w.keyword_mode === 'any' ? <span className="text-sm text-gray-600">Anything</span> : w.include_keywords.map(k => <span key={k} className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs">{k}</span>)}
            {w.exclude_keywords.length > 0 && <span className="text-xs text-gray-500">· except {w.exclude_keywords.join(', ')}</span>}</div>
        </Row>
        {w.comment_reply_enabled && <Row label="Reply publicly with one of"><ul className="list-disc pl-5 text-sm text-gray-700">{w.comment_replies.map((r, i) => <li key={i}>{r}</li>)}</ul></Row>}
        {needsStarter(w) && <Row label="Send a conversation starter"><Bubble text={w.starter_message.text} btns={[w.starter_message.button]} /></Row>}
        {w.follow_gate.enabled && <Row label="Ask them to follow you"><Bubble text={w.follow_gate.opening} btns={[w.follow_gate.visit_label, w.follow_gate.confirm_label]} /><p className="text-xs text-gray-500">Retries 3×, then {w.follow_gate.fail_action === 'send' ? 'sends the DM anyway' : 'stops'}.</p></Row>}
        {w.email_gate.enabled && <Row label="Ask for their email"><Bubble text={w.email_gate.opening} /><p className="text-xs text-gray-500">Retries 3×, then {w.email_gate.fail_action === 'send' ? 'sends the DM anyway' : 'stops'}. Saved to CRM.</p></Row>}
        <Row label="Send the primary DM"><Bubble text={p.card?.title ?? p.text ?? (p.attachment ? `[${p.attachment.type}]` : '')} btns={p.buttons.map(b => b.title)} /></Row>
        {w.followups.map((f, i) => (<Row key={i} label="Follow-up"><p className="text-sm text-gray-700">{condSentence(f)} after <b>{f.delay_amount} {f.delay_unit === 'hours' ? 'h' : 'min'}</b>, send:</p><Bubble text={f.text} btns={f.buttons.map(b => b.title)} /></Row>))}
      </div>
      <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
        <p className="text-sm font-medium text-blue-900">Send Automated Email</p>
        <p className="mb-2 text-xs text-blue-700">When an email is captured, send a message through your Teamzo email settings.</p>
        <Toggle on={em.enabled} onChange={v => set({ send_email: { ...em, enabled: v } })} label="Send an email when an email is captured" />
        {em.enabled && <div className="mt-3 space-y-2">
          <CountInput max={150} placeholder="Subject" value={em.subject} onChange={v => set({ send_email: { ...em, subject: v } })} />
          <CountInput multiline max={2000} placeholder="Email body — {{name}} and {{email}} work here too" value={em.body} onChange={v => set({ send_email: { ...em, body: v } })} />
        </div>}
      </div>
    </>
  )
}
