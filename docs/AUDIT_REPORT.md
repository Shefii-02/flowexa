# Audit report — CRM + HR (2026-11-19)

Baseline: Laravel 13 / PHP 8.4, module layout `backend/app/Modules/*`, JWT auth, custom roles/permissions, tenant column is **`company_id`** (read "organization" in the work docs as "company"). Tests: `php artisan test` (SQLite in memory, 196 pass).

## Target concept → existing
| Concept | Existing | Status |
|---|---|---|
| organizations | `companies` | exists |
| users/roles/permissions | `users`, `roles`, `permissions`, `permission_overrides`, plan-level permission list | exists |
| leads / contacts | `leads`, `contacts`, `lead_categories`, `lead_events`, `lead_imports` | exists |
| assignment + history | `lead_assignments`, `lead_assignment_rules`, `lead_assignment_notifications`, `staff_availability` | exists (engine: auto / hybrid / uber, round robin or weighted) |
| follow-ups / tasks | `crm_tasks` (type followup/todo/call…), `calendar_events`, `follow_up_queue` | exists |
| deals / segments | `crm_deals`, `crm_segments` | exists |
| conversations / messages | `wa_conversations`, `wa_messages`, `instagram_*`, `widget_*`, `team_chat_*` | exists |
| webhook log | `webhook_logs`, `instagram_webhook_events`, `meta_leads` | exists (per provider) |
| automation | `automation_rules/logs`, `wa_cloud_automation_*`, flow builder | exists |
| AI | `ai_*` tables, `conversation_analyses`, `lead_scores`, `lead_scoring_rules`, Meta AI | exists |
| HR | `hr_attendance*`, `hr_leave_*`, `hr_payroll_*`, `hr_incentive*`, `hr_staff_profiles`, `hr_settings` | exists |

## Tenancy
CRM and HR controllers scope every lookup with `where('company_id', …)` and validate foreign ids with `Rule::exists(...)->where('company_id', …)`. No global scope/trait — scoping is by convention, so each new query must do it by hand.

## Bugs found and fixed
1. **Password-reset tokens never expired** — `now()->diffInMinutes($created_at)` is negative in Carbon 3, so `> 60` was never true (`AuthController::resetPassword`).
2. **Payroll over-counted leave days** when a leave ran past month end (5.99… + 1 = 6.99 days) → unpaid-leave deduction too high (`Hr\PayrollService::leaveDaysInMonth`). Test added.
3. **HR dashboard "days late" was negative** (`today()->diffInDays(past)`).
4. **Lead assignments — any staff with `leads.view` could**: accept a lead offered to someone else (steal it), complete or transfer anyone's lead, set any status via `PUT`, decline to re-trigger the cascade; `complete` was not idempotent (workload and conversion counters moved twice). Now: accept needs the offer or ownership and is row-locked (409 if taken); complete/transfer need the assigned staff or `leads.manage`; update validates the status; complete is idempotent; `campaign_id`/`staff_id`/`to_staff_id` are validated against the company.
5. Test double `LeadServiceImportExportTest` no longer matched `LeadRepositoryInterface` → fatal error aborted the whole suite. Stock `ExampleTest` (needed a DB) removed.

## Follow-up fixes (done)
- Assignment list/show: staff without `leads.manage` see only their own and offered assignments.
- Round robin: candidate row is locked (`lockForUpdate`) inside `assign()`'s transaction, so concurrent leads no longer pick the same person.
- Accepting an offered lead now counts it in `staff_availability` (shared `bumpWorkload`, busy at `max_leads`), without double-counting the instant-assign path.
- Payroll: working days start at the staff account's creation date for mid-month joiners; releasing a run with no pay lines is refused.
- Tests added: leave-days, working-days, workload / round-robin / busy-skip (201 passing).

## Still open
- HR has no explicit joining date (the account creation date is used as the proxy).
- No HTTP-level feature tests for the assignment controller (accept/decline/complete/transfer) — needs a schema-backed test base.
