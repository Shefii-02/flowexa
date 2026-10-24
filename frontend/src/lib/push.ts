import { initializeApp } from 'firebase/app'
import { getMessaging, getToken, isSupported, onMessage } from 'firebase/messaging'
import toast from 'react-hot-toast'
import api from '@/api/client'

const config = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
}

let registeredToken: string | null = null

export type WebPushResult = { ok: boolean; reason?: string }

/** Why web push can't work in this browser right now (no side effects). */
export async function webPushSupport(): Promise<{ supported: boolean; permission: NotificationPermission | 'unsupported'; hasVapid: boolean }> {
  const supported = 'Notification' in window && 'serviceWorker' in navigator && (await isSupported().catch(() => false))
  return {
    supported,
    permission: 'Notification' in window ? Notification.permission : 'unsupported',
    hasVapid: !!import.meta.env.VITE_FIREBASE_VAPID_KEY,
  }
}

/** Asks for notification permission, gets an FCM token and registers it with the backend. Safe to call repeatedly. */
export async function registerWebPush(): Promise<WebPushResult> {
  try {
    const vapidKey = import.meta.env.VITE_FIREBASE_VAPID_KEY
    if (!vapidKey) return { ok: false, reason: 'VITE_FIREBASE_VAPID_KEY is not set in the web build.' }
    if (!(await isSupported()) || !('Notification' in window)) return { ok: false, reason: 'This browser does not support web push (HTTPS required).' }
    if ((await Notification.requestPermission()) !== 'granted') return { ok: false, reason: 'Notifications are blocked for this site in the browser settings.' }

    const app = initializeApp(config)
    const messaging = getMessaging(app)
    const sw = await navigator.serviceWorker.register('/firebase-messaging-sw.js')
    const token = await getToken(messaging, { vapidKey, serviceWorkerRegistration: sw })
    if (!token) return { ok: false, reason: 'Firebase did not return a token.' }
    if (token === registeredToken) return { ok: true }

    await api.post('/push/register-token', { fcm_token: token, device_type: 'web' })
    registeredToken = token

    // Foreground messages aren't shown by the browser — surface them as a toast.
    onMessage(messaging, (m) => {
      if (m.notification) toast(`${m.notification.title ?? ''}${m.notification.body ? ' — ' + m.notification.body : ''}`, { icon: '🔔' })
    })
    return { ok: true }
  } catch (e: any) {
    console.warn('Web push registration failed', e)
    return { ok: false, reason: e?.message ?? 'Registration failed.' }
  }
}
