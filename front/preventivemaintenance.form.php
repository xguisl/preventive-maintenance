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
 
 
 

// Inclui arquivos necessários do GLPI e verifica permissões
// Includes required GLPI files and checks permissions
include('../../../inc/includes.php');
Session::checkRight('plugin_preventivemaintenance', CREATE);

// Conexão manual com o banco de dados
// Manual database connection
$DB = new DB();

// Verifica e adiciona campo created_by se não existir
if (!$DB->fieldExists('glpi_plugin_preventivemaintenance_preventivemaintenances', 'created_by')) {
    $alter_query = "ALTER TABLE `glpi_plugin_preventivemaintenance_preventivemaintenances` 
                    ADD COLUMN `created_by` int(10) UNSIGNED NOT NULL DEFAULT '0' AFTER `maintenance_interval`,
                    ADD KEY `created_by` (`created_by`)";
    $DB->query($alter_query);
}

$is_edit = isset($_GET['id']);
$pm = new PluginPreventivemaintenancePreventivemaintenance();
$item_data = [];
$selected_entity_id = 0;

// Se estiver editando, carrega os dados existentes
// If editing, loads existing data
if ($is_edit) {
    $id = (int)$_GET['id'];
    if (!$pm->getFromDB($id)) {
        Session::addMessageAfterRedirect(__('Registro não encontrado'), false, ERROR);
        Html::redirect('preventivemaintenance.php');
    }
    $item_data = $pm->fields;
    $selected_entity_id = $item_data['entities_id'];
}

// Busca todos os perfis disponíveis para seleção
// Finds all available profiles for selection
$profile = new Profile();
$all_profiles = $profile->find([], 'name ASC');

// Busca os perfis técnicos selecionados (armazenados na sessão ou usa o padrão 'Technician')
// Finds selected technician profiles (stored in session or uses default 'Technician')
$selected_profiles = $_SESSION['plugin_preventivemaintenance_selected_profiles'] ?? ['Technician'];

// Busca os técnicos responsáveis (usuários com perfil de técnico selecionado)
// Finds responsible technicians (users with selected technician profile)
$technicians = [];
$user = new User();
$profile_user = new Profile_User();

foreach ($selected_profiles as $profile_name) {
    $technician_profile = $profile->find(['name' => $profile_name]);
    if (!empty($technician_profile)) {
        $technician_profile_id = key($technician_profile);
        $profile_users = $profile_user->find(['profiles_id' => $technician_profile_id]);
        
        foreach ($profile_users as $pu) {
            $user->getFromDB($pu['users_id']);
            if ($user->fields['is_active'] && !isset($technicians[$user->getID()])) {
                $technicians[$user->getID()] = $user->getName();
            }
        }
    }
}

