<?php

namespace App\Support\StatusPages;

use GdImage;

/**
 * The picture a status page shows when its link is shared (Discord, Slack,
 * X, iMessage), drawn like the page itself in its dark theme: the title, a
 * banner edged in the overall status's colour, and up to four services,
 * worst first, each with its uptime and a bar per day for the last 30
 * days. 1200×630 PNG, drawn with GD.
 */
class StatusOgImage
{
    public const int WIDTH = 1200;

    public const int HEIGHT = 630;

    private const int PAD = 72;

    /** Days of bars, as on the page. */
    private const int DAYS = 30;

    /** Services shown; the rest are counted. */
    private const int ROWS = 4;

    /** Status colours, as on the page (also the link preview's edge colour). */
    public const array COLOURS = [
        StatusReport::OPERATIONAL => '#1fa382',
        StatusReport::DEGRADED => '#f59e0b',
        StatusReport::DOWN => '#e5484d',
        'unknown' => '#6b6b75',
    ];

    /** The banner's tinted header strip, per status, from the page's dark theme. */
    private const array HEADS = [
        StatusReport::OPERATIONAL => '#0e493a',
        StatusReport::DEGRADED => '#4a3207',
        StatusReport::DOWN => '#4c1d1f',
        'unknown' => '#26262b',
    ];

    /**
     * @param  array<string, mixed>  $report  StatusReport::toArray() or FleetStatusReport::toArray()
     */
    public static function png(array $report, string $address): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        imageantialias($image, true);

        $bg = self::colour($image, '#161618');
        $text = self::colour($image, '#f7f7f8');
        $strong = self::colour($image, '#e4e4e7');
        $muted = self::colour($image, '#787882');
        $line = self::colour($image, '#29292f');
        $empty = self::colour($image, '#29292f');
        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        $bold = resource_path('fonts/MonaSans-600.ttf');
        $medium = resource_path('fonts/MonaSans-500.ttf');
        $width = self::WIDTH - self::PAD * 2;
        $right = self::WIDTH - self::PAD;

        $status = (string) ($report['overall']['status'] ?? 'unknown');
        $accent = self::colour($image, self::COLOURS[$status] ?? self::COLOURS['unknown']);
        $head = self::colour($image, self::HEADS[$status] ?? self::HEADS['unknown']);

        // Title
        $title = (string) ($report['title'] ?? 'Status');
        $size = 40;
        while ($size > 28 && self::textWidth($title, $bold, $size) > $width) {
            $size -= 2;
        }
        imagettftext($image, $size, 0, self::PAD, 92, $text, $bold, self::fit($title, $bold, $size, $width));

        // Banner: an edge in the status colour, a tinted strip, then a summary.
        $components = self::components($report);
        [$top, $headEnd, $bottom] = [124, 190, 246];
        self::roundedRect($image, self::PAD, $top, $right, $bottom, 14, $accent);
        self::roundedRect($image, self::PAD + 2, $top + 2, $right - 2, $bottom - 2, 12, $head);
        self::roundedRect($image, self::PAD + 2, $headEnd, $right - 2, $bottom - 2, 12, $bg);
        imagefilledrectangle($image, self::PAD + 2, $headEnd, $right - 2, $headEnd + 14, $bg);

        self::statusIcon($image, self::PAD + 36, (int) (($top + $headEnd) / 2) + 1, $status === StatusReport::OPERATIONAL, $accent, $head);
        $label = $status === StatusReport::OPERATIONAL ? 'We’re fully operational' : (string) ($report['overall']['label'] ?? 'Not reporting');
        imagettftext($image, 26, 0, self::PAD + 64, $headEnd - 22, $text, $medium, $label);

        $up = count(array_filter($components, fn (array $c) => $c['status'] === StatusReport::OPERATIONAL));
        $summary = array_filter([
            count($components) > 0 ? "{$up} of ".count($components).' '.(count($components) === 1 ? 'service' : 'services').' up' : null,
            isset($report['uptimePercent']) ? self::percent((float) $report['uptimePercent']).' uptime over 90 days' : null,
        ]);
        imagettftext($image, 20, 0, self::PAD + 28, $bottom - 20, $muted, $medium, implode('  ·  ', $summary));

