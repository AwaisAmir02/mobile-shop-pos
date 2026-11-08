// Pixel difference between two capture runs for named screens.
// Usage: node pixdiff.cjs <before> <after> <width> <name> [<name> ...]
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');
const [a, b, width, ...names] = process.argv.slice(2);
(async () => {
  const browser = await puppeteer.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', args: ['--no-sandbox'] });
  const page = await browser.newPage();
  for (const name of names) {
    const load = (label) => 'data:image/png;base64,' + fs.readFileSync(path.join(__dirname, 'out', label, width, name + '.png')).toString('base64');
    const r = await page.evaluate(async (x, y) => {
      const img = (src) => new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = src; });
      const [p, q] = [await img(x), await img(y)];
      const w = Math.min(p.width, q.width), h = Math.min(p.height, q.height);
      const data = (i) => { const c = document.createElement('canvas'); c.width = w; c.height = h; const g = c.getContext('2d'); g.drawImage(i, 0, 0); return g.getImageData(0, 0, w, h).data; };
      const [d1, d2] = [data(p), data(q)];
      let n = 0, minY = h, maxY = -1, minX = w, maxX = -1;
      for (let k = 0; k < d1.length; k += 4) {
        if (Math.abs(d1[k] - d2[k]) + Math.abs(d1[k + 1] - d2[k + 1]) + Math.abs(d1[k + 2] - d2[k + 2]) > 30) {
          n++; const px = (k / 4) % w, py = Math.floor(k / 4 / w);
          minY = Math.min(minY, py); maxY = Math.max(maxY, py); minX = Math.min(minX, px); maxX = Math.max(maxX, px);
        }
      }
      return { sizeA: `${p.width}x${p.height}`, sizeB: `${q.width}x${q.height}`, changed: n, pct: (100 * n / (w * h)).toFixed(3), box: n ? `x${minX}-${maxX} y${minY}-${maxY}` : '' };
    }, load(a), load(b));
    console.log(name.padEnd(18), JSON.stringify(r));
  }
  await browser.close();
})();
