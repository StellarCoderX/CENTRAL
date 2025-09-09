 <?php
/**
 * PAINEL ADMINISTRATIVO RECARGAPAY - CONFIGURAÇÕES DO SISTEMA DE CRÉDITOS
 * Interface para configurar RecargaPay, valores e outras configurações
 */
require_once 'db_connection.php';
session_start();
require_once 'credits_system.php';
require_once 'recargapay_pix_integration.php';

// Verificar se é administrador
function isAdmin() {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] == 1;
}

if (!isAdmin()) {
    die('Acesso negado. Apenas administradores podem acessar esta página.');
}

$pdo = connect_db();
$creditSystem = initCreditSystem($pdo);
$recargaPaySystem = initRecargaPaySystem($pdo, $creditSystem);
$feedback_message = '';
$feedback_type = 'error';

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $feedback_message = "Token de segurança inválido.";
    } else {
        switch ($_POST['action']) {
            case 'update_recargapay_config':
                // 1. Capturar e validar o valor do formulário
                $expirationMinutes = intval($_POST['expiration_minutes'] ?? 30);

                if ($expirationMinutes >= 1 && $expirationMinutes <= 1440) { // Validação (1 min a 24 horas)
                    // 2. Usar o creditSystem para salvar a configuração no banco de dados
                    $creditSystem->updateSetting('pix_expiration_minutes', $expirationMinutes, $_SESSION['user_id']);

                    // A linha abaixo pode ser mantida para garantir que outros dados estejam corretos
                    updateSystemForRecargaPay($pdo);

                    $feedback_message = "Configurações RecargaPay atualizadas com sucesso!";
                    $feedback_type = 'success';
                } else {
                    $feedback_message = "Tempo de expiração inválido. Use um valor razoável (ex: entre 1 e 60).";
                    $feedback_type = 'error';
                }
                break;
                
            case 'update_pricing':
                $costPerTest = floatval($_POST['cost_per_test'] ?? 1.50);
                $minRecharge = floatval($_POST['min_recharge'] ?? 50.00);
                $freeCredits = floatval($_POST['free_credits'] ?? 5.00);
                
                if ($costPerTest <= 0 || $minRecharge <= 0) {
                    $feedback_message = "Valores devem ser maiores que zero.";
                } else {
                    $creditSystem->updateSetting('cost_per_approved_test', number_format($costPerTest, 2, '.', ''), $_SESSION['user_id']);
                    $creditSystem->updateSetting('minimum_recharge_amount', number_format($minRecharge, 2, '.', ''), $_SESSION['user_id']);
                    $creditSystem->updateSetting('free_trial_credits', number_format($freeCredits, 2, '.', ''), $_SESSION['user_id']);
                    
                    $feedback_message = "Configurações de preços atualizadas com sucesso!";
                    $feedback_type = 'success';
                }
                break;
                
            case 'toggle_system':
                $enabled = $_POST['system_enabled'] === '1' ? '1' : '0';
                $creditSystem->updateSetting('system_enabled', $enabled, $_SESSION['user_id']);
                
                $feedback_message = "Sistema " . ($enabled === '1' ? 'ativado' : 'desativado') . " com sucesso!";
                $feedback_type = 'success';
                break;
                
            case 'add_credits':
                $userId = intval($_POST['user_id'] ?? 0);
                $amount = floatval($_POST['amount'] ?? 0);
                $description = sanitize_input($_POST['description'] ?? 'Créditos adicionados pelo administrador');
                
                if ($userId <= 0 || $amount <= 0) {
                    $feedback_message = "Usuário e valor são obrigatórios.";
                } else {
                    // CORRIGIDO: Agora chamamos a nova função addCreditsToUser, que lida com a lógica de forma segura.
                    $result = $creditSystem->addCreditsToUser($userId, $amount, $description, 'admin_add_' . time());

                    if ($result['success']) {
                        $_SESSION['feedback_message'] = "Créditos adicionados com sucesso!";
                        $_SESSION['feedback_type'] = 'success';
                    } else {
                        $_SESSION['feedback_message'] = "Erro ao adicionar créditos. Verifique os logs do servidor.";
                        $_SESSION['feedback_type'] = 'error';
                    }

                    header('Location: admin_config_recargapay.php');
                    exit();
                }
                break;
                
            case 'test_recargapay':
                // Testar configuração RecargaPay
                try {
                    $testAmount = 50.00;
                    $testPayment = $recargaPaySystem->createRecargaPayPayment(
                        $_SESSION['user_id'], 
                        $testAmount, 
                        'Teste de configuração RecargaPay'
                    );
                    
                    // Cancelar o pagamento de teste imediatamente
                    $stmt = $pdo->prepare("UPDATE pix_payments SET status = 'cancelled' WHERE payment_id = ?");
                    $stmt->execute([$testPayment['payment_id']]);
                    
                    $feedback_message = "Teste RecargaPay realizado com sucesso! PIX gerado e cancelado automaticamente.";
                    $feedback_type = 'success';
                } catch (Exception $e) {
                    $feedback_message = "Erro no teste RecargaPay: " . $e->getMessage();
                }
                break;
        }
    }
}

