# Laporan Perbaikan & Improvisasi — Monitors v2

**Tanggal:** 16 November 2026
**Lingkup:** backend, frontend (UI/UX), keamanan, performa, dan perbaikan bug grafik history
**Status:** seluruh perubahan diterapkan di working tree, belum di-commit

---

## 1. Ringkasan

Pekerjaan dibagi menjadi tiga paket yang dieksekusi berurutan:

| Paket | Fokus | Status |
| --- | --- | --- |
| **A** | Hardening: HTTP contract, sesi/login, CSRF, self-host aset | Selesai |
| **B** | Bug grafik history, performa query/worker, cache, jalur push | Selesai |
| **C** | UI/UX: fleet chart, freshness, skeleton, aksesibilitas, densitas tabel | Selesai |
| **D** | Perbaikan ikon tidak tampil di dev server | Selesai |

Prinsip yang dipegang: **perilaku legacy tetap default** di mana perubahan berpotensi mengubah hasil, `declare(strict_types=1)`, named PDO parameter (nama unik untuk native prepares), tanpa dependency eksternal baru, dan tidak ada file `.php` baru di `public/`.

---

## 2. Paket A — Hardening, keamanan, korektnes

### 2.1 HTTP layer

| File | Perubahan |
| --- | --- |
| `app/Http/Request.php` | Parsing **JSON body** (`php://input` + `json_decode` dengan `JSON_THROW_ON_ERROR`), properti `rawBody`, helper `isJson()`. Sebelumnya hanya `$_POST`. |
| `app/Http/Router.php` | Cache pola rute yang sudah dikompilasi; **405 Method Not Allowed** + header `Allow` (menambahkan `HEAD` bila `GET` tersedia, selalu `OPTIONS`); `HEAD` dipetakan otomatis ke `GET`; perbaikan pemotongan prefix handler redirect (301/307/308) dan penerusan query string. |
| `app/Http/Response.php` | Properti `meta` + `withData()`; `send()` menangani `HEAD` (mengirim `Content-Length` tanpa body, kecuali 204). |

### 2.2 Sesi & login

| File | Perubahan |
| --- | --- |
| `app/Services/AuthService.php` | `requireLogin()` didelegasikan ke `sessionExpiryResponse()`; menegakkan **idle timeout**, **absolute timeout**, dan **User-Agent binding** dengan respons **401 JSON** untuk `/api/*` (sebelumnya hanya cek `is_logged_in()` di middleware). `attemptLogin()` baru: dummy-hash bcrypt (menyamakan waktu respons untuk username tidak dikenal), `password_needs_rehash()` upgrade. Rate limit dipisah menjadi per-IP dan **per-username**; `clearLoginAttempts()` juga membersihkan kuota username. `logoutUser()` aman dipanggil saat `headers_sent()`. |
| `app/Http/Middleware/Auth.php` | Menjalankan `sessionExpiryResponse()`; unauthenticated → 401 JSON untuk `/api/*`, selain itu flash + 303 ke login. |
| `app/Http/Middleware/Admin.php` | 403 JSON untuk `/api/*`, selain itu flash danger + 303 ke dashboard. |
| `app/Controllers/Auth/LoginController.php` | Memakai `is_login_rate_limited()` dan `attempt_login()`; tidak lagi memanggil `password_verify()` langsung. |
| `app/Support/Globals/auth.php` | Wrapper baru: `is_username_rate_limited()`, `is_login_rate_limited()`, `attempt_login()`, `clear_login_attempts($ip, $username)`. |
| `config/bootstrap.php` | `LOGIN_USERNAME_MAX_ATTEMPTS = 20`. |

### 2.3 CSRF di luar query string

- `app/Controllers/Admin/PingTerminalTokenController.php` (baru) — `POST /ping/terminal/token` (middleware `admin`, `csrf`) menerbitkan nonce 24-byte hex di session dengan TTL 120 detik.
- `PingTerminalController::stream()` menerima token dari header `X-CSRF-Token`; fallback nonce `?nonce=` one-shot (langsung dihapus setelah dipakai).
- `public/assets/js/ping-terminal.js` ditulis ulang: `fetch()` + `ReadableStream` SSE parser, mengirim header `X-CSRF-Token` (token tidak lagi muncul di URL/log/referrer).

### 2.4 Self-host aset (CSP tanpa CDN)

