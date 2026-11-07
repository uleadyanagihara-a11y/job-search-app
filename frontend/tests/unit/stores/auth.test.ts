import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@/types/api';

// auth store は module scope の状態を持つため、test ごとに module を読み直す。
// 読み直すと class も別物になる（instanceof が一致しない）ので、ApiError も load() の戻り値を使う。
// vi.mock の factory は作り直されず、mock の呼び出し履歴は restoreMocks（vitest.config.ts）で消している。
vi.mock('@/api/auth', () => ({ fetchUser: vi.fn(), logout: vi.fn() }));

const unverifiedUser: User = { id: 1, name: 'Test', email: 'test@example.com', email_verified_at: null };
const verifiedUser: User = { ...unverifiedUser, email_verified_at: '2026-10-01T00:00:00+09:00' };

async function load() {
    const authApi = await import('@/api/auth');
    const { ApiError } = await import('@/api/client');
    const { useAuthStore } = await import('@/stores/auth');

    return { authApi: vi.mocked(authApi), ApiError, auth: useAuthStore() };
}

beforeEach(() => {
    vi.resetModules();
});

describe('ensureLoaded', () => {
    it('GET /api/user の結果を user に入れ、loaded にする', async () => {
        const { authApi, auth } = await load();
        authApi.fetchUser.mockResolvedValue(verifiedUser);

        await auth.ensureLoaded();

        expect(auth.user.value).toEqual(verifiedUser);
        expect(auth.loaded.value).toBe(true);
        expect(auth.isAuthenticated.value).toBe(true);
    });

    it('未ログイン（null）でも loaded にし、2 回目以降は request しない', async () => {
        const { authApi, auth } = await load();
        authApi.fetchUser.mockResolvedValue(null);

        await auth.ensureLoaded();
        await auth.ensureLoaded();

        expect(auth.user.value).toBeNull();
        expect(auth.loaded.value).toBe(true);
        expect(auth.isAuthenticated.value).toBe(false);
        expect(authApi.fetchUser).toHaveBeenCalledTimes(1);
    });

    it('同時に呼ばれても request は 1 本にまとめる', async () => {
        const { authApi, auth } = await load();
        let resolve: (user: User | null) => void = () => {};
        authApi.fetchUser.mockReturnValue(new Promise((r) => (resolve = r)));

        const first = auth.ensureLoaded();
        const second = auth.ensureLoaded();
        resolve(verifiedUser);
        await Promise.all([first, second]);

        expect(authApi.fetchUser).toHaveBeenCalledTimes(1);
        expect(auth.user.value).toEqual(verifiedUser);
    });

    it('失敗したら loadError を残して loaded にせず、次の呼び出しで再試行して loadError を消す', async () => {
        const { authApi, ApiError, auth } = await load();
        const error = new ApiError(0, 'network_error', 'サーバーに接続できませんでした。');
        authApi.fetchUser.mockRejectedValueOnce(error).mockResolvedValueOnce(verifiedUser);

        await expect(auth.ensureLoaded()).rejects.toBe(error);

        expect(auth.loaded.value).toBe(false);
        expect(auth.loadError.value).toBe(error);

        await auth.ensureLoaded();

        expect(authApi.fetchUser).toHaveBeenCalledTimes(2);
        expect(auth.loaded.value).toBe(true);
        expect(auth.loadError.value).toBeNull();
        expect(auth.user.value).toEqual(verifiedUser);
    });
});

