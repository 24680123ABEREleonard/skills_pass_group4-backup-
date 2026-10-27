<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
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
$applicationsByJob = [];
$candidateApplications = [];
$employerDashboardData = null;
$profileData = null;
$publicProfileData = null;
$pendingUnlockPayments = [];
$unlockPriceXaf = 300;
$paymentReceiver = trim(getenv('MTN_MOMO_RECEIVER') ?: '682268179');
$paymentReceiverName = trim(getenv('MTN_MOMO_RECEIVER_NAME') ?: 'VIVIAN MANYI TANYI');
$momoBaseUrl = rtrim(trim(getenv('MTN_MOMO_BASE_URL') ?: ''), '/');
$momoApiUser = trim(getenv('MTN_MOMO_API_USER') ?: '');
$momoApiKey = trim(getenv('MTN_MOMO_API_KEY') ?: '');
$momoSubscriptionKey = trim(getenv('MTN_MOMO_SUBSCRIPTION_KEY') ?: '');
$momoTargetEnvironment = trim(getenv('MTN_MOMO_TARGET_ENVIRONMENT') ?: '');
$momoConfigured = $momoBaseUrl !== ''
    && filter_var($momoBaseUrl, FILTER_VALIDATE_URL) !== false
    && parse_url($momoBaseUrl, PHP_URL_SCHEME) === 'https'
    && $momoApiUser !== ''
    && $momoApiKey !== ''
    && $momoSubscriptionKey !== ''
    && $momoTargetEnvironment !== '';

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

    $digits = ltrim($digits, '+');
    if (preg_match('/\A6\d{8}\z/', $digits)) {
        return '237' . $digits;
    }
    return preg_match('/\A2376\d{8}\z/', $digits) ? $digits : null;
}

