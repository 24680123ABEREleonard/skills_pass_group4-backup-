# SkillPass

SkillPass is a PHP, HTML, CSS, and JavaScript skills-first hiring system. Employees (the existing internal `jobseeker` role) create accounts and immediately build profiles, apply for approved openings, submit practical challenges, track applications, and follow employers for news and announcements. Employers create accounts and immediately manage openings, publish updates to their followers, and review candidates. A manually provisioned admin uses a separate dashboard to review job posts, oversee system activity, and mediate messages between both sides. Employers retain responsibility for application reviews and hiring decisions.

The request handling and application logic are in [`index.php`](./index.php); the rendered page markup is in [`views/app.php`](./views/app.php), and presentation styles are maintained separately in [`style.css`](./style.css). Employees can follow active employers from the Employer updates page, while employers publish a titled update to their followers from Company updates. The view uses PHP only for dynamic, escaped values and role-specific content.

## Software requirements specification

The detailed project baseline, including distinct functional and non-functional requirements, acceptance criteria, and the database ER diagram, is available in [SkillPass_SRS.pdf](./SkillPass_SRS.pdf). Its editable HTML source is [SkillPass_SRS.html](./SkillPass_SRS.html).

## Run with XAMPP

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open phpMyAdmin at `http://localhost/phpmyadmin`.
3. Import [`database.sql`](./database.sql). This is the single SQL file for SkillPass: it creates the `skillpass` database, all tables and sample records, and applies the admin approval schema to existing installations. It uses `IF NOT EXISTS` and `INSERT IGNORE`; importing it again does not drop existing tables or records.
4. Install Composer if needed, then run `composer install` from the project folder to install the mail dependency.
5. Copy [`.env.example`](./.env.example) to `.env` in the project folder and replace the example SMTP values with credentials and a verified sender from your email provider. SkillPass reads this private local file automatically; no Apache restart is needed. The `.env` file is ignored by Git and blocked from direct web requests.
6. Open `http://localhost/GROUP%203%20PROJECT/` in your browser.
7. If your MySQL settings differ from XAMPP's defaults, edit the connection values at the top of [`config.php`](./config.php), or set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the PHP environment.

### Updating an existing database

Back up the existing database, then import [`database.sql`](./database.sql) into phpMyAdmin. The same file creates missing tables, including employer follows and update feeds, and updates the account/job status enums; existing pending employee and employer accounts are activated, and existing open/closed jobs keep their current statuses. New employee and employer accounts are active immediately. New job posts remain unpublished until approved.

Create the first admin manually; there is intentionally no public admin registration or seeded admin password:

1. Generate a password hash using PHP CLI, replacing the example with a unique password you will store securely:
   `php -r "echo password_hash('replace-with-a-unique-password', PASSWORD_DEFAULT), PHP_EOL;"`
2. In phpMyAdmin, run the following after replacing the values, especially `PASTE_GENERATED_HASH_HERE`:
   ```sql
   INSERT INTO users (name, email, password_hash, role, status)
   VALUES ('SkillPass Administrator', 'admin@example.com', 'PASTE_GENERATED_HASH_HERE', 'admin', 'active');
   ```
3. Sign in using the email and original password used to generate the hash. Add additional admins only by repeating this controlled database process.

The [`database.sql`](./database.sql) file creates the role, account/job status, and message-center schema for a brand-new database and upgrades existing installations. The sample employer and employee accounts and their existing open roles are active and preserved for demonstration. Employee dashboards are available at [`employee-dashboard.php`](./employee-dashboard.php). Sign in with the manually provisioned admin account to open its separate dashboard at [`admin-dashboard.php`](./admin-dashboard.php). Change or remove demo credentials before a public deployment.

## Deploy on Railway

1. Create a Railway project from this repository. Railway will build the root `Dockerfile` and provide the web service's `PORT`.
2. Add a Railway MySQL service and import [`database.sql`](./database.sql). It creates and selects a database named `skillpass`.
3. In the PHP service, add these variables using references to the MySQL service. Replace `MySQL` with that service's exact name if needed:

	| Variable | Railway reference |
	| --- | --- |
	| `DB_HOST` | `${{MySQL.MYSQLHOST}}` |
	| `DB_PORT` | `${{MySQL.MYSQLPORT}}` |
	| `DB_NAME` | `skillpass` |
	| `DB_USER` | `${{MySQL.MYSQLUSER}}` |
	| `DB_PASSWORD` | `${{MySQL.MYSQLPASSWORD}}` |

4. Add the SMTP settings for your verified email sender as described below.
5. Deploy the PHP service and open its generated public domain.

### Password reset email settings

Password reset codes are sent through SMTP. For XAMPP, copy [`.env.example`](./.env.example) to `.env` and set the variables there. For Gmail, enable 2-Step Verification and create a Google App Password; use that 16-character App Password, not your regular Gmail password. Set both `MAIL_USERNAME` and `MAIL_FROM_ADDRESS` to the Gmail address authorized to send. On Railway, configure these values in the PHP service's environment settings. Never share email credentials in chat or commit them to the repository.

| Variable | Purpose | Example |
| --- | --- | --- |
| `MAIL_HOST` | SMTP server hostname | `smtp.gmail.com` |
| `MAIL_PORT` | SMTP port | `587` |
| `MAIL_USERNAME` | SMTP account username | Your full Gmail address |
| `MAIL_PASSWORD` | SMTP account app password | Store as a secret |
| `MAIL_ENCRYPTION` | Connection encryption: `tls` or `ssl` | `tls` |
| `MAIL_FROM_ADDRESS` | Verified sender address | Your Gmail address |
| `MAIL_FROM_NAME` | Sender display name | `SkillPass` |

The forgot-password form deliberately gives the same response for registered and unregistered email addresses. Codes expire after 15 minutes, are stored hashed, allow at most five verification attempts, and can be requested once per account per minute. Import [`database.sql`](./database.sql) after deploying this update so the reset-code table exists. If email is not arriving, verify the SMTP hostname, port, encryption, provider-issued credentials, and verified sender address in `.env`, then check the Apache error log for delivery errors.

This project seeds demo accounts with the shared password `Demo123!`. For a public deployment, remove or change those accounts before sharing the site.

## Demo accounts

All demo accounts use the password `Demo123!`.

| Role | Email |
| --- | --- |
| Employer | `employer@skillpass.cm` |
| Jobseeker | `amina@skillpass.cm` |
| Jobseeker | `joel@skillpass.cm` |

Select **Get started** to create an account as an employee or employer. Both account types can sign in immediately after registration. Job posts still require admin approval before they are published. The admin dashboard is a separate, admin-only page and admin accounts are provisioned manually.

The site root is the public homepage and explains the skills-first flow before account creation. Its floating WhatsApp button opens a chat with SkillPass at `+237 672 828 084`.

## Matching and privacy

Applicants are ordered by a transparent role-fit score: required-skill overlap contributes up to 70 points, each project sample adds 5 points up to 20, and a submitted practical challenge adds 5 points or a passed challenge adds 10. The score is a comparison aid, not an automatic hiring decision. It does not use personal characteristics. Candidate email addresses are only shown to the employer after shortlisting; before that, candidate names are abbreviated.

Skill tags are self-reported. Employers review work samples and challenge submissions as separate evidence and record a pass or feedback. This demo stores passwords as PHP password hashes, uses prepared database statements, and protects form submissions with CSRF tokens.
