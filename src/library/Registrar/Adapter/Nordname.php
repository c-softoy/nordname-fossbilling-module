<?php

declare(strict_types=1);

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Registrar_Adapter_Nordname extends Registrar_AdapterAbstract
{
    private const API_URL_PRODUCTION = 'https://api.nordname.fi/api/v3/';
    private const API_URL_SANDBOX = 'https://api.ote.nordname.fi/api/v3/';

    private array $config = [
        'api_key' => '',
        'auxiliary_contact' => '',
        'sandbox' => false,
        'registrant_language' => 'en',
        'renewal_days_before_expiration' => '',
    ];

    public function __construct($options)
    {
        if (empty($options['api_key'])) {
            throw new Registrar_Exception(
                'The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing',
                [':domain_registrar' => 'NordName', ':missing' => 'API Key'],
                3001
            );
        }
        $this->config['api_key'] = $options['api_key'];

        if (empty($options['auxiliary_contact'])) {
            throw new Registrar_Exception(
                'The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing',
                [':domain_registrar' => 'NordName', ':missing' => 'Admin/tech/billing contact ID'],
                3001
            );
        }
        $this->config['auxiliary_contact'] = $options['auxiliary_contact'];

        if (isset($options['sandbox'])) {
            $this->config['sandbox'] = in_array((string) $options['sandbox'], ['1', 'true', 'on', 'yes'], true);
        }

        if (!empty($options['registrant_language']) && in_array($options['registrant_language'], ['en', 'fi', 'sv'], true)) {
            $this->config['registrant_language'] = $options['registrant_language'];
        }

        if (array_key_exists('renewal_days_before_expiration', $options)) {
            $this->config['renewal_days_before_expiration'] = (string) $options['renewal_days_before_expiration'];
        }

        $this->validateConfiguration();
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Manages domains on NordName via API v3.',
            'form' => [
                'api_key' => [
                    'password',
                    [
                        'label' => 'API Key',
                        'description' => 'Your NordName reseller API key.',
                        'required' => true,
                    ],
                ],
                'auxiliary_contact' => [
                    'text',
                    [
                        'label' => 'Admin/tech/billing contact ID',
                        'description' => 'Contact ID of an existing NordName contact with is_registrant set to false. Used as admin, tech, and billing contact on registrations and transfers.',
                        'required' => true,
                    ],
                ],
                'sandbox' => [
                    'radio',
                    [
                        'label' => 'Sandbox mode',
                        'description' => 'Use the NordName OTE (test) environment instead of production.',
                        'multiOptions' => [
                            '0' => 'No (production)',
                            '1' => 'Yes (OTE)',
                        ],
                    ],
                ],
                'registrant_language' => [
                    'select',
                    [
                        'label' => 'Registrant contact language',
                        'description' => 'Language setting set on registrant contacts. NordName will send email/SMS communication in this language to your end users.',
                        'multiOptions' => [
                            'en' => 'English',
                            'fi' => 'Finnish',
                            'sv' => 'Swedish',
                        ],
                    ],
                ],
                'renewal_days_before_expiration' => [
                    'text',
                    [
                        'label' => 'Renewal date offset (days)',
                        'description' => 'When set, the cron job sets each synced order renewal date to the domain expiration date minus this many days. Leave empty to skip. For example, use 0 for the exact expiration datetime, or 7 for one week before expiration.',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    public function listTlds(): array
    {
        $response = $this->request('GET', 'tld', ['type' => 'TLDList']);

        if (!is_array($response)) {
            return [];
        }

        if (isset($response['data']) && is_array($response['data'])) {
            $tlds = [];
            foreach ($response['data'] as $item) {
                if (is_string($item)) {
                    $tlds[] = $item;
                } elseif (is_array($item) && !empty($item['tld'])) {
                    $tlds[] = (string) $item['tld'];
                }
            }

            return $tlds;
        }

        return array_values(array_filter($response, 'is_string'));
    }

    public function listTldPrices(): array
    {
        $all = [];
        $page = 1;
        $size = 500;

        do {
            $response = $this->request('GET', 'tld', [
                'type' => 'TLDPriceList',
                'page' => $page,
                'size' => $size,
            ]);

            if (!is_array($response)) {
                break;
            }

            $items = $response['data'] ?? [];
            if (!is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (is_array($item) && !empty($item['tld'])) {
                    $all[] = $item;
                }
            }

            $total = (int) ($response['total'] ?? 0);
            if (count($items) < $size || ($total > 0 && count($all) >= $total)) {
                break;
            }

            ++$page;
        } while ($page <= 100);

        return $all;
    }

    public function getTldInfo(string $tld): array
    {
        return $this->request('GET', 'tld/' . ltrim($tld, '.'));
    }

    public function extractOperationPrice(array $prices, string $operation, bool $usePromotional): ?float
    {
        if (!isset($prices[$operation]) || !is_array($prices[$operation])) {
            return null;
        }

        $operationPrices = $prices[$operation];

        if ($usePromotional) {
            return $this->normalizePriceValue($operationPrices['price'] ?? null);
        }

        $standard = $operationPrices['standard_price'] ?? null;
        if ($standard !== null && $standard !== '') {
            return $this->normalizePriceValue($standard);
        }

        return $this->normalizePriceValue($operationPrices['price'] ?? null);
    }

    private function normalizePriceValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value) && isset($value['net_price']) && is_numeric($value['net_price'])) {
            return (float) $value['net_price'];
        }

        return null;
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->getAvailabilityResult($domain->getName());

        if (!empty($result['is_premium'])) {
            throw new Registrar_Exception('Premium domains cannot be registered through this registrar.');
        }

        return (bool) ($result['avail'] ?? false);
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $result = $this->getAvailabilityResult($domain->getName());

        if (!empty($result['is_premium'])) {
            throw new Registrar_Exception('Premium domains cannot be transferred through this registrar.');
        }

        return !($result['avail'] ?? true);
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $nameservers = $this->collectNameservers($domain);
        if (count($nameservers) < 2) {
            throw new Registrar_Exception('At least two nameservers are required.');
        }

        $this->request('PUT', 'domain/' . $domain->getName() . '/change_nameservers', [
            'nameservers' => implode(',', $nameservers),
        ]);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        $contact = $domain->getContactRegistrar();
        if (!$contact instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('Registrant contact is required to update domain contacts.');
        }

        $domainInfo = $this->request('GET', 'domain/' . $domain->getName());
        $registrantId = $domainInfo['registrant'] ?? null;
        if (empty($registrantId)) {
            throw new Registrar_Exception('Registrant contact not found for this domain.');
        }

        $existing = $this->request('GET', 'contact/' . $registrantId);
        $body = $this->buildContactBody($contact);
        $body['first_name'] = $existing['first_name'] ?? $body['first_name'];
        $body['last_name'] = $existing['last_name'] ?? $body['last_name'];

        $this->request('POST', 'contact/' . $registrantId, [], $body);

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $registrantId = null;

        try {
            $registrantId = $this->createRegistrantContact($domain);
            $this->request('POST', 'domain/transfer/' . $domain->getName(), [
                'auth_code' => $domain->getEpp(),
                'registrant' => $registrantId,
                'admin' => $this->config['auxiliary_contact'],
                'tech' => $this->config['auxiliary_contact'],
                'billing' => $this->config['auxiliary_contact'],
            ]);
        } catch (Exception $e) {
            if ($registrantId !== null) {
                $this->deleteContactSilently($registrantId);
            }
            throw $e instanceof Registrar_Exception ? $e : new Registrar_Exception($e->getMessage());
        }

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain)
    {
        $info = $this->request('GET', 'domain/' . $domain->getName());

        if (!empty($info['nameservers']) && is_array($info['nameservers'])) {
            $nameservers = array_values(array_filter($info['nameservers']));
            $domain->setNs1($nameservers[0] ?? null);
            $domain->setNs2($nameservers[1] ?? null);
            $domain->setNs3($nameservers[2] ?? null);
            $domain->setNs4($nameservers[3] ?? null);
        }

        if (isset($info['settings']['privacy'])) {
            $domain->setPrivacyEnabled((bool) $info['settings']['privacy']);
        }

        $transferLock = $info['settings']['transfer_lock'] ?? $info['settings']['transferlock'] ?? null;
        if ($transferLock !== null) {
            $domain->setLocked((bool) $transferLock);
        }

        if (!empty($info['registered_at'])) {
            $domain->setRegistrationTime(strtotime((string) $info['registered_at']));
        }

        if (!empty($info['expires_at'])) {
            $domain->setExpirationTime(strtotime((string) $info['expires_at']));
        }

        if (!empty($info['registrant'])) {
            $contactData = $this->request('GET', 'contact/' . $info['registrant']);
            $domain->setContactRegistrar($this->mapApiContactToDomainContact($contactData));
        }

        return $domain;
    }

    public function getEpp(Registrar_Domain $domain)
    {
        $response = $this->request('PUT', 'domain/' . $domain->getName() . '/retrieve_auth_code', [
            'mode' => 'return_in_response',
        ]);

        if (empty($response['auth_code'])) {
            throw new Registrar_Exception('Failed to retrieve EPP code from NordName.');
        }

        return $response['auth_code'];
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $registrantId = null;

        try {
            $registrantId = $this->createRegistrantContact($domain);
            $params = [
                'years' => $domain->getRegistrationPeriod() ?? 1,
                'registrant' => $registrantId,
                'admin' => $this->config['auxiliary_contact'],
                'tech' => $this->config['auxiliary_contact'],
                'billing' => $this->config['auxiliary_contact'],
            ];

            $nameservers = $this->collectNameservers($domain);
            if (!empty($nameservers)) {
                $params['nameservers'] = implode(',', $nameservers);
            }

            $this->request('POST', 'domain/register/' . $domain->getName(), $params);
        } catch (Exception $e) {
            if ($registrantId !== null) {
                $this->deleteContactSilently($registrantId);
            }
            throw $e instanceof Registrar_Exception ? $e : new Registrar_Exception($e->getMessage());
        }

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $years = $domain->getRegistrationPeriod() ?? 1;
        $result = $this->getAvailabilityResult($domain->getName());
        if (!empty($result['is_premium'])) {
            throw new Registrar_Exception('Premium domains cannot be renewed through this registrar.');
        }

        $this->request('POST', 'domain/' . $domain->getName() . '/renew', [
            'years' => $years,
        ]);

        return true;
    }

    public function deleteDomain(Registrar_Domain $domain): never
    {
        throw new Registrar_Exception(
            ':type: does not support :action:',
            [':type:' => 'NordName', ':action:' => __trans('deleting domains')]
        );
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('PUT', 'domain/' . $domain->getName() . '/feature/privacy', [
            'privacy' => 'true',
        ]);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->request('PUT', 'domain/' . $domain->getName() . '/feature/privacy', [
            'privacy' => 'false',
        ]);

        return true;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->request('PUT', 'domain/' . $domain->getName() . '/feature/transfer_lock', [
            'transfer_lock' => 'true',
        ]);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->request('PUT', 'domain/' . $domain->getName() . '/feature/transfer_lock', [
            'transfer_lock' => 'false',
        ]);

        return true;
    }

    private function validateConfiguration(): void
    {
        try {
            $this->request('GET', 'tld', ['type' => 'TLDList']);
        } catch (Registrar_Exception $e) {
            throw new Registrar_Exception('Invalid NordName API key: :message', [':message' => $e->getMessage()]);
        }

        try {
            $contact = $this->request('GET', 'contact/' . $this->config['auxiliary_contact']);
        } catch (Registrar_Exception $e) {
            throw new Registrar_Exception('Invalid admin/tech/billing contact ID: :message', [':message' => $e->getMessage()]);
        }

        if (!empty($contact['is_registrant'])) {
            throw new Registrar_Exception(
                'The configured admin/tech/billing contact must have is_registrant set to false.'
            );
        }
    }

    private function getAvailabilityResult(string $domainName): array
    {
        $response = $this->request('GET', 'domain/availability', [
            'domain' => $domainName,
        ]);

        if (!is_array($response)) {
            throw new Registrar_Exception('Unexpected response from NordName availability check.');
        }

        if (isset($response['domain'])) {
            return $response;
        }

        foreach ($response as $result) {
            if (is_array($result) && ($result['domain'] ?? '') === $domainName) {
                return $result;
            }
        }

        if (!empty($response[0]) && is_array($response[0])) {
            return $response[0];
        }

        throw new Registrar_Exception('Domain availability result not found for :domain', [':domain' => $domainName]);
    }

    private function createRegistrantContact(Registrar_Domain $domain): string
    {
        $contact = $domain->getContactRegistrar();
        if (!$contact instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('Registrant contact is required to register or transfer a domain.');
        }

        $tld = $domain->getTld(false);
        $body = $this->buildContactBody($contact);

        $this->request('POST', 'contact', [
            'validate_for_tld' => $tld,
            'validate_for_type' => 'registrant',
        ], $body);

        $response = $this->request('POST', 'contact', [], $body);
        if (empty($response['contact'])) {
            throw new Registrar_Exception('Failed to create registrant contact at NordName.');
        }

        return (string) $response['contact'];
    }

    private function buildContactBody(Registrar_Domain_Contact $contact): array
    {
        $body = [
            'first_name' => $contact->getFirstName(),
            'last_name' => $contact->getLastName(),
            'address1' => $contact->getAddress1(),
            'city' => $contact->getCity(),
            'zip_code' => $contact->getZip(),
            'country' => $contact->getCountry(),
            'email' => $contact->getEmail(),
            'phone' => $this->formatPhone($contact),
            'is_registrant' => true,
            'language' => $this->config['registrant_language'],
        ];

        if ($contact->getState()) {
            $body['area'] = $contact->getState();
        }

        if ($contact->getAddress2()) {
            $body['address2'] = $contact->getAddress2();
        }

        if ($contact->getCompany()) {
            $body['company'] = $contact->getCompany();
            $body['registrant_type'] = 'Company';
        } else {
            $body['registrant_type'] = 'Private Person';
        }

        if ($contact->getBirthday()) {
            $body['birth_date'] = $contact->getBirthday();
        }

        if ($contact->getCompanyNumber()) {
            $body['register_number'] = $contact->getCompanyNumber();
        }

        if ($contact->getDocumentNr()) {
            $body['id_number'] = $contact->getDocumentNr();
        }

        return $body;
    }

    private function mapApiContactToDomainContact(array $data): Registrar_Domain_Contact
    {
        $contact = new Registrar_Domain_Contact();
        $contact->setFirstName($data['first_name'] ?? '');
        $contact->setLastName($data['last_name'] ?? '');
        $contact->setEmail($data['email'] ?? '');
        $contact->setCompany($data['company'] ?? '');
        $contact->setAddress1($data['address1'] ?? '');
        $contact->setAddress2($data['address2'] ?? '');
        $contact->setCity($data['city'] ?? '');
        $contact->setState($data['area'] ?? '');
        $contact->setZip($data['zip_code'] ?? '');
        $contact->setCountry($data['country'] ?? '');

        if (!empty($data['phone'])) {
            $phoneParts = explode('.', (string) $data['phone'], 2);
            if (count($phoneParts) === 2) {
                $contact->setTelCc(ltrim($phoneParts[0], '+'));
                $contact->setTel($phoneParts[1]);
            } else {
                $contact->setTel((string) $data['phone']);
            }
        }

        if (!empty($data['birth_date'])) {
            $contact->setBirthday((string) $data['birth_date']);
        }

        if (!empty($data['register_number'])) {
            $contact->setCompanyNumber((string) $data['register_number']);
        }

        if (!empty($data['id_number'])) {
            $contact->setDocumentNr((string) $data['id_number']);
        }

        return $contact;
    }

    private function formatPhone(Registrar_Domain_Contact $contact): string
    {
        $tel = str_replace(' ', '', (string) $contact->getTel());
        $telCc = trim((string) $contact->getTelCc());

        if ($telCc !== '' && $tel !== '') {
            return '+' . ltrim($telCc, '+') . '.' . $tel;
        }

        if ($tel !== '') {
            return $tel;
        }

        throw new Registrar_Exception('A phone number is required for the registrant contact.');
    }

    private function collectNameservers(Registrar_Domain $domain): array
    {
        return array_values(array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ]));
    }

    private function deleteContactSilently(string $contactId): void
    {
        try {
            $this->request('DELETE', 'contact/' . $contactId);
        } catch (Exception) {
        }
    }

    private function getApiUrl(): string
    {
        return $this->config['sandbox'] ? self::API_URL_SANDBOX : self::API_URL_PRODUCTION;
    }

    private function request(string $method, string $action, array $query = [], ?array $body = null): array
    {
        $url = $this->getApiUrl() . ltrim($action, '/');
        $headers = [
            'Accept' => 'application/json',
            'X-Module-Version' => '3.0',
            'Authorization' => 'token ' . $this->config['api_key'],
        ];

        $options = [
            'headers' => $headers,
            'timeout' => 360,
        ];

        if (!empty($query)) {
            $options['query'] = $this->normalizeQuery($query);
        }

        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['json'] = $body;
        }

        $client = $this->getHttpClient();

        try {
            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface | HttpExceptionInterface $error) {
            $message = 'NordName API connection error: ' . $error->getMessage();
            $this->getLog()->error($message);
            throw new Registrar_Exception($message);
        }

        $this->getLog()->debug('NordName API ' . $method . ' ' . $url . ' query=' . json_encode($query) . ' response=' . $content);

        if ($statusCode === 204 || $content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) && json_last_error() !== JSON_ERROR_NONE) {
            throw new Registrar_Exception('Invalid response received from NordName API.');
        }

        if (!in_array($statusCode, [200, 201, 202], true)) {
            $detail = is_array($decoded) ? ($decoded['detail'] ?? $content) : $content;
            throw new Registrar_Exception((string) $detail);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeQuery(array $query): array
    {
        $normalized = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = implode(',', $value);
            } elseif (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