describe('setUser / clear', () => {
    it('setUser は user を入れて loaded にし、以後 GET /api/user を呼ばない', async () => {
        const { authApi, auth } = await load();

        auth.setUser(unverifiedUser);
        await auth.ensureLoaded();

        expect(auth.user.value).toEqual(unverifiedUser);
        expect(authApi.fetchUser).not.toHaveBeenCalled();
    });

    it('clear は user を破棄し、loaded のまま再取得しない', async () => {
        const { authApi, auth } = await load();
        auth.setUser(verifiedUser);

        auth.clear();
        await auth.ensureLoaded();

        expect(auth.user.value).toBeNull();
        expect(auth.isAuthenticated.value).toBe(false);
        expect(authApi.fetchUser).not.toHaveBeenCalled();
    });

    // loaded にすると ensureLoaded が再試行しないため、ここで消さないと App.vue の警告が残り続ける。
    // 使われない mockResolvedValueOnce は後の test に持ち越されるため、refresh の応答は settle の中で仕込む。
    it.each<[string, (loaded: Awaited<ReturnType<typeof load>>) => unknown]>([
        ['setUser', ({ auth }) => auth.setUser(verifiedUser)],
        ['clear', ({ auth }) => auth.clear()],
        [
            'refresh',
            ({ authApi, auth }) => {
                authApi.fetchUser.mockResolvedValueOnce(verifiedUser);

                return auth.refresh();
            },
        ],
    ])('%s で状態が確定したら、以前の loadError を消す', async (_, settle) => {
        const loaded = await load();
        const { authApi, ApiError, auth } = loaded;
        const error = new ApiError(0, 'network_error', 'サーバーに接続できませんでした。');
        authApi.fetchUser.mockRejectedValueOnce(error);
        await expect(auth.ensureLoaded()).rejects.toBe(error);
        expect(auth.loadError.value).toBe(error);

        await settle(loaded);

        expect(auth.loaded.value).toBe(true);
        expect(auth.loadError.value).toBeNull();
    });
});

describe('isVerified', () => {
    it('email_verified_at の有無と user の差し替えに追従する', async () => {
        const { auth } = await load();

        expect(auth.isVerified.value).toBe(false);

        auth.setUser(unverifiedUser);
        expect(auth.isVerified.value).toBe(false);

        auth.setUser(verifiedUser);
        expect(auth.isVerified.value).toBe(true);

        auth.clear();
        expect(auth.isVerified.value).toBe(false);
    });
});

describe('refresh', () => {
    it('loaded でも GET /api/user を取り直して user を差し替える', async () => {
        const { authApi, auth } = await load();
        auth.setUser(unverifiedUser);
        authApi.fetchUser.mockResolvedValue(verifiedUser);

        await auth.refresh();

        expect(authApi.fetchUser).toHaveBeenCalledTimes(1);
        expect(auth.user.value).toEqual(verifiedUser);
        expect(auth.isVerified.value).toBe(true);
    });

    it('未ログイン（null）なら user を破棄する。失敗したら投げ、user は残す', async () => {
        const { authApi, ApiError, auth } = await load();
        auth.setUser(verifiedUser);
        const error = new ApiError(0, 'network_error', 'サーバーに接続できませんでした。');
        authApi.fetchUser.mockRejectedValueOnce(error).mockResolvedValueOnce(null);

        await expect(auth.refresh()).rejects.toBe(error);
        expect(auth.user.value).toEqual(verifiedUser);
        // 前の user は有効なままなので、ログイン状態不明（loadError）にはしない。
        expect(auth.loadError.value).toBeNull();

        await auth.refresh();
        expect(auth.user.value).toBeNull();
        expect(auth.loaded.value).toBe(true);
    });
});

describe('logout', () => {
    it('logout API が成功したら user を破棄する', async () => {
        const { authApi, auth } = await load();
        authApi.logout.mockResolvedValue();
        auth.setUser(verifiedUser);

        await auth.logout();

        expect(authApi.logout).toHaveBeenCalledTimes(1);
        expect(auth.user.value).toBeNull();
    });

    it('logout API が失敗したら投げ、user は残す', async () => {
        const { authApi, ApiError, auth } = await load();
        const error = new ApiError(0, 'network_error', 'サーバーに接続できませんでした。');
        authApi.logout.mockRejectedValue(error);
        auth.setUser(verifiedUser);

        await expect(auth.logout()).rejects.toBe(error);

        expect(auth.user.value).toEqual(verifiedUser);
    });
});
