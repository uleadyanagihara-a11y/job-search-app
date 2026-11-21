import { describe, expect, it } from 'vitest';
import * as invitationsApi from '@/api/invitations';
import { isApiError } from '@/api/client';
import type { User } from '@/types/api';
import { catchError, jsonResponse, sentRequests, stubFetch } from './helpers';

// 企業担当者の招待（docs/domain-api-contract.md M2.3）の endpoint・body と、response の形の検証。
// token は API の path・query に入れず body で送る（access log に残さないため。docs/domain-design.md 2 章）。

const token = 'secret-invitation-token-0123456789';
const summary = { company_name: '株式会社テスト', email: 'invited@example.com', expires_at: '2026-11-28T00:00:00Z' };
const companyUser: User = { id: 2, name: 'Invited', email: 'invited@example.com', email_verified_at: '2026-11-21T00:00:00Z', role: 'company', company_id: 1 };

describe('lookupInvitation', () => {
    it('token を body で POST し、URL には入れない', async () => {
        const fetchMock = stubFetch(() => jsonResponse(200, { data: summary }));

        await expect(invitationsApi.lookupInvitation({ token })).resolves.toEqual(summary);

        const requests = sentRequests(fetchMock);
        expect(requests).toEqual([{ url: '/api/company-invitations/lookup', method: 'POST', body: { token } }]);
        expect(requests[0]?.url).not.toContain(token);
    });

    it.each([
        ['company_name が無い', { email: summary.email, expires_at: summary.expires_at }],
        ['email が null', { ...summary, email: null }],
        ['expires_at が数値', { ...summary, expires_at: 1764288000 }],
    ])('契約外の形（%s）は unexpected_response にする', async (_, data) => {
        stubFetch(() => jsonResponse(200, { data }));

        const caught = await catchError(invitationsApi.lookupInvitation({ token }));

        expect(isApiError(caught) && caught.code).toBe('unexpected_response');
    });

    it('422 errors.token・409 conflict は code を持つ ApiError として投げる', async () => {
        stubFetch(() => jsonResponse(422, { code: 'validation_failed', message: '入力内容を確認してください。', errors: { token: ['無効です。'] } }));
        const invalid = await catchError(invitationsApi.lookupInvitation({ token }));
        expect(isApiError(invalid) && invalid.errors).toEqual({ token: ['無効です。'] });

        stubFetch(() => jsonResponse(409, { code: 'conflict', message: '競合しました。' }));
        const conflict = await catchError(invitationsApi.lookupInvitation({ token }));
        expect(isApiError(conflict) && conflict.code).toBe('conflict');
    });
});

describe('acceptInvitation', () => {
    const payload = { token, name: 'Invited', password: 'Passw0rd!', password_confirmation: 'Passw0rd!' };

    it('token と入力を body で POST し、URL には token を入れず、user と redirect_to を返す', async () => {
        const response = { data: companyUser, meta: { redirect_to: '/dashboard' } };
        const fetchMock = stubFetch(() => jsonResponse(201, response));

        await expect(invitationsApi.acceptInvitation(payload)).resolves.toEqual(response);

        const requests = sentRequests(fetchMock);
        expect(requests).toEqual([{ url: '/api/company-invitations/accept', method: 'POST', body: payload }]);
        expect(requests[0]?.url).not.toContain(token);
    });

    it.each([
        ['meta.redirect_to が無い', { data: companyUser, meta: {} }],
        ['user の role が無い', { data: { ...companyUser, role: undefined }, meta: { redirect_to: '/dashboard' } }],
    ])('契約外の形の response（%s）は unexpected_response にする', async (_, response) => {
        stubFetch(() => jsonResponse(201, response));

        const caught = await catchError(invitationsApi.acceptInvitation(payload));

        expect(isApiError(caught) && caught.code).toBe('unexpected_response');
    });
});