// Obter configurações atuais
$currentSettings = [
    'cost_per_test' => $creditSystem->getSetting('cost_per_approved_test', '1.50'),
    'min_recharge' => $creditSystem->getSetting('minimum_recharge_amount', '50.00'),
    'free_credits' => $creditSystem->getSetting('free_trial_credits', '5.00'),
    'system_enabled' => $creditSystem->getSetting('system_enabled', '1'),
    'expiration_minutes' => $creditSystem->getSetting('pix_expiration_minutes', '30')
];

// Obter configurações RecargaPay
$recargaPayConfig = RecargaPayPixIntegration::getRecargaPayConfig();

// Obter estatísticas do sistema
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT uc.user_id) as total_users_with_credits,
        SUM(uc.balance) as total_balance,
        COUNT(ct.id) as total_transactions,
        SUM(CASE WHEN ct.transaction_type = 'credit' THEN ct.amount ELSE 0 END) as total_credits_added,
        SUM(CASE WHEN ct.transaction_type = 'debit' THEN ct.amount ELSE 0 END) as total_credits_spent
    FROM user_credits uc
    LEFT JOIN credit_transactions ct ON uc.user_id = ct.user_id AND ct.status = 'completed'
");
$stats = $stmt->fetch();

// Obter estatísticas RecargaPay
$recargaPayReport = $recargaPaySystem->getRecargaPayReport();

// Obter usuários para adicionar créditos
$stmt = $pdo->query("SELECT id, username, email FROM users ORDER BY username");
$users = $stmt->fetchAll();

