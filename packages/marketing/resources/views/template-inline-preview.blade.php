@php
    $record = $getRecord();
    $slots = $get('slots') ?? ($record?->slots ?? []);
    $subject = $get('subject') ?? ($record?->subject ?? 'Sample Email Subject');
    $previewText = $get('preview_text') ?? ($record?->preview_text ?? '');
    
    $html = '';
    if (!empty($slots) && is_array($slots) && class_exists(\Odden\MailBuilder\MailBuilder::class)) {
        $html = \Odden\MailBuilder\MailBuilder::compile($slots, [
            'subject' => $subject,
            'preview_text' => $previewText,
            'interpolate' => true,
            'context' => \Odden\MailBuilder\MailBuilder::mergeTags()->sampleContext(),
        ]);
    } elseif ($record?->body_html) {
        $html = class_exists(\Odden\MailBuilder\MailBuilder::class)
            ? \Odden\MailBuilder\MailBuilder::interpolate($record->body_html)
            : $record->body_html;
    } else {
        $html = '<div style="padding: 40px; text-align: center; color: #64748b; font-family: sans-serif;"><p style="font-size: 16px; font-weight: bold;">No Slots Added Yet</p><p style="font-size: 13px;">Add blocks in the "Visual Slot Designer" tab or apply a layout preset to preview your email here.</p></div>';
    }

    $frameLight = \Odden\Marketing\Support\EmailPreviewFrame::document((string) $html);
    $frameDark = \Odden\Marketing\Support\EmailPreviewFrame::document((string) $html, dark: true);
@endphp

<div x-data="{ mode: 'desktop', themeMode: 'light' }" class="flex flex-col gap-4 w-full">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between border-b pb-3 border-slate-200 dark:border-slate-800 gap-3">
        <div class="text-xs text-slate-500 dark:text-slate-400">
            Previewing with sample recipient: <strong class="text-slate-800 dark:text-slate-200">Alex Morgan &lt;alex.morgan@acme.com&gt;</strong>
        </div>

        <div class="flex items-center gap-2">
            {{-- Viewport Switcher --}}
            <div class="inline-flex rounded-lg border border-slate-200 p-0.5 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                <button
                    type="button"
                    @click="mode = 'desktop'"
                    :class="mode === 'desktop' ? 'bg-white shadow-sm font-semibold text-sky-600 dark:bg-slate-700 dark:text-sky-400' : 'text-slate-600 dark:text-slate-400'"
                    class="px-2.5 py-1 text-xs rounded transition flex items-center gap-1.5"
                >
                    <x-filament::icon icon="heroicon-m-computer-desktop" class="w-3.5 h-3.5" />
                    <span>Desktop (600px)</span>
                </button>
                <button
                    type="button"
                    @click="mode = 'mobile'"
                    :class="mode === 'mobile' ? 'bg-white shadow-sm font-semibold text-sky-600 dark:bg-slate-700 dark:text-sky-400' : 'text-slate-600 dark:text-slate-400'"
                    class="px-2.5 py-1 text-xs rounded transition flex items-center gap-1.5"
                >
                    <x-filament::icon icon="heroicon-m-device-phone-mobile" class="w-3.5 h-3.5" />
                    <span>Mobile (375px)</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Preview Canvas Area --}}
    <div
        class="w-full flex justify-center items-center p-6 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 min-h-[580px] transition-colors bg-slate-100 dark:bg-slate-950"
    >
        {{-- Desktop View Window --}}
        <template x-if="mode === 'desktop'">
            <div class="w-full max-w-[620px] transition-all duration-200 bg-white rounded-lg shadow-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="bg-slate-50 dark:bg-slate-800/90 px-4 py-2.5 border-b border-slate-200 dark:border-slate-700 text-xs flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <span class="text-slate-400 font-medium">Subject:</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">{{ $subject }}</span>
                    </div>
                    @if (!empty($previewText))
                        <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                            <span class="text-slate-400">Preview:</span>
                            <span>{{ $previewText }}</span>
                        </div>
                    @endif
                </div>
                <iframe sandbox referrerpolicy="no-referrer" title="Email preview" srcdoc="{{ $frameLight }}"
                    x-bind:srcdoc="themeMode === 'dark' ? {{ \Illuminate\Support\Js::from($frameDark) }} : {{ \Illuminate\Support\Js::from($frameLight) }}"
                    class="block w-full h-[600px] border-0 bg-white"></iframe>
            </div>
        </template>

        {{-- Mobile Phone Bezel Mockup (375px Chassis) --}}
        <template x-if="mode === 'mobile'">
            <div class="w-[375px] max-w-full rounded-[44px] p-3 shadow-2xl transition-all duration-300 bg-slate-900 border-4 border-slate-800 flex flex-col relative">
                {{-- Speaker Notch / Dynamic Island --}}
                <div class="w-24 h-4 bg-slate-950 rounded-full mx-auto mb-2 flex items-center justify-center">
                    <div class="w-2.5 h-2.5 bg-slate-800 rounded-full mr-2"></div>
                    <div class="w-2 h-2 bg-slate-900 rounded-full"></div>
                </div>

                {{-- Phone Screen --}}
                <div class="bg-white rounded-[32px] overflow-hidden flex flex-col shadow-inner">
                    {{-- Compact Mobile Email Client Header --}}
                    <div class="bg-slate-100 px-4 py-2 border-b border-slate-200 text-[11px] flex flex-col gap-0.5 text-slate-700">
                        <div class="font-bold truncate text-slate-900">{{ $subject }}</div>
                        @if (!empty($previewText))
                            <div class="text-[10px] text-slate-500 truncate">{{ $previewText }}</div>
                        @endif
                    </div>

                    {{-- Scrollable Email Body --}}
                    <iframe sandbox referrerpolicy="no-referrer" title="Email preview" srcdoc="{{ $frameLight }}"
                        x-bind:srcdoc="themeMode === 'dark' ? {{ \Illuminate\Support\Js::from($frameDark) }} : {{ \Illuminate\Support\Js::from($frameLight) }}"
                        class="block w-full h-[500px] border-0 bg-white"></iframe>
                </div>

                {{-- Home Indicator Bar --}}
                <div class="w-28 h-1 bg-slate-600 rounded-full mx-auto mt-3"></div>
            </div>
        </template>
    </div>
</div>
