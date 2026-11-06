<#
.SYNOPSIS
    Automated Hostinger Deployment Script for RSL Communication Desk (WinSCP FTP)

.DESCRIPTION
    Uploads backend and frontend application files to the live Hostinger production server.
    Uses WinSCP CLI with the pre-saved session 'u105084344.mailpilot@89.117.188.34'.

.USAGE
    powershell -ExecutionPolicy Bypass -File backend\scripts\deploy_hostinger.ps1
#>

$winscpPath = "C:\Program Files (x86)\WinSCP\WinSCP.com"
if (-not (Test-Path $winscpPath)) {
    Write-Error "WinSCP not found at $winscpPath. Please verify WinSCP is installed."
    exit 1
}

$workspaceRoot = (Get-Item $PSScriptRoot).Parent.Parent.FullName
$tempScript = Join-Path $PSScriptRoot "winscp_run.tmp"

Write-Host "===================================================" -ForegroundColor Cyan
Write-Host "   RSL Communication Desk - Hostinger Deployer     " -ForegroundColor Green
Write-Host "===================================================" -ForegroundColor Cyan
Write-Host "Workspace Root: $workspaceRoot"
Write-Host "Target Server:  89.117.188.34 (u105084344.mailpilot)"
Write-Host ""

# Construct the WinSCP command sequence
$commands = @"
open "u105084344.mailpilot@89.117.188.34"
option batch abort
option confirm off

# --- Email Services ---
cd /backend/app/Services/Email
lcd "$workspaceRoot\backend\app\Services\Email"
put ConversationThread.php
put EmailHistory.php
put EmailProcessor.php
put ImapClient.php
put Mailer.php
put EmailQueue.php

# --- Core Services ---
cd /backend/app/Services
lcd "$workspaceRoot\backend\app\Services"
put DashboardService.php
put MailAccountService.php
put UserService.php
put AdminSetup.php

# --- Sheet Services ---
cd /backend/app/Services/Sheets
lcd "$workspaceRoot\backend\app\Services\Sheets"
put SheetService.php
put UploadReader.php
put UploadedWorkbook.php
put WhatsAppSheetService.php

# --- WhatsApp Services ---
option batch continue
mkdir /backend/app/Services/WhatsApp
option batch abort
cd /backend/app/Services/WhatsApp
lcd "$workspaceRoot\backend\app\Services\WhatsApp"
put DeviceLink.php
put PlatformSettings.php
put WhatsAppChatService.php
put WhatsAppInbox.php
put WhatsAppOverviewService.php
put WhatsAppSentService.php
put WhatsAppTables.php

# --- Google Services ---
cd /backend/app/Services/Google
lcd "$workspaceRoot\backend\app\Services\Google"
put GoogleClient.php

# --- Support ---
cd /backend/app/Support
lcd "$workspaceRoot\backend\app\Support"
put Database.php

# --- Backend Public ---
cd /backend/public
lcd "$workspaceRoot\backend\public"
put index.php
put migrate.php
put .user.ini

# --- Routes ---
cd /backend/routes
lcd "$workspaceRoot\backend\routes"
put sheets.php
put whatsapp_sheets.php
put whatsapp_device.php
put whatsapp_overview.php
put whatsapp_send.php
put whatsapp_sent.php
put whatsapp_chats.php
put whatsapp_settings.php

# --- Frontend Assets ---
cd /frontend/public/assets
lcd "$workspaceRoot\frontend\public\assets"
put app.css
put app.js
put inbox.js
put sheets.js
put wa-sheets.js
put wa-sheets.css
put wa-device.js
put wa-overview.js
put wa-sent.js
put wa-chats.js

# --- Dashboard View ---
cd /frontend/resources/views/dashboard
lcd "$workspaceRoot\frontend\resources\views\dashboard"
put index.php

exit
"@

Set-Content -Path $tempScript -Value $commands -Encoding ASCII

try {
    Write-Host "Connecting to Hostinger and uploading files..." -ForegroundColor Yellow
    & $winscpPath /script="$tempScript"
    if ($LASTEXITCODE -eq 0) {
        Write-Host ""
        Write-Host "Deployment completed successfully! Live site is updated." -ForegroundColor Green
    } else {
        Write-Host ""
        Write-Host "Deployment exited with code $LASTEXITCODE. Check connection/credentials." -ForegroundColor Red
    }
} finally {
    if (Test-Path $tempScript) {
        Remove-Item $tempScript -Force -ErrorAction SilentlyContinue
    }
}
