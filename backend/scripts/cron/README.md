# Cron worker

See [the setup guide](../../../docs/SETUP.md) for current cron configuration.

Run from the project root:

```sh
php backend/scripts/cron/process-emails.php
```

This is a plain PHP worker; Laravel artisan is not used. No system cron job is installed.
