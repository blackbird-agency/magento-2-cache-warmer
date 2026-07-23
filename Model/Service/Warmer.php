<?php

namespace Blackbird\CacheWarmer\Model\Service;

use Blackbird\CacheWarmer\Api\WarmerInterface;
use Blackbird\CacheWarmer\Logger\Logger;
use Blackbird\CacheWarmer\Model\Warmer\ClientFactory;
use Blackbird\CacheWarmer\Model\Warmer\WarmerOptions;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\PoolFactory;
use GuzzleHttp\Psr7\RequestFactory;
use GuzzleHttp\TransferStats;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Promise\Utils;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Service for warming URLs in the cache
 */
class Warmer implements WarmerInterface
{
    /**
     * @param ClientFactory         $clientFactory
     * @param RequestFactory        $requestFactory
     * @param PoolFactory           $poolFactory
     * @param UrlInterface          $urlBuilder
     * @param WarmerOptions         $options
     * @param StoreManagerInterface $storeManager
     * @param Logger                $logger
     */
    public function __construct(
        protected ClientFactory $clientFactory,
        protected RequestFactory $requestFactory,
        protected PoolFactory $poolFactory,
        protected UrlInterface $urlBuilder,
        protected WarmerOptions $options,
        protected StoreManagerInterface $storeManager,
        protected Logger $logger
    ) {
    }


    // phpcs:disable

    /**
     * @inheritDoc
     */
    public function warmUrls(array $urls): array
    {
        $urls = array_values($urls);
        $results = [];
        $promises = [];
        $instances = $this->getInstances();
        $customerCredentials = $this->getCustomerCredentials();

        $warmCounter = 0;
        foreach ($instances as $ip) {
            foreach ($customerCredentials as $customerCredential) {

                $client = $this->getCrawlClient($ip, $customerCredential['username'], $customerCredential['password']);

                $results[$warmCounter] = [
                    'urls' => $urls,
                    'statuses' => [],
                    'durations' => [],
                    'total' => count($urls)
                ];

                $durations = &$results[$warmCounter]['durations'];
                $requestUrls = &$results[$warmCounter]['urls'];
                $statuses = &$results[$warmCounter]['statuses'];
                $total = &$results[$warmCounter]['total'];
                $requests = function ($urls) use ($client, &$durations) {
                    foreach ($urls as $index => $url) {
                        yield function () use ($client, $url, $index, &$durations) {
                            return $client->requestAsync("GET", $url, [
                                'on_stats' => function (TransferStats $stats) use (&$durations, $index) {
                                    $durations[$index] = round($stats->getTransferTime() * 1000, 2); // ms
                                }
                            ]);
                        };
                    }
                };

                $pool = $this->poolFactory->create(
                    [
                        'client' => $client,
                        'requests' => $requests($requestUrls),
                        'config' => [
                            'concurrency' => $this->options->getConcurrency(),
                            'fulfilled' => function (ResponseInterface $response, $index) use (&$requestUrls, &$total, &$statuses, &$durations) {
                                $statusCode = $response->getStatusCode();
                                $statuses[$index] = $statusCode;
                                $duration = $durations[$index] ?? "N/A";
                                $url = $requestUrls[$index] ?? "N/A";
                                if ($statusCode >= 200 && $statusCode < 300) {
                                    $this->logMessage(sprintf(
                                        '%s/%s %s success in %s ms with status %d',
                                        $index +1 ,
                                        $total,
                                        $url,
                                        $duration,
                                        $statusCode
                                    ));
                                } elseif ($statusCode < 500) {
                                    $this->logWarning(sprintf(
                                        '%s/%s %s warning in %s ms with status %d',
                                        $index +1 ,
                                        $total,
                                        $url,
                                        $duration,
                                        $statusCode
                                    ));
                                } else {
                                    $this->logError(sprintf(
                                        '%s/%s %s error in %s ms with status %d',
                                        $index +1 ,
                                        $total,
                                        $url,
                                        $duration,
                                        $statusCode
                                    ));
                                }
                            },
                            'rejected' => function ($reason, $index) use (&$requestUrls, &$total, &$statuses, &$durations) {
                                $statuses[$index] = false;
                                $duration = $durations[$index] ?? "N/A";
                                $url = $requestUrls[$index] ?? "N/A";
                                $this->logError(sprintf(
                                    '%s/%s %s failed in %s ms : %s',
                                    $index +1,
                                    $total,
                                    $url,
                                    $duration,
                                    $reason
                                ));
                            },
                        ]
                    ]
                );

                // Initiate the transfers and create a promise
                $promises[] = $pool->promise();
                $warmCounter++;
            }
        }

        // Force the pool of requests to complete
        Utils::all($promises)->wait();

        if($this->options->isSlackWebhookEnabled() && $this->resultsContainsError($results)) {
            $this->sendSlackNotification('Some pages returned an error. Check the logs in var/log/warmer.log for more details.');
        }

        return $results;
    }

