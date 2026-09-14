import { Forward, Paperclip } from 'lucide-react';
import { useMemo, useState, type ComponentType } from 'react';
import EmailBodyViewer, { type EmailBodyViewerLabels } from '../EmailBodyViewer';
import { buildForwardQuote, type QuoteOptions } from '../quote';
import type { ReplyAddress } from '../replyRecipients';

/**
 * Forwarding a mail.
 *
 * ## Why the original is a pointer and not a payload
 *
 * `source` says WHERE the original is — a mailbox pointer (`folder` + `uid`)
 * or a stored id — instead of carrying its attachments. The server fetches
 * them itself, so a forward with a 20 MB attachment does not travel through
 * the browser twice.
 *
 * ## Why this one shows its errors inline
 *
 * The predecessor swallowed a failed draft save without a word: the composer
 * stayed open, and the draft was not saved. Somebody closed the window later
 * and lost the text. Every failure here ends up in `errorMessage`, which the
 * form displays.
 */

export type ForwardSource = { type: 'imap'; folder: string; uid: number } | { type: 'stored'; emailId: number };

export interface ForwardComposerLabels {
    forward: string;
    from: string;
    forwardedMessage: string;
    /** e.g. (2, "a.pdf, b.pdf") => "2 original attachments are included: a.pdf, b.pdf" */
    originalAttachments: (count: number, names: string) => string;
    forwarded: string;
    draftSaved: string;
    /** e.g. (422) => "Could not save the draft (error 422)." */
    draftFailed: (status: number) => string;
    failed: (status: number) => string;
    unknownError: string;
    quote: QuoteOptions;
    body: EmailBodyViewerLabels;
}

export interface ForwardEndpoints {
    forward: string;
    draft: string;
}

export interface ForwardComposerProps {
    account: { id: number; name: string; email: string };
    source: ForwardSource;
    subject: string;
    fromName?: string | null;
    fromAddress: string;
    toAddresses?: { email: string; name?: string }[];
    date: string;
    bodyHtml?: string | null;
    bodyText?: string | null;
    originalAttachments?: { filename: string }[];
    labels: ForwardComposerLabels;
    endpoints: ForwardEndpoints;
    /** The product's composer — already carrying editor, recipients, signatures and its words. */
    composer: ComponentType<Record<string, unknown>>;
    notify: { success(message: string): void };
    onSent: () => void;
    onCancel: () => void;
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export function ForwardComposer({
    account,
    source,
    subject: originalSubject,
    fromName = null,
    fromAddress,
    toAddresses = [],
    date,
    bodyHtml = null,
    bodyText = null,
    originalAttachments = [],
    labels,
    endpoints,
    composer: Composer,
    notify,
    onSent,
    onCancel,
}: ForwardComposerProps) {
    const [to, setTo] = useState<ReplyAddress[]>([]);
    const [cc, setCc] = useState<ReplyAddress[]>([]);
    const [bcc, setBcc] = useState<ReplyAddress[]>([]);
    const [subject, setSubject] = useState(
        originalSubject.startsWith('Fwd:') ? originalSubject : `Fwd: ${originalSubject}`,
    );
    const [body, setBody] = useState('');
    const [attachments, setAttachments] = useState<File[]>([]);
    const [sending, setSending] = useState(false);
    const [draftSaving, setDraftSaving] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const quotedHtml = useMemo(
        () =>
            buildForwardQuote(
                { date, fromName, fromAddress, subject: originalSubject, toAddresses, bodyHtml, bodyText },
                labels.quote,
            ),
        [date, fromName, fromAddress, originalSubject, toAddresses, bodyHtml, bodyText, labels.quote],
    );

    /** The shared body of sending and saving. */
    const buildFormData = (): FormData => {
        const data = new FormData();
        data.append('email_account_id', String(account.id));

        ([['to', to], ['cc', cc], ['bcc', bcc]] as const).forEach(([name, list]) => {
            list.forEach((r, i) => {
                data.append(`${name}[${i}][email]`, r.email);
                data.append(`${name}[${i}][name]`, r.name);
            });
        });

        data.append('subject', subject);
        data.append('body_html', body);
        data.append('quoted_html', quotedHtml);
        attachments.forEach((file, i) => data.append(`attachments[${i}]`, file));

        return data;
    };

    const post = (url: string, data: FormData) =>
        fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: data,
        });

    const send = async () => {
        if (to.length === 0) {
            return;
        }

        setSending(true);
        setErrorMessage(null);

        const data = buildFormData();
        data.append('source_type', source.type);

        if (source.type === 'imap') {
            data.append('source_folder', source.folder);
            data.append('source_uid', String(source.uid));
        } else {
            data.append('source_email_id', String(source.emailId));
        }

        try {
            const response = await post(endpoints.forward, data);
            const answer = await response.json().catch(() => ({}));

            if (!response.ok || answer.success === false) {
                setErrorMessage(answer.message ?? labels.failed(response.status));

                return;
            }

            notify.success(labels.forwarded);
            onSent();
        } catch (error) {
            setErrorMessage(error instanceof Error ? error.message : labels.unknownError);
        } finally {
            setSending(false);
        }
    };

    const saveDraft = async () => {
        setDraftSaving(true);
        setErrorMessage(null);

        try {
            const response = await post(endpoints.draft, buildFormData());

            if (response.ok) {
                notify.success(labels.draftSaved);
                onSent();

                return;
            }

            // Never silent: a swallowed failure leaves the composer open and
            // the draft unsaved, and the text is gone with the window.
            setErrorMessage(labels.draftFailed(response.status));
        } catch (error) {
            setErrorMessage(error instanceof Error ? error.message : labels.draftFailed(0));
        } finally {
            setDraftSaving(false);
        }
    };

    return (
        <Composer
            to={to}
            onToChange={setTo}
            cc={cc}
            onCcChange={setCc}
            bcc={bcc}
            onBccChange={setBcc}
            subject={subject}
            onSubjectChange={setSubject}
            bodyHtml={body}
            onBodyHtmlChange={setBody}
            attachments={attachments}
            onAttachmentsChange={setAttachments}
            signatureAccountId={account.id}
            allowBcc
            allowAttachments
            allowDraft
            draftSaving={draftSaving}
            sending={sending}
            errorMessage={errorMessage}
            submitLabel={labels.forward}
            onSend={() => void send()}
            onSaveDraft={() => void saveDraft()}
            onCancel={onCancel}
            header={
                <>
                    <div className="flex items-center gap-2 text-lg font-semibold">
                        <Forward className="h-5 w-5" />
                        {labels.forward}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {labels.from} {account.name} &lt;{account.email}&gt;
                    </p>
                    {originalAttachments.length > 0 && (
                        <p className="flex items-center gap-1 text-xs text-muted-foreground">
                            <Paperclip className="h-3 w-3" />
                            {labels.originalAttachments(
                                originalAttachments.length,
                                originalAttachments.map((a) => a.filename).join(', '),
                            )}
                        </p>
                    )}
                </>
            }
            quoted={
                <div className="space-y-2">
                    <p className="text-xs font-medium text-muted-foreground">{labels.forwardedMessage}</p>
                    <EmailBodyViewer bodyHtml={quotedHtml} autoHeight minHeight={120} labels={labels.body} />
                </div>
            }
        />
    );
}

export default ForwardComposer;
