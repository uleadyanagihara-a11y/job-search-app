<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { isApiError } from '@/api/client';
import * as profileApi from '@/api/profile';
import AlertMessage from '@/components/AlertMessage.vue';
import ContentCard from '@/components/ContentCard.vue';
import MutedText from '@/components/MutedText.vue';
import PageHeading from '@/components/PageHeading.vue';
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
        await auth.syncFrom(profileApi.fetchProfile);
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
            <PageHeading>プロフィール</PageHeading>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <ContentCard v-if="loadError">
                    <AlertMessage>プロフィールを読み込めませんでした。{{ loadError }}</AlertMessage>

                    <SecondaryButton
                        class="mt-4"
                        @click="load"
                    >
                        再読み込み
                    </SecondaryButton>
                </ContentCard>

                <template v-else-if="loaded">
                    <!-- account 削除の直後は auth store の user が null になり、login へ遷移するまで描画しない。
                         見出しは各 form が持つ（h2）ため、ContentCard には title を渡さない -->
                    <ContentCard v-if="auth.user.value">
                        <UpdateProfileInformationForm
                            :user="auth.user.value"
                            class="max-w-xl"
                        />
                    </ContentCard>

                    <ContentCard>
                        <UpdatePasswordForm class="max-w-xl" />
                    </ContentCard>

                    <ContentCard>
                        <DeleteUserForm class="max-w-xl" />
                    </ContentCard>
                </template>

                <MutedText
                    v-else
                    class="px-4 sm:px-0"
                >
                    読み込み中…
                </MutedText>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
