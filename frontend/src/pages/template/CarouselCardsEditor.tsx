import { useState } from 'react'
import { Input } from '@/components/ui'
import { useMediaGate } from '@/hooks/useMediaGate'
import { MEDIA_RULES } from '@/utils/mediaRules'
import MediaPickerModal from '@/components/MediaPickerModal'

export type CarouselButtonType = 'QUICK_REPLY' | 'URL' | 'PHONE_NUMBER' | 'SURVEY_FORM'
export type CarouselButton = { type: CarouselButtonType; text: string; url?: string; example?: string; survey_form_id?: number; phone_cc?: string; phone_local?: string }
export type SurveyFormOption = { id: number; name: string; is_active: boolean }
export type CarouselCard = { uid: string; body: string; buttons: CarouselButton[] }
// A card's sample media: a freshly picked file, a Media Library URL, and/or what is already stored on the template.
export type CardMedia = { file?: File; libraryUrl?: string; previewUrl?: string }
export type CarouselMediaFormat = 'IMAGE' | 'VIDEO'

export const MIN_CARDS = 2
export const MAX_CARDS = 10
export const CARD_BODY_MAX = 160
const DEFAULT_CC = '91'

const BUTTON_LABEL: Record<CarouselButtonType, string> = {
  QUICK_REPLY: 'Custom (quick reply)', URL: 'Visit website', PHONE_NUMBER: 'Call phone number', SURVEY_FORM: 'Survey form',
}

export const newCardUid = () => 'c' + Math.random().toString(36).slice(2, 11).padEnd(9, '0')
export const newCard = (types: CarouselButtonType[] = ['QUICK_REPLY']): CarouselCard => ({
  uid: newCardUid(), body: '', buttons: types.map(type => emptyButton(type)),
})
const emptyButton = (type: CarouselButtonType): CarouselButton => ({
  type, text: '', url: type === 'URL' ? '' : undefined,
  phone_cc: type === 'PHONE_NUMBER' ? DEFAULT_CC : undefined, phone_local: type === 'PHONE_NUMBER' ? '' : undefined,
})

