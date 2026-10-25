// Public privacy policy — no login needed, served at /privacy-policy (used as the Google Play / App Store policy URL).
import { Link } from 'react-router-dom'

// Edit these two values to match the business before publishing.
const CONTACT_EMAIL = 'support@teamzo.io'
const LAST_UPDATED = '24 October 2026'

const Section = ({ title, children }: { title: string; children: React.ReactNode }) => (
  <section className="space-y-2">
    <h2 className="text-lg font-semibold text-gray-900">{title}</h2>
    <div className="text-sm text-gray-700 leading-relaxed space-y-2">{children}</div>
  </section>
)

const List = ({ items }: { items: string[] }) => (
  <ul className="list-disc pl-5 space-y-1">{items.map((i) => <li key={i}>{i}</li>)}</ul>
)

export default function PrivacyPolicyPage() {
  return (
    <div className="min-h-screen bg-gray-50">
      <div className="max-w-3xl mx-auto px-5 py-10 space-y-8">
        <header className="space-y-2">
          <Link to="/login" className="inline-flex items-center gap-2 text-sm text-brand-600 hover:underline">
            <img src="/teamzo-icon.png" alt="" className="w-6 h-6 rounded" /> Teamzo
          </Link>
          <h1 className="text-3xl font-bold text-gray-900">Privacy Policy</h1>
          <p className="text-sm text-gray-500">Last updated: {LAST_UPDATED}</p>
        </header>

        <Section title="1. Who we are">
          <p>
            Teamzo (“we”, “us”) is a business platform, operated by Univexa Technologies, that helps companies manage
            customer conversations, leads, campaigns and staff attendance through our website and mobile app (together,
            the “Service”). A company that signs up (“Company”) controls its workspace and the data of its staff and
            customers; we process that data on the Company’s behalf to provide the Service.
          </p>
        </Section>

        <Section title="2. Information we collect">
          <p><b>Account information.</b> Name, email address, phone number, role and department, and your sign-in credentials (passwords are stored hashed).</p>
          <p><b>Attendance information (mobile and web).</b> When your Company turns these features on: clock-in/out and break times, a selfie taken at the moment of the punch, and your device’s location at that moment, used to check you are within your office’s allowed radius.</p>
          <p><b>Business content.</b> Contacts, leads, labels, notes, tasks, message content and media handled through connected WhatsApp, Instagram and similar channels, templates, campaigns and reports your Company creates.</p>
          <p><b>Device and usage information.</b> Device name and type, app version, operating system, browser, IP address, approximate sign-in times, and a push-notification token so we can send you alerts.</p>
          <p><b>Camera, microphone, photos and files.</b> Only when you choose to use a feature that needs them, such as taking an attendance selfie, attaching a photo or recording a voice note.</p>
        </Section>

        <Section title="3. How we use information">
          <List items={[
            'To provide, secure and maintain the Service, and to sign you in on permitted devices and browsers.',
            'To record and verify attendance, including location and selfie checks your Company has enabled.',
            'To deliver messages, notifications and push alerts you or your Company have set up.',
            'To detect abuse and fraud, enforce device and session limits, and troubleshoot problems.',
            'To comply with legal obligations. We do not sell personal information and we do not use it for third-party advertising.',
          ]} />
        </Section>

        <Section title="4. Location">
          <p>
            Location is requested only when you perform an attendance action and your Company has enabled location
            checks. We do not track your location in the background. You can withhold permission in your device
            settings, but attendance punches that require location will then not be accepted.
          </p>
        </Section>

        <Section title="5. Sharing">
          <p>We share information only as needed to run the Service:</p>
          <List items={[
            'With your Company and its authorised administrators, who can see your attendance records, selfies and activity.',
            'With service providers that process data for us, such as cloud hosting, Google Firebase (push notifications) and messaging platform providers (for example WhatsApp / Meta) connected by your Company.',
            'When required by law, or to protect the rights, safety and security of users and the Service.',
          ]} />
        </Section>

        <Section title="6. Storage, security and retention">
          <p>
            Data is transmitted over encrypted connections (HTTPS) and stored on secured servers with access controls.
            We keep data while your Company’s account is active and as needed to meet legal obligations. After an account is
            closed or a deletion request is processed, data is deleted or anonymised within a reasonable period, except
            where we must retain it by law. No system is perfectly secure, but we work to protect your information.
          </p>
        </Section>

        <Section title="7. Your choices and rights">
          <List items={[
            'Access, correct or update your profile information in the app.',
            'Turn off notifications, camera or location permissions in your device or browser settings.',
            'Sign out of any device or browser from your account’s linked-devices list.',
            'Request access to or deletion of your personal data by emailing us (see Contact). Because your Company controls its workspace data, some requests may need to be confirmed with your Company administrator.',
          ]} />
        </Section>

        <Section title="8. Account and data deletion">
          <p>
            To delete your account and associated personal data, email <a className="text-brand-600 hover:underline" href={`mailto:${CONTACT_EMAIL}?subject=Delete%20my%20account`}>{CONTACT_EMAIL}</a> from
            your registered email address with the subject “Delete my account”. We will confirm and complete the request
            within 30 days, subject to legal retention requirements.
          </p>
        </Section>

        <Section title="9. Children">
          <p>The Service is intended for business use by adults. It is not directed to children under 18 and we do not knowingly collect their information.</p>
        </Section>

        <Section title="10. International transfers">
          <p>Your information may be processed in countries other than your own where our providers operate. We use appropriate safeguards where required by law.</p>
        </Section>

        <Section title="11. Changes to this policy">
          <p>We may update this policy from time to time. The “Last updated” date above shows the latest version; material changes will be announced in the app or by email.</p>
        </Section>

        <Section title="12. Contact">
          <p>Questions or requests: <a className="text-brand-600 hover:underline" href={`mailto:${CONTACT_EMAIL}`}>{CONTACT_EMAIL}</a></p>
        </Section>
      </div>
    </div>
  )
}
