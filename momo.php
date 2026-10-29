<?php
declare(strict_types=1);

const PREMIUM_CHECKOUT_AMOUNT_XAF = 500;
const PREMIUM_CHECKOUT_TERM_DAYS = 50;

function momo_sandbox_is_configured(): bool
{
    return (getenv('MTN_MOMO_SUBSCRIPTION_KEY') ?: '') !== ''
        && (getenv('MTN_MOMO_API_USER') ?: '') !== ''
        && (getenv('MTN_MOMO_API_KEY') ?: '') !== '';
}

function momo_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function momo_http_request(
    string $method,
    string $path,
    ?array $payload = null,
    ?string $accessToken = null,
    ?string $referenceId = null
): array
{
    $subscriptionKey = trim((string) (getenv('MTN_MOMO_SUBSCRIPTION_KEY') ?: ''));
    $apiUser = trim((string) (getenv('MTN_MOMO_API_USER') ?: ''));
    $apiKey = trim((string) (getenv('MTN_MOMO_API_KEY') ?: ''));
    if ($subscriptionKey === '' || $apiUser === '' || $apiKey === '') {
        throw new RuntimeException('The MTN MoMo sandbox is not configured. Ask the administrator to configure its server credentials.');
    }

    foreach ([$subscriptionKey, $apiUser, $apiKey] as $credential) {
        if (str_contains($credential, "\r") || str_contains($credential, "\n")) {
            throw new RuntimeException('The MTN MoMo sandbox credentials are invalid.');
        }
    }

    $headers = [
        'Accept: application/json',
        'Ocp-Apim-Subscription-Key: ' . $subscriptionKey,
        'X-Target-Environment: sandbox',
    ];
    if ($accessToken === null) {
        $headers[] = 'Authorization: Basic ' . base64_encode($apiUser . ':' . $apiKey);
    } else {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }
    if ($referenceId !== null) {
        $headers[] = 'X-Reference-Id: ' . $referenceId;
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 20,
            'ignore_errors' => true,
            'max_redirects' => 0,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];
    if ($payload !== null) {
        $options['http']['header'] .= "\r\nContent-Type: application/json";
        $options['http']['content'] = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    $responseBody = @file_get_contents(
        'https://sandbox.momodeveloper.mtn.com' . $path,
        false,
        stream_context_create($options)
    );
    $responseHeaders = $http_response_header ?? [];
    if ($responseBody === false) {
        error_log('SkillsPassAurora MTN MoMo sandbox request failed before an HTTP response was received.');
        throw new RuntimeException('The mobile-money provider did not respond. Refresh the payment status before trying again.');
    }

    $httpStatus = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/\AHTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
            $httpStatus = (int) $matches[1];
        }
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        error_log(sprintf('SkillsPassAurora MTN MoMo sandbox returned HTTP %d for %s.', $httpStatus, $path));
        throw new RuntimeException('The mobile-money sandbox rejected the request. Check the sandbox credentials and account setup.', $httpStatus);
    }

    if ($responseBody === '') {
        return [];
    }
    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        error_log(sprintf('SkillsPassAurora MTN MoMo sandbox returned an invalid response for %s.', $path));
        throw new RuntimeException('The mobile-money provider returned an invalid response. Refresh the payment status before trying again.');
    }

    return $decoded;
}

function momo_access_token(): string
{
    static $accessToken = null;
    static $expiresAt = 0;
    if (is_string($accessToken) && $expiresAt > time() + 30) {
        return $accessToken;
    }

    try {
        $response = momo_http_request('POST', '/collection/token/');
    } catch (RuntimeException $exception) {
        if ($exception->getCode() === 0) {
            throw new RuntimeException(
                'The mobile-money sandbox could not be reached. No payment was submitted; try again later.',
                0,
                $exception
            );
        }
        throw $exception;
    }
    $token = $response['access_token'] ?? null;
    $expiresIn = filter_var($response['expires_in'] ?? null, FILTER_VALIDATE_INT);
    if (!is_string($token) || $token === '' || $expiresIn === false || $expiresIn < 1) {
        error_log('SkillsPassAurora MTN MoMo sandbox did not return a valid access token.');
        throw new RuntimeException('The mobile-money sandbox could not authorize this request. Check the sandbox setup.');
    }

    $accessToken = $token;
    $expiresAt = time() + $expiresIn;
    return $accessToken;
}

function momo_start_wallet_topup_request(
    string $referenceId,
    string $externalId,
    string $phoneNumber,
    string $accessToken
): void
{
    momo_start_collection_request(
        PREMIUM_CHECKOUT_AMOUNT_XAF,
        $referenceId,
        $externalId,
        $phoneNumber,
        'SkillsPassAurora wallet top-up',
        'Wallet top-up',
        $accessToken
    );
}

function momo_start_collection_request(
    int $amount,
    string $referenceId,
    string $externalId,
    string $phoneNumber,
    string $payerMessage,
    string $payeeNote,
    string $accessToken
): void
{
    if ($amount < 1) {
        throw new RuntimeException('The mobile-money amount must be positive.');
    }

    momo_http_request(
        'POST',
        '/collection/v1_0/requesttopay',
        [
            'amount' => (string) $amount,
            'currency' => 'XAF',
            'externalId' => $externalId,
            'payer' => [
                'partyIdType' => 'MSISDN',
                'partyId' => $phoneNumber,
            ],
            'payerMessage' => $payerMessage,
            'payeeNote' => $payeeNote,
        ],
        $accessToken,
        $referenceId
    );
}

function momo_read_premium_request(string $referenceId): array
{
    return momo_read_collection_request($referenceId);
}

function momo_read_wallet_topup_request(string $referenceId): array
{
    return momo_read_collection_request($referenceId);
}

function momo_read_collection_request(string $referenceId): array
{
    return momo_http_request(
        'GET',
        '/collection/v1_0/requesttopay/' . rawurlencode($referenceId),
        null,
        momo_access_token()
    );
}

function momo_payment_matches_order(array $providerPayment, array $payment): bool
{
    $providerAmount = $providerPayment['amount'] ?? null;
    $providerCurrency = $providerPayment['currency'] ?? null;
    $providerExternalId = $providerPayment['externalId'] ?? null;
    $storedAmount = (string) ($payment['amount'] ?? '');
    if (is_string($providerAmount) || is_int($providerAmount)) {
        $providerAmount = (string) $providerAmount;
    } else {
        return false;
    }
    $providerAmount = preg_replace('/\.0+\z/', '', $providerAmount);

    return $providerAmount === $storedAmount
        && is_string($providerCurrency)
        && strtoupper($providerCurrency) === (string) ($payment['currency'] ?? '')
        && is_string($providerExternalId)
        && is_string($payment['external_id'] ?? null)
        && hash_equals((string) $payment['external_id'], $providerExternalId);
}
