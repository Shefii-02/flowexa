// Ids in website addresses: /crm/leads/u0ha86wjm4g instead of /crm/leads/12.
// Every id gets one unique, random-looking code (and back) from a server-provided key — nothing to look up, the same for everyone so a shared link
// opens for whoever it is sent to. The numbers 1, 2, 3 … no longer show or can be counted up. The server still decides who may open what.
// Old numeric links and the first-generation codes keep working.
import { useParams } from 'react-router-dom'

const PREFIX = 'u'   // an encoded id always starts with a letter, so it can never be mistaken for an old all-digit id
const KEY_STORE = 'pid_key'
const MAX_ID = 2 ** 31 - 1

/** The key comes from the server with the signed-in user (same for everyone, so a link works for whoever opens it). */
export const setPidKey = (key?: string | null) => { try { if (key) localStorage.setItem(KEY_STORE, key) } catch { /* private mode */ } }
const keyParts = (): number[] | null => {
  try {
    const k = localStorage.getItem(KEY_STORE)
    if (!k || k.length < 32) return null
    return [0, 8, 16, 24].map(i => parseInt(k.slice(i, i + 8), 16) >>> 0)
  } catch { return null }
}

// A 4-round Feistel network over 48 bits: every id maps to one unique, random-looking 10-character code, and back. Not encryption you
// should trust with secrets — the server still decides who may open what — just ids that are not 1, 2, 3 … and cannot be guessed.
const round = (x: number, k: number, r: number): number => {
  let h = (x ^ k ^ Math.imul(r + 1, 0x9e3779b1)) >>> 0
  h = Math.imul(h ^ (h >>> 15), 0x85ebca6b) >>> 0
  h = Math.imul(h ^ (h >>> 13), 0xc2b2ae35) >>> 0
  h = (h ^ (h >>> 16)) >>> 0
  return h & 0xffffff
}
const TWO24 = 2 ** 24
const feistel = (v: number, key: number[], decode: boolean): number => {
  let l = Math.floor(v / TWO24), r = v % TWO24
  for (let i = 0; i < 4; i++) {
    const rr = decode ? 3 - i : i
    if (!decode) { const t = r; r = (l ^ round(r, key[rr], rr)) >>> 0; l = t }
    else { const t = l; l = (r ^ round(l, key[rr], rr)) >>> 0; r = t }
  }
  return l * TWO24 + r
}

// the first scheme (reversible but not keyed) — still understood so addresses shared earlier keep working
const OLD_SALT = 7919, OLD_OFFSET = 104729

/** 12 → "u3k9x2m8q1a" — a random-looking code; with no key yet it falls back to the plain number (which every page still accepts) */
export const pid = (id: number | string | null | undefined): string => {
  const n = Number(id)
  if (!Number.isFinite(n) || n <= 0 || n > MAX_ID) return String(id ?? '')
  const key = keyParts()
  if (!key) return String(n)
  return PREFIX + feistel(n, key, false).toString(36).padStart(10, '0')
}

/** code → 12; an old plain number → itself; anything else → null */
export function decodePid(p: string | null | undefined): number | null {
  if (!p) return null
  if (/^\d+$/.test(p)) return Number(p)
  if (!p.startsWith(PREFIX)) return null
  const body = p.slice(1)
  if (body.length === 10) {
    const key = keyParts()
    if (!key) return null
    const v = parseInt(body, 36)
    if (!Number.isFinite(v) || v >= 2 ** 48) return null
    const id = feistel(v, key, true)
    return id > 0 && id <= MAX_ID ? id : null   // anything that does not decode to a real-sized id is not one of ours
  }
  const n = parseInt(body, 36)   // old scheme
  if (!Number.isFinite(n) || n <= OLD_OFFSET || (n - OLD_OFFSET) % OLD_SALT !== 0) return null
  return (n - OLD_OFFSET) / OLD_SALT
}

/** the route-param names that carry a record id (the others, like :widget, are names) */
const ID_KEYS = ['id', 'staffId', 'builderId', 'campaignId']

/** Drop-in for react-router's useParams: id-like params come back decoded to the plain number (as text), so pages need no other change. */
export function useDecodedParams<T extends Record<string, string | undefined> = Record<string, string | undefined>>(): T {
  const raw = useParams()
  const out: Record<string, string | undefined> = { ...raw }
  for (const k of ID_KEYS) {
    if (out[k] !== undefined) { const n = decodePid(out[k]); if (n !== null) out[k] = String(n) }
  }
  return out as T
}
