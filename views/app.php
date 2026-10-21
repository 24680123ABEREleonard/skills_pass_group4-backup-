<?php
declare(strict_types=1);
$isPublic = !$user || $page === 'how-it-works';
$profileCompletion = is_array($profile)
    ? (int) min(100, (($profile['headline'] ? 20 : 0) + ($profile['location'] ? 15 : 0) + ($profile['bio'] ? 25 : 0) + (count($skillNames) ? 20 : 0) + (count($projects) ? 20 : 0)))
    : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#202229">
    <title><?= $page === 'how-it-works' ? 'How SkillPass Works — SkillPass' : 'SkillPass — proof over paperwork' ?></title>
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
            <?php if ($user['role'] === 'jobseeker'): ?>
                <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="index.php">Overview</a>
                <a class="<?= $page === 'opportunities' ? 'active' : '' ?>" href="index.php?page=opportunities">Find work</a>
                <a class="<?= $page === 'profile' ? 'active' : '' ?>" href="index.php?page=profile">My profile</a>
                <a class="<?= $page === 'applications' ? 'active' : '' ?>" href="index.php?page=applications">Applications</a>
            <?php else: ?>
                <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="index.php">Overview</a>
                <a class="<?= $page === 'jobs' ? 'active' : '' ?>" href="index.php?page=jobs">My openings</a>
            <?php endif; ?>
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
                <article class="feature-card"><span class="feature-number">01</span><span class="feature-icon">◎</span><h2>Build a skills profile</h2><p>Share the skills you have, the projects you have done, and the evidence behind your work.</p></article>
                <article class="feature-card"><span class="feature-number">02</span><span class="feature-icon">↗</span><h2>Show what you can do</h2><p>Take practical job challenges. Employers review the same work-based evidence for every applicant.</p></article>
                <article class="feature-card"><span class="feature-number">03</span><span class="feature-icon">⌕</span><h2>Find a genuine fit</h2><p>Compare applicants against the skills a role needs, with a clear explanation of every match.</p></article>
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
            </div>
            <form class="panel auth-card" method="post">
                <h2>Sign in</h2>
                <p class="muted">Use your SkillPass account to continue.</p>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="login">
                <label>Email address<input type="email" name="email" autocomplete="email" required></label>
                <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
                <button class="button button-primary button-full" type="submit">Sign in</button>
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
            </div>
            <form class="panel auth-card" method="post">
                <h2>Create your account</h2>
                <p class="muted">Choose the space that fits what you need.</p>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="register">
                <label>Your name<input type="text" name="name" autocomplete="name" maxlength="120" required></label>
                <label>Email address<input type="email" name="email" autocomplete="email" required></label>
                <label>Password<input type="password" name="password" autocomplete="new-password" minlength="8" required><span class="field-hint">At least 8 characters.</span></label>
                <label>I am here to
                    <select name="role" required>
                        <option value="jobseeker">Find work and show my skills</option>
                        <option value="employer">Hire people for my team</option>
                    </select>
                </label>
                <button class="button button-primary button-full" type="submit">Create account</button>
                <p class="form-foot">Already have an account? <a href="index.php?page=login">Sign in</a></p>
            </form>
        </section>
    <?php elseif (!$user && $page === 'opportunities'): ?>
        <section class="public-heading">
            <span class="eyebrow">OPEN OPPORTUNITIES</span>
            <h1>Find work that values what you can do.</h1>
            <p>Every opening explains the skills it needs. Sign in to apply and share your evidence.</p>
        </section>
        <?php
        $statement = $pdo->query(
            "SELECT j.id, j.title, j.company, j.location, j.work_type, j.description,
                    (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
                     FROM job_skills js JOIN skills s ON s.id = js.skill_id WHERE js.job_id = j.id) AS skills
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
                        <p><?= e(mb_strimwidth($job['description'], 0, 155, '…')) ?></p>
                        <?php if ($job['skills']): ?><div class="tag-list"><?php foreach (explode(', ', $job['skills']) as $skill): ?><span class="tag"><?= e($skill) ?></span><?php endforeach; ?></div><?php endif; ?>
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
                <div class="privacy-note"><span>◈</span><p><strong>Your contact details stay private.</strong> Employers only see your email after they shortlist you.</p></div>
            </div>
        </div>
    <?php elseif ($user['role'] === 'jobseeker' && $page === 'opportunities'): ?>
        <div class="page-heading"><div><span class="eyebrow">FIND YOUR NEXT STEP</span><h1>Open opportunities</h1><p>See the skills each role needs and apply with evidence of what you can do.</p></div><span class="count-pill"><?= count($openJobs) ?> open roles</span></div>
        <?php if ($gapSkill): ?><div class="insight-banner"><span class="insight-icon">✳</span><div><strong>A skill that could open more doors</strong><p><?= e($gapSkill['name']) ?> appears in <?= (int) $gapSkill['demand'] ?> more open <?= (int) $gapSkill['demand'] === 1 ? 'role' : 'roles' ?> than the skills on your profile.</p></div><a href="index.php?page=profile">Update my profile →</a></div><?php endif; ?>
        <?php if ($openJobs): ?><div class="job-grid"><?php foreach ($openJobs as $job): ?>
            <article class="panel job-card">
                <div class="job-card-top"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($job['company'], 0, 1))) ?></span><span class="badge badge-green">Open</span></div>
                <p class="company-name"><?= e($job['company']) ?></p><h2><?= e($job['title']) ?></h2><p class="muted"><?= e($job['location']) ?> · <?= e($job['work_type']) ?></p>
                <p><?= e(mb_strimwidth($job['description'], 0, 190, '…')) ?></p>
                <?php if ($job['skills']): ?><div class="tag-list"><?php foreach (explode(', ', $job['skills']) as $skill): ?><span class="tag <?= in_array(strtolower($skill), array_map('strtolower', $skillNames), true) ? 'tag-match' : '' ?>"><?= e($skill) ?></span><?php endforeach; ?></div><?php endif; ?>
                <?php if ($job['applied']): ?><span class="button button-done button-full">Application sent</span><?php else: ?><form method="post" class="apply-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="apply_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><label class="sr-only" for="note-<?= (int) $job['id'] ?>">Optional note for <?= e($job['title']) ?></label><textarea id="note-<?= (int) $job['id'] ?>" name="cover_note" rows="2" maxlength="2000" placeholder="Add a short note (optional)"></textarea><button class="button button-primary button-full" type="submit">Apply for this role <span aria-hidden="true">→</span></button></form><?php endif; ?>
            </article>
        <?php endforeach; ?></div><?php else: ?><div class="empty-state"><h2>No open roles yet</h2><p>New opportunities will show here as employers post them.</p></div><?php endif; ?>
    <?php elseif ($user['role'] === 'jobseeker' && $page === 'applications'): ?>
        <div class="page-heading"><div><span class="eyebrow">YOUR NEXT STEP, AT A GLANCE</span><h1>My applications</h1><p>Track updates from employers and complete any practical challenges attached to a role.</p></div></div>
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
                <div class="full-field"><button class="button button-primary" type="submit">Publish opening</button></div>
            </form>
        </section>
        <div class="section-subheading"><h2>Your published openings</h2><span><?= count($jobs) ?> total</span></div>
        <?php if ($jobs): ?><div class="employer-job-list"><?php foreach ($jobs as $job): ?><article class="panel employer-job-card"><div class="employer-job-main"><div class="job-card-top"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($job['company'], 0, 1))) ?></span><span class="<?= e(badge_class($job['status'])) ?>"><?= e(status_label($job['status'])) ?></span></div><h3><?= e($job['title']) ?></h3><p class="muted"><?= e($job['company']) ?> · <?= e($job['location']) ?> · <?= e($job['work_type']) ?></p><?php if ($job['skills']): ?><div class="tag-list"><?php foreach (explode(', ', $job['skills']) as $skill): ?><span class="tag"><?= e($skill) ?></span><?php endforeach; ?></div><?php endif; ?><?php if ($job['challenge_title']): ?><p class="tiny-note">Practical challenge: <?= e($job['challenge_title']) ?></p><?php endif; ?></div><div class="employer-job-actions"><a class="button button-primary" href="index.php?page=candidates&amp;job=<?= (int) $job['id'] ?>">View <?= (int) $job['applicant_count'] ?> applicants <span aria-hidden="true">→</span></a><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_job"><input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>"><input type="hidden" name="status" value="<?= $job['status'] === 'open' ? 'closed' : 'open' ?>"><button class="text-button" type="submit"><?= $job['status'] === 'open' ? 'Close role' : 'Reopen role' ?></button></form></div></article><?php endforeach; ?></div><?php else: ?><div class="empty-state"><h2>Start with your first opening</h2><p>Describe the role and the skills that matter most.</p></div><?php endif; ?>
    <?php elseif ($user['role'] === 'employer' && $page === 'candidates' && $selectedJob): ?>
        <div class="page-heading"><div><a class="back-link" href="index.php?page=jobs">← My openings</a><span class="eyebrow">CANDIDATE COMPARISON</span><h1><?= e($selectedJob['title']) ?></h1><p><?= e($selectedJob['company']) ?> · <?= e($selectedJob['location']) ?></p></div><span class="count-pill"><?= count($applicantRows) ?> applicants</span></div>
        <div class="ranking-note"><span class="insight-icon">i</span><p><strong>How this fit score works</strong> — 70% required-skill overlap, up to 20% for project evidence, and up to 10% for this role’s practical challenge (submitted or passed). It does not use personal characteristics. Use it as a guide, not an automatic hiring decision.</p></div>
        <?php if ($applicantRows): ?><div class="candidate-list"><?php foreach ($applicantRows as $index => $candidate): ?><article class="panel candidate-card">
            <div class="candidate-top"><div class="candidate-identity"><span class="candidate-avatar"><?= e(mb_strtoupper(mb_substr(candidate_display_name($candidate['name']), 0, 1))) ?></span><div><div class="candidate-title-line"><h2><?= e(candidate_display_name($candidate['name'])) ?></h2><span class="candidate-rank">#<?= $index + 1 ?></span></div><p><?= e($candidate['headline'] ?: 'Skills-first candidate') ?><?= $candidate['location'] ? ' · ' . e($candidate['location']) : '' ?></p></div></div><div class="fit-score"><strong><?= (int) $candidate['score'] ?><small>%</small></strong><span>role fit</span></div></div>
            <div class="score-breakdown"><span><?= count($candidate['matched_skills']) ?>/<?= count($candidate['required_skills']) ?> skills matched</span><span><?= (int) $candidate['project_count'] ?> work <?= (int) $candidate['project_count'] === 1 ? 'sample' : 'samples' ?></span><span><?= $candidate['challenge_status'] === 'passed' ? 'Challenge passed' : ($candidate['challenge_status'] === 'submitted' ? 'Challenge submitted' : 'No challenge yet') ?></span></div>
            <?php if ($candidate['bio']): ?><p class="candidate-bio"><?= e($candidate['bio']) ?></p><?php endif; ?>
            <?php if ($candidate['skills']): ?><div class="tag-list"><?php foreach ($candidate['skills'] as $skill): ?><span class="tag <?= in_array(strtolower($skill), array_map('strtolower', $candidate['required_skills']), true) ? 'tag-match' : '' ?>"><?= e($skill) ?></span><?php endforeach; ?></div><?php else: ?><p class="muted">No skills added to this profile yet.</p><?php endif; ?>
            <?php if ($candidate['cover_note']): ?><div class="candidate-note"><strong>Application note</strong><p><?= e($candidate['cover_note']) ?></p></div><?php endif; ?>
            <?php if ($candidate['challenge_id'] && $candidate['submission_id']): ?><div class="submitted-work"><div><strong><?= e($candidate['challenge_title']) ?></strong><span class="<?= e(badge_class($candidate['challenge_status'])) ?>"><?= e(status_label($candidate['challenge_status'])) ?></span></div><p><?= e($candidate['submission_response']) ?></p><?php if ($candidate['submission_evidence']): ?><a href="<?= e($candidate['submission_evidence']) ?>" target="_blank" rel="noopener noreferrer">View submitted evidence ↗</a><?php endif; ?>
                <?php if ($candidate['challenge_status'] === 'submitted'): ?><form method="post" class="review-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review_challenge"><input type="hidden" name="submission_id" value="<?= (int) $candidate['submission_id'] ?>"><input type="hidden" name="job_id" value="<?= (int) $selectedJob['id'] ?>"><label class="sr-only" for="feedback-<?= (int) $candidate['submission_id'] ?>">Feedback on challenge</label><input id="feedback-<?= (int) $candidate['submission_id'] ?>" name="reviewer_note" maxlength="1500" placeholder="Feedback to the candidate (optional)"><button class="button button-outline button-small" name="status" value="needs_work" type="submit">Needs work</button><button class="button button-primary button-small" name="status" value="passed" type="submit">Mark passed</button></form><?php elseif ($candidate['reviewer_note']): ?><p class="review-feedback"><strong>Your feedback:</strong> <?= e($candidate['reviewer_note']) ?></p><?php endif; ?>
            </div><?php endif; ?>
            <div class="candidate-footer"><div><span class="<?= e(badge_class($candidate['application_status'])) ?>"><?= e(status_label($candidate['application_status'])) ?></span><?php if ($candidate['application_status'] === 'shortlisted'): ?><a class="contact-reveal" href="mailto:<?= e($candidate['email']) ?>">Contact <?= e(explode(' ', $candidate['name'])[0]) ?> ↗</a><?php endif; ?></div><form method="post" class="status-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_application"><input type="hidden" name="application_id" value="<?= (int) $candidate['application_id'] ?>"><input type="hidden" name="job_id" value="<?= (int) $selectedJob['id'] ?>"><label class="sr-only" for="status-<?= (int) $candidate['application_id'] ?>">Update application status</label><select id="status-<?= (int) $candidate['application_id'] ?>" name="status"><option value="reviewing" <?= $candidate['application_status'] === 'reviewing' ? 'selected' : '' ?>>In review</option><option value="shortlisted" <?= $candidate['application_status'] === 'shortlisted' ? 'selected' : '' ?>>Shortlist</option><option value="declined" <?= $candidate['application_status'] === 'declined' ? 'selected' : '' ?>>Not selected</option><option value="hired" <?= $candidate['application_status'] === 'hired' ? 'selected' : '' ?>>Hired</option></select><button class="button button-primary button-small" type="submit">Save status</button></form></div>
        </article><?php endforeach; ?></div><?php else: ?><div class="empty-state"><h2>Applicants will appear here</h2><p>Share this opening and compare candidates when they apply.</p></div><?php endif; ?>
    <?php elseif ($user['role'] === 'jobseeker'): ?>
        <section class="welcome-banner"><div><span class="eyebrow">YOUR SKILLS HAVE A PLACE HERE</span><h1>Good <?= date('G') < 12 ? 'morning' : (date('G') < 17 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $user['name'])[0]) ?>.</h1><p>Build your profile with real work and discover roles that fit.</p></div><div class="welcome-mark">S<span>+</span></div></section>
        <div class="stats-grid"><article class="stat-card"><span class="stat-icon">↗</span><strong><?= $counts['applications'] ?></strong><span>Applications sent</span></article><article class="stat-card"><span class="stat-icon">✓</span><strong><?= $counts['shortlisted'] ?></strong><span>Shortlisted</span></article><article class="stat-card"><span class="stat-icon">◎</span><strong><?= count($skillNames) ?></strong><span>Skills listed</span></article><article class="stat-card"><span class="stat-icon">▧</span><strong><?= $counts['projects'] ?></strong><span>Work samples</span></article></div>
        <div class="dashboard-columns"><section class="panel dashboard-panel"><div class="panel-heading"><div><h2>Roles chosen for your skills</h2><p>Compare your strengths with what employers need.</p></div><a href="index.php?page=opportunities">See all →</a></div>
            <?php if ($openJobs): ?><div class="compact-job-list"><?php foreach (array_slice($openJobs, 0, 3) as $job): $jobSkills = $job['skills'] ? explode(', ', $job['skills']) : []; $matches = array_intersect(array_map('strtolower', $jobSkills), array_map('strtolower', $skillNames)); ?><a class="compact-job" href="index.php?page=opportunities"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($job['company'], 0, 1))) ?></span><span class="compact-job-copy"><strong><?= e($job['title']) ?></strong><small><?= e($job['company']) ?> · <?= e($job['location']) ?></small></span><span class="match-pill"><?= count($jobSkills) ? (int) round(count($matches) / count($jobSkills) * 100) : 0 ?>% match</span></a><?php endforeach; ?></div><?php else: ?><p class="muted">New openings will show up here.</p><?php endif; ?>
        </section><section class="panel dashboard-panel"><div class="panel-heading"><div><h2>Make your skills visible</h2><p>Profiles with evidence give employers more to evaluate.</p></div><span class="heading-icon">✳</span></div><div class="profile-progress"><div class="progress-track"><progress max="100" value="<?= $profileCompletion ?>" aria-label="Profile completion"></progress></div><strong><?= $profileCompletion ?>% complete</strong></div><a class="button button-outline button-full" href="index.php?page=profile">Add skills and project evidence →</a><?php if ($gapSkill): ?><div class="gap-callout"><span>↗</span><p>Adding <strong><?= e($gapSkill['name']) ?></strong> could help you match more of the <?= (int) $gapSkill['demand'] ?> open <?= (int) $gapSkill['demand'] === 1 ? 'role' : 'roles' ?> that need it.</p></div><?php endif; ?></section></div>
        <section class="panel dashboard-panel recent-applications"><div class="panel-heading"><div><h2>Recent applications</h2><p>See where things stand.</p></div><a href="index.php?page=applications">View all →</a></div><?php if ($applications): ?><div class="recent-list"><?php foreach (array_slice($applications, 0, 3) as $application): ?><div class="recent-row"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($application['company'], 0, 1))) ?></span><div><strong><?= e($application['title']) ?></strong><small><?= e($application['company']) ?></small></div><span class="<?= e(badge_class($application['status'])) ?>"><?= e(status_label($application['status'])) ?></span></div><?php endforeach; ?></div><?php else: ?><div class="inline-empty"><p>No applications yet. A good first step is to find a role that sounds right.</p><a href="index.php?page=opportunities">Explore openings →</a></div><?php endif; ?></section>
    <?php else: ?>
        <section class="welcome-banner employer-welcome"><div><span class="eyebrow">BETTER HIRING STARTS WITH BETTER EVIDENCE</span><h1>Welcome, <?= e(explode(' ', $user['name'])[0]) ?>.</h1><p>See what people can do, not only what their CV says.</p></div><a class="button button-primary" href="index.php?page=jobs#post-job">Post an opening <span aria-hidden="true">+</span></a></section>
        <div class="stats-grid"><article class="stat-card"><span class="stat-icon">▤</span><strong><?= $counts['open_jobs'] ?></strong><span>Open roles</span></article><article class="stat-card"><span class="stat-icon">♙</span><strong><?= $counts['candidates'] ?></strong><span>Total applicants</span></article><article class="stat-card"><span class="stat-icon">✓</span><strong><?= $counts['shortlisted'] ?></strong><span>Shortlisted candidates</span></article></div>
        <section class="panel dashboard-panel employer-openings"><div class="panel-heading"><div><h2>Your openings</h2><p>Review candidate evidence and move each application forward.</p></div><a href="index.php?page=jobs">Manage openings →</a></div>
        <?php if ($jobs): ?><div class="compact-job-list"><?php foreach (array_slice($jobs, 0, 4) as $job): ?><a class="compact-job" href="index.php?page=candidates&amp;job=<?= (int) $job['id'] ?>"><span class="company-mark"><?= e(mb_strtoupper(mb_substr($job['company'], 0, 1))) ?></span><span class="compact-job-copy"><strong><?= e($job['title']) ?></strong><small><?= e($job['location']) ?> · <?= e($job['skills'] ?: 'No skills specified') ?></small></span><span class="applicant-pill"><?= (int) $job['applicant_count'] ?> applicants</span><span class="<?= e(badge_class($job['status'])) ?>"><?= e(status_label($job['status'])) ?></span><span class="preview-arrow">→</span></a><?php endforeach; ?></div><?php else: ?><div class="inline-empty"><p>Your first role will give skills-first candidates a place to show what they can do.</p><a href="index.php?page=jobs#post-job">Post an opening →</a></div><?php endif; ?></section>
        <div class="privacy-note"><span>◈</span><p><strong>Fair, evidence-led shortlisting.</strong> Candidate scores use only required skills, work samples, and role challenges. Contact details remain private until you shortlist someone.</p></div>
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
