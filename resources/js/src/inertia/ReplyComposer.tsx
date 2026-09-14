import { useForm } from '@inertiajs/react';
import { Reply, Users } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ComponentType } from 'react';
import EmailBodyViewer, { type EmailBodyViewerLabels } from '../EmailBodyViewer';
import { buildReplyQuote, type QuoteOptions } from '../quote';
import { replyCcRecipients, replyToRecipients, type ReplyAddress } from '../replyRecipients';
import { Button } from '../ui/button';
import { Card, CardContent } from '../ui/card';

/**
 * Replying to a stored mail — the Inertia half.
 *
 * ## Why this is a separate entry point
 *
 * `@peppermint-digital/mailbox/inertia` needs `@inertiajs/react`, and the base
 * package must not. Two Laravel products that both speak Inertia should not
 * each rebuild form state, validation errors and the submit round trip; anyone
 * else imports the plain composer and wires their own.
 *
 * ## What the product still brings
 *
 * The **addresses** of its endpoints, its **composer** (already fitted with
 * editor, recipients and signatures), its **notifications**, and its
 * **words**. None of that is knowable from here, and guessing any of it would
 * make this fit exactly one application.
 */

export interface ReplyOriginal {
    id: number;
    subject: string;
    from_address: string;
    from_name: string | null;
    to_addresses: ReplyAddress[];
    cc_addresses: ReplyAddress[] | null;
    body_html: string | null;
    body_text: string | null;
    received_at: string;
    email_account: { id: number; name: string; email: string };
}

export interface ReplyComposerLabels {
    reply: string;
    replyAll: string;
    /** Button text while "all" is on / off. */
    allRecipients: string;
    senderOnly: string;
    /** Tooltip of the switch, in both directions. */
    switchToSenderOnly: string;
    switchToAll: string;
    /** "From: <account>" above the form. */
    from: string;
    quotedText: string;
    draftSaved: string;
    draftFailed: string;
    quote: QuoteOptions;
    body: EmailBodyViewerLabels;
}

export interface ReplyEndpoints {
    /** Where the reply is posted. */
    reply(emailId: number): string;
    /** Where a draft is stored (multipart, attachments included). */
    draft: string;
}

