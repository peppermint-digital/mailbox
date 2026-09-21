import { Reply, Users } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ComponentType } from 'react';
import EmailBodyViewer, { type EmailBodyViewerLabels } from './EmailBodyViewer';
import { buildReplyQuote, type QuoteOptions } from './quote';
import { replyCcRecipients, replyToRecipients, type ReplyAddress } from './replyRecipients';
import { Button } from './ui/button';
import type { MessageHandle } from './rows';

/**
 * A reply field right inside the mailbox view.
 *
 * ## Why this one does not send
 *
 * It hands the finished reply to `onSend` instead of posting it. The page
 * above knows the folder and the uid the message sits in; this panel does not,
 * and giving it those would tie it to one way of addressing mail.
 *
 * That is also why it lives in the base entry and not under `/inertia`: there
 * is no form round trip here to share.
 *
 * ## One difference to the full composer
 *
 * This one refuses to fire on an empty text. In a list view the send button is
 * two keystrokes away from where people scroll, and an empty reply is never
 * what somebody meant.
 */

export interface InlineReplyAccount {
    id: number;
    name: string;
    email: string;
}

export interface InlineReplyMessage {
    uid: MessageHandle;
    message_id: string;
    subject: string;
    from_address: string;
    from_name: string;
    to: ReplyAddress[];
    cc: ReplyAddress[];
    date: string;
    body_html: string | null;
    body_text: string | null;
}

export interface InlineReplyLabels {
    reply: string;
    replyAll: string;
    allRecipients: string;
    senderOnly: string;
    quotedText: string;
    quote: QuoteOptions;
    body: EmailBodyViewerLabels;
}

export interface InlineReplyPanelProps {
    account: InlineReplyAccount;
    message: InlineReplyMessage;
    labels: InlineReplyLabels;
    /** The product's composer — already carrying editor, recipients, signatures and its words. */
    composer: ComponentType<Record<string, unknown>>;
    replyAll?: boolean;
    sending?: boolean;
    onSend: (
        bodyHtml: string,
        to: ReplyAddress[],
        cc: ReplyAddress[],
        subject: string,
        attachments: File[],
        quotedHtml: string,
    ) => void;
    onCancel: () => void;
}

export function InlineReplyPanel({
    account,
    message,
    labels,
    composer: Composer,
    replyAll = false,
    sending = false,
    onSend,
    onCancel,
}: InlineReplyPanelProps) {
    const source = useMemo(
        () => ({
            from_address: message.from_address,
            from_name: message.from_name,
            to_addresses: message.to,
            cc_addresses: message.cc,
            own_address: account.email,
        }),
        [message, account],
    );

    const [isReplyAll, setIsReplyAll] = useState(replyAll);
    const [bodyHtml, setBodyHtml] = useState('');
    const [attachments, setAttachments] = useState<File[]>([]);
    const [to, setTo] = useState<ReplyAddress[]>(() => replyToRecipients(source, replyAll));
    const [cc, setCc] = useState<ReplyAddress[]>(() => replyCcRecipients(source, replyAll));
    const [subject, setSubject] = useState(
        message.subject.startsWith('Re:') ? message.subject : `Re: ${message.subject}`,
    );

    const firstRun = useRef(true);

    useEffect(() => {
        if (firstRun.current) {
            firstRun.current = false;

            return;
        }

        setTo(replyToRecipients(source, isReplyAll));
        setCc(replyCcRecipients(source, isReplyAll));
    }, [isReplyAll, source]);

    const quotedHtml = useMemo(
        () =>
            buildReplyQuote(
                {
                    date: message.date,
                    fromName: message.from_name,
                    fromAddress: message.from_address,
                    bodyHtml: message.body_html,
                    bodyText: message.body_text,
                },
                labels.quote,
            ),
        [message, labels.quote],
    );

    const send = () => {
        if (to.length === 0 || !bodyHtml.trim()) {
            return;
        }

        onSend(bodyHtml, to, cc, subject, attachments, quotedHtml);
    };

    return (
        <div className="p-4">
            <Composer
                to={to}
                onToChange={setTo}
                cc={cc}
                onCcChange={setCc}
                subject={subject}
                onSubjectChange={setSubject}
                bodyHtml={bodyHtml}
                onBodyHtmlChange={setBodyHtml}
                attachments={attachments}
                onAttachmentsChange={setAttachments}
                allowAttachments
                signatureAccountId={account.id}
                sending={sending}
                onSend={send}
                onCancel={onCancel}
                header={
                    <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2 text-sm font-medium">
                            <Reply className="h-4 w-4" />
                            {isReplyAll ? labels.replyAll : labels.reply}
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className={isReplyAll ? 'bg-muted' : undefined}
                            onClick={() => setIsReplyAll((v) => !v)}
                        >
                            <Users className="mr-1 h-3 w-3" />
                            {isReplyAll ? labels.allRecipients : labels.senderOnly}
                        </Button>
                    </div>
                }
                quoted={
                    <div className="space-y-2">
                        <p className="text-xs font-medium text-muted-foreground">{labels.quotedText}</p>
                        <EmailBodyViewer bodyHtml={quotedHtml} autoHeight minHeight={120} labels={labels.body} />
                    </div>
                }
            />
        </div>
    );
}

export default InlineReplyPanel;
