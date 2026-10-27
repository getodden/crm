<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Odden\Core\Encryption\ColumnConverter;
use Odden\Core\Encryption\EncryptedColumns;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\SearchIndex;
use Odden\Core\Models\ActionLogEntry;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\CustomObjectDefinition;
use Odden\Core\Models\CustomObjectRecord;
use Odden\Core\Models\ProposedAction;
use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Registry\ActionRegistry;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actions\BaseAction;
use Odden\Core\Registry\Actor;
use Odden\Core\Registry\Risk;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealProduct;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\QuoteItem;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;

/*
 * Step 7 of PLAN-encryption-at-rest.md: the copies of customer content that sat outside the Identity columns. Each is stored
 * encrypted with the workspace key, and every value below is a made-up marker that must never appear in a stored row.
 */

class SweepEchoAction extends BaseAction
{
    public static function name(): string
    {
        return 'test.sweep';
    }

    public static function description(): string
    {
        return 'Say what was asked.';
    }

    public static function rules(): array
    {
        return ['who' => ['required', 'string'], 'fail' => ['sometimes', 'boolean']];
    }

    public function risk(): Risk
    {
        return Risk::Outbound;
    }

    public function handle(Actor $actor, array $input): ActionResult
    {
        return ($input['fail'] ?? false) ? ActionResult::failed('Could not reach Quentin Marplethorpe') : ActionResult::completed(['ok' => true]);
    }
}

function sweepEncrypted(string $value): bool
{
    return app(FieldEncrypter::class)->isEncrypted($value);
}

/**
 * The first row of a table as text with every envelope (odden1.{key id}.{ciphertext}) taken out, so what is left is what the table holds
 * in plain text. Searching the ciphertext itself for a plain value can match by chance, because random base64 contains any short string.
 */
function sweepRow(string $table): string
{
    return sweepPlainText((array) DB::table($table)->first());
}

/**
 * @param  array<string, mixed>  $row
 */
function sweepPlainText(array $row): string
{
    return (string) preg_replace('/odden1\.[^.\s"]+\.[A-Za-z0-9_-]+/', '[encrypted]', implode(' ', array_map('strval', $row)));
}

it('stores the input and error of an action log entry encrypted', function (): void {
    app(ActionRegistry::class)->register(SweepEchoAction::class);

    app(ActionExecutor::class)->run('test.sweep', Actor::user(3), ['who' => 'Quentin Marplethorpe']);
    app(ActionExecutor::class)->run('test.sweep', Actor::user(3), ['who' => 'Quentin Marplethorpe', 'fail' => true]);

    foreach (DB::table('odden_action_log')->get() as $row) {
        expect(sweepEncrypted($row->input))->toBeTrue()->and($row->input)->not->toContain('Marplethorpe');
    }

    $failed = DB::table('odden_action_log')->where('status', 'failed')->first();
    expect($failed)->not->toBeNull();
    expect(sweepEncrypted($failed->error))->toBeTrue()->and($failed->error)->not->toContain('Quentin')
        ->and(ActionLogEntry::query()->where('status', 'failed')->sole()->error)->toBe('Could not reach Quentin Marplethorpe')
        ->and(ActionLogEntry::query()->orderBy('id')->first()->input['who'])->toBe('Quentin Marplethorpe');
});

it('stores a proposal with the workspace key, not the application key, and still runs it when approved', function (): void {
    app(ActionRegistry::class)->register(SweepEchoAction::class);

    app(ActionExecutor::class)->run('test.sweep', Actor::ai(3), ['who' => 'Quentin Marplethorpe']);

    $raw = DB::table('odden_proposed_actions')->value('input');
    expect(sweepEncrypted($raw))->toBeTrue()->and($raw)->not->toContain('Marplethorpe');

    $proposal = ProposedAction::query()->sole();
    expect($proposal->input['who'])->toBe('Quentin Marplethorpe')
        ->and(app(ActionExecutor::class)->approve($proposal, Actor::user(4))->ok())->toBeTrue();
});

it('moves a proposal that Laravel encrypted with the application key to the workspace key when existing data is converted', function (): void {
    app(ActionRegistry::class)->register(SweepEchoAction::class);
    app(ActionExecutor::class)->run('test.sweep', Actor::ai(3), ['who' => 'Quentin Marplethorpe']);

    // The way it was stored before: Laravel's `encrypted:array`.
    DB::table('odden_proposed_actions')->update(['input' => Crypt::encryptString(json_encode(['who' => 'Quentin Marplethorpe']))]);
    expect(sweepEncrypted((string) DB::table('odden_proposed_actions')->value('input')))->toBeFalse();

    app(EncryptedColumns::class)->convert(app(ColumnConverter::class));

    $raw = (string) DB::table('odden_proposed_actions')->value('input');
    expect(sweepEncrypted($raw))->toBeTrue()->and(ProposedAction::query()->sole()->input)->toBe(['who' => 'Quentin Marplethorpe']);
});