function momo_api_call(string $method, string $path, array $config, ?array $payload = null, ?string $token = null, ?string $referenceId = null): array
{
    $headers = [
        'Accept: application/json',
        'Ocp-Apim-Subscription-Key: ' . $config['subscription_key'],
        'X-Target-Environment: ' . $config['target_environment'],
    ];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    } else {
        $headers[] = 'Authorization: Basic ' . base64_encode($config['api_user'] . ':' . $config['api_key']);
        $headers[] = 'Cache-Control: no-cache';
    }
    if ($referenceId !== null) {
        $headers[] = 'X-Reference-Id: ' . $referenceId;
    }
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $curl = curl_init($config['base_url'] . $path);
    if ($curl === false) {
        throw new RuntimeException('MTN Mobile Money could not be reached. Please try again later.');
    }
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($payload !== null) {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $options[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if (!is_string($body)) {
        error_log('SkillsPassAurora MTN MoMo API request failed: ' . $curlError);
        throw new RuntimeException('MTN Mobile Money is temporarily unreachable. The payment may still be processing; check its status before retrying.');
    }

    $decoded = [];
    if ($body !== '') {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            error_log('SkillsPassAurora MTN MoMo API returned invalid JSON (HTTP ' . $status . ').');
            throw new RuntimeException('MTN Mobile Money returned an invalid response. Please try again later.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('MTN Mobile Money returned an invalid response. Please try again later.');
        }
    }

    return ['status' => $status, 'data' => $decoded];
}

function momo_access_token(array $config): string
{
    $response = momo_api_call('POST', '/collection/token/', $config);
    $token = $response['data']['access_token'] ?? null;
    if ($response['status'] !== 200 || !is_string($token) || $token === '') {
        error_log('SkillsPassAurora MTN MoMo token request failed (HTTP ' . $response['status'] . ').');
        throw new RuntimeException('MTN Mobile Money is not ready to accept payments. Check the Collection API credentials and try again.');
    }
    return $token;
}

function new_payment_reference(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
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
        } elseif ($action === 'start_unlock_payment') {
            $user = signed_in_user();
            if ($user === null || $user['role'] !== 'employer') {
                throw new RuntimeException('Only signed-in employers can request access to applicant details.');
            }
            if (!$momoConfigured) {
                throw new RuntimeException('Automatic MTN payment is not configured on the server. Ask the administrator to configure the MTN Collection API settings.');
            }

            $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $submittedPhone = $_POST['payer_phone'] ?? '';
            $payerPhone = is_string($submittedPhone) ? normalize_cameroon_phone($submittedPhone) : null;
            if ($applicationId === false || $payerPhone === null) {
                throw new RuntimeException('Enter a valid Cameroon MTN number, for example 682268179 or +237682268179.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT applications.id
                 FROM applications
                 INNER JOIN jobs ON jobs.id = applications.job_id
                 WHERE applications.id = :application_id AND jobs.employer_id = :employer_id
                 FOR UPDATE'
            );
            $statement->execute(['application_id' => $applicationId, 'employer_id' => $user['id']]);
            if ($statement->fetchColumn() === false) {
                throw new RuntimeException('That application does not belong to one of your jobs.');
            }

            $statement = $pdo->prepare(
                'SELECT status, payment_method FROM application_unlock_payments
                 WHERE application_id = :application_id ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['application_id' => $applicationId]);
            $latestPayment = $statement->fetch();
            if ($latestPayment && $latestPayment['status'] === 'approved') {
                throw new RuntimeException('This application has already been unlocked.');
            }
            if ($latestPayment && $latestPayment['status'] === 'pending') {
                throw new RuntimeException(
                    $latestPayment['payment_method'] === 'mtn_momo'
                        ? 'A Mobile Money request is already pending. Check its status before trying again.'
                        : 'A previous payment reference is still awaiting review.'
                );
            }

            $transactionReference = new_payment_reference();
            $statement = $pdo->prepare(
                'INSERT INTO application_unlock_payments
                    (application_id, employer_id, amount_xaf, transaction_reference, payment_method, payer_phone)
                 VALUES (:application_id, :employer_id, :amount_xaf, :transaction_reference, \'mtn_momo\', :payer_phone)'
            );
            $statement->execute([
                'application_id' => $applicationId,
                'employer_id' => $user['id'],
                'amount_xaf' => $unlockPriceXaf,
                'transaction_reference' => $transactionReference,
                'payer_phone' => $payerPhone,
            ]);
            $momoConfig = [
                'base_url' => $momoBaseUrl,
                'api_user' => $momoApiUser,
                'api_key' => $momoApiKey,
                'subscription_key' => $momoSubscriptionKey,
                'target_environment' => $momoTargetEnvironment,
            ];
            $accessToken = momo_access_token($momoConfig);
            $pdo->commit();

            $paymentResponse = momo_api_call(
                'POST',
                '/collection/v1_0/requesttopay',
                $momoConfig,
                [
                    'amount' => (string) $unlockPriceXaf,
                    'currency' => 'XAF',
                    'externalId' => 'unlock-' . $applicationId . '-' . str_replace('-', '', $transactionReference),
                    'payer' => [
                        'partyIdType' => 'MSISDN',
                        'partyId' => $payerPhone,
                    ],
                    'payerMessage' => 'SkillsPassAurora applicant access',
                    'payeeNote' => 'Applicant details unlock',
                ],
                $accessToken,
                $transactionReference
            );
            if ($paymentResponse['status'] >= 400 && $paymentResponse['status'] < 500) {
                db()->prepare(
                    "UPDATE application_unlock_payments
                     SET status = 'rejected', reviewed_at = UTC_TIMESTAMP()
                     WHERE transaction_reference = :reference AND status = 'pending'"
                )->execute(['reference' => $transactionReference]);
                error_log('SkillsPassAurora MTN MoMo payment request was rejected (HTTP ' . $paymentResponse['status'] . ').');
                throw new RuntimeException('MTN Mobile Money did not accept the payment request. Check the phone number and try again.');
            }
            if ($paymentResponse['status'] !== 202) {
                error_log('SkillsPassAurora MTN MoMo payment request has an unconfirmed result (HTTP ' . $paymentResponse['status'] . ').');
                throw new RuntimeException('MTN has not confirmed whether the payment request was created. Check its status before trying again.');
            }

            $notice = 'Payment request sent to ' . $payerPhone . '. Approve the XAF ' . $unlockPriceXaf . ' request on your phone, then check its status here.';
            $view = 'dashboard';
        } elseif ($action === 'check_unlock_payment') {
            $user = signed_in_user();
            if ($user === null || $user['role'] !== 'employer') {
                throw new RuntimeException('Only the employer who started the payment can check its status.');
            }
            if (!$momoConfigured) {
                throw new RuntimeException('Automatic MTN payment is not configured on the server. Ask the administrator to configure the MTN Collection API settings.');
            }

            $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($paymentId === false) {
                throw new RuntimeException('Choose a valid payment request.');
            }

            $statement = db()->prepare(
                "SELECT transaction_reference, status FROM application_unlock_payments
                 WHERE id = :id AND employer_id = :employer_id AND payment_method = 'mtn_momo'"
            );
            $statement->execute(['id' => $paymentId, 'employer_id' => $user['id']]);
            $payment = $statement->fetch();
            if (!$payment) {
                throw new RuntimeException('That payment request could not be found.');
            }
            if ($payment['status'] !== 'pending') {
                throw new RuntimeException('This payment request has already been ' . $payment['status'] . '.');
            }

            $momoConfig = [
                'base_url' => $momoBaseUrl,
                'api_user' => $momoApiUser,
                'api_key' => $momoApiKey,
                'subscription_key' => $momoSubscriptionKey,
                'target_environment' => $momoTargetEnvironment,
            ];
            $accessToken = momo_access_token($momoConfig);
            $statusResponse = momo_api_call(
                'GET',
                '/collection/v1_0/requesttopay/' . rawurlencode((string) $payment['transaction_reference']),
                $momoConfig,
                null,
                $accessToken
            );
            if ($statusResponse['status'] !== 200 || !isset($statusResponse['data']['status'])) {
                error_log('SkillsPassAurora MTN MoMo payment status check failed (HTTP ' . $statusResponse['status'] . ').');
                throw new RuntimeException('MTN could not confirm the payment status yet. Please check again shortly.');
            }

            $providerStatus = strtoupper((string) $statusResponse['data']['status']);
            if (in_array($providerStatus, ['SUCCESSFUL', 'SUCCESS'], true)) {
                db()->prepare(
                    "UPDATE application_unlock_payments
                     SET status = 'approved', reviewed_at = UTC_TIMESTAMP()
                     WHERE id = :id AND employer_id = :employer_id AND status = 'pending'"
                )->execute(['id' => $paymentId, 'employer_id' => $user['id']]);
                $notice = 'Payment confirmed. The employee information is now unlocked.';
            } elseif (in_array($providerStatus, ['FAILED', 'REJECTED'], true)) {
                db()->prepare(
                    "UPDATE application_unlock_payments
                     SET status = 'rejected', reviewed_at = UTC_TIMESTAMP()
                     WHERE id = :id AND employer_id = :employer_id AND status = 'pending'"
                )->execute(['id' => $paymentId, 'employer_id' => $user['id']]);
                $notice = 'MTN reports that the payment was not completed. You can try again.';
                $noticeType = 'error';
            } else {
                $notice = 'The payment is still pending. Approve the request on your phone, then check again.';
            }
            $view = 'dashboard';
        } elseif ($action === 'review_unlock_payment') {
            $user = signed_in_user();
            if (!is_payment_admin($user)) {
                throw new RuntimeException('You are not authorized to review payment references.');
            }

            $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $decision = $_POST['decision'] ?? '';
            if ($paymentId === false || !is_string($decision) || !in_array($decision, ['approve', 'reject'], true)) {
                throw new RuntimeException('Choose a valid payment review action.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT status FROM application_unlock_payments WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $paymentId]);
            if ($statement->fetchColumn() !== 'pending') {
                throw new RuntimeException('This payment is no longer awaiting review.');
            }

            $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
            $statement = $pdo->prepare(
                'UPDATE application_unlock_payments
                 SET status = :status, reviewed_by = :reviewed_by, reviewed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = :pending_status'
            );
            $statement->execute([
                'status' => $newStatus,
                'reviewed_by' => $user['id'],
                'id' => $paymentId,
                'pending_status' => 'pending',
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('This payment is no longer awaiting review.');
            }
            $pdo->commit();
            $notice = $decision === 'approve'
                ? 'Payment verified. The employer can now view the applicant details.'
                : 'Payment reference rejected. The employer can submit a new reference.';
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
                 WHERE job_id = :job_id AND candidate_id = :candidate_id AND status = 'pending'
                   AND EXISTS (
                       SELECT 1 FROM application_unlock_payments
                       WHERE application_unlock_payments.application_id = applications.id
                         AND application_unlock_payments.employer_id = :employer_id
                         AND application_unlock_payments.status = 'approved'
                   )"
            );
            $statement->execute([
                'job_id' => $jobId,
                'candidate_id' => $candidateId,
                'employer_id' => $user['id'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('That candidate does not have an unlocked pending application for this job.');
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
            ['submit_unlock_payment', '23000'] => 'That MTN transaction reference has already been submitted. Check it and try again.',
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
    if ($user !== null && $user['role'] === 'employer') {
        $employerDashboardData = load_employer_dashboard_data($pdo, (int) $user['id']);
        $statement = $pdo->prepare(
            'SELECT applications.id AS application_id, applications.job_id, applications.candidate_id,
                    applications.status, applications.created_at,
                    CASE WHEN unlocked.id IS NOT NULL THEN applications.cover_letter END AS cover_letter,
                    CASE WHEN unlocked.id IS NOT NULL THEN applications.relevant_experience END AS relevant_experience,
                    CASE WHEN unlocked.id IS NOT NULL THEN applications.portfolio_url END AS portfolio_url,
                    CASE WHEN unlocked.id IS NOT NULL THEN users.display_name END AS candidate_name,
                    CASE WHEN unlocked.id IS NOT NULL THEN users.email END AS candidate_email,
                    (unlocked.id IS NOT NULL) AS is_unlocked,
                    latest_payment.id AS latest_payment_id,
                    latest_payment.status AS latest_payment_status,
                    latest_payment.payment_method AS latest_payment_method,
                    latest_payment.payer_phone AS latest_payer_phone
             FROM applications
             INNER JOIN jobs ON jobs.id = applications.job_id
             INNER JOIN users ON users.id = applications.candidate_id
             LEFT JOIN application_unlock_payments AS unlocked
               ON unlocked.application_id = applications.id AND unlocked.status = \'approved\'
             LEFT JOIN application_unlock_payments AS latest_payment
               ON latest_payment.id = (
                   SELECT MAX(id) FROM application_unlock_payments
                   WHERE application_id = applications.id
               )
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
    if ($user !== null && is_payment_admin($user)) {
        $statement = $pdo->query(
            "SELECT payments.id, payments.amount_xaf, payments.transaction_reference, payments.created_at,
                    jobs.title AS job_title, employers.display_name AS employer_name,
                    employers.email AS employer_email
             FROM application_unlock_payments AS payments
             INNER JOIN applications ON applications.id = payments.application_id
             INNER JOIN jobs ON jobs.id = applications.job_id
             INNER JOIN users AS employers ON employers.id = payments.employer_id
             WHERE payments.status = 'pending' AND payments.payment_method = 'manual'
             ORDER BY payments.created_at ASC"
        );
        $pendingUnlockPayments = $statement->fetchAll();
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
    <title>SkillsPassAurora | Proving What You Can Do</title>
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
<?php if ($view !== 'landing'): ?>
    <?php
    $navigationUser = signed_in_user();
    $navigationRole = ($navigationUser['role'] ?? '') === 'candidate' ? 'Employee' : 'Employer';
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
            <p class="muted">Share a role with candidates looking for their next opportunity.</p>
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
        <?php $workspaceRole = ($user['role'] ?? '') === 'candidate' ? 'Employee' : 'Employer'; ?>
        <section class="welcome-panel dashboard-welcome" id="dashboard-overview">
            <p class="eyebrow">Welcome</p>
            <h1>Welcome to SkillsPassAurora, <?= escape($user['display_name'] ?? '') ?>.</h1>
            <p class="muted">Your <?= escape($workspaceRole) ?> account is ready. Browse jobs and see their current availability below.</p>
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
                                    <p class="muted">Your candidates by review status</p>
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
                <img src="images/logo.jpeg" alt="SkillsPassAurora — Proving What You Can Do">
                <p>Real skills. Real opportunities. A brighter future.</p>
            </div>
            <div class="auth-panel auth-page-panel">
                <p class="eyebrow"><?= $authMode === 'signup' ? 'Join the community' : 'Welcome back' ?></p>
                <h1><?= $authMode === 'signup' ? 'Create your account' : 'Sign in to SkillsPass' ?></h1>
                <p class="auth-intro"><?= $authMode === 'signup' ? ($signupRole === '' ? 'Choose how you want to use SkillsPassAurora.' : 'Create your ' . ($signupRole === 'candidate' ? 'employee' : 'employer') . ' account.') : 'Continue to your SkillsPassAurora account.' ?></p>
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
    <?php $currentUser = signed_in_user(); ?>
    <?php if (is_payment_admin($currentUser)): ?>
        <section class="jobs-section payment-review-section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Developer review</p>
                    <h2>MTN payment references</h2>
                </div>
                <p class="muted">Verify each reference against your MTN Mobile Money records before approving it.</p>
            </div>
            <?php if ($pendingUnlockPayments === []): ?>
                <div class="empty-state">No payment references are waiting for review.</div>
            <?php else: ?>
                <div class="job-list">
                    <?php foreach ($pendingUnlockPayments as $payment): ?>
                        <article class="job-card">
                            <h3><?= escape($payment['job_title']) ?></h3>
                            <p><strong>Employer:</strong> <?= escape($payment['employer_name']) ?> (<?= escape($payment['employer_email']) ?>)</p>
                            <p><strong>Amount:</strong> <?= (int) $payment['amount_xaf'] ?> XAF</p>
                            <p><strong>MTN reference:</strong> <?= escape($payment['transaction_reference']) ?></p>
                            <form class="stack" method="post" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                <input type="hidden" name="action" value="review_unlock_payment">
                                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                                <div class="job-form-grid">
                                    <button type="submit" name="decision" value="approve">Approve verified payment</button>
                                    <button class="secondary" type="submit" name="decision" value="reject">Reject reference</button>
                                </div>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
                                                            <button type="submit">Select candidate</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="application-status"><?= escape(ucfirst((string) $application['status'])) ?></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div>
                                                        <strong>Applicant details locked</strong>
                                                        <div class="payment-instructions">
                                                            <strong>Pay <?= (int) $unlockPriceXaf ?> XAF to view this employee's information</strong>
                                                            <?php if ($paymentReceiverName !== '' && $paymentReceiver !== ''): ?>
                                                                <span><strong>MTN MoMo recipient:</strong> <?= escape($paymentReceiverName) ?></span>
                                                                <span><strong>Recipient number:</strong> <?= escape($paymentReceiver) ?></span>
                                                                <span>Enter your Cameroon MTN number to receive a secure payment request on your phone.</span>
                                                            <?php else: ?>
                                                                <span>Payment recipient details are not configured. Please contact the site developer.</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($application['latest_payment_status'] === 'pending' && $application['latest_payment_method'] === 'mtn_momo'): ?>
                                                            <span class="application-status">Payment request sent to <?= escape($application['latest_payer_phone']) ?>. Approve it on your phone.</span>
                                                            <form method="post">
                                                                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                                                <input type="hidden" name="action" value="check_unlock_payment">
                                                                <input type="hidden" name="payment_id" value="<?= (int) $application['latest_payment_id'] ?>">
                                                                <button class="secondary" type="submit">Check payment status</button>
                                                            </form>
                                                        <?php elseif ($application['latest_payment_status'] === 'pending'): ?>
                                                            <span class="application-status">Previous payment reference awaiting manual review.</span>
                                                        <?php elseif (!$momoConfigured): ?>
                                                            <span class="application-status">Automatic MTN payment is not set up on the server yet.</span>
                                                            <button class="secondary" type="button" data-open-momo-demo>Enter MoMo phone number</button>
                                                        <?php else: ?>
                                                            <form class="stack" method="post" autocomplete="off">
                                                                <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                                                                <input type="hidden" name="action" value="start_unlock_payment">
                                                                <input type="hidden" name="application_id" value="<?= (int) $application['application_id'] ?>">
                                                                <div class="field">
                                                                    <label for="payer-phone-<?= (int) $application['application_id'] ?>">Your MTN MoMo phone number</label>
                                                                    <input id="payer-phone-<?= (int) $application['application_id'] ?>" name="payer_phone" type="tel" inputmode="tel" autocomplete="tel" pattern="(?:\+?237)?6[0-9]{8}" placeholder="682268179 or +237682268179" required>
                                                                </div>
                                                                <button type="submit">Request payment on my phone</button>
                                                            </form>
                                                        <?php endif; ?>
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
<footer class="site-footer" id="contact">SkillsPassAurora &middot; Proving What You Can Do</footer>
<dialog class="momo-demo-dialog" data-momo-demo-dialog aria-labelledby="momo-demo-title">
    <div class="momo-demo-content">
        <button class="momo-demo-close" type="button" data-close-momo-demo aria-label="Close">&times;</button>
        <p class="eyebrow">Demo only</p>
        <h2 id="momo-demo-title">MTN MoMo payment</h2>
        <p>Automatic payment is not configured on this server. You may enter your MoMo phone number below to preview the payment step.</p>
        <label for="momo-demo-phone">Your MoMo phone number</label>
        <input id="momo-demo-phone" type="tel" inputmode="tel" autocomplete="off" placeholder="682268179 or +237682268179">
        <p class="momo-demo-warning">This demo does not send or save your number and will not make a payment. Never enter your MoMo PIN, password, or OTP on this website.</p>
        <button class="secondary" type="button" disabled>Payment unavailable</button>
    </div>
</dialog>
<script>
const momoDemoDialog = document.querySelector('[data-momo-demo-dialog]');
if (momoDemoDialog instanceof HTMLDialogElement) {
    document.querySelectorAll('[data-open-momo-demo]').forEach((button) => {
        button.addEventListener('click', () => momoDemoDialog.showModal());
    });
    momoDemoDialog.querySelector('[data-close-momo-demo]')?.addEventListener('click', () => momoDemoDialog.close());
    momoDemoDialog.addEventListener('click', (event) => {
        if (event.target === momoDemoDialog) momoDemoDialog.close();
    });
}

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
    const liveStatus = document.querySelector('[data-chart-status]');
    const refreshButton = document.querySelector('[data-chart-refresh]');
    const svgNamespace = 'http://www.w3.org/2000/svg';
    let dashboardData;
    let refreshInProgress = false;

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
                && point.total >= 0);
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
        const values = data.trend.map((point) => point.total);
        const maximum = Math.max(...values, 1);
        [0, Math.ceil(maximum / 2), maximum].forEach((value) => {
            const y = bottom - (value / maximum) * (bottom - top);
            addChartElement(chart, 'line', { x1: left, y1: y, x2: right, y2: y, class: 'chart-grid' });
            addChartElement(chart, 'text', { x: left - 12, y: y + 4, 'text-anchor': 'end' }, value);
        });

        const points = data.trend.map((point, index) => {
            const x = left + (index * (right - left)) / (data.trend.length - 1);
            const y = bottom - (point.total / maximum) * (bottom - top);
            addChartElement(chart, 'text', { x, y: bottom + 25, 'text-anchor': 'middle' }, point.label);
            addChartElement(chart, 'circle', { cx: x, cy: y, r: 5, class: 'chart-point' });
            return `${x},${y}`;
        });
        addChartElement(chart, 'polyline', { points: points.join(' '), class: 'chart-line' });

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