- Vendor di `public/assets/vendor/`: Bootstrap 5.3.3 (CSS + bundle JS), Tabler Icons 3.34.1 (CSS + woff/woff2), ECharts 5.5.1.
- `app/Views/layouts/head.php`, `app/Views/layouts/footer.php`, `public/install.php` memakai aset lokal.
- CSP diperketat: `default-src 'self'` (tanpa `cdn.jsdelivr.net`), `font-src 'self' data:`, `connect-src 'self' https://challenges.cloudflare.com`.
- ECharts hanya dimuat bila view menetapkan `loadEcharts` (`ServerDetailController`, `PingDetailController`, `IpReputationDetailController`); dashboard memakai lazy loader.

---

## 3. Paket B — Bug grafik history & performa

### 3.1 Bug "data history terpotong / tidak lengkap"

**Penyebab yang ditemukan**

1. `ORDER BY recorded_at DESC LIMIT n` pada timestamp presisi detik: beberapa push dalam detik yang sama membuat baris di batas terpotong **dibuang tidak konsisten**.
2. Rentang 24 jam hanya membaca tabel `metrics` mentah; begitu partisi harian di-drop (retensi), sisi kiri grafik kosong.
3. Rollup (02:00) dan cleanup (03:00) yang bergantung jadwal: satu kali rollup terlewat = lubang permanen ±24 jam di 7d/30d.
4. Bootstrap halaman detail memakai `ORDER BY recorded_at ASC LIMIT 1200` (menyimpan baris **tertua**) sementara API memakai `DESC` → ujung kanan grafik hilang setelah load.
5. Label waktu di-parse sebagai waktu lokal browser → grafik bergeser untuk pengunjung beda zona waktu.

**Perbaikan**

| File | Perubahan |
| --- | --- |
| `app/Controllers/Api/StatusController.php` | `historyQuery()` ditulis ulang: setiap rentang memilih titik secara **tie-safe** (`GROUP BY ts`, `ORDER BY ts DESC LIMIT {$points}` di dalam subquery, hasil akhir ascending), menyertakan kolom **`ts` epoch**. Rentang 24h = gabungan rollup `bucket_seconds = 300` (lebih lama dari `metrics_raw_hours`) + raw. 7d/30d memakai irisan berbatas dengan **nama placeholder unik per irisan** (wajib untuk native prepares). Rentang tak dikenal → `24h`. Helper `bucketExpression()`, `wrapHistoryPoints()`, `wrapRollupHistoryPoints()`. |
| `app/Controllers/Admin/ServerDetailController.php` | Bootstrap history: `UNIX_TIMESTAMP(recorded_at) AS ts`, `ORDER BY recorded_at DESC LIMIT 1200`, lalu `array_reverse()`. |
| `public/assets/js/detail.js` | `downsample(rows, 720)` dihapus (data tidak lagi dibuang dengan stride indeks); `normalizeRows()` memprioritaskan `ts` epoch, timestamp tak valid ditaruh di akhir. |
| `public/assets/js/common.js` | `parseTimestampMs()` menerima epoch (detik < 1e12, selain itu milidetik). |

**Verifikasi:** `tests/history_completeness_test.php` (baru) menguji tie dua baris/detik dengan `points=10`, fallback rollup 24h, dan konsistensi `ts` terhadap label. `tests/history_payload_test.php` diubah dari hitungan baris rapuh menjadi perbandingan **bucket-set** deterministik.

### 3.2 Retensi & rollup

- `app/Services/RetentionService.php`: cutoff drop partisi/raw = `now - (metrics_raw_hours + 24 jam)` (margin aman satu hari), hasil run menyertakan `metrics_drop_cutoff_at`.
- `app/Services/Settings/CronWorkerService.php`: rollup dipindah dari `0 2 * * *` ke **`15 * * * *`** (tiap jam); TTL health `rollup_metrics` 129600 → 5400 detik.
- `README.md`: contoh crontab disesuaikan.

### 3.3 Dashboard

| File | Perubahan |
| --- | --- |
| `app/Controllers/Admin/DashboardController.php` | Satu query count (`COUNT(*)` + `SUM(active = 1)`) menggantikan dua query; KPI dihitung di SQL; 8 pemanggilan `worker_health_status()` menjadi satu `worker_health_statuses()`; seluruh string cron diambil dari `CronWorkerService::cronCommands()` (menghapus duplikasi dengan halaman Settings). |
| `app/Services/ServerListService.php` | `countByStatusFromDb()` (KPI benar walau server > 50 — sebelumnya dihitung dari window 50 baris); `serviceSummaryMap()` memakai named parameter (sebelumnya posisional `?`). |
| `app/Services/WorkerService.php` + `Globals/services_worker.php` | `statusFromRow()`, `worker_health_statuses([...])` (satu query `IN (...)`), sanitasi nama worker. |
| `app/Services/Settings/CronWorkerService.php` | `workerStatuses()` memakai batch (11 query → 1); `cronCommands()` baru. |

