import { useCallback, useRef, useState } from 'react'
import { toast } from 'react-hot-toast'
import ImageCropModal from '@/components/media/ImageCropModal'
import { MediaRuleKey, validateFile, isCroppable } from '@/utils/mediaRules'

/**
 * Client-side gate for every media pick: checks type/size against the shared rules and, for
 * images, opens the crop editor. Usage:
 *   const { gate, cropModal } = useMediaGate()
 *   const ok = await gate(file, 'header_image'); if (!ok) return   // null = rejected/cancelled
 *   …render {cropModal} once in the component.
 * The server re-validates with the same rules (App\Support\Meta\MediaRules).
 */
export function useMediaGate() {
  const [pending, setPending] = useState<{ file: File; key: MediaRuleKey } | null>(null)
  const resolver = useRef<((f: File | null) => void) | null>(null)

  const gate = useCallback((file: File, key: MediaRuleKey): Promise<File | null> => {
    const croppable = isCroppable(file, key)
    // A croppable image may be re-encoded smaller, so its size is checked after the editor.
    const err = validateFile(file, key, { skipSize: croppable })
    if (err) { toast.error(err); return Promise.resolve(null) }
    if (!croppable) return Promise.resolve(file)
    return new Promise(resolve => { resolver.current = resolve; setPending({ file, key }) })
  }, [])

  const finish = (f: File | null) => {
    const key = pending?.key
    setPending(null)
    if (f && key) {
      const err = validateFile(f, key)
      if (err) { toast.error(err); f = null }
    }
    resolver.current?.(f)
    resolver.current = null
  }

  const cropModal = pending
    ? <ImageCropModal file={pending.file} ruleKey={pending.key} onDone={f => finish(f)} onCancel={() => finish(null)} />
    : null

  return { gate, cropModal }
}
