<?php

declare(strict_types=1);

namespace Odden\Core\Enums;

enum ActivityType: string
{
    case Note = 'note';
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Task = 'task';
    case LinkedIn = 'linkedin';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    case StageChange = 'stage_change';
    case SystemEvent = 'system_event';

    /**
     * Get a human-readable label for the activity type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Call => 'Phone Call',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::Task => 'Task',
            self::LinkedIn => 'LinkedIn / Social',
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
            self::StageChange => 'Stage Change',
            self::SystemEvent => 'System Event',
        };
    }
}
