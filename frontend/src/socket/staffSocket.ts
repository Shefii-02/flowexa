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
