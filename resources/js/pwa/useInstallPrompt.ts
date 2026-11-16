import { useEffect, useState } from 'react';

type InstallPromptEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

/** Captures Chrome and Edge's `beforeinstallprompt` so the page can offer its own Install button. */
export function useInstallPrompt(): { canInstall: boolean; install: () => Promise<boolean> } {
    const [prompt, setPrompt] = useState<InstallPromptEvent | null>(null);

    useEffect(() => {
        const capture = (event: Event) => {
            event.preventDefault();
            setPrompt(event as InstallPromptEvent);
        };
        const clear = () => setPrompt(null);

        window.addEventListener('beforeinstallprompt', capture);
        window.addEventListener('appinstalled', clear);

        return () => {
            window.removeEventListener('beforeinstallprompt', capture);
            window.removeEventListener('appinstalled', clear);
        };
    }, []);

    const install = async (): Promise<boolean> => {
        if (prompt === null) {
            return false;
        }

        await prompt.prompt();
        const choice = await prompt.userChoice;
        setPrompt(null);

        return choice.outcome === 'accepted';
    };

    return { canInstall: prompt !== null, install };
}