// Processamento do formulário quando enviado
// Form processing when submitted
if (isset($_POST['add'])) {
    try {
        // Verificação de segurança CSRF
        // CSRF security check
        if (!isset($_POST['_glpi_csrf_token'])) {
            throw new Exception(__('Token de segurança ausente. Recarregue a página e tente novamente.'));
        }

        // Entidade fixa: entidade raiz (id = 0)
        // Fixed entity: root entity (id = 0)
        $selected_entity_id = 0;

        // Verifica se é criação em lote
        $is_bulk = isset($_POST['bulk_create']) && $_POST['bulk_create'] == '1';
        
        if ($is_bulk) {
            // Criação em lote: múltiplos computadores
            $computer_ids = isset($_POST['items_id_bulk']) ? $_POST['items_id_bulk'] : [];
            if (empty($computer_ids)) {
                throw new Exception(__('Selecione pelo menos um computador.'));
            }
            
            $success_count = 0;
            $skipped_count = 0;
            $error_count = 0;
            
            foreach ($computer_ids as $comp_id) {
                $comp_id = (int)$comp_id;
                $computer = new Computer();
                if (!$computer->getFromDB($comp_id)) {
                    $error_count++;
                    continue;
                }
                if ($computer->fields['entities_id'] != $selected_entity_id) {
                    $skipped_count++;
                    continue;
                }
                
                // Verifica se já existe manutenção
                $existing = $pm->find(['items_id' => $comp_id, 'itemtype' => 'Computer']);
                if (count($existing) > 0) {
                    $skipped_count++;
                    continue;
                }
                
                // Se o nome não for preenchido, usa o nome do usuário atrelado à máquina
                // If name is empty, uses the computer's assigned user name
                $maintenance_name = $_POST['name'] ?? '';
                if (empty($maintenance_name)) {
                    $comp_user_id = $computer->fields['users_id'] ?? 0;
                    if ($comp_user_id > 0) {
                        $comp_user = new User();
                        if ($comp_user->getFromDB($comp_user_id)) {
                            $maintenance_name = $comp_user->getName();
                        }
                    }
                }
                
                // Calcula intervalo
                $maintenance_interval = 30;
                $last_date = !empty($_POST['last_maintenance_date']) ? $_POST['last_maintenance_date'] : null;
                $next_date = $_POST['next_maintenance_date'];
                if (!empty($last_date) && !empty($next_date)) {
                    $last_dt = new DateTime($last_date);
                    $next_dt = new DateTime($next_date);
                    $maintenance_interval = $last_dt->diff($next_dt)->days;
                }
                
                $query = "INSERT INTO glpi_plugin_preventivemaintenance_preventivemaintenances
                         (name, entities_id, is_recursive, technician_id, items_id, itemtype, 
                          last_maintenance_date, next_maintenance_date, description, maintenance_interval, created_by)
                          VALUES (
                          '".$DB->escape($maintenance_name)."',
                          ".(int)$selected_entity_id.",
                          0,
                          ".(int)($_POST['technician_id'] ?? 0).",
                          $comp_id,
                          'Computer',
                          ".(!empty($last_date) ? "'".$DB->escape($last_date)."'" : "NULL").",
                          '".$DB->escape($next_date)."',
                          ".(!empty($_POST['description']) ? "'".$DB->escape($_POST['description'])."'" : "NULL").",
                          ".(int)$maintenance_interval.",
                          ".(int)Session::getLoginUserID().")";
                
                $result = $DB->query($query);
                if ($result) {
                    $success_count++;
                } else {
                    error_log("[ERRO BULK] Query falhou para computador ID $comp_id: " . $DB->error());
                    $error_count++;
                }
            }
            
            $msg = sprintf(__('Manutenções criadas: %d | Puladas (já existem): %d | Erros: %d'), $success_count, $skipped_count, $error_count);
            Session::addMessageAfterRedirect($msg, $error_count > 0 ? false : true, $error_count > 0 ? WARNING : INFO);
            Html::redirect('preventivemaintenance.php');
        }

        // Validação do computador (criação individual)
        // Computer validation (single creation)
        if (!isset($_POST['items_id']) || empty($_POST['items_id'])) {
            throw new Exception(__('Selecione um computador válido.'));
        }

        $computer_id = (int)$_POST['items_id'];
        $computer = new Computer();
        if (!$computer->getFromDB($computer_id)) {
            throw new Exception(__('Computador selecionado não encontrado.'));
        }

        // Verifica se o computador pertence à entidade raiz (id = 0)
        // Checks if computer belongs to root entity (id = 0)
        if ($computer->fields['entities_id'] != $selected_entity_id) {
            throw new Exception(__('O computador selecionado não pertence à entidade raiz.') . ' PC entity: ' . $computer->fields['entities_id'] . ', Expected: ' . $selected_entity_id);
        }

        // Verifica se já existe manutenção para este computador
        // Checks if maintenance already exists for this computer
        $existing = $pm->find([
            'items_id' => $computer_id,
            'itemtype' => 'Computer'
        ]);
        
        if ($is_edit) {
            unset($existing[$id]);
        }
        
        if (count($existing) > 0) {
            throw new Exception(sprintf(
                __('Já existe uma manutenção cadastrada para o computador %s (ID: %d)'),
                $computer->getName(),
                $computer_id
            ));
        }

        // Prepara os dados para gravação
        // Prepares data for saving
        $maintenance_name = $_POST['name'] ?? '';
        
        // Se o nome não for preenchido, usa o nome do usuário atrelado à máquina
        // If name is empty, uses the computer's assigned user name
        if (empty($maintenance_name)) {
            $comp_user_id = $computer->fields['users_id'] ?? 0;
            if ($comp_user_id > 0) {
                $comp_user = new User();
                if ($comp_user->getFromDB($comp_user_id)) {
                    $maintenance_name = $comp_user->getName();
                }
            }
        }
        
        $input = [
            'name' => $maintenance_name,
            'entities_id' => $selected_entity_id,
            'is_recursive' => 0,
            'technician_id' => (int)$_POST['technician_id'],
            'items_id' => $computer_id,
            'itemtype' => 'Computer',
            'last_maintenance_date' => $_POST['last_maintenance_date'] ?? null,
            'next_maintenance_date' => $_POST['next_maintenance_date'],
            'description' => $_POST['description'] ?? '',
            'maintenance_interval' => 30,
            'created_by' => Session::getLoginUserID()
        ];

        // Cálculo do intervalo de manutenção
        // Maintenance interval calculation
        if (!empty($_POST['last_maintenance_date']) && !empty($_POST['next_maintenance_date'])) {
            $last = new DateTime($_POST['last_maintenance_date']);
            $next = new DateTime($_POST['next_maintenance_date']);
            $interval = $last->diff($next)->days;
            $input['maintenance_interval'] = $interval;
        }

        error_log("[DADOS] Input preparado: " . print_r($input, true));

        // GRAVAÇÃO MANUAL NO BANCO DE DADOS
        // MANUAL DATABASE SAVE
        if ($is_edit) {
            $input['id'] = $id;
            
            // Se o nome não for preenchido, usa o nome do usuário atrelado à máquina
            // If name is empty, uses the computer's assigned user name
            if (empty($_POST['name'])) {
                $edit_computer = new Computer();
                if ($edit_computer->getFromDB($input['items_id'])) {
                    $comp_user_id = $edit_computer->fields['users_id'] ?? 0;
                    if ($comp_user_id > 0) {
                        $comp_user = new User();
                        if ($comp_user->getFromDB($comp_user_id)) {
                            $input['name'] = $comp_user->getName();
                        }
                    }
                }
            }
            
            error_log("[DEBUG UPDATE] Dados recebidos: " . print_r($_POST, true));
            error_log("[DEBUG UPDATE] Input preparado: " . print_r($input, true));
            
            // Atualização manual
            // Manual update
            $query = "UPDATE glpi_plugin_preventivemaintenance_preventivemaintenances SET
                      name = '".$DB->escape($input['name'])."',
                      entities_id = ".(int)$input['entities_id'].",
                      is_recursive = 0,
                      technician_id = ".(int)$input['technician_id'].",
                      items_id = ".(int)$input['items_id'].",
                      itemtype = 'Computer',
                      last_maintenance_date = ".(!empty($input['last_maintenance_date']) ? "'".$DB->escape($input['last_maintenance_date'])."'" : "NULL").",
                      next_maintenance_date = '".$DB->escape($input['next_maintenance_date'])."',
                      description = ".(!empty($input['description']) ? "'".$DB->escape($input['description'])."'" : "NULL").",
                      maintenance_interval = ".(int)$input['maintenance_interval'].",
                      created_by = ".(int)$input['created_by']."
                      WHERE id = ".(int)$input['id'];
            
            error_log("[QUERY] Update: " . $query);
            $result = $DB->query($query);
            
            if (!$result) {
                $db_error = $DB->error();
                error_log("[ERRO] Query falhou: " . $db_error);
                error_log("[ERRO] MySQL error number: " . $DB->errno());
                throw new Exception(__('Erro ao atualizar no banco de dados: ') . $db_error);
            }
            
            // Atualiza o conteúdo do ticket aberto se existir
            // Updates the open ticket content if it exists
            $computer = new Computer();
            $computer_name = '';
            if ($computer->getFromDB($computer_id)) {
                $computer_name = $computer->getName();
            }
            
            $ticket_query = "SELECT t.id 
                             FROM glpi_tickets t
                             INNER JOIN glpi_items_tickets it ON it.tickets_id = t.id
                             WHERE it.items_id = " . (int)$computer_id . "
                             AND it.itemtype = 'Computer'
                             AND t.name LIKE '%" . $DB->escape($input['name']) . "%'
                             AND t.status NOT IN (6, 5)
                             LIMIT 1";
            $ticket_result = $DB->query($ticket_query);
            if ($ticket_result && $ticket_result->num_rows > 0) {
                $ticket_data = $ticket_result->fetch_assoc();
                $ticket_id = $ticket_data['id'];
                
                $new_content = sprintf('O computador %s requer manutenção preventiva para: %s', $computer_name, $input['name']);
                if (!empty($input['description'])) {
                    $new_content .= "<br><br><strong>Descrição:</strong><br>" . nl2br(htmlspecialchars($input['description']));
                }
                
                $update_ticket = "UPDATE glpi_tickets SET content = '" . $DB->escape($new_content) . "' WHERE id = " . (int)$ticket_id;
                $DB->query($update_ticket);
            }
            
            Session::addMessageAfterRedirect(__('Manutenção atualizada com sucesso!'), true, INFO);
        } else {
            // Inserção manual
            // Manual insert
            $query = "INSERT INTO glpi_plugin_preventivemaintenance_preventivemaintenances
                     (name, entities_id, is_recursive, technician_id, items_id, itemtype, 
                      last_maintenance_date, next_maintenance_date, description, maintenance_interval, created_by)
                      VALUES (
                      '".$DB->escape($input['name'])."',
                      ".(int)$input['entities_id'].",
                      0,
                      ".(int)$input['technician_id'].",
                      ".(int)$input['items_id'].",
                      'Computer',
                      ".(!empty($input['last_maintenance_date']) ? "'".$DB->escape($input['last_maintenance_date'])."'" : "NULL").",
                      '".$DB->escape($input['next_maintenance_date'])."',
                      ".(!empty($input['description']) ? "'".$DB->escape($input['description'])."'" : "NULL").",
                      ".(int)$input['maintenance_interval'].",
                      ".(int)$input['created_by']."
                      )";
            
            error_log("[QUERY] Insert: " . $query);
            $result = $DB->query($query);
            
            if (!$result) {
                $db_error = $DB->error();
                error_log("[ERRO] Query falhou: " . $db_error);
                throw new Exception(__('Erro ao gravar no banco de dados: ') . $db_error);
            }
            
            Session::addMessageAfterRedirect(__('Manutenção criada com sucesso!'), true, INFO);
        }

        Html::redirect('preventivemaintenance.php');
        
    } catch (Exception $e) {
        error_log("[ERRO] Processamento: " . $e->getMessage());
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
        Html::back();
    }
}

