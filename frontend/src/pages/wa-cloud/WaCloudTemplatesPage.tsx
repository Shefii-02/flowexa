import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import WaChatTemplatesPage from '@/pages/wa-chat/pages/WaChatTemplates'

const qc = new QueryClient({ defaultOptions: { queries: { retry: 1, staleTime: 30_000 } } })

export default function WaCloudTemplatesPage() {
  return (
    <QueryClientProvider client={qc}>
      <WaChatTemplatesPage />
    </QueryClientProvider>
  )
}
