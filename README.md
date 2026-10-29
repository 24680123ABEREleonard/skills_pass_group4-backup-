# SkillsPassAurora

**Your skills are not useless.** SkillsPassAurora is a PHP and MySQL hiring marketplace for employers and job seekers. Employers can post jobs, review job seekers, and hire from a shared dashboard. Job seekers can browse jobs, apply with a required cover letter, and manage their public profile. User authentication uses secure password hashing and OTP-based sign-in verification after repeated failed attempts. The platform is designed around premium access limits rather than applicant unlock payments.

## System presentation

Open `presentation.html` in a browser to view the 16-slide system walkthrough. Use the slide-number links or side-mounted previous/next controls to navigate. Slides 14–16 show screenshots of the source code for the live dashboard charts, employer job map, and digital wallet. The walkthrough can be printed or saved as PDF from the browser.

## Employer live job map

The employer dashboard displays its job-location map after the job listings. The dark, illustrated world map is inspired by the dashboard design reference and plots only the employer's own jobs. Colored city labels show job counts and availability; selecting a label shows its jobs. Map data refreshes with the dashboard every 30 seconds. The map recognizes common Cameroon city names and accepts a latitude/longitude pair in a job's location field. Unrecognized or remote locations are listed as unmapped instead of being placed inaccurately. The map artwork is rendered locally and does not depend on third-party map tiles.

## Requirements

- PHP 8.1+ with `pdo_mysql` enabled
- MySQL 8+
- Composer dependencies installed with `composer install`
- A Gmail account with two-step verification and a Google app password for sending sign-in security codes

## Setup

1. Import `schema.sql` into MySQL for a fresh installation or to update an existing installation. This is the project's only SQL file; it creates and uses the `aurora_group3` database, includes marketplace and Premium payment tables, and preserves existing records. Its unsigned 64-bit auto-increment IDs support more than one million users; actual capacity depends on database storage and server resources.
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

SMTP is only used for login security codes after 10 failed password attempts. Codes expire after 10 minutes, allow at most five attempts, and can be resent no more than once per minute. If SMTP is not configured or Gmail rejects delivery, the app shows an email-delivery message and writes the transport failure to Apache's error log; it does not expose OTP codes.

## Premium access model

The marketplace enforces premium limits instead of payment-gated applicant unlocks:

- Employers can post up to 8 active jobs on the free plan.
- Job seekers can view up to 8 distinct jobs per month on the free plan.
- Users with `is_premium = 1` and a valid `premium_expires_at` value can exceed those limits.
- A 500 XAF MTN MoMo top-up credits the user's in-app wallet only after the provider confirms the matching successful transaction.
- Wallet funds can be used only to purchase 50 days of Premium; users cannot withdraw wallet funds.
- Premium activates after a transaction atomically debits 500 XAF from the wallet and extends the user's Premium expiry.

## MTN MoMo sandbox wallet top-ups

The current integration is sandbox-only and uses MTN MoMo Collection API. Sandbox top-ups do not charge real money and must not be treated as a live payment service. Configure sandbox credentials in the Apache/PHP environment, never in source files or the database:

```powershell
$env:MTN_MOMO_SUBSCRIPTION_KEY = 'your-sandbox-subscription-key'
$env:MTN_MOMO_API_USER = 'your-sandbox-api-user'
$env:MTN_MOMO_API_KEY = 'your-sandbox-api-key'
```

Create these values through the MTN MoMo developer portal and use a supported Cameroon sandbox test number. The app requests an XAF 500 wallet top-up; after the provider reports `SUCCESSFUL`, it verifies the amount, currency, and external reference before crediting the wallet exactly once. Users then make a separate wallet debit to buy Premium. Pending top-ups remain pending until checked against MTN; a second top-up is blocked while one is pending to reduce duplicate charges. No provider credentials are included in this repository.

Live payments are not enabled. Production requires an approved MTN merchant account, separate live credentials, provider approval, HTTPS, operational monitoring, and a compliance review. The application is intentionally pinned to MTN's sandbox endpoint until that work is completed.

All signed-in users can view and update their account profile and upload a profile picture. Each account has a shareable public profile showing the user's name, account type, member date, and profile picture; email addresses and private application information are not shown on public profiles. Profile pictures must be JPEG, PNG, or WebP, no larger than 2 MB. Re-import `schema.sql` into an existing database to add any missing supported columns and tables without replacing its records.

Job seekers applying for a job must submit a cover letter. They can also include relevant experience and an HTTP/HTTPS portfolio link. Employers can review the submitted details in their job's Applicants section.
