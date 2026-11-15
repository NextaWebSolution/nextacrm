// Build assets/css/app.css with Tailwind CSS v4 (developer tool; not needed on the server).
// Usage: node tools/build-css.mjs [path/to/tailwindcss]
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const twDir = process.argv[2] || process.env.TAILWIND_DIR || path.join(root, 'node_modules/tailwindcss');
const { compile } = await import(pathToFileURL(path.join(twDir, 'dist/lib.mjs')).href);

const input = path.join(root, 'assets/src/app.css');
const out = path.join(root, 'assets/css/app.css');
const compiler = await compile(fs.readFileSync(input, 'utf8'), {
  base: path.dirname(input),
  loadStylesheet: async (id, base) => {
    let p = id === 'tailwindcss' ? path.join(twDir, 'index.css') : id.startsWith('tailwindcss/') ? path.join(twDir, id.slice(12)) : path.resolve(base, id);
    if (!p.endsWith('.css') && fs.existsSync(p + '.css')) p += '.css';
    return { path: p, base: path.dirname(p), content: fs.readFileSync(p, 'utf8') };
  },
});

const candidates = new Set();
const scan = (p) => {
  const st = fs.statSync(p);
  if (st.isDirectory()) { for (const f of fs.readdirSync(p)) scan(path.join(p, f)); return; }
  if (!/\.(php|js|html)$/.test(p)) return;
  for (let t of fs.readFileSync(p, 'utf8').split(/[\s"'`<>\\]+/)) {
    t = t.replace(/^[:@.]+/, '').replace(/[,;]+$/, '');
    if (t && t.length < 120) candidates.add(t);
  }
};
['app/Views', 'app/Services', 'app/Core/helpers.php', 'assets/js/app.js'].forEach((p) => scan(path.join(root, p)));

let css = compiler.build([...candidates]);
// light minification
css = css.replace(/\/\*(?!!)[\s\S]*?\*\//g, '').replace(/\n\s*\n/g, '\n');
fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(out, css);
console.log(`candidates: ${candidates.size}, css: ${(fs.statSync(out).size / 1024).toFixed(1)} KB`);
