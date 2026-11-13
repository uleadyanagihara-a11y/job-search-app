import { apiRequest, expectShape } from '@/api/client';
import { isSeekerProfileResponse, type SeekerProfile } from '@/types/api';

// docs/domain-api-contract.md M2.3「求職者プロフィール」。企業担当者は 403。

export type SeekerProfileInput = Pick<SeekerProfile, 'desired_prefecture' | 'desired_job_type' | 'self_pr'>;

export async function fetchSeekerProfile(): Promise<SeekerProfile> {
    return expectShape(await apiRequest('GET', '/seeker-profile'), isSeekerProfileResponse).data;
}

/**
 * 全 field を送る置き換え（送らなかった field は server で null になる）。
 */
export async function updateSeekerProfile(payload: SeekerProfileInput): Promise<SeekerProfile> {
    return expectShape(await apiRequest('PUT', '/seeker-profile', { body: payload }), isSeekerProfileResponse).data;
}
