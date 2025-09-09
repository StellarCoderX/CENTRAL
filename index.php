 <?php
session_start();

// --- ATIVAR EXIBIÇÃO DE ERROS PARA DEBUG ---
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- CONFIGURAÇÃO DO BANCO DE DADOS ---
// Exemplo: Substitua os valores abaixo pelos seus dados da InfinityFree
define('DB_HOST', 'sql306.infinityfree.com');
define('DB_NAME', 'if0_39842623_terminus_db');
define('DB_USER', 'if0_39842623');
define('DB_PASS', 'IyekXlfMvuVx7');
define('AVATAR_PATH', 'uploads/avatars/');

// Incluir sistema de créditos
require_once 'credits_system.php';
// Incluir arquivo de conexão com o banco de dados
require_once 'db_connection.php';
// ------------------------------------

function redirect($url) {
    header("Location: " . $url);
    exit();
}

function require_auth() {
    if (!isset($_SESSION['user_id'])) {
        redirect('index.php?page=login&error=auth_required');
    }
}

function sanitize_input($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// --- LÓGICA DE AÇÕES E ROTEAMENTO ---
$pdo = connect_db();
$creditSystem = initCreditSystem($pdo);
$feedback_message = '';
$feedback_type = 'error';

$page = $_GET['page'] ?? 'login';
$action = $_POST['action'] ?? null;

// Logout
if ($page === 'logout') {
    session_destroy();
    redirect('?page=login&success=logout');
}

// Processamento de ações
if ($action) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $feedback_message = "Token de segurança inválido.";
    } else {
        switch ($action) {
            case 'register':
                $username = sanitize_input($_POST['username'] ?? '');
                $email = sanitize_input($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';

                if (empty($username) || empty($email) || empty($password)) {
                    $feedback_message = "Todos os campos são obrigatórios.";
                } elseif (!validate_email($email)) {
                    $feedback_message = "E-mail inválido.";
                } elseif (strlen($password) < 8) {
                    $feedback_message = "A senha deve ter pelo menos 8 caracteres.";
                } else {
                    try {
                        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
                        $stmt->execute(['username' => $username, 'email' => $email]);
                        if ($stmt->fetch()) {
                            $feedback_message = "Nome de usuário ou e-mail já existe.";
                        } else {
                            $password_hash = password_hash($password, PASSWORD_ARGON2ID);
                            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, created_at) VALUES (:username, :email, :password_hash, NOW())");
                            $stmt->execute(['username' => $username, 'email' => $email, 'password_hash' => $password_hash]);
                            
                            // Adicionar créditos gratuitos para novo usuário
                            $newUserId = $pdo->lastInsertId();
                            $creditSystem->addFreeTrialCredits($newUserId);
                            
                            redirect('index.php?page=login&success=registered');
                        }
                    } catch (PDOException $e) { 
                        error_log("Database error: " . $e->getMessage());
                        $feedback_message = "Erro no servidor. Tente novamente."; 
                    }
                }
                break;

            case 'login':
                $username = sanitize_input($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                
                if (empty($username) || empty($password)) {
                    $feedback_message = "Todos os campos são obrigatórios.";
                } else {
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
                        $stmt->execute(['username' => $username]);
                        $user = $stmt->fetch();

                        if ($user && password_verify($password, $user['password_hash'])) {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['avatar'] = $user['avatar'];
                            $_SESSION['login_time'] = time();
                            
                            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
                            $stmt->execute(['id' => $user['id']]);
                            
                            redirect('index.php?page=dashboard');
                        } else {
                            $feedback_message = "Credenciais inválidas.";
                        }
                    } catch (PDOException $e) { 
                        error_log("Database error: " . $e->getMessage());
                        $feedback_message = "Erro no servidor. Tente novamente."; 
                    }
                }
                break;

            case 'update_avatar':
                require_auth();
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['avatar'];
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    if (in_array($file['type'], $allowedTypes) && $file['size'] < 2000000) {
                        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $newFilename = 'avatar-' . $_SESSION['user_id'] . '-' . time() . '.' . $extension;
                        if (move_uploaded_file($file['tmp_name'], AVATAR_PATH . $newFilename)) {
                            $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = :id");
                            $stmt->execute(['id' => $_SESSION['user_id']]);
                            $oldAvatar = $stmt->fetchColumn();
                            if ($oldAvatar && file_exists(AVATAR_PATH . $oldAvatar)) {
                                @unlink(AVATAR_PATH . $oldAvatar);
                            }
                            $stmt = $pdo->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
                            $stmt->execute(['avatar' => $newFilename, 'id' => $_SESSION['user_id']]);
                            $_SESSION['avatar'] = $newFilename;
                            $feedback_message = 'Foto de perfil atualizada com sucesso!';
                            $feedback_type = 'success';
                        } else { $feedback_message = 'Erro ao mover o ficheiro. Verifique as permissões da pasta.'; }
                    } else { $feedback_message = 'Ficheiro inválido. Apenas JPG, PNG, GIF até 2MB são permitidos.'; }
                } else { $feedback_message = 'Erro no upload. Por favor, tente novamente.'; }
                $page = 'profile';
                break;

            case 'update_password':
                require_auth();
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';

                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    $feedback_message = "Todos os campos de senha são obrigatórios.";
                } elseif (strlen($new_password) < 8) {
                    $feedback_message = "A nova senha deve ter pelo menos 8 caracteres.";
                } elseif ($new_password !== $confirm_password) {
                    $feedback_message = "As novas senhas não coincidem.";
                } else {
                    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id");
                    $stmt->execute(['id' => $_SESSION['user_id']]);
                    $user = $stmt->fetch();
                    if ($user && password_verify($current_password, $user['password_hash'])) {
                        $new_password_hash = password_hash($new_password, PASSWORD_ARGON2ID);
                        $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
                        $stmt->execute(['hash' => $new_password_hash, 'id' => $_SESSION['user_id']]);
                        $feedback_message = "Senha alterada com sucesso!";
                        $feedback_type = 'success';
                    } else {
                        $feedback_message = "A senha atual está incorreta.";
                    }
                }
                $page = 'profile';
                break;
        }
    }
}

