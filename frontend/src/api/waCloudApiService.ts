// API client for the WA Cloud OTP Service (Meta Cloud API flavour).
// Backed by /api/v1/wa-cloud/otp-service/* — separate from the wa-chat
// "/otp-service/*" endpoints, which stay untouched.
import { api } from '@/api/client'

export type WaCloudConfigKind = 'auth' | 'utility' | 'invoice'

export const waCloudOtp = {
  getService:   () => api.get('/wa-cloud/otp-service').then(r => r.data),
  saveService:  (body: Record<string, unknown>) => api.post('/wa-cloud/otp-service', body),
  resetToken:   () => api.post('/wa-cloud/otp-service/reset-token'),
  stopToken:    () => api.post('/wa-cloud/otp-service/stop-token'),
  logs:         (params: Record<string, unknown>) =>
    api.get('/wa-cloud/otp-service/logs', { params }).then(r => r.data),
  testSend:     (body: Record<string, unknown>) => api.post('/wa-cloud/otp-service/test-send', body),

  configs:      (kind: WaCloudConfigKind) =>
    api.get('/wa-cloud/otp-service/configs', { params: { kind } }).then(r => r.data?.data ?? []),
  createConfig: (body: Record<string, unknown>) => api.post('/wa-cloud/otp-service/configs', body),
  updateConfig: (id: number, body: Record<string, unknown>) =>
    api.patch(`/wa-cloud/otp-service/configs/${id}`, body),
  deleteConfig: (id: number) => api.delete(`/wa-cloud/otp-service/configs/${id}`),
  submitConfig: (id: number) => api.post(`/wa-cloud/otp-service/configs/${id}/submit`),
  syncConfig:   (id: number) => api.post(`/wa-cloud/otp-service/configs/${id}/sync`),
  configStats:  (id: number) =>
    api.get(`/wa-cloud/otp-service/configs/${id}/stats`).then(r => r.data?.data),

  prebuilt:     (type: 'auth' | 'utility') =>
    api.get('/wa-cloud/otp-service/prebuilt-templates', { params: { type } }).then(r => r.data?.data ?? []),

  uploadHeaderMedia: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post(`/wa-cloud/otp-service/configs/${id}/upload-header-media`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
  deleteHeaderMedia: (id: number) =>
    api.delete(`/wa-cloud/otp-service/configs/${id}/delete-header-media`),
}
