# WA SaaS Frontend — Setup Guide

## Stack
- React 18 + TypeScript + Vite
- Redux Toolkit + React Router v6
- Tailwind CSS + custom component layer
- Recharts (analytics), React Hot Toast, Axios

---

## Quick start

```bash
# Install
cd waapi-react
npm install

# Create env file
echo 'VITE_API_URL=/api/v1' > .env

# Start dev server (proxies /api to Laravel on :8000)
npm run dev
```

Visit: http://localhost:3000

---

## File structure

```
src/
├── api/
│   ├── client.ts          ← Axios + JWT auto-refresh interceptor
│   └── index.ts           ← All API services (authApi, campaignApi, etc.)
├── components/
│   ├── layout/
│   │   ├── Sidebar.tsx    ← Nav with permissions, wallet badge
│   │   └── DashboardLayout.tsx
│   ├── shared/
│   │   └── ProtectedRoute.tsx
│   └── ui/
│       └── index.tsx      ← Badge, Button, Input, Modal, Table, Pagination...
├── hooks/index.ts         ← useToast, useDebounce, usePagination, useAsync
├── pages/
│   ├── auth/              ← Login, Register
│   ├── dashboard/         ← Overview with stats + lead funnel
│   ├── staff/             ← CRUD + toggle + reset password
│   ├── contacts/          ← List + CSV import/export + opt-out
│   ├── flow/              ← Tree builder (create/edit/toggle/delete nodes)
│   ├── campaigns/         ← Create + launch + pause + resume + stats
│   ├── leads/             ← Table + Kanban toggle, inline stage update
│   ├── wallet/            ← Balance + Razorpay checkout + transactions
│   ├── analytics/         ← Recharts: line, bar, pie
│   ├── settings/          ← Company + WA credentials + token regenerate
│   ├── otp/               ← API credentials + curl examples
│   ├── message-logs/      ← Filterable message log
│   └── superadmin/        ← Companies, Plans, Stats
├── store/
│   ├── index.ts           ← Store + typed hooks + usePermission
│   └── slices/index.ts    ← All 9 Redux slices
├── types/index.ts         ← All TypeScript interfaces
├── utils/index.ts         ← fmt, stageConfig, campaignStatusConfig, getError
├── App.tsx                ← All routes with ProtectedRoute
└── main.tsx               ← Entry point
```

---

## Permissions

The `usePermission(perm)` hook checks the current user's role permissions:

```tsx
const canCreate = usePermission('staff.create')
const isSuperAdmin = useIsSuperAdmin()
```

Permission strings match the backend role permission array.

---

## Auth flow

1. User logs in → JWT stored in `localStorage` as `wa_token`
2. Axios request interceptor attaches it as `Authorization: Bearer ...`
3. On 401 `token_expired` → auto-refresh → retry queued requests
4. On 401 other → logout → redirect to `/login`
5. `ProtectedRoute` hydrates user from stored token on page load

---

## Razorpay integration

In `WalletPage.tsx`:
1. Call `POST /wallet/create-order` → get order_id + razorpay_key
2. Load Razorpay checkout.js dynamically
3. Open checkout modal
4. On success → call `POST /wallet/verify-payment` → wallet credited

---

## Environment variables

```env
VITE_API_URL=/api/v1        # Laravel backend (proxied via Vite in dev)
```

For production build, set `VITE_API_URL=https://api.yourdomain.com/api/v1`

---

## Build for production

```bash
npm run build
# Output: dist/
```

Serve `dist/` from your web server. Configure it to serve `index.html` for all routes (SPA routing).

---

## Pages and routes

| Route | Page | Auth |
|-------|------|------|
| /login | LoginPage | Public |
| /register | RegisterPage | Public |
| /dashboard | DashboardPage | JWT |
| /staff | StaffPage | JWT + staff.view |
| /contacts | ContactsPage | JWT + contacts.view |
| /flow | FlowPage | JWT + flow.view |
| /campaigns | CampaignsPage | JWT + campaigns.view |
| /leads | LeadsPage | JWT |
| /wallet | WalletPage | JWT + billing.view |
| /analytics | AnalyticsPage | JWT + analytics |
| /settings | SettingsPage | JWT + settings.manage |
| /otp | OtpPage | JWT |
| /message-logs | MessageLogsPage | JWT |
| /superadmin/companies | SuperAdminCompanies | Superadmin only |
| /superadmin/plans | SuperAdminPlans | Superadmin only |
| /superadmin/stats | SuperAdminStats | Superadmin only |
