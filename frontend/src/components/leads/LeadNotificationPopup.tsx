// src/components/leads/LeadNotificationPopup.tsx
import { useEffect, useRef, useState } from 'react'
import { useAppDispatch, useAppSelector } from '@/store'
import { removeNotification } from '@/store/slices'
import { leadAssignmentApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import type { LeadNotification } from '@/types'

function NotificationCard({ notification, onDone }: { notification: LeadNotification; onDone: () => void }) {
  const [timeLeft, setTimeLeft] = useState(notification.timeout_seconds)
  const [responding, setResponding] = useState(false)
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null)

  useEffect(() => {
    timerRef.current = setInterval(() => {
      setTimeLeft(t => {
        if (t <= 1) {
          clearInterval(timerRef.current!)
          onDone()
          return 0
        }
        return t - 1
      })
    }, 1000)
    return () => clearInterval(timerRef.current!)
  }, [onDone])

  const handleAccept = async () => {
    clearInterval(timerRef.current!)
    setResponding(true)
    try {
      await leadAssignmentApi.accept(notification.assignment_id)
      toast.success('Lead accepted!')
      onDone()
    } catch (e) {
      toast.error(getError(e))
      setResponding(false)
    }
  }

  const handleDecline = async () => {
    clearInterval(timerRef.current!)
    setResponding(true)
    try {
      await leadAssignmentApi.decline(notification.assignment_id)
      onDone()
    } catch (e) {
      toast.error(getError(e))
      setResponding(false)
    }
  }

  const pct = Math.round((timeLeft / notification.timeout_seconds) * 100)
  const srcLabel: Record<string, string> = {
    wa_chat: '💬 WA Chat', campaign: '📢 Campaign', organic: '🌱 Organic',
    meta_api: '📘 Meta', flow_builder: '🔄 Flow', manual: '✋ Manual',
  }

  return (
    <div className="bg-white rounded-xl shadow-2xl border border-gray-200 overflow-hidden w-80">
      {/* Top bar */}
      <div className="bg-brand-600 text-white px-4 py-2 flex items-center justify-between">
        <span className="font-semibold text-sm">🔔 New Lead!</span>
        <button onClick={onDone} className="text-white/70 hover:text-white text-lg leading-none">×</button>
      </div>

      <div className="p-4 space-y-3">
        {/* Contact info */}
        <div>
          <p className="font-semibold text-gray-900 text-base">👤 {notification.contact_name}</p>
          <p className="text-gray-500 text-sm font-mono">📱 {notification.contact_phone}</p>
          <p className="text-gray-400 text-xs mt-1">
            {srcLabel[notification.source_type] ?? notification.source_type}
            {notification.campaign_name && ` · 📢 ${notification.campaign_name}`}
          </p>
        </div>

        {/* Score + Priority */}
        <div className="flex items-center gap-3">
          <div className="bg-brand-50 text-brand-700 px-3 py-1.5 rounded-lg text-center">
            <p className="text-xs text-brand-500">Lead Score</p>
            <p className="font-bold text-lg">{notification.lead_score}</p>
          </div>
          <div className={`px-3 py-1.5 rounded-lg text-center ${notification.priority <= 2 ? 'bg-red-50 text-red-700' : 'bg-gray-50 text-gray-600'}`}>
            <p className="text-xs opacity-70">Priority</p>
            <p className="font-bold text-lg">P{notification.priority}</p>
          </div>
        </div>

        {/* Countdown */}
        <div>
          <div className="flex justify-between text-xs text-gray-500 mb-1">
            <span>Accepting in:</span>
            <span className={timeLeft <= 10 ? 'text-red-500 font-semibold' : ''}>{timeLeft}s</span>
          </div>
          <div className="w-full bg-gray-100 rounded-full h-2">
            <div
              className={`h-2 rounded-full transition-all ${pct > 50 ? 'bg-brand-500' : pct > 25 ? 'bg-yellow-400' : 'bg-red-400'}`}
              style={{ width: `${pct}%` }}
            />
          </div>
        </div>

        {/* Actions */}
        <div className="flex gap-2 pt-1">
          <button
            onClick={handleDecline}
            disabled={responding}
            className="flex-1 py-2 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50 transition-colors"
          >❌ Decline</button>
          <button
            onClick={handleAccept}
            disabled={responding}
            className="flex-1 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 transition-colors animate-pulse disabled:animate-none"
          >✅ Accept Lead</button>
        </div>
      </div>
    </div>
  )
}

export default function LeadNotificationPopup() {
  const dispatch = useAppDispatch()
  const notifications = useAppSelector(s => s.leadAssignment.activeNotifications)

  if (notifications.length === 0) return null

  return (
    <div className="fixed bottom-6 right-6 z-[9999] flex flex-col gap-3">
      {notifications.map(n => (
        <NotificationCard
          key={n.assignment_id}
          notification={n}
          onDone={() => dispatch(removeNotification(n.assignment_id))}
        />
      ))}
    </div>
  )
}
