# RSL Communication Desk & Email Automation Platform

A high-performance, lightweight **Email Automation, Inbound Conversation Desk, and Lead Management Platform** built with pure native PHP 8.2+ and MySQL. 

Designed for enterprise reliability with zero heavy framework bloat, sub-millisecond execution times, native Hostinger SMTP/IMAP integration, Google Sheets & Excel lead sync, and an authentic Gmail-style threaded conversation reader.

---

## 🚀 Key Highlights & Capabilities

- **📊 Executive Analytics Overview Dashboard**
  - **7-Day Communication & Response Curve**: Dynamic SVG traffic graph comparing outgoing dispatches against inbound responses with smooth cubic bezier curves and peak window tracking.
  - **Deliverability Status Donut Chart**: Live SVG visualization of accepted dispatches, bounces, and active queue counts.
  - **Hot Conversations & Top Engaged Leads**: Intelligent ranking of contacts with active conversations, reply counts, and instant deep-linking into thread reading.
  - **Real-Time System Health**: Live connectivity and latency indicators for Hostinger SMTP, IMAP Polling, Google Sheets Hub, and MySQL Worker Queue.

- **📬 Authentic Gmail-Style Thread Reader**
  - **In-Place Canvas Navigation**: Seamless reading experience without distracting modal popups.
  - **Thread Grouping & Message Chronology**: Earliest messages collapsed into compact single-line summaries; latest message automatically expanded with clean, native typography.
  - **Quoted History Trimming**: Cleanly identifies and tucks historical reply text (`On ... wrote:`) behind an authentic Gmail `...` toggle button.
  - **Inline Reply Composer**: Send direct threaded replies via Hostinger SMTP with automatic `In-Reply-To`, `References`, and `Re:` subject formatting.
  - **Socket Protocol Cleaning**: Built-in sanitation filters prevent socket protocol tags (e.g. `)\r\nA0004`) from leaking into email bodies.

- **⚡ Hostinger SMTP & IMAP 2-Way Sync Engine**
  - **Live SMTP Sending**: High-deliverability TLS/SSL dispatch on port 465/587 via PHPMailer.
  - **Idempotency Protection**: SHA-256 idempotency hashing ensures duplicate sends are prevented even under concurrent cron execution.
  - **Automated IMAP Inbox Sync**: Direct socket polling over SSL (`imap.hostinger.com:993`) fetching full sender details, recipient copies, thread headers, and unread counts.
  - **Background Worker & Cron Support**: Atomic record locks prevent race conditions during batch queue processing.

- **📑 Lead Integration Hub (Google Sheets & Excel / CSV)**
  - **Google Sheets OAuth 2.0**: Connect directly to Google Sheets with automated refresh token management.
  - **Manual Excel / CSV Importer**: Upload `.xlsx` or UTF-8 `.csv` files directly without third-party dependencies.
  - **Interactive Column Mapping**: Map dynamic spreadsheet headers to recipient email, recipient name, subject line, and custom template tags.
  - **Live Preview & Selective Queueing**: Preview rows, inspect mapped data, and selectively dispatch records into the sending queue.

---

## 📁 Repository Structure

```text
├── database.sql                 # Clean MySQL production schema (structure only - zero data)
├── backend/
│   ├── .env.example             # Production environment template for Hostinger
│   ├── bootstrap.php            # Autoloader, environment parser, error handlers
│   ├── composer.json            # Minimal dependencies (PHPMailer)
│   ├── database.sql             # Duplicate schema copy inside backend
│   ├── app/
│   │   ├── Services/
│   │   │   ├── AdminSetup.php          # Admin authentication & security
│   │   │   ├── Email/
│   │   │   │   ├── ConversationThread.php # Thread grouping, quote trimming, artifact filters
│   │   │   │   ├── EmailProcessor.php     # Queue worker dispatch engine
│   │   │   │   ├── EmailQueue.php         # Queue management & idempotency
│   │   │   │   ├── ImapClient.php         # Hostinger IMAP client & MIME decoder
│   │   │   │   └── Mailer.php             # PHPMailer SMTP client
│   │   │   ├── Google/                 # Google OAuth & Sheets API
│   │   │   └── Sheets/                 # Excel (.xlsx) / CSV parser & mapper
│   │   └── Support/
│   │       └── Database.php            # PDO MySQL connection & schema migrations
│   ├── public/
│   │   └── index.php            # HTTP single-entry point & REST API router
│   ├── scripts/
│   │   ├── check-database.php   # MySQL connection test utility
│   │   ├── check-smtp.php       # SMTP diagnostic utility
│   │   └── cron/
│   │       ├── process-emails.php # Background queue processing worker
│   │       └── sync-inbox.php     # Background IMAP sync worker
│   └── tests/                   # Complete test suite (46 automated checks)
├── frontend/
│   ├── public/
│   │   └── assets/              # Native CSS & ES6 JS (No Node build steps required)
│   │       ├── app.css          # Design system & dark sidebar styling
│   │       ├── app.js           # Single-page controller & SVG chart rendering
│   │       ├── inbox.js         # Gmail thread reader & inline composer logic
│   │       └── sheets.js        # Google Sheets & Excel mapping controller
│   └── resources/views/
│       └── dashboard/index.php  # Main single-page application view
└── docs/
    ├── GOOGLE_SHEETS.md         # Google OAuth setup guide
    └── SETUP.md                 # Local setup & configuration
```

---

## 🔒 Security & Environment Protection