// Processa a seleção de perfis técnicos se enviado
// Processes technician profiles selection if submitted
if (isset($_POST['save_selected_profiles'])) {
    $_SESSION['plugin_preventivemaintenance_selected_profiles'] = $_POST['profiles'] ?? ['Technician'];
    Html::back();
}

// Configuração do formulário
// Form configuration
// Entidade raiz fixa (id = 0)
// Fixed root entity (id = 0)
$root_entity_id = 0;
$root_entity_name = 'Entidade Raiz';

$computer = new Computer();
// Busca computadores apenas da entidade raiz (id = 0)
// Finds computers only from root entity (id = 0)
$all_computers = $computer->find(['is_deleted' => 0, 'entities_id' => $root_entity_id], "name ASC");

$existing_maintenances = $pm->find(['itemtype' => 'Computer']);
$blocked_computers = [];
foreach ($existing_maintenances as $maintenance) {
    if ($is_edit && $maintenance['id'] == $item_data['id']) continue;
    $blocked_computers[] = $maintenance['items_id'];
}

$available_computers = array_filter($all_computers, function($comp) use ($blocked_computers, $is_edit, $item_data) {
    if ($is_edit && $comp['id'] == $item_data['items_id']) {
        return true;
    }
    return !in_array($comp['id'], $blocked_computers);
});

