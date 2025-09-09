 <?php

/**
 * INTEGRAÇÃO RECARGAPAY PIX - CENTRAL DE CHECKERS
 * Sistema específico para RecargaPay com código PIX fornecido
 */
require_once 'db_connection.php'; // <-- ADICIONE ESTA LINHA AQUI
class RecargaPayPixIntegration {
    
    // Código PIX base fornecido pelo usuário
    const RECARGAPAY_PIX_BASE = '00020126580014br.gov.bcb.pix01362b3485a0-d28c-4d37-9b5d-b2efcac4cee65204000053039865802BR5919TIAGO SOUZA SANTANA6008Salvador6211050726f78796304D315';
    
    // Informações extraídas do código PIX
    const MERCHANT_INFO = [
        'name' => 'TIAGO SOUZA SANTANA',
        'city' => 'Salvador',
        'pix_key' => '2b3485a0-d28c-4d37-9b5d-b2efcac4cee6',
        'account_type' => 'RecargaPay'
    ];
    
    private $pdo;
    private $creditSystem;
    
    public function __construct($pdo, $creditSystem) {
        $this->pdo = $pdo;
        $this->creditSystem = $creditSystem;
    }
    
    /**
     * Gera PIX personalizado para RecargaPay
     */
    public function generateRecargaPayPix($amount, $description, $txid) {
        // Validar valor
        if ($amount < 1.00 || $amount > 50000.00) {
            throw new Exception('Valor inválido para PIX RecargaPay');
        }
        
        // Gerar código PIX personalizado com valor
        $pixCode = $this->buildRecargaPayPixCode($amount, $description, $txid);
        
        // Não gerar o QR Code
        $qrCodeData = '';
        
        return [
            'pix_code' => $pixCode,
            'qr_code_data' => $qrCodeData,
            'merchant_name' => self::MERCHANT_INFO['name'],
            'merchant_city' => self::MERCHANT_INFO['city'],
            'amount' => $amount,
            'description' => $description,
            'txid' => $txid,
            'expires_in_minutes' => 30
        ];
    }
    
    /**
     * Constrói código PIX personalizado para RecargaPay
     */
    private function buildRecargaPayPixCode($amount, $description, $txid) {
        // Componentes do PIX
        $components = [
            '00' => '01', // Payload Format Indicator
            '01' => '12', // Point of Initiation Method
            '26' => $this->buildMerchantAccountInfo(),
            '52' => '0000', // Merchant Category Code
            '53' => '986', // Transaction Currency (BRL)
            '54' => number_format($amount, 2, '.', ''), // Transaction Amount
            '58' => 'BR', // Country Code
            '59' => substr(self::MERCHANT_INFO['name'], 0, 25), // Merchant Name
            '60' => substr(self::MERCHANT_INFO['city'], 0, 15), // Merchant City
            '62' => $this->buildAdditionalDataField($txid, $description)
        ];
        
        // Construir string PIX
        $pixString = '';
        foreach ($components as $id => $value) {
            $pixString .= sprintf('%02d%02d%s', $id, strlen($value), $value);
        }
        
        // Adicionar CRC16
        $pixString .= '6304';
        $crc = $this->calculateCRC16($pixString);
        $pixString .= strtoupper(sprintf('%04X', $crc));
        
        return $pixString;
    }
    
    /**
     * Constrói informações da conta do comerciante
     */
    private function buildMerchantAccountInfo() {
        $merchantData = [
            '00' => 'br.gov.bcb.pix',
            '01' => self::MERCHANT_INFO['pix_key']
        ];
        
        $merchantString = '';
        foreach ($merchantData as $id => $value) {
            $merchantString .= sprintf('%02d%02d%s', $id, strlen($value), $value);
        }
        
        return $merchantString;
    }
    
    /**
     * Constrói campo de dados adicionais
     */
    private function buildAdditionalDataField($txid, $description) {
    // Para garantir máxima compatibilidade, usamos '***' como txid.
    // Isso indica uma transação simples, sem um ID de gateway específico,
    // e é o formato mais aceito pelos bancos.
    $txidLimpo = '***';

    $campo05 = '05' . sprintf('%02d', strlen($txidLimpo)) . $txidLimpo;

    // O campo 62 deve conter o campo 05 (txid)
    return $campo05;
}
    /**
     * Calcula CRC16 para PIX
     */
    private function calculateCRC16($data) {
        $crc = 0xFFFF;
        
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 8;
            
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = ($crc << 1) ^ 0x1021;
                } else {
                    $crc = $crc << 1;
                }
                $crc &= 0xFFFF;
            }
        }
        
        return $crc;
    }
    
    /**
     * Gera dados do QR Code
     */