// 3. Verifica se existe uma mensagem de feedback na sessão e a exibe
if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    $feedback_type = $_SESSION['feedback_type'];
    // 4. Limpa a mensagem da sessão para que não seja exibida novamente
    unset($_SESSION['feedback_message']);
    unset($_SESSION['feedback_type']);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo RecargaPay - Sistema de Créditos</title>
    <link rel="stylesheet" href="styles.css">
    <style>
    .admin-container {
        max-width: 1400px;
        margin: 2rem auto;
        padding: 2rem;
    }

    .admin-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 2rem;
        margin-top: 2rem;
    }

    .admin-card {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        padding: 2rem;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .admin-card h3 {
        margin-bottom: 1.5rem;
        color: var(--primary);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background: var(--bg-glass);
        padding: 1.5rem;
        border-radius: var(--radius-md);
        text-align: center;
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 0.5rem;
    }

    .stat-label {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .recargapay-info {
        background: linear-gradient(135deg, #00ff88, #0066ff);
        color: white;
        padding: 1.5rem;
        border-radius: var(--radius-md);
        margin-bottom: 2rem;
    }

    .recargapay-info h4 {
        margin-bottom: 1rem;
        font-size: 1.25rem;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .info-item {
        background: rgba(255, 255, 255, 0.1);
        padding: 1rem;
        border-radius: var(--radius-sm);
    }

    .info-label {
        font-size: 0.9rem;
        opacity: 0.8;
        margin-bottom: 0.25rem;
    }

    .info-value {
        font-weight: 600;
        font-size: 1.1rem;
    }

    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 60px;
        height: 34px;
    }

    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 34px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 26px;
        width: 26px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }

    input:checked+.slider {
        background-color: var(--primary);
    }

    input:checked+.slider:before {
        transform: translateX(26px);
    }

    .test-result {
        margin-top: 1rem;
        padding: 1rem;
        border-radius: var(--radius-md);
        background: var(--bg-glass);
    }
    </style>
</head>

<body>
    <div class="bg-animation">
        <div class="floating-shapes">
            <div class="shape"></div>
            <div class="shape"></div>
            <div class="shape"></div>
        </div>
    </div>
    <div class="grid-bg"></div>

    <div class="admin-container">
        <div class="main-card">
            <div class="header">
                <div class="logo">
                    <div class="logo-icon"><i class="fas fa-cog"></i></div>
                    <div class="logo-text">Painel RecargaPay</div>
                </div>
                <p class="subtitle">Sistema de Créditos com RecargaPay PIX</p>
            </div>

            <?php if (!empty($feedback_message)): ?>
            <div class="feedback <?= $feedback_type ?>">
                <i class="fas fa-<?= $feedback_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= sanitize_input($feedback_message) ?>
            </div>
            <?php endif; ?>

            <!-- Informações RecargaPay -->
            <div class="recargapay-info">
                <h4><i class="fas fa-credit-card"></i> Configuração RecargaPay Ativa</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Comerciante</div>
                        <div class="info-value"><?= $recargaPayConfig['merchant_name'] ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Cidade</div>
                        <div class="info-value"><?= $recargaPayConfig['merchant_city'] ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Chave PIX</div>
                        <div class="info-value"><?= substr($recargaPayConfig['pix_key'], 0, 8) ?>...</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Provedor</div>
                        <div class="info-value"><?= $recargaPayConfig['provider'] ?></div>
                    </div>
                </div>
            </div>

            <!-- Estatísticas Gerais -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= intval($stats['total_users_with_credits'] ?? 0) ?></div>
                    <div class="stat-label">Usuários com Créditos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= CreditSystem::formatCurrency($stats['total_balance'] ?? 0) ?></div>
                    <div class="stat-label">Saldo Total</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= intval($recargaPayReport['statistics']['paid_payments']) ?></div>
                    <div class="stat-label">PIX Confirmados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $recargaPayReport['statistics']['total_received_formatted'] ?></div>
                    <div class="stat-label">Recebido via PIX</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $recargaPayReport['statistics']['success_rate'] ?>%</div>
                    <div class="stat-label">Taxa de Sucesso PIX</div>
                </div>
            </div>

            <div class="admin-grid">
                <!-- Configurações RecargaPay -->
                <div class="admin-card">
                    <h3><i class="fas fa-credit-card"></i> Configurações RecargaPay</h3>

                    <div
                        style="background: var(--bg-glass); padding: 1.5rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                        <h4 style="color: var(--primary); margin-bottom: 1rem;">Informações da Conta</h4>
                        <div style="display: grid; gap: 0.5rem; font-size: 0.9rem;">
                            <div><strong>Nome:</strong> <?= $recargaPayConfig['merchant_name'] ?></div>
                            <div><strong>Cidade:</strong> <?= $recargaPayConfig['merchant_city'] ?></div>
                            <div><strong>Chave PIX:</strong> <?= $recargaPayConfig['pix_key'] ?></div>
                            <div><strong>Tipo de Conta:</strong> <?= $recargaPayConfig['account_type'] ?></div>
                            <div><strong>Suporte Webhook:</strong>
                                <?= $recargaPayConfig['supports_webhook'] ? 'Sim' : 'Não' ?></div>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_recargapay_config">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                        <div class="form-group">
                            <label class="form-label">Tempo de Expiração PIX (minutos)</label>
                            <input type="number" name="expiration_minutes" class="form-input"
                                value="<?= intval($currentSettings['expiration_minutes']) ?>" min="1" max="60" required>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Atualizar Configurações
                        </button>
                    </form>

                    <div style="margin-top: 1.5rem;">
                        <form method="POST">
                            <input type="hidden" name="action" value="test_recargapay">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <button type="submit" class="btn btn-secondary">
                                <i class="fas fa-vial"></i> Testar RecargaPay
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Configurações de Preços -->
                <div class="admin-card">
                    <h3><i class="fas fa-dollar-sign"></i> Configurações de Preços</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_pricing">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                        <div class="form-group">
                            <label class="form-label">Custo por Teste Aprovado (R$)</label>
                            <input type="number" name="cost_per_test" class="form-input"
                                value="<?= floatval($currentSettings['cost_per_test']) ?>" step="0.01" min="0.01"
                                required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Valor Mínimo de Recarga (R$)</label>
                            <input type="number" name="min_recharge" class="form-input"
                                value="<?= floatval($currentSettings['min_recharge']) ?>" step="0.01" min="1.00"
                                required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Créditos Gratuitos para Novos Usuários (R$)</label>
                            <input type="number" name="free_credits" class="form-input"
                                value="<?= floatval($currentSettings['free_credits']) ?>" step="0.01" min="0.00"
                                required>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Salvar Preços
                        </button>
                    </form>
                </div>

                <!-- Controle do Sistema -->
                <div class="admin-card">
                    <h3><i class="fas fa-power-off"></i> Controle do Sistema</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="toggle_system">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                        <div class="form-group">
                            <label class="form-label">Sistema de Créditos</label>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="system_enabled" value="1"
                                        <?= $currentSettings['system_enabled'] === '1' ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <span><?= $currentSettings['system_enabled'] === '1' ? 'Ativo' : 'Inativo' ?></span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Atualizar Status
                        </button>
                    </form>

                    <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid rgba(255,255,255,0.1);">
                        <p style="color: var(--text-secondary); font-size: 0.9rem;">
                            <strong>Ativo:</strong> Usuários precisam de créditos para usar as ferramentas<br>
                            <strong>Inativo:</strong> Ferramentas funcionam normalmente sem cobrança<br>
                            <strong>RecargaPay:</strong> Pagamentos processados via RecargaPay PIX
                        </p>
                    </div>
                </div>

                <!-- Adicionar Créditos -->
                <div class="admin-card">
                    <h3><i class="fas fa-plus-circle"></i> Adicionar Créditos</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_credits">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                        <div class="form-group">
                            <label class="form-label">Usuário</label>
                            <select name="user_id" class="form-input" required>
                                <option value="">Selecione um usuário</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>">
                                    <?= sanitize_input($user['username']) ?> (<?= sanitize_input($user['email']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Valor (R$)</label>
                            <input type="number" name="amount" class="form-input" step="0.01" min="0.01"
                                placeholder="0.00" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Descrição</label>
                            <input type="text" name="description" class="form-input"
                                value="Créditos adicionados pelo administrador" required>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Adicionar Créditos
                        </button>
                    </form>
                </div>

                <!-- Estatísticas RecargaPay -->
                <div class="admin-card">
                    <h3><i class="fas fa-chart-bar"></i> Estatísticas RecargaPay</h3>

                    <div style="display: grid; gap: 1rem;">
                        <div class="info-item">
                            <div class="info-label">Período do Relatório</div>
                            <div class="info-value">
                                <?= $recargaPayReport['period']['start'] ?> a <?= $recargaPayReport['period']['end'] ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Total de Pagamentos</div>
                            <div class="info-value"><?= $recargaPayReport['statistics']['total_payments'] ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Pagamentos Confirmados</div>
                            <div class="info-value"><?= $recargaPayReport['statistics']['paid_payments'] ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Valor Médio</div>
                            <div class="info-value"><?= $recargaPayReport['statistics']['average_payment_formatted'] ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Taxa de Sucesso</div>
                            <div class="info-value"><?= $recargaPayReport['statistics']['success_rate'] ?>%</div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="text-align: center; margin-top: 2rem;">
                <a href="?page=dashboard" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                </a>
                <a href="?page=credits" class="btn btn-primary" style="margin-left: 1rem;">
                    <i class="fas fa-coins"></i> Ver Créditos
                </a>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
</body>

</html>