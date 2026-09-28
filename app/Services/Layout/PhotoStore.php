<?php

declare(strict_types=1);

namespace App\Services\Layout;

use App\Core\Exceptions\ValidationException;
use Intervention\Image\ImageManager;

/**
 * Building / floor photos for the Space Explorer (spec 5.4 "Upload photos").
 *
 * Accepts JPG / PNG / WebP (sniffed with finfo, not trusted from the browser), re-encodes to WebP (strips EXIF,
 * auto-orients, scales down to MAX_WIDTH) and stores it under public/media/uploads/ with a random name. Returns
 * the path relative to public/ (what buildings.photo_path / floors.photo_path hold) plus the pixel size, which
 * the map uses as its SVG viewBox. Seat/zone/hotspot coordinates are percentages, so a photo with a different
 * aspect ratio keeps every coordinate (shapes stretch with the image).
 *
 * The seeded SVG placeholders (media/*.svg) are never touched; only files this class wrote are deleted.
 */
final class PhotoStore
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const MAX_WIDTH = 3200;
    public const DIR = 'media/uploads';
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly ?string $publicPath = null)
    {
    }

    /**
     * @param array<string, mixed>|null $file an entry of $_FILES
     * @return array{path: string, width: int, height: int, original: string}
     */
    public function store(?array $file, string $field = 'photo'): array
    {
        $fail = static fn (string $msg) => new ValidationException([$field => [$msg]]);
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw $fail('Choose a photo to upload.');
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_file((string) ($file['tmp_name'] ?? ''))) {
            throw $fail('The upload failed — please try again (max 15 MB).');
        }
        $tmp = (string) $file['tmp_name'];
        if ((int) filesize($tmp) > self::MAX_BYTES) {
            throw $fail('The photo is larger than 15 MB.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::MIMES, true) || @getimagesize($tmp) === false) {
            throw $fail('Upload a JPG, PNG or WebP image.');
        }
        $unsafe = \App\Services\Security\UploadGuard::image($tmp);
        if ($unsafe !== null) {
            throw $fail($unsafe);
        }

        $manager = ImageManager::gd(autoOrientation: true, strip: true);
        try {
            $image = $manager->read($tmp);
        } catch (\Throwable) {
            throw $fail('This image could not be read.');
        }
        $image->scaleDown(width: self::MAX_WIDTH);
        $dir = $this->root() . '/' . self::DIR;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $name = bin2hex(random_bytes(16)) . '.webp';
        $image->toWebp(quality: 82)->save($dir . '/' . $name);

        return [
            'path' => self::DIR . '/' . $name,
            'width' => $image->width(),
            'height' => $image->height(),
            'original' => mb_substr(basename((string) ($file['name'] ?? 'photo')), 0, 255),
        ];
    }

    /** Remove a previously uploaded photo (never the seeded placeholders). */
    public function deleteIfUploaded(?string $path): void
    {
        if ($path === null || preg_match('#^' . preg_quote(self::DIR, '#') . '/[a-f0-9]{32}\.webp$#', $path) !== 1) {
            return;
        }
        $file = $this->root() . '/' . $path;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function root(): string
    {
        return rtrim($this->publicPath ?? public_path(''), '/');
    }
}
