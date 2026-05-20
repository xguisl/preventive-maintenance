<?php

/**
 * -------------------------------------------------------------------------
 * Plugin de Manutenção Preventiva para GLPI
 * -------------------------------------------------------------------------
 *
 * LICENÇA
 *
 * Este arquivo é parte do Plugin de Manutenção Preventiva.
 *
 * Manutenção Preventiva é um software livre; você pode redistribuí-lo e/ou modificar
 * sob os termos da Licença Pública Geral GNU conforme publicada pela
 * Free Software Foundation; ou versão 2 da Licença, ou
 * (a seu critério) qualquer versão posterior.
 * 
 * Manutenção Preventiva é distribuído na esperança de que seja útil,
 * mas SEM QUALQUER GARANTIA; sem mesmo a garantia implícita de
 * COMERCIALIZAÇÃO ou ADEQUAÇÃO A UM DETERMINADO FIM. Veja o
 * GNU General Public License para mais detalhes.
 *
 * Você deve ter recebido uma cópia da Licença Pública Geral GNU
 * junto com o Manutenção Preventiva. Se não, veja <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2025 William Oliveira Santos / WIDA Work Information Development Analytics
 * @license   GPLv2+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link      [URL do seu plugin ou repositório GitHub]
 * -------------------------------------------------------------------------
 */

/**
 * -------------------------------------------------------------------------
 * Preventive Maintenance plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Preventive Maintenance.
 *
 * Preventive Maintenance is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * Preventive Maintenance is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Preventive Maintenance. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2025 William Oliveira Santos / WIDA Work Information Development Analytics
 * @license   GPLv2+ https://www.gnu.org/licenses/gpl-2.0.html
 * @link      [Your Plugin URL or GitHub Repository]
 * -------------------------------------------------------------------------
 */

// Inclui arquivos necessários do GLPI
include('../../../inc/includes.php');

// Verificação de segurança
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

// Verifica permissões
Session::checkRight('plugin_preventivemaintenance', READ);

// Instancia a classe principal
$pm = new PluginPreventivemaintenancePreventivemaintenance();

// Função para obter configuração
function getPluginConfig($name) {
    global $DB;
    
    $criteria = [
        'SELECT' => ['value'],
        'FROM' => 'glpi_plugin_preventivemaintenance_config',
        'WHERE' => ['name' => $name],
        'LIMIT' => 1
    ];
    
    $iterator = $DB->request($criteria);
    
    if (count($iterator)) {
        $data = $iterator->current();
        return $data['value'];
    }
    
    return false;
}

// Função para atualizar configuração
function updatePluginConfig($name, $value) {
    global $DB;
    
    $existing = getPluginConfig($name);
    $now = date('Y-m-d H:i:s');
    
    if ($existing !== false) {
        return $DB->update('glpi_plugin_preventivemaintenance_config', [
            'value' => $value,
            'date_mod' => $now
        ], [
            'name' => $name
        ]);
    } else {
        return $DB->insert('glpi_plugin_preventivemaintenance_config', [
            'name' => $name,
            'value' => $value,
            'date_creation' => $now,
            'date_mod' => $now
        ]);
    }
}

// Obtém configuração do Auto Ticket
$auto_ticket = getPluginConfig('auto_ticket');
if ($auto_ticket === false) {
    $auto_ticket = '0';
    updatePluginConfig('auto_ticket', $auto_ticket);
}
$auto_ticket_enabled = ($auto_ticket === '1');

// Obtém configuração da entidade de destino para tickets
$ticket_entity_config = getPluginConfig('ticket_entity');
if ($ticket_entity_config === false) {
    $ticket_entity_config = '0';
    updatePluginConfig('ticket_entity', $ticket_entity_config);
}

// Processa salvamento da entidade de destino
if (isset($_POST['save_ticket_entity'])) {
    $new_entity = isset($_POST['ticket_entity']) ? (int)$_POST['ticket_entity'] : 0;
    updatePluginConfig('ticket_entity', (string)$new_entity);
    $ticket_entity_config = (string)$new_entity;
    Session::addMessageAfterRedirect(
        __('Entidade de destino dos tickets atualizada com sucesso!'),
        true,
        INFO
    );
    Html::redirect('preventivemaintenance.php');
}

// Obtém configurações de notificação Teams
$teams_webhook = getPluginConfig('teams_webhook');
if ($teams_webhook === false) {
    $teams_webhook = '';
    updatePluginConfig('teams_webhook', $teams_webhook);
}
$notify_days_before = getPluginConfig('notify_days_before');
if ($notify_days_before === false) {
    $notify_days_before = '3';
    updatePluginConfig('notify_days_before', $notify_days_before);
}

// Processa salvamento das configurações de notificação
if (isset($_POST['save_notification_config'])) {
    $new_webhook = isset($_POST['teams_webhook']) ? trim($_POST['teams_webhook']) : '';
    $new_days = isset($_POST['notify_days_before']) ? (int)$_POST['notify_days_before'] : 3;
    updatePluginConfig('teams_webhook', $new_webhook);
    updatePluginConfig('notify_days_before', (string)$new_days);
    $teams_webhook = $new_webhook;
    $notify_days_before = (string)$new_days;
    Session::addMessageAfterRedirect(
        __('Configurações de notificação atualizadas com sucesso!'),
        true,
        INFO
    );
    Html::redirect('preventivemaintenance.php');
}

// Função para enviar notificação via Teams
function sendTeamsNotification($webhook_url, $message) {
    $payload = json_encode([
        'text' => $message
    ]);
    
    $options = [
        'http' => [
            'header'  => 'Content-Type: application/json',
            'method'  => 'POST',
            'content' => $payload
        ]
    ];
    
    $context  = stream_context_create($options);
    $result = @file_get_contents($webhook_url, false, $context);
    
    return $result !== false;
}

