import { appOrigin, type Env } from './env';

export const NO_STORE = 'no-store, no-cache, must-revalidate, max-age=0';

export function json(data: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json', 'Cache-Control': NO_STORE, ...headers },
  });
}

export function text(body: string, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(body, { status, headers: { 'Content-Type': 'text/plain; charset=UTF-8', 'Cache-Control': NO_STORE, ...headers } });
}

// Same-origin CORS, as the PHP endpoints did: only APP_ORIGIN is echoed back.
export function withCors(req: Request, env: Env, res: Response, opts: { credentials?: boolean; methods?: string } = {}): Response {
  const origin = req.headers.get('Origin');
  if (origin && origin === appOrigin(env)) {
    const h = new Headers(res.headers);
    h.set('Access-Control-Allow-Origin', origin);
    h.append('Vary', 'Origin');
    if (opts.credentials) h.set('Access-Control-Allow-Credentials', 'true');
    if (opts.methods) {
      h.set('Access-Control-Allow-Methods', opts.methods);
      h.set('Access-Control-Allow-Headers', 'Content-Type');
    }
    return new Response(res.body, { status: res.status, headers: h });
  }
  return res;
}

export function clientIp(req: Request): string {
  return req.headers.get('CF-Connecting-IP') || 'unknown';
}

export async function readJson(req: Request, maxBytes = 2_000_000): Promise<any | null> {
  const declared = Number(req.headers.get('Content-Length') || 0);
  if (declared > maxBytes) throw new HttpError(413, 'Body too large');
  const body = await req.text();
  if (body.length > maxBytes) throw new HttpError(413, 'Body too large');
  if (!body) return null;
  try { return JSON.parse(body); } catch { return null; }
}

export class HttpError extends Error {
  constructor(public status: number, message: string, public payload?: unknown, public headers: Record<string, string> = {}) {
    super(message);
  }
}

export function getCookie(req: Request, name: string): string | null {
  const raw = req.headers.get('Cookie');
  if (!raw) return null;
  for (const part of raw.split(';')) {
    const i = part.indexOf('=');
    if (i < 0) continue;
    if (part.slice(0, i).trim() === name) return decodeURIComponent(part.slice(i + 1).trim());
  }
  return null;
}

export function nowSql(d = new Date()): string {
  return d.toISOString().slice(0, 19).replace('T', ' ');
}

export function sqlToMs(s: string | null): number | null {
  if (!s) return null;
  const t = Date.parse(s.replace(' ', 'T') + 'Z');
  return Number.isNaN(t) ? null : t;
}
