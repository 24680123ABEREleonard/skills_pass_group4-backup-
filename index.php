<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$user = current_user();
$page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : ($user ? 'dashboard' : 'home');

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
            $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
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

        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => $role];
        flash('Welcome to SkillPass. Let’s build your profile.');
        redirect('index.php');
    }

    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $statement = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = ?');
        $statement->execute([$email]);
        $account = $statement->fetch();

        if (!$account || !password_verify((string) ($_POST['password'] ?? ''), $account['password_hash'])) {
            flash('The email or password was not recognised. Please try again.', 'error');
            redirect('index.php?page=login');
        }

        session_regenerate_id(true);
        unset($account['password_hash']);
        $_SESSION['user'] = $account;
        redirect('index.php');
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

    if (!$user) {
        flash('Please sign in to continue.', 'error');
        redirect('index.php?page=login');
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
            'INSERT INTO jobs (employer_id, title, company, location, work_type, description) VALUES (?, ?, ?, ?, ?, ?)'
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
        flash('Your opening is live. Applicants will be compared using job skills, work evidence, and challenge results.');
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

    if ($action === 'toggle_job') {
        $employer = require_role('employer');
        $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if (!$jobId || !in_array($status, ['open', 'closed'], true)) {
            flash('Choose a valid opening status.', 'error');
            redirect('index.php?page=jobs');
        }
        $statement = $pdo->prepare('UPDATE jobs SET status = ? WHERE id = ? AND employer_id = ?');
        $statement->execute([$status, $jobId, $employer['id']]);
        flash('Opening status updated.');
        redirect('index.php?page=jobs');
    }

    flash('That action was not recognised.', 'error');
    redirect('index.php');
}

$user = current_user();
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$knownPages = ['home', 'how-it-works', 'dashboard', 'opportunities', 'profile', 'applications', 'jobs', 'candidates', 'login', 'register'];
if (!in_array($page, $knownPages, true)) {
    $page = $user ? 'dashboard' : 'home';
}
if ($user && in_array($page, ['login', 'register'], true)) {
    redirect('index.php');
}
if (!$user && $page === 'dashboard') {
    $page = 'home';
}
if (!$user && !in_array($page, ['home', 'how-it-works', 'login', 'register', 'opportunities'], true)) {
    redirect('index.php?page=home');
}
if ($user && $user['role'] === 'jobseeker' && in_array($page, ['jobs', 'candidates'], true)) {
    redirect('index.php');
}
if ($user && $user['role'] === 'employer' && in_array($page, ['opportunities', 'profile', 'applications'], true)) {
    redirect('index.php');
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
$counts = ['applications' => 0, 'shortlisted' => 0, 'projects' => 0, 'jobs' => 0, 'candidates' => 0];

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
}

if ($user && $user['role'] === 'employer') {
    $statement = $pdo->prepare(
        "SELECT j.id, j.title, j.company, j.location, j.work_type, j.description, j.status, j.created_at,
                (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
                 FROM job_skills js JOIN skills s ON s.id = js.skill_id WHERE js.job_id = j.id) AS skills,
                (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicant_count,
                (SELECT c.title FROM challenges c WHERE c.job_id = j.id LIMIT 1) AS challenge_title
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
                    u.id AS candidate_id, u.name, u.email,
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
                "SELECT user_id, COUNT(*) AS total FROM projects WHERE user_id IN ({$candidatePlaceholders}) GROUP BY user_id"
            );
            $statement->execute($candidateIds);
            $projectsByCandidate = [];
            foreach ($statement->fetchAll() as $row) {
                $projectsByCandidate[(int) $row['user_id']] = (int) $row['total'];
            }

            $statement = $pdo->prepare(
                'SELECT s.name FROM job_skills js JOIN skills s ON s.id = js.skill_id WHERE js.job_id = ?'
            );
            $statement->execute([$selectedJob['id']]);
            $requiredSkills = array_column($statement->fetchAll(), 'name');

            foreach ($applicantRows as &$candidate) {
                $candidateSkills = $skillsByCandidate[(int) $candidate['candidate_id']] ?? [];
                $candidate['skills'] = $candidateSkills;
                $matchedSkills = array_values(array_intersect(array_map('strtolower', $requiredSkills), array_map('strtolower', $candidateSkills)));
                $skillScore = $requiredSkills ? (count($matchedSkills) / count($requiredSkills)) * 70 : 0;
                $projectScore = min(($projectsByCandidate[(int) $candidate['candidate_id']] ?? 0) * 5, 20);
                $challengeScore = $candidate['challenge_status'] === 'passed' ? 10 : ($candidate['challenge_status'] === 'submitted' ? 5 : 0);
                $candidate['matched_skills'] = $matchedSkills;
                $candidate['required_skills'] = $requiredSkills;
                $candidate['score'] = (int) round($skillScore + $projectScore + $challengeScore);
                $candidate['project_count'] = $projectsByCandidate[(int) $candidate['candidate_id']] ?? 0;
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
        'declined', 'needs_work', 'closed' => 'badge badge-muted',
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
