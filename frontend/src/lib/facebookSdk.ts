// Loads Facebook's JS SDK once and reuses window.FB if another screen already loaded it.
let ready: Promise<any> | null = null

export function loadFacebookSdk(appId: string): Promise<any> {
  const w = window as any
  if (w.FB) return Promise.resolve(w.FB)
  ready ??= new Promise((resolve, reject) => {
    w.fbAsyncInit = () => { w.FB.init({ appId, cookie: true, xfbml: false, version: 'v21.0' }); resolve(w.FB) }
    const el = document.createElement('script')
    el.src = 'https://connect.facebook.net/en_US/sdk.js'; el.async = true
    el.onerror = () => { ready = null; reject(new Error('Could not load Facebook login.')) }
    document.body.appendChild(el)
  })
  return ready
}
