// Public page Meta links to after a data-deletion request.
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { instagramApi } from './api/instagram'

export default function InstagramDeletionStatusPage() {
  const code = useSearchParams()[0].get('code') ?? ''
  const [st, setSt] = useState<{ status: string; completed_at: string | null } | 'missing' | null>(null)
  useEffect(() => { instagramApi.deletionStatus(code).then(r => setSt(r.data)).catch(() => setSt('missing')) }, [code])
  return (
    <main className="mx-auto max-w-md px-4 py-16 text-center">
      <h1 className="text-xl font-semibold text-gray-900">Instagram data deletion</h1>
      <p className="mt-1 text-sm text-gray-500">Confirmation code: <code>{code || '—'}</code></p>
      <p className="mt-6 text-gray-700">
        {st === null ? 'Checking…' : st === 'missing' ? 'We couldn’t find that request.' : st.status === 'completed'
          ? 'Your Instagram data has been deleted from Teamzo.' : 'Your request was received and your data is being deleted.'}
      </p>
    </main>
  )
}
