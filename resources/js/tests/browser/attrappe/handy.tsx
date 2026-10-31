/**
 * Die Attrappe fuer die Handy-Messreihe (#9134).
 *
 * Gezeichnet werden die ECHTEN Bauteile des Pakets in der Anordnung, die
 * {@see MailboxFlaeche} herstellt — Rahmen, schmale Leiste, Schublade,
 * Leseansicht mit Aktionen unten, schwebender Verfassen-Knopf, Assistent als
 * Overlay. Nachgebaut ist nur, was Daten braucht: ein paar Ordner, ein paar
 * Zeilen Liste, eine Nachricht.
 *
 * Warum nicht `MailboxFlaeche` selbst: Die haengt an `useMailboxSeite` und
 * damit an HTTP. Eine Attrappe, die dafuer `fetch` faelscht, misst zur Haelfte
 * die Faelschung. Der Rahmen und die Leiste sind das, was #9134 aendert — und
 * die stehen hier echt.
 *
 * Vier Zustaende stehen gleichzeitig auf der Seite, jeder in einem eigenen
 * Kasten von Geraetegroesse. Der Test faehrt die Geraetebreiten ab und misst
 * in jedem Kasten.
 */
import { useState } from 'react';
import { useMailboxSidePanel } from '../../../src/MailboxSidePanel';
import { germanLabels } from '../../../src/germanLabels';
import { MailboxBrowser } from '../../../src/MailboxBrowser';
import { MailboxKontoAuswahl } from '../../../src/MailboxHeader';
import { MailboxMobilLeiste, MailboxVerfassenKnopf } from '../../../src/MailboxMobilLeiste';
import { useMailboxPane } from '../../../src/MailboxPane';
import { MailboxSidePanel } from '../../../src/MailboxSidePanel';
import { MailFolderList, type MailFolder } from '../../../src/MailFolderList';
import { MailMessageView, type MailMessageViewMessage } from '../../../src/MailMessageView';
import { MailWerkzeugleiste } from '../../../src/MailWerkzeugleiste';
import { leereSignaturQuelle, VerfassenFeld } from '../../../src/VerfassenFeld';
import { Button } from '../../../src/ui/button';
import { FolderInput, Trash2 } from 'lucide-react';
import type { MailWerkzeug } from '../../../src/useMailWerkzeuge';
import { createRoot } from 'react-dom/client';

/*
 * Die Namen, wie ein Exchange sie meldet — nicht die bequemen deutschen.
 *
 * Mit „Posteingang / Entwürfe / Gesendet" waere die Attrappe durchgelaufen und
 * haette den Befund aus dem Rollout nie erzeugt: Die Verwaltung zeigte auf dem
 * Telefon „INBOX", weil der Name roh vom Server kommt (#9134, 30.10.2026).
 * Dazu eine kodierte Schreibweise, wie Office 365 sie liefert, und ein eigener
 * Ordner, der NICHT uebersetzt werden darf.
 */
const ORDNER: MailFolder[] = [
    { name: 'INBOX', path: 'INBOX' },
    { name: 'Entw&APw-rfe', path: 'Entw&APw-rfe' },
    { name: 'Sent Items', path: 'Sent Items' },
    { name: 'Junk E-Mail', path: 'Junk E-Mail' },
    { name: 'Gelöschte Elemente', path: 'Gelöschte Elemente' },
    { name: 'Archiv 2024', path: 'Archiv 2024' },
];

/*
 * Der Assistent steht im Speicher auf „offen" — so, wie ihn jemand am
 * Schreibtisch hinterlaesst. Genau das hat ihn am Telefon ueber die Liste
 * gelegt, und ohne diese Zeile misst die Attrappe den Fall nicht, der gemeldet
 * wurde.
 */
const SPEICHER = 'attrappe.mailbox.assistent';
try {
    localStorage.setItem(SPEICHER, 'true');
} catch {
    // Privates Fenster — dann steht die Vorgabe, und der Fall wird nicht geprueft.
}

const KONTEN = [{ id: 1, name: 'department.purchasing@peppermint-digital.de' }];

