<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/momo.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

$notice = '';
$noticeType = 'success';
$requestedPage = isset($_GET['page']) ? (string) $_GET['page'] : '';
$view = in_array($requestedPage, ['auth', 'jobs', 'post_job', 'profile', 'public_profile', 'dashboard', 'landing'], true) ? $requestedPage : 'landing';
$authMode = isset($_GET['mode']) && $_GET['mode'] === 'signup' ? 'signup' : 'login';
$signupRole = isset($_GET['role']) && in_array($_GET['role'], ['candidate', 'employer'], true)
    ? (string) $_GET['role']
    : '';
$pendingEmail = $_SESSION['pending_login_email'] ?? '';
$jobs = [];
$jobsLoaded = false;
$candidateJobViewCount = 0;
$candidateJobViewLimit = 8;
$applicationsByJob = [];
$candidateApplications = [];
$employerDashboardData = null;
$profileData = null;
$publicProfileData = null;
$latestPremiumPayments = [];
$walletBalance = 0;
$latestWalletTopups = [];
$latestWalletTransactions = [];
$walletStorageReady = true;
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

function normalize_cameroon_phone(string $phone): ?string
{
    $digits = preg_replace('/[\s().-]+/', '', trim($phone));
    if (!is_string($digits)) {
        return null;
    }

    if (str_starts_with($digits, '+')) {
        $digits = substr($digits, 1);
    }
    if (str_contains($digits, '+')) {
        return null;
    }
    if (preg_match('/\A6\d{8}\z/', $digits)) {
        return '237' . $digits;
    }
    return preg_match('/\A2376\d{8}\z/', $digits) ? $digits : null;
}

function start_user_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'display_name' => (string) $user['display_name'],
        'is_premium' => (int) ($user['is_premium'] ?? 0),
        'premium_expires_at' => isset($user['premium_expires_at']) && $user['premium_expires_at'] !== null && $user['premium_expires_at'] !== '' ? (string) $user['premium_expires_at'] : null,
    ];
    unset($_SESSION['pending_login_user_id'], $_SESSION['pending_login_email']);
}

function user_has_active_premium(?array $user): bool
{
    if ($user === null) {
        return false;
    }

    $premium = (int) ($user['is_premium'] ?? 0);
    if ($premium !== 1) {
        return false;
    }

    $expiresAt = $user['premium_expires_at'] ?? null;
    if (!is_string($expiresAt) || $expiresAt === '') {
        return true;
    }

    $timestamp = strtotime($expiresAt . ' UTC');
    return $timestamp !== false && $timestamp > time();
}

function current_view_month(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m');
}

