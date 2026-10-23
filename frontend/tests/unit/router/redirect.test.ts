import { describe, expect, it } from 'vitest';
import { safeRedirectPath } from '@/router/redirect';

describe('safeRedirectPath', () => {
    it.each(['/dashboard', '/profile?tab=password', '/reset-password/abc'])('同一 origin の SPA path %s は受け付ける', (path) => {
        expect(safeRedirectPath(path)).toBe(path);
    });

    it.each([
        ['protocol-relative URL', '//evil.example'],
        ['backslash の protocol-relative URL', '/\\evil.example'],
        ['絶対 URL', 'https://evil.example/'],
        ['相対 path', 'dashboard'],
        ['Laravel の verification callback', '/auth/email/verify/1/hash'],
        ['API path', '/api/user'],
    ])('%s は弾く', (_label, path) => {
        expect(safeRedirectPath(path)).toBeNull();
    });

    it('配列や未指定は弾く', () => {
        expect(safeRedirectPath(['/dashboard'])).toBeNull();
        expect(safeRedirectPath(null)).toBeNull();
        expect(safeRedirectPath(undefined)).toBeNull();
    });
});
