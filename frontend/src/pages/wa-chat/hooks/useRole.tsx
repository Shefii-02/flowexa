import { createContext, useContext } from 'react';
import type { RoleContextType } from '../types/role';

export type { UserRole, RoleContextType } from '../types/role';

export const RoleContext = createContext<RoleContextType | undefined>(undefined);

export function useRole(): RoleContextType {
  const context = useContext(RoleContext);
  console.log("****************");
  console.log(context);
   console.log("****************");
  if (context === undefined) {
    throw new Error('useRole must be used within a RoleProvider');
  }
  return context;
}
