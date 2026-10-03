<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Odden\Filament\Resources\MarketingTemplateResource\Pages\CreateMarketingTemplate;
use Odden\Filament\Resources\MarketingTemplateResource\Pages\EditMarketingTemplate;
use Odden\Filament\Resources\MarketingTemplateResource\Pages\ListMarketingTemplates;
use Odden\Filament\Support\OddenAuthorization;
use Odden\MailBuilder\Filament\Components\EmailSlotBuilder;
use Odden\MailBuilder\MailBuilder;
use Odden\MailBuilder\Presets\PresetRegistry;
use Odden\Marketing\Actions\EvaluateTemplateAbTestsAction;
use Odden\Marketing\Actions\SuggestSubjectLinesAction;
use Odden\Marketing\Models\MarketingSavedBlock;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Services\EmailBlockRenderer;
use UnitEnum;

class MarketingTemplateResource extends Resource
{
    protected static ?string $model = MarketingTemplate::class;

    protected static ?string $modelLabel = 'Email Template';

    protected static ?string $pluralModelLabel = 'Email Templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static UnitEnum|string|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Email Template Workspace')
                    ->tabs([
                        Tab::make('Visual Slot Designer')
                            ->icon(Heroicon::Squares2x2)
                            ->schema([
                                Section::make('Visual Modular Slots')
                                    ->description('Build modular, responsive, Outlook-bulletproof email layouts with visual slots.')
                                    ->headerActions([
                                        Action::make('applyPresetInForm')
                                            ->label('Apply Layout Preset')
                                            ->icon(Heroicon::Squares2x2)
                                            ->color('gray')
                                            ->modalHeading('Apply Modular Email Template Preset')
                                            ->modalDescription('Populate this template with an industry-tested, responsive modular email block layout.')
                                            ->schema([
                                                Select::make('preset')
                                                    ->label('Select Preset')
                                                    ->options(function (): array {
                                                        if (class_exists(PresetRegistry::class)) {
                                                            return app(PresetRegistry::class)->toSelectOptions();
                                                        }

                                                        return [
                                                            'product_announcement' => 'Product Launch & Feature Announcement',
                                                            'newsletter_digest' => 'Standard Editorial Digest & Newsletter',
                                                        ];
                                                    })
                                                    ->default('product_announcement')
                                                    ->required(),
                                            ])
                                            ->action(function (array $data, $set): void {
                                                $presetKey = (string) $data['preset'];
                                                if (class_exists(PresetRegistry::class) && app(PresetRegistry::class)->has($presetKey)) {
                                                    $preset = app(PresetRegistry::class)->get($presetKey);
                                                    $document = $preset->document();
                                                    $slots = array_map(fn ($slot): array => [
                                                        'type' => $slot->type->value,
                                                        'data' => $slot->data,
                                                    ], $document->slots);

                                                    $set('slots', $slots);
                                                    if (! empty($document->subject)) {
                                                        $set('subject', $document->subject);
                                                    }
                                                    if (! empty($document->previewText)) {
                                                        $set('preview_text', $document->previewText);
                                                    }

                                                    Notification::make()
                                                        ->title('Preset Applied')
                                                        ->body("Applied [{$preset->label()}] with ".count($slots).' blocks.')
                                                        ->success()
                                                        ->send();
                                                }
                                            }),
                                        Action::make('insertSavedBlockInForm')
                                            ->label('Insert Reusable Snippet')
                                            ->icon(Heroicon::BookmarkSquare)
                                            ->color('gray')
                                            ->modalHeading('Insert Reusable Snippet')
                                            ->modalDescription('Append a pre-configured global brand snippet into this email layout.')
                                            ->schema([
                                                Select::make('saved_block_id')
                                                    ->label('Select Snippet')
                                                    ->options(fn (): array => MarketingSavedBlock::query()->pluck('name', 'id')->toArray())
                                                    ->required(),
                                            ])
                                            ->action(function (array $data, $get, $set): void {
                                                /** @var MarketingSavedBlock|null $block */
                                                $block = MarketingSavedBlock::find($data['saved_block_id']);
                                                if ($block) {
                                                    $currentSlots = is_array($get('slots')) ? $get('slots') : [];
                                                    $currentSlots[] = [
                                                        'type' => $block->slot_type,
                                                        'data' => $block->slot_data,
                                                    ];
                                                    $set('slots', $currentSlots);

                                                    Notification::make()
                                                        ->title('Snippet Inserted')
                                                        ->body("Appended [{$block->name}] to visual slots.")
                                                        ->success()
                                                        ->send();
                                                }
                                            }),
                                    ])
                                    ->schema(function (): array {
                                        if (class_exists(EmailSlotBuilder::class)) {
                                            return [
                                                EmailSlotBuilder::make('slots'),
                                            ];
                                        }

                                        return [];
                                    }),
                            ]),

                        Tab::make('Live Interactive Preview')
                            ->icon(Heroicon::Eye)
                            ->schema([
                                SchemaView::make('odden-marketing::template-inline-preview'),
                            ]),

                        Tab::make('Pre-Flight Deliverability Audit')
                            ->icon(Heroicon::ShieldCheck)
                            ->schema([
                                SchemaView::make('odden-marketing::template-preflight-audit'),
                            ]),

                        Tab::make('Personalization & Merge Tags')
                            ->icon(Heroicon::Variable)
                            ->schema([
                                SchemaView::make('mail-builder::merge-tags-reference'),
                            ]),

                        Tab::make('Brand & Global Styling')
                            ->icon(Heroicon::PaintBrush)
                            ->schema([
                                Section::make('Global Theme & Color Tokens')
                                    ->description('These styling tokens automatically tint buttons, cards, links, and layout geometry across all slots.')
                                    ->schema([
                                        ColorPicker::make('theme.primary_color')
                                            ->label('Brand Primary Color')
                                            ->default('#2563eb'),
                                        ColorPicker::make('theme.heading_color')
                                            ->label('Heading Text Color')
                                            ->default('#0f172a'),
                                        ColorPicker::make('theme.background_color')
                                            ->label('Outer Canvas Background')
                                            ->default('#f8fafc'),
                                        ColorPicker::make('theme.content_background_color')
                                            ->label('Email Card Background')
                                            ->default('#ffffff'),
                                        Select::make('theme.font_family')
                                            ->label('Typography Pairing')
                                            ->options([
                                                "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" => 'Modern System Sans (Apple / Segoe / Roboto)',
                                                "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif" => 'Inter Clean Grotesk',
                                                "Georgia, 'Times New Roman', serif" => 'Editorial Modern Serif (Georgia)',
                                                "'SF Mono', 'Fira Code', Menlo, Consolas, monospace" => 'Technical Monospace',
                                            ])
                                            ->default("-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"),
                                        Select::make('theme.border_radius')
                                            ->label('Card Corner Radius')
                                            ->options([
                                                '0px' => 'Sharp Modernist (0px)',
                                                '4px' => 'Subtle Rounded (4px)',
                                                '8px' => 'Classic Rounded (8px)',
                                                '16px' => 'Soft Curved (16px)',
                                            ])
                                            ->default('8px'),
                                        Select::make('theme.container_width')
                                            ->label('Max Container Width')
                                            ->options([
                                                560 => '560px (Compact Mobile-First)',
                                                600 => '600px (Industry Standard)',
                                                640 => '640px (Wide Editorial Canvas)',
                                            ])
                                            ->default(600),
                                    ])
                                    ->columns(2),
                            ]),

                        Tab::make('A/B Testing Experiments')
                            ->icon(Heroicon::Beaker)
                            ->schema([
                                Section::make('Subject Line & Copy Experimentation')
                                    ->description('Test alternative subject lines and inbox preview snippets to optimize open and click rates.')
                                    ->headerActions([
                                        Action::make('generateAiSubjectLines')
                                            ->label('Generate AI Variants')
                                            ->icon(Heroicon::Sparkles)
                                            ->color('primary')
                                            ->modalHeading('AI Subject Line & Copy Generator')
                                            ->modalDescription('Generate high-converting subject lines, inbox snippets, and Variant B experiments.')
                                            ->schema([
                                                TextInput::make('topic')
                                                    ->label('Campaign / Template Topic')
                                                    ->placeholder('e.g. Q4 RevOps Platform Launch')
                                                    ->required(),
                                                Select::make('tone')
                                                    ->label('Copywriting Tone')
                                                    ->options([
                                                        'engaging' => 'Engaging & Value-Driven (Default)',
                                                        'urgent' => 'Urgent & Scarcity (Final Hours / Last Chance)',
                                                        'curious' => 'Curiosity & Mystery (Sneak Peek / Did you know?)',
                                                        'friendly' => 'Warm & Friendly (Community / Relationship)',
                                                        'bold' => 'Bold & Disruptive (Rethink Everything)',
                                                    ])
                                                    ->default('engaging')
                                                    ->required(),
                                                TextInput::make('audience')
                                                    ->label('Target Audience (Optional)')
                                                    ->placeholder('e.g. VP of Sales or Product Leaders'),
                                            ])
                                            ->action(function (array $data, $set): void {
                                                $action = app(SuggestSubjectLinesAction::class);
                                                $result = $action->execute(
                                                    topic: (string) $data['topic'],
                                                    tone: (string) $data['tone'],
                                                    audience: ! empty($data['audience']) ? (string) $data['audience'] : null
                                                );

                                                if (! empty($result['suggestions'][0])) {
                                                    $set('subject', $result['suggestions'][0]);
                                                }
                                                if (! empty($result['variant_b'])) {
                                                    $set('subject_variant_b', $result['variant_b']);
                                                }
                                                if (! empty($result['preview_text'])) {
                                                    $set('preview_text_variant_b', $result['preview_text']);
                                                }

                                                Notification::make()
                                                    ->title('AI Variants Applied')
                                                    ->body('Generated and populated Variant A and Variant B subject lines.')
                                                    ->success()
                                                    ->send();
                                            }),
                                    ])
                                    ->schema([
                                        TextInput::make('subject_variant_b')
                                            ->label('Variant B Subject Line')
                                            ->placeholder('Alternative hook or question (e.g. Quick question about your pipeline, {{contact.first_name}}?)')
                                            ->hintAction(self::getMergeTagsHintAction())
                                            ->columnSpanFull(),
                                        TextInput::make('preview_text_variant_b')
                                            ->label('Variant B Preview Header Text')
                                            ->placeholder('Alternative inbox preview snippet')
                                            ->hintAction(self::getMergeTagsHintAction())
                                            ->columnSpanFull(),
                                        Select::make('ab_split_percentage')
                                            ->label('Traffic Distribution')
                                            ->options([
                                                50 => '50% Variant A / 50% Variant B (Balanced Split)',
                                                20 => '20% Sample Test (10% A / 10% B, Winner dispatched to 80%)',
                                                10 => '10% Sample Test (5% A / 5% B, Winner dispatched to 90%)',
                                            ])
                                            ->default(50),
                                    ])
                                    ->columns(2),
                            ]),

                        Tab::make('Settings & Code')
                            ->icon(Heroicon::Cog6Tooth)
                            ->schema([
                                Section::make('Template Details')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Template Name')
                                            ->placeholder('e.g. Monthly Product Digest')
                                            ->required()
                                            ->maxLength(255),
                                        Select::make('category')
                                            ->label('Category')
                                            ->options([
                                                'newsletter' => 'Newsletter',
                                                'product_update' => 'Product Update',
                                                'promotional' => 'Promotional',
                                                'onboarding' => 'Onboarding / Welcome',
                                                'event' => 'Event / Webinar',
                                                'general' => 'General',
                                            ])
                                            ->default('newsletter')
                                            ->required(),
                                        TextInput::make('subject')
                                            ->label('Default Subject Line')
                                            ->placeholder('e.g. {{contact.first_name}}, check out our latest release!')
                                            ->hintAction(self::getMergeTagsHintAction())
                                            ->required()
                                            ->columnSpanFull(),
                                        TextInput::make('preview_text')
                                            ->label('Preview Header Text')
                                            ->placeholder('Brief inbox preview snippet')
                                            ->hintAction(self::getMergeTagsHintAction())
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),

                                Section::make('Raw HTML / Fallback Code')
                                    ->description('Direct HTML output (auto-generated from visual blocks above or edited manually).')
                                    ->collapsible()
                                    ->collapsed()
                                    ->schema([
                                        Textarea::make('body_html')
                                            ->label('HTML Email Body')
                                            ->rows(12)
                                            ->helperText('Supported merge tags: {{contact.first_name}}, {{contact.last_name}}, {{contact.email}}, {{company.name}}, {{unsubscribe_url}}')
                                            ->hintAction(self::getMergeTagsHintAction())
                                            ->required(fn ($get): bool => empty($get('slots')))
                                            ->columnSpanFull(),
                                        Textarea::make('body_text')
                                            ->label('Plain Text Fallback')
                                            ->rows(5)
                                            ->hintAction(self::getMergeTagsHintAction())
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Analytics & Revisions')
                            ->icon(Heroicon::ChartBar)
                            ->schema([
                                SchemaView::make('odden-marketing::template-analytics-history'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Template Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->limit(45)
                    ->searchable(),
                TextColumn::make('campaigns_count')
                    ->counts('campaigns')
                    ->label('Campaigns Used'),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->actions([
                Action::make('preview')
                    ->label('Live Preview')
                    ->icon(Heroicon::Eye)
                    ->color('info')
                    ->modalHeading(fn (MarketingTemplate $record): string => "Email Preview: {$record->name}")
                    ->modalDescription(fn (MarketingTemplate $record): string => "Subject: {$record->subject}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (MarketingTemplate $record): View => view('odden-marketing::template-preview', [
                        'renderedHtml' => self::renderSampleHtml($record),
                        'template' => $record,
                    ])),
                Action::make('sendTest')
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->label('Send Test')
                    ->icon(Heroicon::PaperAirplane)
                    ->color('success')
                    ->modalHeading('Send Test Preview Email')
                    ->modalDescription('Dispatch a sample rendering to your inbox to check client appearance.')
                    ->schema([
                        TextInput::make('recipient_email')
                            ->label('Recipient Email')
                            ->email()
                            ->default(fn (): string => auth()->user() !== null ? auth()->user()->email : 'admin@odden.test')
                            ->required(),
                    ])
                    ->action(function (MarketingTemplate $record, array $data): void {
                        $recipient = (string) $data['recipient_email'];
                        $subject = '[TEST PREVIEW] '.$record->subject;
                        $html = self::renderSampleHtml($record);

                        try {
                            Mail::html($html, function ($message) use ($recipient, $subject): void {
                                $message->to($recipient)->subject($subject);
                            });
                        } catch (\Throwable) {
                            // Handled cleanly in testing / sandbox environments without SMTP
                        }

                        Notification::make()
                            ->title('Test Email Dispatched')
                            ->body("Rendered test preview sent to {$recipient}")
                            ->success()
                            ->send();
                    }),
                Action::make('applyModularPreset')
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->label('Apply Layout Preset')
                    ->icon(Heroicon::Squares2x2)
                    ->color('gray')
                    ->modalHeading('Apply Modular Email Template Preset')
                    ->modalDescription('Populate this template with an industry-tested, responsive modular email block layout.')
                    ->schema([
                        Select::make('preset')
                            ->label('Select Preset')
                            ->options(function (): array {
                                if (class_exists(PresetRegistry::class)) {
                                    return app(PresetRegistry::class)->toSelectOptions();
                                }

                                return [
                                    'product_launch' => '🚀 Product Launch & Feature Announcement (Hero, Feature Grid, Quote, CTA)',
                                    'default' => '📰 Standard Editorial Digest & Newsletter',
                                ];
                            })
                            ->default('product_announcement')
                            ->required(),
                    ])
                    ->action(function (MarketingTemplate $record, array $data): void {
                        $presetKey = (string) $data['preset'];

                        if (class_exists(PresetRegistry::class) && app(PresetRegistry::class)->has($presetKey)) {
                            $preset = app(PresetRegistry::class)->get($presetKey);
                            $document = $preset->document();

                            $slots = array_map(fn ($slot): array => [
                                'type' => $slot->type->value,
                                'data' => $slot->data,
                            ], $document->slots);

                            $html = MailBuilder::compile($document);
                            $text = MailBuilder::plainText($document);

                            $record->update([
                                'slots' => $slots,
                                'body_html' => $html,
                                'body_text' => $text,
                            ]);
                        } else {
                            $renderer = app(EmailBlockRenderer::class);
                            $html = $renderer->renderPreset($presetKey);
                            $record->update(['body_html' => $html]);
                        }

                        Notification::make()
                            ->title('Modular Preset Applied')
                            ->body('Template visual slots and responsive HTML have been updated.')
                            ->success()
                            ->send();
                    }),
                Action::make('evaluateAbWinner')
                    ->label('Evaluate A/B')
                    ->icon(Heroicon::Trophy)
                    ->color('warning')
                    ->visible(fn (MarketingTemplate $record): bool => $record->hasAbTest())
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->action(function (MarketingTemplate $record): void {
                        $action = app(EvaluateTemplateAbTestsAction::class);
                        $result = $action->execute($record);

                        Notification::make()
                            ->title("A/B Winner: Variant {$result['winner']}")
                            ->body("Evaluated {$result['sample_size']} recipients across campaigns. Winning variant {$result['winner']} declared.")
                            ->success()
                            ->send();
                    }),
                Action::make('revisions')
                    ->label('Revisions')
                    ->icon(Heroicon::Clock)
                    ->color('gray')
                    ->modalHeading(fn (MarketingTemplate $record): string => "Revision History: {$record->name}")
                    ->modalDescription('View recent versions and automated snapshot logs.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (MarketingTemplate $record): View => view('odden-marketing::template-analytics-history', [
                        'getRecord' => fn (): MarketingTemplate => $record,
                    ])),
                Action::make('exportHtml')
                    ->label('Export HTML')
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('gray')
                    ->modalHeading(fn (MarketingTemplate $record): string => "Export HTML: {$record->name}")
                    ->modalDescription('Production-ready, inlined HTML email payload.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        Textarea::make('exported_html')
                            ->label('Compiled HTML Code')
                            ->rows(18)
                            ->default(fn (MarketingTemplate $record): string => self::renderSampleHtml($record))
                            ->readOnly(),
                    ]),
                Action::make('exportMjml')
                    ->label('Export MJML')
                    ->icon(Heroicon::CodeBracketSquare)
                    ->color('gray')
                    ->modalHeading(fn (MarketingTemplate $record): string => "Export MJML: {$record->name}")
                    ->modalDescription('Standard MJML markup for developer pipelines and CLI workflows.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        Textarea::make('mjml_code')
                            ->label('MJML Code')
                            ->rows(18)
                            ->default(fn (MarketingTemplate $record): string => MailBuilder::exporter()->exportMjml(
                                slots: $record->slots ?? [],
                                theme: $record->theme ?? [],
                                subject: $record->subject
                            ))
                            ->readOnly(),
                    ]),
                Action::make('downloadZip')
                    ->label('Download ZIP')
                    ->icon(Heroicon::ArchiveBoxArrowDown)
                    ->color('gray')
                    ->action(function (MarketingTemplate $record) {
                        $html = self::renderSampleHtml($record);
                        $plainText = $record->body_text ?? MailBuilder::plainText($record->slots ?? []);
                        $zipBytes = MailBuilder::exporter()->createZipPackage(
                            templateName: $record->name,
                            html: $html,
                            plainText: $plainText,
                            metadata: [
                                'name' => $record->name,
                                'subject' => $record->subject,
                                'category' => $record->category,
                                'slots_count' => is_array($record->slots) ? count($record->slots) : 0,
                                'exported_at' => now()->toIso8601String(),
                            ]
                        );

                        return response()->streamDownload(
                            function () use ($zipBytes): void {
                                echo $zipBytes;
                            },
                            ($record->slug ?? 'template').'-package.zip',
                            ['Content-Type' => 'application/zip']
                        );
                    }),
                ReplicateAction::make()
                    ->label('Duplicate')
                    ->beforeReplicaSaved(function (MarketingTemplate $replica): void {
                        $replica->name = $replica->name.' (Copy)';
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Render sample template body with realistic dummy merge tags.
     */
    public static function renderSampleHtml(MarketingTemplate $template): string
    {
        $sampleContext = class_exists(MailBuilder::class)
            ? MailBuilder::mergeTags()->sampleContext()
            : [
                'contact' => ['first_name' => 'Alex', 'last_name' => 'Morgan', 'email' => 'alex.morgan@acme.com'],
                'company' => ['name' => 'Acme Corporation'],
                'unsubscribe_url' => route('odden.marketing.preferences.show', 'preview-sample'),
            ];

        if (! empty($template->slots) && class_exists(MailBuilder::class)) {
            return MailBuilder::compile($template->slots, [
                'subject' => $template->subject,
                'preview_text' => $template->preview_text,
                'interpolate' => true,
                'context' => $sampleContext,
            ]);
        }

        if (class_exists(MailBuilder::class)) {
            return MailBuilder::interpolate($template->body_html, $sampleContext);
        }

        return $template->body_html;
    }

    /**
     * Reusable Hint Action to open the Merge Tag cheat sheet modal.
     */
    public static function getMergeTagsHintAction(): Action
    {
        return Action::make('mergeTags')
            ->label('Merge Tags')
            ->icon(Heroicon::Tag)
            ->color('info')
            ->tooltip('Browse available personalization tags & fallback syntax')
            ->modalHeading('Personalization Tokens & Merge Tags')
            ->modalDescription('Click to copy tokens into your clipboard.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (): View => view('mail-builder::merge-tags-reference'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketingTemplates::route('/'),
            'create' => CreateMarketingTemplate::route('/create'),
            'edit' => EditMarketingTemplate::route('/{record}/edit'),
        ];
    }
}
