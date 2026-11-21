// Helpers for the WA Chat API Service guide: what a template needs from the caller, and a ready-to-run request for it.
// Keep AUTO_TOKENS in step with the backend's ApiTemplateVariables::AUTO — the API refuses a call whose variables do not cover the rest.

/** Tokens the platform fills in itself — never sent by the caller. */
export const AUTO_TOKENS = ['otp', 'code', 'expiry', 'company', 'company_name', 'business name', 'time', 'date']

/** Every {{token}} in a template, de-duplicated, in first-seen order. */
export function templateTokens(content: string | null | undefined): string[] {
  return [...new Set([...(content ?? '').matchAll(/\{\{\s*(.+?)\s*\}\}/g)].map(m => m[1].trim()))]
}

/** What the caller has to pass in `variables`. */
export function requiredVariables(content: string | null | undefined): string[] {
  return templateTokens(content).filter(t => !AUTO_TOKENS.includes(t.toLowerCase()))
}

export type DocKind = 'auth' | 'utility' | 'invoice'
export interface DocConfig { id: number; name: string; kind: DocKind; content: string | null }

/** The endpoint path for a kind, after `{baseUrl}`. */
export const endpointFor = (kind: DocKind) => (kind === 'auth' ? '/otp/send' : kind === 'utility' ? '/api-service/utility-send' : '/api-service/invoice-share')

/** The JSON body for a template: receiver number, template id and every variable the template needs. */
export function exampleBody(c: DocConfig): Record<string, unknown> {
  if (c.kind === 'invoice') return { phone: '919876543210', template_id: c.id }
  const vars = requiredVariables(c.content)
  return { phone: '919876543210', template_id: c.id, ...(vars.length ? { variables: Object.fromEntries(vars.map(v => [v, `<${v}>`])) } : {}) }
}

/** A cURL command (one line per flag) for terminal / Postman import. */
export function curlFor(c: DocConfig, baseUrl: string, token: string): string {
  return `curl -X POST ${baseUrl}${endpointFor(c.kind)} \\\n  -H "Authorization: Bearer ${token}" \\\n  -H "Content-Type: application/json" \\\n  -d '${JSON.stringify(exampleBody(c), null, 2)}'`
}

/** The sentence the API (and the Test Send tab) uses when variables are missing — keep it word-for-word with ApiTemplateVariables::failure(). */
export function missingVariablesMessage(missing: string[], templateName?: string | null): string {
  if (missing.length === 0) return ''
  const list = `"${missing.join('", "')}"`
  const forWhat = templateName ? `this template (${templateName})` : 'this template'
  return `${missing.length === 1 ? `The variable ${list} is` : `The variables ${list} are`} missing for ${forWhat}.`
}

/** A signed cURL for a service that requires signed requests: the body is built once, the HMAC is made from "{timestamp}.{body}" and the same bytes are sent. */
export function curlSignedFor(c: DocConfig, baseUrl: string, token: string): string {
  const body = JSON.stringify(exampleBody(c))
  return `# the signing secret from Settings → "Require signed requests" (it never leaves your server)\nSIGNING_SECRET="<your-signing-secret>"\nBODY='${body}'\nTS=$(date +%s)\nSIG=$(printf '%s' "$TS.$BODY" | openssl dgst -sha256 -hmac "$SIGNING_SECRET" | sed 's/^.* //')\n\ncurl -X POST ${baseUrl}${endpointFor(c.kind)} \\\n  -H "Authorization: Bearer ${token}" \\\n  -H "Content-Type: application/json" \\\n  -H "X-Timestamp: $TS" \\\n  -H "X-Signature: $SIG" \\\n  -d "$BODY"`
}