it('stores the rules of a list encrypted and still evaluates them', function (): void {
    $list = CrmList::query()->create([
        'name' => 'Berlin leads', 'entity_type' => 'contact', 'type' => 'active',
        'criteria' => [['field' => 'job_title', 'operator' => 'equals', 'value' => 'Marplethorpe Holdings']],
    ]);

    $raw = (string) DB::table('odden_lists')->where('id', $list->id)->value('criteria');
    expect(sweepEncrypted($raw))->toBeTrue()->and($raw)->not->toContain('Marplethorpe')
        ->and($list->fresh()->criteria[0]['value'])->toBe('Marplethorpe Holdings');
});

it('stores a custom object record encrypted whole, and finds it by property in PHP', function (): void {
    $definition = CustomObjectDefinition::query()->create([
        'name' => 'asset', 'singular_label' => 'Asset', 'plural_label' => 'Assets', 'primary_display_property' => 'serial',
    ]);
    $one = CustomObjectRecord::query()->create(['definition_id' => $definition->id, 'name' => 'Marplethorpe laptop', 'properties' => ['serial' => 'MQ-1', 'status' => 'repair']]);
    CustomObjectRecord::query()->create(['definition_id' => $definition->id, 'name' => 'Other', 'properties' => ['serial' => 'MQ-2', 'status' => 'ok']]);

    $row = (array) DB::table('odden_custom_object_records')->where('id', $one->id)->first();
    expect(sweepEncrypted($row['name']))->toBeTrue()->and(sweepEncrypted($row['properties']))->toBeTrue()
        ->and(sweepPlainText($row))->not->toContain('Marplethorpe')->not->toContain('MQ-1')
        ->and(CustomObjectRecord::whereProperty('status', 'repair')->pluck('id')->all())->toBe([$one->id])
        ->and($one->fresh()->name)->toBe('Marplethorpe laptop');
});

it('stores deal names encrypted and finds a deal by the start of any word of its name', function (): void {
    $deal = Deal::factory()->create(['name' => 'Marplethorpe Holdings renewal']);
    Deal::factory()->create(['name' => 'Something else']);

    $stored = (string) DB::table('odden_deals')->where('id', $deal->id)->value('name');
    expect(sweepEncrypted($stored))->toBeTrue()->and($stored)->not->toContain('Marplethorpe')
        ->and(Deal::query()->whereNameStartsWith('marple')->pluck('id')->all())->toBe([$deal->id])
        ->and(Deal::query()->whereNameStartsWith('renew hold')->pluck('id')->all())->toBe([$deal->id])
        ->and(Deal::query()->whereNameStartsWith('zebra')->count())->toBe(0);

    // Renaming moves the index with it, and deleting for good removes it.
    $deal->update(['name' => 'Quillfeather account']);
    expect(Deal::query()->whereNameStartsWith('marple')->count())->toBe(0)
        ->and(Deal::query()->whereNameStartsWith('quillf')->pluck('id')->all())->toBe([$deal->id]);

    $deal->forceDelete();
    expect(DB::table(app(SearchIndex::class)->table())->where('field', 'like', 'deals.%')->where('record_id', $deal->id)->count())->toBe(0);
});

it('stores who signed a quote and who booked a meeting encrypted', function (): void {
    $quote = Quote::factory()->create(['signed_by_name' => 'Quentin Marplethorpe', 'signed_by_email' => 'quentin@marplethorpe.example']);
    $host = User::factory()->create();
    $link = SalesMeetingLink::query()->create(['user_id' => $host->id, 'slug' => 'intro', 'title' => 'Intro', 'duration_minutes' => 30]);
    $booking = SalesMeetingBooking::query()->create([
        'uid' => (string) Str::uuid(), 'meeting_link_id' => $link->id, 'user_id' => $host->id, 'invitee_name' => 'Quentin Marplethorpe', 'invitee_email' => 'quentin@marplethorpe.example',
        'starts_at' => now(), 'ends_at' => now()->addHour(), 'timezone' => 'UTC',
    ]);

    expect(sweepRow('odden_quotes'))->not->toContain('Marplethorpe')->not->toContain('marplethorpe')
        ->and(sweepRow('odden_sales_meeting_bookings'))->not->toContain('Marplethorpe')->not->toContain('marplethorpe')
        ->and($quote->fresh()->signed_by_email)->toBe('quentin@marplethorpe.example')
        ->and($booking->fresh()->invitee_name)->toBe('Quentin Marplethorpe');
});

/*
 * The follow-up round: quote and product text, the lost reason, rule JSON.
 */

