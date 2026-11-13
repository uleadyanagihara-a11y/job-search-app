<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { isApiError } from '@/api/client';
import * as seekerProfileApi from '@/api/seekerProfile';
import AlertMessage from '@/components/AlertMessage.vue';
import ContentCard from '@/components/ContentCard.vue';
import MutedText from '@/components/MutedText.vue';
import PageHeading from '@/components/PageHeading.vue';
import SecondaryButton from '@/components/SecondaryButton.vue';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import type { SeekerProfile } from '@/types/api';
import SeekerProfileForm from '@/views/seeker-profile/SeekerProfileForm.vue';

// 求職者プロフィール（docs/domain-api-contract.md M2.3・M2.6）。route の meta.roles で求職者だけが開く。
// form は取得した値で初期化するため、読み込めてから描画する。
const profile = ref<SeekerProfile | null>(null);
const loadError = ref<string | null>(null);

async function load(): Promise<void> {
    loadError.value = null;

    try {
        profile.value = await seekerProfileApi.fetchSeekerProfile();
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
            <PageHeading>求職者プロフィール</PageHeading>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <ContentCard v-if="loadError">
                    <AlertMessage>プロフィールを読み込めませんでした。{{ loadError }}</AlertMessage>

                    <SecondaryButton
                        class="mt-4"
                        @click="load"
                    >
                        再読み込み
                    </SecondaryButton>
                </ContentCard>

                <ContentCard
                    v-else-if="profile"
                    title="応募に使うプロフィール"
                    description="応募した企業の担当者が、最新の内容を見ます。メールアドレスなどの連絡先は企業に見せません。"
                >
                    <SeekerProfileForm
                        :profile="profile"
                        class="max-w-xl"
                    />
                </ContentCard>

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
