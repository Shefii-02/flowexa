// Which sidebar URL belongs to which plan module — the browser-side twin of backend App\Support\Modules.
// The server enforces the same split on the API, so a hidden page can never be reached by typing its URL either.
export const PATH_MODULES: [prefix: string, module: string][] = [
  ['/media-library', 'media_library'], ['/wa-chat/media-library', 'media_library'], ['/short-links', 'short_links'], ['/crm/short-links', 'short_links'],
  ['/sticky-notes', 'sticky_notes'], ['/todo', 'todo'], ['/settings/integrations', 'integrations'],
  ['/wa-chat', 'wa_chat'], ['/wa-cloud', 'wa_cloud'], ['/phone-numbers', 'wa_cloud'], ['/flow', 'wa_cloud'],
  ['/ai-agent', 'ai_agent'], ['/wa-agent', 'ai_agent'],
  ['/tasks', 'tasks'], ['/crm/leads', 'leads'], ['/crm', 'crm'],
  ['/hr', 'hr'], ['/accounts', 'finance'],
  ['/instagram', 'social_dm'], ['/messenger', 'social_dm'], ['/website-widget', 'web_widgets'], ['/meta-ads', 'meta_ads'],
]

/** Pages that are open when ANY one of several modules is on (the Flow Builder serves WA Cloud, Instagram / Messenger and the website widget). */
export const ANY_MODULES: [prefix: string, modules: string[]][] = [['/flow-builders', ['wa_cloud', 'social_dm', 'web_widgets']]]

/** The modules a URL accepts (any one is enough), or null when it is not one of the "any of" pages. */
export function anyModulesForPath(pathname: string): string[] | null {
  for (const [prefix, mods] of ANY_MODULES) if (pathname === prefix || pathname.startsWith(prefix + '/')) return mods
  return null
}

/** The module a URL needs, or null when the page is part of every plan. Longest prefix wins (/crm/leads before /crm). */
export function moduleForPath(pathname: string): string | null {
  let best: [string, string] | null = null
  for (const [prefix, mod] of PATH_MODULES) {
    if ((pathname === prefix || pathname.startsWith(prefix + '/')) && (!best || prefix.length > best[0].length)) best = [prefix, mod]
  }
  return best ? best[1] : null
}
