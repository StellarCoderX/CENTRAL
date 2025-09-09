 <?php
/**
 * API CRÉDITOS - CENTRAL DE CHECKERS
 * Endpoints para gerenciar créditos dos usuários
 */
require_once 'db_connection.php'; // <-- ADICIONE ESTA LINHA AQUI
// Headers para API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Tratar OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Incluir sistema de créditos
require_once 'credits_system.php';

// Inicializar conexão com banco
try {
    $pdo = connect_db();
    $creditSystem = initCreditSystem($pdo);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro de conexão com banco de dados']);
    exit();
}

// Função para resposta JSON
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// Função para validar autenticação
function validateAuth() {
    if (session_status() === PHP_SESSION_NONE) { // <-- Usar esta verificação
        session_start(); // <-- Mudar esta linha
    }
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Não autenticado'], 401);
    }
    return $_SESSION['user_id'];
}

// Função para validar CSRF
function validateCSRF() {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        jsonResponse(['error' => 'Token CSRF inválido'], 403);
    }
}

// Roteamento da API
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['endpoint'] ?? '';

try {
    switch ($path) {
        
        // GET /api/credits/balance - Obter saldo atual
        case 'balance':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            $balance = $creditSystem->getUserBalance($userId);
            
            jsonResponse([
                'balance' => $balance,
                'balance_formatted' => CreditSystem::formatCurrency($balance),
                'system_enabled' => $creditSystem->isSystemEnabled()
            ]);
            break;
            
        // GET /api/credits/summary - Resumo completo da conta
        case 'summary':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            $summary = $creditSystem->getUserAccountSummary($userId);
            
            jsonResponse([
                'success' => true,
                'summary' => $summary
            ]);
            break;
            
        // GET /api/credits/history - Histórico de transações
        case 'history':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            $limit = intval($_GET['limit'] ?? 50);
            $offset = intval($_GET['offset'] ?? 0);
            
            $transactions = $creditSystem->getUserTransactionHistory($userId, $limit, $offset);
            
            // Formatar dados
            foreach ($transactions as &$transaction) {
                $transaction['amount'] = floatval($transaction['amount']);
                $transaction['amount_formatted'] = CreditSystem::formatCurrency($transaction['amount']);
                $transaction['type_label'] = $transaction['transaction_type'] === 'credit' ? 'Crédito' : 'Débito';
            }
            
            jsonResponse([
                'transactions' => $transactions,
                'total' => count($transactions),
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        // GET /api/credits/usage - Estatísticas de uso
        case 'usage':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            $days = intval($_GET['days'] ?? 30);
            
            $stats = $creditSystem->getUserUsageStats($userId, $days);
            
            jsonResponse([
                'period_days' => $days,
                'total_tests' => intval($stats['total_tests'] ?? 0),
                'approved_tests' => intval($stats['approved_tests'] ?? 0),
                'rejected_tests' => intval($stats['rejected_tests'] ?? 0),
                'error_tests' => intval($stats['error_tests'] ?? 0),
                'total_spent' => floatval($stats['total_credits_spent'] ?? 0),
                'total_spent_formatted' => CreditSystem::formatCurrency($stats['total_credits_spent'] ?? 0),
                'approval_rate' => $stats['total_tests'] > 0 ? round(($stats['approved_tests'] / $stats['total_tests']) * 100, 1) : 0
            ]);
            break;
            
        // POST /api/credits/check - Verificar se pode executar ferramenta
        case 'check':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            $input = json_decode(file_get_contents('php://input'), true);
            $toolId = intval($input['tool_id'] ?? 0);
            
            if ($toolId <= 0) {
                jsonResponse(['error' => 'ID da ferramenta é obrigatório'], 400);
            }
            
            $check = checkCreditsBeforeToolExecution($userId, $toolId, $pdo);
            
            jsonResponse($check);
            break;
            
        // POST /api/credits/process - Processar resultado da ferramenta
        case 'process':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            validateCSRF();
            
            $input = json_decode(file_get_contents('php://input'), true);
            $toolId = intval($input['tool_id'] ?? 0);
            $resultStatus = $input['result_status'] ?? '';
            $inputData = $input['input_data'] ?? '';
            $outputData = $input['output_data'] ?? '';
            
            if ($toolId <= 0 || empty($resultStatus)) {
                jsonResponse(['error' => 'Dados obrigatórios não fornecidos'], 400);
            }
            
            if (!in_array($resultStatus, ['approved', 'rejected', 'error'])) {
                jsonResponse(['error' => 'Status de resultado inválido'], 400);
            }
            
            $result = processToolResult($userId, $toolId, $resultStatus, $inputData, $outputData, $pdo);
            
            jsonResponse([
                'success' => true,
                'result' => $result
            ]);
            break;
            
        // GET /api/credits/tools-usage - Log de uso das ferramentas
        case 'tools-usage':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            $limit = intval($_GET['limit'] ?? 20);
            $offset = intval($_GET['offset'] ?? 0);
            
            $stmt = $pdo->prepare("
                SELECT 
                    tul.*,
                    t.name as tool_name,
                    t.description as tool_description
                FROM tool_usage_log tul
                JOIN tools t ON tul.tool_id = t.id
                WHERE tul.user_id = ?
                ORDER BY tul.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $stmt->execute([$userId, $limit, $offset]);
            $usage = $stmt->fetchAll();
            
            // Formatar dados
            foreach ($usage as &$log) {
                $log['credits_charged'] = floatval($log['credits_charged']);
                $log['credits_charged_formatted'] = CreditSystem::formatCurrency($log['credits_charged']);
                $log['execution_time'] = floatval($log['execution_time'] ?? 0);
                $log['status_label'] = [
                    'approved' => 'Aprovado',
                    'rejected' => 'Rejeitado',
                    'error' => 'Erro'
                ][$log['result_status']] ?? $log['result_status'];
            }
            
            jsonResponse([
                'usage_log' => $usage,
                'total' => count($usage),
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        // POST /api/credits/add-trial - Adicionar créditos de teste (apenas para novos usuários)
        case 'add-trial':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            validateCSRF();
            
            $creditsAdded = $creditSystem->addFreeTrialCredits($userId);
            
            if ($creditsAdded > 0) {
                jsonResponse([
                    'success' => true,
                    'message' => 'Créditos gratuitos adicionados com sucesso!',
                    'credits_added' => $creditsAdded,
                    'credits_added_formatted' => CreditSystem::formatCurrency($creditsAdded),
                    'new_balance' => CreditSystem::formatCurrency($creditSystem->getUserBalance($userId))
                ]);
            } else {
                jsonResponse([
                    'success' => false,
                    'message' => 'Você já recebeu seus créditos gratuitos ou o sistema não está configurado para oferecê-los.'
                ]);
            }
            break;
            
        default:
            jsonResponse(['error' => 'Endpoint não encontrado'], 404);
    }
    
} catch (Exception $e) {
    error_log("Erro na API de Créditos: " . $e->getMessage());
    jsonResponse(['error' => 'Erro interno do servidor', 'message' => $e->getMessage()], 500);
}
?