private function generateQRCodeData($pixCode) {
    try {
        // Garante que a classe exista antes de usar
        if (!class_exists('QRcode')) {
            require_once __DIR__ . '/phpqrcode.php';
        }

        ob_start();
        \QRcode::png($pixCode, false, QR_ECLEVEL_L, 10, 2);
        $pngData = ob_get_contents();
        ob_end_clean();

        if (empty($pngData)) {
             throw new Exception("Gerador de QR Code retornou saida vazia.");
        }

        return 'data:image/png;base64,' . base64_encode($pngData);

    } catch (Exception $e) {
        ob_start();
        $im = imagecreate(250, 120);
        $bg = imagecolorallocate($im, 240, 240, 240);
        $textcolor = imagecolorallocate($im, 200, 0, 0);
        imagestring($im, 5, 20, 10, "Erro ao gerar QR Code:", $textcolor);
        $errorMessage = wordwrap($e->getMessage(), 35, "\n");
        imagestring($im, 3, 15, 40, $errorMessage, $textcolor);
        imagepng($im);
        imagedestroy($im);
        $errorPngData = ob_get_contents();
        ob_end_clean();

        return 'data:image/png;base64,' . base64_encode($errorPngData);
    }
}
    
    /**
     * Cria pagamento PIX no RecargaPay
     */
    public function createRecargaPayPayment($userId, $amount, $description = 'Recarga de créditos') {
        // Validar valor mínimo
        if (!$this->creditSystem->validateRechargeAmount($amount)) {
            throw new Exception('Valor inválido para recarga');
        }
        
        // Criar transação de crédito pendente
        $transactionId = $this->creditSystem->createCreditTransaction($userId, $amount, $description);
        
        // Gerar ID único do pagamento
        $paymentId = 'RECARGAPAY_' . time() . '_' . $userId . '_' . rand(1000, 9999);
        $txid = 'TXN' . substr($paymentId, -10);
        
        // Gerar PIX RecargaPay
        $pixData = $this->generateRecargaPayPix($amount, $description, $txid);
        
        // Configurar expiração
        $expirationMinutes = intval($this->creditSystem->getSetting('pix_expiration_minutes', '30'));
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expirationMinutes} minutes"));
        
        // Salvar pagamento no banco
        $stmt = $this->pdo->prepare("
            INSERT INTO pix_payments 
            (user_id, transaction_id, pix_key, amount, qr_code, payment_id, expires_at, pix_response) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $transactionId,
            self::MERCHANT_INFO['pix_key'],
            $amount,
            $pixData['qr_code_data'], 
            $paymentId,
            $expiresAt,
            json_encode(['pix_code' => $pixData['pix_code']])
        ]);
        
        $pixPaymentId = $this->pdo->lastInsertId();
        
        return [
            'payment_id' => $paymentId,
            'pix_payment_id' => $pixPaymentId,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'amount_formatted' => CreditSystem::formatCurrency($amount),
            'pix_code' => $pixData['pix_code'],
            'qr_code_data' => $pixData['qr_code_data'],
            'merchant_name' => $pixData['merchant_name'],
            'merchant_city' => $pixData['merchant_city'],
            'expires_at' => $expiresAt,
            'expires_in_minutes' => $expirationMinutes,
            'txid' => $txid,
            'instructions' => $this->getPaymentInstructions()
        ];
    }
    
    /**
     * Instruções de pagamento para RecargaPay
     */
    private function getPaymentInstructions() {
        return [
            'step1' => 'Abra o app do seu banco (Nubank, Itaú, Bradesco, etc.)',
            'step2' => 'Vá na opção PIX',
            'step3' => 'Escolha "Pagar com QR Code" ou "PIX Copia e Cola"',
            'step4' => 'Escaneie o QR Code ou cole o código PIX',
            'step5' => 'Confirme o pagamento no seu banco',
            'step6' => 'Aguarde a confirmação (até 2 minutos)',
            'note' => 'O pagamento será processado via RecargaPay e os créditos serão adicionados automaticamente.'
        ];
    }
    
    /**
     * Verifica status do pagamento RecargaPay
     */
    public function checkRecargaPayPaymentStatus($paymentId) {
        $stmt = $this->pdo->prepare("
            SELECT pp.*, ct.user_id, ct.amount, ct.description
            FROM pix_payments pp
            JOIN credit_transactions ct ON pp.transaction_id = ct.id
            WHERE pp.payment_id = ?
        ");
        
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            throw new Exception('Pagamento não encontrado');
        }
        
        // Verificar se expirou
        if ($payment['status'] === 'pending' && strtotime($payment['expires_at']) < time()) {
            $this->expirePayment($payment['id']);
            $payment['status'] = 'expired';
        }
        
        return [
            'payment_id' => $payment['payment_id'],
            'status' => $payment['status'],
            'amount' => floatval($payment['amount']),
            'amount_formatted' => CreditSystem::formatCurrency($payment['amount']),
            'description' => $payment['description'],
            'created_at' => $payment['created_at'],
            'expires_at' => $payment['expires_at'],
            'paid_at' => $payment['paid_at'],
            'merchant_info' => self::MERCHANT_INFO,
            'is_expired' => strtotime($payment['expires_at']) < time()
        ];
    }
    
    /**
     * Confirma pagamento RecargaPay
     */
    public function confirmRecargaPayPayment($paymentId, $confirmationData = null) {
        $stmt = $this->pdo->prepare("
            SELECT pp.*, ct.user_id, ct.amount 
            FROM pix_payments pp
            JOIN credit_transactions ct ON pp.transaction_id = ct.id
            WHERE pp.payment_id = ? AND pp.status = 'pending'
        ");
        
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            throw new Exception('Pagamento não encontrado ou já processado');
        }
        
        // Verificar se não expirou
        if (strtotime($payment['expires_at']) < time()) {
            $this->expirePayment($payment['id']);
            throw new Exception('Pagamento expirado');
        }
        
        // Atualizar status do pagamento PIX
        $stmt = $this->pdo->prepare("
            UPDATE pix_payments 
            SET status = 'paid', paid_at = NOW(), pix_response = ?
            WHERE id = ?
        ");
        
        $confirmationResponse = json_encode([
            'confirmed_at' => date('Y-m-d H:i:s'),
            'confirmation_data' => $confirmationData,
            'provider' => 'RecargaPay',
            'merchant' => self::MERCHANT_INFO
        ]);
        
        $stmt->execute([$confirmationResponse, $payment['id']]);
        
        // Completar transação de crédito
        $this->creditSystem->completeTransaction($payment['transaction_id']);
        
        // Log da confirmação
        error_log("RecargaPay PIX confirmado: {$paymentId} - Valor: {$payment['amount']} - Usuário: {$payment['user_id']}");
        
        return [
            'success' => true,
            'payment_id' => $paymentId,
            'amount' => $payment['amount'],
            'amount_formatted' => CreditSystem::formatCurrency($payment['amount']),
            'user_id' => $payment['user_id'],
            'new_balance' => $this->creditSystem->getUserBalance($payment['user_id']),
            'new_balance_formatted' => CreditSystem::formatCurrency($this->creditSystem->getUserBalance($payment['user_id'])),
            'confirmed_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Expira pagamento
     */
    private function expirePayment($pixPaymentId) {
        $stmt = $this->pdo->prepare("
            UPDATE pix_payments 
            SET status = 'expired' 
            WHERE id = ? AND status = 'pending'
        ");
        
        $stmt->execute([$pixPaymentId]);
        
        // Cancelar transação associada
        $stmt = $this->pdo->prepare("
            SELECT transaction_id FROM pix_payments WHERE id = ?
        ");
        $stmt->execute([$pixPaymentId]);
        $result = $stmt->fetch();
        
        if ($result) {
            $this->creditSystem->cancelTransaction($result['transaction_id'], 'PIX RecargaPay expirado');
        }
    }
    
    /**
     * Webhook para RecargaPay (se disponível)
     */
    public function processRecargaPayWebhook($webhookData) {
        // Log do webhook
        error_log("RecargaPay Webhook recebido: " . json_encode($webhookData));
        
        // Processar dados do webhook conforme API do RecargaPay
        if (isset($webhookData['payment_id']) && isset($webhookData['status'])) {
            $paymentId = $webhookData['payment_id'];
            $status = $webhookData['status'];
            
            if ($status === 'paid' || $status === 'confirmed' || $status === 'approved') {
                try {
                    $result = $this->confirmRecargaPayPayment($paymentId, $webhookData);
                    return ['success' => true, 'message' => 'Webhook processado', 'result' => $result];
                } catch (Exception $e) {
                    error_log("Erro no webhook RecargaPay: " . $e->getMessage());
                    return ['success' => false, 'error' => $e->getMessage()];
                }
            }
        }
        
        return ['success' => true, 'message' => 'Webhook recebido mas não processado'];
    }
    
    /**
     * Configurações específicas do RecargaPay
     */
    public static function getRecargaPayConfig() {
        return [
            'provider' => 'RecargaPay',
            'merchant_name' => self::MERCHANT_INFO['name'],
            'merchant_city' => self::MERCHANT_INFO['city'],
            'pix_key' => self::MERCHANT_INFO['pix_key'],
            'account_type' => 'RecargaPay',
            'supports_webhook' => true,
            'manual_confirmation' => true,
            'max_amount' => 50000.00,
            'min_amount' => 1.00,
            'currency' => 'BRL',
            'country' => 'BR'
        ];
    }
    
    /**
     * Gera relatório de pagamentos RecargaPay
     */
    public function getRecargaPayReport($startDate = null, $endDate = null) {
        $startDate = $startDate ?: date('Y-m-d', strtotime('-30 days'));
        $endDate = $endDate ?: date('Y-m-d');
        
        
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_payments,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_payments,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_payments,
                COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_payments,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_received,
                AVG(CASE WHEN status = 'paid' THEN amount ELSE NULL END) as average_payment
            FROM pix_payments 
            WHERE DATE(created_at) BETWEEN ? AND ?
        ");
        
        $stmt->execute([$startDate, $endDate]);
        $stats = $stmt->fetch();
        
        
        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'provider' => 'RecargaPay',
            'merchant' => self::MERCHANT_INFO,
            'statistics' => [
                'total_payments' => intval($stats['total_payments']),
                'paid_payments' => intval($stats['paid_payments']),
                'pending_payments' => intval($stats['pending_payments']),
                'expired_payments' => intval($stats['expired_payments']),
                'total_received' => floatval($stats['total_received']),
                'total_received_formatted' => CreditSystem::formatCurrency($stats['total_received']),
                'average_payment' => floatval($stats['average_payment']),
                'average_payment_formatted' => CreditSystem::formatCurrency($stats['average_payment']),
                'success_rate' => $stats['total_payments'] > 0 ? 
                    round(($stats['paid_payments'] / $stats['total_payments']) * 100, 2) : 0
            ]
        ];
    }
    /**
 * Limpa todos os pagamentos PIX pendentes que já expiraram.
 * Esta é a função principal que o cron job irá chamar.
 */
public function cleanExpiredPayments() {
    // Seleciona todos os pagamentos pendentes que já passaram da data de expiração
    $stmt = $this->pdo->prepare("
        SELECT id, transaction_id
        FROM pix_payments
        WHERE status = 'pending' AND expires_at < NOW()
    ");
    $stmt->execute();
    $expired_payments = $stmt->fetchAll();

    $count = 0;
    foreach ($expired_payments as $payment) {
        // Usamos uma transação para garantir que ambas as tabelas sejam atualizadas corretamente
        $this->pdo->beginTransaction();
        try {
            // 1. Atualiza o status do pagamento PIX para 'expired'
            $update_stmt = $this->pdo->prepare("
                UPDATE pix_payments SET status = 'expired' WHERE id = ?
            ");
            $update_stmt->execute([$payment['id']]);

            // 2. Cancela a transação de crédito associada
            $this->creditSystem->cancelTransaction($payment['transaction_id'], 'PIX expirado');

            $this->pdo->commit();
            $count++;
        } catch (Exception $e) {
            // Se algo der errado, desfaz tudo para manter a consistência do banco
            $this->pdo->rollBack();
            error_log("Falha ao limpar o pagamento PIX ID {$payment['id']}: " . $e->getMessage());
        }
    }
    return $count;
}
}

/**
 * Função para inicializar RecargaPay
 */
function initRecargaPaySystem($pdo, $creditSystem) {
    return new RecargaPayPixIntegration($pdo, $creditSystem);
}


/**
 * Função para atualizar configurações do sistema para RecargaPay
 */
function updateSystemForRecargaPay($pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO credit_settings (setting_key, setting_value, description) VALUES
        ('pix_provider', 'RecargaPay', 'Provedor PIX utilizado'),
        ('recargapay_merchant_name', 'TIAGO SOUZA SANTANA', 'Nome do comerciante RecargaPay'),
        ('recargapay_merchant_city', 'Salvador', 'Cidade do comerciante RecargaPay'),
        ('recargapay_pix_key', '2b3485a0-d28c-4d37-9b5d-b2efcac4cee6', 'Chave PIX RecargaPay')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    
    return $stmt->execute();
}


?>