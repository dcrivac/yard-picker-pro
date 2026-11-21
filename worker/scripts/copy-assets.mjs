// Copies the public static pages from the repo root into ./public for Workers
// static assets. This is an allowlist on purpose: the repo root also holds
// PHP files and internal notes that must never be served.
import { cpSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const out = join(here, '..', 'public');
const FILES = ['index.html', 'yp2.html', 'privacy.html', 'terms.html', 'sitemap.xml', 'robots.txt'];

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });
for (const f of FILES) cpSync(join(root, f), join(out, f));
// Same no-cache rule the old .htaccess set for HTML.
writeFileSync(join(out, '_headers'), '/*.html\n  Cache-Control: no-cache, no-store, must-revalidate\n/\n  Cache-Control: no-cache, no-store, must-revalidate\n');
console.log('copied', FILES.length, 'files to public/');
