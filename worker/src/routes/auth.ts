// Port of auth.php. Same actions, same request and response shapes.
//   GET  /auth.php                         -> {user} or {user:null}
//   POST /auth.php {action:"register"|"login"|"forgot"|"reset"|"logout", ...}
import { appOrigin, type Env } from '../env';
import { clientIp, json, readJson } from '../http';
import { randomHex, sha256Hex } from '../crypto';
import { hashPassword, verifyPassword } from '../password';
import { createSession, creditStatus, currentUser, destroySession, type UserRow } from '../session';
import { rateLimit } from '../store';
import { escapeHtml, sendEmail } from '../email';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

async function publicUser(env: Env, u: UserRow | null) {
  if (!u) return null;
  const [free, paid, total] = await creditStatus(env, u);
  return { email: u.email, credits: total, free, paid };
}

function iterations(env: Env): number {
  return Math.min(100_000, parseInt(env.PBKDF2_ITERATIONS || '100000', 10) || 100_000);
}

async function loggedIn(env: Env, userId: number): Promise<Response> {
  const cookie = await createSession(env.DB, userId);
  const user = await env.DB.prepare('SELECT id, email, credits, free_credits_used, free_period_start, created_at FROM users WHERE id = ?').bind(userId).first<UserRow>();
  return json({ user: await publicUser(env, user) }, 200, { 'Set-Cookie': cookie });
}

export async function handleAuth(req: Request, env: Env): Promise<Response> {
  if (req.method === 'GET') return json({ user: await publicUser(env, await currentUser(req, env)) });
  if (req.method !== 'POST') return json({ error: 'Method not allowed' }, 405);

  const body = await readJson(req, 10_000);
  if (!body || typeof body !== 'object') return json({ error: 'Invalid request' }, 400);
  const action = typeof body.action === 'string' ? body.action : '';

  // Brute-force guard: 20 attempts per IP per 5 minutes.
  if (['login', 'register', 'forgot', 'reset'].includes(action)) {
    await rateLimit(env.DB, 'auth', clientIp(req), 20, 300, 'Too many attempts. Try again in a few minutes.');
  }

  if (action === 'logout') {
    return json({ user: null }, 200, { 'Set-Cookie': await destroySession(req, env.DB) });
  }

  const pass = typeof body.password === 'string' ? body.password : body.password == null ? '' : String(body.password);

  if (action === 'reset') {
    const token = typeof body.token === 'string' ? body.token : '';
    if (token === '' || pass.length < 8) return json({ error: 'Enter a new password (8+ characters).' }, 400);
    const row = await env.DB.prepare("SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > datetime('now')")
      .bind(await sha256Hex(token)).first<{ id: number; user_id: number }>();
    if (!row) return json({ error: 'This reset link is invalid or has expired. Request a new one.' }, 400);
    await env.DB.batch([
      env.DB.prepare('UPDATE users SET password_hash = ? WHERE id = ?').bind(await hashPassword(pass, iterations(env)), row.user_id),
      env.DB.prepare("UPDATE password_resets SET used_at = datetime('now') WHERE id = ?").bind(row.id),
      // A password reset signs out every other session for this account.
      env.DB.prepare('DELETE FROM sessions WHERE user_id = ?').bind(row.user_id),
    ]);
    return loggedIn(env, row.user_id);
  }

  const email = typeof body.email === 'string' ? body.email.trim().toLowerCase() : '';
  if (!EMAIL_RE.test(email) || email.length > 255) return json({ error: 'Enter a valid email.' }, 400);

  if (action === 'forgot') {
    // Same response whether or not the email exists (no account enumeration).
    const row = await env.DB.prepare('SELECT id FROM users WHERE email = ?').bind(email).first<{ id: number }>();
    if (row) {
      const token = randomHex(32);
      await env.DB.prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, datetime('now', '+1 hour'))")
        .bind(row.id, await sha256Hex(token)).run();
      const link = `${appOrigin(env)}/yp2.html?reset=${encodeURIComponent(token)}`;
      await sendEmail(env, email, 'Reset your Yard Picker Pro password', `
            <p>Someone (hopefully you) requested a password reset for your Yard Picker Pro account.</p>
            <p><a href="${escapeHtml(link)}">Click here to set a new password</a> (link expires in 1 hour).</p>
            <p>If you did not request this, you can ignore this email.</p>
        `);
    }
    return json({ ok: true });
  }

  if (action === 'register') {
    if (pass.length < 8) return json({ error: 'Password must be at least 8 characters.' }, 400);
    const exists = await env.DB.prepare('SELECT id FROM users WHERE email = ?').bind(email).first();
    if (exists) return json({ error: 'That email is already registered. Try logging in.' }, 409);
    const hash = await hashPassword(pass, iterations(env));
    const res = await env.DB.prepare("INSERT INTO users (email, password_hash, free_period_start) VALUES (?, ?, date('now')) RETURNING id")
      .bind(email, hash).first<{ id: number }>();
    return loggedIn(env, res!.id);
  }

  if (action === 'login') {
    const row = await env.DB.prepare('SELECT id, password_hash FROM users WHERE email = ?').bind(email).first<{ id: number; password_hash: string }>();
    if (!row) return json({ error: 'Wrong email or password.' }, 401);
    const { ok, rehash } = await verifyPassword(pass, row.password_hash, iterations(env));
    if (!ok) return json({ error: 'Wrong email or password.' }, 401);
    if (rehash) {
      // Old bcrypt (or older PBKDF2 settings): store a current hash now.
      await env.DB.prepare('UPDATE users SET password_hash = ? WHERE id = ?').bind(await hashPassword(pass, iterations(env)), row.id).run();
    }
    return loggedIn(env, row.id);
  }

  return json({ error: 'Unknown action' }, 400);
}
