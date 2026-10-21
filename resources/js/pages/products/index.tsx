import PageHeader from '@/components/page-header';
import Pagination from '@/components/pagination';
import SearchInput from '@/components/search-input';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, formatRate } from '@/lib/money';
import { type BreadcrumbItem, type Paginated, type Product } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Products & services', href: '/products' }];

export default function ProductsIndex({ products, filters }: { products: Paginated<Product>; filters: { search: string } }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Products & services" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Products & services"
                    description="Saved items you can add to estimates and invoices in one click"
                    actions={
                        <Button asChild>
                            <Link href={route('products.create')}>
                                <Plus /> Add item
                            </Link>
                        </Button>
                    }
                />

                <SearchInput value={filters.search} placeholder="Search items" />

                {products.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-10 text-center text-sm">
                        {filters.search ? 'No items match your search.' : 'No saved items yet. Add the products or services you sell most often.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-muted-foreground text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Name</th>
                                    <th className="hidden px-4 py-2 font-medium sm:table-cell">VAT</th>
                                    <th className="px-4 py-2 text-right font-medium">Price</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {products.data.map((product) => (
                                    <tr key={product.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            <Link href={route('products.edit', product.id)} className="font-medium hover:underline">
                                                {product.name}
                                            </Link>
                                            {product.description && <p className="text-muted-foreground line-clamp-1">{product.description}</p>}
                                        </td>
                                        <td className="hidden px-4 py-3 sm:table-cell">
                                            {product.tax_rate ? formatRate(product.tax_rate.rate) : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                                            {formatMoney(product.unit_price)}
                                            {product.unit && <span className="text-muted-foreground"> / {product.unit}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination data={products} />
            </div>
        </AppLayout>
    );
}
