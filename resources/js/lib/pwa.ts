/**
 * Progressive web app helpers: registers the service worker and keeps hold of
 * the browser's install prompt, so an "Install app" button can show it later.
 */

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

let deferredPrompt: BeforeInstallPromptEvent | null = null;
const listeners = new Set<() => void>();

function notify() {
    listeners.forEach((listener) => listener());
}

export function registerServiceWorker() {
    if (typeof window === 'undefined') {
        return;
    }

    // Chrome, Edge and Android fire this when the app can be installed.
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event as BeforeInstallPromptEvent;
        notify();
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        notify();
    });

    // Vite's dev server serves assets from another origin with hot reload,
    // so the service worker only runs on built assets (npm run build).
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Installing is a nice-to-have; the app works the same without it.
        });
    });
}

export function subscribeToInstallPrompt(listener: () => void) {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export function canPromptInstall() {
    return deferredPrompt !== null;
}

export async function promptInstall() {
    if (!deferredPrompt) {
        return false;
    }

    const prompt = deferredPrompt;
    deferredPrompt = null;
    notify();
    await prompt.prompt();
    const { outcome } = await prompt.userChoice;

    return outcome === 'accepted';
}

export function isRunningAsApp() {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

/** iPhone and iPad Safari have no install prompt: people use Share, then Add to Home Screen. */
export function isIos() {
    if (typeof navigator === 'undefined') {
        return false;
    }

    return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}
