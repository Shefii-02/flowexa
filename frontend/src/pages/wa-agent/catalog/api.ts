import api from '@/api/client'

export interface AttributeField {
  key: string
  label: string
  type: 'text' | 'number' | 'enum' | 'boolean'
  options?: string[]
  matchable?: boolean
  tolerance?: number
}

export interface IndustryTemplate {
  name: string
  listing_type: string
  attribute_schema: AttributeField[]
  qualification_fields: { key: string; label: string; type: string; required: boolean }[]
  question_flow: string[]
  agent_prompt: string
}

export interface Listing {
  id: number
  type: string
  title: string
  description: string | null
  status: 'draft' | 'active' | 'inactive' | 'sold'
  price: string | null
  price_unit: string | null
  currency: string
  location: string | null
  attributes: Record<string, unknown> | null
  custom_fields?: { id?: number; key: string; value: string | null }[]
  media: { url: string; type?: string }[] | null
  source: string
  sort_order: number
}

export const catalogApi = {
  templates: () => api.get<{ templates: Record<string, IndustryTemplate>; active: string }>('/listings/templates'),
  setTemplate: (key: string) => api.post('/listings/templates', { industry_template: key }),
  list: (p?: Record<string, unknown>) => api.get('/listings', { params: p }),
  create: (d: Record<string, unknown>) => api.post('/listings', d),
  update: (id: number, d: Record<string, unknown>) => api.put(`/listings/${id}`, d),
  remove: (id: number) => api.delete(`/listings/${id}`),
  match: (requirements: Record<string, unknown>) => api.post('/listings/match', { requirements }),

  // Bulk CSV — attributes/media travel as JSON/pipe-joined columns so export → edit → import round-trips.
  export: (p?: Record<string, unknown>) => api.get('/listings/export', { params: p, responseType: 'blob' }),
  /** The columns this company's business type uses in an import file, and a ready-to-fill sample for it. */
  importColumns: () => api.get<{ business_type: string; listing_type: string; columns: { column: string; label: string; required: boolean; help: string; example: string }[] }>('/listings/import-columns'),
  importTemplate: () => api.get('/listings/import-template', { responseType: 'blob' }),
  import: (file: File, onDuplicate: 'skip' | 'update' = 'skip') => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('on_duplicate', onDuplicate)
    return api.post('/listings/import', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },

  // Google Drive as external storage for listing media
  googleStatus: () => api.get<{ configured: boolean; integration: { drive_folder_url: string | null } | null }>('/google/status'),
  driveFiles: () => api.get<{ files: DriveFile[] }>('/google/drive/files'),
  driveUpload: (file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post<{ file: DriveFile }>('/google/drive/upload', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
}

export interface DriveFile {
  id: string
  name: string | null
  mime: string | null
  size: number
  view_url: string | null
  download_url: string
  thumbnail: string | null
  created_at?: string
}
