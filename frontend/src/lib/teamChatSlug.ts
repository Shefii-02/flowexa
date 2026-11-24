// Team-chat links: /team-chat?c=sales-team-1k9x — the chat's name plus a short code that is the chat id in disguise.
// The name part is only for people (a link reads well); the code is what opens the chat, so renaming a group never breaks a link,
// and the server still checks membership — a code someone guesses opens nothing they may not see. Old ?c=12 links keep working.
const SALT = 7919
const OFFSET = 104729

export const chatCode = (id: number) => (id * SALT + OFFSET).toString(36)

export const slugify = (s: string) =>
  s.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40)

/** group → "sales-team-1k9x"; direct → both names, so the same pair always reads the same: "asif-sneha-1k9x" */
export const chatSlug = (id: number, names: string[]) => `${slugify(names.filter(Boolean).sort().join(' ')) || 'chat'}-${chatCode(id)}`

/** the chat id behind a `?c=` value (slug, bare code or a plain number); null when it is none of those */
export function chatIdFromParam(p: string | null): number | null {
  if (!p) return null
  if (/^\d+$/.test(p)) return Number(p)
  const n = parseInt(p.split('-').pop() ?? '', 36)
  if (!Number.isFinite(n) || n <= OFFSET || (n - OFFSET) % SALT !== 0) return null
  return (n - OFFSET) / SALT
}
