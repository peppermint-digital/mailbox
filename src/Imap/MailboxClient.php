<?php

namespace Peppermint\Mailbox\Imap;

use DirectoryTree\ImapEngine\Mailbox;
use Peppermint\Mailbox\Contracts\TokenRefresher;
use Peppermint\Mailbox\Models\MailAccount;
use RuntimeException;

/**
 * A mailbox, as a product uses one.
 *
 * This is the point of the package: install it, hand it an account, read mail.
 * Nothing above this class knows what IMAP is, and no product has to write the
 * connecting, the retrying, the folder arithmetic or the extraction again.
 *
 * ## Every call connects and disconnects
 *
 * Tempting to keep the connection open — and wrong for a web request. A held
 * IMAP connection outlives the request in the worker, and the next request on
 * the same worker inherits a socket whose server may have dropped it in the
 * meantime. The symptom is an error on an action nobody did anything wrong in.
 *
 * Cheap it is not; correct it is. Products that need a batch of operations
 * should pass them to {@see session()} rather than calling ten methods.
 *
 * ## The token is refreshed before connecting, not after failing
 *
 * An expired token gives an authentication error that looks exactly like a
 * wrong password. Refreshing first turns a confusing failure into no failure.
 */
class MailboxClient
{
    public function __construct(
        private readonly MailAccount $account,
        private readonly ?TokenRefresher $refresher = null,
        private readonly ?RetryPolicy $retry = null,
        private readonly int $timeout = 120,
        /** Told about each retry, so the product can log in its own way. */
        private readonly ?\Closure $onRetry = null,
        /**
         * Builds the connection. Injectable so the whole client can be shown
         * without a server — and so a product with its own IMAP setup can
         * keep it.
         *
         * @var null|\Closure(array): \DirectoryTree\ImapEngine\MailboxInterface
         */
        private readonly ?\Closure $connector = null,
    ) {
        if (! $connector && ! class_exists(Mailbox::class)) {
            throw new RuntimeException(
                'directorytree/imapengine is required to talk to a mailbox. '
                .'It is a suggest of peppermint/mailbox: install it in the product that reads mail.'
            );
        }
    }

    /**
     * Opens a connection, runs the callback, closes it — with retries.
     *
     * @template T
     *
     * @param  callable(mixed): T  $work
     * @return T
     */
    public function session(callable $work): mixed
    {
        $policy = $this->retry ?? new RetryPolicy;

        return $policy->run(function () use ($work) {
            $mailbox = $this->connect();

            try {
                return $work($mailbox);
            } finally {
                $mailbox->disconnect();
            }
        }, $this->onRetry);
    }

    /**
     * The folders of this mailbox, as plain rows.
     *
     * @return list<array{name: string, path: string, flags: list<string>}>
     */
    public function folders(): array
    {
        return $this->session(function ($mailbox): array {
            $rows = [];

            foreach ($mailbox->folders()->get() as $folder) {
                $rows[] = [
                    'name' => $folder->name(),
                    'path' => $folder->path(),
                    'flags' => array_map('strval', $folder->flags() ?? []),
                ];
            }

            return $rows;
        });
    }

    /**
     * Creates the standard folders this mailbox is missing.
     *
     * Best effort: a server that refuses one is not a reason to skip the rest.
     *
     * @return list<string> the paths actually created
     */
    public function ensureStandardFolders(): array
    {
        return $this->session(function ($mailbox): array {
            $rows = [];

            foreach ($mailbox->folders()->get() as $folder) {
                $rows[] = ['name' => $folder->name(), 'path' => $folder->path()];
            }

            $prefix = FolderPaths::inboxPrefix(array_column($rows, 'path'));
            $created = [];

            foreach (FolderPaths::missingStandardFolders($rows) as $kind) {
                foreach (FolderPaths::standardCandidates($kind, $prefix) as $path) {
                    try {
                        $folder = $mailbox->folders()->create($path);
                        $created[] = $folder->path();
                        break;
                    } catch (\Throwable) {
                        // This shape was refused; the next candidate may fit.
                        continue;
                    }
                }
            }

            return $created;
        });
    }

    public function createFolder(string $path): void
    {
        $this->session(fn ($mailbox) => $mailbox->folders()->create($path));
    }

    /**
     * Renames a folder, keeping it where it is.
     *
     * @throws RuntimeException when the folder is gone or is a system folder
     */
    public function renameFolder(string $path, string $newName): string
    {
        return $this->session(function ($mailbox) use ($path, $newName): string {
            $folder = $this->folderAt($mailbox, $path);
            $this->refuseSystemFolder($folder);

            $ziel = FolderPaths::renamed($folder->path(), $newName, $folder->delimiter() ?: '/');
            $folder->move($ziel);

            return $ziel;
        });
    }

