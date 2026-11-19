import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Eraser, FileText, ImageUp, LoaderCircle, PenLine, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Invoice design',
        href: '/settings/invoice-design',
    },
];

// An A4 page at 96 dpi, the size the PDF is laid out at.
const PAGE_WIDTH = 794;
const PAGE_HEIGHT = 1123;

const COLOUR_PRESETS = ['#1F2937', '#071B49', '#0875E8', '#0F766E', '#15803D', '#B45309', '#B91C1C', '#7C3AED', '#DB2777'];

type Design = {
    document_layout: string;
    document_colour: string;
    document_accent_colour: string;
    document_font: string;
    document_footer: string;
    document_footer_details: boolean;
    document_show_signature: boolean;
    document_signature_name: string;
};

interface Option {
    value: string;
    label: string;
    description?: string;
}

type DesignForm = Design & {
    logo: File | null;
    remove_logo: boolean;
    signature: File | null;
    remove_signature: boolean;
};

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function previewBody(data: DesignForm, type: string, format: 'html' | 'pdf'): FormData {
    const body = new FormData();
    body.append('document_layout', data.document_layout);
    body.append('document_colour', data.document_colour);
    body.append('document_accent_colour', data.document_accent_colour);
    body.append('document_font', data.document_font);
    body.append('document_footer', data.document_footer);
    body.append('remove_logo', data.remove_logo ? '1' : '0');
    body.append('document_footer_details', data.document_footer_details ? '1' : '0');
    body.append('document_show_signature', data.document_show_signature ? '1' : '0');
    body.append('document_signature_name', data.document_signature_name);
    body.append('remove_signature', data.remove_signature ? '1' : '0');
    if (data.signature) {
        body.append('signature', data.signature);
    }
    body.append('type', type);
    body.append('format', format);
    if (data.logo) {
        body.append('logo', data.logo);
    }

    return body;
}

function isHex(value: string): boolean {
    return /^#[0-9A-Fa-f]{6}$/.test(value);
}

