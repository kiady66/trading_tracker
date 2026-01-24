<?php

namespace App\Tests\Service;

use App\Service\FileUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploaderTest extends TestCase
{
    private string $targetDirectory;
    private SluggerInterface $slugger;
    private FileUploader $fileUploader;

    protected function setUp(): void
    {
        $this->targetDirectory = '/tmp/uploads';
        $this->slugger = $this->createMock(SluggerInterface::class);
        $this->fileUploader = new FileUploader(
            $this->targetDirectory,
            $this->slugger,
            100,
            80
        );
    }

    public function testGetTargetDirectory(): void
    {
        $this->assertSame($this->targetDirectory, $this->fileUploader->getTargetDirectory());
    }

    public function testGetMaxFileSizeKB(): void
    {
        $this->assertSame(100, $this->fileUploader->getMaxFileSizeKB());
    }

    public function testGetCompressionQuality(): void
    {
        $this->assertSame(80, $this->fileUploader->getCompressionQuality());
    }

    public function testConstructorWithDefaultParameters(): void
    {
        $uploader = new FileUploader($this->targetDirectory, $this->slugger);

        $this->assertSame(100, $uploader->getMaxFileSizeKB());
        $this->assertSame(80, $uploader->getCompressionQuality());
    }

    public function testConstructorWithCustomParameters(): void
    {
        $uploader = new FileUploader($this->targetDirectory, $this->slugger, 200, 90);

        $this->assertSame(200, $uploader->getMaxFileSizeKB());
        $this->assertSame(90, $uploader->getCompressionQuality());
    }

    public function testCompressImageToMaxSizeWithNonExistentFile(): void
    {
        $filePath = '/tmp/non-existent-file.jpg';

        $this->fileUploader->compressImageToMaxSize($filePath, 100);

        $this->assertFileDoesNotExist($filePath);
    }
}