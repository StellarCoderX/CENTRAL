 <?php
// ARQUIVO: db_connection.php
// --- CONFIGURE SEUS DADOS AQUI ---
date_default_timezone_set('America/Sao_Paulo');
$db_host = 'sql306.infinityfree.com'; // Host do banco de dados da InfinityFree
$db_name = 'if0_39842623_terminus_db'; // Nome do seu banco de dados na InfinityFree
$db_user = 'if0_39842623';             // Seu usuário do banco de dados na InfinityFree
$db_pass = 'IyekXlfMvuVx7';            // Sua senha do banco de dados na InfinityFree
$charset = 'utf8mb4';
// ------------------------------------

/**
 * Função para conectar ao banco de dados usando PDO.
 * @return PDO Retorna o objeto de conexão PDO.
 */
function connect_db() {
    global $db_host, $db_name, $db_user, $db_pass, $charset;

    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $db_user, $db_pass, $options);
        return $pdo;
    } catch (\PDOException $e) {
        // Em um ambiente de produção, você deve registrar este erro em um log, não exibi-lo.
        error_log("Erro de conexão com o banco de dados: " . $e->getMessage());
        die("Erro crítico: Não foi possível conectar ao banco de dados. Verifique os logs do servidor.");
    }
}

// Incluir outras funções essenciais que podem estar faltando
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        return htmlspecialchars(stripslashes(trim($data)));
    }
