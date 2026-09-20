/**
 * Resolve the browser WebSocket target for Reverb.
 *
 * VITE_REVERB_HOST/PORT/SCHEME are inlined at `npm run build` / image build.
 * Runtime `.env` on the container cannot change those. The published image
 * bakes localhost:8080, which is correct for Herd / local Docker (the browser
 * talks to Reverb on the machine) but wrong behind a public HTTPS domain
 * where nginx already proxies `/app/` and `/apps/` on the same host.
 *
 * When the baked host is loopback and the page is not a local dev host,
 * follow `window.location` so self-hosted installs work without a rebuild.
 */
export const resolveReverbConnection = (
    env: {
        host?: string;
        port?: string;
        scheme?: string;
    },
    location?: Pick<Location, 'hostname' | 'port' | 'protocol'>,
): { wsHost: string; port: number; scheme: 'http' | 'https' } => {
    const bakedHost = env.host ?? '';
    const pageHost = location?.hostname ?? '';

    const bakedLooksLocal = bakedHost === '' || bakedHost === 'localhost' || bakedHost === '127.0.0.1';
    const pageLooksLocal =
        pageHost === '' ||
        pageHost === 'localhost' ||
        pageHost === '127.0.0.1' ||
        pageHost.endsWith('.test') ||
        pageHost.endsWith('.localhost');

    const usePage = Boolean(location) && bakedLooksLocal && !pageLooksLocal;

    if (usePage && location) {
        const scheme = location.protocol === 'https:' ? 'https' : 'http';
        const port = Number(location.port || (scheme === 'https' ? 443 : 80));

        return { wsHost: pageHost, port, scheme };
    }

    const scheme = env.scheme === 'https' ? 'https' : 'http';
    const port = Number(env.port ?? (scheme === 'https' ? 443 : 80));

    return { wsHost: bakedHost || 'localhost', port, scheme };
};

/**
 * Prefer the runtime key from the page (REVERB_APP_KEY) over the Vite-baked
 * VITE_REVERB_APP_KEY. Coolify / compose can set REVERB_APP_KEY without
 * passing a Docker build-arg, which left Echo connecting to `/app/` with
 * an empty key.
 */
export const resolveReverbAppKey = (
    bakedKey?: string,
    document?: Pick<Document, 'querySelector'> | null,
): string => {
    const runtime = document?.querySelector('meta[name="reverb-app-key"]')?.getAttribute('content')?.trim() ?? '';

    return runtime || bakedKey?.trim() || '';
};
