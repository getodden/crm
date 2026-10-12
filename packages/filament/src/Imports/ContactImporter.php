<?php

declare(strict_types=1);

namespace Odden\Filament\Imports;

use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Odden\Core\Actions\AutoAssociateContactCompanyAction;
use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Events\ContactCreated;
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;
use Odden\Core\Models\Contact;
use Odden\Core\Support\ContactLookup;
use Odden\Core\Support\EmailValidator;

/**
 * Imports contacts from a CSV file.
 *
 * Every address is checked the way a contact created by hand is checked. An address that can
 * never be delivered to (malformed, or a domain that accepts no mail) is not imported and
 * lands in the failed-rows download with the reason. Risky addresses (typo, disposable,
 * shared mailbox) are imported and carry their status, so nothing is silently dropped and
 * sending can still hold them back.
 */
class ContactImporter extends Importer
{
    protected static ?string $model = Contact::class;

    // The failed-rows file repeats the uploaded values, and people open it in a spreadsheet.
    protected static bool $shouldPreventFormulaInjection = true;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('email')
                ->label('Email')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255'])
                ->castStateUsing(fn (?string $state): ?string => blank($state) ? null : ContactLookup::normalizeEmail($state))
                ->guess(['email', 'e-mail', 'email address', 'e-mail address', 'mail'])
                ->example('grace@navy.mil'),
            ImportColumn::make('first_name')
                ->ignoreBlankState()
                ->label('First name')
                ->rules(['nullable', 'max:255'])
                ->guess(['first name', 'firstname', 'given name'])
                ->example('Grace'),
            ImportColumn::make('last_name')
                ->ignoreBlankState()
                ->label('Last name')
                ->rules(['nullable', 'max:255'])
                ->guess(['last name', 'lastname', 'surname', 'family name'])
                ->example('Hopper'),
            ImportColumn::make('job_title')
                ->ignoreBlankState()
                ->label('Job title')
                ->rules(['nullable', 'max:255'])
                ->guess(['title', 'job title', 'position'])
                ->example('Rear Admiral'),
            ImportColumn::make('phone')
                ->ignoreBlankState()
                ->label('Phone')
                ->rules(['nullable', 'max:50'])
                ->guess(['phone', 'phone number', 'mobile', 'telephone'])
                ->example('+1 555 0100'),
            ImportColumn::make('linkedin_url')
                ->ignoreBlankState()
                ->label('LinkedIn URL')
                ->rules(['nullable', 'url', 'max:255'])
                ->guess(['linkedin', 'linkedin url', 'linkedin profile'])
                ->example('https://linkedin.com/in/grace'),
            ImportColumn::make('timezone')
                ->ignoreBlankState()
                ->label('Timezone')
                ->rules(['nullable', 'timezone'])
                ->example('America/New_York'),
            ImportColumn::make('lifecycle_stage')
                ->ignoreBlankState()
                ->label('Lifecycle stage')
                ->castStateUsing(fn (?string $state): ?string => self::enumValue(LifecycleStage::class, $state))
                ->rules(['nullable', Rule::enum(LifecycleStage::class)])
                ->guess(['lifecycle stage', 'lifecycle', 'stage'])
                ->example('lead'),
            ImportColumn::make('lead_status')
                ->ignoreBlankState()
                ->label('Lead status')
                ->castStateUsing(fn (?string $state): ?string => self::enumValue(LeadStatus::class, $state))
                ->rules(['nullable', Rule::enum(LeadStatus::class)])
                ->guess(['lead status', 'status'])
                ->example('new'),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('existing')
                ->label('If a contact with the same email already exists')
                ->options([
                    'skip' => 'Skip it and list the row as failed',
                    'update' => 'Update it with the values in this file',
                ])
                ->default('skip')
                ->required(),
        ];
    }

    public function resolveRecord(): ?Contact
    {
        $email = ContactLookup::normalizeEmail((string) ($this->data['email'] ?? ''));

        $existing = $email === '' ? null : ContactLookup::findByEmail($email);

        if ($existing === null) {
            return new Contact;
        }

        if (($this->options['existing'] ?? 'skip') !== 'update') {
            throw new RowImportFailedException('A contact with this email address already exists.');
        }

        return $existing;
    }

    /**
     * Check the address and record the result, or refuse the row when it can never be delivered to.
     */
    protected function beforeSave(): void
    {
        // A bounce is evidence from a real delivery attempt; re-importing the address must not check it away.
        if (! config('odden-core.email_validation.enabled', true) || ! $this->record instanceof Contact || $this->record->hasBounced()) {
            return;
        }

        $check = app(EmailValidator::class)->check(
            (string) $this->record->email,
            (bool) config('odden-core.email_validation.check_dns', true),
        );

        if ($check->status === EmailStatus::Invalid) {
            throw new RowImportFailedException($check->reason?->label() ?? 'Not a valid email address.');
        }

        $this->record->forceFill([
            'email_status' => $check->status,
            'email_status_reason' => $check->reason,
            'email_checked_at' => now(),
        ]);
    }

    /**
     * A changed lifecycle stage on an existing contact goes through TransitionLifecycleStageAction, which checks
     * the lifecycle rules, records the transition and fires LifecycleStageChanged. A refused transition fails
     * the row, and nothing in it is saved.
     */
    protected function beforeUpdate(): void
    {
        if (! $this->record instanceof Contact || ! $this->record->isDirty('lifecycle_stage')) {
            return;
        }

        $raw = $this->record->getAttribute('lifecycle_stage');
        $toStage = $raw instanceof LifecycleStage ? $raw : LifecycleStage::from((string) $raw);

        // The action reads the current stage from the record, then saves it with the row's other changes.
        $this->record->setAttribute('lifecycle_stage', $this->record->getOriginal('lifecycle_stage'));

        $userId = $this->import->getAttribute('user_id');

        try {
            app(TransitionLifecycleStageAction::class)->execute($this->record, $toStage, 'import', is_numeric($userId) ? (int) $userId : null);
        } catch (InvalidLifecycleStageTransitionException $e) {
            throw new RowImportFailedException($e->getMessage());
        }
    }

    /**
     * What CreateContactAction does after creating a contact: announce it, and link it to its company when the
     * installation has auto-association turned on. (The address check already ran in beforeSave().)
     */
    protected function afterCreate(): void
    {
        if (! $this->record instanceof Contact) {
            return;
        }

        event(new ContactCreated($this->record));

        if ((bool) config('odden-core.auto_associate_companies', false)) {
            app(AutoAssociateContactCompanyAction::class)->execute($this->record);
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your contact import has finished and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if (($failed = $import->getFailedRowsCount()) > 0) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed to import; download the failed rows to see why.';
        }

        return $body;
    }

    /**
     * Accept either the stored value or the label a person would see ("In Progress").
     *
     * @param  class-string<LifecycleStage|LeadStatus>  $enum
     */
    protected static function enumValue(string $enum, ?string $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        $needle = str((string) $state)->trim()->lower()->snake()->toString();

        foreach ($enum::cases() as $case) {
            if ($needle === $case->value || $needle === str($case->label())->lower()->snake()->toString()) {
                return $case->value;
            }
        }

        return (string) $state;
    }
}