// Inicializa filtros
$filters = [
    'status' => 'all',
    'date_from' => '',
    'date_to' => '',
    'technician' => 0,
    'entity' => 0,
    'computer_name' => ''
];

// Atualiza filtros da requisição
if (isset($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}
if (isset($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}
if (isset($_GET['technician'])) {
    $filters['technician'] = (int)$_GET['technician'];
}
if (isset($_GET['entity'])) {
    $filters['entity'] = (int)$_GET['entity'];
}
if (isset($_GET['computer_name'])) {
    $filters['computer_name'] = trim($_GET['computer_name']);
}

// Processa exclusão
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0 && $pm->canDelete()) {
        if ($pm->delete(['id' => $id])) {
            Session::addMessageAfterRedirect(
                __('Registro apagado com sucesso!'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                __('Falha ao apagar registro!'),
                false,
                ERROR
            );
        }
        Html::redirect('preventivemaintenance.php');
    }
}

// Processa exclusão em lote
if (isset($_POST['bulk_delete']) && isset($_POST['selected_items']) && is_array($_POST['selected_items'])) {
    if ($pm->canDelete()) {
        $selected_ids = array_map('intval', $_POST['selected_items']);
        $success_count = 0;
        $error_count = 0;
        
        foreach ($selected_ids as $id) {
            if ($id > 0 && $pm->delete(['id' => $id])) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        
        if ($success_count > 0) {
            Session::addMessageAfterRedirect(
                sprintf(__('%d registros apagados com sucesso!'), $success_count),
                true,
                INFO
            );
        }
        if ($error_count > 0) {
            Session::addMessageAfterRedirect(
                sprintf(__('%d registros falharam ao apagar.'), $error_count),
                false,
                ERROR
            );
        }
        Html::redirect('preventivemaintenance.php');
    }
}

// Processa toggle do Auto Ticket
if (isset($_GET['toggle_auto_ticket'])) {
    $new_value = $auto_ticket_enabled ? '0' : '1';
    if (updatePluginConfig('auto_ticket', $new_value)) {
        $auto_ticket_enabled = !$auto_ticket_enabled;
        Session::addMessageAfterRedirect(
            $auto_ticket_enabled ? __('Auto ticket ativado com sucesso!') : __('Auto ticket desativado com sucesso!'),
            true,
            INFO
        );
    } else {
        Session::addMessageAfterRedirect(
            __('Falha ao atualizar a configuração do auto ticket!'),
            false,
            ERROR
        );
    }
    Html::redirect('preventivemaintenance.php');
}

// Função para verificar tickets abertos
function hasOpenMaintenanceTicket($computer_id, $maintenance_name) {
    global $DB;
    
    if (empty($computer_id) || !is_numeric($computer_id) || empty($maintenance_name)) {
        return false;
    }
    
    try {
        // Busca tickets abertos (não resolvidos/fechados) associados ao computador
        // via tabela glpi_items_tickets
        $query = "SELECT t.id, t.name, t.status 
                  FROM glpi_tickets t
                  INNER JOIN glpi_items_tickets it ON it.tickets_id = t.id
                  WHERE it.items_id = " . (int)$computer_id . "
                  AND it.itemtype = 'Computer'
                  AND t.name LIKE '%" . $DB->escape($maintenance_name) . "%'
                  AND t.status NOT IN (" . Ticket::CLOSED . ", " . Ticket::SOLVED . ")
                  LIMIT 1";
        
        $result = $DB->query($query);
        if ($result && $result->num_rows > 0) {
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        Toolbox::logError("Erro ao verificar tickets existentes: " . $e->getMessage());
        return false;
    }
}

// Função para registrar ticket
function registerMaintenanceTicket($ticket_id, $computer_id, $maintenance_name) {
    global $DB;
    
    try {
        $DB->insert('glpi_plugin_preventivemaintenance_tickets', [
            'ticket_id' => (int)$ticket_id,
            'computer_id' => (int)$computer_id,
            'maintenance_name' => $maintenance_name,
            'date_creation' => date('Y-m-d H:i:s')
        ]);
        return true;
    } catch (Exception $e) {
        Toolbox::logError("Erro ao registrar o ticket: " . $e->getMessage());
        return false;
    }
}

// Função para atualizar manutenção
function updateMaintenanceOnTicketResolution($ticket_id) {
    global $DB;
    
    try {
        $criteria = [
            'SELECT' => ['computer_id', 'maintenance_name'],
            'FROM' => 'glpi_plugin_preventivemaintenance_tickets',
            'WHERE' => [
                'ticket_id' => (int)$ticket_id
            ],
            'LIMIT' => 1
        ];
        
        $iterator = $DB->request($criteria);
        
        if (count($iterator)) {
            $data = $iterator->current();
            $computer_id = $data['computer_id'];
            $maintenance_name = $data['maintenance_name'];
            
            $pm = new PluginPreventivemaintenancePreventivemaintenance();
            $maintenance = $pm->find([
                'items_id' => $computer_id,
                'name' => $maintenance_name
            ]);
            
            if (count($maintenance)) {
                $maintenance_data = current($maintenance);
                $maintenance_id = $maintenance_data['id'];
                
                $ticket = new Ticket();
                if ($ticket->getFromDB($ticket_id)) {
                    $solvedate = $ticket->getField('solvedate');
                    
                    if (!empty($solvedate)) {
                        $last_date = $maintenance_data['last_maintenance_date'];
                        $next_date = $maintenance_data['next_maintenance_date'];
                        
                        if (!empty($last_date) && !empty($next_date)) {
                            $last_timestamp = strtotime($last_date);
                            $next_timestamp = strtotime($next_date);
                            $interval = $next_timestamp - $last_timestamp;
                            
                            $new_next_date = date('Y-m-d H:i:s', strtotime($solvedate) + $interval);
                            
                            $input = [
                                'id' => $maintenance_id,
                                'name' => $maintenance_name,
                                'last_maintenance_date' => $solvedate,
                                'next_maintenance_date' => $new_next_date
                            ];
                            
                            if ($pm->update($input)) {
                                $DB->delete('glpi_plugin_preventivemaintenance_tickets', [
                                    'ticket_id' => $ticket_id
                                ]);
                                return true;
                            }
                        }
                    }
                }
            }
        }
        return false;
    } catch (Exception $e) {
        Toolbox::logError("Erro ao atualizar a manutenção: " . $e->getMessage());
        return false;
    }
}

// Função para limpar tickets resolvidos
function cleanResolvedMaintenanceTickets() {
    global $DB;
    
    try {
        $criteria = [
            'SELECT' => ['glpi_tickets.id', 'glpi_tickets.solvedate'],
            'FROM' => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_plugin_preventivemaintenance_tickets' => [
                    'ON' => [
                        'glpi_plugin_preventivemaintenance_tickets' => 'ticket_id',
                        'glpi_tickets' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_tickets.status' => [Ticket::CLOSED, Ticket::SOLVED]
            ]
        ];
        
        $iterator = $DB->request($criteria);
        
        foreach ($iterator as $data) {
            updateMaintenanceOnTicketResolution($data['id']);
            
            $DB->delete('glpi_plugin_preventivemaintenance_tickets', [
                'ticket_id' => $data['id']
            ]);
        }
        
        return true;
    } catch (Exception $e) {
        Toolbox::logError("Erro ao limpar tickets resolvidos: " . $e->getMessage());
        return false;
    }
}

// Função para criar ticket
function createMaintenanceTicket($computer_id, $maintenance_name, $technician_id) {
    global $ticket_entity_config;
    
    if (hasOpenMaintenanceTicket($computer_id, $maintenance_name)) {
        return false;
    }
    
    $ticket = new Ticket();
    
    // Busca o nome do computador para incluir no título
    $computer = new Computer();
    $computer_name = '';
    if ($computer->getFromDB($computer_id)) {
        $computer_name = $computer->getName();
    }
    
    // Busca a descrição da manutenção e o usuário criador
    global $DB;
    $maintenance_description = '';
    $created_by_user_id = Session::getLoginUserID(); // Default to current user
    $maintenance_query = "SELECT description, created_by FROM glpi_plugin_preventivemaintenance_preventivemaintenances 
                          WHERE items_id = " . (int)$computer_id . " AND name = '" . $DB->escape($maintenance_name) . "' LIMIT 1";
    $maintenance_result = $DB->query($maintenance_query);
    if ($maintenance_result && $maintenance_result->num_rows > 0) {
        $maintenance_data = $maintenance_result->fetch_assoc();
        $maintenance_description = $maintenance_data['description'] ?? '';
        $created_by_user_id = (int)($maintenance_data['created_by'] ?? Session::getLoginUserID());
    }
    
    // Busca a categoria "Manutenção Preventiva"
    $category = new ITILCategory();
    $category_id = 0;
    $categories = $category->find(['name' => 'Manutenção Preventiva']);
    if (count($categories) > 0) {
        $category_data = current($categories);
        $category_id = $category_data['id'];
    }
    
    // Monta o conteúdo do ticket com a descrição
    $content = sprintf('O computador %s requer manutenção preventiva para: %s', $computer_name, $maintenance_name);
    if (!empty($maintenance_description)) {
        $content .= "<br><br><strong>" . __('Descrição:') . "</strong><br>" . nl2br(htmlspecialchars($maintenance_description));
    }
    
    $input = [
        'name' => sprintf('🔧 Manutenção Preventiva: %s - %s', $computer_name, $maintenance_name),
        'content' => $content,
        'type' => Ticket::INCIDENT_TYPE,
        'status' => Ticket::INCOMING,
        'urgency' => 5,
        'impact' => 5,
        'priority' => 5,
        'requesttypes_id' => 1,
        'users_id_recipient' => Session::getLoginUserID(),
        'users_id_requester' => $created_by_user_id,
        'entities_id' => (int)$ticket_entity_config,
        'date' => date('Y-m-d H:i:s')
    ];
    
    // Adiciona a categoria se encontrada
    if ($category_id > 0) {
        $input['itilcategories_id'] = $category_id;
    }
    
    if (!empty($technician_id)) {
        $input['_observers']['_users_id_observer'] = [(int)$technician_id];
    }
    
    try {
        $ticket_id = $ticket->add($input);
        if ($ticket_id) {
            // Associa o computador ao ticket usando Item_Ticket
            $item_ticket = new Item_Ticket();
            $item_ticket->add([
                'tickets_id' => $ticket_id,
                'itemtype' => 'Computer',
                'items_id' => (int)$computer_id
            ]);
            
            registerMaintenanceTicket($ticket_id, $computer_id, $maintenance_name);
            return $ticket_id;
        }
        return false;
    } catch (Exception $e) {
        Toolbox::logError("Erro ao criar o ticket: " . $e->getMessage());
        return false;
    }
}

// Limpa tickets resolvidos
cleanResolvedMaintenanceTickets();

// Cria tickets automáticos e envia notificações se habilitado
if ($auto_ticket_enabled) {
    $all_maintenances = $pm->find([], 'next_maintenance_date ASC');
    $notify_days = (int)$notify_days_before;
    $notify_seconds = $notify_days * 86400;
    
    foreach ($all_maintenances as $item) {
        if (!empty($item['last_maintenance_date']) && !empty($item['next_maintenance_date'])) {
            $last = strtotime($item['last_maintenance_date']);
            $next = strtotime($item['next_maintenance_date']);
            $now = time();
            
            $total_days = $next - $last;
            $elapsed_days = $now - $last;
            
            if ($total_days > 0) {
                $percent = min(100, max(0, round(($elapsed_days / $total_days) * 100)));
                
                // Notificação Teams: X dias antes da próxima manutenção
                if (!empty($teams_webhook) && $notify_days > 0) {
                    $days_until_next = floor(($next - $now) / 86400);
                    $notified_key = 'teams_notified_' . $item['id'] . '_' . date('Ymd', $next);
                    $already_notified = getPluginConfig($notified_key);
                    
                    if ($days_until_next <= $notify_days && $days_until_next >= 0 && $already_notified === false) {
                        $computer = new Computer();
                        $computer_name = $computer->getFromDB($item['items_id']) ? $computer->getName() : 'N/A';
                        $technician_name = isset($technicians_data[$item['technician_id']]) ? $technicians_data[$item['technician_id']] : '-';
                        
                        $msg = "⚠️ **Manutenção Preventiva se aproximando!**\n";
                        $msg .= "📋 **Manutenção:** {$item['name']}\n";
                        $msg .= "💻 **Computador:** {$computer_name}\n";
                        $msg .= "🔧 **Técnico:** {$technician_name}\n";
                        $msg .= "📅 **Próxima manutenção:** {$item['next_maintenance_date']}\n";
                        $msg .= "⏰ **Dias restantes:** {$days_until_next} dia(s)";
                        
                        sendTeamsNotification($teams_webhook, $msg);
                        updatePluginConfig($notified_key, '1');
                    }
                }
                
                if ($percent >= 99) {
                    $has_open = hasOpenMaintenanceTicket($item['items_id'], $item['name']);
                    
                    if (!$has_open) {
                        $ticket_id = createMaintenanceTicket(
                            $item['items_id'],
                            $item['name'],
                            $item['technician_id']
                        );
                        
                        if ($ticket_id) {
                            Session::addMessageAfterRedirect(
                                sprintf(__('Ticket criado automaticamente para manutenção preventiva: %s'), $item['name']),
                                true,
                                INFO
                            );
                        }
                    }
                }
            }
        }
    }
}

// Prepara critérios de busca
$criteria = [];
if (!empty($filters['technician'])) {
    $criteria['technician_id'] = (int)$filters['technician'];
}
if (!empty($filters['entity'])) {
    $criteria['entities_id'] = (int)$filters['entity'];
}

// Adiciona filtros de data
if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
    $date_criteria = [];
    if (!empty($filters['date_from'])) {
        $date_criteria[] = ['next_maintenance_date' => ['>=', $filters['date_from']]];
    }
    if (!empty($filters['date_to'])) {
        $date_criteria[] = ['next_maintenance_date' => ['<=', $filters['date_to']]];
    }
    $criteria[] = ['AND' => $date_criteria];
}

// Busca itens
$all_items = $pm->find($criteria, 'entities_id, next_maintenance_date ASC');

// Aplica filtro por nome do computador se especificado
if (!empty($filters['computer_name'])) {
    $computer = new Computer();
    $matching_computers = $computer->find(['name' => ['LIKE', '%' . $filters['computer_name'] . '%']]);
    $matching_ids = array_keys($matching_computers);
    
    if (!empty($matching_ids)) {
        $all_items = array_filter($all_items, function($item) use ($matching_ids) {
            return in_array($item['items_id'], $matching_ids);
        });
    } else {
        $all_items = [];
    }
}

// Obtém lista de técnicos cadastrados nas manutenções
$technicians_in_maintenance = [];
foreach ($all_items as $item) {
    if ($item['technician_id'] > 0) {
        $technicians_in_maintenance[$item['technician_id']] = $item['technician_id'];
    }
}

// Carrega os dados dos técnicos (com nome completo - realname)
$technicians_data = [];
if (!empty($technicians_in_maintenance)) {
    $user = new User();
    $technicians_iterator = $user->find(['id' => array_values($technicians_in_maintenance)]);
    foreach ($technicians_iterator as $tech) {
        $technicians_data[$tech['id']] = formatUserName($tech['id'], $tech['name'], $tech['realname'], $tech['firstname']);
    }
}

// Função para obter caminho da entidade
function getFullEntityPath($entity_id) {
    $entity = new Entity();
    if ($entity->getFromDB($entity_id)) {
        $path = $entity->getName();
        $parent_id = $entity->getField('entities_id');
        
        while ($parent_id > 0) {
            $parent = new Entity();
            if ($parent->getFromDB($parent_id)) {
                $path = $parent->getName().' > '.$path;
                $parent_id = $parent->getField('entities_id');
            } else {
                break;
            }
        }
        return $path;
    }
    return '';
}

// Agrupa itens por entidade
$items_by_entity = [];
foreach ($all_items as $item) {
    $entity_id = $item['entities_id'];
    $entity_path = getFullEntityPath($entity_id);
    
    if ($entity_path !== '') {
        if (!isset($items_by_entity[$entity_path])) {
            $items_by_entity[$entity_path] = [
                'name' => $entity_path,
                'items' => []
            ];
        }
        
        $last = !empty($item['last_maintenance_date']) ? strtotime($item['last_maintenance_date']) : 0;
        $next = !empty($item['next_maintenance_date']) ? strtotime($item['next_maintenance_date']) : 0;
        $now = time();
        
        $include_item = true;
        
        if ($filters['status'] !== 'all' && $next > 0 && $last > 0) {
            $total_days = $next - $last;
            $elapsed_days = $now - $last;
            
            $percent = ($total_days > 0) ? min(100, max(0, round(($elapsed_days / $total_days) * 100))) : 0;
            
            switch ($filters['status']) {
                case 'ontime':
                    if ($percent >= 80) $include_item = false;
                    break;
                case 'warning':
                    if ($percent < 80 || $percent >= 98) $include_item = false;
                    break;
                case 'urgent':
                    if ($percent < 98) $include_item = false;
                    break;
                case 'undefined':
                    if ($next > 0 && $last > 0) $include_item = false;
                    break;
            }
        }
        
        if ($include_item) {
            $items_by_entity[$entity_path]['items'][] = $item;
        }
    }
}

// Ordena entidades
uksort($items_by_entity, function($a, $b) {
    return strnatcasecmp($a, $b);
});

// Exibe cabeçalho
Html::header(
    __('Preventive Maintenance', 'preventivemaintenance'),
    $_SERVER['PHP_SELF'],
    'plugins',
    'preventivemaintenance'
);
?>

<style>
    body {
        background-color: #cacccf !important;
    }
    .plugin-preventive-maintenance-container {
        background-color: white;
        padding: 25px;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        margin: 20px auto;
        max-width: 98%;
    }
    .entity-group {
        margin-bottom: 40px;
        border: 1px solid #e0e0e0;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .entity-title {
        background: #cacccf;
        padding: 15px 25px;
        font-size: 1.3rem;
        font-weight: 600;
        color: #2c3e50;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        align-items: center;
    }
    .entity-title i {
        margin-right: 12px;
        color: #6c757d;
        font-size: 1.2em;
    }
    .table {
        width: 100%;
        margin-bottom: 0;
        border-collapse: collapse;
    }
    .table thead th {
        vertical-align: middle;
        text-align: center;
        background-color: #f1f3f5;
        font-weight: 600;
        border-bottom-width: 2px;
        color: #495057;
        padding: 12px 8px;
    }
    .table td {
        vertical-align: middle;
        padding: 10px 8px;
        border-bottom: 1px solid #e0e0e0;
        text-align: center;
    }
    .table-hover tbody tr:hover {
        background-color: rgba(0, 123, 255, 0.03);
    }
    .custom-footer {
        text-align: center;
        padding: 20px;
        margin-top: 40px;
        color: #6c757d;
        font-size: 0.9rem;
        border-top: 1px solid #e0e0e0;
        background-color: #f8f9fa;
    }
    .progress {
        height: 28px;
        border-radius: 6px;
        background-color: #e9ecef;
        overflow: hidden;
    }
    .progress-bar {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 500;
        color: white;
    }
    .action-buttons {
        display: flex;
        gap: 5px;
        justify-content: center;
    }
    .advanced-filters {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid #dee2e6;
        display: none;
    }
    .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 10px;
    }
    .filter-group {
        flex: 1;
        min-width: 180px;
    }
    .filter-group.small {
        flex: 0.5;
        min-width: 120px;
    }
    .filter-title {
        font-weight: 600;
        margin-bottom: 5px;
        color: #495057;
        font-size: 0.9rem;
    }
    .filter-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 10px;
    }
    .form-control {
        width: 100%;
        padding: 6px 10px;
        font-size: 0.9rem;
        border: 1px solid #ced4da;
        border-radius: 4px;
    }
    .toggle-btn {
        position: relative;
        display: inline-block;
        width: 60px;
        height: 30px;
        border-radius: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-left: 10px;
        vertical-align: middle;
    }
    .toggle-btn .toggle-knob {
        position: absolute;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: white;
        top: 2px;
        left: 2px;
        transition: all 0.3s ease;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    .toggle-btn.on {
        background: #28a745;
    }
    .toggle-btn.off {
        background: #dc3545;
    }
    .toggle-btn.on .toggle-knob {
        left: 32px;
    }
    .toggle-btn.off .toggle-knob {
        left: 2px;
    }
    .toggle-container {
        display: inline-flex;
        align-items: center;
        margin-left: 15px;
    }
    .toggle-label {
        margin-right: 5px;
        font-weight: 500;
        color: #495057;
    }
    
    /* Donation button styles */
    .donation-button {
        position: fixed;
        bottom: 30px;
        right: 30px;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background-color: #28a745;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        cursor: pointer;
        z-index: 1000;
        transition: all 0.3s ease;
    }
    .donation-button:hover {
        background-color: #218838;
        transform: scale(1.1);
    }
    .donation-qr-container {
        position: fixed;
        bottom: 100px;
        right: 30px;
        background-color: white;
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 5px 25px rgba(0,0,0,0.2);
        z-index: 1001;
        display: none;
        flex-direction: column;
        align-items: center;
        max-width: 300px;
    }
    .donation-qr-container.show {
        display: flex;
    }
    .donation-qr-code {
        width: 200px;
        height: 200px;
        margin-bottom: 15px;
        background-color: white;
        padding: 10px;
        border: 1px solid #ddd;
    }
    .donation-message {
        text-align: center;
        font-size: 14px;
        color: #333;
        margin-top: 10px;
    }
    .pix-code-container {
        width: 100%;
        margin-top: 15px;
        position: relative;
    }
    .pix-code {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 12px;
        word-break: break-all;
        background-color: #f8f9fa;
    }
    .copy-pix-btn {
        position: absolute;
        right: 5px;
        top: 5px;
        background: #28a745;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 2px 8px;
        font-size: 12px;
        cursor: pointer;
    }
    .copy-pix-btn:hover {
        background: #218838;
    }
    .copy-notification {
        position: fixed;
        bottom: 180px;
        right: 30px;
        background: #28a745;
        color: white;
        padding: 8px 15px;
        border-radius: 4px;
        z-index: 1002;
        display: none;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    
    @media (max-width: 768px) {
        .donation-button {
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            font-size: 20px;
        }
        .donation-qr-container {
            bottom: 80px;
            right: 20px;
            max-width: 250px;
        }
        .donation-qr-code {
            width: 180px;
            height: 180px;
        }
        .copy-notification {
            bottom: 160px;
            right: 20px;
        }
    }
</style>

<div class="plugin-preventive-maintenance-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
        <h2 style="margin: 0;"><i class="fas fa-calendar-check" style="margin-right: 10px;"></i><?= __('Preventive Maintenance') ?></h2>
        <div style="display: flex; align-items: center;">
            <?php if ($pm->canCreate()): ?>
                <a href="preventivemaintenance.form.php" class="btn btn-primary" style="margin-right: 10px;">
                    <i class="fas fa-plus"></i> <?= "&nbsp",__('Add') ?>
                </a>
            <?php endif; ?>
            <button id="toggleFilters" class="btn btn-outline-info">
                <i class="fas fa-filter"></i>  <?="&nbsp", __('Filters') ?>
            </button>
            
            <div class="toggle-container">
                <span class="toggle-label"><?= __('Auto Ticket') ?></span>
                <a href="preventivemaintenance.php?toggle_auto_ticket=1" 
                   class="toggle-btn <?= $auto_ticket_enabled ? 'on' : 'off' ?>" 
                   title="<?= $auto_ticket_enabled ? __('Disable Auto Ticket') : __('Enable Auto Ticket') ?>">
                    <span class="toggle-knob"></span>
                </a>
            </div>
            
            <form method="post" style="display: flex; align-items: center; gap: 8px; margin-left: 10px;">
                <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
                <span class="toggle-label"><?= __('Entidade Ticket') ?>:</span>
                <select name="ticket_entity" class="form-select form-select-sm" style="width: auto; min-width: 150px;">
                    <?php
                    $entity_query = "SELECT id, completename FROM glpi_entities ORDER BY completename ASC";
                    $entity_result = $DB->query($entity_query);
                    if ($entity_result) {
                        while ($ent_row = $entity_result->fetch_assoc()) {
                            $selected = ((int)$ticket_entity_config == $ent_row['id']) ? 'selected' : '';
                            echo "<option value='{$ent_row['id']}' $selected>{$ent_row['completename']}</option>";
                        }
                    }
                    ?>
                </select>
                <button type="submit" name="save_ticket_entity" value="1" class="btn btn-sm btn-outline-success">
                    <i class="fas fa-save"></i>
                </button>
            </form>
            
            <button id="toggleNotifConfig" class="btn btn-sm btn-outline-warning" style="margin-left: 10px;" title="Configurar Notificações">
                <i class="fas fa-bell"></i>
            </button>
        </div>
    </div>

    <!-- Painel de Configuração de Notificações -->
    <div id="notifConfigPanel" style="display: none; margin-bottom: 20px; padding: 20px; background: #fff8e1; border: 2px solid #ffc107; border-radius: 8px;">
        <h5 style="margin-bottom: 15px;"><i class="fas fa-bell" style="margin-right: 8px;"></i><?= __('Notificações Microsoft Teams') ?></h5>
        <form method="post" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 15px;">
            <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
            <div style="flex: 1; min-width: 300px;">
                <label class="form-label"><strong>Webhook URL do Teams</strong></label>
                <input type="url" name="teams_webhook" class="form-control form-control-sm" 
                       value="<?= htmlspecialchars($teams_webhook) ?>" 
                       placeholder="https://outlook.office.com/webhook/...">
            </div>
            <div style="width: 120px;">
                <label class="form-label"><strong>Dias antes</strong></label>
                <input type="number" name="notify_days_before" class="form-control form-control-sm" 
                       value="<?= (int)$notify_days_before ?>" min="1" max="30">
            </div>
            <button type="submit" name="save_notification_config" value="1" class="btn btn-sm btn-success">
                <i class="fas fa-save"></i> Salvar
            </button>
        </form>
        <small class="text-muted" style="display: block; margin-top: 8px;">
            Para criar o webhook: Teams > Canal > (...) > Conectores > Webhook de Entrada > Configurar
        </small>
    </div>

    <div id="advancedFilters" class="advanced-filters" style="<?= isset($_GET['filter_applied']) ? '' : 'display: none;' ?>">
        <form method="get" action="">
            <input type="hidden" name="filter_applied" value="1">
            
            <div class="filter-row">
                <div class="filter-group small">
                    <div class="filter-title"><?= __('Status') ?></div>
                    <select name="status" class="form-control">
                        <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>><?= __('Todos') ?></option>
                        <option value="ontime" <?= $filters['status'] === 'ontime' ? 'selected' : '' ?>><?= __('Em dia') ?></option>
                        <option value="warning" <?= $filters['status'] === 'warning' ? 'selected' : '' ?>><?= __('Atenção') ?></option>
                        <option value="urgent" <?= $filters['status'] === 'urgent' ? 'selected' : '' ?>><?= __('Urgente') ?></option>
                        <option value="undefined" <?= $filters['status'] === 'undefined' ? 'selected' : '' ?>><?= __('Indefinido') ?></option>
                    </select>
                </div>
                
                <div class="filter-group small">
                    <div class="filter-title"><?= __('De') ?></div>
                    <input type="date" name="date_from" class="form-control" value="<?= $filters['date_from'] ?>">
                </div>
                
                <div class="filter-group small">
                    <div class="filter-title"><?= __('Até') ?></div>
                    <input type="date" name="date_to" class="form-control" value="<?= $filters['date_to'] ?>">
                </div>
                
                <div class="filter-group">
                    <div class="filter-title"><?= __('Technician') ?></div>
                    <select name="technician" class="form-control">
                        <option value="0"><?= __('Todos') ?></option>
                        <?php
                        if (!empty($technicians_data)) {
                            foreach ($technicians_data as $tech_id => $tech_name) {
                                $selected = ($filters['technician'] == $tech_id) ? 'selected' : '';
                                echo "<option value='$tech_id' $selected>$tech_name</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <div class="filter-title"><?= __('Entity') ?></div>
                    <?php 
                    $entity_options = [
                        'name' => 'entity',
                        'value' => $filters['entity'],
                        'display' => false,
                        'width' => '100%'
                    ];
                    echo Entity::dropdown($entity_options);
                    ?>
                </div>
                
                <div class="filter-group">
                    <div class="filter-title"><?= __('Nome do Computador') ?></div>
                    <input type="text" name="computer_name" class="form-control" value="<?= htmlspecialchars($filters['computer_name']) ?>" placeholder="Buscar por nome...">
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-filter"></i> <?= __('Aplicar') ?>
                </button>
                <a href="preventivemaintenance.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times"></i> <?= __('Limpar') ?>
                </a>
            </div>
        </form>
    </div>

    <?php if (empty($items_by_entity)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> <?= __('Nenhum registro encontrado.') ?>
        </div>
    <?php else: ?>
        <?php if ($pm->canDelete()): ?>
        <form method="post" id="bulkDeleteForm">
            <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
            <input type="hidden" name="bulk_delete" value="1">
            <div style="margin-bottom: 15px; text-align: right;">
                <button type="submit" class="btn btn-danger" onclick="return confirm('<?= __('Deseja realmente excluir os registros selecionados?') ?>');">
                    <i class="fas fa-trash"></i> <?= __('Excluir Selecionados') ?>
                </button>
            </div>
        <?php endif; ?>
        
        <?php foreach ($items_by_entity as $entity): ?>
            <?php if (empty($entity['items'])) continue; ?>
            
            <div class="entity-group">
                <div class="entity-title">
                    <i class="fas fa-building"></i> <?= $entity['name'] ?>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <?php if ($pm->canDelete()): ?>
                                <th style="text-align: center; width: 50px;">
                                    <input type="checkbox" id="selectAllCheckbox" onchange="toggleAllCheckboxes(this)">
                                </th>
                                <?php endif; ?>
                                <th style="text-align: center"><?= __('ID') ?></th>
                                <th style="text-align: center"><?= __('Nome/descr.') ?></th>
                                <th style="text-align: center"><?= __('Computer') ?></th>
                                <th style="text-align: center"><?= __('Technician') ?></th>
                                <th style="text-align: center"><?= __('Ult. Man.') ?></th>
                                <th style="text-align: center"><?= __('Prox. Man') ?></th>
                                <th style="text-align: center"><?= __('Status') ?></th>
                                <th style="text-align: center"><?= __('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entity['items'] as $item): ?>
                                <?php
                                $computer = new Computer();
                                $computer_name = $computer->getFromDB($item['items_id']) ? $computer->getName() : __('N/A');
                                
                                $technician_name = isset($technicians_data[$item['technician_id']]) ? $technicians_data[$item['technician_id']] : '-';
                                
                                $last = !empty($item['last_maintenance_date']) ? strtotime($item['last_maintenance_date']) : 0;
                                $next = !empty($item['next_maintenance_date']) ? strtotime($item['next_maintenance_date']) : 0;
                                $now = time();
                                
                                $status_html = "<span class='badge bg-secondary'>".__('Undefined')."</span>";
                                
                                if ($next > 0 && $last > 0) {
                                    $total_days = $next - $last;
                                    $elapsed_days = $now - $last;
                                    
                                    $percent = ($total_days > 0) ? min(100, max(0, round(($elapsed_days / $total_days) * 100))) : 0;
                                    
                                    if ($percent < 80) {
                                        $status_class = 'bg-success';
                                        $status_text = __('Em dia');
                                    } elseif ($percent >= 80 && $percent < 98) {
                                        $status_class = 'bg-warning';
                                        $status_text = __('Atenção');
                                    } else {
                                        $status_class = 'bg-danger';
                                        $status_text = __('Urgente');
                                    }
                                    
                                    $status_html = "<div class='progress'>
                                        <div class='progress-bar $status_class' role='progressbar' style='width: $percent%'>
                                            <strong>$percent% - $status_text</strong>
                                        </div>";
                                }
                                ?>
                                <tr>
                                    <?php if ($pm->canDelete()): ?>
                                    <td style="text-align: center">
                                        <input type="checkbox" name="selected_items[]" value="<?= $item['id'] ?>" class="item-checkbox">
                                    </td>
                                    <?php endif; ?>
                                    <td style="text-align: center"><?= $item['id'] ?></td>
                                    <td style="text-align: center"><?= $item['name'] ?></td>
                                    <td style="text-align: center"><?= $computer_name ?></td>
                                    <td style="text-align: center"><?= $technician_name ?></td>
                                    <td style="text-align: center"><?= !empty($item['last_maintenance_date']) ? Html::convDate($item['last_maintenance_date']) : '-' ?></td>
                                    <td style="text-align: center"><?= !empty($item['next_maintenance_date']) ? Html::convDate($item['next_maintenance_date']) : '-' ?></td>
                                    <td style="text-align: center"><?= $status_html ?></td>
                                    <td style="text-align: center">
                                        <div class="action-buttons">
                                            <?php if (Session::haveRight('plugin_preventivemaintenance', UPDATE)): ?>
                                                <a href="preventivemaintenance.form.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-primary" title="<?= __('Edit') ?>">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($pm->canDelete()): ?>
                                                <a href="preventivemaintenance.php?delete=<?= $item['id'] ?>" 
                                                   class="btn btn-sm btn-danger"
                                                   title="<?= __('Delete') ?>"
                                                   onclick="return confirm('<?= __('Do you really want to delete this record?') ?>');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        
        <?php if ($pm->canDelete()): ?>
        </form>
        <?php endif; ?>
        <?php endif; ?>

    <div class="custom-footer">
            <i class="fas fa-code"></i> <?= __('Developed by WIDA - Work Information Develops and Analytics') ?>
        </div>
    </div>
</div>

<div class="donation-qr-container" id="donationQrContainer">
    <div class="donation-qr-code">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=00020126720014BR.GOV.BCB.PIX0136db70b7e3-711a-4774-8884-7275386521e40210Obrigado!!5204000053039865802BR5925William%20de%20Oliveira%20Santo6009SAO%20PAULO621405101SdXWYH7RP6304D3A2" 
             alt="QR Code para doação via PIX">
    </div>
    <div class="pix-code-container">
        <div class="pix-code" id="pixCode">
            00020126720014BR.GOV.BCB.PIX0136db70b7e3-711a-4774-8884-7275386521e40210Obrigado!!5204000053039865802BR5925William de Oliveira Santo6009SAO PAULO621405101SdXWYH7RP6304D3A2
        </div>
        <button class="copy-pix-btn" id="copyPixBtn">
            <i class="fas fa-copy"></i> Copiar
        </button>
    </div>
    <div class="donation-message">
        Se esse projeto te ajudou, ajude também doando
    </div>
</div>

<div class="copy-notification" id="copyNotification">
    Código PIX copiado!
</div>

<script>
function toggleAllCheckboxes(source) {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const toggleFiltersBtn = document.getElementById('toggleFilters');
    const advancedFilters = document.getElementById('advancedFilters');
    const toggleNotifConfig = document.getElementById('toggleNotifConfig');
    const notifConfigPanel = document.getElementById('notifConfigPanel');
    
    if (toggleNotifConfig) {
        toggleNotifConfig.addEventListener('click', function() {
            if (notifConfigPanel.style.display === 'none') {
                notifConfigPanel.style.display = 'block';
                toggleNotifConfig.classList.remove('btn-outline-warning');
                toggleNotifConfig.classList.add('btn-warning');
            } else {
                notifConfigPanel.style.display = 'none';
                toggleNotifConfig.classList.remove('btn-warning');
                toggleNotifConfig.classList.add('btn-outline-warning');
            }
        });
    }
    
    toggleFiltersBtn.addEventListener('click', function() {
        if (advancedFilters.style.display === 'none') {
            advancedFilters.style.display = 'block';
            toggleFiltersBtn.classList.remove('btn-outline-info');
            toggleFiltersBtn.classList.add('btn-info');
        } else {
            advancedFilters.style.display = 'none';
            toggleFiltersBtn.classList.remove('btn-info');
            toggleFiltersBtn.classList.add('btn-outline-info');
        }
    });
    
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('filter_applied')) {
        advancedFilters.style.display = 'block';
        toggleFiltersBtn.classList.remove('btn-outline-info');
        toggleFiltersBtn.classList.add('btn-info');
    }
    
    function setupMobileView() {
        if (window.innerWidth < 768) {
            const headers = Array.from(document.querySelectorAll('thead th')).map(th => th.textContent.trim());
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                cells.forEach((cell, index) => {
                    cell.setAttribute('data-label', headers[index]);
                });
            });
        }
    }
    
    // Donation button functionality
    const donationButton = document.getElementById('donationButton');
    const donationQrContainer = document.getElementById('donationQrContainer');
    const copyPixBtn = document.getElementById('copyPixBtn');
    const pixCode = document.getElementById('pixCode');
    const copyNotification = document.getElementById('copyNotification');
    
    donationButton.addEventListener('click', function(e) {
        e.stopPropagation();
        donationQrContainer.classList.toggle('show');
    });
    
    // Copy PIX code functionality
    copyPixBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const textToCopy = pixCode.textContent;
        
        navigator.clipboard.writeText(textToCopy).then(function() {
            // Show notification
            copyNotification.style.display = 'block';
            setTimeout(function() {
                copyNotification.style.display = 'none';
            }, 2000);
        }).catch(function(err) {
            console.error('Erro ao copiar texto: ', err);
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = textToCopy;
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                // Show notification
                copyNotification.style.display = 'block';
                setTimeout(function() {
                    copyNotification.style.display = 'none';
                }, 2000);
            } catch (err) {
                console.error('Fallback erro ao copiar texto: ', err);
            }
            document.body.removeChild(textarea);
        });
    });
    
    // Close QR code when clicking outside
    document.addEventListener('click', function(event) {
        if (!donationButton.contains(event.target) && 
            !donationQrContainer.contains(event.target)) {
            donationQrContainer.classList.remove('show');
        }
    });
});
</script>

<?php
Html::footer();							   