/* ============================================================
   BookWA — Main JavaScript
   ============================================================ */

// ─── Navbar scroll effect ─────────────────────────────────
const navbar = document.getElementById('navbar');
if (navbar) {
  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 20);
  }, { passive: true });
}

// ─── Mobile menu ─────────────────────────────────────────
const mobileToggle = document.getElementById('mobileToggle');
const navLinks = document.querySelector('.nav-links');
if (mobileToggle && navLinks) {
  mobileToggle.addEventListener('click', () => {
    navLinks.classList.toggle('mobile-open');
  });
}

// ─── Scroll-reveal animations ─────────────────────────────
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add('visible');
      observer.unobserve(e.target);
    }
  });
}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

// ─── Animated counter ─────────────────────────────────────
function animateCounter(el) {
  const target = parseInt(el.dataset.target || el.textContent.replace(/\D/g, ''), 10);
  const suffix = el.dataset.suffix || '';
  const prefix = el.dataset.prefix || '';
  const duration = 1800;
  const start = performance.now();

  function update(now) {
    const elapsed = now - start;
    const progress = Math.min(elapsed / duration, 1);
    const eased = 1 - Math.pow(1 - progress, 3);
    const current = Math.floor(eased * target);
    el.textContent = prefix + current.toLocaleString() + suffix;
    if (progress < 1) requestAnimationFrame(update);
  }
  requestAnimationFrame(update);
}

const counterObserver = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      animateCounter(e.target);
      counterObserver.unobserve(e.target);
    }
  });
}, { threshold: 0.5 });

document.querySelectorAll('[data-counter]').forEach(el => counterObserver.observe(el));

// ─── Pricing toggle ───────────────────────────────────────
const toggleOptions = document.querySelectorAll('.toggle-option');
const prices        = document.querySelectorAll('[data-monthly][data-yearly]');

toggleOptions.forEach(btn => {
  btn.addEventListener('click', () => {
    toggleOptions.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const billing = btn.dataset.billing;
    prices.forEach(el => {
      el.textContent = billing === 'monthly' ? el.dataset.monthly : el.dataset.yearly;
    });
  });
});

// ─── WhatsApp Chat Simulation ─────────────────────────────
const chatContainer = document.getElementById('waMessages');
const typingEl      = document.getElementById('waTyping');

const demoMessages = [
  { dir: 'in',  text: 'Hi! Welcome to City Clinic 👋\nHow can I help you today?',                                      delay: 600  },
  { dir: 'out', text: 'book appointment',                                                                               delay: 2200 },
  { dir: 'in',  text: 'Great! Please select a service category:\n\n1️⃣ General Consultation\n2️⃣ Dental Services\n3️⃣ Dermatology', delay: 3200 },
  { dir: 'out', text: '2',                                                                                               delay: 4800 },
  { dir: 'in',  text: 'Available Dental Services:\n\n• Dental Checkup — $30 (30 min)\n• Teeth Cleaning — $50 (45 min)\n• Whitening — $80 (60 min)', delay: 5800 },
  { dir: 'out', text: 'Teeth Cleaning',                                                                                 delay: 7400 },
  { dir: 'in',  text: 'Select a time slot for Mon, Jun 10:\n\n🕘 9:00 AM\n🕙 11:00 AM\n🕑 2:00 PM\n🕔 4:00 PM',           delay: 8400 },
  { dir: 'out', text: '11:00 AM',                                                                                       delay: 9800 },
  { dir: 'in',  text: '✅ Appointment Confirmed!\n\nService: Teeth Cleaning\nDate: Mon, Jun 10\nTime: 11:00 AM\nStaff: Dr. Sarah\n\nSee you soon! 😊', delay: 10800 },
];

function now() {
  const d = new Date();
  return d.getHours() + ':' + String(d.getMinutes()).padStart(2, '0');
}

function addMessage(dir, text) {
  const msg = document.createElement('div');
  msg.className = `wa-msg wa-msg-${dir}`;
  msg.innerHTML = text.replace(/\n/g, '<br>') + `<div class="wa-msg-time">${now()}</div>`;
  chatContainer.appendChild(msg);
  chatContainer.scrollTop = chatContainer.scrollHeight;
}

function showTyping() {
  if (typingEl) typingEl.classList.add('show');
}

function hideTyping() {
  if (typingEl) typingEl.classList.remove('show');
}

let chatStarted = false;

function startChat() {
  if (chatStarted || !chatContainer) return;
  chatStarted = true;

  demoMessages.forEach((m, i) => {
    if (m.dir === 'in' && i > 0) {
      setTimeout(showTyping, m.delay - 600);
    }
    setTimeout(() => {
      hideTyping();
      addMessage(m.dir, m.text);
    }, m.delay);
  });

  // Restart after last message
  const lastDelay = demoMessages[demoMessages.length - 1].delay;
  setTimeout(() => {
    chatStarted = false;
    chatContainer.innerHTML = '';
    startChat();
  }, lastDelay + 5000);
}

