<?php

declare(strict_types=1);

namespace Box\Mod\Nordname\Api;

if (class_exists(\FOSSBilling\Api\AbstractApi::class)) {
    abstract class NordnameApiBase extends \FOSSBilling\Api\AbstractApi
    {
    }
} elseif (class_exists(\Api_Abstract::class)) {
    abstract class NordnameApiBase extends \Api_Abstract
    {
    }
} else {
    throw new \RuntimeException('Unsupported FOSSBilling version: API base class not found');
}

class Admin extends NordnameApiBase
{
    public function getService(): \Box\Mod\Nordname\Service
    {
        return $this->di['mod_service']('nordname');
    }

    public function tld_fetch_list(array $data): array
    {
        $this->checkManageTldsPermission();
        $this->requireParams(['tld_registrar_id' => 'Registrar ID is required'], $data);

        $registrar = $this->_getNordnameRegistrar((int) $data['tld_registrar_id']);

        return $this->getService()->fetchTldCatalog($registrar);
    }

    public function tld_import(array $data): array
    {
        $this->checkManageTldsPermission();
        $this->requireParams([
            'tld_registrar_id' => 'Registrar ID is required',
            'tlds' => 'At least one TLD must be selected',
        ], $data);

        $registrar = $this->_getNordnameRegistrar((int) $data['tld_registrar_id']);
        $tlds = $data['tlds'] ?? [];
        if (!is_array($tlds)) {
            $tlds = [$tlds];
        }

        $fixedMargin = isset($data['fixed_margin']) && is_numeric($data['fixed_margin']) ? (float) $data['fixed_margin'] : 0.0;
        $percentMargin = isset($data['percent_margin']) && is_numeric($data['percent_margin']) ? (float) $data['percent_margin'] : 0.0;
        $usePromotional = !empty($data['use_promotional']);

        return $this->getService()->importTlds($registrar, $tlds, $fixedMargin, $percentMargin, $usePromotional);
    }

    private function checkManageTldsPermission(): void
    {
        if (method_exists($this, 'checkPermissions')) {
            $this->checkPermissions('servicedomain', 'manage_tlds');

            return;
        }

        $staff = $this->di['mod_service']('Staff');
        $method = new \ReflectionMethod($staff, 'checkPermissionsAndThrowException');
        if ($method->getNumberOfParameters() >= 4) {
            $staff->checkPermissionsAndThrowException('servicedomain', 'manage_tlds', null, $this->getIdentity());
        } else {
            $staff->checkPermissionsAndThrowException('servicedomain', 'manage_tlds');
        }
    }

    private function requireParams(array $required, array $data): void
    {
        $this->di['validator']->checkRequiredParamsForArray($required, $data);
    }

    private function _getNordnameRegistrar(int $id): \Model_TldRegistrar
    {
        $registrar = $this->di['db']->load('TldRegistrar', $id);
        if (!$registrar instanceof \Model_TldRegistrar) {
            throw new \FOSSBilling\Exception('Registrar not found');
        }

        if (strcasecmp($registrar->registrar, 'Nordname') !== 0) {
            throw new \FOSSBilling\Exception('Selected registrar is not a NordName registrar');
        }

        return $registrar;
    }
}
