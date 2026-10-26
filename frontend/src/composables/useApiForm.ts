import { reactive, ref, watch } from 'vue';
import { ApiError, isApiError } from '@/api/client';

// 旧 Inertia `useForm` の置き換え（docs/inertia-removal-migration.md §3.3, §8）。
// 422 は field ごとの最初の message を errors に入れ、それ以外の ApiError は error に入れて
// field error と混ぜない。401 は client の onUnauthenticated、419 は client の再送で処理済み。

const RECENTLY_SUCCESSFUL_MS = 2000;

type FormData = Record<string, string | boolean>;

export interface SubmitOptions<R> {
    onSuccess?: (result: R) => void | Promise<void>;
}

export function useApiForm<T extends FormData>(initial: T) {
    const defaults = { ...initial };
    const data = reactive({ ...initial }) as T;
    // reset-password の `token` のように入力欄の無い field の error も受けるため、key は string にする。
    const errors = ref<Record<string, string>>({});
    const error = ref<ApiError | null>(null);
    const processing = ref(false);
    const recentlySuccessful = ref(false);

    let recentlySuccessfulTimer: ReturnType<typeof setTimeout> | undefined;
    let resetting = false;

    // 入力を変えたら、その field の error だけ消す（§8 validation error）。
    // 失敗後の reset('password') では error を残すため、sync で実行して reset 中の変更を見分ける。
    for (const key of Object.keys(defaults)) {
        watch(
            () => data[key],
            () => {
                if (!resetting) {
                    delete errors.value[key];
                }
            },
            { flush: 'sync' },
        );
    }

    /**
     * 値を初期値へ戻す。field error は消さない（消すのは clearErrors）。
     */
    function reset(...fields: (keyof T & string)[]): void {
        const targets = fields.length > 0 ? fields : (Object.keys(defaults) as (keyof T & string)[]);

        resetting = true;

        try {
            for (const field of targets) {
                data[field] = defaults[field];
            }
        } finally {
            resetting = false;
        }
    }

    function clearErrors(...fields: string[]): void {
        if (fields.length === 0) {
            errors.value = {};
            return;
        }

        for (const field of fields) {
            delete errors.value[field];
        }
    }

    /**
     * 成功なら true。二重送信は processing 中に無視する。
     */
    async function submit<R>(action: (payload: T) => Promise<R>, options: SubmitOptions<R> = {}): Promise<boolean> {
        if (processing.value) {
            return false;
        }

        processing.value = true;
        errors.value = {};
        error.value = null;
        recentlySuccessful.value = false;

        try {
            const result = await action({ ...data });

            await options.onSuccess?.(result);

            clearTimeout(recentlySuccessfulTimer);
            recentlySuccessful.value = true;
            recentlySuccessfulTimer = setTimeout(() => {
                recentlySuccessful.value = false;
            }, RECENTLY_SUCCESSFUL_MS);

            return true;
        } catch (caught) {
            if (!isApiError(caught)) {
                throw caught;
            }

            if (caught.code === 'validation_failed') {
                errors.value = Object.fromEntries(
                    Object.entries(caught.errors).flatMap(([field, messages]) =>
                        messages[0] === undefined ? [] : [[field, messages[0]]],
                    ),
                );
            } else {
                error.value = caught;
            }

            return false;
        } finally {
            processing.value = false;
        }
    }

    return { data, errors, error, processing, recentlySuccessful, submit, reset, clearErrors };
}
