import { cn } from '@/lib/utils';
import { Upload } from 'lucide-react';
import { type DragEvent, type ReactNode, useRef, useState } from 'react';

interface FileDropZoneProps {
    id?: string;
    accept: string;
    onFile: (file: File) => void;
    disabled?: boolean;
    children?: ReactNode;
}

/**
 * A box you can drop a file onto, or click to choose one.
 */
export default function FileDropZone({ id, accept, onFile, disabled, children }: FileDropZoneProps) {
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    const drop = (e: DragEvent) => {
        e.preventDefault();
        setDragging(false);

        const file = e.dataTransfer.files?.[0];
        if (file && !disabled) {
            onFile(file);
        }
    };

    return (
        <div
            role="button"
            tabIndex={0}
            aria-disabled={disabled}
            onClick={() => !disabled && input.current?.click()}
            onKeyDown={(e) => {
                if ((e.key === 'Enter' || e.key === ' ') && !disabled) {
                    e.preventDefault();
                    input.current?.click();
                }
            }}
            onDragOver={(e) => {
                e.preventDefault();
                setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={drop}
            className={cn(
                'focus-visible:ring-ring flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center text-sm transition-colors outline-none focus-visible:ring-2',
                dragging ? 'border-primary bg-primary/5' : 'border-input hover:bg-muted/40',
                disabled && 'cursor-wait opacity-70',
            )}
        >
            <Upload className="text-muted-foreground size-5" />
            {children}
            <input
                ref={input}
                id={id}
                type="file"
                accept={accept}
                className="hidden"
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) {
                        onFile(file);
                    }
                    e.target.value = '';
                }}
            />
        </div>
    );
}
