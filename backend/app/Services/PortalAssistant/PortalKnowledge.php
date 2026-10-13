<?php

namespace App\Services\PortalAssistant;

/**
 * Static map of the portal's pages and what each is for. Fed to the assistant so it can
 * explain features, give step-by-step guidance and point at the right page. `permission`
 * (optional) lets the controller hide pages the asking user cannot open.
 */
class PortalKnowledge
{
    /** @return array<int,array{path:string,title:string,about:string,permission?:string,admin?:bool}> */
    public static function pages(): array
    {
        return [
            ['path' => '/dashboard', 'title' => 'Dashboard', 'about' => 'Overview of messages, leads, sessions and usage at a glance.'],
            ['path' => '/wa-chat/sessions', 'title' => 'WhatsApp Sessions', 'about' => 'Connect a WhatsApp number by scanning a QR code, see session status, reconnect or remove a number.'],
            ['path' => '/wa-chat/chats', 'title' => 'Chats', 'about' => 'Shared team inbox for WhatsApp conversations: reply, send media/voice, assign, label and close chats.'],
            ['path' => '/wa-chat/message-sender', 'title' => 'Create Campaign', 'about' => 'Bulk broadcast to contacts: pick contacts/labels, write the message with media, schedule and send.'],
            ['path' => '/wa-chat/message-sender/history', 'title' => 'Campaign History', 'about' => 'Past campaigns with sent/delivered/failed counts.'],
            ['path' => '/wa-chat/webhooks', 'title' => 'Webhooks', 'admin' => true, 'about' => 'Send WhatsApp events to your own URL.'],
            ['path' => '/wa-chat/webhook-logs', 'title' => 'Webhook Logs', 'about' => 'Delivery history and errors of webhooks.'],
            ['path' => '/wa-chat/templates', 'title' => 'Templates', 'about' => 'Reusable message templates for quick replies and campaigns.'],
            ['path' => '/wa-chat/api-services', 'title' => 'API Services', 'admin' => true, 'about' => 'API keys and endpoints to send WhatsApp messages from your own software.'],
            ['path' => '/wa-chat/export', 'title' => 'Data Export', 'admin' => true, 'about' => 'Export contacts/chats/messages to a file.'],
            ['path' => '/wa-chat/media-library', 'title' => 'Media Library', 'about' => 'Upload and reuse images, videos and documents.'],
            ['path' => '/wa-chat/automations', 'title' => 'Agent Automations', 'about' => 'Rules that auto-reply or act on incoming WhatsApp messages.'],
            ['path' => '/wa-chat/analytics', 'title' => 'Inbox Analytics', 'about' => 'Response times, volumes and agent performance for the inbox.'],
            ['path' => '/wa-chat/logs', 'title' => 'Audit Log', 'about' => 'Who did what in WhatsApp chat.'],
            ['path' => '/wa-cloud/dashboard', 'title' => 'WhatsApp Cloud API Dashboard', 'about' => 'Official Meta WhatsApp Cloud API overview.'],
            ['path' => '/wa-cloud/phone-numbers', 'title' => 'Cloud Phone Numbers', 'about' => 'Register and manage official Cloud API phone numbers.'],
            ['path' => '/wa-cloud/templates', 'title' => 'WA Templates', 'about' => 'Create Meta-approved message templates (required for messaging outside the 24h window).'],
            ['path' => '/wa-cloud/campaigns', 'title' => 'Cloud Campaigns', 'about' => 'Bulk template campaigns over the Cloud API.'],
            ['path' => '/wa-cloud/survey-forms', 'title' => 'Survey Forms', 'about' => 'Build surveys/forms and collect responses on WhatsApp.'],
            ['path' => '/wa-cloud/inbox', 'title' => 'Cloud Inbox', 'about' => 'Inbox for Cloud API conversations.'],
            ['path' => '/wa-cloud/api-service', 'title' => 'Cloud API Service', 'admin' => true, 'about' => 'API access for the Cloud API numbers.'],
            ['path' => '/wa-cloud/settings', 'title' => 'Cloud Settings', 'admin' => true, 'about' => 'Cloud API credentials and webhook configuration.'],
            ['path' => '/flow-builders', 'title' => 'Flow Builder', 'about' => 'Drag-and-drop chatbot flows with questions, conditions and actions.'],
            ['path' => '/wa-agent/ai-agent', 'title' => 'AI Agent', 'about' => 'The customer-facing AI agent: live test chat, response mode (text/voice), sessions and stats.'],
            ['path' => '/wa-agent/knowledge-base', 'title' => 'Knowledge Base', 'about' => 'Upload documents/FAQs the AI agent uses to answer customers (RAG).'],
            ['path' => '/wa-agent/pipelines', 'title' => 'AI Pipelines', 'about' => 'Stages the AI moves leads through.'],
            ['path' => '/wa-agent/playbook', 'title' => 'Playbook', 'about' => 'Business rules, tone, products and instructions for the AI agent.'],
            ['path' => '/wa-agent/lead-intelligence', 'title' => 'Lead Intelligence', 'about' => 'AI scoring and insights on leads.'],
            ['path' => '/wa-agent/meta-ai', 'title' => 'AI Config', 'about' => 'Conversation intelligence and AI behaviour settings.'],
            ['path' => '/wa-agent/logs', 'title' => 'AI Logs', 'about' => 'What the AI agent answered and why.'],
            ['path' => '/wa-agent/settings', 'title' => 'AI Settings / API Keys', 'admin' => true, 'about' => 'Add Anthropic/OpenAI/Google AI keys and choose the active provider. An OpenAI key also enables voice transcription and spoken replies.'],
            ['path' => '/meta-ads/accounts', 'title' => 'Meta Ad Accounts', 'about' => 'Connect Facebook ad accounts.'],
            ['path' => '/meta-ads/campaigns', 'title' => 'Meta Ad Campaigns', 'about' => 'Create and manage ad campaigns.'],
            ['path' => '/meta-ads/audiences', 'title' => 'Audience Sets', 'about' => 'Custom and lookalike audiences.'],
            ['path' => '/meta-ads/lead-ads', 'title' => 'Lead Ads', 'about' => 'Leads captured from Facebook/Instagram lead forms.'],
            ['path' => '/meta-ads/creatives', 'title' => 'Creative Studio', 'about' => 'Build ad creatives.'],
            ['path' => '/meta-ads/insights', 'title' => 'Ads Insight', 'about' => 'Spend, reach and results reporting.'],
            ['path' => '/instagram/accounts', 'title' => 'Instagram Accounts', 'about' => 'Connect Instagram business accounts.'],
            ['path' => '/instagram/automations', 'title' => 'Auto-DM Rules', 'about' => 'Auto-send DMs when someone comments a keyword.'],
            ['path' => '/instagram/inbox', 'title' => 'Instagram DM Inbox', 'about' => 'Reply to Instagram DMs.'],
            ['path' => '/instagram/comments', 'title' => 'Instagram Comments', 'about' => 'Manage and reply to comments.'],
            ['path' => '/instagram/insights', 'title' => 'Instagram Insights', 'about' => 'Account analytics.'],
            ['path' => '/catalog', 'title' => 'Catalog', 'about' => 'Products/services catalog the AI and chats can share.'],
            ['path' => '/website-widget', 'title' => 'Website Widget', 'about' => 'Embed a chat widget on your website and capture leads.'],
            ['path' => '/contacts', 'title' => 'Contacts', 'about' => 'All contacts: add, import CSV, tag, search and view history.'],
            ['path' => '/labels', 'title' => 'Labels', 'about' => 'Tags to group contacts and chats.'],
            ['path' => '/blacklist', 'title' => 'Blacklist', 'about' => 'Numbers blocked from messaging.'],
            ['path' => '/crm/deals', 'title' => 'Deals Pipeline', 'about' => 'Kanban of sales deals by stage.'],
            ['path' => '/crm/tasks', 'title' => 'Tasks & Follow-ups', 'about' => 'To-dos and follow-up reminders.'],
            ['path' => '/crm/calendar', 'title' => 'Calendar', 'about' => 'Schedule of follow-ups and meetings.'],
            ['path' => '/crm/segments', 'title' => 'Segments', 'about' => 'Saved contact filters for targeted campaigns.'],
            ['path' => '/leads', 'title' => 'All Leads', 'about' => 'Every lead with status, owner and source; open one to add notes, change status or reassign.'],
            ['path' => '/leads/categories', 'title' => 'Lead Categories', 'about' => 'Define lead categories/statuses.'],
            ['path' => '/leads/by-staff', 'title' => 'Leads by Staff', 'about' => 'Lead workload per staff member.'],
            ['path' => '/leads/assignments', 'title' => 'Assignment Queues', 'admin' => true, 'about' => 'Queues that distribute new leads to staff.'],
            ['path' => '/leads/assignment-rules', 'title' => 'Assignment Rules', 'admin' => true, 'about' => 'Rules (round-robin, by category, working hours) for auto-assigning leads.'],
            ['path' => '/leads/summary', 'title' => 'Leads Summary', 'about' => 'Lead counts and conversion summary.'],
            ['path' => '/leads/report', 'title' => 'Leads Report', 'about' => 'Detailed lead reports.'],
            ['path' => '/leads/logs', 'title' => 'Lead Logs', 'about' => 'History of lead changes.'],
            ['path' => '/hr/attendance', 'title' => 'My Attendance', 'about' => 'Staff check-in/check-out, breaks and own attendance history.'],
            ['path' => '/hr/target', 'title' => 'My Target', 'about' => 'Staff sales/lead targets and progress.'],
            ['path' => '/hr/payroll', 'title' => 'My Payroll', 'about' => 'Staff own payslips.'],
            ['path' => '/hr/admin/attendance', 'title' => 'Attendance (Admin)', 'admin' => true, 'about' => 'Company-wide attendance, corrections and reports.'],
            ['path' => '/hr/admin/payroll', 'title' => 'Payroll (Admin)', 'admin' => true, 'about' => 'Generate and review payroll.'],
            ['path' => '/hr/admin/sales', 'title' => 'Sales (Admin)', 'admin' => true, 'about' => 'Record and review staff sales.'],
            ['path' => '/hr/admin/incentives', 'title' => 'Incentives', 'admin' => true, 'about' => 'Incentive/commission rules and payouts.'],
            ['path' => '/hr/admin/leave', 'title' => 'Leave Requests', 'admin' => true, 'about' => 'Approve or reject staff leave.'],
            ['path' => '/hr/admin/staff-setup', 'title' => 'HR Staff Setup', 'admin' => true, 'about' => 'Salary, shifts and HR profile of each staff member.'],
            ['path' => '/staff/index', 'title' => 'Staff', 'admin' => true, 'about' => 'Add staff, set roles, activate/deactivate accounts.'],
            ['path' => '/staff/roles', 'title' => 'Roles & Permissions', 'admin' => true, 'about' => 'Create roles and choose which pages/actions each role can use.'],
            ['path' => '/staff/account-access', 'title' => 'Account Access', 'admin' => true, 'about' => 'Control which WhatsApp numbers/accounts each staff member can see.'],
            ['path' => '/settings/integrations', 'title' => 'Integrations', 'admin' => true, 'about' => 'Google Calendar, Drive, Sheets and Email integrations.'],
            ['path' => '/settings/api-keys', 'title' => 'API Keys', 'admin' => true, 'about' => 'AI provider keys for the company.'],
            ['path' => '/plan-purchase', 'title' => 'Plans', 'admin' => true, 'about' => 'View your plan and upgrade or renew.'],
            ['path' => '/wallet', 'title' => 'Wallet', 'admin' => true, 'about' => 'Balance, top-ups and charges.'],
            ['path' => '/setup-guide', 'title' => 'Setup Guide', 'about' => 'Step-by-step onboarding checklist.'],
        ];
    }

    /** Compact text block for the system prompt. */
    public static function asPromptText(callable $canOpen, bool $isAdmin = false): string
    {
        $lines = [];
        foreach (self::pages() as $p) {
            if (isset($p['permission']) && !$canOpen($p['permission'])) {
                continue;
            }
            if (!empty($p['admin']) && !$isAdmin) {
                continue;
            }
            $lines[] = "- {$p['title']} [{$p['path']}]: {$p['about']}";
        }
        return implode("\n", $lines);
    }

    /** @return array<string,string> path => title */
    public static function titles(): array
    {
        return collect(self::pages())->pluck('title', 'path')->all();
    }
}
