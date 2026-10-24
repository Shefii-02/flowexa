/* Firebase Cloud Messaging service worker — shows push notifications while the tab is closed/background. */
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js')
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js')

firebase.initializeApp({
  apiKey: 'AIzaSyDLrWg_GETKUNykUEpS1CW-fNe518npiF8',
  authDomain: 'teamzo-push-app.firebaseapp.com',
  projectId: 'teamzo-push-app',
  storageBucket: 'teamzo-push-app.firebasestorage.app',
  messagingSenderId: '310581291802',
  appId: '1:310581291802:web:044480cd63043e84369aa3',
})

// Payloads carry a `notification` block, so the browser displays them itself;
// this just keeps the worker registered and handles click-through.
firebase.messaging()

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  event.waitUntil(clients.openWindow('/'))
})
