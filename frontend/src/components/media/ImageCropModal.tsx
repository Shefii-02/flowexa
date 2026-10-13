import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { X, Check, ZoomIn, ZoomOut, Loader2, AlertTriangle, RotateCcw } from 'lucide-react'
import { cn } from '@/utils'
import { MEDIA_RULES, MediaRuleKey, MediaRule, fmtBytes } from '@/utils/mediaRules'

interface Props {
  file: File
  ruleKey: MediaRuleKey
  onDone: (file: File) => void
  onCancel: () => void
}

const STAGE_W = 400
const STAGE_MAX_H = 320

/** Crop / zoom editor. Pan by dragging, zoom with the slider or wheel; export is JPEG, shrunk to fit the rule's size cap. */
export default function ImageCropModal({ file, ruleKey, onDone, onCancel }: Props) {
  const rule: MediaRule = MEDIA_RULES[ruleKey]
  const crop = rule.crop!
  const [url] = useState(() => URL.createObjectURL(file))
  const [nat, setNat] = useState<{ w: number; h: number } | null>(null)
  const [aspectId, setAspectId] = useState(crop.defaultAspect)
  const [zoom, setZoom] = useState(1)
  const [pos, setPos] = useState({ x: 0, y: 0 }) // image centre offset from stage centre (stage px)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const drag = useRef<{ sx: number; sy: number; px: number; py: number } | null>(null)

  useEffect(() => () => URL.revokeObjectURL(url), [url])
  useEffect(() => {
    const img = new Image()
    img.onload = () => setNat({ w: img.naturalWidth, h: img.naturalHeight })
    img.onerror = () => setError('This image could not be read — it may be corrupted.')
    img.src = url
  }, [url])

  // Crop frame size inside the stage (original shape = whole image).
  const aspect = crop.aspects.find(a => a.id === aspectId) ?? crop.aspects[0]
  const ratio = aspect.ratio ?? (nat ? nat.w / nat.h : 1)
  const frame = useMemo(() => {
    let w = STAGE_W, h = STAGE_W / ratio
    if (h > STAGE_MAX_H) { h = STAGE_MAX_H; w = h * ratio }
    return { w, h }
  }, [ratio])

  const baseScale = nat ? Math.max(frame.w / nat.w, frame.h / nat.h) : 1
  const scale = baseScale * zoom

  const clamp = useCallback((p: { x: number; y: number }, z = zoom) => {
    if (!nat) return p
    const s = baseScale * z
    const maxX = Math.max(0, (nat.w * s - frame.w) / 2)
    const maxY = Math.max(0, (nat.h * s - frame.h) / 2)
    return { x: Math.min(maxX, Math.max(-maxX, p.x)), y: Math.min(maxY, Math.max(-maxY, p.y)) }
  }, [nat, baseScale, frame, zoom])

  useEffect(() => { setPos(p => clamp(p)) }, [clamp])
  const reset = () => { setZoom(1); setPos({ x: 0, y: 0 }) }
  useEffect(reset, [aspectId])

  const onPointerDown = (e: React.PointerEvent) => {
    (e.target as HTMLElement).setPointerCapture(e.pointerId)
    drag.current = { sx: e.clientX, sy: e.clientY, px: pos.x, py: pos.y }
  }
  const onPointerMove = (e: React.PointerEvent) => {
    if (!drag.current) return
    setPos(clamp({ x: drag.current.px + e.clientX - drag.current.sx, y: drag.current.py + e.clientY - drag.current.sy }))
  }
  const onWheel = (e: React.WheelEvent) => setZoom(z => Math.min(4, Math.max(1, z - e.deltaY * 0.002)))

  // Source rectangle (natural-pixel coordinates) currently inside the frame.
  const sourceRect = () => {
    const sw = frame.w / scale, sh = frame.h / scale
    return { sx: nat!.w / 2 - pos.x / scale - sw / 2, sy: nat!.h / 2 - pos.y / scale - sh / 2, sw, sh }
  }

  const info = useMemo(() => {
    if (!nat) return null
    const sw = frame.w / scale
    return { outW: Math.round(Math.min(sw, crop.maxWidth)), srcW: Math.round(sw) }
  }, [nat, frame, scale, crop.maxWidth])

  const tooSmall = !!(rule.minWidth && info && info.srcW < rule.minWidth)

  const confirm = async () => {
    if (!nat) return
    setBusy(true); setError(null)
    try {
      const { sx, sy, sw, sh } = sourceRect()
      const img = await new Promise<HTMLImageElement>((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = url })
      const maxBytes = rule.maxMb * 1048576
      let outW = Math.round(Math.min(sw, crop.maxWidth))
      let blob: Blob | null = null
      // Shrink quality, then dimensions, until the file fits the size cap.
      for (let attempt = 0; attempt < 8 && !blob; attempt++) {
        const outH = Math.round(outW * (sh / sw))
        const c = document.createElement('canvas')
        c.width = outW; c.height = outH
        const ctx = c.getContext('2d')!
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, outW, outH)
        ctx.drawImage(img, sx, sy, sw, sh, 0, 0, outW, outH)
        for (const q of [0.92, 0.82, 0.7, 0.55]) {
          const b: Blob | null = await new Promise(r => c.toBlob(r, 'image/jpeg', q))
          if (b && b.size <= maxBytes) { blob = b; break }
        }
        if (!blob) outW = Math.round(outW * 0.8)
      }
      if (!blob) throw new Error('Could not compress the image under the size limit.')
      if (rule.minWidth && outW < rule.minWidth) throw new Error(`Cropped image is only ${outW}px wide — use at least ${rule.minWidth}px.`)
      const name = file.name.replace(/\.[^.]+$/, '') + '.jpg'
      onDone(new File([blob], name, { type: 'image/jpeg' }))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not process the image.')
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 p-4" onMouseDown={e => e.stopPropagation()}>
      <div className="w-full max-w-[460px] bg-white rounded-2xl shadow-2xl overflow-hidden">
        <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
          <div>
            <div className="text-sm font-semibold text-gray-900">Crop image</div>
            <div className="text-[11px] text-gray-500">{rule.hint}</div>
          </div>
          <button onClick={onCancel} className="p-1.5 rounded-lg text-gray-400 hover:bg-gray-100"><X size={16} /></button>
        </div>

        <div className="p-4 space-y-3">
          {/* Aspect presets */}
          <div className="flex flex-wrap gap-1.5">
            {crop.aspects.map(a => (
              <button key={a.id} onClick={() => setAspectId(a.id)}
                className={cn('px-2.5 py-1 rounded-full text-xs border transition-colors',
                  a.id === aspectId ? 'bg-brand-600 text-white border-brand-600' : 'border-gray-200 text-gray-600 hover:border-brand-300')}>
                {a.label}
              </button>
            ))}
          </div>

          {/* Stage */}
          <div className="flex justify-center bg-gray-900 rounded-xl py-3">
            <div
              className="relative overflow-hidden touch-none cursor-grab active:cursor-grabbing select-none"
              style={{ width: frame.w, height: frame.h }}
              onPointerDown={onPointerDown} onPointerMove={onPointerMove}
              onPointerUp={() => (drag.current = null)} onPointerCancel={() => (drag.current = null)}
              onWheel={onWheel}
            >
              {nat ? (
                <img src={url} alt="" draggable={false}
                  className="absolute max-w-none pointer-events-none"
                  style={{
                    width: nat.w * scale, height: nat.h * scale,
                    left: frame.w / 2 - (nat.w * scale) / 2 + pos.x,
                    top: frame.h / 2 - (nat.h * scale) / 2 + pos.y,
                  }} />
              ) : <div className="absolute inset-0 flex items-center justify-center text-white/60"><Loader2 className="animate-spin" size={20} /></div>}
              {/* rule-of-thirds guide */}
              <div className="absolute inset-0 pointer-events-none border border-white/70"
                style={{ backgroundImage: 'linear-gradient(to right,transparent 33%,rgba(255,255,255,.25) 33%,rgba(255,255,255,.25) calc(33% + 1px),transparent calc(33% + 1px),transparent 66%,rgba(255,255,255,.25) 66%,rgba(255,255,255,.25) calc(66% + 1px),transparent calc(66% + 1px)),linear-gradient(to bottom,transparent 33%,rgba(255,255,255,.25) 33%,rgba(255,255,255,.25) calc(33% + 1px),transparent calc(33% + 1px),transparent 66%,rgba(255,255,255,.25) 66%,rgba(255,255,255,.25) calc(66% + 1px),transparent calc(66% + 1px))' }} />
            </div>
          </div>

          {/* Zoom */}
          <div className="flex items-center gap-2">
            <ZoomOut size={14} className="text-gray-400" />
            <input type="range" min={1} max={4} step={0.01} value={zoom}
              onChange={e => { const z = Number(e.target.value); setZoom(z); setPos(p => clamp(p, z)) }}
              className="flex-1 accent-brand-600" />
            <ZoomIn size={14} className="text-gray-400" />
            <button onClick={reset} title="Reset" className="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100"><RotateCcw size={14} /></button>
          </div>

          {/* Info */}
          <div className="flex items-center justify-between text-[11px] text-gray-500">
            <span>Original {nat ? `${nat.w}×${nat.h}` : '…'} · {fmtBytes(file.size)}</span>
            <span>Output ≈ {info ? `${info.outW}px wide` : '…'}{crop.recommended ? ` · best ${crop.recommended}` : ''}</span>
          </div>
          {(tooSmall || error) && (
            <div className="flex gap-2 p-2.5 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-800">
              <AlertTriangle size={14} className="flex-shrink-0 mt-0.5" />
              {error ?? `This crop is only ${info?.srcW}px wide — Meta needs at least ${rule.minWidth}px. Zoom out or use a larger image.`}
            </div>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 px-4 py-3 border-t border-gray-100 bg-gray-50">
          <button onClick={onCancel} className="px-3 py-1.5 text-sm rounded-lg text-gray-600 hover:bg-gray-100">Cancel</button>
          {!crop.required && (
            <button onClick={() => onDone(file)} disabled={busy}
              className="px-3 py-1.5 text-sm rounded-lg border border-gray-200 text-gray-700 hover:bg-white">
              Use original
            </button>
          )}
          <button onClick={confirm} disabled={busy || !nat || tooSmall}
            className="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-sm rounded-lg bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
            {busy ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} Crop &amp; use
          </button>
        </div>
      </div>
    </div>
  )
}
