<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

$notice = '';
$noticeType = 'success';
$requestedPage = isset($_GET['page']) ? (string) $_GET['page'] : '';
$view = in_array($requestedPage, ['auth', 'jobs'], true) ? $requestedPage : 'landing';
$authMode = isset($_GET['mode']) && $_GET['mode'] === 'signup' ? 'signup' : 'login';
$signupRole = isset($_GET['role']) && in_array($_GET['role'], ['candidate', 'employer'], true)
    ? (string) $_GET['role']
    : '';
$pendingEmail = $_SESSION['pending_login_email'] ?? '';
$jobs = [];
$jobsLoaded = false;
$applicationsByJob = [];
$candidateApplications = [];

function send_login_code(string $email, string $code): bool
{
    $username = getenv('SMTP_USER') ?: '';
    $password = getenv('SMTP_PASS') ?: '';
    if ($username === '' || $password === '') {
        error_log('SkillsPassAurora sign-in code was not sent: SMTP_USER and SMTP_PASS are not configured.');
        return false;
    }

    $subject = 'Your SkillsPassAurora sign-in code';
    $message = "Your SkillsPassAurora sign-in code is {$code}. It expires in 10 minutes.";

    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom(getenv('SMTP_FROM') ?: $username, 'SkillsPassAurora');
        $mailer->addAddress($email);
        $mailer->Subject = $subject;
        $mailer->Body = $message;
        $mailer->send();
        return true;
    } catch (MailerException $exception) {
        error_log('SkillsPassAurora sign-in code email could not be sent: ' . $mailer->ErrorInfo);
        return false;
    }
}

