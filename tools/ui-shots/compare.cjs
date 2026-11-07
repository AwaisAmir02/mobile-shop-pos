// Compares two capture runs and lists what got better or worse.
// Usage: node compare.cjs <before-label> <after-label>
const fs = require('fs');
const path = require('path');

const [a, b] = process.argv.slice(2);
if (!a || !b) {
  console.error('Usage: node compare.cjs <before-label> <after-label>');
  process.exit(1);
}

const load = (label) => JSON.parse(fs.readFileSync(path.join(__dirname, 'out', label, 'metrics.json'), 'utf8'));
const before = load(a);
const after = load(b);
const over = (r) => (r?.clipped || []).reduce((sum, c) => sum + c.over, 0);
const worse = [];
const better = [];

for (const key of new Set([...Object.keys(before), ...Object.keys(after)])) {
  const x = before[key];
  const y = after[key];
  if (x?.state || y?.state) continue;
  if (!x || !y) { worse.push(`${key}: only in ${x ? a : b}`); continue; }
  if (x.status !== y.status) worse.push(`${key}: status ${x.status} -> ${y.status}`);
  if ((y.finalPath || '') !== (x.finalPath || '')) worse.push(`${key}: lands on ${x.finalPath} -> ${y.finalPath}`);
  const newErrors = (y.errors || []).filter((e) => !(x.errors || []).includes(e));
  if (newErrors.length) worse.push(`${key}: new errors: ${newErrors[0]}`);
  if ((y.docOverflow || 0) > (x.docOverflow || 0)) worse.push(`${key}: page overflow ${x.docOverflow} -> ${y.docOverflow}`);
  if (over(y) > over(x)) worse.push(`${key}: table clip +${over(x)} -> +${over(y)}`);
  if (over(y) < over(x)) better.push(`${key}: table clip +${over(x)} -> +${over(y)}`);
}

console.log(`Worse or changed (${worse.length}):`);
worse.forEach((line) => console.log('  ' + line));
console.log(`Better (${better.length}):`);
better.forEach((line) => console.log('  ' + line));
process.exitCode = worse.length ? 1 : 0;
