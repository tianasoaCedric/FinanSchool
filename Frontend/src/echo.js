import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Le temps réel (Laravel Reverb) n'est activé que si VITE_REVERB_ENABLED=true.
// Sinon on expose un Echo factice : les appels window.Echo.channel(...).listen(...)
// restent valides mais ne font rien, au lieu de tenter des reconnexions
// WebSocket en boucle vers un serveur absent.
const enabled = import.meta.env.VITE_REVERB_ENABLED === 'true';

function createNoopEcho() {
    const channel = {
        listen() { return channel; },
        stopListening() { return channel; },
        notification() { return channel; },
    };
    return {
        channel: () => channel,
        private: () => channel,
        leave() {},
        leaveChannel() {},
        disconnect() {},
    };
}

if (enabled) {
    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY || 'local',
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT || 443,
        forceTLS: false,
        enabledTransports: ['ws', 'wss'],
    });
} else {
    window.Echo = createNoopEcho();
}

export default window.Echo;