// Start chat when phone mockup enters viewport
const phoneEl = document.querySelector('.phone-wrap');
if (phoneEl) {
  const phoneObserver = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting) {
      startChat();
      phoneObserver.unobserve(phoneEl);
    }
  }, { threshold: 0.3 });
  phoneObserver.observe(phoneEl);
}

// ─── Password visibility toggle ───────────────────────────
document.querySelectorAll('.toggle-password').forEach(btn => {
  btn.addEventListener('click', () => {
    const input = btn.closest('.input-group').querySelector('input');
    const isText = input.type === 'text';
    input.type = isText ? 'password' : 'text';
    btn.textContent = isText ? '👁' : '🙈';
  });
});

// ─── Signup form: real-time validation ───────────────────
const signupForm = document.getElementById('signupForm');
if (signupForm) {
  const password  = signupForm.querySelector('[name="password"]');
  const confirm   = signupForm.querySelector('[name="password_confirm"]');
  const strength  = document.getElementById('passwordStrength');

  if (password && strength) {
    password.addEventListener('input', () => {
      const v = password.value;
      let score = 0;
      if (v.length >= 8)       score++;
      if (/[A-Z]/.test(v))     score++;
      if (/[0-9]/.test(v))     score++;
      if (/[^A-Za-z0-9]/.test(v)) score++;
      const levels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
      const colors = ['', '#ef4444', '#f59e0b', '#3b82f6', '#10b981'];
      strength.textContent = v ? levels[score] : '';
      strength.style.color = colors[score];
    });
  }

  if (confirm && password) {
    confirm.addEventListener('input', () => {
      const err = confirm.parentElement.querySelector('.form-error');
      if (err) err.style.display = confirm.value && confirm.value !== password.value ? 'flex' : 'none';
    });
  }

  // Prevent submit if passwords don't match
  signupForm.addEventListener('submit', e => {
    if (password && confirm && password.value !== confirm.value) {
      e.preventDefault();
      confirm.focus();
    }
  });
}

// ─── Modals ────────────────────────────────────────────────
document.querySelectorAll('[data-modal-open]').forEach(btn => {
  btn.addEventListener('click', () => {
    const modal = document.getElementById(btn.dataset.modalOpen);
    if (modal) modal.classList.add('open');
  });
});

document.querySelectorAll('[data-modal-close]').forEach(btn => {
  btn.addEventListener('click', () => {
    const overlay = btn.closest('.modal-overlay');
    if (overlay) overlay.classList.remove('open');
  });
});

document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) overlay.classList.remove('open');
  });
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(o => o.classList.remove('open'));
  }
});

// ─── Tabs ──────────────────────────────────────────────────
document.querySelectorAll('.tabs').forEach(tabGroup => {
  const tabs = tabGroup.querySelectorAll('.tab');
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.tab;
      const panelGroupSelector = tabGroup.dataset.panelGroup;
      const panelGroup = panelGroupSelector ? document.querySelector(panelGroupSelector) : document;

      tabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');

      panelGroup.querySelectorAll('.tab-panel').forEach(p => {
        p.classList.toggle('active', p.dataset.panel === target);
      });
    });
  });
});

// ─── Color / Icon pickers ──────────────────────────────────
document.querySelectorAll('.color-options, .icon-options').forEach(group => {
  const input = document.getElementById(group.dataset.input);
  group.querySelectorAll('.color-option, .icon-option').forEach(opt => {
    opt.addEventListener('click', () => {
      group.querySelectorAll('.selected').forEach(o => o.classList.remove('selected'));
      opt.classList.add('selected');
      if (input) input.value = opt.dataset.value;
    });
  });
});

// ─── Sidebar mobile toggle ─────────────────────────────────
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarEl = document.getElementById('sidebar');
if (sidebarToggle && sidebarEl) {
  sidebarToggle.addEventListener('click', () => sidebarEl.classList.toggle('open'));
}

// ─── Auto-dismiss alerts ──────────────────────────────────
document.querySelectorAll('.alert').forEach(alert => {
  setTimeout(() => {
    alert.style.transition = 'opacity .5s ease';
    alert.style.opacity = '0';
    setTimeout(() => alert.remove(), 500);
  }, 5000);
});

// ─── Smooth anchor scrolling ──────────────────────────────
document.querySelectorAll('a[href^="#"]').forEach(link => {
  link.addEventListener('click', e => {
    const target = document.querySelector(link.getAttribute('href'));
    if (!target) return;
    e.preventDefault();
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});
