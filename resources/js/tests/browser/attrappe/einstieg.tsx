/**
 * Die Attrappe für die Breiten-Messreihe (Bug #1197).
 *
 * Hier wird KEINE Nachbildung der Leseansicht gezeichnet, sondern die echte
 * `MailMessageView` aus `resources/js/src` — mit den echten deutschen Worten
 * und den echten Knöpfen. Eine Nachbildung würde messen, was die Attrappe tut,
 * nicht was das Paket tut.
 *
 * Zwei Fassungen stehen gleichzeitig auf der Seite, weil die Knopfzahl je
 * Produkt verschieden ist:
 *
 * - `acht`  — CRM und Verwaltung: Antworten, Allen antworten, Weiterleiten,
 *             Gelesen, Markieren, Verschieben, Archivieren, Löschen.
 *             Leiste rund 444 px (gemessen am lebenden Element, 30.10.2026).
 * - `neun`  — AI Brain: dieselben acht plus „Aus der Ablage entfernen", das
 *             dort über `zusatzAktionen` dazukommt. Leiste rund 490 px.
 *
 * Genau diese Differenz ist der Grund, warum eine feste Schwelle im Paket den
 * Fall nicht treffen kann: Ein Produkt darf Knöpfe dazustellen.
 */
import { FolderInput, Trash2 } from 'lucide-react';
import { createRoot } from 'react-dom/client';
import { germanLabels } from '../../../src/germanLabels';
import { MailMessageView, type MailMessageViewMessage } from '../../../src/MailMessageView';
import { Button } from '../../../src/ui/button';

/**
 * Ein Betreff, wie er den Fehler gemeldet hat — lang, mit Wörtern, die für
 * sich genommen schon breit sind. Ein kurzer Betreff hätte in jeder Fassung
 * gepasst und nichts bewiesen.
 */
const BETREFF = 'Partneranfrage: CRUDE HARZ — Lieferung Q1/2027, Konditionen und Zahlungsziel bitte bestätigen';

const nachricht: MailMessageViewMessage = {
    uid: 4711,
    message_id: '<mess-1197@peppermint-digital.de>',
    subject: BETREFF,
    from_address: 'department.purchasing@peppermint-digital.de',
    from_name: '',
    to: [
        { email: 'einkauf@peppermint-digital.de', name: 'Einkauf' },
        { email: 'bastian@peppermint-digital.de', name: 'Bastian' },
    ],
    cc: [{ email: 'buchhaltung@peppermint-digital.de', name: 'Buchhaltung' }],
    date: '2026-10-30T14:12:00+00:00',
    body_html: null,
    body_text: 'Guten Tag,\n\nbitte bestätigen Sie uns die Konditionen.\n\nMit freundlichen Grüßen',
    attachments: [],
    is_read: true,
    is_flagged: false,
    source: 'mailbox',
};

function Fassung({ kennung, mitZusatz }: { kennung: string; mitZusatz: boolean }) {
    return (
        <MailMessageView
            message={nachricht}
            labels={germanLabels.view}
            formatDateTime={(iso) => new Date(iso).toLocaleString('de-DE')}
            formatAddressList={(adressen) => adressen.map((a) => a.name || a.email).join(', ')}
            formatFileSize={(bytes) => `${Math.round(bytes / 1024)} KB`}
            attachmentUrl={() => '#'}
            onReply={() => {}}
            onForward={() => {}}
            onToggleRead={() => {}}
            onToggleFlag={() => {}}
            onArchive={() => {}}
            onDelete={() => {}}
            /* Der Verschieben-Knopf, den die Fläche selbst zeichnet (A1, #9116). */
            moveControl={
                <Button size="sm" variant="outline" title={germanLabels.toolbar.moveTitle} aria-label={germanLabels.toolbar.moveTitle}>
                    <FolderInput className="h-4 w-4" />
                </Button>
            }
            /* Nur AI Brain: „Aus der Ablage entfernen" über `zusatzAktionen`. */
            extraActions={
                mitZusatz ? (
                    <Button size="sm" variant="outline" title="Aus der Ablage entfernen" data-attrappe={`${kennung}-zusatz`}>
                        <Trash2 className="h-4 w-4 text-destructive" />
                    </Button>
                ) : undefined
            }
        />
    );
}

for (const [kennung, mitZusatz] of [
    ['acht', false],
    ['neun', true],
] as const) {
    const kasten = document.createElement('div');
    kasten.dataset.fassung = kennung;
    /*
     * Der Rahmen gibt die Breite vor, die der Test durchfährt. Die Leseansicht
     * selbst trägt `flex flex-1 flex-col` — in den Produkten steht sie in einer
     * Flex-Spalte, also hier auch, sonst messen wir ein anderes Element als das
     * ausgelieferte.
     */
    kasten.style.cssText = 'display:flex;flex-direction:column;overflow:hidden;width:800px;margin-bottom:24px';
    document.body.appendChild(kasten);
    createRoot(kasten).render(<Fassung kennung={kennung} mitZusatz={mitZusatz} />);
}

/* Der Riegel: Der Test wartet darauf, statt auf eine Zeitspanne zu hoffen. */
requestAnimationFrame(() => {
    requestAnimationFrame(() => {
        document.body.dataset.gezeichnet = 'ja';
    });
});
