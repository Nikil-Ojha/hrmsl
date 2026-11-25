# Realtime RS 9W (`RSS20220619704`) — Local & Live Setup Guide

This guide covers connecting the **Realtime RS 9W (`RS09W`)** biometric terminal to this HRMS in both **Local Development** and **Live Production (`https://arrowbitinfosoft.com/hrms/`)**.

---

## 1. HRMS Device Settings (Identical on Local & Live)

Under **Attendance → Biometric Devices**, configure the device record:

| Field | Value |
|---|---|
| **Serial Number** | `RSS20220619704` |
| **Manufacturer** | `Realtime` |
| **Model** | `RS 9W` |
| **Protocol** | `fkweb` *(FKWeb Realtime Push — switch to `zk_adms` if your firmware sends `/iclock/cdata`)* |
| **Connection Type** | `Wi-Fi` (`wifi`) |
| **Timezone** | `Asia/Kolkata` (`UTC+05:30`) |
| **Auth Type** | `None` (`none`) |
| **Port** | `5005` *(FKWeb default; note that `121` in Realtime Cloud is Realtime's cloud tenant port)* |

---

## 2. Local Office Setup (Direct Push over Office Wi-Fi/LAN)

1. Ensure Laravel is listening on your PC's LAN IP (not only `127.0.0.1`):
   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```
   *(Or bind `192.168.1.17:8000` alongside your existing localhost server.)*
2. Verify `.env` has:
   ```ini
   BIOMETRIC_ALLOW_PRIVATE_PROBE=true
   ```
3. On the **RS 9W physical machine menu**:
   - Go to **Menu → Comm → Cloud Server / Server Setting** (or **Wi-Fi / Push Server**)
   - **Enable Domain Name:** `OFF`
   - **Server Address / IP:** `192.168.1.17` *(your local PC LAN IP)*
   - **Server Port:** `8000`
   - Save and reboot the device.
4. Watch live incoming device traffic:
   ```bash
   tail -f storage/logs/laravel.log | grep -E "Biometric FKWeb|Biometric iClock"
   ```
   - If you see `[Biometric FKWeb Push]` → keep protocol as **`fkweb`**.
   - If you see `[Biometric iClock cdata]` → change device protocol in HRMS to **`zk_adms`**.

---

## 3. Live Setup (`https://arrowbitinfosoft.com/hrms/`)

You have **two clean ways** to connect the RS 9W on live — choose whichever fits your office setup:

### Option A — Direct Push from RS 9W to Live Server

Because live HRMS is hosted in the `/hrms/` subdirectory (`https://arrowbitinfosoft.com/hrms/`), while RS 9W firmware pushes to domain-root paths (`/hdata.aspx`, `/iclock/cdata`, `/`), add these rules to your **domain root `public_html/.htaccess`** (above `/hrms/` and **before** any HTTP→HTTPS redirect rule, because many 2022 RS 9W firmwares only speak plain HTTP on port 80):

```apache
# Forward Realtime RS 9W / FKWeb / ADMS device push requests directly into /hrms/
RewriteEngine On

# 1. FKWeb header-based push to root or /hdata.aspx
RewriteCond %{HTTP:request_code} !^$ [OR]
RewriteCond %{HTTP:dev_id} !^$ [OR]
RewriteCond %{REQUEST_URI} ^/(hdata(\.aspx)?|iclock/.*|cdata|getrequest|devicecmd)$ [NC]
RewriteRule ^(.*)$ /hrms/index.php [L,QSA]
```

Then on the **RS 9W physical machine menu**:
- **Enable Domain Name:** `ON`
- **Server Address / Domain:** `arrowbitinfosoft.com`
- **Server Port:** `80` *(or `443` only if your RS 9W firmware supports HTTPS)*

### Option B — Keep RS 9W on Realtime Cloud (`Realtimeserver1.com:121`) + Webhook to HRMS

If you prefer **not** to change settings on the physical RS 9W (keep it pushing to `Realtimeserver1.com` port `121` as shown in your Realtime Cloud portal):

1. In live `.env`, set:
   ```ini
   BIOMETRIC_API_TOKEN=bd_1amgqblx_fkFYx12DTgyNT3jTOW6mrVfXbaKePx6SBnvqQ0yl
   BIOMETRIC_API_AUTH_REQUIRED=true
   ```
2. In your **OnlineRealsoft / Realtime Cloud portal → Third-Party API Integration**, configure:
   - **Webhook URL:** `https://arrowbitinfosoft.com/hrms/api/attendance-log`
   - **Method:** `POST` (JSON or Form)
   - **Authorization:** `Bearer <BIOMETRIC_API_TOKEN>`
3. Punches pushed to `/api/attendance-log` automatically update `biometric_punches`, `biometric_attendance_logs`, `attendance_records`, and the device's Online status in the Biometric Dashboard.
