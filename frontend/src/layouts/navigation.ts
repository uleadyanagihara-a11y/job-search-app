import type { Role } from '@/types/api';

// AuthenticatedLayout の上段の navigation に並べる項目。desktop と responsive menu はこの一覧から描画し、
// 片方だけに項目を足すことが無いようにする。
// roles のある項目は、そのロールの user にだけ出す。route の meta.roles と同じ値を書く（docs/domain-api-contract.md M2.6）。
// 一致は tests/router/navigation.test.ts が本物の routes と照らして確かめる。
export interface NavigationItem {
    name: string;
    label: string;
    roles?: readonly Role[];
}

export const navigationItems: readonly NavigationItem[] = [
    { name: 'dashboard', label: 'ダッシュボード' },
    { name: 'seeker-profile', label: '求職者プロフィール', roles: ['seeker'] },
];
