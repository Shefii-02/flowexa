<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Superadmin-controlled list of tiles hidden from the MOBILE app's Explore screen.
 * Web is unaffected. Stored platform-wide in platform_settings['mobile_hidden_modules'].
 */
class MobileModulesController extends Controller
{
    private const KEY = 'mobile_hidden_modules';

    /** Mirrors unichat_mobile_app/lib/core/nav/app_modules.dart (id => title/group). */
    private function catalogue(): array
    {
        return [
            ['id' => "wa_chat_dashboard", 'title' => "Dashboard", 'group' => "WA Chat"],
            ['id' => "phone_numbers", 'title' => "Sessions", 'group' => "WA Chat"],
            ['id' => "wa_chat", 'title' => "Chats", 'group' => "WA Chat"],
            ['id' => "message_sender", 'title' => "Campaigns", 'group' => "WA Chat"],
            ['id' => "templates", 'title' => "Templates", 'group' => "WA Chat"],
            ['id' => "wa_chat_api_services", 'title' => "API Services", 'group' => "WA Chat"],
            ['id' => "wa_chat_media_library", 'title' => "Media Library", 'group' => "WA Chat"],
            ['id' => "wa_chat_automations", 'title' => "Automation", 'group' => "WA Chat"],
            ['id' => "wa_chat_analytics", 'title' => "Inbox Analytics", 'group' => "WA Chat"],
            ['id' => "wa_chat_audit", 'title' => "Audit Logs", 'group' => "WA Chat"],
            ['id' => "wa_chat_export", 'title' => "Export", 'group' => "WA Chat"],
            ['id' => "cloud_numbers", 'title' => "Phone Numbers", 'group' => "WA Cloud"],
            ['id' => "wa_cloud_templates", 'title' => "WA Templates", 'group' => "WA Cloud"],
            ['id' => "wa_cloud_survey_forms", 'title' => "Survey Forms", 'group' => "WA Cloud"],
            ['id' => "wa_cloud_api_services", 'title' => "API Services", 'group' => "WA Cloud"],
            ['id' => "wa_cloud_automations", 'title' => "Agent Automations", 'group' => "WA Cloud"],
            ['id' => "wa_cloud_inbox", 'title' => "Inbox", 'group' => "WA Cloud"],
            ['id' => "wa_cloud_settings", 'title' => "Settings", 'group' => "WA Cloud"],
            ['id' => "wa_cloud", 'title' => "Inbox Analytics", 'group' => "WA Cloud"],
            ['id' => "wa_cloud_audit_log", 'title' => "Audit Logs", 'group' => "WA Cloud"],
            ['id' => "wa_cloud_campaigns", 'title' => "Campaigns", 'group' => "WA Cloud"],
            ['id' => "message_logs", 'title' => "Message Logs", 'group' => "WA Cloud"],
            ['id' => "knowledge_base", 'title' => "Knowledge Base", 'group' => "WA Agents"],
            ['id' => "ai_pipelines", 'title' => "Pipelines", 'group' => "WA Agents"],
            ['id' => "wa_agent_ai_agents", 'title' => "AI Agents", 'group' => "WA Agents"],
            ['id' => "wa_agent_playbooks", 'title' => "Playbooks", 'group' => "WA Agents"],
            ['id' => "lead_intelligence", 'title' => "Lead Intelligence", 'group' => "WA Agents"],
            ['id' => "ai_config", 'title' => "AI Config", 'group' => "WA Agents"],
            ['id' => "wa_agent_logs", 'title' => "Logs", 'group' => "WA Agents"],
            ['id' => "wa_agent_catalog", 'title' => "Catalog", 'group' => "WA Agents"],
            ['id' => "wa_agent_settings", 'title' => "Settings", 'group' => "WA Agents"],
            ['id' => "meta_accounts", 'title' => "Accounts", 'group' => "Meta Ads & Instagram"],
            ['id' => "meta_campaigns", 'title' => "Campaign", 'group' => "Meta Ads & Instagram"],
            ['id' => "meta_audience_sets", 'title' => "Audience Set", 'group' => "Meta Ads & Instagram"],
            ['id' => "meta_lead_ads", 'title' => "Lead Ads", 'group' => "Meta Ads & Instagram"],
            ['id' => "meta_creative_studio", 'title' => "Creative Studio", 'group' => "Meta Ads & Instagram"],
            ['id' => "meta_media_library", 'title' => "Media Library", 'group' => "Meta Ads & Instagram"],
            ['id' => "meta_ads", 'title' => "Ads Insight", 'group' => "Meta Ads & Instagram"],
            ['id' => "instagram_automations", 'title' => "Auto DM Rules", 'group' => "Meta Ads & Instagram"],
            ['id' => "instagram", 'title' => "SM Inbox", 'group' => "Meta Ads & Instagram"],
            ['id' => "instagram_comments", 'title' => "Comments", 'group' => "Meta Ads & Instagram"],
            ['id' => "instagram_insights", 'title' => "Insight", 'group' => "Meta Ads & Instagram"],
            ['id' => "listings", 'title' => "Catalogs", 'group' => "Meta Ads & Instagram"],
            ['id' => "contacts", 'title' => "All Contacts", 'group' => "CRM & Leads"],
            ['id' => "labels", 'title' => "Labels", 'group' => "CRM & Leads"],
            ['id' => "deals", 'title' => "Deal Pipelines", 'group' => "CRM & Leads"],
            ['id' => "crm_tasks", 'title' => "Tasks", 'group' => "CRM & Leads"],
            ['id' => "crm_followups", 'title' => "Follow-ups", 'group' => "CRM & Leads"],
            ['id' => "calendar", 'title' => "Calendar", 'group' => "CRM & Leads"],
            ['id' => "segments", 'title' => "Segments", 'group' => "CRM & Leads"],
            ['id' => "blacklist", 'title' => "Black List", 'group' => "CRM & Leads"],
            ['id' => "leads", 'title' => "All Leads", 'group' => "CRM & Leads"],
            ['id' => "lead_categories", 'title' => "Lead Category", 'group' => "CRM & Leads"],
            ['id' => "leads_by_staff", 'title' => "Lead by Staff", 'group' => "CRM & Leads"],
            ['id' => "lead_logs", 'title' => "Lead Logs", 'group' => "CRM & Leads"],
            ['id' => "lead_report", 'title' => "Lead Report", 'group' => "CRM & Leads"],
            ['id' => "assignment_queue", 'title' => "Assign Queues", 'group' => "CRM & Leads"],
            ['id' => "assignment_rules", 'title' => "Assignment Rules", 'group' => "CRM & Leads"],
            ['id' => "my_attendance", 'title' => "My Attendance", 'group' => "HR"],
            ['id' => "my_target", 'title' => "My Target", 'group' => "HR"],
            ['id' => "my_payroll", 'title' => "My Payroll", 'group' => "HR"],
            ['id' => "hr_attendance_all", 'title' => "Attendances (All)", 'group' => "HR"],
            ['id' => "hr_payrolls", 'title' => "Payrolls", 'group' => "HR"],
            ['id' => "hr_sales", 'title' => "Sales", 'group' => "HR"],
            ['id' => "hr_incentives", 'title' => "Incentives", 'group' => "HR"],
            ['id' => "hr_leave_requests", 'title' => "Leave Requests", 'group' => "HR"],
            ['id' => "hr_staff_setup", 'title' => "Staff Setups", 'group' => "HR"],
            ['id' => "hr_break_types", 'title' => "Break Types", 'group' => "HR"],
            ['id' => "hr_leave_types", 'title' => "Leave Types", 'group' => "HR"],
            ['id' => "hr_office", 'title' => "Office Details", 'group' => "HR"],
            ['id' => "staff", 'title' => "Staffs", 'group' => "Account"],
            ['id' => "roles", 'title' => "Roles", 'group' => "Account"],
            ['id' => "devices", 'title' => "Account Access", 'group' => "Account"],
            ['id' => "wallet", 'title' => "Wallet", 'group' => "Account"],
            ['id' => "plan_billing", 'title' => "Plan Billing", 'group' => "Account"],
            ['id' => "setup_guide", 'title' => "Setup Guides", 'group' => "Account"],
            ['id' => "flow_builder", 'title' => "Flow Builder", 'group' => "Operations"],
            ['id' => "media_library", 'title' => "Media Library", 'group' => "Operations"],
            ['id' => "otp_service", 'title' => "OTP / API", 'group' => "Operations"],
            ['id' => "export", 'title' => "Export", 'group' => "Operations"],
            ['id' => "integrations", 'title' => "Integrations", 'group' => "Operations"],
            ['id' => "widget", 'title' => "Chat Widget", 'group' => "Operations"],
            ['id' => "analytics", 'title' => "Analytics", 'group' => "Operations"],
            ['id' => "lead_summary", 'title' => "Lead Summary", 'group' => "Operations"],
            ['id' => "accounts_books", 'title' => "Accounts", 'group' => "Accounts"],
            ['id' => "settings", 'title' => "Settings", 'group' => "Operations"],
            ['id' => "superadmin", 'title' => "SuperAdmin", 'group' => "Operations"],
        ];
    }

    private function hiddenIds(): array
    {
        $raw = DB::table('platform_settings')->where('key', self::KEY)->value('value');
        $list = $raw ? json_decode($raw, true) : [];
        return is_array($list) ? array_values($list) : [];
    }

    // GET /superadmin/mobile-modules
    public function show(): JsonResponse
    {
        return response()->json(['catalogue' => $this->catalogue(), 'hidden' => $this->hiddenIds()]);
    }

    // PUT /superadmin/mobile-modules
    public function update(Request $request): JsonResponse
    {
        $d = $request->validate(['hidden' => ['present', 'array'], 'hidden.*' => ['string', 'max:100']]);
        $valid = array_column($this->catalogue(), 'id');
        $hidden = array_values(array_intersect(array_unique($d['hidden']), $valid));

        DB::table('platform_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($hidden), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['hidden' => $hidden]);
    }

    // GET /mobile-modules/hidden  (any authenticated user — the mobile app)
    public function hidden(): JsonResponse
    {
        return response()->json(['hidden' => $this->hiddenIds()]);
    }
}
