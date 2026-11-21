// Password hashing for the Workers free plan (10 ms CPU per request).
//
// New hashes: PBKDF2-SHA256 through WebCrypto, which runs natively. Workers
// cap PBKDF2 at 100,000 iterations. Format: pbkdf2_sha256$<iter>$<salt>$<hash>.
//
// Legacy hashes from the PHP app ($2y$ bcrypt) are checked once with
// bcryptjs, then replaced with a PBKDF2 hash on that same login. A bcrypt
// check in JavaScript costs far more CPU than the free plan's 10 ms, so that
// one login may be refused with error 1102; "Forgot password" always works
// as the fallback because it never touches the old hash.
import bcrypt from 'bcryptjs';
import { b64, safeEqual, unb64 } from './crypto';

const enc = new TextEncoder();

async function pbkdf2(password: string, salt: Uint8Array, iterations: number): Promise<Uint8Array> {
  const key = await crypto.subtle.importKey('raw', enc.encode(password), 'PBKDF2', false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, key, 256);
  return new Uint8Array(bits);
}

export async function hashPassword(password: string, iterations = 100_000): Promise<string> {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const hash = await pbkdf2(password, salt, iterations);
  return `pbkdf2_sha256$${iterations}$${b64(salt)}$${b64(hash)}`;
}

export function isLegacyHash(stored: string): boolean {
  return /^\$2[aby]\$\d\d\$/.test(stored);
}

export async function verifyPassword(password: string, stored: string, iterations = 100_000): Promise<{ ok: boolean; rehash: boolean }> {
  if (stored.startsWith('pbkdf2_sha256$')) {
    const [, iterStr, saltB64, hashB64] = stored.split('$');
    const iter = parseInt(iterStr, 10);
    if (!iter || !saltB64 || !hashB64) return { ok: false, rehash: false };
    const got = b64(await pbkdf2(password, unb64(saltB64), iter));
    const ok = safeEqual(got, hashB64);
    return { ok, rehash: ok && iter !== iterations };
  }
  if (isLegacyHash(stored)) {
    // PHP writes $2y$, which is the same algorithm as $2b$.
    const ok = await bcrypt.compare(password, stored.replace(/^\$2y\$/, '$2b$'));
    return { ok, rehash: ok };
  }
  return { ok: false, rehash: false };
}
