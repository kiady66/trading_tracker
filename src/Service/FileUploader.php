<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploader
{
    private string $targetDirectory;
    private SluggerInterface $slugger;
    private int $maxFileSizeKB;
    private int $compressionQuality;

    public function __construct($targetDirectory, SluggerInterface $slugger, int $maxFileSizeKB = 100, int $compressionQuality = 80)
    {
        $this->targetDirectory = $targetDirectory;
        $this->slugger = $slugger;
        $this->maxFileSizeKB = $maxFileSizeKB;
        $this->compressionQuality = $compressionQuality;
    }

    public function upload(UploadedFile $file, int $maxFileSizeKB = null, int $compressionQuality = null): string
    {
        $maxFileSizeKB = $maxFileSizeKB ?? $this->maxFileSizeKB;
        $compressionQuality = $compressionQuality ?? $this->compressionQuality;

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $fileName = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        try {
            $file->move($this->getTargetDirectory(), $fileName);

            $filePath = $this->getTargetDirectory().'/'.$fileName;
            $this->compressImageToMaxSize($filePath, $maxFileSizeKB, $compressionQuality);

        } catch (FileException $e) {
            throw new FileException('Erreur lors de l\'upload du fichier');
        }

        return $fileName;
    }

    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }

    public function compressImageToMaxSize(string $filePath, int $maxSizeKB, int $quality = 80): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $currentSizeKB = filesize($filePath) / 1024;

        // Si l'image est déjà plus petite que la taille maximale, on ne fait rien
        if ($currentSizeKB <= $maxSizeKB) {
            return;
        }

        $info = getimagesize($filePath);
        if (!$info) {
            return;
        }

        $mime = $info['mime'];
        $originalQuality = $quality;

        // Tentative de compression progressive jusqu'à atteindre la taille souhaitée
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
                    // Format non supporté
                    return;
            }

            if (isset($image)) {
                imagedestroy($image);
            }

            clearstatcache(true, $filePath);
            $currentSizeKB = filesize($filePath) / 1024;

            // Réduire la qualité pour la prochaine itération si nécessaire
            if ($currentSizeKB > $maxSizeKB) {
                $quality -= 10;
            }
        }

        // Si on n'arrive toujours pas à réduire suffisamment, on réduit les dimensions
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

        // Créer l'image selon le format
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

        // Réduire progressivement la taille jusqu'à atteindre la limite
        $scaleFactor = 0.9;
        $currentSizeKB = filesize($filePath) / 1024;

        while ($currentSizeKB > $maxSizeKB && $scaleFactor > 0.3) {
            $newWidth = (int)($width * $scaleFactor);
            $newHeight = (int)($height * $scaleFactor);

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

            // Préserver la transparence pour les PNG
            if ($mime === 'image/png') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
            }

            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            // Sauvegarder l'image redimensionnée
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

    public function remove(string $filename): void
    {
        $filePath = $this->getTargetDirectory().'/'.$filename;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    // Getters pour les paramètres
    public function getMaxFileSizeKB(): int
    {
        return $this->maxFileSizeKB;
    }

    public function getCompressionQuality(): int
    {
        return $this->compressionQuality;
    }
}
