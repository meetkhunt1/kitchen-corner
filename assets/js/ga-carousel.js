/* ============================================================
   Kitchen Corner - carousel
   A small looping slider: N visible slides, 1-slide steps,
   arrows, dots, autoplay with pause-on-hover/interaction.
   Written from scratch - no slider library.
   ============================================================ */
(function () {
  "use strict";

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  function num(v, fallback) {
    var n = parseFloat(v);
    return isNaN(n) ? fallback : n;
  }

  function Carousel(root) {
    var viewport = root.querySelector(".ga-carousel-viewport");
    var track = root.querySelector(".ga-carousel-track");
    if (!viewport || !track) return;

    var originals = Array.prototype.slice.call(
      track.querySelectorAll(".ga-carousel-slide")
    );
    if (!originals.length) return;

    var showDesktop = num(root.dataset.gaShow, 3);
    var showTablet = num(root.dataset.gaShowTablet, showDesktop);
    var showMobile = num(root.dataset.gaShowMobile, 1);
    var gap = num(root.dataset.gaGap, 20);
    var speed = num(root.dataset.gaSpeed, 500);
    var autoplay = num(root.dataset.gaAutoplay, 0);
    var count = originals.length;

    // clone a full set on each side so the loop never runs dry
    var before = originals.map(function (s) {
      var c = s.cloneNode(true);
      c.setAttribute("aria-hidden", "true");
      c.classList.add("ga-carousel-clone");
      return c;
    });
    var after = originals.map(function (s) {
      var c = s.cloneNode(true);
      c.setAttribute("aria-hidden", "true");
      c.classList.add("ga-carousel-clone");
      return c;
    });
    before.reverse().forEach(function (c) {
      track.insertBefore(c, track.firstChild);
    });
    after.forEach(function (c) {
      track.appendChild(c);
    });

    var all = Array.prototype.slice.call(
      track.querySelectorAll(".ga-carousel-slide")
    );
    var index = count; // first real slide
    var slideW = 0;
    var animating = false;

    function perView() {
      var w = window.innerWidth;
      if (w <= 767) return showMobile;
      if (w < 1024) return showTablet;
      return showDesktop;
    }

    function layout() {
      var n = perView();
      var vw = viewport.clientWidth;
      slideW = (vw - gap * (n - 1)) / n;
      track.style.gap = gap + "px";
      all.forEach(function (s) {
        s.style.width = slideW + "px";
      });
      position(false);
    }

    function position(animate) {
      track.style.transition = animate
        ? "transform " + speed + "ms ease"
        : "none";
      track.style.transform =
        "translate3d(" + -(index * (slideW + gap)) + "px,0,0)";
      updateDots();
    }

    function go(delta) {
      if (animating) return;
      animating = true;
      index += delta;
      position(true);
      window.setTimeout(function () {
        if (index >= count * 2) {
          index -= count;
          position(false);
        } else if (index < count) {
          index += count;
          position(false);
        }
        animating = false;
      }, speed);
    }

    /* dots */
    var dotsWrap = root.querySelector(".ga-carousel-dots");
    var dots = [];
    if (dotsWrap) {
      for (var i = 0; i < count; i++) {
        (function (i) {
          var b = document.createElement("button");
          b.type = "button";
          b.className = "ga-carousel-dot";
          b.setAttribute("aria-label", "Go to slide " + (i + 1));
          b.addEventListener("click", function () {
            stopAuto();
            if (animating) return;
            index = count + i;
            animating = true;
            position(true);
            window.setTimeout(function () {
              animating = false;
            }, speed);
          });
          dotsWrap.appendChild(b);
          dots.push(b);
        })(i);
      }
    }
    function updateDots() {
      if (!dots.length) return;
      var real = ((index - count) % count + count) % count;
      dots.forEach(function (d, i) {
        d.classList.toggle("ga-is-active", i === real);
      });
    }

    /* arrows */
    var prev = root.querySelector(".ga-carousel-prev");
    var next = root.querySelector(".ga-carousel-next");
    if (prev)
      prev.addEventListener("click", function () {
        stopAuto();
        go(-1);
      });
    if (next)
      next.addEventListener("click", function () {
        stopAuto();
        go(1);
      });

    /* autoplay */
    var timer = null;
    var stopped = false;
    function startAuto() {
      if (!autoplay || stopped || timer) return;
      timer = window.setInterval(function () {
        go(1);
      }, autoplay);
    }
    function pauseAuto() {
      if (timer) {
        window.clearInterval(timer);
        timer = null;
      }
    }
    function stopAuto() {
      stopped = true;
      pauseAuto();
    }
    root.addEventListener("mouseenter", pauseAuto);
    root.addEventListener("mouseleave", startAuto);

    /* touch swipe */
    var startX = null;
    viewport.addEventListener(
      "touchstart",
      function (e) {
        startX = e.touches[0].clientX;
        pauseAuto();
      },
      { passive: true }
    );
    viewport.addEventListener(
      "touchend",
      function (e) {
        if (startX === null) return;
        var dx = e.changedTouches[0].clientX - startX;
        if (Math.abs(dx) > 40) {
          stopAuto();
          go(dx < 0 ? 1 : -1);
        }
        startX = null;
      },
      { passive: true }
    );

    layout();
    startAuto();

    var rt;
    window.addEventListener("resize", function () {
      clearTimeout(rt);
      rt = setTimeout(layout, 120);
    });
    window.addEventListener("load", layout);
  }

  ready(function () {
    var nodes = document.querySelectorAll("[data-ga-carousel]");
    Array.prototype.forEach.call(nodes, Carousel);
  });
})();
