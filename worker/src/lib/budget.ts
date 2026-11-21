// The Workers free plan allows 50 external subrequests (fetch calls to the
// internet) per incoming request; calls to D1 count against a separate limit
// of 1,000. Every outside fetch goes through a Budget so a request degrades
// gracefully (skips optional lookups) instead of throwing at call 51.
export const FREE_PLAN_EXTERNAL_SUBREQUESTS = 50;

export class Budget {
  used = 0;
  constructor(public limit = FREE_PLAN_EXTERNAL_SUBREQUESTS) {}
  get remaining(): number { return this.limit - this.used; }
  // Redirects count as extra subrequests, so callers that follow redirects
  // should reserve more than one.
  async fetch(input: RequestInfo, init?: RequestInit, cost = 1): Promise<Response> {
    if (this.used + cost > this.limit) throw new BudgetExceeded();
    this.used += cost;
    return fetch(input, init);
  }
}

export class BudgetExceeded extends Error {
  constructor() { super('external subrequest budget exceeded'); }
}

// Run tasks with at most `n` in flight (Workers allow 6 open connections).
export async function pool<T, R>(items: T[], n: number, fn: (item: T, i: number) => Promise<R>): Promise<R[]> {
  const out = new Array<R>(items.length);
  let next = 0;
  const worker = async () => {
    while (next < items.length) {
      const i = next++;
      out[i] = await fn(items[i], i);
    }
  };
  await Promise.all(Array.from({ length: Math.min(n, items.length) }, worker));
  return out;
}
