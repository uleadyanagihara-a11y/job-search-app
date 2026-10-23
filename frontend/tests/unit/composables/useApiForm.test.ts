import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { ApiError } from '@/api/client';
import { useApiForm } from '@/composables/useApiForm';

describe('useApiForm', () => {
    it('422 は field ごとの最初の message を errors に入れ、error には入れない', async () => {
        const form = useApiForm({ email: '', password: '' });

        const ok = await form.submit(async () => {
            throw new ApiError(422, 'validation_failed', '入力内容を確認してください。', {
                email: ['形式が不正です。', '2 件目'],
                password: ['必須です。'],
            });
        });

        expect(ok).toBe(false);
        expect(form.errors.value).toEqual({ email: '形式が不正です。', password: '必須です。' });
        expect(form.error.value).toBeNull();
        expect(form.processing.value).toBe(false);
    });

    it('入力を変えた field の error だけ消える', async () => {
        const form = useApiForm({ email: '', password: '' });
        await form.submit(async () => {
            throw new ApiError(422, 'validation_failed', 'invalid', { email: ['x'], password: ['y'] });
        });

        form.data.email = 'user@example.com';
        await nextTick();

        expect(form.errors.value).toEqual({ password: 'y' });
    });

    it('422 以外の ApiError は error に入れる', async () => {
        const form = useApiForm({ email: '' });
        const failure = new ApiError(429, 'too_many_requests', 'しばらくしてから再試行してください。');

        await form.submit(async () => {
            throw failure;
        });

        expect(form.error.value).toBe(failure);
        expect(form.errors.value).toEqual({});
    });

    it('送信中の二重送信は無視する', async () => {
        const form = useApiForm({ email: '' });
        let resolve!: () => void;
        const action = vi.fn(() => new Promise<void>((r) => (resolve = r)));

        const first = form.submit(action);
        expect(await form.submit(action)).toBe(false);
        resolve();

        expect(await first).toBe(true);
        expect(action).toHaveBeenCalledOnce();
    });
});
