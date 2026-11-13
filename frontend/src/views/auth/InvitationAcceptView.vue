<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isApiError } from '@/api/client';
import * as invitationsApi from '@/api/invitations';
import AlertMessage from '@/components/AlertMessage.vue';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import MutedText from '@/components/MutedText.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import SecondaryButton from '@/components/SecondaryButton.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { useAuthStore } from '@/stores/auth';
import type { InvitationSummary } from '@/types/api';

// 招待の受諾（docs/domain-api-contract.md M2.3・M2.6）。メールの URL は /invitations/{token}。
// token は API の path に入れず、lookup・accept の body で送る（docs/domain-design.md 2 章）。
// email は招待に保存したものだけを使うため、表示するだけで送らない。

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const token = typeof route.params.token === 'string' ? route.params.token : '';

type LookupState =
    | { status: 'loading' }
    | { status: 'ready'; invitation: InvitationSummary }
    /** 無効・期限切れ・使用済み（422）。理由は区別しない */
    | { status: 'invalid'; message: string }
    /** 企業が停止中・email が登録済み（409） */
    | { status: 'conflict' }
    | { status: 'error'; message: string };

const state = ref<LookupState>({ status: 'loading' });

async function lookup(): Promise<void> {
    state.value = { status: 'loading' };

    try {
        state.value = { status: 'ready', invitation: await invitationsApi.lookupInvitation({ token }) };
    } catch (caught) {
        if (!isApiError(caught)) {
            throw caught;
        }

        if (caught.code === 'validation_failed') {
            state.value = { status: 'invalid', message: caught.errors.token?.[0] ?? caught.message };
        } else if (caught.code === 'conflict') {
            state.value = { status: 'conflict' };
        } else {
            state.value = { status: 'error', message: caught.message };
        }
    }
}

onMounted(lookup);

const { data, errors, error, processing, submit, reset } = useApiForm({
    token,
    name: '',
    password: '',
    password_confirmation: '',
});

async function onSubmit(): Promise<void> {
    const accepted = await submit(invitationsApi.acceptInvitation, {
        onSuccess: async (result) => {
            auth.setUser(result.data);
            // token を含む URL へ戻れないよう replace する。
            await router.replace(result.meta.redirect_to);
        },
    });

    if (accepted) {
        return;
    }

    reset('password', 'password_confirmation');

    // 画面を開いた後に期限切れ・使用済み・停止になった場合は、form を閉じて理由を出す。
    if (errors.value.token !== undefined) {
        state.value = { status: 'invalid', message: errors.value.token };
    } else if (error.value?.code === 'conflict') {
        state.value = { status: 'conflict' };
    }
}
</script>

<template>
    <GuestLayout>
        <MutedText v-if="state.status === 'loading'">
            招待を確認しています…
        </MutedText>

        <template v-else-if="state.status === 'invalid'">
            <AlertMessage>{{ state.message }}</AlertMessage>
            <MutedText class="mt-4">
                招待をもう一度送ってもらう場合は、運営にお問い合わせください。
            </MutedText>
        </template>

        <AlertMessage v-else-if="state.status === 'conflict'">
            この招待は現在受けられません。既に登録済みのメールアドレスか、企業アカウントが利用できない状態です。運営にお問い合わせください。
        </AlertMessage>

        <template v-else-if="state.status === 'error'">
            <AlertMessage>招待を確認できませんでした。{{ state.message }}</AlertMessage>

            <SecondaryButton
                class="mt-4"
                @click="lookup"
            >
                再読み込み
            </SecondaryButton>
        </template>

        <template v-else>
            <MutedText class="mb-4">
                「{{ state.invitation.company_name }}」の担当者として招待されています。名前とパスワードを設定すると、ログインした状態になります。
            </MutedText>

            <AlertMessage
                v-if="error"
                class="mb-4"
            >
                {{ error.message }}
            </AlertMessage>

            <form @submit.prevent="onSubmit">
                <div>
                    <InputLabel
                        for="email"
                        value="メールアドレス"
                    />

                    <!-- 変更できない。password manager が account を結び付けられるよう autocomplete は残す -->
                    <TextInput
                        id="email"
                        :model-value="state.invitation.email"
                        type="email"
                        class="mt-1 block w-full"
                        readonly
                        autocomplete="username"
                    />
                </div>

                <div class="mt-4">
                    <InputLabel
                        for="name"
                        value="名前"
                    />

                    <TextInput
                        id="name"
                        v-model="data.name"
                        type="text"
                        class="mt-1 block w-full"
                        required
                        autofocus
                        autocomplete="name"
                        :aria-invalid="errors.name ? 'true' : undefined"
                        :aria-describedby="errors.name ? 'name-error' : undefined"
                    />

                    <InputError
                        id="name-error"
                        class="mt-2"
                        :message="errors.name"
                    />
                </div>

                <div class="mt-4">
                    <InputLabel
                        for="password"
                        value="パスワード"
                    />

                    <TextInput
                        id="password"
                        v-model="data.password"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        autocomplete="new-password"
                        :aria-invalid="errors.password ? 'true' : undefined"
                        :aria-describedby="errors.password ? 'password-error' : undefined"
                    />

                    <InputError
                        id="password-error"
                        class="mt-2"
                        :message="errors.password"
                    />
                </div>

                <div class="mt-4">
                    <InputLabel
                        for="password_confirmation"
                        value="パスワード（確認）"
                    />

                    <TextInput
                        id="password_confirmation"
                        v-model="data.password_confirmation"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        autocomplete="new-password"
                        :aria-invalid="errors.password_confirmation ? 'true' : undefined"
                        :aria-describedby="errors.password_confirmation ? 'password_confirmation-error' : undefined"
                    />

                    <InputError
                        id="password_confirmation-error"
                        class="mt-2"
                        :message="errors.password_confirmation"
                    />
                </div>

                <div class="mt-4 flex justify-end">
                    <PrimaryButton :disabled="processing">
                        登録してログイン
                    </PrimaryButton>
                </div>
            </form>
        </template>
    </GuestLayout>
</template>
