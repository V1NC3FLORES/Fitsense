<?php
/**
 * brand.php
 * Reusable FitSense brand component
 * 
 * Usage:
 *   Sidebar:  <?php include 'brand.php'; renderBrand('sidebar'); ?>
 *   Header:   <?php include 'brand.php'; renderBrand('header'); ?>
 *   Auth:     <?php include 'brand.php'; renderBrand('auth'); ?>
 *   Footer:   <?php include 'brand.php'; renderBrand('footer'); ?>
 */

if (!function_exists('renderBrand')) {

    function renderBrand($variant = 'sidebar', $options = []) {
        $logo = $options['logo'] ?? 'uploads/umak-gym-logo.png';
        $href = $options['href'] ?? 'user-dashboard.php';
        $showSubtitle = $options['showSubtitle'] ?? true;
        $subtitle = $options['subtitle'] ?? 'UMak Gym';
        
        // Fallback if logo doesn't exist
        $logoExists = file_exists(__DIR__ . '/' . $logo);
        $logoSrc = $logoExists ? htmlspecialchars($logo) : '';
        
        switch ($variant) {

            // ============================================
            // SIDEBAR VARIANT (inside dark sidebar)
            // ============================================
            case 'sidebar':
                ?>
                <a href="<?= htmlspecialchars($href) ?>" class="sidebar-brand-link">
                    <div class="sidebar-brand-logo">
                        <?php if ($logoSrc): ?>
                            <img src="<?= $logoSrc ?>" alt="FitSense Logo">
                        <?php else: ?>
                            <div class="logo-fallback">FS</div>
                        <?php endif; ?>
                    </div>
                    <div class="sidebar-brand-text">
                        <h3>FITSENSE</h3>
                        <?php if ($showSubtitle): ?>
                            <p><?= htmlspecialchars($subtitle) ?></p>
                        <?php endif; ?>
                    </div>
                </a>
                <?php
                break;

            // ============================================
            // HEADER VARIANT (inside white top-header)
            // ============================================
            case 'header':
                ?>
                <a href="<?= htmlspecialchars($href) ?>" class="header-brand-link">
                    <?php if ($logoSrc): ?>
                        <img src="<?= $logoSrc ?>" alt="FitSense Logo" class="header-brand-logo">
                    <?php else: ?>
                        <div class="header-logo-fallback">FS</div>
                    <?php endif; ?>
                    <div class="header-brand-text">
                        <h3>FITSENSE</h3>
                        <span><?= htmlspecialchars($subtitle) ?></span>
                    </div>
                </a>
                <?php
                break;

            // ============================================
            // AUTH VARIANT (login/register pages - yellow header)
            // ============================================
            case 'auth':
                ?>
                <a href="<?= htmlspecialchars($href) ?>" class="auth-brand">
                    <?php if ($logoSrc): ?>
                        <img src="<?= $logoSrc ?>" alt="FitSense Logo">
                    <?php else: ?>
                        <div class="auth-logo-fallback">FS</div>
                    <?php endif; ?>
                    <div>
                        <div class="auth-brand-title">FitSense</div>
                        <div class="auth-brand-sub">UMak Gym System</div>
                    </div>
                </a>
                <?php
                break;

            // ============================================
            // AUTH-CENTERED VARIANT (large centered logo on login/register card)
            // ============================================
            case 'auth-center':
                ?>
                <div class="auth-center-brand">
                    <?php if ($logoSrc): ?>
                        <img src="<?= $logoSrc ?>" alt="FitSense Logo">
                    <?php else: ?>
                        <div class="auth-center-fallback">FS</div>
                    <?php endif; ?>
                    <div>
                        <div class="brand-title">FitSense</div>
                        <div class="brand-sub">UMAK GYM</div>
                    </div>
                </div>
                <?php
                break;

            // ============================================
            // FOOTER VARIANT (dark footer)
            // ============================================
            case 'footer':
                ?>
                <div class="footer-brand">
                    <?php if ($logoSrc): ?>
                        <img src="<?= $logoSrc ?>" alt="FitSense Logo">
                    <?php else: ?>
                        <div class="footer-logo-fallback">FS</div>
                    <?php endif; ?>
                    <div>
                        <h4>FITSENSE</h4>
                        <p>UMak Gym System</p>
                    </div>
                </div>
                <?php
                break;

            // ============================================
            // FAVICON VARIANT
            // ============================================
            case 'favicon':
                if ($logoSrc) {
                    echo '<link rel="icon" type="image/png" href="' . $logoSrc . '">';
                    echo '<link rel="apple-touch-icon" href="' . $logoSrc . '">';
                }
                break;
        }
    }
}

