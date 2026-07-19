<?php

namespace App\Tests\Service;

use App\Service\FileUploader;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploaderTest extends TestCase
{
    private FilesystemOperator&MockObject $storage;
    private SluggerInterface $slugger;
    private FileUploader $fileUploader;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(FilesystemOperator::class);
        $this->slugger = $this->createMock(SluggerInterface::class);
        $this->fileUploader = new FileUploader(
            $this->storage,
            $this->slugger,
            100,
            80
        );
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
        $uploader = new FileUploader($this->storage, $this->slugger);

        $this->assertSame(100, $uploader->getMaxFileSizeKB());
        $this->assertSame(80, $uploader->getCompressionQuality());
    }

    public function testConstructorWithCustomParameters(): void
    {
        $uploader = new FileUploader($this->storage, $this->slugger, 200, 90);

        $this->assertSame(200, $uploader->getMaxFileSizeKB());
        $this->assertSame(90, $uploader->getCompressionQuality());
    }

    public function testRemoveDeletesExistingFile(): void
    {
        $this->storage->method('fileExists')->with('screenshot.jpg')->willReturn(true);
        $this->storage->expects($this->once())->method('delete')->with('screenshot.jpg');

        $this->fileUploader->remove('screenshot.jpg');
    }

    public function testRemoveIgnoresMissingFile(): void
    {
        $this->storage->method('fileExists')->with('screenshot.jpg')->willReturn(false);
        $this->storage->expects($this->never())->method('delete');

        $this->fileUploader->remove('screenshot.jpg');
    }

    public function testCompressImageToMaxSizeWithNonExistentFile(): void
    {
        $filePath = '/tmp/non-existent-file.jpg';

        $this->fileUploader->compressImageToMaxSize($filePath, 100);

        $this->assertFileDoesNotExist($filePath);
    }
}
