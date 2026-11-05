import { expect, test } from '@playwright/test';
import { latestMailText, PASSWORD, registerViaApi, uniqueEmail } from './helpers';

// ログイン後の共通 layout（Dashboard、navigation、Logout）を Nginx の入口からブラウザで操作する。
// dashboard は verified 必須のため、登録時のメール認証 URL（§5.4 の callback）を開いて認証済みにする。
// register の rate limit（IP 単位 6 回/分）を使い切らないよう、user の用意は 1 回にまとめる。

test('メール認証後の dashboard から navigation で移動し、desktop・mobile の menu と session 切れの状態から logout する',async ({ page, request }) => {
    const email = uniqueEmail('shell');

    await page.goto('/login');
    expect(await registerViaApi(page, email)).toBe(201);

    const verifyUrl = /http:\/\/localhost\/auth\/email\/verify\/[^\s)\]]+/.exec(await latestMailText(request, email))?.[0];
    expect(verifyUrl).toBeDefined();

    await page.goto(verifyUrl!);
    await expect(page).toHaveURL('/dashboard?verified=1');
    await expect(page).toHaveTitle('Dashboard - Job Search App');
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByRole('status')).toHaveText('メールアドレスの確認が完了しました。');
    await expect(page.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page');

    // desktop: user 名の dropdown から Profile へ移動し、logo で dashboard へ戻る
    await page.getByRole('button', { name: 'E2E User' }).click();
    await page.getByRole('link', { name: 'Profile' }).click();
    await expect(page).toHaveURL('/profile');
    await expect(page.getByRole('link', { name: 'Dashboard' })).not.toHaveAttribute('aria-current', 'page');

    await page.getByRole('link', { name: 'Job Search App' }).click();
    await expect(page).toHaveURL('/dashboard');

    await page.getByRole('button', { name: 'E2E User' }).click();
    await page.getByRole('button', { name: 'Log Out' }).click();
    await expect(page).toHaveURL('/login');

    // session が破棄され、保護された画面は再び login へ送られる
    await page.goto('/dashboard');
    await expect(page).toHaveURL('/login?redirect=/dashboard');

    // mobile: hamburger の responsive menu から logout する
    await page.setViewportSize({ width: 375, height: 800 });
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL('/dashboard');

    await expect(page.getByRole('button', { name: 'Log Out' })).toBeHidden();
    await page.getByRole('button', { name: 'Navigation' }).click();
    await expect(page.locator('#responsive-navigation')).toContainText(email);
    await page.getByRole('button', { name: 'Log Out' }).click();
    await expect(page).toHaveURL('/login');

    const user = await page.request.get('/api/user', { headers: { Accept: 'application/json' } });
    expect(user.status()).toBe(401);

    // 別 tab 等で既に logout 済みの session でも、Log Out は `?redirect=` 無しの login へ進む。
    // 実際の Laravel が（古い XSRF-TOKEN での 419 → 再送を経ても）401 を返すことの確認を兼ねる。
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL('/dashboard');

    const otherTab = await page.context().newPage();
    await otherTab.goto('/dashboard');
    await otherTab.getByRole('button', { name: 'E2E User' }).click();
    await otherTab.getByRole('button', { name: 'Log Out' }).click();
    await expect(otherTab).toHaveURL('/login');
    await otherTab.close();

    const logoutStatuses: number[] = [];
    page.on('response', (response) => {
        if (response.url().endsWith('/api/auth/logout')) {
            logoutStatuses.push(response.status());
        }
    });

    await page.getByRole('button', { name: 'E2E User' }).click();
    await page.getByRole('button', { name: 'Log Out' }).click();
    await expect(page).toHaveURL('/login');
    await expect(page.getByRole('alert')).toHaveCount(0);
    expect(logoutStatuses.at(-1)).toBe(401);
});
