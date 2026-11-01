import { useQuery } from '@tanstack/react-query';
import { api } from '@/api/client';

export type OtpGwSession = {
  id: string;
  display_name: string | null;
  phone: string | null;
  status: string | null;
  connected: boolean;
};

/**
 * WhatsApp Chat sessions an OTP / API service can send through, as the SERVER sees them (delivery goes through Laravel,
 * so a session is only useful here if Laravel can reach the gateway). `gatewayError` says why the list is empty when
 * it is not simply that the company has no sessions.
 */
export function useOtpSessions() {
  const q = useQuery<{ sessions: OtpGwSession[]; gatewayError: string | null }>({
    queryKey: ['otp-wa-sessions'],
    queryFn: () =>
      api.get('/otp-service/sessions').then(r => ({ sessions: r.data?.data ?? [], gatewayError: r.data?.gateway_error ?? null })),
  });
  return { sessions: q.data?.sessions ?? [], gatewayError: q.data?.gatewayError ?? null, isLoading: q.isLoading, refetch: q.refetch };
}
