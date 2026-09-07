<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AvatarService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Phase 4b, Lot 7 (bec-docs/docs/plan-correction/plan-correction-cobage.md) : validation MIME
 * reelle (finfo, pas seulement l'extension - cf. CLAUDE.md backend section 7), plafond de
 * taille, generation d'un nom de fichier non derive de l'entree utilisateur brute. Fixtures
 * generees en memoire (octets magiques JPEG/PNG/WebP), pas de binaire committe dans le depot.
 */
class AvatarServiceTest extends TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $uploadDir;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir() . '/avatar-test-' . uniqid();
        mkdir($this->uploadDir, 0777, true);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadDir)) {
            foreach (glob($this->uploadDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->uploadDir);
        }
    }

    private function service(float $maxFileSize = 5 * 1024 * 1024): AvatarService
    {
        return new AvatarService(
            $this->uploadDir,
            '/uploads/avatars',
            $maxFileSize,
            new AsciiSlugger(),
            $this->logger,
        );
    }

    private function fixture(string $filename, string $bytes): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid() . '-' . $filename;
        file_put_contents($path, $bytes);

        return $path;
    }

    private function uploadedJpeg(string $originalName = 'photo.jpg'): UploadedFile
    {
        $path = $this->fixture($originalName, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100));

        return new UploadedFile($path, $originalName, 'image/jpeg', null, true);
    }

    private function uploadedPng(string $originalName = 'photo.png'): UploadedFile
    {
        $path = $this->fixture($originalName, base64_decode(self::ONE_PIXEL_PNG_BASE64));

        return new UploadedFile($path, $originalName, 'image/png', null, true);
    }

    private function uploadedText(string $originalName = 'not-an-image.txt'): UploadedFile
    {
        $path = $this->fixture($originalName, 'ceci nest pas une image, juste du texte brut');

        return new UploadedFile($path, $originalName, 'text/plain', null, true);
    }

    // ==================== uploadAvatar ====================

    public function testUploadAvatarRejectsAFileThatExceedsTheMaxSize(): void
    {
        $service = $this->service(maxFileSize: 5); // 5 octets : n'importe quel fixture le depasse
        $file = $this->uploadedJpeg();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trop volumineux');
        $service->uploadAvatar($file, 1);
    }

    public function testUploadAvatarRejectsADisallowedMimeType(): void
    {
        $service = $this->service();
        $file = $this->uploadedText();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Format de fichier non autorisé');
        $service->uploadAvatar($file, 1);
    }

    public function testUploadAvatarMovesAValidJpegAndReturnsThePublicUrl(): void
    {
        $service = $this->service();
        $file = $this->uploadedJpeg();
        $this->logger->expects(self::once())->method('info')->with(
            'Avatar uploadé avec succès',
            self::callback(fn (array $ctx) => $ctx['user_id'] === 42)
        );

        $url = $service->uploadAvatar($file, 42);

        self::assertStringStartsWith('/uploads/avatars/avatar_42_photo_', $url);
        self::assertStringEndsWith('.jpg', $url);
        self::assertFileExists($this->uploadDir . '/' . basename($url));
    }

    public function testUploadAvatarAcceptsAValidPng(): void
    {
        $service = $this->service();
        $file = $this->uploadedPng();

        $url = $service->uploadAvatar($file, 7);

        self::assertStringEndsWith('.png', $url);
    }

    public function testUploadAvatarThrowsARuntimeExceptionWhenTheDestinationCannotBeCreated(): void
    {
        // Un fichier regulier occupe deja le chemin cible : mkdir() echoue meme en root.
        $blockedPath = sys_get_temp_dir() . '/avatar-blocked-' . uniqid();
        file_put_contents($blockedPath, 'obstacle');
        $service = new AvatarService($blockedPath, '/uploads/avatars', 5 * 1024 * 1024, new AsciiSlugger(), $this->logger);
        $file = $this->uploadedJpeg();
        $this->logger->expects(self::once())->method('error');

        try {
            $this->expectException(\RuntimeException::class);
            $service->uploadAvatar($file, 1);
        } finally {
            @unlink($blockedPath);
        }
    }

    // ==================== deleteAvatar ====================

    public function testDeleteAvatarDoesNothingWhenUrlIsNull(): void
    {
        $service = $this->service();
        $this->logger->expects(self::never())->method('info');
        $this->logger->expects(self::never())->method('warning');

        $service->deleteAvatar(null);
    }

    public function testDeleteAvatarDoesNothingWhenTheFileDoesNotExist(): void
    {
        $service = $this->service();
        $this->logger->expects(self::never())->method('info');

        $service->deleteAvatar('/uploads/avatars/avatar_inexistant.jpg');
    }

    public function testDeleteAvatarRemovesAnExistingFile(): void
    {
        $service = $this->service();
        $filename = 'avatar_1_photo_abc.jpg';
        file_put_contents($this->uploadDir . '/' . $filename, 'contenu');
        $this->logger->expects(self::once())->method('info')->with(
            'Avatar supprimé',
            ['file' => $filename]
        );

        $service->deleteAvatar('/uploads/avatars/' . $filename);

        self::assertFileDoesNotExist($this->uploadDir . '/' . $filename);
    }
}
