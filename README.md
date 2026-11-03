# WorkFlow Tracker — PHP + MariaDB

Responsive task-tracking application using the **existing** database structure in `fr84tvk69sit_fawftracking.sql`. This delivery intentionally does **not** bundle the original SQL dump: that dump contains user accounts, hashes, email addresses and historical data.

## Included
- Login via original `users.username` or `users.email` and `password_verify()` against existing password hashes.
- Dashboard: tasks, progress, overdue counts, task list, mobile cards, due-date calendar.
- Self-created tasks, role-scoped delegation, project link, priority and comments.
- Task lifecycle `ได้รับมอบหมาย → กำลังทำ → ตรวจงาน → เสร็จสิ้น` with reviewer return.
- Notification inbox: assigned, comments, status changes, due soon and overdue.
- SweetAlert2 confirmations/toasts, AOS scroll animation, jQuery AJAX, responsive layout.
- Original `works`, `projects`, `users`, `departments`, `activity_log`, `status_history` preserved.

## Installation on DirectAdmin / Apache + PHP 8.1+ + MariaDB
1. **Backup the existing production database** first. Do not import the original SQL dump into production a second time.
2. Import `database/migration.sql` into the **existing** database using phpMyAdmin.
3. Upload the folder to a document root (for example a subdomain root). Put `config` and `cron` **outside** document root if possible; provided `.htaccess` blocks browser access on Apache.
4. Copy `config/config.example.php` to `config/config.php`, update database credentials and use a strong random `cron_secret` (unused in CLI mode).
5. Make sure PHP has `pdo_mysql`, `mbstring`, `openssl`, and session support. Browse to `index.php` and sign in using an existing active user.
6. Set cron once a day (example at 08:00 Bangkok time): `0 8 * * * /usr/local/bin/php /ABSOLUTE/PATH/cron/reminders.php` (check PHP CLI executable path with hosting provider). The PHP timezone is set to Asia/Bangkok; make sure your crontab time zone matches expected execution time.
7. Enable HTTPS. Check Console/Network for blocked CDN resources and self-host pinned copies of jQuery, SweetAlert2, AOS and fonts for fully offline operation.

## Important usage and implementation notes
- Current `works.due_date` is a **DATE**, so overdue reminders operate by day, **not hour/minute**. To support deadline times, introduce a `DATETIME` field in a future migration.
- `task_notifications` and `task_comments` are additive tables; existing `notification_log` is retained unchanged for compatibility with other integrations.
- Employees can create tasks for themselves. Admin/superadmin/CEO can assign to active users; managers can assign within their own department or direct reports; HR to their direct reports. Task visibility includes creators, assignees, and authorized managers/admins.
- Review approvals require privileged roles (including the creator when privileged). This means employee self-created tasks can be submitted for review but need an authorized manager to approve. Workflows requiring self-approval should be configured separately.
- While the page is open, the browser polls for notifications every 60 seconds. **SweetAlert2 does not deliver notifications when the page is closed**. Background alerts need email, LINE Messaging API or web push integration.
- This is an initial deployable version, not a completed production security audit. Before external deployment add rate limits on login, server-side pagination, audit/monitoring and a backup/restore drill.
- `config/config.php` contains secrets: never commit it to a public repo.
- `status_history` logs status updates. `activity_log` logs login, logout, task create and status updates.

## File structure
```
index.php
api/index.php
assets/style.css
assets/app.js
config/bootstrap.php
config/config.example.php
cron/reminders.php
database/migration.sql
```
