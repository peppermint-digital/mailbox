<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Signaturen eines Produkts, das sie selbst fuehrt.
 *
 * Dieselbe Form wie in AI Brain (`mail_signatures`): Person, wahlweise ein
 * Postfach, Name, Rumpf, Vorgabe. Eine Zeile ohne Postfach gilt fuer alle
 * Postfaecher dieser Person. Gleiche Form heisst: Eine Uebernahme bildet
 * Feld auf Feld ab und erfindet nichts dazu.
 *
 * ## Warum die Fremdschluessel loeschen statt leeren
 *
 * Verschwindet das Postfach, darf seine Signatur nicht zur Signatur fuer
 * ALLE Postfaecher werden — `nullOnDelete` haette genau das getan, und die
 * Teamunterschrift stuende ploetzlich unter privaten Mails.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = (string) config('mailbox.tables.signatures', 'mail_signatures');

        if (Schema::hasTable($tabelle)) {
            return;
        }

        $konten = (string) config('mailbox.tables.accounts', 'mail_accounts');
        $personenModell = (string) config('mailbox.user_model', 'App\\Models\\User');
        $personen = class_exists($personenModell) ? (new $personenModell)->getTable() : 'users';

        Schema::create($tabelle, function (Blueprint $table) use ($konten, $personen): void {
            $table->id();
            $table->foreignId('user_id')->constrained($personen)->cascadeOnDelete();
            $table->foreignId('mail_account_id')->nullable()->constrained($konten)->cascadeOnDelete();
            $table->string('name');
            $table->longText('body_html');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'mail_account_id'], 'mail_signatures_person_konto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('mailbox.tables.signatures', 'mail_signatures'));
    }
};
