<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Company;
use Odden\Core\Support\PropertyMerge;
use Odden\Core\Support\TenantGuard;

class UpdateCompanyAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Company $company, array $attributes): Company
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($company);

        return DB::transaction(function () use ($company, $attributes): Company {
            $company->fill(PropertyMerge::onto($company, $attributes))->save();

            return $company;
        });
    }
}
