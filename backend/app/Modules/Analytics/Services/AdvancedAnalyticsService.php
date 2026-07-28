<?php
// ════════════════════════════════════════════════════════════════════════════
// AdvancedAnalyticsService.php
// app/Modules/Analytics/Services/AdvancedAnalyticsService.php
// ════════════════════════════════════════════════════════════════════════════
namespace App\Modules\Analytics\Services;

use Illuminate\Support\Facades\DB;

class AdvancedAnalyticsService
{
    // ── Cohort analysis — lead conversion by signup month ──────────────────
    public function cohortAnalysis(int $companyId, int $months = 6): array
    {
        $cohorts = DB::select("
            SELECT
                DATE_FORMAT(l.created_at, '%Y-%m') as cohort_month,
                COUNT(*)                           as total_leads,
                SUM(l.stage = 'enrolled')          as enrolled,
                ROUND(SUM(l.stage='enrolled')/COUNT(*)*100,1) as conversion_rate,
                AVG(DATEDIFF(l.enrolled_at, l.created_at)) as avg_days_to_enroll
            FROM leads l
            WHERE l.company_id = ?
              AND l.created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
              AND l.deleted_at IS NULL
            GROUP BY cohort_month
            ORDER BY cohort_month
        ", [$companyId, $months]);

        return $cohorts;
    }

    // ── Staff performance comparison ────────────────────────────────────────
    public function staffPerformanceComparison(int $companyId): array
    {
        return DB::select("
            SELECT
                u.name,
                u.department,
                COUNT(l.id)                          as total_leads,
                SUM(l.stage = 'enrolled')             as enrolled,
                SUM(l.stage = 'lost')                 as lost,
                ROUND(SUM(l.stage='enrolled')/NULLIF(COUNT(l.id),0)*100,1) as conversion_rate,
                AVG(DATEDIFF(l.enrolled_at, l.assigned_at)) as avg_days_to_close,
                SUM(l.priority = 'high')              as high_priority_leads
            FROM users u
            LEFT JOIN leads l ON l.assigned_to = u.id AND l.company_id = ?
            WHERE u.company_id = ? AND u.is_active = 1
            GROUP BY u.id, u.name, u.department
            ORDER BY conversion_rate DESC
        ", [$companyId, $companyId]);
    }

    // ── Message funnel by hour-of-day (best time to send) ──────────────────
    public function messageSendTimeAnalysis(int $companyId): array
    {
        return DB::select("
            SELECT
                HOUR(created_at)                  as hour_of_day,
                COUNT(*)                          as messages_sent,
                SUM(status='delivered')           as delivered,
                SUM(status='read')                as `read`,
                ROUND(SUM(status='read')/NULLIF(COUNT(*),0)*100,1) as read_rate
            FROM message_logs
            WHERE company_id = ? AND direction = 'outbound'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY hour_of_day
            ORDER BY hour_of_day
        ", [$companyId]);
    }

    // ── Flow node performance ──────────────────────────────────────────────
    public function flowNodePerformance(int $companyId): array
    {
        return DB::select("
            SELECT
                fn.id,
                fn.title,
                fn.type,
                fn.lead_category,
                fn.trigger_count,
                COUNT(DISTINCT l.id) AS leads_generated,
                COUNT(DISTINCT fs.contact_id) AS unique_contacts
            FROM flow_nodes fn
            LEFT JOIN leads l ON l.flow_node_id = fn.id
            LEFT JOIN flow_sessions fs ON fs.current_node_id = fn.id
            WHERE fn.company_id = ?
            GROUP BY
                fn.id,
                fn.title,
                fn.type,
                fn.lead_category,
                fn.trigger_count
            ORDER BY fn.trigger_count DESC
        ", [$companyId]);
    }

    // ── Campaign performance trends (30 days) ──────────────────────────────
    public function campaignTrends(int $companyId): array
    {
        $daily = DB::select("
            SELECT
                DATE(started_at)               as date,
                COUNT(*)                       as campaigns_run,
                SUM(total_contacts)            as total_reached,
                SUM(delivered)                 as total_delivered,
                SUM(`read`)                    as total_read,
                ROUND(AVG(delivered/NULLIF(total_contacts,0)*100),1) as avg_delivery_rate,
                ROUND(AVG(`read`/NULLIF(total_contacts,0)*100),1)    as avg_read_rate
            FROM campaigns
            WHERE company_id = ? AND status = 'completed'
              AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(started_at)
            ORDER BY date
        ", [$companyId]);

        $byTemplate = DB::select("
            SELECT
                t.id,
                t.name as template_name,
                t.category,
                COUNT(c.id) as times_used,
                ROUND(AVG(c.delivered/NULLIF(c.total_contacts,0)*100),1) as avg_delivery,
                ROUND(AVG(c.`read`/NULLIF(c.total_contacts,0)*100),1) as avg_read
            FROM campaigns c
            JOIN wa_templates t ON c.template_id = t.id
            WHERE c.company_id = ? AND c.status = 'completed'
            GROUP BY t.id, t.name, t.category
            ORDER BY times_used DESC
            LIMIT 10
        ", [$companyId]);

        return ['daily' => $daily, 'by_template' => $byTemplate];
    }

    // ── Lead scoring ────────────────────────────────────────────────────────
    public function topScoredLeads(int $companyId, int $limit = 20): array
    {
        return DB::select("
            SELECT
                l.id, l.stage, l.priority, l.category,
                c.name as contact_name, c.phone,
                ls.score,
                u.name as assigned_to
            FROM leads l
            JOIN contacts c ON l.contact_id = c.id
            LEFT JOIN lead_scores ls ON ls.lead_id = l.id
            LEFT JOIN users u ON l.assigned_to = u.id
            WHERE l.company_id = ? AND l.deleted_at IS NULL
              AND l.stage NOT IN ('enrolled','lost')
            ORDER BY ls.score DESC
            LIMIT ?
        ", [$companyId, $limit]);
    }

    // ── Wallet burn rate analysis ──────────────────────────────────────────
    public function walletBurnRate(int $companyId): array
    {
        $daily = DB::select("
            SELECT
                DATE(created_at) as date,
                SUM(amount)      as debited
            FROM wallet_transactions
            WHERE company_id = ? AND type = 'debit'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ", [$companyId]);

        $avgDaily = collect($daily)->avg('debited') ?? 0;
        $wallet   = DB::table('wallets')->where('company_id', $companyId)->first();
        $balance  = $wallet?->balance ?? 0;
        $daysLeft = $avgDaily > 0 ? round($balance / $avgDaily) : null;

        return ['daily' => $daily, 'avg_daily_burn' => round($avgDaily), 'estimated_days_remaining' => $daysLeft];
    }

    // ── Pre-aggregate daily snapshot (run via scheduler) ──────────────────
    public function aggregateDaily(int $companyId, string $date): void
    {
        $d   = $date;
        $cid = $companyId;

        $data = [
            'company_id'           => $cid,
            'date'                 => $d,
            'messages_sent'        => DB::table('message_logs')->where('company_id',$cid)->where('direction','outbound')->whereDate('created_at',$d)->count(),
            'messages_delivered'   => DB::table('message_logs')->where('company_id',$cid)->where('status','delivered')->whereDate('created_at',$d)->count(),
            'messages_read'        => DB::table('message_logs')->where('company_id',$cid)->where('status','read')->whereDate('created_at',$d)->count(),
            'messages_failed'      => DB::table('message_logs')->where('company_id',$cid)->where('status','failed')->whereDate('created_at',$d)->count(),
            'messages_inbound'     => DB::table('message_logs')->where('company_id',$cid)->where('direction','inbound')->whereDate('created_at',$d)->count(),
            'contacts_new'         => DB::table('contacts')->where('company_id',$cid)->whereDate('created_at',$d)->count(),
            'contacts_opted_out'   => DB::table('contacts')->where('company_id',$cid)->whereDate('opted_out_at',$d)->count(),
            'leads_created'        => DB::table('leads')->where('company_id',$cid)->whereDate('created_at',$d)->count(),
            'leads_enrolled'       => DB::table('leads')->where('company_id',$cid)->whereDate('enrolled_at',$d)->count(),
            'leads_lost'           => DB::table('leads')->where('company_id',$cid)->where('stage','lost')->whereDate('updated_at',$d)->count(),
            'campaigns_launched'   => DB::table('campaigns')->where('company_id',$cid)->whereDate('started_at',$d)->count(),
            'wallet_debited'       => DB::table('wallet_transactions')->where('company_id',$cid)->where('type','debit')->whereDate('created_at',$d)->sum('amount'),
            'updated_at'           => now(),
            'created_at'           => now(),
        ];

        DB::table('analytics_daily')->upsert($data, ['company_id','date'], array_keys($data));
    }
}