$token = Session::getNewCSRFToken();

// Exibe o cabeçalho do GLPI
// Displays GLPI header
Html::header(
    __('Manutenção Preventiva', 'preventivemaintenance'),
    $_SERVER['PHP_SELF'],
    'plugins',
    'preventivemaintenance'
);
?>

<!-- Estilos CSS para a interface -->
<!-- CSS styles for interface -->
<style>
    body {
        background-color: #cacccf !important;
    }
    .form-section {
        margin-bottom: 15px;
    }
    .form-section label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
    }
    .form-control, .form-select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
    }
    /* Estilo específico para o dropdown de técnico */
    /* Specific style for technician dropdown */
    select[name='technician_id'] {
        width: 100% !important;
    }
    .required {
        color: #dc3545;
    }
    .btn {
        padding: 8px 16px;
        border-radius: 4px;
    }
    .entity-info {
        background-color: #f8f9fa;
        padding: 10px;
        border-radius: 4px;
        margin-bottom: 20px;
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
    /* Estilos para o datepicker com intervalo */
    /* Styles for datepicker with interval */
    .ui-datepicker {
        width: 350px !important;
        padding: 10px;
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        font-family: Arial, sans-serif;
    }
    .ui-datepicker-header {
        background: #f8f9fa;
        border-radius: 6px 6px 0 0;
        padding: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .ui-datepicker-title {
        font-weight: bold;
        display: flex;
        gap: 10px;
    }
    .ui-datepicker-month, .ui-datepicker-year {
        padding: 3px 5px;
        border-radius: 3px;
        border: 1px solid #ced4da;
    }
    .ui-datepicker-prev, .ui-datepicker-next {
        position: relative;
        top: auto;
        left: auto;
        right: auto;
        cursor: pointer;
        padding: 3px 8px;
        border-radius: 3px;
        background: #f0f0f0;
    }
    .ui-datepicker-prev:hover, .ui-datepicker-next:hover {
        background: #e0e0e0;
    }
    .ui-datepicker-calendar {
        width: 100%;
        margin-top: 10px;
    }
    .ui-datepicker-interval {
        padding: 10px;
        background: #f5f5f5;
        border-bottom: 1px solid #ddd;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin: -10px -10px 10px -10px;
        border-radius: 8px 8px 0 0;
    }
    .ui-datepicker-interval select {
        padding: 6px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        background: white;
        flex-grow: 1;
    }
    .ui-datepicker-interval button {
        padding: 6px 12px;
        background: #4CAF50;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        flex-grow: 1;
    }
    .ui-datepicker-interval button:hover {
        background: #45a049;
    }
    
    /* Estilos para o modal de seleção de perfis */
    /* Styles for profile selection modal */
    .profile-modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.4);
    }
    .profile-modal-content {
        background-color: #fefefe;
        margin: 10% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 50%;
        border-radius: 5px;
        box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
    }
    .profile-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .profile-modal-title {
        font-size: 1.2em;
        font-weight: bold;
    }
    .profile-modal-close {
        color: #aaa;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    .profile-modal-close:hover {
        color: black;
    }
    .profile-checkboxes {
        max-height: 400px;
        overflow-y: auto;
        margin-bottom: 20px;
    }
    .profile-checkbox-item {
        margin-bottom: 10px;
    }
    .profile-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    .select-profile-btn {
        margin-bottom: 15px;
    }