export default function InvoiceDesign({
    design,
    logoUrl,
    signatureUrl,
    layouts,
    fonts,
    canUpdate,
}: {
    design: Design;
    logoUrl: string | null;
    signatureUrl: string | null;
    layouts: Option[];
    fonts: Option[];
    canUpdate: boolean;
}) {
    const { data, setData, post, errors, processing, recentlySuccessful } = useForm<DesignForm>({
        ...design,
        logo: null,
        remove_logo: false,
        signature: null,
        remove_signature: false,
    });
    const [drawing, setDrawing] = useState(false);

    const [previewType, setPreviewType] = useState('invoice');
    const [previewHtml, setPreviewHtml] = useState<string | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const disabled = !canUpdate || processing;

    // Re-render the preview a moment after the last change.
    useEffect(() => {
        if (!isHex(data.document_colour) || !isHex(data.document_accent_colour)) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setPreviewLoading(true);
            try {
                const response = await fetch(route('invoice-design.preview'), {
                    method: 'POST',
                    headers: { Accept: 'text/html', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                    body: previewBody(data, previewType, 'html'),
                    signal: controller.signal,
                });
                if (response.ok) {
                    setPreviewHtml(await response.text());
                }
            } catch {
                // A newer change cancelled this request; its own preview will follow.
            } finally {
                if (!controller.signal.aborted) {
                    setPreviewLoading(false);
                }
            }
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        data.document_layout,
        data.document_colour,
        data.document_accent_colour,
        data.document_font,
        data.document_footer,
        data.logo,
        data.remove_logo,
        data.document_footer_details,
        data.document_show_signature,
        data.document_signature_name,
        data.signature,
        data.remove_signature,
        previewType,
    ]);

    const openPdf = async () => {
        // Open the tab straight away so the browser doesn't treat it as a pop-up.
        const tab = window.open('', '_blank');
        const response = await fetch(route('invoice-design.preview'), {
            method: 'POST',
            headers: { Accept: 'application/pdf', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            body: previewBody(data, previewType, 'pdf'),
        });

        if (!response.ok) {
            tab?.close();
            return;
        }

        const url = URL.createObjectURL(await response.blob());
        if (tab) {
            tab.location.href = url;
        } else {
            window.location.href = url;
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('invoice-design.update'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setData((current) => ({ ...current, logo: null, remove_logo: false, signature: null, remove_signature: false })),
        });
    };

    const selectedLogoUrl = useMemo(() => (data.logo ? URL.createObjectURL(data.logo) : null), [data.logo]);
    useEffect(() => () => (selectedLogoUrl ? URL.revokeObjectURL(selectedLogoUrl) : undefined), [selectedLogoUrl]);
    const shownLogo = selectedLogoUrl ?? (data.remove_logo ? null : logoUrl);

    const selectedSignatureUrl = useMemo(() => (data.signature ? URL.createObjectURL(data.signature) : null), [data.signature]);
    useEffect(() => () => (selectedSignatureUrl ? URL.revokeObjectURL(selectedSignatureUrl) : undefined), [selectedSignatureUrl]);
    const shownSignature = selectedSignatureUrl ?? (data.remove_signature ? null : signatureUrl);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Invoice design" />

            <SettingsLayout wide>
                <div className="grid grid-cols-[minmax(0,1fr)] gap-10 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
                    <form onSubmit={submit} className="space-y-8">
                        <HeadingSmall title="Invoice design" description="How your estimates and invoices look, as PDFs and when printed" />

                        <fieldset className="space-y-3" disabled={disabled}>
                            <legend className="text-sm font-medium">Layout</legend>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                {layouts.map((layout) => (
                                    <button
                                        key={layout.value}
                                        type="button"
                                        onClick={() => setData('document_layout', layout.value)}
                                        aria-pressed={data.document_layout === layout.value}
                                        className={cn(
                                            'hover:border-primary/60 rounded-lg border p-2 text-left transition-colors disabled:opacity-60',
                                            data.document_layout === layout.value && 'border-primary ring-primary ring-1',
                                        )}
                                    >
                                        <LayoutThumbnail
                                            layout={layout.value}
                                            colour={isHex(data.document_colour) ? data.document_colour : '#1F2937'}
                                            accent={isHex(data.document_accent_colour) ? data.document_accent_colour : '#2563EB'}
                                        />
                                        <div className="mt-2 text-sm font-medium">{layout.label}</div>
                                        <div className="text-muted-foreground text-xs leading-snug">{layout.description}</div>
                                    </button>
                                ))}
                            </div>
                            <InputError message={errors.document_layout} />
                        </fieldset>

                        <div className="space-y-3">
                            <Label htmlFor="logo">Logo</Label>
                            <div className="flex items-center gap-4">
                                <div className="bg-muted/40 flex h-16 w-36 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                                    {shownLogo ? (
                                        <img src={shownLogo} alt="Your logo" className="max-h-14 max-w-32 object-contain" />
                                    ) : (
                                        <span className="text-muted-foreground text-xs">No logo</span>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <label
                                        htmlFor="logo"
                                        className={cn(
                                            buttonVariants({ variant: 'outline', size: 'sm' }),
                                            'cursor-pointer',
                                            disabled && 'pointer-events-none opacity-50',
                                        )}
                                    >
                                        <ImageUp className="size-4" />
                                        {shownLogo ? 'Replace' : 'Upload'}
                                    </label>
                                    {shownLogo && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={disabled}
                                            onClick={() => setData((current) => ({ ...current, logo: null, remove_logo: true }))}
                                        >
                                            <Trash2 className="size-4" />
                                            Remove
                                        </Button>
                                    )}
                                </div>
                                <input
                                    id="logo"
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    className="sr-only"
                                    disabled={disabled}
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] ?? null;
                                        setData((current) => ({ ...current, logo: file, remove_logo: false }));
                                        e.target.value = '';
                                    }}
                                />
                            </div>
                            <p className="text-muted-foreground text-xs">
                                PNG or JPG, up to 1 MB. A wide logo on a transparent background works best.
                            </p>
                            <InputError message={errors.logo} />
                        </div>

                        <div className="grid gap-6 sm:grid-cols-2">
                            <ColourField
                                id="document_colour"
                                label="Brand colour"
                                hint="Headings, bands and table headers"
                                value={data.document_colour}
                                onChange={(value) => setData('document_colour', value)}
                                disabled={disabled}
                                error={errors.document_colour}
                            />
                            <ColourField
                                id="document_accent_colour"
                                label="Accent colour"
                                hint="Highlights, rules and the total"
                                value={data.document_accent_colour}
                                onChange={(value) => setData('document_accent_colour', value)}
                                disabled={disabled}
                                error={errors.document_accent_colour}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="document_font">Font</Label>
                            <Select value={data.document_font} onValueChange={(value) => setData('document_font', value)} disabled={disabled}>
                                <SelectTrigger id="document_font">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {fonts.map((font) => (
                                        <SelectItem key={font.value} value={font.value}>
                                            {font.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.document_font} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="document_footer">Footer text</Label>
                            <Textarea
                                id="document_footer"
                                rows={3}
                                maxLength={500}
                                value={data.document_footer}
                                onChange={(e) => setData('document_footer', e.target.value)}
                                disabled={disabled}
                                placeholder="Thank you for your business!"
                            />
                            <p className="text-muted-foreground text-xs">Shown at the bottom of every page, above your business details.</p>
                            <InputError message={errors.document_footer} />
                        </div>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="document_footer_details"
                                checked={data.document_footer_details}
                                onCheckedChange={(checked) => setData('document_footer_details', checked === true)}
                                disabled={disabled}
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="document_footer_details">Show business details in the footer</Label>
                                <p className="text-muted-foreground text-xs">
                                    Your address, contact person, phone, website, email and bank details, from{' '}
                                    <Link href={route('business.edit')} className="underline underline-offset-2">
                                        Business settings
                                    </Link>
                                    . Empty details are left out.
                                </p>
                            </div>
                        </div>

                        <div className="space-y-3">
                            <Label>Signature</Label>
                            {drawing ? (
                                <SignaturePad
                                    onCancel={() => setDrawing(false)}
                                    onSave={(file) => {
                                        setData((current) => ({
                                            ...current,
                                            signature: file,
                                            remove_signature: false,
                                            document_show_signature: true,
                                        }));
                                        setDrawing(false);
                                    }}
                                />
                            ) : (
                                <div className="flex items-center gap-4">
                                    <div className="bg-muted/40 flex h-16 w-36 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-white">
                                        {shownSignature ? (
                                            <img src={shownSignature} alt="Your signature" className="max-h-14 max-w-32 object-contain" />
                                        ) : (
                                            <span className="text-muted-foreground text-xs">No signature</span>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button type="button" variant="outline" size="sm" disabled={disabled} onClick={() => setDrawing(true)}>
                                            <PenLine className="size-4" />
                                            Draw
                                        </Button>
                                        <label
                                            htmlFor="signature"
                                            className={cn(
                                                buttonVariants({ variant: 'outline', size: 'sm' }),
                                                'cursor-pointer',
                                                disabled && 'pointer-events-none opacity-50',
                                            )}
                                        >
                                            <ImageUp className="size-4" />
                                            Upload
                                        </label>
                                        {shownSignature && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                disabled={disabled}
                                                onClick={() =>
                                                    setData((current) => ({
                                                        ...current,
                                                        signature: null,
                                                        remove_signature: true,
                                                        document_show_signature: false,
                                                    }))
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                    <input
                                        id="signature"
                                        type="file"
                                        accept="image/png,image/jpeg"
                                        className="sr-only"
                                        disabled={disabled}
                                        onChange={(e) => {
                                            const file = e.target.files?.[0] ?? null;
                                            setData((current) => ({
                                                ...current,
                                                signature: file,
                                                remove_signature: false,
                                                document_show_signature: file ? true : current.document_show_signature,
                                            }));
                                            e.target.value = '';
                                        }}
                                    />
                                </div>
                            )}
                            <p className="text-muted-foreground text-xs">
                                Draw it here, or upload a PNG or JPG of your signature on a white or transparent background.
                            </p>
                            <InputError message={errors.signature} />

                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="document_show_signature"
                                    checked={data.document_show_signature && !!shownSignature}
                                    onCheckedChange={(checked) => setData('document_show_signature', checked === true)}
                                    disabled={disabled || !shownSignature}
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor="document_show_signature">Sign my documents</Label>
                                    <p className="text-muted-foreground text-xs">
                                        Adds &ldquo;Issued by, signature&rdquo; with your signature above the footer.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="document_signature_name">Name under the signature</Label>
                                <Input
                                    id="document_signature_name"
                                    value={data.document_signature_name}
                                    maxLength={255}
                                    onChange={(e) => setData('document_signature_name', e.target.value)}
                                    disabled={disabled}
                                    placeholder="Optional"
                                />
                                <InputError message={errors.document_signature_name} />
                            </div>
                        </div>

                        {canUpdate ? (
                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Save design</Button>

                                <Transition
                                    show={recentlySuccessful}
                                    enter="transition ease-in-out"
                                    enterFrom="opacity-0"
                                    leave="transition ease-in-out"
                                    leaveTo="opacity-0"
                                >
                                    <p className="text-sm text-neutral-600">Saved</p>
                                </Transition>
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-sm">Only owners and admins can change the invoice design.</p>
                        )}
                    </form>

                    <div className="space-y-3 xl:sticky xl:top-4 xl:self-start">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                size="sm"
                                className="flex-wrap justify-start"
                                value={previewType}
                                onValueChange={(value) => value && setPreviewType(value)}
                            >
                                <ToggleGroupItem value="invoice" className="px-3">
                                    Invoice
                                </ToggleGroupItem>
                                <ToggleGroupItem value="proforma" className="px-3">
                                    Pro-forma
                                </ToggleGroupItem>
                                <ToggleGroupItem value="estimate" className="px-3">
                                    Estimate
                                </ToggleGroupItem>
                                <ToggleGroupItem value="creditnote" className="px-3">
                                    Credit note
                                </ToggleGroupItem>
                                <ToggleGroupItem value="deliverynote" className="px-3">
                                    Delivery note
                                </ToggleGroupItem>
                            </ToggleGroup>
                            <div className="flex items-center gap-2">
                                {previewLoading && (
                                    <LoaderCircle className="text-muted-foreground size-4 animate-spin" aria-label="Updating preview" />
                                )}
                                <Button type="button" variant="outline" size="sm" onClick={openPdf}>
                                    <FileText className="size-4" />
                                    Open sample PDF
                                </Button>
                            </div>
                        </div>
                        <PagePreview html={previewHtml} />
                        <p className="text-muted-foreground text-xs">Sample data. Your changes show here before you save.</p>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function ColourField({
    id,
    label,
    hint,
    value,
    onChange,
    disabled,
    error,
}: {
    id: string;
    label: string;
    hint: string;
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex items-center gap-2">
                <input
                    type="color"
                    aria-label={`${label} picker`}
                    value={isHex(value) ? value : '#000000'}
                    onChange={(e) => onChange(e.target.value.toUpperCase())}
                    disabled={disabled}
                    className="h-9 w-11 shrink-0 cursor-pointer rounded-md border bg-transparent p-1 disabled:cursor-not-allowed"
                />
                <Input
                    id={id}
                    value={value}
                    onChange={(e) => onChange(e.target.value.trim().toUpperCase())}
                    disabled={disabled}
                    maxLength={7}
                    className="font-mono"
                />
            </div>
            <div className="flex flex-wrap gap-1.5">
                {COLOUR_PRESETS.map((preset) => (
                    <button
                        key={preset}
                        type="button"
                        title={preset}
                        aria-label={`Use ${preset}`}
                        disabled={disabled}
                        onClick={() => onChange(preset)}
                        className={cn('size-5 rounded-full border border-black/10', value === preset && 'ring-ring ring-2 ring-offset-1')}
                        style={{ backgroundColor: preset }}
                    />
                ))}
            </div>
            <p className="text-muted-foreground text-xs">{hint}</p>
            <InputError message={error} />
        </div>
    );
}

/**
 * A box to sign in with a mouse, finger or stylus. Saves the signature as a PNG cropped to the ink.
 */
function SignaturePad({ onSave, onCancel }: { onSave: (file: File) => void; onCancel: () => void }) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const last = useRef<{ x: number; y: number } | null>(null);
    const [empty, setEmpty] = useState(true);

    // Draw at the screen's pixel density so the line stays crisp.
    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) {
            return;
        }
        const ratio = window.devicePixelRatio || 1;
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        const context = canvas.getContext('2d');
        if (context) {
            context.scale(ratio, ratio);
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.lineWidth = 2.2;
            context.strokeStyle = '#1e3a8a';
        }
    }, []);

    const point = (e: React.PointerEvent<HTMLCanvasElement>) => {
        const rect = e.currentTarget.getBoundingClientRect();

        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    };

    const start = (e: React.PointerEvent<HTMLCanvasElement>) => {
        e.currentTarget.setPointerCapture(e.pointerId);
        last.current = point(e);
        const context = e.currentTarget.getContext('2d');
        if (context) {
            // A dot, so a single tap still marks the page.
            context.beginPath();
            context.arc(last.current.x, last.current.y, context.lineWidth / 2, 0, Math.PI * 2);
            context.fillStyle = context.strokeStyle;
            context.fill();
        }
        setEmpty(false);
    };

    const move = (e: React.PointerEvent<HTMLCanvasElement>) => {
        const context = e.currentTarget.getContext('2d');
        if (!last.current || !context) {
            return;
        }
        const next = point(e);
        context.beginPath();
        context.moveTo(last.current.x, last.current.y);
        context.lineTo(next.x, next.y);
        context.stroke();
        last.current = next;
    };

    const end = () => {
        last.current = null;
    };

    const clear = () => {
        const canvas = canvasRef.current;
        canvas?.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
        setEmpty(true);
    };

    const save = () => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');
        if (!canvas || !context) {
            return;
        }

        // Crop to the ink, with a little margin, so the signature sits neatly on the page.
        const { width, height } = canvas;
        const pixels = context.getImageData(0, 0, width, height).data;
        let [left, top, right, bottom] = [width, height, -1, -1];
        for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
                if (pixels[(y * width + x) * 4 + 3] > 0) {
                    left = Math.min(left, x);
                    right = Math.max(right, x);
                    top = Math.min(top, y);
                    bottom = Math.max(bottom, y);
                }
            }
        }
        if (right < 0) {
            return;
        }
        const pad = 8;
        left = Math.max(0, left - pad);
        top = Math.max(0, top - pad);
        right = Math.min(width - 1, right + pad);
        bottom = Math.min(height - 1, bottom + pad);

        const cropped = document.createElement('canvas');
        cropped.width = right - left + 1;
        cropped.height = bottom - top + 1;
        cropped.getContext('2d')?.drawImage(canvas, left, top, cropped.width, cropped.height, 0, 0, cropped.width, cropped.height);
        cropped.toBlob((blob) => blob && onSave(new File([blob], 'signature.png', { type: 'image/png' })), 'image/png');
    };

    return (
        <div className="space-y-2">
            <canvas
                ref={canvasRef}
                aria-label="Draw your signature"
                className="h-36 w-full touch-none rounded-md border border-dashed bg-white"
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={end}
                onPointerCancel={end}
            />
            <div className="flex flex-wrap gap-2">
                <Button type="button" size="sm" disabled={empty} onClick={save}>
                    Use this signature
                </Button>
                <Button type="button" variant="outline" size="sm" disabled={empty} onClick={clear}>
                    <Eraser className="size-4" />
                    Clear
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}

/**
 * The rendered sample document, drawn at A4 size and scaled to fit the column.
 */
function PagePreview({ html }: { html: string | null }) {
    const frameRef = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(0.5);

    useEffect(() => {
        const element = frameRef.current;
        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) => setScale(Math.min(1, entry.contentRect.width / PAGE_WIDTH)));
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return (
        <div ref={frameRef} className="w-full">
            <div
                className="relative overflow-hidden rounded-md border bg-white shadow-sm"
                style={{ height: PAGE_HEIGHT * scale, maxWidth: PAGE_WIDTH * scale }}
            >
                {html ? (
                    <iframe
                        title="Document preview"
                        srcDoc={html}
                        sandbox=""
                        className="pointer-events-none absolute top-0 left-0 origin-top-left border-0"
                        style={{ width: PAGE_WIDTH, height: PAGE_HEIGHT, transform: `scale(${scale})` }}
                    />
                ) : (
                    <div className="flex h-full items-center justify-center">
                        <LoaderCircle className="size-5 animate-spin text-neutral-400" />
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * A small sketch of each layout, in the chosen colours, for the layout picker.
 */
function LayoutThumbnail({ layout, colour, accent }: { layout: string; colour: string; accent: string }) {
    const line = (width: string, extra = '') => <div className={cn('h-1 rounded-full bg-neutral-200', extra)} style={{ width }} />;
    const rows = (
        <div className="space-y-1">
            {[0, 1, 2].map((row) => (
                <div key={row} className="flex justify-between">
                    {line('55%')}
                    {line('18%')}
                </div>
            ))}
        </div>
    );

    return (
        <div className="aspect-[210/150] w-full overflow-hidden rounded-sm border bg-white p-2" aria-hidden>
            {layout === 'classic' && (
                <div className="space-y-2">
                    <div className="flex justify-between">
                        <div className="space-y-1">
                            <div className="h-1.5 w-8 rounded-sm" style={{ backgroundColor: colour }} />
                            {line('1.25rem')}
                        </div>
                        <div className="space-y-1">
                            {line('1.5rem')}
                            {line('1.25rem')}
                        </div>
                    </div>
                    <div className="h-px" style={{ backgroundColor: colour }} />
                    {rows}
                </div>
            )}
            {layout === 'modern' && (
                <div className="-m-2 space-y-2">
                    <div className="flex h-5 items-center justify-between px-2" style={{ backgroundColor: colour }}>
                        <div className="h-1.5 w-5 rounded-sm bg-white/70" />
                        <div className="h-1.5 w-7 rounded-sm bg-white/90" />
                    </div>
                    <div className="-mt-2 h-0.5" style={{ backgroundColor: accent }} />
                    <div className="space-y-2 px-2">
                        <div className="h-1.5 rounded-sm" style={{ backgroundColor: colour, opacity: 0.15 }} />
                        {rows}
                    </div>
                </div>
            )}
            {layout === 'minimal' && (
                <div className="space-y-3 px-1 pt-1">
                    <div className="flex justify-between">
                        {line('1.25rem')}
                        <div className="h-1 w-6 rounded-full" style={{ backgroundColor: colour }} />
                    </div>
                    <div className="space-y-1.5">
                        {[0, 1].map((row) => (
                            <div key={row} className="flex justify-between">
                                {line('50%', 'bg-neutral-100')}
                                {line('15%', 'bg-neutral-100')}
                            </div>
                        ))}
                    </div>
                    <div className="ml-auto h-px w-1/3" style={{ backgroundColor: accent }} />
                </div>
            )}
            {layout === 'bold' && (
                <div className="space-y-2">
                    <div className="flex items-start justify-between">
                        <div className="h-3 w-10 rounded-sm" style={{ backgroundColor: colour }} />
                        <div className="h-5 w-9 rounded-sm" style={{ backgroundColor: colour }} />
                    </div>
                    <div className="h-1 rounded-sm" style={{ backgroundColor: accent }} />
                    {rows}
                </div>
            )}
            {layout === 'corporate' && (
                <div className="space-y-1.5">
                    <div className="h-0.5" style={{ backgroundColor: colour }} />
                    <div className="grid grid-cols-3 gap-1">
                        {[0, 1, 2].map((box) => (
                            <div key={box} className="h-3 rounded-[2px] border border-neutral-200" />
                        ))}
                    </div>
                    <div className="h-1.5 rounded-sm" style={{ backgroundColor: colour }} />
                    <div className="space-y-0.5">
                        {[0, 1, 2].map((row) => (
                            <div key={row} className="h-1.5" style={{ backgroundColor: row % 2 ? `${colour}14` : 'transparent' }} />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
