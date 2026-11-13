<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { isApiError } from '@/api/client';
import * as profileApi from '@/api/profile';
import SecondaryButton from '@/components/SecondaryButton.vue';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { useAuthStore } from '@/stores/auth';
import DeleteUserForm from '@/views/profile/DeleteUserForm.vue';
import UpdatePasswordForm from '@/views/profile/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from '@/views/profile/UpdateProfileInformationForm.vue';

const auth = useAuthStore();

// 旧 page props の代わりに GET /api/profile で form を初期化し、auth store も同じ user にそろえる（§5.2）。
// 旧 props の `mustVerifyEmail` は返らないため、`email_verified_at === null` から導出する（phase0 §0.3）。
const loaded = ref(false);
const loadError = ref<string | null>(null);

async function load(): Promise<void> {
    loadError.value = null;

    try {
        auth.setUser(await profileApi.fetchProfile());
        loaded.value = true;
    } catch (caught) {
        if (!isApiError(caught)) {
            throw caught;
        }

        // 401 は client の handler が login へ送る。
        loadError.value = caught.message;
    }
}

onMounted(load);
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-fg">
                プロフィール
            </h1>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="loadError"
                    class="bg-surface p-4 shadow-sm sm:rounded-lg sm:p-8"
                >
                    <p
                        role="alert"
                        class="text-sm text-danger-600"
                    >
                        プロフィールを読み込めませんでした。{{ loadError }}
                    </p>

                    <SecondaryButton
                        class="mt-4"
                        @click="load"
                    >
                        再読み込み
                    </SecondaryButton>
                </div>

                <template v-else-if="loaded">
                    <!-- account 削除の直後は auth store の user が null になり、login へ遷移するまで描画しない -->
                    <div
                        v-if="auth.user.value"
                        class="bg-surface p-4 shadow-sm sm:rounded-lg sm:p-8"
                    >
                        <UpdateProfileInformationForm
                            :user="auth.user.value"
                            class="max-w-xl"
                        />
                    </div>

                    <div class="bg-surface p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <UpdatePasswordForm class="max-w-xl" />
                    </div>

                    <div class="bg-surface p-4 shadow-sm sm:rounded-lg sm:p-8">
                        <DeleteUserForm class="max-w-xl" />
                    </div>
                </template>

                <p
                    v-else
                    class="px-4 text-sm text-fg-muted sm:px-0"
                >
                    読み込み中…
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
