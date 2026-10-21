<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/mailer.php';

$user = current_user();
$defaultPage = $user
    ? ($user['role'] === 'admin' ? 'admin' : ($user['role'] === 'jobseeker' ? 'employee-dashboard' : 'dashboard'))
    : 'home';
$page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : $defaultPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role']     ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($password) < 8 || !in_array($role, ['jobseeker', 'employer'], true)) {
            flash('Enter a valid name and email, choose an account type, and use a password with at least 8 characters.', 'error');
            redirect('index.php?page=register');
        }

        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, 'active')"
            );
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO profiles (user_id, headline, location, bio) VALUES (?, '', '', '')")
                ->execute([$userId]);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                flash('That email address already has an account. Please sign in instead.', 'error');
                redirect('index.php?page=login');
            }
            throw $exception;
        }

        flash('Your account has been created. You can sign in now.');
        redirect('index.php?page=login');
    }

    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $statement = $pdo->prepare('SELECT id, name, email, password_hash, role, status FROM users WHERE email = ?');
        $statement->execute([$email]);
        $account = $statement->fetch();

        if (!$account || !password_verify((string) ($_POST['password'] ?? ''), $account['password_hash'])) {
            flash('The email or password was not recognised. Please try again.', 'error');
            redirect('index.php?page=login');
        }

        if ($account['status'] === 'pending' && $account['role'] !== 'admin') {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending'")
                ->execute([$account['id']]);
            $account['status'] = 'active';
        }
        if ($account['status'] !== 'active') {
            flash('This account is not active. Contact the SkillPass admin for assistance.', 'error');
            redirect('index.php?page=login');
        }

        session_regenerate_id(true);
        unset($account['password_hash']);
        unset($account['status']);
        $_SESSION['user'] = $account;
        redirect(match ($account['role']) {
            'admin' => 'admin-dashboard.php',
            'jobseeker' => 'employee-dashboard.php',
            default => 'index.php',
        });
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
        }
        session_destroy();
        redirect('index.php');
    }

    if ($action === 'request_password_reset') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid email address.', 'error');
            redirect('index.php?page=forgot-password');
        }

        $statement = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $statement->execute([$email]);
        $userId = $statement->fetchColumn();

        if ($userId) {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $statement->execute([$userId]);
            $lockedUserId = $statement->fetchColumn();
            $code = null;

            if ($lockedUserId) {
                $statement = $pdo->prepare('SELECT last_sent_at FROM password_reset_codes WHERE user_id = ?');
                $statement->execute([$userId]);
                $lastSentAt = $statement->fetchColumn();

                if (!$lastSentAt || strtotime((string) $lastSentAt) <= time() - 60) {
                    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $pdo->prepare(
                        'INSERT INTO password_reset_codes (user_id, code_hash, expires_at, attempts, last_sent_at)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
                         ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash),
                           expires_at = VALUES(expires_at), attempts = 0, last_sent_at = NOW()'
                    )->execute([$userId, password_hash($code, PASSWORD_DEFAULT)]);
                }
            }
            $pdo->commit();

            if ($code !== null) {
                if (!send_password_reset_code($email, $code)) {
                    error_log('SkillPass password reset email was not delivered; the response remains generic to protect account privacy.');
                }
            }
        }

        flash('If an account exists for that address, a reset code will be sent. If no message arrives, check your spam folder or contact the site administrator; email delivery may be unavailable.');
        redirect('index.php?page=forgot-password');
    }

    if ($action === 'complete_password_reset') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $code = trim((string) ($_POST['code'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)
            || strlen($password) < 8 || $password !== $passwordConfirm) {
            flash('Enter the account email, a six-digit code, and matching passwords of at least 8 characters.', 'error');
            redirect('index.php?page=reset-password');
        }

        $statement = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $statement->execute([$email]);
        $userId = $statement->fetchColumn();
        $reset = null;

        if ($userId) {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT code_hash, attempts, expires_at > NOW() AS is_unexpired
                 FROM password_reset_codes WHERE user_id = ? FOR UPDATE'
            );
            $statement->execute([$userId]);
            $reset = $statement->fetch() ?: null;
        }

        $validCode = $reset
            && (int) $reset['attempts'] < 5
            && (int) $reset['is_unexpired'] === 1
            && password_verify($code, $reset['code_hash']);

        if (!$validCode) {
            if ($reset && (int) $reset['attempts'] < 5) {
                $pdo->prepare('UPDATE password_reset_codes SET attempts = attempts + 1 WHERE user_id = ?')
                    ->execute([$userId]);
            }
            if ($userId && $pdo->inTransaction()) {
                $pdo->commit();
            }
            flash('The reset code is invalid, expired, or has reached its attempt limit. Request a new code and try again.', 'error');
            redirect('index.php?page=reset-password');
        }

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        $pdo->prepare('DELETE FROM password_reset_codes WHERE user_id = ?')->execute([$userId]);
        $pdo->commit();
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Your password has been reset. Sign in with your new password.');
        redirect('index.php?page=login');
    }

    if (!$user) {
        flash('Please sign in to continue.', 'error');
        redirect('index.php?page=login');
    }

    $statement = $pdo->prepare('SELECT role, status FROM users WHERE id = ?');
    $statement->execute([$user['id']]);
    $accountState = $statement->fetch();
    if (!$accountState || $accountState['status'] !== 'active' || $accountState['role'] !== $user['role']) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Your account is not active. Contact the SkillPass admin for assistance.', 'error');
        redirect('index.php?page=login');
    }

    if ($action === 'review_job') {
        require_role('admin');
        $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if (!$jobId || !in_array($status, ['open', 'rejected'], true)) {
            flash('Choose a valid job-post review decision.', 'error');
            redirect('index.php');
        }

        $statement = $pdo->prepare("UPDATE jobs SET status = ? WHERE id = ? AND status = 'pending'");
        $statement->execute([$status, $jobId]);
        if ($statement->rowCount() !== 1) {
            flash('That job post is no longer awaiting review.', 'error');
            redirect('index.php');
        }

        flash($status === 'open' ? 'Job post approved and published.' : 'Job post declined.');
        redirect('index.php');
    }

    if ($action === 'send_message') {
        $sender = $user['role'] === 'admin' ? require_role('admin') : $user;
        $recipientId = filter_input(INPUT_POST, 'recipient_id', FILTER_VALIDATE_INT);
        $body = trim((string) ($_POST['body'] ?? ''));
        $recipient = null;

        if ($sender['role'] === 'admin' && $recipientId) {
            $statement = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND role IN ('employer', 'jobseeker')");
            $statement->execute([$recipientId]);
            $recipient = $statement->fetch() ?: null;
        } elseif ($sender['role'] !== 'admin') {
            $statement = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1");
            $adminId = $statement->fetchColumn();
            if ($adminId) {
                $recipient = ['id' => (int) $adminId, 'role' => 'admin'];
            }
        }

        if (!$recipient || $body === '' || mb_strlen($body) > 4000) {
            flash('Choose a recipient and enter a message of 1–4,000 characters.', 'error');
            redirect('index.php?page=messages');
        }

        $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, body) VALUES (?, ?, ?)')
            ->execute([$sender['id'], $recipient['id'], $body]);
        flash($sender['role'] === 'admin' ? 'Your message has been sent.' : 'Your message has been sent to the SkillPass admin for mediation.');
        redirect('index.php?page=messages');
    }

    if ($action === 'save_profile') {
        $jobseeker = require_role('jobseeker');
        $headline = mb_substr(trim((string) ($_POST['headline'] ?? '')), 0, 160);
        $location = mb_substr(trim((string) ($_POST['location'] ?? '')), 0, 120);
        $bio = trim((string) ($_POST['bio'] ?? ''));
        if (mb_strlen($bio) > 3000) {
            flash('Your profile summary must be 3,000 characters or fewer.', 'error');
            redirect('index.php?page=profile');
        }

        $pdo->beginTransaction();
        $pdo->prepare('UPDATE profiles SET headline = ?, location = ?, bio = ? WHERE user_id = ?')
            ->execute([$headline, $location, $bio, $jobseeker['id']]);
        save_skill_list($pdo, (int) $jobseeker['id'], (string) ($_POST['skills'] ?? ''));
        $pdo->commit();
        flash('Your profile and skills have been updated.');
        redirect('index.php?page=profile');
    }

    if ($action === 'add_project') {
        $jobseeker = require_role('jobseeker');
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $evidenceUrl = trim((string) ($_POST['evidence_url'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160 || $description === '' || mb_strlen($description) > 3000
            || ($evidenceUrl !== '' && !valid_evidence_url($evidenceUrl))) {
            flash('Add a project title and description. Evidence links must be valid URLs.', 'error');
            redirect('index.php?page=profile');
        }

        $pdo->prepare('INSERT INTO projects (user_id, title, description, evidence_url) VALUES (?, ?, ?, ?)')
            ->execute([$jobseeker['id'], $title, $description, $evidenceUrl]);
        flash('Project added to your evidence portfolio.');
        redirect('index.php?page=profile');
    }

    if ($action === 'apply_job') {
        $jobseeker = require_role('jobseeker');
        $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
        $coverNote = trim((string) ($_POST['cover_note'] ?? ''));
        $jobStatement = $pdo->prepare("SELECT id FROM jobs WHERE id = ? AND status = 'open'");
        $jobStatement->execute([$jobId]);

        if (!$jobId || !$jobStatement->fetchColumn()) {
            flash('This opening is no longer available.', 'error');
            redirect('index.php?page=opportunities');
        }
        if (mb_strlen($coverNote) > 2000) {
            flash('Your note must be 2,000 characters or fewer.', 'error');
            redirect('index.php?page=opportunities');
        }

        try {
            $pdo->prepare('INSERT INTO applications (job_id, jobseeker_id, cover_note) VALUES (?, ?, ?)')
                ->execute([$jobId, $jobseeker['id'], $coverNote]);
            flash('Application sent. You can follow its status in My applications.');
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            flash('You have already applied for this opening.', 'error');
        }
        redirect('index.php?page=applications');
    }

    if ($action === 'submit_challenge') {
        $jobseeker = require_role('jobseeker');
        $challengeId = filter_input(INPUT_POST, 'challenge_id', FILTER_VALIDATE_INT);
        $response = trim((string) ($_POST['response'] ?? ''));
        $evidenceUrl = trim((string) ($_POST['evidence_url'] ?? ''));
        $statement = $pdo->prepare(
            "SELECT c.id FROM challenges c
             JOIN jobs j ON j.id = c.job_id
             JOIN applications a ON a.job_id = j.id AND a.jobseeker_id = ?
             WHERE c.id = ? AND j.status = 'open'"
        );
        $statement->execute([$jobseeker['id'], $challengeId]);

        if (!$challengeId || !$statement->fetchColumn() || $response === '' || mb_strlen($response) > 5000
            || ($evidenceUrl !== '' && !valid_evidence_url($evidenceUrl))) {
            flash('Apply to the open role first, then add a response and a valid evidence link if you have one.', 'error');
            redirect('index.php?page=applications');
        }

        $pdo->prepare(
            "INSERT INTO challenge_submissions (challenge_id, jobseeker_id, response, evidence_url, reviewer_note)
             VALUES (?, ?, ?, ?, '')
             ON DUPLICATE KEY UPDATE response = VALUES(response), evidence_url = VALUES(evidence_url),
               status = 'submitted', reviewer_note = '', submitted_at = CURRENT_TIMESTAMP, reviewed_at = NULL"
        )->execute([$challengeId, $jobseeker['id'], $response, $evidenceUrl]);
        flash('Challenge response submitted. The employer will review the evidence.');
        redirect('index.php?page=applications');
    }

    if ($action === 'create_job') {
        $employer = require_role('employer');
        $title = trim((string) ($_POST['title'] ?? ''));
        $company = trim((string) ($_POST['company'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        $workType = (string) ($_POST['work_type'] ?? 'On-site');
        $description = trim((string) ($_POST['description'] ?? ''));
        $challengeTitle = trim((string) ($_POST['challenge_title'] ?? ''));
        $challengeInstructions = trim((string) ($_POST['challenge_instructions'] ?? ''));

        if ($title === '' || $company === '' || $location === '' || $description === ''
            || mb_strlen($title) > 160 || mb_strlen($company) > 160 || mb_strlen($location) > 120
            || mb_strlen($description) > 5000 || mb_strlen($challengeInstructions) > 4000
            || !in_array($workType, ['On-site', 'Hybrid', 'Remote'], true)
            || (($challengeTitle === '') !== ($challengeInstructions === ''))) {
            flash('Complete the role details. If you add a practical challenge, provide both its title and instructions.', 'error');
            redirect('index.php?page=jobs');
        }

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO jobs (employer_id, title, company, location, work_type, description, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([$employer['id'], $title, $company, $location, $workType, $description]);
        $jobId = (int) $pdo->lastInsertId();

        $skillInsert = $pdo->prepare(
            'INSERT INTO skills (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $skillGet = $pdo->prepare('SELECT id FROM skills WHERE slug = ?');
        $jobSkill = $pdo->prepare('INSERT INTO job_skills (job_id, skill_id) VALUES (?, ?)');
        foreach (parse_skill_names((string) ($_POST['skills'] ?? '')) as $skillName) {
            $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $skillName), '-'));
            if ($slug === '') {
                continue;
            }
            $skillInsert->execute([$skillName, $slug]);
            $skillGet->execute([$slug]);
            $skillId = $skillGet->fetchColumn();
            if ($skillId) {
                $jobSkill->execute([$jobId, (int) $skillId]);
            }
        }
        if ($challengeTitle !== '') {
            $pdo->prepare('INSERT INTO challenges (job_id, title, instructions) VALUES (?, ?, ?)')
                ->execute([$jobId, mb_substr($challengeTitle, 0, 160), $challengeInstructions]);
        }
        $pdo->commit();
        flash('Your opening has been submitted for admin approval. It will appear to employees once approved.');
        redirect('index.php?page=jobs');
    }

    if ($action === 'update_application') {
        $employer = require_role('employer');
        $applicationId = filter_input(INPUT_POST, 'application_id', FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if (!$applicationId || !in_array($status, ['reviewing', 'shortlisted', 'declined', 'hired'], true)) {
            flash('Choose a valid application status.', 'error');
            redirect('index.php?page=jobs');
        }

        $statement = $pdo->prepare(
            'UPDATE applications a
             JOIN jobs j ON j.id = a.job_id
             SET a.status = ?
             WHERE a.id = ? AND j.employer_id = ?'
        );
        $statement->execute([$status, $applicationId, $employer['id']]);
        if ($statement->rowCount() === 0) {
            $check = $pdo->prepare(
                'SELECT a.status FROM applications a JOIN jobs j ON j.id = a.job_id
                 WHERE a.id = ? AND j.employer_id = ?'
            );
            $check->execute([$applicationId, $employer['id']]);
            if (!$check->fetchColumn()) {
                http_response_code(404);
                exit('Application not found.');
            }
        }
        flash('Application status updated.');
        redirect('index.php?page=candidates&job=' . (int) ($_POST['job_id'] ?? 0));
    }

    if ($action === 'review_challenge') {
        $employer = require_role('employer');
        $submissionId = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        $reviewerNote = trim((string) ($_POST['reviewer_note'] ?? ''));
        if (!$submissionId || !in_array($status, ['passed', 'needs_work'], true) || mb_strlen($reviewerNote) > 1500) {
            flash('Choose a valid review result and keep feedback under 1,500 characters.', 'error');
            redirect('index.php?page=jobs');
        }

        $statement = $pdo->prepare(
            'UPDATE challenge_submissions s
             JOIN challenges c ON c.id = s.challenge_id
             JOIN jobs j ON j.id = c.job_id
             SET s.status = ?, s.reviewer_note = ?, s.reviewed_at = CURRENT_TIMESTAMP
             WHERE s.id = ? AND j.employer_id = ?'
        );
        $statement->execute([$status, $reviewerNote, $submissionId, $employer['id']]);
        if ($statement->rowCount() === 0) {
            http_response_code(404);
            exit('Challenge submission not found.');
        }
        flash('Challenge reviewed. The candidate can see your result and feedback.');
        redirect('index.php?page=candidates&job=' . (int) ($_POST['job_id'] ?? 0));
    }

    if ($action === 'follow_employer' || $action === 'unfollow_employer') {
        $jobseeker = require_role('jobseeker');
        $employerId = filter_input(INPUT_POST, 'employer_id', FILTER_VALIDATE_INT);
        $statement = $pdo->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'employer' AND status = 'active'"
        );
        $statement->execute([$employerId]);
        if (!$employerId || (int) $employerId === (int) $jobseeker['id'] || !$statement->fetchColumn()) {
            flash('That employer is not available to follow.', 'error');
            redirect('index.php?page=updates');
        }

        if ($action === 'follow_employer') {
            try {
                $pdo->prepare('INSERT INTO employer_follows (employee_id, employer_id) VALUES (?, ?)')
                    ->execute([$jobseeker['id'], $employerId]);
                flash('You are now following this employer. Their updates will appear in your feed.');
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
                flash('You are already following this employer.', 'error');
            }
        } else {
            $statement = $pdo->prepare('DELETE FROM employer_follows WHERE employee_id = ? AND employer_id = ?');
            $statement->execute([$jobseeker['id'], $employerId]);
            $unfollowed = $statement->rowCount() > 0;
            flash($unfollowed ? 'You have unfollowed this employer.' : 'You were not following this employer.', $unfollowed ? 'success' : 'error');
        }
        redirect('index.php?page=updates');
    }

    if ($action === 'create_employer_update') {
        $employer = require_role('employer');
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160 || $body === '' || mb_strlen($body) > 3000) {
            flash('Add an update title and message. Titles can be up to 160 characters and messages up to 3,000 characters.', 'error');
            redirect('index.php?page=updates');
        }

        $pdo->prepare('INSERT INTO employer_updates (employer_id, title, body) VALUES (?, ?, ?)')
            ->execute([$employer['id'], $title, $body]);
        flash('Your update has been published to your followers.');
        redirect('index.php?page=updates');
    }

    if ($action === 'toggle_job') {
        $employer = require_role('employer');
        $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if (!$jobId || !in_array($status, ['open', 'closed'], true)) {
            flash('Choose a valid opening status.', 'error');
            redirect('index.php?page=jobs');
        }
        $nextStatus = $status === 'closed' ? 'closed' : 'pending';
        $statement = $pdo->prepare(
            "UPDATE jobs SET status = ? WHERE id = ? AND employer_id = ? AND status IN ('open', 'closed', 'rejected')"
        );
        $statement->execute([$nextStatus, $jobId, $employer['id']]);
        flash($nextStatus === 'pending'
            ? 'Your opening has been resubmitted for admin approval before it is published.'
            : 'Opening closed.');
        redirect('index.php?page=jobs');
    }

    flash('That action was not recognised.', 'error');
    redirect('index.php');
}

$user = current_user();
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$knownPages = ['home', 'how-it-works', 'dashboard', 'employee-dashboard', 'admin', 'opportunities', 'profile', 'applications', 'jobs', 'candidates', 'messages', 'updates', 'login', 'register', 'forgot-password', 'reset-password'];
if (!in_array($page, $knownPages, true)) {
    $page = $defaultPage;
}
if ($user && in_array($page, ['login', 'register', 'forgot-password', 'reset-password'], true)) {
    redirect('index.php');
}
if (!$user && $page === 'dashboard') {
    $page = 'home';
}
if (!$user && $page === 'employee-dashboard') {
    redirect('index.php?page=login');
}
if (!$user && $page === 'admin') {
    redirect('index.php?page=login');
}
if (!$user && !in_array($page, ['home', 'how-it-works', 'login', 'register', 'forgot-password', 'reset-password', 'opportunities'], true)) {
    redirect('index.php?page=home');
}
if ($user && $page === 'employee-dashboard' && $user['role'] !== 'jobseeker') {
    redirect($user['role'] === 'admin' ? 'admin-dashboard.php' : 'index.php');
}
if ($user && $user['role'] === 'jobseeker' && in_array($page, ['jobs', 'candidates'], true)) {
    redirect('index.php');
}
if ($user && $user['role'] === 'employer' && in_array($page, ['opportunities', 'profile', 'applications'], true)) {
    redirect('index.php');
}
if ($user && $user['role'] !== 'admin' && $page === 'admin') {
    redirect($user['role'] === 'jobseeker' ? 'employee-dashboard.php' : 'index.php');
}
if ($user && $user['role'] === 'admin' && !in_array($page, ['admin', 'messages', 'how-it-works'], true)) {
    redirect('index.php?page=admin');
}
if ($user) {
    $statement = $pdo->prepare('SELECT role, status FROM users WHERE id = ?');
    $statement->execute([$user['id']]);
    $accountState = $statement->fetch();
    if (!$accountState || $accountState['status'] !== 'active' || $accountState['role'] !== $user['role']) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Your account is not active. Contact the SkillPass admin for assistance.', 'error');
        redirect('index.php?page=login');
    }
}

$profile = null;
$skillNames = [];
$projects = [];
$openJobs = [];
$applications = [];
$jobs = [];
$applicantRows = [];
$selectedJob = null;
$gapSkill = null;
$pendingJobs = [];
$adminApplications = [];
$messages = [];
$messageRecipients = [];
$employers = [];
$employerUpdates = [];
$followedEmployerCount = 0;
$counts = ['applications' => 0, 'shortlisted' => 0, 'projects' => 0, 'jobs' => 0, 'candidates' => 0, 'unread_messages' => 0, 'followers' => 0];

if ($user && $user['role'] === 'admin') {
    $counts['active_accounts'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('employer', 'jobseeker') AND status = 'active'")->fetchColumn();
    $counts['pending_jobs'] = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'")->fetchColumn();
    $counts['open_jobs'] = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'open'")->fetchColumn();
    $counts['applications'] = (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn();
    $counts['unread_messages'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM messages m JOIN users u ON u.id = m.recipient_id WHERE u.role = 'admin' AND m.read_at IS NULL"
    )->fetchColumn();

    $pendingJobs = $pdo->query(
        "SELECT j.id, j.title, j.company, j.location, j.work_type, j.description, j.created_at, u.name AS employer_name
         FROM jobs j JOIN users u ON u.id = j.employer_id WHERE j.status = 'pending' ORDER BY j.created_at ASC"
    )->fetchAll();
    $adminApplications = $pdo->query(
        'SELECT a.id, a.status, a.created_at, j.title, j.company, u.name AS candidate_name
         FROM applications a JOIN jobs j ON j.id = a.job_id JOIN users u ON u.id = a.jobseeker_id
         ORDER BY a.created_at DESC LIMIT 8'
    )->fetchAll();
    $messageRecipients = $pdo->query(
        "SELECT id, name, email, role, status FROM users WHERE role IN ('employer', 'jobseeker') AND status = 'active' ORDER BY name"
    )->fetchAll();
    $messages = $pdo->query(
        'SELECT m.id, m.sender_id, m.recipient_id, m.body, m.read_at, m.created_at,
                sender.name AS sender_name, sender.role AS sender_role,
                recipient.name AS recipient_name, recipient.role AS recipient_role
         FROM messages m JOIN users sender ON sender.id = m.sender_id
         JOIN users recipient ON recipient.id = m.recipient_id
         ORDER BY m.created_at DESC LIMIT 100'
    )->fetchAll();
    if ($page === 'messages') {
        $pdo->prepare('UPDATE messages SET read_at = CURRENT_TIMESTAMP WHERE recipient_id = ? AND read_at IS NULL')
            ->execute([$user['id']]);
    }
}

if ($user && $user['role'] !== 'admin') {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND read_at IS NULL');
    $statement->execute([$user['id']]);
    $counts['unread_messages'] = (int) $statement->fetchColumn();
    if ($page === 'messages') {
        $statement = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.recipient_id, m.body, m.read_at, m.created_at,
                    sender.name AS sender_name, sender.role AS sender_role,
                    recipient.name AS recipient_name, recipient.role AS recipient_role
             FROM messages m JOIN users sender ON sender.id = m.sender_id
             JOIN users recipient ON recipient.id = m.recipient_id
             WHERE m.sender_id = ? OR m.recipient_id = ? ORDER BY m.created_at DESC LIMIT 100'
        );
        $statement->execute([$user['id'], $user['id']]);
        $messages = $statement->fetchAll();
        $pdo->prepare('UPDATE messages SET read_at = CURRENT_TIMESTAMP WHERE recipient_id = ? AND read_at IS NULL')
            ->execute([$user['id']]);
    }
}

if ($user && $user['role'] === 'jobseeker') {
    $statement = $pdo->prepare('SELECT headline, location, bio FROM profiles WHERE user_id = ?');
    $statement->execute([$user['id']]);
    $profile = $statement->fetch() ?: ['headline' => '', 'location' => '', 'bio' => ''];

    $statement = $pdo->prepare(
        'SELECT s.name FROM user_skills us JOIN skills s ON s.id = us.skill_id WHERE us.user_id = ? ORDER BY s.name'
    );
    $statement->execute([$user['id']]);
    $skillNames = array_column($statement->fetchAll(), 'name');

    $statement = $pdo->prepare('SELECT id, title, description, evidence_url, created_at FROM projects WHERE user_id = ? ORDER BY created_at DESC');
    $statement->execute([$user['id']]);
    $projects = $statement->fetchAll();

    $statement = $pdo->prepare(
        "SELECT j.id, j.title, j.company, j.location, j.work_type, j.description, j.created_at,
                u.name AS employer_name,
                (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
                 FROM job_skills js JOIN skills s ON s.id = js.skill_id WHERE js.job_id = j.id) AS skills,
                (SELECT c.title FROM challenges c WHERE c.job_id = j.id LIMIT 1) AS challenge_title,
                (SELECT c.instructions FROM challenges c WHERE c.job_id = j.id LIMIT 1) AS challenge_instructions,
                EXISTS(SELECT 1 FROM applications a WHERE a.job_id = j.id AND a.jobseeker_id = ?) AS applied
         FROM jobs j JOIN users u ON u.id = j.employer_id
         WHERE j.status = 'open' ORDER BY j.created_at DESC"
    );
    $statement->execute([$user['id']]);
    $openJobs = $statement->fetchAll();

    $statement = $pdo->prepare(
        "SELECT a.id, a.status, a.cover_note, a.created_at, j.id AS job_id, j.title, j.company, j.location,
                c.id AS challenge_id, c.title AS challenge_title, c.instructions,
                s.id AS submission_id, s.response AS submission_response, s.evidence_url AS submission_evidence,
                s.status AS challenge_status, s.reviewer_note, s.submitted_at
         FROM applications a
         JOIN jobs j ON j.id = a.job_id
         LEFT JOIN challenges c ON c.job_id = j.id
         LEFT JOIN challenge_submissions s ON s.challenge_id = c.id AND s.jobseeker_id = a.jobseeker_id
         WHERE a.jobseeker_id = ? ORDER BY a.created_at DESC"
    );
    $statement->execute([$user['id']]);
    $applications = $statement->fetchAll();

    $statement = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE jobseeker_id = ?");
    $statement->execute([$user['id']]);
    $counts['applications'] = (int) $statement->fetchColumn();
    $statement = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE jobseeker_id = ? AND status = 'shortlisted'");
    $statement->execute([$user['id']]);
    $counts['shortlisted'] = (int) $statement->fetchColumn();
    $counts['projects'] = count($projects);

    if ($skillNames) {
        $statement = $pdo->prepare(
            "SELECT s.name, COUNT(DISTINCT js.job_id) AS demand
             FROM job_skills js
             JOIN jobs j ON j.id = js.job_id AND j.status = 'open'
             JOIN skills s ON s.id = js.skill_id
             WHERE s.id NOT IN (
               SELECT us.skill_id FROM user_skills us WHERE us.user_id = ?
             )
             GROUP BY s.id ORDER BY demand DESC, s.name ASC LIMIT 1"
        );
        $statement->execute([$user['id']]);
        $gapSkill = $statement->fetch() ?: null;
    } else {
        $statement = $pdo->query(
            "SELECT s.name, COUNT(DISTINCT js.job_id) AS demand
             FROM job_skills js
             JOIN jobs j ON j.id = js.job_id AND j.status = 'open'
             JOIN skills s ON s.id = js.skill_id
             GROUP BY s.id ORDER BY demand DESC, s.name ASC LIMIT 1"
        );
        $gapSkill = $statement->fetch() ?: null;
    }

    if ($page === 'updates') {
        $statement = $pdo->prepare(
            "SELECT u.id, u.name,
                    EXISTS(SELECT 1 FROM employer_follows ef WHERE ef.employee_id = ? AND ef.employer_id = u.id) AS is_following,
                    (SELECT COUNT(*) FROM employer_follows ef WHERE ef.employer_id = u.id) AS follower_count,
                    (SELECT MAX(eu.created_at) FROM employer_updates eu WHERE eu.employer_id = u.id) AS latest_update,
                    (SELECT COUNT(*) FROM jobs j WHERE j.employer_id = u.id AND j.status = 'open') AS open_role_count
             FROM users u WHERE u.role = 'employer' AND u.status = 'active'
             ORDER BY is_following DESC, latest_update DESC, u.name ASC"
        );
        $statement->execute([$user['id']]);
        $employers = $statement->fetchAll();
        $followedEmployerCount = count(array_filter(
            $employers,
            static fn (array $employer): bool => (int) $employer['is_following'] === 1
        ));

        $statement = $pdo->prepare(
            "SELECT eu.id, eu.title, eu.body, eu.created_at, u.id AS employer_id, u.name AS employer_name
             FROM employer_updates eu
             JOIN users u ON u.id = eu.employer_id AND u.status = 'active'
             JOIN employer_follows ef ON ef.employer_id = eu.employer_id AND ef.employee_id = ?
             ORDER BY eu.created_at DESC LIMIT 50"
        );
        $statement->execute([$user['id']]);
        $employerUpdates = $statement->fetchAll();
    }
}

if ($user && $user['role'] === 'employer') {
    $statement = $pdo->prepare(
        "SELECT j.id, j.title, j.company, j.location, j.work_type, j.description, j.status, j.created_at,
                (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
                 FROM job_skills js JOIN skills s ON s.id = js.skill_id WHERE js.job_id = j.id) AS skills,
                (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicant_count,
                (SELECT c.title FROM challenges c WHERE c.job_id = j.id LIMIT 1) AS challenge_title,
                (SELECT c.instructions FROM challenges c WHERE c.job_id = j.id LIMIT 1) AS challenge_instructions
         FROM jobs j WHERE j.employer_id = ? ORDER BY j.created_at DESC"
    );
    $statement->execute([$user['id']]);
    $jobs = $statement->fetchAll();
    $counts['jobs'] = count($jobs);
    foreach ($jobs as $job) {
        $counts['candidates'] += (int) $job['applicant_count'];
    }
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM jobs WHERE employer_id = ? AND status = 'open'"
    );
    $statement->execute([$user['id']]);
    $counts['open_jobs'] = (int) $statement->fetchColumn();
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM applications a JOIN jobs j ON j.id = a.job_id
         WHERE j.employer_id = ? AND a.status = 'shortlisted'"
    );
    $statement->execute([$user['id']]);
    $counts['shortlisted'] = (int) $statement->fetchColumn();
    $statement = $pdo->prepare('SELECT COUNT(*) FROM employer_follows WHERE employer_id = ?');
    $statement->execute([$user['id']]);
    $counts['followers'] = (int) $statement->fetchColumn();

    if ($page === 'updates') {
        $statement = $pdo->prepare(
            'SELECT id, title, body, created_at FROM employer_updates WHERE employer_id = ? ORDER BY created_at DESC LIMIT 50'
        );
        $statement->execute([$user['id']]);
        $employerUpdates = $statement->fetchAll();
    }

    if ($page === 'candidates') {
        $jobId = filter_input(INPUT_GET, 'job', FILTER_VALIDATE_INT);
        $statement = $pdo->prepare('SELECT * FROM jobs WHERE id = ? AND employer_id = ?');
        $statement->execute([$jobId, $user['id']]);
        $selectedJob = $statement->fetch() ?: null;
        if (!$selectedJob) {
            http_response_code(404);
            exit('Opening not found.');
        }

        $statement = $pdo->prepare(
            'SELECT a.id AS application_id, a.status AS application_status, a.cover_note, a.created_at AS applied_at,
                    u.id AS candidate_id, u.name,
                    p.headline, p.location, p.bio,
                    c.id AS challenge_id, c.title AS challenge_title,
                    s.id AS submission_id, s.response AS submission_response, s.evidence_url AS submission_evidence,
                    s.status AS challenge_status, s.reviewer_note, s.submitted_at
             FROM applications a
             JOIN users u ON u.id = a.jobseeker_id
             LEFT JOIN profiles p ON p.user_id = u.id
             LEFT JOIN challenges c ON c.job_id = a.job_id
             LEFT JOIN challenge_submissions s ON s.challenge_id = c.id AND s.jobseeker_id = u.id
             WHERE a.job_id = ? ORDER BY a.created_at ASC'
        );
        $statement->execute([$selectedJob['id']]);
        $applicantRows = $statement->fetchAll();

        if ($applicantRows) {
            $candidateIds = array_map(static fn (array $row): int => (int) $row['candidate_id'], $applicantRows);
            $candidatePlaceholders = implode(',', array_fill(0, count($candidateIds), '?'));
            $statement = $pdo->prepare(
                "SELECT us.user_id, s.name FROM user_skills us JOIN skills s ON s.id = us.skill_id
                 WHERE us.user_id IN ({$candidatePlaceholders}) ORDER BY s.name"
            );
            $statement->execute($candidateIds);
            $skillsByCandidate = [];
            foreach ($statement->fetchAll() as $row) {
                $skillsByCandidate[(int) $row['user_id']][] = $row['name'];
            }

            $statement = $pdo->prepare(
                "SELECT user_id, title, description, evidence_url
                 FROM projects WHERE user_id IN ({$candidatePlaceholders}) ORDER BY created_at DESC"
            );
            $statement->execute($candidateIds);
            $projectsByCandidate = [];
            foreach ($statement->fetchAll() as $row) {
                $projectsByCandidate[(int) $row['user_id']][] = $row;
            }

            $statement = $pdo->prepare(
                'SELECT s.name FROM job_skills js JOIN skills s ON s.id = js.skill_id WHERE js.job_id = ?'
            );
            $statement->execute([$selectedJob['id']]);
            $requiredSkills = array_column($statement->fetchAll(), 'name');

            foreach ($applicantRows as &$candidate) {
                $candidateSkills = $skillsByCandidate[(int) $candidate['candidate_id']] ?? [];
                $candidateProjects = $projectsByCandidate[(int) $candidate['candidate_id']] ?? [];
                $candidate['skills'] = $candidateSkills;
                $matchedSkills = array_values(array_intersect(array_map('strtolower', $requiredSkills), array_map('strtolower', $candidateSkills)));
                $skillScore = $requiredSkills ? (count($matchedSkills) / count($requiredSkills)) * 70 : 0;
                $projectScore = min(count($candidateProjects) * 5, 20);
                $challengeScore = $candidate['challenge_status'] === 'passed' ? 10 : ($candidate['challenge_status'] === 'submitted' ? 5 : 0);
                $candidate['matched_skills'] = $matchedSkills;
                $candidate['required_skills'] = $requiredSkills;
                $candidate['score'] = (int) round($skillScore + $projectScore + $challengeScore);
                $candidate['skill_points'] = $skillScore;
                $candidate['project_points'] = $projectScore;
                $candidate['challenge_points'] = $challengeScore;
                $candidate['projects'] = $candidateProjects;
                $candidate['project_count'] = count($candidate['projects']);
            }
            unset($candidate);
            usort($applicantRows, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp((string) $a['applied_at'], (string) $b['applied_at']));
        }
    }
}

function badge_class(string $status): string
{
    return match ($status) {
        'shortlisted', 'passed', 'hired' => 'badge badge-green',
        'reviewing', 'submitted' => 'badge badge-blue',
        'declined', 'needs_work', 'closed', 'rejected' => 'badge badge-muted',
        'pending' => 'badge badge-amber',
        default => 'badge',
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'needs_work' => 'Needs another look',
        'shortlisted' => 'Shortlisted',
        'reviewing' => 'In review',
        'submitted' => 'Submitted',
        'passed' => 'Passed',
        'declined' => 'Not selected',
        'hired' => 'Hired',
        'closed' => 'Closed',
        'pending' => 'Awaiting admin approval',
        'rejected' => 'Not approved',
        'open' => 'Open',
        default => ucfirst($status),
    };
}

function candidate_display_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (count($parts) < 2) {
        return $parts[0] ?? 'Candidate';
    }

    return $parts[0] . ' ' . mb_substr($parts[count($parts) - 1], 0, 1) . '.';
}

require __DIR__ . '/views/app.php';
