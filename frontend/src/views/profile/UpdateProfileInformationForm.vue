<script setup lang="ts">
import { computed, ref } from 'vue';
import * as authApi from '@/api/auth';
import * as profileApi from '@/api/profile';
import InputError from '@/components/InputError.vue';
import InputLabel from '@/components/InputLabel.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import TextInput from '@/components/TextInput.vue';
import { useApiForm } from '@/composables/useApiForm';
import { useAuthStore } from '@/stores/auth';
import type { User } from '@/types/api';

// 旧 `usePage().props.auth.user` の代わりに、ProfileView が GET /api/profile で取った user（auth store）を受ける。
const props = defineProps<{ user: User }>();

const auth = useAuthStore();

const { data, errors, error, processing, recentlySuccessful, submit } = useApiForm({
    name: props.user.name,
    email: props.user.email,
});

async function onSubmit(): Promise<void> {
    await submit(profileApi.updateProfile, {
        // layout の user 名や未認証の表示が古いまま残らないよう、更新済み user で auth store を更新する（§9）。
        onSuccess: (updated) => {
            auth.setUser(updated);
        },
    });
}

// email を変えると server が email_verified_at を null に戻す。
const unverified = computed(() => props.user.email_verified_at === null);

// 旧 props の `status`（verification-link-sent）の代わりに、再送 API の message を表示する。
const resendForm = useApiForm({});
const verificationStatus = ref<string | null>(null);

async function resendVerification(): Promise<void> {
    verificationStatus.value = null;

    const sent = await resendForm.submit(authApi.sendVerificationNotification, {
        onSuccess: (result) => {
            verificationStatus.value = result.message;
        },
    });

    // 409 は別 tab 等で認証済みになっていた場合。user を取り直して未認証の表示を消す。
    if (!sent && resendForm.error.value?.code === 'conflict') {
        try {
            await auth.refresh();
        } catch {
            // 取り直せなくても 409 の message は表示済み。
        }
    }
}

const resendError = resendForm.error;
const resending = resendForm.processing;
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-fg-strong">
                プロフィール情報
            </h2>

            <p class="mt-1 text-sm text-fg-muted">
                名前とメールアドレスを変更できます。
            </p>
        </header>

        <p
            v-if="error"
            role="alert"
            class="mt-6 text-sm text-danger-600"
        >
            {{ error.message }}
        </p>

        <form
            class="mt-6 space-y-6"
            @submit.prevent="onSubmit"
        >
            <div>
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

            <div v-if="unverified">
                <p class="mt-2 text-sm text-fg">
                    メールアドレスが確認されていません。
                    <!-- 旧 Link（method="post" as="button"）は API を呼ぶ button にする（§3.3）。 -->
                    <button
                        type="button"
                        class="rounded-md text-sm text-fg-muted underline hover:text-fg-strong focus:outline-hidden focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                        :disabled="resending"
                        @click="resendVerification"
                    >
                        確認メールを再送信する
                    </button>
                </p>

                <div
                    v-if="verificationStatus"
                    role="status"
                    class="mt-2 text-sm font-medium text-success-600"
                >
                    {{ verificationStatus }}
                </div>

                <p
                    v-if="resendError"
                    role="alert"
                    class="mt-2 text-sm text-danger-600"
                >
                    {{ resendError.message }}
                </p>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="processing">
                    保存
                </PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="recentlySuccessful"
                        role="status"
                        class="text-sm text-fg-muted"
                    >
                        保存しました。
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
