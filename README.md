# SkillsPassAurora

A PHP and MySQL two-sided hiring marketplace for candidates and employers. Users can register and sign in without email verification. After 10 incorrect password attempts for an account, the next correct-password sign-in requires an email OTP. Employers can post jobs, review applicants, and select a candidate; new jobs are marked Available and are automatically marked Taken when a candidate is selected. Candidates can browse jobs and apply. Passwords and OTPs are hashed, OTPs expire after 10 minutes, and OTP attempts and resend frequency are limited.

## Requirements

- PHP 8.1+ with `pdo_mysql` enabled
- MySQL 8+
- Composer dependencies installed with `composer install`
- A Gmail account with two-step verification and a Google app password for sending sign-in security codes

## Setup

1. Import `schema.sql` into MySQL. It creates and uses the `aurora_group3` database. If you already have that database, import the file there to create any missing marketplace tables.
2. If you already have a database, re-import `schema.sql` to add any missing columns. It adds the failed-login counter and application cover-letter, experience, and portfolio-link fields without replacing existing records. Existing email-verification columns can remain; they are no longer used.
3. Configure `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` in your environment. Defaults are `127.0.0.1`, `aurora_group3`, `root`, and an empty password.
4. Run `composer install` from this directory to install PHPMailer.
5. For Gmail, enable 2-Step Verification and create an app password in your Google Account. Do not use your regular Gmail password. Set the SMTP environment variables below on the machine running PHP/Apache. `SMTP_PASS` must contain the Google app password; never commit it to source control.
6. From this directory, start the local server with `php -S localhost:8000` and open `http://localhost:8000`.

Windows PowerShell example:

```powershell
$env:DB_USER = 'root'
$env:DB_PASS = 'your-local-password'
$env:SMTP_HOST = 'smtp.gmail.com'
$env:SMTP_PORT = '587'
$env:SMTP_USER = 'your-account@gmail.com'
$env:SMTP_PASS = 'your-google-app-password'
$env:SMTP_FROM = 'your-account@gmail.com'
php -S localhost:8000
```

For XAMPP Apache, add these variables as Windows user or system environment variables, then fully restart Apache from the XAMPP Control Panel. SMTP is only used for login security codes after 10 failed password attempts. Codes expire after 10 minutes, allow at most five attempts, and can be resent no more than once per minute. If SMTP is not configured or Gmail rejects delivery, the app shows an email-delivery message and writes the transport failure to Apache's error log; it does not expose OTP codes.

Employees applying for a job must submit a cover letter. They can also include relevant experience and an HTTP/HTTPS portfolio link. Employers can review the submitted details in their job's Applicants section.
