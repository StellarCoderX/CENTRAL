 <?php
/**
 * SCRIPT DE LIMPEZA AUTOMÁTICA - PIX EXPIRADOS (VERSÃO CORRIGIDA E FUNCIONAL)
 * Execute via cron job para limpar pagamentos PIX expirados
 */
require_once 'db_connection.php';
require_once 'credits_system.php';
require_once 'recargapay_pix_integration.php';

// Configurar para exibir erros (útil para debug do cron job)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log de execução
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    // Mostra a mensagem na tela quando executado manualmente
    echo "[$timestamp] $message" . PHP_EOL . "<br>";
    // Também salva em um log de erro do servidor
    error_log("[$timestamp] [PIX_CLEANUP] $message");
}

try {
    logMessage("Iniciando limpeza de pagamentos PIX expirados...");
    
    // Conectar ao banco e inicializar sistemas
    $pdo = connect_db();
    $creditSystem = initCreditSystem($pdo);
    // Esta função initRecargaPaySystem já existe e está correta
    $recargaPaySystem = initRecargaPaySystem($pdo, $creditSystem);
    
    // Chamar o método de limpeza corrigido que adicionamos na classe
    $expiredCount = $recargaPaySystem->cleanExpiredPayments();
    
    logMessage("Limpeza concluída. $expiredCount pagamentos expirados foram processados.");
    
    logMessage("Script de limpeza finalizado com sucesso.");
    
} catch (Exception $e) {
    $errorMsg = "ERRO CRÍTICO na limpeza PIX: " . $e->getMessage();
    logMessage($errorMsg);
    http_response_code(500); // Retorna um código de erro para o cron job
    exit(1);
}
?