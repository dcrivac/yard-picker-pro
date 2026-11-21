// Port of tracker.php. Every query is scoped to the signed-in user.
import type { Env } from '../env';
import { json, nowSql, readJson, sqlToMs } from '../http';
import { currentUser } from '../session';

const STATUSES = ['pulled', 'listed', 'sold'];

interface Row {
  id: number; part: string; car: string; lkq_cost: number | null; labor_cost: number | null; ebay_avg: number | null;
  list_price: number | null; sale_price: number | null; reason: string | null; status: string;
  pulled_at: string | null; listed_at: string | null; sold_at: string | null;
}

function itemOut(r: Row) {
  return {
    id: r.id, part: r.part, car: r.car,
    lkqCost: r.lkq_cost, laborCost: r.labor_cost, ebayAvg: r.ebay_avg,
    listPrice: r.list_price, salePrice: r.sale_price, reason: r.reason, status: r.status,
    pulledAt: sqlToMs(r.pulled_at), listedAt: sqlToMs(r.listed_at), soldAt: sqlToMs(r.sold_at),
  };
}

// Client epoch-ms (or nothing) -> 'YYYY-MM-DD HH:MM:SS' UTC.
function tsOrNull(ms: unknown): string | null {
  const n = Number(ms);
  if (!ms || !Number.isFinite(n)) return null;
  return nowSql(new Date(Math.floor(n / 1000) * 1000));
}

const num = (v: unknown): number | null => (v === undefined || v === null ? null : Number(v) || 0);

export async function handleTracker(req: Request, env: Env): Promise<Response> {
  const me = await currentUser(req, env);
  if (!me) return json({ error: 'Sign in required' }, 401);

  if (req.method === 'GET') {
    const { results } = await env.DB.prepare('SELECT * FROM tracker_items WHERE user_id = ? ORDER BY created_at ASC, id ASC').bind(me.id).all<Row>();
    return json({ items: results.map(itemOut) });
  }
  if (req.method !== 'POST') return json({ error: 'Method not allowed' }, 405);

  const body = await readJson(req, 50_000);
  if (!body || typeof body !== 'object') return json({ error: 'Invalid request' }, 400);
  const action = body.action;

  if (action === 'add') {
    const part = body.part == null ? '' : String(body.part).trim();
    if (part === '') return json({ error: 'Missing part name' }, 400);
    const status = STATUSES.includes(body.status) ? body.status : 'pulled';
    const row = await env.DB.prepare(
      `INSERT INTO tracker_items (user_id, part, car, lkq_cost, labor_cost, ebay_avg, list_price, sale_price, reason, status, pulled_at, listed_at, sold_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING *`
    ).bind(
      me.id, part.slice(0, 255), body.car == null ? '' : String(body.car).slice(0, 255),
      num(body.lkqCost), num(body.laborCost), num(body.ebayAvg), num(body.listPrice), num(body.salePrice),
      body.reason == null ? null : String(body.reason).slice(0, 255), status,
      tsOrNull(body.pulledAt) ?? nowSql(), tsOrNull(body.listedAt), tsOrNull(body.soldAt),
    ).first<Row>();
    return json({ item: itemOut(row!) });
  }

  if (action === 'update_status') {
    const id = parseInt(body.id, 10) || 0;
    const status = String(body.status ?? '');
    if (!id || !STATUSES.includes(status)) return json({ error: 'Invalid request' }, 400);
    const owned = await env.DB.prepare('SELECT id FROM tracker_items WHERE id = ? AND user_id = ?').bind(id, me.id).first();
    if (!owned) return json({ error: 'Not found' }, 404);
    const sets = ['status = ?'];
    const params: unknown[] = [status];
    if (status === 'listed' && body.listPrice !== undefined && body.listPrice !== null) {
      sets.push('list_price = ?', 'listed_at = ?'); params.push(Number(body.listPrice) || 0, nowSql());
    }
    if (status === 'sold' && body.salePrice !== undefined && body.salePrice !== null) {
      sets.push('sale_price = ?', 'sold_at = ?'); params.push(Number(body.salePrice) || 0, nowSql());
    }
    params.push(id, me.id);
    const row = await env.DB.prepare(`UPDATE tracker_items SET ${sets.join(', ')} WHERE id = ? AND user_id = ? RETURNING *`).bind(...params).first<Row>();
    return json({ item: itemOut(row!) });
  }

  if (action === 'delete') {
    const id = parseInt(body.id, 10) || 0;
    if (!id) return json({ error: 'Invalid request' }, 400);
    await env.DB.prepare('DELETE FROM tracker_items WHERE id = ? AND user_id = ?').bind(id, me.id).run();
    return json({ ok: true });
  }

  return json({ error: 'Unknown action' }, 400);
}
