<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $isReset ? "Récupération de mot de passe" : "Vérification de votre adresse email" }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
                    <tr>
                        <td style="background:#0f766e;padding:20px 28px;color:#ffffff;">
                            <div style="font-size:18px;font-weight:700;">Institut des Formations Paramédicales</div>
                            <div style="font-size:13px;opacity:.9;">Plateforme académique</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            @if ($isReset)
                                <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">
                                    Une réinitialisation de mot de passe a été demandée pour votre compte
                                    <strong>{{ config('app.name') }}</strong>. Saisissez le code ci-dessous sur l''écran
                                    de connexion pour choisir un nouveau mot de passe.
                                </p>
                            @else
                                <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">
                                    Merci d''avoir ajouté une adresse email à votre compte
                                    <strong>{{ config('app.name') }}</strong>. Confirmez-la avec le code ci-dessous pour
                                    pouvoir récupérer votre mot de passe en cas d''oubli.
                                </p>
                            @endif
                            <div style="margin:0 0 20px;padding:18px;text-align:center;background:#f9fafb;border:1px dashed #0f766e;border-radius:10px;">
                                <div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#6b7280;margin-bottom:8px;">Votre code</div>
                                <div style="font-size:34px;font-weight:700;letter-spacing:8px;color:#0f766e;font-family:SFMono-Regular,Consolas,monospace;">{{ $code }}</div>
                            </div>
                            <p style="margin:0 0 8px;font-size:13px;color:#4b5563;">
                                Ce code est valable <strong>{{ $ttlMinutes }} minutes</strong> et ne peut être utilisé qu''une seule fois.
                            </p>
                            <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
                                Si vous n''êtes pas à l''origine de cette demande, vous pouvez ignorer ce message : votre mot de passe
                                ne sera pas modifié.
                            </p>
                            <p style="margin:0;font-size:12px;color:#9ca3af;">
                                Vous recevez cet email parce qu''une adresse a été enregistrée sur votre compte.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
