// Screenshots every screen at laptop and phone width and records measurements.
// Usage: node capture.cjs <label> [--only=substring] See README.md.
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const BASE = process.env.UI_SHOTS_BASE || 'http://127.0.0.1:8765';
const CHROME = process.env.UI_SHOTS_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const label = process.argv[2];
const only = (process.argv.find((a) => a.startsWith('--only=')) || '').slice(7);
if (!label) {
  console.error('Usage: node capture.cjs <label> [--only=substring]');
  process.exit(1);
}

const WIDTHS = [[1366, 768], [390, 844]];
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

const PAGES = {
  guest: {
    'site-home': '/', 'site-features': '/features', 'site-pricing': '/pricing', 'site-about': '/about', 'site-contact': '/contact',
    signup: '/signup', login: '/login', 'forgot-password': '/forgot-password',
  },
  owner: {
    dashboard: '/dashboard', products: '/products', 'product-show': '/products/1', 'product-variants': '/products/8?tab=variants', sales: '/sales', 'sales-history': '/sales/history', 'sale-show': '/sales/1', 'sale-edit': '/sales/1/edit',
    'stock-in': '/stock-ins', 'stock-history': '/stock-ins/history', 'stock-expiry': '/stock-ins/expiry', 'stock-writeoffs': '/stock-ins/write-offs',
    po: '/purchase-orders', 'po-create': '/purchase-orders/create', 'po-show': '/purchase-orders/1', 'po-receive': '/purchase-orders/1/receive',
    invoices: '/purchase-orders/invoices', 'invoice-create': '/purchase-orders/invoices/create', receipts: '/purchase-orders/receipts',
    vendors: '/vendors', 'vendor-show': '/vendors/1', customers: '/customers', 'customer-show': '/customers/1',
    udhaar: '/udhaar', 'udhaar-show': '/udhaar/2', 'party-ledger': '/party-ledger', expenses: '/expenses',
    reports: '/reports', 'reports-screens': '/reports/screens', profit: '/gross-profit',
    'ledger-tb': '/ledger/trial-balance', 'ledger-pl': '/ledger/profit-and-loss', 'ledger-bs': '/ledger/balance-sheet', 'ledger-cash': '/ledger/cash-book', 'ledger-health': '/ledger/health', 'ledger-owner': '/ledger/owner-money',
    'payroll-emp': '/payroll/employees', 'payroll-run': '/payroll/run', 'payroll-adv': '/payroll/advances', 'payroll-hist': '/payroll/history', 'payroll-ded': '/payroll/deduction-types',
    'sim-sale': '/sim-sales', 'sim-history': '/sim-sales/history', 'balance-load': '/balance-loads', 'balance-history': '/balance-loads/history',
    'wallet-load': '/wallet-loads', 'wallet-history': '/wallet-loads/history', bills: '/bills', 'bills-history': '/bills/history',
    nadra: '/nadra-verifications', 'nadra-history': '/nadra-verifications/history', repairs: '/repairs', 'repairs-history': '/repairs/history',
    services: '/services', 'services-history': '/services/history',
    'wa-orders': '/whatsapp-orders', 'online-orders': '/online-orders', messages: '/customer-messages',
    marketing: '/marketing', 'marketing-posts': '/marketing/posts', 'marketing-reviews': '/marketing/reviews', 'marketing-ads': '/marketing/ads', 'marketing-qr': '/marketing/qr',
    team: '/users', branches: '/branches', settings: '/settings', billing: '/billing', profile: '/profile', welcome: '/welcome',
    'wa-order': '/whatsapp-orders/1', 'online-order': '/online-orders/1',
    support: '/support', 'support-new': '/support/new?from=%2Fsales%2Fhistory&screen=Sales%20History', 'support-ticket': '/support/1',
    ...Object.fromEntries(['branding', 'shop-accounts', 'accessory-categories', 'brands', 'networks', 'percentage', 'bills', 'services', 'deduction-types', 'roles', 'modules', 'integrations', 'profile']
      .map((tab) => ['settings-' + tab, '/settings?tab=' + tab])),
  },
  cashier: {
    'cashier-welcome': '/welcome', 'cashier-sales': '/sales', 'cashier-sales-history': '/sales/history', 'cashier-products': '/products', 'cashier-customers': '/customers', 'cashier-udhaar': '/udhaar', 'cashier-balance-load': '/balance-loads', 'cashier-support': '/support',
  },
  admin: {
    'admin-shops': '/admin', 'admin-shop': '/admin/shops/1', 'admin-plans': '/admin/plans', 'admin-addons': '/admin/addons', 'admin-payments': '/admin/payments', 'admin-integrations': '/admin/integrations', 'admin-backups': '/admin/backups', 'admin-support': '/admin/support', 'admin-ticket': '/admin/support/1',
  },
};

const LOGINS = { owner: 'shop@example.com', cashier: 'cashier@example.com', admin: 'admin@example.com' };

