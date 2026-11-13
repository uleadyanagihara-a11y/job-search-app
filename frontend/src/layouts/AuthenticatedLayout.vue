<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ApplicationLogo from '@/components/ApplicationLogo.vue';
import Dropdown from '@/components/Dropdown.vue';
import DropdownLink from '@/components/DropdownLink.vue';
import NavLink from '@/components/NavLink.vue';
import ResponsiveNavLink from '@/components/ResponsiveNavLink.vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

// desktop と responsive menu はこの一覧から描画し、片方だけに項目を足すことが無いようにする。
// navigationItems は上段の navigation、settingsItems は user 名の dropdown（responsive では user 情報の下）に並べる。
const navigationItems = [{ name: 'dashboard', label: 'ダッシュボード' }] as const;
const settingsItems = [{ name: 'profile', label: 'プロフィール' }] as const;

function isActive(name: string): boolean {
    return route.name === name;
}

const showingNavigationDropdown = ref(false);
const loggingOut = ref(false);
const logoutFailed = ref(false);

// 画面遷移したら responsive menu を閉じる（Inertia では page ごとに layout が作り直されていた）。
watch(
    () => route.fullPath,
    () => {
        showingNavigationDropdown.value = false;
    },
);

// logout は link ではなく API 呼び出しの button にする（§3.3）。
async function logout(): Promise<void> {
    if (loggingOut.value) {
        return;
    }

    loggingOut.value = true;
    logoutFailed.value = false;

    try {
        await auth.logout();
        // home は dashboard への redirect なので、未ログインの行き先である login へ直接送る（§6.1）。
        await router.push({ name: 'login' });
    } catch {
        logoutFailed.value = true;
    } finally {
        loggingOut.value = false;
    }
}
</script>

<template>
    <div class="min-h-screen bg-canvas">
        <nav class="border-b border-line-subtle bg-surface">
            <!-- Primary Navigation Menu -->
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <!-- Logo -->
                        <div class="flex shrink-0 items-center">
                            <RouterLink
                                :to="{ name: 'dashboard' }"
                                aria-label="Job Search App"
                            >
                                <ApplicationLogo class="block h-9 w-auto fill-current text-fg" />
                            </RouterLink>
                        </div>

                        <!-- Navigation Links -->
                        <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                            <NavLink
                                v-for="item in navigationItems"
                                :key="item.name"
                                :to="{ name: item.name }"
                                :active="isActive(item.name)"
                            >
                                {{ item.label }}
                            </NavLink>
                        </div>
                    </div>

                    <div
                        v-if="auth.user.value"
                        class="hidden sm:ms-6 sm:flex sm:items-center"
                    >
                        <!-- Settings Dropdown -->
                        <div class="relative ms-3">
                            <Dropdown
                                align="right"
                                width="48"
                            >
                                <template #trigger>
                                    <span class="inline-flex rounded-md">
                                        <button
                                            type="button"
                                            data-user-menu
                                            class="inline-flex items-center rounded-md border border-transparent bg-surface px-3 py-2 text-sm font-medium leading-4 text-fg-subtle transition duration-150 ease-in-out hover:text-fg-secondary focus:outline-hidden"
                                        >
                                            {{ auth.user.value.name }}

                                            <svg
                                                class="-me-0.5 ms-2 h-4 w-4"
                                                xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                        </button>
                                    </span>
                                </template>

                                <template #content>
                                    <DropdownLink
                                        v-for="item in settingsItems"
                                        :key="item.name"
                                        :to="{ name: item.name }"
                                    >
                                        {{ item.label }}
                                    </DropdownLink>
                                    <DropdownLink
                                        :disabled="loggingOut"
                                        @click="logout"
                                    >
                                        ログアウト
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>

                    <!-- Hamburger -->
                    <div class="-me-2 flex items-center sm:hidden">
                        <button
                            type="button"
                            aria-controls="responsive-navigation"
                            :aria-expanded="showingNavigationDropdown ? 'true' : 'false'"
                            class="inline-flex items-center justify-center rounded-md p-2 text-fg-faint transition duration-150 ease-in-out hover:bg-surface-hover hover:text-fg-subtle focus:bg-surface-hover focus:text-fg-subtle focus:outline-hidden"
                            @click="showingNavigationDropdown = !showingNavigationDropdown"
                        >
                            <span class="sr-only">メニュー</span>
                            <svg
                                class="h-6 w-6"
                                stroke="currentColor"
                                fill="none"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    :class="{ hidden: showingNavigationDropdown, 'inline-flex': !showingNavigationDropdown }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16"
                                />
                                <path
                                    :class="{ hidden: !showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Responsive Navigation Menu -->
            <!-- 開閉は v-show（閉じているときは display: none）。sm 以上では常に隠す -->
            <div
                v-show="showingNavigationDropdown"
                id="responsive-navigation"
                class="sm:hidden"
            >
                <div class="space-y-1 pb-3 pt-2">
                    <ResponsiveNavLink
                        v-for="item in navigationItems"
                        :key="item.name"
                        :to="{ name: item.name }"
                        :active="isActive(item.name)"
                    >
                        {{ item.label }}
                    </ResponsiveNavLink>
                </div>

                <!-- Responsive Settings Options -->
                <div
                    v-if="auth.user.value"
                    class="border-t border-line pb-1 pt-4"
                >
                    <div class="px-4">
                        <div class="text-base font-medium text-fg">
                            {{ auth.user.value.name }}
                        </div>
                        <div class="text-sm font-medium text-fg-subtle">
                            {{ auth.user.value.email }}
                        </div>
                    </div>

                    <div class="mt-3 space-y-1">
                        <ResponsiveNavLink
                            v-for="item in settingsItems"
                            :key="item.name"
                            :to="{ name: item.name }"
                            :active="isActive(item.name)"
                        >
                            {{ item.label }}
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :disabled="loggingOut"
                            @click="logout"
                        >
                            ログアウト
                        </ResponsiveNavLink>
                    </div>
                </div>
            </div>
        </nav>

        <div
            v-if="logoutFailed"
            role="alert"
            class="mx-auto max-w-7xl px-4 pt-4 text-sm text-danger-600 sm:px-6 lg:px-8"
        >
            ログアウトできませんでした。時間をおいて再度お試しください。
        </div>

        <!-- Page Heading -->
        <header
            v-if="$slots.header"
            class="bg-surface shadow-sm"
        >
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <main>
            <slot />
        </main>
    </div>
</template>
