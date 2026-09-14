/**
 * Sammelaktionen über mehrere Nachrichten.
 *
 * ## Warum das gruppiert werden muss
 *
 * Eine UID ist **nur innerhalb ihres Ordners eindeutig**. Das ist keine
 * Feinheit des Protokolls, sondern der Grund für eine ganze Klasse von
 * Fehlern: Wer in einer Suchtrefferliste — deren Treffer aus verschiedenen
 * Ordnern stammen — alle ankreuzt und löscht, schickt sonst fremde Kennungen
 * an einen Ordner. Der Server löscht dann entweder nichts oder die falsche
 * Nachricht.
 *
 * Deshalb wird vor jeder Sammelaktion nach Ordnern gruppiert und pro Ordner
 * ein eigener Aufruf gemacht.
 */

/**
 * Teilt Kennungen nach dem Ordner auf, in dem sie wirklich liegen.
 *
 * @param uids die angekreuzten Kennungen
 * @param folderOf sagt, wo eine Kennung liegt — unbekannt heisst: Standardordner
 * @param fallback der Ordner für alles, was `folderOf` nicht kennt
 */
export function groupUidsByFolder(
    uids: Iterable<number>,
    folderOf: (uid: number) => string | null | undefined,
    fallback: string,
): Map<string, number[]> {
    const nachOrdner = new Map<string, number[]>();

    for (const uid of uids) {
        const ordner = folderOf(uid) || fallback;

        nachOrdner.set(ordner, [...(nachOrdner.get(ordner) ?? []), uid]);
    }

    return nachOrdner;
}
