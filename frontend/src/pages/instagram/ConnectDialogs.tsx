// Guided connect popup + post-connect setup checklist (Instagram accounts and Facebook Pages).
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import toast from 'react-hot-toast'
import { getError } from '@/utils'
import { instagramApi, type Channel, type SetupStatus } from './api/instagram'

function Shell({ title, subtitle, onClose, children, footer }: { title: string; subtitle?: string; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  useEffect(() => {
    const k = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k)
  }, [onClose])
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-0 sm:p-4" role="dialog" aria-modal aria-label={title}
      onMouseDown={e => { if (e.target === e.currentTarget) onClose() }}>
      <div className="flex max-h-full w-full max-w-lg flex-col overflow-hidden bg-white sm:rounded-2xl">
        <header className="flex items-start gap-3 border-b border-gray-100 px-5 py-4">
          <div className="flex-1"><h2 className="font-semibold text-gray-900">{title}</h2>{subtitle && <p className="mt-0.5 text-sm text-gray-500">{subtitle}</p>}</div>
          <button onClick={onClose} aria-label="Close" className="text-2xl leading-none text-gray-400 hover:text-gray-700">×</button>
        </header>
        <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>
        {footer && <footer className="flex items-center justify-end gap-2 border-t border-gray-100 px-5 py-3">{footer}</footer>}
      </div>
    </div>
  )
}

const pill = 'rounded-full px-5 py-2 text-sm font-medium'

/** Step 1: choose how to connect. Login is recommended; the token method stays available under "Advanced". */
export function ConnectMethodDialog({ channel, busy, onClose, onLogin, onToken }: {
  channel: Channel; busy?: boolean; onClose: () => void; onLogin: () => void; onToken?: () => void
}) {
  const fb = channel === 'facebook'
  const steps = fb
    ? ['Log in with Facebook', 'Choose the Page and allow access', 'We connect it and check everything works']
    : ['Log in with Instagram', 'Allow Teamzo to manage messages and comments', 'You come back here and we check everything works']
  return (
    <Shell title={fb ? 'Connect a Facebook Page' : 'Connect Instagram Account'} subtitle={fb ? 'For Messenger and Page-comment automation' : 'For comment, DM and story-reply automation'} onClose={onClose}
      footer={<button onClick={onClose} className={`${pill} border border-gray-300`}>Cancel</button>}>
      <ol className="space-y-3">
        {steps.map((t, i) => (
          <li key={t} className="flex items-center gap-3 text-sm text-gray-700">
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold">{i + 1}</span>{t}
          </li>))}
      </ol>
      {!fb && <p className="mt-4 rounded-lg bg-gray-50 p-3 text-xs text-gray-500">Your account must be an Instagram <b>Business or Creator</b> account. Personal accounts can’t be connected.</p>}
      <button onClick={onLogin} disabled={busy} className="mt-5 w-full rounded-full px-5 py-3 text-sm font-semibold text-white disabled:opacity-60"
        style={{ background: fb ? 'linear-gradient(45deg,#0084ff,#a334fa)' : 'linear-gradient(45deg,#f9ce34,#ee2a7b,#6228d7)' }}>
        {busy ? 'Opening…' : fb ? 'Continue with Facebook' : 'Continue with Instagram'}
      </button>
      {onToken && (
        <details className="mt-4 text-sm">
          <summary className="cursor-pointer text-gray-500">Advanced: connect with an access token</summary>
          <p className="mt-2 text-xs text-gray-500">For developers who already have a Page access token. Use this if the login option isn’t available for your account.</p>
          <button onClick={onToken} className="mt-2 rounded-full border border-gray-300 px-4 py-1.5 text-sm">Use an access token</button>
        </details>)}
    </Shell>
  )
}