export interface ReplyComposerProps {
    email: ReplyOriginal;
    labels: ReplyComposerLabels;
    endpoints: ReplyEndpoints;
    /** The product's composer — already carrying editor, recipients, signatures and its words. */
    composer: ComponentType<Record<string, unknown>>;
    notify: { success(message: string): void; error(message: string): void };
    replyAll?: boolean;
    onCancel: () => void;
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export function ReplyComposer({
    email,
    labels,
    endpoints,
    composer: Composer,
    notify,
    replyAll = false,
    onCancel,
}: ReplyComposerProps) {
    const [sending, setSending] = useState(false);
    const [draftSaving, setDraftSaving] = useState(false);
    const [isReplyAll, setIsReplyAll] = useState(replyAll);

    const source = useMemo(
        () => ({
            from_address: email.from_address,
            from_name: email.from_name,
            to_addresses: email.to_addresses,
            cc_addresses: email.cc_addresses,
            own_address: email.email_account.email,
        }),
        [email],
    );

    const form = useForm({
        to: replyToRecipients(source, replyAll),
        cc: replyCcRecipients(source, replyAll),
        subject: email.subject.startsWith('Re:') ? email.subject : `Re: ${email.subject}`,
        body_html: '',
        quoted_html: '',
        attachments: [] as File[],
    });

    // `setData` is not stable across renders; without the mirror the effect
    // below would recompute the recipients on every keystroke.
    const setData = useRef(form.setData);
    setData.current = form.setData;

    const firstRun = useRef(true);

    useEffect(() => {
        if (firstRun.current) {
            firstRun.current = false;

            return;
        }

        setData.current('to', replyToRecipients(source, isReplyAll));
        setData.current('cc', replyCcRecipients(source, isReplyAll));
    }, [isReplyAll, source]);

    const quotedHtml = useMemo(
        () =>
            buildReplyQuote(
                {
                    date: email.received_at,
                    fromName: email.from_name,
                    fromAddress: email.from_address,
                    bodyHtml: email.body_html,
                    bodyText: email.body_text,
                },
                labels.quote,
            ),
        [email, labels.quote],
    );

    const send = () => {
        if (form.data.to.length === 0) {
            return;
        }

        setSending(true);
        form.transform((data) => ({ ...data, quoted_html: quotedHtml }));
        form.post(endpoints.reply(email.id), {
            preserveScroll: true,
            onFinish: () => setSending(false),
        });
    };

    const saveDraft = async () => {
        setDraftSaving(true);

        const body = new FormData();
        body.append('email_account_id', String(email.email_account.id));
        form.data.to.forEach((r, i) => {
            body.append(`to[${i}][email]`, r.email);
            body.append(`to[${i}][name]`, r.name);
        });
        form.data.cc.forEach((r, i) => {
            body.append(`cc[${i}][email]`, r.email);
            body.append(`cc[${i}][name]`, r.name);
        });
        body.append('subject', form.data.subject);
        body.append('body_html', form.data.body_html);
        body.append('quoted_html', quotedHtml);
        form.data.attachments.forEach((file, i) => body.append(`attachments[${i}]`, file));

        try {
            const response = await fetch(endpoints.draft, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body,
            });

            if (response.ok) {
                notify.success(labels.draftSaved);
                onCancel();
            } else {
                notify.error(labels.draftFailed);
            }
        } catch {
            notify.error(labels.draftFailed);
        } finally {
            setDraftSaving(false);
        }
    };

    return (
        <Card>
            <CardContent className="pt-6">
                <Composer
                    to={form.data.to}
                    onToChange={(v: ReplyAddress[]) => form.setData('to', v)}
                    cc={form.data.cc}
                    onCcChange={(v: ReplyAddress[]) => form.setData('cc', v)}
                    subject={form.data.subject}
                    onSubjectChange={(v: string) => form.setData('subject', v)}
                    bodyHtml={form.data.body_html}
                    onBodyHtmlChange={(v: string) => form.setData('body_html', v)}
                    attachments={form.data.attachments}
                    onAttachmentsChange={(v: File[]) => form.setData('attachments', v)}
                    signatureAccountId={email.email_account.id}
                    allowAttachments
                    sending={sending || form.processing}
                    errors={form.errors as Record<string, string>}
                    allowDraft
                    draftSaving={draftSaving}
                    onSend={send}
                    onSaveDraft={() => void saveDraft()}
                    onCancel={onCancel}
                    header={
                        <>
                            <div className="flex items-center justify-between gap-2">
                                <div className="flex items-center gap-2 text-lg font-semibold">
                                    <Reply className="h-5 w-5" />
                                    {isReplyAll ? labels.replyAll : labels.reply}
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className={isReplyAll ? 'bg-muted' : undefined}
                                    title={isReplyAll ? labels.switchToSenderOnly : labels.switchToAll}
                                    onClick={() => setIsReplyAll((v) => !v)}
                                >
                                    <Users className="mr-2 h-4 w-4" />
                                    {isReplyAll ? labels.allRecipients : labels.senderOnly}
                                </Button>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {labels.from} {email.email_account.name} &lt;{email.email_account.email}&gt;
                            </p>
                        </>
                    }
                    quoted={
                        <div className="space-y-2">
                            <p className="text-xs font-medium text-muted-foreground">{labels.quotedText}</p>
                            <EmailBodyViewer bodyHtml={quotedHtml} autoHeight minHeight={120} labels={labels.body} />
                        </div>
                    }
                />
            </CardContent>
        </Card>
    );
}

export default ReplyComposer;
