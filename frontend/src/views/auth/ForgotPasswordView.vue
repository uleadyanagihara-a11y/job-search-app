<script setup lang="ts">
import { ref } from 'vue';
import * as authApi from '@/api/auth';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';
import GuestLayout from '@/layouts/GuestLayout.vue';

const { data, errors, error, processing, submit } = useApiForm({ email: '' });

// 旧 props の `status`（flash）の代わりに、API の message を同じ画面に表示する（§5.2）。
// user の有無にかかわらず同じ message が返る（account enumeration 対策）。
const status = ref<string | null>(null);

async function onSubmit(): Promise<void> {
    status.value = null;

    await submit(authApi.sendPasswordResetLink, {
        onSuccess: (result) => {
            status.value = result.message;
        },
    });
}
</script>

<template>
    <GuestLayout>
        <div class="mb-4 text-sm text-fg-muted">
            登録したメールアドレスを入力してください。パスワードを再設定するためのリンクをお送りします。
        </div>

        <div
            v-if="status"
            role="status"
            class="mb-4 text-sm font-medium text-success-600"
        >
            {{ status }}
        </div>

        <p
            v-if="error"
            role="alert"
            class="mb-4 text-sm text-danger-600"
        >
            {{ error.message }}
        </p>

        <form @submit.prevent="onSubmit">
            <div>
                <InputLabel
                    for="email"
                    value="メールアドレス"
                />

                <TextInput
                    id="email"
                    v-model="data.email"
                    type="email"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="username"
                    :aria-invalid="errors.email ? 'true' : undefined"
                    :aria-describedby="errors.email ? 'email-error' : undefined"
                />

                <InputError
                    id="email-error"
                    class="mt-2"
                    :message="errors.email"
                />
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-end gap-y-2">
                <RouterLink
                    :to="{ name: 'login' }"
                    class="rounded-md text-sm text-fg-muted underline hover:text-fg-strong focus:outline-hidden focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                >
                    ログインに戻る
                </RouterLink>

                <PrimaryButton
                    class="ms-4"
                    :disabled="processing"
                >
                    再設定リンクを送信
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
