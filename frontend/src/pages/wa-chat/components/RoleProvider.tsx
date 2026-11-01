import { useState, useCallback, useEffect, type ReactNode } from 'react';
import type { UserRole, RoleContextType } from '../types/role';
import { RoleContext } from '../hooks/useRole';
import { useCurrentUser } from '@/store';
import { resolveWaChatRole } from '@/store/slices/auth.slice';

const ROLE_STORAGE = 'openwa_user_role';

export function RoleProvider({ children }: { children: ReactNode }) {
  const user = useCurrentUser();
  const [stored, setStored] = useState<UserRole | null>(() => (localStorage.getItem(ROLE_STORAGE) as UserRole) || null);

  // The signed-in user is the source of truth. This provider is mounted before the WhatsApp workspace may be connected,
  // and the role is only written to localStorage later (after login / "Connect" / "Reconnect"). Reading localStorage once
  // at mount froze `role` at null in that case, which left `canWrite` false and the whole composer disabled until a full
  // page reload. Deriving it from the user here keeps it correct the moment the user (or their company key) loads or changes.
  const derived: UserRole | null = user?.company?.wa_chat_token ? resolveWaChatRole(user) : null;

  // Also pick up a role written to localStorage by something else (another tab, or the auth slice) after we mounted.
  useEffect(() => {
    const sync = () => setStored((localStorage.getItem(ROLE_STORAGE) as UserRole) || null);
    window.addEventListener('storage', sync);
    return () => window.removeEventListener('storage', sync);
  }, []);

  const role: UserRole | null = derived ?? stored;

  const setRole = useCallback((newRole: UserRole | null) => {
    setStored(newRole);
    if (newRole) {
      localStorage.setItem(ROLE_STORAGE, newRole);
    } else {
      localStorage.removeItem(ROLE_STORAGE);
    }
  }, []);

  const value: RoleContextType = {
    role,
    setRole,
    isAdmin: role === 'admin',
    isOperator: role === 'operator',
    isViewer: role === 'viewer',
    canWrite: role === 'admin' || role === 'operator' || role === 'viewer',
  };

  return <RoleContext.Provider value={value}>{children}</RoleContext.Provider>;
}
