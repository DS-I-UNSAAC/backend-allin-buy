<?php
/**
 * Configuración de base de datos mejorada para AllinBuy
 * Archivo: php/config/database.php
 */

// Configuración de la base de datos
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'allin_buy');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? 'ciro');
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
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);
        } catch (PDOException $e) {
            error_log("Error de conexión a la base de datos: " . $e->getMessage());
            throw new Exception("Error de conexión a la base de datos");
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
    
    // Método mejorado para consultas SELECT
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en consulta SQL: " . $e->getMessage() . " | SQL: " . $sql);
            throw new Exception("Error en la consulta a la base de datos");
        }
    }
    
    // Método mejorado para INSERT, UPDATE, DELETE
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error en ejecución SQL: " . $e->getMessage() . " | SQL: " . $sql);
            throw new Exception("Error en la ejecución de la consulta");
        }
    }
    
    // Método para obtener el último ID insertado
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    // Método para iniciar transacción
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    // Método para confirmar transacción
    public function commit() {
        return $this->pdo->commit();
    }
    
    // Método para revertir transacción
    public function rollback() {
        return $this->pdo->rollback();
    }
    
    // Método para verificar si estamos en una transacción
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
    
    // Método para obtener una sola fila
    public function queryOne($sql, $params = []) {
        $result = $this->query($sql, $params);
        return !empty($result) ? $result[0] : null;
    }
    
    // Método para contar registros
    public function count($table, $where = '', $params = []) {
        $sql = "SELECT COUNT(*) as total FROM $table";
        if ($where) {
            $sql .= " WHERE $where";
        }
        $result = $this->queryOne($sql, $params);
        return (int)$result['total'];
    }
}

// Función helper para obtener la conexión rápidamente
function getDB() {
    return Database::getInstance();
}

// Función para verificar si las tablas existen
function checkTables() {
    try {
        $db = getDB();
        
        $tables = [
            'usuarios', 'categorias', 'productos', 'emprendedores', 
            'carrito', 'pedidos', 'pedido_detalles', 'favoritos', 
            'resenas', 'producto_imagenes', 'direcciones'
        ];
        
        $existingTables = [];
        $missingTables = [];
        
        foreach ($tables as $table) {
            $result = $db->query("SHOW TABLES LIKE '$table'");
            if (!empty($result)) {
                $existingTables[] = $table;
            } else {
                $missingTables[] = $table;
            }
        }
        
        return [
            'existing' => $existingTables,
            'missing' => $missingTables,
            'all_exist' => empty($missingTables)
        ];
        
    } catch (Exception $e) {
        return [
            'error' => $e->getMessage(),
            'all_exist' => false
        ];
    }
}

