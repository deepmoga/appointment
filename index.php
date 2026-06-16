<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Redirect to dashboard if already logged in
if (isLoggedIn()) {
  header('Location: ' . APP_URL . '/dashboard/index.php');
  exit;
}

// Check if DB is installed; show install notice if not
$dbReady = dbConnected();

$platformSettings = $dbReady ? getPlatformSettings() : ['currency_symbol' => '$', 'contact_phone' => '', 'contact_email' => '', 'demo_whatsapp' => ''];
$plans            = $dbReady ? getActivePlans() : [];
$currencySymbol   = $platformSettings['currency_symbol'];
$demoWaDigits     = preg_replace('/\D/', '', $platformSettings['demo_whatsapp']);
$demoWaLink       = $demoWaDigits !== '' ? 'https://wa.me/' . $demoWaDigits . '?text=' . rawurlencode('Hi') : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>BookWA — WhatsApp Appointment Booking Platform</title>
  <meta name="description" content="Automate appointment bookings for your clinic, salon, or business via WhatsApp. Let customers book 24/7 through a smart chatbot — no app, no friction.">
  <meta name="theme-color" content="#0c0e1a">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

  <!-- ════════════════════════════════════════════════════════
     INSTALL NOTICE
     ════════════════════════════════════════════════════════ -->
  <?php if (!$dbReady): ?>
    <div style="background:#fef3c7;border-bottom:2px solid #f59e0b;padding:12px 24px;text-align:center;font-size:.875rem;font-weight:600;color:#92400e;">
      ⚠ Database not connected. <a href="install.php" style="color:#b45309;text-decoration:underline;">Run the installer →</a>
    </div>
  <?php endif; ?>

  <!-- ════════════════════════════════════════════════════════
     NAVBAR
     ════════════════════════════════════════════════════════ -->
  <nav class="navbar" id="navbar">
    <div class="nav-container">
      <a href="index.php" class="nav-logo">
        <span class="nav-logo-icon">💬</span>
        BookWA
      </a>
      <ul class="nav-links">
        <li><a href="#features">Features</a></li>
        <li><a href="#how-it-works">How It Works</a></li>
        <li><a href="#pricing">Pricing</a></li>
        <li><a href="#testimonials">Testimonials</a></li>
      </ul>
      <div class="nav-cta">
        <a href="auth/login.php" class="btn btn-outline-white btn-sm">Sign In</a>
        <a href="auth/signup.php" class="btn btn-wa btn-sm">Get Started Free</a>
      </div>
      <button class="nav-mobile-toggle" id="mobileToggle" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </nav>

  <!-- ════════════════════════════════════════════════════════
     HERO
     ════════════════════════════════════════════════════════ -->
  <section class="hero" id="home">
    <div class="hero-inner">

      <!-- Left: Copy -->
      <div class="hero-copy">
        <div class="hero-badge">
          <span class="badge-pulse"></span>
          Trusted by 500+ businesses worldwide
        </div>
        <h1 class="hero-title">
          Automate Bookings<br>
          via <span class="gradient-text">WhatsApp</span>
        </h1>
        <p class="hero-sub">
          Connect your WhatsApp Business account and let customers book appointments 24/7 through an intelligent chatbot. Reduce no-shows, eliminate phone tag, and grow your business.
        </p>
        <div class="hero-actions">
          <a href="auth/signup.php" class="btn btn-wa btn-lg">
            🚀 Start Free Trial
          </a>
          <a href="#how-it-works" class="btn btn-outline-white btn-lg">
            ▶ See How It Works
          </a>
        </div>
        <div class="hero-trust">
          <span class="hero-trust-item"><span class="check">✓</span>No credit card required</span>
          <span class="hero-trust-item"><span class="check">✓</span>14-day free trial</span>
          <span class="hero-trust-item"><span class="check">✓</span>Cancel anytime</span>
        </div>
      </div>

      <!-- Right: Phone mockup -->
      <div class="hero-visual">
        <div class="phone-wrap">
          <div class="phone-glow"></div>

          <!-- Floating badges -->
          <div class="hero-float hero-float-1">
            <div class="hero-float-icon green">✅</div>
            <div>
              <div class="hero-float-label">New Booking</div>
              <div class="hero-float-value">Just confirmed!</div>
            </div>
          </div>
          <div class="hero-float hero-float-2">
            <div class="hero-float-icon blue">📅</div>
            <div>
              <div class="hero-float-label">Today's Appointments</div>
              <div class="hero-float-value">12 bookings</div>
            </div>
          </div>

          <!-- Phone -->
          <div class="phone-frame">
            <div class="phone-notch"></div>
            <div class="phone-screen">
              <!-- WhatsApp-style header -->
              <div class="wa-topbar">
                <div class="wa-av">CC</div>
                <div>
                  <div class="wa-info-name">City Clinic</div>
                  <div class="wa-info-status">online</div>
                </div>
              </div>
              <!-- Messages -->
              <div class="wa-msgs" id="waMessages">
                <!-- injected by JS -->
              </div>
              <!-- Typing indicator -->
              <div class="wa-typing" id="waTyping">
                <span></span><span></span><span></span>
              </div>
              <!-- Input bar -->
              <div class="wa-inputbar">
                <div class="wa-inputbar-inner">Type a message…</div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════
     STATS BAR
     ════════════════════════════════════════════════════════ -->
  <section class="stats-bar">
    <div class="container">
      <div class="stats-grid">
        <div class="stat-item">
          <div class="stat-num"><span data-counter data-target="500" data-suffix="+">500+</span></div>
          <div class="stat-label">Businesses using BookWA</div>
        </div>
        <div class="stat-item stat-divider">
          <div class="stat-num"><span data-counter data-target="50000" data-suffix="+">50K+</span></div>
          <div class="stat-label">Appointments booked</div>
        </div>
        <div class="stat-item stat-divider">
          <div class="stat-num"><span data-counter data-target="98" data-suffix="%">98%</span></div>
          <div class="stat-label">Customer satisfaction rate</div>
        </div>
        <div class="stat-item stat-divider">
          <div class="stat-num"><span data-counter data-target="40" data-suffix="%">40%</span></div>
          <div class="stat-label">Reduction in no-shows</div>
        </div>
      </div>
    </div>
  </section>


  <!-- ════════════════════════════════════════════════════════
     DEMO & CONTACT CTA
     ════════════════════════════════════════════════════════ -->
  <section class="section demo-cta" id="demo">
    <div class="container">
      <div class="demo-cta-grid">

        <div class="demo-cta-info">
          <span class="section-label">📱 Live Demo</span>
          <h2 class="section-title fade-in" style="text-align:left;">Try the WhatsApp booking bot right now</h2>
          <p class="section-sub fade-in fade-in-delay-1" style="text-align:left;margin:0 0 28px;max-width:480px;">
            Scan the QR code or tap the button below to open WhatsApp and chat with our live demo bot — see exactly what your customers will experience.
          </p>
          <?php if ($demoWaLink !== ''): ?>
            <a href="<?= htmlspecialchars($demoWaLink) ?>" class="btn btn-wa btn-lg" target="_blank" rel="noopener">
              💬 Chat on WhatsApp: <?= htmlspecialchars($platformSettings['demo_whatsapp']) ?>
            </a>
          <?php endif; ?>

          <?php if (!empty($platformSettings['contact_phone']) || !empty($platformSettings['contact_email'])): ?>
            <div class="contact-pills">
              <?php if (!empty($platformSettings['contact_phone'])): ?>
                <a href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', $platformSettings['contact_phone'])) ?>" class="contact-pill">
                  📞 <?= htmlspecialchars($platformSettings['contact_phone']) ?>
                </a>
              <?php endif; ?>
              <?php if (!empty($platformSettings['contact_email'])): ?>
                <a href="mailto:<?= htmlspecialchars($platformSettings['contact_email']) ?>" class="contact-pill">
                  ✉️ <?= htmlspecialchars($platformSettings['contact_email']) ?>
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($demoWaLink !== ''): ?>
          <div class="demo-cta-qr fade-in fade-in-delay-2">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=<?= rawurlencode($demoWaLink) ?>" alt="Scan to chat with the BookWA demo on WhatsApp" width="240" height="240" loading="lazy">
            <div class="demo-cta-qr-label">📷 Scan to view demo</div>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════
     FEATURES
     ════════════════════════════════════════════════════════ -->
  <section class="section section-alt" id="features">
    <div class="container">
      <div class="section-header">
        <span class="section-label">✨ Features</span>
        <h2 class="section-title fade-in">Everything your business needs</h2>
        <p class="section-sub fade-in fade-in-delay-1">One platform to manage appointments, staff, services, and customer communication — all through WhatsApp.</p>
      </div>

      <div class="features-grid">

        <div class="feature-card fade-in">
          <div class="feature-icon green">💬</div>
          <div class="feature-title">WhatsApp Booking Bot</div>
          <div class="feature-desc">Customers send a simple message and your intelligent bot guides them through category selection, service, time slot, and confirmation — 24/7.</div>
        </div>

        <div class="feature-card fade-in fade-in-delay-1">
          <div class="feature-icon indigo">📋</div>
          <div class="feature-title">Service Catalog Management</div>
          <div class="feature-desc">Organize services into categories with names, descriptions, durations, and prices. Enable or disable services in one click.</div>
        </div>

        <div class="feature-card fade-in fade-in-delay-2">
          <div class="feature-icon blue">👥</div>
          <div class="feature-title">Staff Management</div>
          <div class="feature-desc">Add multiple staff members, assign services to specific staff, set individual schedules, and manage leave — no double-bookings.</div>
        </div>

        <div class="feature-card fade-in fade-in-delay-1">
          <div class="feature-icon purple">📅</div>
          <div class="feature-title">Smart Scheduling</div>
          <div class="feature-desc">Configure working hours, break times, slot intervals, and blackout dates. The bot only shows genuinely available slots.</div>
        </div>

        <div class="feature-card fade-in fade-in-delay-2">
          <div class="feature-icon orange">🔔</div>
          <div class="feature-title">Automated Reminders</div>
          <div class="feature-desc">Automatic WhatsApp reminders 24 hours and 1 hour before appointments dramatically reduce no-shows and last-minute cancellations.</div>
        </div>

        <div class="feature-card fade-in fade-in-delay-3">
          <div class="feature-icon teal">📊</div>
          <div class="feature-title">Analytics & Reports</div>
          <div class="feature-desc">Track revenue, booking trends, staff utilization, popular services, and customer retention — all in a beautiful dashboard.</div>
        </div>

        <div class="feature-card fade-in">
          <div class="feature-icon yellow">💰</div>
          <div class="feature-title">Online Payments</div>
          <div class="feature-desc">Collect deposits or full payment via payment links sent through WhatsApp — integrates with Stripe and PayPal. <em style="color:var(--primary)">Coming soon</em></div>
        </div>

        <div class="feature-card fade-in fade-in-delay-1">
          <div class="feature-icon green">📅</div>
          <div class="feature-title">Google Calendar Sync</div>
          <div class="feature-desc">Two-way sync keeps your team's calendars updated automatically. No more manual entries or missed appointments. <em style="color:var(--primary)">Coming soon</em></div>
        </div>

        <div class="feature-card fade-in fade-in-delay-2">
          <div class="feature-icon blue">🌍</div>
          <div class="feature-title">Multi-language Support</div>
          <div class="feature-desc">Serve customers in Arabic, Spanish, French, Hindi, and more. Customize your bot's language per customer preference. <em style="color:var(--primary)">Coming soon</em></div>
        </div>

      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════
     HOW IT WORKS
     ════════════════════════════════════════════════════════ -->
  <section class="section" id="how-it-works">
    <div class="container">
      <div class="section-header">
        <span class="section-label">🚀 Setup</span>
        <h2 class="section-title fade-in">Up and running in 3 steps</h2>
        <p class="section-sub fade-in fade-in-delay-1">No technical knowledge required. If you can use WhatsApp, you can use BookWA.</p>
      </div>

      <div class="steps-grid">
        <div class="step-card fade-in">
          <div class="step-num">1</div>
          <h3 class="step-title">Create Your Account</h3>
          <p class="step-desc">Sign up in 60 seconds. Add your business name, type, and basic details. Your personalized dashboard is ready immediately.</p>
        </div>
        <div class="step-card fade-in fade-in-delay-2">
          <div class="step-num">2</div>
          <h3 class="step-title">Connect WhatsApp & Add Services</h3>
          <p class="step-desc">Link your WhatsApp Business API account, add your service categories, set prices, durations, staff, and availability windows.</p>
        </div>
        <div class="step-card fade-in fade-in-delay-4">
          <div class="step-num">3</div>
          <h3 class="step-title">Share Your Number</h3>
          <p class="step-desc">Share your WhatsApp number with customers. They message "Hi" or "Book" and the bot handles the rest — confirmations, reminders, everything.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════
     BUSINESS TYPES
     ════════════════════════════════════════════════════════ -->
  <section class="section section-dark">
    <div class="container">
      <div class="section-header">
        <span class="section-label" style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.8);">🏢 Use Cases</span>
        <h2 class="section-title fade-in">Built for every appointment-based business</h2>
        <p class="section-sub fade-in fade-in-delay-1">Any business that takes bookings can automate them with BookWA.</p>
      </div>
      <div class="biz-grid">
        <div class="biz-card fade-in">
          <div class="biz-emoji">🏥</div>
          <div class="biz-name">Clinics</div>
        </div>
        <div class="biz-card fade-in fade-in-delay-1">
          <div class="biz-emoji">🦷</div>
          <div class="biz-name">Dental</div>
        </div>
        <div class="biz-card fade-in fade-in-delay-2">
          <div class="biz-emoji">💇</div>
          <div class="biz-name">Salons</div>
        </div>
        <div class="biz-card fade-in fade-in-delay-1">
          <div class="biz-emoji">💆</div>
          <div class="biz-name">Spas</div>
        </div>
        <div class="biz-card fade-in fade-in-delay-2">
          <div class="biz-emoji">🏋️</div>
          <div class="biz-name">Gyms</div>
        </div>
        <div class="biz-card fade-in fade-in-delay-3">
          <div class="biz-emoji">⚖️</div>
          <div class="biz-name">Legal</div>
        </div>
      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════
     PRICING
     ════════════════════════════════════════════════════════ -->
  <section class="section section-alt" id="pricing">
    <div class="container">
      <div class="section-header">
        <span class="section-label">💳 Pricing</span>
        <h2 class="section-title fade-in">Simple, transparent pricing</h2>
        <p class="section-sub fade-in fade-in-delay-1">Start free. Upgrade when you need more. No hidden fees, ever.</p>

        <div class="pricing-toggle" style="margin-top:28px;">
          <span class="toggle-option active" data-billing="monthly">Monthly</span>
          <span class="toggle-option" data-billing="yearly">Yearly <span class="toggle-save">Save 17%</span></span>
        </div>
      </div>

      <div class="pricing-grid">

        <?php if (empty($plans)): ?>
          <p style="text-align:center;color:var(--gray-400);grid-column:1/-1;">Pricing plans are being updated. Please check back soon.</p>
        <?php else: ?>
          <?php
          $planCount   = count($plans);
          $featuredIdx = $planCount >= 2 ? intdiv($planCount, 2) : -1;
          ?>
          <?php foreach ($plans as $i => $plan): ?>
            <?php
            $features    = json_decode($plan['features'] ?? '[]', true) ?: [];
            $isFeatured  = $i === $featuredIdx;
            $monthlyDisp = (float)$plan['price_monthly'] == 0 ? 'Free' : round($plan['price_monthly']);
            $yearlyDisp  = (float)$plan['price_yearly']  == 0 ? 'Free' : round($plan['price_yearly'] / 12);
            $delayClass  = ['', ' fade-in-delay-1', ' fade-in-delay-2', ' fade-in-delay-3'][$i % 4];
            ?>
            <div class="pricing-card<?= $isFeatured ? ' featured' : '' ?> fade-in<?= $delayClass ?>">
              <?php if ($isFeatured): ?>
                <div class="pricing-popular">Most Popular</div>
              <?php endif; ?>
              <div class="pricing-plan"><?= htmlspecialchars($plan['name']) ?></div>
              <div class="pricing-price">
                <?php if ($monthlyDisp === 'Free'): ?>
                  <div class="pricing-amount">Free</div>
                <?php else: ?>
                  <div class="pricing-amount"><?= htmlspecialchars($currencySymbol) ?><span data-monthly="<?= $monthlyDisp ?>" data-yearly="<?= $yearlyDisp ?>"><?= $monthlyDisp ?></span></div>
                  <div class="pricing-per">/month</div>
                <?php endif; ?>
              </div>
              <div class="pricing-features">
                <?php foreach ($features as $feature): ?>
                  <div class="pricing-feature"><span class="check-icon">✓</span><?= htmlspecialchars($feature) ?></div>
                <?php endforeach; ?>
                <?php if ($plan['max_staff'] != 0): ?>
                  <div class="pricing-feature"><span class="check-icon">✓</span><?= $plan['max_staff'] == -1 ? 'Unlimited' : $plan['max_staff'] ?> Staff Member<?= $plan['max_staff'] == 1 ? '' : 's' ?></div>
                <?php endif; ?>
                <div class="pricing-feature"><span class="check-icon">✓</span><?= $plan['max_appointments_per_month'] == -1 ? 'Unlimited' : number_format($plan['max_appointments_per_month']) ?> Appointments / month</div>
              </div>
              <a href="auth/signup.php" class="btn <?= $isFeatured ? 'btn-primary' : 'btn-outline' ?> btn-full"><?= $isFeatured ? 'Start Free Trial' : 'Get Started Free' ?></a>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════
     TESTIMONIALS
     ════════════════════════════════════════════════════════ -->
  <section class="section" id="testimonials">
    <div class="container">
      <div class="section-header">
        <span class="section-label">⭐ Reviews</span>
        <h2 class="section-title fade-in">Loved by businesses everywhere</h2>
        <p class="section-sub fade-in fade-in-delay-1">See what our customers say after switching to WhatsApp-powered bookings.</p>
      </div>
      <div class="testimonials-grid">

        <div class="testimonial-card fade-in">
          <div class="testimonial-stars">★★★★★</div>
          <p class="testimonial-text">"Our no-show rate dropped by 45% in the first month. Patients love booking on WhatsApp — it's what they already use. BookWA was the best decision we made this year."</p>
          <div class="testimonial-author">
            <div class="testimonial-av" style="background:#6366f1;">DR</div>
            <div>
              <div class="testimonial-name">Dr. Rania Hussain</div>
              <div class="testimonial-role">Owner, Bright Smile Dental Clinic</div>
            </div>
          </div>
        </div>

        <div class="testimonial-card fade-in fade-in-delay-1">
          <div class="testimonial-stars">★★★★★</div>
          <p class="testimonial-text">"I was spending 2 hours a day on the phone managing bookings. Now the bot handles everything automatically. My clients get instant confirmation and I get my time back."</p>
          <div class="testimonial-author">
            <div class="testimonial-av" style="background:#25D366;">SA</div>
            <div>
              <div class="testimonial-name">Sara Al-Ahmed</div>
              <div class="testimonial-role">Owner, Glow Beauty Salon</div>
            </div>
          </div>
        </div>

        <div class="testimonial-card fade-in fade-in-delay-2">
          <div class="testimonial-stars">★★★★★</div>
          <p class="testimonial-text">"BookWA helped us scale from 1 location to 3 without hiring extra reception staff. The staff scheduling and multi-service management is incredibly well thought out."</p>
          <div class="testimonial-author">
            <div class="testimonial-av" style="background:#7c3aed;">MK</div>
            <div>
              <div class="testimonial-name">Mohammed Khan</div>
              <div class="testimonial-role">Manager, FitZone Gym Chain</div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- ════════════════════════════════════════════════════════
     CTA
     ════════════════════════════════════════════════════════ -->
  <section class="cta-section">
    <div class="container">
      <h2 class="cta-title fade-in">Ready to automate your bookings?</h2>
      <p class="cta-sub fade-in fade-in-delay-1">Join 500+ businesses saving hours every week with BookWA. Your first 14 days are completely free.</p>
      <div class="cta-actions fade-in fade-in-delay-2">
        <a href="auth/signup.php" class="btn btn-wa btn-lg">🚀 Start Free Trial</a>
        <a href="#features" class="btn btn-outline-white btn-lg">Learn More</a>
      </div>
      <p class="cta-note">No credit card required · Setup in minutes · Cancel anytime</p>
    </div>
  </section>


  <!-- ════════════════════════════════════════════════════════
     FOOTER
     ════════════════════════════════════════════════════════ -->
  <footer class="footer">
    <div class="container">
      <div class="footer-grid">
        <div>
          <div class="footer-brand-logo">
            <span style="width:32px;height:32px;background:var(--wa);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">💬</span>
            BookWA
          </div>
          <p class="footer-brand-desc">The smartest way to manage appointments for modern businesses. Powered by WhatsApp, built for growth.</p>
          <div class="footer-wa-badge">
            <span>💬</span> Powered by WhatsApp Business API
          </div>
        </div>
        <div>
          <div class="footer-col-title">Product</div>
          <ul class="footer-links">
            <li><a href="#features">Features</a></li>
            <li><a href="#pricing">Pricing</a></li>
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="auth/signup.php">Get Started</a></li>
          </ul>
        </div>
        <div>
          <div class="footer-col-title">Business</div>
          <ul class="footer-links">
            <li><a href="#">For Clinics</a></li>
            <li><a href="#">For Salons</a></li>
            <li><a href="#">For Gyms</a></li>
            <li><a href="#">For Spas</a></li>
          </ul>
        </div>
        <div>
          <div class="footer-col-title">Company</div>
          <ul class="footer-links">
            <li><a href="#">About Us</a></li>
            <li><a href="#">Contact</a></li>
            <li><a href="#">Privacy Policy</a></li>
            <li><a href="#">Terms of Service</a></li>
          </ul>
        </div>
      </div>
      <div class="footer-bottom">
        <div class="footer-legal">
          © <?= date('Y') ?> BookWA. All rights reserved.
        </div>
        <div class="footer-legal">
          Made with ❤️ for appointment-based businesses
        </div>
      </div>
    </div>
  </footer>

  <script src="assets/js/main.js"></script>
</body>

</html>