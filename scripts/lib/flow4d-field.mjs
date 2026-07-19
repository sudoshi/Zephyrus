/**
 * Shared helpers for the 4D Navigator field-verification scripts
 * (HFE closure H4 — docs/FLOW-4D-HFE-CLOSURE-PLAN-2026-07-19.md).
 *
 * Credentials come ONLY from the environment — never hardcode them:
 *   FLOW4D_BASE_URL   target origin (default https://zephyrus.acumenus.net)
 *   FLOW4D_USERNAME   login username (required)
 *   FLOW4D_PASSWORD   login password (required)
 *   FLOW4D_PERSONA    optional ?persona= flow-lens role (empty = user default)
 */
import { appendFileSync, mkdirSync } from 'node:fs';

export function fieldConfig() {
  const baseUrl = (process.env.FLOW4D_BASE_URL ?? 'https://zephyrus.acumenus.net').replace(/\/$/, '');
  const username = process.env.FLOW4D_USERNAME;
  const password = process.env.FLOW4D_PASSWORD;
  if (!username || !password) {
    throw new Error('FLOW4D_USERNAME and FLOW4D_PASSWORD must be set (never hardcoded).');
  }
  return {
    baseUrl,
    username,
    password,
    persona: process.env.FLOW4D_PERSONA ?? '',
  };
}

export function intEnv(name, fallback) {
  const raw = process.env[name];
  const value = raw === undefined || raw === '' ? NaN : Number(raw);
  return Number.isFinite(value) ? value : fallback;
}

export function floatEnv(name, fallback) {
  const raw = process.env[name];
  const value = raw === undefined || raw === '' ? NaN : Number(raw);
  return Number.isFinite(value) ? value : fallback;
}

/**
 * Log in through the real login form so the session cookie set matches what a
 * wall operator gets. Fails loudly on must_change_password — the soak account
 * has to be a fully provisioned user, and this script must never try to
 * change or reset a credential.
 */
export async function login(page, config) {
  await page.goto(`${config.baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', config.username);
  await page.fill('#password', config.password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30_000 }),
    page.click('button[type="submit"]'),
  ]);
  if (page.url().includes('/change-password')) {
    throw new Error('Account requires a password change — use a fully provisioned soak account.');
  }
}

export function ensureDir(dir) {
  mkdirSync(dir, { recursive: true });
  return dir;
}

export function appendJsonl(file, record) {
  appendFileSync(file, `${JSON.stringify(record)}\n`);
}

export function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