/*
 * Die Verben des CRM aus Bastians Bild — aufgeklappt waren sie dort rund
 * 180 px hoch, und zwar ueber der Mail, die man lesen wollte. Sie gehoeren in
 * die Messung, sonst misst sie einen Fall, den es in keinem Produkt gibt.
 */
const WERKZEUGE: MailWerkzeug[] = [
    ['ticket', 'Ticket erstellen', 'ticket'],
    ['beleg', 'Als Eingangsrechnung', 'receipt'],
    ['zustaendig', 'Zuständig', 'user-check'],
    ['aufgabe', 'Aufgabe anhängen', 'list-checks'],
].map(([key, label, icon], i) => ({
    key: key!,
    label: label!,
    icon: icon!,
    order: i,
    group: null,
    fields: null,
    confirm: null,
    state: null,
    href: null,
    system: 'Verwaltung',
})) as MailWerkzeug[];

const NACHRICHT: MailMessageViewMessage = {
    uid: 4711,
    message_id: '<handy-9134@peppermint-digital.de>',
    subject: 'Partneranfrage: CRUDE HARZ — Lieferung Q1/2027, Konditionen und Zahlungsziel bitte bestätigen',
    from_address: 'newsletter@service.tiktok.com',
    from_name: '',
    to: [
        { email: 'einkauf@peppermint-digital.de', name: 'Einkauf' },
        { email: 'bastian@peppermint-digital.de', name: 'Bastian' },
    ],
    cc: [{ email: 'buchhaltung@peppermint-digital.de', name: 'Buchhaltung' }],
    date: '2026-10-30T14:12:00+00:00',
    /*
     * HTML mit einem FREMDEN Bild, nicht nur Text.
     *
     * Dann zeichnet `EmailBodyViewer` den Hinweis „Externe Bilder wurden
     * blockiert" — rund 50 px, die ueber dem Mailtext stehen. Die erste
     * Fassung dieser Attrappe hatte nur `body_text` und damit keinen Hinweis;
     * gemessen wurde ein Fall, den eine echte Werbemail nie hat.
     */
    body_html:
        '<p><img src="https://example.invalid/pixel.gif" width="1" height="1" alt=""></p>' +
        Array.from({ length: 40 }, (_, i) => `<p>Zeile ${i + 1} des Mailtextes, damit es etwas zu scrollen gibt.</p>`).join(''),
    body_text: null,
    attachments: [],
    is_read: true,
    is_flagged: false,
    source: 'mailbox',
};

/**
 * Die Zeilen schalten selbst auf die Leseansicht um — so, wie es die echte
 * {@see MailMessageList} tut. Nachgebaut ist nur das Aussehen einer Zeile;
 * der Griff in den Kontext ist derselbe.
 */