function start_user_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'display_name' => (string) $user['display_name'],
    ];
    unset($_SESSION['pending_login_user_id'], $_SESSION['pending_login_email']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'login' || $action === 'register') {
        $authMode = $action === 'register' ? 'signup' : 'login';
    }
    if ($action === 'register') {
        $submittedRole = (string) ($_POST['role'] ?? '');
        $signupRole = in_array($submittedRole, ['candidate', 'employer'], true) ? $submittedRole : '';
    }

    try {
        if ($action === 'register') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? '');
            $displayName = trim((string) ($_POST['display_name'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['candidate', 'employer'], true) || $displayName === '') {
                throw new RuntimeException('Enter a valid email, account type, and name.');
            }
            if (strlen($password) < 10) {
                throw new RuntimeException('Use a password with at least 10 characters.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'INSERT INTO users (email, password_hash, role, display_name)
                 VALUES (:email, :password_hash, :role, :display_name)'
            );
            $statement->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'display_name' => $displayName,
            ]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->commit();

            start_user_session([
                'id' => $userId,
                'email' => $email,
                'role' => $role,
                'display_name' => $displayName,
            ]);
            $notice = 'Your account has been created.';
            $view = 'dashboard';
        } elseif ($action === 'verify') {
            $userId = filter_var($_SESSION['pending_login_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) {
                throw new RuntimeException('Sign in with your password before requesting a sign-in code.');
            }
            $code = trim((string) ($_POST['otp'] ?? ''));
            $statement = db()->prepare('SELECT id, email, role, display_name, otp_hash, otp_expires_at, otp_attempts FROM users WHERE id = :id');
            $statement->execute(['id' => $userId]);
            $user = $statement->fetch();

            if (!$user || $user['otp_hash'] === null || $user['otp_expires_at'] === null
                || (int) $user['otp_attempts'] >= 5
                || strtotime((string) $user['otp_expires_at'] . ' UTC') <= time()
                || !password_verify($code, (string) $user['otp_hash'])) {
                if ($user && (int) $user['otp_attempts'] < 5) {
                    db()->prepare('UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = :id')->execute(['id' => $user['id']]);
                }
                throw new RuntimeException('That code is invalid or expired. Request a new code and try again.');
            }

            $statement = db()->prepare(
                'UPDATE users
                 SET failed_login_attempts = 0, otp_hash = NULL, otp_expires_at = NULL, otp_attempts = 0
                 WHERE id = :id AND otp_hash = :otp_hash AND otp_attempts < 5'
            );
            $statement->execute(['id' => $user['id'], 'otp_hash' => $user['otp_hash']]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('That code is invalid or expired. Request a new code and try again.');
            }
            start_user_session($user);
            $notice = 'Sign-in verified.';
            $view = 'dashboard';
        } elseif ($action === 'resend') {
            $userId = filter_var($_SESSION['pending_login_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($userId === false) {
                throw new RuntimeException('Sign in with your password before requesting a sign-in code.');
            }
            $statement = db()->prepare('SELECT id, email, otp_last_sent_at FROM users WHERE id = :id');
            $statement->execute(['id' => $userId]);
            $user = $statement->fetch();
            if (!$user) {
                unset($_SESSION['pending_login_user_id'], $_SESSION['pending_login_email']);
                throw new RuntimeException('The sign-in request expired. Please sign in again.');
            }
            if ($user['otp_last_sent_at'] !== null && strtotime((string) $user['otp_last_sent_at'] . ' UTC') > time() - 60) {
                throw new RuntimeException('Please wait one minute before requesting another code.');
            }

            $code = (string) random_int(100000, 999999);
            db()->prepare(
                'UPDATE users SET otp_hash = :otp_hash, otp_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), otp_last_sent_at = UTC_TIMESTAMP(), otp_attempts = 0 WHERE id = :id'
            )->execute(['otp_hash' => password_hash($code, PASSWORD_DEFAULT), 'id' => $user['id']]);
            if (!send_login_code((string) $user['email'], $code)) {
                throw new RuntimeException('Email delivery failed. Check the SMTP settings and try again after one minute.');
            }
            $notice = 'A new sign-in code has been sent.';
            $view = 'verify';
        } elseif ($action === 'login') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT id, email, password_hash, role, display_name, failed_login_attempts, otp_hash, otp_expires_at, otp_last_sent_at FROM users WHERE email = :email FOR UPDATE');
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if (!$user || !password_verify($password, (string) $user['password_hash'])) {
                if ($user) {
                    $pdo->prepare(
                        'UPDATE users SET failed_login_attempts = LEAST(failed_login_attempts + 1, 10) WHERE id = :id'
                    )->execute(['id' => $user['id']]);
                }
                $pdo->commit();
                throw new RuntimeException('Email or password is incorrect.');
            }

            if ((int) $user['failed_login_attempts'] >= 10) {
                session_regenerate_id(true);
                $_SESSION['pending_login_user_id'] = (int) $user['id'];
                $_SESSION['pending_login_email'] = (string) $user['email'];
                $pendingEmail = (string) $user['email'];
                $view = 'verify';
                $activeCode = $user['otp_hash'] !== null && $user['otp_expires_at'] !== null
                    && strtotime((string) $user['otp_expires_at'] . ' UTC') > time();
                if ($activeCode) {
                    $pdo->commit();
                    $notice = 'Enter the sign-in code sent to your email.';
                } else {
                    $lastSentAt = $user['otp_last_sent_at'] === null ? false : strtotime((string) $user['otp_last_sent_at'] . ' UTC');
                    if ($lastSentAt !== false && $lastSentAt > time() - 60) {
                        $pdo->commit();
                        $notice = 'Please check your email for the sign-in code, or wait one minute before requesting another.';
                        $noticeType = 'error';
                    } else {
                        $code = (string) random_int(100000, 999999);
                        $pdo->prepare(
                            'UPDATE users SET otp_hash = :otp_hash, otp_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), otp_last_sent_at = UTC_TIMESTAMP(), otp_attempts = 0 WHERE id = :id'
                        )->execute(['otp_hash' => password_hash($code, PASSWORD_DEFAULT), 'id' => $user['id']]);
                        $pdo->commit();
                        if (send_login_code((string) $user['email'], $code)) {
                            $notice = 'Too many incorrect password attempts. A sign-in code was sent to your email.';
                        } else {
                            $notice = 'Too many incorrect password attempts. Email delivery failed; check the SMTP settings and request a new code after one minute.';
                            $noticeType = 'error';
                        }
                    }
                }
            } else {
                $pdo->prepare('UPDATE users SET failed_login_attempts = 0 WHERE id = :id')->execute(['id' => $user['id']]);
                $pdo->commit();
                start_user_session($user);
                $view = 'dashboard';
            }
        } elseif ($action === 'create_job') {
            $user = signed_in_user();
            if ($user === null || $user['role'] !== 'employer') {
                throw new RuntimeException('Only signed-in employers can post jobs.');
            }

            $pdo = db();
            $statement = $pdo->prepare(
                'SELECT id FROM users
                 WHERE id = :id AND email = :email AND role = :role'
            );
            $statement->execute([
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => 'employer',
            ]);
            $employerId = $statement->fetchColumn();
            if ($employerId === false) {
                $statement = $pdo->prepare(
                    'SELECT id FROM users WHERE email = :email AND role = :role'
                );
                $statement->execute([
                    'email' => $user['email'],
                    'role' => 'employer',
                ]);
                $employerId = $statement->fetchColumn();
            }
            if ($employerId === false) {
                throw new RuntimeException('Your employer account could not be found. Sign out and sign in again, then retry.');
            }
            $user['id'] = (int) $employerId;
            $_SESSION['user']['id'] = $user['id'];

            $title = trim((string) ($_POST['title'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $employmentType = (string) ($_POST['employment_type'] ?? '');
            $description = trim((string) ($_POST['description'] ?? ''));
            $skills = trim((string) ($_POST['skills'] ?? ''));
            if (strcasecmp($title, 'software expert') === 0) {
                $title = 'Software Expert';
            }

            if ($title === '' || strlen($title) > 150 || $location === '' || strlen($location) > 150
                || !in_array($employmentType, ['full-time', 'part-time', 'contract', 'temporary'], true)
                || $description === '' || strlen($description) > 10000 || strlen($skills) > 2000) {
                throw new RuntimeException('Enter a valid job title, location, job type, and description.');
            }

            $statement = db()->prepare(
                'INSERT INTO jobs (employer_id, title, location, employment_type, description, skills)
                 VALUES (:employer_id, :title, :location, :employment_type, :description, :skills)'
            );
            $statement->execute([
                'employer_id' => $user['id'],
                'title' => $title,
                'location' => $location,
                'employment_type' => $employmentType,
                'description' => $description,
                'skills' => $skills !== '' ? $skills : null,
            ]);
            $notice = 'Your job has been posted and is now available to candidates.';
            $view = 'dashboard';
        } elseif ($action === 'apply_job') {
            $user = signed_in_user();
            if ($user === null || $user['role'] !== 'candidate') {
                throw new RuntimeException('Only signed-in candidates can apply for jobs.');
            }

            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($jobId === false) {
                throw new RuntimeException('Choose a valid job before applying.');
            }

            $coverLetter = trim((string) ($_POST['cover_letter'] ?? ''));
            $relevantExperience = trim((string) ($_POST['relevant_experience'] ?? ''));
            $portfolioUrl = trim((string) ($_POST['portfolio_url'] ?? ''));
            $portfolioScheme = strtolower((string) parse_url($portfolioUrl, PHP_URL_SCHEME));
            if ($coverLetter === '' || strlen($coverLetter) > 5000
                || strlen($relevantExperience) > 3000
                || ($portfolioUrl !== '' && (
                    strlen($portfolioUrl) > 2048
                    || !filter_var($portfolioUrl, FILTER_VALIDATE_URL)
                    || !in_array($portfolioScheme, ['http', 'https'], true)
                ))) {
                throw new RuntimeException('Add a cover letter of up to 5,000 characters. Experience is limited to 3,000 characters, and portfolio links must be valid HTTP or HTTPS URLs.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT status FROM jobs WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $jobId]);
            if ($statement->fetchColumn() !== 'available') {
                throw new RuntimeException('This job is no longer available.');
            }

            $statement = $pdo->prepare(
                'INSERT INTO applications (job_id, candidate_id, cover_letter, relevant_experience, portfolio_url)
                 VALUES (:job_id, :candidate_id, :cover_letter, :relevant_experience, :portfolio_url)'
            );
            $statement->execute([
                'job_id' => $jobId,
                'candidate_id' => $user['id'],
                'cover_letter' => $coverLetter,
                'relevant_experience' => $relevantExperience !== '' ? $relevantExperience : null,
                'portfolio_url' => $portfolioUrl !== '' ? $portfolioUrl : null,
            ]);
            $pdo->commit();
            $notice = 'Your application has been sent to the employer.';
            $view = 'dashboard';
        } elseif ($action === 'hire_candidate') {
            $user = signed_in_user();
            if ($user === null || $user['role'] !== 'employer') {
                throw new RuntimeException('Only signed-in employers can select a candidate.');
            }

            $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $candidateId = filter_var($_POST['candidate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($jobId === false || $candidateId === false) {
                throw new RuntimeException('Choose a valid job and applicant.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT status FROM jobs WHERE id = :id AND employer_id = :employer_id FOR UPDATE');
            $statement->execute(['id' => $jobId, 'employer_id' => $user['id']]);
            if ($statement->fetchColumn() !== 'available') {
                throw new RuntimeException('This job is unavailable or does not belong to your employer account.');
            }

            $statement = $pdo->prepare(
                "UPDATE applications SET status = 'accepted'
                 WHERE job_id = :job_id AND candidate_id = :candidate_id AND status = 'pending'"
            );
            $statement->execute(['job_id' => $jobId, 'candidate_id' => $candidateId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('That candidate does not have a pending application for this job.');
            }

            $statement = $pdo->prepare(
                "UPDATE jobs SET status = 'taken', selected_candidate_id = :candidate_id
                 WHERE id = :job_id AND employer_id = :employer_id AND status = 'available'"
            );
            $statement->execute([
                'candidate_id' => $candidateId,
                'job_id' => $jobId,
                'employer_id' => $user['id'],
            ]);
            $pdo->prepare("UPDATE applications SET status = 'rejected' WHERE job_id = :job_id AND status = 'pending'")
                ->execute(['job_id' => $jobId]);
            $pdo->commit();

            $notice = 'Candidate selected. The job is now marked Taken and other pending applications are closed.';
            $view = 'dashboard';
        } elseif ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            $notice = 'You have signed out.';
        }
    } catch (PDOException $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $driverErrorCode = (string) ($exception->errorInfo[1] ?? 'unknown');
        error_log(sprintf(
            'SkillsPassAurora database action "%s" failed (SQLSTATE %s, driver error %s).',
            $action,
            (string) $exception->getCode(),
            $driverErrorCode
        ));
        $notice = match ([$action, (string) $exception->getCode()]) {
            ['register', '23000'] => 'An account already exists for that email.',
            ['apply_job', '23000'] => 'You have already applied for this job.',
            ['create_job', '42S02'] => 'The jobs table is missing. Import schema.sql into the configured database, then try again.',
            ['create_job', '42S22'] => 'The jobs table does not match the current schema. Update it using schema.sql, then try again.',
            default => 'The request could not be completed. Check the database setup and try again.',
        };
        $noticeType = 'error';
        $view = $action === 'login' || $action === 'register' ? 'auth' : $view;
    } catch (RuntimeException $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $notice = $exception->getMessage();
        $noticeType = 'error';
        $view = $action === 'verify' || $action === 'resend' ? 'verify' : ($action === 'login' || $action === 'register' ? 'auth' : $view);
    }
}

if ($view === 'auth' && isset($_SESSION['pending_login_user_id'])) {
    $view = 'verify';
}
if ($view === 'verify' && !isset($_SESSION['pending_login_user_id'])) {
    $view = 'auth';
}
if (signed_in_user() !== null) {
    $view = $view === 'jobs' ? 'jobs' : 'dashboard';
}

try {
    $pdo = db();
    $statement = $pdo->query(
        "SELECT jobs.*, users.display_name AS employer_name,
                (SELECT COUNT(*) FROM applications WHERE applications.job_id = jobs.id) AS application_count
         FROM jobs
         INNER JOIN users ON users.id = jobs.employer_id
         ORDER BY (jobs.status = 'available') DESC, jobs.created_at DESC"
    );
    $jobs = $statement->fetchAll();
    $jobsLoaded = true;

    $user = signed_in_user();
    if ($user !== null && $user['role'] === 'employer') {
        $statement = $pdo->prepare(
            'SELECT applications.job_id, applications.candidate_id, applications.status,
                    applications.cover_letter, applications.relevant_experience, applications.portfolio_url,
                    applications.created_at, users.display_name AS candidate_name, users.email AS candidate_email
             FROM applications
             INNER JOIN jobs ON jobs.id = applications.job_id
             INNER JOIN users ON users.id = applications.candidate_id
             WHERE jobs.employer_id = :employer_id
             ORDER BY applications.created_at DESC'
        );
        $statement->execute(['employer_id' => $user['id']]);
        foreach ($statement->fetchAll() as $application) {
            $applicationsByJob[$application['job_id']][] = $application;
        }
    } elseif ($user !== null && $user['role'] === 'candidate') {
        $statement = $pdo->prepare('SELECT job_id, status FROM applications WHERE candidate_id = :candidate_id');
        $statement->execute(['candidate_id' => $user['id']]);
        foreach ($statement->fetchAll() as $application) {
            $candidateApplications[$application['job_id']] = $application['status'];
        }
    }
} catch (PDOException $exception) {
    $notice = 'Job listings could not be loaded. Check the database setup and try again.';
    $noticeType = 'error';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SkillsPassAurora | Proving What You Can Do</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?= $view === 'landing' ? 'landing-site' : '' ?>">
<header class="topbar">
    <nav class="nav" aria-label="Main navigation">
        <a class="brand" href="index.php" aria-label="SkillsPassAurora home">
            <span class="brand-logo-frame">
                <img class="brand-logo" src="images/logo.jpeg" alt="">
            </span>
            <span class="brand-name">SkillsPassAurora</span>
        </a>
        <div class="landing-nav-links">
            <a href="index.php?page=jobs">Browse jobs</a>
            <a href="index.php#contact">Contact us</a>
        </div>
        <?php if (signed_in_user() !== null): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="secondary" type="submit">Sign out</button>
            </form>
        <?php else: ?>
            <a class="nav-login" href="index.php?page=auth&amp;mode=login">Sign in</a>
            <a class="nav-join" href="index.php?page=auth&amp;mode=signup">Get started</a>
        <?php endif; ?>
    </nav>
</header>
<main>
    <?php if ($notice !== ''): ?>
        <div class="notice <?= $noticeType === 'error' ? 'notice-error' : '' ?>" role="status"><?= escape($notice) ?></div>
    <?php endif; ?>

    <?php if ($view === 'verify'): ?>
        <section class="auth-wrap">
            <div class="auth-panel verify-panel">
                <p class="eyebrow">Extra sign-in security</p>
                <h1>Confirm it's you</h1>
                <p class="muted">Enter the six-digit sign-in code sent to <strong><?= escape($pendingEmail ?: 'your email') ?></strong>.</p>
                <form class="stack" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="verify">
                    <div class="field">
                        <label for="otp">Sign-in code</label>
                        <input id="otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                    </div>
                    <button type="submit">Verify and sign in</button>
                </form>
                <form class="resend-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="resend">
                    <button class="text-button" type="submit">Send a new code</button>
                </form>
            </div>
        </section>
    <?php elseif ($view === 'landing'): ?>
        <section class="landing-hero">
            <div class="landing-hero-inner">
                <div class="landing-copy">
                    <p class="eyebrow">SkillsPassAurora &middot; Skills-first opportunities</p>
                    <h1>Find work that fits your skills.</h1>
                    <p class="landing-description">Discover opportunities, show employers what you can do, and build your next career step with confidence.</p>
                    <div class="landing-actions">
                        <a class="primary-link" href="index.php?page=jobs">Browse available jobs <span aria-hidden="true">&rarr;</span></a>
                    </div>
                    <div class="landing-proof">
                        <span><strong>Show your skills</strong><br>Share experience and work samples</span>
                        <span><strong>Meet opportunity</strong><br>Connect with employers</span>
                    </div>
                </div>
                <div class="slideshow" aria-label="People connecting through work">
                    <div class="slides">
                        <?php
                        $landingSlides = [
                            ['images/image 1.jpg', 'Your skills open doors', 'Show employers what you can bring to their team.'],
                            ['images/image 5.jpg', 'Make the next connection', 'Find people and opportunities that move you forward.'],
                            ['images/images 2.jpg', 'Bring your experience forward', 'Explore a role where your strengths can make a difference.'],
                            ['images/image 3.jpg', 'Build your next opportunity', 'Connect your skills with work that matters.'],
                            ['images/image 4.jpg', 'Let your work speak for you', 'Share your skills and grow your career.'],
                            ['images/images 6.jpg', 'Find the right fit', 'Meet employers looking for practical ability.'],
                            ['images/images 8.jpg', 'Take your next step', 'Discover opportunities and show what you can do.'],
                            ['images/images 10.jpg', 'Skills bring people together', 'Build a stronger future through meaningful work.'],
                            ['images/images 9.jpg', 'A new opportunity starts here', 'Put your experience in front of the right people.'],
                            ['images/handshake.avif', 'Make opportunity happen', 'Start with your skills and keep moving forward.'],
                        ];
                        foreach ($landingSlides as $index => [$image, $caption, $description]):
                        ?>
                            <article class="slide<?= $index === 0 ? ' is-active' : '' ?>" aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
                                <img src="<?= escape($image) ?>" alt="" <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
                                <div class="slide-caption">
                                    <span class="slide-kicker">Talent meets opportunity</span>
                                    <h2><?= escape($caption) ?></h2>
                                    <p><?= escape($description) ?></p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="slideshow-controls">
                        <div class="slide-dots" aria-label="Choose a slide">
                            <?php foreach ($landingSlides as $index => $_slide): ?>
                                <button class="slide-dot<?= $index === 0 ? ' is-active' : '' ?>" type="button" data-slide-to="<?= $index ?>" aria-label="Show slide <?= $index + 1 ?>" aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="slide-arrows">
                            <button class="slide-arrow" type="button" data-slide-direction="-1" aria-label="Previous slide">&#8592;</button>
                            <button class="slide-arrow" type="button" data-slide-direction="1" aria-label="Next slide">&#8594;</button>
                        </div>
                    </div>
                    <span class="slide-count" aria-live="polite"><span data-slide-current>01</span> / <?= count($landingSlides) ?></span>
                </div>
            </div>
        </section>
        <section class="landing-categories" id="how-it-works">
            <div class="landing-categories-inner">
                <div class="landing-category-heading">
                    <div>
                        <p class="eyebrow">Explore your next move</p>
                        <h2>Find opportunities by area</h2>
                    </div>
                    <a href="index.php?page=jobs">See all available jobs <span aria-hidden="true">&rarr;</span></a>
                </div>
                <div class="category-grid">
                    <a class="category-card" href="index.php?page=jobs">
                        <img src="images/images 2.jpg" alt="" loading="lazy">
                        <span class="category-shade"></span>
                        <span class="category-title">Technology</span>
                        <span class="category-copy">Digital, web and technical skills</span>
                    </a>
                    <a class="category-card" href="index.php?page=jobs">
                        <img src="images/image 3.jpg" alt="" loading="lazy">
                        <span class="category-shade"></span>
                        <span class="category-title">Business &amp; administration</span>
                        <span class="category-copy">Operations, office and business support</span>
                    </a>
                    <a class="category-card" href="index.php?page=jobs">
                        <img src="images/images 6.jpg" alt="" loading="lazy">
                        <span class="category-shade"></span>
                        <span class="category-title">Customer service</span>
                        <span class="category-copy">Communication and customer care</span>
                    </a>
                    <a class="category-card" href="index.php?page=jobs">
                        <img src="images/image 1.jpg" alt="" loading="lazy">
                        <span class="category-shade"></span>
                        <span class="category-title">Creative &amp; marketing</span>
                        <span class="category-copy">Content, design and digital media</span>
                    </a>
                </div>
            </div>
        </section>
        <section class="landing-employer-callout" id="employers">
            <div>
                <p class="eyebrow">For employers</p>
                <h2>Meet people who can do the work.</h2>
                <p>Share an opportunity, review candidates’ skills and work samples, and find the right fit for your team.</p>
            </div>
            <a class="primary-link" href="index.php?page=auth&amp;mode=signup&amp;role=employer">Find your next hire <span aria-hidden="true">&rarr;</span></a>
        </section>
    <?php elseif ($view === 'jobs'): ?>
        <section class="jobs-page-intro">
            <p class="eyebrow">SkillsPassAurora opportunities</p>
            <h1>Find your next opportunity.</h1>
            <p class="muted">Explore current job openings and find a role that matches your skills.</p>
        </section>
    <?php elseif ($view === 'dashboard'): ?>
        <?php $user = signed_in_user(); ?>
        <section class="welcome-panel dashboard-welcome">
            <p class="eyebrow">Welcome</p>
            <h1>Welcome to SkillsPassAurora, <?= escape($user['display_name'] ?? '') ?>.</h1>
            <p class="muted">Your <?= escape($user['role'] ?? '') ?> account is ready. Browse jobs and see their current availability below.</p>
        </section>
        <?php if (($user['role'] ?? '') === 'employer'): ?>
            <section class="auth-panel job-form-panel">
                <p class="eyebrow">For employers</p>
                <h2>Post a job</h2>
                <form class="stack" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_job">
                    <div class="job-form-grid">
                        <div class="field">
                            <label for="job-title">Job title</label>
                            <input id="job-title" name="title" maxlength="150" required>
                        </div>
                        <div class="field">
                            <label for="job-location">Location</label>
                            <input id="job-location" name="location" maxlength="150" placeholder="City or remote" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="employment-type">Job type</label>
                        <select id="employment-type" name="employment_type" required>
                            <option value="full-time">Full-time</option>
                            <option value="part-time">Part-time</option>
                            <option value="contract">Contract</option>
                            <option value="temporary">Temporary</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="job-description">Description</label>
                        <textarea id="job-description" name="description" rows="5" maxlength="10000" required></textarea>
                    </div>
                    <div class="field">
                        <label for="job-skills">Skills sought <span class="muted">(optional)</span></label>
                        <textarea id="job-skills" name="skills" rows="2" maxlength="2000" placeholder="For example: customer service, bookkeeping"></textarea>
                    </div>
                    <button type="submit">Post job as Available</button>
                </form>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <section class="auth-page">
            <div class="auth-brand-panel">
                <a class="back-home" href="index.php">&larr; Back to home</a>
                <img src="images/logo.jpeg" alt="SkillsPassAurora — Proving What You Can Do">
                <p>Real skills. Real opportunities. A brighter future.</p>
            </div>
            <div class="auth-panel auth-page-panel">
                <p class="eyebrow"><?= $authMode === 'signup' ? 'Join the community' : 'Welcome back' ?></p>
                <h1><?= $authMode === 'signup' ? 'Create your account' : 'Sign in to SkillsPass' ?></h1>
                <p class="auth-intro"><?= $authMode === 'signup' ? ($signupRole === '' ? 'Choose how you want to use SkillsPassAurora.' : 'Create your ' . ($signupRole === 'candidate' ? 'employee' : 'employer') . ' account.') : 'Continue to your SkillsPassAurora account.' ?></p>
                <?php if ($authMode === 'login'): ?>
                <form class="stack" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <div class="field">
                        <label for="login-email">Email address</label>
                        <input id="login-email" name="email" type="email" autocomplete="email" required>
                    </div>
                    <div class="field">
                        <label for="login-password">Password</label>
                        <div class="password-wrap">
                            <input id="login-password" name="password" type="password" autocomplete="current-password" required>
                            <button class="password-toggle" type="button" data-password-toggle="login-password" aria-pressed="false">Show</button>
                        </div>
                    </div>
                    <button type="submit">Sign in</button>
                </form>
                <p class="auth-switch">New to SkillsPassAurora? <a href="index.php?page=auth&amp;mode=signup">Create an account</a></p>
                <?php else: ?>
                <?php if ($signupRole === ''): ?>
                <div class="role-options">
                    <a class="role-option" href="index.php?page=auth&amp;mode=signup&amp;role=candidate">
                        <span class="role-option-icon" aria-hidden="true">&#128100;</span>
                        <span class="role-option-copy">
                            <strong>Employee</strong>
                            <span>I'm looking for work and want to show employers my skills.</span>
                        </span>
                        <span class="role-option-arrow" aria-hidden="true">&rarr;</span>
                    </a>
                    <a class="role-option" href="index.php?page=auth&amp;mode=signup&amp;role=employer">
                        <span class="role-option-icon" aria-hidden="true">&#127970;</span>
                        <span class="role-option-copy">
                            <strong>Employer</strong>
                            <span>I'm hiring and want to find skilled people for my business.</span>
                        </span>
                        <span class="role-option-arrow" aria-hidden="true">&rarr;</span>
                    </a>
                </div>
                <?php else: ?>
                <div class="selected-role">
                    <span>Creating an account as an <strong><?= $signupRole === 'candidate' ? 'Employee' : 'Employer' ?></strong></span>
                    <a href="index.php?page=auth&amp;mode=signup">Change</a>
                </div>
                <form class="stack" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="role" value="<?= escape($signupRole) ?>">
                    <div class="field">
                        <label for="display-name">Your name or company</label>
                        <input id="display-name" name="display_name" maxlength="150" autocomplete="name" required>
                    </div>
                    <div class="field">
                        <label for="register-email">Email address</label>
                        <input id="register-email" name="email" type="email" autocomplete="email" required>
                    </div>
                    <div class="field">
                        <label for="register-password">Create password</label>
                        <div class="password-wrap">
                            <input id="register-password" name="password" type="password" minlength="10" autocomplete="new-password" required>
                            <button class="password-toggle" type="button" data-password-toggle="register-password" aria-pressed="false">Show</button>
                        </div>
                        <span class="field-hint">Use at least 10 characters.</span>
                    </div>
                    <button type="submit">Create account</button>
                </form>
                <?php endif; ?>
                <p class="auth-switch">Already have an account? <a href="index.php?page=auth&amp;mode=login">Sign in</a></p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($view === 'jobs' || $view === 'dashboard'): ?>
        <section class="jobs-section" id="jobs">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">SkillsPassAurora opportunities</p>
                    <h2><?= $view === 'jobs' ? 'Available jobs' : 'Browse jobs' ?></h2>
                </div>
                <p class="muted"><?= $view === 'jobs' ? 'Explore current opportunities and check each listing’s availability before applying.' : 'Each listing shows whether it is available or has been taken.' ?></p>
            </div>
            <?php if (!$jobsLoaded): ?>
                <div class="empty-state">Job listings are unavailable right now.</div>
            <?php elseif ($jobs === []): ?>
                <div class="empty-state">No jobs have been posted yet. Check back soon.</div>
            <?php else: ?>
                <div class="job-list">
                    <?php foreach ($jobs as $job): ?>
                        <?php
                        $jobId = (int) $job['id'];
                        $jobStatus = (string) $job['status'];
                        $user = signed_in_user();
                        $applicationStatus = $candidateApplications[$jobId] ?? null;
                        ?>
                        <article class="job-card">
                            <div class="job-card-heading">
                                <div>
                                    <p class="job-employer"><?= escape($job['employer_name']) ?></p>
                                    <h3><?= escape(strcasecmp((string) $job['title'], 'software expert') === 0 ? 'Software Expert' : $job['title']) ?></h3>
                                </div>
                                <span class="status-badge <?= $jobStatus === 'available' ? 'status-available' : 'status-taken' ?>"><?= $jobStatus === 'available' ? 'Available' : 'Taken' ?></span>
                            </div>
                            <p class="job-meta"><?= escape($job['location']) ?> <span aria-hidden="true">&middot;</span> <?= escape(ucwords(str_replace('-', ' ', (string) $job['employment_type']))) ?></p>
                            <p class="job-description"><?= nl2br(escape($job['description'])) ?></p>
                            <?php if (!empty($job['skills'])): ?>
                                <p class="job-skills"><strong>Skills sought:</strong> <?= escape($job['skills']) ?></p>
                            <?php endif; ?>
                            <div class="job-card-footer">
                                <span class="muted"><?= (int) $job['application_count'] ?> application<?= (int) $job['application_count'] === 1 ? '' : 's' ?></span>
                                <?php if ($user !== null && $user['role'] === 'candidate' && $jobStatus === 'available'): ?>
                                    <?php if ($applicationStatus === null): ?>
                                        <form class="application-form" method="post">
                                            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="apply_job">
                                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                                            <div class="field">
                                                <label for="cover-letter-<?= $jobId ?>">Cover letter</label>
                                                <textarea id="cover-letter-<?= $jobId ?>" name="cover_letter" rows="4" maxlength="5000" autocomplete="off" placeholder="Explain why you're a good fit for this role." required></textarea>
                                            </div>
                                            <div class="field">
                                                <label for="relevant-experience-<?= $jobId ?>">Relevant experience <span class="muted">(optional)</span></label>
                                                <textarea id="relevant-experience-<?= $jobId ?>" name="relevant_experience" rows="3" maxlength="3000" autocomplete="off" placeholder="Describe experience or skills related to this role."></textarea>
                                            </div>
                                            <div class="field">
                                                <label for="portfolio-url-<?= $jobId ?>">Portfolio link <span class="muted">(optional)</span></label>
                                                <input id="portfolio-url-<?= $jobId ?>" name="portfolio_url" type="url" maxlength="2048" autocomplete="off" placeholder="https://example.com/your-work">
                                            </div>
                                            <button type="submit">Apply</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="application-status">Application: <?= escape(ucfirst($applicationStatus)) ?></span>
                                    <?php endif; ?>
                                <?php elseif ($user !== null && $user['role'] === 'employer' && (int) $job['employer_id'] === (int) $user['id']): ?>
                                    <span class="muted">Your job</span>
                                <?php elseif ($user === null && $jobStatus === 'available'): ?>
                                    <a class="job-login-link" href="index.php?page=auth&amp;mode=login">Sign in as a candidate to apply</a>
                                <?php endif; ?>
                            </div>
                            <?php if ($user !== null && $user['role'] === 'employer' && (int) $job['employer_id'] === (int) $user['id']): ?>
                                <div class="applicants">
                                    <h4>Applicants</h4>
                                    <?php $jobApplications = $applicationsByJob[$jobId] ?? []; ?>
                                    <?php if ($jobApplications === []): ?>
                                        <p class="muted">No applications yet.</p>
                                    <?php else: ?>
                                        <?php foreach ($jobApplications as $application): ?>
                                            <div class="applicant-row">
                                                <div>
                                                    <strong><?= escape($application['candidate_name']) ?></strong>
                                                    <a href="mailto:<?= escape($application['candidate_email']) ?>"><?= escape($application['candidate_email']) ?></a>
                                                    <p><strong>Cover letter</strong><br><?= nl2br(escape($application['cover_letter'])) ?></p>
                                                    <?php if (!empty($application['relevant_experience'])): ?>
                                                        <p><strong>Relevant experience</strong><br><?= nl2br(escape($application['relevant_experience'])) ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($application['portfolio_url'])): ?>
                                                        <p><strong>Portfolio:</strong> <a href="<?= escape($application['portfolio_url']) ?>" target="_blank" rel="noopener noreferrer"><?= escape($application['portfolio_url']) ?></a></p>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($jobStatus === 'available' && $application['status'] === 'pending'): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="hire_candidate">
                                                        <input type="hidden" name="job_id" value="<?= $jobId ?>">
                                                        <input type="hidden" name="candidate_id" value="<?= (int) $application['candidate_id'] ?>">
                                                        <button type="submit">Select candidate</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="application-status"><?= escape(ucfirst((string) $application['status'])) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<a class="whatsapp-contact" href="https://wa.me/237672828084?text=Hello%2C%20I%20would%20like%20to%20contact%20the%20SkillsPassAurora%20developer." target="_blank" rel="noopener noreferrer" aria-label="Contact the developer on WhatsApp">
    <svg class="whatsapp-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M20.52 3.48A11.82 11.82 0 0 0 12.08 0C5.5 0 .15 5.35.15 11.93c0 2.1.55 4.16 1.6 5.97L.05 24l6.25-1.64a11.9 11.9 0 0 0 5.77 1.47h.01c6.58 0 11.93-5.35 11.93-11.93 0-3.19-1.24-6.19-3.49-8.42ZM12.08 21.8h-.01a9.9 9.9 0 0 1-5.04-1.38l-.36-.21-3.71.97.99-3.62-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.46 4.44-9.9 9.9-9.9 2.65 0 5.14 1.03 7.01 2.9a9.84 9.84 0 0 1 2.9 7c0 5.45-4.44 9.89-9.93 9.89Zm5.43-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.66.15-.2.3-.76.97-.93 1.17-.17.2-.34.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.61.13-.13.3-.34.44-.51.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.87 1.22 3.07.15.2 2.1 3.2 5.08 4.49.71.31 1.27.49 1.7.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.69.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35Z"/>
    </svg>
    <span>Contact developer</span>
</a>
<footer class="site-footer" id="contact">SkillsPassAurora &middot; Proving What You Can Do</footer>
<script>
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(reveal));
        button.setAttribute('aria-label', `${reveal ? 'Hide' : 'Show'} password`);
    });
});

const slideshow = document.querySelector('.slideshow');
if (slideshow) {
    const slides = Array.from(slideshow.querySelectorAll('.slide'));
    const dots = Array.from(slideshow.querySelectorAll('.slide-dot'));
    const currentCount = slideshow.querySelector('[data-slide-current]');
    let activeSlide = 0;
    let rotation;

    const showSlide = (index) => {
        activeSlide = (index + slides.length) % slides.length;
        slides.forEach((slide, slideIndex) => {
            const isActive = slideIndex === activeSlide;
            slide.classList.toggle('is-active', isActive);
            slide.setAttribute('aria-hidden', String(!isActive));
            dots[slideIndex].classList.toggle('is-active', isActive);
            dots[slideIndex].setAttribute('aria-pressed', String(isActive));
        });
        if (currentCount) currentCount.textContent = String(activeSlide + 1).padStart(2, '0');
    };

    const stopRotation = () => window.clearInterval(rotation);
    const startRotation = () => {
        stopRotation();
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            rotation = window.setInterval(() => showSlide(activeSlide + 1), 4000);
        }
    };

    slideshow.querySelectorAll('[data-slide-direction]').forEach((button) => {
        button.addEventListener('click', () => {
            showSlide(activeSlide + Number(button.dataset.slideDirection));
            startRotation();
        });
    });
    dots.forEach((button) => {
        button.addEventListener('click', () => {
            showSlide(Number(button.dataset.slideTo));
            startRotation();
        });
    });
    slideshow.addEventListener('mouseenter', stopRotation);
    slideshow.addEventListener('mouseleave', startRotation);
    slideshow.addEventListener('focusin', stopRotation);
    slideshow.addEventListener('focusout', (event) => {
        if (!slideshow.contains(event.relatedTarget)) startRotation();
    });
    startRotation();
}
</script>
</body>
</html>
