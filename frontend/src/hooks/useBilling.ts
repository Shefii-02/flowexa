// src/hooks/useBilling.ts
import { useAppSelector } from '@/store/hooks'

/**
 * How the signed-in company pays for WhatsApp messages (set by the superadmin on the company's
 * detail page): 'wallet' → prepaid credits, 'own_account' → the company's own Meta account is billed
 * directly and the wallet is unused. Anything that is wallet-only (balance, recharge, top-up,
 * per-message cost) should be gated on `usesWallet`.
 */
export const useBilling = () => {
  const user = useAppSelector((state) => state.auth.user)
  const waConfig = user?.company?.wa_config ?? 'wallet'
  return { waConfig, usesWallet: waConfig === 'wallet', ownAccount: waConfig !== 'wallet' }
}
