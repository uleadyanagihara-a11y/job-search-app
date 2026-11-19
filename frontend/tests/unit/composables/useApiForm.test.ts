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

    it('422 のうち form の field に無い key は、errors に入れず error に入れる', async () => {
        const form = useApiForm({ email: '' });

        await form.submit(async () => {
            throw new ApiError(422, 'validation_failed', '入力内容を確認してください。', {
                email: ['形式が不正です。'],
                'skills.0': ['スキルは必須です。', '2 件目'],
            });
        });

        expect(form.errors.value).toEqual({ email: '形式が不正です。' });
        expect(form.error.value).toMatchObject({
            status: 422,
            code: 'validation_failed',
            message: 'スキルは必須です。',
            errors: { 'skills.0': ['スキルは必須です。', '2 件目'] },
        });
    });

    it('fieldsWithoutError の key は、data にあっても error に入れる', async () => {
        const form = useApiForm({ email: '', remember: false }, { fieldsWithoutError: ['remember'] });

        await form.submit(async () => {
            throw new ApiError(422, 'validation_failed', 'invalid', { remember: ['真偽値で指定してください。'] });
        });

        expect(form.errors.value).toEqual({});
        expect(form.error.value?.message).toBe('真偽値で指定してください。');
    });

    it('422 の key がすべて form の field なら error は null のまま', async () => {
        const form = useApiForm({ email: '' });
        form.error.value = new ApiError(429, 'too_many_requests', 'x');

        await form.submit(async () => {
            throw new ApiError(422, 'validation_failed', 'invalid', { email: ['x'] });
        });

        expect(form.error.value).toBeNull();
    });

    it('Object の prototype の名前（constructor 等）の key も field に無いものとして扱う', async () => {
        const form = useApiForm({ email: '' });

        await form.submit(async () => {
            throw new ApiError(422, 'validation_failed', 'invalid', { constructor: ['x'] });
        });

        expect(form.errors.value).toEqual({});
        expect(form.error.value?.message).toBe('x');
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

    it('失敗後に reset した field の error は消えない', async () => {
        const form = useApiForm({ email: '', password: '' });
        form.data.password = 'wrong';
        await form.submit(async () => {
            throw new ApiError(422, 'validation_failed', 'invalid', { password: ['x'] });
        });

        form.reset('password');
        await nextTick();

        expect(form.data.password).toBe('');
        expect(form.errors.value).toEqual({ password: 'x' });
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
