<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('legt das Verzeichnis an, auch wenn die Paket-Migrationen abgeschaltet sind', function () {
    /*
     * Der Fund, der diese Datei nötig gemacht hat: Die Migration lag zuerst
     * neben den Kontotabellen und wurde deshalb in KEINEM der vier Produkte
     * angelegt — alle vier stehen auf `run_migrations => false`.
     *
     * Der Grund dafür ist gut (keine zweite, leere Kontotabelle neben der
     * gewachsenen) und gilt für dieses Verzeichnis nicht: Es gehört dem Paket
     * allein, kein Fremdschlüssel zeigt darauf, kein Produkt liest es selbst.
     */
    expect(Schema::hasTable('mailbox_headers'))->toBeTrue()
        ->and(Schema::hasTable('mail_accounts'))->toBeFalse();
});