        // Services, worst first.
        $rank = [StatusReport::DOWN => 0, StatusReport::DEGRADED => 1, 'unknown' => 2, StatusReport::OPERATIONAL => 3];
        usort($components, fn (array $a, array $b) => ($rank[$a['status']] ?? 2) <=> ($rank[$b['status']] ?? 2));
        $y = 286;
        foreach (array_slice($components, 0, self::ROWS) as $i => $component) {
            if ($i > 0) {
                imageline($image, self::PAD, $y - 16, $right, $y - 16, $line);
            }
            $dot = self::colour($image, self::COLOURS[$component['status']] ?? self::COLOURS['unknown']);
            imagefilledellipse($image, self::PAD + 6, $y + 9, 12, 12, $dot);
            $uptime = self::uptime($component['days']);
            $uptimeText = $uptime === null ? '' : self::percent($uptime);
            $uptimeWidth = self::textWidth($uptimeText, $medium, 18);
            imagettftext($image, 20, 0, self::PAD + 24, $y + 17, $strong, $medium, self::fit($component['label'], $medium, 20, $width - 24 - $uptimeWidth - 24));
            if ($uptimeText !== '') {
                imagettftext($image, 18, 0, $right - $uptimeWidth, $y + 16, $muted, $medium, $uptimeText);
            }

            // A bar per day.
            $days = array_slice($component['days'], -self::DAYS);
            if ($days !== []) {
                $gap = 4;
                $barWidth = ($width - $gap * (self::DAYS - 1)) / self::DAYS;
                $offset = self::DAYS - count($days);
                foreach ($days as $d => $value) {
                    $x = (int) round(self::PAD + ($offset + $d) * ($barWidth + $gap));
                    $fill = match (true) {
                        $value === null => $empty,
                        $value >= 99.9 => self::colour($image, self::COLOURS[StatusReport::OPERATIONAL]),
                        $value >= 95 => self::colour($image, self::COLOURS[StatusReport::DEGRADED]),
                        default => self::colour($image, self::COLOURS[StatusReport::DOWN]),
                    };
                    self::roundedRect($image, $x, $y + 30, (int) round($x + $barWidth), $y + 46, 2, $fill);
                }
            }
            $y += 70;
        }
        if ($components === []) {
            imagettftext($image, 20, 0, self::PAD, $y + 17, $muted, $medium, 'Nothing is listed on this page yet.');
        }

        // Footer: the address, how many more, and ServerOS's mark.
        $more = count($components) - self::ROWS;
        $foot = self::fit($address, $medium, 18, $width - 240).($more > 0 ? "  ·  {$more} more ".($more === 1 ? 'service' : 'services') : '');
        imagettftext($image, 18, 0, self::PAD, 598, $muted, $medium, $foot);
        $mark = resource_path('images/serveros-mark-ink.png');
        if (is_file($mark) && ($logo = imagecreatefrompng($mark)) instanceof GdImage) {
            // The ink mark, recoloured to the page's text colour for the dark ground.
            imagefilter($logo, IMG_FILTER_NEGATE);
            $brand = 'ServerOS';
            $x = $right - self::textWidth($brand, $bold, 20);
            imagecopyresampled($image, $logo, $x - 34, 574, 0, 0, 24, 27, imagesx($logo), imagesy($logo));
            imagettftext($image, 20, 0, $x, 598, $text, $bold, $brand);
        }

        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }

    /**
     * Every service on the page, across its sections, with its daily uptime.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{status: string, label: string, days: list<float|null>}>
     */
    public static function components(array $report): array
    {
        $components = [];
        foreach (is_array($report['sections'] ?? null) ? $report['sections'] : [] as $section) {
            foreach (is_array($section['components'] ?? null) ? $section['components'] : [] as $component) {
                $days = is_array($component['uptime']['days'] ?? null) ? $component['uptime']['days'] : [];
                $components[] = [
                    'status' => is_string($component['status'] ?? null) ? $component['status'] : 'unknown',
                    'label' => is_string($component['label'] ?? null) ? $component['label'] : '',
                    'days' => array_values(array_map(fn ($v) => is_numeric($v) ? (float) $v : null, $days)),
                ];
            }
        }

        return $components;
    }

    /** @param  list<float|null>  $days */
    private static function uptime(array $days): ?float
    {
        $known = array_filter(array_slice($days, -self::DAYS), fn ($v) => $v !== null);

        return $known === [] ? null : array_sum($known) / count($known);
    }

    /** A filled circle with a tick, or an exclamation mark, cut out of it. */
    private static function statusIcon(GdImage $image, int $cx, int $cy, bool $ok, int $fill, int $cut): void
    {
        imagefilledellipse($image, $cx, $cy, 28, 28, $fill);
        imagesetthickness($image, 3);
        if ($ok) {
            imageline($image, $cx - 6, $cy, $cx - 2, $cy + 5, $cut);
            imageline($image, $cx - 2, $cy + 5, $cx + 7, $cy - 5, $cut);
        } else {
            imageline($image, $cx, $cy - 7, $cx, $cy + 2, $cut);
            imagefilledellipse($image, $cx, $cy + 7, 4, 4, $cut);
        }
        imagesetthickness($image, 1);
    }

    private static function colour(GdImage $image, string $hex): int
    {
        $channel = fn (int $offset): int => max(0, min(255, (int) hexdec(substr($hex, $offset, 2))));

        return (int) imagecolorallocate($image, $channel(1), $channel(3), $channel(5));
    }

    private static function textWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? 0 : (int) abs($box[2] - $box[0]);
    }

    /** Cut with an ellipsis until it fits. */
    private static function fit(string $text, string $font, int $size, int $width): string
    {
        if (self::textWidth($text, $font, $size) <= $width) {
            return $text;
        }
        while (mb_strlen($text) > 1 && self::textWidth($text.'…', $font, $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private static function roundedRect(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $fill): void
    {
        $radius = min($radius, (int) floor(($x2 - $x1) / 2), (int) floor(($y2 - $y1) / 2));
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $fill);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $fill);
        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $fill);
        }
    }

    private static function percent(float $value): string
    {
        return ($value >= 99.995 ? '100' : rtrim(rtrim(number_format($value, 2), '0'), '.')).'%';
    }
}
