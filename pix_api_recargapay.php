<?php
/**
 * API PIX RECARGAPAY - CENTRAL DE CHECKERS
 * Endpoints para gerenciar pagamentos PIX via RecargaPay
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

// Incluir sistemas
require_once 'credits_system.php';
require_once 'recargapay_pix_integration.php';

// Inicializar conexão com banco
try {
    $pdo = connect_db();
    $creditSystem = initCreditSystem($pdo);
    $recargaPaySystem = initRecargaPaySystem($pdo, $creditSystem);
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

// Em pix_api_recargapay.php, dentro da função validateAuth()
function validateAuth() {
    // Inicia a sessão APENAS se ela ainda não foi iniciada
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Não autenticado'], 401);
    }
    return $_SESSION['user_id'];
}

// Função para validar CSRF
function validateCSRF($input) { // A função agora aceita um parâmetro
    $token = $input['csrf_token'] ?? ''; // Pega o token do input decodificado
    if (!verify_csrf_token($token)) {
        jsonResponse(['error' => 'Token CSRF inválido'], 403);
    }
}

// Roteamento da API
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['endpoint'] ?? '';

try {
    switch ($path) {
        
        // GET /api/pix/config - Obter configurações PIX RecargaPay
        case 'config':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            $recargaPayConfig = RecargaPayPixIntegration::getRecargaPayConfig();
            
            jsonResponse([
                'provider' => 'RecargaPay',
                'merchant_name' => $recargaPayConfig['merchant_name'],
                'merchant_city' => $recargaPayConfig['merchant_city'],
                'minimum_amount' => floatval($creditSystem->getSetting('minimum_recharge_amount', '50.00')),
                'minimum_amount_formatted' => CreditSystem::formatCurrency($creditSystem->getSetting('minimum_recharge_amount', '50.00')),
                'maximum_amount' => $recargaPayConfig['max_amount'],
                'maximum_amount_formatted' => CreditSystem::formatCurrency($recargaPayConfig['max_amount']),
                'cost_per_test' => floatval($creditSystem->getSetting('cost_per_approved_test', '1.50')),
                'cost_per_test_formatted' => CreditSystem::formatCurrency($creditSystem->getSetting('cost_per_approved_test', '1.50')),
                'expiration_minutes' => intval($creditSystem->getSetting('pix_expiration_minutes', '30')),
                'system_enabled' => $creditSystem->isSystemEnabled(),
                'pix_configured' => true,
                'supports_webhook' => $recargaPayConfig['supports_webhook'],
                'currency' => $recargaPayConfig['currency'],
                'country' => $recargaPayConfig['country']
            ]);
            break;
            
        // POST /api/pix/generate - Gerar pagamento PIX RecargaPay
        case 'generate':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            
            // 1. Lemos o corpo da requisição JSON PRIMEIRO
            $input = json_decode(file_get_contents('php://input'), true);

            // 2. Passamos os dados lidos para a validação do CSRF
            validateCSRF($input);
            
            // 3. Agora podemos usar os dados para o resto da lógica
            $amount = floatval($input['amount'] ?? 0);
            $description = sanitize_input($input['description'] ?? 'Recarga de créditos');
        
            if (!$creditSystem->validateRechargeAmount($amount)) {
                $minAmount = $creditSystem->getSetting('minimum_recharge_amount', '50.00');
                jsonResponse([
                    'error' => 'Valor inválido',
                    'message' => "Valor mínimo para recarga: " . CreditSystem::formatCurrency($minAmount)
                ], 400);
            }
            
            // Chamar o sistema RecargaPay para criar o pagamento
            $paymentData = $recargaPaySystem->createRecargaPayPayment($userId, $amount, $description);

            // Retornar os dados do pagamento para o frontend
            jsonResponse([
                'success' => true,
                'provider' => 'RecargaPay',
                'payment' => $paymentData, // Estrutura correta para o frontend
                'message' => 'Pagamento PIX RecargaPay gerado com sucesso'
            ]);
            break;
            
        // GET /api/pix/status/{payment_id} - Verificar status do pagamento
        case 'status':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            $paymentId = $_GET['payment_id'] ?? '';
            
            if (empty($paymentId)) {
                jsonResponse(['error' => 'ID do pagamento é obrigatório'], 400);
            }
            
            $paymentStatus = $recargaPaySystem->checkRecargaPayPaymentStatus($paymentId);
            
            // Verificar se o pagamento pertence ao usuário (segurança)
            $stmt = $pdo->prepare("
                SELECT pp.user_id 
                FROM pix_payments pp 
                WHERE pp.payment_id = ?
            ");
            $stmt->execute([$paymentId]);
            $paymentOwner = $stmt->fetch();
            
            if (!$paymentOwner || $paymentOwner['user_id'] != $userId) {
                jsonResponse(['error' => 'Acesso negado'], 403);
            }
            
            jsonResponse([
                'success' => true,
                'provider' => 'RecargaPay',
                'payment' => $paymentStatus
            ]);
            break;
            
        // POST /api/pix/confirm - Confirmar pagamento RecargaPay
        case 'confirm':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $paymentId = $input['payment_id'] ?? '';
            $confirmationData = $input['confirmation_data'] ?? null;
            
            if (empty($paymentId)) {
                jsonResponse(['error' => 'ID do pagamento é obrigatório'], 400);
            }
            
            $result = $recargaPaySystem->confirmRecargaPayPayment($paymentId, $confirmationData);
            
            jsonResponse([
                'success' => true,
                'provider' => 'RecargaPay',
                'message' => 'Pagamento RecargaPay confirmado com sucesso',
                'result' => $result
            ]);
            break;
            
        // POST /api/pix/cancel - Cancelar pagamento
        case 'cancel':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            
            $input = json_decode(file_get_contents('php://input'), true);
            validateCSRF($input); // Passa o input para a validação
            $paymentId = $input['payment_id'] ?? '';
            
            if (empty($paymentId)) {
                jsonResponse(['error' => 'ID do pagamento é obrigatório'], 400);
            }
            
            // Verificar se o pagamento existe e pertence ao usuário
            $stmt = $pdo->prepare("
                SELECT pp.*
                FROM pix_payments pp
                WHERE pp.payment_id = ? AND pp.user_id = ?
            ");
            $stmt->execute([$paymentId, $userId]);
            $payment = $stmt->fetch();
            
            if (!$payment) {
                jsonResponse(['error' => 'Pagamento não encontrado'], 404);
            }
            
            if ($payment['status'] !== 'pending') {
                jsonResponse(['error' => 'Pagamento não pode ser cancelado'], 400);
            }
            
            // Cancelar pagamento
            $stmt = $pdo->prepare("UPDATE pix_payments SET status = 'cancelled' WHERE payment_id = ?");
            $stmt->execute([$paymentId]);
            
            // Cancelar transação
            $creditSystem->cancelTransaction($payment['transaction_id'], 'Cancelado pelo usuário');
            
            jsonResponse([
                'success' => true,
                'provider' => 'RecargaPay',
                'message' => 'Pagamento RecargaPay cancelado com sucesso'
            ]);
            break;
            
        // GET /api/pix/payments - Listar pagamentos do usuário
        case 'payments':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            $limit = intval($_GET['limit'] ?? 20);
            $offset = intval($_GET['offset'] ?? 0);
            
            $stmt = $pdo->prepare("
                SELECT 
                    pp.payment_id,
                    pp.amount,
                    pp.status,
                    pp.created_at,
                    pp.expires_at,
                    pp.paid_at,
                    ct.description
                FROM pix_payments pp
                JOIN credit_transactions ct ON pp.transaction_id = ct.id
                WHERE pp.user_id = ?
                ORDER BY pp.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $stmt->execute([$userId, $limit, $offset]);
            $payments = $stmt->fetchAll();
            
            // Formatar dados
            foreach ($payments as &$payment) {
                $payment['amount'] = floatval($payment['amount']);
                $payment['amount_formatted'] = CreditSystem::formatCurrency($payment['amount']);
                $payment['provider'] = 'RecargaPay';
                $payment['is_expired'] = strtotime($payment['expires_at']) < time();
            }
            
            jsonResponse([
                'success' => true,
                'provider' => 'RecargaPay',
                'payments' => $payments,
                'total' => count($payments),
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        // POST /api/pix/webhook - Webhook RecargaPay
        case 'webhook':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            // Log do webhook para debug
            $webhookData = file_get_contents('php://input');
            error_log("RecargaPay Webhook recebido: " . $webhookData);
            
            $input = json_decode($webhookData, true);
            
            if (!$input) {
                jsonResponse(['error' => 'Dados do webhook inválidos'], 400);
            }
            
            $result = $recargaPaySystem->processRecargaPayWebhook($input);
            
            jsonResponse([
                'success' => $result['success'],
                'provider' => 'RecargaPay',
                'message' => $result['message'] ?? 'Webhook processado',
                'result' => $result['result'] ?? null
            ]);
            break;
            
        // GET /api/pix/report - Relatório RecargaPay
        case 'report':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            
            // Verificar se é admin (adaptar conforme sua lógica)
            if ($userId != 1) {
                jsonResponse(['error' => 'Acesso negado - apenas administradores'], 403);
            }
            
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            
            $report = $recargaPaySystem->getRecargaPayReport($startDate, $endDate);
            
            jsonResponse([
                'success' => true,
                'provider' => 'RecargaPay',
                'report' => $report
            ]);
            break;
            
        // GET /api/pix/instructions - Instruções de pagamento
        case 'instructions':
            if ($method !== 'GET') {
                jsonResponse(['error' => 'Método não permitido'], 405);
            }
            
            $userId = validateAuth();
            
            jsonResponse([
                'success' => true,
                'provider' => 'RecargaPay',
                'instructions' => [
                    'title' => 'Como pagar via PIX RecargaPay',
                    'steps' => [
                        '1. Abra o app do seu banco (Nubank, Itaú, Bradesco, etc.)',
                        '2. Vá na opção PIX',
                        '3. Escolha "Pagar com QR Code" ou "PIX Copia e Cola"',
                        '4. Escaneie o QR Code ou cole o código PIX',
                        '5. Confirme os dados: TIAGO SOUZA SANTANA - Salvador',
                        '6. Confirme o pagamento no seu banco',
                        '7. Aguarde a confirmação (até 2 minutos)'
                    ],
                    'important_notes' => [
                        'O pagamento será processado via RecargaPay',
                        'Os créditos são adicionados automaticamente após confirmação',
                        'Em caso de problemas, entre em contato pelo Telegram @ALVINCODER',
                        'Guarde o comprovante do pagamento'
                    ],
                    'merchant_info' => RecargaPayPixIntegration::getRecargaPayConfig()
                ]
            ]);
            break;
            
        default:
            jsonResponse(['error' => 'Endpoint não encontrado'], 404);
    }
    
} catch (Exception $e) {
    error_log("Erro na API PIX RecargaPay: " . $e->getMessage());
    jsonResponse([
        'error' => 'Erro interno do servidor', 
        'message' => $e->getMessage(),
        'provider' => 'RecargaPay'
    ], 500);
}
?>