    /** @throws RuntimeException when the folder is gone or is a system folder */
    public function deleteFolder(string $path): void
    {
        $this->session(function ($mailbox) use ($path): void {
            $folder = $this->folderAt($mailbox, $path);
            $this->refuseSystemFolder($folder);

            $folder->delete();
        });
    }

    /**
     * Marks a message read or unread.
     *
     * Returns false when the message is not there any more, rather than
     * throwing: a mailbox is shared and things move. Someone clicking "mark
     * read" on a mail a colleague just filed should see nothing happen, not an
     * error about a uid.
     */
    public function setSeen(string $folder, int $uid, bool $seen): bool
    {
        return $this->onMessage($folder, $uid, function ($message) use ($seen): bool {
            $seen ? $message->markSeen() : $message->unmarkSeen();

            return true;
        });
    }

    /** Sets or clears the flag. Returns false when the message is gone. */
    public function setFlagged(string $folder, int $uid, bool $flagged): bool
    {
        return $this->onMessage($folder, $uid, function ($message) use ($flagged): bool {
            $flagged ? $message->markFlagged() : $message->unmarkFlagged();

            return true;
        });
    }

    /**
     * Moves a message into another folder.
     *
     * Moving into the folder it already sits in is answered with true without
     * asking the server: it is not an error, nothing needs to happen, and some
     * servers refuse it in a way that reads like a real failure.
     */
    public function move(string $from, int $uid, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return $this->session(function ($mailbox) use ($from, $uid, $to): bool {
            $quelle = FolderResolver::resolve($mailbox->folders()->get(), $from, fn ($f) => $f->path(), fn ($f) => $f->name());
            $ziel = FolderResolver::resolve($mailbox->folders()->get(), $to, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $quelle || ! $ziel) {
                return false;
            }

            $message = $quelle->messages()->find($uid);

            if (! $message) {
                return false;
            }

            $message->move($ziel->path(), true);

            return true;
        });
    }

    /**
     * Deletes a message — into the trash where there is one.
     *
     * "Delete" in a mail client means "put it where I can get it back". Only
     * when there is no trash folder, or the message is already in it, does the
     * message actually go. Deleting outright from the inbox would be a
     * different promise than the button makes.
     *
     * @param  list<string>  $trashNames lowercase aliases of the trash folder
     */
    public function delete(string $folder, int $uid, array $trashNames = ['trash', 'papierkorb', 'deleted items', 'gelöschte elemente']): bool
    {
        return $this->session(function ($mailbox) use ($folder, $uid, $trashNames): bool {
            $quelle = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $quelle) {
                return false;
            }

            $message = $quelle->messages()->find($uid);

            if (! $message) {
                return false;
            }

            $papierkorb = null;

            foreach ($mailbox->folders()->get() as $kandidat) {
                if (FolderPaths::matchesAny($kandidat->name(), $kandidat->path(), $trashNames)) {
                    $papierkorb = $kandidat->path();
                    break;
                }
            }

            if ($papierkorb && $papierkorb !== $quelle->path()) {
                $message->move($papierkorb, true);
            } else {
                // Already in the trash, or there is none: now it really goes.
                $message->delete(true);
            }

            return true;
        });
    }

    /**
     * Runs something on one message, or answers false if it is not there.
     *
     * @param  callable(mixed): bool  $work
     */
    private function onMessage(string $folder, int $uid, callable $work): bool
    {
        return $this->session(function ($mailbox) use ($folder, $uid, $work): bool {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());
            $message = $ordner?->messages()->find($uid);

            if (! $message) {
                return false;
            }

            return $work($message);
        });
    }

    /**
     * Opens the connection.
     *
     * Deliberately untyped: a product may bring its own mailbox object, and
     * tests bring one that never touches a network. What the object has to do
     * is defined by the calls in this class, not by a class name.
     */
    private function connect(): mixed
    {
        // Before connecting, not after failing: an expired token looks exactly
        // like a wrong password from the outside.
        if ($this->refresher && $this->account->usesOAuth() && $this->account->isTokenExpiringSoon()) {
            $this->refresher->ensureFresh($this->account);
        }

        $settings = ConnectionSettings::for($this->account, $this->timeout)->values;

        return $this->connector ? ($this->connector)($settings) : Mailbox::make($settings);
    }

    private function folderAt($mailbox, string $path): mixed
    {
        $folder = FolderResolver::resolve(
            $mailbox->folders()->get(),
            $path,
            fn ($f) => $f->path(),
            fn ($f) => $f->name(),
        );

        if (! $folder) {
            throw new RuntimeException("Folder not found: {$path}");
        }

        return $folder;
    }

    private function refuseSystemFolder($folder): void
    {
        $flags = array_map('strval', $folder->flags() ?? []);

        if (SystemFolders::isProtected($folder->path(), $folder->name(), $flags)) {
            throw new RuntimeException('System folders cannot be renamed or deleted.');
        }
    }
}
