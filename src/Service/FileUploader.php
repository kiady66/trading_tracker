<?php

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploader
{
    public function __construct(
        private FilesystemOperator $screenshotsStorage,
        private SluggerInterface $slugger,
        private int $maxFileSizeKB = 100,
        private int $compressionQuality = 80,
    ) {}

    public function upload(UploadedFile $file, int $maxFileSizeKB = null, int $compressionQuality = null): string
    {
        $maxFileSizeKB = $maxFileSizeKB ?? $this->maxFileSizeKB;
        $compressionQuality = $compressionQuality ?? $this->compressionQuality;

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $fileName = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        try {
            $tmpPath = sys_get_temp_dir().'/'.$fileName;
            $file->move(sys_get_temp_dir(), $fileName);

            $this->compressImageToMaxSize($tmpPath, $maxFileSizeKB, $compressionQuality);

            $this->screenshotsStorage->write($fileName, file_get_contents($tmpPath));
            unlink($tmpPath);
        } catch (\Throwable $e) {
            throw new FileException('Erreur lors de l\'upload du fichier : '.$e->getMessage());
        }

        return $fileName;
    }

    public function remove(string $filename): void
    {
        if ($this->screenshotsStorage->fileExists($filename)) {
            $this->screenshotsStorage->delete($filename);
        }
    }

    public function compressImageToMaxSize(string $filePath, int $maxSizeKB, int $quality = 80): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $currentSizeKB = filesize($filePath) / 1024;

        if ($currentSizeKB <= $maxSizeKB) {
            return;
        }

        $info = getimagesize($filePath);
        if (!$info) {
            return;
        }

        $mime = $info['mime'];
        $originalQuality = $quality;

        while ($currentSizeKB > $maxSizeKB && $quality >= 10) {
            switch ($mime) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($filePath);
                    imagejpeg($image, $filePath, $quality);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($filePath);
                    imagesavealpha($image, true);
                    imagepng($image, $filePath, round(9 * $quality / 100));
                    break;
                case 'image/webp':
                    $image = imagecreatefromwebp($filePath);
                    imagewebp($image, $filePath, $quality);
                    break;
                default:
                    return;
            }

            if (isset($image)) {
                imagedestroy($image);
            }

            clearstatcache(true, $filePath);
            $currentSizeKB = filesize($filePath) / 1024;

            if ($currentSizeKB > $maxSizeKB) {
                $quality -= 10;
            }
        }

        if ($currentSizeKB > $maxSizeKB) {
            $this->resizeImageToMaxSize($filePath, $maxSizeKB, $originalQuality);
        }
    }

    private function resizeImageToMaxSize(string $filePath, int $maxSizeKB, int $quality): void
    {
        $info = getimagesize($filePath);
        if (!$info) {
            return;
        }

        $mime = $info['mime'];
        $width = $info[0];
        $height = $info[1];

        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($filePath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($filePath);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($filePath);
                break;
            default:
                return;
        }

        $scaleFactor = 0.9;
        $currentSizeKB = filesize($filePath) / 1024;

        while ($currentSizeKB > $maxSizeKB && $scaleFactor > 0.3) {
            $newWidth = (int)($width * $scaleFactor);
            $newHeight = (int)($height * $scaleFactor);

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

            if ($mime === 'image/png') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
            }

            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            switch ($mime) {
                case 'image/jpeg':
                    imagejpeg($resizedImage, $filePath, $quality);
                    break;
                case 'image/png':
                    imagepng($resizedImage, $filePath, round(9 * $quality / 100));
                    break;
                case 'image/webp':
                    imagewebp($resizedImage, $filePath, $quality);
                    break;
            }

            imagedestroy($resizedImage);
            clearstatcache(true, $filePath);
            $currentSizeKB = filesize($filePath) / 1024;

            $scaleFactor -= 0.1;
        }

        imagedestroy($image);
    }

    public function getMaxFileSizeKB(): int
    {
        return $this->maxFileSizeKB;
    }

    public function getCompressionQuality(): int
    {
        return $this->compressionQuality;
    }
}