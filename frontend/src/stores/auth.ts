import { computed, readonly, ref } from 'vue';
import * as authApi from '@/api/auth';
import type { User } from '@/types/api';

// 旧 Inertia の `$page.props.auth.user` を置き換える（docs/inertia-removal-migration.md §3.3, §8）。
// store package は追加せず、module scope の ref を共有する。

const user = ref<User | null>(null);
const loaded = ref(false);
const loadError = ref<unknown>(null);

let pending: Promise<void> | null = null;

/**
 * 起動後最初の 1 回だけ GET /api/user を呼ぶ。同時に呼ばれても request は 1 本にまとめる。
 * 401 以外の失敗では loaded にせず、次の呼び出しで再試行する。
 */
function ensureLoaded(): Promise<void> {
    if (loaded.value) {
        return Promise.resolve();
    }

    pending ??= authApi
        .fetchUser()
        .then((fetched) => {
            user.value = fetched;
            loaded.value = true;
            loadError.value = null;
        })
        .catch((error: unknown) => {
            loadError.value = error;
            throw error;
        })
        .finally(() => {
            pending = null;
        });

    return pending;
}

/**
 * login/register/profile 更新の response で受け取った user を反映する。
 */
function setUser(next: User): void {
    user.value = next;
    loaded.value = true;
    // loaded にすると ensureLoaded は再試行しないため、ここで消さないと App.vue の警告が残り続ける。
    loadError.value = null;
}

/**
 * logout/account 削除/401 の後に呼ぶ。再取得は不要なので loaded のままにする。
 */
function clear(): void {
    user.value = null;
    loaded.value = true;
    loadError.value = null;
}

/**
 * GET /api/user を取り直す。別 tab でメール認証した等、server 側で user が変わったときに使う。
 */
async function refresh(): Promise<void> {
    const fetched = await authApi.fetchUser();

    if (fetched === null) {
        clear();
    } else {
        setUser(fetched);
    }
}

async function logout(): Promise<void> {
    await authApi.logout();
    clear();
}

const isAuthenticated = computed(() => user.value !== null);
const isVerified = computed(() => user.value?.email_verified_at != null);

export function useAuthStore() {
    return {
        user: readonly(user),
        loaded: readonly(loaded),
        loadError: readonly(loadError),
        isAuthenticated,
        isVerified,
        ensureLoaded,
        setUser,
        clear,
        refresh,
        logout,
    };
}
