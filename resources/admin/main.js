import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router.js';
import { onUnauthorized } from './api.js';
import { session } from './session.js';
import './admin.css';

// セッションが切れた（401）ら、ログインが要る画面にいる時だけログイン画面へ戻す
onUnauthorized(() => {
    // 起動時のセッションの確認（/auth/me）の 401 は、ルーターの見張りが扱う
    if (!session.checked) {
        return;
    }

    const wasSignedIn = session.operator !== null;
    session.operator = null;
    const current = router.currentRoute.value;

    if (!current.meta.guest && !current.meta.public) {
        session.returnTo = current.fullPath;
        session.notice = wasSignedIn ? 'ログインの有効期限が切れました。もう一度ログインしてください。' : null;
        router.replace({ name: 'login' });
    }
});

createApp(App).use(router).mount('#app');
