<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class AvatarService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        #[Target('avatars.storage')]
        private readonly FilesystemOperator $storage,
        private readonly string $uploadPublicPath,
        private readonly float $maxFileSize,
        private readonly SluggerInterface $slugger,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Upload un avatar et retourne l'URL publique
     *
     * @throws \InvalidArgumentException si le fichier est invalide
     */
    public function uploadAvatar(UploadedFile $file, int $userId): string
    {
        // Validation taille
        if ($file->getSize() > $this->maxFileSize) {
            throw new \InvalidArgumentException(
                'Le fichier est trop volumineux. Taille maximale : 5MB'
            );
        }

        // Validation type MIME (sécurité)
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Format de fichier non autorisé. Formats acceptés : JPEG, PNG, WebP'
            );
        }

        // Générer un nom de fichier unique et sécurisé
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $extension = $file->guessExtension();
        $newFilename = sprintf(
            'avatar_%d_%s_%s.%s',
            $userId,
            $safeFilename,
            uniqid(),
            $extension
        );

        $stream = fopen($file->getPathname(), 'r');

        try {
            $this->storage->writeStream($newFilename, $stream);
            $this->logger->info('Avatar uploadé avec succès', [
                'user_id' => $userId,
                'filename' => $newFilename
            ]);
        } catch (FilesystemException $e) {
            $this->logger->error('Erreur lors de l\'upload de l\'avatar', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Impossible d\'uploader le fichier');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Retourner l'URL publique
        return $this->uploadPublicPath . '/' . $newFilename;
    }

    /**
     * Supprime un ancien avatar du stockage
     */
    public function deleteAvatar(?string $photoUrl): void
    {
        if (!$photoUrl) {
            return;
        }

        // Extraire le nom du fichier de l'URL
        $filename = basename($photoUrl);

        try {
            if ($this->storage->fileExists($filename)) {
                $this->storage->delete($filename);
                $this->logger->info('Avatar supprimé', ['file' => $filename]);
            }
        } catch (FilesystemException $e) {
            $this->logger->warning('Impossible de supprimer l\'avatar', [
                'file' => $filename,
                'error' => $e->getMessage()
            ]);
        }
    }
}
