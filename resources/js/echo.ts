import { configureEcho } from '@laravel/echo-vue';

import { resolveReverbConnection } from '@/lib/reverbConnection';

const { wsHost, port, scheme } = resolveReverbConnection(
    {
        host: import.meta.env.VITE_REVERB_HOST,
        port: import.meta.env.VITE_REVERB_PORT,
        scheme: import.meta.env.VITE_REVERB_SCHEME,
    },
    typeof window === 'undefined' ? undefined : window.location,
);

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});
