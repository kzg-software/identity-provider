<?php

namespace App\Mail;

use App\Models\SystemSetting;
use App\Support\AccentPalette;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Allgemeine System-E-Mail im Layout des restlichen Systems (Name, Logo,
 * Akzentfarbe). HTML mit Text-Fallback. Kurzer Text, eine optionale
 * Schaltfläche.
 */
class SystemMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $body  Absätze des Fließtexts
     */
    public function __construct(
        public string $subjectLine,
        public string $heading,
        public array $body = [],
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $logoPath = SystemSetting::get('logo_path');

        return new Content(
            view: 'emails.system',
            text: 'emails.system_plain',
            with: [
                'systemName' => SystemSetting::get('system_name') ?: config('app.name'),
                'logoUrl' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
                'accent' => AccentPalette::from(SystemSetting::get('accent_color')),
            ],
        );
    }
}
