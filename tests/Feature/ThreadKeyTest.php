<?php

use Peppermint\Mailbox\Threading\ThreadKey;

/**
 * The threading rules, lifted from peppermint-manager (#5488).
 *
 * These cases are not invented — each one stands for a mail client that does
 * something slightly different with the headers. That is why they travel with
 * the rules instead of staying behind in the product they were found in.
 */
it('takes the root of the chain from References', function () {
    expect(ThreadKey::fromHeaders('<c@x>', '<b@x>', '<a@x> <b@x>'))->toBe('a@x');
});

it('falls back to the direct predecessor when References is absent', function () {
    expect(ThreadKey::fromHeaders('<c@x>', '<b@x>', null))->toBe('b@x');
});

it('lets an unrelated message start its own chain', function () {
    expect(ThreadKey::fromHeaders('<a@x>', null, null))->toBe('a@x');
});

it('unifies angle brackets and whitespace', function () {
    // The same conversation falls apart into two without this.
    expect(ThreadKey::normalize('  <a@x>  '))->toBe('a@x')
        ->and(ThreadKey::normalize('a@x'))->toBe('a@x')
        ->and(ThreadKey::normalize('   '))->toBeNull()
        ->and(ThreadKey::normalize(null))->toBeNull();
});

it('reads References separated by commas and line breaks too', function () {
    expect(ThreadKey::parseReferences("<a@x>,\n <b@x>\t<c@x>"))->toBe(['a@x', 'b@x', 'c@x']);
});

it('shortens an over-long root id instead of truncating it', function () {
    // Truncation would give two different conversations the same value — the
    // kind of bug that looks like "mails end up in the wrong thread".
    $lang = str_repeat('a', 300).'@x';

    expect(ThreadKey::fit($lang))->toBe('h:'.sha1($lang))
        ->and(mb_strlen((string) ThreadKey::fit($lang)))->toBeLessThan(255)
        ->and(ThreadKey::fit('kurz@x'))->toBe('kurz@x');
});

it('uses the subject as a last resort, marked as such', function () {
    expect(ThreadKey::fallbackFromSubject('Re: AW: Fwd: Angebot'))->toBe('subject:angebot')
        ->and(ThreadKey::fallbackFromSubject('   '))->toBeNull()
        ->and(ThreadKey::fallbackFromSubject(null))->toBeNull();
});

it('asks for both spellings when looking a message up', function () {
    expect(ThreadKey::bracketVariants('<a@x>'))->toBe(['a@x', '<a@x>']);
});

it('joins a shortened References chain to the chain already known', function () {
    // C answers B and names only B. The header rule alone would open a second
    // chain; because B is known, its chain id wins.
    $gespeichert = static fn (array $varianten): ?string => in_array('b@x', $varianten, true) ? 'a@x' : null;

    expect(ThreadKey::resolveAgainst($gespeichert, '<c@x>', '<b@x>', null))->toBe('a@x');
});

it('keeps the header answer when nothing is known yet', function () {
    expect(ThreadKey::resolveAgainst(fn () => null, '<c@x>', '<b@x>', null))->toBe('b@x');
});

it('has no answer for a message without any usable header', function () {
    expect(ThreadKey::resolveAgainst(fn () => 'egal', null, null, null))->toBeNull();
});
