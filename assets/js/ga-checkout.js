/* ============================================================
   Kitchen Corner - checkout (/checkout/)
   Runs the WooCommerce checkout from the storefront through the Store
   API. The cart is identified by a signed Cart-Token (no cookies are
   sent cross-site); prices, delivery charges and totals always come
   from WooCommerce. Placing the order hands the shopper to the payment
   page on the store; only that host or PhonePe's are ever followed.
   Hand-written; no external libraries. Needs ga-shop.js (window.GaShop).
   ============================================================ */
(function () {
  "use strict";

  var root = document.querySelector("[data-ga-checkout-page]");
  var S = window.GaShop;
  if (!root || !S) return;
  // Catalogue mode: there is nothing to check out yet.
  if (S.ordering === false) {
    window.location.replace("/products/");
    return;
  }

  var API = S.apiBase + "/wp-json/wc/store/v1";
  var TOKEN_KEY = "ga-cart-token"; // localStorage: lets the cart survive page loads (48 h)
  var FORM_KEY = "ga-checkout-form"; // sessionStorage: personal details, this tab only
  var COUNTRY = "IN";

  // Labels used if the store's payment-method list can't be loaded.
  var FALLBACK_METHODS = {
    phonepe: { title: "UPI, cards & netbanking (PhonePe)", description: "" },
    cod: { title: "Cash on delivery", description: "Pay in cash when your order arrives." },
    bacs: { title: "Direct bank transfer", description: "" }
  };

  var el = {
    state: root.querySelector("[data-ga-co-state]"),
    main: root.querySelector("[data-ga-co-main]"),
    banner: root.querySelector("[data-ga-co-banner]"),
    form: root.querySelector("[data-ga-co-form]"),
    rates: root.querySelector("[data-ga-co-rates]"),
    methods: root.querySelector("[data-ga-co-methods]"),
    items: root.querySelector("[data-ga-co-items]"),
    totals: root.querySelector("[data-ga-co-totals]"),
    error: root.querySelector("[data-ga-co-error]"),
    submit: root.querySelector("[data-ga-co-submit]")
  };

  var cart = null; // latest WooCommerce cart
  var methodInfo = null; // id -> {title, description}
  var busy = false;

  /* ----------------------------------------------------------
     Storage (every access may throw in private mode)
     ---------------------------------------------------------- */
  function getItem(store, key) {
    try {
      return window[store].getItem(key);
    } catch (e) {
      return null;
    }
  }
  function setItem(store, key, value) {
    try {
      if (value === null) window[store].removeItem(key);
      else window[store].setItem(key, value);
    } catch (e) {}
  }

  /* ----------------------------------------------------------
     Store API
     ---------------------------------------------------------- */
  function ApiError(status, code, message) {
    this.status = status;
    this.code = code || "";
    this.message = message || "Something went wrong. Please try again.";
  }

  function api(method, path, body) {
    var headers = { Accept: "application/json" };
    var token = getItem("localStorage", TOKEN_KEY);
    if (token) headers["Cart-Token"] = token;
    if (body) headers["Content-Type"] = "application/json";
    return fetch(API + path, {
      method: method,
      headers: headers,
      body: body ? JSON.stringify(body) : undefined,
      credentials: "omit",
      cache: "no-store"
    }).then(
      function (r) {
        var next = r.headers.get("Cart-Token");
        if (next) setItem("localStorage", TOKEN_KEY, next);
        return r.json().then(
          function (data) {
            if (!r.ok) throw new ApiError(r.status, data && data.code, data && S.text(data.message));
            return data;
          },
          function () {
            throw new ApiError(r.status);
          }
        );
      },
      function () {
        throw new ApiError(0, "network", "We couldn’t reach the store. Check your connection and try again.");
      }
    );
  }

  /* ----------------------------------------------------------
     Cart sync: make the WooCommerce cart match the storefront cart
     ---------------------------------------------------------- */
  var syncing = null;
  var resync = false;

  function wanted() {
    var out = {};
    S.Cart.items().forEach(function (i) {
      if (i.available) out[i.id] = i.qty;
    });
    return out;
  }

  function reconcile(current, problems) {
    var want = wanted();
    var steps = [];
    current.items.forEach(function (it) {
      if (!want[it.id]) steps.push(["/cart/remove-item", { key: it.key }]);
      else if (it.quantity !== want[it.id]) steps.push(["/cart/update-item", { key: it.key, quantity: want[it.id] }]);
      delete want[it.id];
    });
    Object.keys(want).forEach(function (id) {
      steps.push(["/cart/add-item", { id: Number(id), quantity: want[id] }]);
    });
    return steps.reduce(function (p, step) {
      return p.then(function (latest) {
        return api("POST", step[0], step[1]).catch(function (err) {
          if (/nonce/.test(err.code)) throw err; // token rejected: start over
          problems.push(err.message);
          return latest;
        });
      });
    }, Promise.resolve(current));
  }

  function doSync(retried) {
    var problems = [];
    return api("GET", "/cart")
      .then(function (c) {
        return reconcile(c, problems);
      })
      .then(function (c) {
        c.kcProblems = problems;
        return c;
      })
      .catch(function (err) {
        if (!retried && /nonce/.test(err.code)) {
          setItem("localStorage", TOKEN_KEY, null);
          return doSync(true);
        }
        throw err;
      });
  }

  function sync() {
    if (syncing) {
      resync = true;
      return syncing;
    }
    syncing = doSync(false).then(
      function (c) {
        syncing = null;
        if (resync) {
          resync = false;
          return sync();
        }
        return c;
      },
      function (err) {
        syncing = null;
        resync = false;
        throw err;
      }
    );
    return syncing;
  }

  /* ----------------------------------------------------------
     Form
     ---------------------------------------------------------- */
  var FIELDS = ["email", "phone", "first_name", "last_name", "address_1", "address_2", "city", "postcode", "state"];

  function field(name) {
    return el.form.elements[name];
  }
  function value(name) {
    var f = field(name);
    return f ? String(f.value).trim() : "";
  }
  function phoneDigits() {
    var d = value("phone").replace(/\D/g, "");
    if (d.length === 12 && d.indexOf("91") === 0) d = d.slice(2);
    if (d.length === 11 && d.charAt(0) === "0") d = d.slice(1);
    return d;
  }

  var RULES = {
    email: function (v) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? "" : "Enter a valid email address.";
    },
    phone: function () {
      return /^[6-9]\d{9}$/.test(phoneDigits()) ? "" : "Enter a valid 10-digit mobile number.";
    },
    first_name: function (v) {
      return v ? "" : "Enter your first name.";
    },
    last_name: function (v) {
      return v ? "" : "Enter your last name.";
    },
    address_1: function (v) {
      return v.length >= 3 ? "" : "Enter your house / flat and street.";
    },
    city: function (v) {
      return v ? "" : "Enter your city.";
    },
    postcode: function (v) {
      return /^[1-9]\d{5}$/.test(v) ? "" : "Enter a valid 6-digit PIN code.";
    },
    state: function (v) {
      return v ? "" : "Select your state.";
    }
  };

  function setFieldError(name, message) {
    var f = field(name);
    var msg = root.querySelector("#co-" + name + "-err");
    if (!f || !msg) return;
    f.setAttribute("aria-invalid", message ? "true" : "false");
    msg.textContent = message;
    msg.hidden = !message;
  }

  function validate() {
    var first = null;
    Object.keys(RULES).forEach(function (name) {
      var message = RULES[name](value(name));
      setFieldError(name, message);
      if (message && !first) first = field(name);
    });
    return first;
  }

  function address() {
    return {
      first_name: value("first_name"),
      last_name: value("last_name"),
      company: "",
      address_1: value("address_1"),
      address_2: value("address_2"),
      city: value("city"),
      state: value("state"),
      postcode: value("postcode"),
      country: COUNTRY,
      phone: phoneDigits()
    };
  }
  function billing() {
    var a = address();
    a.email = value("email");
    return a;
  }
  function addressReady() {
    return !RULES.postcode(value("postcode")) && !RULES.state(value("state")) && !RULES.city(value("city"));
  }

  // Personal details live only in this tab, so a failed payment doesn't
  // mean typing everything again.
  function saveForm() {
    var data = {};
    FIELDS.concat("note").forEach(function (n) {
      data[n] = value(n);
    });
    setItem("sessionStorage", FORM_KEY, JSON.stringify(data));
  }
  function restoreForm() {
    var data;
    try {
      data = JSON.parse(getItem("sessionStorage", FORM_KEY) || "{}");
    } catch (e) {
      data = {};
    }
    FIELDS.concat("note").forEach(function (n) {
      if (data[n] && field(n)) field(n).value = String(data[n]);
    });
  }
  function prefillFromCart(c) {
    var b = (c && c.billing_address) || {};
    FIELDS.forEach(function (n) {
      if (!value(n) && b[n] && n !== "state") field(n).value = b[n];
    });
    if (!value("state") && b.state && b.country === COUNTRY) field("state").value = b.state;
  }

  /* ----------------------------------------------------------
     Rendering (all store text is escaped with S.esc)
     ---------------------------------------------------------- */
  function cur(obj) {
    return S.currencyOf(obj);
  }

  function selectedRate(c) {
    var pick = null;
    (c.shipping_rates || []).forEach(function (pkg) {
      (pkg.shipping_rates || []).forEach(function (r) {
        if (r.selected) pick = r;
      });
    });
    return pick;
  }

  function renderItems(c) {
    el.items.innerHTML = c.items
      .map(function (it) {
        var img = (it.images && it.images[0]) || {};
        var size = (it.variation || [])
          .map(function (v) {
            return v.value;
          })
          .join(" / ");
        return (
          '<li class="ga-co-item">' +
            '<span class="ga-co-thumb">' +
              (img.thumbnail || img.src
                ? '<img src="' + S.esc(img.thumbnail || img.src) + '" alt="" width="64" height="85" loading="lazy" decoding="async">'
                : S.placeholderHTML()) +
              '<span class="ga-co-qty" aria-label="Quantity">' + Number(it.quantity) + "</span>" +
            "</span>" +
            '<span class="ga-co-item-name">' + S.esc(it.name) +
              (size ? '<span class="ga-co-item-size">' + S.esc(size) + "</span>" : "") +
            "</span>" +
            '<span class="ga-co-item-price">' + S.money(it.totals.line_total, cur(it.totals)) + "</span>" +
          "</li>"
        );
      })
      .join("");
  }

  function renderTotals(c) {
    var t = c.totals;
    var c$ = cur(t);
    var rate = selectedRate(c);
    var shipping = !c.needs_shipping
      ? "Free"
      : c.has_calculated_shipping && rate
        ? Number(t.total_shipping) > 0
          ? S.money(t.total_shipping, c$)
          : "Free"
        : '<span class="ga-co-muted">Enter address</span>';
    var rows = [["Subtotal", S.money(t.total_items, c$)]];
    if (Number(t.total_discount) > 0) rows.push(["Discount", "&minus;" + S.money(t.total_discount, c$)]);
    rows.push(["Delivery", shipping]);
    if (Number(t.total_tax) > 0) rows.push(["Tax", S.money(t.total_tax, c$)]);
    el.totals.innerHTML =
      rows
        .map(function (r) {
          return "<div><dt>" + r[0] + "</dt><dd>" + r[1] + "</dd></div>";
        })
        .join("") +
      '<div class="ga-co-grand"><dt>Total</dt><dd><span class="ga-co-cur">' + S.esc(t.currency_code || "") + "</span>" +
        S.money(t.total_price, c$) + "</dd></div>";
  }

  function renderRates(c) {
    if (!c.needs_shipping) {
      el.rates.innerHTML = '<p class="ga-co-hint">No delivery needed for this order.</p>';
      return;
    }
    if (!c.has_calculated_shipping || !addressReady()) {
      el.rates.innerHTML = '<p class="ga-co-hint">Enter your PIN code to see delivery options.</p>';
      return;
    }
    var options = [];
    (c.shipping_rates || []).forEach(function (pkg) {
      (pkg.shipping_rates || []).forEach(function (r) {
        options.push({ pkg: pkg.package_id, rate: r });
      });
    });
    if (!options.length) {
      el.rates.innerHTML =
        '<p class="ga-co-warn">Sorry, we don’t deliver to this PIN code yet. Please check it, or contact us.</p>';
      return;
    }
    el.rates.innerHTML = options
      .map(function (o, n) {
        var r = o.rate;
        var price = Number(r.price) > 0 ? S.money(r.price, cur(r)) : "Free";
        var eta = r.delivery_time && r.delivery_time.value ? S.esc(r.delivery_time.value) : "";
        return (
          '<label class="ga-co-option">' +
            '<input type="radio" name="kc_rate" value="' + S.esc(r.rate_id) + '" data-pkg="' + Number(o.pkg) + '"' +
              (r.selected ? " checked" : "") + (n === 0 && !selectedRate(c) ? " checked" : "") + ">" +
            '<span class="ga-co-option-body"><span class="ga-co-option-title">' + S.esc(r.name) + "</span>" +
              (eta ? '<span class="ga-co-option-desc">' + eta + "</span>" : "") +
            "</span>" +
            '<span class="ga-co-option-price">' + price + "</span>" +
          "</label>"
        );
      })
      .join("");
  }

  function chosenMethod() {
    var checked = el.methods.querySelector('input[name="kc_method"]:checked');
    return checked ? checked.value : "";
  }

  function renderMethods(c) {
    var before = chosenMethod();
    var ids = c.payment_methods || [];
    if (!ids.length) {
      el.methods.innerHTML = '<p class="ga-co-warn">Online payment is not available right now. Please try again later.</p>';
      return;
    }
    var pick = ids.indexOf(before) !== -1 ? before : ids.indexOf("phonepe") !== -1 ? "phonepe" : ids[0];
    el.methods.innerHTML = ids
      .map(function (id) {
        var info = (methodInfo && methodInfo[id]) || FALLBACK_METHODS[id] || { title: id, description: "" };
        return (
          '<label class="ga-co-option">' +
            '<input type="radio" name="kc_method" value="' + S.esc(id) + '"' + (id === pick ? " checked" : "") + ">" +
            '<span class="ga-co-option-body"><span class="ga-co-option-title">' + S.esc(info.title) + "</span>" +
              (info.description ? '<span class="ga-co-option-desc">' + S.esc(info.description) + "</span>" : "") +
            "</span>" +
          "</label>"
        );
      })
      .join("");
  }

  function renderButton() {
    if (busy || !cart) return;
    var online = chosenMethod() && chosenMethod() !== "cod" && chosenMethod() !== "bacs";
    el.submit.textContent = online
      ? "Pay " + S.money(cart.totals.total_price, cur(cart.totals))
      : "Place order";
  }

  function render(c) {
    cart = c;
    if (!c.items.length) {
      el.main.hidden = true;
      el.state.hidden = false;
      el.state.innerHTML =
        '<p>Your cart is empty.</p><a class="ga-btn" href="/products/">Shop products</a>';
      return;
    }
    el.state.hidden = true;
    el.main.hidden = false;
    renderItems(c);
    renderTotals(c);
    renderRates(c);
    renderMethods(c);
    renderButton();
    var problems = (c.kcProblems || []).concat(
      (c.errors || []).map(function (e) {
        return S.text(e.message);
      })
    );
    showError(problems.length ? problems.join(" ") : "");
  }

  function showError(message) {
    el.error.textContent = message;
    el.error.hidden = !message;
  }

  /* ----------------------------------------------------------
     Actions
     ---------------------------------------------------------- */
  function updateCustomer() {
    return api("POST", "/cart/update-customer", {
      billing_address: billing(),
      shipping_address: address()
    });
  }

  var addressTimer = null;
  function onAddressChange() {
    clearTimeout(addressTimer);
    if (!addressReady()) {
      if (cart) renderRates(cart);
      return;
    }
    addressTimer = setTimeout(function () {
      el.rates.innerHTML = '<p class="ga-co-hint">Checking delivery options&hellip;</p>';
      updateCustomer().then(render, function (err) {
        showError(err.message);
        if (cart) renderRates(cart);
      });
    }, 450);
  }

  function selectRate(input) {
    api("POST", "/cart/select-shipping-rate", {
      package_id: Number(input.getAttribute("data-pkg")),
      rate_id: input.value
    }).then(render, function (err) {
      showError(err.message);
    });
  }

  // Only the store's own pages or PhonePe's payment pages are followed.
  function safeRedirect(raw) {
    var u;
    try {
      u = new URL(String(raw || ""), S.apiBase);
    } catch (e) {
      return "";
    }
    if (u.protocol !== "https:" && u.hostname !== "localhost") return "";
    var storeHost = new URL(S.apiBase).hostname;
    if (u.hostname === storeHost) {
      // Tells the store this order came from the storefront, so the shopper
      // is brought back here after paying (or not).
      u.searchParams.set("kc_src", "storefront");
      return u.href;
    }
    return /(^|\.)phonepe\.com$/.test(u.hostname) ? u.href : "";
  }

  function setBusy(on, label) {
    busy = on;
    el.submit.disabled = on;
    el.form.setAttribute("aria-busy", on ? "true" : "false");
    if (on) el.submit.textContent = label;
    else renderButton();
  }

  function placeOrder(e) {
    e.preventDefault();
    if (busy) return;
    showError("");
    var invalid = validate();
    if (invalid) {
      invalid.focus();
      showError("Please check the highlighted details.");
      return;
    }
    var method = chosenMethod();
    if (!method) {
      showError("Choose a payment method.");
      return;
    }
    saveForm();
    setBusy(true, "Processing…");

    sync()
      .then(updateCustomer)
      .then(function (c) {
        render(c);
        if (!c.items.length) throw new ApiError(0, "empty", "Your cart is empty.");
        if (c.errors && c.errors.length) throw new ApiError(0, "cart", S.text(c.errors[0].message));
        if (c.needs_shipping && !selectedRate(c)) {
          throw new ApiError(0, "shipping", "Sorry, we can’t deliver to this address yet. Please check the PIN code.");
        }
        return api("POST", "/checkout", {
          billing_address: billing(),
          shipping_address: address(),
          customer_note: value("note").slice(0, 500),
          payment_method: method,
          payment_data: []
        });
      })
      .then(function (order) {
        var result = (order && order.payment_result) || {};
        if (result.payment_status === "failure" || result.payment_status === "error") {
          var detail = (result.payment_details || []).filter(function (d) {
            return d.key === "message" || d.key === "errorMessage";
          })[0];
          throw new ApiError(0, "payment", detail ? S.text(detail.value) : "The payment could not be started. Please try again.");
        }
        var url = safeRedirect(result.redirect_url);
        if (!url) throw new ApiError(0, "redirect", "We couldn’t open the payment page. Please try again.");
        el.submit.textContent = "Redirecting to payment…";
        window.location.assign(url);
      })
      .catch(function (err) {
        setBusy(false);
        showError(err.message || "Something went wrong. Please try again.");
        if (err.status === 429) showError("Too many attempts. Please wait a minute and try again.");
      });
  }

  /* ----------------------------------------------------------
     Start
     ---------------------------------------------------------- */
  function loadMethodNames() {
    return fetch(S.apiBase + "/wp-json/kc/v1/payment-methods", { credentials: "omit" })
      .then(function (r) {
        return r.ok ? r.json() : [];
      })
      .then(function (list) {
        methodInfo = {};
        (Array.isArray(list) ? list : []).forEach(function (m) {
          if (m && m.id) methodInfo[String(m.id)] = { title: String(m.title || m.id), description: String(m.description || "") };
        });
      })
      .catch(function () {
        methodInfo = null;
      });
  }

  function start() {
    if (!S.live) {
      el.state.textContent = "Checkout opens once the online store is connected.";
      return;
    }
    var params = new URLSearchParams(window.location.search);
    if (params.get("payment") === "failed") {
      el.banner.textContent = "Your payment was not completed, so no order was placed. Your cart is saved – please try again.";
      el.banner.hidden = false;
      history.replaceState(null, "", window.location.pathname);
    }
    if (!S.Cart.count()) {
      el.state.innerHTML = '<p>Your cart is empty.</p><a class="ga-btn" href="/products/">Shop products</a>';
      return;
    }
    restoreForm();
    Promise.all([sync(), loadMethodNames()])
      .then(function (results) {
        var c = results[0];
        prefillFromCart(c);
        render(c);
        if (addressReady()) onAddressChange();
      })
      .catch(function (err) {
        el.state.innerHTML =
          "<p>" + S.esc(err.message) + '</p><button type="button" class="ga-btn" data-ga-co-retry>Try again</button>';
      });

    el.form.addEventListener("submit", placeOrder);
    el.form.addEventListener("input", function (e) {
      var name = e.target.name;
      if (RULES[name] && e.target.getAttribute("aria-invalid") === "true") setFieldError(name, RULES[name](value(name)));
    });
    el.form.addEventListener("change", function (e) {
      var name = e.target.name;
      if (name === "kc_rate") return selectRate(e.target);
      if (name === "kc_method") return renderButton();
      if (["postcode", "state", "city"].indexOf(name) !== -1) onAddressChange();
      if (RULES[name]) setFieldError(name, RULES[name](value(name)));
      saveForm();
    });
    root.addEventListener("click", function (e) {
      if (e.target.closest("[data-ga-co-retry]")) window.location.reload();
    });

    // Editing the cart in the drawer updates the order summary.
    var cartTimer = null;
    S.Cart.onChange(function () {
      clearTimeout(cartTimer);
      cartTimer = setTimeout(function () {
        sync().then(function (c) {
          if (addressReady()) return updateCustomer().then(render);
          render(c);
        }, function (err) {
          showError(err.message);
        });
      }, 400);
    });
  }

  start();
})();
