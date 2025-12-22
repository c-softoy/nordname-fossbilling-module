<?php

declare(strict_types=1);

namespace Box\Mod\Nordname;

use FOSSBilling\InjectionAwareInterface;

class Service implements InjectionAwareInterface
{
    private const NORDNAME_WHOLESALE_CURRENCY = 'EUR';

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public static function onBeforeAdminCronRun(\Box_Event $event): bool
    {
        try {
            self::syncNordnameDomainOrders($event->getDi());
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        return true;
    }

    private static function syncNordnameDomainOrders(\Pimple\Container $di): void
    {
        $domainService = $di['mod_service']('servicedomain');
        $orderService = $di['mod_service']('order');

        $syncWhois = new \ReflectionMethod($domainService, 'syncWhois');
        $syncWhois->setAccessible(true);

        $orders = $di['db']->find('ClientOrder', 'status = :status AND service_type = :type', [
            ':status' => \Model_ClientOrder::STATUS_ACTIVE,
            ':type' => 'domain',
        ]);

        foreach ($orders as $order) {
            if (!$order instanceof \Model_ClientOrder) {
                continue;
            }

            try {
                $domain = $orderService->getOrderService($order);
                if (!$domain instanceof \Model_ServiceDomain) {
                    continue;
                }

                if (!self::isNordnameRegistrar($di, $domain)) {
                    continue;
                }

                $syncWhois->invoke($domainService, $domain, $order);
                self::syncNameserversToServiceDomain($di, $domainService, $domain);

                $tldRegistrar = $di['db']->load('TldRegistrar', $domain->tld_registrar_id);
                $config = json_decode($tldRegistrar->config ?? '', true) ?? [];

                $orderUpdated = false;

                if (empty($order->activated_at)) {
                    $order->activated_at = date('Y-m-d H:i:s');
                    $orderUpdated = true;
                }

                $renewalDaysBeforeExpiration = self::getRenewalDaysBeforeExpiration($config);
                if ($renewalDaysBeforeExpiration !== null && !empty($domain->expires_at)) {
                    $order->expires_at = date(
                        'Y-m-d H:i:s',
                        strtotime(sprintf('-%d days', $renewalDaysBeforeExpiration), strtotime((string) $domain->expires_at))
                    );
                    $orderUpdated = true;
                }

                if ($orderUpdated) {
                    $di['db']->store($order);
                }
            } catch (\Exception $e) {
                $di['logger']->error(
                    'NordName WHOIS sync failed for order %s: %s',
                    $order->id ?? 'unknown',
                    $e->getMessage()
                );
            }
        }
    }

    private static function syncNameserversToServiceDomain(
        \Pimple\Container $di,
        $domainService,
        \Model_ServiceDomain $domain
    ): void {
        $whois = null;
        if (!empty($domain->details)) {
            $whois = @unserialize($domain->details, ['allowed_classes' => [\Registrar_Domain::class, \Registrar_Domain_Contact::class]]);
        }

        if (!$whois instanceof \Registrar_Domain) {
            $getD = new \ReflectionMethod($domainService, '_getD');
            $getD->setAccessible(true);
            [$registrarDomain, $adapter] = $getD->invoke($domainService, $domain);
            $whois = $adapter->getDomainDetails($registrarDomain);
        }

        $nameservers = [
            $whois->getNs1() ?: null,
            $whois->getNs2() ?: null,
            $whois->getNs3() ?: null,
            $whois->getNs4() ?: null,
        ];

        $changed = false;
        foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $index => $field) {
            if ($domain->$field !== $nameservers[$index]) {
                $domain->$field = $nameservers[$index];
                $changed = true;
            }
        }

        if ($changed) {
            $domain->updated_at = date('Y-m-d H:i:s');
            $di['db']->store($domain);
        }
    }

    public function fetchTldCatalog(\Model_TldRegistrar $registrar): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $this->assertEurConversionAvailable();
        $pricingContext = $this->getTldPricingContext();

        $domainService = $this->di['mod_service']('servicedomain');
        $adapter = $domainService->registrarGetRegistrarAdapter($registrar);
        if (!$adapter instanceof \Registrar_Adapter_Nordname) {
            throw new \FOSSBilling\Exception('Registrar adapter is not NordName');
        }