</style>

<!-- HTML principal do formulário -->
<!-- Main form HTML -->
<div class='plugin-preventive-maintenance-container'>
    <div class='d-flex justify-content-between align-items-center mb-4'>
        <h2>
            <i class='fas fa-calendar-check me-2'></i>
            <?php echo $is_edit ? __('Editar Manutenção') : __('Nova Manutenção'); ?>
        </h2>
        <a href='preventivemaintenance.php' class='btn btn-outline-primary'>
            <i class='fas fa-arrow-left me-2'></i><?php echo __('Voltar'); ?>
        </a>
    </div>

    <div class='card'>
        <div class='card-body'>
            <form method='post' id='preventive_maintenance_form'>
                <?php echo Html::hidden('_glpi_csrf_token', ['value' => $token]); ?>
                <input type='hidden' name='add' value='1'>
                <input type='hidden' name='entities_id' value='<?php echo $root_entity_id; ?>'>
                
                <!-- Botão para selecionar perfis técnicos -->
                <!-- Button to select technician profiles -->
                <div class="select-profile-btn" style="margin-bottom: 20px;">
                    <button type="button" id="selectProfilesBtn" class="btn btn-info">
                        <i class="fas fa-user-cog me-2"></i><?php echo __('Selecionar Perfis Técnicos'); ?>
                    </button>
                    <small class="text-muted d-block mt-1"><?php echo __('Perfis selecionados: ') . implode(', ', $selected_profiles); ?></small>
                </div>
                
                <div class='entity-info' style="margin-bottom: 15px; padding: 10px; background: #e8f4fd; border-radius: 5px;">
                    <strong><?php echo __('Entidade:'); ?></strong>
                    <?php echo $root_entity_name; ?> (ID: <?php echo $root_entity_id; ?>)
                </div>
                    
                    <div class='form-section'>
                        <label for='name'><?php echo __('Nome da Manutenção'); ?></label>
                        <input type='text' name='name' id='name' class='form-control' 
                               value="<?php echo $is_edit ? htmlspecialchars($item_data['name']) : ''; ?>" 
                               placeholder="<?php echo __('Se não preenchido, será usado o nome do usuário da máquina'); ?>">
                    </div>
                    
                    <div class='form-section'>
                        <label for='technician_id'><?php echo __('Técnico Responsável'); ?> <span class='required'>*</span></label>
                        <select name='technician_id' id='technician_id' class='form-select' required>
                            <option value=''><?php echo __('Selecione um técnico responsável'); ?></option>
                            <?php 
                            foreach ($technicians as $id => $name) {
                                $selected = ($is_edit && $item_data['technician_id'] == $id) ? 'selected' : '';
                                echo "<option value='{$id}' {$selected}>{$name}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <?php if ($is_edit): 
                        $edit_computer = new Computer();
                        $edit_computer_name = '';
                        if ($edit_computer->getFromDB($item_data['items_id'])) {
                            $edit_computer_name = $edit_computer->getName();
                        }
                    ?>
                    <div class='form-section'>
                        <label><?php echo __('Computador'); ?></label>
                        <input type='text' class='form-control' value="<?php echo htmlspecialchars($edit_computer_name); ?>" readonly disabled>
                        <input type='hidden' name='items_id' value="<?php echo (int)$item_data['items_id']; ?>">
                    </div>
                    <?php else: ?>
                    <div class='form-section'>
                        <label>
                            <input type='checkbox' id='bulkModeToggle' onchange='toggleBulkMode()' checked> 
                            <strong><?php echo __('Criar para múltiplos computadores'); ?></strong>
                        </label>
                    </div>
                    
                    <!-- Modo Bulk: checkboxes -->
                    <div id='bulkModeSection' class='form-section'>
                        <div style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">
                            <button type='button' class='btn btn-sm btn-outline-primary' onclick='toggleSelectAll(true)'>
                                <i class='fas fa-check-double'></i> Selecionar Todos
                            </button>
                            <button type='button' class='btn btn-sm btn-outline-secondary' onclick='toggleSelectAll(false)'>
                                <i class='fas fa-times'></i> Desmarcar Todos
                            </button>
                            <span class='text-muted' id='selectedCount'>0 selecionado(s)</span>
                        </div>
                        <div style='max-height: 300px; overflow-y: auto; border: 1px solid #ced4da; border-radius: 4px; padding: 10px; background: #f8f9fa;'>
                            <?php foreach ($available_computers as $comp): ?>
                            <div style='margin-bottom: 4px;'>
                                <label style='cursor: pointer;'>
                                    <input type='checkbox' name='items_id_bulk[]' value='<?php echo $comp['id']; ?>' 
                                           class='bulk-computer-checkbox' onchange='updateSelectedCount()'>
                                    <?php echo htmlspecialchars($comp['name']); ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($available_computers)): ?>
                            <p class='text-muted'><?php echo __('Nenhum computador disponível (todos já possuem manutenção)'); ?></p>
                            <?php endif; ?>
                        </div>
                        <input type='hidden' name='bulk_create' id='bulkCreateInput' value='1'>
                    </div>
                    
                    <!-- Modo Individual: select -->
                    <div id='singleModeSection' class='form-section' style='display: none;'>
                        <label for='items_id'><?php echo __('Computador'); ?> <span class='required'>*</span></label>
                        <select name='items_id' id='items_id' class='form-select'>
                            <option value=''><?php echo __('Selecione um computador'); ?></option>
                            <?php 
                            foreach ($available_computers as $comp) {
                                echo "<option value='{$comp['id']}'>" . htmlspecialchars($comp['name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class='form-section'>
                        <label for='last_maintenance_date'><?php echo __('Última Manutenção'); ?></label>
                        <input type='text' id='last_maintenance_date' name='last_maintenance_date' 
                               value="<?php echo $is_edit ? $item_data['last_maintenance_date'] : ''; ?>" 
                               class='form-control'>
                    </div>
                    
                    <div class='form-section'>
                        <label for='next_maintenance_date'><?php echo __('Próxima Manutenção'); ?> <span class='required'>*</span></label>
                        <input type='text' id='next_maintenance_date' name='next_maintenance_date' 
                               value="<?php echo $is_edit ? $item_data['next_maintenance_date'] : ''; ?>" 
                               class='form-control interval-field' required>
                    </div>
                    
                    <div class='form-section'>
                        <label for='description'><?php echo __('Descrição'); ?></label>
                        <textarea id='description' name='description' class='form-control' rows='4'
                                  placeholder='<?php echo __('Detalhes adicionais sobre esta manutenção (opcional)'); ?>'><?php echo $is_edit ? htmlspecialchars($item_data['description'] ?? '') : ''; ?></textarea>
                    </div>
                    
                    <div class='d-flex justify-content-end mt-4'>
                        <button type='submit' class='btn btn-success'>
                            <i class='fas fa-save me-2'></i><?php echo $is_edit ? __('Atualizar') : __('Salvar'); ?>
                        </button>
                    </div>
            </form>
            
            <!-- Modal para seleção de perfis técnicos -->
            <!-- Modal for technician profiles selection -->
            <div id="profileModal" class="profile-modal">
                <div class="profile-modal-content">
                    <div class="profile-modal-header">
                        <div class="profile-modal-title"><?php echo __('Selecionar Perfis Técnicos'); ?></div>
                        <span class="profile-modal-close">&times;</span>
                    </div>
                    <form method="post" id="profileSelectionForm">
                        <?php echo Html::hidden('_glpi_csrf_token', ['value' => $token]); ?>
                        <input type="hidden" name="save_selected_profiles" value="1">
                        
                        <div class="profile-checkboxes">
                            <?php foreach ($all_profiles as $prof): ?>
                                <div class="profile-checkbox-item">
                                    <label>
                                        <input type="checkbox" name="profiles[]" value="<?php echo $prof['name']; ?>"
                                            <?php echo in_array($prof['name'], $selected_profiles) ? 'checked' : ''; ?>>
                                        <?php echo $prof['name']; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="profile-modal-footer">
                            <button type="button" class="btn btn-secondary" id="cancelProfileSelection"><?php echo __('Cancelar'); ?></button>
                            <button type="submit" class="btn btn-primary"><?php echo __('Salvar Seleção'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Inclusão de bibliotecas JavaScript -->
            <!-- JavaScript libraries inclusion -->
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-ui-i18n/1.12.1/jquery-ui-i18n.min.js"></script>
            
            <!-- Script JavaScript para funcionalidades do formulário -->
            <!-- JavaScript script for form functionalities -->
            <script>
            const computersData = <?php echo json_encode(array_values($all_computers)); ?>;
            const blockedComputers = <?php echo json_encode($blocked_computers); ?>;
            
            $(document).ready(function() {
                // Configuração de localização para português
                // Portuguese localization setup
                $.datepicker.regional['pt-BR'] = {
                    closeText: 'Fechar',
                    prevText: '&#x3C;Anterior',
                    nextText: 'Próximo&#x3E;',
                    currentText: 'Hoje',
                    monthNames: ['Janeiro','Fevereiro','Março','Abril','Maio','Junho',
                    'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],
                    monthNamesShort: ['Jan','Fev','Mar','Abr','Mai','Jun',
                    'Jul','Ago','Set','Out','Nov','Dez'],
                    dayNames: ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'],
                    dayNamesShort: ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'],
                    dayNamesMin: ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'],
                    weekHeader: 'Sm',
                    dateFormat: 'yy-mm-dd',
                    firstDay: 0,
                    isRTL: false,
                    showMonthAfterYear: false,
                    yearSuffix: ''};
                $.datepicker.setDefaults($.datepicker.regional['pt-BR']);

                // Inicializa o datepicker para a última manutenção
                // Initializes datepicker for last maintenance
                $("#last_maintenance_date").datepicker({
                    dateFormat: 'yy-mm-dd',
                    showAnim: 'fadeIn',
                    changeMonth: true,
                    changeYear: true,
                    showButtonPanel: true,
                    onSelect: function(dateText) {
                        $(this).val(dateText);
                    },
                    beforeShow: function(input, inst) {
                        setTimeout(function() {
                            var button = inst.dpDiv.find('.ui-datepicker-current');
                            button.unbind('click').click(function() {
                                var today = new Date();
                                var formattedDate = $.datepicker.formatDate('yy-mm-dd', today);
                                $(input).val(formattedDate);
                                inst.dpDiv.hide();
                            });
                        }, 1);
                    }
                });
                
                // Inicializa o datepicker para a próxima manutenção com intervalo
                // Initializes datepicker for next maintenance with interval
                $("#next_maintenance_date").datepicker({
                    dateFormat: 'yy-mm-dd',
                    showAnim: 'fadeIn',
                    changeMonth: true,
                    changeYear: true,
                    showButtonPanel: true,
                    onSelect: function(dateText) {
                        $(this).val(dateText);
                    },
                    beforeShow: function(input, inst) {
                        setTimeout(function() {
                            // Configura o botão "Hoje"
                            // Configures "Today" button
                            var button = inst.dpDiv.find('.ui-datepicker-current');
                            button.unbind('click').click(function() {
                                var today = new Date();
                                var formattedDate = $.datepicker.formatDate('yy-mm-dd', today);
                                $(input).val(formattedDate);
                                inst.dpDiv.hide();
                            });
                            
                            // Adiciona controles de intervalo
                            // Adds interval controls
                            var dpDiv = $(inst.dpDiv);
                            dpDiv.find('.ui-datepicker-interval').remove();
                            
                            var controls = $(
                                '<div class="ui-datepicker-interval">' +
                                '  <span><?php echo __("Intervalo"); ?>:</span>' +
                                '  <select class="interval-value">' +
                                '    <option value="1">1 mês</option>' +
                                '    <option value="2">2 meses</option>' +
                                '    <option value="3">3 meses</option>' +
                                '    <option value="4">4 meses</option>' +
                                '    <option value="5">5 meses</option>' +
                                '    <option value="6" selected>6 meses</option>' +
                                '    <option value="7">7 meses</option>' +
                                '    <option value="8">8 meses</option>' +
                                '    <option value="9">9 meses</option>' +
                                '    <option value="10">10 meses</option>' +
                                '    <option value="11">11 meses</option>' +
                                '    <option value="12">1 ano</option>' +
                                '  </select>' +
                                '  <button type="button" class="apply-interval"><?php echo __("Aplicar"); ?></button>' +
                                '</div>'
                            );
                            
                            dpDiv.prepend(controls);
                            
                            dpDiv.find('.apply-interval').click(function() {
                                var lastDate = $("#last_maintenance_date").val();
                                if (!lastDate) {
                                    alert('<?php echo __("Informe a data da última manutenção"); ?>');
                                    return;
                                }
                                
                                var months = parseInt(dpDiv.find('.interval-value').val());
                                var date = new Date(lastDate);
                                date.setMonth(date.getMonth() + months);
                                
                                // Ajusta para o final do mês se necessário
                                // Adjusts to end of month if needed
                                var originalDay = new Date(lastDate).getDate();
                                if (date.getDate() !== originalDay) {
                                    date.setDate(0);
                                }
                                
                                var formatted = $.datepicker.formatDate('yy-mm-dd', date);
                                $("#next_maintenance_date").val(formatted).datepicker('hide');
                            });
                        }, 1);
                    }
                });

                // Carrega computadores da entidade raiz
                // Loads computers from root entity
                loadComputers(<?php echo $root_entity_id; ?>);
                
                // Carrega os computadores disponíveis para a entidade selecionada
                // Loads available computers for selected entity
                function loadComputers(entityId) {
                    const select = $('#items_id');
                    select.find('option').not(':first').remove();
                    
                    const filteredComputers = computersData.filter(comp => {
                        return comp.entities_id == entityId && 
                               (!blockedComputers.includes(comp.id) || <?php echo $is_edit ? 'comp.id == ' . $item_data['items_id'] : 'false'; ?>);
                    });
                    
                    if (filteredComputers.length > 0) {
                        filteredComputers.forEach(comp => {
                            select.append(new Option(comp.name, comp.id));
                        });
                    } else {
                        const option = new Option('<?php echo __("Nenhum computador disponível"); ?>', '');
                        option.disabled = true;
                        select.append(option);
                    }
                }
                
                // ==============================================
                // CÓDIGO PARA O BOTÃO DE SELEÇÃO DE PERFIS TÉCNICOS
                // CODE FOR TECHNICIAN PROFILES SELECTION BUTTON
                // ==============================================
                
                // Abre o modal de seleção de perfis
                // Opens profile selection modal
                $('#selectProfilesBtn').click(function() {
                    $('#profileModal').show();
                });
                
                // Fecha o modal quando clica no X
                // Closes modal when clicking X
                $('.profile-modal-close').click(function() {
                    $('#profileModal').hide();
                });
                
                // Fecha o modal quando clica em Cancelar
                // Closes modal when clicking Cancel
                $('#cancelProfileSelection').click(function() {
                    $('#profileModal').hide();
                });
                
                // Fecha o modal quando clica fora da área de conteúdo
                // Closes modal when clicking outside content area
                $(window).click(function(event) {
                    if (event.target == $('#profileModal')[0]) {
                        $('#profileModal').hide();
                    }
                });
                
                // Processa o formulário de seleção de perfis
                // Processes profile selection form
                $('#profileSelectionForm').submit(function(e) {
                    e.preventDefault();
                    
                    // Verifica se pelo menos um perfil foi selecionado
                    // Checks if at least one profile was selected
                    if ($('#profileSelectionForm input[name="profiles[]"]:checked').length === 0) {
                        alert('<?php echo __("Selecione pelo menos um perfil técnico"); ?>');
                        return;
                    }
                    
                    // Envia o formulário via AJAX
                    // Submits form via AJAX
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: $(this).serialize(),
                        success: function(response) {
                            // Recarrega a página para atualizar a lista de técnicos
                            // Reloads page to update technicians list
                            window.location.reload();
                        },
                        error: function() {
                            alert('<?php echo __("Erro ao salvar a seleção de perfis"); ?>');
                        }
                    });
                });
            });
            
            // Funções do modo Bulk
            function toggleBulkMode() {
                var bulkToggle = document.getElementById('bulkModeToggle');
                var bulkSection = document.getElementById('bulkModeSection');
                var singleSection = document.getElementById('singleModeSection');
                var bulkInput = document.getElementById('bulkCreateInput');
                
                if (bulkToggle.checked) {
                    bulkSection.style.display = 'block';
                    singleSection.style.display = 'none';
                    bulkInput.value = '1';
                } else {
                    bulkSection.style.display = 'none';
                    singleSection.style.display = 'block';
                    bulkInput.value = '0';
                }
            }
            
            function toggleSelectAll(selectAll) {
                var checkboxes = document.querySelectorAll('.bulk-computer-checkbox');
                checkboxes.forEach(function(cb) {
                    cb.checked = selectAll;
                });
                updateSelectedCount();
            }
            
            function updateSelectedCount() {
                var checked = document.querySelectorAll('.bulk-computer-checkbox:checked').length;
                document.getElementById('selectedCount').textContent = checked + ' selecionado(s)';
            }
            
            // Inicializa contador
            updateSelectedCount();
            </script>
        </div>
        <!-- Rodapé personalizado -->
        <!-- Custom footer -->
        <div class="custom-footer">
            <i class="fas fa-code"></i> <?= __('Desenvolvido por WIDA - Work Information Developments and Analytics') ?>
        </div>
    </div>
</div>

<?php
// Exibe o rodapé do GLPI
// Displays GLPI footer
Html::footer();
?>