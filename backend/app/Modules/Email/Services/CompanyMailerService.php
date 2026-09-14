<?php

namespace App\Modules\Email\Services;

use App\Models\Company;
use App\Models\EmailIntegration;
use App\Models\EmailLog;
use App\Services\ApiKeyEncryption;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Sends mail through a company's OWN connected SMTP account rather than the platform's shared
 * mailer, so alerts/notifications/announcements land in a customer's inbox as that company, not
 * as us. Laravel's config-driven mailers assume one fixed set of mailers defined at boot, so each
 * send here registers a throwaway "dynamic" mailer built from the company's stored (encrypted)
 * SMTP credentials and purges any previously-resolved instance first — otherwise the very next
 * send, for a different company in the same worker process, would reuse the first company's
 * transport since Laravel caches resolved mailers by name.
 */
class CompanyMailerService
{
    /** @return array{success:bool, message:?string} */
    public function send(Company $company, string $toEmail, ?string $toName, string $subject, string $bodyHtml, string $kind = 'notification', ?int $contactId = null): array
    {
        $integration = EmailIntegration::where('company_id', $company->id)->where('is_active', true)->first();
        if (!$integration) {
            return $this->fail($company, $toEmail, $toName, $subject, $kind, $contactId, 'No email integration connected for this company.');
        }

        try {
            $this->applyMailerConfig(
                $integration->smtp_host, $integration->smtp_port, $integration->encryption,
                $integration->smtp_username, ApiKeyEncryption::decrypt($integration->smtp_password),
            );

            Mail::mailer('dynamic')->html($bodyHtml, function (Message $message) use ($integration, $toEmail, $toName, $subject) {
                $message->to($toEmail, $toName)
                    ->from($integration->from_email, $integration->from_name)
                    ->subject($subject);
            });
        } catch (\Throwable $e) {
            return $this->fail($company, $toEmail, $toName, $subject, $kind, $contactId, $e->getMessage());
        }

        EmailLog::create([
            'company_id' => $company->id, 'contact_id' => $contactId, 'sent_by' => auth()->id(),
            'to_email' => $toEmail, 'to_name' => $toName, 'subject' => $subject, 'kind' => $kind, 'status' => 'sent',
        ]);

        return ['success' => true, 'message' => null];
    }

    /**
     * Sends a one-off test message against raw (not-yet-saved) credentials, so the "Save" button
     * on the connect form can prove the SMTP details actually work before they're persisted.
     * @param array{smtp_host:string, smtp_port:int, encryption:string, smtp_username:string, smtp_password:string, from_email:string, from_name:?string} $creds
     */
    public function testConnection(array $creds, string $toEmail): array
    {
        try {
            $this->applyMailerConfig($creds['smtp_host'], $creds['smtp_port'], $creds['encryption'], $creds['smtp_username'], $creds['smtp_password']);
            Mail::mailer('dynamic')->html('<p>This is a test email confirming your SMTP connection works.</p>', function (Message $message) use ($creds, $toEmail) {
                $message->to($toEmail)
                    ->from($creds['from_email'], $creds['from_name'] ?? null)
                    ->subject('Test email — connection verified');
            });
            return ['valid' => true, 'message' => 'Test email sent successfully.'];
        } catch (\Throwable $e) {
            return ['valid' => false, 'message' => $e->getMessage()];
        }
    }

    private function applyMailerConfig(string $host, int $port, string $encryption, string $username, string $password): void
    {
        config(['mail.mailers.dynamic' => [
            'transport'  => 'smtp',
            'host'       => $host,
            'port'       => $port,
            'encryption' => $encryption === 'none' ? null : $encryption,
            'username'   => $username,
            'password'   => $password,
            'timeout'    => 15,
        ]]);

        Mail::purge('dynamic');
    }

    private function fail(Company $company, string $toEmail, ?string $toName, string $subject, string $kind, ?int $contactId, string $error): array
    {
        EmailLog::create([
            'company_id' => $company->id, 'contact_id' => $contactId, 'sent_by' => auth()->id(),
            'to_email' => $toEmail, 'to_name' => $toName, 'subject' => $subject, 'kind' => $kind,
            'status' => 'failed', 'error' => $error,
        ]);
        return ['success' => false, 'message' => $error];
    }
}
