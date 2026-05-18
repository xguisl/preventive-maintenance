# Plugin de Manutenção Preventiva para GLPI

## 📌 Visão Geral
Plugin para agendar, monitorar e automatizar manutenções preventivas de computadores no GLPI, com:
- ✔ Cadastro de planos de manutenção por computador
- ✔ Alertas visuais com progresso em porcentagem
- ✔ Geração automática de tickets para manutenções urgentes
- ✔ Atualização automática da data de manutenção quando o ticket é resolvido
- ✔ Filtros avançados na lista (status, técnico, data, entidade)
- ✔ Filtro por nome do computador
- ✔ Criação em lote de manutenções para múltiplos computadores
- ✔ Notificações via Microsoft Teams
- ✔ Configuração de entidade de destino para tickets
- ✔ Atualização do conteúdo do ticket ao editar a manutenção

## 🚀 Instalação
Baixe a última versão do GitHub

Extraia para: `/glpi/plugins/preventivemaintenance`

Ative o plugin em: Configurações > Plugins
Configure permissões para grupos técnicos

### Requisitos
- GLPI 10.0.0 ou superior
- PHP 7.4+
- Extensões PHP: mysqli, json, date

## 🔧 Funcionalidades Principais

### 1. Agendamento Inteligente
- Defina intervalos personalizados (cálculo automático entre datas)
- Calendário interativo para seleção de datas
- Campo de descrição opcional para detalhes da manutenção

### 2. Automatização
- **Auto Ticket**: Tickets automáticos para manutenções que atingem 99% do intervalo
- **Atualização automática**: A data de manutenção é atualizada quando o ticket é resolvido (calcula novo intervalo baseado na data de resolução)
- **Limpeza automática**: Tickets resolvidos/fechados são limpos da tabela de controle automaticamente
- **Notificações Teams**: Alertas enviados X dias antes da próxima manutenção (configurável)
- **Atualização de ticket**: Ao editar a descrição da manutenção, o conteúdo do ticket aberto é atualizado automaticamente

### 3. Criação em Lote
- Selecione múltiplos computadores de uma vez
- Aplique as mesmas configurações (nome, técnico, datas, descrição) para todos
- Botões "Selecionar Todos" e "Desmarcar Todos"
- Contador de computadores selecionados
- Computadores que já possuem manutenção são pulados automaticamente

### 4. Configurações Avançadas
- **Entidade de Destino dos Tickets**: Selecione em qual entidade os tickets serão criados (independente de onde os computadores estão cadastrados)
- **Notificações Microsoft Teams**: Configure webhook e dias antes para receber alertas
- **Filtro por Nome do Computador**: Busque rapidamente por nome específico na lista

### 5. Integrações
- **GLPI Tickets**: Vincula manutenções a tickets existentes
- **Inventário**: Mostra dados do item (serial, modelo, localização)
- **Atualização de Ticket**: Ao editar a descrição da manutenção, o conteúdo do ticket aberto é atualizado automaticamente

### 6. Interface Visual
- Status visual por cores (✅ Em dia / ⚠️ Atenção / ❌ Urgente)
- Filtros por técnico, entidade, data e nome do computador
- Progresso em porcentagem para cada item
- Modo de edição com preservação de dados
- Agrupamento por entidade hierárquica
- Barra de progresso colorida (verde < 95%, laranja 95-99%, vermelho ≥ 99%)
- Ícones de status informativos na lista

## ⚙ Configuração

### Acesso
Acesse: `Configurações > Plugins > Manutenção Preventiva`

### Configurações Principais
- **Auto Ticket**: Habilite/desative a criação automática de tickets
- **Entidade Ticket**: Selecione a entidade onde os tickets serão criados
- **Notificações Teams**: Configure o webhook e dias antes para alertas

### Permissões
Controle acesso por perfil (leitura, edição, exclusão). Os níveis de permissão são configurados automaticamente durante a instalação:

- **Super-Admin**: Todos os direitos (Ler, Criar, Atualizar, Excluir, Purgar) - Nível 31
- **Admin**: Ler, Criar, Atualizar - Nível 7
- **Supervisor**: Ler, Criar, Atualizar - Nível 7
- **Technician**: Ler, Criar - Nível 3
- **Outros perfis**: Apenas Leitura - Nível 1

Para ajustar permissões manualmente, acesse: `Configurações > Usuários > Perfis > [Selecione o perfil] > Aba "Preventive Maintenance"`

## 📊 Como Usar

### Criar Manutenção Individual
1. Clique em "Adicionar"
2. Desmarque "Criar para múltiplos computadores"
3. Selecione o computador, técnico, datas e descrição
4. Salve

### Criar Manutenção em Lote
1. Clique em "Adicionar"
2. Mantenha "Criar para múltiplos computadores" marcado
3. Selecione os computadores desejados (ou "Selecionar Todos")
4. Configure nome, técnico, datas e descrição
5. Salve - cada PC receberá sua própria manutenção

### Configurar Notificações Teams
1. Clique no ícone 🔔 (sino) na página principal
2. Cole a URL do Webhook do Teams
3. Defina quantos dias antes deseja ser notificado
4. Salve

**Para criar o Webhook no Teams:**
1. Abra o canal desejado no Teams
2. Clique em **(...)** > **Conectores**
3. Adicione **Webhook de Entrada**
4. Copie a URL gerada

### Selecionar Perfis Técnicos
O plugin permite selecionar quais perfis de usuário são considerados "técnicos" para atribuição de manutenções:

