<x-mail.layout preheader="Confirm {{ $email }} so Yak accepts forwarded emails from it.">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td class="em-pad em-text" style="padding:24px 24px 8px;color:#282a30;">
                <h1 style="margin:0 0 10px;font-size:20px;line-height:1.3;font-weight:650;">Confirm this address for Yak</h1>
                <p class="em-muted" style="margin:0;font-size:14px;line-height:1.55;color:#6b6f7a;">
                    {{ $ownerName }} added <span class="em-text" style="color:#282a30;font-weight:600;">{{ $email }}</span> to their Yak account. After you confirm, Yak accepts forwarded emails from it.
                </p>
            </td>
        </tr>
        <tr>
            <td class="em-pad" style="padding:18px 24px 24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td class="em-button" style="border-radius:8px;background-color:#503ba3;">
                            <a class="em-button" href="{{ $confirmationUrl }}" style="display:inline-block;padding:11px 18px;border-radius:8px;background-color:#503ba3;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">Confirm address</a>
                        </td>
                    </tr>
                </table>
                <p class="em-faint" style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#9a9ea8;">
                    The link expires in 24 hours. You need to be signed in to Yak to confirm. If you did not expect this, ignore it.
                </p>
            </td>
        </tr>
    </table>
</x-mail.layout>
