// src/pages/otp/OtpPage.tsx
import { useAppSelector } from '@/store'

export default function OtpPage() {
  const company = useAppSelector((s) => s.auth.user?.company)

  return (
    <div className="space-y-5 max-w-2xl">
      <div><h1 className="page-title">OTP Service</h1><p className="page-sub">WhatsApp OTP API for your apps</p></div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">Your API credentials</h3></div>
        <div className="card-body space-y-4">
          <div>
            <p className="label">App ID (X-App-Id header)</p>
            <code className="text-sm bg-gray-100 px-3 py-2 rounded font-mono text-gray-800 block">{company?.app_id || '—'}</code>
          </div>
          <div>
            <p className="label">Private Token (X-Private-Token header)</p>
            <p className="text-xs text-gray-400">Regenerate from Settings → OTP API credentials</p>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-header"><h3 className="card-title">API usage</h3></div>
        <div className="card-body space-y-4">
          <div>
            <p className="label mb-2">Send OTP</p>
            <pre className="bg-gray-900 text-green-400 text-xs p-4 rounded-lg overflow-x-auto">{`curl -X POST \\
  ${window.location.origin}/api/v1/otp/send \\
  -H 'X-App-Id: ${company?.app_id || 'WA_APP_XXXX'}' \\
  -H 'X-Private-Token: your_private_token' \\
  -H 'Content-Type: application/json' \\
  -d '{
    "phone": "918086544828",
    "device_id": "unique-device-fingerprint"
  }'`}</pre>
          </div>

          <div>
            <p className="label mb-2">Verify OTP</p>
            <pre className="bg-gray-900 text-green-400 text-xs p-4 rounded-lg overflow-x-auto">{`curl -X POST \\
  ${window.location.origin}/api/v1/otp/verify \\
  -H 'X-App-Id: ${company?.app_id || 'WA_APP_XXXX'}' \\
  -H 'X-Private-Token: your_private_token' \\
  -H 'Content-Type: application/json' \\
  -d '{
    "ref_id": "uuid-returned-by-send",
    "otp": "847291",
    "device_id": "same-device-fingerprint"
  }'`}</pre>
          </div>

          <div className="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700 space-y-1">
            <p>💡 <strong>Cost:</strong> 1 message per OTP send. Verification is free.</p>
            <p>⏱️ <strong>Expiry:</strong> OTP expires after 15 minutes.</p>
            <p>📱 <strong>Device ID:</strong> Must match between send and verify requests.</p>
            <p>🔢 <strong>Format:</strong> 6-digit numeric OTP sent via WhatsApp template.</p>
          </div>
        </div>
      </div>
    </div>
  )
}
