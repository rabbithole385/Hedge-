/* Nexora UI - reveal-on-scroll, sliders and marquee helpers. No dependencies. */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function reducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  ready(function () {
    var reduced = reducedMotion();

    /* 1. Reveal on scroll.
       Elements already tagged with .reveal in the markup are picked up, and
       common marketing blocks are auto-tagged so every page animates without
       per-page edits. If JS or the observer is unavailable everything still
       shows, because the class is only ever added from here. */
    var tagged = Array.prototype.slice.call(document.querySelectorAll('.reveal'));

    var autoSel = [
      '.hero-inner', '.hero-art',
      '.section-head',
      '.section-inner > .two-col',
      '.section-inner > .grid',
      '.section-inner > .card',
      '.section-inner > .note',
      '.cta-band', '.stat-band'
    ].join(',');

    Array.prototype.forEach.call(document.querySelectorAll(autoSel), function (el) {
      if (el.classList.contains('reveal')) return;   // already in the markup
      if (el.closest('.slider')) return;             // slides animate via the slider
      el.classList.add('reveal');
      tagged.push(el);
    });

    // Gentle stagger within each group of siblings.
    tagged.forEach(function (el) {
      if (el.style.getPropertyValue('--nx-delay')) return;
      var parent = el.parentElement;
      var sibs = parent ? Array.prototype.filter.call(parent.children, function (c) {
        return c.classList && c.classList.contains('reveal');
      }) : [el];
      var i = sibs.indexOf(el);
      el.style.setProperty('--nx-delay', ((i > 0 ? i % 5 : 0) * 70) + 'ms');
    });

    if (reduced || !('IntersectionObserver' in window)) {
      tagged.forEach(function (el) { el.classList.add('in'); });
    } else {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
      tagged.forEach(function (el) { io.observe(el); });

      // Safety net: anything already on screen when the page settles is revealed.
      window.addEventListener('load', function () {
        setTimeout(function () {
          tagged.forEach(function (el) {
            var r = el.getBoundingClientRect();
            if (r.top < window.innerHeight && r.bottom > 0) el.classList.add('in');
          });
        }, 400);
      });
    }

    /* 2. Sliders
       <div class="slider" data-autoplay="6000">
         <div class="slider-viewport"><div class="slider-track">...slides...</div></div>
         <div class="slider-nav"></div>
       </div> */
    document.querySelectorAll('.slider').forEach(function (slider) {
      var track = slider.querySelector('.slider-track');
      if (!track) return;
      var count = track.children.length;
      if (count <= 1) return;
      var nav = slider.querySelector('.slider-nav');
      var dots = [];
      var idx = 0;

      function go(i) {
        idx = (i + count) % count;
        track.style.transform = 'translateX(' + (-idx * 100) + '%)';
        dots.forEach(function (d, k) { d.classList.toggle('active', k === idx); });
      }

      if (nav) {
        Array.prototype.forEach.call(track.children, function (_, i) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'slider-dot' + (i === 0 ? ' active' : '');
          b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
          b.addEventListener('click', function () { restart(); go(i); });
          nav.appendChild(b);
          dots.push(b);
        });
      }
      go(0);

      var delay = parseInt(slider.getAttribute('data-autoplay') || '6500', 10);
      var timer = null;
      function start() { if (delay > 0 && !reduced) timer = setInterval(function () { go(idx + 1); }, delay); }
      function stop() { if (timer) { clearInterval(timer); timer = null; } }
      function restart() { stop(); start(); }
      slider.addEventListener('mouseenter', stop);
      slider.addEventListener('mouseleave', start);
      start();

      // Swipe support for touch.
      var sx = null;
      slider.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; stop(); }, { passive: true });
      slider.addEventListener('touchend', function (e) {
        if (sx === null) return;
        var dx = e.changedTouches[0].clientX - sx;
        if (Math.abs(dx) > 40) go(idx + (dx < 0 ? 1 : -1));
        sx = null; start();
      });
    });

    /* 3. Marquee: duplicate content once so the -50% keyframe loops seamlessly */
    document.querySelectorAll('.marquee-track').forEach(function (track) {
      if (track.getAttribute('data-nx-dup')) return;
      track.setAttribute('data-nx-dup', '1');
      track.innerHTML += track.innerHTML;
    });

    /* 4. Count-up numbers: <b class="nx-count" data-to="128">0</b> */
    document.querySelectorAll('.nx-count[data-to]').forEach(function (el) {
      var to = parseFloat(el.getAttribute('data-to'));
      if (isNaN(to)) return;
      if (reduced || !('IntersectionObserver' in window)) { el.textContent = el.getAttribute('data-to'); return; }
      var dec = (el.getAttribute('data-dec') | 0);
      var io2 = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) return;
          io2.unobserve(el);
          var t0 = null, dur = 1100;
          function step(ts) {
            if (t0 === null) t0 = ts;
            var p = Math.min((ts - t0) / dur, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = (to * eased).toFixed(dec);
            if (p < 1) requestAnimationFrame(step);
          }
          requestAnimationFrame(step);
        });
      }, { threshold: 0.4 });
      io2.observe(el);
    });
  });
})();