function Liste() {
    const { showPane } = useMailboxPane();

    return (
        <div className="flex flex-1 flex-col overflow-hidden">
            <div className="border-b p-2 text-sm text-muted-foreground">24 Nachrichten</div>
            <div className="flex-1 overflow-y-auto">
                {Array.from({ length: 12 }, (_, i) => (
                    <div key={i} className="border-b p-3" data-slot="attrappe-zeile" onClick={() => showPane('view')}>
                        <div className="truncate text-sm font-medium">newsletter@service.tiktok.com</div>
                        <div className="truncate text-sm">Erinnerung: tolgatokerofficial hat dich bei TikTok erwähnt</div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * Ein Kasten in Geraetegroesse mit der ganzen Flaeche darin.
 *
 * Den Zustand der Flaechen fuehrt {@see MailboxBrowser} selbst — ein Provider
 * von aussen wuerde ueberschrieben. Der Test stellt die vier Zustaende
 * deshalb her, wie ein Mensch es tut: durch Antippen. Das ist ohnehin der
 * ehrlichere Weg, weil dabei die echten Knoepfe des Pakets benutzt werden.
 */
function Fassung({ kennung }: { kennung: string }) {
    /*
     * Der echte Haken, nicht ein `useState`. Nur so wird geprueft, was gemeldet
     * wurde: dass ein im Speicher liegender, am Schreibtisch gesetzter Zustand
     * den Assistenten am Telefon aufgehen laesst.
     */
    const assistent = useMailboxSidePanel(SPEICHER);
    const chatOffen = assistent.sichtbar;

    return (
        <>
            <div className="relative flex h-full flex-col overflow-hidden" data-rahmen={kennung}>
                <MailboxBrowser
                    eigeneNavigation
                    toolbar={
                        <>
                            <div className="max-md:hidden border-b px-6 py-4 text-2xl font-bold">E-Mails</div>
                            <MailboxMobilLeiste
                                ordnerName="Posteingang"
                                absender={NACHRICHT.from_name || NACHRICHT.from_address}
                                onAssistent={assistent.zeigen}
                                onAdressbuch={() => undefined}
                                onAktualisieren={() => undefined}
                            />
                        </>
                    }
                    schubladeKopf={
                        <MailboxKontoAuswahl
                            accounts={KONTEN}
                            accountId={1}
                            onAccountChange={() => undefined}
                            labels={germanLabels.header}
                            className="w-full"
                        />
                    }
                    folders={
                        <MailFolderList
                            folders={ORDNER}
                            labels={{ heading: 'Ordner', createFolder: 'Neuer Ordner' }}
                            selectedPath="INBOX"
                            onSelect={() => undefined}
                            onCreate={() => undefined}
                        />
                    }
                    list={<Liste />}
                    view={
                        <>
                        <MailWerkzeugleiste
                            werkzeuge={WERKZEUGE}
                            unerreichbar={[]}
                            gescheitert={[]}
                            laedt={false}
                            leerAberVollstaendig={false}
                            erreicht
                            ausloesen={() => Promise.resolve({ ok: true, message: 'ok' })}
                        />
                        <MailMessageView
                            aktionenUnten
                            message={NACHRICHT}
                            labels={germanLabels.view}
                            formatDateTime={(iso) => new Date(iso).toLocaleString('de-DE')}
                            formatAddressList={(a) => a.map((e) => e.name || e.email).join(', ')}
                            formatFileSize={(b) => `${Math.round(b / 1024)} KB`}
                            attachmentUrl={() => '#'}
                            onReply={() => undefined}
                            onForward={() => undefined}
                            onToggleRead={() => undefined}
                            onToggleFlag={() => undefined}
                            onArchive={() => undefined}
                            onDelete={() => undefined}
                            /*
                             * Der schlimmste Fall, und nicht der bequeme: NEUN
                             * Knoepfe wie in AI Brain — die sieben gemeinsamen,
                             * dazu der Verschieben-Knopf, den die Flaeche selbst
                             * zeichnet, und „Aus der Ablage entfernen" aus
                             * `zusatzAktionen`. Mit sieben passte alles in eine
                             * Reihe, und die Messung haette den Umbruch nie
                             * gesehen.
                             */
                            moveControl={
                                <Button size="sm" variant="outline" title="In Ordner verschieben" aria-label="In Ordner verschieben">
                                    <FolderInput className="h-4 w-4" />
                                </Button>
                            }
                            extraActions={
                                <Button size="sm" variant="outline" title="Aus der Ablage entfernen" aria-label="Aus der Ablage entfernen">
                                    <Trash2 className="h-4 w-4 text-destructive" />
                                </Button>
                            }
                        />
                        </>
                    }
                    schwebend={<MailboxVerfassenKnopf label="Neue E-Mail" onClick={() => undefined} />}
                    asideGewaehlt={assistent.gewaehlt}
                    aside={
                        chatOffen ? (
                            <MailboxSidePanel title="Postfach-Assistent" onClose={assistent.verbergen}>
                                <div className="flex h-full flex-col justify-end p-4 text-sm">
                                    <div className="flex-1">Der Assistent.</div>
                                    {/* Der Senden-Knopf unten rechts — genau der, auf dem
                                        der runde Verfassen-Knopf lag (56 x 26 px Ueberlappung,
                                        gemessen in CRM und Verwaltung). */}
                                    <div className="flex justify-end">
                                        <button type="button" className="rounded-md border px-3 py-2" data-slot="attrappe-senden">
                                            Senden
                                        </button>
                                    </div>
                                </div>
                            </MailboxSidePanel>
                        ) : undefined
                    }
                />
            </div>
        </>
    );
}

/**
 * Die Verfassen-Seite, wie die Produkte sie bauen.
 *
 * Gemeldet am 31.10.2026 (Session ui-fixes, weitergegeben von mail-rollout3):
 * Im Feld „Von" laeuft die Absenderzeile bei 390 px rechts aus dem Kasten.
 * Weitergegeben ist nicht gemessen — deshalb steht sie hier in der Messreihe
 * und nicht in einer Notiz.
 *
 * Die Adresse ist absichtlich lang: `info@crewtex.de` haette nie ueberlaufen.
 * Ein Postfachname plus eine lange Adresse in spitzen Klammern ist das, was
 * ein geteiltes Postfach wirklich liefert.
 */
function Verfassen() {
    const [kontoId, setKontoId] = useState<number | null>(1);
    const [an, setAn] = useState<{ email: string; name?: string }[]>([]);
    const [betreff, setBetreff] = useState('');
    const [inhalt, setInhalt] = useState('');

    return (
        <div className="h-full overflow-y-auto p-4">
            <VerfassenFeld
                /*
                 * Eigene Quellen statt der Vorgaben: Die Vorgabe fragt
                 * `/mailbox/recipients` ab, und in dieser Attrappe gibt es
                 * keinen Server dahinter. Ein 404 wuerde den Riegel „die
                 * Attrappe hat Fehler geworfen" ausloesen — zu Recht, nur
                 * saegt er dann am falschen Ast. Gemessen wird der KASTEN,
                 * nicht der Vorschlagsdienst.
                 */
                recipients={{ all: () => Promise.resolve([]), search: () => Promise.resolve([]) }}
                signatureSource={leereSignaturQuelle}
                accounts={[
                    { id: 1, name: 'Einkauf Peppermint Digital', email: 'department.purchasing@peppermint-digital.de' },
                    { id: 2, name: 'Info', email: 'info@crewtex.de' },
                ]}
                accountId={kontoId}
                onAccountIdChange={setKontoId}
                to={an}
                onToChange={setAn}
                subject={betreff}
                onSubjectChange={setBetreff}
                bodyHtml={inhalt}
                onBodyHtmlChange={setInhalt}
                vorhandeneAnhaenge={[{ filename: 'Rechnung-2026-0147.pdf', size: 128000 }]}
                onSend={() => undefined}
                onCancel={() => undefined}
            />
        </div>
    );
}

for (const kennung of ['liste', 'schublade', 'lesen', 'assistent', 'verfassen'] as const) {
    const kasten = document.createElement('div');
    kasten.dataset.fassung = kennung;
    /*
     * Die Breite macht das FENSTER, nicht dieser Kasten.
     *
     * `md:` und `max-md:` sind Fenster-Abfragen. Ein 390 px breiter Kasten in
     * einem 1400 px breiten Fenster zeichnet die SCHREIBTISCH-Fassung — der
     * erste Entwurf dieser Attrappe tat genau das, und die Messung meldete
     * folgerichtig „keine schmale Leiste da". Der Test faehrt deshalb die
     * Fenstergroesse durch; die Kaesten nehmen, was kommt.
     *
     * `contain: layout` macht jeden Kasten zum Bezug fuer das, was in ihm
     * schwebt. Ohne das klebten die vier Verfassen-Knoepfe alle am selben Fleck
     * des Fensters, und die Messung saehe eine Ueberlappung, die es auf einem
     * Telefon nicht gibt — dort ist genau eine Fassung im Bild.
     */
    kasten.style.cssText =
        'position:relative;contain:layout;isolate:isolate;display:block;width:100%;height:844px;overflow:hidden;outline:1px solid #ccc;outline-offset:-1px'
    document.body.appendChild(kasten);
    createRoot(kasten).render(kennung === 'verfassen' ? <Verfassen /> : <Fassung kennung={kennung} />);
}

requestAnimationFrame(() => {
    requestAnimationFrame(() => {
        document.body.dataset.gezeichnet = 'ja';
    });
});
