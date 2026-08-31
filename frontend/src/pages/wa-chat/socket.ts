// Dedicated Socket.IO endpoint for the WA Chat module (Project B / WAHA dashboard).
//
// CRITICAL: this is a SEPARATE realtime connection from Project A's socket. It points
// only at the unichatwa backend and must never be pointed at flownode. Project A's
// existing socket setup is left completely untouched.
//
// The actual io() connection is created inside hooks/useWebSocket.ts (it needs the
// per-render API key and lifecycle); this module owns the URL resolution so there is a
// single, isolated source of truth for the WA Chat socket origin.
import { warnIfInsecureHttpUrl } from './utils/urlSecurity'

// Overridable at build time via a DEDICATED env var (never VITE_WS_URL — keep it distinct
// from any Project A socket config). Defaults to the production unichatwa gateway.
export const WA_CHAT_SOCKET_URL = (
  import.meta.env.VITE_WA_CHAT_WS_URL ?? 'https://unichatwa.univexa.in'
).replace(/\/+$/, '')

// Warn when the socket origin is an insecure http:// URL on a non-localhost host.
warnIfInsecureHttpUrl(WA_CHAT_SOCKET_URL, 'VITE_WA_CHAT_WS_URL')
