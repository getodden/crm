<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Odden\Core\Models\Company;
use Odden\Core\Support\PropertyMerge;

class UpdateCompanyAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Company $company, array $attributes): Company
    {
        $company->fill(PropertyMerge::onto($company, $attributes))->save();

        return $company;
    }
}
