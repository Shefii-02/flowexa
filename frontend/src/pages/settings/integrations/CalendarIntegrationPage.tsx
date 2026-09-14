// Calendar — the same Google connection as Sheets/Drive also carries the Calendar scope, so
// appointments booked at CRM → Calendar best-effort mirror into the connected Google Calendar
// (with Meet links). Booking itself lives at CRM → Calendar; this page is just the connection.
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui'
import { useGoogleIntegration, GoogleConnectionBar } from './shared'

export default function CalendarIntegrationPage() {
  const { configured, integration, loading, reload } = useGoogleIntegration()

  return (
    <div className="space-y-5 max-w-2xl">
      <div>
        <h1 className="page-title">📅 Calendar</h1>
        <p className="page-sub">Appointments and followups sync to your Google Calendar when connected</p>
      </div>

      <GoogleConnectionBar configured={configured} integration={integration} loading={loading} onReload={reload}
        note="Connect your Google account so bookings show up on staff's Google Calendar, with Meet links and invites." />

      {integration?.is_active && (
        <div className="rounded-lg bg-green-50 border border-green-200 p-3 text-sm text-green-800">
          Calendar is connected — new bookings will mirror to Google Calendar automatically.
        </div>
      )}

      <div className="card p-5 space-y-3">
        <p className="text-sm text-gray-600">
          Booking appointments and followups, rescheduling, and marking them complete or no-show
          all happen from the CRM Calendar — it works whether or not Google is connected above.
        </p>
        <Link to="/crm/calendar"><Button>Go to Calendar →</Button></Link>
      </div>
    </div>
  )
}
