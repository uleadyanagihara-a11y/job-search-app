<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const appName = 'Job Search App';

const auth = useAuthStore();
const router = useRouter();
const loggingOut = ref(false);

// logout は link ではなく API 呼び出しの button にする（§3.3）。
async function logout(): Promise<void> {
    if (loggingOut.value) {
        return;
    }

    loggingOut.value = true;

    try {
        await auth.logout();
        // home は dashboard への redirect なので、未ログインの行き先である login へ直接送る。
        await router.push({ name: 'login' });
    } finally {
        loggingOut.value = false;
    }
}

function reload(): void {
    window.location.reload();
}
</script>

<template>
    <header>
        <nav>
            <RouterLink :to="{ name: 'home' }">
                {{ appName }}
            </RouterLink>

            <template v-if="auth.user.value">
                <RouterLink :to="{ name: 'dashboard' }">
                    Dashboard
                </RouterLink>
                <RouterLink :to="{ name: 'profile' }">
                    {{ auth.user.value.name }}
                </RouterLink>
                <button
                    type="button"
                    :disabled="loggingOut"
                    @click="logout"
                >
                    Log Out
                </button>
            </template>
            <template v-else-if="auth.loaded.value">
                <RouterLink :to="{ name: 'login' }">
                    Log in
                </RouterLink>
                <RouterLink :to="{ name: 'register' }">
                    Register
                </RouterLink>
            </template>
        </nav>
    </header>

    <div
        v-if="auth.loadError.value"
        role="alert"
    >
        ログイン状態を確認できませんでした。
        <button
            type="button"
            @click="reload"
        >
            再読み込み
        </button>
    </div>

    <main>
        <RouterView />
    </main>
</template>
