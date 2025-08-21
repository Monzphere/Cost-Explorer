<?php declare(strict_types = 0);
/*
** Copyright (C) 2001-2025 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/

namespace Modules\CostExplorer\Actions;

use CControllerResponseData;
use CSettingsHelper;
use CWebUser;

class CControllerCostExplorerViewRefresh extends CControllerCostExplorerView {

    protected function init(): void {
        $this->setPostContentType(self::CONTENT_TYPE_JSON);
    }

    protected function doAction(): void {
        $input = $this->getInputAll();
        $filter = $input + self::FILTER_FIELDS_DEFAULT;

        // Get pricing configuration
        $pricing = $this->getPricing();

        // Get hosts with Zabbix agent interface
        $hosts_data = $this->getHostsData($filter);

        // Calculate costs for each host
        $hosts_with_costs = $this->calculateCosts($hosts_data, $pricing);

        // Sort hosts
        $hosts_sorted = $this->sortHosts($hosts_with_costs, $filter['sort'], $filter['sortorder']);
        
        // Save page number
        $page_num = $filter['page'];
        
        // Get paginated hosts using the search limit
        $search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);
        $offset = ($page_num - 1) * $search_limit;
        $hosts_paginated = array_slice($hosts_sorted, $offset, $search_limit);

        // Prepare chart data
        $chart_data = $this->prepareChartData($hosts_with_costs);

        // Prepare data for view
        $data = [
            'hosts' => $hosts_paginated,
            'pricing' => $pricing,
            'filter' => $filter,
            'sort' => $filter['sort'],
            'sortorder' => $filter['sortorder'],
            'groups' => $this->getHostGroups($filter['groupids']),
            'total_hosts' => count($hosts_sorted),
            'chart_data' => $chart_data,
            'user' => [
                'debug_mode' => CWebUser::$data['debug_mode']
            ]
        ];

        $response = new CControllerResponseData($data);
        $this->setResponse($response);
    }
}
