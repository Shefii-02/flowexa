// Comment moderation: pick a post, read its comments, reply to / hide / delete any of them.
import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Button, Textarea, Badge, EmptyState, Spinner } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import { instagramApi, type IgAccount, type IgMedia, type IgComment } from './api/instagram'

function repliesOf(c: IgComment): IgComment[] {
  if (!c.replies) return []
  return Array.isArray(c.replies) ? c.replies : (c.replies.data ?? [])
}

export default function InstagramCommentsPage() {
  const [params, setParams] = useSearchParams()
  const [accounts, setAccounts] = useState<IgAccount[]>([])
  const [media, setMedia] = useState<IgMedia[]>([])
  const [comments, setComments] = useState<IgComment[]>([])
  const [loadingMedia, setLoadingMedia] = useState(false)
  const [loadingComments, setLoadingComments] = useState(false)
  const [selectedMedia, setSelectedMedia] = useState<IgMedia | null>(null)
  const [replyTo, setReplyTo] = useState<IgComment | null>(null)
  const [replyText, setReplyText] = useState('')
  const [busy, setBusy] = useState<string | null>(null)

  const accountId = Number(params.get('account')) || accounts[0]?.id || null

  useEffect(() => {
    instagramApi.accounts().then(r => setAccounts(r.data.accounts ?? [])).catch(e => toast.error(getError(e)))
  }, [])

  useEffect(() => {
    if (!accountId) return
    setLoadingMedia(true); setSelectedMedia(null); setComments([])
    instagramApi.accountMedia(accountId)
      .then(r => setMedia((r.data.media ?? []).filter((m: IgMedia) => m.thumbnail_url || m.media_url)))
      .catch(e => toast.error(getError(e)))
      .finally(() => setLoadingMedia(false))
  }, [accountId])

  const openMedia = async (m: IgMedia) => {
    if (!accountId) return
    setSelectedMedia(m); setLoadingComments(true)
    try {
      const r = await instagramApi.mediaComments(accountId, m.id)
      setComments(r.data.comments ?? [])
    } catch (e) { toast.error(getError(e)) }
    finally { setLoadingComments(false) }
  }

  const refreshComments = async () => { if (selectedMedia) void openMedia(selectedMedia) }

  const sendReply = async () => {
    if (!accountId || !replyTo || !replyText.trim()) return
    setBusy(`reply-${replyTo.id}`)
    try {
      await instagramApi.replyComment(accountId, replyTo.id, replyText.trim())
      toast.success('Reply posted.')
      setReplyTo(null); setReplyText('')
      void refreshComments()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(null) }
  }

  const toggleHide = async (c: IgComment) => {
    if (!accountId) return
    setBusy(`hide-${c.id}`)
    try {
      await instagramApi.hideComment(accountId, c.id, !c.hidden)
      toast.success(c.hidden ? 'Comment unhidden.' : 'Comment hidden.')
      void refreshComments()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(null) }
  }

  const remove = async (c: IgComment) => {
    if (!accountId) return
    if (!confirm('Delete this comment? This cannot be undone.')) return
    setBusy(`del-${c.id}`)
    try {
      await instagramApi.deleteComment(accountId, c.id)
      toast.success('Comment deleted.')
      void refreshComments()
    } catch (e) { toast.error(getError(e)) }
    finally { setBusy(null) }
  }

  const totalComments = useMemo(
    () => comments.length + comments.reduce((n, c) => n + repliesOf(c).length, 0),
    [comments],
  )

  if (accounts.length === 0) {
    return <EmptyState icon="💬" title="Connect an Instagram account first"
      desc="Comment moderation needs a connected account with instagram_manage_comments." />
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h1 className="page-title">Comments</h1>
          <p className="page-sub">Read, reply to, hide or delete comments on your posts and reels</p>
        </div>
        {accounts.length > 1 && (
          <select className="select w-56" value={accountId ?? ''} onChange={e => setParams({ account: e.target.value })}>
            {accounts.map(a => <option key={a.id} value={a.id}>@{a.username || a.ig_user_id}</option>)}
          </select>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-5">
        {/* Post picker */}
        <div className="card p-3">
          <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide px-1 pb-2">Posts &amp; reels</p>
          {loadingMedia ? (
            <div className="flex justify-center py-8"><Spinner /></div>
          ) : media.length === 0 ? (
            <p className="text-sm text-gray-400 px-1">No posts found.</p>
          ) : (
            <div className="grid grid-cols-3 gap-1.5 max-h-[560px] overflow-y-auto">
              {media.map(m => (
                <button key={m.id} type="button" onClick={() => openMedia(m)}
                  className={`relative rounded-lg overflow-hidden border-2 aspect-square ${selectedMedia?.id === m.id ? 'border-brand-500' : 'border-transparent'}`}>
                  <img src={m.thumbnail_url || m.media_url} alt="" className="w-full h-full object-cover" />
                  {(m.comments_count ?? 0) > 0 && (
                    <span className="absolute bottom-1 right-1 bg-black/60 text-white text-[10px] px-1 rounded">
                      💬 {m.comments_count}
                    </span>
                  )}
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Comments panel */}
        <div className="card p-5 min-h-[400px]">
          {!selectedMedia ? (
            <div className="h-full flex items-center justify-center text-sm text-gray-400 py-16">
              Select a post on the left to see its comments
            </div>
          ) : loadingComments ? (
            <div className="flex justify-center py-16"><Spinner /></div>
          ) : (
            <div className="space-y-4">
              <div className="flex items-center gap-3 pb-3 border-b border-gray-100">
                <img src={selectedMedia.thumbnail_url || selectedMedia.media_url} alt="" className="w-10 h-10 rounded object-cover" />
                <p className="text-sm text-gray-600 line-clamp-1 flex-1">{selectedMedia.caption || '(no caption)'}</p>
                <Badge variant="gray">{totalComments} comment{totalComments === 1 ? '' : 's'}</Badge>
              </div>

              {comments.length === 0 ? (
                <p className="text-sm text-gray-400 py-6 text-center">No comments on this post yet.</p>
              ) : comments.map(c => (
                <div key={c.id} className="space-y-2">
                  <CommentRow c={c} busy={busy}
                    onReply={() => { setReplyTo(c); setReplyText('') }}
                    onHide={() => toggleHide(c)}
                    onDelete={() => remove(c)} />
                  {repliesOf(c).length > 0 && (
                    <div className="ml-8 pl-3 border-l-2 border-gray-100 space-y-2">
                      {repliesOf(c).map(r => (
                        <CommentRow key={r.id} c={r} busy={busy}
                          onReply={() => { setReplyTo(r); setReplyText('') }}
                          onHide={() => toggleHide(r)}
                          onDelete={() => remove(r)} />
                      ))}
                    </div>
                  )}
                  {replyTo?.id === c.id && (
                    <div className="ml-8 flex gap-2">
                      <Textarea rows={1} className="flex-1" placeholder={`Reply to @${c.username}…`}
                        value={replyText} onChange={e => setReplyText(e.target.value)} />
                      <Button size="sm" onClick={sendReply} loading={busy === `reply-${c.id}`}>Send</Button>
                      <Button size="sm" variant="secondary" onClick={() => setReplyTo(null)}>Cancel</Button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

function CommentRow({ c, busy, onReply, onHide, onDelete }: {
  c: IgComment; busy: string | null
  onReply: () => void; onHide: () => void; onDelete: () => void
}) {
  return (
    <div className="flex items-start gap-3">
      <div className="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-xs text-gray-500 shrink-0">
        {(c.username || '?')[0]?.toUpperCase()}
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm">
          <span className="font-medium text-gray-900">@{c.username}</span>{' '}
          <span className="text-gray-700">{c.text}</span>
          {c.hidden && <Badge variant="gray" className="ml-2">Hidden</Badge>}
        </p>
        <div className="flex items-center gap-3 mt-1 text-xs text-gray-400">
          <span>{new Date(c.timestamp).toLocaleString('en-IN')}</span>
          {(c.like_count ?? 0) > 0 && <span>♥ {c.like_count}</span>}
          <button onClick={onReply} className="text-brand-600 hover:underline">Reply</button>
          <button onClick={onHide} disabled={busy === `hide-${c.id}`} className="hover:underline">
            {c.hidden ? 'Unhide' : 'Hide'}
          </button>
          <button onClick={onDelete} disabled={busy === `del-${c.id}`} className="text-red-500 hover:underline">Delete</button>
        </div>
      </div>
    </div>
  )
}
