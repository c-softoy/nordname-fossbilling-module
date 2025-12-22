<?php

declare(strict_types=1);

namespace Box\Mod\Nordname\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'extensions',
                    'label' => __trans('NordName registrar'),
                    'index' => 2100,
                    'uri' => $this->di['url']->adminLink('nordname'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/nordname', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_nordname_index', [
            'nordname_registrars' => $this->di['mod_service']('nordname')->getNordnameRegistrars(),
            'pricing_context' => $this->di['mod_service']('nordname')->getTldPricingContext(),
        ]);
    }
}
