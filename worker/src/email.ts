import type { Env } from './env';

// Transactional email through Resend's REST API. Best-effort: logs and
// returns false on failure, never throws.
export async function sendEmail(env: Env, to: string, subject: string, html: string): Promise<boolean> {
  if (!env.RESEND_API_KEY || !env.RESEND_FROM) { console.log('sendEmail: RESEND_API_KEY/RESEND_FROM not set'); return false; }
  try {
    const r = await fetch('https://api.resend.com/emails', {
      method: 'POST',
      headers: { Authorization: `Bearer ${env.RESEND_API_KEY}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ from: env.RESEND_FROM, to: [to], subject, html }),
    });
    if (!r.ok) { console.log('sendEmail failed', r.status, (await r.text()).slice(0, 300)); return false; }
    return true;
  } catch (e) {
    console.log('sendEmail error', String(e));
    return false;
  }
}

export function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}
