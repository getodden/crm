<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Filament\Resources\CampaignResource\Pages\CreateCampaign;
use Odden\Filament\Resources\CampaignResource\Pages\EditCampaign;
use Odden\Filament\Resources\CampaignResource\Pages\ListCampaigns;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Marketing\Actions\AuditCampaignDeliverabilityAction;
use Odden\Marketing\Actions\DispatchCampaignAction;
use Odden\Marketing\Actions\EvaluateAbTestWinnerAction;
use Odden\Marketing\Actions\SendCampaignProofAction;
use Odden\Marketing\Actions\SuggestSubjectLinesAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Exceptions\CampaignHasNoAudienceException;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\MarketingSubscriptionTopic;
use Odden\Marketing\Models\MarketingTemplate;
use UnitEnum;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $modelLabel = 'Campaign';

    protected static ?string $pluralModelLabel = 'Campaigns';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Envelope;

    protected static UnitEnum|string|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Campaign Details')
                    ->schema([
                        TextInput::make('name')
                            ->label('Internal Campaign Name')
                            ->placeholder('e.g. Q3 Enterprise Feature Announcement')
                            ->required()
                            ->maxLength(255),
                        Select::make('template_id')
                            ->label('Email Template')
                            ->options(fn (): array => MarketingTemplate::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('subject')
                            ->label('Subject Line')
                            ->placeholder('e.g. Introducing our new Enterprise Tier')
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('preview_text')
                            ->label('Preview Header Text')
                            ->placeholder('Brief summary displayed in inbox previews')
                            ->columnSpanFull(),
                        Select::make('topic_id')
                            ->label('Topic Preference Channel')
                            ->options(fn (): array => MarketingSubscriptionTopic::query()->pluck('name', 'id')->all())
                            ->placeholder('General (Broadcast to all active subscribers)')
                            ->helperText('Recipients must be opted into this topic in their subscriber preferences.'),
                    ])
                    ->columns(2),

                Section::make('Campaign Budget & Target Goals')
                    ->description('Track marketing spend, forecasted pipeline, and conversion goals.')
                    ->schema([
                        TextInput::make('budget')
                            ->label('Allocated Budget')
                            ->numeric()
                            ->prefix('$')
                            ->placeholder('0.00'),
                        TextInput::make('actual_spend')
                            ->label('Actual Direct Spend')
                            ->numeric()
                            ->prefix('$')
                            ->placeholder('0.00')
                            ->helperText('Direct cost incurred (ESP sends, creative, copy, paid promo)'),
                        TextInput::make('target_leads')
                            ->label('Target Leads / Engagement')
                            ->numeric()
                            ->placeholder('e.g. 100')
                            ->helperText('Target number of high-intent unique clicks/leads.'),
                        TextInput::make('target_pipeline')
                            ->label('Target Pipeline Influenced')
                            ->numeric()
                            ->prefix('$')
                            ->placeholder('50000.00'),
                        TextInput::make('target_revenue')
                            ->label('Target Closed-Won Revenue')
                            ->numeric()
                            ->prefix('$')
                            ->placeholder('20000.00'),
                    ])
                    ->columns(3),

                Section::make('Tracking & Analytics (UTMs)')
                    ->description('Google Analytics and RevOps attribution tag settings for outbound hyperlinks.')
                    ->schema([
                        Toggle::make('utm_auto_tag')
                            ->label('Auto-Tag Outbound Links with UTMs')
                            ->helperText('Appends utm_source, utm_medium, utm_campaign, and utm_content to all external URLs.')
                            ->default(true),
                        TextInput::make('utm_campaign')
                            ->label('Custom UTM Campaign Name')
                            ->placeholder('e.g. enterprise-q3-launch')
                            ->helperText('Leave empty to automatically slugify the campaign name.'),
                    ])
                    ->columns(2),

                Section::make('Sender & Audience Targeting')
                    ->schema([
                        TextInput::make('sender_name')
                            ->label('From Name')
                            ->default(config('odden-marketing.defaults.sender_name', 'Odden Marketing'))
                            ->required(),
                        TextInput::make('sender_email')
                            ->label('From Email')
                            ->email()
                            ->default(config('odden-marketing.defaults.sender_email', 'newsletter@odden.test'))
                            ->required(),
                        TextInput::make('reply_to_email')
                            ->label('Reply-To Email')
                            ->email()
                            ->placeholder('support@odden.test'),
                        Select::make('list_id')
                            ->label('Target Audience List')
                            ->options(fn (): array => CrmList::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->helperText('Select a static or dynamic CRM list from Contacts'),
                        DateTimePicker::make('scheduled_at')
                            ->label('Schedule Send Time (Optional)'),
                        Toggle::make('send_in_recipient_timezone')
                            ->label('Send in Recipient Local Timezone')
                            ->helperText('Deliver email staggered based on each subscriber’s local morning window.')
                            ->default(false),
                        Toggle::make('use_sto')
                            ->label('Predictive Send Time Optimization (STO)')
                            ->helperText('Analyze past open behavior and deliver during each recipient’s personal peak engagement hour.')
                            ->default(false),
                        TextInput::make('recipient_send_hour')
                            ->label('Local Delivery Hour (0-23)')
                            ->numeric()
                            ->default(9)
                            ->minValue(0)
                            ->maxValue(23)
                            ->visible(fn (callable $get): bool => (bool) $get('send_in_recipient_timezone')),
                    ])
                    ->columns(2),

                Section::make('A/B Split Testing & Optimization')
                    ->description('Test alternative subject lines and template variants against a sample audience before rolling out the winner.')
                    ->schema([
                        Toggle::make('is_ab_test')
                            ->label('Enable A/B Subject & Template Experiment')
                            ->default(false),
                        TextInput::make('variant_b_subject')
                            ->label('Variant B: Subject Line')
                            ->placeholder('e.g. 🚀 High-impact alternative subject line')
                            ->columnSpanFull(),
                        Select::make('variant_b_template_id')
                            ->label('Variant B: Email Template (Optional)')
                            ->options(fn (): array => MarketingTemplate::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->helperText('Leave empty to test subject line only using the primary template'),
                        TextInput::make('ab_test_sample_percentage')
                            ->label('Test Group Sample %')
                            ->numeric()
                            ->default(20)
                            ->helperText('e.g. 20% splits 10% to Variant A, 10% to Variant B; winner gets remaining 80%'),
                        Select::make('ab_winning_metric')
                            ->label('Winning Determination Metric')
                            ->options([
                                'open_rate' => 'Highest Unique Open Rate',
                                'click_rate' => 'Highest Unique Click Rate (CTR)',
                            ])
                            ->default('open_rate'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Campaign')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?CampaignStatus $state): string => $state?->getLabel() ?? '')
                    ->color(fn (?CampaignStatus $state): string => $state?->getColor() ?? 'gray')
                    ->sortable(),
                TextColumn::make('topic')
                    ->label('Topic Channel')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'product_updates' => 'Product Updates',
                        'newsletter' => 'Newsletter',
                        'webinars' => 'Webinars',
                        'security' => 'Security',
                        default => 'All Topics',
                    })
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('delivered_count')
                    ->label('Delivered')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('open_rate')
                    ->label('Open Rate')
                    ->formatStateUsing(fn (Campaign $record): string => $record->open_rate.'% ('.$record->unique_opens_count.')')
                    ->color('info')
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('unique_opens_count', $direction)),
                TextColumn::make('click_rate')
                    ->label('Click Rate')
                    ->formatStateUsing(fn (Campaign $record): string => $record->click_rate.'% ('.$record->unique_clicks_count.')')
                    ->color('success')
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('unique_clicks_count', $direction)),
                TextColumn::make('budget')
                    ->label('Budget')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('actual_cost')
                    ->label('Actual Spend')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sent_at')
                    ->label('Sent Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->actions([
                Action::make('preview')
                    ->label('Preview')
                    ->authorize(OddenAuthorization::forRecord('view', self::class))
                    ->icon(Heroicon::Eye)
                    ->color('info')
                    ->modalHeading(fn (Campaign $record): string => "Email Preview: {$record->name}")
                    ->modalDescription(fn (Campaign $record): string => "Subject: {$record->subject}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Campaign $record): View => view('odden-marketing::template-preview', [
                        'renderedHtml' => self::renderSampleHtml($record),
                        'template' => $record,
                    ])),
                Action::make('deliverabilityAudit')
                    ->label('Spam Audit')
                    ->authorize(OddenAuthorization::forRecord('view', self::class))
                    ->icon(Heroicon::ShieldCheck)
                    ->color('warning')
                    ->modalHeading(fn (Campaign $record): string => "Pre-Flight Deliverability Audit: {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Campaign $record): View => view('odden-marketing::campaign-deliverability-audit', [
                        'audit' => app(AuditCampaignDeliverabilityAction::class)->execute($record),
                        'campaign' => $record,
                    ])),
                Action::make('send')
                    ->label('Send Now')
                    ->icon(Heroicon::PaperAirplane)
                    ->color('success')
                    ->visible(fn (Campaign $record): bool => in_array($record->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true))
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->requiresConfirmation()
                    ->modalHeading('Send Broadcast Campaign')
                    ->modalDescription('Are you sure you want to broadcast this campaign immediately to all targeted list recipients?')
                    ->action(function (Campaign $record): void {
                        try {
                            $results = app(DispatchCampaignAction::class)->execute($record);
                        } catch (CampaignHasNoAudienceException) {
                            Notification::make()
                                ->title('Campaign Has No Audience')
                                ->body('Choose a list for this campaign before sending it.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Campaign Broadcast Sent')
                            ->body("Successfully delivered to {$results['delivered_count']} recipients ({$results['suppressed_count']} suppressed).")
                            ->success()
                            ->send();
                    }),
                Action::make('evaluateAbTest')
                    ->label('Pick Winner & Deploy')
                    ->icon(Heroicon::Trophy)
                    ->color('warning')
                    ->visible(fn (Campaign $record): bool => $record->is_ab_test && $record->status === CampaignStatus::Sending && $record->ab_winner_variant === null)
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->requiresConfirmation()
                    ->modalHeading('Conclude A/B Test Experiment')
                    ->modalDescription('Calculate current engagement metrics, determine the winning variant, and immediately dispatch it to the remaining audience.')
                    ->action(function (Campaign $record): void {
                        $result = app(EvaluateAbTestWinnerAction::class)->execute($record);

                        Notification::make()
                            ->title("Variant {$result['winner']} Won!")
                            ->body("Variant {$result['winner']} won by {$result['metric']} ({$result['variant_a_score']}% vs {$result['variant_b_score']}%). Dispatched to {$result['remaining_sent']} remaining contacts.")
                            ->success()
                            ->send();
                    }),
                Action::make('aiSubjectAssistant')
                    ->label('AI Copy Assistant')
                    ->icon(Heroicon::Sparkles)
                    ->color('info')
                    ->visible(fn (Campaign $record): bool => in_array($record->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true))
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->modalHeading('AI Campaign Subject & Copy Assistant')
                    ->modalDescription('Generate high-converting subject lines and A/B test variants optimized for your campaign topic and tone.')
                    ->form([
                        TextInput::make('topic')
                            ->label('Campaign Theme / Topic')
                            ->default(fn (Campaign $record): string => $record->subject ?: $record->name)
                            ->required(),
                        Select::make('tone')
                            ->label('Desired Tone of Voice')
                            ->options([
                                'engaging' => 'Engaging & Professional (Default)',
                                'urgent' => 'Urgent & Action-Oriented (FOMO)',
                                'curious' => 'Curiosity & Mystery Hook',
                                'friendly' => 'Friendly & Warm (Personal)',
                                'bold' => 'Bold & Disruptive',
                            ])
                            ->default('engaging')
                            ->required(),
                        Select::make('subject_choice')
                            ->label('Select Generated Subject Line')
                            ->helperText('Choose one of the AI suggestions')
                            ->options(function (callable $get, Campaign $record): array {
                                $topic = (string) ($get('topic') ?: ($record->subject ?: $record->name));
                                $tone = (string) ($get('tone') ?: 'engaging');
                                $ai = app(SuggestSubjectLinesAction::class)->execute($topic, $tone);

                                return collect($ai['suggestions'])->mapWithKeys(fn (string $s): array => [$s => $s])->all();
                            })
                            ->required(),
                        TextInput::make('variant_b_subject')
                            ->label('Variant B Subject (A/B Test)')
                            ->helperText('Alternative angle to test against Variant A')
                            ->default(function (callable $get, Campaign $record): string {
                                $topic = (string) ($get('topic') ?: ($record->subject ?: $record->name));
                                $tone = (string) ($get('tone') ?: 'engaging');
                                $ai = app(SuggestSubjectLinesAction::class)->execute($topic, $tone);

                                return $ai['variant_b'];
                            }),
                        TextInput::make('preview_text')
                            ->label('Inbox Preview Text')
                            ->default(function (callable $get, Campaign $record): string {
                                $topic = (string) ($get('topic') ?: ($record->subject ?: $record->name));
                                $ai = app(SuggestSubjectLinesAction::class)->execute($topic, 'engaging');

                                return $ai['preview_text'];
                            }),
                    ])
                    ->action(function (Campaign $record, array $data): void {
                        $record->update([
                            'subject' => (string) $data['subject_choice'],
                            'variant_b_subject' => ! empty($data['variant_b_subject']) ? (string) $data['variant_b_subject'] : $record->variant_b_subject,
                            'preview_text' => ! empty($data['preview_text']) ? (string) $data['preview_text'] : $record->preview_text,
                        ]);

                        Notification::make()
                            ->title('AI Copy Applied')
                            ->body('Campaign subject line and A/B variant copy have been updated successfully.')
                            ->success()
                            ->send();
                    }),
                Action::make('sendTestEmail')
                    ->label('Send Test')
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->icon(Heroicon::PaperAirplane)
                    ->color('info')
                    ->modalHeading(fn (Campaign $record): string => "Send Proof / Test Email: {$record->name}")
                    ->modalDescription('Dispatch an immediate test email to internal reviewers with personalized sample merge tags.')
                    ->form([
                        TextInput::make('recipient_emails')
                            ->label('Reviewer Email Address(es)')
                            ->placeholder('reviewer@example.com, marketing-team@example.com')
                            ->helperText('Comma-separated list of email addresses to receive the test broadcast.')
                            ->default(fn (): string => auth()->user()->email ?? 'test@odden.test')
                            ->required(),
                        Select::make('sample_contact_id')
                            ->label('Simulate Merge Tags As Contact (Optional)')
                            ->options(fn (): array => OddenAuthorization::query(ContactResource::class, Contact::class)->limit(50)->pluck('first_name', 'id')->map(function ($name, $id): string {
                                $contact = Contact::find($id);

                                return "{$name} {$contact?->last_name} ({$contact?->email})";
                            })->all())
                            ->searchable()
                            ->helperText('Uses this contact\'s real CRM attributes (name, company) for merge tag substitutions.'),
                    ])
                    ->action(function (Campaign $record, array $data): void {
                        /** @var Contact|null $sampleContact */
                        $sampleContact = ! empty($data['sample_contact_id']) ? OddenAuthorization::query(ContactResource::class, Contact::class)->find($data['sample_contact_id']) : null;
                        $result = app(SendCampaignProofAction::class)->execute($record, (string) $data['recipient_emails'], $sampleContact);

                        if ($result['success']) {
                            Notification::make()
                                ->title('Test Email Dispatched')
                                ->body($result['message'])
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Failed to Dispatch Test')
                                ->body($result['message'])
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->authorize(fn (Campaign $record): bool => OddenAuthorization::allows('view', $record, self::class) && OddenAuthorization::allows('create', Campaign::class, self::class))
                    ->icon(Heroicon::DocumentDuplicate)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Duplicate Campaign')
                    ->modalDescription('Create a draft copy of this campaign with all targeting, UTM tracking, and template configurations preserved.')
                    ->action(function (Campaign $record): void {
                        $replica = $record->replicate([
                            'sent_at',
                            'delivered_count',
                            'total_recipients',
                            'unique_opens_count',
                            'unique_clicks_count',
                            'bounces_count',
                            'unsubscribes_count',
                            'ab_winner_variant',
                        ]);
                        $replica->name = "Copy of {$record->name}";
                        $replica->status = CampaignStatus::Draft;
                        $replica->sent_at = null;
                        $replica->delivered_count = 0;
                        $replica->total_recipients = 0;
                        $replica->unique_opens_count = 0;
                        $replica->unique_clicks_count = 0;
                        $replica->bounces_count = 0;
                        $replica->unsubscribes_count = 0;
                        $replica->save();

                        Notification::make()
                            ->title('Campaign Duplicated')
                            ->body("Created draft '{$replica->name}'.")
                            ->success()
                            ->send();
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
     * Render sample campaign message body with dummy merge tags.
     */
    public static function renderSampleHtml(Campaign $campaign): string
    {
        $template = $campaign->template;
        $rawHtml = $template !== null
            ? $template->body_html
            : '<div style="font-family:sans-serif;padding:24px;line-height:1.6;"><h2 style="color:#0f172a;">'.$campaign->name.'</h2><p style="color:#475569;">Previewing broadcast campaign without custom HTML template. Hello {{contact.first_name}}!</p></div>';

        $placeholders = [
            '{{contact.first_name}}' => 'Alex',
            '{{contact.last_name}}' => 'Morgan',
            '{{contact.email}}' => 'alex.morgan@acme.com',
            '{{company.name}}' => 'Acme Corporation',
            '{{unsubscribe_url}}' => route('odden.marketing.preferences.show', 'preview-sample'),
            '{{campaign.name}}' => $campaign->name,
            '{{campaign.subject}}' => $campaign->subject,
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $rawHtml);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