// Mensagens de feedback baseadas em parâmetros GET
if (isset($_GET['error'])) {
    $feedback_type = 'error';
    switch ($_GET['error']) {
        case 'auth_required':
            $feedback_message = 'Acesso negado. Por favor, faça o login.';
            break;
        case 'insufficient_credits':
            $feedback_message = 'Saldo insuficiente para executar esta ferramenta.';
            break;
        default:
            $feedback_message = 'Ocorreu um erro. Tente novamente.';
    }
}

if (isset($_GET['success'])) {
    $feedback_type = 'success';
    switch ($_GET['success']) {
        case 'registered':
            $feedback_message = 'Registro concluído com sucesso! Você recebeu créditos gratuitos. Faça o login.';
            break;
        case 'logout':
            $feedback_message = 'Você foi desconectado com segurança.';
            break;
        case 'credits_added':
            $feedback_message = 'Créditos adicionados com sucesso!';
            break;
        default:
            $feedback_message = 'Operação realizada com sucesso.';
    }
}

// Página de execução de ferramentas
if ($page === 'run_tool') {
    require_auth();
    $tool_id = intval($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM tools WHERE id = :id AND is_active = 1");
    $stmt->execute(['id' => $tool_id]);
    $tool = $stmt->fetch();

    if (!$tool || basename($tool['script_filename']) === 'index.php') {
        $feedback_message = "Ferramenta não encontrada.";
        $feedback_type = 'error';
        $page = 'dashboard';
    } else {
        $output = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_tool') {
            if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
                // Verificar créditos antes de executar
                $creditCheck = checkCreditsBeforeToolExecution($_SESSION['user_id'], $tool_id, $pdo);
                
                if (!$creditCheck['allowed']) {
                    $output = "ERRO: " . $creditCheck['message'];
                } else {
                    ob_start();
                    $tool_script_path = __DIR__ . '/tools/' . $tool['script_filename'];
                    if (file_exists($tool_script_path)) {
                        $input_data = $_POST['input_data'] ?? '';
                        include $tool_script_path;
                        
                        // Simular resultado (em produção, isso viria da ferramenta)
                        $result_status = 'approved'; // ou 'rejected' ou 'error'
                        
                        // Processar resultado e cobrar créditos se necessário
                        $chargeResult = processToolResult($_SESSION['user_id'], $tool_id, $result_status, $input_data, ob_get_contents(), $pdo);
                        
                        if ($chargeResult['charged']) {
                            $output .= "\n\n--- COBRANÇA ---\n";
                            $output .= "Resultado: APROVADO\n";
                            $output .= "Valor cobrado: " . $chargeResult['amount_charged_formatted'] . "\n";
                            $output .= "Novo saldo: " . $chargeResult['new_balance'] . "\n";
                        }
                    } else {
                        echo "ERRO: Arquivo da ferramenta não encontrado.";
                    }
                    $output = ob_get_clean();
                }
            } else {
                $output = "ERRO: Token de segurança inválido.";
            }
        }
    }
}

