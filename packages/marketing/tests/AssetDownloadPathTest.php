<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Marketing\Models\MarketingAsset;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetDownloadPathTest extends TestCase
{
    use RefreshDatabase;

    private string $inside;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inside = storage_path('app/assets-test/report.txt');
        $this->outside = storage_path('outside-secret.txt');
        @mkdir(dirname($this->inside), 0777, true);
        file_put_contents($this->inside, 'the report');
        file_put_contents($this->outside, 'APP_KEY=secret');
    }

    protected function tearDown(): void
    {
        @unlink($this->inside);
        @rmdir(dirname($this->inside));
        @unlink($this->outside);

        parent::tearDown();
    }

    private function asset(string $path): MarketingAsset
    {
        return MarketingAsset::create(['name' => 'Report', 'slug' => 'report', 'file_path' => $path]);
    }

    public function test_a_file_inside_storage_app_is_served(): void
    {
        $this->asset('assets-test/report.txt');

        $response = $this->get(route('odden.marketing.assets.download', ['slug' => 'report']));

        $response->assertOk();
        $this->assertSame('the report', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_a_path_that_leaves_storage_app_is_never_served(): void
    {
        foreach (['../outside-secret.txt', 'assets-test/../../outside-secret.txt', $this->outside, "assets-test/report.txt\0.png"] as $path) {
            MarketingAsset::query()->delete();
            $asset = $this->asset($path);

            $this->assertNull($asset->downloadPath(), $path);
            $response = $this->get(route('odden.marketing.assets.download', ['slug' => 'report']));
            $this->assertFalse($response->baseResponse instanceof BinaryFileResponse, $path);
        }
    }

    public function test_a_symlink_inside_storage_that_points_outside_is_refused(): void
    {
        $link = storage_path('app/assets-test/link.txt');
        @symlink($this->outside, $link);

        try {
            $this->assertNull($this->asset('assets-test/link.txt')->downloadPath());
        } finally {
            @unlink($link);
        }
    }
}
