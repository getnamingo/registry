# ntfy Error Notifications

Namingo includes `automation/ntfy_error_notifier.php`, an optional job that
checks the registry's `error_log` table and publishes new high-severity
events to an [ntfy](https://ntfy.sh) topic. You can use ntfy.sh or your own ntfy
server. No separate notifier checkout or database migration is required.

The notifier supports MariaDB and PostgreSQL through the existing automation
database connection. Each run handles up to 500 matching events, in ID order.
It records progress after each successful notification, so a failed HTTP
delivery is retried on the next run. Parallel runs are prevented by a file
lock. Because delivery and writing the cursor are separate operations, a
process interruption between them may produce a duplicate notification.

## Configuration

1. Create a hard-to-guess ntfy topic, and subscribe to it using an ntfy mobile
   app or web client. Topics on public ntfy.sh are not private by default:
   anyone who knows the topic can subscribe. Consider authenticated,
   self-hosted ntfy for sensitive operational messages.
2. Edit `/opt/registry/automation/config.php`:

   ```php
   'ntfy_topic' => 'https://ntfy.sh/replace-with-a-long-random-topic',
   'ntfy_token' => '', // Optional: access token for an authenticated server
   'ntfy_min_level' => 400, // 400 ERROR, 500 CRITICAL, 550 ALERT, 600 EMERGENCY
   'ntfy_last_id_file' => '/var/log/namingo/ntfy-last-id',
   ```

   The 1.0.37 upgrade adds these optional settings to existing installations
   without overwriting their values. New installations already have them in
   `automation/config.php.dist`. If your ntfy server requires authentication,
   set `ntfy_token` to its bearer access token and use HTTPS.
3. Ensure the configured cursor directory exists and is writable by the user
   running the job. Namingo normally creates `/var/log/namingo` for this
   purpose. Keep the cursor file private and persistent across restarts.

The notifier is disabled when `ntfy_topic` is empty. By default only events
at Monolog level 400 (ERROR) or above are sent. Notifications contain the
original error message, level and timestamp; those messages may contain
operationally sensitive information.

## First run

```bash
sudo -u www-data php /opt/registry/automation/ntfy_error_notifier.php
```

The first run **initializes the cursor to the newest existing error** and
does not notify about historical errors. Generate a new test error through a
normal registry workflow, then run the command again to confirm delivery.
Failures are logged to `/var/log/namingo/ntfy_error_notifier.log` and cause
a nonzero exit status. Check that log if a notification does not arrive.

## Cron

The notifier is deliberately opt-in and not automatically scheduled by
`automation/cron.php`. To check once per minute on a package installation,
edit the `www-data` crontab:

```bash
sudo crontab -u www-data -e
```

Add:

```cron
* * * * * /usr/bin/php /opt/registry/automation/ntfy_error_notifier.php >/dev/null 2>&1
```

For Docker, run it through the existing application container (for example,
`docker compose exec -T automation php /opt/registry/automation/ntfy_error_notifier.php`)
from a host cron job if desired. Configure the topic in the automation
configuration override so it survives container recreation; see
[Docker configuration](docker.md). Keep only one scheduling mechanism active.

If a run fails, the last successfully delivered event remains checkpointed;
the remaining events are retried on the next invocation. Investigate repeated
failures rather than deleting the cursor file, because deleting it establishes
a new baseline and skips historical messages.
