import { describe, expect, it } from 'vitest';
import { isApiError } from '@/api/client';
import * as seekerProfileApi from '@/api/seekerProfile';
import { catchError, jsonResponse, sentRequests, stubFetch } from './helpers';

// 求職者プロフィール（docs/domain-api-contract.md M2.3）の endpoint・body と、response の形の検証。

const profile = { desired_prefecture: 13, desired_job_type: 'エンジニア', self_pr: 'よろしくお願いします。', updated_at: '2026-11-21T00:00:00Z' };
const empty = { desired_prefecture: null, desired_job_type: null, self_pr: null, updated_at: null };

describe('fetchSeekerProfile', () => {
    it('GET /api/seeker-profile の data を返す（行が無ければ全 field null）', async () => {
        const fetchMock = stubFetch(() => jsonResponse(200, { data: empty }));

        await expect(seekerProfileApi.fetchSeekerProfile()).resolves.toEqual(empty);

        expect(sentRequests(fetchMock)).toEqual([{ url: '/api/seeker-profile', method: 'GET', body: undefined }]);
    });

    it.each([
        ['desired_prefecture が文字列', { ...profile, desired_prefecture: '13' }],
        ['desired_prefecture が 0', { ...profile, desired_prefecture: 0 }],
        ['desired_prefecture が 48', { ...profile, desired_prefecture: 48 }],
        ['desired_prefecture が小数', { ...profile, desired_prefecture: 13.5 }],
        ['self_pr が無い', { desired_prefecture: 13, desired_job_type: null, updated_at: null }],
        ['updated_at が数値', { ...profile, updated_at: 0 }],
    ])('契約外の形（%s）は unexpected_response にする', async (_, data) => {
        stubFetch(() => jsonResponse(200, { data }));

        const caught = await catchError(seekerProfileApi.fetchSeekerProfile());

        expect(isApiError(caught) && caught.code).toBe('unexpected_response');
    });

    it('企業担当者（403 forbidden）は code を持つ ApiError として投げる', async () => {
        stubFetch(() => jsonResponse(403, { code: 'forbidden', message: 'この操作は許可されていません。' }));

        const caught = await catchError(seekerProfileApi.fetchSeekerProfile());

        expect(isApiError(caught) && caught.code).toBe('forbidden');
    });
});

describe('updateSeekerProfile', () => {
    it('全 field を PUT で送り、保存後の data を返す', async () => {
        const input = { desired_prefecture: null, desired_job_type: 'エンジニア', self_pr: null };
        const saved = { ...input, updated_at: '2026-11-21T00:00:00Z' };
        const fetchMock = stubFetch(() => jsonResponse(200, { data: saved }));

        await expect(seekerProfileApi.updateSeekerProfile(input)).resolves.toEqual(saved);

        expect(sentRequests(fetchMock)).toEqual([{ url: '/api/seeker-profile', method: 'PUT', body: input }]);
    });

    it('契約外の形の data は unexpected_response にする', async () => {
        stubFetch(() => jsonResponse(200, { data: { ...profile, desired_prefecture: '13' } }));

        const caught = await catchError(
            seekerProfileApi.updateSeekerProfile({ desired_prefecture: 13, desired_job_type: null, self_pr: null }),
        );

        expect(isApiError(caught) && caught.code).toBe('unexpected_response');
    });
});
