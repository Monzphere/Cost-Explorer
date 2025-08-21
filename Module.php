<?php

namespace Modules\CostExplorer;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {
    public function init(): void {
        // Registrar menu
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->insertAfter(_('Problems'),
                (new CMenuItem('Cost Explorer'))
                    ->setAction('costexplorer.view')
                    ->setIcon(ZBX_ICON_INTEGRATIONS)
            );
    }
} 