# FCWM Student Complaint & Support Portal

A PHP and MySQL support-ticket portal for Federal College of Wildlife Management. It uses the Bootstrap admin-template assets in the parent `bootstrap` folder and is intended to run locally under XAMPP.

## Requirements

- XAMPP with Apache, MySQL/MariaDB, and PHP 8.1 or later
- PHP PDO MySQL extension enabled

## Install with XAMPP

1. Keep `fcwm-complaints` beside the existing `bootstrap` folder. For example, the supplied workspace works at `http://localhost/free-admin-template/fcwm-complaints/`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/free-admin-template/fcwm-complaints/setup.php` from the same computer.
4. The setup page creates the `fcwm_complaints` database, its tables, the initial departments and topics, and the first administrator. Use a unique password with at least 12 characters.
5. Sign in with the administrator account. Add a staff account for each department from **Department accounts**.
6. Students can register, submit tickets, and track a ticket using its reference number. Administrators route new tickets; department staff can only see tickets assigned to their own department.

The default database settings match a standard local XAMPP installation (`127.0.0.1:3306`, user `root`, blank password). To override them, set `FCWM_DB_HOST`, `FCWM_DB_PORT`, `FCWM_DB_NAME`, `FCWM_DB_USER`, and `FCWM_DB_PASSWORD` in the PHP/Apache environment before starting Apache. The database structure is also documented in `database/schema.sql`.

The one-time installer only accepts requests from `127.0.0.1` or `::1`. It redirects to sign-in after the initial administrator exists.

## Email notifications

Email notifications are enabled by default and use PHP's `mail()` function. A default XAMPP installation may not have outgoing email configured. Configure a trusted SMTP relay in XAMPP's `php.ini` and `sendmail.ini`, restart Apache, and set `FCWM_MAIL_FROM` to a sender address accepted by that relay. Do not put SMTP credentials in public files. If mail delivery fails, the ticket/update is still saved, an explicit warning is shown, and the failure is recorded in the PHP error log; students can always check status in the portal.

## Roles and ticket workflow

- **Students** register with their full name, email, matric number, department, and password. They can create a ticket under Academic, Registration, Finance, Accommodation, Facilities, IT, or Other, follow replies, and track the public status with the private reference.
- **Administrators** can see all tickets, route/unroute them, set status and priority, and create department staff accounts.
- **Department staff** are assigned to a single department and can see/reply to only that department's routed tickets and update case status.
- Passwords are hashed; forms use CSRF protection; database access uses PDO prepared statements; role checks restrict ticket access.

## XAMPP path note

The Bootstrap assets are loaded from `../bootstrap/dist/assets/`. If you move this app out of the provided workspace, copy the Bootstrap `dist/assets` folder next to it in a `bootstrap` directory, or update those two shared-asset paths in `includes/app.php` and `setup.php`.