    /**
     * @param array<string, array{
     *      urls: string[],
     *      statuses: array<int, int>,
     *      durations: array<int, float>,
     *      total: int
     *  }> $result
     * @return int
     */
    public function resultsContainsError(array $result): int
    {
        $success = true;
        foreach ($result as $poolResult) {
            foreach ($poolResult['statuses'] as $httpCode) {
                if ($httpCode >= 500) {
                    $success = false;
                    break 2;
                }
            }
        }
        return !$success;
    }

    // phpcs:enable


    /**
     * @param  string|null $ip
     * @param  string|null $customerUsername
     * @param  string|null $customerPassword
     * @return Client
     * @throws GuzzleException
     */
    protected function getCrawlClient(
        ?string $ip = null,
        ?string $customerUsername = null,
        ?string $customerPassword = null
    ): Client {
        $cookieJar = new CookieJar();
        if (isset($customerUsername) && isset($customerPassword)) {
            $cookieJar = $this->login($cookieJar, $customerUsername, $customerPassword);
        }

        if ($this->options->isSwitchStoreEnabled()) {
            $cookieJar = $this->switchStore($cookieJar);
        }

        $config = [
            'cookies'         => $cookieJar,
            'http_errors'     => false,
            'allow_redirects' => false,
        ];

        if (isset($ip)) {
            $config['curl'][CURLOPT_RESOLVE] = $this->getDnsResolutionConfig($ip);
        }

        return $this->createClient(
            $config
        );
    }

    /**
     * @param array $config
     * @return Client
     */
    protected function createClient(array $config = []): Client
    {
        $defaultClientConfig = [];
        if ($this->options->isBasicAuthEnabled()) {
            $defaultClientConfig['auth'] = [
                $this->options->getBasicAuthUsername(),
                $this->options->getBasicAuthPassword(),
            ];
        }

        $this->clientFactory->setDefaultConfig($defaultClientConfig);

        return $this->clientFactory->create($config);
    }


    /**
     * @param  string $ip
     * @return array
     */
    protected function getDnsResolutionConfig(string $ip): array
    {
        $resolutions = [];
        foreach ($this->storeManager->getStores() as $store) {
            $parsedUrl     = \parse_url($store->getBaseUrl());
            $port          = ($parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80));
            $host          = $parsedUrl['host'];
            $resolutions[] = sprintf('%s:%s:%s', $host, $port, $ip);
        }

