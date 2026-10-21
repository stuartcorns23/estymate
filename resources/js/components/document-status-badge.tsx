import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type DocumentStatus } from '@/types';

const styles: Record<DocumentStatus | 'overdue', string> = {
    draft: 'bg-muted text-muted-foreground',
    sent: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    accepted: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    paid: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    invoiced: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    declined: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    overdue: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    void: 'bg-muted text-muted-foreground line-through',
};

export default function DocumentStatusBadge({ status, overdue = false }: { status: DocumentStatus; overdue?: boolean }) {
    const key = overdue ? 'overdue' : status;

    return <Badge className={cn('border-transparent capitalize', styles[key])}>{key}</Badge>;
}
