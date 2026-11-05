<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';

const route = useRoute();

// メール認証 callback は成功すると `/dashboard?verified=1` へ送る（§5.4）。
const justVerified = computed(() => route.query.verified === '1');
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard
            </h1>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div
                    v-if="justVerified"
                    role="status"
                    class="mb-4 px-4 text-sm font-medium text-green-600 sm:px-0"
                >
                    メールアドレスの確認が完了しました。
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        You're logged in!
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
