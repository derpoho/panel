<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28mm 22mm 24mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.55; color: #151515; }
        header { position: fixed; top: -18mm; left: 0; right: 0; height: 10mm; font-size: 8pt; color: #6b6b6b; }
        header img { width: 16px; height: 16px; vertical-align: middle; margin-right: 6px; }
        h1 { font-size: 18pt; line-height: 1.25; margin: 0 0 4pt; font-weight: bold; }
        .for { color: #6b6b6b; margin: 0 0 18pt; }
        h2 { font-size: 11pt; margin: 16pt 0 6pt; page-break-after: avoid; }
        h3 { font-size: 10pt; margin: 12pt 0 4pt; page-break-after: avoid; }
        p { margin: 0 0 7pt; }
        ul, ol { margin: 0 0 7pt; padding-left: 16pt; }
        li { margin-bottom: 3pt; }
        a { color: #1C4BB0; text-decoration: none; }
        table { width: 100%; border-collapse: collapse; margin: 6pt 0 10pt; page-break-inside: avoid; }
        th, td { border: 0.5pt solid #d4d4d8; padding: 5pt 6pt; text-align: left; vertical-align: top; }
        th { background: #f4f4f5; font-weight: bold; }
        td { height: 12pt; }
        code { font-family: 'DejaVu Sans Mono', monospace; font-size: 8.5pt; }
    </style>
</head>
<body>
    <header>
        @if ($logo)<img src="{{ $logo }}" alt="">@endif {{ \App\Support\Brand::signOff() }} · {{ $title }}
    </header>

    <h1>{{ $title }}</h1>
    <p class="for">For {{ $company }} · {{ $date }}</p>

    {!! $html !!}
</body>
</html>