type Tone = 'ok' | 'warn' | 'bad' | 'wait'
function Check({ tone, title, detail, action }: { tone: Tone; title: string; detail?: ReactNode; action?: ReactNode }) {
  const icon = { ok: '✓', warn: '!', bad: '✕', wait: '…' }[tone]
  const color = { ok: 'bg-green-100 text-green-700', warn: 'bg-amber-100 text-amber-700', bad: 'bg-red-100 text-red-700', wait: 'bg-gray-100 text-gray-500' }[tone]
  return (
    <li className="flex gap-3">
      <span className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${color}`} aria-hidden>{icon}</span>
      <div className="min-w-0 flex-1"><p className="text-sm font-medium text-gray-900">{title}</p>{detail && <p className="text-xs text-gray-500">{detail}</p>}</div>
      {action}
    </li>
  )
}

/** Shown right after connecting: live checklist of what is (and isn't) ready, with a fix where possible. */
export function SetupChecklistDialog({ accountId, channel, onClose }: { accountId: number; channel: Channel; onClose: () => void }) {
  const [st, setSt] = useState<SetupStatus | null>(null)
  const [busy, setBusy] = useState(false)
  const fb = channel === 'facebook'

  const load = useCallback(async () => {
    try { setSt((await instagramApi.setupStatus(accountId)).data) } catch (e) { toast.error(getError(e)) }
  }, [accountId])
  useEffect(() => { void load() }, [load])

  const resub = async () => {
    setBusy(true)
    try { setSt((await instagramApi.resubscribe(accountId)).data) } catch (e) { toast.error(getError(e)) } finally { setBusy(false) }
  }

  const a = st?.account
  const allGood = !!st && st.token_valid && st.webhook.subscribed && st.permissions.missing.length === 0
  const label = fb ? (a?.name ?? 'your Page') : `@${a?.username ?? ''}`
  const base = fb ? '/messenger' : '/instagram'

  return (
    <Shell title={allGood ? 'You’re all set 🎉' : 'Finishing setup'} subtitle={a ? `Connected ${label}` : 'Checking your connection…'} onClose={onClose}
      footer={<>
        <button onClick={load} className={`${pill} border border-gray-300`}>Re-check</button>
        <Link to={`${base}/autodm`} onClick={onClose} className={`${pill} bg-black text-white`}>Create your first AutoDM</Link>
      </>}>
      {!st ? <p className="py-6 text-center text-sm text-gray-400">Checking…</p> : (
        <ul className="space-y-4">
          <Check tone="ok" title={fb ? 'Page connected' : 'Account connected'}
            detail={<>{label} · {st.account.followers_count} {fb ? 'followers' : 'followers'}</>} />
          <Check tone={st.token_valid ? 'ok' : 'bad'} title={st.token_valid ? 'Access token is valid' : 'Access token was rejected'}
            detail={st.token_valid
              ? (st.token_expires_at ? `Valid until ${new Date(st.token_expires_at).toLocaleDateString()}${st.auto_refresh ? ' — renewed automatically' : ''}` : 'Does not expire')
              : (st.error ?? 'Reconnect the account.')} />
          {!fb && <Check tone={st.permissions.missing.length === 0 ? 'ok' : 'warn'} title="Permissions"
            detail={st.permissions.missing.length === 0 ? 'Messages, comments and profile access granted'
              : <>Missing: {st.permissions.missing.map(p => p.replace('instagram_business_', '')).join(', ')} — reconnect and allow everything.</>} />}
          <Check tone={st.webhook.subscribed ? 'ok' : 'warn'} title="Live updates (webhooks)"
            detail={st.webhook.subscribed ? 'Teamzo will be notified of new comments and messages'
              : (st.webhook.missing_fields.length ? `Not subscribed to: ${st.webhook.missing_fields.join(', ')}` : 'Not subscribed yet')}
            action={!st.webhook.subscribed && <button onClick={resub} disabled={busy} className="shrink-0 rounded-full border border-gray-300 px-3 py-1 text-xs disabled:opacity-50">{busy ? '…' : 'Fix'}</button>} />
          <Check tone={st.webhook_events_7d > 0 ? 'ok' : 'wait'} title="Test it"
            detail={st.webhook_events_7d > 0 ? `${st.webhook_events_7d} events received in the last 7 days`
              : (fb ? 'Comment on a Page post or message the Page from another account — it should appear in the Activity tab.'
                    : 'Comment on one of your posts from another account (a tester) — it should appear in the Activity tab.')} />
        </ul>)}
      {st && !allGood && <p className="mt-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">While your Meta app is in development mode, only accounts with a role on the app (admin, developer, tester) trigger live updates.</p>}
    </Shell>
  )
}