        $catalog = [];
        foreach ($adapter->listTldPrices() as $item) {
            $tld = ltrim((string) ($item['tld'] ?? ''), '.');
            if ($tld === '') {
                continue;
            }

            $prices = $item['prices'] ?? [];
            $fossbillingTld = '.' . $tld;
            $existing = $domainService->tldFindOneByTld($fossbillingTld);

            $catalog[] = [
                'tld' => $tld,
                'tld_fossbilling' => $fossbillingTld,
                'exists' => $existing instanceof \Model_Tld,
                'min_years' => 1,
                'wholesale' => $this->buildWholesalePrices($adapter, $prices, 1),
            ];
        }

        usort($catalog, static fn (array $a, array $b): int => strcmp($a['tld'], $b['tld']));

        return array_merge($pricingContext, ['tlds' => $catalog]);
    }

    public function applyMargin(float $base, float $fixed, float $percent): float
    {
        return round($base + $fixed + ($base * $percent / 100), 2);
    }

    public function importTlds(
        \Model_TldRegistrar $registrar,
        array $selectedTlds,
        float $fixedMargin,
        float $percentMargin,
        bool $usePromotional
    ): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $this->assertEurConversionAvailable();

        $domainService = $this->di['mod_service']('servicedomain');
        $adapter = $domainService->registrarGetRegistrarAdapter($registrar);
        if (!$adapter instanceof \Registrar_Adapter_Nordname) {
            throw new \FOSSBilling\Exception('Registrar adapter is not NordName');
        }

        $result = [
            'created' => 0,
            'updated' => 0,
            'failed' => [],
        ];

        foreach ($selectedTlds as $tld) {
            $tld = ltrim((string) $tld, '.');
            if ($tld === '') {
                continue;
            }

            try {
                $info = $adapter->getTldInfo($tld);
                $registrationYears = $info['technical']['registration_years'] ?? [1];
                $minYears = min($registrationYears ?: [1]);
                $prices = $info['prices'] ?? [];

                $registerBase = $this->multiplyPrice($adapter->extractOperationPrice($prices, 'registration', $usePromotional), $minYears);
                $renewBase = $this->multiplyPrice($adapter->extractOperationPrice($prices, 'renewal', $usePromotional), $minYears);
                $transferBase = $adapter->extractOperationPrice($prices, 'transfer', $usePromotional);

                if ($registerBase === null || $renewBase === null || $transferBase === null) {
                    throw new \FOSSBilling\Exception('Incomplete pricing data from NordName for .' . $tld);
                }

                $payload = [
                    'tld' => '.' . $tld,
                    'tld_registrar_id' => $registrar->id,
                    'price_registration' => $this->applySellPrice($registerBase, $fixedMargin, $percentMargin),
                    'price_renew' => $this->applySellPrice($renewBase, $fixedMargin, $percentMargin),
                    'price_transfer' => $this->applySellPrice($transferBase, $fixedMargin, $percentMargin),
                    'min_years' => $minYears,
                    'allow_register' => true,
                    'allow_transfer' => true,
                    'active' => true,
                ];

                $existing = $domainService->tldFindOneByTld('.' . $tld);
                if ($existing instanceof \Model_Tld) {
                    $domainService->tldUpdate($existing, $payload);
                    ++$result['updated'];
                } else {
                    $domainService->tldCreate($payload);
                    ++$result['created'];
                }
            } catch (\Exception $e) {
                $result['failed'][] = [
                    'tld' => $tld,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    public function getTldPricingContext(): array
    {
        $defaultCurrency = $this->getDefaultCurrency();
        if ($defaultCurrency === null) {
            return $this->buildPricingContextPayload(
                sellCurrency: '',
                conversionRequired: true,
                eurToSellRate: null,
                error: 'Default currency is not configured in FOSSBilling.'
            );
        }

        $sellCurrency = $this->getCurrencyCode($defaultCurrency);
        $conversionRequired = strcasecmp($sellCurrency, self::NORDNAME_WHOLESALE_CURRENCY) !== 0;

        if (!$conversionRequired) {
            return $this->buildPricingContextPayload(
                sellCurrency: $sellCurrency,
                conversionRequired: false,
                eurToSellRate: 1.0,
                error: null
            );
        }

        $eurCurrency = $this->getCurrencyByCode(self::NORDNAME_WHOLESALE_CURRENCY);
        if ($eurCurrency === null) {
            return $this->buildPricingContextPayload(
                sellCurrency: $sellCurrency,
                conversionRequired: true,
                eurToSellRate: null,
                error: sprintf(
                    'No exchange rate configured for %s. Add %s under System → Settings → Currency settings and set a conversion rate.',
                    self::NORDNAME_WHOLESALE_CURRENCY,
                    self::NORDNAME_WHOLESALE_CURRENCY
                )
            );
        }

        $eurRate = $this->getCurrencyConversionRate($eurCurrency);
        if ($eurRate === null || $eurRate <= 0) {
            return $this->buildPricingContextPayload(
                sellCurrency: $sellCurrency,
                conversionRequired: true,
                eurToSellRate: null,
                error: sprintf(
                    'The %s exchange rate is missing or zero. Configure it under System → Settings → Currency settings.',
                    self::NORDNAME_WHOLESALE_CURRENCY
                )
            );
        }

        try {
            $eurToSellRate = round($this->getEurToSellRate(), 6);
        } catch (\Exception $e) {
            return $this->buildPricingContextPayload(
                sellCurrency: $sellCurrency,
                conversionRequired: true,
                eurToSellRate: null,
                error: $this->formatCurrencyConversionError($e)
            );
        }

        return $this->buildPricingContextPayload(
            sellCurrency: $sellCurrency,
            conversionRequired: true,
            eurToSellRate: $eurToSellRate,
            error: null
        );
    }

    public function assertEurConversionAvailable(): void
    {
        $context = $this->getTldPricingContext();
        if (!empty($context['error'])) {
            throw new \FOSSBilling\Exception((string) $context['error']);
        }
    }

    public function convertWholesaleToSell(?float $eurAmount): ?float
    {
        if ($eurAmount === null) {
            return null;
        }

        $defaultCurrency = $this->getDefaultCurrency();
        if ($defaultCurrency === null) {
            throw new \FOSSBilling\Exception('Default currency is not configured in FOSSBilling.');
        }

        if (strcasecmp($this->getCurrencyCode($defaultCurrency), self::NORDNAME_WHOLESALE_CURRENCY) === 0) {
            return round($eurAmount, 2);
        }

        try {
            $currencyService = $this->di['mod_service']('currency');
            $converted = $currencyService->toBaseCurrency(self::NORDNAME_WHOLESALE_CURRENCY, $eurAmount);
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception($this->formatCurrencyConversionError($e));
        }

        return round((float) $converted, 2);
    }

    public function getNordnameRegistrars(): array
    {
        $registrars = $this->di['db']->find('TldRegistrar', 'ORDER BY name ASC');
        $result = [];

        foreach ($registrars as $registrar) {
            if (!$registrar instanceof \Model_TldRegistrar) {
                continue;
            }
            if (strcasecmp($registrar->registrar, 'Nordname') !== 0) {
                continue;
            }
            $result[] = [
                'id' => $registrar->id,
                'name' => $registrar->name,
            ];
        }

        return $result;
    }

    private function applySellPrice(?float $eurBase, float $fixedMargin, float $percentMargin): ?float
    {
        $converted = $this->convertWholesaleToSell($eurBase);
        if ($converted === null) {
            return null;
        }

        return $this->applyMargin($converted, $fixedMargin, $percentMargin);
    }

    private function buildPricingContextPayload(
        string $sellCurrency,
        bool $conversionRequired,
        ?float $eurToSellRate,
        ?string $error
    ): array {
        return [
            'wholesale_currency' => self::NORDNAME_WHOLESALE_CURRENCY,
            'sell_currency' => $sellCurrency,
            'conversion_required' => $conversionRequired,
            'eur_to_sell_rate' => $eurToSellRate,
            'currency_settings_url' => $this->di['url']->adminLink('extension/settings/currency'),
            'error' => $error,
        ];
    }

    private function formatCurrencyConversionError(\Exception $e): string
    {
        $message = trim($e->getMessage());
        if ($message !== '') {
            return $message;
        }

        return sprintf(
            'Unable to convert %s wholesale prices. Configure %s under System → Settings → Currency settings.',
            self::NORDNAME_WHOLESALE_CURRENCY,
            self::NORDNAME_WHOLESALE_CURRENCY
        );
    }

    private function getCurrencyService(): object
    {
        return $this->di['mod_service']('currency');
    }

    private function getDefaultCurrency(): ?object
    {
        $currencyService = $this->getCurrencyService();
        if (method_exists($currencyService, 'getDefault')) {
            $currency = $currencyService->getDefault();

            return $currency instanceof \Model_Currency ? $currency : null;
        }

        if (method_exists($currencyService, 'getCurrencyRepository')) {
            return $currencyService->getCurrencyRepository()->findDefault();
        }

        return null;
    }

    private function getCurrencyByCode(string $code): ?object
    {
        $currencyService = $this->getCurrencyService();
        if (method_exists($currencyService, 'getByCode')) {
            $currency = $currencyService->getByCode($code);

            return $currency instanceof \Model_Currency ? $currency : null;
        }

        if (method_exists($currencyService, 'getCurrencyRepository')) {
            return $currencyService->getCurrencyRepository()->findOneByCode($code);
        }

        return null;
    }

    private function getCurrencyCode(object $currency): string
    {
        if ($currency instanceof \Model_Currency) {
            return (string) $currency->code;
        }

        if (method_exists($currency, 'getCode')) {
            return (string) $currency->getCode();
        }

        return '';
    }

    private function getCurrencyConversionRate(object $currency): ?float
    {
        if ($currency instanceof \Model_Currency) {
            return is_numeric($currency->conversion_rate) ? (float) $currency->conversion_rate : null;
        }

        if (method_exists($currency, 'getConversionRate')) {
            $rate = $currency->getConversionRate();

            return is_numeric($rate) ? (float) $rate : null;
        }

        return null;
    }

    private function getEurToSellRate(): float
    {
        $currencyService = $this->getCurrencyService();
        if (method_exists($currencyService, 'getBaseCurrencyRate')) {
            return (float) $currencyService->getBaseCurrencyRate(self::NORDNAME_WHOLESALE_CURRENCY);
        }

        return (float) $this->convertWholesaleToSell(1.0);
    }

    private function multiplyPrice(?float $price, int $years): ?float
    {
        if ($price === null) {
            return null;
        }

        return round($price * $years, 2);
    }

    private function buildWholesalePrices(\Registrar_Adapter_Nordname $adapter, array $prices, int $minYears): array
    {
        return [
            'register' => [
                'promotional' => $this->multiplyPrice($adapter->extractOperationPrice($prices, 'registration', true), $minYears),
                'standard' => $this->multiplyPrice($adapter->extractOperationPrice($prices, 'registration', false), $minYears),
            ],
            'renew' => [
                'promotional' => $this->multiplyPrice($adapter->extractOperationPrice($prices, 'renewal', true), $minYears),
                'standard' => $this->multiplyPrice($adapter->extractOperationPrice($prices, 'renewal', false), $minYears),
            ],
            'transfer' => [
                'promotional' => $adapter->extractOperationPrice($prices, 'transfer', true),
                'standard' => $adapter->extractOperationPrice($prices, 'transfer', false),
            ],
        ];
    }

    private static function isNordnameRegistrar(\Pimple\Container $di, \Model_ServiceDomain $domain): bool
    {
        $tldRegistrar = $di['db']->load('TldRegistrar', $domain->tld_registrar_id);
        if (!$tldRegistrar instanceof \Model_TldRegistrar) {
            return false;
        }

        return strcasecmp($tldRegistrar->registrar, 'Nordname') === 0;
    }

    private static function getRenewalDaysBeforeExpiration(array $config): ?int
    {
        if (!array_key_exists('renewal_days_before_expiration', $config)) {
            return null;
        }

        $value = $config['renewal_days_before_expiration'];
        if ($value === '' || $value === null) {
            return null;
        }

        if (!is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }
}
