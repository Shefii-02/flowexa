# Backend changelog

Notable changes to the Laravel backend. Newest first.

## 2026-10-15

### Fixed

- `GET /api/v1/wa-cloud/otp-service/logs` returned `404` for a company that had not set up the WA Cloud OTP
  service yet (`firstOrFail()` in `WaCloudApiServiceController::logs()`). It now returns an empty paginated
  result (`data: []`, `total: 0`). `stopToken()` still 404s deliberately — stopping a service that does not
  exist is an error.

- `POST /api/v1/message-sender/jobs` did not exist, although the sender page calls it when a browser-side send
  finishes (`persistJobToBackend`). Those campaigns were never saved and lived only in localStorage. Added
  `MessageSenderController::recordCompleted()` (route in `routes/api.php`): it stores a finished `done`/`stopped`
  campaign in `openwa_message_sender_jobs` and dispatches nothing.

### Operations notes (no code change — see root `README.md` §1.4, §1.5 and §6)

- Two installs sharing one Redis must each set `REDIS_PREFIX`, `REDIS_DB`, `REDIS_CACHE_DB`, `CACHE_PREFIX` and
  `HORIZON_PREFIX`; otherwise the other install's Horizon consumes this app's `campaigns` jobs and drops them.
- Every install needs its own `schedule:run` cron entry for its own folder.
- If the Node gateway's MySQL tables are missing, per-company key creation fails with HTTP 500; restart the gateway
  and rotate `WA_CHAT_ADMIN_KEY`.
