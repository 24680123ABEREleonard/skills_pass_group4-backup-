<?php
declare(strict_types=1);
$isPublic = !$user || $page === 'how-it-works';
$profileCompletion = is_array($profile)
    ? (int) min(100, (($profile['headline'] ? 20 : 0) + ($profile['location'] ? 15 : 0) + ($profile['bio'] ? 25 : 0) + (count($skillNames) ? 20 : 0) + (count($projects) ? 20 : 0)))
    : 0;
$pageTitle = match ($page) {
    'dashboard' => 'Employer dashboard',
    'employee-dashboard' => 'Employee dashboard',
    'home' => 'Proof of skill',
    'how-it-works' => 'How SkillPass Works',
    'login' => 'Sign in',
    'register' => 'Create an account',
    'forgot-password' => 'Forgot password',
    'reset-password' => 'Reset password',
    'opportunities' => 'Open opportunities',
    'updates' => 'Employer updates',
    'profile' => 'My profile',
    'applications' => 'My applications',
    'jobs' => 'My openings',
    'candidates' => 'Review candidates',
    'messages' => 'Message center',
    'admin' => 'Admin dashboard',
    default => 'SkillPass',
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111318">
    <title><?= e($pageTitle) ?> — SkillPass</title>
    <link rel="stylesheet" href="style.css">
    <script src="app.js" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php" aria-label="SkillPass home">
        <img class="brand-logo" src="images/logo%202.jpeg" alt="SkillPass Aurora — Proving What You Can Do" width="160" height="100">
    </a>
    <?php if ($user): ?>
        <button class="menu-toggle" type="button" aria-expanded="false" aria-label="Open navigation">Menu</button>
        <nav class="main-nav" aria-label="Main navigation">
            <?php if ($user['role'] === 'admin'): ?>
                <a class="<?= $page === 'admin' ? 'active' : '' ?>" href="admin-dashboard.php">Admin dashboard</a>
            <?php elseif ($user['role'] === 'jobseeker'): ?>
                <a class="<?= in_array($page, ['dashboard', 'employee-dashboard'], true) ? 'active' : '' ?>" href="employee-dashboard.php">Employee dashboard</a>
                <a class="<?= $page === 'opportunities' ? 'active' : '' ?>" href="index.php?page=opportunities">Find work</a>
                <a class="<?= $page === 'profile' ? 'active' : '' ?>" href="index.php?page=profile">My profile</a>
                <a class="<?= $page === 'applications' ? 'active' : '' ?>" href="index.php?page=applications">Applications</a>
                <a class="<?= $page === 'updates' ? 'active' : '' ?>" href="index.php?page=updates">Employer updates</a>
            <?php else: ?>
                <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="index.php">Employer dashboard</a>
                <a class="<?= $page === 'jobs' ? 'active' : '' ?>" href="index.php?page=jobs">My openings</a>
                <a class="<?= $page === 'updates' ? 'active' : '' ?>" href="index.php?page=updates">Company updates</a>
            <?php endif; ?>
            <a class="<?= $page === 'messages' ? 'active' : '' ?>" href="index.php?page=messages">Messages<?= $counts['unread_messages'] ? ' (' . (int) $counts['unread_messages'] . ')' : '' ?></a>
            <a class="<?= $page === 'how-it-works' ? 'active' : '' ?>" href="index.php?page=how-it-works">How it works</a>
        </nav>
        <div class="top-user">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
            <span class="top-user-name"><?= e($user['name']) ?></span>
            <form method="post" class="logout-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="text-button" type="submit">Sign out</button>
            </form>
        </div>
    <?php else: ?>
        <button class="menu-toggle" type="button" aria-expanded="false" aria-label="Open navigation">Menu</button>
        <nav class="main-nav public-nav" aria-label="Main navigation">
            <a class="<?= $page === 'home' ? 'active' : '' ?>" href="index.php?page=home">Home</a>
            <a href="index.php?page=opportunities">Explore openings</a>
            <a class="<?= $page === 'how-it-works' ? 'active' : '' ?>" href="index.php?page=how-it-works">How it works</a>
            <a href="admin-dashboard.php">Admin sign in</a>
            <a class="button button-small button-outline" href="index.php?page=login">Sign in</a>
            <a class="button button-small button-primary" href="index.php?page=register">Get started</a>
        </nav>
    <?php endif; ?>
</header>

<main class="<?= $isPublic ? 'public-main' : 'app-main' ?>">
    <?php if ($flashMessage): ?>
        <div class="flash <?= e($flashMessage['type'] === 'error' ? 'flash-error' : 'flash-success') ?>" role="status">
            <?= e($flashMessage['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($page === 'how-it-works'): ?>
        <section class="section how-it-works-page">
            <div class="section-heading">
                <span class="eyebrow">HOW SKILLPASS WORKS</span>
                <h1>Make ability easier to see.</h1>
                <p>A fairer way for people to prove themselves and for teams to hire with confidence.</p>
            </div>
            <div class="feature-grid">
                <article class="feature-card"><span class="feature-number">01</span><span class="feature-icon">◎</span><h2>Build a skills profile</h2><p>Jobseekers add a professional headline, location, summary, and comma-separated skills. Then they attach projects they have completed, with an optional link to each work sample.</p></article>
                <article class="feature-card"><span class="feature-number">02</span><span class="feature-icon">↗</span><h2>Apply and stay connected</h2><p>Explore open roles, follow employers for their news and announcements, and apply with an optional note. If a role includes a practical challenge, you can respond and link to evidence.</p></article>
                <article class="feature-card"><span class="feature-number">03</span><span class="feature-icon">⌕</span><h2>Review evidence fairly</h2><p>Employers compare required-skill overlap, project samples, and challenge results. The transparent fit score supports human review; it does not make the hiring decision.</p></article>
            </div>
            <div class="guide-grid">
                <article class="guide-card"><span class="eyebrow">FOR EMPLOYEES</span><h2>Make your ability easy to understand</h2><p>Use specific skills, describe your own contribution to each project, and keep evidence links relevant. Follow employers to read their news in your updates feed.</p><a href="index.php?page=register">Create an employee account →</a></article>
                <article class="guide-card"><span class="eyebrow">FOR EMPLOYERS</span><h2>Describe the work before reviewing people</h2><p>Post a clear role description and the skills that matter. An optional practical challenge lets every applicant demonstrate the same job-related task, and company updates keep followers informed.</p><a href="index.php?page=register">Create an employer account →</a></article>
                <article class="guide-card"><span class="eyebrow">FOR THE ADMIN</span><h2>Keep the marketplace trusted</h2><p>Approve employer and employee accounts, review job posts before publication, oversee platform activity, and mediate messages between both sides. Admin accounts are created manually by the system owner.</p></article>
                <article class="guide-card"><span class="eyebrow">PRIVACY & DECISIONS</span><h2>Keep people at the centre</h2><p>Candidate names are abbreviated during review. Messages are mediated by the SkillPass admin, and scores do not use personal characteristics.</p></article>
            </div>
            <div class="how-it-works-cta">
                <h2>Ready to get started?</h2>
                <a class="button button-primary" href="index.php?page=register">Create your free account <span aria-hidden="true">→</span></a>
            </div>
        </section>
    <?php elseif (!$user && $page === 'login'): ?>
        <section class="auth-layout">
            <div class="auth-story">
                <span class="eyebrow">WELCOME BACK</span>
                <h1>Your next opportunity starts with what you can do.</h1>
                <p>Sign in to pick up where your skills and evidence left off.</p>
                <div class="guide-card auth-guide"><h2>Your SkillPass workspace</h2><p>Employees can manage a profile, work samples, applications, and challenge feedback. Employers can manage openings and review applicants. The SkillPass admin has a separate dashboard to review job posts and mediate platform messages.</p><p>Create an employee or employer account to sign in right away.</p></div>
            </div>
            <form class="panel auth-card" method="post">
                <h2>Sign in</h2>
                <p class="muted">Use your SkillPass account to continue.</p>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="login">
                <label>Email address<input type="email" name="email" autocomplete="email" required></label>
                <label>Password
                    <span class="password-field"><input id="login-password" type="password" name="password" autocomplete="current-password" required><button class="password-toggle" type="button" data-password-toggle data-show-label="Show password" data-hide-label="Hide password" aria-controls="login-password" aria-label="Show password" aria-pressed="false">Show</button></span>
                </label>
                <button class="button button-primary button-full" type="submit">Sign in</button>
                <p class="form-foot"><a href="index.php?page=forgot-password">Forgot your password?</a></p>
                <p class="form-foot">New to SkillPass? <a href="index.php?page=register">Create an account</a></p>
                <div class="demo-hint">Demo accounts: <strong>employer@skillpass.cm</strong>, <strong>amina@skillpass.cm</strong> or <strong>joel@skillpass.cm</strong><br>Password: <strong>Demo123!</strong></div>
            </form>
        </section>
    <?php elseif (!$user && $page === 'register'): ?>
        <section class="auth-layout">
            <div class="auth-story">
                <span class="eyebrow">START WITH YOUR SKILLS</span>
                <h1>Show what you know. Find where it can take you.</h1>
                <p>Create a free account to build a skills profile, share proof of your work, or find people who can do the job.</p>
                <div class="guide-stack">
                    <article class="guide-card"><h2>Employee account</h2><p>Build a profile with skills and project evidence, browse available opportunities, apply, and follow employer updates. Your account is ready to use as soon as you register.</p></article>
                    <article class="guide-card"><h2>Employer account</h2><p>Create an account and start preparing openings with clear skill requirements and optional practical challenges. New openings are reviewed before publication.</p></article>
                    <p class="muted">Choose the account type that matches how you plan to use SkillPass. Admin access is separate and provisioned by the system owner.</p>
                </div>
            </div>
            <form class="panel auth-card" method="post">
                <h2>Create your account</h2>
                <p class="muted">Choose the space that fits what you need.</p>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="register">
                <label>Your name<input type="text" name="name" autocomplete="name" maxlength="120" required></label>
                <label>Email address<input type="email" name="email" autocomplete="email" required></label>
                <label>Password
                    <span class="password-field"><input id="register-password" type="password" name="password" autocomplete="new-password" minlength="8" required><button class="password-toggle" type="button" data-password-toggle data-show-label="Show password" data-hide-label="Hide password" aria-controls="register-password" aria-label="Show password" aria-pressed="false">Show</button></span>
                    <span class="field-hint">At least 8 characters.</span>
                </label>
                <label>I am here to
                    <select name="role" required>
                        <option value="jobseeker">Employee — find work and show my skills</option>
                        <option value="employer">Hire people for my team</option>
                    </select>
                </label>
                <button class="button button-primary button-full" type="submit">Create account</button>
                <p class="form-foot">Already have an account? <a href="index.php?page=login">Sign in</a></p>
            </form>
        </section>
    <?php elseif (!$user && $page === 'forgot-password'): ?>
        <section class="auth-layout">
            <div class="auth-story">
                <span class="eyebrow">ACCOUNT RECOVERY</span>
                <h1>Get back to your SkillPass account.</h1>
                <p>Request a six-digit recovery code using the email address on your account. For security, this page does not confirm whether an address is registered.</p>
                <div class="guide-card auth-guide"><h2>What happens next?</h2><p>If the address belongs to an account and email delivery is available, a code will be sent. It expires after 15 minutes and can be used once. To limit repeated requests, wait at least a minute before requesting another code. Check your spam folder if it does not appear.</p></div>
            </div>
            <form class="panel auth-card" method="post">
                <h2>Request a reset code</h2>
                <p class="muted">Enter the email address you use to sign in.</p>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="request_password_reset">
                <label>Email address<input type="email" name="email" autocomplete="email" required></label>
                <button class="button button-primary button-full" type="submit">Send reset code</button>
                <p class="form-foot">Already have a code? <a href="index.php?page=reset-password">Set a new password</a></p>
                <p class="form-foot"><a href="index.php?page=login">Return to sign in</a></p>
            </form>
        </section>
    <?php elseif (!$user && $page === 'reset-password'): ?>
        <section class="auth-layout">
            <div class="auth-story">
                <span class="eyebrow">CHOOSE A NEW PASSWORD</span>
                <h1>Use your email code to reset access.</h1>
                <p>Enter the email address where the code was sent, the six-digit code, and a new password of at least eight characters.</p>
                <div class="guide-card auth-guide"><h2>Keep your account secure</h2><p>Reset codes expire after 15 minutes, can only be used once, and are limited to five attempts. Request a new code if yours has expired.</p></div>
            </div>
            <form class="panel auth-card" method="post">
                <h2>Reset your password</h2>
                <p class="muted">The new password takes effect as soon as the code is verified.</p>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="complete_password_reset">
                <label>Email address<input type="email" name="email" autocomplete="email" required></label>
                <label>Six-digit code<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label>
                <label>New password
                    <span class="password-field"><input id="reset-password" type="password" name="password" autocomplete="new-password" minlength="8" required><button class="password-toggle" type="button" data-password-toggle data-show-label="Show new password" data-hide-label="Hide new password" aria-controls="reset-password" aria-label="Show new password" aria-pressed="false">Show</button></span>
                </label>
                <label>Confirm new password
                    <span class="password-field"><input id="reset-password-confirm" type="password" name="password_confirm" autocomplete="new-password" minlength="8" required><button class="password-toggle" type="button" data-password-toggle data-show-label="Show confirmation password" data-hide-label="Hide confirmation password" aria-controls="reset-password-confirm" aria-label="Show confirmation password" aria-pressed="false">Show</button></span>
                </label>
                <button class="button button-primary button-full" type="submit">Save new password</button>
                <p class="form-foot">Need another code? <a href="index.php?page=forgot-password">Request a new one</a></p>
            </form>
        </section>
    <?php elseif (!$user && $page === 'opportunities'): ?>
        <section class="public-heading">
            <span class="eyebrow">OPEN OPPORTUNITIES</span>
            <h1>Find work that values what you can do.</h1>
            <p>Read each employer's complete role description, location, work arrangement, required skills, and any practical challenge before deciding whether to apply. Sign in to submit an application.</p>
        </section>
        <?php
        $statement = $pdo->query(
            "SELECT j.id, j.title, j.company, j.location, j.work_type, j.description,
                    (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
                     FROM job_skills js JOIN skills s ON s.id = js.skill_id WHERE js.job_id = j.id) AS skills,
                    (SELECT c.title FROM challenges c WHERE c.job_id = j.id LIMIT 1) AS challenge_title,
                    (SELECT c.instructions FROM challenges c WHERE c.job_id = j.id LIMIT 1) AS challenge_instructions
             FROM jobs j WHERE j.status = 'open' ORDER BY j.created_at DESC"
        );
        $publicJobs = $statement->fetchAll();
        ?>
        <?php if ($publicJobs): ?>
            <div class="job-grid">
                <?php foreach ($publicJobs as $job): ?>
                    <article class="panel job-card">
                        <div class="job-card-top"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($job['company'], 0, 1))) ?></span><span class="badge badge-green">Open</span></div>
                        <p class="company-name"><?= e($job['company']) ?></p>
                        <h2><?= e($job['title']) ?></h2>
                        <p class="muted"><?= e($job['location']) ?> · <?= e($job['work_type']) ?></p>
                        <p class="job-description"><?= e($job['description']) ?></p>
                        <?php if ($job['skills']): ?><div class="job-detail"><strong>Skills requested</strong><div class="tag-list"><?php foreach (explode(', ', $job['skills']) as $skill): ?><span class="tag"><?= e($skill) ?></span><?php endforeach; ?></div></div><?php else: ?><p class="tiny-note">The employer has not listed specific skill tags for this role.</p><?php endif; ?>
                        <?php if ($job['challenge_title']): ?><div class="job-challenge"><strong>Practical challenge: <?= e($job['challenge_title']) ?></strong><p><?= e($job['challenge_instructions']) ?></p><small>Apply first, then submit your response from My applications.</small></div><?php endif; ?>
                        <a class="button button-outline button-full" href="index.php?page=login">Sign in to apply</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state"><h2>No open roles yet</h2><p>Check back soon to find a role that fits your skills.</p></div>
        <?php endif; ?>
    <?php elseif (!$user && $page === 'home'): ?>
        <section class="hero">
            <div class="hero-copy">
                <span class="eyebrow"><span class="eyebrow-dot"></span> SKILLS FIRST. PEOPLE ALWAYS.</span>
                <h1>Proof of skill.<br><span>Not just a piece of paper.</span></h1>
                <p class="hero-lede">Build a profile from real skills and project evidence. Prove your ability through practical challenges. Connect with employers hiring for the skills you can show — not just the certificates or contacts you have.</p>
                <div class="hero-actions">
                    <a class="button button-primary" href="index.php?page=opportunities">Explore opportunities <span aria-hidden="true">→</span></a>
                    <a class="button button-plain" href="index.php?page=how-it-works">How SkillPass works <span aria-hidden="true">→</span></a>
                </div>
                <div class="hero-proof"><span class="proof-avatars"><i>A</i><i>J</i><i>M</i></span><span>Ability deserves to be seen.</span></div>
            </div>
            <div class="hero-visual">
                <div class="home-slideshow" data-slideshow role="region" aria-roledescription="carousel" aria-label="Photos of job candidates and employers">
                    <?php
                    $homeSlides = [
                        ['src' => 'images/handshake.avif', 'alt' => 'A candidate and employer shake hands in a bright office.'],
                        ['src' => 'images/image 1.jpg', 'alt' => 'Two professionals greet each other with a handshake.'],
                        ['src' => 'images/image 3.jpg', 'alt' => 'Two colleagues shake hands across a meeting table.'],
                        ['src' => 'images/image 4.jpg', 'alt' => 'A professional welcomes a visitor with a handshake.'],
                        ['src' => 'images/image 5.jpg', 'alt' => 'Two people meet and shake hands across a desk.'],
                        ['src' => 'images/images 10.jpg', 'alt' => 'Service members and a civilian meet at an event.'],
                        ['src' => 'images/images 2.jpg', 'alt' => 'A candidate and employer shake hands at an office.'],
                        ['src' => 'images/images 5.jpg', 'alt' => 'Two people greet each other at a formal event.'],
                        ['src' => 'images/images 6.jpg', 'alt' => 'Two service members meet and shake hands indoors.'],
                        ['src' => 'images/images 8.jpg', 'alt' => 'Two people shake hands in front of a flag.'],
                        ['src' => 'images/images 9.jpg', 'alt' => 'Two people meet in an office lobby.'],
                    ];
                    ?>
                    <div class="slideshow-track">
                        <?php foreach ($homeSlides as $slideIndex => $slide): ?>
                            <div class="slideshow-slide<?= $slideIndex === 0 ? ' is-active' : '' ?>" data-slide aria-hidden="<?= $slideIndex === 0 ? 'false' : 'true' ?>" role="group" aria-roledescription="slide" aria-label="<?= $slideIndex + 1 ?> of <?= count($homeSlides) ?>">
                                <img src="<?= e(str_replace(' ', '%20', $slide['src'])) ?>" alt="<?= e($slide['alt']) ?>" <?= $slideIndex > 0 ? 'loading="lazy"' : 'fetchpriority="high"' ?> decoding="async">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="slideshow-controls" role="group" aria-label="Photo slideshow controls">
                        <button class="slideshow-arrow" type="button" data-slide-prev aria-label="Previous photo">‹</button>
                        <div class="slideshow-indicators">
                            <?php foreach ($homeSlides as $slideIndex => $slide): ?>
                                <button class="slideshow-indicator<?= $slideIndex === 0 ? ' is-active' : '' ?>" type="button" data-slide-to="<?= $slideIndex ?>" aria-label="Show photo <?= $slideIndex + 1 ?>" aria-pressed="<?= $slideIndex === 0 ? 'true' : 'false' ?>"></button>
                            <?php endforeach; ?>
                        </div>
                        <button class="slideshow-arrow" type="button" data-slide-next aria-label="Next photo">›</button>
                    </div>
                </div>
            </div>
        </section>
        <section class="stat-strip">
            <div><strong>80%</strong><span>of young people have at least some secondary education</span></div>
            <div><strong>36%</strong><span>of 18–35 year olds say they are unemployed</span></div>
            <p>Cameroon has capable young people.<br>Let's make their skills visible.</p>
        </section>
        <section class="system-guide home-guide">
            <div class="guide-intro"><span class="eyebrow">THREE DASHBOARDS, ONE TRUSTED PLATFORM</span><h2>How SkillPass supports a better hiring conversation</h2><p>Employees show job-related evidence, employers review applicants, and an admin approves accounts and job posts while mediating platform communication.</p></div>
            <div class="guide-grid">
                <article class="guide-card"><span class="guide-step">01 · BUILD</span><h3>Create a useful profile</h3><p>List relevant skills, write a short work-focused introduction, and add projects that show what you personally contributed. Links to work are optional.</p></article>
                <article class="guide-card"><span class="guide-step">02 · CONNECT</span><h3>Apply to an open role</h3><p>Each role has a company, location, work arrangement, description, and requested skills. Applicants can include a short optional note.</p></article>
                <article class="guide-card"><span class="guide-step">03 · DEMONSTRATE</span><h3>Complete a practical challenge</h3><p>Some roles include a task. Jobseekers submit a written response and may add a link; employers can mark it passed or request more work with feedback.</p></article>
                <article class="guide-card"><span class="guide-step">04 · REVIEW</span><h3>Make a human decision</h3><p>Employers see a role-fit comparison based on required skills, work samples, and challenge results, then choose an application status themselves.</p></article>
            </div>
        </section>
        <section class="closing-cta"><div><span class="eyebrow">YOUR NEXT STEP STARTS HERE</span><h2>Show them what you can do.</h2></div><a class="button button-light" href="index.php?page=register">Get started for free <span aria-hidden="true">→</span></a></section>
    <?php elseif ($user['role'] === 'jobseeker' && $page === 'profile'): ?>
        <div class="page-heading"><div><span class="eyebrow">YOUR STORY, YOUR PROOF</span><h1>My profile</h1><p>Skills are self-reported; project and challenge evidence helps employers see your work for themselves.</p></div></div>
        <div class="two-column">
            <form class="panel form-panel" method="post">
                <div class="panel-heading"><div><h2>About you</h2><p>Only relevant work details are shown to employers who receive your application.</p></div><span class="heading-icon">◎</span></div>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_profile">
                <label>Headline<input name="headline" maxlength="160" value="<?= e($profile['headline']) ?>" placeholder="e.g. Junior web developer"></label>
                <label>Location<input name="location" maxlength="120" value="<?= e($profile['location']) ?>" placeholder="e.g. Douala"></label>
                <label>Profile summary<textarea name="bio" rows="5" maxlength="3000" placeholder="Tell employers about the kind of work you enjoy and what you can bring."><?= e($profile['bio']) ?></textarea></label>
                <label>Skills <span class="label-note">Separate each skill with a comma</span><input name="skills" value="<?= e(implode(', ', $skillNames)) ?>" placeholder="HTML, customer service, graphic design"></label>
                <button class="button button-primary" type="submit">Save profile</button>
            </form>
            <div class="profile-side">
                <section class="panel form-panel">
                    <div class="panel-heading"><div><h2>Project evidence</h2><p>Show what you have made or done.</p></div><span class="heading-icon">↗</span></div>
                    <?php if ($projects): ?><div class="project-list"><?php foreach ($projects as $project): ?><article class="project-item"><h3><?= e($project['title']) ?></h3><p><?= e($project['description']) ?></p><?php if ($project['evidence_url']): ?><a href="<?= e($project['evidence_url']) ?>" target="_blank" rel="noopener noreferrer">View evidence ↗</a><?php endif; ?></article><?php endforeach; ?></div><?php else: ?><p class="muted">No projects yet. Add a project to give your skills context.</p><?php endif; ?>
                    <form method="post" class="stack-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_project">
                        <label>Project title<input name="title" maxlength="160" required></label>
                        <label>What did you do?<textarea name="description" rows="3" maxlength="3000" required></textarea></label>
                        <label>Link to your work <span class="label-note">Optional</span><input type="url" name="evidence_url" placeholder="https://"></label>
                        <button class="button button-outline" type="submit">Add project</button>
                    </form>
                </section>
                <div class="privacy-note"><span>◈</span><p><strong>Your contact details stay private.</strong> Platform messages go through the SkillPass admin rather than exposing your email to employers.</p></div>
            </div>
        </div>
        <section class="system-guide">
            <div class="guide-intro"><span class="eyebrow">MAKE YOUR PROFILE WORK FOR YOU</span><h2>What to include and why it matters</h2><p>Your profile is a concise introduction to your work. Complete the parts that best explain your experience and interests.</p></div>
            <div class="guide-grid">
                <article class="guide-card"><h3>Headline and location</h3><p>State the kind of work you do or want to do, and the city where you are based. These help employers understand your profile quickly.</p></article>
                <article class="guide-card"><h3>Summary and skills</h3><p>Describe the tasks you can handle and list skills you can discuss or demonstrate. Keep the summary clear; skill tags are self-reported, not verified credentials.</p></article>
                <article class="guide-card"><h3>Project evidence</h3><p>Explain the goal, your contribution, and what the project demonstrates. Add a working public link when available; do not include private or confidential material.</p></article>
            </div>
        </section>
    <?php elseif ($user['role'] === 'jobseeker' && $page === 'opportunities'): ?>
        <div class="page-heading"><div><span class="eyebrow">FIND YOUR NEXT STEP</span><h1>Open opportunities</h1><p>See the skills each role needs and apply with evidence of what you can do.</p></div><span class="count-pill"><?= count($openJobs) ?> open roles</span></div>
        <div class="system-guide compact-guide"><div class="guide-grid">
            <article class="guide-card"><h3>Compare skills</h3><p>Gold skill tags are also on your profile; other tags are requirements you may want to build or demonstrate.</p></article>
            <article class="guide-card"><h3>Read the full role</h3><p>Review the complete description, location, and work arrangement. Roles can be on-site, hybrid, or remote.</p></article>
            <article class="guide-card"><h3>Apply and follow up</h3><p>Add an optional note when applying. Track status changes and submit or revise any practical challenge under My applications.</p></article>
        </div></div>
        <?php if ($gapSkill): ?><div class="insight-banner"><span class="insight-icon">✳</span><div><strong>A skill that could open more doors</strong><p><?= e($gapSkill['name']) ?> appears in <?= (int) $gapSkill['demand'] ?> more open <?= (int) $gapSkill['demand'] === 1 ? 'role' : 'roles' ?> than the skills on your profile.</p></div><a href="index.php?page=profile">Update my profile →</a></div><?php endif; ?>
        <?php if ($openJobs): ?><div class="job-grid"><?php foreach ($openJobs as $job): ?>
            <article class="panel job-card">
                <div class="job-card-top"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($job['company'], 0, 1))) ?></span><span class="badge badge-green">Open</span></div>
                <p class="company-name"><?= e($job['company']) ?></p><h2><?= e($job['title']) ?></h2><p class="muted"><?= e($job['location']) ?> · <?= e($job['work_type']) ?></p>
                <p class="job-description"><?= e($job['description']) ?></p>
                <?php if ($job['skills']): ?><div class="tag-list"><?php foreach (explode(', ', $job['skills']) as $skill): ?><span class="tag <?= in_array(strtolower($skill), array_map('strtolower', $skillNames), true) ? 'tag-match' : '' ?>"><?= e($skill) ?></span><?php endforeach; ?></div><?php endif; ?>
                <?php if ($job['challenge_title']): ?><div class="job-challenge"><strong>Practical challenge: <?= e($job['challenge_title']) ?></strong><p><?= e($job['challenge_instructions']) ?></p><small>After applying, submit your response from My applications.</small></div><?php endif; ?>
                <?php if ($job['applied']): ?><span class="button button-done button-full">Application sent</span><?php else: ?><form method="post" class="apply-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="apply_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><label class="sr-only" for="note-<?= (int) $job['id'] ?>">Optional note for <?= e($job['title']) ?></label><textarea id="note-<?= (int) $job['id'] ?>" name="cover_note" rows="2" maxlength="2000" placeholder="Add a short note (optional)"></textarea><button class="button button-primary button-full" type="submit">Apply for this role <span aria-hidden="true">→</span></button></form><?php endif; ?>
            </article>
        <?php endforeach; ?></div><?php else: ?><div class="empty-state"><h2>No open roles yet</h2><p>New opportunities will show here as employers post them.</p></div><?php endif; ?>
    <?php elseif ($user['role'] === 'jobseeker' && $page === 'updates'): ?>
        <div class="page-heading"><div><span class="eyebrow">KEEP UP WITH THE TEAMS YOU LIKE</span><h1>Employer updates</h1><p>Follow employers to see their news and announcements here.</p></div><span class="count-pill"><?= count($employerUpdates) ?> updates</span></div>
        <section class="panel form-panel employer-directory-panel">
            <div class="panel-heading"><div><h2>Employers on SkillPass</h2><p>Follow an employer to add its updates to your feed. You can unfollow at any time.</p></div></div>
            <?php if ($employers): ?><div class="employer-directory">
                <?php foreach ($employers as $employer): ?>
                    <article class="employer-card">
                        <span class="company-mark"><?= e(mb_strtoupper(mb_substr($employer['name'], 0, 1))) ?></span>
                        <div class="employer-card-copy"><strong><?= e($employer['name']) ?></strong><small><?= (int) $employer['open_role_count'] ?> open <?= (int) $employer['open_role_count'] === 1 ? 'role' : 'roles' ?> · <?= (int) $employer['follower_count'] ?> followers</small></div>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="<?= $employer['is_following'] ? 'unfollow_employer' : 'follow_employer' ?>">
                            <input type="hidden" name="employer_id" value="<?= (int) $employer['id'] ?>">
                            <button class="button <?= $employer['is_following'] ? 'button-outline' : 'button-primary' ?>" type="submit"><?= $employer['is_following'] ? 'Following' : 'Follow' ?></button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div><?php else: ?><div class="inline-empty"><p>There are no active employers to follow yet.</p></div><?php endif; ?>
        </section>
        <div class="section-subheading"><h2>Updates from employers you follow</h2></div>
        <?php if ($employerUpdates): ?><div class="update-feed">
            <?php foreach ($employerUpdates as $update): ?>
                <article class="panel update-card">
                    <div class="update-meta"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($update['employer_name'], 0, 1))) ?></span><div><strong><?= e($update['employer_name']) ?></strong><time datetime="<?= e(date(DATE_ATOM, strtotime($update['created_at']))) ?>"><?= e(date('M j, Y · g:i a', strtotime($update['created_at']))) ?></time></div></div>
                    <h3><?= e($update['title']) ?></h3><p><?= e($update['body']) ?></p>
                </article>
            <?php endforeach; ?>
        </div><?php else: ?><div class="empty-state"><h2><?= $followedEmployerCount ? 'No updates from your followed employers yet' : 'Your feed is ready when you are' ?></h2><p><?= $followedEmployerCount ? 'New updates from the employers you follow will appear here.' : 'Follow an employer above to see their updates here.' ?></p></div><?php endif; ?>
    <?php elseif ($user['role'] === 'employer' && $page === 'updates'): ?>
        <div class="page-heading"><div><span class="eyebrow">STAY CONNECTED WITH CANDIDATES</span><h1>Company updates</h1><p>Share news and announcements with employees who follow your account.</p></div><span class="count-pill"><?= (int) $counts['followers'] ?> followers</span></div>
        <div class="two-column updates-layout">
            <section class="panel form-panel">
                <div class="panel-heading"><div><h2>Publish an update</h2><p>Updates are shown immediately to your followers.</p></div><span class="heading-icon">↗</span></div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_employer_update">
                    <label>Title<input name="title" maxlength="160" required placeholder="e.g. New team project starting soon"></label>
                    <label>Update<textarea name="body" rows="6" maxlength="3000" required placeholder="Share news, events, or a helpful introduction to your team."></textarea></label>
                    <button class="button button-primary" type="submit">Publish update</button>
                </form>
            </section>
            <section class="panel form-panel">
                <div class="panel-heading"><div><h2>Your recent updates</h2><p><?= count($employerUpdates) ?> shown · newest first</p></div></div>
                <?php if ($employerUpdates): ?><div class="update-feed">
                    <?php foreach ($employerUpdates as $update): ?>
                        <article class="update-card">
                            <time datetime="<?= e(date(DATE_ATOM, strtotime($update['created_at']))) ?>"><?= e(date('M j, Y · g:i a', strtotime($update['created_at']))) ?></time>
                            <h3><?= e($update['title']) ?></h3><p><?= e($update['body']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div><?php else: ?><div class="inline-empty"><p>You have not published any updates yet.</p></div><?php endif; ?>
            </section>
        </div>
    <?php elseif ($user['role'] === 'jobseeker' && $page === 'applications'): ?>
        <div class="page-heading"><div><span class="eyebrow">YOUR NEXT STEP, AT A GLANCE</span><h1>My applications</h1><p>Track updates from employers and complete any practical challenges attached to a role.</p></div></div>
        <div class="system-guide compact-guide"><div class="guide-intro"><h2>Understanding application updates</h2><p>Employers update your status as they review. A practical challenge is separate evidence attached to the opening; submit it here when one is available.</p></div><div class="guide-grid">
            <article class="guide-card"><h3>Submitted</h3><p>Your application has been received. Check back for an employer update.</p></article>
            <article class="guide-card"><h3>In review</h3><p>The employer is considering your application. This is not yet a final decision.</p></article>
            <article class="guide-card"><h3>Shortlisted or hired</h3><p>Shortlisting means the employer wants to continue the conversation; hired records the employer's hiring decision.</p></article>
            <article class="guide-card"><h3>Not selected</h3><p>The employer has closed out this application. You can continue exploring other open roles.</p></article>
        </div></div>
        <?php if ($applications): ?><div class="application-list"><?php foreach ($applications as $application): ?>
            <article class="panel application-card">
                <div class="application-head"><div><p class="company-name"><?= e($application['company']) ?></p><h2><?= e($application['title']) ?></h2><p class="muted"><?= e($application['location']) ?> · Applied <?= e(date('M j, Y', strtotime($application['created_at']))) ?></p></div><span class="<?= e(badge_class($application['status'])) ?>"><?= e(status_label($application['status'])) ?></span></div>
                <?php if ($application['cover_note']): ?><p class="application-note"><?= e($application['cover_note']) ?></p><?php endif; ?>
                <?php if ($application['challenge_id']): ?>
                    <div class="challenge-box"><div class="challenge-title"><span class="heading-icon">✳</span><div><h3><?= e($application['challenge_title']) ?></h3><p><?= e($application['instructions']) ?></p></div><?php if ($application['challenge_status']): ?><span class="<?= e(badge_class($application['challenge_status'])) ?>"><?= e(status_label($application['challenge_status'])) ?></span><?php endif; ?></div>
                        <?php if ($application['reviewer_note']): ?><div class="review-feedback"><strong>Employer feedback</strong><p><?= e($application['reviewer_note']) ?></p></div><?php endif; ?>
                        <?php if ($application['submission_id'] && $application['challenge_status'] !== 'needs_work'): ?><div class="evidence-link"><strong>Your response</strong><p><?= e($application['submission_response']) ?></p><?php if ($application['submission_evidence']): ?><a href="<?= e($application['submission_evidence']) ?>" target="_blank" rel="noopener noreferrer">Open submitted evidence ↗</a><?php endif; ?></div><?php elseif ($application['status'] !== 'declined' && $application['status'] !== 'hired'): ?>
                            <form method="post" class="challenge-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="submit_challenge"><input type="hidden" name="challenge_id" value="<?= (int) $application['challenge_id'] ?>"><label><?= $application['submission_id'] ? 'Revise your response' : 'Your response' ?><textarea name="response" rows="3" maxlength="5000" required placeholder="Describe or show how you approached the task."><?= e($application['submission_response'] ?? '') ?></textarea></label><label>Link to evidence <span class="label-note">Optional</span><input type="url" name="evidence_url" value="<?= e($application['submission_evidence'] ?? '') ?>" placeholder="https://"></label><button class="button button-outline" type="submit"><?= $application['submission_id'] ? 'Resubmit practical challenge' : 'Submit practical challenge' ?></button></form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?></div><?php else: ?><div class="empty-state"><span class="empty-icon">↗</span><h2>Your next opportunity is out there</h2><p>Apply for a role to see its updates and any practical challenge here.</p><a class="button button-primary" href="index.php?page=opportunities">Explore open roles</a></div><?php endif; ?>
    <?php elseif ($user['role'] === 'employer' && $page === 'jobs'): ?>
        <div class="page-heading"><div><span class="eyebrow">BUILD YOUR TEAM</span><h1>My openings</h1><p>Describe the work, name the skills, and let candidates show their ability.</p></div><a class="button button-primary" href="#post-job">Post an opening <span aria-hidden="true">+</span></a></div>
        <div class="system-guide compact-guide"><div class="guide-intro"><h2>Write an opening candidates can act on</h2><p>Concrete descriptions help jobseekers understand the work and make skill-based comparisons more useful.</p></div><div class="guide-grid">
            <article class="guide-card"><h3>Describe outcomes</h3><p>Explain day-to-day responsibilities, expected contribution, location, and whether the work is on-site, hybrid, or remote.</p></article>
            <article class="guide-card"><h3>Name essential skills</h3><p>Choose the practical skills most relevant to the role. The system compares these with candidate profile skills.</p></article>
            <article class="guide-card"><h3>Make challenges consistent</h3><p>A challenge is optional. Give every applicant the same job-related task and explain what a useful response should contain.</p></article>
        </div></div>
        <section class="panel form-panel post-job-panel" id="post-job">
            <div class="panel-heading"><div><h2>Post a new role</h2><p>Clear skill requirements make candidate comparisons fairer and more useful.</p></div><span class="heading-icon">+</span></div>
            <form method="post" class="job-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_job">
                <label>Role title<input name="title" maxlength="160" required placeholder="e.g. Junior web assistant"></label>
                <label>Company<input name="company" maxlength="160" required></label>
                <label>Location<input name="location" maxlength="120" required placeholder="e.g. Douala"></label>
                <label>Work arrangement<select name="work_type"><option>On-site</option><option>Hybrid</option><option>Remote</option></select></label>
                <label class="full-field">What will this person do?<textarea name="description" rows="4" maxlength="5000" required></textarea></label>
                <label class="full-field">Skills needed <span class="label-note">Separate skills with a comma</span><input name="skills" placeholder="HTML, communication, customer service"></label>
                <div class="form-divider full-field"><span>OPTIONAL PRACTICAL CHALLENGE</span><p>Give every applicant a fair way to demonstrate a key skill.</p></div>
                <label>Challenge title<input name="challenge_title" maxlength="160" placeholder="e.g. Improve a sample product page"></label>
                <label>Challenge instructions<textarea name="challenge_instructions" rows="3" maxlength="4000" placeholder="Explain the task and what useful evidence looks like."></textarea></label>
                <div class="full-field"><button class="button button-primary" type="submit">Submit for admin approval</button></div>
            </form>
        </section>
        <div class="section-subheading"><h2>Your opening submissions</h2><span><?= count($jobs) ?> total</span></div>
        <?php if ($jobs): ?>
            <div class="employer-job-list">
                <?php foreach ($jobs as $job): ?>
                    <article class="panel employer-job-card">
                        <div class="employer-job-main">
                            <div class="job-card-top"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($job['company'], 0, 1))) ?></span><span class="<?= e(badge_class($job['status'])) ?>"><?= e(status_label($job['status'])) ?></span></div>
                            <h3><?= e($job['title']) ?></h3>
                            <p class="muted"><?= e($job['company']) ?> · <?= e($job['location']) ?> · <?= e($job['work_type']) ?></p>
                            <p class="job-description"><?= e($job['description']) ?></p>
                            <?php if ($job['skills']): ?><div class="tag-list"><?php foreach (explode(', ', $job['skills']) as $skill): ?><span class="tag"><?= e($skill) ?></span><?php endforeach; ?></div><?php endif; ?>
                            <?php if ($job['challenge_title']): ?><div class="job-challenge"><strong>Practical challenge: <?= e($job['challenge_title']) ?></strong><p><?= e($job['challenge_instructions']) ?></p></div><?php endif; ?>
                        </div>
                        <div class="employer-job-actions">
                            <a class="button button-primary" href="index.php?page=candidates&amp;job=<?= (int) $job['id'] ?>">Review <?= (int) $job['applicant_count'] ?> applicants <span aria-hidden="true">→</span></a>
                            <?php if ($job['status'] === 'pending'): ?><span class="tiny-note">Awaiting admin review</span>
                            <?php elseif (in_array($job['status'], ['open', 'closed', 'rejected'], true)): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $job['status'] === 'open' ? 'closed' : 'open' ?>">
                                    <button class="text-button" type="submit"><?= $job['status'] === 'open' ? 'Close role' : ($job['status'] === 'rejected' ? 'Resubmit for approval' : 'Reopen for approval') ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?><div class="empty-state"><h2>Start with your first opening</h2><p>Describe the role and the skills that matter most.</p></div><?php endif; ?>
    <?php elseif ($user['role'] === 'employer' && $page === 'candidates' && $selectedJob): ?>
        <div class="page-heading"><div><a class="back-link" href="index.php?page=jobs">← My openings</a><span class="eyebrow">CANDIDATE COMPARISON</span><h1><?= e($selectedJob['title']) ?></h1><p><?= e($selectedJob['company']) ?> · <?= e($selectedJob['location']) ?></p></div><span class="count-pill"><?= count($applicantRows) ?> applicants</span></div>
        <div class="ranking-note"><span class="insight-icon">i</span><p><strong>How this fit score works</strong> — required-skill overlap contributes up to 70 points; each project sample contributes 5 points, up to 20; a submitted role challenge contributes 5 points and a passed challenge contributes 10. The score is a comparison aid only, does not use personal characteristics, and must not replace your own review or hiring decision. Candidate names are abbreviated, and the admin mediates platform communication.</p></div>
        <?php if ($applicantRows): ?><div class="candidate-list"><?php foreach ($applicantRows as $index => $candidate): ?><article class="panel candidate-card">
            <div class="candidate-top"><div class="candidate-identity"><span class="candidate-avatar"><?= e(mb_strtoupper(mb_substr(candidate_display_name($candidate['name']), 0, 1))) ?></span><div><div class="candidate-title-line"><h2><?= e(candidate_display_name($candidate['name'])) ?></h2><span class="candidate-rank">#<?= $index + 1 ?></span></div><p><?= e($candidate['headline'] ?: 'Skills-first candidate') ?><?= $candidate['location'] ? ' · ' . e($candidate['location']) : '' ?></p></div></div><div class="fit-score"><strong><?= (int) $candidate['score'] ?><small>%</small></strong><span>role fit</span></div></div>
            <div class="score-breakdown"><span><?= number_format((float) $candidate['skill_points'], 1) ?>/70 skill points (<?= count($candidate['matched_skills']) ?>/<?= count($candidate['required_skills']) ?> matched)</span><span><?= (int) $candidate['project_points'] ?>/20 project points (<?= (int) $candidate['project_count'] ?> samples)</span><span><?= (int) $candidate['challenge_points'] ?>/10 challenge points (<?= !$candidate['challenge_id'] ? 'no challenge for this role' : ($candidate['challenge_status'] === 'passed' ? 'passed' : ($candidate['challenge_status'] === 'submitted' ? 'submitted' : ($candidate['challenge_status'] === 'needs_work' ? 'needs another look' : 'not submitted'))) ?></span></div>
            <?php if ($candidate['bio']): ?><p class="candidate-bio"><?= e($candidate['bio']) ?></p><?php endif; ?>
            <?php if ($candidate['skills']): ?><div class="tag-list"><?php foreach ($candidate['skills'] as $skill): ?><span class="tag <?= in_array(strtolower($skill), array_map('strtolower', $candidate['required_skills']), true) ? 'tag-match' : '' ?>"><?= e($skill) ?></span><?php endforeach; ?></div><?php else: ?><p class="muted">No skills added to this profile yet.</p><?php endif; ?>
            <?php if ($candidate['projects']): ?><div class="candidate-projects"><strong>Work samples</strong><?php foreach ($candidate['projects'] as $project): ?><article class="project-item"><h3><?= e($project['title']) ?></h3><p><?= e($project['description']) ?></p><?php if ($project['evidence_url']): ?><a href="<?= e($project['evidence_url']) ?>" target="_blank" rel="noopener noreferrer">Review project evidence ↗</a><?php endif; ?></article><?php endforeach; ?></div><?php else: ?><p class="muted">No project samples have been added to this profile yet.</p><?php endif; ?>
            <?php if ($candidate['cover_note']): ?><div class="candidate-note"><strong>Application note</strong><p><?= e($candidate['cover_note']) ?></p></div><?php endif; ?>
            <?php if ($candidate['challenge_id'] && $candidate['submission_id']): ?><div class="submitted-work"><div><strong><?= e($candidate['challenge_title']) ?></strong><span class="<?= e(badge_class($candidate['challenge_status'])) ?>"><?= e(status_label($candidate['challenge_status'])) ?></span></div><p><?= e($candidate['submission_response']) ?></p><?php if ($candidate['submission_evidence']): ?><a href="<?= e($candidate['submission_evidence']) ?>" target="_blank" rel="noopener noreferrer">View submitted evidence ↗</a><?php endif; ?>
                <?php if ($candidate['challenge_status'] === 'submitted'): ?><form method="post" class="review-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review_challenge"><input type="hidden" name="submission_id" value="<?= (int) $candidate['submission_id'] ?>"><input type="hidden" name="job_id" value="<?= (int) $selectedJob['id'] ?>"><label class="sr-only" for="feedback-<?= (int) $candidate['submission_id'] ?>">Feedback on challenge</label><input id="feedback-<?= (int) $candidate['submission_id'] ?>" name="reviewer_note" maxlength="1500" placeholder="Feedback to the candidate (optional)"><button class="button button-outline button-small" name="status" value="needs_work" type="submit">Needs work</button><button class="button button-primary button-small" name="status" value="passed" type="submit">Mark passed</button></form><?php elseif ($candidate['reviewer_note']): ?><p class="review-feedback"><strong>Your feedback:</strong> <?= e($candidate['reviewer_note']) ?></p><?php endif; ?>
            </div><?php endif; ?>
            <div class="candidate-footer"><div><span class="<?= e(badge_class($candidate['application_status'])) ?>"><?= e(status_label($candidate['application_status'])) ?></span><?php if ($candidate['application_status'] === 'shortlisted'): ?><a class="contact-reveal" href="index.php?page=messages">Ask admin to mediate contact →</a><?php endif; ?></div><form method="post" class="status-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_application"><input type="hidden" name="application_id" value="<?= (int) $candidate['application_id'] ?>"><input type="hidden" name="job_id" value="<?= (int) $selectedJob['id'] ?>"><label class="sr-only" for="status-<?= (int) $candidate['application_id'] ?>">Update application status</label><select id="status-<?= (int) $candidate['application_id'] ?>" name="status"><option value="reviewing" <?= $candidate['application_status'] === 'reviewing' ? 'selected' : '' ?>>In review</option><option value="shortlisted" <?= $candidate['application_status'] === 'shortlisted' ? 'selected' : '' ?>>Shortlist</option><option value="declined" <?= $candidate['application_status'] === 'declined' ? 'selected' : '' ?>>Not selected</option><option value="hired" <?= $candidate['application_status'] === 'hired' ? 'selected' : '' ?>>Hired</option></select><button class="button button-primary button-small" type="submit">Save status</button></form></div>
        </article><?php endforeach; ?></div><?php else: ?><div class="empty-state"><h2>Applicants will appear here</h2><p>Share this opening and compare candidates when they apply.</p></div><?php endif; ?>
    <?php elseif ($page === 'messages'): ?>
        <div class="page-heading"><div><span class="eyebrow">ADMIN-MEDIATED COMMUNICATION</span><h1>Message center</h1><p><?= $user['role'] === 'admin' ? 'Review requests and communicate with employers and employees as the neutral platform administrator.' : 'Send your question to the SkillPass admin. Messages between employers and employees are handled through the admin.' ?></p></div></div>
        <div class="message-layout">
            <form class="panel form-panel message-compose" method="post">
                <div class="panel-heading"><div><h2><?= $user['role'] === 'admin' ? 'Send a message' : 'Contact the SkillPass admin' ?></h2><p>Messages are stored in the platform and visible to their sender, recipient, and admin.</p></div><span class="heading-icon">✉</span></div>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="send_message">
                <?php if ($user['role'] === 'admin'): ?>
                    <label>Recipient<select name="recipient_id" required><option value="">Select an account</option><?php foreach ($messageRecipients as $recipient): ?><option value="<?= (int) $recipient['id'] ?>"><?= e($recipient['name']) ?> · <?= e($recipient['role'] === 'jobseeker' ? 'Employee' : 'Employer') ?> · <?= e($recipient['email']) ?> (<?= e(status_label($recipient['status'])) ?>)</option><?php endforeach; ?></select></label>
                <?php else: ?><p class="message-admin-recipient"><strong>To:</strong> SkillPass Admin</p><?php endif; ?>
                <label>Message<textarea name="body" rows="6" maxlength="4000" required placeholder="Write a clear message (up to 4,000 characters)."></textarea></label>
                <button class="button button-primary" type="submit">Send message</button>
            </form>
            <section class="message-list">
                <div class="section-subheading"><h2><?= $user['role'] === 'admin' ? 'Platform conversations' : 'Your conversation with the admin' ?></h2><span><?= count($messages) ?> shown</span></div>
                <?php if ($messages): ?>
                    <?php foreach ($messages as $message): $sentByUser = (int) $message['sender_id'] === (int) $user['id']; ?>
                        <article class="panel message-card<?= $sentByUser ? ' message-sent' : '' ?>">
                            <div class="message-meta"><strong><?= e($sentByUser ? 'You' : $message['sender_name']) ?></strong><span><?= e(date('M j, Y · g:i a', strtotime($message['created_at']))) ?></span></div>
                            <?php if ($user['role'] === 'admin'): ?><p class="message-direction"><?= $sentByUser ? 'To ' . e($message['recipient_name']) : 'From ' . e($message['sender_name']) ?> · <?= e($sentByUser ? $message['recipient_role'] : $message['sender_role']) ?></p><?php endif; ?>
                            <p class="message-body"><?= nl2br(e($message['body'])) ?></p>
                            <?php if ($user['role'] === 'admin' && !$sentByUser && $message['sender_role'] !== 'admin'): ?>
                                <form class="message-reply" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="send_message"><input type="hidden" name="recipient_id" value="<?= (int) $message['sender_id'] ?>"><label class="sr-only" for="reply-<?= (int) $message['id'] ?>">Reply to <?= e($message['sender_name']) ?></label><textarea id="reply-<?= (int) $message['id'] ?>" name="body" rows="2" maxlength="4000" required placeholder="Reply to <?= e($message['sender_name']) ?>"></textarea><button class="button button-outline button-small" type="submit">Reply</button></form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?><div class="empty-state"><h2>No messages yet</h2><p><?= $user['role'] === 'admin' ? 'Messages from employers and employees will appear here.' : 'Send a message if you need help or want the admin to facilitate communication.' ?></p></div><?php endif; ?>
            </section>
        </div>
    <?php elseif ($user['role'] === 'admin'): ?>
        <div class="page-heading"><div><span class="eyebrow">PLATFORM OVERSIGHT</span><h1>Admin dashboard</h1><p>Review job posts, support both sides, and oversee activity across SkillPass.</p></div><a class="button button-outline" href="index.php?page=messages">Open messages<?php if ($counts['unread_messages']): ?> · <?= (int) $counts['unread_messages'] ?> unread<?php endif; ?></a></div>
        <div class="stats-grid admin-stats"><article class="stat-card"><span class="stat-icon">▤</span><strong><?= $counts['pending_jobs'] ?></strong><span>Job posts awaiting review</span></article><article class="stat-card"><span class="stat-icon">◎</span><strong><?= $counts['active_accounts'] ?></strong><span>Active employer/employee accounts</span></article><article class="stat-card"><span class="stat-icon">⌂</span><strong><?= $counts['open_jobs'] ?></strong><span>Published openings</span></article><article class="stat-card"><span class="stat-icon">↗</span><strong><?= $counts['applications'] ?></strong><span>Total applications</span></article><article class="stat-card"><span class="stat-icon">✉</span><strong><?= $counts['unread_messages'] ?></strong><span>Unread admin messages</span></article></div>
        <section class="system-guide compact-guide"><div class="guide-intro"><span class="eyebrow">YOUR ROLE AS THE MIDDLE PERSON</span><h2>Keep the marketplace trusted and fair</h2><p>New employee and employer accounts can sign in immediately. Review job posts before publication and use the message center to support both sides. Applications and hiring decisions remain with employers.</p></div></section>
        <div class="admin-review-grid">
            <section class="panel admin-section"><div class="panel-heading"><div><h2>Job-post requests</h2><p>Only approved openings are visible to employees and the public.</p></div><span class="count-pill"><?= $counts['pending_jobs'] ?> pending</span></div>
                <?php if ($pendingJobs): ?><div class="admin-record-list"><?php foreach ($pendingJobs as $job): ?><article class="admin-record"><div><strong><?= e($job['title']) ?> · <?= e($job['company']) ?></strong><p><?= e($job['employer_name']) ?> · <?= e($job['location']) ?> · <?= e($job['work_type']) ?></p><p><?= e($job['description']) ?></p></div><div class="admin-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><button class="button button-primary button-small" name="status" value="open" type="submit">Approve & publish</button><button class="button button-outline button-small" name="status" value="rejected" type="submit">Decline</button></form></div></article><?php endforeach; ?></div><?php else: ?><p class="inline-empty">No job posts need review.</p><?php endif; ?>
            </section>
        </div>
        <section class="panel admin-section admin-oversight"><div class="panel-heading"><div><h2>Recent applications</h2><p>Oversight only: employers manage application statuses and make hiring decisions.</p></div></div>
            <?php if ($adminApplications): ?><div class="admin-record-list"><?php foreach ($adminApplications as $application): ?><article class="admin-record"><div><strong><?= e($application['title']) ?> · <?= e($application['company']) ?></strong><p><?= e($application['candidate_name']) ?> applied <?= e(date('M j, Y', strtotime($application['created_at']))) ?></p></div><span class="<?= e(badge_class($application['status'])) ?>"><?= e(status_label($application['status'])) ?></span></article><?php endforeach; ?></div><?php else: ?><p class="inline-empty">Applications will be listed here as employees apply to approved roles.</p><?php endif; ?>
        </section>
    <?php elseif ($user['role'] === 'jobseeker'): ?>
        <section class="welcome-banner"><div><span class="eyebrow">YOUR SKILLS HAVE A PLACE HERE</span><h1>Good <?= date('G') < 12 ? 'morning' : (date('G') < 17 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $user['name'])[0]) ?>.</h1><p>Build your profile with real work and discover roles that fit.</p></div><div class="welcome-mark">S<span>+</span></div></section>
        <div class="stats-grid"><article class="stat-card"><span class="stat-icon">↗</span><strong><?= $counts['applications'] ?></strong><span>Applications sent</span></article><article class="stat-card"><span class="stat-icon">✓</span><strong><?= $counts['shortlisted'] ?></strong><span>Shortlisted</span></article><article class="stat-card"><span class="stat-icon">◎</span><strong><?= count($skillNames) ?></strong><span>Skills listed</span></article><article class="stat-card"><span class="stat-icon">▧</span><strong><?= $counts['projects'] ?></strong><span>Work samples</span></article></div>
        <div class="system-guide compact-guide"><div class="guide-intro"><h2>Your jobseeker dashboard</h2><p>Use this overview to strengthen your profile, explore relevant open roles, and keep track of applications. Profile completion is a checklist: headline 20%, location 15%, summary 25%, at least one skill 20%, and at least one project 20%.</p></div><div class="guide-grid">
            <article class="guide-card"><h3>Profile completion is not a fit score</h3><p>The completion bar shows whether key profile sections are filled in. Each employer role has its own fit comparison based on that role's required skills, project evidence, and challenge.</p></article>
            <article class="guide-card"><h3>Skill matches are a starting point</h3><p>Suggested roles show overlap between your listed skills and the role's skill tags. Review the complete role details to decide whether it suits you.</p></article>
        </div></div>
        <div class="dashboard-columns"><section class="panel dashboard-panel"><div class="panel-heading"><div><h2>Roles chosen for your skills</h2><p>Compare your strengths with what employers need.</p></div><a href="index.php?page=opportunities">See all →</a></div>
            <?php if ($openJobs): ?><div class="compact-job-list"><?php foreach (array_slice($openJobs, 0, 3) as $job): $jobSkills = $job['skills'] ? explode(', ', $job['skills']) : []; $matches = array_intersect(array_map('strtolower', $jobSkills), array_map('strtolower', $skillNames)); ?><a class="compact-job" href="index.php?page=opportunities"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($job['company'], 0, 1))) ?></span><span class="compact-job-copy"><strong><?= e($job['title']) ?></strong><small><?= e($job['company']) ?> · <?= e($job['location']) ?></small></span><span class="match-pill"><?= count($jobSkills) ? (int) round(count($matches) / count($jobSkills) * 100) : 0 ?>% match</span></a><?php endforeach; ?></div><?php else: ?><p class="muted">New openings will show up here.</p><?php endif; ?>
        </section><section class="panel dashboard-panel"><div class="panel-heading"><div><h2>Make your skills visible</h2><p>Profiles with evidence give employers more to evaluate.</p></div><span class="heading-icon">✳</span></div><div class="profile-progress"><div class="progress-track"><progress max="100" value="<?= $profileCompletion ?>" aria-label="Profile completion"></progress></div><strong><?= $profileCompletion ?>% complete</strong></div><a class="button button-outline button-full" href="index.php?page=profile">Add skills and project evidence →</a><?php if ($gapSkill): ?><div class="gap-callout"><span>↗</span><p>Adding <strong><?= e($gapSkill['name']) ?></strong> could help you match more of the <?= (int) $gapSkill['demand'] ?> open <?= (int) $gapSkill['demand'] === 1 ? 'role' : 'roles' ?> that need it.</p></div><?php endif; ?></section></div>
        <section class="panel dashboard-panel recent-applications"><div class="panel-heading"><div><h2>Recent applications</h2><p>See where things stand.</p></div><a href="index.php?page=applications">View all →</a></div><?php if ($applications): ?><div class="recent-list"><?php foreach (array_slice($applications, 0, 3) as $application): ?><div class="recent-row"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($application['company'], 0, 1))) ?></span><div><strong><?= e($application['title']) ?></strong><small><?= e($application['company']) ?></small></div><span class="<?= e(badge_class($application['status'])) ?>"><?= e(status_label($application['status'])) ?></span></div><?php endforeach; ?></div><?php else: ?><div class="inline-empty"><p>No applications yet. A good first step is to find a role that sounds right.</p><a href="index.php?page=opportunities">Explore openings →</a></div><?php endif; ?></section>
    <?php else: ?>
        <section class="welcome-banner employer-welcome"><div><span class="eyebrow">BETTER HIRING STARTS WITH BETTER EVIDENCE</span><h1>Welcome, <?= e(explode(' ', $user['name'])[0]) ?>.</h1><p>See what people can do, not only what their CV says.</p></div><a class="button button-primary" href="index.php?page=jobs#post-job">Post an opening <span aria-hidden="true">+</span></a></section>
        <div class="stats-grid"><article class="stat-card"><span class="stat-icon">▤</span><strong><?= $counts['open_jobs'] ?></strong><span>Open roles</span></article><article class="stat-card"><span class="stat-icon">♙</span><strong><?= $counts['candidates'] ?></strong><span>Total applicants</span></article><article class="stat-card"><span class="stat-icon">✓</span><strong><?= $counts['shortlisted'] ?></strong><span>Shortlisted candidates</span></article></div>
        <div class="system-guide compact-guide"><div class="guide-intro"><h2>Your employer workspace</h2><p>Post openings that explain the work, then review candidates using comparable, role-related information.</p></div><div class="guide-grid">
            <article class="guide-card"><h3>Define the requirements</h3><p>List the skills that are genuinely important and use the same optional challenge for everyone applying to that opening.</p></article>
            <article class="guide-card"><h3>Review the evidence</h3><p>Compare each candidate's skill overlap, project samples, and practical challenge. Read the candidate's summary and application note too.</p></article>
            <article class="guide-card"><h3>Move applications forward</h3><p>Update statuses from the candidate list. Use the message center to contact the SkillPass admin, who mediates communication with employees.</p></article>
        </div></div>
        <section class="panel dashboard-panel employer-openings"><div class="panel-heading"><div><h2>Your openings</h2><p>Review candidate evidence and move each application forward.</p></div><a href="index.php?page=jobs">Manage openings →</a></div>
        <?php if ($jobs): ?><div class="compact-job-list"><?php foreach (array_slice($jobs, 0, 4) as $job): ?><a class="compact-job" href="index.php?page=candidates&amp;job=<?= (int) $job['id'] ?>"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($job['company'], 0, 1))) ?></span><span class="compact-job-copy"><strong><?= e($job['title']) ?></strong><small><?= e($job['location']) ?> · <?= e($job['skills'] ?: 'No skills specified') ?></small></span><span class="applicant-pill"><?= (int) $job['applicant_count'] ?> applicants</span><span class="<?= e(badge_class($job['status'])) ?>"><?= e(status_label($job['status'])) ?></span><span class="preview-arrow">→</span></a><?php endforeach; ?></div><?php else: ?><div class="inline-empty"><p>Your first role will give skills-first candidates a place to show what they can do.</p><a href="index.php?page=jobs#post-job">Post an opening →</a></div><?php endif; ?></section>
        <div class="privacy-note"><span>◈</span><p><strong>Fair, evidence-led shortlisting.</strong> Candidate scores use only required skills, work samples, and role challenges. Contact details remain private; communication is mediated by the admin.</p></div>
    <?php endif; ?>
