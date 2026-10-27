# Local setup

Requires PHP 8.2+, PDO SQLite or PDO MySQL, mbstring, cURL, Sodium, DOM, Zip, OpenSSL, and Composer.

1. Run `composer install` from `backend/`.
2. Copy `backend/.env.example` to `backend/.env`.
3. Leave `ADMIN_PASSWORD_HASH` blank for first-time local setup.
4. From the repository root run `Set-Location backend/public` and then `php -S 127.0.0.1:8085 index.php`.
5. Open `http://127.0.0.1:8085` and create your administrator with a password of 6 to 8 characters. Setup is available only on this computer and disables itself after configuration. The password is stored as a hash while preserving the other environment settings.
6. Queue a message and run `php backend/scripts/cron/process-emails.php`. Refresh to see the dry-run result.

SQLite initializes automatically in private storage. For MySQL, create an empty database and configure `DB_CONNECTION=mysql` plus the DB credentials. Versioned migrations should replace automatic table initialization before production.

## Sending

Keep `MAIL_DRY_RUN=true` during setup. Configure SMTP variables and `MAIL_FROM_ADDRESS`, then explicitly set `MAIL_DRY_RUN=false` for real sends. Use provider-approved SMTP credentials. Gmail OAuth authentication is not implemented yet.

The composer supports plain text, links, CC, and BCC. [Google Sheets setup](GOOGLE_SHEETS.md) covers OAuth, saved links, column mapping, live refresh, row editing, and manual queueing. Attachments, multiple user accounts, scheduled sheet processing, Gmail inbox/thread management, forwarding, and automated reply rules remain to be implemented.

## Cron

On a single Linux server:

```cron
* * * * * cd /absolute/path/to/project/backend && /usr/bin/php scripts/cron/process-emails.php >> storage/logs/worker.log 2>&1
```

No system cron job has been installed. The worker processes up to 25 messages, uses a local lock, and claims records atomically. Failed sends require manual review before retry; there are at most three processing attempts.

SMTP cannot guarantee exactly-once delivery. A crash after SMTP acceptance can leave a job processing. Check the mailbox before recovering it manually; never automatically reset processing jobs. Multiple application servers require shared locking and delivery reconciliation.

## Validation and deployment

Run `php backend/tests/run.php`. Serve only `backend/public/` as the web document root; the application routes the allowed frontend assets. Use HTTPS, restricted admin access, and server-level login rate limiting before production. The built-in server is for local development.

