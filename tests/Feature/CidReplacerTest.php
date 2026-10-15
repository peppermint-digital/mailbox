<?php

use Peppermint\Mailbox\Content\CidReplacer;
use Peppermint\Mailbox\Imap\MessageFormatter;

/**
 * Inline images in a mail body.
 *
 * Lifted from peppermint-manager, which had this logic twice and untested. The
 * failure mode is always the same and always looks like somebody else's fault:
 * a broken image in an otherwise fine mail.
 */
it('replaces a bare cid reference', function () {
    $html = '<img src="cid:logo@x">';

    expect((new CidReplacer)->replace($html, ['logo@x' => 'https://example.test/logo.png']))
        ->toBe('<img src="https://example.test/logo.png">');
});

it('replaces a reference written with angle brackets', function () {
    // The part header carries the id in brackets, the src usually without.
    // Matching only one spelling replaces half the references.
    $html = '<img src="cid:<logo@x>">';

    expect((new CidReplacer)->replace($html, ['logo@x' => 'U']))->toBe('<img src="U">');
});

it('finds the reference regardless of the spelling it was given', function () {
    $html = '<img src="cid:logo@x">';

    expect((new CidReplacer)->replace($html, ['<logo@x>' => 'U']))->toBe('<img src="U">');
});

it('does not leave angle brackets behind', function () {
    // Replacing the bare id first would turn `cid:<logo@x>` into `<U>`.
    $html = '<img src="cid:<logo@x>">';

    expect((new CidReplacer)->replace($html, ['logo@x' => 'U']))->not->toContain('<U>');
});

it('ignores the case of the reference', function () {
    expect((new CidReplacer)->replace('<img src="CID:Logo@X">', ['logo@x' => 'U']))
        ->toBe('<img src="U">');
});

it('survives a target containing regex back-references', function () {
    // `$` and `\` mean something to preg_replace. Base64 itself never contains
    // them, so an inline image is safe — a stored image behind a signed URL is
    // not, and that is the case this guards.
    $ziel = 'https://example.test/bild.png?sig=a$1b\\c';

    expect((new CidReplacer)->replace('<img src="cid:a@x">', ['a@x' => $ziel]))
        ->toBe('<img src="'.$ziel.'">');
});

it('survives regex characters in the content id', function () {
    $html = '<img src="cid:a.b+c(1)@x">';

    expect((new CidReplacer)->replace($html, ['a.b+c(1)@x' => 'U']))->toBe('<img src="U">');
});

it('replaces several images in one body', function () {
    $html = '<img src="cid:a@x"><img src="cid:b@x">';

    expect((new CidReplacer)->replace($html, ['a@x' => 'A', 'b@x' => 'B']))
        ->toBe('<img src="A"><img src="B">');
});

it('leaves a body without references untouched', function () {
    $html = '<p>Kein Bild weit und breit.</p>';

    expect((new CidReplacer)->replace($html, ['a@x' => 'A']))->toBe($html);
});

it('ignores an empty content id instead of mangling the body', function () {
    $html = '<img src="cid:a@x">';

    expect((new CidReplacer)->replace($html, ['' => 'A', '  ' => 'B']))->toBe($html);
});

it('ignoriert eine Kennung, die nur aus Klammern besteht', function () {
    $html = '<img src="cid:<>">';

    expect((new CidReplacer)->replace($html, ['<>' => 'A']))->toBe($html);
});

it('nennt nur Kennungen einen Rumpfteil, die im Rumpf auch ersetzbar sind', function (string $cid) {
    // DIE Kopplung, und der Grund, dass bareId() public ist: Die Regel nimmt
    // einen Teil aus der Anhangliste, weil er „schon im Rumpf steht" — wenn
    // dann nichts ersetzt wird, ist er nirgends. Genau so ist Bug #1023 ein
    // zweites Mal entstanden, mit der Kennung `<>`.
    $html = '<img src="cid:'.trim($cid).'">';

    $istRumpfteil = MessageFormatter::isEmbeddedImage($cid, 'inline', 'image/png');
    $wurdeErsetzt = (new CidReplacer)->replace($html, [$cid => 'data:image/png;base64,AA']) !== $html;

    expect($istRumpfteil)->toBe(
        $wurdeErsetzt,
        $istRumpfteil
            ? "Kennung \"{$cid}\" gilt als Rumpfteil, wird im Rumpf aber nicht ersetzt — der Teil ist nirgends."
            : "Kennung \"{$cid}\" wird im Rumpf ersetzt, gilt aber nicht als Rumpfteil — der Teil erscheint doppelt."
    );
})->with(['logo@x', '<logo@x>', '<>', ' <> ', '   ', 'a@x.test']);
