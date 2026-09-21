<?php

namespace Peppermint\Mailbox\Guard;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Der Riegel gegen das, was dieses Paket verhindern soll (#5848).
 *
 * „Eigentlich dürfte es da gar keine Möglichkeit geben, das anders zu machen."
 * Das ist der Auftrag — und er lässt sich prüfen statt besprechen.
 *
 * ## Warum das Paket die Regel hält und das Produkt sie ausführt
 *
 * Ein Test hier drin sieht die Produkte nicht. Also liefert das Paket die
 * Regel, und jedes Produkt lässt sie in der eigenen Suite über den eigenen
 * Code laufen. Dieselbe Trennung wie überall: Das Paket sagt WAS gilt, das
 * Produkt sagt WORÜBER.
 *
 * ## Was geprüft wird
 *
 * Drei Dinge, die alle dasselbe bedeuten — hier entsteht wieder ein eigener
 * Weg ans Postfach vorbei am Vertrag:
 *
 * 1. **Die Bibliothek direkt.** Wer `DirectoryTree\ImapEngine` anfasst, redet
 *    IMAP — und kann dasselbe Postfach über JMAP nicht mehr lesen.
 * 2. **Eigene Token-Rotation.** Zwei Parteien, die dasselbe Refresh-Token
 *    rotieren, entwerten es sich gegenseitig. Der Schaden zeigt sich Tage
 *    später als Anmeldeablehnung, in der nichts von OAuth steht.
 * 3. **Eigene Verbindungsdaten.** Ein `Mailbox::make([...])` mit Host und
 *    Kennwort aus der eigenen Tabelle ist die zweite Wahrheit in Reinform.
 *
 * ## Ausnahmen: genau eine begründete Stelle, nicht „nirgends"
 *
 * Die teuerste Lehre dieses Umbaus (21.09.2026): Ein Riegel, der ein Absolut
 * fordert, kann nicht melden, dass das Absolut falsch ist. Der Wächter im
 * Manager verlangte NULL Token-Erneuerungen — und schrieb damit fest, dass
 * drei zentral unbekannte Postfächer stillschweigend ausfallen, weil sich
 * niemand mehr um sie kümmerte.
 *
 * Deshalb: Ausnahmen sind erlaubt, aber sie kosten einen **Satz Begründung**
 * im Diff. Und sie altern nicht still — siehe {@see pruefen()}.
 */
class MailboxGuard
{
    /**
     * Muster, die einen eigenen Weg ans Postfach verraten.
     *
     * Bewusst Zeichenketten und keine regulären Ausdrücke: Was hier steht,
     * muss jemand beim Lesen des Fehlers wiedererkennen können.
     *
     * @var array<string, string> Fundstelle => was daran falsch ist
     */
    public const MUSTER = [
        'DirectoryTree\\ImapEngine' => 'spricht IMAP direkt und kann dasselbe Postfach über JMAP nicht mehr lesen',
        'refreshAccessToken(' => 'rotiert ein Refresh-Token selbst — tut AI Brain dasselbe, entwerten sie es sich gegenseitig',
    ];

    /**
     * Alles, was an diesem Code gegen die Regel verstößt — Ausnahmen inbegriffen.
     *
     * Eine Liste statt eines Wahrheitswerts, weil der Aufrufer sie in die
     * Fehlermeldung schreiben soll. Ist sie leer, ist alles in Ordnung.
     *
     * ## Die Ausnahmeliste wird mitgeprüft
     *
     * Eine Ausnahme, die nichts mehr trifft, ist ein Freibrief für die nächste
     * Datei, die zufällig so heißt. Sie taucht deshalb genauso als Problem auf
     * wie ein echter Verstoß — der einzige Weg, eine Liste am Leben zu halten,
     * ist, dass ihr Verfall wehtut.
     *
     * @param  string  $verzeichnis  meist app_path()
     * @param  array<string, string>  $ausnahmen  Pfad-Anfang => Begründung (ein Satz, kein „TODO")
     * @return list<string> leserliche Probleme, leer wenn alles stimmt
     */
    public static function pruefen(string $verzeichnis, array $ausnahmen = []): array
    {
        $funde = self::funde($verzeichnis);
        $probleme = [];
        $genutzt = [];

        foreach ($funde as [$pfad, $muster]) {
            $ausnahme = self::ausnahmeFuer($pfad, $ausnahmen);

            if ($ausnahme !== null) {
                $genutzt[$ausnahme] = true;

                continue;
            }

            $probleme[] = sprintf(
                '%s %s (gefunden: %s). Statt dessen: das Verb aus Peppermint\Mailbox\Contracts\Mailbox '
                .'benutzen — fehlt eines, gehört es in den Vertrag, nicht ins Produkt. '
                .'Muss es wirklich hier bleiben, trag den Pfad mit einem Satz Begründung in die Ausnahmen ein.',
                $pfad,
                self::MUSTER[$muster],
                $muster,
            );
        }

        foreach (array_keys($ausnahmen) as $pfad) {
            if (! isset($genutzt[$pfad])) {
                $probleme[] = sprintf(
                    'Die Ausnahme "%s" trifft nichts mehr — entweder ist die Datei weg oder sie verstößt '
                    .'nicht mehr. Eintrag löschen: Eine Ausnahme, die niemand braucht, befreit stillschweigend '
                    .'die nächste Datei, die zufällig so heißt.',
                    $pfad,
                );
            }
        }

        return $probleme;
    }

    /**
     * Jede Fundstelle als [relativer Pfad, Muster].
     *
     * Rekursiv über einen Iterator und NICHT per `glob('**')`: PHPs glob
     * steigt nicht ab. Ein Wächter, der nie etwas findet, ist grün und
     * wertlos — dieser Fehler ist beim Bau dieses Riegels wirklich passiert.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function funde(string $verzeichnis): array
    {
        $verzeichnis = rtrim($verzeichnis, '/');
        $funde = [];

        $dateien = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($verzeichnis, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($dateien as $datei) {
            if ($datei->isDir() || $datei->getExtension() !== 'php') {
                continue;
            }

            $inhalt = (string) file_get_contents($datei->getPathname());
            $relativ = ltrim(str_replace($verzeichnis, '', $datei->getPathname()), '/');

            foreach (array_keys(self::MUSTER) as $muster) {
                if (str_contains($inhalt, $muster)) {
                    $funde[] = [$relativ, $muster];
                }
            }
        }

        // Stabile Reihenfolge, damit eine Fehlermeldung zweimal gleich aussieht.
        sort($funde);

        return $funde;
    }

    /**
     * Welche Ausnahme diesen Pfad deckt — oder null.
     *
     * Pfad-ANFANG und kein Namensmuster: „alles was *Service heißt" wäre
     * bequem und würde beim nächsten gleichnamigen Neuzugang stillschweigend
     * mitbefreien.
     *
     * @param  array<string, string>  $ausnahmen
     */
    private static function ausnahmeFuer(string $pfad, array $ausnahmen): ?string
    {
        foreach (array_keys($ausnahmen) as $anfang) {
            if (str_starts_with($pfad, $anfang)) {
                return $anfang;
            }
        }

        return null;
    }
}
