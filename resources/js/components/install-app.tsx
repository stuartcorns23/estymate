import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SidebarGroup, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { canPromptInstall, isIos, isRunningAsApp, promptInstall, subscribeToInstallPrompt } from '@/lib/pwa';
import { Download, Share, SquarePlus } from 'lucide-react';
import { useEffect, useState, useSyncExternalStore } from 'react';

/**
 * "Install app" in the sidebar. Chrome, Edge and Android show their own install
 * prompt; iPhone and iPad get the Share > Add to Home Screen steps instead.
 * Hidden once Estymate is already running as an installed app.
 */
export function InstallApp() {
    const promptable = useSyncExternalStore(subscribeToInstallPrompt, canPromptInstall, () => false);
    const [ios, setIos] = useState(false);
    const [installed, setInstalled] = useState(true);
    const [showIosSteps, setShowIosSteps] = useState(false);

    useEffect(() => {
        setIos(isIos());
        setInstalled(isRunningAsApp());
    }, []);

    if (installed || (!promptable && !ios)) {
        return null;
    }

    const install = () => {
        if (promptable) {
            void promptInstall();
        } else {
            setShowIosSteps(true);
        }
    };

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton onClick={install} tooltip="Install app">
                        <Download />
                        <span>Install app</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <Dialog open={showIosSteps} onOpenChange={setShowIosSteps}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Estymate to your Home Screen</DialogTitle>
                        <DialogDescription>It opens full screen like any other app, with the Estymate icon.</DialogDescription>
                    </DialogHeader>
                    <ol className="space-y-3 text-sm">
                        <li className="flex items-center gap-3">
                            <Share className="text-primary size-5 shrink-0" />
                            <span>
                                Tap the <strong>Share</strong> button in Safari's toolbar.
                            </span>
                        </li>
                        <li className="flex items-center gap-3">
                            <SquarePlus className="text-primary size-5 shrink-0" />
                            <span>
                                Scroll down and tap <strong>Add to Home Screen</strong>, then <strong>Add</strong>.
                            </span>
                        </li>
                    </ol>
                </DialogContent>
            </Dialog>
        </SidebarGroup>
    );
}
