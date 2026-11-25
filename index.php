<?php
// Startup / Landing Page entry point
$pageTitle = "Start Your Journey | UMak Gym";
$pageDescription = "Join UMak Gym today. Accessible fitness, strength training, and expert coaching tailored for you.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <meta property="og:type" content="website">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="/PBL/css/icon-policy.css"></head>
<body class="landing-page-body">
    <header class="landing-header">
        <div class="landing-brand">
            <img src="uploads/umak-gym-logo.png" alt="UMak Gym" class="landing-brand-logo">
            <div class="landing-brand-mark">UMAK</div>
            <span>GYM</span>
        </div>
        <nav class="landing-nav">
            <a href="login.php" class="btn btn-primary">Member Login</a>
        </nav>
    </header>

    <main class="landing-main">
        <section class="hero-section">
            <div class="hero-copy">
                <p class="eyebrow">Build strength. Build discipline.</p>
                <h1>Start your journey to <span>strength</span></h1>
                <p class="lead">A community built for resilience, performance, and sustainable fitness for every stage of your life.</p>
                <div class="hero-actions">
                    <a href="user-register.php" class="btn btn-secondary">Join Now</a>
                    <a href="login.php" class="btn btn-ghost">View Dashboard</a>
                </div>
            </div>
            <div class="hero-panel">
                <div class="metric-card">
                    <strong>1.2K+</strong>
                    <span>Active members</span>
                </div>
                <div class="metric-card accent">
                    <strong>24/7</strong>
                    <span>Access support</span>
                </div>
                <div class="metric-card">
                    <strong>98%</strong>
                    <span>Goal retention</span>
                </div>
            </div>
        </section>

        <section class="info-section">
            <div class="section-heading">
                <p class="eyebrow">Why choose us</p>
                <h2>We believe that <span>strong is beautiful</span></h2>
            </div>
            <div class="feature-grid">
                <article class="feature-card">
                    <div class="icon">🏋️‍♂️</div>
                    <h3>Strength Training</h3>
                    <p>Top-tier equipment and guided support for smarter, stronger progress.</p>
                </article>
                <article class="feature-card">
                    <div class="icon">❤️</div>
                    <h3>Cardio + HIIT</h3>
                    <p>Boost endurance and conditioning with structured, high-impact training.</p>
                </article>
                <article class="feature-card">
                    <div class="icon">🤝</div>
                    <h3>Expert Coaching</h3>
                    <p>Coaches who help you train safely, confidently, and with purpose.</p>
                </article>
            </div>
        </section>

        <section class="pillars-section">
            <div class="section-heading center">
                <p class="eyebrow">Our pillars</p>
                <h2>Driven by results</h2>
            </div>
            <div class="pillar-list">
                <div class="pillar-item"><span>1</span><h3>Accessible Fitness</h3></div>
                <div class="pillar-item"><span>2</span><h3>Community Driven</h3></div>
                <div class="pillar-item"><span>3</span><h3>Results Oriented</h3></div>
            </div>
        </section>

        <section class="cta-section">
            <h2>Strength is a process.</h2>
            <p>Start building yours today.</p>
            <a href="login.php" class="btn btn-primary dark">Login to Dashboard</a>
        </section>
    </main>

    <footer class="landing-footer">
        <div>
            <h3>UMAK GYM</h3>
            <p>University of Makati • Taguig • Metro Manila</p>
        </div>
        <div class="social-links">
            <a href="#" aria-label="Facebook">Facebook</a>
            <a href="#" aria-label="Instagram">Instagram</a>
        </div>
    </footer>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>
