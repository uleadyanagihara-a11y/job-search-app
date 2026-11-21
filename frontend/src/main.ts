import '@/assets/app.css';
import { createApp } from 'vue';
import App from '@/App.vue';
import { router } from '@/router';
import { registerApiErrorHandlers } from '@/router/apiErrorHandlers';

// どの画面で起きても同じ遷移をする API の失敗の処理（対象の status・code は src/router/apiErrorHandlers.ts の JSDoc）。
registerApiErrorHandlers(router);

createApp(App).use(router).mount('#app');
