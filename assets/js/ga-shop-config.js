/* ============================================================
   Kitchen Corner - shop settings
   The only file to touch when the WordPress store goes live.
   ============================================================ */
window.GA_SHOP_CONFIG = {
  // WordPress + WooCommerce address, no trailing slash, e.g.
  // "https://store.mykitchencorner.in". Leave empty to run on the
  // mock catalogue below (checkout is disabled in that mode).
  wpBase: "https://store.mykitchencorner.in",

  // true: full shop (prices, cart, checkout). false: product catalogue only -
  // no prices, cart or checkout, product pages say online ordering is coming
  // soon. Flip to true when the store is ready to take orders.
  ordering: true,

  // Size a product opens on when it comes in several sizes (must match the
  // size name in WooCommerce). Falls back to the first size in stock.
  preferredSize: "500 g",

  // Which product photo (1 = first) to show for each size when the size has
  // no image of its own in WooCommerce. Our studio photos are in the order
  // 500 g, 1 kg, 300 g, ingredients label. Sizes not listed show photo 1.
  // A size image set in WooCommerce (Variations -> image) always wins.
  sizePhotos: { "500 g": 1, "1 kg": 2 },

  // Mock catalogue in the WooCommerce Store API response shape.
  mockUrl: "/assets/data/products.json"
};
