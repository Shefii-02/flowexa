// Controlled "code delivery setup" block for Meta AUTHENTICATION templates.
//
// The markup is copied from src/pages/template/TemplatesPage.tsx (~L742-842) —
// the wa-chat/Meta template builder — and made into a reusable controlled
// component so the WA Cloud Api Service (Auth OTP config) can offer the same
// three delivery types (zero-tap / one-tap / copy code) and "up to 5 apps"
// supported-apps editor. TemplatesPage keeps its own inline copy; if you change
// the shape here, mirror it there.

export type AuthApp = { package_name: string; signature_hash: string }

export interface AuthDeliveryValue {
  deliveryMethod: 'copy_code' | 'one_tap' | 'zero_tap'
  apps: AuthApp[]
  addExpiry: boolean
  codeExpirationMinutes: number
  addSecurityRecommendation: boolean
  zeroTapTermsAccepted: boolean
}

interface Props extends AuthDeliveryValue {
  onChange: (patch: Partial<AuthDeliveryValue>) => void
  disabled?: boolean
}

const DELIVERY_OPTIONS = [
  { value: 'zero_tap', title: 'Zero-tap auto-fill', desc: "Recommended — code sends automatically, no tap needed. Falls back to auto-fill or copy code if zero-tap isn't possible." },
  { value: 'one_tap', title: 'One-tap auto-fill', desc: "Code sends to your app when the customer taps the button. Falls back to copy code if auto-fill isn't possible." },
  { value: 'copy_code', title: 'Copy code', desc: 'Basic authentication — customers copy and paste the code into your app.' },
] as const

export function AuthDeliverySetup({
  deliveryMethod, apps, addExpiry, codeExpirationMinutes,
  addSecurityRecommendation, zeroTapTermsAccepted, onChange, disabled,
}: Props) {
  const needsApps = deliveryMethod === 'zero_tap' || deliveryMethod === 'one_tap'

  const addApp = () => onChange({ apps: [...apps, { package_name: '', signature_hash: '' }] })
  const removeApp = (i: number) => onChange({ apps: apps.filter((_, idx) => idx !== i) })
  const updateApp = (i: number, k: keyof AuthApp, v: string) =>
    onChange({ apps: apps.map((a, idx) => (idx === i ? { ...a, [k]: v } : a)) })

  return (
    <div className="space-y-4">
      <div className="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-xs text-blue-700">
        Meta writes the OTP message copy itself for authentication templates — there's no body/header/footer to edit.
        You only choose how the code is delivered.
      </div>

      <div>
        <label className="label mb-2">Code delivery setup</label>
        <div className="space-y-2">
          {DELIVERY_OPTIONS.map(opt => (
            <label key={opt.value}
              className={`flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition-all ${
                deliveryMethod === opt.value ? 'border-brand-500 bg-brand-50' : 'border-gray-200 hover:border-gray-300'
              }`}>
              <input type="radio" name="auth_delivery_method" className="mt-1" disabled={disabled}
                checked={deliveryMethod === opt.value}
                onChange={() => onChange({ deliveryMethod: opt.value })} />
              <div>
                <p className="text-sm font-semibold">{opt.title}</p>
                <p className="text-xs text-gray-500 mt-0.5">{opt.desc}</p>
              </div>
            </label>
          ))}
        </div>
      </div>

      {deliveryMethod === 'zero_tap' && (
        <label className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-xl p-3 cursor-pointer">
          <input type="checkbox" className="mt-0.5" disabled={disabled}
            checked={zeroTapTermsAccepted}
            onChange={e => onChange({ zeroTapTermsAccepted: e.target.checked })} />
          <span className="text-xs text-amber-700">
            I understand zero-tap authentication is subject to the WhatsApp Business Terms of Service, and it's my
            responsibility to ensure customers expect the code to be auto-filled on their behalf.{' '}
            <strong>This must be ticked to submit this template.</strong>
          </span>
        </label>
      )}

      {needsApps && (
        <div className="border border-gray-200 rounded-xl p-3 space-y-2">
          <div className="flex items-center justify-between">
            <label className="label mb-0">App setup <span className="text-xs font-normal text-gray-400 ml-1">(up to 5 apps)</span></label>
            {apps.length < 5 && !disabled && (
              <button type="button" onClick={addApp} className="text-xs text-brand-600 hover:underline">+ Add app</button>
            )}
          </div>
          {apps.length === 0 && (
            <p className="text-xs text-gray-400">Add at least one app — required for auto-fill delivery.</p>
          )}
          {apps.map((app, i) => (
            <div key={i} className="flex gap-2 items-start border border-gray-100 rounded-lg p-2">
              <div className="flex-1 space-y-1.5">
                <input className="form-control border w-full p-2 rounded text-sm" disabled={disabled}
                  placeholder="Package name — e.g. com.univexa.app" maxLength={224}
                  value={app.package_name}
                  onChange={e => updateApp(i, 'package_name', e.target.value)} />
                <input className="form-control border w-full p-2 rounded text-sm font-mono" disabled={disabled}
                  placeholder="App signature hash" maxLength={50}
                  value={app.signature_hash}
                  onChange={e => updateApp(i, 'signature_hash', e.target.value)} />
              </div>
              {!disabled && (
                <button type="button" onClick={() => removeApp(i)}
                  className="text-red-400 hover:text-red-600 text-lg flex-shrink-0 mt-1">×</button>
              )}
            </div>
          ))}
        </div>
      )}

      <label className="flex items-center gap-3 bg-gray-50 rounded-xl px-4 py-3 cursor-pointer">
        <div className={`w-10 h-6 rounded-full transition-colors flex items-center px-0.5 flex-shrink-0 ${addExpiry ? 'bg-green-500' : 'bg-gray-300'}`}
          onClick={() => !disabled && onChange({ addExpiry: !addExpiry })}>
          <div className={`w-5 h-5 bg-white rounded-full shadow transition-transform ${addExpiry ? 'translate-x-4' : 'translate-x-0'}`} />
        </div>
        <div className="flex-1">
          <p className="text-sm font-medium text-gray-700">Add expiry time for the code</p>
          <input type="number" min={1} max={90} disabled={disabled || !addExpiry}
            className="form-control border rounded text-sm p-1 w-24 mt-1"
            value={codeExpirationMinutes}
            onChange={e => onChange({ codeExpirationMinutes: parseInt(e.target.value) || 10 })} />
          <span className="text-xs text-gray-400 ml-2">minutes (Meta cap: 90)</span>
        </div>
      </label>

      <label className="flex items-center gap-3 bg-gray-50 rounded-xl px-4 py-3 cursor-pointer">
        <div className={`w-10 h-6 rounded-full transition-colors flex items-center px-0.5 flex-shrink-0 ${addSecurityRecommendation ? 'bg-green-500' : 'bg-gray-300'}`}
          onClick={() => !disabled && onChange({ addSecurityRecommendation: !addSecurityRecommendation })}>
          <div className={`w-5 h-5 bg-white rounded-full shadow transition-transform ${addSecurityRecommendation ? 'translate-x-4' : 'translate-x-0'}`} />
        </div>
        <div>
          <p className="text-sm font-medium text-gray-700">Add security recommendation</p>
          <p className="text-xs text-gray-400">Adds "For your security, do not share this code" to the message</p>
        </div>
      </label>
    </div>
  )
}
