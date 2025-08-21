<?php declare(strict_types = 0);


namespace Modules\CostExplorer\Actions;

use CController;
use API;
use CControllerResponseData;
use CRoleHelper;
use CUrl;
use CArrayHelper;
use CCsrfTokenHelper;
use CWebUser;
use CPagerHelper;
use CSettingsHelper;

class CControllerCostExplorerView extends CController {

    // Filter fields default values
    const FILTER_FIELDS_DEFAULT = [
        'name' => '',
        'groupids' => [],
        'show_inactive' => 0,
        'sort' => 'name',
        'sortorder' => ZBX_SORT_UP,
        'page' => 1
    ];

    // CPU Usage Keys - Lista configurável de chaves para buscar CPU usage
    // Adicione aqui outras chaves que você deseja considerar
    const CPU_USAGE_KEYS = [
        'system.cpu.util'            // System stat CPU user
        // Adicione suas chaves personalizadas abaixo:
        // 'sua.chave.personalizada',
        // 'outro.key.cpu.util',
    ];

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'name' => 'string',
            'groupids' => 'array_id',
            'show_inactive' => 'in 0,1',
            'sort' => 'in name,cpu_cores,memory_gb,cpu_cost,memory_cost,total_cost',
            'sortorder' => 'in '.ZBX_SORT_DOWN.','.ZBX_SORT_UP,
            'page' => 'ge 1'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
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
        
        // Store total count before pagination
        $total_hosts = count($hosts_sorted);
        
        // Save page number
        $page_num = $filter['page'];
        CPagerHelper::savePage('costexplorer.view', $page_num);
        
