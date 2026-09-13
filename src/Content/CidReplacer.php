<?php

namespace Peppermint\Mailbox\Content;

/**
 * Replaces `cid:` references in a mail body with something a browser can show.
 *
 * ## What a cid is, and why this keeps going wrong
 *
 * An HTML mail carries its images as separate parts and points at them with
 * `<img src="cid:logo@example">`. A browser knows nothing about that scheme, so
 * before display every reference has to be swapped for a data URL or a real
 * URL. Miss one and the reader sees a broken image in an otherwise fine mail.
 *
 * The catch is spelling: a Content-ID appears with angle brackets in the part
 * header and usually without them in the `src`. Anyone matching only one form
 * replaces half the references and blames the mail client.
 *
 * ## One method, two callers
 *
 * It was lifted from peppermint-manager, where the same replacement existed
 * twice — once for inline images turned into data URLs, once for stored images
 * behind a URL. The only difference was which spelling each version tried, so
 * this one tries all of them.
 */
class CidReplacer
{
    /**
     * @param  array<string, string>  $cidToTarget  Content-ID => data URL or URL
     */
    public function replace(string $html, array $cidToTarget): string
    {
        foreach ($cidToTarget as $contentId => $target) {
            foreach ($this->spellings((string) $contentId) as $spelling) {
                $pattern = '/cid:'.preg_quote($spelling, '/').'/i';

                $html = preg_replace($pattern, $this->escapeReplacement($target), $html) ?? $html;
            }
        }

        return $html;
    }

    /**
     * Every way this Content-ID can appear in a `src` attribute, longest first.
     *
     * Longest first matters: replacing the bare id inside `cid:<logo@x>` would
     * leave the angle brackets behind as stray text.
     *
     * @return list<string>
     */
    private function spellings(string $contentId): array
    {
        $bare = trim(trim($contentId), '<>');

        if ($bare === '') {
            return [];
        }

        $spellings = ['<'.$bare.'>', $bare];

        // The id as given, when it is neither of the two above.
        if (! in_array($contentId, $spellings, true) && trim($contentId) !== '') {
            array_unshift($spellings, trim($contentId));
        }

        return array_values(array_unique($spellings));
    }

    /**
     * A data URL contains base64, and base64 contains `$` and `\` — both of
     * which `preg_replace` reads as back-references. Unescaped, the image
     * silently loses characters.
     */
    private function escapeReplacement(string $target): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $target);
    }
}
