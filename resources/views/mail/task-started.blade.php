@php
    $label = 'font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#9a9ea8;padding-bottom:10px;';
    $section = 'padding:18px 24px;border-top:1px solid #ebecef;';
@endphp
<x-mail.layout :preheader="$preheader">
    <x-slot:footer>
        Replies to this email aren't read. Continue the conversation in Yak.<br>
        Sent because you forwarded an email from {{ $from }} &middot; <a class="em-link" href="{{ $settingsUrl }}" style="color:#6b6f7a;text-decoration:underline;">Email settings</a>
    </x-slot:footer>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        {{-- Status, title, and what Yak is doing --}}
        <tr>
            <td class="em-pad em-text" style="padding:24px 24px 18px;color:#282a30;">
                @if ($needsAnswer)
                    <span class="em-warn" style="display:inline-block;padding:3px 10px;border-radius:999px;background-color:#fbf1e6;color:#b8641a;font-size:12px;font-weight:600;">&#9679;&nbsp; Needs one answer</span>
                @else
                    <span class="em-ok" style="display:inline-block;padding:3px 10px;border-radius:999px;background-color:#ebf4ea;color:#3e8e38;font-size:12px;font-weight:600;">&#9679;&nbsp; Task started</span>
                @endif
                <h1 style="margin:12px 0 6px;font-size:21px;line-height:1.3;font-weight:650;letter-spacing:-0.01em;">{{ $title }}</h1>
                <p class="em-muted" style="margin:0;font-size:14px;line-height:1.5;color:#6b6f7a;">{{ $sentence }}</p>

                @unless ($needsAnswer)
                    @foreach ($rows as $row)
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:{{ $loop->first ? '14px' : '8px' }};">
                            <tr>
                                @foreach (['Repo' => $row['repo'], 'Mode' => $row['mode'], 'Task' => $row['externalId']] as $chipLabel => $chipValue)
                                    <td style="padding-right:6px;">
                                        <span class="em-chip" style="display:inline-block;padding:3px 9px;border-radius:5px;border:1px solid #ebecef;background-color:#f5f6f8;font-size:12px;color:#282a30;"><span class="em-muted" style="color:#6b6f7a;">{{ $chipLabel }}</span> {{ $chipValue }}</span>
                                    </td>
                                @endforeach
                                @if (count($rows) > 1)
                                    <td><a class="em-link" href="{{ $row['url'] }}" style="font-size:12px;color:#503ba3;">Open</a></td>
                                @endif
                            </tr>
                        </table>
                    @endforeach
                @endunless
            </td>
        </tr>

        {{-- Primary action, or one link per candidate repository --}}
        <tr>
            <td class="em-pad" style="padding:0 24px 22px;">
                @if ($needsAnswer)
                    @foreach ($repoOptions as $option)
                        <a class="em-ghost" href="{{ $option['url'] }}" style="display:inline-block;margin:0 6px 6px 0;padding:10px 14px;border-radius:8px;border:1px solid #e0e1e6;background-color:#ffffff;color:#282a30;font-size:14px;text-decoration:none;">{{ $option['slug'] }}</a>
                    @endforeach
                    <p class="em-muted" style="margin:6px 0 0;font-size:12px;line-height:1.5;color:#6b6f7a;">Each button opens Yak with that repo selected. You confirm with one click.</p>
                @else
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td class="em-button" style="border-radius:8px;background-color:#503ba3;">
                                <a class="em-button" href="{{ $rows[0]['url'] }}" style="display:inline-block;padding:11px 18px;border-radius:8px;background-color:#503ba3;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">Open task in Yak</a>
                            </td>
                        </tr>
                    </table>
                @endif
            </td>
        </tr>

        {{-- Your instruction --}}
        <tr>
            <td class="em-pad em-border" style="{{ $section }}">
                <div class="em-faint" style="{{ $label }}">Your instruction</div>
                <div class="em-note" style="padding:12px 14px;border-radius:8px;background-color:#fbf5e3;color:#282a30;font-size:14px;line-height:1.5;">{!! nl2br(e($instruction)) !!}</div>
            </td>
        </tr>

        {{-- What Yak read --}}
        <tr>
            <td class="em-pad em-border em-text" style="{{ $section }}color:#282a30;">
                <div class="em-faint" style="{{ $label }}">What I read</div>
                <div style="font-size:13px;font-weight:600;line-height:1.4;">{{ $subject }}</div>
                @if ($originalSender || $originalDate)
                    <div class="em-muted" style="margin-top:2px;font-size:13px;color:#6b6f7a;">{{ collect([$originalSender ? "From {$originalSender}" : null, $originalDate])->filter()->implode(' · ') }}</div>
                @endif
                @if ($attachments !== [])
                    <div style="margin-top:12px;">
                        @foreach ($attachments as $attachment)
                            <span class="em-chip" style="display:inline-block;margin:0 6px 6px 0;padding:6px 10px;border-radius:8px;border:1px solid #ebecef;background-color:#f5f6f8;font-size:12px;color:#282a30;">{{ $attachment['name'] }} <span class="em-muted" style="color:#6b6f7a;">{{ $attachment['size'] }}</span></span>
                        @endforeach
                    </div>
                @endif
            </td>
        </tr>

        {{-- What happens next --}}
        <tr>
            <td class="em-pad em-border em-text" style="{{ $section }}padding-bottom:22px;color:#282a30;">
                <div class="em-faint" style="{{ $label }}">What happens next</div>
                @foreach ([
                    ['state' => 'done', 'text' => 'Email received and verified'],
                    ['state' => 'now', 'text' => $needsAnswer ? 'You pick the repository' : $workingStep],
                    ['state' => 'later', 'text' => $resultStep],
                ] as $step)
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:3px 0;">
                        <tr>
                            <td style="width:20px;height:20px;padding-right:12px;vertical-align:middle;">
                                @if ($step['state'] === 'done')
                                    <div style="width:20px;height:20px;line-height:20px;border-radius:50%;background-color:#3e8e38;color:#ffffff;font-size:11px;text-align:center;">&#10003;</div>
                                @else
                                    <div class="{{ $step['state'] === 'now' ? 'em-step-now' : 'em-border em-faint' }}" style="width:17px;height:17px;line-height:17px;border-radius:50%;border:1.5px solid {{ $step['state'] === 'now' ? '#503ba3' : '#e0e1e6' }};color:{{ $step['state'] === 'now' ? '#503ba3' : '#9a9ea8' }};font-size:11px;text-align:center;">{{ $loop->iteration }}</div>
                                @endif
                            </td>
                            <td class="{{ $step['state'] === 'later' ? 'em-muted' : 'em-text' }}" style="font-size:13px;color:{{ $step['state'] === 'later' ? '#6b6f7a' : '#282a30' }};vertical-align:middle;">{{ $step['text'] }}</td>
                        </tr>
                    </table>
                @endforeach
            </td>
        </tr>
    </table>
</x-mail.layout>
