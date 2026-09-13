<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Genere et stocke durablement une facture PDF par transaction (Lot N4, plan-
 * complements-monetisation-cobage.md). Generation unique : une fois generee, jamais
 * regeneree (une facture ne doit pas changer retroactivement, meme si le template
 * evolue plus tard) - contrairement a AvatarService, remplacable a volonte.
 *
 * Contenu HTML construit en PHP brut (sprintf), pas de template Twig : aucun service de
 * ce backend n'utilise Twig pour generer du contenu (verifie - EmailService suit le meme
 * patron pour ses emails), pas de raison d'introduire une premiere dependance Twig ici.
 */
readonly class InvoiceService
{
    private const ISSUER_NAME = 'Timothée Joël Tchinda Tchoffo';
    private const ISSUER_ADDRESS = 'Toulouse, France';
    private const ISSUER_EMAIL = 'support@cobage.joeltech.fr';

    private const TYPE_LABELS = [
        Transaction::TYPE_SUBSCRIPTION_INITIAL => 'Abonnement (souscription initiale)',
        Transaction::TYPE_SUBSCRIPTION_RENEWAL => 'Abonnement (renouvellement)',
        Transaction::TYPE_BOOST => 'Boost de visibilité',
    ];

    public function __construct(
        #[Target('invoices.storage')]
        private FilesystemOperator $storage,
        private TransactionRepository $transactionRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Retourne le contenu binaire du PDF, en le générant au préalable si nécessaire
     * (première demande de téléchargement sur une transaction déjà réussie).
     */
    public function getContent(Transaction $transaction): string
    {
        $path = $this->ensureGenerated($transaction);

        return $this->storage->read($path);
    }

    /**
     * Génère la facture si elle n'existe pas encore, retourne son chemin de stockage.
     * Idempotent : un appel répété sur une transaction déjà facturée ne régénère rien.
     */
    public function ensureGenerated(Transaction $transaction): string
    {
        $existingPath = $transaction->getInvoiceStoragePath();
        if ($existingPath !== null) {
            return $existingPath;
        }

        $invoiceNumber = $this->generateInvoiceNumber();
        $html = $this->buildInvoiceHtml($transaction, $invoiceNumber);

        $options = new Options();
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $path = $invoiceNumber . '.pdf';

        try {
            $this->storage->write($path, $dompdf->output());
        } catch (\League\Flysystem\FilesystemException $e) {
            $this->logger->error('Erreur lors de l\'écriture de la facture PDF', [
                'transaction_id' => $transaction->getId(),
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Impossible de générer la facture');
        }

        $transaction->setInvoiceNumber($invoiceNumber);
        $transaction->setInvoiceStoragePath($path);
        $this->entityManager->flush();

        return $path;
    }

    private function generateInvoiceNumber(): string
    {
        $year = (int) date('Y');
        $sequence = $this->transactionRepository->countInvoicedTransactionsForYear($year) + 1;

        return sprintf('INV-%d-%05d', $year, $sequence);
    }

    private function buildInvoiceHtml(Transaction $transaction, string $invoiceNumber): string
    {
        $user = $transaction->getUser();
        $typeLabel = self::TYPE_LABELS[$transaction->getType()] ?? $transaction->getType();
        $date = $transaction->getCreatedAt()?->format('d/m/Y') ?? '-';
        $refundLine = $transaction->getRefundedAt() !== null
            ? sprintf('<p>Remboursé le %s.</p>', $transaction->getRefundedAt()->format('d/m/Y'))
            : '';

        return sprintf(
            '<html><head><style>
                body { font-family: Arial, sans-serif; color: #1a1a1a; font-size: 13px; }
                .header { display: flex; justify-content: space-between; margin-bottom: 40px; }
                h1 { color: #00695c; font-size: 20px; }
                table { width: 100%%; border-collapse: collapse; margin-top: 20px; }
                th, td { text-align: left; padding: 8px; border-bottom: 1px solid #ddd; }
                .total { font-weight: bold; font-size: 16px; }
                .provisional { color: #888; font-size: 10px; margin-top: 60px; }
            </style></head>
            <body>
                <h1>Facture %s</h1>
                <p><strong>%s</strong><br>%s<br>%s</p>
                <p>Facturé à : %s %s<br>%s</p>
                <table>
                    <tr><th>Description</th><th>Date</th><th>Moyen de paiement</th><th>Montant</th></tr>
                    <tr>
                        <td>%s</td>
                        <td>%s</td>
                        <td>%s</td>
                        <td>%s %s</td>
                    </tr>
                </table>
                %s
                <p class="total">Total : %s %s</p>
                <p class="provisional">Numérotation de facture provisoire, en attente de validation comptable/juridique définitive.</p>
            </body></html>',
            htmlspecialchars($invoiceNumber),
            htmlspecialchars(self::ISSUER_NAME),
            htmlspecialchars(self::ISSUER_ADDRESS),
            htmlspecialchars(self::ISSUER_EMAIL),
            htmlspecialchars($user?->getPrenom() ?? ''),
            htmlspecialchars($user?->getNom() ?? ''),
            htmlspecialchars($user?->getEmail() ?? ''),
            htmlspecialchars($typeLabel),
            htmlspecialchars($date),
            $transaction->getPaymentMethodFamily() === Transaction::METHOD_FAMILY_CARD ? 'Carte' : 'Mobile Money',
            htmlspecialchars($transaction->getAmount() ?? ''),
            htmlspecialchars($transaction->getCurrency() ?? ''),
            $refundLine,
            htmlspecialchars($transaction->getAmount() ?? ''),
            htmlspecialchars($transaction->getCurrency() ?? ''),
        );
    }
}
