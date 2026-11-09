{{ $heading }}

@foreach ($paragraphs as $paragraph)
{{ $paragraph }}

@endforeach
@foreach ($sections as $section)
@if (! empty($section['title']))
{{ $section['title'] }}
@endif
@foreach ($section['rows'] as $row)
- {{ $row['name'] }}{{ filled($row['value'] ?? null) ? ': '.$row['value'] : '' }}
@endforeach
@if (! empty($section['more']))
- {{ $section['more'] }}
@endif

@endforeach
@foreach ($after as $paragraph)
{{ $paragraph }}

@endforeach
@if ($button)
{{ $button['label'] }}: {{ $button['url'] }}

@endif
@if ($link ?? null)
{{ $link['label'] }}: {{ $link['url'] }}

@endif
@if ($note)
{{ $note }}

@endif
@if ($signature)
{{ $signature }}

@endif
@foreach ($footer as $line)
{{ $line }}
@endforeach
@isset($unsubscribe)
{{ $unsubscribe['label'] }}: {{ $unsubscribe['url'] }}
@endisset
