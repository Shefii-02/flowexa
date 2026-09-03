import { useQuery } from '@tanstack/react-query';
import { contactApi, type Contact } from '../api/api';

/**
 * The session's engine contact list — the account's saved addressbook plus any contact the engine
 * knows a push name for. Used to enrich the chat list: the sidebar search matches a chat against
 * the saved contact's name and phone number (even when the chat id is an `@lid` that doesn't
 * contain the number), and a saved contact's row shows the number under the name.
 *
 * Cached 5 minutes: the list can be large and a contact rename is rare. `retry: false` so a
 * permission/engine failure falls back to id-only search instead of hammering the endpoint.
 */
export function useSessionContacts(sessionId: string | undefined) {
  return useQuery<Contact[], Error>({
    queryKey: ['sessionContacts', sessionId] as const,
    queryFn: () => contactApi.list(sessionId!),
    enabled: Boolean(sessionId),
    staleTime: 5 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
    retry: false,
  });
}
