# Walkthrough: Perbaikan & Improvisasi servmon v2

Seluruh perbaikan dan optimasi yang telah direncanakan pada [implementation_plan.md](file:///C:/Users/monitors/.gemini/antigravity-ide/brain/8c046f50-9075-418d-9de7-10bf7e5a6673/implementation_plan.md) telah berhasil diterapkan secara rapi dan aman tanpa merusak struktur kode yang sudah ada.

---

## Ringkasan Perubahan yang Dilakukan

### 1. IP Reputation: Pencegahan False Positive Spamhaus
* **File**: [app/Services/IpReputationService.php](file:///d:/monitors/app/Services/IpReputationService.php)
* **Perubahan**:
  * Menambahkan pendeteksian kode respons Spamhaus `127.255.255.x` (query refusal/open resolver blocked).
  * Jika kode ini terdeteksi, status lookup diklasifikasikan sebagai `failed` (bukan `listed`), sehingga server/IP bersih tidak lagi dilaporkan ter-blacklist saat server monitor menggunakan recursive resolver publik (Google DNS `8.8.8.8` atau Cloudflare `1.1.1.1`).

### 2. Alerting: Cooldown Pasca-Recovery & Auto-Resolve Level Critical
* **File**: [app/Services/AlertService.php](file:///d:/monitors/app/Services/AlertService.php)
* **Perubahan**:
  * **Cooldown Lifecycle**: Pada method `inCooldown()`, jika status alert terakhir sudah `'resolved'`, cooldown tidak memblokir pembuatan alert baru. Hal ini memastikan insiden baru yang sah setelah recovery tetap memicu notifikasi ke admin.
  * **Auto-Resolve Critical Severity**: Pada method `evaluateServerThresholdAlerts()`, saat metrik (CPU, RAM, Disk, Mail Queue) turun dari level *Critical* ke level *High/Warning*, sistem secara otomatis memanggil `resolveConditionAlerts($serverId, ['..._critical'])` agar status alert critical tidak menggantung aktif di dashboard/notification center.

### 3. Server-Sent Events (SSE): Pencegahan Exhaustion Pool PHP-FPM
* **File**: [app/Controllers/Api/LiveStreamController.php](file:///d:/monitors/app/Controllers/Api/LiveStreamController.php)
* **Perubahan**:
  * Menambahkan batas waktu rotasi loop SSE (`$maxDuration = 45` detik).
  * Setelah 45 detik, koneksi ditutup secara rapi sehingga proses worker PHP-FPM dibebaskan untuk melayani request web/API lainnya. Browser `EventSource` secara otomatis melakukan *reconnect* dalam 1–3 detik berikutnya tanpa mengganggu tampilan live UI.

### 4. Dashboard Frontend: Eliminasi Double-Polling
* **File**: [public/assets/js/dashboard.js](file:///d:/monitors/public/assets/js/dashboard.js)
* **Perubahan**:
  * Poller AJAX kini terkoordinasi dengan status koneksi SSE:
    * Saat SSE berhasil terhubung (`open` event), poller di-pause sehingga tidak terjadi pemanggilan ganda (*double-polling*) ke backend setiap 5 detik.
    * Saat SSE terputus atau mengalami error (`onerror`), poller otomatis aktif kembali sebagai fallback yang andal.

### 5. Metrics History API: Deduplikasi Titik Waktu Grafik 7 Hari & 30 Hari
* **File**: [app/Controllers/Api/StatusController.php](file:///d:/monitors/app/Controllers/Api/StatusController.php)
* **Perubahan**:
  * Menambahkan pengelompokan `GROUP BY recorded_at` pada query `7d` dan `30d` yang menggabungkan tabel `metrics` dan `metrics_history`.
  * Menghilangkan titik waktu ganda akibat jeda waktu transisi rollup, sehingga visualisasi grafik garis di frontend tetap mulus dan konsisten.

### 6. Autentikasi: Optimasi Query Sargable Rate Limiting Login
* **File**: [app/Services/AuthService.php](file:///d:/monitors/app/Services/AuthService.php)
* **Perubahan**:
  * Mengganti perbandingan fungsi `TIMESTAMPDIFF(MINUTE, attempted_at, NOW())` dengan cutoff datetime langsung:
    * `attempted_at <= :cutoff` untuk penghapusan attempt lama.
    * `attempted_at > :cutoff` untuk penghitungan limit attempt saat ini.
  * Memungkinkan MySQL memanfaatkan index `(ip_address, attempted_at)` secara optimal (*index range scan*) tanpa table scan.

---

## Verifikasi & Integritas

| File | Status Modifikasi | Integritas Sintaks |
| :--- | :---: | :---: |
| [IpReputationService.php](file:///d:/monitors/app/Services/IpReputationService.php) | Selesai | Aman & Terverifikasi |
| [AlertService.php](file:///d:/monitors/app/Services/AlertService.php) | Selesai | Aman & Terverifikasi |
| [LiveStreamController.php](file:///d:/monitors/app/Controllers/Api/LiveStreamController.php) | Selesai | Aman & Terverifikasi |
| [dashboard.js](file:///d:/monitors/public/assets/js/dashboard.js) | Selesai | Aman & Terverifikasi |
| [StatusController.php](file:///d:/monitors/app/Controllers/Api/StatusController.php) | Selesai | Aman & Terverifikasi |
| [AuthService.php](file:///d:/monitors/app/Services/AuthService.php) | Selesai | Aman & Terverifikasi |
