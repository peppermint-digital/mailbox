<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * One command instead of a four-step README (#5488).
 *
 * The step people miss is the Tailwind source line, because missing it does
 * not fail: the page builds, the component appears, it just sits wrong. So
 * that is the step these tests watch hardest.
 */
beforeEach(function () {
    Process::fake();

    $this->css = base_path('resources/css/app.css');
    File::ensureDirectoryExists(dirname($this->css));
    File::put($this->css, "@import 'tailwindcss';\n");
});

afterEach(function () {
    File::delete($this->css);
    File::delete(config_path('mailbox.php'));
});

it('teaches Tailwind to look inside the package', function () {
    $this->artisan('mailbox:install', ['--no-npm' => true])->assertSuccessful();

    expect(File::get($this->css))->toContain("@source '../../node_modules/@peppermint-digital/mailbox/dist';")
        // The original content stays — the file belongs to the application.
        ->toContain("@import 'tailwindcss';");
});

it('can be run twice without doubling anything', function () {
    // An install command that is not idempotent is one people are afraid to
    // run — and then they run the four steps by hand again.
    $this->artisan('mailbox:install', ['--no-npm' => true]);
    $this->artisan('mailbox:install', ['--no-npm' => true]);

    expect(substr_count(File::get($this->css), '@peppermint-digital/mailbox/dist'))->toBe(1);
});

it('says so instead of failing when there is no stylesheet', function () {
    File::delete($this->css);

    $this->artisan('mailbox:install', ['--no-npm' => true])->assertSuccessful();
});

it('publishes the config', function () {
    $this->artisan('mailbox:install', ['--no-npm' => true]);

    expect(File::exists(config_path('mailbox.php')))->toBeTrue();
});

it('installs the JavaScript half', function () {
    File::put(base_path('package.json'), json_encode(['dependencies' => []]));

    $this->artisan('mailbox:install')->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, 'npm install github:peppermint-digital/mailbox'));

    File::delete(base_path('package.json'));
});

it('leaves an already installed JavaScript half alone', function () {
    File::put(base_path('package.json'), json_encode([
        'dependencies' => ['@peppermint-digital/mailbox' => 'github:peppermint-digital/mailbox#v0.16.0'],
    ]));

    $this->artisan('mailbox:install')->assertSuccessful();

    Process::assertNothingRan();

    File::delete(base_path('package.json'));
});
