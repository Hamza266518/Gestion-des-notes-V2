<?php

namespace App\Mail;

use App\Models\AuthCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $purpose,
        public int $ttlMinutes
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->purpose === AuthCode::PURPOSE_EMAIL_VERIFICATION
            ? 'Votre code de vérification IFP'
            : 'Votre code de récupération de mot de passe IFP';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth-code',
            with: [
                'code'       => $this->code,
                'purpose'    => $this->purpose,
                'ttlMinutes' => $this->ttlMinutes,
                'isReset'    => $this->purpose === AuthCode::PURPOSE_PASSWORD_RESET,
            ],
        );
    }
}