import { expect, test, type Page } from '@playwright/test';
import { E2E_USERS, openAs, PASSWORD } from './helpers';

// ロールによる画面の振り分けと、求職者プロフィール・企業の停止（docs/domain-api-contract.md M2）。
// user は E2eSeeder が作る（register の rate limit を使わない）。

/** SPA と同じく session cookie と Referer（Sanctum の stateful 判定）を付けて API を呼ぶ。 */
async function apiStatus(page: Page, path: string): Promise<number> {
    const response = await page.request.get(path, { headers: { Accept: 'application/json', Referer: 'http://localhost/' } });

    return response.status();
}

test('求職者は nav から求職者プロフィールを開いて保存でき、reload 後も残る', async ({ page }) => {
    await openAs(page, 'seeker', '/dashboard');

    await page.getByRole('link', { name: '求職者プロフィール' }).click();
    await expect(page).toHaveURL('/seeker-profile');
    await expect(page).toHaveTitle('求職者プロフィール - Job Search App');
    await expect(page.getByRole('link', { name: '求職者プロフィール' })).toHaveAttribute('aria-current', 'page');

    const prefecture = page.getByLabel('希望勤務地');
    const jobType = page.getByLabel('希望職種');
    const selfPr = page.getByLabel('自己 PR');

    // 2000 文字を超えると 422 を field に出す
    await selfPr.fill('あ'.repeat(2001));
    await page.getByRole('button', { name: '保存' }).click();
    await expect(page.locator('#self_pr-error')).not.toBeEmpty();
    await expect(selfPr).toHaveAttribute('aria-invalid', 'true');

    await prefecture.selectOption({ label: '大阪府' });
    await jobType.fill('Web エンジニア');
    await selfPr.fill('Laravel と Vue で\n3 年開発してきました。');
    await page.getByRole('button', { name: '保存' }).click();
    await expect(page.getByRole('status')).toHaveText('保存しました。');

    await page.reload();
    await expect(prefecture).toHaveValue('27');
    await expect(jobType).toHaveValue('Web エンジニア');
    await expect(selfPr).toHaveValue('Laravel と Vue で\n3 年開発してきました。');
});

test('企業担当者には求職者プロフィールを出さず、URL で開いても dashboard へ送り、API も 403', async ({ page }) => {
    await openAs(page, 'company', '/dashboard');

    await expect(page.getByRole('button', { name: E2E_USERS.company.name })).toBeVisible();
    await expect(page.getByRole('link', { name: '求職者プロフィール' })).toHaveCount(0);

    await page.goto('/seeker-profile');
    await expect(page).toHaveURL('/dashboard');

    // 画面の振り分けは表示だけで、データは API の Policy が守る
    expect(await apiStatus(page, '/api/seeker-profile')).toBe(403);
    expect(await apiStatus(page, '/api/user')).toBe(200);
});

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

// --- ログイン中の停止（docs/domain-api-contract.md M2.2）---
// server 側（本物の company:suspend で session cookie・remember cookie が締め出される）は
// tests/Feature/Console/CompanyCommandsTest.php で確かめている。E2E の DB は spec 間で共有しており、
// 企業を本当に止めると後の test が壊れるため、ここは API の response を 403 company_suspended に差し替えて
// SPA の反応（auth store の破棄・login への遷移・停止の旨の表示）だけを実ブラウザで確かめる。

const COMPANY_SUSPENDED = {
    status: 403,
    contentType: 'application/json',
    body: JSON.stringify({
        message: 'この企業アカウントは現在利用できません。運営にお問い合わせください。',
        code: 'company_suspended',
    }),
};

test('ログイン中に企業が停止されると、次の API の 403 company_suspended で login へ送り、停止の旨を出す', async ({ page }) => {
    await openAs(page, 'company', '/profile');

    await page.route('**/api/profile', (route) =>
        route.request().method() === 'PATCH' ? route.fulfill(COMPANY_SUSPENDED) : route.fallback(),
    );

    const section = page.locator('section').filter({ has: page.getByRole('heading', { name: 'プロフィール情報' }) });
    await section.getByRole('button', { name: '保存' }).click();

    await expect(page).toHaveURL('/login?company_suspended=1');
    await expect(page.getByRole('alert').filter({ hasText: 'この企業アカウントは現在利用できません。' })).toBeVisible();
    await expect(page.getByLabel('メールアドレス')).toBeVisible();
});

test('reload 時の GET /api/user が 403 company_suspended なら、redirect 付きでなく停止の旨の login へ送る', async ({ page }) => {
    await openAs(page, 'company', '/profile');
    await expect(page.getByRole('heading', { name: 'プロフィール情報' })).toBeVisible();

    await page.route('**/api/user', (route) => route.fulfill(COMPANY_SUSPENDED));
    await page.reload();

    // handler と router guard の遷移が競うと `/login?redirect=%2Fprofile` が勝ち、停止の旨が消える
    await expect(page).toHaveURL('/login?company_suspended=1');
    await expect(page.getByRole('alert').filter({ hasText: 'この企業アカウントは現在利用できません。' })).toBeVisible();
});
