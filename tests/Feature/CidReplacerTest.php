<?php

use Peppermint\Mailbox\Content\CidReplacer;

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