</main>
<footer class="site-footer"><a class="brand" href="index.php" aria-label="SkillPass home"><img class="brand-logo" src="images/logo%202.jpeg" alt="SkillPass Aurora — Proving What You Can Do" width="160" height="100"></a><span>Proving what you can do, one skill at a time.</span><span>Built for opportunity in Cameroon.</span></footer>
<a class="whatsapp-float" href="https://wa.me/<?= e($whatsappNumber) ?>?text=<?= e(rawurlencode('Hi SkillPass, I would like to learn more about the platform.')) ?>" target="_blank" rel="noopener noreferrer" aria-label="Chat with SkillPass on WhatsApp" title="Chat with us on WhatsApp">
    <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <path d="M16.02 2.1a13.8 13.8 0 0 0-11.8 21l-1.4 5.1 5.2-1.36A13.9 13.9 0 1 0 16.02 2.1Zm0 25.2c-2.1 0-4.1-.55-5.87-1.61l-.42-.25-3.1.81.83-3.02-.27-.44a11.3 11.3 0 1 1 8.83 4.51Zm6.2-8.47c-.34-.17-2.02-1-2.34-1.1-.31-.11-.54-.17-.76.17-.23.34-.88 1.1-1.08 1.33-.2.22-.4.25-.74.08-.34-.17-1.44-.53-2.74-1.68-1.01-.9-1.69-2.02-1.89-2.36-.2-.34-.02-.52.15-.69.15-.15.34-.4.51-.6.17-.2.23-.34.34-.56.12-.23.06-.43-.03-.6-.08-.17-.76-1.84-1.04-2.52-.27-.66-.55-.57-.76-.58h-.65c-.23 0-.6.08-.91.42-.31.34-1.2 1.17-1.2 2.84 0 1.68 1.22 3.3 1.39 3.52.17.23 2.4 3.66 5.81 5.13.81.35 1.45.56 1.95.72.82.26 1.56.22 2.14.13.66-.1 2.02-.83 2.31-1.63.28-.8.28-1.48.2-1.63-.09-.14-.31-.23-.65-.4Z"/>
    </svg>
</a>
</body>
</html>
