<?php

namespace Payflex\Gateway\Helper;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as CronScheduleCollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\Dir as ModuleDir;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Diagnostics / support-page helper: environment details, credential checks,
 * pending-order overview and update-availability against the GitHub release API.
 */
class Support extends AbstractHelper
{
    /** @var Communication */
    private $communication;

    /** @var array|null */
    private $limits;

    /** @var OrderCollectionFactory */
    private $orderCollectionFactory;

    /** @var Configuration */
    private $configuration;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var ProductMetadataInterface */
    private $productMetadata;

    /** @var ModuleListInterface */
    private $moduleList;

    /** @var OrderFactory */
    private $orderFactory;

    /** @var CronScheduleCollectionFactory */
    private $cronScheduleCollectionFactory;

    /** @var DirectoryList */
    private $directoryList;

    /** @var ModuleDir */
    private $moduleDir;

    /** @var \DateTimeZone */
    private $timezone;

    /** @var string|null */
    private $github_latest_version;

    public function __construct(
        Context $context,
        OrderCollectionFactory $orderCollectionFactory,
        Communication $communication,
        Configuration $configuration,
        StoreManagerInterface $storeManager,
        ProductMetadataInterface $productMetadata,
        ModuleListInterface $moduleList,
        OrderFactory $orderFactory,
        CronScheduleCollectionFactory $cronScheduleCollectionFactory,
        DirectoryList $directoryList,
        ModuleDir $moduleDir,
        TimezoneInterface $localeDate
    ) {
        parent::__construct($context);
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->communication = $communication;
        $this->configuration = $configuration;
        $this->storeManager = $storeManager;
        $this->productMetadata = $productMetadata;
        $this->moduleList = $moduleList;
        $this->orderFactory = $orderFactory;
        $this->cronScheduleCollectionFactory = $cronScheduleCollectionFactory;
        $this->directoryList = $directoryList;
        $this->moduleDir = $moduleDir;
        $this->timezone = new \DateTimeZone($localeDate->getConfigTimezone());
    }

    public function getMagentoVersion()
    {
        return $this->productMetadata->getVersion();
    }

    public function getModuleVersion()
    {
        return $this->moduleList->getOne('Payflex_Gateway')['setup_version'];
    }

    public function getPHPVersion()
    {
        $version = phpversion();
        $parts = explode('.', $version);

        if (count($parts) > 2) {
            return $parts[0] . '.' . $parts[1] . '<span style="color: lightgray;">.' . implode('.', array_slice($parts, 2)) . '</span>';
        }

        return $version;
    }

    public function checkValidPayflexCredentials()
    {
        return true;
    }

    public function getAccessToken()
    {
        $accessToken = $this->communication->getAccessToken();
        if ($accessToken == null) {
            $this->communication->forceRefeshToken();
            $accessToken = $this->communication->getAccessToken();
        }

        if ($accessToken == null) {
            return false;
        }

        return $this->communication->getAccessToken();
    }

    public function getFormattedPayflexEnvironment()
    {
        $environment = $this->communication->getEnvironments();
        $current_env = $this->communication->getCurrentEnvironment('key');

        $current_env_text = '';

        // Format array into html string
        $html = "<div class='environment-table'>";
        foreach ($environment as $env => $data) {
            if ($env == $current_env) {
                $current_env_text = ' (current)';
            }
            $html .= "<div class='environment-row'>";
            $html .= "<div class='env-detail-row'>";
            $html .= "<span class='env-header'>Environment</span>";
            $html .= "<span class='env-detail'>: $env $current_env_text</span>";
            $html .= "</div>";
            foreach ($data as $key => $value) {
                $html .= "<div class='env-detail-row'>";
                $html .= "<span class='env-header'>$key</span>";
                $html .= "<span class='env-detail'>: $value</span>";
                $html .= "</div>";
            }
            $html .= "</div class='environment-row'>";
            $current_env_text = '';
        }
        $html .= "</div>";

        return $html;
    }

    public function validAccessToken()
    {
        $accessToken = $this->communication->getAccessToken();
        if ($accessToken == null || $accessToken == "") {
            return false;
        }

        return true;
    }

