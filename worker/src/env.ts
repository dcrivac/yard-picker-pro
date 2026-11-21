export interface Env {
  DB: D1Database;
  ASSETS: Fetcher;
  APP_ORIGIN: string;
  FREE_MONTHLY?: string;
  DAILY_COST_CEILING_USD?: string;
  PBKDF2_ITERATIONS?: string;
  EBAY_DELETION_ENDPOINT?: string;
  // Secrets (wrangler secret put)
  ANTHROPIC_API_KEY?: string;
  EBAY_CLIENT_ID?: string;
  EBAY_CLIENT_SECRET?: string;
  EBAY_VERIFICATION_TOKEN?: string;
  STRIPE_SECRET_KEY?: string;
  STRIPE_WEBHOOK_SECRET?: string;
  RESEND_API_KEY?: string;
  RESEND_FROM?: string;
}

export function appOrigin(env: Env): string {
  return (env.APP_ORIGIN || 'https://yardpicker.crivac.com').replace(/\/+$/, '');
}
