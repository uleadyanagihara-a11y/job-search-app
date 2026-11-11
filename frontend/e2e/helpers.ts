import { expect, test, type APIRequestContext, type Browser, type Page } from '@playwright/test';

// E2E の共通手順。Laravel の register は IP 単位で 6 回/分に制限されているため、
// user の用意は 1 test 1 回にとどめる（超えた分は registerViaApi が Retry-After だけ待つ）。

export const PASSWORD = 'E2e-Passw0rd!';

export function uniqueEmail(label: string): string {
    return `e2e-${label}-${Date.now()}@example.com`;
}

/**
 * SPA の API client と同じ手順（CSRF Cookie → X-XSRF-TOKEN 付きの POST）で register し、status を返す。
 * page の context には session が残る。
 * suite 全体で register の rate limit（IP 単位 6 回/分）を超えたら、429 の Retry-After だけ待って 1 回だけ再送する
 * （spec を足すたびに user の用意を他の spec へ統合しなくて済むようにするため）。
 */
export async function registerViaApi(page: Page, email: string, password = PASSWORD): Promise<number> {
    let result = await postRegister(page, email, password);

    if (result.status === 429 && result.retryAfter !== null) {
        const waitMs = (result.retryAfter + 1) * 1000;

        test.info().setTimeout(test.info().timeout + waitMs);
        await page.waitForTimeout(waitMs);
        result = await postRegister(page, email, password);
    }

    return result.status;
}

async function postRegister(
    page: Page,
    email: string,
    password: string,
): Promise<{ status: number; retryAfter: number | null }> {
    return page.evaluate(
        async ({ email, password }) => {
            await fetch('/sanctum/csrf-cookie', { headers: { Accept: 'application/json' } });

            const token = document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.slice('XSRF-TOKEN='.length);

            const response = await fetch('/api/auth/register', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
                },
                body: JSON.stringify({ name: 'E2E User', email, password, password_confirmation: password }),
            });
            const retryAfter = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);

            return { status: response.status, retryAfter: Number.isNaN(retryAfter) ? null : retryAfter };
        },
        { email, password },
    );
}

/**
 * 別の browser context で user を作り、その session は捨てる（呼び出し側の page は未ログインのまま）。
 */
export async function createUser(browser: Browser, email: string): Promise<void> {
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto('/login');
    expect(await registerViaApi(page, email)).toBe(201);

    await context.close();
}

// laravel.e2e は開発と同じ Mailpit に送る。frontend-e2e は nginx-e2e の network namespace 経由で sail network にいる。
const MAILPIT_API = process.env.E2E_MAILPIT_URL ?? 'http://mailpit:8025/api/v1';

/**
 * 宛先が email の最新のメール本文（text）を返す。開発のメールと混ざらないよう、宛先は一意な email にする。
 */
export async function latestMailText(request: APIRequestContext, email: string): Promise<string> {
    let id: string | undefined;

    await expect
        .poll(async () => {
            const response = await request.get(`${MAILPIT_API}/search`, { params: { query: `to:"${email}"` } });
            const body = (await response.json()) as { messages?: { ID: string }[] };
            id = body.messages?.[0]?.ID;

            return id;
        })
        .toBeDefined();

    const response = await request.get(`${MAILPIT_API}/message/${id}`);
    const body = (await response.json()) as { Text: string };

    return body.Text;
}
