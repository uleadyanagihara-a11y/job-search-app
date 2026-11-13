import { apiRequest, expectShape } from '@/api/client';
import {
    isInvitationSummaryResponse,
    isUserWithRedirectResponse,
    type InvitationSummary,
    type UserWithRedirectResponse,
} from '@/types/api';

// 企業担当者の招待（docs/domain-api-contract.md M2.3）。
// token は API の path に入れず body で送る（access log に残さないため。docs/domain-design.md 2 章）。

export async function lookupInvitation(payload: { token: string }): Promise<InvitationSummary> {
    return expectShape(
        await apiRequest('POST', '/company-invitations/lookup', { body: payload }),
        isInvitationSummaryResponse,
    ).data;
}

export interface AcceptInvitationPayload {
    token: string;
    name: string;
    password: string;
    password_confirmation: string;
}

export async function acceptInvitation(payload: AcceptInvitationPayload): Promise<UserWithRedirectResponse> {
    return expectShape(
        await apiRequest('POST', '/company-invitations/accept', { body: payload }),
        isUserWithRedirectResponse,
    );
}