// Función para insertar datos de prueba si no existen
function insertSampleData() {
    try {
        $db = getDB();
        
        // Verificar si ya hay datos
        $productCount = $db->count('productos');
        if ($productCount > 0) {
            return ['message' => 'Los datos de prueba ya existen'];
        }
        
        $db->beginTransaction();
        
        // Insertar categorías de prueba
        $categorias = [
            ['Artesanía', 'Piezas únicas elaboradas por artesanos locales', '🏺', '#8B4513', 1],
            ['Textiles', 'Tejidos tradicionales con técnicas ancestrales', '🧶', '#4F46E5', 1],
            ['Alimentos', 'Productos orgánicos y tradicionales de la región', '🌾', '#059669', 1],
            ['Turismo', 'Experiencias auténticas con emprendedores locales', '🏔️', '#DC2626', 1]
        ];
        
        foreach ($categorias as $cat) {
            $db->execute(
                "INSERT INTO categorias (nombre, descripcion, icono, color_hex, es_destacada) VALUES (?, ?, ?, ?, ?)",
                $cat
            );
        }
        
        // Insertar emprendedores de prueba
        $emprendedores = [
            ['Textiles Andinos', 'Especialistas en textiles tradicionales cusqueños', 'Ana', 'Quispe', 'ana@textilesandinos.com', '984123456'],
            ['ArteCusco', 'Cerámicas y artesanías inspiradas en la cultura inca', 'Carlos', 'Mamani', 'carlos@artecusco.com', '984234567'],
            ['Café Qosqo', 'Café orgánico de altura directo del productor', 'María', 'Flores', 'maria@cafeqosqo.com', '984345678'],
            ['Andean Tours', 'Tours auténticos y experiencias locales', 'Jorge', 'Condori', 'jorge@andeantours.com', '984456789']
        ];
        
        foreach ($emprendedores as $emp) {
            $db->execute(
                "INSERT INTO emprendedores (nombre_negocio, descripcion, propietario_nombre, propietario_apellido, email, telefono, categoria_principal, estado, verificado) VALUES (?, ?, ?, ?, ?, ?, 1, 'activo', 1)",
                $emp
            );
        }
        
        // Insertar productos de prueba
        $productos = [
            ['Chal de alpaca trenzado', 'Chal artesanal elaborado con lana de alpaca pura, tejido a mano por artesanas cusqueñas.', 89.99, null, 15, 2, 1, '/assets/macbook_image.png', 1, 'textiles, alpaca, tradicional, cusco'],
            ['Cerámica artesanal Inca', 'Pieza de cerámica artesanal inspirada en diseños incas tradicionales.', 120.00, null, 8, 1, 2, '/assets/cannon_camera_image.png', 1, 'cerámica, artesanía, inca, decoración'],
            ['Café orgánico de altura', 'Café de especialidad cultivado a más de 1800 msnm en parcelas orgánicas.', 35.50, null, 20, 3, 3, '/assets/venu_watch_image.png', 1, 'café, orgánico, altura, cusco'],
            ['Tour Valle Sagrado', 'Tour completo por el Valle Sagrado con guía especializado.', 150.00, null, 10, 4, 4, '/assets/jbl_soundbox_image.png', 1, 'turismo, valle sagrado, experiencia, cultura']
        ];
        
        foreach ($productos as $prod) {
            $db->execute(
                "INSERT INTO productos (nombre, descripcion, precio, precio_oferta, stock, categoria_id, emprendedor_id, imagen_principal, es_destacado, palabras_clave) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                $prod
            );
        }
        
        // Actualizar calificaciones y contadores
        $db->execute("UPDATE productos SET calificacion_promedio = 4.5 + (RAND() * 0.5), total_resenas = FLOOR(5 + (RAND() * 45))");
        $db->execute("UPDATE categorias SET total_productos = (SELECT COUNT(*) FROM productos WHERE categoria_id = categorias.id)");
        $db->execute("UPDATE emprendedores SET total_ventas = FLOOR(RAND() * 50)");
        
        $db->commit();
        
        return ['message' => 'Datos de prueba insertados correctamente'];
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        return ['error' => 'Error insertando datos de prueba: ' . $e->getMessage()];
    }
}

