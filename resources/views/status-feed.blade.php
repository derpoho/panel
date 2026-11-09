{!! '<'.'?xml version="1.0" encoding="utf-8"?'.'>' !!}
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>{{ $title }}</title>
    <link href="{{ $pageUrl }}"/>
    <link rel="self" href="{{ $feedUrl }}"/>
    <id>{{ $feedUrl }}</id>
    <updated>{{ $updated }}</updated>
@foreach ($notices as $notice)
@foreach ($notice['updates'] as $index => $update)
    <entry>
        <title>{{ ucfirst($update['state']) }}: {{ $notice['title'] }}</title>
        <link href="{{ $pageUrl }}"/>
        <id>{{ $feedUrl }}#incident-{{ $notice['id'] }}-{{ count($notice['updates']) - $index }}</id>
        <updated>{{ $update['at'] }}</updated>
        <content type="text">{{ $update['message'] }}</content>
    </entry>
@endforeach
@endforeach
</feed>
