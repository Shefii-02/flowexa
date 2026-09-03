import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import WaOtpServicePage from '@/pages/wa-chat/pages/WaOtpService'

const qc = new QueryClient({ defaultOptions: { queries: { retry: 1, staleTime: 30_000 } } })

export default function WaCloudOtpPage() {
  return (
    <QueryClientProvider client={qc}>
      <WaOtpServicePage />
    </QueryClientProvider>
  )
}
