<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploader
{
    private $targetDirectory;
    private $slugger;

    public function __construct($targetDirectory, SluggerInterface $slugger)
    {
        $this->targetDirectory = $targetDirectory;
        $this->slugger = $slugger;
    }

    public function upload(UploadedFile $file): string
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $fileName = $safeFilename.'-'.uniqid().'.'.$file->guessExtension();

        try {
            $file->move($this->getTargetDirectory(), $fileName);
        } catch (FileException $e) {
            throw new FileException('Erreur lors de l\'upload du fichier');
        }

        return $fileName;
    }

    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }

    public function compressImage(string $filePath, int $quality = 80): void
    {
        $info = getimagesize($filePath);
        $mime = $info['mime'];

        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($filePath);
                imagejpeg($image, $filePath, $quality);
                break;
            case 'image/png':
                $image = imagecreatefrompng($filePath);
                imagesavealpha($image, true);
                imagepng($image, $filePath, 9);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($filePath);
                imagewebp($image, $filePath, $quality);
                break;
        }

        if (isset($image)) {
            imagedestroy($image);
        }
    }

    public function remove(string $filename): void
    {
        $filePath = $this->getTargetDirectory().'/'.$filename;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