        // Get search limit
        $search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);
        
        // Create URL with all filter parameters preserved
        $url = (new CUrl('?action=costexplorer.view'))
            ->setArgument('name', $filter['name'])
            ->setArgument('show_inactive', $filter['show_inactive'])
            ->setArgument('sort', $filter['sort'])
            ->setArgument('sortorder', $filter['sortorder']);
        
        // Handle groupids array properly
        if (!empty($filter['groupids'])) {
            foreach ($filter['groupids'] as $groupid) {
                $url->setArgument('groupids[]', $groupid);
            }
        }
        
        // Pagination with proper parameter handling  
        // CPagerHelper::paginate() modifies $hosts_sorted by reference, no need for manual array_slice
        $paging = CPagerHelper::paginate($page_num, $hosts_sorted, $filter['sortorder'], $url);
        
        // $hosts_sorted is now already paginated by CPagerHelper
        $hosts_paginated = $hosts_sorted;

        // Prepare data for view
        $data = [
            'hosts' => $hosts_paginated,
            'pricing' => $pricing,
            'filter' => $filter,
            'sort' => $filter['sort'],
            'sortorder' => $filter['sortorder'],
            'paging' => $paging,
            'groups' => $this->getHostGroups($filter['groupids']),
            'total_hosts' => $total_hosts,
            'user' => [
                'debug_mode' => CWebUser::$data['debug_mode']
            ]
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Cost Explorer'));
        $this->setResponse($response);
    }

    /**
     * Get pricing configuration from JSON file
     */
    private function getPricing(): array {
        $pricing_file = dirname(__FILE__) . '/../data/price.json';
        
        if (file_exists($pricing_file)) {
            $pricing_data = json_decode(file_get_contents($pricing_file), true);
            if ($pricing_data) {
                return $pricing_data;
            }
        }

        // Default pricing if file doesn't exist
        return [
            'default' => true,
            'per_cpu_core' => 0.03465,
            'per_memory_gb' => 0.003938
        ];
    }

    /**
     * Get hosts with Zabbix agent interface and their resource information
     */
    private function getHostsData(array $filter): array {
        // First get hosts with Zabbix agent interface
        $host_options = [
            'output' => ['hostid', 'name', 'status'],
            'selectInterfaces' => ['type', 'main', 'available'],
            'monitored' => true
        ];

        // Add filters
        if (!empty($filter['name'])) {
            $host_options['search'] = ['name' => $filter['name']];
        }

        if (!empty($filter['groupids'])) {
            $host_options['groupids'] = $filter['groupids'];
        }

        if (!$filter['show_inactive']) {
            $host_options['filter'] = ['status' => HOST_STATUS_MONITORED];
        }

        $hosts = API::Host()->get($host_options);

        // Filter hosts that have Zabbix agent interface AND required items
        $agent_hosts = [];
        foreach ($hosts as $host) {
            $has_agent_interface = false;
            foreach ($host['interfaces'] as $interface) {
                if ($interface['type'] == INTERFACE_TYPE_AGENT && $interface['main'] == INTERFACE_PRIMARY) {
                    $has_agent_interface = true;
                    break;
                }
            }

            if ($has_agent_interface) {
                // Get items for this specific host
                $host_items = $this->getHostItems($host['hostid']);
                
                // Check if host has required CPU and memory items
                $cpu_info = $this->getHostCpuCores($host_items);
                $memory_info = $this->getHostMemoryGb($host_items);
                
                // Only include hosts that have both CPU and memory items
                if ($cpu_info['has_item'] && $memory_info['has_item']) {
                    $host['cpu_cores'] = $cpu_info['value'];
                    $host['memory_gb'] = $memory_info['value'];
                    $host['cpu_item_key'] = $cpu_info['item_key'];
                    $host['memory_item_key'] = $memory_info['item_key'];
                    
                    // Get CPU and memory usage details
                    $host['cpu_usage'] = $this->getHostCpuUsage($host_items);
                    $host['memory_usage'] = $this->getHostMemoryUsage($host_items);
                    $host['processes'] = $this->getHostProcesses($host_items);
                    
                    $agent_hosts[] = $host;
                }
            }
        }

        return $agent_hosts;
    }

    /**
     * Get items for a specific host
     */
    private function getHostItems(string $hostid): array {
        return API::Item()->get([
            'output' => ['itemid', 'key_', 'value_type', 'units', 'name', 'status'],
            'hostids' => [$hostid],
            'monitored' => true,
            'filter' => ['status' => ITEM_STATUS_ACTIVE]
        ]);
    }

    /**
     * Get CPU cores for a host items
     */
    private function getHostCpuCores(array $items): array {
        $cpu_keys = [
            'system.cpu.num',        // Number of CPUs
            'system.cpu.num[]',      // Alternative format
            'system.hw.cpu[,num]',   // Hardware CPU count
            'hw.cpu.num'             // Some templates use this
        ];
        
        foreach ($items as $item) {
            if (in_array($item['key_'], $cpu_keys)) {
                // Get the latest value using Item API
                $item_data = API::Item()->get([
                    'itemids' => [$item['itemid']],
                    'output' => ['itemid', 'key_', 'name', 'lastvalue', 'lastclock'],
                    'selectLastvalue' => true
                ]);
                
                if (!empty($item_data) && isset($item_data[0]['lastvalue']) && is_numeric($item_data[0]['lastvalue'])) {
                    return [
                        'has_item' => true,
                        'value' => (float)$item_data[0]['lastvalue'],
                        'item_key' => $item['key_'],
                        'item_name' => $item['name']
                    ];
                }
            }
        }

        // Check for partial matches in case of parameterized keys
        foreach ($items as $item) {
            if (strpos($item['key_'], 'system.cpu.num') === 0 || 
                strpos($item['key_'], 'hw.cpu') !== false ||
                (strpos($item['key_'], 'cpu') !== false && strpos($item['name'], 'CPU') !== false && strpos($item['name'], 'num') !== false)) {
                
                $item_data = API::Item()->get([
                    'itemids' => [$item['itemid']],
                    'output' => ['itemid', 'key_', 'name', 'lastvalue', 'lastclock'],
                    'selectLastvalue' => true
                ]);
                
                if (!empty($item_data) && isset($item_data[0]['lastvalue']) && is_numeric($item_data[0]['lastvalue']) && $item_data[0]['lastvalue'] > 0) {
                    return [
                        'has_item' => true,
                        'value' => (float)$item_data[0]['lastvalue'],
                        'item_key' => $item['key_'],
                        'item_name' => $item['name']
                    ];
                }
            }
        }

        return [
            'has_item' => false,
            'value' => 0,
            'item_key' => '',
            'item_name' => ''
        ];
    }

    /**
     * Get memory in GB for a host items
     */
    private function getHostMemoryGb(array $items): array {
        $memory_keys = [
            'vm.memory.size[total]',     // Total memory
            'system.hw.memory[total]',   // Hardware memory total
            'vm.memory.total',           // Alternative format
            'system.memory.total'        // Some systems use this
        ];
        
        foreach ($items as $item) {
            if (in_array($item['key_'], $memory_keys)) {
                // Get the latest value using Item API
                $item_data = API::Item()->get([
                    'itemids' => [$item['itemid']],
                    'output' => ['itemid', 'key_', 'name', 'lastvalue', 'lastclock'],
                    'selectLastvalue' => true
                ]);
                
                if (!empty($item_data) && isset($item_data[0]['lastvalue']) && is_numeric($item_data[0]['lastvalue'])) {
                    $memory_bytes = (float)$item_data[0]['lastvalue'];
                    return [
                        'has_item' => true,
                        'value' => round($memory_bytes / 1024 / 1024 / 1024, 2), // Convert to GB
                        'item_key' => $item['key_'],
                        'item_name' => $item['name']
                    ];
                }
            }
        }

        // Check for partial matches
        foreach ($items as $item) {
            if (strpos($item['key_'], 'vm.memory') === 0 || 
                strpos($item['key_'], 'memory') !== false && strpos($item['key_'], 'total') !== false ||
                (strpos($item['name'], 'Memory') !== false && strpos($item['name'], 'total') !== false)) {
                
                $item_data = API::Item()->get([
                    'itemids' => [$item['itemid']],
                    'output' => ['itemid', 'key_', 'name', 'lastvalue', 'lastclock'],
                    'selectLastvalue' => true
                ]);
                
                if (!empty($item_data) && isset($item_data[0]['lastvalue']) && is_numeric($item_data[0]['lastvalue']) && $item_data[0]['lastvalue'] > 0) {
                    $memory_bytes = (float)$item_data[0]['lastvalue'];
                    // Try to detect if it's already in GB or in bytes
                    $memory_gb = $memory_bytes > 1000000000 ? 
                        round($memory_bytes / 1024 / 1024 / 1024, 2) : // Assume bytes
                        round($memory_bytes, 2); // Assume GB
                    
                    return [
                        'has_item' => true,
                        'value' => $memory_gb,
                        'item_key' => $item['key_'],
                        'item_name' => $item['name']
                    ];
                }
            }
        }

        return [
            'has_item' => false,
            'value' => 0,
            'item_key' => '',
            'item_name' => ''
        ];
    }

    /**
     * Get CPU usage percentage for a host
     */
    private function getHostCpuUsage(array $items): array {
        $cpu_usage = [
            'total_usage' => 0,
            'idle' => 100,
            'processes' => [],
            'debug' => []
        ];
        
        
        
        
        
        // Debug: log all CPU-related items found
        $cpu_items_found = [];
        foreach ($items as $item) {
            if (strpos(strtolower($item['name']), 'cpu') !== false || 
                strpos(strtolower($item['key_']), 'cpu') !== false) {
                $cpu_items_found[] = [
                    'name' => $item['name'],
                    'key' => $item['key_'],
                    'status' => $item['status'],
                    'value_type' => $item['value_type']
                ];
                
            }
        }
        $cpu_usage['debug']['cpu_items_found'] = $cpu_items_found;
        $cpu_usage['debug']['total_cpu_items'] = count($cpu_items_found);
        $cpu_usage['debug']['configured_keys'] = self::CPU_USAGE_KEYS;
        
        
        
        // MÉTODO 1: Busca exata pelas chaves configuradas (prioritário)
        
        foreach (self::CPU_USAGE_KEYS as $cpu_key) {
            
            
            foreach ($items as $item) {
                if ($item['key_'] === $cpu_key) {
                    
                    
                    $cpu_usage['debug']['exact_match_key'] = $cpu_key;
                    $cpu_usage['debug']['exact_match_item'] = [
                        'name' => $item['name'],
                        'key' => $item['key_'],
                        'value_type' => $item['value_type']
                    ];
                    
                    $history = $this->getCpuLastValue($item['itemid'], $cpu_key);
                    
                    if ($history['success']) {
                        $result = $this->processCpuValue($history['value'], $cpu_key, $item['name']);
                        $cpu_usage['total_usage'] = $result['total_usage'];
                        $cpu_usage['idle'] = $result['idle'];
                        $cpu_usage['debug']['method'] = 'exact_match';
                        $cpu_usage['debug']['used_key'] = $cpu_key;
                        $cpu_usage['debug']['processing_type'] = $result['type'];
                        
                        
                        
                        return $cpu_usage;
                    }
                }
            }
        }
        
        // MÉTODO 2: Busca parcial pelas chaves configuradas
        
        foreach (self::CPU_USAGE_KEYS as $cpu_key) {
            
            
            foreach ($items as $item) {
                if (strpos($item['key_'], $cpu_key) === 0 || 
                    strpos($item['key_'], str_replace(['[', ']'], '', $cpu_key)) !== false) {
                    
                    
                    
                    $cpu_usage['debug']['partial_match_key'] = $cpu_key;
                    $cpu_usage['debug']['partial_match_item'] = [
                        'name' => $item['name'],
                        'key' => $item['key_'],
                        'value_type' => $item['value_type']
                    ];
                    
                    $history = $this->getCpuLastValue($item['itemid'], $item['key_']);
                    
                    if ($history['success']) {
                        $result = $this->processCpuValue($history['value'], $item['key_'], $item['name']);
                        $cpu_usage['total_usage'] = $result['total_usage'];
                        $cpu_usage['idle'] = $result['idle'];
                        $cpu_usage['debug']['method'] = 'partial_match';
                        $cpu_usage['debug']['used_key'] = $item['key_'];
                        $cpu_usage['debug']['processing_type'] = $result['type'];
                        
                        
                        
                        return $cpu_usage;
                    }
                }
            }
        }
        
        // MÉTODO 3: Fallback - busca genérica (como antes)
        
        foreach ($items as $item) {
            if (strpos($item['key_'], 'system.cpu.util') === 0 ||
                strpos($item['key_'], 'cpu.util') !== false ||
                strpos($item['key_'], 'perf_counter') !== false ||
                (strpos(strtolower($item['name']), 'cpu') !== false && 
                 (strpos(strtolower($item['name']), 'util') !== false || 
                  strpos(strtolower($item['name']), 'usage') !== false))) {
                
                
                
                $cpu_usage['debug']['fallback_item'] = [
                    'name' => $item['name'],
                    'key' => $item['key_'],
                    'value_type' => $item['value_type']
                ];
                
                $history = $this->getCpuLastValue($item['itemid'], $item['key_']);
                
                if ($history['success']) {
                    $result = $this->processCpuValue($history['value'], $item['key_'], $item['name']);
                    $cpu_usage['total_usage'] = $result['total_usage'];
                    $cpu_usage['idle'] = $result['idle'];
                    $cpu_usage['debug']['method'] = 'fallback_generic';
                    $cpu_usage['debug']['used_key'] = $item['key_'];
                    $cpu_usage['debug']['processing_type'] = $result['type'];
                    
                    
                    
                    return $cpu_usage;
                }
            }
        }
        
        
        
        
        
        return $cpu_usage;
    }
    
    /**
     * Buscar último valor de CPU usando Item API (mais eficiente que histórico)
     */
    private function getCpuLastValue(string $itemid, string $key): array {
        
        
        $item = API::Item()->get([
            'itemids' => [$itemid],
            'output' => ['itemid', 'key_', 'name', 'lastvalue', 'lastclock', 'status', 'value_type'],
            'selectLastvalue' => true
        ]);
        
        
        
        if (!empty($item)) {
            $item_data = $item[0];         
            // Verificar se o item tem lastvalue
            if (isset($item_data['lastvalue']) && $item_data['lastvalue'] !== null && is_numeric($item_data['lastvalue'])) {
                $value = (float)$item_data['lastvalue'];
                $timestamp = isset($item_data['lastclock']) ? date('Y-m-d H:i:s', $item_data['lastclock']) : 'unknown';
                
                return [
                    'success' => true, 
                    'value' => $value,
                    'timestamp' => $timestamp,
                    'item_info' => [
                        'name' => $item_data['name'],
                        'key' => $item_data['key_'],
                        'status' => $item_data['status'],
                        'value_type' => $item_data['value_type']
                    ]
                ];
            } else {
                
                
                return ['success' => false, 'value' => 0, 'error' => 'no_lastvalue'];
            }
        } else {
            
            return ['success' => false, 'value' => 0, 'error' => 'item_not_found'];
        }
    }
    
    /**
     * Processar valor de CPU baseado no tipo de métrica
     */
    private function processCpuValue(float $value, string $key, string $name): array {
        
        
        $result = [
            'total_usage' => 0,
            'idle' => 100,
            'type' => 'unknown'
        ];
        
        // Detectar se é métrica de IDLE (deve ser convertida)
        if (strpos($key, 'idle') !== false || strpos(strtolower($name), 'idle') !== false) {
            // Este é CPU idle, converter para usage
            $result['idle'] = $value;
            $result['total_usage'] = 100 - $value;
            $result['type'] = 'idle_converted';
            
        } else {
            // Este deve ser CPU usage direto
            $result['total_usage'] = $value;
            $result['idle'] = 100 - $value;
            $result['type'] = 'direct_usage';
            
        }
        
        // Garantir valores dentro dos limites (0-100%)
        $result['total_usage'] = max(0, min(100, $result['total_usage']));
        $result['idle'] = max(0, min(100, $result['idle']));
        
        
        
        return $result;
    }

    /**
     * Get memory usage for a host
     */
    private function getHostMemoryUsage(array $items): array {
        $memory_usage = [
            'total_gb' => 0,
            'used_gb' => 0,
            'free_gb' => 0,
            'usage_percent' => 0
        ];
        
        $total_memory = 0;
        $used_memory = 0;
        $free_memory = 0;
        
        foreach ($items as $item) {
            $item_data = API::Item()->get([
                'itemids' => [$item['itemid']],
                'output' => ['itemid', 'key_', 'name', 'lastvalue', 'lastclock'],
                'selectLastvalue' => true
            ]);
            
            if (!empty($item_data) && isset($item_data[0]['lastvalue']) && is_numeric($item_data[0]['lastvalue'])) {
                $value = (float)$item_data[0]['lastvalue'];
                
                // Check for different memory metrics
                if (strpos($item['key_'], 'vm.memory.size[total]') === 0 || 
                    strpos($item['key_'], 'memory.total') !== false) {
                    $total_memory = $value;
                } elseif (strpos($item['key_'], 'vm.memory.size[used]') === 0 || 
                          strpos($item['key_'], 'memory.used') !== false) {
                    $used_memory = $value;
                } elseif (strpos($item['key_'], 'vm.memory.size[available]') === 0 || 
                          strpos($item['key_'], 'memory.available') !== false ||
                          strpos($item['key_'], 'memory.free') !== false) {
                    $free_memory = $value;
                }
            }
        }
        
        if ($total_memory > 0) {
            $memory_usage['total_gb'] = round($total_memory / 1024 / 1024 / 1024, 2);
            
            if ($used_memory > 0) {
                $memory_usage['used_gb'] = round($used_memory / 1024 / 1024 / 1024, 2);
                $memory_usage['free_gb'] = $memory_usage['total_gb'] - $memory_usage['used_gb'];
            } elseif ($free_memory > 0) {
                $memory_usage['free_gb'] = round($free_memory / 1024 / 1024 / 1024, 2);
                $memory_usage['used_gb'] = $memory_usage['total_gb'] - $memory_usage['free_gb'];
            }
            
            $memory_usage['usage_percent'] = round(($memory_usage['used_gb'] / $memory_usage['total_gb']) * 100, 1);
        }
        
        return $memory_usage;
    }

    /**
     * Get top processes for a host
     */
    private function getHostProcesses(array $items): array {
        $processes = [];
        
        foreach ($items as $item) {
            // Look for process-related items
            if (strpos($item['key_'], 'proc.cpu.util') === 0 ||
                strpos($item['key_'], 'proc.mem') === 0 ||
                strpos($item['key_'], 'process.') === 0) {
                
                $item_data = API::Item()->get([
                    'itemids' => [$item['itemid']],
                    'output' => ['itemid', 'key_', 'name', 'lastvalue', 'lastclock'],
                    'selectLastvalue' => true
                ]);
                
                if (!empty($item_data) && isset($item_data[0]['lastvalue']) && is_numeric($item_data[0]['lastvalue']) && $item_data[0]['lastvalue'] > 0) {
                    // Extract process name from key
                    $process_name = $this->extractProcessName($item['key_'], $item['name']);
                    
                    if (!isset($processes[$process_name])) {
                        $processes[$process_name] = [
                            'name' => $process_name,
                            'cpu' => 0,
                            'memory' => 0
                        ];
                    }
                    
                    if (strpos($item['key_'], 'cpu') !== false) {
                        $processes[$process_name]['cpu'] = (float)$item_data[0]['lastvalue'];
                    } elseif (strpos($item['key_'], 'mem') !== false) {
                        $processes[$process_name]['memory'] = (float)$item_data[0]['lastvalue'];
                    }
                }
            }
        }
        
        // Sort by CPU usage and return top 10
        uasort($processes, function($a, $b) {
            return $b['cpu'] <=> $a['cpu'];
        });
        
        return array_slice($processes, 0, 10, true);
    }

    /**
     * Extract process name from item key or name
     */
    private function extractProcessName(string $key, string $name): string {
        // Try to extract from key first
        if (preg_match('/\[(.*?)\]/', $key, $matches)) {
            $process = $matches[1];
            // Clean up common patterns
            $process = str_replace(['"', "'", ',*'], '', $process);
            if (!empty($process) && $process !== '*') {
                return $process;
            }
        }
        
        // Fall back to item name
        $name = str_replace(['CPU utilization of ', 'Memory usage of ', 'Process '], '', $name);
        return !empty($name) ? $name : 'Unknown Process';
    }

    /**
     * Calculate costs for hosts
     */
    private function calculateCosts(array $hosts, array $pricing): array {
        foreach ($hosts as &$host) {
            // Base resource costs
            $host['cpu_cost_hourly'] = $host['cpu_cores'] * $pricing['per_cpu_core'];
            $host['memory_cost_hourly'] = $host['memory_gb'] * $pricing['per_memory_gb'];
            
            // Calculate usage-based costs
            $cpu_usage_percent = $host['cpu_usage']['total_usage'] ?? 0;
            $memory_usage_percent = $host['memory_usage']['usage_percent'] ?? 0;
            
            // Used vs Idle costs
            $host['cpu_used_cost_hourly'] = $host['cpu_cost_hourly'] * ($cpu_usage_percent / 100);
            $host['cpu_idle_cost_hourly'] = $host['cpu_cost_hourly'] * ((100 - $cpu_usage_percent) / 100);
            
            $host['memory_used_cost_hourly'] = $host['memory_cost_hourly'] * ($memory_usage_percent / 100);
            $host['memory_idle_cost_hourly'] = $host['memory_cost_hourly'] * ((100 - $memory_usage_percent) / 100);
            
            $host['total_used_cost_hourly'] = $host['cpu_used_cost_hourly'] + $host['memory_used_cost_hourly'];
            $host['total_idle_cost_hourly'] = $host['cpu_idle_cost_hourly'] + $host['memory_idle_cost_hourly'];
            $host['total_cost_hourly'] = $host['total_used_cost_hourly'] + $host['total_idle_cost_hourly'];
            
            // Monthly costs (24h * 30 days)
            $host['cpu_cost_monthly'] = $host['cpu_cost_hourly'] * 24 * 30;
            $host['memory_cost_monthly'] = $host['memory_cost_hourly'] * 24 * 30;
            $host['total_cost_monthly'] = $host['total_cost_hourly'] * 24 * 30;
            $host['total_used_cost_monthly'] = $host['total_used_cost_hourly'] * 24 * 30;
            $host['total_idle_cost_monthly'] = $host['total_idle_cost_hourly'] * 24 * 30;
            
            // Calculate cost per process
            $host['process_costs'] = [];
            $total_process_cpu = 0;
            
            foreach ($host['processes'] as $process_name => $process) {
                $total_process_cpu += $process['cpu'];
            }
            
            if ($total_process_cpu > 0) {
                foreach ($host['processes'] as $process_name => $process) {
                    $process_cpu_share = ($process['cpu'] / 100) * $host['cpu_cores'];
                    $process_memory_share = ($process['memory'] / 1024 / 1024 / 1024); // Convert to GB if in bytes
                    
                    $process_cpu_cost = $process_cpu_share * $pricing['per_cpu_core'];
                    $process_memory_cost = $process_memory_share * $pricing['per_memory_gb'];
                    
                    $host['process_costs'][$process_name] = [
                        'name' => $process_name,
                        'cpu_percent' => $process['cpu'],
                        'memory_gb' => $process_memory_share,
                        'cpu_cost_hourly' => $process_cpu_cost,
                        'memory_cost_hourly' => $process_memory_cost,
                        'total_cost_hourly' => $process_cpu_cost + $process_memory_cost,
                        'total_cost_monthly' => ($process_cpu_cost + $process_memory_cost) * 24 * 30
                    ];
                }
                
                // Sort by cost
                uasort($host['process_costs'], function($a, $b) {
                    return $b['total_cost_hourly'] <=> $a['total_cost_hourly'];
                });
            }
        }

        return $hosts;
    }

    /**
     * Sort hosts by specified field
     */
    private function sortHosts(array $hosts, string $sort_field, string $sort_order): array {
        $sort_fields = [
            'name' => 'name',
            'cpu_cores' => 'cpu_cores',
            'memory_gb' => 'memory_gb',
            'cpu_cost' => 'cpu_cost_hourly',
            'memory_cost' => 'memory_cost_hourly',
            'total_cost' => 'total_cost_hourly'
        ];

        if (isset($sort_fields[$sort_field])) {
            $field = $sort_fields[$sort_field];
            
            usort($hosts, function($a, $b) use ($field, $sort_order) {
                if ($field === 'name') {
                    $result = strcasecmp($a[$field], $b[$field]);
                } else {
                    $result = $a[$field] <=> $b[$field];
                }
                
                return $sort_order === ZBX_SORT_DOWN ? -$result : $result;
            });
        }

        return $hosts;
    }

    /**
     * Get host groups for multiselect
     */
    private function getHostGroups(array $groupids): array {
        if (empty($groupids)) {
            return [];
        }

        $host_groups = API::HostGroup()->get([
            'output' => ['groupid', 'name'],
            'groupids' => $groupids,
            'preservekeys' => true
        ]);

        return CArrayHelper::renameObjectsKeys($host_groups, ['groupid' => 'id']);
    }


}
