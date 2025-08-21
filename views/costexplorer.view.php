<?php




$this->addJsFile('multiselect.js');
$this->addJsFile('items.js');
$this->includeJsFile('costexplorer.js.php');
$this->includeJsFile('costexplorergeneral.js.php');

$html_page = (new CHtmlPage())
    ->setTitle(_('Cost Explorer'))
    ->setDocUrl('')
    ->setControls(
        (new CTag('nav', true,
            (new CList())
                ->addItem(
                    (new CButton('pricing_config', _('Configure Pricing')))
                        ->setId('pricing_config')
                        ->onClick('openPricingConfigDialog()')
                )
        ))->setAttribute('aria-label', _('Content controls'))
    );

// Filter form
$filter_form = (new CForm('get'))
    ->setName('zbx_filter')
    ->setAttribute('aria-label', _('Main filter'));

$filter_column1 = (new CFormGrid())
    ->addItem([
        new CLabel(_('Host name'), 'filter_name'),
        new CFormField((new CTextBox('name', $data['filter']['name']))
            ->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
            ->setAttribute('autofocus', 'autofocus')
        )
    ])
    ->addItem([
        new CLabel(_('Host groups'), 'filter_groupids__ms'),
        new CFormField(
            (new CMultiSelect([
                'name' => 'groupids[]',
                'object_name' => 'hostGroup',
                'data' => $data['groups'],
                'popup' => [
                    'parameters' => [
                        'srctbl' => 'host_groups',
                        'srcfld1' => 'groupid',
                        'dstfrm' => 'zbx_filter',
                        'dstfld1' => 'filter_groupids_',
                        'with_hosts' => true,
                        'enrich_parent_groups' => true
                    ]
                ]
            ]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
        )
    ]);

$filter_column2 = (new CFormGrid())
    ->addItem([
        new CLabel(_('Sort by')),
        new CFormField([
            (new CSelect('sort'))
                ->setValue($data['sort'])
                ->addOptions([
                    new CSelectOption('name', _('Name')),
                    new CSelectOption('cpu_cores', _('CPU Cores')),
                    new CSelectOption('memory_gb', _('Memory (GB)')),
                    new CSelectOption('cpu_cost', _('CPU Cost/hour')),
                    new CSelectOption('memory_cost', _('Memory Cost/hour')),
                    new CSelectOption('total_cost', _('Total Cost/hour'))
                ]),
            (new CSelect('sortorder'))
                ->setValue($data['sortorder'])
                ->addOptions([
                    new CSelectOption(ZBX_SORT_UP, _('Ascending')),
                    new CSelectOption(ZBX_SORT_DOWN, _('Descending'))
                ])
        ])
    ])
    ->addItem([
        new CLabel(_('Show inactive')),
        new CFormField(
            (new CCheckBox('show_inactive'))
                ->setChecked($data['filter']['show_inactive'])
        )
    ]);

$filter_form->addItem(
    (new CFilter())
        ->setResetUrl(new CUrl('?action=costexplorer.view'))
        ->addFilterTab(_('Filter'), [$filter_column1, $filter_column2])
        ->addVar('action', 'costexplorer.view')
);

// Pricing info panel
$pricing_info = (new CDiv([
    new CTag('h4', true, _('Current Pricing Configuration')),
    new CDiv([
        new CSpan(_('CPU per core/hour: $' . number_format($data['pricing']['per_cpu_core'], 5))),
        ' | ',
        new CSpan(_('Memory per GB/hour: $' . number_format($data['pricing']['per_memory_gb'], 6)))
    ])
]))->addClass('pricing-info-panel');



// Create Zabbix-compatible table with mini bar charts
$hosts_table = new CTableInfo();
$hosts_table->setId('costexplorer-table');

// Table headers with sorting
$sort_field = $data['sort'];
$sort_order = $data['sortorder'];

$headers = [
    make_sorting_header(_('Host'), 'name', $sort_field, $sort_order, '?action=costexplorer.view'),
    _('Status'),
    make_sorting_header(_('CPU Cores'), 'cpu_cores', $sort_field, $sort_order, '?action=costexplorer.view'),
    _('CPU Usage'),
    make_sorting_header(_('Memory (GB)'), 'memory_gb', $sort_field, $sort_order, '?action=costexplorer.view'),
    _('Memory Usage'),
    make_sorting_header(_('CPU Cost/hour'), 'cpu_cost', $sort_field, $sort_order, '?action=costexplorer.view'),
    make_sorting_header(_('Memory Cost/hour'), 'memory_cost', $sort_field, $sort_order, '?action=costexplorer.view'),
    make_sorting_header(_('Total/hour'), 'total_cost', $sort_field, $sort_order, '?action=costexplorer.view'),
    _('Total/month'),
    _('Potential Savings')
];

$hosts_table->setHeader($headers);

// Calculate totals
$total_used_cost_hourly = 0;
$total_idle_cost_hourly = 0;
$total_cost_hourly = 0;

if ($data['hosts']) {
    $total_used_cost_hourly = array_sum(array_column($data['hosts'], 'total_used_cost_hourly'));
    $total_idle_cost_hourly = array_sum(array_column($data['hosts'], 'total_idle_cost_hourly'));
    $total_cost_hourly = $total_used_cost_hourly + $total_idle_cost_hourly;

    foreach ($data['hosts'] as $host) {
        // Host name with clickable link and status indicator
        $host_name = (new CLinkAction($host['name']))
            ->setTitle($host['name'])
            ->setMenuPopup(CMenuPopupHelper::getHost($host['hostid']));
        if ($host['status'] == HOST_STATUS_NOT_MONITORED) {
            $host_name->addClass('grey');
        }

        // Status
        $status = $host['status'] == HOST_STATUS_NOT_MONITORED ? _('Inactive') : _('Active');
        $status_span = new CSpan($status);
        $status_span->addClass($host['status'] == HOST_STATUS_NOT_MONITORED ? 'host-inactive' : 'host-active');

        // CPU Usage mini bar
        $cpu_usage = $host['cpu_usage']['total_usage'] ?? 0;
        $cpu_usage_bar = new CDiv();
        $cpu_usage_bar->addClass('mini-usage-bar');
        
        $cpu_used_bar = new CDiv();
        $cpu_used_bar->addClass('mini-bar-used');
        $cpu_used_bar->addStyle('width: ' . $cpu_usage . '%');
        
        $cpu_usage_bar->addItem($cpu_used_bar);
        
        $cpu_usage_span = new CSpan(number_format($cpu_usage, 1) . '%');
        
        $cpu_usage_container = new CDiv([
            $cpu_usage_bar,
            $cpu_usage_span
        ]);
        $cpu_usage_container->addClass('usage-bar-cell');

        // Memory Usage mini bar  
        $memory_usage = $host['memory_usage']['usage_percent'] ?? 0;
        $memory_usage_bar = new CDiv();
        $memory_usage_bar->addClass('mini-usage-bar');
        
        $memory_used_bar = new CDiv();
        $memory_used_bar->addClass('mini-bar-used');
        $memory_used_bar->addStyle('width: ' . $memory_usage . '%');
        
        $memory_usage_bar->addItem($memory_used_bar);
        $memory_usage_container = new CDiv([
            $memory_usage_bar,
            new CSpan(number_format($memory_usage, 1) . '%')
        ]);
        $memory_usage_container->addClass('usage-bar-cell');

        // Cost breakdown with mini indicators
        $cpu_used_small = new CSpan('Used: $' . number_format($host['cpu_used_cost_hourly'], 4));
        $cpu_used_small->addClass('cost-used-small');
        
        $cpu_idle_small = new CSpan(' | Idle: $' . number_format($host['cpu_idle_cost_hourly'], 4));
        $cpu_idle_small->addClass('cost-idle-small');
        
        $cpu_cost_cell = new CDiv([
            new CDiv('$' . number_format($host['cpu_cost_hourly'], 4)),
            new CDiv([$cpu_used_small, $cpu_idle_small])
        ]);
        $cpu_cost_cell->addClass('cost-breakdown-cell');

        $memory_used_small = new CSpan('Used: $' . number_format($host['memory_used_cost_hourly'], 4));
        $memory_used_small->addClass('cost-used-small');
        
        $memory_idle_small = new CSpan(' | Idle: $' . number_format($host['memory_idle_cost_hourly'], 4));
        $memory_idle_small->addClass('cost-idle-small');
        
        $memory_cost_cell = new CDiv([
            new CDiv('$' . number_format($host['memory_cost_hourly'], 4)),
            new CDiv([$memory_used_small, $memory_idle_small])
        ]);
        $memory_cost_cell->addClass('cost-breakdown-cell');

        // Total cost with used/idle breakdown
        $total_main = new CDiv('$' . number_format($host['total_cost_hourly'], 4));
        $total_main->addClass('total-cost-main');
        
        // Calcular percentuais evitando divisão por zero
        $used_percentage = $host['total_cost_hourly'] > 0 ? 
            ($host['total_used_cost_hourly'] / $host['total_cost_hourly']) * 100 : 0;
        $idle_percentage = $host['total_cost_hourly'] > 0 ? 
            ($host['total_idle_cost_hourly'] / $host['total_cost_hourly']) * 100 : 100;
            
        $total_used_pct = new CSpan('Used: ' . number_format($used_percentage, 1) . '%');
        $total_used_pct->addClass('cost-used-small');
        
        $total_idle_pct = new CSpan(' | Idle: ' . number_format($idle_percentage, 1) . '%');
        $total_idle_pct->addClass('cost-idle-small');
        
        $total_cost_cell = new CDiv([
            $total_main,
            new CDiv([$total_used_pct, $total_idle_pct])
        ]);
        $total_cost_cell->addClass('cost-breakdown-cell');

        // Potential Savings (based on idle resources)
        $savings_cell = new CDiv();
        
        // Calculate potential savings from idle resources
        $idle_savings_hourly = $host['total_idle_cost_hourly'];
        $idle_savings_monthly = $idle_savings_hourly * 24 * 30;
        $savings_percentage = $host['total_cost_hourly'] > 0 ? 
            ($idle_savings_hourly / $host['total_cost_hourly']) * 100 : 0;
        
        // Create main savings display
        $main_savings = new CDiv('$' . number_format($idle_savings_monthly, 2) . '/mo');
        $main_savings->addClass('savings-main');
        
        // Create percentage and breakdown
        $savings_percentage_span = new CSpan(number_format($savings_percentage, 1) . '% idle');
        $savings_percentage_span->addClass('savings-percentage');
        
        $hourly_savings_span = new CSpan('$' . number_format($idle_savings_hourly, 4) . '/hr');
        $hourly_savings_span->addClass('savings-hourly');
        
        $savings_breakdown = new CDiv([
            $savings_percentage_span,
            ' | ',
            $hourly_savings_span
        ]);
        $savings_breakdown->addClass('savings-breakdown');
        
        $savings_cell->addItem([
            $main_savings,
            $savings_breakdown
        ]);
        
        // Add color coding based on savings potential
        if ($savings_percentage > 80) {
            $savings_cell->addClass('savings-high'); // Red - muito desperdício
        } elseif ($savings_percentage > 50) {
            $savings_cell->addClass('savings-medium'); // Orange - médio desperdício  
        } elseif ($savings_percentage > 20) {
            $savings_cell->addClass('savings-low'); // Yellow - baixo desperdício
        } else {
            $savings_cell->addClass('savings-minimal'); // Green - pouco desperdício
        }
        
        $savings_cell->addClass('savings-cell');

        $hosts_table->addRow([
            $host_name,
            $status_span,
            $host['cpu_cores'],
            $cpu_usage_container,
            number_format($host['memory_gb'], 2),
            $memory_usage_container,
            $cpu_cost_cell,
            $memory_cost_cell,
            $total_cost_cell,
            '$' . number_format($host['total_cost_monthly'], 2),
            $savings_cell
        ]);
    }
} else {
    $hosts_table->addRow(
        (new CCol(_('No hosts found with Zabbix agent interface and CPU/memory items')))
            ->setColSpan(count($headers))
            ->addClass('center')
    );
}

// Summary totals section
$summary_section = new CDiv();
if ($data['hosts']) {
    $total_cpu_cores = array_sum(array_column($data['hosts'], 'cpu_cores'));
    $total_memory_gb = array_sum(array_column($data['hosts'], 'memory_gb'));
    $total_cost_monthly = $total_cost_hourly * 24 * 30;
    
    // Overall utilization percentages
    $overall_used_percentage = $total_cost_hourly > 0 ? ($total_used_cost_hourly / $total_cost_hourly) * 100 : 0;
    $overall_idle_percentage = 100 - $overall_used_percentage;

    $summary_table = new CTableInfo();
    $summary_table->setHeader([
        _('Summary'),
        _('Total Hosts'),
        _('Total CPU Cores'),
        _('Total Memory (GB)'),
        _('Overall Utilization'),
        _('Total Used/hour'),
        _('Total Idle/hour'), 
        _('Total Cost/hour'),
        _('Total Cost/month')
    ]);

    // Overall utilization bar
    $overall_usage_bar = new CDiv();
    $overall_usage_bar->addClass('mini-usage-bar summary-bar');
    
    $overall_used_bar = new CDiv();
    $overall_used_bar->addClass('mini-bar-used');
    $overall_used_bar->addStyle('width: ' . $overall_used_percentage . '%');
    
    $overall_usage_bar->addItem($overall_used_bar);
    $overall_usage_container = new CDiv([
        $overall_usage_bar,
        new CSpan(number_format($overall_used_percentage, 1) . '% used')
    ]);
    $overall_usage_container->addClass('usage-bar-cell');

    $all_hosts_span = new CSpan(_('All Hosts'));
    $all_hosts_span->addClass('bold');
    
    $used_cost_span = new CSpan('$' . number_format($total_used_cost_hourly, 2));
    $used_cost_span->addClass('cost-used');
    
    $idle_cost_span = new CSpan('$' . number_format($total_idle_cost_hourly, 2));
    $idle_cost_span->addClass('cost-idle');
    
    $total_cost_span = new CSpan('$' . number_format($total_cost_hourly, 2));
    $total_cost_span->addClass('cost-total');
    
    $monthly_cost_span = new CSpan('$' . number_format($total_cost_monthly, 2));
    $monthly_cost_span->addClass('cost-total-monthly');
    
    $summary_table->addRow([
        $all_hosts_span,
        count($data['hosts']),
        $total_cpu_cores,
        number_format($total_memory_gb, 2),
        $overall_usage_container,
        $used_cost_span,
        $idle_cost_span,
        $total_cost_span,
        $monthly_cost_span
    ]);

    $summary_section = $summary_table;
}

$content = new CDiv([
    $hosts_table,
    $summary_section
]);

// Page content
$html_page
    ->addItem($filter_form)
    ->addItem($pricing_info)
    ->addItem([
        new CDiv([
            new CSpan(_('Total hosts: ') . $data['total_hosts'])
        ]),
        $content,
        $data['paging'] ?? null
    ]);



$html_page->show();
