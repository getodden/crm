<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Panel;
use Odden\Filament\OddenPlugin;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Resources\ContactResource;
use Tests\TestCase;

class PackageIndependenceArchitectureTest extends TestCase
{
    public function test_core_package_has_no_cross_package_dependencies(): void
    {
        $coreFiles = $this->getPhpFiles(base_path('packages/core/src'));

        foreach ($coreFiles as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('use Odden\\Sales', $contents, "Core file [{$file}] must not depend on Sales");
            $this->assertStringNotContainsString('use Odden\\Service', $contents, "Core file [{$file}] must not depend on Service");
            $this->assertStringNotContainsString('use Odden\\Marketing', $contents, "Core file [{$file}] must not depend on Marketing");
            $this->assertStringNotContainsString('use Odden\\Filament', $contents, "Core file [{$file}] must not depend on Filament");
        }
    }

    public function test_sales_package_has_no_dependencies_on_service_or_marketing_or_filament(): void
    {
        $salesFiles = $this->getPhpFiles(base_path('packages/sales/src'));

        foreach ($salesFiles as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('use Odden\\Service', $contents, "Sales file [{$file}] must not depend on Service");
            $this->assertStringNotContainsString('use Odden\\Marketing', $contents, "Sales file [{$file}] must not depend on Marketing");
            $this->assertStringNotContainsString('use Odden\\Filament', $contents, "Sales file [{$file}] must not depend on Filament");
        }
    }

    public function test_filament_package_does_not_depend_on_the_paid_add_ons(): void
    {
        // Marketing and Service are separate, paid packages. Their screens plug in through Odden\Filament\Support\Modules,
        // so nothing in the open admin package may import them.
        foreach ($this->getPhpFiles(base_path('packages/filament/src')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/^use Odden\\\\(Marketing|Service)\\\\/m', (string) file_get_contents($file), "Filament file [{$file}] must not import the paid add-ons");
        }
    }

    public function test_filament_plugin_conditionally_mounts_resources_safely(): void
    {
        $panel = new Panel;
        $panel->id('admin');

        $plugin = new OddenPlugin;
        $plugin->register($panel);

        $resources = $panel->getResources();

        // Core resources are always present
        $this->assertContains(ContactResource::class, $resources);
        $this->assertContains(CompanyResource::class, $resources);

        // Contact and Company relation managers are arrays
        $contactRelations = ContactResource::getRelations();
        $this->assertIsArray($contactRelations);
        $this->assertNotEmpty($contactRelations);

        $companyRelations = CompanyResource::getRelations();
        $this->assertIsArray($companyRelations);
        $this->assertNotEmpty($companyRelations);
    }

    /**
     * @return list<string>
     */
    protected function getPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
