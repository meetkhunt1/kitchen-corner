/* ============================================================
   Kitchen Corner - shop
   Products come from the WooCommerce Store API (or the mock file
   until WordPress is connected). The cart lives in localStorage and
   is handed over to WooCommerce's own checkout page.
   Hand-written; no external libraries.
   ============================================================ */
(function () {
  "use strict";

  var CONFIG = window.GA_SHOP_CONFIG || {};
  var WP_BASE = (CONFIG.wpBase || "").replace(/\/+$/, "");
  var LIVE = !!WP_BASE;
  var CART_KEY = "ga-cart-v1";
  // Served by wordpress/kc-cart-handoff.php; admin-post.php is never page-cached.
  var HANDOFF_URL = "/wp-admin/admin-post.php?action=kc_cart&items=";

  var ready = function (fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  };

  var ARROW_SVG =
    '<svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="0.5" y="0.5" width="31" height="31" rx="15.5" stroke="black"></rect><path d="M14 12L18.1464 16.1464C18.3417 16.3417 18.3417 16.6583 18.1464 16.8536L14 21" stroke="black" stroke-linecap="round"></path></svg>';
  var CLOSE_SVG =
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" fill="none"></path></svg>';

  /* ----------------------------------------------------------
     1. Helpers
     ---------------------------------------------------------- */
  var decoder = document.createElement("textarea");

  // Store API names can carry HTML entities (&#8217;); decode, then escape.
  function text(s) {
    decoder.innerHTML = s == null ? "" : String(s);
    return decoder.value;
  }
  function esc(s) {
    return text(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function stripTags(html) {
    var d = document.createElement("div");
    d.innerHTML = html || "";
    return (d.textContent || "").replace(/\s+/g, " ").trim();
  }

  // Store API prices are integer strings in minor units ("9900" = 99.00).
  function currencyOf(prices) {
    prices = prices || {};
    return {
      prefix: prices.currency_prefix != null ? prices.currency_prefix : "₹",
      suffix: prices.currency_suffix || "",
      unit: prices.currency_minor_unit != null ? prices.currency_minor_unit : 2
    };
  }
  function money(minor, cur) {
    var value = Number(minor || 0) / Math.pow(10, cur.unit);
    var whole = value % 1 === 0;
    return (
      cur.prefix +
      value.toLocaleString("en-IN", {
        minimumFractionDigits: whole ? 0 : cur.unit,
        maximumFractionDigits: cur.unit
      }) +
      cur.suffix
    );
  }
  function priceHTML(p) {
    var cur = currencyOf(p.prices);
    var now = Number(p.prices.price);
    var was = Number(p.prices.regular_price);
    if (was > now) {
      return (
        '<span class="ga-price"><del>' + money(was, cur) + "</del> <ins>" +
        money(now, cur) + "</ins></span>"
      );
    }
    return '<span class="ga-price">' + money(now, cur) + "</span>";
  }

  // Pretty /product/<slug>/ URLs rely on .htaccess; a plain local static
  // server can't rewrite, so fall back to ?slug= there.
  var LOCAL = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(window.location.hostname);
  function productUrl(p) {
    var slug = encodeURIComponent(p.slug);
    return LOCAL ? "/product/?slug=" + slug : "/product/" + slug + "/";
  }
  // Only simple products can be bought from the storefront for now.
  function buyable(p) {
    return !!(p && p.is_purchasable && p.is_in_stock && (p.type || "simple") === "simple");
  }
  function maxQty(p) {
    var m = p && p.add_to_cart && p.add_to_cart.maximum;
    return m > 0 ? Math.min(m, 99) : 99;
  }
  function clampQty(v, max) {
    v = parseInt(v, 10);
    if (isNaN(v) || v < 1) v = 1;
    return Math.min(v, max || 99);
  }

  /* ----------------------------------------------------------
     2. Catalogue (Store API or mock)
     ---------------------------------------------------------- */
  var Store = (function () {
    var request = null;
    var list = [];

    function getJSON(url) {
      return fetch(url, { headers: { Accept: "application/json" } }).then(function (r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      });
    }

    function products() {
      if (!request) {
        var url = LIVE
          ? WP_BASE + "/wp-json/wc/store/v1/products?per_page=100&orderby=menu_order&order=asc"
          : CONFIG.mockUrl || "/assets/data/products.json";
        request = getJSON(url).then(
          function (data) {
            list = Array.isArray(data) ? data : [];
            return list;
          },
          function (err) {
            request = null; // allow a retry
            throw err;
          }
        );
      }
      return request;
    }

    function byId(id) {
      id = Number(id);
      for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i];
      return null;
    }

    function bySlug(slug) {
      for (var i = 0; i < list.length; i++) if (list[i].slug === slug) return list[i];
      return null;
    }

    return { products: products, byId: byId, bySlug: bySlug };
  })();

  /* ----------------------------------------------------------
     3. Cart (localStorage, synced across tabs)
     ---------------------------------------------------------- */
  var Cart = (function () {
    var items = load();
    var listeners = [];

    function load() {
      try {
        var data = JSON.parse(localStorage.getItem(CART_KEY) || "[]");
        return Array.isArray(data) ? data : [];
      } catch (e) {
        return [];
      }
    }
    function emit() {
      listeners.forEach(function (fn) {
        fn(items);
      });
    }
    function save() {
      try {
        localStorage.setItem(CART_KEY, JSON.stringify(items));
      } catch (e) {}
      emit();
    }
    function find(id) {
      for (var i = 0; i < items.length; i++) if (items[i].id === id) return items[i];
      return null;
    }
    function snapshot(p, item) {
      var img = (p.images && p.images[0]) || {};
      item.id = p.id;
      item.slug = p.slug;
      item.name = text(p.name);
      item.price = p.prices.price;
      item.currency = currencyOf(p.prices);
      item.image = img.thumbnail || img.src || "";
      item.max = maxQty(p);
      item.available = buyable(p);
      item.qty = clampQty(item.qty, item.max);
      return item;
    }

    window.addEventListener("storage", function (e) {
      if (e.key === CART_KEY) {
        items = load();
        emit();
      }
    });

    return {
      items: function () {
        return items;
      },
      onChange: function (fn) {
        listeners.push(fn);
      },
      add: function (p, qty) {
        var item = find(p.id);
        if (item) item.qty += qty;
        else items.push((item = { qty: qty }));
        snapshot(p, item);
        save();
      },
      setQty: function (id, qty) {
        var item = find(id);
        if (!item) return;
        item.qty = clampQty(qty, item.max);
        save();
      },
      remove: function (id) {
        items = items.filter(function (i) {
          return i.id !== id;
        });
        save();
      },
      clear: function () {
        items = [];
        save();
      },
      // Refresh names, prices and stock from the latest catalogue;
      // drop products that no longer exist.
      refresh: function (products) {
        if (!items.length) return;
        var byId = {};
        products.forEach(function (p) {
          byId[p.id] = p;
        });
        items = items.filter(function (i) {
          return byId[i.id];
        });
        items.forEach(function (i) {
          snapshot(byId[i.id], i);
        });
        save();
      },
      count: function () {
        return items.reduce(function (n, i) {
          return n + (i.available ? i.qty : 0);
        }, 0);
      },
      subtotal: function () {
        return items.reduce(function (n, i) {
          return n + (i.available ? Number(i.price) * i.qty : 0);
        }, 0);
      }
    };
  })();

  /* ----------------------------------------------------------
     4. Cart drawer
     ---------------------------------------------------------- */
  var Drawer = (function () {
    var root, body, foot, subtotal, msg, opener;

    function build() {
      var overlay = document.createElement("div");
      overlay.className = "ga-cart-overlay";
      overlay.setAttribute("data-ga-cart-close", "");

      root = document.createElement("aside");
      root.className = "ga-cart";
      root.id = "ga-cart";
      root.setAttribute("role", "dialog");
      root.setAttribute("aria-modal", "true");
      root.setAttribute("aria-labelledby", "ga-cart-title");
      root.setAttribute("aria-hidden", "true");
      root.innerHTML =
        '<div class="ga-cart-head">' +
          '<h2 id="ga-cart-title">Your Cart</h2>' +
          '<button type="button" class="ga-cart-x" data-ga-cart-close aria-label="Close cart">' + CLOSE_SVG + "</button>" +
        "</div>" +
        '<div class="ga-cart-body"></div>' +
        '<div class="ga-cart-foot">' +
          '<div class="ga-cart-sub"><span>Subtotal</span><strong></strong></div>' +
          '<p class="ga-cart-note">Shipping and taxes are calculated at checkout.</p>' +
          '<button type="button" class="ga-btn ga-cart-checkout" data-ga-checkout>Checkout</button>' +
          '<p class="ga-cart-msg" role="status" hidden></p>' +
        "</div>";

      document.body.appendChild(overlay);
      document.body.appendChild(root);
      body = root.querySelector(".ga-cart-body");
      foot = root.querySelector(".ga-cart-foot");
      subtotal = root.querySelector(".ga-cart-sub strong");
      msg = root.querySelector(".ga-cart-msg");

      // Keep the page's smooth scroller from moving the page behind.
      overlay.addEventListener("wheel", function (e) {
        e.preventDefault();
        e.stopPropagation();
      }, { passive: false });
      root.addEventListener("wheel", function (e) {
        e.stopPropagation();
      }, { passive: true });
    }

    function render() {
      var items = Cart.items();
      var count = Cart.count();

      Array.prototype.forEach.call(document.querySelectorAll("[data-ga-cart-count]"), function (el) {
        el.textContent = count;
        el.hidden = count === 0;
      });
      Array.prototype.forEach.call(document.querySelectorAll("[data-ga-cart-open]"), function (el) {
        el.setAttribute("aria-label", "Cart, " + count + (count === 1 ? " item" : " items"));
      });
      if (!root) return;

      if (!items.length) {
        body.innerHTML =
          '<div class="ga-cart-empty">' +
            "<p>Your cart is empty.</p>" +
            '<a class="ga-btn" href="/products/">Shop products</a>' +
          "</div>";
        foot.hidden = true;
        return;
      }

      foot.hidden = false;
      var cur = items[0].currency;
      subtotal.textContent = money(Cart.subtotal(), cur);
      root.querySelector(".ga-cart-checkout").disabled = Cart.count() === 0;

      body.innerHTML =
        '<ul class="ga-cart-items">' +
        items
          .map(function (i) {
            var url = productUrl(i);
            return (
              '<li class="ga-cart-item' + (i.available ? "" : " ga-is-oos") + '" data-id="' + i.id + '">' +
                '<a class="ga-cart-thumb" href="' + url + '">' +
                  (i.image ? '<img src="' + esc(i.image) + '" alt="" width="200" height="300" loading="lazy" decoding="async">' : "") +
                "</a>" +
                '<div class="ga-cart-info">' +
                  '<a class="ga-cart-name" href="' + url + '">' + esc(i.name) + "</a>" +
                  '<span class="ga-cart-unit">' + money(i.price, i.currency) + "</span>" +
                  (i.available
                    ? '<div class="ga-qty ga-qty--sm">' +
                        '<button type="button" data-ga-qty="-1" aria-label="Decrease quantity"' + (i.qty <= 1 ? " disabled" : "") + ">&minus;</button>" +
                        '<input type="number" inputmode="numeric" min="1" max="' + i.max + '" value="' + i.qty + '" aria-label="Quantity for ' + esc(i.name) + '">' +
                        '<button type="button" data-ga-qty="1" aria-label="Increase quantity"' + (i.qty >= i.max ? " disabled" : "") + ">+</button>" +
                      "</div>"
                    : '<span class="ga-cart-oos">Out of stock</span>') +
                "</div>" +
                '<div class="ga-cart-line">' +
                  "<strong>" + (i.available ? money(Number(i.price) * i.qty, i.currency) : "") + "</strong>" +
                  '<button type="button" class="ga-cart-remove" data-ga-remove>Remove</button>' +
                "</div>" +
              "</li>"
            );
          })
          .join("") +
        "</ul>";
    }

    function open(from) {
      if (!root) return;
      opener = from || document.activeElement;
      msg.hidden = true;
      document.body.classList.add("ga-cart-open");
      root.setAttribute("aria-hidden", "false");
      setExpanded(true);
      root.querySelector(".ga-cart-x").focus();
    }
    function close() {
      if (!root || !document.body.classList.contains("ga-cart-open")) return;
      document.body.classList.remove("ga-cart-open");
      root.setAttribute("aria-hidden", "true");
      setExpanded(false);
      if (opener && opener.focus) opener.focus();
    }
    function setExpanded(on) {
      Array.prototype.forEach.call(document.querySelectorAll("[data-ga-cart-open]"), function (el) {
        el.setAttribute("aria-expanded", on ? "true" : "false");
      });
    }

    function checkout() {
      var lines = Cart.items()
        .filter(function (i) {
          return i.available;
        })
        .map(function (i) {
          return i.id + ":" + i.qty;
        });
      if (!lines.length) return;
      if (!LIVE) {
        msg.textContent = "Checkout opens once the online store is connected.";
        msg.hidden = false;
        return;
      }
      window.location.href = WP_BASE + HANDOFF_URL + encodeURIComponent(lines.join(","));
    }

    function bind() {
      document.addEventListener("click", function (e) {
        var t = e.target;
        var opn = t.closest("[data-ga-cart-open]");
        if (opn) {
          e.preventDefault();
          open(opn);
          return;
        }
        if (t.closest("[data-ga-cart-close]")) {
          close();
          return;
        }
        if (t.closest("[data-ga-checkout]")) {
          checkout();
          return;
        }
        var row = t.closest(".ga-cart-item");
        if (!row) return;
        var id = Number(row.getAttribute("data-id"));
        if (t.closest("[data-ga-remove]")) {
          Cart.remove(id);
          var next = root.querySelector(".ga-cart-remove, .ga-cart-empty a");
          if (next) next.focus();
          return;
        }
        var step = t.closest("[data-ga-qty]");
        if (step) {
          var input = row.querySelector("input");
          Cart.setQty(id, Number(input.value) + Number(step.getAttribute("data-ga-qty")));
          var again = root.querySelector('.ga-cart-item[data-id="' + id + '"] [data-ga-qty="' + step.getAttribute("data-ga-qty") + '"]');
          if (again && !again.disabled) again.focus();
        }
      });
      document.addEventListener("change", function (e) {
        var row = e.target.closest && e.target.closest(".ga-cart-item");
        if (row && e.target.tagName === "INPUT") {
          Cart.setQty(Number(row.getAttribute("data-id")), e.target.value);
        }
      });
      document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") close();
        if (e.key === "Tab" && document.body.classList.contains("ga-cart-open")) trapFocus(e);
      });
    }

    function trapFocus(e) {
      var f = root.querySelectorAll("a[href], button:not([disabled]), input");
      if (!f.length) return;
      var first = f[0];
      var last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      } else if (!root.contains(document.activeElement)) {
        e.preventDefault();
        first.focus();
      }
    }

    return {
      init: function () {
        build();
        bind();
        render();
        Cart.onChange(render);
      },
      open: open
    };
  })();

  /* ----------------------------------------------------------
     5. Add-to-cart buttons (grid and product page)
     ---------------------------------------------------------- */
  function initAddButtons() {
    document.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-ga-add]");
      if (!btn || btn.disabled) return;
      var p = Store.byId(btn.getAttribute("data-ga-add"));
      if (!buyable(p)) return;
      var qtyInput = document.querySelector(btn.getAttribute("data-ga-qty-from") || "#ga-none");
      var qty = qtyInput ? clampQty(qtyInput.value, maxQty(p)) : 1;
      Cart.add(p, qty);
      var label = btn.textContent;
      btn.textContent = "Added";
      btn.classList.add("ga-is-added");
      setTimeout(function () {
        btn.textContent = label;
        btn.classList.remove("ga-is-added");
      }, 1400);
      Drawer.open(btn);
    });
  }

  /* ----------------------------------------------------------
     6. Product cards
     ---------------------------------------------------------- */
  function cardHTML(p, i) {
    var img = (p.images && p.images[0]) || {};
    var url = productUrl(p);
    var ok = buyable(p);
    var cls = "ga-pcard" + (p.is_in_stock ? "" : " ga-is-oos");
    var badge = !p.is_in_stock
      ? '<span class="ga-badge ga-badge--muted">Out of stock</span>'
      : p.on_sale
        ? '<span class="ga-badge">Sale</span>'
        : "";
    return (
      '<article class="' + cls + '">' +
        '<a class="ga-pcard-link" href="' + url + '">' +
          '<div class="ga-pcard-media">' + badge +
            (img.src
              ? '<img src="' + esc(img.src) + '"' +
                (img.srcset ? ' srcset="' + esc(img.srcset) + '" sizes="(max-width: 767px) 50vw, 25vw"' : "") +
                ' alt="' + esc(img.alt || p.name) + '" width="683" height="1024" decoding="async"' +
                (i > 3 ? ' loading="lazy"' : "") + ">"
              : "") +
          "</div>" +
          '<div class="ga-pcard-foot">' +
            "<h2>" + esc(p.name) + "</h2>" +
            '<div class="ga-pcard-icon"><span class="ga-icon">' + ARROW_SVG + "</span></div>" +
          "</div>" +
        "</a>" +
        '<div class="ga-pcard-buy">' +
          priceHTML(p) +
          '<button type="button" class="ga-pcard-add" data-ga-add="' + p.id + '"' +
            (ok ? ' aria-label="Add ' + esc(p.name) + ' to cart"' : " disabled") + ">" +
            (ok ? "Add to cart" : p.is_in_stock ? "Unavailable" : "Out of stock") +
          "</button>" +
        "</div>" +
      "</article>"
    );
  }

  function skeletonHTML(n) {
    var one =
      '<div class="ga-pcard ga-pcard--skel" aria-hidden="true">' +
        '<div class="ga-pcard-media"></div>' +
        '<div class="ga-skel-line"></div><div class="ga-skel-line ga-skel-line--short"></div>' +
      "</div>";
    return new Array(n + 1).join(one);
  }

  function stateHTML(message, retry) {
    return (
      '<div class="ga-shop-state">' +
        "<p>" + message + "</p>" +
        (retry ? '<button type="button" class="ga-btn" data-ga-retry>Try again</button>' : "") +
      "</div>"
    );
  }

  /* ----------------------------------------------------------
     7. Products page grid
     ---------------------------------------------------------- */
  function initGrid() {
    var grid = document.querySelector("[data-ga-products]");
    if (!grid) return;

    function load() {
      grid.setAttribute("aria-busy", "true");
      grid.innerHTML = skeletonHTML(8);
      Store.products().then(
        function (list) {
          Cart.refresh(list);
          grid.setAttribute("aria-busy", "false");
          grid.innerHTML = list.length
            ? list.map(cardHTML).join("")
            : stateHTML("New products are on their way. Check back soon.");
        },
        function () {
          grid.setAttribute("aria-busy", "false");
          grid.innerHTML = stateHTML("We couldn’t load the products just now.", true);
        }
      );
    }

    grid.addEventListener("click", function (e) {
      if (e.target.closest("[data-ga-retry]")) load();
    });
    load();
  }

  /* ----------------------------------------------------------
     8. Product detail page (/product/<slug>/)
     ---------------------------------------------------------- */
  function slugFromUrl() {
    var m = window.location.pathname.match(/^\/product\/([^\/]+)\/?$/);
    if (m && m[1] !== "index.html") return decodeURIComponent(m[1]);
    var q = new URLSearchParams(window.location.search).get("slug");
    return q || "";
  }

  function setMeta(p) {
    var title = text(p.name) + " | Kitchen Corner";
    var desc = stripTags(p.short_description || p.description).slice(0, 160);
    var url = "https://mykitchencorner.in/product/" + encodeURIComponent(p.slug) + "/";
    var img = (p.images && p.images[0] && p.images[0].src) || "";
    if (img && img.charAt(0) === "/") img = "https://mykitchencorner.in" + img;

    document.title = title;
    var set = function (sel, attr, val) {
      var el = document.querySelector(sel);
      if (el && val) el.setAttribute(attr, val);
    };
    set('meta[name="description"]', "content", desc);
    set('link[rel="canonical"]', "href", url);
    set('meta[property="og:title"]', "content", title);
    set('meta[property="og:description"]', "content", desc);
    set('meta[property="og:url"]', "content", url);
    set('meta[property="og:image"]', "content", img);

    var cur = currencyOf(p.prices);
    var ld = document.createElement("script");
    ld.type = "application/ld+json";
    ld.textContent = JSON.stringify({
      "@context": "https://schema.org",
      "@type": "Product",
      name: text(p.name),
      description: desc,
      image: img || undefined,
      sku: p.sku || undefined,
      url: url,
      brand: { "@type": "Brand", name: "Kitchen Corner" },
      offers: {
        "@type": "Offer",
        url: url,
        priceCurrency: p.prices.currency_code || "INR",
        price: (Number(p.prices.price) / Math.pow(10, cur.unit)).toFixed(cur.unit),
        availability: p.is_in_stock ? "https://schema.org/InStock" : "https://schema.org/OutOfStock"
      }
    });
    document.head.appendChild(ld);
  }

  function productHTML(p) {
    var imgs = p.images && p.images.length ? p.images : [{}];
    var ok = buyable(p);
    var cat = p.categories && p.categories[0];
    var stock = p.is_in_stock
      ? '<p class="ga-pdp-stock">In stock</p>'
      : '<p class="ga-pdp-stock ga-is-oos">Out of stock</p>';

    var gallery =
      '<div class="ga-pdp-gallery">' +
        '<div class="ga-pdp-main">' +
          (p.on_sale && p.is_in_stock ? '<span class="ga-badge">Sale</span>' : "") +
          (imgs[0].src
            ? '<img src="' + esc(imgs[0].src) + '"' +
              (imgs[0].srcset ? ' srcset="' + esc(imgs[0].srcset) + '" sizes="(max-width: 767px) 100vw, 50vw"' : "") +
              ' alt="' + esc(imgs[0].alt || p.name) + '" width="683" height="1024" fetchpriority="high" decoding="async">'
            : "") +
        "</div>" +
        (imgs.length > 1
          ? '<div class="ga-pdp-thumbs" role="list">' +
            imgs
              .map(function (img, n) {
                return (
                  '<button type="button" role="listitem" class="ga-pdp-thumb' + (n === 0 ? " ga-is-active" : "") +
                  '" data-ga-thumb="' + n + '" aria-label="Show image ' + (n + 1) + '"' + (n === 0 ? ' aria-current="true"' : "") + ">" +
                    '<img src="' + esc(img.thumbnail || img.src) + '" alt="" width="200" height="300" loading="lazy" decoding="async">' +
                  "</button>"
                );
              })
              .join("") +
            "</div>"
          : "") +
      "</div>";

    var buy = ok
      ? '<div class="ga-pdp-buy">' +
          '<div class="ga-qty">' +
            '<button type="button" data-ga-pdp-step="-1" aria-label="Decrease quantity">&minus;</button>' +
            '<input id="ga-pdp-qty" type="number" inputmode="numeric" min="1" max="' + maxQty(p) + '" value="1" aria-label="Quantity">' +
            '<button type="button" data-ga-pdp-step="1" aria-label="Increase quantity">+</button>' +
          "</div>" +
          '<button type="button" class="ga-btn ga-pdp-add" data-ga-add="' + p.id + '" data-ga-qty-from="#ga-pdp-qty">Add to cart</button>' +
        "</div>"
      : '<div class="ga-pdp-buy"><button type="button" class="ga-btn ga-pdp-add" disabled>' +
          (p.is_in_stock ? "Unavailable" : "Out of stock") + "</button></div>";

    return (
      '<nav class="ga-crumbs" aria-label="Breadcrumb">' +
        '<a href="/">Home</a><span aria-hidden="true">/</span>' +
        '<a href="/products/">Products</a><span aria-hidden="true">/</span>' +
        '<span aria-current="page">' + esc(p.name) + "</span>" +
      "</nav>" +
      '<div class="ga-pdp-grid">' +
        gallery +
        '<div class="ga-pdp-info">' +
          (cat ? '<p class="ga-pdp-cat">' + esc(cat.name) + "</p>" : "") +
          '<h1 class="ga-pdp-title">' + esc(p.name) + "</h1>" +
          '<div class="ga-pdp-price">' + priceHTML(p) + "</div>" +
          stock +
          (p.short_description ? '<div class="ga-pdp-short">' + p.short_description + "</div>" : "") +
          buy +
          (p.description
            ? '<div class="ga-pdp-desc"><h2>Details</h2>' + p.description + "</div>"
            : "") +
        "</div>" +
      "</div>"
    );
  }

  function bindGallery(root, p) {
    var main = root.querySelector(".ga-pdp-main img");
    root.addEventListener("click", function (e) {
      var th = e.target.closest("[data-ga-thumb]");
      if (th && main) {
        var img = p.images[Number(th.getAttribute("data-ga-thumb"))];
        main.src = img.src;
        if (img.srcset) main.srcset = img.srcset;
        else main.removeAttribute("srcset");
        main.alt = text(img.alt || p.name);
        Array.prototype.forEach.call(root.querySelectorAll("[data-ga-thumb]"), function (b) {
          var on = b === th;
          b.classList.toggle("ga-is-active", on);
          if (on) b.setAttribute("aria-current", "true");
          else b.removeAttribute("aria-current");
        });
      }
      var step = e.target.closest("[data-ga-pdp-step]");
      if (step) {
        var input = root.querySelector("#ga-pdp-qty");
        input.value = clampQty(Number(input.value) + Number(step.getAttribute("data-ga-pdp-step")), maxQty(p));
      }
    });
    var qty = root.querySelector("#ga-pdp-qty");
    if (qty) {
      qty.addEventListener("change", function () {
        qty.value = clampQty(qty.value, maxQty(p));
      });
    }
  }

  function related(p, list) {
    var cat = p.categories && p.categories[0] && p.categories[0].id;
    var others = list.filter(function (x) {
      return x.id !== p.id;
    });
    var same = others.filter(function (x) {
      return cat && x.categories && x.categories.some(function (c) { return c.id === cat; });
    });
    var rest = others.filter(function (x) {
      return same.indexOf(x) === -1;
    });
    return same.concat(rest).slice(0, 4);
  }

  function initProduct() {
    var root = document.querySelector("[data-ga-product]");
    if (!root) return;
    var relatedSec = document.querySelector("[data-ga-related]");
    var slug = slugFromUrl();

    function notFound() {
      var robots = document.querySelector('meta[name="robots"]');
      if (robots) robots.setAttribute("content", "noindex, follow");
      document.title = "Product not found | Kitchen Corner";
      root.innerHTML =
        '<div class="ga-shop-state">' +
          '<h1 class="ga-pdp-title">Product not found</h1>' +
          "<p>This product may have moved or is no longer available.</p>" +
          '<a class="ga-btn" href="/products/">See all products</a>' +
        "</div>";
    }

    function load() {
      root.setAttribute("aria-busy", "true");
      Store.products().then(
        function (list) {
          Cart.refresh(list);
          root.setAttribute("aria-busy", "false");
          var p = slug && Store.bySlug(slug);
          if (!p) return notFound();
          root.innerHTML = productHTML(p);
          bindGallery(root, p);
          setMeta(p);
          var rel = related(p, list);
          if (relatedSec && rel.length) {
            relatedSec.querySelector(".ga-pgrid").innerHTML = rel.map(cardHTML).join("");
            relatedSec.hidden = false;
          }
        },
        function () {
          root.setAttribute("aria-busy", "false");
          root.innerHTML = stateHTML("We couldn’t load this product just now.", true);
        }
      );
    }

    root.addEventListener("click", function (e) {
      if (e.target.closest("[data-ga-retry]")) load();
    });
    load();
  }

  /* ----------------------------------------------------------
     9. Return from WooCommerce after a completed order
     ---------------------------------------------------------- */
  function initOrderReturn() {
    var params = new URLSearchParams(window.location.search);
    if (params.get("order") !== "placed") return;
    Cart.clear();
    params.delete("order");
    var qs = params.toString();
    history.replaceState(null, "", window.location.pathname + (qs ? "?" + qs : "") + window.location.hash);
  }

  /* ---------------------------------------------------------- */
  ready(function () {
    initOrderReturn();
    Drawer.init();
    initAddButtons();
    initGrid();
    initProduct();
  });

  window.GaShop = { Store: Store, Cart: Cart, open: Drawer.open };
})();
