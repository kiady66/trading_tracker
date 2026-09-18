<?php

namespace App\Tests\Service;

use App\Service\FileUploader;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploaderTest extends TestCase
{
    private FilesystemOperator&Stub $storage;
    private SluggerInterface $slugger;
    private FileUploader $fileUploader;

    protected function setUp(): void
    {
        $this->storage = $this->createStub(FilesystemOperator::class);
        $this->slugger = $this->createStub(SluggerInterface::class);
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
        // Mock local : ce test vérifie une attente (expects), contrairement
        // aux stubs partagés du setUp
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('fileExists')->with('screenshot.jpg')->willReturn(true);
        $storage->expects($this->once())->method('delete')->with('screenshot.jpg');

        (new FileUploader($storage, $this->slugger, 100, 80))->remove('screenshot.jpg');
    }

    public function testRemoveIgnoresMissingFile(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->method('fileExists')->with('screenshot.jpg')->willReturn(false);
        $storage->expects($this->never())->method('delete');

        (new FileUploader($storage, $this->slugger, 100, 80))->remove('screenshot.jpg');
    }

    public function testCompressImageToMaxSizeWithNonExistentFile(): void
    {
        $filePath = '/tmp/non-existent-file.jpg';

        $this->fileUploader->compressImageToMaxSize($filePath, 100);

        $this->assertFileDoesNotExist($filePath);
    }
}
