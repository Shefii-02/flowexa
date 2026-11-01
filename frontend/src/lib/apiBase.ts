/**
 * The public API root an integrator must call, e.g. `https://api.teamzo.io/api/v1`.
 *
 * It is NOT `window.location.origin`: the dashboard is served from the website host (teamzo.io), which does not
 * route /api/* to Laravel — a curl against it returns nginx's "405 Not Allowed". The API lives on its own host and
 * the app already knows it (VITE_API_URL, the same value the axios client uses), so the docs show exactly that.
 * Falls back to the page origin only when no API URL is configured (local dev, where the dev-server ports map to :8000).
 */
const configured = (import.meta.env.VITE_API_URL as string | undefined)?.trim()

function resolve(): string {
  if (configured) {
    return (/^https?:\/\//i.test(configured) ? configured : `${window.location.origin}${configured}`).replace(/\/+$/, '')
  }
  return `${window.location.origin.replace(':3000', ':8000').replace(':5173', ':8000')}/api/v1`
}

export const API_ROOT = resolve()
