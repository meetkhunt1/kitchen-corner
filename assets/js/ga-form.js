/* ============================================================
   Kitchen Corner - contact form
   Character counter + inline validation.
   NOTE: the original site posts this form to its WordPress
   back end. This rebuild has no server, so `GA_FORM_ENDPOINT`
   below is the single place to wire up a real submit URL.
   Until one is set, the form validates and confirms locally.
   ============================================================ */
(function () {
  "use strict";

  var GA_FORM_ENDPOINT = ""; // e.g. "/api/contact" - POSTs JSON when set

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  ready(function () {
    /* ---- character counters ---- */
    var counters = document.querySelectorAll("[data-ga-counter]");
    Array.prototype.forEach.call(counters, function (el) {
      var target = document.getElementById(el.dataset.gaCounter);
      var limit = parseInt(el.dataset.gaLimit, 10) || 0;
      if (!target) return;
      var update = function () {
        el.textContent = target.value.length + " / " + limit;
      };
      target.addEventListener("input", update);
      update();
    });

    /* ---- validation + submit ---- */
    var form = document.getElementById("ga-contact-form");
    if (!form) return;
    var msg = document.getElementById("ga-form-message");

    function setMessage(text, kind) {
      if (!msg) return;
      msg.textContent = text;
      msg.className =
        "ga-form-message ga-is-visible " + (kind === "success" ? "ga-is-success" : "ga-is-error");
    }
    function clearMessage() {
      if (!msg) return;
      msg.textContent = "";
      msg.className = "ga-form-message";
    }

    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    var PHONE = /^[0-9+()\-.\s]{6,20}$/;

    function fieldError(input, on) {
      input.classList.toggle("ga-has-error", !!on);
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      clearMessage();

      var honeypot = form.querySelector('[name="ga_hp"]');
      if (honeypot && honeypot.value) return; // silently drop bots

      var name = form.querySelector("#ga-field-name");
      var email = form.querySelector("#ga-field-email");
      var phone = form.querySelector("#ga-field-phone");
      var message = form.querySelector("#ga-field-message");
      var problems = [];

      [name, email, phone, message].forEach(function (f) {
        fieldError(f, false);
      });

      if (!name.value.trim()) {
        problems.push("Please enter your name.");
        fieldError(name, true);
      }
      if (!EMAIL.test(email.value.trim())) {
        problems.push("Please enter a valid email address.");
        fieldError(email, true);
      }
      if (!PHONE.test(phone.value.trim())) {
        problems.push("Please enter a valid phone number.");
        fieldError(phone, true);
      }
      if (!message.value.trim()) {
        problems.push("Please add a message.");
        fieldError(message, true);
      }

      if (problems.length) {
        setMessage(problems[0], "error");
        var first = form.querySelector(".ga-has-error");
        if (first) first.focus();
        return;
      }

      var payload = {
        name: name.value.trim(),
        email: email.value.trim(),
        phone: phone.value.trim(),
        message: message.value.trim()
      };

      if (!GA_FORM_ENDPOINT) {
        setMessage(
          "Thanks! Your message is ready to send — set GA_FORM_ENDPOINT in assets/js/ga-form.js to deliver it.",
          "success"
        );
        form.reset();
        var c = form.querySelector("[data-ga-counter]");
        if (c) c.textContent = "0 / " + (c.dataset.gaLimit || 180);
        return;
      }

      var btn = form.querySelector(".ga-form-submit");
      if (btn) btn.disabled = true;
      fetch(GA_FORM_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
        .then(function (r) {
          if (!r.ok) throw new Error("bad status");
          setMessage("Thanks! We'll get back to you shortly.", "success");
          form.reset();
        })
        .catch(function () {
          setMessage("Sorry, something went wrong. Please try again.", "error");
        })
        .then(function () {
          if (btn) btn.disabled = false;
        });
    });
  });
})();
