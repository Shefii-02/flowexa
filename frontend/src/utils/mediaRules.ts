// Upload restrictions, mirrored server-side in backend/app/Support/Meta/MediaRules.php —
// change both together. Meta limits: template header IMAGE jpeg/png ≤ 5 MB, VIDEO mp4/3gpp ≤ 16 MB,
// DOCUMENT pdf (capped at 16 MB — the app's upload safety limit).

export interface CropAspect { id: string; label: string; ratio: number | null } // null = keep original shape

export interface MediaRule {
  label: string
  mimes: string[]
  maxMb: number
  /** Hard minimum width for images (px). */
  minWidth?: number
  /** Shown to the user before they pick a file. */
  hint: string
  /** Value for <input accept>. */
  accept: string
  /** Images only: open the crop editor. `required` hides "Use original". */
  crop?: { aspects: CropAspect[]; defaultAspect: string; maxWidth: number; required: boolean; recommended?: string }
}

const ASPECTS: Record<string, CropAspect> = {
  wide:     { id: 'wide',     label: '1.91 : 1 (Recommended)', ratio: 1.91 },
  square:   { id: 'square',   label: '1 : 1 Square',           ratio: 1 },
  portrait: { id: 'portrait', label: '4 : 5 Portrait',         ratio: 4 / 5 },
  video:    { id: 'video',    label: '16 : 9',                 ratio: 16 / 9 },
  original: { id: 'original', label: 'Original shape',         ratio: null },
}
const ALL_ASPECTS = [ASPECTS.wide, ASPECTS.square, ASPECTS.portrait, ASPECTS.video, ASPECTS.original]

export const MEDIA_RULES = {
  header_image: {
    label: 'image', mimes: ['image/jpeg', 'image/png'], maxMb: 5, minWidth: 200,
    accept: 'image/jpeg,image/png', hint: 'JPG or PNG · max 5 MB · best size 800×418 px',
    crop: { aspects: ALL_ASPECTS, defaultAspect: 'wide', maxWidth: 1600, required: false, recommended: '800×418 px (1.91:1)' },
  },
  header_video: {
    label: 'video', mimes: ['video/mp4', 'video/3gpp'], maxMb: 16,
    accept: 'video/mp4,video/3gpp', hint: 'MP4 or 3GP (H.264 + AAC) · max 16 MB',
  },
  header_document: {
    label: 'document', mimes: ['application/pdf'], maxMb: 16,
    accept: 'application/pdf', hint: 'PDF only · max 16 MB',
  },
  chat_image: {
    label: 'image', mimes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], maxMb: 16,
    accept: 'image/jpeg,image/png,image/webp,image/gif', hint: 'JPG, PNG, WebP or GIF · max 16 MB',
    crop: { aspects: ALL_ASPECTS, defaultAspect: 'original', maxWidth: 2048, required: false },
  },
  chat_video: {
    label: 'video', mimes: ['video/mp4', 'video/3gpp', 'video/quicktime', 'video/webm'], maxMb: 16,
    accept: 'video/mp4,video/3gpp,video/quicktime,video/webm', hint: 'MP4, 3GP, MOV or WebM · max 16 MB',
  },
  chat_audio: {
    label: 'audio', mimes: ['audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/amr', 'audio/webm'], maxMb: 16,
    accept: 'audio/*', hint: 'MP3, OGG, AAC, M4A, WAV or AMR · max 16 MB',
  },
  library: {
    label: 'file', maxMb: 100, accept: 'image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip',
    mimes: [
      'image/jpeg', 'image/png', 'image/webp', 'image/gif',
      'video/mp4', 'video/3gpp', 'video/quicktime', 'video/webm',
      'audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/amr', 'audio/webm',
      'application/pdf', 'text/plain', 'text/csv', 'application/zip', 'application/x-zip-compressed',
      'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ],
    hint: 'Images, video, audio, PDF, Office files, ZIP · max 100 MB',
    crop: { aspects: ALL_ASPECTS, defaultAspect: 'original', maxWidth: 2048, required: false },
  },
} satisfies Record<string, MediaRule>

export type MediaRuleKey = keyof typeof MEDIA_RULES

export const fmtBytes = (b: number) =>
  b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`

const EXT_MIME: Record<string, string> = {
  jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif',
  mp4: 'video/mp4', '3gp': 'video/3gpp', mov: 'video/quicktime', webm: 'video/webm',
  pdf: 'application/pdf', mp3: 'audio/mpeg', ogg: 'audio/ogg', wav: 'audio/wav', aac: 'audio/aac', m4a: 'audio/mp4', amr: 'audio/amr',
}
const mimeOf = (f: File) => f.type || EXT_MIME[f.name.split('.').pop()?.toLowerCase() ?? ''] || ''

/** Type check (always) + size check. Returns a user-facing error or null. */
export function validateFile(file: File, key: MediaRuleKey, opts: { skipSize?: boolean } = {}): string | null {
  const rule: MediaRule = MEDIA_RULES[key]
  const mime = mimeOf(file)
  if (!rule.mimes.includes(mime)) {
    const allowed = [...new Set(rule.mimes.map(m => m.split('/')[1].toUpperCase()))].slice(0, 6).join(', ')
    return key === 'library'
      ? `"${file.name}" isn't a supported file type.`
      : `Unsupported ${rule.label} type${mime ? ` (${mime})` : ''}. Allowed: ${allowed}.`
  }
  if (!opts.skipSize && file.size > rule.maxMb * 1048576) {
    return `${rule.label[0].toUpperCase() + rule.label.slice(1)} is ${fmtBytes(file.size)} — the maximum is ${rule.maxMb} MB.`
  }
  return null
}

export const isCroppable = (file: File, key: MediaRuleKey) => {
  const rule: MediaRule = MEDIA_RULES[key]
  const m = mimeOf(file)
  return !!rule.crop && ['image/jpeg', 'image/png', 'image/webp'].includes(m) // GIFs stay untouched (animation)
}

/** Rule for a message-composer block / media type ('image' | 'video' | 'audio' | 'document' …). */
export const ruleForBlockType = (type: string): MediaRuleKey =>
  type === 'image' ? 'chat_image' : type === 'video' ? 'chat_video' : type === 'audio' ? 'chat_audio' : 'library'
