<?php
final class SumUpApiException extends RuntimeException
{
}

function sumUpSettings(): array
{
    $config = appConfig();
    $apiKey = trim((string)($config['SUMUP_API_KEY'] ?? ''));
    $merchantCode = trim((string)($config['SUMUP_MERCHANT_CODE'] ?? ''));
    $currency = strtoupper(trim((string)($config['SUMUP_CURRENCY'] ?? 'EUR')));

    if ($apiKey === '' || $merchantCode === '') {
        throw new SumUpApiException('Configurazione SumUp incompleta.');
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new SumUpApiException('Valuta SumUp non valida.');
    }
    if (!function_exists('curl_init')) {
        throw new SumUpApiException('Estensione PHP cURL non disponibile.');
    }

    return [
        'api_key' => $apiKey,
        'merchant_code' => $merchantCode,
        'currency' => $currency,
    ];
}

function sumUpApiRequest(string $method, string $path, ?array $payload = null, bool $allowEmptyResponse = false): array
{
    $settings = sumUpSettings();
    $curl = curl_init('https://api.sumup.com' . $path);
    if ($curl === false) {
        throw new SumUpApiException('Impossibile inizializzare la connessione a SumUp.');
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $settings['api_key'],
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($payload !== null) {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            curl_close($curl);
            throw new SumUpApiException('Impossibile preparare la richiesta SumUp.');
        }
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $encodedPayload;
    }

    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('SumUp connection error: ' . $curlError);
        throw new SumUpApiException('SumUp non è raggiungibile. Riprova tra poco.');
    }

    if ($statusCode >= 200 && $statusCode < 300 && $allowEmptyResponse && trim((string)$responseBody) === '') {
        return [];
    }

    $response = json_decode((string)$responseBody, true);
    if ($statusCode < 200 || $statusCode >= 300 || !is_array($response)) {
        error_log('SumUp API error. HTTP status: ' . $statusCode);
        throw new SumUpApiException('SumUp non ha accettato la richiesta. Riprova o scegli il pagamento in studio.');
    }

    return $response;
}

function createSumUpCheckout(float $amount, string $reference, string $description): array
{
    $settings = sumUpSettings();
    $checkout = sumUpApiRequest('POST', '/v0.1/checkouts', [
        'checkout_reference' => $reference,
        'amount' => round($amount, 2),
        'currency' => $settings['currency'],
        'merchant_code' => $settings['merchant_code'],
        'description' => $description,
    ]);

    if (!isset($checkout['id']) || trim((string)$checkout['id']) === '') {
        throw new SumUpApiException('SumUp non ha restituito un checkout valido.');
    }

    return $checkout;
}

function retrieveSumUpCheckout(string $checkoutId): array
{
    if (!preg_match('/^[A-Za-z0-9-]{10,100}$/', $checkoutId)) {
        throw new SumUpApiException('Identificativo checkout non valido.');
    }

    return sumUpApiRequest('GET', '/v0.1/checkouts/' . rawurlencode($checkoutId));
}

function deactivateSumUpCheckout(string $checkoutId): array
{
    if (!preg_match('/^[A-Za-z0-9-]{10,100}$/', $checkoutId)) {
        throw new SumUpApiException('Identificativo checkout non valido.');
    }

    return sumUpApiRequest('DELETE', '/v0.1/checkouts/' . rawurlencode($checkoutId), null, true);
}
