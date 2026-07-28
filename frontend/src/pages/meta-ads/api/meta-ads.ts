// src/api/meta-ads.ts

import api from "@/api/client";

// ── Key billing clarification (stored as a comment for devs) ──────────────
// Meta Ads spend goes directly from the COMPANY'S OWN Meta ad account.
// It is NOT deducted from the WA SaaS wallet.
// WA wallet = WhatsApp message credits (campaigns, OTP, flows)
// Meta Ads = paid via company's own Meta payment method
// These are 100% SEPARATE billing systems.
// Each company connects their OWN ad account — fully multi-tenant.

export const metaAdsApi = {
  // Ad accounts
  accounts: () => api.get('/meta-ads/accounts'),
  connectAccount: (d: Record<string, unknown>) => api.post('/meta-ads/accounts', d),
  updateAccount: (id: number, d: Record<string, unknown>) => api.put(`/meta-ads/accounts/${id}`, d),
  removeAccount: (id: number) => api.delete(`/meta-ads/accounts/${id}`),
  setDefault: (id: number) => api.post(`/meta-ads/accounts/${id}/set-default`),
  verifyAccount: (id: number) => api.get(`/meta-ads/accounts/${id}/verify`),

  // Audience templates
  audienceTemplates: () => api.get('/meta-ads/audience-templates'),

  // Campaigns
  campaigns: (p?: Record<string, unknown>) => api.get('/meta-ads/campaigns', { params: p }),
  campaign: (id: number) => api.get(`/meta-ads/campaigns/${id}`),
  createCampaign: (d: Record<string, unknown>) => api.post('/meta-ads/campaigns', d),
  updateCampaign: (id: number, d: Record<string, unknown>) => api.put(`/meta-ads/campaigns/${id}`, d),
  deleteCampaign: (id: number) => api.delete(`/meta-ads/campaigns/${id}`),
  setCampaignStatus: (id: number, status: string) => api.patch(`/meta-ads/campaigns/${id}/status`, { status }),

  // Ad sets
  adSets: (campaignId: number) => api.get(`/meta-ads/campaigns/${campaignId}/adsets`),
  createAdSet: (campaignId: number, d: Record<string, unknown>) => api.post(`/meta-ads/campaigns/${campaignId}/adsets`, d),
  updateAdSet: (id: number, d: Record<string, unknown>) => api.put(`/meta-ads/adsets/${id}`, d),
  setAdSetStatus: (id: number, status: string) => api.patch(`/meta-ads/adsets/${id}/status`, { status }),
  deleteAdSet: (id: number) => api.delete(`/meta-ads/adsets/${id}`),

  // Media
  media: (p?: Record<string, unknown>) => api.get('/meta-ads/media', { params: p }),
  uploadImage: (accountId: number, file: File) => {
    const fd = new FormData(); fd.append('file', file); fd.append('account_id', String(accountId))
    return api.post('/meta-ads/media/upload-image', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  uploadVideo: (accountId: number, file: File, title: string) => {
    const fd = new FormData(); fd.append('file', file); fd.append('account_id', String(accountId)); fd.append('title', title)
    return api.post('/meta-ads/media/upload-video', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  deleteMedia: (id: number) => api.delete(`/meta-ads/media/${id}`),

  // Creatives
  creatives: (p?: Record<string, unknown>) => api.get('/meta-ads/creatives', { params: p }),
  createCreative: (d: Record<string, unknown>) => api.post('/meta-ads/creatives', d),
  deleteCreative: (id: number) => api.delete(`/meta-ads/creatives/${id}`),

  // Ads
  ads: (adSetId: number) => api.get(`/meta-ads/adsets/${adSetId}/ads`),
  createAd: (adSetId: number, d: Record<string, unknown>) => api.post(`/meta-ads/adsets/${adSetId}/ads`, d),
  setAdStatus: (id: number, status: string) => api.patch(`/meta-ads/ads/${id}/status`, { status }),
  syncReview: (id: number) => api.post(`/meta-ads/ads/${id}/sync-review`),
  deleteAd: (id: number) => api.delete(`/meta-ads/ads/${id}`),

  // Insights
  insightsOverview: (p?: Record<string, unknown>) => api.get('/meta-ads/insights/overview', { params: p }),
  campaignInsights: (id: number, p?: Record<string, unknown>) => api.get(`/meta-ads/insights/campaign/${id}`, { params: p }),
  syncInsights: (campaignId: number, p?: Record<string, unknown>) => api.post(`/meta-ads/insights/sync/${campaignId}`, p),
}
