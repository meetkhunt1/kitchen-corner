/* ============================================================
   Kitchen Corner - "Harvested / Blended" scroll journey
   A scrub-linked animation: a garlic bulb travels from the
   farmer's hand into the pot, then a drop arcs from the pot
   into the logo pan and the pan starts steaming.
   Written from scratch - no animation library.
   ============================================================ */
(function () {
  "use strict";

  var GARLIC_VERT = 0.58;
  var POT_VERT = 0.0;
  var LOGO_PAN_VERT = 0.0;

  var GARLIC_SRC = "/assets/media/2025/12/Vector-5.png";
  var WATER_SRC = "/assets/media/2025/10/Union.png";

  /* timeline layout (same proportions as the original) */
  var T_PATH_1 = 0.5; // garlic travels
  var T_FADE = 0.3; // garlic out / water in
  var T_PATH_2 = 0.5; // water arcs
  var T_WATER_OUT = 0.25;
  var T_TOTAL = T_PATH_1 + T_PATH_2 + T_WATER_OUT + 0.001;
  var T_DROP_HITS = T_PATH_1 + T_PATH_2 + T_WATER_OUT; // steam starts

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  function clamp01(v) {
    return v < 0 ? 0 : v > 1 ? 1 : v;
  }

  ready(function () {
    var container = document.getElementById("ga-journey");
    if (!container) return;

    var garlic = null;
    var water = null;
    var steamOn = false;
    var points = { garlic: null, water: null };
    var enabled = false;

    function addActor(id, src) {
      var el = document.getElementById(id);
      if (!el) {
        el = document.createElement("img");
        el.id = id;
        el.className = "ga-floater";
        el.alt = "";
        el.setAttribute("aria-hidden", "true");
        el.src = src;
        container.appendChild(el);
      }
      return el;
    }

    function anchor(selector, vertFactor) {
      var el = document.querySelector(selector);
      if (!el) return null;
      var r = el.getBoundingClientRect();
      var c = container.getBoundingClientRect();
      return {
        x: r.left + r.width / 2 - c.left,
        y: r.top + r.height * vertFactor - c.top
      };
    }

    function buildPaths() {
      var garlicStart = anchor("#ga-garlic-start", GARLIC_VERT);
      var potTop = anchor("#ga-pot-target", POT_VERT);
      var logoPanTop = anchor("#ga-logo-pan-target", LOGO_PAN_VERT);
      if (!garlicStart || !potTop || !logoPanTop) return false;

      points.garlic = [garlicStart, potTop];

      // an arc that rises 60px above the higher of the two ends
      var arcY = Math.min(potTop.y, logoPanTop.y) - 60;
      var mid = { x: (potTop.x + logoPanTop.x) / 2, y: arcY };
      // quadratic control point chosen so the curve passes through `mid`
      var ctrl = {
        x: 2 * mid.x - (potTop.x + logoPanTop.x) / 2,
        y: 2 * mid.y - (potTop.y + logoPanTop.y) / 2
      };
      points.water = { p0: potTop, c: ctrl, p1: logoPanTop };
      return true;
    }

    function lerpPoint(a, b, t) {
      return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t };
    }

    function quad(p0, c, p1, t) {
      var mt = 1 - t;
      return {
        x: mt * mt * p0.x + 2 * mt * t * c.x + t * t * p1.x,
        y: mt * mt * p0.y + 2 * mt * t * c.y + t * t * p1.y
      };
    }

    function place(el, pt, opacity, scale) {
      el.style.transform =
        "translate3d(" + pt.x + "px," + pt.y + "px,0) translate(-50%,-50%)" +
        (scale && scale !== 1 ? " scale(" + scale + ")" : "");
      el.style.opacity = opacity;
    }

    function setSteam(on) {
      if (on === steamOn) return;
      steamOn = on;
      var groups = container.querySelectorAll(".ga-steam");
      Array.prototype.forEach.call(groups, function (g) {
        g.classList.toggle("ga-steam-on", on);
      });
    }

    function render(progress) {
      if (!points.garlic || !points.water) return;
      var t = progress * T_TOTAL;

      /* garlic: straight line, then fades out */
      var u = clamp01(t / T_PATH_1);
      var gPos = lerpPoint(points.garlic[0], points.garlic[1], u);
      var gOpacity =
        t <= T_PATH_1 ? 1 : 1 - clamp01((t - T_PATH_1) / T_FADE);
      place(garlic, gPos, gOpacity, 1);

      /* water drop: fades in while arcing, then fades out */
      var v = clamp01((t - T_PATH_1) / T_PATH_2);
      var wPos = quad(points.water.p0, points.water.c, points.water.p1, v);
      var wOpacity = clamp01((t - T_PATH_1) / T_FADE);
      var outStart = T_PATH_1 + T_PATH_2;
      if (t > outStart) {
        var o = clamp01((t - outStart) / T_WATER_OUT);
        // power1.out
        wOpacity = Math.min(wOpacity, 1 - (1 - Math.pow(1 - o, 2)));
      }
      var wScale = 0.9 + 0.1 * clamp01((t - T_PATH_1) / T_FADE);
      place(water, wPos, wOpacity, wScale);

      setSteam(t >= T_DROP_HITS - 0.0005);
    }

    // start: container top reaches 10% of the viewport height
    // end:   container bottom reaches the viewport bottom
    function currentProgress() {
      var rect = container.getBoundingClientRect();
      var vh = window.innerHeight;
      var startDist = rect.top - vh * 0.1;   // 0 when the scrub starts
      var endDist = rect.bottom - vh;        // 0 when the scrub ends
      var total = endDist - startDist;       // section height - 0.9 viewport
      if (total <= 0) return startDist <= 0 ? 1 : 0;
      return clamp01(-startDist / total);
    }

    var ticking = false;
    function onScroll() {
      if (!enabled || ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        render(currentProgress());
        ticking = false;
      });
    }

    function init() {
      if (window.innerWidth < 1024) {
        enabled = false;
        if (garlic) garlic.style.display = "none";
        if (water) water.style.display = "none";
        setSteam(false);
        return;
      }
      garlic = addActor("ga-garlic", GARLIC_SRC);
      water = addActor("ga-water", WATER_SRC);
      garlic.style.display = "";
      water.style.display = "";
      if (!buildPaths()) return;
      enabled = true;
      render(currentProgress());
    }

    // wait for the artwork to settle before measuring
    init();
    window.addEventListener("load", function () {
      init();
      onScroll();
    });
    window.addEventListener("scroll", onScroll, { passive: true });

    var rt;
    window.addEventListener("resize", function () {
      clearTimeout(rt);
      rt = setTimeout(function () {
        init();
        onScroll();
      }, 150);
    });
  });
})();