function load_free_candidate_jobs(PDO $pdo, int $candidateId, int $monthlyLimit): array
{
    $monthlyLimit = max(1, min(8, $monthlyLimit));
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role = :role FOR UPDATE');
        $statement->execute(['id' => $candidateId, 'role' => 'candidate']);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Your job seeker account could not be found. Sign out and sign in again.');
        }

        $viewMonth = current_view_month();
        $statement = $pdo->prepare(
            'SELECT job_id
             FROM job_views
             WHERE user_id = :user_id AND view_month = :view_month
             ORDER BY viewed_at, job_id
             LIMIT ' . $monthlyLimit
        );
        $statement->execute(['user_id' => $candidateId, 'view_month' => $viewMonth]);
        $jobIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM job_views
             WHERE user_id = :user_id AND view_month = :view_month'
        );
        $statement->execute(['user_id' => $candidateId, 'view_month' => $viewMonth]);
        $viewCount = (int) $statement->fetchColumn();
        $remainingViews = max(0, $monthlyLimit - $viewCount);

        if ($remainingViews > 0) {
            $statement = $pdo->prepare(
                'SELECT jobs.id
                 FROM jobs
                 WHERE NOT EXISTS (
                     SELECT 1
                     FROM job_views
                     WHERE job_views.user_id = :user_id
                       AND job_views.view_month = :view_month
                       AND job_views.job_id = jobs.id
                 )
                 ORDER BY (jobs.status = :available_status) DESC, jobs.created_at DESC
                 LIMIT ' . $remainingViews
            );
            $statement->execute([
                'user_id' => $candidateId,
                'view_month' => $viewMonth,
                'available_status' => 'available',
            ]);
            $newJobIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $insertView = $pdo->prepare(
                'INSERT INTO job_views (user_id, job_id, view_month, viewed_at)
                 VALUES (:user_id, :job_id, :view_month, UTC_TIMESTAMP())'
            );
            foreach ($newJobIds as $jobId) {
                $insertView->execute([
                    'user_id' => $candidateId,
                    'job_id' => $jobId,
                    'view_month' => $viewMonth,
                ]);
            }
            $jobIds = array_merge($jobIds, $newJobIds);
            $viewCount += count($newJobIds);
        }

        if ($jobIds === []) {
            $pdo->commit();
            return ['jobs' => [], 'view_count' => min($viewCount, $monthlyLimit)];
        }

        $jobPlaceholders = implode(', ', array_fill(0, count($jobIds), '?'));
        $statement = $pdo->prepare(
            "SELECT jobs.*, users.display_name AS employer_name,
                    (SELECT COUNT(*) FROM applications WHERE applications.job_id = jobs.id) AS application_count
             FROM jobs
             INNER JOIN users ON users.id = jobs.employer_id
             WHERE jobs.id IN ({$jobPlaceholders})
             ORDER BY (jobs.status = 'available') DESC, jobs.created_at DESC"
        );
        $statement->execute($jobIds);
        $jobs = $statement->fetchAll();
        $pdo->commit();

        return ['jobs' => $jobs, 'view_count' => min($viewCount, $monthlyLimit)];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function activate_premium_payment(PDO $pdo, int $userId, int $paymentId): ?string
{
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT status, premium_days FROM premium_payments
             WHERE id = :id AND user_id = :user_id
             FOR UPDATE'
        );
        $statement->execute(['id' => $paymentId, 'user_id' => $userId]);
        $payment = $statement->fetch();
        if (!$payment) {
            throw new RuntimeException('This payment could not be found for your account.');
        }
        if ($payment['status'] === 'successful') {
            $pdo->commit();
            return null;
        }
        if ($payment['status'] !== 'pending') {
            throw new RuntimeException('This payment is no longer pending.');
        }
        if ((int) $payment['premium_days'] < 1) {
            throw new RuntimeException('This payment has an invalid Premium term.');
        }

        $statement = $pdo->prepare(
            'SELECT is_premium, premium_expires_at
             FROM users
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $userId]);
        $userPremium = $statement->fetch();
        if (!$userPremium) {
            throw new RuntimeException('Your account could not be found. Sign out and sign in again.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiryBase = $now;
        if ((int) $userPremium['is_premium'] === 1 && $userPremium['premium_expires_at'] !== null) {
            $currentExpiry = new DateTimeImmutable((string) $userPremium['premium_expires_at'], new DateTimeZone('UTC'));
            if ($currentExpiry > $now) {
                $expiryBase = $currentExpiry;
            }
        }
        $premiumDays = (int) $payment['premium_days'];
        $newExpiry = $expiryBase->modify('+' . $premiumDays . ' days')->format('Y-m-d H:i:s');

        $statement = $pdo->prepare(
            'UPDATE users
             SET is_premium = 1, premium_expires_at = :premium_expires_at
             WHERE id = :id'
        );
        $statement->execute(['premium_expires_at' => $newExpiry, 'id' => $userId]);
        $statement = $pdo->prepare(
            "UPDATE premium_payments
             SET status = 'successful', completed_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND status = 'pending'"
        );
        $statement->execute(['id' => $paymentId, 'user_id' => $userId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The payment confirmation was already processed or is no longer pending.');
        }
        $pdo->commit();

        if (isset($_SESSION['user']) && (int) $_SESSION['user']['id'] === $userId) {
            $_SESSION['user']['is_premium'] = 1;
            $_SESSION['user']['premium_expires_at'] = $newExpiry;
        }

        return $newExpiry;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function credit_wallet_topup(PDO $pdo, int $userId, int $topupId): int
{
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Your account could not be found. Sign out and sign in again.');
        }

        $statement = $pdo->prepare(
            'SELECT amount, currency, status
             FROM wallet_topups
             WHERE id = :id AND user_id = :user_id
             FOR UPDATE'
        );
        $statement->execute(['id' => $topupId, 'user_id' => $userId]);
        $topup = $statement->fetch();
        if (!$topup) {
            throw new RuntimeException('This wallet top-up could not be found for your account.');
        }
        if ($topup['status'] === 'successful') {
            $statement = $pdo->prepare('SELECT balance_xaf FROM wallets WHERE user_id = :user_id');
            $statement->execute(['user_id' => $userId]);
            $balance = $statement->fetchColumn();
            if ($balance === false) {
                throw new RuntimeException('The confirmed wallet top-up has no wallet account. Contact support.');
            }
            $pdo->commit();
            return (int) $balance;
        }
        if ($topup['status'] !== 'pending'
            || (int) $topup['amount'] !== PREMIUM_CHECKOUT_AMOUNT_XAF
            || $topup['currency'] !== 'XAF') {
            throw new RuntimeException('This wallet top-up is not valid for crediting.');
        }

        $pdo->prepare('INSERT IGNORE INTO wallets (user_id) VALUES (:user_id)')
            ->execute(['user_id' => $userId]);
        $statement = $pdo->prepare('SELECT balance_xaf FROM wallets WHERE user_id = :user_id FOR UPDATE');
        $statement->execute(['user_id' => $userId]);
        $balance = (int) $statement->fetchColumn();
        $newBalance = $balance + PREMIUM_CHECKOUT_AMOUNT_XAF;

        $statement = $pdo->prepare(
            "UPDATE wallet_topups
             SET status = 'successful', completed_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND status = 'pending'"
        );
        $statement->execute(['id' => $topupId, 'user_id' => $userId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('This wallet top-up was already processed or is no longer pending.');
        }

        $statement = $pdo->prepare(
            'UPDATE wallets SET balance_xaf = :balance WHERE user_id = :user_id'
        );
        $statement->execute(['balance' => $newBalance, 'user_id' => $userId]);
        $statement = $pdo->prepare(
            "INSERT INTO wallet_transactions
             (user_id, transaction_type, amount_xaf, balance_after_xaf, event_key)
             VALUES (:user_id, 'top_up', :amount, :balance, :event_key)"
        );
        $statement->execute([
            'user_id' => $userId,
            'amount' => PREMIUM_CHECKOUT_AMOUNT_XAF,
            'balance' => $newBalance,
            'event_key' => 'topup:' . $topupId,
        ]);
        $pdo->commit();
        return $newBalance;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function purchase_premium_with_wallet(PDO $pdo, int $userId): string
{
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT is_premium, premium_expires_at FROM users WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $userId]);
        $account = $statement->fetch();
        if (!$account) {
            throw new RuntimeException('Your account could not be found. Sign out and sign in again.');
        }
        if (user_has_active_premium($account)) {
            throw new RuntimeException('Your Premium access is already active.');
        }

        $pdo->prepare('INSERT IGNORE INTO wallets (user_id) VALUES (:user_id)')
            ->execute(['user_id' => $userId]);
        $statement = $pdo->prepare('SELECT balance_xaf FROM wallets WHERE user_id = :user_id FOR UPDATE');
        $statement->execute(['user_id' => $userId]);
        $balance = (int) $statement->fetchColumn();
        if ($balance < PREMIUM_CHECKOUT_AMOUNT_XAF) {
            throw new RuntimeException('Your wallet needs at least 500 XAF to purchase Premium. Add funds to your wallet first.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiryBase = $now;
        if ((int) $account['is_premium'] === 1 && $account['premium_expires_at'] !== null) {
            $currentExpiry = new DateTimeImmutable((string) $account['premium_expires_at'], new DateTimeZone('UTC'));
            if ($currentExpiry > $now) {
                $expiryBase = $currentExpiry;
            }
        }
        $newExpiry = $expiryBase->modify('+' . PREMIUM_CHECKOUT_TERM_DAYS . ' days')->format('Y-m-d H:i:s');
        $newBalance = $balance - PREMIUM_CHECKOUT_AMOUNT_XAF;

        $statement = $pdo->prepare(
            'UPDATE wallets SET balance_xaf = :balance
             WHERE user_id = :user_id AND balance_xaf >= :amount'
        );
        $statement->execute([
            'balance' => $newBalance,
            'user_id' => $userId,
            'amount' => PREMIUM_CHECKOUT_AMOUNT_XAF,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Your wallet balance changed. Refresh the dashboard and try again.');
        }

        $statement = $pdo->prepare(
            'UPDATE users SET is_premium = 1, premium_expires_at = :expires_at WHERE id = :id'
        );
        $statement->execute(['expires_at' => $newExpiry, 'id' => $userId]);
        $statement = $pdo->prepare(
            "INSERT INTO wallet_transactions
             (user_id, transaction_type, amount_xaf, balance_after_xaf, event_key)
             VALUES (:user_id, 'premium_purchase', :amount, :balance, :event_key)"
        );
        $statement->execute([
            'user_id' => $userId,
            'amount' => PREMIUM_CHECKOUT_AMOUNT_XAF,
            'balance' => $newBalance,
            'event_key' => 'premium:' . momo_uuid(),
        ]);
        $pdo->commit();

        if (isset($_SESSION['user']) && (int) $_SESSION['user']['id'] === $userId) {
            $_SESSION['user']['is_premium'] = 1;
            $_SESSION['user']['premium_expires_at'] = $newExpiry;
        }
        return $newExpiry;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

if (isset($_GET['profile_picture'])) {
    try {
        $publicProfileId = filter_var(
            $_GET['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $user = signed_in_user();
        if ($publicProfileId !== false) {
            $statement = db()->prepare(
                'SELECT profile_picture, profile_picture_mime FROM users WHERE id = :id'
            );
            $statement->execute(['id' => $publicProfileId]);
            $cacheControl = 'public, max-age=3600';
        } elseif ($user !== null) {
            $statement = db()->prepare(
                'SELECT profile_picture, profile_picture_mime FROM users WHERE id = :id'
            );
            $statement->execute(['id' => $user['id']]);
            $cacheControl = 'private, no-store';
        } else {
            http_response_code(403);
            exit;
        }
        $picture = $statement->fetch();
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!$picture || $picture['profile_picture'] === null
            || !in_array($picture['profile_picture_mime'], $allowedMimes, true)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $picture['profile_picture_mime']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . $cacheControl);
        echo $picture['profile_picture'];
    } catch (PDOException $exception) {
        error_log('SkillsPassAurora profile picture could not be loaded: ' . $exception->getMessage());
        http_response_code(500);
    }
    exit;
}

function load_employer_dashboard_data(PDO $pdo, int $employerId): array
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS total_jobs,
                COALESCE(SUM(status = 'available'), 0) AS available_jobs,
                COALESCE(SUM(status = 'taken'), 0) AS filled_jobs
         FROM jobs
         WHERE employer_id = :employer_id"
    );
    $statement->execute(['employer_id' => $employerId]);
    $jobStats = $statement->fetch();

    $statement = $pdo->prepare(
        'SELECT applications.status, COUNT(*) AS total
         FROM applications
         INNER JOIN jobs ON jobs.id = applications.job_id
         WHERE jobs.employer_id = :employer_id
         GROUP BY applications.status'
    );
    $statement->execute(['employer_id' => $employerId]);
    $applicationStats = ['pending' => 0, 'accepted' => 0, 'rejected' => 0];
    foreach ($statement->fetchAll() as $row) {
        $applicationStats[(string) $row['status']] = (int) $row['total'];
    }

    $statement = $pdo->prepare(
        'SELECT DATE(applications.created_at) AS application_date, COUNT(*) AS total
         FROM applications
         INNER JOIN jobs ON jobs.id = applications.job_id
         WHERE jobs.employer_id = :employer_id
           AND applications.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
           AND applications.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
         GROUP BY DATE(applications.created_at)
         ORDER BY application_date'
    );
    $statement->execute(['employer_id' => $employerId]);
    $applicationsByDate = [];
    foreach ($statement->fetchAll() as $row) {
        $applicationsByDate[(string) $row['application_date']] = (int) $row['total'];
    }

    $statement = $pdo->query('SELECT CURDATE()');
    $today = new DateTimeImmutable((string) $statement->fetchColumn());
    $trend = [];
    for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
        $date = $today->modify("-{$daysAgo} days")->format('Y-m-d');
        $trend[] = [
            'label' => $today->modify("-{$daysAgo} days")->format('D'),
            'date' => $date,
            'total' => $applicationsByDate[$date] ?? 0,
        ];
    }

    $statement = $pdo->prepare(
        'SELECT id, title, location, status
         FROM jobs
         WHERE employer_id = :employer_id
         ORDER BY created_at DESC, id DESC'
    );
    $statement->execute(['employer_id' => $employerId]);
    $mapJobs = array_map(
        static fn (array $job): array => [
            'id' => (int) $job['id'],
            'title' => (string) $job['title'],
            'location' => (string) $job['location'],
            'status' => (string) $job['status'],
        ],
        $statement->fetchAll()
    );

    return [
        'jobs' => [
            'total' => (int) $jobStats['total_jobs'],
            'available' => (int) $jobStats['available_jobs'],
            'filled' => (int) $jobStats['filled_jobs'],
        ],
        'applications' => [
            'total' => array_sum($applicationStats),
            'pending' => $applicationStats['pending'],
            'accepted' => $applicationStats['accepted'],
            'rejected' => $applicationStats['rejected'],
        ],
        'trend' => $trend,
        'map_jobs' => $mapJobs,
    ];
}

if (isset($_GET['dashboard_data'])) {
    header('Content-Type: application/json; charset=utf-8');
    $user = signed_in_user();
    if ($user === null || $user['role'] !== 'employer') {
        http_response_code(403);
        echo json_encode(['error' => 'Employer access is required.']);
        exit;
    }

    try {
        echo json_encode(
            load_employer_dashboard_data(db(), (int) $user['id']),
            JSON_THROW_ON_ERROR
        );
    } catch (PDOException $exception) {
        error_log('SkillsPassAurora employer dashboard data could not be loaded: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Dashboard data could not be refreshed.']);
    }
    exit;
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
        if ($action === 'upload_profile_picture') {
            $user = signed_in_user();
            if ($user === null) {
                throw new RuntimeException('Sign in before uploading a profile picture.');
            }

            $file = $_FILES['profile_picture'] ?? null;
            if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'])
                || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Choose a profile picture to upload.');
            }
            if ((int) $file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('The profile picture could not be uploaded. Choose a file smaller than 2 MB and try again.');
            }

            $maximumPictureSize = 2 * 1024 * 1024;
            if ((int) $file['size'] < 1 || (int) $file['size'] > $maximumPictureSize
                || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new RuntimeException('Choose a valid profile picture smaller than 2 MB.');
            }

            $imageInfo = @getimagesize((string) $file['tmp_name']);
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $fileInfo->file((string) $file['tmp_name']);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            if ($imageInfo === false || !in_array($detectedMime, $allowedMimes, true)
                || ($imageInfo['mime'] ?? '') !== $detectedMime
                || $imageInfo[0] > 4096 || $imageInfo[1] > 4096
                || $imageInfo[0] * $imageInfo[1] > 16000000) {
                throw new RuntimeException('Use a valid JPEG, PNG, or WebP image up to 4096 pixels per side.');
            }

            $pictureContents = file_get_contents((string) $file['tmp_name']);
            if ($pictureContents === false) {
                throw new RuntimeException('The profile picture could not be read. Please try again.');
            }

            $statement = db()->prepare(
                'UPDATE users
                 SET profile_picture = :profile_picture, profile_picture_mime = :profile_picture_mime
                 WHERE id = :id'
            );
            $statement->bindValue(':profile_picture', $pictureContents, PDO::PARAM_LOB);
            $statement->bindValue(':profile_picture_mime', $detectedMime, PDO::PARAM_STR);
            $statement->bindValue(':id', (int) $user['id'], PDO::PARAM_INT);
            $statement->execute();
            if ($statement->rowCount() !== 1) {
                $statement = db()->prepare('SELECT id FROM users WHERE id = :id');
                $statement->execute(['id' => $user['id']]);
                if ($statement->fetchColumn() === false) {
                    throw new RuntimeException('Your account could not be found. Sign out and sign in again.');
                }
            }

            $notice = 'Your profile picture has been updated.';
            $view = 'profile';
        } elseif ($action === 'register') {
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
            $statement = $pdo->prepare('SELECT id, email, password_hash, role, display_name, failed_login_attempts, otp_hash, otp_expires_at, otp_last_sent_at, is_premium, premium_expires_at FROM users WHERE email = :email FOR UPDATE');
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

            $countStatement = $pdo->prepare(
                'SELECT COUNT(*) FROM jobs WHERE employer_id = :employer_id AND status = :status'
            );
            $countStatement->execute(['employer_id' => $user['id'], 'status' => 'available']);
            $activeJobs = (int) $countStatement->fetchColumn();
            if ($activeJobs >= 8 && !user_has_active_premium($user)) {
                throw new RuntimeException('Your free plan allows up to 8 active jobs. Upgrade to premium to post more than 8 roles.');
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
            $notice = 'Your job has been posted and is now available to job seekers.';
            $view = 'dashboard';
        } elseif ($action === 'apply_job') {
            $user = signed_in_user();
            if ($user === null || $user['role'] !== 'candidate') {
                throw new RuntimeException('Only signed-in job seekers can apply for jobs.');
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
            $premiumAllowed = user_has_active_premium($user);
            if (!$premiumAllowed) {
                $statement = $pdo->prepare(
                    'SELECT id FROM users WHERE id = :id AND role = :role FOR UPDATE'
                );
                $statement->execute(['id' => $user['id'], 'role' => 'candidate']);
                if ($statement->fetchColumn() === false) {
                    throw new RuntimeException('Your job seeker account could not be found. Sign out and sign in again.');
                }

                $jobViewMonth = current_view_month();
                $statement = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM job_views
                     WHERE user_id = :user_id AND view_month = :view_month'
                );
                $statement->execute([
                    'user_id' => $user['id'],
                    'view_month' => $jobViewMonth,
                ]);
                $jobViewsThisMonth = (int) $statement->fetchColumn();

                $statement = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM job_views
                     WHERE user_id = :user_id AND job_id = :job_id AND view_month = :view_month'
                );
                $statement->execute([
                    'user_id' => $user['id'],
                    'job_id' => $jobId,
                    'view_month' => $jobViewMonth,
                ]);
                if ((int) $statement->fetchColumn() !== 1) {
                    if ($jobViewsThisMonth >= $candidateJobViewLimit) {
                        throw new RuntimeException('Your free plan allows you to view only 8 jobs per month. Upgrade to Premium for more job views.');
                    }
                    throw new RuntimeException('Open this job from your available job listings before applying.');
                }
            }

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
        } elseif ($action === 'start_premium_payment') {
            throw new RuntimeException('Direct Premium checkout is disabled. Add funds to your wallet, then purchase Premium from your balance.');
        } elseif ($action === 'start_wallet_topup') {
            $user = signed_in_user();
            if ($user === null) {
                throw new RuntimeException('Sign in before adding funds to your wallet.');
            }
            if (!momo_sandbox_is_configured()) {
                throw new RuntimeException('MTN MoMo sandbox top-up is not configured. Ask the administrator to configure the server credentials.');
            }

            $phoneNumber = normalize_cameroon_phone((string) ($_POST['mobile_money_number'] ?? ''));
            if ($phoneNumber === null) {
                throw new RuntimeException('Enter a valid Cameroon MTN mobile number, such as +237 6XX XXX XXX.');
            }
            $accessToken = momo_access_token();
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
                $statement->execute(['id' => $user['id']]);
                if ($statement->fetchColumn() === false) {
                    throw new RuntimeException('Your account could not be found. Sign out and sign in again.');
                }

                $statement = $pdo->prepare(
                    "SELECT id FROM wallet_topups
                     WHERE user_id = :user_id AND status = 'pending'
                     ORDER BY id DESC
                     LIMIT 1
                     FOR UPDATE"
                );
                $statement->execute(['user_id' => $user['id']]);
                if ($statement->fetchColumn() !== false) {
                    throw new RuntimeException('You already have a pending wallet top-up. Refresh its status before starting another.');
                }

                $referenceId = momo_uuid();
                $externalId = bin2hex(random_bytes(16));
                $statement = $pdo->prepare(
                    "INSERT INTO wallet_topups
                     (user_id, provider_reference_id, external_id, amount, currency, status)
                     VALUES (:user_id, :provider_reference_id, :external_id, :amount, 'XAF', 'pending')"
                );
                $statement->execute([
                    'user_id' => $user['id'],
                    'provider_reference_id' => $referenceId,
                    'external_id' => $externalId,
                    'amount' => PREMIUM_CHECKOUT_AMOUNT_XAF,
                ]);
                $topupId = (int) $pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            try {
                momo_start_wallet_topup_request($referenceId, $externalId, $phoneNumber, $accessToken);
                $notice = 'A 500 XAF sandbox top-up request was sent to your MTN MoMo number. Approve it on your phone, then refresh the wallet status.';
                $noticeType = 'success';
            } catch (RuntimeException $exception) {
                if ($exception->getCode() >= 400 && $exception->getCode() < 500) {
                    $pdo->prepare(
                        "UPDATE wallet_topups SET status = 'failed'
                         WHERE id = :id AND user_id = :user_id AND status = 'pending'"
                    )->execute(['id' => $topupId, 'user_id' => $user['id']]);
                }
                throw $exception;
            }
            $view = 'dashboard';
        } elseif ($action === 'buy_premium_wallet') {
            $user = signed_in_user();
            if ($user === null) {
                throw new RuntimeException('Sign in before purchasing Premium.');
            }
            $expiry = purchase_premium_with_wallet(db(), (int) $user['id']);
            $notice = 'Premium purchased from your wallet and active until ' . $expiry . ' UTC.';
            $view = 'dashboard';
        } elseif ($action === 'check_wallet_topup') {
            $user = signed_in_user();
            if ($user === null) {
                throw new RuntimeException('Sign in to check your wallet top-up status.');
            }

            $topupId = filter_var(
                $_POST['topup_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($topupId === false) {
                throw new RuntimeException('Choose a valid wallet top-up.');
            }

            $pdo = db();
            $statement = $pdo->prepare(
                'SELECT id, provider_reference_id, external_id, amount, currency, status
                 FROM wallet_topups
                 WHERE id = :id AND user_id = :user_id'
            );
            $statement->execute(['id' => $topupId, 'user_id' => $user['id']]);
            $topup = $statement->fetch();
            if (!$topup) {
                throw new RuntimeException('This wallet top-up could not be found for your account.');
            }

            if ($topup['status'] === 'successful') {
                $notice = 'This wallet top-up was already confirmed.';
            } elseif ($topup['status'] !== 'pending') {
                throw new RuntimeException('This wallet top-up did not complete. Start a new top-up when ready.');
            } else {
                $providerPayment = momo_read_wallet_topup_request((string) $topup['provider_reference_id']);
                $providerStatus = strtoupper((string) ($providerPayment['status'] ?? ''));
                if ($providerStatus === 'SUCCESSFUL') {
                    if (!momo_payment_matches_order($providerPayment, $topup)) {
                        error_log(sprintf(
                            'SkillsPassAurora MTN MoMo confirmation did not match wallet top-up %d.',
                            $topupId
                        ));
                        throw new RuntimeException('The provider confirmation did not match this wallet top-up. No funds were added; contact support with your reference.');
                    }

                    $balance = credit_wallet_topup($pdo, (int) $user['id'], $topupId);
                    $notice = 'Top-up confirmed. Your wallet balance is now ' . number_format($balance) . ' XAF.';
                } elseif ($providerStatus === 'FAILED') {
                    $pdo->prepare(
                        "UPDATE wallet_topups SET status = 'failed'
                         WHERE id = :id AND user_id = :user_id AND status = 'pending'"
                    )->execute(['id' => $topupId, 'user_id' => $user['id']]);
                    throw new RuntimeException('The mobile-money top-up was declined or cancelled. You can start a new top-up.');
                } else {
                    $notice = 'The mobile-money top-up is still pending. Approve the request on your phone, then refresh again.';
                }
            }
            $view = 'dashboard';
        } elseif ($action === 'check_premium_payment') {
            $user = signed_in_user();
            if ($user === null) {
                throw new RuntimeException('Sign in to check your payment status.');
            }

            $paymentId = filter_var(
                $_POST['payment_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($paymentId === false) {
                throw new RuntimeException('Choose a valid Premium payment.');
            }

            $pdo = db();
            $statement = $pdo->prepare(
                'SELECT id, user_id, provider_reference_id, external_id, amount, currency, premium_days, status
                 FROM premium_payments
                 WHERE id = :id AND user_id = :user_id'
            );
            $statement->execute(['id' => $paymentId, 'user_id' => $user['id']]);
            $payment = $statement->fetch();
            if (!$payment) {
                throw new RuntimeException('This payment could not be found for your account.');
            }

            if ($payment['status'] === 'successful') {
                $notice = 'This payment was already confirmed. Premium access is active.';
            } elseif ($payment['status'] !== 'pending') {
                throw new RuntimeException('This payment did not complete. Start a new payment when you are ready.');
            } else {
                $providerPayment = momo_read_premium_request((string) $payment['provider_reference_id']);
                $providerStatus = strtoupper((string) ($providerPayment['status'] ?? ''));
                if ($providerStatus === 'SUCCESSFUL') {
                    if (!momo_payment_matches_order($providerPayment, $payment)) {
                        error_log(sprintf(
                            'SkillsPassAurora MTN MoMo confirmation did not match payment record %d.',
                            $paymentId
                        ));
                        throw new RuntimeException('The provider confirmation did not match this order. Premium was not activated; contact support with your payment reference.');
                    }

                    $expiry = activate_premium_payment($pdo, (int) $user['id'], $paymentId);
                    $notice = $expiry === null
                        ? 'Payment confirmed. Your Premium access has been extended.'
                        : 'Payment confirmed. Premium is active until ' . $expiry . ' UTC.';
                } elseif ($providerStatus === 'FAILED') {
                    $pdo->prepare(
                        "UPDATE premium_payments SET status = 'failed'
                         WHERE id = :id AND user_id = :user_id AND status = 'pending'"
                    )->execute(['id' => $paymentId, 'user_id' => $user['id']]);
                    throw new RuntimeException('The mobile-money payment was declined or cancelled. You can start a new payment.');
                } else {
                    $notice = 'The mobile-money payment is still pending. Approve the request on your phone, then refresh again.';
                }
            }
            $view = 'dashboard';
        } elseif ($action === 'hire_candidate') {
            $user = signed_in_user();
            if ($user === null || $user['role'] !== 'employer') {
                throw new RuntimeException('Only signed-in employers can select a job seeker.');
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
            $statement->execute([
                'job_id' => $jobId,
                'candidate_id' => $candidateId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('That job seeker does not have a pending application for this job.');
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

            $notice = 'Job seeker selected. The job is now marked Taken and other pending applications are closed.';
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
            ['start_wallet_topup', '42S02'], ['check_wallet_topup', '42S02'], ['buy_premium_wallet', '42S02'] => 'Wallet storage is not set up yet. Import schema.sql, then try again.',
            ['start_wallet_topup', '42S22'], ['check_wallet_topup', '42S22'], ['buy_premium_wallet', '42S22'] => 'Wallet storage does not match the current schema. Re-import schema.sql, then try again.',
            ['check_premium_payment', '42S02'] => 'Premium payment storage is not set up yet. Import schema.sql, then try again.',
            ['upload_profile_picture', '42S22'] => 'Profile picture storage is not set up yet. Re-import schema.sql, then try again.',
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
    $user = signed_in_user();
    if ($view !== 'landing' && $view !== 'public_profile') {
        $view = $view === 'jobs'
            ? 'jobs'
            : ($view === 'post_job' && $user['role'] === 'employer'
                ? 'post_job'
                : ($view === 'profile' ? 'profile' : 'dashboard'));
    }
} elseif (in_array($view, ['post_job', 'profile', 'dashboard'], true)) {
    $view = 'auth';
}

try {
    $pdo = db();
    $user = signed_in_user();
    if ($user !== null && $user['role'] === 'candidate' && !user_has_active_premium($user)
        && in_array($view, ['jobs', 'dashboard'], true)) {
        $candidateJobs = load_free_candidate_jobs($pdo, (int) $user['id'], $candidateJobViewLimit);
        $jobs = $candidateJobs['jobs'];
        $candidateJobViewCount = $candidateJobs['view_count'];
    } else {
        $statement = $pdo->query(
            "SELECT jobs.*, users.display_name AS employer_name,
                    (SELECT COUNT(*) FROM applications WHERE applications.job_id = jobs.id) AS application_count
             FROM jobs
             INNER JOIN users ON users.id = jobs.employer_id
             ORDER BY (jobs.status = 'available') DESC, jobs.created_at DESC"
        );
        $jobs = $statement->fetchAll();
    }
    $jobsLoaded = true;

    if ($view === 'public_profile') {
        $publicProfileId = filter_var(
            $_GET['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($publicProfileId !== false) {
            $statement = $pdo->prepare(
                'SELECT id, display_name, role, created_at, profile_picture_mime
                 FROM users WHERE id = :id'
            );
            $statement->execute(['id' => $publicProfileId]);
            $publicProfileData = $statement->fetch() ?: null;
        }
        if ($publicProfileData === null) {
            http_response_code(404);
        }
    }
    if ($user !== null) {
        $statement = $pdo->prepare(
            'SELECT display_name, email, role, created_at, profile_picture_mime
             FROM users WHERE id = :id'
        );
        $statement->execute(['id' => $user['id']]);
        $profileData = $statement->fetch() ?: null;
    }
    if ($user !== null && $view === 'dashboard') {
        try {
            $pdo->prepare('INSERT IGNORE INTO wallets (user_id) VALUES (:user_id)')
                ->execute(['user_id' => $user['id']]);
            $statement = $pdo->prepare('SELECT balance_xaf FROM wallets WHERE user_id = :user_id');
            $statement->execute(['user_id' => $user['id']]);
            $walletBalance = (int) $statement->fetchColumn();

            $statement = $pdo->prepare(
                'SELECT id, amount, currency, status, created_at
                 FROM wallet_topups
                 WHERE user_id = :user_id
                 ORDER BY id DESC
                 LIMIT 5'
            );
            $statement->execute(['user_id' => $user['id']]);
            $latestWalletTopups = $statement->fetchAll();

            $statement = $pdo->prepare(
                'SELECT transaction_type, amount_xaf, balance_after_xaf, created_at
                 FROM wallet_transactions
                 WHERE user_id = :user_id
                 ORDER BY id DESC
                 LIMIT 5'
            );
            $statement->execute(['user_id' => $user['id']]);
            $latestWalletTransactions = $statement->fetchAll();
        } catch (PDOException $exception) {
            error_log('SkillsPassAurora wallet data could not be loaded: ' . $exception->getMessage());
            $walletStorageReady = false;
        }

        try {
            $statement = $pdo->prepare(
                'SELECT id, amount, currency, premium_days, status, created_at
                 FROM premium_payments
                 WHERE user_id = :user_id
                 ORDER BY id DESC
                 LIMIT 5'
            );
            $statement->execute(['user_id' => $user['id']]);
            $latestPremiumPayments = $statement->fetchAll();
        } catch (PDOException $exception) {
            error_log('SkillsPassAurora Premium payment history could not be loaded: ' . $exception->getMessage());
            $notice = 'Premium payment history is unavailable. Re-import schema.sql to restore payment records.';
            $noticeType = 'error';
        }
    }
    if ($user !== null && $user['role'] === 'employer') {
        $employerDashboardData = load_employer_dashboard_data($pdo, (int) $user['id']);
        $statement = $pdo->prepare(
            'SELECT applications.id AS application_id, applications.job_id, applications.candidate_id,
                    applications.status, applications.created_at,
                    applications.cover_letter, applications.relevant_experience, applications.portfolio_url,
                    users.display_name AS candidate_name, users.email AS candidate_email,
                    1 AS is_unlocked
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
    error_log('SkillsPassAurora dashboard data could not be loaded: ' . $exception->getMessage());
    $notice = 'Dashboard data could not be loaded. Check the database setup and try again.';
    $noticeType = 'error';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SkillsPassAurora | Your Skills Are Not Useless</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?= $view === 'landing' ? 'landing-site' : 'application-site' ?>">
<header class="topbar">
    <nav class="nav" aria-label="Main navigation">
        <a class="brand" href="index.php?page=landing" aria-label="SkillsPassAurora home">
            <span class="brand-logo-frame">
                <img class="brand-logo" src="images/logo.jpeg" alt="">
            </span>
            <span class="brand-name">SkillsPassAurora</span>
        </a>
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
<?php if ($view !== 'landing'): ?>
    <?php
    $navigationUser = signed_in_user();
    $navigationRole = ($navigationUser['role'] ?? '') === 'candidate' ? 'Job Seeker' : 'Employer';
    ?>
    <div class="dashboard-layout">
        <aside class="dashboard-sidebar" aria-label="Page navigation">
            <a class="sidebar-brand" href="index.php?page=landing" aria-label="SkillsPassAurora home">
                <span class="sidebar-brand-logo"><img src="images/logo.jpeg" alt=""></span>
                <span>SkillsPassAurora</span>
            </a>
            <?php if ($navigationUser !== null): ?>
                <p class="sidebar-label">Workspace</p>
                <div class="sidebar-workspace">
                    <span class="workspace-avatar">
                        <?= escape(strtoupper(substr((string) ($navigationUser['display_name'] ?? 'U'), 0, 1))) ?>
                        <?php if ($profileData !== null && $profileData['profile_picture_mime'] !== null): ?>
                            <img class="sidebar-avatar-image" src="index.php?profile_picture=1" alt="" data-sidebar-avatar>
                        <?php endif; ?>
                    </span>
                    <span class="workspace-copy">
                        <strong><?= escape($navigationUser['display_name'] ?? '') ?></strong>
                        <small><?= escape($navigationRole) ?> workspace</small>
                    </span>
                    <span class="workspace-chevron" aria-hidden="true"></span>
                </div>
            <?php endif; ?>
            <p class="sidebar-label sidebar-section-label">Workspace</p>
            <nav class="sidebar-links" aria-label="Workspace">
                <a class="sidebar-link" href="index.php?page=landing">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path></svg>
                    <span>Landing page</span>
                </a>
                <?php if ($navigationUser !== null): ?>
                    <a class="sidebar-link<?= $view === 'dashboard' ? ' is-active' : '' ?>" href="index.php?page=dashboard" <?= $view === 'dashboard' ? 'aria-current="page"' : '' ?>>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"></rect><rect x="14" y="4" width="6" height="6" rx="1"></rect><rect x="4" y="14" width="6" height="6" rx="1"></rect><rect x="14" y="14" width="6" height="6" rx="1"></rect></svg>
                        <span>Overview</span>
                    </a>
                <?php endif; ?>
                <?php if (($navigationUser['role'] ?? '') === 'employer'): ?>
                    <a class="sidebar-link<?= $view === 'post_job' ? ' is-active' : '' ?>" href="index.php?page=post_job" <?= $view === 'post_job' ? 'aria-current="page"' : '' ?>>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"></path><rect x="3" y="3" width="18" height="18" rx="3"></rect></svg>
                        <span>Post a job</span>
                    </a>
                <?php endif; ?>
                <a class="sidebar-link<?= $view === 'jobs' ? ' is-active' : '' ?>" href="index.php?page=jobs" <?= $view === 'jobs' ? 'aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
                    <span>Browse Jobs</span>
                </a>
            </nav>
            <?php if ($navigationUser !== null): ?>
                <p class="sidebar-label sidebar-section-label">Preferences</p>
                <nav class="sidebar-links" aria-label="Preferences">
                    <a class="sidebar-link<?= $view === 'profile' ? ' is-active' : '' ?>" href="index.php?page=profile" <?= $view === 'profile' ? 'aria-current="page"' : '' ?>>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c.5-3.5 3.2-5.5 7-5.5s6.5 2 7 5.5"></path></svg>
                        <span>Profile</span>
                    </a>
                </nav>
                <div class="sidebar-footer">
                    <span class="sidebar-user-avatar">
                        <?= escape(strtoupper(substr((string) ($navigationUser['display_name'] ?? 'U'), 0, 1))) ?>
                        <?php if ($profileData !== null && $profileData['profile_picture_mime'] !== null): ?>
                            <img class="sidebar-avatar-image" src="index.php?profile_picture=1" alt="" data-sidebar-avatar>
                        <?php endif; ?>
                    </span>
                    <span class="sidebar-user-copy">
                        <strong><?= escape($navigationUser['display_name'] ?? '') ?></strong>
                        <small><?= escape($navigationRole) ?></small>
                    </span>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button class="sidebar-logout" type="submit" aria-label="Sign out" title="Sign out">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"></path><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"></path></svg>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="sidebar-guest-actions">
                    <a class="sidebar-link<?= $view === 'auth' && $authMode === 'login' ? ' is-active' : '' ?>" href="index.php?page=auth&amp;mode=login">Sign in</a>
                    <a class="sidebar-link" href="index.php?page=auth&amp;mode=signup">Create account</a>
                </div>
            <?php endif; ?>
        </aside>
        <div class="dashboard-content">
<?php endif; ?>
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
                <form class="stack" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="verify">
                    <div class="field">
                        <label for="otp">Sign-in code</label>
                        <input id="otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                    </div>
                    <button type="submit">Verify and sign in</button>
                </form>
                <form class="resend-form" method="post" autocomplete="off">
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
                    <p class="eyebrow">Skills-first opportunities</p>
                    <h1>SkillsPassAurora</h1>
                    <p class="landing-motto">Your skills are not useless.</p>
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
                <p>Share an opportunity, review job seekers’ skills and work samples, and find the right fit for your team.</p>
            </div>
            <a class="primary-link" href="index.php?page=auth&amp;mode=signup&amp;role=employer">Find your next hire <span aria-hidden="true">&rarr;</span></a>
        </section>
    <?php elseif ($view === 'jobs'): ?>
        <?php $isEmployerBrowsingJobs = (signed_in_user()['role'] ?? '') === 'employer'; ?>
        <section class="jobs-page-intro">
            <p class="eyebrow">SkillsPassAurora opportunities</p>
            <h1><?= $isEmployerBrowsingJobs ? 'Browse Jobs' : 'Find your next opportunity.' ?></h1>
            <p class="muted"><?= $isEmployerBrowsingJobs
                ? 'Explore job listings across SkillsPassAurora and see what opportunities employers have posted.'
                : 'Explore current job openings and find a role that matches your skills.' ?></p>
        </section>
    <?php elseif ($view === 'public_profile'): ?>
        <?php if ($publicProfileData !== null): ?>
            <section class="profile-page" aria-labelledby="profile-title">
                <p class="eyebrow">Public profile</p>
                <h1 id="profile-title"><?= escape($publicProfileData['display_name']) ?></h1>
                <p class="muted">This profile is publicly visible. Contact details and private application information are not shown here.</p>
                <section class="profile-panel" aria-label="Public profile details">
                    <div class="profile-heading">
                        <span class="profile-avatar-wrap">
                            <span class="profile-avatar profile-avatar-fallback" aria-hidden="true"><?= escape(strtoupper(substr((string) $publicProfileData['display_name'], 0, 1))) ?></span>
                            <?php if ($publicProfileData['profile_picture_mime'] !== null): ?>
                                <img
                                    class="profile-avatar profile-avatar-image"
                                    src="index.php?profile_picture=1&amp;user_id=<?= (int) $publicProfileData['id'] ?>"
                                    alt="Profile picture for <?= escape($publicProfileData['display_name']) ?>"
                                >
                            <?php endif; ?>
                        </span>
                        <div>
                            <h2><?= escape($publicProfileData['display_name']) ?></h2>
                            <p class="muted"><?= escape(ucfirst((string) $publicProfileData['role'])) ?></p>
                        </div>
                    </div>
                    <dl class="profile-details">
                        <div><dt>Account type</dt><dd><?= escape(ucfirst((string) $publicProfileData['role'])) ?></dd></div>
                        <div><dt>Member since</dt><dd><?= escape((new DateTimeImmutable((string) $publicProfileData['created_at']))->format('F j, Y')) ?></dd></div>
                    </dl>
                </section>
                <a class="job-login-link" href="index.php?page=jobs">Browse jobs</a>
            </section>
        <?php else: ?>
            <section class="empty-state" aria-labelledby="profile-not-found">
                <h1 id="profile-not-found">Profile not found</h1>
                <p>This public profile is unavailable.</p>
                <a href="index.php">Return to SkillsPassAurora</a>
            </section>
        <?php endif; ?>
    <?php elseif ($view === 'profile' && $profileData !== null): ?>
        <section class="profile-page" aria-labelledby="profile-title">
            <p class="eyebrow">Your account</p>
            <h1 id="profile-title">Your profile</h1>
            <p class="muted">Review your account details and manage your profile picture.</p>
            <section class="profile-panel" aria-label="Profile details">
                <div class="profile-heading">
                    <span class="profile-avatar-wrap">
                        <span class="profile-avatar profile-avatar-fallback" data-profile-fallback aria-hidden="true"><?= escape(strtoupper(substr((string) $profileData['display_name'], 0, 1))) ?></span>
                        <img
                            class="profile-avatar profile-avatar-image"
                            data-profile-preview
                            <?php if ($profileData['profile_picture_mime'] !== null): ?>src="index.php?profile_picture=1"<?php endif; ?>
                            alt="Profile picture for <?= escape($profileData['display_name']) ?>"
                            <?= $profileData['profile_picture_mime'] === null ? 'hidden' : '' ?>
                        >
                    </span>
                    <div>
                        <h2><?= escape($profileData['display_name']) ?></h2>
                        <p class="muted">Your name, account type, member date, and profile picture are visible to everyone. Your email stays private.</p>
                    </div>
                </div>
                <dl class="profile-details">
                    <div><dt>Name</dt><dd><?= escape($profileData['display_name']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= escape($profileData['email']) ?></dd></div>
                    <div><dt>Account type</dt><dd><?= escape(ucfirst((string) $profileData['role'])) ?></dd></div>
                    <div><dt>Member since</dt><dd><?= escape((new DateTimeImmutable((string) $profileData['created_at']))->format('F j, Y')) ?></dd></div>
                </dl>
                <p><a class="job-login-link" href="index.php?page=public_profile&amp;id=<?= (int) $user['id'] ?>">View your public profile</a></p>
                <form class="profile-picture-form" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="upload_profile_picture">
                    <div class="field">
                        <label for="profile-picture">Profile picture</label>
                        <input id="profile-picture" name="profile_picture" type="file" accept="image/jpeg,image/png,image/webp" required>
                        <span class="field-hint">JPEG, PNG, or WebP. Maximum file size: 2 MB.</span>
                    </div>
                    <button type="submit">Upload picture</button>
                    <span class="profile-picture-status" data-profile-picture-status role="status" aria-live="polite"></span>
                </form>
            </section>
        </section>
    <?php elseif ($view === 'post_job'): ?>
        <section class="auth-panel job-form-panel" id="post-job">
            <p class="eyebrow">For employers</p>
            <h1>Post a job</h1>
            <p class="muted">Share a role with job seekers looking for their next opportunity.</p>
            <form class="stack" method="post" autocomplete="off">
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
    <?php elseif ($view === 'dashboard'): ?>
        <?php $user = signed_in_user(); ?>
        <?php $workspaceRole = ($user['role'] ?? '') === 'candidate' ? 'Job Seeker' : 'Employer'; ?>
        <section class="welcome-panel dashboard-welcome" id="dashboard-overview">
            <p class="eyebrow">Welcome</p>
            <h1>Welcome to SkillsPassAurora, <?= escape($user['display_name'] ?? '') ?>.</h1>
            <p class="muted">Your <?= escape($workspaceRole) ?> account is ready. Browse jobs and see their current availability below.</p>
        </section>
        <section class="wallet-panel" id="wallet-panel" aria-labelledby="wallet-title">
            <div class="wallet-heading">
                <div>
                    <p class="eyebrow">Digital wallet</p>
                    <h2 id="wallet-title">Wallet balance</h2>
                </div>
                <strong class="wallet-balance"><?= number_format($walletBalance) ?> XAF</strong>
            </div>
            <p class="muted">Add 500 XAF using MTN MoMo, then use your wallet balance to purchase 50 days of Premium. Wallet funds are only for Premium and cannot be withdrawn.</p>
            <?php if (!$walletStorageReady): ?>
                <p class="wallet-setup-note">Wallet storage is not set up. Import <code>schema.sql</code>, then reload the dashboard.</p>
            <?php elseif (!momo_sandbox_is_configured()): ?>
                <p class="wallet-setup-note">MTN MoMo sandbox top-up is not configured. An administrator must configure the sandbox credentials on the server.</p>
            <?php elseif (in_array('pending', array_column($latestWalletTopups, 'status'), true)): ?>
                <p class="wallet-setup-note">A top-up is pending. Refresh its status below before starting another.</p>
            <?php else: ?>
                <form class="wallet-topup-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="start_wallet_topup">
                    <div class="field">
                        <label for="wallet-mobile-number">MTN Cameroon mobile-money number</label>
                        <input id="wallet-mobile-number" name="mobile_money_number" type="tel" autocomplete="tel" maxlength="24" placeholder="+237 6XX XXX XXX" required>
                        <span class="field-hint">Only a sandbox test number works while the integration is in test mode.</span>
                    </div>
                    <button type="submit">Add 500 XAF to wallet</button>
                </form>
            <?php endif; ?>
            <?php if ($latestWalletTopups !== []): ?>
                <div class="wallet-history">
                    <h3>Recent wallet top-ups</h3>
                    <?php foreach ($latestWalletTopups as $topup): ?>
                        <div class="wallet-history-row">
                            <div>
                                <strong><?= number_format((int) $topup['amount']) ?> <?= escape($topup['currency']) ?> top-up</strong>
                                <span class="muted">Started <?= escape((string) $topup['created_at']) ?> UTC</span>
                            </div>
                            <span class="premium-status premium-status-<?= escape((string) $topup['status']) ?>"><?= escape(ucfirst((string) $topup['status'])) ?></span>
                            <?php if ($topup['status'] === 'pending'): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="check_wallet_topup">
                                    <input type="hidden" name="topup_id" value="<?= (int) $topup['id'] ?>">
                                    <button class="secondary" type="submit">Refresh status</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($latestWalletTransactions !== []): ?>
                <div class="wallet-history">
                    <h3>Wallet activity</h3>
                    <?php foreach ($latestWalletTransactions as $transaction): ?>
                        <div class="wallet-history-row">
                            <div>
                                <strong><?= $transaction['transaction_type'] === 'top_up' ? 'Wallet top-up' : 'Premium purchase' ?></strong>
                                <span class="muted"><?= escape((string) $transaction['created_at']) ?> UTC · Balance <?= number_format((int) $transaction['balance_after_xaf']) ?> XAF</span>
                            </div>
                            <strong><?= $transaction['transaction_type'] === 'top_up' ? '+' : '&minus;' ?><?= number_format((int) $transaction['amount_xaf']) ?> XAF</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p class="wallet-sandbox-note">Sandbox only: top-ups do not charge real money. Live payments require an approved MTN production merchant account and production credentials configured privately on the server.</p>
        </section>
        <section class="premium-panel" aria-labelledby="premium-title">
            <div class="premium-heading">
                <div>
                    <p class="eyebrow">Membership</p>
                    <h2 id="premium-title">Premium access</h2>
                </div>
                <?php if (user_has_active_premium($user)): ?>
                    <span class="premium-status premium-status-successful">Active</span>
                <?php else: ?>
                    <span class="premium-status">Free plan</span>
                <?php endif; ?>
            </div>
            <p class="muted">Use <?= number_format(PREMIUM_CHECKOUT_AMOUNT_XAF) ?> XAF from your wallet for <?= PREMIUM_CHECKOUT_TERM_DAYS ?> days of Premium.</p>
            <?php if (user_has_active_premium($user)): ?>
                <?php if (!empty($user['premium_expires_at'])): ?>
                    <p class="premium-expiry">Your Premium access expires <?= escape((string) $user['premium_expires_at']) ?> UTC.</p>
                <?php else: ?>
                    <p class="premium-expiry">Your Premium access is active with no scheduled expiry.</p>
                <?php endif; ?>
            <?php elseif (!$walletStorageReady): ?>
                <p class="premium-setup-note">Wallet storage is not set up. Import <code>schema.sql</code>, then reload the dashboard.</p>
            <?php elseif ($walletBalance < PREMIUM_CHECKOUT_AMOUNT_XAF): ?>
                <p class="premium-setup-note">Your wallet needs at least <?= number_format(PREMIUM_CHECKOUT_AMOUNT_XAF) ?> XAF. <a href="#wallet-panel">Add funds to your wallet</a> first.</p>
            <?php else: ?>
                <form class="wallet-topup-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="buy_premium_wallet">
                    <button type="submit">Use wallet for <?= number_format(PREMIUM_CHECKOUT_AMOUNT_XAF) ?> XAF Premium</button>
                </form>
            <?php endif; ?>
            <?php if ($latestPremiumPayments !== []): ?>
                <div class="premium-history">
                    <h3>Recent Premium payments</h3>
                    <?php foreach ($latestPremiumPayments as $payment): ?>
                        <div class="premium-payment-row">
                            <div>
                                <strong><?= number_format((int) $payment['amount']) ?> <?= escape($payment['currency']) ?> · <?= (int) $payment['premium_days'] ?> days</strong>
                                <span class="muted">Started <?= escape((string) $payment['created_at']) ?> UTC</span>
                            </div>
                            <span class="premium-status premium-status-<?= escape((string) $payment['status']) ?>"><?= escape(ucfirst((string) $payment['status'])) ?></span>
                            <?php if ($payment['status'] === 'pending'): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="check_premium_payment">
                                    <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                    <button class="secondary" type="submit">Refresh status</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p class="premium-sandbox-note">A wallet top-up is credited only after MTN confirms the exact amount, currency, and transaction. Premium is activated only after an atomic wallet debit succeeds.</p>
        </section>
        <?php if (($user['role'] ?? '') === 'employer'): ?>
            <section class="employer-insights" aria-labelledby="employer-insights-title">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Hiring activity</p>
                        <h2 id="employer-insights-title">Your dashboard</h2>
                    </div>
                    <div class="chart-live-controls">
                        <span class="chart-live-status" data-chart-status role="status" aria-live="polite">
                            <?= $employerDashboardData === null ? 'Dashboard data unavailable' : 'Live · refreshes every 30 seconds' ?>
                        </span>
                        <?php if ($employerDashboardData !== null): ?>
                            <button class="chart-refresh-button secondary" type="button" data-chart-refresh>Refresh charts</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($employerDashboardData === null): ?>
                    <p class="empty-state">Dashboard insights are temporarily unavailable. Use Refresh charts to try again.</p>
                <?php else: ?>
                    <div class="dashboard-metrics" data-dashboard-metrics>
                        <article class="dashboard-metric">
                            <span>Total jobs</span>
                            <strong data-metric="jobs"><?= $employerDashboardData['jobs']['total'] ?></strong>
                        </article>
                        <article class="dashboard-metric">
                            <span>Total applications</span>
                            <strong data-metric="applications"><?= $employerDashboardData['applications']['total'] ?></strong>
                        </article>
                        <article class="dashboard-metric">
                            <span>Awaiting review</span>
                            <strong data-metric="pending"><?= $employerDashboardData['applications']['pending'] ?></strong>
                        </article>
                        <article class="dashboard-metric">
                            <span>Jobs filled</span>
                            <strong data-metric="filled"><?= $employerDashboardData['jobs']['filled'] ?></strong>
                        </article>
                    </div>
                    <div class="dashboard-charts" data-dashboard-charts>
                        <article class="chart-panel">
                            <div class="chart-heading">
                                <div>
                                    <h3>Applications over time</h3>
                                    <p class="muted">Daily applications · last 7 days</p>
                                </div>
                                <strong class="chart-total" data-trend-total><?= array_sum(array_column($employerDashboardData['trend'], 'total')) ?></strong>
                            </div>
                            <svg class="dashboard-chart" data-chart="trend" role="img" aria-label="Applications received over the last seven days"></svg>
                        </article>
                        <article class="chart-panel">
                            <div class="chart-heading">
                                <div>
                                    <h3>Application status</h3>
                                    <p class="muted">Your job seekers by review status</p>
                                </div>
                            </div>
                            <svg class="dashboard-chart status-chart" data-chart="status" role="img" aria-label="Applications by pending, accepted, and rejected status"></svg>
                        </article>
                    </div>
                    <script type="application/json" id="employer-dashboard-data"><?= json_encode($employerDashboardData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php else: ?>
        <section class="auth-page">
            <div class="auth-brand-panel">
                <a class="back-home" href="index.php">&larr; Back to home</a>
                <img src="images/logo.jpeg" alt="SkillsPassAurora — Your Skills Are Not Useless">
                <p>Your skills are not useless.</p>
            </div>
            <div class="auth-panel auth-page-panel">
                <p class="eyebrow"><?= $authMode === 'signup' ? 'Join the community' : 'Welcome back' ?></p>
                <h1><?= $authMode === 'signup' ? 'Create your account' : 'Sign in to SkillsPass' ?></h1>
                <p class="auth-intro"><?= $authMode === 'signup' ? ($signupRole === '' ? 'Choose how you want to use SkillsPassAurora.' : 'Create your ' . ($signupRole === 'candidate' ? 'job seeker' : 'employer') . ' account.') : 'Continue to your SkillsPassAurora account.' ?></p>
                <?php if ($authMode === 'login'): ?>
                <form class="stack" method="post" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <div class="field">
                        <label for="login-email">Email address</label>
                        <input id="login-email" name="email" type="email" autocomplete="username" required>
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
                            <strong>Job Seeker</strong>
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
                    <span>Creating an account as an <strong><?= $signupRole === 'candidate' ? 'Job Seeker' : 'Employer' ?></strong></span>
                    <a href="index.php?page=auth&amp;mode=signup">Change</a>
                </div>
                <form class="stack" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="role" value="<?= escape($signupRole) ?>">
                    <div class="field">
                        <label for="display-name">Your name or company</label>
                        <input id="display-name" name="display_name" maxlength="150" autocomplete="off" required>
                    </div>
                    <div class="field">
                        <label for="register-email">Email address</label>
                        <input id="register-email" name="email" type="email" autocomplete="off" required>
                    </div>
                    <div class="field">
                        <label for="register-password">Create password</label>
                        <div class="password-wrap">
                            <input id="register-password" name="password" type="password" minlength="10" autocomplete="off" required>
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
                    <h2><?= $view === 'jobs'
                        ? ((signed_in_user()['role'] ?? '') === 'employer' ? 'Browse Jobs' : 'Available jobs')
                        : 'Browse jobs' ?></h2>
                </div>
                <p class="muted"><?= $view === 'jobs' ? 'Explore current opportunities and check each listing’s availability before applying.' : 'Each listing shows whether it is available or has been taken.' ?></p>
            </div>
            <?php if ($user !== null && $user['role'] === 'candidate' && !user_has_active_premium($user)): ?>
                <p class="candidate-view-limit" role="status">
                    You have viewed <?= $candidateJobViewCount ?> of <?= $candidateJobViewLimit ?> free jobs this month.
                    <?php if ($candidateJobViewCount >= $candidateJobViewLimit): ?>
                        Your allowance is used. <a href="index.php?page=dashboard#premium-title">Upgrade to Premium for more job views</a>.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
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
                                        <form class="application-form" method="post" autocomplete="off">
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
                                    <a class="job-login-link" href="index.php?page=auth&amp;mode=login">Sign in as a job seeker to apply</a>
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
                                                <?php if ((bool) $application['is_unlocked']): ?>
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
                                                            <button type="submit">Select job seeker</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="application-status"><?= escape(ucfirst((string) $application['status'])) ?></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div>
                                                        <strong>Applicant details are visible once the employer has selected the job seeker.</strong>
                                                    </div>
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
    <?php if ($view === 'dashboard' && $user !== null && $user['role'] === 'employer' && $employerDashboardData !== null): ?>
        <section class="chart-panel location-map-panel" aria-labelledby="job-location-map-title">
            <div class="chart-heading">
                <div>
                    <h2 id="job-location-map-title">Job locations</h2>
                    <p class="muted">Live map of your posted jobs</p>
                </div>
                <strong class="chart-total" data-map-count><?= count($employerDashboardData['map_jobs']) ?></strong>
            </div>
            <div class="location-map-layout">
                <div id="employer-jobs-map" class="employer-jobs-map" role="region" aria-label="World map showing the locations of employer job listings">
                    <svg class="world-map-art" viewBox="0 0 450 330" aria-hidden="true" focusable="false">
                        <path d="M37 106 29 99 34 90 44 87 49 78 61 74 68 66 83 63 91 56 105 59 113 53 130 58 138 67 151 70 157 81 173 84 179 94 192 99 198 109 193 118 181 121 173 130 162 133 152 142 144 154 133 156 126 146 117 141 110 131 100 127 92 120 79 120 72 115 59 118 49 113zM139 119 146 126 153 137 150 151 158 163 156 178 165 190 161 204 168 217 162 232 165 248 156 262 152 280 145 295 139 284 138 268 131 255 132 239 124 227 126 211 119 198 123 183 117 170 122 156 117 145 124 135 120 127zM139 58 143 45 151 38 160 42 167 39 175 46 174 56 181 62 176 73 168 78 157 73 150 68 142 69zM194 108 202 97 214 95 221 100 230 96 237 101 246 100 253 104 266 100 276 106 290 103 300 108 314 108 324 115 337 116 345 122 357 123 367 130 380 132 388 138 399 142 405 150 398 157 389 154 381 159 371 154 362 157 352 150 342 152 334 145 326 147 318 139 307 142 299 135 288 138 280 131 269 134 261 126 249 130 242 123 232 129 223 125 218 117 208 121 204 114zM220 126 230 128 238 137 234 149 241 159 237 173 243 185 238 199 239 215 231 229 229 245 220 261 212 279 206 272 207 255 201 244 204 231 198 220 202 206 196 194 201 182 196 170 202 160 197 149 202 138 196 129zM238 139 247 137 255 141 260 150 256 159 246 160 240 154zM336 216 346 211 359 213 366 220 377 222 384 230 380 239 370 241 359 237 350 240 341 234 334 227zM202 81 204 75 208 79 206 85zM183 134 186 129 189 135 187 140zM303 161 306 157 309 163 307 168zM326 167 329 163 331 169 329 174zM103 129 108 126 111 132 106 136z" />
                    </svg>
                    <div class="world-map-heading">
                        <span>Job locations</span>
                        <span class="world-map-live"><i aria-hidden="true"></i> Live · 30s</span>
                    </div>
                    <svg class="world-map-connectors" viewBox="0 0 450 330" aria-hidden="true" focusable="false" data-map-connectors></svg>
                    <div class="world-map-markers" data-map-markers></div>
                    <div class="world-map-legend" aria-label="Job status legend">
                        <span><i class="map-legend-available"></i>Available</span>
                        <span><i class="map-legend-taken"></i>Taken</span>
                        <span><i class="map-legend-total"></i>Job count</span>
                    </div>
                    <p class="world-map-caption">Select a location to see its jobs</p>
                </div>
                <aside class="location-map-sidebar" aria-label="Job locations">
                    <p class="location-map-status" data-map-status role="status" aria-live="polite">Loading job locations…</p>
                    <ul class="location-map-list" data-map-locations></ul>
                    <p class="location-map-note" data-map-unmapped hidden></p>
                </aside>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php if ($view !== 'landing'): ?>
        </div>
    </div>
<?php endif; ?>
<a class="whatsapp-contact" href="https://wa.me/237672828084?text=Hello%2C%20I%20would%20like%20to%20contact%20the%20SkillsPassAurora%20developer." target="_blank" rel="noopener noreferrer" aria-label="Contact the developer on WhatsApp">
    <svg class="whatsapp-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M20.52 3.48A11.82 11.82 0 0 0 12.08 0C5.5 0 .15 5.35.15 11.93c0 2.1.55 4.16 1.6 5.97L.05 24l6.25-1.64a11.9 11.9 0 0 0 5.77 1.47h.01c6.58 0 11.93-5.35 11.93-11.93 0-3.19-1.24-6.19-3.49-8.42ZM12.08 21.8h-.01a9.9 9.9 0 0 1-5.04-1.38l-.36-.21-3.71.97.99-3.62-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.46 4.44-9.9 9.9-9.9 2.65 0 5.14 1.03 7.01 2.9a9.84 9.84 0 0 1 2.9 7c0 5.45-4.44 9.89-9.93 9.89Zm5.43-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.66.15-.2.3-.76.97-.93 1.17-.17.2-.34.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.61.13-.13.3-.34.44-.51.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.87 1.22 3.07.15.2 2.1 3.2 5.08 4.49.71.31 1.27.49 1.7.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.69.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35Z"/>
    </svg>
    <span>Contact developer</span>
</a>
<footer class="site-footer" id="contact">SkillsPassAurora &middot; Your Skills Are Not Useless</footer>
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

document.querySelectorAll('[data-sidebar-avatar]').forEach((image) => {
    image.addEventListener('error', () => image.remove());
});

const profilePictureInput = document.getElementById('profile-picture');
if (profilePictureInput) {
    const profilePreview = document.querySelector('[data-profile-preview]');
    const profileFallback = document.querySelector('[data-profile-fallback]');
    const profilePictureStatus = document.querySelector('[data-profile-picture-status]');
    let profilePreviewUrl = null;

    profilePreview.addEventListener('error', () => {
        profilePreview.hidden = true;
        profileFallback.hidden = false;
        if (profilePictureStatus) {
            profilePictureStatus.textContent = 'The profile picture could not be displayed. Choose and upload it again.';
        }
    });

    profilePictureInput.addEventListener('change', () => {
        const [file] = profilePictureInput.files;
        if (!file) return;

        const acceptedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!acceptedTypes.includes(file.type) || file.size > 2 * 1024 * 1024) {
            profilePictureInput.value = '';
            if (profilePictureStatus) {
                profilePictureStatus.textContent = 'Choose a JPEG, PNG, or WebP image smaller than 2 MB.';
            }
            return;
        }

        if (profilePreviewUrl) URL.revokeObjectURL(profilePreviewUrl);
        profilePreviewUrl = URL.createObjectURL(file);
        profilePreview.hidden = false;
        profileFallback.hidden = true;
        profilePreview.src = profilePreviewUrl;
        if (profilePictureStatus) {
            profilePictureStatus.textContent = 'Preview ready. Select Upload picture to save it to your profile.';
        }
    });
}

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

const dashboardDataElement = document.getElementById('employer-dashboard-data');
if (dashboardDataElement) {
    const trendChart = document.querySelector('[data-chart="trend"]');
    const statusChart = document.querySelector('[data-chart="status"]');
    const mapStatus = document.querySelector('[data-map-status]');
    const mapCount = document.querySelector('[data-map-count]');
    const mapLocations = document.querySelector('[data-map-locations]');
    const mapUnmapped = document.querySelector('[data-map-unmapped]');
    const mapMarkers = document.querySelector('[data-map-markers]');
    const mapConnectors = document.querySelector('[data-map-connectors]');
    const liveStatus = document.querySelector('[data-chart-status]');
    const refreshButton = document.querySelector('[data-chart-refresh]');
    const svgNamespace = 'http://www.w3.org/2000/svg';
    let dashboardData;
    let refreshInProgress = false;

    const cameroonCities = {
        buea: { label: 'Buea', coordinates: [4.1527, 9.241] },
        tiko: { label: 'Tiko', coordinates: [4.075, 9.36] },
        yaounde: { label: 'Yaounde', coordinates: [3.848, 11.5021] },
        douala: { label: 'Douala', coordinates: [4.0511, 9.7679] },
        limbe: { label: 'Limbe', coordinates: [4.0236, 9.2068] },
        bamenda: { label: 'Bamenda', coordinates: [5.9631, 10.1591] },
        bafoussam: { label: 'Bafoussam', coordinates: [5.4781, 10.4175] },
        garoua: { label: 'Garoua', coordinates: [9.3014, 13.3977] },
        maroua: { label: 'Maroua', coordinates: [10.591, 14.3159] },
        ngaoundere: { label: 'Ngaoundere', coordinates: [7.3277, 13.5847] },
        bertoua: { label: 'Bertoua', coordinates: [4.5773, 13.6846] },
        ebolowa: { label: 'Ebolowa', coordinates: [2.9, 11.15] },
        kumba: { label: 'Kumba', coordinates: [4.6373, 9.4469] },
        kribi: { label: 'Kribi', coordinates: [2.94, 9.91] },
        dschang: { label: 'Dschang', coordinates: [5.444, 10.053] },
        foumban: { label: 'Foumban', coordinates: [5.726, 10.898] },
        bafang: { label: 'Bafang', coordinates: [5.157, 10.177] },
        kumbo: { label: 'Kumbo', coordinates: [6.2, 10.67] },
    };
    const cityOffsets = {
        buea: [-21, -12],
        tiko: [10, 8],
        yaounde: [24, -11],
        douala: [-2, 14],
        limbe: [-24, 7],
        bamenda: [-6, -22],
        bafoussam: [-33, -11],
        garoua: [-13, -24],
        maroua: [12, -22],
        ngaoundere: [25, -5],
        bertoua: [28, 10],
        ebolowa: [20, 22],
        kumba: [-24, 19],
        kribi: [2, 28],
        dschang: [-36, -20],
        foumban: [-38, -2],
        bafang: [-38, 13],
        kumbo: [-43, -25],
    };

    const addChartElement = (chart, name, attributes = {}, text = '') => {
        const element = document.createElementNS(svgNamespace, name);
        Object.entries(attributes).forEach(([attribute, value]) => element.setAttribute(attribute, String(value)));
        if (text !== '') element.textContent = text;
        chart.append(element);
        return element;
    };

    const validateDashboardData = (data) => {
        const counts = [data?.jobs?.total, data?.jobs?.available, data?.jobs?.filled,
            data?.applications?.total, data?.applications?.pending,
            data?.applications?.accepted, data?.applications?.rejected];
        return counts.every((count) => Number.isInteger(count) && count >= 0)
            && Array.isArray(data.trend)
            && data.trend.length === 7
            && data.trend.every((point) => typeof point.label === 'string'
                && typeof point.date === 'string'
                && Number.isInteger(point.total)
                && point.total >= 0)
            && Array.isArray(data.map_jobs)
            && data.map_jobs.every((job) => Number.isInteger(job.id)
                && job.id > 0
                && typeof job.title === 'string'
                && typeof job.location === 'string'
                && ['available', 'taken'].includes(job.status));
    };

    const normalizeLocation = (location) => location
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();

    const coordinatesForLocation = (location) => {
        const normalized = normalizeLocation(location);
        const explicitCoordinates = location.trim().match(/^(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)$/);
        if (explicitCoordinates) {
            const latitude = Number(explicitCoordinates[1]);
            const longitude = Number(explicitCoordinates[2]);
            if (latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180) {
                return { label: location, key: `${latitude.toFixed(5)},${longitude.toFixed(5)}`, coordinates: [latitude, longitude] };
            }
        }

        const cityEntry = Object.entries(cameroonCities).find(([, candidate]) => {
            const normalizedCity = normalizeLocation(candidate.label);
            return normalized === normalizedCity || normalized.startsWith(`${normalizedCity} `);
        });
        if (!cityEntry) return null;
        const [key, city] = cityEntry;
        return { ...city, key };
    };

    const selectMapLocation = (key) => {
        const selectedPoint = [...mapMarkers.children].find((point) => point.dataset.locationKey === key);
        if (!selectedPoint) return;

        mapMarkers.querySelectorAll('.map-location-point').forEach((point) => {
            const isSelected = point === selectedPoint;
            point.classList.toggle('is-selected', isSelected);
            point.querySelector('.map-location-chip').setAttribute('aria-expanded', String(isSelected));
            point.querySelector('.map-location-tooltip').hidden = !isSelected;
        });
        mapLocations.querySelectorAll('.location-map-item').forEach((button) => {
            button.classList.toggle('is-selected', button.dataset.locationKey === key);
        });
        mapStatus.textContent = `${selectedPoint.dataset.locationLabel} · ${selectedPoint.dataset.jobCount} jobs · Select another location or refresh to update`;
    };

    const renderJobMap = (data) => {
        if (mapCount) mapCount.textContent = String(data.map_jobs.length);
        if (!mapMarkers || !mapConnectors || !mapLocations || !mapStatus) return;

        mapMarkers.replaceChildren();
        mapConnectors.replaceChildren();
        mapLocations.replaceChildren();
        if (mapUnmapped) {
            mapUnmapped.hidden = true;
            mapUnmapped.textContent = '';
        }

        const groupedLocations = new Map();
        const unmappedJobs = [];
        data.map_jobs.forEach((job) => {
            const resolved = coordinatesForLocation(job.location);
            if (!resolved) {
                unmappedJobs.push(job);
                return;
            }
            if (!groupedLocations.has(resolved.key)) {
                groupedLocations.set(resolved.key, { ...resolved, jobs: [] });
            }
            groupedLocations.get(resolved.key).jobs.push(job);
        });

        const locationGroups = [...groupedLocations.values()];
        locationGroups.forEach((group, index) => {
            const [latitude, longitude] = group.coordinates;
            const trueX = ((longitude + 180) / 360) * 450;
            const trueY = ((90 - latitude) / 180) * 330;
            const offset = cityOffsets[group.key] || [8 + (index % 3) * 6, -10 + (index % 2) * 9];
            const markerX = trueX + offset[0];
            const markerY = trueY + offset[1];
            const availableCount = group.jobs.filter((job) => job.status === 'available').length;
            const takenCount = group.jobs.length - availableCount;
            const statusClass = availableCount > 0 && takenCount > 0
                ? 'is-mixed'
                : (availableCount > 0 ? 'is-available' : 'is-taken');

            addChartElement(mapConnectors, 'line', {
                x1: trueX,
                y1: trueY,
                x2: markerX,
                y2: markerY,
            });

            const point = document.createElement('div');
            point.className = 'map-location-point';
            point.dataset.locationKey = group.key;
            point.dataset.locationLabel = group.label;
            point.dataset.jobCount = String(group.jobs.length);
            point.style.left = `${(markerX / 450) * 100}%`;
            point.style.top = `${(markerY / 330) * 100}%`;

            const locationButton = document.createElement('button');
            locationButton.type = 'button';
            locationButton.className = `map-location-chip ${statusClass}`;
            locationButton.setAttribute('aria-expanded', 'false');
            locationButton.setAttribute('aria-label', `${group.label}: ${group.jobs.length} jobs. Select to view job titles.`);
            const locationName = document.createElement('span');
            locationName.textContent = group.label;
            const jobCount = document.createElement('span');
            jobCount.className = 'map-location-chip-count';
            jobCount.textContent = String(group.jobs.length);
            locationButton.append(locationName, jobCount);
            locationButton.addEventListener('click', () => selectMapLocation(group.key));

            const details = document.createElement('div');
            details.className = 'map-location-tooltip';
            details.hidden = true;
            const locationHeading = document.createElement('strong');
            locationHeading.textContent = group.label;
            details.append(locationHeading);
            group.jobs.forEach((job) => {
                const listing = document.createElement('span');
                listing.textContent = `${job.title} · ${job.status === 'available' ? 'Available' : 'Taken'}`;
                details.append(listing);
            });
            point.append(locationButton, details);
            mapMarkers.append(point);

            const listButton = document.createElement('button');
            listButton.type = 'button';
            listButton.className = 'location-map-item';
            listButton.dataset.locationKey = group.key;
            listButton.textContent = `${group.label} · ${group.jobs.length} job${group.jobs.length === 1 ? '' : 's'}`;
            listButton.addEventListener('click', () => {
                selectMapLocation(group.key);
                locationButton.focus();
            });
            mapLocations.append(listButton);
        });

        if (unmappedJobs.length > 0 && mapUnmapped) {
            mapUnmapped.hidden = false;
            mapUnmapped.textContent = `Not mapped: ${unmappedJobs.map((job) => `${job.title} (${job.location})`).join(', ')}. Add a supported city or latitude and longitude to show these jobs on the map.`;
        }
        mapStatus.textContent = data.map_jobs.length === 0
            ? 'No job locations yet.'
            : `${data.map_jobs.length} job${data.map_jobs.length === 1 ? '' : 's'} · ${locationGroups.length} mapped location${locationGroups.length === 1 ? '' : 's'}${unmappedJobs.length > 0 ? ` · ${unmappedJobs.length} unmapped` : ''} · Updated ${new Date().toLocaleTimeString()}`;
    };

    const renderTrendChart = (data) => {
        const chart = trendChart;
        chart.replaceChildren();
        chart.setAttribute('viewBox', '0 0 640 250');
        chart.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        addChartElement(chart, 'title', {}, 'Applications received over the last seven days');

        const left = 48;
        const right = 620;
        const top = 24;
        const bottom = 190;
        const definitions = addChartElement(chart, 'defs');
        const areaGradient = addChartElement(definitions, 'linearGradient', {
            id: 'trend-area-gradient',
            x1: 0,
            y1: 0,
            x2: 0,
            y2: 1,
        });
        addChartElement(areaGradient, 'stop', { offset: '0%', 'stop-color': '#8975f5', 'stop-opacity': '.42' });
        addChartElement(areaGradient, 'stop', { offset: '100%', 'stop-color': '#55a9e8', 'stop-opacity': '.02' });
        const lineGradient = addChartElement(definitions, 'linearGradient', {
            id: 'trend-line-gradient',
            x1: 0,
            y1: 0,
            x2: 1,
            y2: 0,
        });
        addChartElement(lineGradient, 'stop', { offset: '0%', 'stop-color': '#8975f5' });
        addChartElement(lineGradient, 'stop', { offset: '100%', 'stop-color': '#55c9d8' });
        const values = data.trend.map((point) => point.total);
        const maximum = Math.max(...values, 1);
        [0, Math.ceil(maximum / 2), maximum].forEach((value) => {
            const y = bottom - (value / maximum) * (bottom - top);
            addChartElement(chart, 'line', { x1: left, y1: y, x2: right, y2: y, class: 'chart-grid' });
            addChartElement(chart, 'text', { x: left - 12, y: y + 4, 'text-anchor': 'end' }, value);
        });

        const coordinates = data.trend.map((point, index) => {
            const x = left + (index * (right - left)) / (data.trend.length - 1);
            const y = bottom - (point.total / maximum) * (bottom - top);
            addChartElement(chart, 'text', { x, y: bottom + 25, 'text-anchor': 'middle' }, point.label);
            return { x, y };
        });
        const points = coordinates.map(({ x, y }) => `${x},${y}`);
        addChartElement(chart, 'polygon', {
            points: `${left},${bottom} ${points.join(' ')} ${right},${bottom}`,
            class: 'chart-area',
        });
        addChartElement(chart, 'polyline', { points: points.join(' '), class: 'chart-line' });
        coordinates.forEach(({ x, y }) => {
            addChartElement(chart, 'circle', { cx: x, cy: y, r: 5, class: 'chart-point' });
        });

        if (values.every((value) => value === 0)) {
            addChartElement(chart, 'text', { x: (left + right) / 2, y: 112, class: 'chart-no-data' }, 'No applications in the last 7 days');
        }
    };

    const renderStatusChart = (data) => {
        const chart = statusChart;
        chart.replaceChildren();
        chart.setAttribute('viewBox', '0 0 600 200');
        chart.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        addChartElement(chart, 'title', {}, 'Applications by review status');

        const rows = [
            { label: 'Pending', value: data.applications.pending, className: 'chart-bar-pending' },
            { label: 'Accepted', value: data.applications.accepted, className: 'chart-bar-accepted' },
            { label: 'Rejected', value: data.applications.rejected, className: 'chart-bar-rejected' },
        ];
        const maximum = Math.max(...rows.map((row) => row.value), 1);
        rows.forEach((row, index) => {
            const y = 24 + index * 58;
            const width = (row.value / maximum) * 340;
            addChartElement(chart, 'text', { x: 16, y: y + 17 }, row.label);
            addChartElement(chart, 'rect', { x: 112, y, width: 340, height: 24, rx: 4, class: 'chart-bar-track' });
            if (width > 0) {
                addChartElement(chart, 'rect', { x: 112, y, width, height: 24, rx: 4, class: row.className });
            }
            addChartElement(chart, 'text', { x: 468, y: y + 17 }, row.value);
        });
        if (data.applications.total === 0) {
            addChartElement(chart, 'text', { x: 300, y: 198, class: 'chart-no-data' }, 'No applications yet');
        }
    };

    const renderDashboard = (data) => {
        if (!validateDashboardData(data)) {
            throw new Error('The dashboard returned invalid chart data.');
        }
        dashboardData = data;
        document.querySelector('[data-metric="jobs"]').textContent = data.jobs.total;
        document.querySelector('[data-metric="applications"]').textContent = data.applications.total;
        document.querySelector('[data-metric="pending"]').textContent = data.applications.pending;
        document.querySelector('[data-metric="filled"]').textContent = data.jobs.filled;
        document.querySelector('[data-trend-total]').textContent = data.trend.reduce((total, point) => total + point.total, 0);
        renderTrendChart(data);
        renderStatusChart(data);
        renderJobMap(data);
    };

    try {
        renderDashboard(JSON.parse(dashboardDataElement.textContent));
    } catch (error) {
        liveStatus.textContent = 'Dashboard data could not be displayed. Refresh the page to try again.';
        console.error(error);
    }

    const refreshDashboard = async () => {
        if (refreshInProgress || !dashboardData) return;
        refreshInProgress = true;
        if (refreshButton) refreshButton.disabled = true;
        try {
            const response = await fetch('index.php?dashboard_data=1', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || 'Dashboard data could not be refreshed.');
            }
            renderDashboard(data);
            liveStatus.textContent = `Live · updated ${new Date().toLocaleTimeString()}`;
        } catch (error) {
            liveStatus.textContent = error.message || 'Live refresh failed. Retrying in 30 seconds.';
            console.error(error);
        } finally {
            refreshInProgress = false;
            if (refreshButton) refreshButton.disabled = false;
        }
    };

    if (refreshButton) refreshButton.addEventListener('click', refreshDashboard);
    window.setInterval(() => {
        if (document.visibilityState === 'visible') refreshDashboard();
    }, 30000);
}
</script>
</body>
</html>
