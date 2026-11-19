import { computed, readonly, ref } from 'vue';
import * as authApi from '@/api/auth';
import type { User } from '@/types/api';

// 旧 Inertia の `$page.props.auth.user` を置き換える（docs/inertia-removal-migration.md §3.3, §8）。
// store package は追加せず、module scope の ref を共有する。

const user = ref<User | null>(null);
const loaded = ref(false);
const loadError = ref<unknown>(null);
// 所属企業の停止で server に logout された（403 company_suspended）。次の画面遷移で router guard が読んで消す。
const companySuspended = ref(false);

let pending: Promise<void> | null = null;

// setUser/clear のたびに進める。取得の結果は、通信中に進んでいたら（logout・401 等で状態が確定したら）捨てる（syncFrom）。
let generation = 0;

/**
 * 起動後最初の 1 回だけ GET /api/user を呼ぶ。同時に呼ばれても request は 1 本にまとめる。
 * 401 以外の失敗では loaded にせず、次の呼び出しで再試行する。
 */
function ensureLoaded(): Promise<void> {
    if (loaded.value) {
        return Promise.resolve();
    }

    pending ??= syncFrom(authApi.fetchUser)
        .catch((error: unknown) => {
            // 通信中に setUser/clear で確定していたら、古い失敗で「状態不明」にしない。
            if (loaded.value) {
                return;
            }

            loadError.value = error;
            throw error;
        })
        .finally(() => {
            pending = null;
        });

    return pending;
}

/**
 * login/register/profile 更新の response で受け取った user を反映する（GET の結果は syncFrom を通す）。
 * 世代を照合しないため、送信中に logout されると user が戻る。通信の完了後に呼ぶ書き込みを足す前に
 * 対応を決める（docs/domain-design.md 13 章。期限は M6）。
 */
function setUser(next: User): void {
    generation++;
    user.value = next;
    loaded.value = true;
    companySuspended.value = false;
    // loaded にすると ensureLoaded は再試行しないため、ここで消さないと App.vue の警告が残り続ける。
    loadError.value = null;
}

/**
 * logout/account 削除/401/403 company_suspended の後に呼ぶ。再取得は不要なので loaded のままにする。
 * `companySuspended` は、所属企業の停止で logout されたことを次の画面遷移で login 画面に伝えるときに付ける。
 */
function clear(options: { companySuspended?: boolean } = {}): void {
    generation++;
    user.value = null;
    loaded.value = true;
    loadError.value = null;
    companySuspended.value = options.companySuspended ?? false;
}

/**
 * 所属企業の停止で logout されたかを返し、印を消す（router guard が 1 度だけ login へ送るため）。
 */
function takeCompanySuspended(): boolean {
    const suspended = companySuspended.value;
    companySuspended.value = false;

    return suspended;
}

/**
 * user を GET する通信の結果を反映する（null は未ログイン）。通信中に setUser/clear があれば捨てる。
 */
async function syncFrom(fetch: () => Promise<User | null>): Promise<void> {
    const started = generation;
    const fetched = await fetch();

    if (started !== generation) {
        return;
    }

    if (fetched === null) {
        clear();
    } else {
        setUser(fetched);
    }
}

/**
 * GET /api/user を取り直す。別 tab でメール認証した等、server 側で user が変わったときに使う。
 */
function refresh(): Promise<void> {
    return syncFrom(authApi.fetchUser);
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
        takeCompanySuspended,
        syncFrom,
        refresh,
        logout,
    };
}