it('stores quote titles, quote lines and deal products encrypted, finds quotes by title and still totals them', function (): void {
    $deal = Deal::factory()->create(['name' => 'Marplethorpe Holdings renewal']);
    $quote = Quote::factory()->create(['deal_id' => $deal->id, 'title' => 'Quote for Marplethorpe Holdings']);
    Quote::factory()->create(['deal_id' => $deal->id, 'title' => 'Something else']);
    $item = QuoteItem::query()->create(['quote_id' => $quote->id, 'name' => 'Marplethorpe rollout', 'description' => 'For Quentin', 'unit_price' => 10, 'quantity' => 3]);
    $product = DealProduct::query()->create(['deal_id' => $deal->id, 'name' => 'Marplethorpe seats', 'description' => 'For Quentin', 'unit_price' => 5, 'quantity' => 2]);

    foreach (['odden_quotes', 'odden_quote_items', 'odden_deal_products'] as $table) {
        expect(sweepRow($table))->not->toContain('Marplethorpe')->not->toContain('Quentin');
    }

    expect(sweepEncrypted((string) DB::table('odden_quotes')->where('id', $quote->id)->value('title')))->toBeTrue()
        ->and($item->fresh()->name)->toBe('Marplethorpe rollout')
        ->and($product->fresh()->description)->toBe('For Quentin')
        ->and((float) $quote->fresh()->subtotal)->toBe(30.0)
        ->and(Quote::query()->whereTitleStartsWith('marple')->pluck('id')->all())->toBe([$quote->id])
        ->and(Quote::query()->whereTitleStartsWith('zebra')->count())->toBe(0);

    $quote->update(['title' => 'Renewal for Quillfeather']);
    expect(Quote::query()->whereTitleStartsWith('marple')->count())->toBe(0)
        ->and(Quote::query()->whereTitleStartsWith('quillf')->pluck('id')->all())->toBe([$quote->id]);

    $quote->forceDelete();
    expect(DB::table(app(SearchIndex::class)->table())->where('field', 'like', 'quotes.%')->where('record_id', $quote->id)->count())->toBe(0);
});

it('stores the lost reason encrypted and still reports the reasons and filters by them', function (): void {
    $lost = Deal::factory()->count(2)->lost('Chose Marplethorpe Holdings')->create();
    Deal::factory()->lost('price')->create();

    foreach (DB::table('odden_deals')->pluck('lost_reason')->filter() as $stored) {
        expect(sweepEncrypted($stored))->toBeTrue()->and($stored)->not->toContain('Marplethorpe');
    }

    $reasons = app(DecryptedFilter::class)->tally(Deal::query()->where('status', 'lost'), 'lost_reason');
    expect($reasons)->toBe(['Chose Marplethorpe Holdings' => 2, 'price' => 1])
        ->and($lost->first()->fresh()->lost_reason)->toBe('Chose Marplethorpe Holdings');
});

it('stores routing rules and stage automations encrypted and still applies them', function (): void {
    $rule = LeadRoutingRule::query()->create([
        'name' => 'Berlin', 'strategy' => LeadRoutingStrategy::Territory, 'criteria' => ['city' => 'Marplethorpe-on-Sea'], 'assigned_user_ids' => [1],
    ]);

    $raw = (string) DB::table('odden_sales_lead_routing_rules')->where('id', $rule->id)->value('criteria');
    expect(sweepEncrypted($raw))->toBeTrue()->and($raw)->not->toContain('Marplethorpe')
        ->and($rule->fresh()->criteria)->toBe(['city' => 'Marplethorpe-on-Sea']);
});

it('converts plain rows of the follow-up columns when existing data is converted, and a second run changes nothing', function (): void {
    $deal = Deal::factory()->lost('Chose Marplethorpe Holdings')->create();
    $quote = Quote::factory()->create(['deal_id' => $deal->id, 'title' => 'Quote for Marplethorpe']);

    // The way they were stored before.
    DB::table('odden_deals')->where('id', $deal->id)->update(['lost_reason' => 'Chose Marplethorpe Holdings']);
    DB::table('odden_quotes')->where('id', $quote->id)->update(['title' => 'Quote for Marplethorpe']);
    DB::table(app(SearchIndex::class)->table())->where('field', 'like', 'quotes.%')->delete();

    $first = app(EncryptedColumns::class)->convert(app(ColumnConverter::class));
    $second = app(EncryptedColumns::class)->convert(app(ColumnConverter::class));

    expect($first['deals.lost_reason'])->toBe(1)->and($first['quotes.title'])->toBe(1)
        ->and($second['deals.lost_reason'])->toBe(0)->and($second['quotes.title'])->toBe(0)
        ->and(sweepRow('odden_quotes'))->not->toContain('Marplethorpe')
        ->and($deal->fresh()->lost_reason)->toBe('Chose Marplethorpe Holdings')
        ->and(Quote::query()->whereTitleStartsWith('marple')->pluck('id')->all())->toBe([$quote->id]);
});
