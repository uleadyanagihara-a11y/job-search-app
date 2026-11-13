import { expect, test, type Page } from '@playwright/test';
import { E2E_USERS, PASSWORD } from './helpers';

// ロールと企業の停止（docs/domain-api-contract.md M2）。
// user は E2eSeeder が作る（register の rate limit を使わない）。

/** SPA と同じく session cookie と Referer（Sanctum の stateful 判定）を付けて API を呼ぶ。 */
async function apiStatus(page: Page, path: string): Promise<number> {
    const response = await page.request.get(path, { headers: { Accept: 'application/json', Referer: 'http://localhost/' } });

    return response.status();
}

test('停止中の企業の担当者は正しい password でもログインできず、理由を表示する', async ({ page }) => {
    await page.goto('/login');

    // password が誤りなら、停止中かどうかは分からない（いつもの field error）
    await page.getByLabel('メールアドレス').fill(E2E_USERS.suspended.email);
    await page.getByLabel('パスワード').fill('Wrong-Passw0rd!');
    await page.getByRole('button', { name: 'ログイン' }).click();
    await expect(page.locator('#email-error')).not.toBeEmpty();
    await expect(page.getByRole('alert').filter({ hasText: '現在利用できません' })).toHaveCount(0);

    await page.getByLabel('パスワード').fill(PASSWORD);
    await page.getByRole('button', { name: 'ログイン' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'この企業アカウントは現在利用できません。' })).toBeVisible();
    await expect(page).toHaveURL('/login');
    expect(await apiStatus(page, '/api/user')).toBe(401);
});