- **`.env` is Git-Ignored**: Real credentials (`backend/.env`) are strictly excluded by `.gitignore` (`**/.env`). Only the clean placeholder template `backend/.env.example` is pushed to GitHub.
- **Data-Free Schema**: `database.sql` contains exclusively `CREATE TABLE` definitions and zero user records, personal emails, or private company data.
- **CSRF & Security Headers**: Built-in CSRF token validation on all state-changing endpoints, strict `SameSite` cookies, `nosniff`, `frame-ancestors 'none'`, and CSP headers.

---

## 🌐 Hostinger Deployment Guide (For Administrator / Boss)

Deploying to **Hostinger** (Shared Web Hosting, Cloud Hosting, or VPS) takes only a few minutes:

### Step 1: Upload Project Files
1. In Hostinger **hPanel**, navigate to **File Manager** (or connect via SFTP).
2. Upload the repository contents to your website directory (e.g. `public_html` or a subdomain folder like `public_html/campaign/`).
3. Set your website **Document Root** in Hostinger to point to:
   ```text
   /public_html/backend/public
   ```
   *(This ensures sensitive backend files, `.env`, and storage folders are protected outside the web root).*

### Step 2: Create & Import the MySQL Database
1. In Hostinger **hPanel**, go to **Databases** > **MySQL Databases**.
2. Create a new database (e.g., `u123456789_email_platform`) and create a database user with full privileges.
3. Open **phpMyAdmin** for that database.
4. Click the **Import** tab.
5. Select `database.sql` from the repository root (or `backend/database.sql`) and click **Go**.
   - *This creates all 6 required InnoDB tables (`emails`, `inbox_emails`, `activity`, `google_connection`, `sheet_sources`, `uploaded_sheets`) in milliseconds.*

### Step 3: Configure Environment Variables
1. In `backend/`, copy `.env.example` to create `.env`:
   ```bash
   cp backend/.env.example backend/.env
   ```
2. Open `backend/.env` in the Hostinger File Manager editor and fill in your details:
   ```ini
   APP_NAME="RSL Communication Desk"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://yourdomain.com
   APP_TIMEZONE=Asia/Kolkata

   # Database Credentials
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=u123456789_email_platform
   DB_USERNAME=u123456789_dbuser
   DB_PASSWORD=YourStrongDatabasePassword

   # Sending Settings
   AUTOMATIC_SENDING_ENABLED=true
   MAIL_DRY_RUN=false

   # Hostinger SMTP Outgoing
   SMTP_HOST=smtp.hostinger.com
   SMTP_PORT=465
   SMTP_USERNAME=your-email@yourdomain.com
   SMTP_PASSWORD=YourEmailPassword
   MAIL_FROM_ADDRESS=your-email@yourdomain.com
   MAIL_FROM_NAME="RSL Communications"

   # Hostinger IMAP Inbound Sync
   IMAP_HOST=imap.hostinger.com
   IMAP_PORT=993
   IMAP_USERNAME=your-email@yourdomain.com
   IMAP_PASSWORD=YourEmailPassword
   ```

### Step 4: Install Hostinger Cron Jobs
Hostinger provides an easy Cron Jobs manager in **hPanel** > **Advanced** > **Cron Jobs**:

1. **Email Dispatch Worker** (runs every minute to send queued emails):
   - **Type**: PHP Command
   - **Schedule**: `* * * * *` (Every minute)
   - **Command**:
     ```bash
     /usr/bin/php /home/uXXXXXXX/public_html/backend/scripts/cron/process-emails.php >> /dev/null 2>&1
     ```

2. **Inbound Email Sync Worker** (polls Hostinger mailbox for replies):
   - **Type**: PHP Command
   - **Schedule**: `*/5 * * * *` (Every 5 minutes)
   - **Command**:
     ```bash
     /usr/bin/php /home/uXXXXXXX/public_html/backend/scripts/cron/sync-inbox.php >> /dev/null 2>&1
     ```

*(Replace `/home/uXXXXXXX/public_html/` with the absolute path displayed at the top of your Hostinger File Manager).*

---

## 💻 Local Development & Testing

### Requirements
- PHP 8.2 or higher (with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `zip`)
- MySQL 8.0+ or MariaDB 10.4+
- Composer (for initial PHPMailer setup)

### Running Locally
1. Install dependencies:
   ```bash
   cd backend
   composer install
   ```
2. Start the built-in development server:
   ```bash
   php -S 127.0.0.1:8085 -t backend/public backend/public/index.php
   ```
3. Open `http://127.0.0.1:8085` in your browser.

### Running Automated Verification Tests
The project includes 46 unit and integration test assertions verifying queue processing, idempotency, email headers, quote trimming, and Google Sheets parsing:
```bash
# Run core email queue and validation checks (25/25 checks)
php backend/tests/run.php

# Run Google Sheets and Excel upload tests (21/21 checks)
php backend/tests/sheets.php
```

---

## 🛠️ Diagnostics & CLI Utilities

Quick terminal commands for testing server connectivity:
```bash
# Verify database connection
php backend/scripts/check-database.php

# Verify Hostinger SMTP sending
php backend/scripts/check-smtp.php

# Run manual queue processing
php backend/scripts/cron/process-emails.php

# Run manual IMAP sync
php backend/scripts/cron/sync-inbox.php
```

---

## 📄 License
Proprietary & Confidential - Developed for **RSL Solution PVT LTD**. All rights reserved.
