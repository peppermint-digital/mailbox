<?php

namespace Peppermint\Mailbox\Exceptions;

use RuntimeException;

/**
 * An OAuth mailbox was about to be opened without an access token.
 *
 * Thrown instead of connecting, because connecting would send an empty
 * password and get back a login refusal — a message that mentions neither
 * OAuth nor the missing token, and sends whoever reads it hunting for a wrong
 * password that does not exist.
 */
class MissingAccessToken extends RuntimeException {}
