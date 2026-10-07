import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowDownLeft, ArrowUpRight, ChevronDown, ChevronRight, Loader2 } from 'lucide-react';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useSessionsQuery, useRecentMessagesQuery, useWebhookDeliveriesQuery } from '../hooks/queries';
import { PageHeader } from '../components/PageHeader';
import type { ChatMessage, WebhookDelivery } from '../api/api';

const ORIGIN_STYLE: Record<string, string> = {
  ai: 'bg-violet-100 text-violet-700',
  manual: 'bg-blue-100 text-blue-700',
  chatbot: 'bg-amber-100 text-amber-700',
};

const ORIGIN_LABEL: Record<string, string> = {
  ai: 'AI',
  manual: 'Manual',
  chatbot: 'Chatbot',
};

function OriginBadge({ origin }: { origin?: string | null }) {
  if (!origin) return null;
  const style = ORIGIN_STYLE[origin] ?? 'bg-gray-100 text-gray-700';
  return <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${style}`}>{ORIGIN_LABEL[origin] ?? origin}</span>;
}

function MessageRow({ message }: { message: ChatMessage }) {
  const isOutgoing = message.direction === 'outgoing';
  return (
    <div className="flex items-start gap-3 px-4 py-3 border-b border-gray-100 last:border-0">
      {isOutgoing ? (
        <ArrowUpRight className="mt-0.5 text-emerald-600 shrink-0" size={16} />
      ) : (
        <ArrowDownLeft className="mt-0.5 text-sky-600 shrink-0" size={16} />
      )}
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className="text-sm font-medium text-gray-900">{message.chatName || message.chatId}</span>
          {isOutgoing && <OriginBadge origin={message.origin} />}
          <span className="text-xs text-gray-400 ml-auto">{new Date(message.createdAt).toLocaleString()}</span>
        </div>
        <p className="text-sm text-gray-600 truncate mt-0.5">{message.body || `[${message.type}]`}</p>
      </div>
    </div>
  );
}

function DeliveryRow({ delivery }: { delivery: WebhookDelivery }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="border-b border-gray-100 last:border-0">
      <button
        className="w-full flex items-start gap-3 px-4 py-3 text-left hover:bg-gray-50"
        onClick={() => setOpen(o => !o)}
      >
        {open ? (
          <ChevronDown className="mt-0.5 text-gray-400 shrink-0" size={16} />
        ) : (
          <ChevronRight className="mt-0.5 text-gray-400 shrink-0" size={16} />
        )}
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span
              className={`px-2 py-0.5 rounded-full text-xs font-medium ${
                delivery.success ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'
              }`}
            >
              {delivery.success ? 'Success' : 'Failed'}
            </span>
            <span className="text-sm font-medium text-gray-900">{delivery.event}</span>
            {delivery.responseStatus != null && (
              <span className="text-xs text-gray-500">HTTP {delivery.responseStatus}</span>
            )}
            <span className="text-xs text-gray-400">attempt {delivery.attempt}</span>
            <span className="text-xs text-gray-400 ml-auto">{new Date(delivery.createdAt).toLocaleString()}</span>
          </div>
          <p className="text-sm text-gray-600 truncate mt-0.5">{delivery.url}</p>
        </div>
      </button>
      {open && (
        <div className="px-4 pb-3 pl-11 space-y-2 text-xs text-gray-600">
          <div>
            <span className="font-medium text-gray-500">Duration: </span>
            {delivery.durationMs}ms
          </div>
          {delivery.error && (
            <div>
              <span className="font-medium text-gray-500">Error: </span>
              <span className="text-red-600">{delivery.error}</span>
            </div>
          )}
          {delivery.responseBody && (
            <div>
              <span className="font-medium text-gray-500">Response: </span>
              {delivery.responseBody}
            </div>
          )}
          {delivery.requestPayload && (
            <pre className="bg-gray-50 border border-gray-200 rounded p-2 overflow-x-auto whitespace-pre-wrap break-all">
              {delivery.requestPayload}
            </pre>
          )}
        </div>
      )}
    </div>
  );
}

export function WebhookLogs() {
  const { t } = useTranslation();
  useDocumentTitle('Webhook Logs');
  const { data: sessions = [], isLoading: loadingSessions } = useSessionsQuery();
  const [selectedSessionId, setSelectedSessionId] = useState('');
  const [tab, setTab] = useState<'messages' | 'deliveries'>('messages');

  useEffect(() => {
    if (!selectedSessionId && sessions.length > 0) {
      setSelectedSessionId(sessions[0].id);
    }
  }, [selectedSessionId, sessions]);

  const { data: messagesData, isLoading: loadingMessages } = useRecentMessagesQuery(
    selectedSessionId,
    tab === 'messages',
  );
  const { data: deliveries = [], isLoading: loadingDeliveries } = useWebhookDeliveriesQuery(
    selectedSessionId,
    tab === 'deliveries',
  );
  const messages = messagesData?.messages ?? [];

  if (loadingSessions) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 className="animate-spin" size={32} />
      </div>
    );
  }

  return (
    <div className="p-6">
      <PageHeader
        title="Webhook Logs"
        subtitle="Incoming/outgoing messages and webhook delivery attempts for a session"
        actions={
          <select
            className="border border-gray-300 rounded-md px-3 py-1.5 text-sm"
            aria-label={t('templates.sessionSelect', { defaultValue: 'Select session' })}
            value={selectedSessionId}
            onChange={event => setSelectedSessionId(event.target.value)}
          >
            {sessions.length === 0 && <option value="">No sessions</option>}
            {sessions.map(session => (
              <option key={session.id} value={session.id}>
                {session.name}
              </option>
            ))}
          </select>
        }
      />

      <div className="mt-4 flex gap-1 border-b border-gray-200">
        <button
          className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px ${
            tab === 'messages' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500'
          }`}
          onClick={() => setTab('messages')}
        >
          Messages
        </button>
        <button
          className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px ${
            tab === 'deliveries' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500'
          }`}
          onClick={() => setTab('deliveries')}
        >
          Webhook Deliveries
        </button>
      </div>

      <div className="mt-4 bg-white border border-gray-200 rounded-lg">
        {!selectedSessionId ? (
          <p className="px-4 py-8 text-center text-sm text-gray-500">Select a session to view its logs.</p>
        ) : tab === 'messages' ? (
          loadingMessages ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="animate-spin" size={24} />
            </div>
          ) : messages.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-gray-500">No messages yet.</p>
          ) : (
            messages.map(message => <MessageRow key={message.id} message={message} />)
          )
        ) : loadingDeliveries ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="animate-spin" size={24} />
          </div>
        ) : deliveries.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-gray-500">No webhook deliveries logged yet.</p>
        ) : (
          deliveries.map(delivery => <DeliveryRow key={delivery.id} delivery={delivery} />)
        )}
      </div>
    </div>
  );
}

export default WebhookLogs;
