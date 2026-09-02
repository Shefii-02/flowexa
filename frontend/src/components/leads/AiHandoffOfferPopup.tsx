// src/components/leads/AiHandoffOfferPopup.tsx
import { useAppDispatch, useAppSelector } from '@/store'
import { removeAiOffer } from '@/store/slices'
import { leadAssignmentApi } from '@/api'
import { getError } from '@/utils'
import toast from 'react-hot-toast'
import type { AiHandoffOffer } from '@/types'
import { useState } from 'react'

function OfferCard({ offer, onDone }: { offer: AiHandoffOffer; onDone: () => void }) {
  const [responding, setResponding] = useState(false)

  const handleAccept = async () => {
    setResponding(true)
    try {
      await leadAssignmentApi.accept(offer.assignment_id)
      toast.success("You're taking over the conversation!")
      onDone()
    } catch (e) {
      toast.error(getError(e))
      setResponding(false)
    }
  }

  const handleDecline = async () => {
    setResponding(true)
    try {
      await leadAssignmentApi.decline(offer.assignment_id)
      onDone()
    } catch (e) {
      toast.error(getError(e))
      setResponding(false)
    }
  }

  return (
    <div className="bg-white rounded-xl shadow-2xl border border-purple-200 overflow-hidden w-80">
      <div className="bg-purple-600 text-white px-4 py-2 flex items-center justify-between">
        <span className="font-semibold text-sm">🤖 AI Agent Offer</span>
        <button onClick={onDone} className="text-white/70 hover:text-white text-lg leading-none">×</button>
      </div>

      <div className="p-4 space-y-3">
        <div>
          <p className="text-xs text-gray-400">I'm currently chatting with:</p>
          <p className="font-semibold text-gray-900">👤 {offer.contact_name}</p>
          <p className="text-gray-500 text-sm font-mono">📱 {offer.contact_phone}</p>
        </div>

        <div className="bg-purple-50 rounded-lg p-3">
          <p className="text-xs text-purple-500 mb-1">Conversation summary</p>
          <p className="text-sm text-gray-700 italic">"{offer.conversation_summary}"</p>
        </div>

        <div className="flex items-center gap-2 text-xs text-gray-400">
          <span>🤖 AI handled {offer.ai_message_count} messages</span>
        </div>

        <p className="text-sm text-gray-600 font-medium">Want to take over this conversation?</p>

        <div className="flex gap-2">
          <button
            onClick={handleDecline}
            disabled={responding}
            className="flex-1 py-2 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50"
          >No, keep AI</button>
          <button
            onClick={handleAccept}
            disabled={responding}
            className="flex-1 py-2 rounded-lg bg-purple-600 text-white text-sm font-semibold hover:bg-purple-700"
          >Yes, I'll take it 👋</button>
        </div>
      </div>
    </div>
  )
}

export default function AiHandoffOfferPopup() {
  const dispatch = useAppDispatch()
  const offers = useAppSelector(s => s.leadAssignment.aiHandoffOffers)

  if (offers.length === 0) return null

  return (
    <div className="fixed bottom-6 left-6 z-[9999] flex flex-col gap-3">
      {offers.map(o => (
        <OfferCard
          key={o.assignment_id}
          offer={o}
          onDone={() => dispatch(removeAiOffer(o.assignment_id))}
        />
      ))}
    </div>
  )
}
