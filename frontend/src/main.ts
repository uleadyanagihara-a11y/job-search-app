import { createApp } from 'vue';
import { onUnauthenticated } from '@/api/client';
import App from '@/App.vue';
import { router } from '@/router';
import { useAuthStore } from '@/stores/auth';

// session 切れ等で API が 401 を返したら、auth store を破棄して login へ送る。
onUnauthenticated(() => {
    useAuthStore().clear();

    const current = router.currentRoute.value;

    if (current.meta.access === 'auth') {
        void router.replace({ name: 'login', query: { redirect: current.fullPath } });
    }
});

createApp(App).use(router).mount('#app');
