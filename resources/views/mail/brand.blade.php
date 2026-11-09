@php
    // Tables and inline styles only: what Gmail, Outlook and Apple Mail all keep.
    $font = "'Mona Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
    $mono = "'Geist Mono',SFMono-Regular,Menlo,Consolas,monospace";
    $dots = ['good' => '#34D399', 'warn' => '#FBBF24', 'bad' => '#F87171', 'neutral' => '#7C8BA8'];
    $tones = ['good' => '#6EE7B7', 'warn' => '#FCD34D', 'bad' => '#FCA5A5', 'mono' => '#8A8A96'];

    // Gmail's apps invert colours in dark mode, which turns this dark email
    // light. They leave background images alone, so every fill is a flat
    // gradient too; and text sits in two blend layers (styled only for Gmail,
    // through `u + .body`) that undo the inversion.
    $fill = fn (string $hex) => "background-color:{$hex}; background-image:linear-gradient({$hex},{$hex});";
    $text = fn (string $html) => '<div class="g-screen"><div class="g-diff">'.$html.'</div></div>';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Dark by design: keep clients from inverting it. --}}
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>{{ $heading }}</title>
    <style>
        :root { color-scheme: dark; supported-color-schemes: dark; }
        u + .body .g-screen { background: #000; mix-blend-mode: screen; }
        u + .body .g-diff { background: #000; mix-blend-mode: difference; }
        @media (max-width: 620px) {
            .so-heading { font-size: 24px !important; line-height: 30px !important; }
            .so-pad { padding-left: 24px !important; padding-right: 24px !important; }
        }
    </style>
</head>
<body class="body" style="margin:0; padding:0; {{ $fill('#121218') }}">
    <u></u>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#121218" style="{{ $fill('#121218') }}">
        <tr>
            <td align="center" style="padding:44px 12px 40px; {{ $fill('#121218') }}">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; font-family:{!! $font !!};">

                    {{-- Logo and name --}}
                    <tr>
                        <td align="center" style="padding-bottom:28px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    @if ($brand)
                                        <td style="vertical-align:middle; font-size:17px; line-height:22px; font-weight:600; letter-spacing:-0.01em; color:#E4E4E9;">{!! $text(e($brand)) !!}</td>
                                    @else
                                        <td style="padding-right:8px; vertical-align:middle;"><img src="{{ $logoUrl }}" width="20" height="22" alt="" style="display:block; border:0;"></td>
                                        <td style="vertical-align:middle; font-size:17px; line-height:22px; font-weight:600; letter-spacing:-0.01em; color:#E4E4E9;">{!! $text('ServerOS') !!}</td>
                                    @endif
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Illustration, its edges already faded into the page --}}
                    <tr>
                        <td><img src="{{ $imageUrl }}" width="600" alt="" style="display:block; width:100%; max-width:600px; height:auto; border:0;"></td>
                    </tr>

                    {{-- Heading beside its icon --}}
                    <tr>
                        <td align="center" class="so-pad" style="padding:32px 48px 0;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding-right:12px; vertical-align:middle;"><img src="{{ $iconUrl }}" width="38" height="38" alt="" style="display:block; border:0;"></td>
                                    <td class="so-heading" style="vertical-align:middle; font-size:30px; line-height:36px; font-weight:500; letter-spacing:-0.015em; color:#E4E4E9;">{!! $text(e($heading)) !!}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Escaped, keeping any line breaks inside a paragraph. --}}
                    @foreach ($paragraphs as $paragraph)
                        <tr>
                            <td align="{{ $align }}" class="so-pad" style="padding:{{ $loop->first ? '18px' : '10px' }} {{ $align === 'left' ? '48px' : '64px' }} 0; text-align:{{ $align }}; font-size:15px; line-height:24px; color:#9A9AA6;">{!! $text(nl2br(e($paragraph), false)) !!}</td>
                        </tr>
                    @endforeach

                    {{-- Tables: what was found, an alert's details, a week's numbers. The
                         outer table is the border (a fill, so Gmail doesn't flip it). --}}
                    @foreach ($sections as $section)
                        <tr>
                            <td align="center" class="so-pad" style="padding:{{ $loop->first ? '22px' : '14px' }} 48px 0;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $fill('#26262E') }} border-radius:11px;">
                                    <tr>
                                        <td style="padding:1px;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $fill('#16161D') }} border-radius:10px;">
                                                @if (! empty($section['title']))
                                                    <tr>
                                                        <td colspan="3" style="padding:12px 16px 4px; font-size:11px; line-height:16px; font-weight:500; letter-spacing:0.08em; text-transform:uppercase; color:#8A8A96;">{!! $text(e($section['title'])) !!}</td>
                                                    </tr>
                                                @endif
                                                @foreach ($section['rows'] as $row)
                                                    @if (! $loop->first || ! empty($section['title']))
                                                        <tr><td colspan="3" style="padding:0 16px;"><div style="height:1px; line-height:1px; font-size:1px; {{ $fill('#22222A') }}">&nbsp;</div></td></tr>
                                                    @endif
                                                    <tr>
                                                        <td width="16" style="width:16px; padding:10px 0 10px 16px; vertical-align:middle;">
                                                            @if (! empty($row['dot']))
                                                                <div style="width:6px; height:6px; border-radius:3px; font-size:1px; line-height:1px; {{ $fill($dots[$row['dot']] ?? $dots['neutral']) }}">&nbsp;</div>
                                                            @endif
                                                        </td>
                                                        <td style="padding:10px 0 10px {{ empty($row['dot']) ? '0' : '4px' }}; vertical-align:middle; font-size:14px; line-height:20px; {{ ($row['muted'] ?? false) ? 'color:#8A8A96;' : 'color:#E4E4E9; font-weight:500;' }}">{!! $text(e($row['name'])) !!}</td>
                                                        <td align="right" style="padding:10px 16px 10px 12px; vertical-align:middle; font-size:{{ ($row['tone'] ?? null) === 'mono' ? '12px' : '14px' }}; line-height:20px; font-family:{!! ($row['tone'] ?? null) === 'mono' ? $mono : $font !!}; color:{{ $tones[$row['tone'] ?? ''] ?? '#E4E4E9' }}; {{ in_array($row['tone'] ?? null, ['good', 'warn', 'bad'], true) ? 'font-weight:500;' : '' }}">{!! filled($row['value'] ?? null) ? $text(e($row['value'])) : '' !!}</td>
                                                    </tr>
                                                @endforeach
                                                @if (! empty($section['more']))
                                                    <tr><td colspan="3" style="padding:0 16px;"><div style="height:1px; line-height:1px; font-size:1px; {{ $fill('#22222A') }}">&nbsp;</div></td></tr>
                                                    <tr>
                                                        <td colspan="3" style="padding:10px 16px 12px 36px; font-size:13px; line-height:20px; color:#7A7A86;">{!! $text(e($section['more'])) !!}</td>
                                                    </tr>
                                                @endif
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endforeach

                    @foreach ($after as $paragraph)
                        <tr>
                            <td align="{{ $align }}" class="so-pad" style="padding:22px {{ $align === 'left' ? '48px' : '64px' }} 0; text-align:{{ $align }}; font-size:15px; line-height:24px; color:#9A9AA6;">{!! $text(nl2br(e($paragraph), false)) !!}</td>
                        </tr>
                    @endforeach

                    @if ($button)
                        <tr>
                            <td align="center" style="padding:32px 48px 0;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $fill('#2F68DF') }} border-radius:9px;">
                                    <tr>
                                        <td style="padding:1px;">
                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $fill('#1C4BB0') }} border-radius:8px;">
                                                <tr>
                                                    <td>
                                                        <a href="{{ $button['url'] }}" style="display:inline-block; padding:11px 22px; font-size:14px; line-height:18px; font-weight:500; color:#FFFFFF; text-decoration:none;">{!! $text(e($button['label'])) !!}</a>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    @if ($link ?? null)
                        <tr>
                            <td align="center" style="padding:16px 48px 0; font-size:13px; line-height:20px;">
                                {!! $text('<a href="'.e($link['url']).'" style="color:#9A9AA6; text-decoration:underline;">'.e($link['label']).'</a>') !!}
                            </td>
                        </tr>
                    @endif

                    @if ($note)
                        <tr>
                            <td align="center" class="so-pad" style="padding:18px 56px 0; font-size:13px; line-height:20px; color:#6E6E7A;">{!! $text(e($note)) !!}</td>
                        </tr>
                    @endif

                    @if ($signature)
                        <tr>
                            <td align="center" style="padding:14px 48px 0; font-size:13px; line-height:20px; font-weight:500; color:#B4B4BE;">{!! $text(e($signature)) !!}</td>
                        </tr>
                    @endif

                    {{-- Footer, under a dotted rule --}}
                    <tr>
                        <td align="center" style="padding:32px 48px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="border-top:2px dotted #2A2A33; padding-top:28px; font-size:12px; line-height:18px; color:#5E5E6A;">
                                        {{-- A customer's visitors (a status page's) don't need our pitch. --}}
                                        @unless ($brand)
                                            {!! $text((config('cloud.enabled') ? 'One panel for every server, yours or ours.' : 'One panel for the servers you already rent.').'<br>'.e(\App\Support\Brand::signOff()).' &middot; <a href="'.e(config('app.url')).'" style="color:#5E5E6A; text-decoration:none;">'.e(\App\Support\Brand::website()).'</a>') !!}
                                        @endunless
                                        @foreach ($footer as $line)
                                            @if (! $loop->first || ! $brand)
                                                <div style="height:12px; line-height:12px; font-size:1px;">&nbsp;</div>
                                            @endif
                                            {!! $text(e($line)) !!}
                                        @endforeach
                                        @isset($unsubscribe)
                                            <div style="height:12px; line-height:12px; font-size:1px;">&nbsp;</div>
                                            {!! $text('<a href="'.e($unsubscribe['url']).'" style="color:#7A7A86; text-decoration:underline;">'.e($unsubscribe['label']).'</a>') !!}
                                        @endisset
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
