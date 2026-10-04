<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #{{ $ticket->ticket_number }} - Odden Support</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex flex-col">

    <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
            <a href="{{ route('odden.help.index') }}" class="flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-lg shadow-sm">F</span>
                <span class="font-bold text-lg text-slate-900 tracking-tight">Odden Support</span>
            </a>
            <div class="flex items-center gap-4 text-xs font-semibold">
                <a href="{{ route('odden.help.index') }}" class="text-slate-500 hover:text-indigo-600 transition">Help Center</a>
                <a href="{{ route('odden.support.create') }}" class="text-indigo-600 hover:text-indigo-700 transition">New Ticket</a>
            </div>
        </div>
    </header>

    <main class="flex-1 max-w-4xl mx-auto w-full px-4 sm:px-6 py-8">
        
        @if(session('status'))
            <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-medium flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                {{ session('status') }}
            </div>
        @endif

        <!-- Ticket Card Header -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 shadow-sm mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100 pb-6 mb-6">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <span class="text-xs font-mono font-bold px-2.5 py-1 bg-slate-100 text-slate-700 rounded-md">
                            {{ $ticket->ticket_number }}
                        </span>
                        <span class="px-2.5 py-1 text-xs font-bold rounded-md bg-{{ $ticket->status->color() }}-50 text-{{ $ticket->status->color() }}-700 border border-{{ $ticket->status->color() }}-200">
                            {{ $ticket->status->label() }}
                        </span>
                        <span class="px-2.5 py-1 text-xs font-bold rounded-md bg-{{ $ticket->priority->color() }}-50 text-{{ $ticket->priority->color() }}-700 border border-{{ $ticket->priority->color() }}-200">
                            {{ $ticket->priority->label() }} Priority
                        </span>
                    </div>
                    <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">{{ $ticket->subject }}</h1>
                </div>

                <!-- CSAT Rating CTA if Resolved -->
                @if($ticket->status === \Odden\Service\Enums\TicketStatus::Resolved || $ticket->status === \Odden\Service\Enums\TicketStatus::Closed)
                    @if($ticket->csat_rating)
                        <div class="px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-center shrink-0">
                            <span class="block text-xs font-bold text-amber-900 uppercase">Your Rating</span>
                            <span class="text-lg text-amber-500 font-bold">
                                {{ str_repeat('★', $ticket->csat_rating) }}{{ str_repeat('☆', 5 - $ticket->csat_rating) }}
                            </span>
                        </div>
                    @else
                        <a href="{{ route('odden.support.rate', $ticket->portal_token) }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-xs font-bold transition shadow-sm shrink-0">
                            ★ Rate Your Experience
                        </a>
                    @endif
                @endif
            </div>

            <!-- Meta attributes -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                <div>
                    <span class="block text-slate-400 mb-0.5 font-medium">Submitted By</span>
                    <span class="font-bold text-slate-800">You</span>
                </div>
                <div>
                    <span class="block text-slate-400 mb-0.5 font-medium">Assigned Specialist</span>
                    <span class="font-bold text-slate-800">{{ $ticket->owner?->name ?? 'Team Support' }}</span>
                </div>
                <div>
                    <span class="block text-slate-400 mb-0.5 font-medium">Created</span>
                    <span class="font-bold text-slate-800">{{ $ticket->created_at?->format('M j, Y g:i A') }}</span>
                </div>
                <div>
                    <span class="block text-slate-400 mb-0.5 font-medium">Channel</span>
                    <span class="font-bold text-slate-800">{{ $ticket->source->label() }}</span>
                </div>
            </div>
        </div>

        <!-- Conversation Timeline -->
        <div class="space-y-6 mb-8">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500 px-1">Conversation Thread</h2>

            @php
                // Public messages only (no internal notes)
                $publicMessages = $ticket->messages->where('is_internal_note', false);
            @endphp

            @foreach($publicMessages as $message)
                @php
                    $isCustomer = $message->sender_type === \Odden\Service\Enums\MessageSenderType::Customer;
                @endphp
                <div class="flex gap-4 {{ $isCustomer ? '' : 'flex-row-reverse' }}">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm text-white shrink-0 {{ $isCustomer ? 'bg-indigo-600' : 'bg-emerald-600' }}">
                        {{ substr($isCustomer ? 'You' : $message->senderName(), 0, 1) }}
                    </div>

                    <div class="flex-1 max-w-2xl bg-white rounded-2xl border {{ $isCustomer ? 'border-indigo-100 shadow-sm' : 'border-emerald-100 shadow-sm' }} p-6">
                        <div class="flex items-center justify-between mb-3 border-b border-slate-100 pb-2">
                            <span class="font-bold text-sm text-slate-900">
                                {{ $isCustomer ? 'You' : $message->senderName() }}
                                <span class="text-xs font-normal text-slate-400 ml-2">({{ $isCustomer ? 'Customer' : 'Support Specialist' }})</span>
                            </span>
                            <span class="text-xs text-slate-400">{{ $message->created_at?->diffForHumans() }}</span>
                        </div>
                        <div class="prose prose-sm text-slate-700">
                            {!! nl2br(e($message->body)) !!}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Customer Reply Box (if not permanently closed) -->
        @if($ticket->status !== \Odden\Service\Enums\TicketStatus::Closed)
            <div class="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 shadow-sm">
                <h3 class="text-base font-bold text-slate-900 mb-2">Reply to this Ticket</h3>
                <p class="text-xs text-slate-500 mb-4">Add more information or follow up with your support specialist.</p>

                <form action="{{ route('odden.support.reply', $ticket->portal_token) }}" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <textarea name="body" rows="4" required placeholder="Type your response here..."
                                  class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none text-sm transition"></textarea>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl text-sm transition shadow-sm">
                            Post Reply
                        </button>
                    </div>
                </form>
            </div>
        @endif

    </main>

    <footer class="bg-white border-t border-slate-200 py-6 text-center text-xs text-slate-400 mt-12">
        &copy; {{ date('Y') }} Odden CRM Inc. All rights reserved.
    </footer>
</body>
</html>
