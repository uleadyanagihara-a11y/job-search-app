<script setup lang="ts">
import { useAuthStore } from '@/stores/auth';

// navigation と logout は各 view が使う layout（AuthenticatedLayout / GuestLayout）が持つ。
const auth = useAuthStore();

function reload(): void {
    window.location.reload();
}
</script>

<template>
    <div
        v-if="auth.loadError.value"
        role="alert"
        class="bg-danger-50 px-4 py-3 text-sm text-danger-700"
    >
        ログイン状態を確認できませんでした。
        <button
            type="button"
            class="ms-2 underline hover:text-danger-900"
            @click="reload"
        >
            再読み込み
        </button>
    </div>

    <RouterView />
</template>
