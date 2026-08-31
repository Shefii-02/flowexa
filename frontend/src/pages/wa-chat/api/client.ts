// Dedicated API client for the WA Chat module (Project B / WAHA dashboard).
//
// CRITICAL: this module talks ONLY to the unichatwa backend. It must never share
// state with Project A's flownode-api client (src/api/client.ts). Keep the base URL,
// auth mechanism (X-API-Key from sessionStorage) and env var name separate from
// Project A so the two backends can never be mixed.
import axios, { type AxiosInstance } from 'axios'

// Project B authenticates with an API key stored in sessionStorage under this key.
export const WA_CHAT_API_KEY_STORAGE = 'openwa_api_key'

// Base origin for the WA Chat backend. Overridable at build time via a DEDICATED env
// var (never VITE_API_URL — that belongs to Project A's flownode client). Defaults to
// the production unichatwa gateway.
const WA_CHAT_API_ORIGIN = (
  import.meta.env.VITE_WA_CHAT_API_URL ?? 'https://unichatwa.univexa.in'
).replace(/\/+$/, '')

// The '/api' prefix every WA Chat endpoint lives under (mirrors Project B's api.ts).
export const WA_CHAT_API_BASE_URL = `${WA_CHAT_API_ORIGIN}/api`

// Axios instance scoped to the WA Chat backend. The fetch-based service layer in
// ./api.ts is the primary caller path (ported verbatim from Project B); this instance
// is exported for any axios-based call sites and to guarantee a single, isolated client
// pointing at unichatwa.univexa.in.
export const waChatApi: AxiosInstance = axios.create({
  baseURL: WA_CHAT_API_BASE_URL,
  headers: { Accept: 'application/json' },
  timeout: 30_000,
})

// Attach the WA Chat API key on every request.
waChatApi.interceptors.request.use((config) => {
  const apiKey = sessionStorage.getItem(WA_CHAT_API_KEY_STORAGE)
  if (apiKey) config.headers['X-API-Key'] = apiKey
  return config
})

export default waChatApi
