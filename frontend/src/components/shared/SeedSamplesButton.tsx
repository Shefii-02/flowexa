import { useState } from 'react'
import type { AxiosResponse } from 'axios'
import toast from 'react-hot-toast'
import { Button } from '@/components/ui'
import { getError } from '@/utils'

/**
 * "Add sample data" button for lists that start empty on a new company.
 * The backend is idempotent — when the samples already exist nothing is created and
 * we show an alert instead of silently adding duplicates.
 */
export default function SeedSamplesButton({ seed, onDone, label = '✨ Add sample data' }: {
  seed: () => Promise<AxiosResponse<{ created: number; already_exists: boolean; message: string }>>
  onDone: () => void
  label?: string
}) {
  const [busy, setBusy] = useState(false)

  const run = async () => {
    setBusy(true)
    try {
      const { data } = await seed()
      if (data.already_exists) {
        window.alert(data.message)
      } else {
        toast.success(data.message)
        onDone()
      }
    } catch (e) {
      toast.error(getError(e))
    } finally {
      setBusy(false)
    }
  }

  return <Button variant="secondary" onClick={run} loading={busy}>{label}</Button>
}
