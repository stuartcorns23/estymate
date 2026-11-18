import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney, formatRate } from '@/lib/money';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Check, FileText, LoaderCircle, MessageCircle, Plus, Send, Sparkles, Trash2, X } from 'lucide-react';
import { type FormEvent, type KeyboardEvent, useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Assistant', href: '/assistant' }];

const suggestions = [
    'Which invoices are overdue?',
    'How much did I spend on materials last quarter?',
    'Who owes me the most money?',
    'Draft an invoice for a client',
];

interface DraftLine {
    description: string;
    quantity: string;
    unit: string | null;
    unit_price: number;
    tax_rate: string;
    net_amount: number;
}

interface Draft {
    id: number;
    status: 'pending' | 'created' | 'cancelled';
    client_name: string | null;
    issue_date: string;
    due_date: string | null;
    po_number: string | null;
    reference: string | null;
    items: DraftLine[];
    subtotal: number;
    tax_total: number;
    total: number;
    currency: string;
    document: { number: string; url: string } | null;
}

interface ChatMessage {
    id: number | string;
    role: 'user' | 'assistant';
    body: string;
    drafts: Draft[];
    failed?: boolean;
}

interface AssistantProps {
    available: boolean;
    conversations: { id: number; title: string | null; updated_at: string }[];
    conversation: { id: number; title: string | null; messages: ChatMessage[] } | null;
    maxQuestions: number;
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function postJson(url: string, body?: object): Promise<{ ok: boolean; status: number; json: Record<string, unknown> }> {
    const response = await fetch(url, {
        method: 'POST',
        body: body ? JSON.stringify(body) : undefined,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    return { ok: response.ok, status: response.status, json: await response.json().catch(() => ({})) };
}

export default function AssistantIndex({ available, conversations, conversation, maxQuestions }: AssistantProps) {
    const [conversationId, setConversationId] = useState<number | null>(conversation?.id ?? null);
    const [messages, setMessages] = useState<ChatMessage[]>(conversation?.messages ?? []);
    const [input, setInput] = useState('');
    const [thinking, setThinking] = useState(false);
    const bottom = useRef<HTMLDivElement>(null);
    const questions = messages.filter((message) => message.role === 'user' && !message.failed).length;

    useEffect(() => {
        setConversationId(conversation?.id ?? null);
        setMessages(conversation?.messages ?? []);
    }, [conversation]);

    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, [messages, thinking]);

    const ask = async (question: string) => {
        question = question.trim();

        if (!question || thinking) {
            return;
        }

        setInput('');
        setThinking(true);
        setMessages((current) => [...current, { id: `local-${Date.now()}`, role: 'user', body: question, drafts: [] }]);

        try {
            const { ok, status, json } = await postJson(route('assistant.messages.store'), { conversation_id: conversationId, message: question });

            if (json.conversation_id && json.conversation_id !== conversationId) {
                setConversationId(json.conversation_id as number);
                window.history.replaceState(window.history.state, '', route('assistant.index', { chat: json.conversation_id as number }));
            }

            if (!ok) {
                const message =
                    status === 429
                        ? "You're asking quickly. Wait a minute and try again."
                        : ((json.message as string) ?? 'Something went wrong. Please try again.');
                setMessages((current) => [
                    ...current.map((m, i) => (i === current.length - 1 ? { ...m, failed: true } : m)),
                    { id: `error-${Date.now()}`, role: 'assistant', body: message, drafts: [], failed: true },
                ]);
                return;
            }

            setMessages((current) => [...current, json.message as ChatMessage]);
        } catch {
            setMessages((current) => [
                ...current,
                {
                    id: `error-${Date.now()}`,
                    role: 'assistant',
                    body: "The assistant couldn't be reached. Check your connection and try again.",
                    drafts: [],
                    failed: true,
                },
            ]);
        } finally {
            setThinking(false);
        }
    };

    const updateDraft = (draft: Draft) => {
        setMessages((current) => current.map((message) => ({ ...message, drafts: message.drafts.map((d) => (d.id === draft.id ? draft : d)) })));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        ask(input);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            ask(input);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Assistant" />

            <div className="flex h-[calc(100svh-6rem)] min-h-[28rem] gap-4 p-4">
                <aside className="hidden w-60 shrink-0 flex-col gap-2 md:flex">
                    <Button variant="outline" className="justify-start" asChild>
                        <Link href={route('assistant.index')}>
                            <Plus /> New chat
                        </Link>
                    </Button>
                    <nav className="flex-1 space-y-0.5 overflow-y-auto">
                        {conversations.map((chat) => (
                            <div
                                key={chat.id}
                                className={cn(
                                    'group hover:bg-muted flex items-center gap-1 rounded-md pr-1 text-sm',
                                    chat.id === conversationId && 'bg-muted font-medium',
                                )}
                            >
                                <Link
                                    href={route('assistant.index', { chat: chat.id })}
                                    className="min-w-0 flex-1 truncate px-2 py-1.5"
                                    preserveScroll
                                >
                                    {chat.title ?? 'New chat'}
                                </Link>
                                <button
                                    type="button"
                                    className="text-muted-foreground hover:text-destructive opacity-0 group-hover:opacity-100 focus:opacity-100"
                                    aria-label="Delete chat"
                                    onClick={() => router.delete(route('assistant.destroy', chat.id))}
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        ))}
                    </nav>
                </aside>

                <section className="flex min-w-0 flex-1 flex-col rounded-xl border">
                    <div className="flex-1 overflow-y-auto p-4">
                        {!available ? (
                            <Empty>
                                The assistant isn't set up yet. Add <code>ANTHROPIC_API_KEY</code> to the app's <code>.env</code> file to turn it on.
                            </Empty>
                        ) : messages.length === 0 ? (
                            <div className="mx-auto flex h-full max-w-lg flex-col items-center justify-center gap-6 text-center">
                                <div className="bg-primary/10 text-primary flex size-12 items-center justify-center rounded-full">
                                    <Sparkles className="size-6" />
                                </div>
                                <div className="space-y-1">
                                    <h1 className="text-xl font-semibold tracking-tight">Ask about your business</h1>
                                    <p className="text-muted-foreground text-sm">
                                        Ask about your invoices, clients and expenses, or have the assistant draft an invoice for you to check.
                                    </p>
                                </div>
                                <div className="flex flex-wrap justify-center gap-2">
                                    {suggestions.map((suggestion) => (
                                        <Button key={suggestion} variant="outline" size="sm" onClick={() => ask(suggestion)}>
                                            {suggestion}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        ) : (
                            <div className="mx-auto max-w-3xl space-y-4">
                                {messages.map((message) => (
                                    <Message key={message.id} message={message} onDraftChange={updateDraft} />
                                ))}
                                {thinking && (
                                    <div className="text-muted-foreground flex items-center gap-2 text-sm">
                                        <LoaderCircle className="size-4 animate-spin" /> Looking that up…
                                    </div>
                                )}
                                <div ref={bottom} />
                            </div>
                        )}
                    </div>

                    {available && (
                        <form onSubmit={submit} className="border-t p-3">
                            {questions >= maxQuestions ? (
                                <p className="text-muted-foreground text-center text-sm">
                                    This chat is full.{' '}
                                    <Link href={route('assistant.index')} className="text-foreground underline">
                                        Start a new chat
                                    </Link>{' '}
                                    to keep going.
                                </p>
                            ) : (
                                <div className="mx-auto flex max-w-3xl items-end gap-2">
                                    <Textarea
                                        value={input}
                                        onChange={(event) => setInput(event.target.value)}
                                        onKeyDown={onKeyDown}
                                        placeholder="Ask a question, or say who to invoice and for what"
                                        rows={1}
                                        maxLength={2000}
                                        className="max-h-40 min-h-10 resize-none"
                                        aria-label="Message"
                                        autoFocus
                                    />
                                    <Button type="submit" size="icon" disabled={thinking || !input.trim()} aria-label="Send">
                                        <Send />
                                    </Button>
                                </div>
                            )}
                            <p className="text-muted-foreground mx-auto mt-2 max-w-3xl text-xs">
                                The assistant can make mistakes. Invoices are only created when you confirm them.
                            </p>
                        </form>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return (
        <div className="text-muted-foreground flex h-full flex-col items-center justify-center gap-3 text-center text-sm">
            <MessageCircle className="size-8" />
            <p className="max-w-sm">{children}</p>
        </div>
    );
}

function Message({ message, onDraftChange }: { message: ChatMessage; onDraftChange: (draft: Draft) => void }) {
    if (message.role === 'user') {
        return (
            <div className="flex justify-end">
                <div
                    className={cn(
                        'bg-primary text-primary-foreground max-w-[85%] rounded-2xl rounded-br-sm px-4 py-2 text-sm whitespace-pre-wrap',
                        message.failed && 'opacity-60',
                    )}
                >
                    {message.body}
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <div className={cn('max-w-[85%] text-sm leading-relaxed whitespace-pre-wrap', message.failed && 'text-destructive')}>{message.body}</div>
            {message.drafts.map((draft) => (
                <DraftCard key={draft.id} draft={draft} onChange={onDraftChange} />
            ))}
        </div>
    );
}

function DraftCard({ draft, onChange }: { draft: Draft; onChange: (draft: Draft) => void }) {
    const [busy, setBusy] = useState<'confirm' | 'cancel' | null>(null);
    const [error, setError] = useState<{ message: string; upgradeUrl?: string } | null>(null);
    const money = (pence: number) => formatMoney(pence, draft.currency);

    const act = async (action: 'confirm' | 'cancel') => {
        setBusy(action);
        setError(null);

        try {
            const { ok, json } = await postJson(route(action === 'confirm' ? 'assistant.drafts.confirm' : 'assistant.drafts.cancel', draft.id));

            if (!ok) {
                setError({
                    message: (json.message as string) ?? 'Something went wrong. Please try again.',
                    upgradeUrl: json.upgrade_url as string | undefined,
                });
                return;
            }

            onChange(json.draft as Draft);
        } catch {
            setError({ message: 'Something went wrong. Please try again.' });
        } finally {
            setBusy(null);
        }
    };

    return (
        <div className={cn('bg-card max-w-xl rounded-xl border text-sm shadow-xs', draft.status === 'cancelled' && 'opacity-60')}>
            <div className="flex items-start justify-between gap-3 border-b px-4 py-3">
                <div className="flex items-center gap-2">
                    <FileText className="text-muted-foreground size-4" />
                    <div>
                        <p className="font-medium">Invoice for {draft.client_name ?? 'unknown client'}</p>
                        <p className="text-muted-foreground text-xs">
                            Issued {formatDate(draft.issue_date)}
                            {draft.due_date && <> · due {formatDate(draft.due_date)}</>}
                            {draft.po_number && <> · PO {draft.po_number}</>}
                            {draft.reference && <> · ref {draft.reference}</>}
                        </p>
                    </div>
                </div>
                {draft.status === 'pending' && <span className="text-muted-foreground text-xs">Not created yet</span>}
            </div>

            <table className="w-full">
                <tbody>
                    {draft.items.map((item, index) => (
                        <tr key={index} className="border-b last:border-0">
                            <td className="px-4 py-2">
                                {item.description}
                                <span className="text-muted-foreground block text-xs">
                                    {Number(item.quantity)} {item.unit ?? '×'} {money(item.unit_price)} · VAT {formatRate(item.tax_rate)}
                                </span>
                            </td>
                            <td className="px-4 py-2 text-right tabular-nums">{money(item.net_amount)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <div className="space-y-1 border-t px-4 py-3 tabular-nums">
                <div className="text-muted-foreground flex justify-between">
                    <span>Subtotal</span>
                    <span>{money(draft.subtotal)}</span>
                </div>
                <div className="text-muted-foreground flex justify-between">
                    <span>VAT</span>
                    <span>{money(draft.tax_total)}</span>
                </div>
                <div className="flex justify-between font-medium">
                    <span>Total</span>
                    <span>{money(draft.total)}</span>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 border-t px-4 py-3">
                {draft.status === 'pending' && (
                    <>
                        <Button size="sm" onClick={() => act('confirm')} disabled={busy !== null}>
                            {busy === 'confirm' ? <LoaderCircle className="animate-spin" /> : <Check />} Create invoice
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => act('cancel')} disabled={busy !== null}>
                            <X /> Cancel
                        </Button>
                    </>
                )}
                {draft.status === 'created' && draft.document && (
                    <p className="flex items-center gap-2">
                        <Check className="size-4 text-green-600" /> Created as a draft:{' '}
                        <Link href={draft.document.url} className="font-medium underline">
                            {draft.document.number}
                        </Link>
                    </p>
                )}
                {draft.status === 'cancelled' && <p className="text-muted-foreground">Cancelled</p>}
                {error && (
                    <p className="text-destructive w-full">
                        {error.message}{' '}
                        {error.upgradeUrl && (
                            <Link href={error.upgradeUrl} className="underline">
                                See plans
                            </Link>
                        )}
                    </p>
                )}
            </div>
        </div>
    );
}
