/* BlockTicker v6.1 — Navbar scroll + Scroll Reveal Animations */
(function(){
  'use strict';

  // ── NAVBAR SCROLL EFFECT ──
  var navbar = document.getElementById('cp-navbar');
  if (navbar) {
    var lastScroll = 0;
    window.addEventListener('scroll', function() {
      var st = window.pageYOffset || document.documentElement.scrollTop;
      if (st > 50) {
        navbar.classList.add('scrolled');
      } else {
        navbar.classList.remove('scrolled');
      }
      lastScroll = st;
    }, { passive: true });
  }

  // ── CLOSE MOBILE MENU ON LINK CLICK ──
  document.querySelectorAll('.cp-mobile-link').forEach(function(link) {
    link.addEventListener('click', function() {
      var menu = document.getElementById('cp-mobile-menu');
      var burger = document.getElementById('cp-hamburger');
      if (menu) menu.classList.remove('open');
      if (burger) burger.classList.remove('open');
    });
  });

  // ── SCROLL REVEAL (IntersectionObserver) ──
  // Elements start hidden via CSS (opacity:0, translateY:30px)
  // On scroll into view, the CSS animation plays
  if ('IntersectionObserver' in window) {
    var revealElements = document.querySelectorAll(
      '.fxlm-section-header, .fxlm-news-item, .fxlm-price-card, ' +
      '.fxlm-broker-card, .fxlm-top-pick, .fxlm-learn-card, ' +
      '.fxlm-table-wrap, .fxlm-chart-wrap, .fxlm-fng-widget, ' +
      '.fxlm-converter, .fxlm-newsletter, .fxlm-cta-strip, ' +
      '.fxlm-signal-item'
    );

    // Pause animation until in view
    revealElements.forEach(function(el) {
      el.style.animationPlayState = 'paused';
    });

    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          entry.target.style.animationPlayState = 'running';
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

    revealElements.forEach(function(el) {
      observer.observe(el);
    });
  }

  // ── SMOOTH SCROLL for internal links ──
  document.querySelectorAll('a[href^="#"]').forEach(function(link) {
    link.addEventListener('click', function(e) {
      var target = document.querySelector(this.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

})();

/* Dropdown menu — works on ALL devices via click + direct style */
document.addEventListener('DOMContentLoaded',function(){
  var triggers=document.querySelectorAll('.cp-has-dropdown');
  triggers.forEach(function(trigger){
    trigger.addEventListener('click',function(e){
      e.preventDefault();
      e.stopPropagation();
      var menu=this.nextElementSibling;
      if(!menu)return;
      var isOpen=menu.style.display==='block';
      // Close all dropdowns first
      document.querySelectorAll('.cp-dropdown-menu').forEach(function(m){m.style.display='none'});
      // Toggle this one
      if(!isOpen){menu.style.display='block'}
    });
    // Hover open on desktop
    trigger.parentElement.addEventListener('mouseenter',function(){
      var menu=this.querySelector('.cp-dropdown-menu');
      if(menu&&window.innerWidth>921)menu.style.display='block';
    });
    trigger.parentElement.addEventListener('mouseleave',function(){
      var menu=this.querySelector('.cp-dropdown-menu');
      if(menu&&window.innerWidth>921)menu.style.display='none';
    });
  });
  // Close dropdowns when clicking outside
  document.addEventListener('click',function(e){
    if(!e.target.closest('.cp-nav-dropdown')){
      document.querySelectorAll('.cp-dropdown-menu').forEach(function(m){m.style.display='none'});
    }
  });
  // Hover effect on dropdown items
  document.querySelectorAll('.cp-dropdown-menu a').forEach(function(a){
    a.addEventListener('mouseenter',function(){this.style.background='rgba(0,212,170,.06)';this.style.color='#00d4aa'});
    a.addEventListener('mouseleave',function(){this.style.background='transparent';this.style.color='#8892a8'});
  });
});

/* Page loader removed */



/* ═══════════════════════════════════════════════════════
   ✨ Sparkle Cursor Trail
   ═══════════════════════════════════════════════════════ */
(function(){
  var canvas = document.createElement('canvas');
  canvas.id = 'fxlm-cursor-trail';
  canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999997;';
  document.body.appendChild(canvas);
  var ctx = canvas.getContext('2d');
  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
  window.addEventListener('resize', function(){ canvas.width=window.innerWidth; canvas.height=window.innerHeight; }, {passive:true});

  var particles = [];
  var mouse = {x: -200, y: -200};
  var colors = ['#00d4aa','#0099ff','#ffffff','#00b8d4','#64e8de'];

  document.addEventListener('mousemove', function(e){
    mouse.x = e.clientX; mouse.y = e.clientY;
    for (var i = 0; i < 3; i++) {
      particles.push({
        x: mouse.x + (Math.random()-0.5)*12,
        y: mouse.y + (Math.random()-0.5)*12,
        vx: (Math.random()-0.5)*1.5,
        vy: (Math.random()-0.5)*1.5 - 0.8,
        r: Math.random()*3 + 1,
        life: 1,
        decay: 0.025 + Math.random()*0.02,
        color: colors[Math.floor(Math.random()*colors.length)]
      });
    }
    if (particles.length > 120) particles.splice(0, particles.length - 120);
  }, {passive:true});

  function draw(){
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    for (var i = particles.length-1; i >= 0; i--) {
      var p = particles[i];
      p.x += p.vx; p.y += p.vy;
      p.vy += 0.04;
      p.life -= p.decay;
      if (p.life <= 0) { particles.splice(i, 1); continue; }
      ctx.globalAlpha = p.life * 0.7;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r * p.life, 0, Math.PI*2);
      ctx.fillStyle = p.color;
      ctx.fill();
    }
    ctx.globalAlpha = 1;
    requestAnimationFrame(draw);
  }
  draw();
})();
