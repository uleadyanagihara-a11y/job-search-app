import { vi } from 'vitest';

// src/api/<resource>.ts の unit test 共通の準備。fetch を stub して、送った request と response の扱いだけを見る。
// CSRF・419・error の変換は tests/unit/api/client.test.ts で確認済み。

export function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

/** XSRF-TOKEN を置いた上で fetch を stub し、CSRF Cookie の取得を挟まずに 1 request だけ観測する。 */
export function stubFetch(respond: () => Response | Promise<Response>) {
    document.cookie = 'XSRF-TOKEN=token; path=/';
    const fetchMock = vi.fn<typeof fetch>(async () => respond());
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

export function sentRequests(fetchMock: ReturnType<typeof stubFetch>) {
    return fetchMock.mock.calls.map(([input, init]) => ({
        url: String(input),
        method: init?.method,
        body: typeof init?.body === 'string' ? (JSON.parse(init.body) as unknown) : init?.body,
    }));
}

export async function catchError(promise: Promise<unknown>): Promise<unknown> {
    return promise.then(
        () => null,
        (error: unknown) => error,
    );
}
