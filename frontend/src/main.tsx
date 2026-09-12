// src/main.tsx
import { StrictMode } from 'react'
import { createRoot }  from 'react-dom/client'
import App             from './App'
import { buildErrorReport } from './utils/errorReport'
import { reportErrorToServer } from './utils/errorReporter'
import './index.css'

// Catches what the React ErrorBoundary can't: errors thrown outside render (event
// handlers, timers, third-party scripts) and unhandled promise rejections.
window.addEventListener('error', (e) => {
  reportErrorToServer(buildErrorReport({
    kind: 'unknown',
    title: 'Uncaught error',
    message: e.error?.message || e.message || 'Unknown error',
    error: e.error,
  }))
})

window.addEventListener('unhandledrejection', (e) => {
  const reason = e.reason
  reportErrorToServer(buildErrorReport({
    kind: 'unknown',
    title: 'Unhandled promise rejection',
    message: reason?.message || String(reason),
    error: reason instanceof Error ? reason : undefined,
  }))
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>
)
