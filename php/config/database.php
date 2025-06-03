<?php
/**
 * Configuración de base de datos para AllinBuy
 * Basado en tu archivo anterior pero actualizado para el nuevo proyecto
 */

// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'allin_buy');
define('DB_USER', 'root');
define('DB_PASS', 'ciro'); // Usa tu misma contraseña
define('DB_CHARSET', 'utf8mb4');

class Database {
    private $pdo;
    private static $instance = null;
    
    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new Exception("Error de conexión: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    // Método para consultas SELECT
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            throw new Exception("Error en consulta: " . $e->getMessage());
        }
    }
    
    // Método para INSERT, UPDATE, DELETE
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            throw new Exception("Error en ejecución: " . $e->getMessage());
        }
    }
    
    // Método para obtener el último ID insertado
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}

// Función helper para obtener la conexión rápidamente
function getDB() {
    return Database::getInstance();
}

// Función para testear la conexión (similar a tu archivo anterior)
function testConnection() {
    try {
        $db = getDB();
        $productos = $db->query("SELECT id, nombre, precio FROM productos LIMIT 5");
        
        echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Test Conexión AllinBuy</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 1rem; }
    table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
    th, td { padding: 0.5rem; border: 1px solid #ccc; text-align: left; }
    th { background: #f0f0f0; }
    tr:nth-child(even) { background: #fafafa; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
  </style>
</head>
<body>
  <h1>Test Conexión AllinBuy Database</h1>
  <p class="success">✅ Conexión exitosa a la base de datos allin_buy</p>
HTML;

        if (count($productos) === 0) {
            echo "<p>No hay productos en la base de datos aún.</p>";
        } else {
            echo '<table>';
            echo '<caption>Productos de prueba (' . count($productos) . ')</caption>';
            echo '<thead><tr><th>ID</th><th>Nombre</th><th>Precio</th></tr></thead>';
            echo '<tbody>';
            foreach ($productos as $producto) {
                $id = htmlspecialchars($producto['id'], ENT_QUOTES);
                $nombre = htmlspecialchars($producto['nombre'], ENT_QUOTES);
                $precio = htmlspecialchars($producto['precio'], ENT_QUOTES);
                echo "<tr><td>{$id}</td><td>{$nombre}</td><td>S/ {$precio}</td></tr>";
            }
            echo '</tbody></table>';
        }
        
        echo "\n</body>\n</html>";
        
    } catch (Exception $e) {
        echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Error Conexión</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 1rem; }
    .error { color: red; font-weight: bold; }
  </style>
</head>
<body>
  <h1>Error de Conexión</h1>
  <p class="error">❌ ERROR: {$e->getMessage()}</p>
</body>
</html>
HTML;
    }
}

// Si este archivo se ejecuta directamente, hacer test
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    testConnection();
}
?>