        return array_unique($resolutions);
    }


    /**
     * @param  CookieJar $cookieJar
     * @return CookieJar
     * @throws GuzzleException
     */
    protected function switchStore(CookieJar $cookieJar): CookieJar
    {
        $client    = $this->createClient(['cookies' => $cookieJar]);
        $storeCode = $this->options->getStore()->getCode();

        // Endpoint for switching the store view
        $switchStoreUrl = $this->urlBuilder->getUrl(
            'stores/store/switch',
            [
                '___store' => $this->options->getStore()->getCode(),
                // The store code you want to switch to
            ]
        );

        $switchStoreRequest = $this->requestFactory->create(['method' => 'GET', 'uri' => $switchStoreUrl]);

        try {
            $switchStoreResponse = $client->send($switchStoreRequest);
            $this->logMessage(
                sprintf('Store switch successful to %s %s', $storeCode, $switchStoreResponse->getStatusCode())
            );
        } catch (RequestException $e) {
            $this->logError('Store switch failed: ' . $e->getMessage());
            throw $e;
        }

        return $cookieJar;
    }


    /**
     * @param  CookieJar $cookieJar
     * @param  string    $customerUsername
     * @param  string    $customerPassword
     * @return CookieJar
     * @throws GuzzleException
     */
    protected function login(CookieJar $cookieJar, string $customerUsername, string $customerPassword): CookieJar
    {
        $client = $this->createClient(['cookies' => $cookieJar]);
        // Authenticate the user (example: login request)
        $loginUrl  = $this->urlBuilder->getUrl('customer/account/loginPost/');
        $loginData = [
            'form_key'        => $this->retrieveFormKey($client),
            // Retrieve this from the Magento site
            'login[username]' => $customerUsername,
            'login[password]' => $customerPassword,
        ];

        $loginRequest = $this->requestFactory->create(
            [
                'method'  => 'POST',
                'uri'     => $loginUrl,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body'    => http_build_query($loginData),
            ]
        );

        try {
            $loginResponse = $client->send($loginRequest);
            $this->logMessage(
                sprintf(
                    'Login successful : %s %s',
                    $customerUsername,
                    $loginResponse->getStatusCode()
                )
            );
        } catch (RequestException $e) {
            $this->logError('Login failed: ' . $e->getMessage());
            throw $e;
        }

        return $cookieJar;
    }


    /**
     * @param  string $message
     * @return void
     */
    protected function logMessage(string $message): void
    {
        $this->options?->getOutput()?->writeln($message, OutputInterface::VERBOSITY_VERBOSE);
        $this->logger->info($message);
    }


    /**
     * @param  string $message
     * @return void
     */
    protected function logVerbose(string $message): void
    {
        $coloredMessage = sprintf('<info>%s</info>', $message);
        $this->options?->getOutput()?->writeln($coloredMessage, OutputInterface::VERBOSITY_VERY_VERBOSE);
        $this->logger->debug($message);
    }


    /**
     * @param  string $message
     * @return void
     */
    protected function logError(string $message): void
    {
        $coloredMessage = sprintf('<error>%s</error>', $message);
        $this->options?->getOutput()?->writeln($coloredMessage, OutputInterface::VERBOSITY_QUIET);
        $this->logger->error($message);
    }


    /**
     * Send a notification to Slack
     *
     * @param  string $errorMessage
     * @return void
     */
    protected function sendSlackNotification(string $errorMessage): void
    {
        $webhookUrl = $this->options->getSlackWebhookUrl();
        if (empty($webhookUrl)) {
            return;
        }

        try {
            $client    = $this->clientFactory->create();
            $storeInfo = sprintf("%s ( %s )",$this->options->getStore()->getCode(), $this->options->getStore()->getBaseUrl());

            $payload = json_encode(
                [
                    'text' => sprintf("*Cache Warmer Error*\nStore: %s\nError: %s", $storeInfo, $errorMessage),
                ]
            );

            $client->post(
                $webhookUrl,
                [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => $payload,
                ]
            );

            $this->logMessage('Slack notification sent successfully');
        } catch (\Exception $e) {
            $this->logWarning('Failed to send Slack notification: ' . $e->getMessage());
        }
    }


    /**
     * @param  string $message
     * @return void
     */
    protected function logWarning(string $message): void
    {
        $coloredMessage = sprintf('<comment>%s</comment>', $message);
        $this->options?->getOutput()?->writeln($coloredMessage, OutputInterface::VERBOSITY_NORMAL);
        $this->logger->warning($message);
    }


    /**
     * @param  Client $client
     * @return string
     * @throws GuzzleException
     */
    protected function retrieveFormKey(Client $client): string
    {
        // Retrieve the form key
        $loginUrl  = $this->urlBuilder->getUrl('customer/account/login');
        $loginForm = $this->requestFactory->create(['method' => 'GET', 'uri' => $loginUrl]);

        try {
            $homepageResponse = $client->send($loginForm);
            $html             = (string) $homepageResponse->getBody();

            // Use a regular expression to extract the form key
            $pattern = '/input name="form_key" type="hidden" value="(.*)" \//';
            preg_match($pattern, $html, $matches);
            $formKey = $matches[1] ?? null;

            $this->logVerbose('Form key retrieved: ' . $formKey);
        } catch (RequestException $e) {
            $this->logError('Failed to retrieve form key: ' . $e->getMessage());
            throw $e;
        }

        return $formKey;
    }


    /**
     * Get customer credentials for authentication
     *
     * If not logged in crawl is not disabled, a null username and password will be included
     * in the list of credentials to allow for guest crawling.
     *
     * @return array<int, array{username: string|null, password: string|null}> List of customer credentials
     */
    protected function getCustomerCredentials(): array
    {
        $customerCredentials = [];
        if (!$this->options->isNotLoggedInCrawlDisabled()) {
            $customerCredentials = [
                0 => [
                    'username' => null,
                    'password' => null,
                ],
            ];
        }

        return array_merge($customerCredentials, $this->options->getCustomerCredentials());
    }


    /**
     * Get instances for warming
     *
     * If no instances are configured, a default instance with null IP will be returned.
     *
     * @return array<int|string, string|null> List of instances, where key is the instance name and value is the IP
     */
    protected function getInstances(): array
    {
        $instances = $this->options->getInstances();
        if (empty($instances)) {
            $instances = [0 => null];
        }

        return $instances;
    }
}
