// Shared avatar helpers for the wa-cloud inbox.
//
// The Meta WhatsApp Cloud API does NOT expose contact profile photos, so we render
// a deterministic coloured initials avatar — the same fallback WhatsApp itself shows
// when a contact has no picture. Colour is derived from the phone/name so a contact
// always gets the same swatch.

const PALETTE = [
  '#0f6e56', '#1d9e75', '#2b7a9e', '#3b6ea5', '#6d5bd0',
  '#9b3fb5', '#c2456f', '#c9662b', '#b8892a', '#4b8b3b',
]

export function avatarColor(seed: string): string {
  let h = 0
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0
  return PALETTE[h % PALETTE.length]
}

export function initials(s: string): string {
  return (s || '').trim().split(/\s+/).slice(0, 2)
    .map(w => w[0]?.toUpperCase() ?? '')
    .join('') || '#'
}
