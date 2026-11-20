// Loads Facebook's JS SDK once and returns it. Shared by every screen that connects a Meta asset with "Continue with Facebook".
let fbReady: Promise<any> | null = null

export function loadFacebookSdk(appId: string): Promise<any> {
  const w = window as any
  if (w.FB) return Promise.resolve(w.FB)                       // already loaded by another screen
  fbReady ??= new Promise((resolve, reject) => {
    w.fbAsyncInit = () => { w.FB.init({ appId, cookie: true, xfbml: false, version: 'v21.0' }); resolve(w.FB) }
    const el = document.createElement('script')
    el.src = 'https://connect.facebook.net/en_US/sdk.js'; el.async = true
    el.onerror = () => { fbReady = null; reject(new Error('Could not load Facebook login. Check your connection or ad blocker.')) }
    document.body.appendChild(el)
  })
  return fbReady
}

/** Opens the Facebook login popup and resolves with the user access token, or null when the person closed it / said no. */
export async function facebookLogin(appId: string, scope: string): Promise<string | null> {
  const FB = await loadFacebookSdk(appId)
  return new Promise(resolve => {
    FB.login((res: any) => resolve(res?.authResponse?.accessToken ?? null), { scope })
  })
}
