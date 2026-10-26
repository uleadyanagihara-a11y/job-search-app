import { expect, test } from '@playwright/test';
import { registerViaApi, uniqueEmail } from './helpers';

// Nginx 単一入口の振り分け、direct access、Cookie/CSRF の疎通を確かめる最小の E2E。
// 画面の操作は guest-auth.spec.ts 等に任せ、ここでは title と URL で判定する。

test('未ログインで / を開くと dashboard を経由して login へ送られる', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveURL('/login?redirect=/dashboard');
    await expect(page).toHaveTitle('Log in - Job Search App');
});

test('存在しない path の direct access は SPA の 404 を表示する', async ({ page }) => {
    await page.goto('/no/such/page');

    await expect(page).toHaveTitle('Not Found - Job Search App');
    await expect(page.getByRole('heading', { name: '404' })).toBeVisible();
});

test('API と healthcheck は Nginx から Laravel へ届く', async ({ request }) => {
    const up = await request.get('/up');
    expect(up.status()).toBe(200);

    const user = await request.get('/api/user', { headers: { Accept: 'application/json' } });
    expect(user.status()).toBe(401);
    expect(await user.json()).toMatchObject({ code: 'unauthenticated' });
});

test('Cookie + CSRF で register すると session が張られ、未認証メールの画面へ送られる', async ({ page }) => {
    await page.goto('/register');

    const status = await registerViaApi(page, uniqueEmail('smoke'));

    expect(status).toBe(201);

    await page.goto('/dashboard');

    await expect(page).toHaveURL('/verify-email');
    await expect(page).toHaveTitle('Email Verification - Job Search App');
});
