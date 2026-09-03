/**
 * Search filtering and status grouping for the three conversation tabs.
 *
 * One search box drives Chats, Channels and Status, but each tab matches on different fields, and the
 * Status tab additionally collapses a flat list into one row per contact with a two-axis ordering.
 * As inline consts inside the page these were three unrelated rules reading one piece of state; as
 * functions they take the query as an argument and can be tested.
 */

/** Case-insensitive "does any of these fields contain the query". Absent fields never match. */
const matches = (query: string, ...fields: (string | undefined)[]): boolean => {
  const needle = query.toLowerCase();
  return fields.some(f => (f ?? '').toLowerCase().includes(needle));
};

interface ChatLike {
  id: string;
  name?: string;
  kind?: string;
}

/** The subset of an engine contact record the chat list needs. */
export interface ChatContactInfo {
  /** Name from the account's addressbook — only a saved contact has one. */
  name?: string;
  /** Name the contact set for themselves. */
  pushName?: string;
  /** MSISDN digits, no separators. */
  number?: string;
}

/**
 * A chat-id → contact lookup, indexed two ways: by the exact engine contact id, and (fallback) by
 * the last 10 digits of the contact's phone number. The second index bridges the common mismatch
 * where the chat id is an `@lid` privacy id or carries a different country-code form than the
 * saved number.
 */
export interface ContactIndex {
  byId: Map<string, ChatContactInfo>;
  byPhone10: Map<string, ChatContactInfo>;
}

export function buildContactIndex(
  contacts: readonly { id: string; name?: string; pushName?: string; number?: string }[] | undefined,
): ContactIndex {
  const byId = new Map<string, ChatContactInfo>();
  const byPhone10 = new Map<string, ChatContactInfo>();
  for (const c of contacts ?? []) {
    const info: ChatContactInfo = { name: c.name, pushName: c.pushName, number: c.number };
    if (c.id) byId.set(c.id, info);
    const p10 = (c.number ?? '').replace(/\D/g, '').slice(-10);
    if (p10.length >= 7) byPhone10.set(p10, info);
  }
  return { byId, byPhone10 };
}

/** Resolve the contact record for a chat id: by exact id, then by the phone digits inside the id. */
export function lookupChatContact(chatId: string, index?: ContactIndex): ChatContactInfo | undefined {
  if (!index || !chatId) return undefined;
  const direct = index.byId.get(chatId);
  if (direct) return direct;
  const digits = chatId.replace(/@[a-z.]+$/i, '').replace(/[^0-9]/g, '');
  return digits.length >= 7 ? index.byPhone10.get(digits.slice(-10)) : undefined;
}

/**
 * Chats tab: real conversations only. Channel- and status-kind rows are hidden here and surfaced on
 * their own tabs instead, so a channel never appears twice.
 *
 * With `contactIndex` supplied, the query also matches the saved contact's name, the contact's push
 * name, and the contact's real phone number — so a chat whose id is an `@lid` still turns up on a
 * number search.
 */
export function filterChats<T extends ChatLike>(chats: T[], query: string, contactIndex?: ContactIndex): T[] {
  return chats.filter(c => {
    if (c.kind === 'channel' || c.kind === 'status') return false;
    if (!query) return true;
    if (matches(query, c.name)) return true;

    // Match on the phone number. The individual chat id is `<number>@c.us`, so stripping the
    // domain gives the number itself; the saved contact record carries it explicitly (and is the
    // only source for an `@lid` chat, whose id is not a phone). Compare digits-only both sides so
    // a query with spaces / `+` / country-code differences still matches, as a substring so a
    // partial number works too.
    const rawId = (c.id ?? '').replace(/@[a-z.]+$/i, '');
    const idDigits = rawId.replace(/[^0-9]/g, '');
    const qDigits  = query.replace(/[^0-9]/g, '');

    const contact = lookupChatContact(c.id ?? '', contactIndex);
    // Saved addressbook name and the contact's own push name.
    if (contact && matches(query, contact.name, contact.pushName)) return true;
    const contactDigits = (contact?.number ?? '').replace(/[^0-9]/g, '');

    if (qDigits.length >= 4) {
      if (idDigits.includes(qDigits)) return true;
      if (contactDigits.includes(qDigits)) return true;
    }
    return matches(query, rawId);
  });
}

/** Channels tab: same search box, matched on the channel's own name/id. */
export function filterChannels<T extends { id: string; name: string }>(channels: T[], query: string): T[] {
  return channels.filter(ch => matches(query, ch.name, ch.id));
}

interface StatusItemLike<C> {
  contact: C;
  /** ISO-8601. Compared lexically, which orders ISO strings correctly — the store hands them over as
   *  strings and never as epoch numbers. */
  timestamp: string;
}

/**
 * Status tab: collapse the flat status list to one row per contact.
 *
 * Two orderings, and they run in opposite directions on purpose. Groups are sorted newest-contact
 * first, so the most recently active contact heads the list. Within a group the items are flipped to
 * oldest-first, because the store returns statuses newest-first (postedAt DESC) and the viewer opens
 * at the newest — reading like a WhatsApp story, newest at the bottom where the scroll lands.
 *
 * Getting either direction wrong is invisible in a screenshot and obvious to a user, which is why it
 * is pinned by a test rather than left inline.
 */
export function groupStatusesByContact<
  C extends { id: string; name?: string; pushName?: string },
  T extends StatusItemLike<C>,
>(statuses: T[], query: string): { contact: C; items: T[]; latest: string }[] {
  const byContact = new Map<string, { contact: C; items: T[]; latest: string }>();
  for (const item of statuses) {
    const existing = byContact.get(item.contact.id);
    if (existing) {
      existing.items.push(item);
      if (item.timestamp > existing.latest) existing.latest = item.timestamp;
    } else {
      byContact.set(item.contact.id, { contact: item.contact, items: [item], latest: item.timestamp });
    }
  }
  for (const group of byContact.values()) group.items.reverse();
  return Array.from(byContact.values())
    .filter(g => matches(query, g.contact.name, g.contact.pushName, g.contact.id))
    .sort((a, b) => (a.latest < b.latest ? 1 : a.latest > b.latest ? -1 : 0));
}
