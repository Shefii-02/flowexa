// src/socket/staffSocket.ts
// Connects to the Node.js /staff namespace for real-time lead notifications.
import { io, Socket } from 'socket.io-client'
import { store } from '@/store'
import { addNotification, addAiOffer, updateAssignment } from '@/store/slices'
import type { LeadNotification, AiHandoffOffer, LeadAssignment } from '@/types'

const NODE_URL = import.meta.env.VITE_NODE_URL || 'http://localhost:3000'

let socket: Socket | null = null

export function connectStaffSocket(staffId: number, companyId: number): void {
  if (socket?.connected) return

  socket = io(`${NODE_URL}/staff`, {
    transports: ['websocket'],
    reconnectionAttempts: 5,
    reconnectionDelay: 2000,
  })

  socket.on('connect', () => {
    socket!.emit('staff_online', { staff_id: staffId, company_id: companyId })
  })

  // Ask once, up front, so a real OS-level notification can fire even when this tab
  // isn't focused — the in-app popup below only shows while the dashboard is open.
  if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
    void Notification.requestPermission()
  }

  socket.on('new_lead_notification', (data: LeadNotification) => {
    store.dispatch(addNotification(data))

    // Play notification sound
    try {
      const audio = new Audio('/sounds/lead-notification.mp3')
      audio.volume = 0.6
      void audio.play()
    } catch {
      // Audio might be blocked — ignore
    }

    // Desktop notification — only worth showing if the dashboard tab isn't the one in focus.
    try {
      if (typeof Notification !== 'undefined' && Notification.permission === 'granted' && document.hidden) {
        const n = new Notification('🔔 New lead assigned to you', {
          body: `${data.contact_name} · ${data.contact_phone}\nAccept within ${data.timeout_seconds}s`,
          tag: `lead-assignment-${data.assignment_id}`,
          requireInteraction: true,
        })
        n.onclick = () => { window.focus(); n.close() }
      }
    } catch {
      // Notification API might be unavailable — ignore
    }
  })

  socket.on('ai_handoff_offer', (data: AiHandoffOffer) => {
    store.dispatch(addAiOffer(data))
  })

  socket.on('lead_assignment_update', (data: LeadAssignment) => {
    store.dispatch(updateAssignment(data))
  })

  socket.on('disconnect', () => {
    // Will auto-reconnect
  })
}

export function disconnectStaffSocket(staffId: number): void {
  if (!socket) return
  socket.emit('staff_offline', { staff_id: staffId })
  socket.disconnect()
  socket = null
}

export function getStaffSocket(): Socket | null {
  return socket
}
