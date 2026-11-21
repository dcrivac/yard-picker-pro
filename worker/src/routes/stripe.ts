// Ports of checkout.php and stripe-webhook.php.
import { appOrigin, type Env } from '../env';
import { json, text } from '../http';
import { hmacSha256Hex, safeEqual } from '../crypto';
import { currentUser } from '../session';

export const PACKS: Record<string, { credits: number; usd_cents: number; label: string }> = {
  small: { credits: 30, usd_cents: 500, label: '30 analyses' },
  large: { credits: 100, usd_cents: 1500, label: '100 analyses' },
};

// GET /checkout.php?pack=small|large -> 303 to Stripe's hosted checkout.
export async function handleCheckout(req: Request, env: Env): Promise<Response> {
  const me = await currentUser(req, env);
  if (!me) return text('Sign in first, then try again.', 401);
  const pack = PACKS[new URL(req.url).searchParams.get('pack') || ''];
  if (!pack) return text('Unknown credit pack.', 400);
  if (!env.STRIPE_SECRET_KEY) return text('Payments are not configured yet.', 503);

  const origin = appOrigin(env);
  const params = new URLSearchParams({
    mode: 'payment',
    success_url: `${origin}/yp2.html?checkout=success`,
    cancel_url: `${origin}/yp2.html?checkout=cancelled`,
    client_reference_id: String(me.id),
    customer_email: me.email,
    'line_items[0][quantity]': '1',
    'line_items[0][price_data][currency]': 'usd',
    'line_items[0][price_data][unit_amount]': String(pack.usd_cents),
    'line_items[0][price_data][product_data][name]': `Yard Picker Pro — ${pack.label}`,
    'metadata[user_id]': String(me.id),
    'metadata[credits]': String(pack.credits),
  });
  const r = await fetch('https://api.stripe.com/v1/checkout/sessions', {
    method: 'POST',
    headers: { Authorization: `Bearer ${env.STRIPE_SECRET_KEY}`, 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params.toString(),
  });
  const data = (await r.json().catch(() => null)) as { url?: string } | null;
  if (r.status !== 200 || !data?.url) {
    console.log('Stripe session create failed', r.status, JSON.stringify(data).slice(0, 500));
    return text('Could not start checkout. Please try again.', 502);
  }
  return new Response(null, { status: 303, headers: { Location: data.url } });
}

// Stripe-Signature: t=<timestamp>,v1=<hex hmac>[,v1=...]
export async function verifyStripeSignature(payload: string, header: string, secret: string, nowSec = Math.floor(Date.now() / 1000)): Promise<'ok' | 'missing' | 'old' | 'bad'> {
  let t = '';
  const sigs: string[] = [];
  for (const kv of header.split(',')) {
    const i = kv.indexOf('=');
    if (i < 0) continue;
    const k = kv.slice(0, i).trim(), v = kv.slice(i + 1).trim();
    if (k === 't' && !t) t = v;
    if (k === 'v1') sigs.push(v);
  }
  if (!t || !sigs.length) return 'missing';
  if (Math.abs(nowSec - parseInt(t, 10)) > 300) return 'old';
  const expected = await hmacSha256Hex(secret, `${t}.${payload}`);
  return sigs.some((s) => safeEqual(expected, s)) ? 'ok' : 'bad';
}

// POST /stripe-webhook.php  (event: checkout.session.completed)
export async function handleStripeWebhook(req: Request, env: Env): Promise<Response> {
  if (!env.STRIPE_WEBHOOK_SECRET) { console.log('STRIPE_WEBHOOK_SECRET not set'); return new Response(null, { status: 503 }); }
  const payload = await req.text();
  const check = await verifyStripeSignature(payload, req.headers.get('Stripe-Signature') || '', env.STRIPE_WEBHOOK_SECRET);
  if (check === 'missing') return json({ error: 'Missing signature' }, 400);
  if (check === 'old') return json({ error: 'Signature too old' }, 400);
  if (check === 'bad') { console.log('stripe signature verification failed'); return json({ error: 'Invalid signature' }, 400); }

  let event: any;
  try { event = JSON.parse(payload); } catch { return new Response(null, { status: 400 }); }

  if (event?.type === 'checkout.session.completed') {
    const session = event.data?.object ?? {};
    const sessionId = String(session.id ?? '');
    const userId = parseInt(session.metadata?.user_id, 10) || 0;
    const credits = parseInt(session.metadata?.credits, 10) || 0;
    const paid = session.payment_status === 'paid';
    if (paid && userId > 0 && credits > 0) {
      // D1 batches are atomic: if the ledger insert hits UNIQUE(ref, reason)
      // (a Stripe retry), the credit update rolls back with it.
      try {
        await env.DB.batch([
          env.DB.prepare('INSERT INTO credit_events (user_id, delta, reason, ref) VALUES (?, ?, ?, ?)').bind(userId, credits, 'purchase', sessionId),
          env.DB.prepare('UPDATE users SET credits = credits + ? WHERE id = ?').bind(credits, userId),
        ]);
      } catch (e) {
        const msg = String(e);
        if (!msg.includes('UNIQUE')) {
          console.log('credit insert failed', msg);
          // Non-duplicate failure: let Stripe retry instead of losing the purchase.
          return json({ error: 'Temporary failure' }, 500);
        }
      }
    } else {
      console.log('checkout.session.completed missing data', payload.slice(0, 300));
    }
  }
  return json({ received: true });
}
