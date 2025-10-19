<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CurrencyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/currencies', name: 'api_currencies_')]
class CurrencyController extends AbstractController
{
    public function __construct(
        private readonly CurrencyService $currencyService,
    ) {}

    /**
     * Liste toutes les devises actives
     *
     * @return JsonResponse
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $currencies = $this->currencyService->getAllActiveCurrencies();

        return $this->json([
            'success' => true,
            'data' => $currencies,
            'count' => count($currencies)
        ], Response::HTTP_OK, [], ['groups' => ['currency:read']]);
    }

    /**
     * Récupère les devises les plus utilisées
     *
     * @return JsonResponse
     */
    #[Route('/popular', name: 'popular', methods: ['GET'])]
    public function popular(Request $request): JsonResponse
    {
        $limit = $request->query->getInt('limit', 5);

        if ($limit < 1 || $limit > 20) {
            return $this->json([
                'success' => false,
                'error' => 'La limite doit être entre 1 et 20'
            ], Response::HTTP_BAD_REQUEST);
        }

        $currencies = $this->currencyService->getMostUsedCurrencies($limit);

        return $this->json([
            'success' => true,
            'data' => $currencies
        ], Response::HTTP_OK, [], ['groups' => ['currency:read']]);
    }

    /**
     * Récupère une devise par son code
     *
     * @param string $code Code ISO 4217 (EUR, USD, XAF, etc.)
     * @return JsonResponse
     */
    #[Route('/{code}', name: 'show', methods: ['GET'])]
    public function show(string $code): JsonResponse
    {
        $currency = $this->currencyService->getCurrency(strtoupper($code));

        if (!$currency) {
            return $this->json([
                'success' => false,
                'error' => 'Devise non trouvée'
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => $currency
        ], Response::HTTP_OK, [], ['groups' => ['currency:read']]);
    }

    /**
     * Convertit un montant d'une devise vers une autre
     *
     * Query params:
     * - amount: float (obligatoire)
     * - from: string (code devise source, obligatoire)
     * - to: string (code devise cible, obligatoire)
     *
     * @return JsonResponse
     */
    #[Route('/convert', name: 'convert', methods: ['GET'])]
    public function convert(Request $request): JsonResponse
    {
        $amount = $request->query->get('amount');
        $fromCurrency = $request->query->get('from');
        $toCurrency = $request->query->get('to');

        // Validation
        if ($amount === null || $fromCurrency === null || $toCurrency === null) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres requis: amount, from, to'
            ], Response::HTTP_BAD_REQUEST);
        }

        $amount = (float) $amount;
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);

        if ($amount <= 0) {
            return $this->json([
                'success' => false,
                'error' => 'Le montant doit être positif'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier que les devises existent
        if (!$this->currencyService->isSupported($fromCurrency)) {
            return $this->json([
                'success' => false,
                'error' => "La devise source '{$fromCurrency}' n'est pas supportée"
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->currencyService->isSupported($toCurrency)) {
            return $this->json([
                'success' => false,
                'error' => "La devise cible '{$toCurrency}' n'est pas supportée"
            ], Response::HTTP_BAD_REQUEST);
        }

        // Conversion
        $conversionInfo = $this->currencyService->getConversionInfo(
            $amount,
            $fromCurrency,
            $toCurrency
        );

        return $this->json([
            'success' => true,
            'data' => $conversionInfo
        ], Response::HTTP_OK);
    }

    /**
     * Détecte la devise selon un pays
     *
     * Body JSON:
     * {
     *   "country": "France" ou "Cameroun" ou "FR" ou "CM"
     * }
     *
     * @return JsonResponse
     */
    #[Route('/detect-by-country', name: 'detect_by_country', methods: ['POST'])]
    public function detectByCountry(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['country']) || empty($data['country'])) {
            return $this->json([
                'success' => false,
                'error' => 'Le paramètre "country" est obligatoire'
            ], Response::HTTP_BAD_REQUEST);
        }

        $country = $data['country'];
        $currencyCode = $this->currencyService->getCurrencyAndLangByCountry($country)['currency'];
        $currency = $this->currencyService->getCurrency($currencyCode);

        return $this->json([
            'success' => true,
            'data' => [
                'country' => $country,
                'currencyCode' => $currencyCode,
                'currency' => $currency
            ]
        ], Response::HTTP_OK, [], ['groups' => ['currency:read']]);
    }

    /**
     * Formate un montant selon une devise
     *
     * Query params:
     * - amount: float (obligatoire)
     * - currency: string (code devise, obligatoire)
     *
     * @return JsonResponse
     */
    #[Route('/format', name: 'format', methods: ['GET'])]
    public function format(Request $request): JsonResponse
    {
        $amount = $request->query->get('amount');
        $currencyCode = $request->query->get('currency');

        // Validation
        if ($amount === null || $currencyCode === null) {
            return $this->json([
                'success' => false,
                'error' => 'Paramètres requis: amount, currency'
            ], Response::HTTP_BAD_REQUEST);
        }

        $amount = (float) $amount;
        $currencyCode = strtoupper($currencyCode);

        if (!$this->currencyService->isSupported($currencyCode)) {
            return $this->json([
                'success' => false,
                'error' => "La devise '{$currencyCode}' n'est pas supportée"
            ], Response::HTTP_BAD_REQUEST);
        }

        $formatted = $this->currencyService->formatAmount($amount, $currencyCode);

        return $this->json([
            'success' => true,
            'data' => [
                'amount' => $amount,
                'currency' => $currencyCode,
                'formatted' => $formatted
            ]
        ], Response::HTTP_OK);
    }

    /**
     * Met à jour les taux de change (Admin ou Cron)
     *
     * @return JsonResponse
     */
    #[Route('/update-rates', name: 'update_rates', methods: ['POST'])]
    public function updateRates(): JsonResponse
    {
        try {
            $this->currencyService->updateExchangeRates();

            return $this->json([
                'success' => true,
                'message' => 'Taux de change mis à jour avec succès'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la mise à jour des taux: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Récupère la devise par défaut de l'application
     *
     * @return JsonResponse
     */
    #[Route('/default', name: 'default', methods: ['GET'])]
    public function getDefault(): JsonResponse
    {
        $defaultCode = $this->currencyService->getDefaultCurrency();
        $currency = $this->currencyService->getCurrency($defaultCode);

        return $this->json([
            'success' => true,
            'data' => [
                'code' => $defaultCode,
                'currency' => $currency
            ]
        ], Response::HTTP_OK, [], ['groups' => ['currency:read']]);
    }

    /**
     * Convertit plusieurs montants en une seule requête (batch)
     *
     * Body JSON:
     * {
     *   "conversions": [
     *     {"amount": 100, "from": "EUR", "to": "XAF"},
     *     {"amount": 50, "from": "USD", "to": "EUR"}
     *   ]
     * }
     *
     * @return JsonResponse
     */
    #[Route('/convert-batch', name: 'convert_batch', methods: ['POST'])]
    public function convertBatch(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['conversions']) || !is_array($data['conversions'])) {
            return $this->json([
                'success' => false,
                'error' => 'Le paramètre "conversions" (array) est obligatoire'
            ], Response::HTTP_BAD_REQUEST);
        }

        $results = [];
        $errors = [];

        foreach ($data['conversions'] as $index => $conversion) {
            if (!isset($conversion['amount'], $conversion['from'], $conversion['to'])) {
                $errors[] = "Conversion #{$index}: paramètres manquants (amount, from, to)";
                continue;
            }

            try {
                $amount = (float) $conversion['amount'];
                $from = strtoupper($conversion['from']);
                $to = strtoupper($conversion['to']);

                if (!$this->currencyService->isSupported($from) || !$this->currencyService->isSupported($to)) {
                    $errors[] = "Conversion #{$index}: devise non supportée";
                    continue;
                }

                $results[] = $this->currencyService->getConversionInfo($amount, $from, $to);

            } catch (\Exception $e) {
                $errors[] = "Conversion #{$index}: " . $e->getMessage();
            }
        }

        return $this->json([
            'success' => count($errors) === 0,
            'data' => $results,
            'errors' => $errors
        ], Response::HTTP_OK);
    }
}
