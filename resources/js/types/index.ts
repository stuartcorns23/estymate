import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    business: BusinessSummary | null;
    businesses: BusinessSummary[];
}

export interface BusinessSummary {
    id: number;
    name: string;
}

export type BusinessRole = 'owner' | 'admin' | 'member';

export interface Business extends BusinessSummary {
    email: string | null;
    phone: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    county: string | null;
    postcode: string | null;
    country: string;
    vat_number: string | null;
    company_number: string | null;
    currency: string;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
