# Deploying to Hostinger

The live web root is `public_html`, a **separate copy** of this repo's `public/`
folder. Pulling the code updates everything except `public_html`, so files under
`public/` reach the live site only when they are uploaded by hand.

## Every deploy

1. `php artisan down`, then pull or upload the code as usual.
2. `composer install --no-dev --optimize-autoloader`
3. Locally, from the exact commit being deployed: `npm ci && npm run build`.
4. Upload the **whole** `public/build` folder to `public_html/build`. It holds the
   CSS, the JavaScript **and the marketing site's images** (logo, share image).
   Old files in it can stay.
5. `php artisan migrate --force` if the release has migrations.
6. `php artisan optimize:clear` (re-cache config and routes afterwards if you normally do).
7. `php artisan up`.

## Check after deploying

- `https://hisabokitab.com/` shows the logo in the header and footer.
- The page source references `build/assets/...` for the CSS and the logo.
- `https://hisabokitab.com/robots.txt` lists `Disallow: /admin`.
- `https://hisabokitab.com/livewire/update` opened in a browser shows a short
  405 page, not Laravel's large error page. If it shows the error page, set
  `APP_DEBUG=false` and `APP_ENV=production` in `.env` and clear the config.

## Rules for code changes

- **Don't add new files to `public/`** that the site needs (images, robots, etc.).
  Put site images in `resources/images/site/` and reference them with
  `Vite::asset('resources/images/site/...')`; they then ship inside `public/build`.
  `MarketingSiteTest` fails if a site page uses an image from outside the build.
- `robots.txt` is a route (`routes/web.php`), not a file. A static
  `public_html/robots.txt` would win over the route, so there must not be one.
- Files already in `public/` and live on the server (favicons, `branding/icon*.png`,
  `branding/logo-horizontal*.png`, used by the app, emails and PDFs) stay where
  they are. If one of them ever changes, upload it by hand.
