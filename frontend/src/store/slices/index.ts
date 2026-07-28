// src/store/slices/index.ts
export * from './auth.slice'
export * from './ui.slice'
export * from './staff.slice'
export * from './contacts.slice'
export * from './labels.slice'
export * from './flow.slice'
export * from './wallet.slice'
export * from './campaigns.slice'
export * from './leads.slice'

import authReducer from './auth.slice'
import uiReducer from './ui.slice'
import staffReducer from './staff.slice'
import contactReducer from './contacts.slice'
import labelReducer from './labels.slice'
import flowReducer from './flow.slice'
import walletReducer from './wallet.slice'
import campaignReducer from './campaigns.slice'
import leadReducer from './leads.slice'

export const reducers = {
  auth:      authReducer,
  ui:        uiReducer,
  staff:     staffReducer,
  contacts:  contactReducer,
  labels:    labelReducer,
  flow:      flowReducer,
  wallet:    walletReducer,
  campaigns: campaignReducer,
  leads:     leadReducer,
}