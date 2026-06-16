<?php

/**
 * HTTP client for delivering ADT HL7v2 messages to an external endpoint.
 *
 * Acquires a bearer token via the OAuth2 client_credentials grant (caching it
 * for the life of the token) and POSTs the raw HL7 message wrapped as
 * {"hl7_message": "..."}. Delivery is best-effort: callers use this from a
 * fire-and-forget context and failures are logged, never thrown.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AdtNotifier\Client;

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
        $this->logger = $logger ?? new SystemLogger();
    }

    /**
     * Deliver a single HL7v2 message. Returns true on a 2xx response.
     */
    public function sendMessage(string $hl7Message): bool
    {
        try {
            $token = $this->getAccessToken();
            if ($token === '') {
                $this->logger->error('ADT notifier: no access token, message not sent');
                return false;
            }

            $response = oeHttp::usingHeaders(['Authorization' => 'Bearer ' . $token])
                ->setOptions([
                    'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                    'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                ])
                ->asJson()
                ->post($this->config->getApiUrl(), ['hl7_message' => $hl7Message]);

            $status = $response->status();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            $this->logger->error('ADT notifier: endpoint returned non-success status', [
                'status' => $status,
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('ADT notifier: failed to deliver message', [
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

        $response = oeHttp::setOptions([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::REQUEST_TIMEOUT_SECONDS,
        ])
            ->asFormParams()
            ->post($this->config->getTokenUrl(), $params);

        if ($response->status() < 200 || $response->status() >= 300) {
            $this->logger->error('ADT notifier: token request failed', [
                'status' => $response->status(),
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
}
