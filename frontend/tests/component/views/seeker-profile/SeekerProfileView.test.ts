import { flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import * as seekerProfileApi from '@/api/seekerProfile';
import { useAuthStore } from '@/stores/auth';
import type { SeekerProfile } from '@/types/api';
import SeekerProfileView from '@/views/seeker-profile/SeekerProfileView.vue';
import { mountAt, user, validationError } from '../auth/helpers';

vi.mock('@/api/seekerProfile', () => ({ fetchSeekerProfile: vi.fn(), updateSeekerProfile: vi.fn() }));
vi.mock('@/api/auth', () => ({ fetchUser: vi.fn(), logout: vi.fn() }));

const empty: SeekerProfile = { desired_prefecture: null, desired_job_type: null, self_pr: null, updated_at: null };
const filled: SeekerProfile = {
    desired_prefecture: 13,
    desired_job_type: 'Web エンジニア',
    self_pr: '1 行目\n2 行目',
    updated_at: '2026-11-13T00:00:00Z',
};

beforeEach(() => {
    useAuthStore().setUser({ ...user, email_verified_at: '2026-10-01T00:00:00Z' });
});

async function mountView(profile: SeekerProfile = filled) {
    vi.mocked(seekerProfileApi.fetchSeekerProfile).mockResolvedValue(profile);

    const { wrapper } = await mountAt(SeekerProfileView, '/seeker-profile');
    await flushPromises();

    return wrapper;
}

function value(wrapper: Awaited<ReturnType<typeof mountView>>, selector: string): string {
    return (wrapper.get(selector).element as HTMLInputElement).value;
}

describe('SeekerProfileView', () => {
    it('取得した値で form を初期化し、都道府県は 47 件と「選択しない」を出す', async () => {
        const wrapper = await mountView();

        expect(wrapper.get('h1').text()).toBe('求職者プロフィール');
        expect(value(wrapper, '#desired_prefecture')).toBe('13');
        expect(wrapper.get('#desired_prefecture option:checked').text()).toBe('東京都');
        expect(wrapper.findAll('#desired_prefecture option')).toHaveLength(48);
        expect(value(wrapper, '#desired_job_type')).toBe('Web エンジニア');
        expect(value(wrapper, '#self_pr')).toBe('1 行目\n2 行目');
    });

    it('行が無ければ空欄で表示する', async () => {
        const wrapper = await mountView(empty);

        expect(value(wrapper, '#desired_prefecture')).toBe('');
        expect(value(wrapper, '#desired_job_type')).toBe('');
        expect(value(wrapper, '#self_pr')).toBe('');
    });

    it('全 field を API の型で送り、空欄は null にして「保存しました。」を出す', async () => {
        vi.mocked(seekerProfileApi.updateSeekerProfile).mockResolvedValue(empty);
        const wrapper = await mountView();

        await wrapper.get('#desired_prefecture').setValue('27');
        await wrapper.get('#desired_job_type').setValue('  ');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(seekerProfileApi.updateSeekerProfile).toHaveBeenCalledWith({
            desired_prefecture: 27,
            desired_job_type: null,
            self_pr: '1 行目\n2 行目',
        });
        expect(wrapper.get('[role="status"]').text()).toBe('保存しました。');

        await wrapper.get('#desired_prefecture').setValue('');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(vi.mocked(seekerProfileApi.updateSeekerProfile).mock.lastCall?.[0].desired_prefecture).toBeNull();
    });

    it('「保存しました。」の live region は保存の前から描画しておく（中身の変化だけを読み上げさせる）', async () => {
        const wrapper = await mountView();

        expect(wrapper.get('[role="status"]').text()).toBe('');
    });

    it('保存後は server が保存した値（前後の空白を落とした値）で form をそろえる', async () => {
        vi.mocked(seekerProfileApi.updateSeekerProfile).mockResolvedValue({ ...filled, desired_job_type: '営業' });
        const wrapper = await mountView();

        await wrapper.get('#desired_job_type').setValue('  営業  ');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(value(wrapper, '#desired_job_type')).toBe('営業');
    });

    it('送信中に書き換えた field は、保存の成功後も利用者の入力を残す', async () => {
        let resolveSave: (profile: SeekerProfile) => void = () => {};
        vi.mocked(seekerProfileApi.updateSeekerProfile).mockReturnValue(
            new Promise((resolve) => {
                resolveSave = resolve;
            }),
        );
        const wrapper = await mountView();

        await wrapper.get('#desired_job_type').setValue('  営業  ');
        await wrapper.get('form').trigger('submit');
        await wrapper.get('#self_pr').setValue('送信中に書き足した文');
        resolveSave({ ...filled, desired_job_type: '営業' });
        await flushPromises();

        // 送った後に触っていない field は server の値、送信中に書き換えた field は入力のまま
        expect(value(wrapper, '#desired_job_type')).toBe('営業');
        expect(value(wrapper, '#self_pr')).toBe('送信中に書き足した文');
    });

    it('送信中は「保存」を無効にし、二重に送らない', async () => {
        vi.mocked(seekerProfileApi.updateSeekerProfile).mockReturnValue(new Promise(() => {}));
        const wrapper = await mountView();

        await wrapper.get('form').trigger('submit');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.get('button[type="submit"]').element).toHaveProperty('disabled', true);
        expect(seekerProfileApi.updateSeekerProfile).toHaveBeenCalledOnce();
    });

    it('form に無い field の 422 は、何も出さずに終わらず alert に出す', async () => {
        vi.mocked(seekerProfileApi.updateSeekerProfile).mockRejectedValue(
            validationError({ prefecture: ['希望勤務地は、正しい値を選択してください。'] }),
        );
        const wrapper = await mountView();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('希望勤務地は、正しい値を選択してください。');
    });

    it('422 は field の下に出し、aria-invalid を付ける', async () => {
        vi.mocked(seekerProfileApi.updateSeekerProfile).mockRejectedValue(
            validationError({ self_pr: ['自己 PRは、2000文字以下で指定してください。'] }),
        );
        const wrapper = await mountView();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('#self_pr-error').text()).toBe('自己 PRは、2000文字以下で指定してください。');
        expect(wrapper.get('#self_pr').attributes('aria-invalid')).toBe('true');
        // error のときも文字数の hint を読み上げの対象に残す
        expect(wrapper.get('#self_pr').attributes('aria-describedby')).toBe('self_pr-error self_pr-hint');
        // live region は常にあり、文言だけが出ない（SavedStatus）
        expect(wrapper.get('[role="status"]').text()).toBe('');
    });

    it('読み込みに失敗したら alert と再読み込みを出し、再読み込みで form を出す', async () => {
        vi.mocked(seekerProfileApi.fetchSeekerProfile).mockRejectedValueOnce(
            new ApiError(0, 'network_error', 'サーバーに接続できませんでした。'),
        );
        const { wrapper } = await mountAt(SeekerProfileView, '/seeker-profile');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('サーバーに接続できませんでした。');
        expect(wrapper.find('form').exists()).toBe(false);

        vi.mocked(seekerProfileApi.fetchSeekerProfile).mockResolvedValue(filled);
        await wrapper.findAll('button').find((b) => b.text() === '再読み込み')!.trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.find('form').exists()).toBe(true);
    });

    it('403（企業担当者が API を直接開いた等）でも form を出さず、読み込めなかったことを出す', async () => {
        vi.mocked(seekerProfileApi.fetchSeekerProfile).mockRejectedValueOnce(
            new ApiError(403, 'forbidden', 'この操作は許可されていません。'),
        );
        const { wrapper } = await mountAt(SeekerProfileView, '/seeker-profile');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('プロフィールを読み込めませんでした。');
        expect(wrapper.find('form').exists()).toBe(false);
    });
});
