// Notification sounds. The backend decides which tone a notification gets (config/notification_tones.php) and sends its key;
// the files are served from {backend}/tones/. A browser blocks sound until the person has clicked something on the page — then it plays.
const BASE_URL = import.meta.env.VITE_API_URL || '/api/v1'
let origin = ''
try { origin = new URL(BASE_URL, window.location.origin).origin } catch { origin = window.location.origin }

export const TONE_FILES: Record<string, string> = {
  lead_popup: 'lead_popup_notifications.mp3',
  lead: 'lead_notifications.mp3',
  conversation: 'conversation_tone.mp3',
  general: 'general_notifications.mp3',
}
const MUTE_KEY = 'tone_muted'
export const tonesMuted = (): boolean => { try { return localStorage.getItem(MUTE_KEY) === '1' } catch { return false } }
export const setTonesMuted = (m: boolean) => { try { localStorage.setItem(MUTE_KEY, m ? '1' : '0') } catch { /* private mode */ } }

const cache: Record<string, HTMLAudioElement> = {}
const last: Record<string, number> = {}

/** Play a tone by key (lead_popup | lead | conversation | general). The same tone within a second is ignored; the mute switch silences all. */
export function playTone(key?: string | null): void {
  if (tonesMuted()) return
  const file = TONE_FILES[key ?? 'general'] ?? TONE_FILES.general
  const now = Date.now()
  if (last[file] && now - last[file] < 1000) return
  last[file] = now
  try {
    const a = (cache[file] ??= new Audio(`${origin}/tones/${file}`))
    a.currentTime = 0
    void a.play().catch(() => { /* no click on the page yet — the browser refuses; the next one will play */ })
  } catch { /* no audio support */ }
}
