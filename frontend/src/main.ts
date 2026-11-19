import '@/assets/app.css';
import { createApp } from 'vue';
import App from '@/App.vue';
import { router } from '@/router';
import { registerApiErrorHandlers } from '@/router/apiErrorHandlers';

// 401・403 company_suspended・423 の画面遷移（src/router/apiErrorHandlers.ts）。
registerApiErrorHandlers(router);

createApp(App).use(router).mount('#app');
