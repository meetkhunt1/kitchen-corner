# Kitchen Corner

A from-scratch HTML / CSS / JS rebuild of mykitchencorner.in. No WordPress,
no Elementor, no Astra, no Swiper, no GSAP, no Lenis, no jQuery — every
style and interaction in here is hand-written. All new CSS classes and IDs
use the `ga-` prefix.

Deployed from the `main` branch to Hostinger.

## URLs

Extensionless, trailing-slash URLs — the same paths the WordPress site served,
so nothing that ranks today changes address.

| URL | File |
| --- | --- |
| `/` | `index.html` |
| `/why-choose-us/` | `why-choose-us/index.html` |
| `/our-founders-journey/` | `our-founders-journey/index.html` |
| `/products/` | `products/index.html` |
| `/contact-us/` | `contact-us/index.html` |
| `/privacy-policy/` | `privacy-policy/index.html` |
| `/terms-conditions/` | `terms-conditions/index.html` |
| 404 | `404.html` |

Every link and asset reference is root-relative (`/assets/…`), so pages work at
any depth. `.htaccess` handles the rest: HTTPS, the apex host, trailing slashes,
and 301s from the old `/page.html` and `/wp-content/uploads/…` paths.

## Structure

```
index.html               home
<slug>/index.html        one directory per page
404.html                 error page (noindex)
.htaccess                rewrites, redirects, compression, caching, headers
robots.txt               allows everything, points at the sitemap
sitemap.xml              all seven canonical URLs
site.webmanifest         icons, theme colour, install metadata
assets/
  css/
    ga-fonts.css     self-hosted @font-face (DM Sans, Cedarville Cursive, Roboto)
    ga-base.css      reset, tokens, header, nav, flyout, footer, floating IG button
    ga-home.css      home page
    ga-products.css  products page
    ga-why.css       why choose us
    ga-story.css     founder story (+ the carousel component)
    ga-contact.css   contact page and form
    ga-legal.css     privacy policy / terms / 404
  js/
    ga-main.js       smooth scroll, nav, flyout, IG button, ticker, tabs, anchors
    ga-journey.js    scroll-scrubbed "Harvested / Blended" animation (home)
    ga-carousel.js   looping slider with arrows, dots, autoplay, swipe
    ga-form.js       contact form counter + validation
  fonts/             woff2 / ttf font files
  media/             every image, SVG and video, in the original folder layout
sourcecode/          the original page sources (git-ignored, reference only)
```

## SEO

Titles, meta descriptions and Open Graph copy are carried over from the live
site unchanged. What this build adds on top:

- `rel=canonical` and `og:url` on every page, pointing at the apex HTTPS URL.
- Absolute `og:image` / `twitter:image` URLs (the relative ones social crawlers
  could not resolve), plus `twitter:title` and `twitter:description`.
- JSON-LD on every page: `Organization` + `WebSite`, a `WebPage`
  (`ContactPage` on `/contact-us/`) and a `BreadcrumbList` on inner pages.
- One `<h1>` per page. The pages previously had none, or two — the heading that
  reads as the page title was promoted, and the footer copyright line demoted
  out of the heading outline. The CSS selectors were widened to `:is(h1, h2)`
  so nothing moved visually.
- `robots.txt`, `sitemap.xml` and `site.webmanifest`.
- `decoding="async"` on every image, `loading="lazy"` on the below-fold product
  shots that were still loading eagerly. Every image already carried explicit
  `width`/`height`, so there is no layout shift.
- Gzip/Brotli, a one-year immutable cache on static assets, and
  `X-Content-Type-Options` / `Referrer-Policy` / `X-Frame-Options` /
  `Permissions-Policy` headers.

## Deliberate changes from the original

Three requested tweaks that intentionally differ from mykitchencorner.in:

1. **One page gutter.** The original uses a different left/right inset per
   section (0, 10, 20, 50 and 100px at desktop). Everything here now aligns to
   a single `--ga-gutter` declared in `ga-base.css` — **50px** desktop, **30px**
   tablet, **15px** mobile. Change that one value to re-space the whole site.
   Full-width bands (the green "Inside Homes" bar, the panning collage strip
   and the marquee) stay edge-to-edge by design.
2. **Dark header on inner pages.** Home and Products sit on dark hero imagery
   and keep the white logo. The other five pages sit on a light background, so
   they get `ga-header--dark`: the dark KC logo plus dark nav text and border.
3. **No hover zoom.** The product-card image zoom (home + products) and the
   printed black/patterned drop-shadow behind "Explore All Products" are gone;
   the button now just darkens on hover.

## Notes

- **Breakpoints** match the original: `>1024px` desktop, `768–1024px` tablet,
  `<=767px` mobile, plus the `921px` root-font step and the `600px` step the
  original uses for two artwork rules.
- **Disabled sections.** The original ships a few sections hidden on every
  breakpoint (the home "Story Behind The Flavor" band, the 2019–2025 timeline
  tabs, the customer-reviews carousel, the Why Choose Us benefits grid, and
  five product cards). They are reproduced here with `ga-hide-all`, fully
  styled and wired up — remove that class to switch any of them on.
- **Contact form.** The original posts to WordPress. There is no back end
  here, so `assets/js/ga-form.js` validates, counts characters and confirms
  locally. Set `GA_FORM_ENDPOINT` at the top of that file to a real URL and it
  will POST the payload as JSON instead.
- **Smooth scrolling** is disabled for touch devices and when the visitor has
  `prefers-reduced-motion` set.

## Running it locally

```
python -m http.server 8777
```

then open <http://127.0.0.1:8777/>. The directory-per-page layout means pretty
URLs work in any static server without extra config; `.htaccess` only matters
on Apache/Hostinger.

## Deploying

The `main` branch is the document root. Upload the repository contents (minus
`sourcecode/`) to `public_html/` on Hostinger, keeping `.htaccess` at the root.