// Obter dados do usuário para o dashboard
$userSummary = null;
if (isset($_SESSION['user_id'])) {
    $userSummary = $creditSystem->getUserAccountSummary($_SESSION['user_id']);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Central de Checkers Profissional - Sistema de Verificação Avançado">
    <meta name="author" content="Central de Checkers">
    <meta name="robots" content="noindex, nofollow">

    <?php
    $page_title = 'Login | Central de Checkers Pro';
    if ($page === 'dashboard') {
        $page_title = 'Dashboard | Central de Checkers Pro';
    } elseif ($page === 'register') {
        $page_title = 'Criar Conta | Central de Checkers Pro';
    } elseif ($page === 'profile') {
        $page_title = 'Gerenciar Perfil | Central de Checkers Pro';
    } elseif ($page === 'credits') {
        $page_title = 'Meus Créditos | Central de Checkers Pro';
    } elseif ($page === 'run_tool' && isset($tool)) {
        $page_title = sanitize_input($tool['name']) . ' | Central de Checkers Pro';
    }
    ?>
    <title><?= $page_title ?></title>

    <link rel="icon" type="image/x-icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡️</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        xintegrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw=="
        crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="styles.css">
    <style>
    .credits-info {
        background: var(--bg-glass);
        border: 1px solid rgba(0, 255, 136, 0.3);
        border-radius: var(--radius-md);
        padding: 1rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .credits-balance {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--primary);
    }

    .credits-actions {
        display: flex;
        gap: 0.5rem;
    }

    .btn-small {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }

    .pix-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }

    .pix-modal-content {
        background: var(--bg-card);
        border-radius: var(--radius-lg);
        padding: 2rem;
        max-width: 500px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
    }

    .qr-code {
        text-align: center;
        margin: 2rem 0;
    }

    .qr-code img {
        max-width: 200px;
        border: 2px solid var(--primary);
        border-radius: var(--radius-md);
    }

    .payment-info {
        background: var(--bg-glass);
        border-radius: var(--radius-md);
        padding: 1.5rem;
        margin: 1rem 0;
    }

    .payment-timer {
        text-align: center;
        font-size: 1.1rem;
        color: var(--warning);
        margin: 1rem 0;
    }

    .transaction-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .transaction-item:last-child {
        border-bottom: none;
    }

    .transaction-type {
        padding: 0.25rem 0.5rem;
        border-radius: var(--radius-sm);
        font-size: 0.8rem;
        font-weight: 500;
    }

    .transaction-credit {
        background: rgba(0, 255, 136, 0.2);
        color: var(--success);
    }

    .transaction-debit {
        background: rgba(255, 71, 87, 0.2);
        color: var(--error);
    }
    </style>
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
</head>

