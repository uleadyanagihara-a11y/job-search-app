import { expect, test, type Page } from '@playwright/test';
import { latestMailText, PASSWORD, registerViaApi, uniqueEmail } from './helpers';

// profile の 3 操作（情報更新・password 更新・account 削除）を Nginx の入口からブラウザで操作する。
// user の用意は 1 回にまとめる（register の rate limit を超えたら registerViaApi が待つ）。

const NEW_PASSWORD = 'E2e-NewPassw0rd!';

function section(page: Page, heading: string) {
    return page.locator('section', { has: page.getByRole('heading', { name: heading, exact: true }) });
}

test('認証済みの user が profile を更新し、password を変え、account を削除する', async ({ page, request }) => {
    const email = uniqueEmail('profile');

    await page.goto('/login');
    expect(await registerViaApi(page, email)).toBe(201);

    const verifyUrl = /http:\/\/localhost\/auth\/email\/verify\/[^\s)\]]+/.exec(await latestMailText(request, email))?.[0];
    expect(verifyUrl).toBeDefined();
    await page.goto(verifyUrl!);
    await expect(page).toHaveURL('/dashboard?verified=1');

    await page.goto('/profile');
    await expect(page).toHaveTitle('プロフィール - Job Search App');
    await expect(page.getByRole('heading', { name: 'プロフィール', exact: true })).toBeVisible();

    // --- profile 情報 ---
    const info = section(page, 'プロフィール情報');
    await expect(info.getByLabel('名前', { exact: true })).toHaveValue('E2E User');
    await expect(info.getByLabel('メールアドレス', { exact: true })).toHaveValue(email);
    await expect(info.getByText('メールアドレスが確認されていません。')).toHaveCount(0);

    // 422 は field に表示する（email は小文字のみ）
    await info.getByLabel('メールアドレス', { exact: true }).fill(email.toUpperCase());
    await info.getByRole('button', { name: '保存' }).click();
    await expect(page.locator('#email-error')).not.toBeEmpty();
    await expect(info.getByLabel('メールアドレス', { exact: true })).toHaveAttribute('aria-invalid', 'true');

    // name と email を変えると Saved. が出て、layout の user 名も更新される
    const newEmail = uniqueEmail('profile-new');
    await info.getByLabel('名前', { exact: true }).fill('Renamed User');
    await info.getByLabel('メールアドレス', { exact: true }).fill(newEmail);
    await info.getByRole('button', { name: '保存' }).click();
    await expect(info.getByRole('status')).toHaveText('保存しました。');
    await expect(page.getByRole('button', { name: 'Renamed User' })).toBeVisible();

    // email を変えると未認証に戻り、確認メールを新しい宛先へ再送できる
    await expect(info.getByText('メールアドレスが確認されていません。')).toBeVisible();
    await info.getByRole('button', { name: '確認メールを再送信する' }).click();
    await expect(info.getByRole('status').filter({ hasText: '確認メールを送信しました。' })).toBeVisible();
    expect(await latestMailText(request, newEmail)).toContain('/auth/email/verify/');

    // reload しても GET /api/profile から更新後の値で初期化される
    await page.reload();
    await expect(info.getByLabel('名前', { exact: true })).toHaveValue('Renamed User');
    await expect(info.getByLabel('メールアドレス', { exact: true })).toHaveValue(newEmail);

    // --- password 更新 ---
    const password = section(page, 'パスワードの変更');
    await password.getByLabel('現在のパスワード').fill('Wrong-Passw0rd!');
    await password.getByLabel('新しいパスワード', { exact: true }).fill(NEW_PASSWORD);
    await password.getByLabel('新しいパスワード（確認）').fill(NEW_PASSWORD);
    await password.getByRole('button', { name: '保存' }).click();
    await expect(page.locator('#update_password_current_password-error')).not.toBeEmpty();
    await expect(password.getByLabel('現在のパスワード')).toHaveValue('');
    await expect(password.getByLabel('現在のパスワード')).toBeFocused();
    await expect(password.getByLabel('新しいパスワード', { exact: true })).toHaveValue(NEW_PASSWORD);

    await password.getByLabel('現在のパスワード').fill(PASSWORD);
    await password.getByRole('button', { name: '保存' }).click();
    await expect(password.getByRole('status')).toHaveText('保存しました。');
    await expect(password.getByLabel('新しいパスワード', { exact: true })).toHaveValue('');

    // --- account 削除 ---
    const dialog = page.getByRole('dialog', { name: 'アカウントを削除してもよろしいですか？' });

    // Escape で閉じ（Modal の dialog の挙動はここで確認する）、開き直しても入力は残らない
    await section(page, 'アカウントの削除').getByRole('button', { name: 'アカウントを削除' }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('パスワード', { exact: true })).toBeFocused();
    await dialog.getByLabel('パスワード', { exact: true }).fill('typed');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    await section(page, 'アカウントの削除').getByRole('button', { name: 'アカウントを削除' }).click();
    await expect(dialog.getByLabel('パスワード', { exact: true })).toHaveValue('');

    // 変更前の password は 422（Enter で送信できる）
    await dialog.getByLabel('パスワード', { exact: true }).fill(PASSWORD);
    await dialog.getByLabel('パスワード', { exact: true }).press('Enter');
    await expect(page.locator('#delete_user_password-error')).not.toBeEmpty();
    await expect(dialog.getByLabel('パスワード', { exact: true })).toHaveValue('');
    await expect(dialog).toBeVisible();

    await dialog.getByLabel('パスワード', { exact: true }).fill(NEW_PASSWORD);
    await dialog.getByRole('button', { name: 'アカウントを削除' }).click();
    await expect(page).toHaveURL('/login');
    expect((await page.request.get('/api/user', { headers: { Accept: 'application/json' } })).status()).toBe(401);

    // 削除した account ではログインできない
    await page.getByLabel('メールアドレス').fill(newEmail);
    await page.getByLabel('パスワード').fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'ログイン' }).click();
    await expect(page.locator('#email-error')).not.toBeEmpty();
    await expect(page).toHaveURL('/login');
});
