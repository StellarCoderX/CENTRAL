 <?php
/**
 * SISTEMA DE CRÉDITOS - CENTRAL DE CHECKERS
 * Gerenciamento completo de créditos e transações
 */


class CreditSystem {
    private $pdo;
    private $settings;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadSettings();
    }
    
    /**
     * Carrega configurações do sistema
     */
    private function loadSettings() {
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM credit_settings");
        $this->settings = [];
        while ($row = $stmt->fetch()) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    /**
     * Obtém uma configuração específica
     */
    public function getSetting($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
    
    /**
     * Atualiza uma configuração
     */
    public function updateSetting($key, $value, $userId = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO credit_settings (setting_key, setting_value, updated_by)
            VALUES (:key, :value, :user_id)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $result = $stmt->execute([
            'key' => $key,
            'value' => $value,
            'user_id' => $userId
        ]);
        
        if ($result) {
            $this->settings[$key] = $value;
        }
        
        return $result;
    }
    
    /**
     * Obtém o saldo atual do usuário
     */
    public function getUserBalance($userId) {
        // CORRIGIDO: Substituindo a função do banco de dados por uma consulta direta
        $stmt = $this->pdo->prepare("SELECT COALESCE(balance, 0.00) as balance FROM user_credits WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return floatval($result['balance'] ?? 0.00);
    }
    
    /**
     * Verifica se o usuário tem saldo suficiente
     */
    public function hasSufficientBalance($userId, $amount) {
        return $this->getUserBalance($userId) >= $amount;
    }
    
    /**
     * Adiciona créditos ao usuário
     */
    public function addCreditsToUser($userId, $amount, $description, $referenceId = null) {
        try {
            $this->pdo->beginTransaction();

            // 1. Inserir ou atualizar o saldo do usuário
            $stmt = $this->pdo->prepare("
                INSERT INTO user_credits (user_id, balance) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$userId, $amount]);

            // 2. Criar a transação de crédito como 'completed'
            $stmt = $this->pdo->prepare("
                INSERT INTO credit_transactions 
                (user_id, transaction_type, amount, description, reference_id, status) 
                VALUES (?, 'credit', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$userId, $amount, $description, $referenceId]);
            $transactionId = $this->pdo->lastInsertId();
            
            $this->pdo->commit();

            return [
                'success' => true,
                'transaction_id' => $transactionId
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Erro ao adicionar créditos: " . $e->getMessage());
            return ['success' => false, 'transaction_id' => null];
        }
    }
    
    /**
     * Debita créditos do usuário
     */
    public function debitUserCredits($userId, $amount, $description, $referenceId = null) {
        try {
            $this->pdo->beginTransaction();

            $balance = $this->getUserBalance($userId);

            if ($balance < $amount) {
                $this->pdo->rollBack();
                return ['success' => false, 'transaction_id' => null];
            }
            
            // Criar a transação de débito
            $stmt = $this->pdo->prepare("
                INSERT INTO credit_transactions 
                (user_id, transaction_type, amount, description, reference_id, status) 
                VALUES (?, 'debit', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$userId, $amount, $description, $referenceId]);
            $transactionId = $this->pdo->lastInsertId();

            // Atualizar o saldo do usuário
            $stmt = $this->pdo->prepare("
                UPDATE user_credits 
                SET balance = balance - ?, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?
            ");
            $stmt->execute([$amount, $userId]);
            
            $this->pdo->commit();

            return [
                'success' => true,
                'transaction_id' => $transactionId
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Erro ao debitar créditos: " . $e->getMessage());
            return ['success' => false, 'transaction_id' => null];
        }
    }
    
    /**
     * Completa uma transação pendente
     */
    public function completeTransaction($transactionId) {
        $stmt = $this->pdo->prepare("
            UPDATE credit_transactions 
            SET status = 'completed' 
            WHERE id = ? AND status = 'pending'
        ");
        
        return $stmt->execute([$transactionId]);
    }
    
    /**
     * Cancela uma transação
     */
    public function cancelTransaction($transactionId, $reason = 'Cancelled by user') {
        $stmt = $this->pdo->prepare("
            UPDATE credit_transactions 
            SET status = 'cancelled', description = CONCAT(description, ' - ', ?) 
            WHERE id = ? AND status = 'pending'
        ");
        
        return $stmt->execute([$reason, $transactionId]);
    }
    
    /**
     * Obtém histórico de transações do usuário
     */
    public function getUserTransactionHistory($userId, $limit = 50, $offset = 0) {
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                transaction_type,
                amount,
                description,
                reference_id,
                status,
                created_at
            FROM credit_transactions 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Registra uso de ferramenta
     */
    public function logToolUsage($userId, $toolId, $resultStatus, $inputData = null, $outputData = null) {
        $creditsCharged = 0;
        $transactionId = null;
        
        // Se o resultado foi aprovado, cobrar créditos
        if ($resultStatus === 'approved') {
            $costPerTest = floatval($this->getSetting('cost_per_approved_test', '1.50'));
            
            $debitResult = $this->debitUserCredits(
                $userId, 
                $costPerTest, 
                'Uso de ferramenta - Resultado aprovado',
                "tool_usage_" . time()
            );
            
            if ($debitResult['success']) {
                $creditsCharged = $costPerTest;
                $transactionId = $debitResult['transaction_id'];
            } else {
                // Se não conseguiu debitar, marcar como erro
                $resultStatus = 'error';
                $outputData = 'Erro: Saldo insuficiente para cobrar pelo teste aprovado';
            }
        }
        
        // Registrar no log
        $stmt = $this->pdo->prepare("
            INSERT INTO tool_usage_log 
            (user_id, tool_id, result_status, credits_charged, transaction_id, input_data, output_data, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $toolId,
            $resultStatus,
            $creditsCharged,
            $transactionId,
            $inputData,
            $outputData,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        return [
            'log_id' => $this->pdo->lastInsertId(),
            'credits_charged' => $creditsCharged,
            'transaction_id' => $transactionId,
            'result_status' => $resultStatus
        ];
    }
    
    /**
     * Obtém estatísticas de uso do usuário
     */
    public function getUserUsageStats($userId, $days = 30) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_tests,
                SUM(CASE WHEN result_status = 'approved' THEN 1 ELSE 0 END) as approved_tests,
                SUM(CASE WHEN result_status = 'rejected' THEN 1 ELSE 0 END) as rejected_tests,
                SUM(CASE WHEN result_status = 'error' THEN 1 ELSE 0 END) as error_tests,
                SUM(credits_charged) as total_credits_spent
            FROM tool_usage_log 
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        
        $stmt->execute([$userId, $days]);
        return $stmt->fetch();
    }
    
    /**
     * Verifica se o sistema está ativo
     */
    public function isSystemEnabled() {
        return $this->getSetting('system_enabled', '1') === '1';
    }
    
    /**
     * Adiciona créditos gratuitos para novos usuários
     */
    public function addFreeTrialCredits($userId) {
        $freeCredits = floatval($this->getSetting('free_trial_credits', '5.00'));
        
        if ($freeCredits > 0) {
            // Verificar se já recebeu créditos gratuitos
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM credit_transactions 
                WHERE user_id = ? AND description LIKE '%créditos gratuitos%'
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            
            if ($result['count'] == 0) {
                // CORRIGIDO: Chamar a nova função addCreditsToUser
                $this->addCreditsToUser(
                    $userId,
                    $freeCredits,
                    'Créditos gratuitos de boas-vindas',
                    'free_trial_' . $userId
                );
                
                return $freeCredits;
            }
        }
        
        return 0;
    }
    
    /**
     * Valida valor de recarga
     */
    public function validateRechargeAmount($amount) {
        $minAmount = floatval($this->getSetting('minimum_recharge_amount', '50.00'));
        $maxAmount = 10000.00; // Limite máximo de segurança
        
        return $amount >= $minAmount && $amount <= $maxAmount;
    }
    
    /**
     * Formata valor monetário
     */
   public static function formatCurrency($amount) {
    // Garante que o valor seja um número float, tratando null como 0.0.
    $numericAmount = floatval($amount); 
    return 'R$ ' . number_format($numericAmount, 2, ',', '.');
}
    
    /**
     * Obtém resumo da conta do usuário
     */
    public function getUserAccountSummary($userId) {
        $balance = $this->getUserBalance($userId);
        $stats = $this->getUserUsageStats($userId);
        
        return [
            'balance' => $balance,
            'balance_formatted' => self::formatCurrency($balance),
            'total_tests' => $stats['total_tests'] ?? 0,
            'approved_tests' => $stats['approved_tests'] ?? 0,
            'rejected_tests' => $stats['rejected_tests'] ?? 0,
            'error_tests' => $stats['error_tests'] ?? 0,
            'total_spent' => $stats['total_credits_spent'] ?? 0,
            'total_spent_formatted' => self::formatCurrency($stats['total_credits_spent'] ?? 0),
            'cost_per_test' => $this->getSetting('cost_per_approved_test', '1.50'),
            'cost_per_test_formatted' => self::formatCurrency($this->getSetting('cost_per_approved_test', '1.50'))
        ];
    }
}

/**
 * Funções auxiliares para integração com o sistema existente
 */

/**
 * Inicializa o sistema de créditos
 */
function initCreditSystem($pdo) {
    return new CreditSystem($pdo);
}

/**
 * Middleware para verificar créditos antes de executar ferramenta
 */
function checkCreditsBeforeToolExecution($userId, $toolId, $pdo) {
    $creditSystem = initCreditSystem($pdo);
    
    if (!$creditSystem->isSystemEnabled()) {
        return ['allowed' => true, 'message' => 'Sistema de créditos desabilitado'];
    }
    
    $costPerTest = floatval($creditSystem->getSetting('cost_per_approved_test', '1.50'));
    $userBalance = $creditSystem->getUserBalance($userId);
    
    if ($userBalance < $costPerTest) {
        return [
            'allowed' => false, 
            'message' => 'Saldo insuficiente. Você precisa de pelo menos ' . CreditSystem::formatCurrency($costPerTest) . ' para usar esta ferramenta.',
            'current_balance' => CreditSystem::formatCurrency($userBalance),
            'required_amount' => CreditSystem::formatCurrency($costPerTest)
        ];
    }
    
    return [
        'allowed' => true, 
        'message' => 'Créditos suficientes',
        'current_balance' => CreditSystem::formatCurrency($userBalance),
        'cost_per_test' => CreditSystem::formatCurrency($costPerTest)
    ];
}

/**
 * Processa resultado da ferramenta e cobra créditos se necessário
 */
function processToolResult($userId, $toolId, $resultStatus, $inputData, $outputData, $pdo) {
    $creditSystem = initCreditSystem($pdo);
    
    if (!$creditSystem->isSystemEnabled()) {
        return ['charged' => false, 'message' => 'Sistema de créditos desabilitado'];
    }
    
    $result = $creditSystem->logToolUsage($userId, $toolId, $resultStatus, $inputData, $outputData);
    
    return [
        'charged' => $result['credits_charged'] > 0,
        'amount_charged' => $result['credits_charged'],
        'amount_charged_formatted' => CreditSystem::formatCurrency($result['credits_charged']),
        'transaction_id' => $result['transaction_id'],
        'new_balance' => CreditSystem::formatCurrency($creditSystem->getUserBalance($userId)),
        'result_status' => $result['result_status']
    ];
}

?>