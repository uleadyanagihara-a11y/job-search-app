import { expect, test } from '@playwright/test';
import { createUser, latestMailText, PASSWORD, uniqueEmail } from './helpers';

// guest auth 画面（Login、Register、ForgotPassword、ResetPassword）を Nginx の入口からブラウザで操作する。
// 422 が各 field に表示されること（Phase 4 の完了条件）もここで確かめる。

test('register: 422 を field に表示し、直して送ると未認証メールの画面へ進む', async ({ page }) => {
    await page.goto('/register');

    await page.getByLabel('Name').fill('E2E User');
    await page.getByLabel('Email').fill(uniqueEmail('register'));
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByLabel('Confirm Password').fill('Mismatch-0000!');
    await page.getByRole('button', { name: 'Register' }).click();

    // password 系は失敗後に空へ戻り、error は password の下に残る
    await expect(page.locator('#password-error')).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
    await expect(page).toHaveURL('/register');

    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByLabel('Confirm Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Register' }).click();

    await expect(page).toHaveURL('/verify-email');
});

test('login: 誤った password は email に 422 を表示し、正しい password で intended へ進む', async ({ browser, page }) => {
    const email = uniqueEmail('login');
    await createUser(browser, email);

    await page.goto('/profile');
    await expect(page).toHaveURL('/login?redirect=/profile');

    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('Wrong-Passw0rd!');
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page.locator('#email-error')).toBeVisible();
    await expect(page.getByLabel('Password')).toHaveValue('');

    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL('/profile');
    await expect(page).toHaveTitle('Profile - Job Search App');
});

test('forgot/reset password: メールの URL で password を変え、login 画面に結果を表示する', async ({ browser, page, request }) => {
    const email = uniqueEmail('reset');
    await createUser(browser, email);

    await page.goto('/login');
    await page.getByRole('link', { name: 'Forgot your password?' }).click();
    await expect(page).toHaveURL('/forgot-password');

    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: 'Email Password Reset Link' }).click();
    await expect(page.getByRole('status')).toBeVisible();

    // §5.3 の SPA 直接遷移 URL（/reset-password/{token}?email=...）がメールに入っている
    const resetUrl = /http:\/\/localhost\/reset-password\/[^\s)\]]+/.exec(await latestMailText(request, email))?.[0];
    expect(resetUrl).toBeDefined();

    await page.goto(resetUrl!);
    await expect(page).toHaveTitle('Reset Password - Job Search App');
    await expect(page.getByLabel('Email')).toHaveValue(email);

    const newPassword = 'N3w-E2e-Passw0rd!';
    await page.getByLabel('Password', { exact: true }).fill(newPassword);
    await page.getByLabel('Confirm Password').fill(newPassword);
    await page.getByRole('button', { name: 'Reset Password' }).click();

    // token/email を含まない /login へ replace し、reset URL へは戻れない
    await expect(page).toHaveURL('/login');
    await expect(page.getByRole('status')).toBeVisible();

    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(newPassword);
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL('/verify-email');
});

test('reset password: 無効な token は token の error を表示する', async ({ page }) => {
    await page.goto(`/reset-password/invalid-token?email=${encodeURIComponent(uniqueEmail('invalid'))}`);

    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByLabel('Confirm Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Reset Password' }).click();

    await expect(page.locator('#token-error')).toBeVisible();
    await expect(page).toHaveURL(/\/reset-password\/invalid-token/);
});
