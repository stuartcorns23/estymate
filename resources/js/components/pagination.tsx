import { Button } from '@/components/ui/button';
import { type Paginated } from '@/types';
import { Link } from '@inertiajs/react';

export default function Pagination({ data }: { data: Paginated<unknown> }) {
    if (data.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-4 text-sm">
            <span className="text-muted-foreground">
                {data.from}–{data.to} of {data.total}
            </span>
            <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={!data.prev_page_url} asChild={!!data.prev_page_url}>
                    {data.prev_page_url ? (
                        <Link href={data.prev_page_url} preserveScroll>
                            Previous
                        </Link>
                    ) : (
                        <span>Previous</span>
                    )}
                </Button>
                <Button variant="outline" size="sm" disabled={!data.next_page_url} asChild={!!data.next_page_url}>
                    {data.next_page_url ? (
                        <Link href={data.next_page_url} preserveScroll>
                            Next
                        </Link>
                    ) : (
                        <span>Next</span>
                    )}
                </Button>
            </div>
        </div>
    );
}
