// What WA Chat campaign / API / test messages cost from the wallet — shown where they are sent.
// Normal chat messages are free and never use this. WA Cloud has its own billing and is not covered.
import { useAppSelector } from '@/store'

export function waChatCost(c: { wa_chat_billing?: string; wa_chat_credits_per_message?: number; wallet?: { balance?: number } | null } | null | undefined, messages = 1) {
  const billed = (c?.wa_chat_billing ?? 'wallet') === 'wallet'
  const rate = c?.wa_chat_credits_per_message ?? 1
  if (!billed || rate <= 0) return null
  const balance = c?.wallet?.balance ?? 0
  const need = messages * rate
  return { rate, need, balance, short: balance < need }
}

/** `messages` = how many this will send (a campaign's recipients); leave out for a per-message note (API / test). */
export default function WaChatCostNote({ messages }: { messages?: number }) {
  const company = useAppSelector(s => s.auth.user?.company)
  const cost = waChatCost(company as never, messages ?? 1)
  if (!cost) return null
  const credits = (n: number) => `${n} credit${n === 1 ? '' : 's'}`
  return (
    <div data-testid="wa-chat-cost" className={`rounded-lg border px-3 py-2 text-xs ${cost.short ? 'bg-red-50 border-red-200 text-red-700' : 'bg-blue-50 border-blue-200 text-blue-700'}`}>
      {messages === undefined
        ? <>💳 Each delivered message costs <b>{credits(cost.rate)}</b> from your wallet (balance: <b>{cost.balance}</b>). Normal chat messages are free.</>
        : <>💳 {messages} message{messages === 1 ? '' : 's'} × {credits(cost.rate)} = <b>{credits(cost.need)}</b> from your wallet when delivered (balance: <b>{cost.balance}</b>).</>}
      {cost.short && <> Not enough credits — <a href="/wallet" className="underline font-medium">recharge the wallet</a>.</>}
    </div>
  )
}
