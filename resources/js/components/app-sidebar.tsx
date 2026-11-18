import { BusinessSwitcher } from '@/components/business-switcher';
import { InstallApp } from '@/components/install-app';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { PlanBadge } from '@/components/plan-badge';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Calculator, FileClock, FileMinus, FileText, LayoutGrid, MessageCircle, Package, Receipt, Repeat, Truck, Users, Wallet } from 'lucide-react';
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
        title: 'Pro-formas',
        url: '/proformas',
        icon: FileClock,
    },
    {
        title: 'Credit notes',
        url: '/creditnotes',
        icon: FileMinus,
    },
    {
        title: 'Delivery notes',
        url: '/deliverynotes',
        icon: Truck,
    },
    {
        title: 'Recurring',
        url: '/recurring-invoices',
        icon: Repeat,
    },
    {
        title: 'Expenses',
        url: '/expenses',
        icon: Wallet,
    },
    {
        title: 'Tax return',
        url: '/tax-return',
        icon: Calculator,
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

const assistantNavItem: NavItem = {
    title: 'Assistant',
    url: '/assistant',
    icon: MessageCircle,
};

export function AppSidebar() {
    const { features } = usePage<SharedData>().props;
    const items = features?.assistant ? [mainNavItems[0], assistantNavItem, ...mainNavItems.slice(1)] : mainNavItems;

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
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <InstallApp />
                <PlanBadge />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