### 3.4 Cache

- `app/Support/Cache.php` ditulis ulang dengan **fallback file backend** di `storage/cache/` (JSON atomik `{k,e,v}`, tmp + rename): Redis tetap dipakai bila aktif, tapi aplikasi tidak lagi kehilangan seluruh cache saat `REDIS_ENABLED=0`. `version()`/`bumpVersion()` bekerja di kedua backend.
- `StatusController`: key `status:disk_summary:*` kini **berversi**.
- `PushDiskController`: invalidasi disk summary melalui `invalidate_status_cache()`.

### 3.5 Jalur push (ingest)

- `app/Controllers/Api/PushController.php`: daftar kolom metrik **tetap** (tanpa probe `information_schema` tiap push), `latest_metric_id` + `last_seen_at` di-update tanpa gate kolom, `recorded_at` diambil sekali (menghapus `SELECT NOW()` ekstra), kegagalan insert/commit mengembalikan `Response::json(..., 500)` alih-alih `json_response()` yang `exit` melewati Kernel.
- `app/Services/PushAuthService.php`: probe `push_allowed_ips` dihapus.

### 3.6 Alert worker

- `app/Services/AlertService.php`:
  - `LIMIT 1000` pada evaluasi service/ping diganti **pagination** (mencegah monitor terpotong diam-diam di fleet besar).
  - **Open-incident index** per proses: satu `SELECT` menggantikan ratusan `UPDATE` nol-baris per menit; disinkronkan lewat `markAlertOpen()`/`markAlertClosed()`, dengan fallback aman bila query index gagal.
- `tests/alert_resolution_test.php` (baru).

### 3.7 Lain-lain

- `LiveStreamController` & `EventsController`: mengirim `retry: 3000` agar klien tidak reconnecting agresif.
- `SettingsService::all()`: cache menyimpan **nilai mentah** (`settings:all:v2`) sehingga key sensitif tetap terenkripsi di cache dan tidak ada query kedua saat cache hit.
- `SettingsService::saveMany()`: satu **multi-row upsert** (sebelumnya N query untuk ±75 key).
- `app/Views/layouts/nav.php`: count alert aktif di-cache 15 detik (`alert:count:active`), diinvalidasi oleh `invalidate_alert_cache()`.
- `app/Console/MigrateCommand.php`: opsi **`--status`** dan **`--dry-run`**, validasi flag (exit code 2).
- `README.md`: perintah migrasi baru, daftar tes baru, cache fallback, fleet chart.

---

## 4. Paket C — UI/UX

| Item | Implementasi |
| --- | --- |
| **Fleet Performance chart** | Backend: `?fleet_history=30m\|6h\|24h\|7d` (`StatusController::fleetHistory()`, `fleetHistoryQuery()`), agregasi rata-rata CPU %, RAM %, Disk %, plus `COUNT(DISTINCT server_id)`; hanya server `active = 1`; cache 15–60 detik ber-versi. Frontend: `public/assets/js/fleet-chart.js` — kartu **di bawah tabel Monitoring Summary**, default **collapsed** jadi baris ringkas 48px berisi angka CPU/RAM/Disk + indikator tren; klik untuk expand (220px desktop / 180px mobile), preferensi tersimpan di `localStorage['monitors_fleet_chart_expanded']`. ECharts **lazy-load** via `IntersectionObserver` hanya saat pertama kali expand, tombol rentang (tersimpan di `sessionStorage`), refresh 60 detik saat tab terlihat, re-render saat tema berubah, resize debounce, empty/error state, skeleton. |
| **Freshness per baris** | Kolom status menampilkan "12s ago / 4m ago / never" dengan kelas `is-fresh/is-warm/is-stale/is-unknown`; `last_seen_ts` (epoch) ditambahkan ke payload `/api/status`; diperbarui tiap 15 detik dan saat polling. |
| **Skeleton & empty state** | Skeleton chart, audit seluruh tabel (alert logs, audit logs, disk health, export, IP reputation, ping monitors, server detail) memiliki empty row. |
| **Aksesibilitas sidebar mobile** | `focus trap` (Tab/Shift+Tab), `inert` + `aria-hidden` pada konten utama saat sidebar terbuka, restore focus, tutup saat link diklik, reset saat breakpoint berubah. |
| **Densitas tabel** | Tombol toggle di dashboard, `.is-compact` pada `[data-ui-table]`, persist di `localStorage['monitors_table_density']`, `aria-pressed` sinkron. |

CSS baru ditambahkan di akhir `public/assets/css/components.css` (kartu chart, badge freshness, toggle densitas, mode compact).

---

## 5. Paket D — Ikon tidak tampil (dev server)

