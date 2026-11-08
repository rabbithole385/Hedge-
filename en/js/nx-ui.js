/* Nexora UI - reveal-on-scroll, sliders and marquee helpers. No dependencies. */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    /* 1. Reveal on scroll */
    var revealEls = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window && revealEls.length) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
      revealEls.forEach(function (el) { io.observe(el); });
    } else {
      revealEls.forEach(function (el) { el.classList.add('in'); });
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
      function start() { if (delay > 0) timer = setInterval(function () { go(idx + 1); }, delay); }
      function stop() { if (timer) { clearInterval(timer); timer = null; } }
      function restart() { stop(); start(); }
      slider.addEventListener('mouseenter', stop);
      slider.addEventListener('mouseleave', start);
      start();
    });

    /* 3. Marquee: duplicate content once so the -50% keyframe loops seamlessly */
    document.querySelectorAll('.marquee-track').forEach(function (track) {
      if (track.getAttribute('data-nx-dup')) return;
      track.setAttribute('data-nx-dup', '1');
      track.innerHTML += track.innerHTML;
    });
  });
})();
