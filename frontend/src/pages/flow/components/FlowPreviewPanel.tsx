// src/pages/flow/components/FlowPreviewPanel.tsx
// WhatsApp-style live preview — updates as user types in the create/edit modal
// Usage: <FlowPreviewPanel form={form} multiMode={multiMode} nodes={nodes} />

import { useMemo } from 'react'

interface Props {
  form:      any
  multiMode: boolean
  nodes:     any[]
}

// ── Render *bold*, _italic_, ~strike~, ```mono```, newlines ──────────────────
function WAText({ text }: { text: string }) {
  if (!text) return null
  const html = text
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/```([\s\S]*?)```/g, '<code class="bg-[#c3e8b8] px-1 rounded text-xs font-mono">$1</code>')
    .replace(/\*(.*?)\*/g,        '<strong>$1</strong>')
    .replace(/_(.*?)_/g,          '<em>$1</em>')
    .replace(/~(.*?)~/g,          '<s>$1</s>')
    .replace(/\n/g,               '<br/>')
  return (
    <p className="text-sm text-gray-900 leading-snug break-words"
      dangerouslySetInnerHTML={{ __html: html }} />
  )
}

// ── WhatsApp outbound bubble ──────────────────────────────────────────────────
function Bubble({ children, time = '12:30' }: {
  children: React.ReactNode; time?: string
}) {
  return (
    <div className="flex justify-end mb-2">
      <div className="relative bg-[#dcf8c6] rounded-2xl rounded-tr-sm px-3 py-2 max-w-[90%] shadow-sm min-w-[80px]">
        {children}
        <p className="text-[10px] text-[#667781] text-right mt-1 select-none">{time} ✓✓</p>
        <div className="absolute -right-1.5 top-0 w-0 h-0"
          style={{ borderLeft: '8px solid #dcf8c6', borderBottom: '8px solid transparent' }} />
      </div>
    </div>
  )
}

// ── Inbound bubble (customer) ────────────────────────────────────────────────
function InboundBubble({ text }: { text: string }) {
  return (
    <div className="flex justify-start mb-2">
      <div className="relative bg-white rounded-2xl rounded-tl-sm px-3 py-2 max-w-[75%] shadow-sm">
        <p className="text-sm text-gray-900">{text}</p>
        <p className="text-[10px] text-[#667781] text-right mt-1">12:29</p>
        <div className="absolute -left-1.5 top-0 w-0 h-0"
          style={{ borderRight: '8px solid white', borderBottom: '8px solid transparent' }} />
      </div>
    </div>
  )
}

