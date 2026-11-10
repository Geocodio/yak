{!! $needsAnswer ? 'Needs one answer' : 'Task started' !!}: {!! $title !!}

{!! $sentence !!}

@if ($needsAnswer)
@foreach ($repoOptions as $option)
{!! $option['slug'] !!}: {!! $option['url'] !!}
@endforeach
Each link opens Yak with that repo selected. You confirm with one click.
@else
@foreach ($rows as $row)
{!! $row['repo'] !!} ({!! $row['mode'] !!}, {!! $row['externalId'] !!})
Open task in Yak: {!! $row['url'] !!}
@endforeach
@endif

Your instruction:
{!! $instruction !!}

What I read: {!! $subject !!}
@foreach ($attachments as $attachment)
- {!! $attachment['name'] !!} ({!! $attachment['size'] !!})
@endforeach

Replies to this email aren't read. Continue the conversation in Yak.
Email settings: {!! $settingsUrl !!}
