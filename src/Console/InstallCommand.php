<?php

namespace Peppermint\Mailbox\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * One command to make this package usable in a Laravel + React application.
 *
 * ## Why this exists
 *
 * Installing used to be four steps across two package managers: composer, npm,
 * a Tailwind `@source` line, and publishing the config. Four steps in a README
 * are not an installation, they are an invitation to miss one — and the one
 * people miss is the `@source` line, because forgetting it does not fail.
 * Tailwind v4 skips `node_modules`, so every class that appears ONLY in this
 * package is dropped from the stylesheet. The page builds, the component
 * appears, it just sits wrong.
 *
 * Measured on 14.09.2026 in a sibling package: of 52 classes exactly one was
 * missing from the built CSS. The other 51 survived because the application
 * happened to use them somewhere else — luck, not order.
 *
 * Everything here is idempotent: running it twice changes nothing.
 */
class InstallCommand extends Command
{
    protected $signature = 'mailbox:install
        {--no-npm : Skip the JavaScript half}
        {--css= : Path to the Tailwind entry file, relative to the project}';

    protected $description = 'Install the mailbox package: config, Tailwind sources, and the JavaScript half';

    private const NPM_PACKAGE = 'github:peppermint-digital/mailbox';

    public function handle(): int
    {
        $this->components->info('Mailbox');

        $this->publishConfig();
        $this->registerTailwindSource();

        if (! $this->option('no-npm')) {
            $this->installJavaScript();
        }

        $this->newLine();
        $this->components->info('Done. Adopting an existing table? Map its column names in config/mailbox.php.');

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        if (File::exists(config_path('mailbox.php'))) {
            $this->components->twoColumnDetail('config/mailbox.php', '<fg=gray>already there</>');

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'mailbox-config']);
        $this->components->twoColumnDetail('config/mailbox.php', '<info>published</info>');
    }

    /**
     * Teach Tailwind to look inside the package.
     *
     * Appended rather than inserted at a fixed place: the file belongs to the
     * application, and a package that rearranges it will eventually rearrange
     * something it did not write.
     */
    private function registerTailwindSource(): void
    {
        $css = base_path($this->option('css') ?: 'resources/css/app.css');

        if (! File::exists($css)) {
            $this->components->twoColumnDetail('Tailwind source', '<comment>no app.css found — add @source yourself</comment>');

            return;
        }

        $inhalt = File::get($css);
        $zeile = "@source '../../node_modules/@peppermint-digital/mailbox/dist';";

        if (str_contains($inhalt, '@peppermint-digital/mailbox/dist')) {
            $this->components->twoColumnDetail('Tailwind source', '<fg=gray>already there</>');

            return;
        }

        File::put($css, rtrim($inhalt)."\n\n".<<<CSS
            /* Without this the package's own classes are silently dropped: Tailwind does not scan node_modules. */
            {$zeile}

            CSS);

        $this->components->twoColumnDetail('Tailwind source', '<info>added</info>');
    }

    private function installJavaScript(): void
    {
        if (! File::exists(base_path('package.json'))) {
            $this->components->twoColumnDetail('JavaScript', '<fg=gray>no package.json — skipped</>');

            return;
        }

        $paket = json_decode(File::get(base_path('package.json')), true);

        if (isset($paket['dependencies']['@peppermint-digital/mailbox'])) {
            $this->components->twoColumnDetail('@peppermint-digital/mailbox', '<fg=gray>already installed</>');

            return;
        }

        $this->components->twoColumnDetail('@peppermint-digital/mailbox', '<comment>installing…</comment>');

        $ergebnis = Process::path(base_path())->timeout(300)->run('npm install '.self::NPM_PACKAGE);

        if ($ergebnis->failed()) {
            // npm 12 blocks git dependencies unless `allow-git` permits them.
            // Saying so beats a wall of npm output.
            $this->components->error('npm install failed. With npm 12, git dependencies need `allow-git=root` in .npmrc.');
            $this->line($ergebnis->errorOutput());

            return;
        }

        $this->components->twoColumnDetail('@peppermint-digital/mailbox', '<info>installed</info>');
    }
}
