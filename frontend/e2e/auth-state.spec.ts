import { expect, test } from '@playwright/test';
import { latestMailText, PASSWORD, registerViaApi, uniqueEmail } from './helpers';

// メール未認証の user の VerifyEmail（再送・callback の error 表示・logout）と ConfirmPassword を、
// Nginx の入口からブラウザで操作する。register の rate limit（IP 単位 6 回/分）があるため user の用意は 1 回にまとめる。

test('未認証の user が確認メールを再送し、password を再確認し、logout 後に確認リンクからログインし直してメール認証を完了する', async ({
    page,
    request,
}) => {
    const email = uniqueEmail('auth-state');

    await page.goto('/login');
    expect(await registerViaApi(page, email)).toBe(201);

    // Cookie + CSRF の register で session が張られ、dashboard は verified 必須なので verify-email へ送られる
    await page.goto('/dashboard');
    await expect(page).toHaveURL('/verify-email');
    await expect(page).toHaveTitle('メールアドレスの確認 - Job Search App');

    await page.getByRole('button', { name: '確認メールを再送信' }).click();
    await expect(page.getByRole('status')).toHaveText('確認メールを送信しました。');

    // callback の失敗（§5.4）は ?error= で受けて表示する
    await page.goto('/verify-email?error=invalid-or-expired');
    await expect(page.getByRole('alert')).toHaveText(
        '確認リンクが無効か、有効期限が切れています。確認メールを再送してください。',
    );

    // password の再確認。誤りは field に、正しければ ?redirect= の画面へ戻る
    await page.goto('/confirm-password?redirect=/profile');
    await expect(page).toHaveTitle('パスワードの確認 - Job Search App');
    await page.getByLabel('パスワード').fill('wrong-password');
    await page.getByRole('button', { name: '確認' }).click();
    await expect(page.locator('#password-error')).not.toBeEmpty();
    await expect(page.getByLabel('パスワード')).toHaveValue('');

    await page.getByLabel('パスワード').fill(PASSWORD);
    await page.getByRole('button', { name: '確認' }).click();
    await expect(page).toHaveURL('/profile');

    // verify-email の Log Out
    await page.goto('/verify-email');
    await page.getByRole('button', { name: 'ログアウト' }).click();
    await expect(page).toHaveURL('/login');
    expect((await page.request.get('/api/user', { headers: { Accept: 'application/json' } })).status()).toBe(401);

    // 未ログインで再送したメールのリンクを開くと、callback は intended に保存して login へ送る（§5.4）
    const verifyUrl = /http:\/\/localhost\/auth\/email\/verify\/[^\s)\]]+/.exec(await latestMailText(request, email))?.[0];
    expect(verifyUrl).toBeDefined();

    await page.goto(verifyUrl!);
    await expect(page).toHaveURL('/login?verification_required=1');
    await expect(page.getByRole('status')).toHaveText('メールアドレスの確認を完了するには、ログインしてください。');

    // ログインすると redirect_to（保存した callback）へ進み、認証を完了して dashboard へ
    await page.getByLabel('メールアドレス').fill(email);
    await page.getByLabel('パスワード').fill(PASSWORD);
    await page.getByRole('button', { name: 'ログイン' }).click();
    await expect(page).toHaveURL('/dashboard?verified=1');

    // 認証済みなら verify-email は dashboard へ送られる

    await page.goto('/verify-email');
    await expect(page).toHaveURL('/dashboard');

    // 認証済みでも callback の失敗（?error=）は guard が通し、error と dashboard への link を表示する
    await page.goto('/verify-email?error=user-mismatch');
    await expect(page).toHaveURL('/verify-email?error=user-mismatch');
    await expect(page.getByRole('alert')).toHaveText('この確認リンクは、ログイン中のアカウントのものではありません。');
    await expect(page.getByRole('link', { name: 'ダッシュボードへ' })).toBeVisible();
});
