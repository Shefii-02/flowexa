import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import WaCloudApiService from '@/pages/wa-cloud/WaCloudApiService'

const qc = new QueryClient({ defaultOptions: { queries: { retry: 1, staleTime: 30_000 } } })

export default function WaCloudOtpPage() {
  return (
    <QueryClientProvider client={qc}>
      <WaCloudApiService />
    </QueryClientProvider>
  )
}
