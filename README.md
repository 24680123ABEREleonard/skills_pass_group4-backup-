# SkillPass

SkillPass is a PHP, HTML, CSS, and JavaScript skills-first hiring demo. Jobseekers build profiles with skills and project evidence, apply for openings, submit practical challenges, and track application updates. Employers post roles, compare applicants against job-related evidence, review challenges, and shortlist candidates.

The request handling and application logic are in [`index.php`](./index.php); the rendered page markup is in [`views/app.php`](./views/app.php), and presentation styles are maintained separately in [`style.css`](./style.css). The view uses PHP only for dynamic, escaped values and role-specific content.

## Software requirements specification

The detailed project baseline, including distinct functional and non-functional requirements, acceptance criteria, and the database ER diagram, is available in [SkillPass_SRS.pdf](./SkillPass_SRS.pdf). Its editable HTML source is [SkillPass_SRS.html](./SkillPass_SRS.html).

## Run with XAMPP

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open phpMyAdmin at `http://localhost/phpmyadmin`.
3. Import [`database.sql`](./database.sql). It creates the `skillpass` database, tables, and sample records. It uses `IF NOT EXISTS` and `INSERT IGNORE`, so importing it again will not drop existing tables.
4. Open `http://localhost/GROUP%203%20PROJECT/` in your browser.
5. If your MySQL settings differ from XAMPP's defaults, edit the connection values at the top of [`config.php`](./config.php), or set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the PHP environment.

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

4. Deploy the PHP service and open its generated public domain.

This project seeds demo accounts with the shared password `Demo123!`. For a public deployment, remove or change those accounts before sharing the site.

## Demo accounts

All demo accounts use the password `Demo123!`.

| Role | Email |
| --- | --- |
| Employer | `employer@skillpass.cm` |
| Jobseeker | `amina@skillpass.cm` |
| Jobseeker | `joel@skillpass.cm` |

You can also register new employer or jobseeker accounts from the sign-in screen.

The site root is the public homepage and explains the skills-first flow before account creation. Its floating WhatsApp button opens a chat with SkillPass at `+237 672 828 084`.

## Matching and privacy

Applicants are ordered by a transparent role-fit score: required-skill overlap contributes up to 70 points, project evidence up to 20, and the practical challenge up to 10. The score is a comparison aid, not an automatic hiring decision. It does not use personal characteristics. Candidate email addresses are only shown to the employer after shortlisting; before that, candidate names are abbreviated.

Skill tags are self-reported. Employers review work samples and challenge submissions as separate evidence and record a pass or feedback. This demo stores passwords as PHP password hashes, uses prepared database statements, and protects form submissions with CSRF tokens.
