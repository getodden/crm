<?php

declare(strict_types=1);

namespace Odden\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Odden\Core\Actions\FindDuplicateCompaniesAction;
use Odden\Core\Actions\FindDuplicateContactsAction;
use Odden\Core\Actions\MergeCompaniesAction;
use Odden\Core\Actions\MergeContactsAction;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Pages\Concerns\AuthorizesPageAccess;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Support\OddenAuthorization;
use UnitEnum;

/**
 * @property-read array<int, array{match_field: string, match_value: string, contacts: Collection<int, Contact>}> $duplicateContacts
 * @property-read array<int, array{match_field: string, match_value: string, companies: Collection<int, Company>}> $duplicateCompanies
 * @property-read int $totalContactsCount
 * @property-read int $totalCompaniesCount
 * @property-read float $dataCleanlinessScore
 */
class DataQuality extends Page
{
    use AuthorizesPageAccess;

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static ?string $navigationLabel = 'Data quality';

    protected static ?string $title = 'Data quality';

    protected string $view = 'odden-filament::pages.data-quality';

    public string $activeTab = 'contacts';

    /**
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    protected static function getAuthorizationResources(): array
    {
        return [
            ContactResource::class,
            CompanyResource::class,
        ];
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /**
     * @return array<int, array{match_field: string, match_value: string, contacts: Collection<int, Contact>}>
     */
    public function getDuplicateContactsProperty(): array
    {
        return app(FindDuplicateContactsAction::class)->execute(ContactResource::getEloquentQuery());
    }

    /**
     * @return array<int, array{match_field: string, match_value: string, companies: Collection<int, Company>}>
     */
    public function getDuplicateCompaniesProperty(): array
    {
        return app(FindDuplicateCompaniesAction::class)->execute(CompanyResource::getEloquentQuery());
    }

    public function getTotalContactsCountProperty(): int
    {
        return OddenAuthorization::scoped(Contact::class)->count();
    }

    public function getTotalCompaniesCountProperty(): int
    {
        return OddenAuthorization::scoped(Company::class)->count();
    }

    public function getDataCleanlinessScoreProperty(): float
    {
        $totalRecords = $this->totalContactsCount + $this->totalCompaniesCount;
        if ($totalRecords === 0) {
            return 100.0;
        }

        $duplicateContactsCount = count($this->duplicateContacts);
        $duplicateCompaniesCount = count($this->duplicateCompanies);
        $totalDupes = $duplicateContactsCount + $duplicateCompaniesCount;

        $score = 100 - (($totalDupes / $totalRecords) * 100);

        return round(max(0.0, min(100.0, $score)), 1);
    }

    /**
     * Merge the secondary contact into the primary. Requires `update` on the primary and `delete` on the secondary.
     */
    public function mergeContacts(int $primaryId, int $secondaryId): void
    {
        abort_if($primaryId === $secondaryId, 422);

        $primary = OddenAuthorization::findAndAuthorize(ContactResource::class, Contact::class, $primaryId, 'update');
        $secondary = OddenAuthorization::findAndAuthorize(ContactResource::class, Contact::class, $secondaryId, 'delete');

        app(MergeContactsAction::class)->execute($primary, $secondary);

        Notification::make()
            ->title('Contacts Successfully Merged')
            ->body("Merged duplicate {$secondary->email} into {$primary->email}. All history and associations preserved.")
            ->success()
            ->send();
    }

    /**
     * Merge the secondary company into the primary. Requires `update` on the primary and `delete` on the secondary.
     */
    public function mergeCompanies(int $primaryId, int $secondaryId): void
    {
        abort_if($primaryId === $secondaryId, 422);

        $primary = OddenAuthorization::findAndAuthorize(CompanyResource::class, Company::class, $primaryId, 'update');
        $secondary = OddenAuthorization::findAndAuthorize(CompanyResource::class, Company::class, $secondaryId, 'delete');

        app(MergeCompaniesAction::class)->execute($primary, $secondary);

        Notification::make()
            ->title('Companies Successfully Merged')
            ->body("Merged duplicate {$secondary->name} into {$primary->name}. All contacts and tickets preserved.")
            ->success()
            ->send();
    }
}
