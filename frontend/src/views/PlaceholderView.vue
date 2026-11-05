<script setup lang="ts">
import { useRoute } from 'vue-router';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';

// Phase 4 で各画面を移植するまでの仮 view。route の解決と guard の確認用。
// 受けている route はすべてログイン必須なので、navigation と logout のある layout で包む。
const route = useRoute();
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-gray-800">
                {{ route.meta.title }}
            </h1>
        </template>

        <div class="mx-auto max-w-7xl px-4 py-12 text-gray-900 sm:px-6 lg:px-8">
            <p>この画面は未移植です（route: {{ String(route.name) }}）。</p>
        </div>
    </AuthenticatedLayout>
</template>
