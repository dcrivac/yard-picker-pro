// Session cookie + user helpers (replaces db.php's PHP-session functions).
import type { Env } from './env';
import { getCookie } from './http';
import { randomHex, sha256Hex } from './crypto';

export const COOKIE = 'yp_sess';
const LIFETIME = 60 * 60 * 24 * 30; // 30 days, as before

export interface UserRow {
  id: number;
  email: string;
  credits: number;
  free_credits_used: number;
  free_period_start: string;
  created_at: string;
}

export async function createSession(db: D1Database, userId: number): Promise<string> {
  const token = randomHex(32);
  await db.prepare('INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)')
    .bind(await sha256Hex(token), userId, Math.floor(Date.now() / 1000) + LIFETIME).run();
  return `${COOKIE}=${token}; Path=/; Max-Age=${LIFETIME}; Secure; HttpOnly; SameSite=Lax`;
}

export async function destroySession(req: Request, db: D1Database): Promise<string> {
  const token = getCookie(req, COOKIE);
  if (token) await db.prepare('DELETE FROM sessions WHERE token_hash = ?').bind(await sha256Hex(token)).run();
  return `${COOKIE}=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax`;
}

export async function currentUser(req: Request, env: Env): Promise<UserRow | null> {
  const token = getCookie(req, COOKIE);
  if (!token || !/^[0-9a-f]{64}$/.test(token)) return null;
  return await env.DB.prepare(
    `SELECT u.id, u.email, u.credits, u.free_credits_used, u.free_period_start, u.created_at
       FROM sessions s JOIN users u ON u.id = s.user_id
      WHERE s.token_hash = ? AND s.expires_at > ?`
  ).bind(await sha256Hex(token), Math.floor(Date.now() / 1000)).first<UserRow>();
}

// Roll the free monthly allowance on a new calendar month, then return
// [freeRemaining, paidCredits, totalAvailable]. Same rules as db.php.
export async function creditStatus(env: Env, user: UserRow): Promise<[number, number, number]> {
  const freeMonthly = parseInt(env.FREE_MONTHLY || '5', 10) || 5;
  const month = new Date().toISOString().slice(0, 7);
  if (String(user.free_period_start).slice(0, 7) !== month) {
    await env.DB.prepare('UPDATE users SET free_credits_used = 0, free_period_start = ? WHERE id = ?')
      .bind(new Date().toISOString().slice(0, 10), user.id).run();
    user.free_credits_used = 0;
  }
  const free = Math.max(0, freeMonthly - user.free_credits_used);
  const paid = user.credits;
  return [free, paid, free + paid];
}