// Función para testear la conexión y mostrar información
function testConnection() {
    try {
        $db = getDB();
        
        // Verificar tablas
        $tableCheck = checkTables();
        
        echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Test Conexión AllinBuy</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; padding: 2rem; background: #f9fafb; }
    .container { max-width: 800px; margin: 0 auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .success { color: #065f46; background: #d1fae5; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
    .error { color: #991b1b; background: #fee2e2; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
    .warning { color: #92400e; background: #fef3c7; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
    table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
    th, td { padding: 0.75rem; border: 1px solid #d1d5db; text-align: left; }
    th { background: #f3f4f6; font-weight: 600; }
    tr:nth-child(even) { background: #f9fafb; }
    .btn { display: inline-block; padding: 0.5rem 1rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 4px; margin: 0.5rem 0; }
    .btn:hover { background: #2563eb; }
    .status-ok { color: #065f46; font-weight: 600; }
    .status-error { color: #991b1b; font-weight: 600; }
  </style>
</head>
<body>
  <div class="container">
    <h1>🚀 Test Conexión AllinBuy</h1>
HTML;

        if (isset($tableCheck['error'])) {
            echo '<div class="error">❌ ERROR: ' . htmlspecialchars($tableCheck['error']) . '</div>';
        } else {
            echo '<div class="success">✅ Conexión exitosa a la base de datos: <strong>' . DB_NAME . '</strong></div>';
            
            // Mostrar estado de las tablas
            echo '<h2>📊 Estado de las Tablas</h2>';
            
            if ($tableCheck['all_exist']) {
                echo '<div class="success">✅ Todas las tablas necesarias están presentes</div>';
            } else {
                echo '<div class="warning">⚠️ Algunas tablas faltan. Ejecuta el script create_database.sql</div>';
            }
            
            echo '<table>';
            echo '<thead><tr><th>Tabla</th><th>Estado</th><th>Registros</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($tableCheck['existing'] as $table) {
                $count = $db->count($table);
                echo "<tr><td>{$table}</td><td class='status-ok'>✅ Existe</td><td>{$count}</td></tr>";
            }
            
            foreach ($tableCheck['missing'] as $table) {
                echo "<tr><td>{$table}</td><td class='status-error'>❌ Falta</td><td>-</td></tr>";
            }
            
            echo '</tbody></table>';
            
            // Mostrar datos de productos si existen
            $productos = $db->query("SELECT p.id, p.nombre, p.precio, c.nombre as categoria FROM productos p LEFT JOIN categorias c ON p.categoria_id = c.id LIMIT 5");
            
            if (count($productos) > 0) {
                echo '<h2>🛍️ Productos de Muestra</h2>';
                echo '<table>';
                echo '<thead><tr><th>ID</th><th>Nombre</th><th>Precio</th><th>Categoría</th></tr></thead>';
                echo '<tbody>';
                foreach ($productos as $producto) {
                    $id = htmlspecialchars($producto['id']);
                    $nombre = htmlspecialchars($producto['nombre']);
                    $precio = htmlspecialchars($producto['precio']);
                    $categoria = htmlspecialchars($producto['categoria'] ?? 'Sin categoría');
                    echo "<tr><td>{$id}</td><td>{$nombre}</td><td>S/ {$precio}</td><td>{$categoria}</td></tr>";
                }
                echo '</tbody></table>';
            } else {
                echo '<div class="warning">⚠️ No hay productos en la base de datos. ¿Quieres insertar datos de prueba?</div>';
                echo '<a href="?action=insert_sample" class="btn">Insertar Datos de Prueba</a>';
            }
            
            // Mostrar información de configuración
            echo '<h2>⚙️ Configuración</h2>';
            echo '<table>';
            echo '<tr><th>Parámetro</th><th>Valor</th></tr>';
            echo '<tr><td>Host</td><td>' . DB_HOST . '</td></tr>';
            echo '<tr><td>Base de datos</td><td>' . DB_NAME . '</td></tr>';
            echo '<tr><td>Usuario</td><td>' . DB_USER . '</td></tr>';
            echo '<tr><td>Charset</td><td>' . DB_CHARSET . '</td></tr>';
            echo '</table>';
            
            // Información para el frontend
            echo '<h2>🌐 Configuración Frontend</h2>';
            echo '<div class="warning">';
            echo '<p><strong>Para conectar tu frontend Next.js:</strong></p>';
            echo '<ol>';
            echo '<li>Crea un archivo <code>.env.local</code> en la raíz de tu proyecto Next.js</li>';
            echo '<li>Agrega: <code>NEXT_PUBLIC_API_URL=http://localhost/php/api</code></li>';
            echo '<li>Reinicia tu servidor de desarrollo de Next.js</li>';
            echo '</ol>';
            echo '</div>';
        }
        
        echo '</div></body></html>';
        
    } catch (Exception $e) {
        echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Error Conexión</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 2rem; background: #fee2e2; }
    .error { color: #991b1b; background: white; padding: 2rem; border-radius: 8px; max-width: 600px; margin: 0 auto; }
  </style>
</head>
<body>
  <div class="error">
    <h1>❌ Error de Conexión</h1>
    <p><strong>Error:</strong> {$e->getMessage()}</p>
    <p><strong>Verifica:</strong></p>
    <ul>
      <li>Que MySQL esté ejecutándose</li>
      <li>Que la base de datos 'allin_buy' exista</li>
      <li>Que las credenciales en database.php sean correctas</li>
      <li>Que el usuario tenga permisos en la base de datos</li>
    </ul>
  </div>
</body>
</html>
HTML;
    }
}

// Manejar acciones desde la URL
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'insert_sample') {
        $result = insertSampleData();
        if (isset($result['error'])) {
            echo '<div class="error">Error: ' . htmlspecialchars($result['error']) . '</div>';
        } else {
            echo '<div class="success">' . htmlspecialchars($result['message']) . '</div>';
        }
        echo '<a href="?" class="btn">← Volver</a>';
        exit;
    }
}

// Si este archivo se ejecuta directamente, hacer test
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    testConnection();
}
?>
