# Database-only settings reference

Most settings live in `app_settings` with defaults in
`SettingsService::defaults()` and UI in Settings. The keys below are
**read by code but have no UI field** — change them with direct DB writes
(e.g. `UPDATE app_settings SET value='30' WHERE \`key\`='cache_ttl_history_5m'`).
This is intentional: exposing everything would be scope creep.

| Key | Default | Read by | Purpose |
|---|---|---|---|
| `cache_ttl_history_5m` | `5` | `Api\StatusController`, ping detail | History cache TTL (minutes) |
| `cache_ttl_history_30m` | `10` | `Api\StatusController`, ping detail | History cache TTL (minutes) |
| `cache_ttl_ip_rep_list` | `30` | `IpReputationApiController` | IP-rep list cache TTL |
| `cache_ttl_ip_rep_detail` | `15` | `IpReputationApiController` | IP-rep detail cache TTL |
| `cache_ttl_disk_health_list` | `15` | `Api\StatusController` | Disk health cache TTL |
| `metrics_raw_hours` | `24` | `RollupWorker`, `RetentionService` | Raw metrics window |
| `metrics_5m_days` | `14` | `RollupWorker`, `RetentionService` | 5-minute rollup window |
| `metrics_1h_days` | `90` | `RollupWorker`, `RetentionService` | 1-hour rollup window |
| `metrics_1d_days` | `730` | `RetentionService` | Daily rollup window |
| `disk_rollup_days` | `2` | `DiskRollupWorker` | Disk rollup window |
| `disk_push_max_body_bytes` | `1048576` | `PushDiskController` | Max push-disk payload |
| `disk_push_max_items` | `64` | `PushDiskController` | Max disks per push |
| `push_api_rate_per_minute` | `600` | `PushController` | Push rate limit |
| `agent_push_signature_required` | `1` | `PushController` | Enforce HMAC signing |
| `service_metrics_store_all` | `0` | `PushController` | Store all service rows |

Removed keys (were seeded but never read — deleted, do not re-add):
`realtime_push_interval_s`, `deadband_enabled`, `deadband_cpu`,
`deadband_network_bps`, `deadband_queue`, `agent_force_interval_s`,
`cache_ttl_disk_health_single`, `cache_ttl_disk_health_history`.