// What a screen reports for comparison between stages.
const measure = () => {
  const scope = document.querySelector('main') || document.body;
  const clipped = [...scope.querySelectorAll('div.overflow-x-auto, [data-table-scroll]')]
    .filter((d) => d.offsetParent && d.querySelector('table') && d.scrollWidth > d.clientWidth + 2)
    .map((w) => {
      const box = w.getBoundingClientRect();
      const hidden = [...w.querySelectorAll('th')].filter((t) => t.getBoundingClientRect().right > box.right + 2).map((t) => t.innerText.trim() || '(actions)');
      return { over: w.scrollWidth - w.clientWidth, hidden };
    });
  const sidebarNav = document.querySelector('.sb-scroll');
  return {
    title: document.querySelector('h1')?.innerText?.trim() || null,
    pageHeight: document.documentElement.scrollHeight,
    docOverflow: document.documentElement.scrollWidth - window.innerWidth,
    clipped,
    sidebarLinks: document.querySelectorAll('aside a[data-nav-item]').length,
    sidebarScroll: sidebarNav ? { content: sidebarNav.scrollHeight, visible: sidebarNav.clientHeight } : null,
  };
};

async function login(context, email) {
  // The tab that submits the login stops taking input in headless Chrome, so
  // sign in on a throwaway tab and work in a fresh one.
  const page = await context.newPage();
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
  await page.type('input[type=email], input#email', email);
  await page.type('input[type=password]', 'password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {}), page.keyboard.press('Enter')]);
  await wait(1200);
  await page.close();
}

async function captureStates(page, out, results, width) {
  const clickByText = (text) => page.evaluate((t) => {
    const button = [...document.querySelectorAll('main button')].find((x) => x.innerText.trim() === t && x.offsetParent);
    if (button) button.click();
    return !!button;
  }, text);

  const shot = async (name) => {
    await page.screenshot({ path: path.join(out, name + '.png') });
    results[`${name}@${width}`] = { state: true };
  };

  await page.goto(BASE + '/products', { waitUntil: 'networkidle2' });
  if (await clickByText('Add Product')) { await wait(1200); await shot('state-product-form'); }
  await page.goto(BASE + '/products', { waitUntil: 'networkidle2' });
  if (await clickByText('Add Variant Group')) { await wait(1200); await shot('state-variant-group'); }

  await page.goto(BASE + '/sales', { waitUntil: 'networkidle2' });
  const search = await page.$('main input[type=search]');
  if (search) {
    await search.type('a');
    await wait(1200);
    await shot('state-sales-search');
    const add = () => page.evaluate(() => { const b = document.querySelector('[wire\\:click^="addToCart"]'); if (b) b.click(); return !!b; });
    if (await add()) {
      await wait(1200);
      await shot('state-sales-cart');
    }
  }
}

(async () => {
  const root = path.join(__dirname, 'out', label);
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'] });
  const results = {};

  for (const [role, pages] of Object.entries(PAGES)) {
    const context = await browser.createBrowserContext();
    if (LOGINS[role]) await login(context, LOGINS[role]);
    const page = await context.newPage();
    let errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message).slice(0, 160)));
    page.on('console', (m) => { if (m.type() === 'error' && !/favicon|fonts\.bunny/.test(m.text())) errors.push(m.text().slice(0, 160)); });

    for (const [w, h] of WIDTHS) {
      await page.setViewport({ width: w, height: h, isMobile: w < 500, hasTouch: w < 500 });
      const out = path.join(root, String(w));
      fs.mkdirSync(out, { recursive: true });
      for (const [name, url] of Object.entries(pages)) {
        if (only && !name.includes(only)) continue;
        errors = [];
        try {
          const response = await page.goto(BASE + url, { waitUntil: 'networkidle2', timeout: 30000 });
          await wait(250);
          await page.screenshot({ path: path.join(out, name + '.png'), fullPage: true });
          const data = await page.evaluate(measure);
          results[`${name}@${w}`] = { url, status: response.status(), finalPath: new URL(page.url()).pathname, errors, ...data };
          console.log(response.status(), String(w).padEnd(5), name.padEnd(24), data.clipped.length ? 'CLIPPED +' + data.clipped.map((c) => c.over).join(',') : '');
        } catch (e) {
          results[`${name}@${w}`] = { url, status: 0, errors: [e.message.slice(0, 160)] };
          console.log('ERR', w, name, e.message.slice(0, 100));
        }
      }
      if (role === 'owner' && !only) await captureStates(page, out, results, w);
    }
    await context.close();
  }

  fs.mkdirSync(root, { recursive: true });
  fs.writeFileSync(path.join(root, 'metrics.json'), JSON.stringify(results, null, 1));
  await browser.close();
  const rows = Object.values(results).filter((r) => !r.state);
  console.log(`\n${rows.length} captures, ${rows.filter((r) => r.status >= 400 || r.status === 0).length} failed, ${rows.filter((r) => r.clipped?.length).length} with clipped tables. Saved to ${root}`);
})();