/**
 * Output the CSS needed for the brand component
 * Include this once per page in the <style> tag
 */
function renderBrandCSS() {
    ?>
    <style>
        /* ============================================
           SIDEBAR BRAND (dark sidebar)
           ============================================ */
        .sidebar-brand-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: white;
        }

        .sidebar-brand-logo {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            background: rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .sidebar-brand-logo .logo-fallback {
            font-weight: 900;
            color: #ffce00;
            font-size: 18px;
        }

        .sidebar-brand-text h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 1.5px;
            color: white;
        }

        .sidebar-brand-text p {
            margin: 2px 0 0;
            font-size: 10px;
            opacity: 0.65;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: white;
        }

        /* ============================================
           HEADER BRAND (white header)
           ============================================ */
        .header-brand-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .header-brand-logo {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
        }

        .header-logo-fallback {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 15px;
            flex-shrink: 0;
        }

        .header-brand-text h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: #005b9f;
            letter-spacing: 1.2px;
            line-height: 1;
        }

        .header-brand-text span {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        /* ============================================
           AUTH BRAND (yellow header on login/register)
           ============================================ */
        .auth-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .auth-brand img {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #005b9f;
            background: #fff;
        }

        .auth-logo-fallback {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #005b9f;
            color: #ffce00;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 16px;
            border: 2px solid #005b9f;
        }

        .auth-brand-title {
            color: #000;
            font-size: 21px;
            font-weight: 900;
            line-height: 1;
        }

        .auth-brand-sub {
            color: #333;
            font-size: 10px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-top: 4px;
        }

        /* ============================================
           AUTH-CENTERED BRAND (large card logo)
           ============================================ */
        .auth-center-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin-bottom: 25px;
        }

        .auth-center-brand img {
            width: 72px;
            height: 72px;
            object-fit: contain;
            border-radius: 50%;
            background: white;
            padding: 4px;
            border: 2px solid #ffce00;
        }

        .auth-center-fallback {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #005b9f;
            color: #ffce00;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 26px;
            border: 2px solid #ffce00;
        }

        .auth-center-brand .brand-title {
            font-size: 30px;
            font-weight: 900;
            color: #005b9f;
            line-height: 1;
        }

        .auth-center-brand .brand-sub {
            font-size: 12px;
            color: #666;
            letter-spacing: 2px;
            margin-top: 6px;
        }

        /* ============================================
           FOOTER BRAND (dark footer)
           ============================================ */
        .footer-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
        }

        .footer-brand img {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            object-fit: cover;
            background: rgba(255,255,255,0.05);
            padding: 4px;
        }

        .footer-logo-fallback {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: #005b9f;
            color: #ffce00;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 18px;
        }

        .footer-brand h4 {
            margin: 0;
            color: #ffce00;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 1.5px;
        }

        .footer-brand p {
            margin: 4px 0 0;
            color: #aaa;
            font-size: 11px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        /* Mobile tweaks */
        @media (max-width: 768px) {
            .auth-center-brand {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .auth-center-brand img {
                width: 64px;
                height: 64px;
            }
            .auth-center-brand .brand-title {
                font-size: 26px;
            }
        }
    </style>
    <?php
}
?>