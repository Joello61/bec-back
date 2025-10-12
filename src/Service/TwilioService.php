<?php

declare(strict_types=1);

namespace App\Service;

use Exception;
use Psr\Log\LoggerInterface;
use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;

/**
 * Service pour l'envoi de SMS via Twilio
 */
readonly class TwilioService
{
    private Client $twilioClient;

    public function __construct(
        private LoggerInterface $logger,
        private string $twilioAccountSid,
        private string $twilioAuthToken,
        private string $twilioPhoneNumber
    ) {
        $this->twilioClient = new Client($twilioAccountSid, $twilioAuthToken);
    }

    /**
     * Envoie un SMS via Twilio
     *
     * @param string $to Numéro de téléphone au format international (+237...)
     * @param string $message Message à envoyer
     * @return bool True si envoyé avec succès
     * @throws Exception Si l'envoi échoue
     */
    public function sendSMS(string $to, string $message): bool
    {
        try {
            $this->twilioClient->messages->create(
                $to,
                [
                    'from' => $this->twilioPhoneNumber,
                    'body' => $message
                ]
            );

            $this->logger->info('SMS envoyé avec succès', [
                'to' => $to,
                'message_length' => strlen($message)
            ]);

            return true;

        } catch (TwilioException $e) {
            $this->logger->error('Erreur lors de l\'envoi du SMS', [
                'to' => $to,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            throw new Exception('Erreur lors de l\'envoi du SMS : ' . $e->getMessage());
        }
    }

    /**
     * Envoie un code de vérification par SMS
     *
     * @param string $phoneNumber Numéro de téléphone
     * @param string $code Code de vérification (6 chiffres)
     * @return bool
     * @throws Exception
     */
    public function sendVerificationCode(string $phoneNumber, string $code): bool
    {
        $message = sprintf(
            "Votre code de vérification Bagage Express : %s\n\nCe code expire dans 15 minutes.\nNe partagez jamais ce code.",
            $code
        );

        return $this->sendSMS($phoneNumber, $message);
    }

    /**
     * Vérifie si un numéro de téléphone est valide pour Twilio
     *
     * @param string $phoneNumber
     * @return bool
     */
    public function isValidPhoneNumber(string $phoneNumber): bool
    {
        // Format E.164 : +[code pays][numéro]
        // Ex: +237612345678, +33612345678
        return preg_match('/^\+?[1-9]\d{1,14}$/', $phoneNumber) === 1;
    }
}
