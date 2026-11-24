// Team-chat links: /team-chat?c=sales-team-1k9x — the chat's name plus a short code that is the chat id in disguise.
// The name part is only for people (a link reads well); the code is what opens the chat, so renaming a group never breaks a link,
// and the server still checks membership — a code someone guesses opens nothing they may not see. Old ?c=12 links keep working.
import { decodePid, pid } from './publicId'

export const chatCode = (id: number) => pid(id)

export const slugify = (s: string) =>
  s.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40)

/** group → "sales-team-1k9x"; direct → both names, so the same pair always reads the same: "asif-sneha-1k9x" */
export const chatSlug = (id: number, names: string[]) => `${slugify(names.filter(Boolean).sort().join(' ')) || 'chat'}-${chatCode(id)}`

/** the chat id behind a `?c=` value (slug, bare code or a plain number); null when it is none of those */
export function chatIdFromParam(p: string | null): number | null {
  if (!p) return null
  const direct = decodePid(p)
  if (direct) return direct
  const tail = p.split('-').pop() ?? ''   // "sales-team-u0ha86wjm4g" → the code at the end
  const n = decodePid(tail)
  if (n) return n
  const old = parseInt(tail, 36)   // links made by the first scheme ("…-1k9x")
  return Number.isFinite(old) && old > 104729 && (old - 104729) % 7919 === 0 ? (old - 104729) / 7919 : null
}
