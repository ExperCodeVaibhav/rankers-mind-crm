<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;

final class UploadService
{
    private const ALLOWED = [
        'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf',
        'text/plain'=>'txt','text/csv'=>'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
    ];

    public static function store(array $file, string $directory = 'private_uploads'): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \RuntimeException('Select a valid file to upload.');
        $max = (int) Config::get('app.upload_max_mb', 5) * 1024 * 1024;
        if (($file['size'] ?? 0) > $max) throw new \RuntimeException('The file exceeds the configured upload limit.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) throw new \RuntimeException('This file type is not allowed.');
        $name = bin2hex(random_bytes(20)) . '.' . self::ALLOWED[$mime];
        $relative = 'storage/' . trim($directory, '/') . '/' . $name;
        $target = root_path($relative);
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
        if (!move_uploaded_file($file['tmp_name'], $target)) throw new \RuntimeException('The upload could not be saved.');
        return $relative;
    }

    public static function storeBase64Image(string $dataUrl, string $directory = 'attendance_photos'): string
    {
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $matches)) throw new \RuntimeException('Capture a valid attendance photo.');
        $binary = base64_decode($matches[2], true);
        if ($binary === false || strlen($binary) > 4 * 1024 * 1024) throw new \RuntimeException('The captured photo is invalid or too large.');
        if (!function_exists('imagecreatefromstring')) {
            $info = @getimagesizefromstring($binary);
            if (!$info) throw new \RuntimeException('The captured photo could not be decoded.');
            $extension = ($info['mime'] ?? '') === 'image/png' ? 'png' : (($info['mime'] ?? '') === 'image/webp' ? 'webp' : 'jpg');
            $name = bin2hex(random_bytes(20)) . '.' . $extension;
            $relative = 'storage/' . trim($directory, '/') . '/' . $name;
            if (file_put_contents(root_path($relative), $binary, LOCK_EX) === false) throw new \RuntimeException('The captured photo could not be saved.');
            return $relative;
        }
        $source = imagecreatefromstring($binary);
        if (!$source) throw new \RuntimeException('The captured photo could not be decoded.');
        $width = imagesx($source); $height = imagesy($source);
        $maxWidth = 960;
        if ($width > $maxWidth) {
            $newHeight = (int) round($height * $maxWidth / $width);
            $targetImage = imagecreatetruecolor($maxWidth, $newHeight);
            imagecopyresampled($targetImage, $source, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);
            imagedestroy($source); $source = $targetImage;
        }
        $name = bin2hex(random_bytes(20)) . '.jpg';
        $relative = 'storage/' . trim($directory, '/') . '/' . $name;
        imagejpeg($source, root_path($relative), 78); imagedestroy($source);
        return $relative;
    }
}
