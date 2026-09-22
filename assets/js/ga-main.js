/* ============================================================
   Kitchen Corner - shared behaviour
   Hand-written; no external libraries.
   ============================================================ */
(function () {
  "use strict";

  var ready = function (fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  };

  /* ----------------------------------------------------------
     1. Smooth (inertial) scrolling  -- replaces the Lenis lib
     ---------------------------------------------------------- */
  var GaSmoothScroll = (function () {
    var target = 0;
    var current = 0;
    var running = false;
    var enabled = false;
    var lerp = 0.08;
    var wheelMultiplier = 0.7;
    var rafId = null;

    function maxScroll() {
      return Math.max(
        0,
        document.documentElement.scrollHeight - window.innerHeight
      );
    }

    function clamp(v) {
      return Math.min(Math.max(v, 0), maxScroll());
    }

    function frame() {
      current += (target - current) * lerp;
      if (Math.abs(target - current) < 0.4) {
        current = target;
        running = false;
      }
      window.scrollTo(0, current);
      if (running) rafId = requestAnimationFrame(frame);
      else rafId = null;
    }

    function start() {
      if (!running) {
        running = true;
        rafId = requestAnimationFrame(frame);
      }
    }

    function onWheel(e) {
      if (!enabled) return;
      if (e.ctrlKey) return;
      // let scrollable inner panels keep native behaviour
      var node = e.target;
      while (node && node !== document.body && node.nodeType === 1) {
        var st = window.getComputedStyle(node);
        if (
          /(auto|scroll)/.test(st.overflowY) &&
          node.scrollHeight > node.clientHeight + 1
        ) {
          return;
        }
        node = node.parentNode;
      }
      var delta = e.deltaY;
      if (e.deltaMode === 1) delta *= 16; // line units
      else if (e.deltaMode === 2) delta *= window.innerHeight;
      e.preventDefault();
      if (!running) current = window.scrollY;
      target = clamp(target + delta * wheelMultiplier);
      start();
    }

    function sync() {
      if (!running) {
        target = window.scrollY;
        current = window.scrollY;
      }
    }

    return {
      init: function () {
        // respect reduced-motion and coarse pointers (touch keeps native scroll)
        if (
          window.matchMedia("(prefers-reduced-motion: reduce)").matches ||
          window.matchMedia("(hover: none)").matches
        ) {
          return;
        }
        enabled = true;
        target = current = window.scrollY;
        window.addEventListener("wheel", onWheel, { passive: false });
        window.addEventListener("scroll", sync, { passive: true });
        window.addEventListener("resize", sync);
      },
      scrollTo: function (el, duration) {
        var top =
          el.getBoundingClientRect().top + window.scrollY;
        var from = window.scrollY;
        var dist = top - from;
        var t0 = null;
        duration = duration || 1100;
        if (rafId) cancelAnimationFrame(rafId);
        running = false;
        function step(ts) {
          if (t0 === null) t0 = ts;
          var p = Math.min((ts - t0) / duration, 1);
          // easeInOutCubic
          var e = p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2;
          window.scrollTo(0, from + dist * e);
          if (p < 1) requestAnimationFrame(step);
          else {
            target = current = window.scrollY;
          }
        }
        requestAnimationFrame(step);
      }
    };
  })();

  /* ----------------------------------------------------------
     2. Header navigation
     ---------------------------------------------------------- */
  function initNav() {
    // tablet dropdown (768 - 1024)
    var nav = document.querySelector(".ga-nav");
    var navToggle = document.querySelector(".ga-nav-toggle");
    if (nav && navToggle) {
      navToggle.addEventListener("click", function () {
        var open = nav.classList.toggle("ga-is-open");
        navToggle.setAttribute("aria-expanded", open ? "true" : "false");
      });
      document.addEventListener("click", function (e) {
        if (!nav.contains(e.target)) {
          nav.classList.remove("ga-is-open");
          navToggle.setAttribute("aria-expanded", "false");
        }
      });
    }

    // mobile flyout drawer
    var burger = document.querySelector(".ga-burger-btn");
    var flyout = document.querySelector(".ga-flyout");
    var overlay = document.querySelector(".ga-flyout-overlay");
    var closeBtn = document.querySelector(".ga-flyout-close");

    function setFlyout(open) {
      document.body.classList.toggle("ga-flyout-open", open);
      if (burger) burger.setAttribute("aria-expanded", open ? "true" : "false");
      if (flyout) flyout.setAttribute("aria-hidden", open ? "false" : "true");
    }
    if (burger) burger.addEventListener("click", function () {
      setFlyout(!document.body.classList.contains("ga-flyout-open"));
    });
    if (overlay) overlay.addEventListener("click", function () { setFlyout(false); });
    if (closeBtn) closeBtn.addEventListener("click", function () { setFlyout(false); });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") setFlyout(false);
    });
  }

  /* ----------------------------------------------------------
     3. Floating Instagram button
     ---------------------------------------------------------- */
  function initIgFab() {
    if (document.getElementById("ga-ig-fab")) return;
    var SCROLL_TRIGGER = 300;
    var link =
      "https://www.instagram.com/kitchencorner.in/?utm_source=site&utm_medium=fab&utm_campaign=scroll_drop";

    var ig = document.createElement("a");
    ig.id = "ga-ig-fab";
    ig.href = link;
    ig.target = "_blank";
    ig.rel = "noopener";
    ig.setAttribute("aria-label", "Follow Kitchen Corner on Instagram");
    ig.innerHTML =
      '<svg viewBox="0 0 448 512" aria-hidden="true"><path fill="currentColor" d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"></path></svg>';
    document.body.appendChild(ig);

    var shown = false;
    function onScroll() {
      if (window.scrollY > SCROLL_TRIGGER && !shown) {
        shown = true;
        ig.classList.remove("ga-is-hidden");
        ig.classList.add("ga-is-visible");
      }
      if (window.scrollY <= SCROLL_TRIGGER && shown) {
        shown = false;
        ig.classList.remove("ga-is-visible");
        ig.classList.add("ga-is-hidden");
      }
    }
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
  }

  /* ----------------------------------------------------------
     4. Anchor links routed through the smooth scroller
     ---------------------------------------------------------- */
  function initAnchors() {
    var links = document.querySelectorAll('a[href^="#"]');
    Array.prototype.forEach.call(links, function (a) {
      a.addEventListener(
        "click",
        function (e) {
          var id = a.getAttribute("href").slice(1);
          if (!id) return;
          var el = document.getElementById(id);
          if (!el) return;
          e.preventDefault();
          GaSmoothScroll.scrollTo(el, 1100);
        },
        { passive: false }
      );
    });
  }

  /* ----------------------------------------------------------
     5. Continuous ticker (replaces the legacy <marquee>)
     ---------------------------------------------------------- */
  function initMarquee() {
    var tracks = document.querySelectorAll(".ga-marquee");
    Array.prototype.forEach.call(tracks, function (m) {
      var inner = m.querySelector(".ga-marquee-track");
      if (!inner || inner.dataset.gaCloned === "1") return;
      // duplicate the content so the loop is seamless
      var clone = inner.cloneNode(true);
      clone.setAttribute("aria-hidden", "true");
      m.appendChild(clone);
      inner.dataset.gaCloned = "1";
    });
  }

  /* ----------------------------------------------------------
     6. Tab groups
     ---------------------------------------------------------- */
  function initTabs() {
    var groups = document.querySelectorAll("[data-ga-tabs]");
    Array.prototype.forEach.call(groups, function (group) {
      var titles = group.querySelectorAll(".ga-tab-title");
      var panels = group.querySelectorAll(".ga-tab-panel");
      function select(i) {
        Array.prototype.forEach.call(titles, function (t, n) {
          var on = n === i;
          t.classList.toggle("ga-is-active", on);
          t.setAttribute("aria-selected", on ? "true" : "false");
          t.tabIndex = on ? 0 : -1;
        });
        Array.prototype.forEach.call(panels, function (pnl, n) {
          pnl.classList.toggle("ga-is-active", n === i);
        });
      }
      Array.prototype.forEach.call(titles, function (t, i) {
        t.addEventListener("click", function () {
          select(i);
        });
        t.addEventListener("keydown", function (e) {
          if (e.key === "ArrowRight" || e.key === "ArrowLeft") {
            e.preventDefault();
            var next =
              (i + (e.key === "ArrowRight" ? 1 : -1) + titles.length) %
              titles.length;
            select(next);
            titles[next].focus();
          }
        });
      });
    });
  }

  /* ----------------------------------------------------------
     7. Reveal-on-scroll helper (used by a few sections)
     ---------------------------------------------------------- */
  function initReveal() {
    var items = document.querySelectorAll("[data-ga-reveal]");
    if (!items.length) return;
    if (!("IntersectionObserver" in window)) {
      Array.prototype.forEach.call(items, function (el) {
        el.classList.add("ga-revealed");
      });
      return;
    }
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) {
            en.target.classList.add("ga-revealed");
            io.unobserve(en.target);
          }
        });
      },
      { threshold: 0.15 }
    );
    Array.prototype.forEach.call(items, function (el) {
      io.observe(el);
    });
  }

  /* ---------------------------------------------------------- */
  ready(function () {
    initNav();
    initIgFab();
    initMarquee();
    initTabs();
    initReveal();
    GaSmoothScroll.init();
    initAnchors();
  });

  window.GaSmoothScroll = GaSmoothScroll;
})();
