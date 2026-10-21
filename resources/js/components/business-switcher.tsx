import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown, Plus } from 'lucide-react';

export function BusinessSwitcher() {
    const { auth } = usePage<SharedData>().props;

    if (!auth.business) {
        return null;
    }

    const switchTo = (id: number) => {
        if (id !== auth.business?.id) {
            router.put(route('current-business.update'), { business_id: id });
        }
    };

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton className="data-[state=open]:bg-sidebar-accent" tooltip={auth.business.name}>
                            <Building2 />
                            <span className="truncate">{auth.business.name}</span>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg" align="start">
                        <DropdownMenuLabel className="text-muted-foreground text-xs">Businesses</DropdownMenuLabel>
                        {auth.businesses.map((business) => (
                            <DropdownMenuItem key={business.id} onSelect={() => switchTo(business.id)}>
                                <span className="truncate">{business.name}</span>
                                {business.id === auth.business?.id && <Check className="ml-auto" />}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link className="block w-full" href={route('businesses.create')}>
                                <Plus className="mr-2" />
                                Add a business
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