<body>
    <div class="bg-animation">
        <div class="floating-shapes">
            <div class="shape"></div>
            <div class="shape"></div>
            <div class="shape"></div>
            <div class="shape"></div>
            <div class="shape"></div>
            <div class="shape"></div>
        </div>
    </div>
    <div class="grid-bg"></div>

    <?php if ($page === 'dashboard'): ?>
    <?php require_auth(); ?>
    <div class="dashboard-container fade-in">
        <div class="dashboard-header">
            <a href="?page=profile" class="user-info-link" style="text-decoration: none; color: inherit;">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($_SESSION['avatar']) && file_exists(AVATAR_PATH . $_SESSION['avatar'])): ?>
                        <img src="<?= AVATAR_PATH . sanitize_input($_SESSION['avatar']) ?>" alt="Avatar"
                            style="width:100%; height:100%; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                        <?= strtoupper(substr($_SESSION['username'], 0, 2)) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-weight: 600;">Bem-vindo, <?= sanitize_input($_SESSION['username']) ?></div>
                        <div style="color: var(--text-secondary); font-size: 0.9rem;">Gerenciar Perfil</div>
                    </div>
                </div>
            </a>
            <div class="flex gap-2">
                <a href="?page=credits" class="btn btn-primary btn-small">
                    <i class="fas fa-coins"></i> Créditos
                </a>
                <a href="?page=logout" class="btn btn-secondary"><i class="fas fa-sign-out-alt"></i> Sair</a>
            </div>
        </div>

        <!-- Informações de Créditos -->
        <?php if ($creditSystem->isSystemEnabled()): ?>
        <div class="credits-info">
            <div>
                <div style="color: var(--text-secondary); font-size: 0.9rem;">Saldo Atual</div>
                <div class="credits-balance"><?= $userSummary['balance_formatted'] ?></div>
                <div style="color: var(--text-secondary); font-size: 0.8rem;">
                    Custo por Live: <?= $userSummary['cost_per_test_formatted'] ?>
                </div>
            </div>
            <div class="credits-actions">
                <button onclick="openRechargeModal()" class="btn btn-primary btn-small">
                    <i class="fas fa-plus"></i> Recarregar
                </button>
                <a href="?page=credits" class="btn btn-secondary btn-small">
                    <i class="fas fa-history"></i> Histórico
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="main-card">
            <div class="header">
                <div class="logo">
                    <div class="logo-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="logo-text">@ALVINCODER</div>
                </div>
                <p class="subtitle">Selecione uma Ferramenta :)</p>
            </div>
            <div class="tools-grid">
                <?php
                    try {
                        $stmt = $pdo->query("SELECT * FROM tools WHERE is_active = 1 ORDER BY name ASC");
                        $tools = $stmt->fetchAll();
                        if (empty($tools)): ?>
                <div class="text-center" style="grid-column: 1 / -1; color: var(--text-secondary); padding: 3rem;">
                    <i class="fas fa-tools" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <h3 style="margin-bottom: 0.5rem;">Nenhuma ferramenta disponível</h3>
                    <p>As ferramentas serão carregadas automaticamente.</p>
                </div>
                <?php else: foreach ($tools as $tool): ?>
                <?php
                            $isInterfaceTool = (basename($tool['script_filename']) === 'index.php');
                            $tool_url = $isInterfaceTool ? sanitize_input($tool['script_filename']) : '?page=run_tool&id=' . $tool['id'];
                        ?>
                <a href="<?= $tool_url ?>" class="tool-card">
                    <div class="tool-icon">
                        <i class="fas fa-<?= sanitize_input($tool['icon'] ?? 'cog') ?>"></i>
                    </div>
                    <div class="tool-name"><?= sanitize_input($tool['name']) ?></div>
                    <div class="tool-desc"><?= sanitize_input($tool['description']) ?></div>
                </a>
                <?php endforeach; endif; ?>
                <?php } catch (PDOException $e) { ?>
                <div class="text-center" style="grid-column: 1 / -1; color: var(--error); padding: 3rem;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 4rem; margin-bottom: 1rem;"></i>
                    <h3>Erro ao carregar ferramentas</h3>
                    <p>Tente recarregar a página.</p>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <?php elseif ($page === 'credits'): ?>
    <?php require_auth(); ?>
    <div class="dashboard-container fade-in">
        <div class="dashboard-header">
            <div class="user-info">
                <div class="user-avatar">
                    <?php if (!empty($_SESSION['avatar']) && file_exists(AVATAR_PATH . $_SESSION['avatar'])): ?>
                    <img src="<?= AVATAR_PATH . sanitize_input($_SESSION['avatar']) ?>" alt="Avatar"
                        style="width:100%; height:100%; object-fit: cover; border-radius: 50%;">
                    <?php else: ?>
                    <?= strtoupper(substr($_SESSION['username'], 0, 2)) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-weight: 600;">Meus Créditos</div>
                    <div style="color: var(--text-secondary); font-size: 0.9rem;">Saldo:
                        <?= $userSummary['balance_formatted'] ?></div>
                </div>
            </div>
            <div class="flex gap-2">
                <button onclick="openRechargeModal()" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Recarregar
                </button>
                <a href="?page=dashboard" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>

        <div class="main-card">
            <div class="header">
                <div class="logo">
                    <div class="logo-icon"><i class="fas fa-coins"></i></div>
                    <div class="logo-text">Gerenciar Créditos</div>
                </div>
            </div>

            <!-- Resumo da Conta -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-value"><?= $userSummary['balance_formatted'] ?></div>
                    <div class="stat-label">Saldo Atual</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $userSummary['total_tests'] ?></div>
                    <div class="stat-label">Testes Realizados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $userSummary['approved_tests'] ?></div>
                    <div class="stat-label">Testes Aprovados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $userSummary['total_spent_formatted'] ?></div>
                    <div class="stat-label">Total Gasto</div>
                </div>
            </div>

            <!-- Histórico de Transações -->
            <div style="margin-top: 2rem;">
                <h3 style="margin-bottom: 1rem; color: var(--primary);">
                    <i class="fas fa-history"></i> Histórico de Transações
                </h3>
                <div id="transactions-list">
                    <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                        <i class="fas fa-spinner fa-spin"></i> Carregando transações...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($page === 'run_tool' && isset($tool)): ?>
    <?php require_auth(); ?>
    <div class="dashboard-container fade-in">
        <div class="dashboard-header">
            <div class="user-info">
                <div style="font-weight: 600;"><?= sanitize_input($tool['name']) ?></div>
                <div style="color: var(--text-secondary); font-size: 0.9rem;">
                    Saldo: <?= $userSummary['balance_formatted'] ?> |
                    Custo: <?= $userSummary['cost_per_test_formatted'] ?>
                </div>
            </div>
            <div class="flex gap-2">
                <a href="?page=dashboard" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>

        <div class="main-card">
            <div class="header">
                <div class="logo">
                    <div class="logo-icon"><i class="fas fa-<?= sanitize_input($tool['icon'] ?? 'cog') ?>"></i></div>
                    <div class="logo-text"><?= sanitize_input($tool['name']) ?></div>
                </div>
                <p class="subtitle"><?= sanitize_input($tool['description']) ?></p>
            </div>

            <?php if ($creditSystem->isSystemEnabled()): ?>
            <div class="credits-info">
                <div>
                    <div style="color: var(--text-secondary); font-size: 0.9rem;">Será cobrado apenas se o resultado for
                        APROVADO</div>
                    <div style="font-weight: 600; color: var(--primary);">
                        Custo por teste aprovado: <?= $userSummary['cost_per_test_formatted'] ?>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="color: var(--text-secondary); font-size: 0.9rem;">Seu saldo</div>
                    <div class="credits-balance"><?= $userSummary['balance_formatted'] ?></div>
                </div>
            </div>
            <?php endif; ?>

            <form action="" method="POST" id="toolForm">
                <input type="hidden" name="action" value="run_tool">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="form-group">
                    <label for="input_data" class="form-label">Dados de Entrada</label>
                    <textarea id="input_data" name="input_data" class="form-input" rows="10"
                        placeholder="Cole aqui os dados para verificação..." required></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-full" id="runToolBtn">
                    <i class="fas fa-play"></i> Executar Ferramenta
                </button>
            </form>

            <?php if (!empty($output)): ?>
            <div style="margin-top: 2rem;">
                <h3 style="margin-bottom: 1rem; color: var(--primary);">
                    <i class="fas fa-terminal"></i> Resultado
                </h3>
                <div
                    style="background: var(--bg-glass); border-radius: var(--radius-md); padding: 1.5rem; font-family: 'JetBrains Mono', monospace; white-space: pre-wrap; font-size: 0.9rem; line-height: 1.4;">
                    <?= sanitize_input($output) ?>
                </div>
                <div style="margin-top: 1rem; text-align: center;">
                    <button onclick="copyToClipboard(`<?= addslashes($output) ?>`)" class="btn btn-secondary">
                        <i class="fas fa-copy"></i> Copiar Resultado
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($page === 'profile'): ?>
    <?php require_auth(); ?>
    <!-- Código da página de perfil existente... -->

    <?php else: ?>
    <div class="main-container">
        <div class="content-wrapper fade-in">
            <div class="main-card">
                <?php if (!empty($feedback_message)): ?>
                <div class="feedback <?= $feedback_type ?>">
                    <i class="fas fa-<?= $feedback_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                    <?= sanitize_input($feedback_message) ?>
                </div>
                <?php endif; ?>

                <?php if ($page === 'register'): ?>
                <div class="header">
                    <div class="logo">
                        <div class="logo-icon"><i class="fas fa-user-plus"></i></div>
                        <div class="logo-text">Criar Conta</div>
                    </div>
                    <p class="subtitle">Registre-se para acessar o sistema</p>
                </div>
                <form action="index.php" method="POST" id="registerForm" novalidate>
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <div class="form-group"><label for="username" class="form-label">Nome de Usuário</label><input
                            type="text" id="username" name="username" class="form-input"
                            placeholder="Digite seu nome de usuário" required minlength="3"></div>
                    <div class="form-group"><label for="email" class="form-label">E-mail</label><input type="email"
                            id="email" name="email" class="form-input" placeholder="Digite seu e-mail" required></div>
                    <div class="form-group"><label for="password" class="form-label">Senha</label><input type="password"
                            id="password" name="password" class="form-input" placeholder="Digite sua senha" required
                            minlength="8"></div>
                    <button type="submit" class="btn btn-primary btn-full"><i class="fas fa-user-plus"></i> Criar
                        Conta</button>
                </form>
                <div class="text-center mt-2">
                    <p style="color: var(--text-secondary);">Já possui uma conta? <a href="?page=login"
                            class="link">Fazer Login</a></p>
                    <p style="color: var(--success); font-size: 0.9rem; margin-top: 1rem;">
                        <i class="fas fa-gift"></i> Novos usuários recebem créditos gratuitos!
                    </p>
                </div>

                <?php else: // Login Page ?>
                <div class="header">
                    <div class="logo">
                        <div class="logo-icon"><i class="fas fa-shield-alt"></i></div>
                        <div class="logo-text">Central de Checkers</div>
                    </div>
                    <p class="subtitle">TELEGRAM: @ALVINCODER</p>
                </div>
                <form action="index.php" method="POST" id="loginForm" novalidate>
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <div class="form-group"><label for="username" class="form-label">Nome de Usuário</label><input
                            type="text" id="username" name="username" class="form-input"
                            placeholder="Digite seu nome de usuário" required></div>
                    <div class="form-group"><label for="password" class="form-label">Senha</label><input type="password"
                            id="password" name="password" class="form-input" placeholder="Digite sua senha" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full"><i class="fas fa-sign-in-alt"></i> Entrar no
                        Sistema</button>
                </form>
                <div class="text-center mt-2">
                    <p style="color: var(--text-secondary);">Não possui uma conta? <a href="?page=register"
                            class="link">Criar Conta</a></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal PIX -->
    <div id="pixModal" class="pix-modal">
        <div class="pix-modal-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h3 style="color: var(--primary); margin: 0;">
                    <i class="fas fa-qrcode"></i> Recarga via PIX
                </h3>
                <button onclick="closePixModal()"
                    style="background: none; border: none; color: var(--text-secondary); font-size: 1.5rem; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div id="pixModalContent">
                <!-- Conteúdo será carregado via JavaScript -->
            </div>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
    /**
     * Função para carregar e exibir o histórico de transações do usuário.
     */
    async function loadTransactionHistory() {
        // Encontra o container onde o histórico será exibido
        const listContainer = document.getElementById('transactions-list');

        // Se o container não existir nesta página, não faz nada
        if (!listContainer) {
            return;
        }

        try {
            // Faz a requisição para a API que retorna o histórico
            const response = await fetch('credits_api.php?endpoint=history');
            const result = await response.json();

            // Se a API retornar um erro, exibe uma mensagem
            if (!response.ok || result.error) {
                throw new Error(result.error || 'Falha ao carregar o histórico.');
            }

            // Limpa a mensagem "Carregando transações..."
            listContainer.innerHTML = '';

            // Verifica se a lista de transações não está vazia
            if (result.transactions && result.transactions.length > 0) {
                result.transactions.forEach(tx => {
                    const typeClass = tx.transaction_type === 'credit' ? 'transaction-credit' :
                        'transaction-debit';
                    const icon = tx.transaction_type === 'credit' ? 'fa-arrow-up' : 'fa-arrow-down';
                    const iconColor = tx.transaction_type === 'credit' ? 'var(--success)' : 'var(--error)';

                    // Formata a data para o padrão brasileiro
                    const date = new Date(tx.created_at);
                    const formattedDate = date.toLocaleString('pt-BR', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });

                    // Cria o HTML para cada item da transação
                    const itemHTML = `
                        <div class="transaction-item">
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <i class="fas ${icon}" style="color: ${iconColor}; font-size: 1.2rem;"></i>
                                <div>
                                    <div style="font-weight: 500;">${tx.description}</div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary);">${formattedDate}</div>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-weight: 600; font-size: 1.1rem; color: ${iconColor};">${tx.amount_formatted}</div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary);">${tx.status === 'completed' ? 'Completo' : tx.status}</div>
                            </div>
                        </div>
                    `;
                    // Insere o HTML no container
                    listContainer.insertAdjacentHTML('beforeend', itemHTML);
                });
            } else {
                // Se não houver transações, mostra uma mensagem
                listContainer.innerHTML =
                    '<div style="text-align: center; padding: 2rem; color: var(--text-secondary);">Nenhuma transação encontrada.</div>';
            }
        } catch (error) {
            // Se houver um erro de conexão, mostra uma mensagem de falha
            console.error('Erro ao carregar histórico:', error);
            listContainer.innerHTML =
                '<div style="text-align: center; padding: 2rem; color: var(--error);"><i class="fas fa-exclamation-triangle"></i> Erro ao carregar o histórico. Tente recarregar a página.</div>';
        }
    }

    // Executa a função quando o documento HTML terminar de carregar
    document.addEventListener('DOMContentLoaded', loadTransactionHistory);
    </script>
    <script>
    let currentPaymentId = null; // <-- ADICIONE ESTA LINHA
    // Sistema de Créditos - JavaScript com API Real

    function openRechargeModal() {
        const modal = document.getElementById('pixModal');
        modal.style.display = 'flex';
        showRechargeForm();
    }

    function closePixModal() {
        const modal = document.getElementById('pixModal');
        modal.style.display = 'none';

        clearInterval(paymentCheckInterval); // Para a verificação em segundo plano
        cancelCurrentPixPayment(); // Chama a função para cancelar o PIX

        currentPaymentId = null; // Limpa o ID do pagamento
        document.getElementById('pixModalContent').innerHTML = ''; // Limpa o conteúdo do modal
    }

    function showRechargeForm() {
        // Obtém o valor mínimo das configurações do sistema (se disponível)
        const minAmount = <?= floatval($creditSystem->getSetting('minimum_recharge_amount', '50.00')) ?>;

        const content = `
            <form onsubmit="generatePixPayment(event)">
                <div class="form-group">
                    <label class="form-label">Valor da Recarga (R$)</label>
                    <input type="number" id="rechargeAmount" class="form-input" 
                           min="${minAmount}" step="0.01" placeholder="${minAmount.toFixed(2)}" required>
                    <div style="color: var(--text-secondary); font-size: 0.8rem; margin-top: 0.5rem;">
                        Valor mínimo: R$ ${minAmount.toFixed(2).replace('.', ',')}
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-full" id="generatePixBtn">
                    <i class="fas fa-qrcode"></i> Gerar PIX
                </button>
            </form>
        `;
        document.getElementById('pixModalContent').innerHTML = content;
    }

    async function generatePixPayment(event) {
        event.preventDefault();
        const amount = parseFloat(document.getElementById('rechargeAmount').value);
        const minAmount = <?= floatval($creditSystem->getSetting('minimum_recharge_amount', '50.00')) ?>;
        const generateBtn = document.getElementById('generatePixBtn');

        if (isNaN(amount) || amount < minAmount) {
            alert(`O valor mínimo para recarga é R$ ${minAmount.toFixed(2)}`);
            return;
        }

        // Mostrar loading no botão
        generateBtn.disabled = true;
        generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando PIX...';

        try {
            const response = await fetch('pix_api_recargapay.php?endpoint=generate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    amount: amount,
                    csrf_token: '<?= generate_csrf_token() ?>'
                })
            });

            const result = await response.json();

            if (result.success && result.payment) {
                showPixPayment(result.payment);
            } else {
                throw new Error(result.message || 'Ocorreu um erro ao gerar o PIX.');
            }

        } catch (error) {
            console.error('Erro na API PIX:', error);
            document.getElementById('pixModalContent').innerHTML = `
                <div class="feedback error" style="text-align: center;">
                    <h4>Erro ao Gerar PIX</h4>
                    <p>${error.message}</p>
                    <button onclick="showRechargeForm()" class="btn btn-secondary mt-2">Tentar Novamente</button>
                </div>
            `;
        } finally {
            // Restaurar botão
            generateBtn.disabled = false;
            generateBtn.innerHTML = '<i class="fas fa-qrcode"></i> Gerar PIX';
        }
    }

    function showPixPayment(paymentData) {
        currentPaymentId = paymentData.payment_id; // <-- ADICIONE ESTA LINHA
        const content = `
            <div class="payment-info">
                <h4 style="margin-bottom: 1rem; color: var(--primary);">Pague para adicionar os créditos</h4>
                <div style="display: grid; gap: 0.5rem;">
                    <div><strong>Valor:</strong> ${paymentData.amount_formatted}</div>
                    <div><strong>Destinatário:</strong> ${paymentData.merchant_name}</div>
                    <div><strong>ID da Transação:</strong> ${paymentData.payment_id}</div>
                </div>
            </div>
            
            <div class="qr-code">
                <p style="margin-bottom: 1rem; color: var(--text-secondary);">Escaneie o QR Code com o app do seu banco:</p>
                <img src="${paymentData.qr_code_data}" alt="PIX QR Code" style="max-width: 250px; border: 4px solid var(--primary); border-radius: var(--radius-md);">
            </div>

            <div class="form-group" style="margin-top: 1.5rem;">
                <label class="form-label">Ou use o PIX Copia e Cola:</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" id="pixCodeInput" class="form-input" value="${paymentData.pix_code}" readonly>
                    <button onclick="copyPixCode()" class="btn btn-secondary" title="Copiar Código">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
            
            <div class="payment-timer" id="paymentTimer">
                Expira em: <span id="timerDisplay">${paymentData.expires_in_minutes}:00</span>
            </div>
            
            <div style="text-align: center; margin-top: 1.5rem;">
                <button onclick="checkPaymentStatus('${paymentData.payment_id}')" class="btn btn-primary" id="checkStatusBtn">
                    <i class="fas fa-check-circle"></i> Já Paguei
                </button>
                <button onclick="closePixModal()" class="btn btn-secondary" style="margin-left: 0.5rem;">
                    Cancelar
                </button>
            </div>
        `;

        document.getElementById('pixModalContent').innerHTML = content;
        startPaymentTimer(paymentData.expires_in_minutes * 60, paymentData.payment_id);
    }

    function copyPixCode() {
        const input = document.getElementById('pixCodeInput');
        input.select();
        document.execCommand('copy');
        alert('Código PIX copiado para a área de transferência!');
    }

    let paymentCheckInterval; // Variável para controlar o intervalo de verificação

    async function cancelCurrentPixPayment() {
        if (!currentPaymentId) return; // Se não houver ID, não faz nada

        try {
            await fetch('pix_api_recargapay.php?endpoint=cancel', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    payment_id: currentPaymentId,
                    csrf_token: '<?= generate_csrf_token() ?>'
                })
            });
            console.log('Pagamento pendente cancelado:', currentPaymentId);
        } catch (error) {
            console.error('Falha ao tentar cancelar o pagamento:', error);
        }
    }

    function startPaymentTimer(seconds, paymentId) {
        clearInterval(paymentCheckInterval); // Limpa verificações anteriores
        const timerDisplay = document.getElementById('timerDisplay');
        let timer = setInterval(() => {
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            if (timerDisplay) {
                timerDisplay.textContent =
                    `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
            }

            if (seconds <= 0) {
                clearInterval(timer);
                clearInterval(paymentCheckInterval);
                if (timerDisplay) {
                    timerDisplay.textContent = 'EXPIRADO';
                    timerDisplay.style.color = 'var(--error)';
                }
                document.getElementById('checkStatusBtn').disabled = true;
            }
            seconds--;
        }, 1000);

        // Inicia a verificação automática a cada 10 segundos
        paymentCheckInterval = setInterval(() => {
            checkPaymentStatus(paymentId, true); // O 'true' indica que é uma verificação automática
        }, 10000);
    }

    async function checkPaymentStatus(paymentId, isAutoCheck = false) {
        if (!isAutoCheck) {
            const checkBtn = document.getElementById('checkStatusBtn');
            checkBtn.disabled = true;
            checkBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';
        }

        try {
            const response = await fetch(`pix_api_recargapay.php?endpoint=status&payment_id=${paymentId}`);
            const result = await response.json();

            if (result.success && result.payment.status === 'paid') {
                clearInterval(paymentCheckInterval); // Para a verificação automática
                alert('Pagamento confirmado! Seus créditos foram adicionados.');
                window.location.reload();
            } else {
                if (!isAutoCheck) {
                    // LÓGICA ALTERADA PARA REDIRECIONAMENTO
                    alert(
                        'Após o pagamento, envie o comprovante para nosso suporte no Telegram para agilizar a liberação dos seus créditos. Você será redirecionado agora.'
                    );
                    window.location.href = 'https://t.me/Medusah_777';
                }
            }
        } catch (error) {
            console.error('Erro ao verificar status:', error);
            if (!isAutoCheck) {
                alert('Ocorreu um erro ao verificar o status do pagamento.');
            }
        } finally {
            if (!isAutoCheck) {
                const checkBtn = document.getElementById('checkStatusBtn');
                checkBtn.disabled = false;
                checkBtn.innerHTML = '<i class="fas fa-check-circle"></i> Já Paguei';
            }
        }
    }

    // Funções auxiliares (se já existirem no seu script.js, não precisa adicionar novamente)
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const firstInput = document.querySelector('.form-input');
        if (firstInput) {
            firstInput.focus();
        }
    });
    </script>
</body>

</html