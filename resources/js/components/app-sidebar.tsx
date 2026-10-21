import { BusinessSwitcher } from '@/components/business-switcher';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { PlanBadge } from '@/components/plan-badge';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { FileText, LayoutGrid, Package, Receipt, Repeat, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Estimates',
        url: '/estimates',
        icon: FileText,
    },
    {
        title: 'Invoices',
        url: '/invoices',
        icon: Receipt,
    },
    {
        title: 'Recurring',
        url: '/recurring-invoices',
        icon: Repeat,
    },
    {
        title: 'Clients',
        url: '/clients',
        icon: Users,
    },
    {
        title: 'Products & services',
        url: '/products',
        icon: Package,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <BusinessSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <PlanBadge />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
