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

/** Why a login gave no token: 'closed' = the popup was shut / blocked, 'denied' = the person (or the app's setup) refused it. */
export type FacebookLoginResult = { token: string | null; reason?: 'closed' | 'denied'; granted?: string }

/** Like facebookLogin, but says why there is no token, and which permissions were granted. */
export async function facebookLoginDetailed(appId: string, scope: string, configId?: string): Promise<FacebookLoginResult> {
  const FB = await loadFacebookSdk(appId)
  return new Promise(resolve => {
    FB.login((res: any) => {
      console.warn('[facebook login]', res)   // status 'unknown' + no authResponse = closed, blocked, or refused by the app's setup
      const token = res?.authResponse?.accessToken ?? null
      if (token) return resolve({ token, granted: res.authResponse.grantedScopes })
      resolve({ token: null, reason: res?.status === 'not_authorized' ? 'denied' : 'closed' })
    }, configId ? { config_id: configId } : { scope, return_scopes: true, auth_type: 'rerequest' })   // a Facebook Login for Business app takes its permissions from a configuration, not `scope`
  })
}

/** Opens the Facebook login popup and resolves with the user access token, or null when the person closed it / said no. */
export async function facebookLogin(appId: string, scope: string): Promise<string | null> {
  const FB = await loadFacebookSdk(appId)
  return new Promise(resolve => {
    FB.login((res: any) => resolve(res?.authResponse?.accessToken ?? null), { scope })
  })
}
