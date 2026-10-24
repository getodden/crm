<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Odden\Core\Contracts\KeyProvider;
use Odden\Core\Encryption\DecryptionException;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\Deal;

/*
 * Step 7 of PLAN-encryption-at-rest.md, the backup restore test in miniature: a database dump (what a backup holds) carries no
 * readable customer text, and a copy restored from it cannot be read without the workspace key. Production's version of this test
 * is in docs/encryption-restore-test.md; this one proves the property on every database the suite runs on.
 */

function backupOf(array $tables): array
{
    return collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()])->all();
}

function restore(array $backup): void
{
    foreach ($backup as $table => $rows) {
        DB::table($table)->delete();

        foreach ($rows as $row) {
            DB::table($table)->insert($row);
        }
    }
}

it('puts no readable customer text in a backup, and a restored copy needs the key to be read', function (): void {
    $contact = Contact::query()->create(['first_name' => 'Quentin', 'last_name' => 'Marplethorpe', 'email' => 'quentin@marplethorpe.example']);
    $deal = Deal::factory()->create(['name' => 'Marplethorpe harbour refit']);

    $tables = [(new Contact)->getTable(), (new Deal)->getTable()];
    $backup = backupOf($tables);

    expect(json_encode($backup))
        ->not->toContain('Marplethorpe')
        ->not->toContain('Quentin')
        ->not->toContain('harbour');

    // Restore into emptied tables, as into a fresh database.
    restore($backup);

    // With the key the restore reads back.
    expect(Contact::query()->findOrFail($contact->id)->email)->toBe('quentin@marplethorpe.example')
        ->and(Deal::query()->findOrFail($deal->id)->name)->toBe('Marplethorpe harbour refit');

    // Without it (a different secret, as a restore on a host that has not the key) every read fails and nothing leaks.
    config(['odden-core.encryption.key' => base64_encode(random_bytes(32))]);
    app()->forgetInstance(KeyProvider::class);
    app()->forgetInstance(FieldEncrypter::class);

    expect(fn () => Contact::query()->findOrFail($contact->id)->email)->toThrow(DecryptionException::class)
        ->and(fn () => Deal::query()->findOrFail($deal->id)->name)->toThrow(DecryptionException::class);
});
