import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface SearchInputProps {
    value: string;
    placeholder?: string;
    params?: Record<string, string>;
}

/**
 * A search box that reloads the current page with ?search= after the person stops typing.
 */
export default function SearchInput({ value, placeholder = 'Search', params = {} }: SearchInputProps) {
    const [search, setSearch] = useState(value);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }

        const timeout = setTimeout(() => {
            router.get(window.location.pathname, { ...params, search }, { preserveState: true, replace: true });
        }, 300);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <div className="relative w-full max-w-xs">
            <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
            <Input className="pl-9" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={placeholder} />
        </div>
    );
}
