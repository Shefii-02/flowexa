import {
  useEffect, useState, useCallback, useMemo, useRef, DragEvent,
} from 'react'
import { useSearchParams } from 'react-router-dom'
import { flowBuilderApi, flowNodeApi } from '@/api'
import { Button, Modal, ConfirmModal, EmptyState } from '@/components/ui'
import { getError } from '@/utils'
import toast from 'react-hot-toast'

// ── Reserved navigation sentinels ───────────────────────────────────────────
// A node whose reply_id starts with one of these prefixes isn't real content —
// it's a navigation shortcut. Your production WhatsApp webhook handler must
// special-case them BEFORE normal parent/child lookup:
//   reply_id starts with "MAIN_MENU__" → jump the session to this builder's
//     ROOT node (the trigger node), ignoring this row's own (nonexistent) children.
//   reply_id starts with "BACK__"      → jump the session to the PARENT of
//     whichever node the customer was actually on, not this row's own parent.
// The prefix (not the full string) is what matters — each row still needs its
// own unique reply_id suffix, e.g. "MAIN_MENU__EX_YES_EN".
const MAIN_MENU_PREFIX = 'MAIN_MENU__'
const BACK_PREFIX = 'BACK__'
const isMainMenuNode = (replyId: string) => !!replyId?.startsWith(MAIN_MENU_PREFIX)
const isBackNode = (replyId: string) => !!replyId?.startsWith(BACK_PREFIX)

// Free-text keywords recognized at ANY point in ANY conversation, independent
// of whichever node the customer is currently on — the primary safety net,
// since it works even before you've wired up an explicit Main Menu button.
const GLOBAL_MENU_KEYWORDS = ['menu', 'main menu', 'hi', 'hello', 'start', 'restart']
const GLOBAL_BACK_KEYWORDS = ['back']

// ─────────────────────────────────────────────────────────────────────────────
// Live WhatsApp-style preview — walks the REAL parent/child tree client-side,
// so clicking through it is an actual test of whether your node relationships
// are wired correctly (broken parent_ref/parent_id shows up immediately as a
// node with no reachable children). Also simulates the two navigation paths
// above, and flags unintentional dead ends the same way the list view does.
// ─────────────────────────────────────────────────────────────────────────────
type ChatMsg = { from: 'bot' | 'user'; text: string; id: string; time: string }

const nowTime = () => new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