**Gejala:** semua ikon Tabler tampil sebagai kotak, dan kartu Fleet Performance berhenti di "Loading…".

**Diagnosis:** font dan CSS sehat (diuji render langsung, semua glyph tampil). Penyebabnya adalah **SSE** (`/api/stream` dan `/api/events`) yang menahan satu worker PHP; `php -S` bersifat single-thread sehingga request font/asset/chart ikut mengantre dan gagal.

**Perbaikan**

- `app/Support/Globals/services.php`: `realtime_stream_enabled()` — otomatis `false` saat `PHP_SAPI === 'cli-server'`, dan menghormati setting `realtime_stream_enabled`.
- `app/Views/admin/dashboard.php` + `app/Views/layouts/footer.php`: endpoint stream dikosongkan bila nonaktif (fallback polling yang sudah ada).
- `public/assets/js/dashboard.js`: fallback polling eksplisit saat endpoint kosong (sebelumnya default ke `/api/stream`).
- `Settings → General → Live Stream`: toggle baru (setting `realtime_stream_enabled`, default `1`).
- `README.md`: catatan perilaku dev server.

Di Apache/nginx/php-fpm stream tetap aktif seperti sebelumnya.

---

## 6. File yang berubah

**Baru**

```
app/Controllers/Admin/PingTerminalTokenController.php
public/assets/js/fleet-chart.js
public/assets/vendor/                      # bootstrap, tabler-icons, echarts
tests/fleet_history_test.php
tests/history_completeness_test.php
tests/http_contract_test.php
tests/alert_resolution_test.php
```

**Dimodifikasi (utama)**

```
app/Console/MigrateCommand.php
app/Controllers/Admin/{DashboardController,ServerDetailController,PingDetailController,
                       IpReputationDetailController,PingTerminalController,SettingsController}.php
app/Controllers/Api/{StatusController,PushController,PushDiskController,LiveStreamController,EventsController}.php
app/Controllers/Auth/LoginController.php
app/Http/{Request,Response,Router}.php
app/Http/Middleware/{Auth,Admin}.php
app/Services/{AlertService,AuthService,PushAuthService,RetentionService,ServerListService,SettingsService,WorkerService}.php
app/Services/Settings/CronWorkerService.php
app/Support/Cache.php
app/Support/Globals/{auth,services,services_worker}.php
app/Views/admin/{dashboard,settings,ping_monitors}.php
app/Views/layouts/{head,footer,nav}.php
config/bootstrap.php
public/assets/css/components.css
public/assets/js/{common,dashboard,detail,ping-terminal,sidebar}.js
public/install.php
routes/web_ping.php
README.md
tests/{architecture_test,history_payload_test}.php
```

---

## 7. Catatan deployment

1. Jalankan migrasi:
   ```bash
   php migrate.php --status
   php migrate.php
   ```
2. Pastikan `storage/cache/` dapat ditulis (mode file backend).
3. Untuk deployment, set `APP_ENV=production` di `config/local.php` (checkout dev masih `development`).
4. SSE otomatis nonaktif hanya pada `php -S`; di FPM/Apache tetap aktif. Bisa dimatikan permanen lewat **Settings → General → Live Stream**.
5. Jika browser sempat gagal memuat font sebelum perbaikan, lakukan **hard reload** (`Ctrl+Shift+R`).

---

## 8. Verifikasi

```bash
# Seluruh tes (standalone, tanpa PHPUnit)
for f in tests/*.php; do php "$f" || echo "FAIL $f"; done

# Sintaks JS
for f in public/assets/js/*.js; do node --check "$f"; done
```

Hasil:

- Seluruh `tests/*.php` **PASS** (27 file, termasuk 5 tes baru).
- `node --check` OK untuk semua JS.
- Smoke live: login berhasil; `/dashboard`, `/servers`, `/settings` → 200; `/api/status?fleet_history=24h` → 200; **push agent asli** (HMAC + token 64 hex) → 200 dengan baris `metrics`, `latest_metric_id`, dan `last_seen_at` terisi.
- `php migrate.php --status` → 30 migrasi, 0 pending, 0 checksum mismatch.
- Render headless Chrome: ikon Tabler tampil benar di layout aplikasi dan toggle Live Stream muncul di Settings.

---

## 9. Saran lanjutan (belum dikerjakan)

1. **Pangkas data publik** pada `/api/status`: `host`, `type`, `provider`, `label` masih dikirim untuk request anonim.
2. **Idempotensi push**: kunci unik dari agent untuk mencegah duplikasi saat retry (butuh perubahan kontrak agent).
3. **Commit terpisah** per paket (A/B/C/D) agar riwayat mudah ditinjau dan di-revert.