import '@/assets/app.css';
import { createApp } from 'vue';
import { onPasswordConfirmationRequired, onUnauthenticated } from '@/api/client';
import App from '@/App.vue';
import { router } from '@/router';
import { routeAccess } from '@/router/guards';
import { useAuthStore } from '@/stores/auth';

// session 切れ等で API が 401 を返したら、auth store を破棄して login へ送る。
onUnauthenticated(() => {
    useAuthStore().clear();

    const current = router.currentRoute.value;

    if (routeAccess(current) === 'auth') {
        void router.replace({ name: 'login', query: { redirect: current.fullPath } });
    }
});

// password 再確認が必要な API が 423 を返したら、confirm-password へ送り、確認後に今の画面へ戻す。
// form の入力は残らないため、戻った画面で操作をやり直してもらう（旧 RequirePassword の redirect と同じ）。
onPasswordConfirmationRequired(() => {
    const current = router.currentRoute.value;

    if (current.name !== 'password.confirm') {
        void router.push({ name: 'password.confirm', query: { redirect: current.fullPath } });
    }
});

createApp(App).use(router).mount('#app');
