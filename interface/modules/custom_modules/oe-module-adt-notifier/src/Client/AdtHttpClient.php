<?php

/**
 * HTTP client for delivering ADT HL7v2 messages to an external endpoint.
 *
 * Acquires a bearer token from Microsoft Entra via the OAuth2
 * client_credentials grant (caching it for the life of the token) and POSTs the
 * raw HL7 message wrapped as {"message": "..."}. Delivery is best-effort:
 * callers use this from a fire-and-forget context and failures are logged,
 * never thrown.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AdtNotifier\Client;

use Monolog\Logger;
use OpenEMR\Common\Http\oeHttp;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\AdtNotifier\GlobalConfig;
use Psr\Log\LoggerInterface;

class AdtHttpClient
{
    /**
     * Short timeouts keep a slow/unreachable endpoint from stalling the
     * clinical save that triggered the notification.
     */
    private const CONNECT_TIMEOUT_SECONDS = 4;
    private const REQUEST_TIMEOUT_SECONDS = 8;

    /**
     * Treat a token as expired this many seconds early to avoid using one that
     * lapses mid-flight.
     */
    private const TOKEN_EXPIRY_SKEW_SECONDS = 30;

    /**
     * In-process token cache keyed by token URL + client id.
     *
     * @var array<string, array{token: string, expires_at: int}>
     */
    private static array $tokenCache = [];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        // When debug logging is enabled, force a debug-level logger so the
        // trace lines emit regardless of the global system_error_logging level.
        $this->logger = $logger ?? new SystemLogger(
            $config->isDebugLoggingEnabled() ? Logger::DEBUG : null
        );
    }

    /**
     * Deliver a single HL7v2 message. Returns true on a 2xx response.
     */
    public function sendMessage(string $hl7Message): bool
    {
        $url = $this->config->getApiUrl();
        $payload = ['message' => $hl7Message];

        try {
            $token = $this->getAccessToken();
            if ($token === '') {
                $this->logger->error('ADT notifier: no access token, message not sent');
                return false;
            }

            // Log the outgoing request (token masked) so a rejected message can
            // be inspected end to end.
            $this->logger->debug('ADT notifier: sending ADT request', [
                'url' => $url,
                'authorization' => 'Bearer ' . $this->maskSecret($token),
                'request_body' => $payload,
            ]);

            $response = oeHttp::usingHeaders(['Authorization' => 'Bearer ' . $token])
                ->setOptions([
                    'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                    'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                ])
                ->asJson()
                ->post($url, $payload);

            $status = $response->status();
            $responseBody = $response->body();

            $this->logger->debug('ADT notifier: received ADT response', [
                'status' => $status,
                'response_headers' => $response->headers(),
                'response_body' => $responseBody,
            ]);

            if ($status >= 200 && $status < 300) {
                return true;
            }

            $this->logger->error('ADT notifier: endpoint returned non-success status', [
                'url' => $url,
                'status' => $status,
                'request_body' => $payload,
                'response_body' => $responseBody,
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('ADT notifier: failed to deliver message', [
                'url' => $url,
                'request_body' => $payload,
                'exception' => $e,
            ]);
            return false;
        }
    }

    private function getAccessToken(): string
    {
        $cacheKey = $this->config->getTokenUrl() . '|' . $this->config->getClientId();
        $cached = self::$tokenCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires_at'] > time()) {
            return $cached['token'];
        }

        $params = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->config->getClientId(),
            'client_secret' => $this->config->getClientSecret(),
        ];
        $scope = $this->config->getScope();
        if ($scope !== '') {
            $params['scope'] = $scope;
        }

        $this->logger->debug('ADT notifier: requesting Entra token', [
            'token_url' => $this->config->getTokenUrl(),
            'client_id' => $this->config->getClientId(),
            'scope' => $scope,
            'grant_type' => 'client_credentials',
            'client_secret' => $this->maskSecret($this->config->getClientSecret()),
        ]);

        $response = oeHttp::setOptions([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::REQUEST_TIMEOUT_SECONDS,
        ])
            ->asFormParams()
            ->post($this->config->getTokenUrl(), $params);

        if ($response->status() < 200 || $response->status() >= 300) {
            $this->logger->error('ADT notifier: token request failed', [
                'status' => $response->status(),
                'response_body' => $response->body(),
            ]);
            return '';
        }

        $body = $response->json();
        if (!is_array($body) || empty($body['access_token']) || !is_string($body['access_token'])) {
            $this->logger->error('ADT notifier: token response missing access_token');
            return '';
        }

        $token = $body['access_token'];
        $expiresIn = (isset($body['expires_in']) && is_numeric($body['expires_in']))
            ? (int) $body['expires_in']
            : 300;

        self::$tokenCache[$cacheKey] = [
            'token' => $token,
            'expires_at' => time() + max(0, $expiresIn - self::TOKEN_EXPIRY_SKEW_SECONDS),
        ];

        return $token;
    }

    /**
     * Reduce a secret to a non-reversible fingerprint for logs: first/last few
     * characters plus length, never the full value.
     */
    private function maskSecret(string $secret): string
    {
        $length = strlen($secret);
        if ($length === 0) {
            return '(empty)';
        }
        if ($length <= 8) {
            return '***(len=' . $length . ')';
        }

        return substr($secret, 0, 4) . '...' . substr($secret, -4) . '(len=' . $length . ')';
    }
}
