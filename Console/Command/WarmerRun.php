<?php

namespace Blackbird\CacheWarmer\Console\Command;

use Blackbird\CacheWarmer\Model\Config;
use Blackbird\CacheWarmer\Model\Service\UrlPoolCollector;
use Blackbird\CacheWarmer\Model\Service\WarmerFactory;
use Blackbird\CacheWarmer\Model\Warmer\WarmerOptionsFactory;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\App\State;
use Magento\Framework\Exception\InvalidArgumentException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WarmerRun extends Command
{
    /**
     * @param WarmerFactory $warmerFactory
     * @param WarmerOptionsFactory $warmerOptionsFactory
     * @param StoreManagerInterface $storeManager
     * @param UrlPoolCollector $urlPoolCollector
     * @param Config $config
     * @param State $state
     * @param ClientFactory $clientFactory
     * @param string|null $name
     * @throws LocalizedException
     */
    public function __construct(
        protected WarmerFactory         $warmerFactory,
        protected WarmerOptionsFactory  $warmerOptionsFactory,
        protected StoreManagerInterface $storeManager,
        protected UrlPoolCollector      $urlPoolCollector,
        protected Config                $config,
        protected State                 $state,
        protected ClientFactory         $clientFactory,
        ?string                         $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Initialization of the command.
     * @noinspection PhpMissingReturnTypeInspection
     */
    protected function configure()
    {
        $this->setName('cache:warmer:run');
        $this->addOption('concurrency', "c", InputOption::VALUE_OPTIONAL, 'Number of concurrent requests', 10);
        $this->addOption('type', "t", InputOption::VALUE_OPTIONAL, 'Type of the cache warmer to run');
        $this->addOption('store', "s", InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, "Store code to run the cache warmer");
        $this->addOption(
            'instances',
            "i",
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            "List of instances local ips",
            []
        );
        $this->addOption('basic-auth-username', "bu", InputOption::VALUE_OPTIONAL, "Basic auth username");
        $this->addOption('basic-auth-password', "bp", InputOption::VALUE_OPTIONAL, "Basic auth password");
        $this->addOption(
            'customer-username',
            "cu",
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            "Customer usernames",
            []
        );
        $this->addOption(
            'customer-password',
            "cp",
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            "Customer passwords",
            []
        );
        $this->addOption(
            'switch-store',
            "sw",
            InputOption::VALUE_NONE,
            "Use Controller to Switch store code"
        );
        $this->addOption(
            'disable-not-logged-in-crawl',
            "dn",
            InputOption::VALUE_NONE,
            "Disable not logged in crawl"
        );

        $this->setDescription('Run the warmer');
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws GuzzleException
     * @throws InvalidArgumentException
     * @throws NoSuchEntityException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->state->setAreaCode('adminhtml');
        [$result, $duration] = $this->measureDuration(function () use ($input, $output) {
            $results = [];

            foreach ($this->getStores($input) as $store) {
                [$lastResult, $duration] = $this->measureDuration(function () use ($input, $output, $store) {
                    $warmer = $this->warmerFactory->create([
                        'options' => $this->warmerOptionsFactory->create([
                            'output' => $output,
                            'store' => $store,
                            'concurrency' => $input->getOption('concurrency'),
                            'customerCredentials' => $this->getCustomerCredentials($input),
                            'switchStore' => $input->getOption('switch-store'),
                            'instances' => $input->getOption('instances'),
                            'basicAuthUsername' => $input->getOption('basic-auth-username'),
                            'basicAuthPassword' => $input->getOption('basic-auth-password'),
                            'isNotLoggedInCrawDisable' => $this->config->isNotLoggedInCrawlDisabled($store),
                            'slackWebhookEnabled' => $this->config->isSlackWebhookEnabled($store),
                            'slackWebhookUrl' => $this->config->getSlackWebhookUrl($store)
                        ])
                    ]);

                    return $warmer->warmUrls(
                        $this->urlPoolCollector->collectUrls([$store], $input->getOption('type')
                            ?? $this->config->getDefaultType($store))
                    );
                });
                $output->writeln(sprintf("Warmup of store %s completed in %s", $store->getCode(), $duration));
                foreach ($lastResult as $value) {
                    $results[] = $value;
                }
            }

            return $results;
        });

        $exitCode = $this->getResultExitCode($result);
        if (self::SUCCESS === $exitCode) {
            $output->writeln("Success Warmup completed in $duration");
        } else {
            $output->writeln("Errors, please check urls in logs. Completed in $duration");
        }

        return $exitCode;
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
    protected function getResultExitCode(array $result): int
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
        return ($success) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param InputInterface $input
     * @return array{username: string, password:string}|null
     * @throws InvalidArgumentException
     */
    protected function getCustomerCredentials(InputInterface $input): ?array
    {
        $customerCredentials = [];
        $passwords = $input->getOption('customer-password');
        $usernames = $input->getOption('customer-username');
        if (count($usernames) !== count($passwords)) {
            throw new InvalidArgumentException(__("The number of customer usernames and passwords must be the same"));
        }
        if (empty($usernames)) {
            return null;
        }
        foreach ($usernames as $key => $username) {
            $customerCredentials[] = ['username' => $username, 'password' => $passwords[$key]];
        }
        return $customerCredentials;
    }

    /**
     * @param InputInterface $input
     * @return array
     * @throws NoSuchEntityException
     */
    protected function getStores(InputInterface $input): array
    {
        $storesOptions = $input->getOption('store');
        if (!empty($storesOptions)) {
            $stores = [];
            foreach ($storesOptions as $store) {
                $stores[] = $this->storeManager->getStore($store);
            }
            return $stores;
        }

        return array_filter($this->storeManager->getStores(), function ($store) {
            return $this->config->isEnabled($store);
        });
    }

    /**
     * Measures the execution time of a callback.
     *
     * @param callable $callback
     * @return array [mixed $result, float $duration]
     */
    protected function measureDuration(callable $callback): array
    {
        $start = microtime(true);
        $result = $callback();
        $duration = microtime(true) - $start;
        $duration = gmdate("H:i:s", (int)$duration);
        return [$result, $duration];
    }
}
