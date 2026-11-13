import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import * as invitationsApi from '@/api/invitations';
import { useAuthStore } from '@/stores/auth';
import type { InvitationSummary, User } from '@/types/api';
import InvitationAcceptView from '@/views/auth/InvitationAcceptView.vue';
import { mountAt, validationError } from './helpers';

vi.mock('@/api/invitations', () => ({ lookupInvitation: vi.fn(), acceptInvitation: vi.fn() }));

const TOKEN = 'a'.repeat(64);
const invitation: InvitationSummary = {
    company_name: '招待株式会社',
    email: 'invited@example.com',
    expires_at: '2026-11-20T00:00:00Z',
};
const member: User = {
    id: 10,
    name: '招待 太郎',
    email: 'invited@example.com',
    email_verified_at: '2026-11-13T00:00:00Z',
    role: 'company',
    company_id: 3,
};
const invalidToken = () => validationError({ token: ['招待のリンクが無効か、有効期限が切れています。'] });
const conflict = () => new ApiError(409, 'conflict', '現在の状態ではこの操作を実行できません。');

// auth store は module scope の状態を持つため、前の test の user を消す。
beforeEach(() => {
    useAuthStore().clear();
});

async function mountView() {
    const mounted = await mountAt(InvitationAcceptView, `/invitations/${TOKEN}`);
    await flushPromises();

    return mounted;
}

async function fillAndSubmit(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper']) {
    await wrapper.get('#name').setValue('招待 太郎');
    await wrapper.get('#password').setValue('password');
    await wrapper.get('#password_confirmation').setValue('password');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}

describe('InvitationAcceptView', () => {
    it('token を body で lookup し、会社名と変更できない email を表示する', async () => {
        vi.mocked(invitationsApi.lookupInvitation).mockResolvedValue(invitation);

        const { wrapper } = await mountView();

        expect(invitationsApi.lookupInvitation).toHaveBeenCalledWith({ token: TOKEN });
        expect(wrapper.text()).toContain('「招待株式会社」の担当者として招待されています。');
        expect((wrapper.get('#email').element as HTMLInputElement).value).toBe('invited@example.com');
        expect(wrapper.get('#email').attributes('readonly')).toBeDefined();
    });

    it('受諾すると email を送らずに token・名前・password を送り、auth store を更新して redirect_to へ移動する', async () => {
        vi.mocked(invitationsApi.lookupInvitation).mockResolvedValue(invitation);
        vi.mocked(invitationsApi.acceptInvitation).mockResolvedValue({ data: member, meta: { redirect_to: '/dashboard' } });
        const { router, wrapper } = await mountView();

        await fillAndSubmit(wrapper);

        expect(invitationsApi.acceptInvitation).toHaveBeenCalledWith({
            token: TOKEN,
            name: '招待 太郎',
            password: 'password',
            password_confirmation: 'password',
        });
        expect(useAuthStore().user.value).toEqual(member);
        expect(router.currentRoute.value.fullPath).toBe('/dashboard');
    });

    it('無効・期限切れの token は理由を出し、form を出さない', async () => {
        vi.mocked(invitationsApi.lookupInvitation).mockRejectedValue(invalidToken());

        const { wrapper } = await mountView();

        expect(wrapper.get('[role="alert"]').text()).toBe('招待のリンクが無効か、有効期限が切れています。');
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('409（停止中・登録済み）は運営への問い合わせを案内し、form を出さない', async () => {
        vi.mocked(invitationsApi.lookupInvitation).mockRejectedValue(conflict());

        const { wrapper } = await mountView();

        expect(wrapper.get('[role="alert"]').text()).toContain('この招待は現在受けられません。');
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('その他の失敗は再読み込みで lookup し直せる', async () => {
        vi.mocked(invitationsApi.lookupInvitation).mockRejectedValueOnce(
            new ApiError(0, 'network_error', 'サーバーに接続できませんでした。'),
        );
        const { wrapper } = await mountView();

        expect(wrapper.get('[role="alert"]').text()).toContain('サーバーに接続できませんでした。');

        vi.mocked(invitationsApi.lookupInvitation).mockResolvedValue(invitation);
        await wrapper.findAll('button').find((b) => b.text() === '再読み込み')!.trigger('click');
        await flushPromises();

        expect(wrapper.find('form').exists()).toBe(true);
    });

    it('422 の name・password は field に出し、password を消す', async () => {
        vi.mocked(invitationsApi.lookupInvitation).mockResolvedValue(invitation);
        vi.mocked(invitationsApi.acceptInvitation).mockRejectedValue(
            validationError({ password: ['パスワード（確認）と一致しません。'] }),
        );
        const { wrapper } = await mountView();

        await fillAndSubmit(wrapper);

        expect(wrapper.get('#password-error').text()).toBe('パスワード（確認）と一致しません。');
        expect(wrapper.get('#password').attributes('aria-invalid')).toBe('true');
        expect((wrapper.get('#password').element as HTMLInputElement).value).toBe('');
        expect((wrapper.get('#name').element as HTMLInputElement).value).toBe('招待 太郎');
        expect(useAuthStore().user.value).toBeNull();
    });

    it.each([
        ['使用済みになっていた（422 token）', invalidToken, '招待のリンクが無効か、有効期限が切れています。'],
        ['停止・登録済みになっていた（409）', conflict, 'この招待は現在受けられません。'],
    ])('受諾の時点で%s場合は form を閉じて理由を出す', async (_, makeError, message) => {
        vi.mocked(invitationsApi.lookupInvitation).mockResolvedValue(invitation);
        vi.mocked(invitationsApi.acceptInvitation).mockRejectedValue(makeError());
        const { wrapper } = await mountView();

        await fillAndSubmit(wrapper);

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toContain(message);
    });
});
