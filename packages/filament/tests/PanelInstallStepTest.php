<?php

declare(strict_types=1);

it('warns on install that the plugin is not on a panel and that nobody can sign in', function (): void {
    $this->artisan('odden:install', ['--skip-migrate' => true, '--no-schedule' => true, '--no-demo' => true])
        ->expectsOutputToContain('There is no user to sign in with yet')
        ->assertSuccessful();
});