    /**
     * Pending Payflex orders for the support page.
     *
     * @param string|null $year Optional year to filter orders (YYYY format)
     * @param string|null $month Optional month to filter orders (1-12)
     * @param bool $onlyPending If true, only show pending orders. If false, show all orders.
     * @return array
     */
    public function getPendingOrders($year = null, $month = null, $onlyPending = true)
    {
        $pMethod = 'payflex_gateway';
        $orders = [];

        // Set date range based on parameters
        if ($year && $month) {
            $orderFromDateTime = date("Y-m-d H:i:s", strtotime($year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01 00:00:00'));
            $orderToDateTime = date("Y-m-d H:i:s", strtotime('last day of ' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . ' 23:59:59'));
        } else {
            // Default to last 24 hours
            $orderFromDateTime = date("Y-m-d H:i:s", strtotime('-24 hours'));
            $orderToDateTime = date("Y-m-d H:i:s", strtotime('0 minutes'));
        }

        $ocf = $this->orderCollectionFactory->create();
        $ocf->addAttributeToSelect('entity_id');
        $ocf->addAttributeToSelect('increment_id');
        $ocf->addAttributeToSelect('store_id');

        // Only filter by pending_payment status if onlyPending is true
        if ($onlyPending) {
            $ocf->addAttributeToFilter('status', ['eq' => 'pending_payment']);
        }

        $ocf->addFieldToFilter('created_at', ['from' => $orderFromDateTime, 'to' => $orderToDateTime]);
        $ocf->getSelect()
            ->join(
                ["sop" => "sales_order_payment"],
                'main_table.entity_id = sop.parent_id',
                ['method']
            )
            ->where('sop.method = ?', $pMethod);
        $ocf->setOrder('increment_id', 'desc');

        foreach ($ocf->getData() as $value) {
            $current_order = $this->orderFactory->create()->loadByIncrementId($value['increment_id']);
            $id = $value['increment_id'];

            $orders[$id]['order_status'] = $current_order->getStatus();
            $orders[$id]['order_id'] = $value['increment_id'];
            $orders[$id]['entity_id'] = $value['entity_id'];
            $orders[$id]['store_id'] = $value['store_id'];

            $order = $this->orderFactory->create()->load($value['entity_id']);
            $order_date = $order->getCreatedAt();
            $orders[$id]['order_date'] = $order_date;

            $cron_check_time = date("Y-m-d H:i:s", strtotime('+30 minutes', strtotime($order_date)));

            // Human-readable time until (or since) the cron would check this order.
            $now            = new \DateTime('now', $this->timezone);
            $checkTime      = new \DateTime($cron_check_time);
            $interval       = $now->diff($checkTime);

            if ($checkTime > $now) {
                $cron_till_time = "Cron will start checking in " . $interval->format('%i minute(s)');
            } else {
                $hoursDiff = $interval->h + ($interval->days * 24);
                if ($hoursDiff >= 1 || $interval->days > 0) {
                    $cron_till_time = "Should have been checked " . $this->get_date_diff($checkTime->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')) . " ago";
                } else {
                    $cron_till_time = "Currently in queue";
                }
            }

            $orders[$id]['cron_check_time'] = $cron_check_time;
            $orders[$id]['cron_till_time'] = $cron_till_time;

            $orders[$id]['order_link'] = $this->storeManager->getStore($value['store_id'])->getBaseUrl()
                . "admin/sales/order/view/order_id/" . $value['entity_id'];
        }
        return $orders;
    }

    public function formatPrice($price)
    {
        if (!is_numeric($price)) {
            return $price;
        }

        $storeCurrency = $this->storeManager->getStore()->getCurrentCurrency();
        $symbol = $storeCurrency->getCurrencySymbol() ?: $storeCurrency->getCode();

        return $symbol . number_format($price, 2);
    }

    public function getPayflexLimits($field = null, $fallback_value = null)
    {
        if ($this->limits) {
            if ($field) {
                return $this->limits[$field] ?? $fallback_value;
            }
            return $this->limits;
        }

        $storeId      = $this->storeManager->getStore()->getId();
        $limits       = $this->communication->getMerchantConfiguration($storeId);
        $this->limits = $limits;

        if ($field) {
            return $limits[$field] ?? $fallback_value;
        }

        return $limits;
    }

    public function numberOfStores()
    {
        return count($this->storeManager->getStores());
    }

    /**
     * Human-readable time since the Payflex order-status cron last succeeded.
     */
    public function getLastCronRanTime()
    {
        $cron_schedule = $this->cronScheduleCollectionFactory->create();
        $cron_schedule->addFieldToFilter('job_code', 'payflex_gateway_check_order_status');
        $cron_schedule->addFieldToFilter('status', 'success');
        $cron_schedule->setOrder('executed_at', 'desc');
        $cron_schedule->setPageSize(1);
        $cron_schedule->setCurPage(1);
        $cron_schedule->load();
        $last_cron_time = '';
        foreach ($cron_schedule as $cron) {
            $last_cron_time = $cron->getExecutedAt();
        }

        if ($last_cron_time == '') {
            return "Cron has not been run yet";
        }

        $now       = new \DateTime('now');
        $checkTime = new \DateTime($last_cron_time);

        return $this->get_date_diff($checkTime->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')) . " ago";
    }

    /**
     * Get the difference between 2 dates in human readable format
     */
    private function get_date_diff($time1, $time2, $precision = 2)
    {
        if (!is_int($time1)) {
            $time1 = strtotime($time1);
        }
        if (!is_int($time2)) {
            $time2 = strtotime($time2);
        }

        if ($time1 > $time2) {
            [$time1, $time2] = [$time2, $time1];
        }

        $intervals = ['year', 'month', 'day', 'hour', 'minute', 'second'];
        $diffs = [];

        foreach ($intervals as $interval) {
            $ttime = strtotime('+1 ' . $interval, $time1);
            $add = 1;
            $looped = 0;
            while ($time2 >= $ttime) {
                $add++;
                $ttime = strtotime("+" . $add . " " . $interval, $time1);
                $looped++;
            }

            $time1 = strtotime("+" . $looped . " " . $interval, $time1);
            $diffs[$interval] = $looped;
        }

        $count = 0;
        $times = [];
        foreach ($diffs as $interval => $value) {
            if ($count >= $precision) {
                break;
            }
            if ($value > 0) {
                if ($value != 1) {
                    $interval .= "s";
                }
                $times[] = $value . " " . $interval;
                $count++;
            }
        }

        return implode(", ", $times);
    }

    public function get_github_version()
    {
        if ($this->github_latest_version) {
            return $this->github_latest_version;
        }

        $url = "https://api.github.com/repos/PayflexSA/payflex-magento-2-4-module/releases/latest";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3');
        $output = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($output, true);

        $this->github_latest_version = $data['tag_name'] ?? null;
        return $this->github_latest_version;
    }

    public function get_github_version_comparison($current_version)
    {
        $latest_version = $this->get_github_version();
        if (!$latest_version) {
            return "Unable to fetch latest version";
        }

        // Strip 'v' prefix if it exists for comparison
        $current_version = ltrim($current_version, 'v');
        $latest_version  = ltrim($latest_version, 'v');

        if (version_compare($current_version, $latest_version, '<')) {
            return "Update available: " . "<a href='" . $this->get_github_latest_link() . "' target='_blank'>v" . $latest_version . "</a>";
        } elseif (version_compare($current_version, $latest_version, '>')) {
            return "You are ahead of the latest release: v" . $latest_version;
        } else {
            return "You are on the latest version";
        }
    }

    public function get_github_latest_link()
    {
        return "https://github.com/PayflexSA/payflex-magento-2-4-module/releases/latest";
    }

    public function get_payflex_install_type()
    {
        $magento_base_path = $this->directoryList->getRoot();

        // The active/registered module path (whichever copy won registration).
        // For a Composer install this resolves under vendor/, not app/code.
        $modulePath = $this->moduleDir->getDir('Payflex_Gateway');

        $isComposerInstall = strpos($modulePath, 'vendor/payflex') !== false;

        $moduleRelPath = str_replace($magento_base_path, '', $modulePath);
        $install_directory = ' (' . $moduleRelPath . ')';

        // Detect a duplicate install: the module physically present in BOTH
        // vendor/ (Composer) and app/code/ (manual). Only one is ever registered
        // (registration.php guards against double-registration), so we check the
        // filesystem directly rather than relying on the single registered path.
        $appCodeExists = file_exists($magento_base_path . '/app/code/Payflex/Gateway/registration.php');
        $vendorExists  = file_exists($magento_base_path . '/vendor/payflex/magento2-gateway/registration.php');

        if ($appCodeExists && $vendorExists) {
            return '<span style="color:#e02b27;font-weight:600;">⚠ Duplicate install detected</span> — active: '
                . ($isComposerInstall ? 'Composer' : 'Manual') . $install_directory
                . '. Both <code>vendor/payflex/magento2-gateway</code> and <code>app/code/Payflex/Gateway</code> are present; remove the inactive copy to avoid load conflicts.';
        }

        return $isComposerInstall ? 'Composer' . $install_directory : 'Manual' . $install_directory;
    }

    /**
     * Run the module's unit-test suite and return a structured, render-ready
     * result for the admin Support page.
     *
     * It shells out to the same standalone runner used on the command line
     * (run-tests.php) but asks PHPUnit for a JUnit XML log instead of console
     * output, then parses that into a per-test pass/fail breakdown. Running the
     * suite in a separate CLI process keeps it isolated from the web request's
     * bootstrap and avoids any PHPUnit/Magento state bleeding into admin.
     *
     * The shape returned is always the same so the template can render it
     * unconditionally:
     *   [
     *     'available' => bool,     // false when the suite could not be run at all
     *     'passed'    => bool,     // true only when every test passed
     *     'summary'   => string,   // one-line human summary (or the error reason)
     *     'counts'    => ['total'=>int,'passed'=>int,'failed'=>int,'skipped'=>int],
     *     'suites'    => [ ['name'=>string, 'tests'=>[ ['name','description','status','message','time'] ]] ],
     *     'exit_code' => int|null,
     *   ]
     *
     * @return array
     */
    public function runUnitTests()
    {
        $moduleDir = rtrim($this->moduleDir->getDir('Payflex_Gateway'), '/');
        $runner    = $moduleDir . '/run-tests.php';

        if (!is_file($runner)) {
            return $this->testRunError('The test runner was not found at ' . $runner . '.');
        }
        if (!function_exists('proc_open')) {
            return $this->testRunError(
                'PHP proc_open() is disabled on this server, so the tests cannot be run from the browser. '
                . 'Run "php ' . $runner . '" from the command line instead.'
            );
        }

        $php = $this->resolvePhpCliBinary();
        if ($php === null) {
            return $this->testRunError(
                'Could not locate the PHP CLI binary on this server. '
                . 'Run "php ' . $runner . '" from the command line instead.'
            );
        }

        $junit = tempnam(sys_get_temp_dir(), 'payflex-junit-');
        if ($junit === false) {
            return $this->testRunError('Could not create a temporary file for the test results.');
        }

        // --log-junit gives machine-readable results; --no-output keeps the child
        // quiet (both also suppress the runner's default --testdox reporter).
        $command = escapeshellarg($php)
            . ' -d memory_limit=-1 '
            . escapeshellarg($runner)
            . ' --log-junit ' . escapeshellarg($junit)
            . ' --no-output';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $stderr   = '';
        $exitCode = null;
        $process  = @proc_open($command, $descriptors, $pipes, $this->directoryList->getRoot());

        if (!is_resource($process)) {
            @unlink($junit);
            return $this->testRunError('The test process could not be started on this server.');
        }

        fclose($pipes[0]);
        // Drain both streams so the child never blocks on a full pipe; stdout is
        // not needed (results come from the JUnit file), stderr is kept for
        // diagnostics if the run fails.
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $xml = is_file($junit) ? (string) @file_get_contents($junit) : '';
        @unlink($junit);

        if ($xml === '') {
            $detail = trim($stderr) !== '' ? ' Details: ' . trim($stderr) : '';
            return $this->testRunError('The tests ran but produced no readable results.' . $detail);
        }

        return $this->parseJunit($xml, $exitCode, $stderr);
    }

    /**
     * Turn PHPUnit's JUnit XML into the structured result described on
     * runUnitTests(). Testcases are grouped by their (shortened) class name and
     * each is given a plain-English description derived from its method name.
     *
     * @param string   $xml
     * @param int|null $exitCode
     * @param string   $stderr
     * @return array
     */
    private function parseJunit($xml, $exitCode, $stderr)
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $this->testRunError('The test results could not be parsed.');
        }

        $suites = [];
        $counts = ['total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($dom->getElementsByTagName('testcase') as $case) {
            $method     = $case->getAttribute('name');
            $shortClass = $this->shortTestClass($case->getAttribute('class'));

            $status  = 'passed';
            $message = '';
            foreach (['error', 'failure', 'skipped'] as $type) {
                $nodes = $case->getElementsByTagName($type);
                if ($nodes->length > 0) {
                    $status  = $type === 'skipped' ? 'skipped' : 'failed';
                    $message = trim($nodes->item(0)->textContent);
                    break;
                }
            }

            $counts['total']++;
            $counts[$status]++;

            if (!isset($suites[$shortClass])) {
                $suites[$shortClass] = ['name' => $shortClass, 'tests' => []];
            }
            $suites[$shortClass]['tests'][] = [
                'name'        => $method,
                'description' => $this->humanizeTestName($method),
                'status'      => $status,
                'message'     => $message,
                'time'        => (int) round(((float) $case->getAttribute('time')) * 1000),
            ];
        }

        if ($counts['total'] === 0) {
            $detail = trim($stderr) !== '' ? ' Details: ' . trim($stderr) : '';
            return $this->testRunError('No tests were discovered.' . $detail);
        }

        $summary = sprintf(
            '%d passed, %d failed%s (%d total)',
            $counts['passed'],
            $counts['failed'],
            $counts['skipped'] > 0 ? ', ' . $counts['skipped'] . ' skipped' : '',
            $counts['total']
        );

        return [
            'available' => true,
            'passed'    => $counts['failed'] === 0,
            'summary'   => $summary,
            'counts'    => $counts,
            'suites'    => array_values($suites),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * "Payflex\Gateway\Test\Unit\Helper\ConfigurationTest" => "Configuration".
     *
     * @param string $class
     * @return string
     */
    private function shortTestClass($class)
    {
        $parts = explode('\\', (string) $class);
        $short = (string) end($parts);
        $short = preg_replace('/Test$/', '', $short);
        return $short === '' ? (string) $class : $short;
    }

    /**
     * Derive a readable sentence from a test method name, mirroring PHPUnit's
     * own "testdox" formatting so the browser readout matches the CLI output.
     * e.g. "testCreateTransactionRejectsWhenNotApproved"
     *        => "Create transaction rejects when not approved".
     *
     * @param string $method
     * @return string
     */
    private function humanizeTestName($method)
    {
        $name = preg_replace('/^test/', '', (string) $method);
        $name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name);
        $name = preg_replace('/(?<=[A-Za-z])(?=[0-9])/', ' ', $name);
        $name = trim(str_replace('_', ' ', $name));
        // Match PHPUnit's testdox casing (sentence case) so the browser readout
        // reads identically to the CLI "--testdox" output.
        return $name === '' ? (string) $method : ucfirst(strtolower($name));
    }

    /**
     * Locate a PHP *CLI* binary usable for the test subprocess. Under php-fpm or
     * Apache the running SAPI binary (PHP_BINARY) is not the CLI interpreter, so
     * those are skipped in favour of a real "php" executable.
     *
     * @return string|null
     */
    private function resolvePhpCliBinary()
    {
        $candidates = [];

        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $base = basename(PHP_BINARY);
            if (strpos($base, 'fpm') === false && strpos($base, 'apache') === false) {
                $candidates[] = PHP_BINARY;
            }
        }
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $candidates[] = PHP_BINDIR . '/php';
        }
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && @is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Uniform "could not run" result so the template renders errors the same way
     * as a normal (but failing) run.
     *
     * @param string $message
     * @return array
     */
    private function testRunError($message)
    {
        return [
            'available' => false,
            'passed'    => false,
            'summary'   => $message,
            'counts'    => ['total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0],
            'suites'    => [],
            'exit_code' => null,
        ];
    }
}
