<?php declare(strict_types = 0);


namespace Modules\CostExplorer\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseRedirect;
use CRoleHelper;

class CControllerCostExplorerPricingUpdate extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        
        $fields = [
            'per_cpu_core' => 'required|string',
            'per_memory_gb' => 'required|string'
        ];

        $ret = $this->validateInput($fields);
        
        
        if (!$ret) {
            
        }

        // Additional validation for numeric values
        if ($ret) {
            $per_cpu_core = $this->getInput('per_cpu_core');
            $per_memory_gb = $this->getInput('per_memory_gb');   
            $cpu_numeric = is_numeric($per_cpu_core) && (float)$per_cpu_core > 0;
            $memory_numeric = is_numeric($per_memory_gb) && (float)$per_memory_gb > 0;
            
            if (!$cpu_numeric || !$memory_numeric) {
                
                $ret = false;
            }
        }

        if (!$ret) {
            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'error' => [
                        'messages' => ['Parâmetros inválidos fornecidos - valores devem ser números positivos']
                    ]
                ])
            ]));
        }

        
        return $ret;
    }

    protected function checkPermissions(): bool {
        $has_host_access = $this->checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
        $user_type = $this->getUserType();
        $is_admin = $user_type >= USER_TYPE_ZABBIX_ADMIN;
        $permission_granted = $has_host_access && $is_admin;
        return $permission_granted;
    }

    protected function doAction(): void {
        $per_cpu_core = $this->getInput('per_cpu_core');
        $per_memory_gb = $this->getInput('per_memory_gb');
        // Convert string values to float (already validated in checkInput)
        $per_cpu_core = (float)$per_cpu_core;
        $per_memory_gb = (float)$per_memory_gb;
        // Prepare pricing data
        $pricing_data = [
            'default' => false,
            'per_cpu_core' => (float)$per_cpu_core,
            'per_memory_gb' => (float)$per_memory_gb,
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => method_exists($this, 'getUserName') ? ($this->getUserName() ?? 'unknown') : 'admin'
        ];

        // Save to JSON file
        $pricing_file = dirname(__FILE__) . '/../data/price.json';
        $pricing_dir = dirname($pricing_file);
        // Create data directory if it doesn't exist
        if (!is_dir($pricing_dir)) {
            
            if (!mkdir($pricing_dir, 0755, true)) {
                
                $this->setResponse(new CControllerResponseData([
                    'main_block' => json_encode([
                        'error' => [
                            'messages' => ['Erro ao criar diretório de configuração']
                        ]
                    ])
                ]));
                return;
            }
            
        }

        // Backup current file if exists
        if (file_exists($pricing_file)) {
            $backup_content = file_get_contents($pricing_file);
            
        }
        $json_data = json_encode($pricing_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        $result = file_put_contents($pricing_file, $json_data);
        if ($result === false) {

            $this->setResponse(new CControllerResponseData([
                'main_block' => json_encode([
                    'error' => [
                        'messages' => ['Erro ao salvar configuração de preços']
                    ]
                ])
            ]));
            return;
        }

        // Verify file was actually updated
        $verify_content = file_get_contents($pricing_file);

        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode([
                'success' => [
                    'messages' => [
                        'Configuração de preços salva com sucesso',
                        "CPU: \$$per_cpu_core por core/hora",
                        "Memória: \$$per_memory_gb por GB/hora"
                    ]
                ]
            ])
        ]));
    }
}
