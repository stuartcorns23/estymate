import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    business: BusinessSummary | null;
    businesses: BusinessSummary[];
    plan: { name: string; trial_ends_at: string | null } | null;
}

export interface PlanInfo {
    key: string;
    name: string;
    price: number;
    yearly_price: number;
    limits: Record<'clients' | 'invoices_per_month' | 'estimates_per_month' | 'team_members', number | null>;
    purchasable: boolean;
    yearly_purchasable: boolean;
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
    estimate_prefix: string;
    invoice_prefix: string;
    payment_terms_days: number;
    payment_details: string | null;
    send_reminders: boolean;
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

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface TaxRate {
    id: number;
    name: string;
    rate: string;
    is_default: boolean;
}

export interface Client {
    id: number;
    name: string;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    county: string | null;
    postcode: string | null;
    vat_number: string | null;
    notes: string | null;
    documents_count?: number;
}

export interface Product {
    id: number;
    name: string;
    description: string | null;
    unit_price: number;
    unit: string | null;
    tax_rate_id: number | null;
    tax_rate?: Pick<TaxRate, 'id' | 'name' | 'rate'> | null;
}

export type DocumentStatus = 'draft' | 'sent' | 'accepted' | 'declined' | 'invoiced' | 'paid' | 'void';

export interface DocumentTypeInfo {
    value: 'estimate' | 'invoice';
    label: string;
    plural: string;
}

export interface DocumentSummary {
    id: number;
    number: string;
    status: DocumentStatus;
    is_overdue: boolean;
    balance_due: number;
    issue_date: string;
    due_date: string | null;
    client_name: string | null;
    total: number;
    currency: string;
}

export interface Option {
    value: string;
    label: string;
}
