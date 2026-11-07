<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import * as authApi from '@/api/auth';
import PrimaryButton from '@/components/PrimaryButton.vue';
import { useApiForm } from '@/composables/useApiForm';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const { error, processing, submit } = useApiForm({});

// 旧 props の `status`（flash）の代わりに、API の message を同じ画面に表示する（§5.2）。
const status = ref<string | null>(null);

// メール認証 callback は失敗すると `?error=` 付きでここへ送る（§5.4）。
const CALLBACK_ERRORS: Record<string, string> = {
    'invalid-or-expired': '確認リンクが無効か、有効期限が切れています。確認メールを再送してください。',
    'user-mismatch': 'この確認リンクは、ログイン中のアカウントのものではありません。',
    'too-many-requests': '確認リンクを開く回数が多すぎます。しばらくしてから再度お試しください。',
};

const callbackError = computed(() => {
    const code = route.query.error;

    if (typeof code !== 'string') {
        return null;
    }

    return CALLBACK_ERRORS[code] ?? 'メールアドレスを確認できませんでした。';
});

async function resend(): Promise<void> {
    status.value = null;

    const sent = await submit(authApi.sendVerificationNotification, {
        onSuccess: (result) => {
            status.value = result.message;
        },
    });

    // 409 は別 tab 等で認証済みになっていた場合。user を取り直して dashboard へ進める。
    if (!sent && error.value?.code === 'conflict') {
        try {
            await auth.refresh();
        } catch {
            return;
        }

        if (auth.isVerified.value) {
            await router.replace({ name: 'dashboard' });
        }
    }
}

const loggingOut = ref(false);
const logoutFailed = ref(false);

// logout は link ではなく API 呼び出しの button にする（§3.3）。
async function logout(): Promise<void> {
    if (loggingOut.value) {
        return;
    }

    loggingOut.value = true;
    logoutFailed.value = false;

    try {
        await auth.logout();
        await router.push({ name: 'login' });
    } catch {
        logoutFailed.value = true;
    } finally {
        loggingOut.value = false;
    }
}
</script>

<template>
    <GuestLayout>
        <p
            v-if="callbackError && !status"
            role="alert"
            class="mb-4 text-sm text-red-600"
        >
            {{ callbackError }}
        </p>

        <!-- 認証済みでも callback の失敗はここに表示する（guard が dashboard へ送らない）。再送は不要。 -->
        <template v-if="auth.isVerified.value">
            <div class="mb-4 text-sm text-gray-600">
                Your email address is already verified.
            </div>

            <div class="mt-4 flex items-center justify-end">
                <RouterLink
                    :to="{ name: 'dashboard' }"
                    class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Go to Dashboard
                </RouterLink>
            </div>
        </template>

        <template v-else>
            <div class="mb-4 text-sm text-gray-600">
                Thanks for signing up! Before getting started, could you verify your
                email address by clicking on the link we just emailed to you? If you
                didn't receive the email, we will gladly send you another.
            </div>

            <div
                v-if="status"
                role="status"
                class="mb-4 text-sm font-medium text-green-600"
            >
                {{ status }}
            </div>

            <p
                v-if="error"
                role="alert"
                class="mb-4 text-sm text-red-600"
            >
                {{ error.message }}
            </p>

            <p
                v-if="logoutFailed"
                role="alert"
                class="mb-4 text-sm text-red-600"
            >
                ログアウトできませんでした。時間をおいて再度お試しください。
            </p>

            <form @submit.prevent="resend">
                <div class="mt-4 flex items-center justify-between">
                    <PrimaryButton :disabled="processing">
                        Resend Verification Email
                    </PrimaryButton>

                    <button
                        type="button"
                        class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        :disabled="loggingOut"
                        @click="logout"
                    >
                        Log Out
                    </button>
                </div>
            </form>
        </template>
    </GuestLayout>
</template>