// ── Media placeholder / preview ───────────────────────────────────────────────
function MediaBlock({ type, url, caption, filename }: {
  type: string; url?: string; caption?: string; filename?: string
}) {
  const icons: Record<string,string> = {
    image:'🖼️', video:'🎬', document:'📄', audio:'🎧', location:'📍',
  }

  // Real image URL — try to show actual image
  if (type === 'image' && url && (url.startsWith('http') || url.startsWith('blob'))) {
    return (
      <div className="mb-1 rounded-xl overflow-hidden">
        <img src={url} alt="preview"
          className="w-full max-h-36 object-cover"
          onError={e => { (e.target as any).style.display = 'none' }} />
        {caption && <p className="text-xs text-gray-700 mt-1 px-1">{caption}</p>}
      </div>
    )
  }

  if (type === 'location') {
    return (
      <div className="rounded-xl overflow-hidden mb-1 border border-[#b5d9a8]">
        <div className="bg-[#c3e8b8] h-16 flex items-center justify-center text-3xl">🗺️</div>
        <div className="bg-white px-2 py-1.5">
          <p className="text-xs font-medium text-gray-800">{caption || 'Location'}</p>
          {filename && <p className="text-[10px] text-gray-400">{filename}</p>}
        </div>
      </div>
    )
  }

  if (type === 'audio') {
    return (
      <div className="flex items-center gap-2 bg-[#c3e8b8] rounded-xl px-3 py-2 mb-1">
        <span className="text-xl">🎧</span>
        <div className="flex-1">
          <div className="flex items-center gap-1">
            <div className="w-2 h-2 rounded-full bg-[#00a884]" />
            <div className="flex-1 h-1 bg-[#8bc8a0] rounded-full overflow-hidden">
              <div className="w-1/3 h-full bg-[#00a884] rounded-full" />
            </div>
          </div>
          <p className="text-[10px] text-gray-500 mt-0.5">0:00</p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex items-center gap-2 bg-[#c3e8b8] rounded-xl px-3 py-2 mb-1">
      <span className="text-xl flex-shrink-0">{icons[type] || '📎'}</span>
      <div className="min-w-0">
        <p className="text-xs font-medium text-gray-800 truncate">
          {filename || (type === 'video' ? 'Video' : type === 'document' ? 'Document' : type)}
        </p>
        {caption && <p className="text-[10px] text-gray-500 truncate">{caption}</p>}
        {url && !url.startsWith('http') && (
          <p className="text-[10px] text-gray-400 truncate">{url.slice(0, 30)}…</p>
        )}
      </div>
    </div>
  )
}

// ── WhatsApp button ───────────────────────────────────────────────────────────
function WAButton({ label }: { label: string }) {
  return (
    <div className="border-t border-[#b5d9a8] py-2 px-3 text-center text-[#00a884] text-sm font-medium
      cursor-pointer hover:bg-[#c8f5be] transition-colors active:bg-[#b5eaaa] select-none">
      {label}
    </div>
  )
}

// ── WhatsApp list row ─────────────────────────────────────────────────────────
function WAListRow({ title, desc, idx }: { title: string; desc?: string; idx: number }) {
  return (
    <div className="px-4 py-2.5 border-b border-gray-100 last:border-0 hover:bg-gray-50 cursor-pointer">
      <p className="text-sm font-medium text-gray-900 truncate">{title}</p>
      {desc && <p className="text-xs text-gray-400 truncate mt-0.5">{desc}</p>}
    </div>
  )
}

// ── Interactive message (button or list) ──────────────────────────────────────
function InteractiveMessage({ message, mediaType, mediaUrl, type }: {
  message: string; mediaType?: string; mediaUrl?: string; type: 'button'|'list'
}) {
  const placeholderButtons = ['Option 1', 'Option 2', 'Option 3']
  const placeholderList    = ['Option 1','Option 2','Option 3','Option 4','Option 5']

  return (
    <div className="flex justify-end mb-2">
      <div className="relative bg-[#dcf8c6] rounded-2xl rounded-tr-sm max-w-[90%] shadow-sm overflow-hidden">

        {/* Media header */}
        {mediaType && mediaUrl && (
          <div className="px-0 pt-0">
            <MediaBlock type={mediaType} url={mediaUrl} />
          </div>
        )}

        {/* Body text */}
        <div className="px-3 pt-2 pb-1">
          {message
            ? <WAText text={message} />
            : <p className="text-sm text-gray-400 italic">Message text here…</p>}
          <p className="text-[10px] text-[#667781] text-right mt-1">12:30 ✓✓</p>
        </div>

        {/* Buttons (max 3) */}
        {type === 'button' && (
          <div className="border-t border-[#b5d9a8]">
            {placeholderButtons.map((l, i) => <WAButton key={i} label={l} />)}
          </div>
        )}

        {/* List trigger */}
        {type === 'list' && (
          <div className="border-t border-[#b5d9a8]">
            <div className="py-2 px-3 text-center text-[#00a884] text-sm font-medium flex items-center justify-center gap-2">
              <span className="text-base">≡</span> View Options
            </div>
          </div>
        )}

        {/* Tail */}
        <div className="absolute -right-1.5 top-0 w-0 h-0"
          style={{ borderLeft: '8px solid #dcf8c6', borderBottom: '8px solid transparent' }} />
      </div>
    </div>
  )
}

// ─────────────────────────────────────────────────────────────────────────────
// Main FlowPreviewPanel
// ─────────────────────────────────────────────────────────────────────────────
export function FlowPreviewPanel({ form, multiMode, nodes }: Props) {
  const isList   = form.type === 'list'
  const isButton = form.type === 'button'
  const isSurvey = form.type === 'survey'
  const isTpl    = form.type === 'template'

  const hasMessage = !!form.message?.trim()

  // Dummy children count for placeholder
  const existingChildren = useMemo(
    () => nodes.filter(n => n.parent_id === form.parent_id && n.id !== undefined),
    [nodes, form.parent_id]
  )

  return (
    <div className="flex flex-col h-full select-none">

      {/* Phone chrome — top bar */}
      <div className="bg-[#075e54] text-white px-3 py-2.5 flex items-center gap-2.5 flex-shrink-0">
        <div className="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-sm flex-shrink-0">
          👤
        </div>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold leading-none truncate">Customer</p>
          <p className="text-[10px] text-white/60 mt-0.5">online</p>
        </div>
        <div className="flex gap-3 text-white/70 text-sm flex-shrink-0">
          <span>📞</span><span>⋮</span>
        </div>
      </div>

      {/* Chat area */}
      <div className="flex-1 overflow-y-auto p-3 space-y-1 min-h-0"
        style={{
          background: '#e5ddd5',
          backgroundImage: `url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23c8bdb4' fill-opacity='0.12'%3E%3Cpath d='M20 20.5V18H0v5h5v5H0v5h20v-5h15v-5H20z'/%3E%3C/g%3E%3C/svg%3E")`,
        }}>

        {/* Customer greeting */}
        <InboundBubble text="Hi" />

        {/* ── Multi-message preview ── */}
        {multiMode && form.multi_messages?.length > 0 ? (
          <>
            {/* Intro text bubble */}
            {hasMessage && (
              <Bubble>
                <WAText text={form.message} />
              </Bubble>
            )}

            {/* Each block */}
            {form.multi_messages.map((b: any, i: number) => (
              <Bubble key={b._key || i} time={`12:3${i}`}>
                {b.type === 'text' && (
                  <WAText text={b.content || '…'} />
                )}
                {['image','video','document','audio'].includes(b.type) && (
                  <MediaBlock
                    type={b.type}
                    url={b.url || (b.upload ? URL.createObjectURL(b.upload) : undefined)}
                    caption={b.caption}
                    filename={b.filename || b.uploadName}
                  />
                )}
                {b.type === 'location' && (
                  <MediaBlock
                    type="location"
                    caption={b.name || 'Location'}
                    filename={b.address}
                  />
                )}
              </Bubble>
            ))}

            {/* After all blocks, show options placeholder */}
            {form.multi_messages.length > 0 && (isButton || isList) && (
              <div className="flex justify-end">
                <div className="bg-[#dcf8c6] rounded-xl shadow-sm overflow-hidden max-w-[90%]">
                  <div className="px-3 py-1.5 text-xs text-gray-500">
                    Then shows options ↓
                  </div>
                  {isButton && (
                    <div className="border-t border-[#b5d9a8]">
                      <WAButton label="Option 1" />
                      <WAButton label="Option 2" />
                    </div>
                  )}
                  {isList && (
                    <div className="border-t border-[#b5d9a8] py-1.5 px-3 text-center text-[#00a884] text-sm flex items-center justify-center gap-1">
                      ≡ View Options
                    </div>
                  )}
                </div>
              </div>
            )}
          </>
        ) : (
          /* ── Single message preview ── */
          <>
            {/* Survey */}
            {isSurvey && (
              <Bubble>
                {hasMessage && (
                  <><WAText text={form.message} /><div className="border-t border-[#b5d9a8] my-2" /></>
                )}
                <div className="space-y-1">
                  <p className="text-xs text-gray-500 font-medium">Survey questions sent one by one:</p>
                  {['Question 1?', 'Question 2?', 'Question 3?'].map((q, i) => (
                    <p key={i} className="text-xs text-gray-600">• {q}</p>
                  ))}
                  <p className="text-[10px] text-gray-400 italic mt-1">Customer replies to each</p>
                </div>
              </Bubble>
            )}

            {/* Template */}
            {isTpl && (
              <Bubble>
                {hasMessage && (
                  <><WAText text={form.message} /><div className="border-t border-[#b5d9a8] my-2" /></>
                )}
                <div className="bg-[#c3e8b8] rounded-lg px-3 py-2 flex items-center gap-2">
                  <span>📨</span>
                  <span className="text-xs text-gray-600">Approved WA template sent here</span>
                </div>
              </Bubble>
            )}

            {/* Dynamic node */}
            {!isSurvey && !isTpl && form.is_dynamic && (
              <Bubble>
                {hasMessage && <><WAText text={form.message} /><div className="border-t border-[#b5d9a8] my-2" /></>}
                <div className="space-y-1">
                  <p className="text-xs text-indigo-600 font-medium">⚡ Dynamic options from:</p>
                  <p className="text-[10px] font-mono text-indigo-400 truncate">
                    {form.dynamic_api_url || 'API URL not set'}
                  </p>
                  <p className="text-[10px] text-gray-400">
                    Label: <span className="font-mono">{form.dynamic_label_field || 'name'}</span>
                    {' · '}Value: <span className="font-mono">{form.dynamic_value_field || 'id'}</span>
                  </p>
                </div>
                <div className="border-t border-[#b5d9a8] mt-2">
                  <div className="py-1.5 px-3 text-center text-[#00a884] text-sm">≡ Live options</div>
                </div>
              </Bubble>
            )}

            {/* Button / List interactive */}
            {!isSurvey && !isTpl && !form.is_dynamic && (isButton || isList) && (
              <InteractiveMessage
                message={form.message}
                mediaType={form.media_type}
                mediaUrl={form.media_url}
                type={isButton ? 'button' : 'list'}
              />
            )}

            {/* Plain text node */}
            {!isSurvey && !isTpl && !isButton && !isList && (
              <Bubble>
                {form.media_type && form.media_url && (
                  <MediaBlock type={form.media_type} url={form.media_url}
                    caption={form.media_caption} filename={form.media_filename} />
                )}
                {hasMessage
                  ? <WAText text={form.message} />
                  : <p className="text-sm text-gray-400 italic">Message text will appear here…</p>}
              </Bubble>
            )}
          </>
        )}

        {/* List sheet modal simulation */}
        {(isList && !multiMode) && (
          <div className="mx-1 mt-1 bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-100">
            <div className="px-4 py-2.5 border-b border-gray-100 flex items-center justify-between">
              <p className="text-xs text-gray-500 font-medium uppercase tracking-wide">Options</p>
              <span className="text-gray-300 text-xs">×</span>
            </div>
            {['Option 1','Option 2','Option 3','Option 4','Option 5'].map((o, i) => (
              <WAListRow key={i} idx={i} title={o} desc="Tap to select" />
            ))}
            <p className="px-4 py-1.5 text-[10px] text-gray-300 italic">Up to 10 options in real flow</p>
          </div>
        )}

        {/* Dead end indicator */}
        {form.is_dead_end && (
          <div className="mx-1 mt-2 bg-red-50 border border-red-200 rounded-xl px-3 py-2 text-xs text-red-500 flex items-center gap-2">
            <span>🔚</span> Terminal node — conversation ends here
          </div>
        )}

        {/* Lead auto-create indicator */}
        {form.lead_category && (
          <div className="mx-1 mt-1 bg-orange-50 border border-orange-200 rounded-xl px-3 py-1.5 text-xs text-orange-600 flex items-center gap-2">
            <span>🎯</span> Auto-creates lead: <strong>{form.lead_category}</strong>
          </div>
        )}
      </div>

      {/* WhatsApp input bar */}
      <div className="bg-[#f0f0f0] px-3 py-2 flex items-center gap-2 flex-shrink-0">
        <div className="text-gray-400 text-xl">😊</div>
        <div className="flex-1 bg-white rounded-full px-3 py-1.5 text-xs text-gray-400 border border-gray-200">
          Type a message…
        </div>
        <div className="w-8 h-8 rounded-full bg-[#128c7e] flex items-center justify-center text-white text-sm">
          🎤
        </div>
      </div>

      {/* Node info strip */}
      <div className="px-2 py-1.5 bg-gray-50 border-t border-gray-100 flex-shrink-0 space-y-0.5">
        <div className="flex items-center justify-between text-[10px] text-gray-400">
          <span>
            Type: <span className="font-medium text-gray-600">{form.type || '—'}</span>
          </span>
          <span className="font-mono text-gray-500 truncate max-w-[120px]">
            {form.reply_id || '—'}
          </span>
        </div>
        {form.is_dynamic && (
          <p className="text-[10px] text-indigo-500 truncate">
            ⚡ <span className="font-mono">{form.dynamic_api_url || 'API URL not set'}</span>
          </p>
        )}
        {form.parent_id && (
          <p className="text-[10px] text-gray-400">
            Parent: #{form.parent_id}
          </p>
        )}
      </div>
    </div>
  )
}