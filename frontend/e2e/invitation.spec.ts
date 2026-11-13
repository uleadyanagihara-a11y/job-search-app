import { expect, test } from '@playwright/test';
import { E2E_INVITED_EMAIL, E2E_USERS, latestMailText, PASSWORD } from './helpers';

// 企業担当者の招待の受諾（docs/domain-design.md 2 章、docs/domain-api-contract.md M2.3・M2.6）。
// artisan は Playwright から呼べないため、E2eSeeder が laravel.e2e の起動時に招待を作ってメールを送る。
// 招待は 1 回しか使えないため、この spec は laravel.e2e を起動し直すまで 1 回だけ通る（CI の retry では 2 回目が失敗する）。

test('招待メールの URL から名前と password を設定して企業担当者になり、同じ URL は使えなくなる', async ({ page, request }) => {
    const invitationUrl = /http:\/\/localhost\/invitations\/[A-Za-z0-9]+/.exec(
        await latestMailText(request, E2E_INVITED_EMAIL),
    )?.[0];
    expect(invitationUrl).toBeDefined();

    await page.goto(invitationUrl!);
    await expect(page).toHaveTitle('招待の受諾 - Job Search App');
    await expect(page.getByText(`「${E2E_USERS.company.companyName}」の担当者として招待されています。`)).toBeVisible();
    await expect(page.getByLabel('メールアドレス')).toHaveValue(E2E_INVITED_EMAIL);
    await expect(page.getByLabel('メールアドレス')).not.toBeEditable();

    // 422 は field に出す
    await page.getByLabel('名前').fill('招待 太郎');
    await page.getByLabel('パスワード', { exact: true }).fill(PASSWORD);
    await page.getByLabel('パスワード（確認）').fill('Different-Passw0rd!');
    await page.getByRole('button', { name: '登録してログイン' }).click();
    await expect(page.locator('#password-error')).not.toBeEmpty();

    await page.getByLabel('パスワード', { exact: true }).fill(PASSWORD);
    await page.getByLabel('パスワード（確認）').fill(PASSWORD);
    await page.getByRole('button', { name: '登録してログイン' }).click();

    // メールの到達で本人確認済みのため、verified 必須の dashboard へそのまま入れる
    await expect(page).toHaveURL('/dashboard');
    await expect(page.getByRole('button', { name: '招待 太郎' })).toBeVisible();
    await expect(page.getByRole('link', { name: '求職者プロフィール' })).toHaveCount(0);

    // ログイン中に招待の URL を開くと guest 用の guard で dashboard へ送られる
    await page.goto(invitationUrl!);
    await expect(page).toHaveURL('/dashboard');

    await page.getByRole('button', { name: '招待 太郎' }).click();
    await page.getByRole('button', { name: 'ログアウト' }).click();
    await expect(page).toHaveURL('/login');

    // 使用済みの招待は理由を区別せず無効と表示する
    await page.goto(invitationUrl!);
    await expect(page.getByRole('alert')).toHaveText('招待のリンクが無効か、有効期限が切れています。');
    await expect(page.getByRole('button', { name: '登録してログイン' })).toHaveCount(0);

    // 設定した password でログインできる
    await page.goto('/login');
    await page.getByLabel('メールアドレス').fill(E2E_INVITED_EMAIL);
    await page.getByLabel('パスワード').fill(PASSWORD);
    await page.getByRole('button', { name: 'ログイン' }).click();
    await expect(page).toHaveURL('/dashboard');
});