1. Ao criar ou editar uma manutenção, clique no botão "Selecionar Perfis Técnicos"
2. Marque os perfis desejados (ex: Technician, Admin, Supervisor)
3. Os usuários desses perfis aparecerão no dropdown de técnico responsável
4. A seleção é salva na sessão do usuário

## 🗄️ Estrutura do Banco de Dados

O plugin cria 3 tabelas no banco de dados durante a instalação:

### 1. glpi_plugin_preventivemaintenance_preventivemaintenances
Tabela principal que armazena as manutenções preventivas:
- `id`: Identificador único
- `entities_id`: Entidade do registro
- `is_recursive`: Se é recursivo para subentidades
- `name`: Nome da manutenção
- `items_id`: ID do computador vinculado
- `itemtype`: Tipo do item (padrão: Computer)
- `technician_id`: ID do técnico responsável
- `last_maintenance_date`: Data da última manutenção
- `next_maintenance_date`: Data da próxima manutenção
- `description`: Descrição detalhada da manutenção
- `maintenance_interval`: Intervalo em dias entre manutenções (calculado automaticamente)
- `created_by`: ID do usuário que criou o registro
- `date_creation`: Data de criação
- `date_mod`: Data da última modificação

### 2. glpi_plugin_preventivemaintenance_tickets
Tabela de controle dos tickets de manutenção:
- `id`: Identificador único
- `ticket_id`: ID do ticket GLPI vinculado
- `computer_id`: ID do computador
- `maintenance_name`: Nome da manutenção
- `date_creation`: Data de criação do registro

### 3. glpi_plugin_preventivemaintenance_config
Tabela de configurações do plugin:
- `id`: Identificador único
- `name`: Nome da configuração (ex: auto_ticket, ticket_entity, teams_webhook, notify_days_before)
- `value`: Valor da configuração
- `date_creation`: Data de criação
- `date_mod`: Data da última modificação

## 📈 Sistema de Status e Alertas

### Cálculo de Progresso
O progresso é calculado baseado no tempo decorrido desde a última manutenção em relação ao intervalo total:
```
Progresso (%) = (Data Atual - Última Manutenção) / (Próxima Manutenção - Última Manutenção) * 100
```

### Thresholds de Status
- **Em dia (On Track)**: Progresso < 80% - Barra verde
- **Atenção (Due Soon)**: 80% ≤ Progresso < 98% - Barra laranja
- **Urgente (Urgent)**: Progresso ≥ 98% - Barra vermelha
- **Atrasado (Overdue)**: Data atual > Próxima manutenção - Ícone de alerta

### Status Visual na Lista
- **No Computer**: Computador não vinculado
- **Not Scheduled**: Datas não definidas
- **Overdue**: Manutenção atrasada (mostra dias de atraso)
- **Due Soon**: Próxima manutenção em até 7 dias
- **On Track**: Manutenção em dia (mostra dias restantes)

### Filtros de Status
Na lista principal, é possível filtrar por:
- **all**: Todos os registros
- **ontime**: Manutenções em dia (progresso < 80%)
- **warning**: Manutenções em atenção (80% ≤ progresso < 98%)
- **urgent**: Manutenções urgentes (progresso ≥ 98%)
- **undefined**: Manutenções sem datas definidas

## 🔒 Segurança e Compatibilidade

### Proteção CSRF
O plugin é totalmente compatível com a proteção CSRF do GLPI. Todos os formulários incluem tokens de segurança para prevenir ataques de falsificação de solicitação entre sites.

### Histórico de Alterações
O plugin mantém um histórico completo de todas as alterações feitas nos registros de manutenção (criação, edição, exclusão), seguindo o padrão do GLPI através da propriedade `$dohistory = true`.

### Validações de Dados
- Validação obrigatória de computador
- Validação de datas (próxima manutenção deve ser posterior à última)
- Verificação de duplicidade (um computador não pode ter mais de uma manutenção ativa)
- Verificação de permissões antes de cada operação

## 🛠️ Notas Técnicas

### Entidade de Destino
Por padrão, as manutenções são criadas na entidade raiz (ID = 0). Os tickets podem ser configurados para serem criados em uma entidade diferente através da configuração "Entidade Ticket".

### Cálculo Automático de Intervalo
Ao criar ou editar uma manutenção com ambas as datas definidas, o plugin calcula automaticamente o intervalo em dias entre a última e a próxima manutenção. Este intervalo é usado para:
- Calcular o progresso atual
- Determinar quando criar tickets automáticos
- Recalcular a próxima data quando um ticket é resolvido

### Atualização ao Resolver Ticket
Quando um ticket de manutenção é resolvido:
1. O plugin identifica a manutenção associada
2. Usa a data de resolução como nova "última manutenção"
3. Adiciona o intervalo original para calcular a nova "próxima manutenção"
4. Remove o registro da tabela de controle de tickets

### Limpeza Automática
O plugin verifica automaticamente tickets resolvidos/fechados e limpa os registros da tabela `glpi_plugin_preventivemaintenance_tickets`, mantendo apenas tickets em aberto ativos.

## 📜 Licença
Licenciado sob GNU GPLv2+ - Ver licença completa.

## Desenvolvido por
© 2025 WIDA - Work Information Development Analytics
www.widatecnologia.com.br
## Modificado por 
@xguisl

🔧 Manutenção preventiva = Menos falhas + Mais produtividade!
