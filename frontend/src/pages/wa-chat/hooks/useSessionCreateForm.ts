import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { sessionApi, type Session } from '../api/api';
import { useToast } from './useToast';
import api from '@/api/client';
import { getError } from '@/utils';

export interface UseSessionCreateFormArgs {
  onCreated: (session: Session) => void;
  onFailed: (message: string) => void;
}

export interface SessionCreateForm {
  showCreateModal: boolean;
  setShowCreateModal: (open: boolean) => void;
  newSessionName: string;
  setNewSessionName: (name: string) => void;
  newDisplayName: string;
  setNewDisplayName: (v: string) => void;
  newPhone: string;
  setNewPhone: (v: string) => void;
  creating: boolean;
  handleCreate: () => Promise<void>;
}

/**
 * Owns the "New Session" modal: its open/closed state, the typed name, and the in-flight `creating`
 * flag. This is a separate feature from onboarding a session onto WhatsApp — `handleCreate` never
 * touches `qrData`/pairing state and never opens the QR modal (that's `handleStart`/`handleShowQR`).
 * Its only outward edges are the created `Session` and a failure message: the page owns appending to
 * `sessions` and invalidating the shared query cache, so this hook stays independent of that state.
 */
export function useSessionCreateForm({ onCreated, onFailed }: UseSessionCreateFormArgs): SessionCreateForm {
  const { t } = useTranslation();
  const toast = useToast();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [newSessionName, setNewSessionName]   = useState('');
  const [newDisplayName, setNewDisplayName]   = useState('');
  const [newPhone, setNewPhone]               = useState('');
  const [creating, setCreating]               = useState(false);

  const handleCreate = async () => {
    if (!newSessionName.trim()) return;
    try {
      setCreating(true);

      // Session creation goes through Laravel, not the gateway directly: this company's
      // own gateway key is session-scoped (allowedSessions), and the gateway's POST /sessions
      // rejects any scoped key outright since a not-yet-created session has no id to scope
      // against. Laravel's controller holds the gateway's unscoped ADMIN key for exactly this,
      // and re-syncs this company's key allowlist to include the new session afterward.
      const { data: created } = await api.post('/waha/sessions', {
        display_name: newDisplayName.trim() || newSessionName.trim(),
      });
      const waSession = created.data;

      if (newPhone.trim()) {
        try {
          await api.patch(`/waha/sessions/${waSession.id}`, { phone: newPhone.trim() });
        } catch {
          // Non-critical — don't block the main success flow
        }
      }

      // Fetch the canonical gateway-shaped session now that this company's scoped key
      // has been re-synced (above) to include it.
      const newSession = await sessionApi.get(waSession.session_name);

      setNewSessionName('');
      setNewDisplayName('');
      setNewPhone('');
      setShowCreateModal(false);
      toast.success(t('sessions.create.successTitle'), t('sessions.create.successDesc', { name: newSession.name }));
      onCreated(newSession);
    } catch (err) {
      const msg = getError(err) || t('sessions.create.errorDefault');
      toast.error(t('sessions.create.errorTitle'), msg);
      onFailed(msg);
    } finally {
      setCreating(false);
    }
  };

  return {
    showCreateModal, setShowCreateModal,
    newSessionName, setNewSessionName,
    newDisplayName, setNewDisplayName,
    newPhone, setNewPhone,
    creating, handleCreate,
  };
}