function FlowPreviewPanelAll({ nodes, startId, nonce, onRestart, builderName, onClose }: {
  nodes: any[]; startId: number | null; nonce: number; onRestart: () => void
  builderName?: string; onClose?: () => void
}) {
  const byId = useMemo(() => Object.fromEntries(nodes.map(n => [n.id, n])), [nodes])
  const byParentId = useMemo(() => {
    const m: Record<string, any[]> = {}
    nodes.forEach(n => {
      const k = String(n.parent_id ?? 'root')
      m[k] = m[k] || []
      m[k].push(n)
    })
    Object.values(m).forEach(arr => arr.sort((a: any, b: any) => (a.sort_order ?? 0) - (b.sort_order ?? 0)))
    return m
  }, [nodes])
  const rootNode = (byParentId['root'] || [])[0] || null

  const [currentId, setCurrentId] = useState<number | null>(null)
  const [history, setHistory] = useState<number[]>([]) // stack of visited ids, for Back
  const [log, setLog] = useState<ChatMsg[]>([])
  const [typed, setTyped] = useState('')
  const [botTyping, setBotTyping] = useState(false)
  const scrollRef = useRef<HTMLDivElement>(null)
  const msgId = useRef(0)
  const nextId = () => String(msgId.current++)

  const say = (from: ChatMsg['from'], text: string) =>
    setLog(l => [...l, { from, text, id: nextId(), time: nowTime() }])

  // Simulates the bot "typing" for a moment before its reply lands — small
  // but it's what makes this read as a live chat instead of an instant swap.
  const sayBotAfterDelay = (text: string, delay = 550) => {
    setBotTyping(true)
    setTimeout(() => { setBotTyping(false); say('bot', text) }, delay)
  }

  // (re)start the whole simulated conversation whenever the caller changes
  // which node to preview from (defaults to root)
  useEffect(() => {
    const target = startId ?? rootNode?.id ?? null
    setCurrentId(target)
    setHistory([])
    setBotTyping(false)
    setLog(target && byId[target] ? [{ from: 'bot', text: byId[target].message || '[no message set]', id: nextId(), time: nowTime() }] : [])
  }, [startId, nonce, rootNode?.id, nodes.length]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' }) }, [log, botTyping])

  const current = currentId ? byId[currentId] : null
  const children = currentId ? (byParentId[String(currentId)] || []) : []
  const isUnintentionalDeadEnd = !!current && children.length === 0 && !current.is_dead_end
    && current.type !== 'text' && current.type !== 'survey' && current.type !== 'template'

  const enterNode = (nodeId: number) => {
    const node = byId[nodeId]
    setCurrentId(nodeId)
    sayBotAfterDelay(node?.message || '[no message set]')
  }

  const goToChild = (child: any) => {
    say('user', child.title)
    setHistory(h => currentId !== null ? [...h, currentId] : h)
    enterNode(child.id)
  }

  const jumpToMainMenu = (viaLabel: string) => {
    if (!rootNode) return
    say('user', viaLabel)
    setHistory([])
    enterNode(rootNode.id)
  }

  const jumpBack = (viaLabel: string) => {
    say('user', viaLabel)
    setHistory(h => {
      if (h.length === 0) { if (rootNode) enterNode(rootNode.id); return h }
      const prev = h[h.length - 1]
      enterNode(prev)
      return h.slice(0, -1)
    })
  }

  const handleOptionClick = (child: any) => {
    if (isMainMenuNode(child.reply_id)) return jumpToMainMenu(child.title)
    if (isBackNode(child.reply_id)) return jumpBack(child.title)
    goToChild(child)
  }

  const handleTypedSend = () => {
    const text = typed.trim()
    if (!text) return
    const lower = text.toLowerCase()
    setTyped('')
    if (GLOBAL_MENU_KEYWORDS.includes(lower)) return jumpToMainMenu(text)
    if (GLOBAL_BACK_KEYWORDS.includes(lower)) return jumpBack(text)
    const match = children.find((c: any) => c.title.toLowerCase() === lower || c.reply_id.toLowerCase() === lower)
    if (match) return handleOptionClick(match)
    say('user', text)
    sayBotAfterDelay("🤖 No option matched this — a real customer would be stuck here unless a fallback or global keyword is configured.")
  }

  if (!rootNode) {
    return (
      <div className="h-full flex items-center justify-center text-center text-sm text-gray-400 p-6 border-2 border-dashed border-gray-200 rounded-2xl">
        Add a root node to preview the flow.
      </div>
    )
  }

  return (
    <div className="flex flex-col h-full bg-[#e5ddd5] rounded-2xl overflow-hidden border border-gray-200 shadow-sm">
      <div className="bg-[#075e54] text-white px-3 py-2.5 flex items-center gap-2 flex-shrink-0">
        {onClose && (
          <button onClick={onClose} className="text-white/90 hover:text-white text-lg leading-none px-1" title="Close preview">‹</button>
        )}
        <span className="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center text-base flex-shrink-0">🤖</span>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold truncate">{builderName || 'Your WhatsApp Bot'}</p>
          <p className="text-[11px] text-white/70 truncate">
            {botTyping ? 'typing…' : `online · testing node #${current?.id ?? '—'}`}
          </p>
        </div>
        <button onClick={onRestart} title="Restart from root"
          className="text-xs bg-white/15 hover:bg-white/25 px-2 py-1 rounded-lg flex-shrink-0">🔄</button>
      </div>

      {isUnintentionalDeadEnd && (
        <div className="bg-red-50 border-b border-red-200 text-red-600 text-[11px] px-3 py-1.5 flex-shrink-0">
          ⚠️ Dead end — no children and not marked Terminal. A real customer has nothing to tap here.
        </div>
      )}
      {current?.is_dead_end && (
        <div className="bg-gray-100 border-b border-gray-200 text-gray-500 text-[11px] px-3 py-1.5 flex-shrink-0">
          🔚 Marked terminal — make sure the message (or a Main Menu button) tells the customer how to continue.
        </div>
      )}

      <div ref={scrollRef} className="flex-1 overflow-y-auto px-3 py-3 space-y-2 min-h-0"
        style={{ backgroundImage: 'radial-gradient(#00000008 1px, transparent 1px)', backgroundSize: '14px 14px' }}>
        {log.map(m => (
          <div key={m.id} className={`flex ${m.from === 'user' ? 'justify-end' : 'justify-start'}`}>
            <div className={`relative max-w-[85%] rounded-lg px-3 py-1.5 text-[13px] whitespace-pre-wrap shadow-sm ${m.from === 'user' ? 'bg-[#dcf8c6] rounded-tr-none' : 'bg-white rounded-tl-none'}`}>
              <span>{m.text}</span>
              <span className="block text-right text-[10px] text-gray-400 mt-0.5 select-none">
                {m.time}{m.from === 'user' && <span className="text-[#4fc3f7] ml-1">✓✓</span>}
              </span>
            </div>
          </div>
        ))}

        {botTyping && (
          <div className="flex justify-start">
            <div className="bg-white rounded-lg rounded-tl-none px-3 py-2 shadow-sm flex items-center gap-1">
              <span className="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
              <span className="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '120ms' }} />
              <span className="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '240ms' }} />
            </div>
          </div>
        )}

        {!botTyping && current && children.length > 0 && (
          <div className="flex justify-start">
            <div className="max-w-[85%] bg-white rounded-lg shadow-sm overflow-hidden">
              {children.map((c: any) => (
                <button key={c.id} onClick={() => handleOptionClick(c)}
                  className="w-full text-left px-3 py-2 text-[13px] text-[#128C7E] border-t first:border-t-0 border-gray-100 hover:bg-gray-50 flex items-center gap-1.5">
                  <span>{isMainMenuNode(c.reply_id) ? '🏠' : isBackNode(c.reply_id) ? '🔙' : '▸'}</span> {c.title}
                </button>
              ))}
            </div>
          </div>
        )}
      </div>

      <div className="p-2 border-t border-gray-200 bg-white flex items-center gap-1.5 flex-shrink-0">
        <span className="text-gray-400 px-1 select-none">😊</span>
        <span className="text-gray-400 px-1 select-none">📎</span>
        <input value={typed} onChange={e => setTyped(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && handleTypedSend()}
          placeholder='Message… try "menu" or "back"'
          className="flex-1 text-sm px-3 py-1.5 rounded-full border border-gray-200 focus:outline-none focus:border-brand-400" />
        <button onClick={handleTypedSend} disabled={!typed.trim()}
          className="text-sm w-8 h-8 flex items-center justify-center rounded-full bg-[#128C7E] text-white flex-shrink-0 disabled:opacity-40">
          {typed.trim() ? '➤' : '🎤'}
        </button>
      </div>
    </div>
  )
}