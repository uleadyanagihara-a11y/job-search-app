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
    await expect(page).toHaveTitle('Profile - Job Search App');
    await expect(page.getByRole('heading', { name: 'Profile', exact: true })).toBeVisible();

    // --- profile 情報 ---
    const info = section(page, 'Profile Information');
    await expect(info.getByLabel('Name', { exact: true })).toHaveValue('E2E User');
    await expect(info.getByLabel('Email', { exact: true })).toHaveValue(email);
    await expect(info.getByText('Your email address is unverified.')).toHaveCount(0);

    // 422 は field に表示する（email は小文字のみ）
    await info.getByLabel('Email', { exact: true }).fill(email.toUpperCase());
    await info.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#email-error')).not.toBeEmpty();
    await expect(info.getByLabel('Email', { exact: true })).toHaveAttribute('aria-invalid', 'true');

    // name と email を変えると Saved. が出て、layout の user 名も更新される
    const newEmail = uniqueEmail('profile-new');
    await info.getByLabel('Name', { exact: true }).fill('Renamed User');
    await info.getByLabel('Email', { exact: true }).fill(newEmail);
    await info.getByRole('button', { name: 'Save' }).click();
    await expect(info.getByRole('status')).toHaveText('Saved.');
    await expect(page.getByRole('button', { name: 'Renamed User' })).toBeVisible();

    // email を変えると未認証に戻り、確認メールを新しい宛先へ再送できる
    await expect(info.getByText('Your email address is unverified.')).toBeVisible();
    await info.getByRole('button', { name: 'Click here to re-send the verification email.' }).click();
    await expect(info.getByRole('status').filter({ hasText: '確認メールを送信しました。' })).toBeVisible();
    expect(await latestMailText(request, newEmail)).toContain('/auth/email/verify/');

    // reload しても GET /api/profile から更新後の値で初期化される
    await page.reload();
    await expect(info.getByLabel('Name', { exact: true })).toHaveValue('Renamed User');
    await expect(info.getByLabel('Email', { exact: true })).toHaveValue(newEmail);

    // --- password 更新 ---
    const password = section(page, 'Update Password');
    await password.getByLabel('Current Password').fill('Wrong-Passw0rd!');
    await password.getByLabel('New Password').fill(NEW_PASSWORD);
    await password.getByLabel('Confirm Password').fill(NEW_PASSWORD);
    await password.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#update_password_current_password-error')).not.toBeEmpty();
    await expect(password.getByLabel('Current Password')).toHaveValue('');
    await expect(password.getByLabel('Current Password')).toBeFocused();
    await expect(password.getByLabel('New Password')).toHaveValue(NEW_PASSWORD);

    await password.getByLabel('Current Password').fill(PASSWORD);
    await password.getByRole('button', { name: 'Save' }).click();
    await expect(password.getByRole('status')).toHaveText('Saved.');
    await expect(password.getByLabel('New Password')).toHaveValue('');

    // --- account 削除 ---
    const dialog = page.getByRole('dialog', { name: 'Are you sure you want to delete your account?' });

    // Escape で閉じ（Modal の dialog の挙動はここで確認する）、開き直しても入力は残らない
    await section(page, 'Delete Account').getByRole('button', { name: 'Delete Account' }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Password', { exact: true })).toBeFocused();
    await dialog.getByLabel('Password', { exact: true }).fill('typed');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    await section(page, 'Delete Account').getByRole('button', { name: 'Delete Account' }).click();
    await expect(dialog.getByLabel('Password', { exact: true })).toHaveValue('');

    // 変更前の password は 422（Enter で送信できる）
    await dialog.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await dialog.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page.locator('#delete_user_password-error')).not.toBeEmpty();
    await expect(dialog.getByLabel('Password', { exact: true })).toHaveValue('');
    await expect(dialog).toBeVisible();

    await dialog.getByLabel('Password', { exact: true }).fill(NEW_PASSWORD);
    await dialog.getByRole('button', { name: 'Delete Account' }).click();
    await expect(page).toHaveURL('/login');
    expect((await page.request.get('/api/user', { headers: { Accept: 'application/json' } })).status()).toBe(401);

    // 削除した account ではログインできない
    await page.getByLabel('Email').fill(newEmail);
    await page.getByLabel('Password').fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.locator('#email-error')).not.toBeEmpty();
    await expect(page).toHaveURL('/login');
});