/** Problems Meta would refuse, in plain language — empty when the carousel is fine. */
export function carouselIssues(
  cards: CarouselCard[], format: CarouselMediaFormat, media: Record<string, CardMedia>,
): string[] {
  const out: string[] = []
  if (cards.length < MIN_CARDS) out.push(`Add at least ${MIN_CARDS} cards`)
  const withText = cards.filter(c => c.body.trim()).length
  if (withText > 0 && withText < cards.length) out.push('If one card has text, every card needs text (or clear it on all)')
  cards.forEach((c, i) => {
    const n = i + 1
    const m = media[c.uid]
    if (!m?.file && !m?.libraryUrl && !m?.previewUrl) out.push(`Card ${n}: add a sample ${format.toLowerCase()}`)
    if (/\{\{/.test(c.body)) out.push(`Card ${n}: variables aren't supported in card text yet`)
    const seen = new Set<string>()
    c.buttons.forEach((b, j) => {
      const label = `Card ${n}, button ${j + 1}`
      if (!b.text.trim()) { out.push(`${label}: text is required`); return }
      if (/[\r\n]|\{\{/.test(b.text)) out.push(`${label}: text can't contain line breaks or variables`)
      const k = b.text.trim().toLowerCase()
      if (seen.has(k)) out.push(`${label}: "${b.text.trim()}" is used twice on this card`)
      seen.add(k)
      if (b.type === 'URL') {
        const u = (b.url || '').trim()
        const vars = (u.match(/\{\{\s*\d+\s*\}\}/g) || []).length
        const plain = u.replace(/\{\{1\}\}$/, '')
        if (vars > 1 || (vars === 1 && !/\{\{1\}\}$/.test(u))) out.push(`${label}: the link can have one variable, {{1}}, only at the end`)
        else if (!/^https?:\/\/\S+$/i.test(plain)) out.push(`${label}: enter a full link starting with https://`)
        else if (/(^|\.)(wa\.me|whatsapp\.com)(\/|$)/i.test(plain.replace(/^https?:\/\//i, ''))) out.push(`${label}: a website button can't link to WhatsApp`)
        else if (vars === 1 && !(b.example || '').trim()) out.push(`${label}: add an example value for {{1}}`)
      }
      if (b.type === 'SURVEY_FORM' && !b.survey_form_id) out.push(`${label}: pick a survey form`)
      if (b.type === 'PHONE_NUMBER' && (!b.phone_cc?.trim() || !/^\d{7,14}$/.test(b.phone_local || ''))) {
        out.push(`${label}: enter a country code and a valid number`)
      }
    })
  })
  return out
}

type Props = {
  cards: CarouselCard[]
  onCards: (cards: CarouselCard[]) => void
  format: CarouselMediaFormat
  onFormat: (f: CarouselMediaFormat) => void
  media: Record<string, CardMedia>
  onMedia: (uid: string, m: CardMedia | null) => void
  surveyForms?: SurveyFormOption[]
  disabled?: boolean
}

export default function CarouselCardsEditor({ cards, onCards, format, onFormat, media, onMedia, surveyForms = [], disabled }: Props) {
  const { gate, cropModal } = useMediaGate()
  const [pickerFor, setPickerFor] = useState<string | null>(null)
  const ruleKey = format === 'IMAGE' ? 'header_image' : 'header_video'
  const issues = carouselIssues(cards, format, media)

  // Meta wants every card to carry the same buttons in the same order, so the button layout is edited once
  // (from card 1) and applied to all cards; only the labels / links differ per card.
  const types: CarouselButtonType[] = cards[0]?.buttons.map(b => b.type) ?? ['QUICK_REPLY']
  const setTypes = (next: CarouselButtonType[]) =>
    onCards(cards.map(c => ({
      ...c,
      buttons: next.map((t, i) => {
        const old = c.buttons[i]
        return old && old.type === t ? old : { ...emptyButton(t), text: old?.text ?? '' }
      }),
    })))

  const patchCard = (uid: string, patch: Partial<CarouselCard>) =>
    onCards(cards.map(c => c.uid === uid ? { ...c, ...patch } : c))
  const patchButton = (uid: string, j: number, patch: Partial<CarouselButton>) =>
    onCards(cards.map(c => c.uid === uid ? { ...c, buttons: c.buttons.map((b, k) => k === j ? { ...b, ...patch } : b) } : c))

  const addCard = () => cards.length < MAX_CARDS && onCards([...cards, newCard(types)])
  const removeCard = (uid: string) => {
    if (cards.length <= MIN_CARDS) return
    onCards(cards.filter(c => c.uid !== uid))
    onMedia(uid, null)
  }

  const changeFormat = (f: CarouselMediaFormat) => {
    if (f === format) return
    if (Object.keys(media).length && !window.confirm(`Switching to ${f.toLowerCase()} removes the sample media on every card. Continue?`)) return
    cards.forEach(c => onMedia(c.uid, null))
    onFormat(f)
  }

  return (
    <div className="space-y-3">
      {cropModal}
      <MediaPickerModal
        open={!!pickerFor}
        onClose={() => setPickerFor(null)}
        accept={format === 'IMAGE' ? 'image' : 'video'}
        title={`Pick a ${format.toLowerCase()} from the Media Library`}
        onSelect={(url) => { if (pickerFor) onMedia(pickerFor, { libraryUrl: url, previewUrl: url }); setPickerFor(null) }}
      />

      <div className="flex items-center justify-between flex-wrap gap-2">
        <label className="label mb-0">Carousel cards * <span className="text-xs font-normal text-gray-400">({cards.length}/{MAX_CARDS}, min {MIN_CARDS})</span></label>
        <div className="flex rounded-lg border border-gray-200 overflow-hidden text-xs">
          {(['IMAGE', 'VIDEO'] as const).map(f => (
            <button key={f} type="button" disabled={disabled} onClick={() => changeFormat(f)}
              className={`px-3 py-1.5 ${format === f ? 'bg-brand-50 text-brand-700 font-semibold' : 'text-gray-500 hover:bg-gray-50'}`}>
              {f === 'IMAGE' ? '🖼️ Image cards' : '🎬 Video cards'}
            </button>
          ))}
        </div>
      </div>

      {/* Meta needs the same buttons, in the same order, on every card — so a button's type is chosen once
          (in any card) and applies to all; the text, link, number etc. stay per card. */}
      <div className="bg-gray-50 border border-gray-200 rounded-xl p-3 flex items-center justify-between gap-3 flex-wrap">
        <p className="text-xs text-gray-500">
          Button types are shared by all cards (Meta rule) — labels, links and numbers are set per card.
        </p>
        <div className="flex items-center gap-2 text-xs">
          <span className="text-gray-500">Buttons per card</span>
          {[1, 2].map(n => (
            <button key={n} type="button" disabled={disabled}
              onClick={() => setTypes(n === 1 ? types.slice(0, 1) : [...types, types.includes('URL') ? 'QUICK_REPLY' : 'URL'].slice(0, 2) as CarouselButtonType[])}
              className={`px-2.5 py-1 rounded-lg border ${types.length === n ? 'border-brand-500 bg-brand-50 text-brand-700 font-semibold' : 'border-gray-200 text-gray-500'}`}>
              {n}
            </button>
          ))}
        </div>
      </div>

      {cards.map((c, i) => {
        const m = media[c.uid]
        const src = m?.file ? URL.createObjectURL(m.file) : m?.previewUrl
        return (
          <div key={c.uid} className="border border-gray-200 rounded-xl p-3 space-y-3">
            <div className="flex items-center justify-between">
              <p className="text-sm font-semibold text-gray-700">Card {i + 1}</p>
              {cards.length > MIN_CARDS && (
                <button type="button" disabled={disabled} className="text-xs text-red-500 hover:underline" onClick={() => removeCard(c.uid)}>Remove card</button>
              )}
            </div>

            <div className="flex gap-3 items-start">
              <div className="w-28 h-28 rounded-lg bg-gray-100 overflow-hidden flex items-center justify-center text-gray-300 text-3xl flex-shrink-0">
                {src
                  ? (format === 'IMAGE'
                    ? <img src={src} alt={`Card ${i + 1}`} className="w-full h-full object-cover" />
                    : <video src={src} className="w-full h-full object-cover" muted />)
                  : (format === 'IMAGE' ? '🖼️' : '🎬')}
              </div>
              <div className="flex-1 space-y-1.5">
                <label className="inline-block cursor-pointer px-2.5 py-1.5 bg-white border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50">
                  {src ? 'Replace' : 'Upload'} {format.toLowerCase()}
                  <input type="file" className="hidden" disabled={disabled} accept={MEDIA_RULES[ruleKey].accept}
                    onChange={async e => {
                      const picked = e.target.files?.[0]
                      e.target.value = ''
                      if (!picked) return
                      const ok = await gate(picked, ruleKey)
                      if (ok) onMedia(c.uid, { file: ok, previewUrl: m?.previewUrl })
                    }} />
                </label>
                <button type="button" disabled={disabled} onClick={() => setPickerFor(c.uid)}
                  className="ml-2 px-2.5 py-1.5 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-lg text-xs hover:bg-indigo-100 font-medium">
                  🗂️ Media Library
                </button>
                <p className="text-[11px] text-gray-400">{MEDIA_RULES[ruleKey].hint}. Meta crops media to a wide ratio automatically.</p>
              </div>
            </div>

            <div>
              <textarea className="textarea text-sm" rows={2} disabled={disabled} placeholder="Card text (optional, but all cards or none) — e.g. Summer sale: 30% off"
                value={c.body} onChange={e => patchCard(c.uid, { body: e.target.value.replace(/\r\n?/g, '\n').slice(0, CARD_BODY_MAX) })} />
              <p className={`text-[11px] text-right ${c.body.length >= CARD_BODY_MAX ? 'text-red-500' : 'text-gray-400'}`}>{c.body.length}/{CARD_BODY_MAX}</p>
            </div>

            {c.buttons.map((b, j) => (
              <div key={j} className="space-y-1.5">
                <div className="flex gap-2 items-center">
                  <select className="select text-xs w-40 flex-shrink-0" value={b.type} disabled={disabled}
                    onChange={e => setTypes(types.map((x, k) => k === j ? e.target.value as CarouselButtonType : x))}>
                    {(Object.keys(BUTTON_LABEL) as CarouselButtonType[])
                      .filter(opt => opt !== 'PHONE_NUMBER' || b.type === 'PHONE_NUMBER' || !types.includes('PHONE_NUMBER'))
                      .map(opt => <option key={opt} value={opt}>{BUTTON_LABEL[opt]}</option>)}
                  </select>
                  <Input className="flex-1" placeholder="Button text" value={b.text} disabled={disabled}
                    onChange={e => patchButton(c.uid, j, { text: e.target.value.slice(0, 25) })} />
                  <span className="text-[11px] text-gray-400 w-10 text-right">{b.text.length}/25</span>
                </div>
                {b.type === 'URL' && (
                  <>
                    <Input placeholder="https://yoursite.com/page  (or …/product/{{1}} for a per-recipient value)" value={b.url || ''} disabled={disabled}
                      onChange={e => patchButton(c.uid, j, { url: e.target.value })} />
                    {/\{\{\s*1\s*\}\}$/.test((b.url || '').trim()) && (
                      <Input placeholder="Example value for {{1}} — e.g. blue-elf (Meta review only)" value={b.example || ''} disabled={disabled}
                        onChange={e => patchButton(c.uid, j, { example: e.target.value })} />
                    )}
                  </>
                )}
                {b.type === 'SURVEY_FORM' && (
                  <select className="select text-xs" value={b.survey_form_id ?? ''} disabled={disabled}
                    onChange={e => patchButton(c.uid, j, { survey_form_id: Number(e.target.value) || undefined })}>
                    <option value="">Select a survey form…</option>
                    {surveyForms.map(sf => <option key={sf.id} value={sf.id} disabled={!sf.is_active}>{sf.name}{sf.is_active ? '' : ' (inactive)'}</option>)}
                  </select>
                )}
                {b.type === 'PHONE_NUMBER' && (
                  <div className="flex gap-2">
                    <div className="w-24 shrink-0">
                      <Input placeholder="Code" inputMode="numeric" value={b.phone_cc ?? DEFAULT_CC} disabled={disabled}
                        onChange={e => patchButton(c.uid, j, { phone_cc: e.target.value.replace(/\D/g, '').slice(0, 4) })} />
                    </div>
                    <div className="flex-1">
                      <Input placeholder="Phone number — e.g. 9846366783" inputMode="numeric" value={b.phone_local || ''} disabled={disabled}
                        onChange={e => patchButton(c.uid, j, { phone_local: e.target.value.replace(/\D/g, '').slice(0, 14) })} />
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>
        )
      })}

      {cards.length < MAX_CARDS && (
        <button type="button" disabled={disabled} onClick={addCard}
          className="w-full border-2 border-dashed border-gray-200 rounded-xl py-2.5 text-sm text-brand-600 hover:border-brand-300">
          + Add card
        </button>
      )}

      {issues.length > 0 && (
        <ul className="text-xs text-amber-600 space-y-0.5">
          {issues.slice(0, 4).map(m => <li key={m}>⚠️ {m}</li>)}
          {issues.length > 4 && <li>…and {issues.length - 4} more</li>}
        </ul>
      )}
    </div>
  )
}

/** WhatsApp-style horizontal strip of the cards, for the live preview column. */
export function CarouselPreview({ cards, format, media }: { cards: CarouselCard[]; format: CarouselMediaFormat; media: Record<string, CardMedia> }) {
  return (
    <div className="mt-1 flex gap-2 overflow-x-auto pb-1">
      {cards.map((c, i) => {
        const m = media[c.uid]
        const src = m?.file ? URL.createObjectURL(m.file) : m?.previewUrl
        return (
          <div key={c.uid} className="bg-white rounded-xl shadow-sm w-40 flex-shrink-0 overflow-hidden">
            <div className="h-28 bg-gray-100 flex items-center justify-center text-gray-300 text-2xl">
              {src
                ? (format === 'IMAGE' ? <img src={src} alt="" className="w-full h-full object-cover" /> : <video src={src} className="w-full h-full object-cover" muted />)
                : (format === 'IMAGE' ? '🖼️' : '🎬')}
            </div>
            <p className="text-xs text-gray-800 p-2 whitespace-pre-wrap break-words">{c.body || `Card ${i + 1} text…`}</p>
            {c.buttons.map((b, j) => (
              <div key={j} className="border-t border-gray-100 text-center text-xs text-[#00a5f4] font-medium py-1.5">
                {b.type === 'URL' ? '🔗 ' : b.type === 'PHONE_NUMBER' ? '📞 ' : ''}{b.text || 'Button'}
              </div>
            ))}
          </div>
        )
      })}
    </div>
  )
}
