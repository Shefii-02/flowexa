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

/** Asks for notification permission, gets an FCM token and registers it with the backend. Safe to call repeatedly. */
export async function registerWebPush(): Promise<void> {
  try {
    const vapidKey = import.meta.env.VITE_FIREBASE_VAPID_KEY
    if (!vapidKey || !(await isSupported()) || !('Notification' in window)) return
    if ((await Notification.requestPermission()) !== 'granted') return

    const app = initializeApp(config)
    const messaging = getMessaging(app)
    const sw = await navigator.serviceWorker.register('/firebase-messaging-sw.js')
    const token = await getToken(messaging, { vapidKey, serviceWorkerRegistration: sw })
    if (!token || token === registeredToken) return

    await api.post('/push/register-token', { fcm_token: token, device_type: 'web' })
    registeredToken = token

    // Foreground messages aren't shown by the browser — surface them as a toast.
    onMessage(messaging, (m) => {
      if (m.notification) toast(`${m.notification.title ?? ''}${m.notification.body ? ' — ' + m.notification.body : ''}`, { icon: '🔔' })
    })
  } catch (e) {
    console.warn('Web push registration failed', e)
  }
}
