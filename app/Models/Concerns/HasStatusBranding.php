<?php

namespace App\Models\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A status page's own branding: the icon beside its name and its favicon,
 * kept on the public disk and removed with the page.
 *
 * @property string|null $page_title
 * @property string|null $icon_path
 * @property string|null $favicon_path
 */
trait HasStatusBranding
{
    /** The images a page can have, by the name the settings use. */
    public const BRANDING_IMAGES = ['icon' => 'icon_path', 'favicon' => 'favicon_path'];

    public static function bootHasStatusBranding(): void
    {
        static::deleted(function (self $page) {
            foreach (self::BRANDING_IMAGES as $column) {
                if ($page->{$column} !== null) {
                    Storage::disk('public')->delete($page->{$column});
                }
            }
        });
    }

    public function iconUrl(): ?string
    {
        return $this->icon_path === null ? null : Storage::disk('public')->url($this->icon_path);
    }

    public function faviconUrl(): ?string
    {
        return $this->favicon_path === null ? null : Storage::disk('public')->url($this->favicon_path);
    }

    /** Put in a new icon or favicon, replacing the old one. */
    public function storeBrandingImage(string $kind, UploadedFile $file): void
    {
        $column = self::BRANDING_IMAGES[$kind];
        $previous = $this->{$column};

        $path = $file->store('status-pages', 'public');
        if ($path === false) {
            throw new RuntimeException('Couldn’t save the image.');
        }

        $this->forceFill([$column => $path])->save();

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }
    }

    /** @return bool Whether there was one to remove. */
    public function removeBrandingImage(string $kind): bool
    {
        $column = self::BRANDING_IMAGES[$kind];
        if ($this->{$column} === null) {
            return false;
        }

        Storage::disk('public')->delete($this->{$column});
        $this->forceFill([$column => null])->save();

        return true;
    }

    /**
     * What both kinds of page take for an icon or favicon. SVGs aren't taken: they can carry scripts.
     *
     * @return list<string>
     */
    public static function brandingImageRules(string $kind): array
    {
        return $kind === 'favicon'
            ? ['required', 'file', 'mimes:png,ico,jpg,jpeg,webp,gif', 'max:1024']
            : ['required', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048', 'dimensions:max_width=4000,max_height=4000'];
    }
}
