<?php
/**
 * API para manejar usuarios
 * Archivo: php/api/usuarios.php
 */

// Incluir configuración de base de datos
require_once '../config/database.php';
require_once '../config/cors.php';

// Configurar headers para API REST
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Obtener método HTTP y parámetros
$method = $_SERVER['REQUEST_METHOD'];
$request = $_SERVER['REQUEST_URI'];
$path = parse_url($request, PHP_URL_PATH);
$path = str_replace('/php/api/usuarios.php', '', $path);

// Obtener parámetros de la URL
$segments = explode('/', trim($path, '/'));
$action = isset($segments[0]) && !empty($segments[0]) ? $segments[0] : null;
$id = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;

try {
    $db = getDB();
    
    // Rutas especiales
    if ($method === 'POST' && $action === 'registro') {
        handleRegistro($db);
    } elseif ($method === 'POST' && $action === 'login') {
        handleLogin($db);
    } elseif ($method === 'GET' && $action === 'perfil' && $id) {
        handlePerfil($db, $id);
    } else {
        // CRUD normal
        switch ($method) {
            case 'GET':
                handleGet($db, $id);
                break;
                
            case 'POST':
                handlePost($db);
                break;
                
            case 'PUT':
                handlePut($db, $id);
                break;
                
            case 'DELETE':
                handleDelete($db, $id);
                break;
                
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Método no permitido']);
                break;
        }
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor', 'message' => $e->getMessage()]);
}

/**
 * POST /registro - Registrar nuevo usuario
 */
function handleRegistro($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    $required = ['nombre', 'apellido', 'email', 'password'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
            http_response_code(400);
            echo json_encode(['error' => "Campo '$field' es requerido"]);
            return;
        }
    }
    
    $email = trim($input['email']);
    $password = trim($input['password']);
    
    // Validar email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email no válido']);
        return;
    }
    
    // Validar longitud de contraseña
    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'La contraseña debe tener al menos 6 caracteres']);
        return;
    }
    
    // Verificar si el email ya existe
    $existe = $db->query("SELECT id FROM usuarios WHERE email = ?", [$email]);
    if (!empty($existe)) {
        http_response_code(409);
        echo json_encode(['error' => 'El email ya está registrado']);
        return;
    }
    
    // Encriptar contraseña
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insertar usuario
    $sql = "INSERT INTO usuarios (nombre, apellido, email, password, telefono, rol) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $params = [
        trim($input['nombre']),
        trim($input['apellido']),
        $email,
        $passwordHash,
        trim($input['telefono'] ?? ''),
        'cliente'
    ];
    
    if ($db->execute($sql, $params)) {
        $usuarioId = $db->lastInsertId();
        
        // Obtener usuario creado (sin password)
        $usuario = $db->query(
            "SELECT id, nombre, apellido, email, telefono, fecha_registro, estado, rol 
             FROM usuarios WHERE id = ?", 
            [$usuarioId]
        );
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Usuario registrado exitosamente',
            'data' => $usuario[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al registrar usuario']);
    }
}

/**
 * POST /login - Iniciar sesión
 */
function handleLogin($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (!isset($input['email']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Email y contraseña son requeridos']);
        return;
    }
    
    $email = trim($input['email']);
    $password = trim($input['password']);
    
    // Buscar usuario
    $usuario = $db->query(
        "SELECT id, nombre, apellido, email, password, telefono, estado, rol 
         FROM usuarios WHERE email = ?", 
        [$email]
    );
    
    if (empty($usuario)) {
        http_response_code(401);
        echo json_encode(['error' => 'Credenciales inválidas']);
        return;
    }
    
    $usuario = $usuario[0];
    
    // Verificar estado del usuario
    if ($usuario['estado'] !== 'activo') {
        http_response_code(401);
        echo json_encode(['error' => 'Usuario inactivo o bloqueado']);
        return;
    }
    
    // Verificar contraseña
    if (!password_verify($password, $usuario['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Credenciales inválidas']);
        return;
    }
    
    // Remover password de la respuesta
    unset($usuario['password']);
    
    // Generar token simple (en producción usar JWT)
    $token = base64_encode($usuario['id'] . ':' . time() . ':' . uniqid());
    
    echo json_encode([
        'success' => true,
        'message' => 'Login exitoso',
        'data' => [
            'usuario' => $usuario,
            'token' => $token
        ]
    ]);
}

/**
 * GET /perfil/{id} - Obtener perfil de usuario
 */
function handlePerfil($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de usuario requerido']);
        return;
    }
    
    // Obtener datos del usuario
    $usuario = $db->query(
        "SELECT id, nombre, apellido, email, telefono, fecha_registro, estado, rol 
         FROM usuarios WHERE id = ?", 
        [$id]
    );
    
    if (empty($usuario)) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
        return;
    }
    
    // Obtener direcciones del usuario
    $direcciones = $db->query(
        "SELECT * FROM direcciones WHERE usuario_id = ? ORDER BY es_principal DESC",
        [$id]
    );
    
    // Obtener estadísticas básicas
    $stats = $db->query(
        "SELECT 
            COUNT(DISTINCT p.id) as total_pedidos,
            COALESCE(SUM(p.total), 0) as total_gastado,
            COUNT(DISTINCT f.id) as productos_favoritos
         FROM usuarios u
         LEFT JOIN pedidos p ON u.id = p.usuario_id
         LEFT JOIN favoritos f ON u.id = f.usuario_id
         WHERE u.id = ?",
        [$id]
    );
    
    $usuario[0]['direcciones'] = $direcciones;
    $usuario[0]['estadisticas'] = $stats[0];
    
    echo json_encode([
        'success' => true,
        'data' => $usuario[0]
    ]);
}

/**
 * GET - Obtener usuarios (solo admin)
 */
function handleGet($db, $id) {
    if ($id) {
        // Obtener usuario específico
        $usuario = $db->query(
            "SELECT id, nombre, apellido, email, telefono, fecha_registro, estado, rol 
             FROM usuarios WHERE id = ?", 
            [$id]
        );
        
        if (empty($usuario)) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuario no encontrado']);
            return;
        }
        
        echo json_encode(['success' => true, 'data' => $usuario[0]]);
        
    } else {
        // Obtener todos los usuarios con paginación
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
        $rol = isset($_GET['rol']) ? $_GET['rol'] : null;
        
        $offset = ($page - 1) * $limit;
        
        // Construir consulta
        $where = "WHERE 1=1";
        $params = [];
        
        if ($rol) {
            $where .= " AND rol = ?";
            $params[] = $rol;
        }
        
        $sql = "SELECT id, nombre, apellido, email, telefono, fecha_registro, estado, rol 
                FROM usuarios 
                $where 
                ORDER BY fecha_registro DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $usuarios = $db->query($sql, $params);
        
        // Contar total
        $sqlCount = "SELECT COUNT(*) as total FROM usuarios $where";
        $totalResult = $db->query($sqlCount, array_slice($params, 0, -2));
        $total = $totalResult[0]['total'];
        
        echo json_encode([
            'success' => true,
            'data' => $usuarios,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }
}

/**
 * POST - Crear usuario (admin)
 */
function handlePost($db) {
    // Usar la función de registro
    handleRegistro($db);
}

/**
 * PUT - Actualizar usuario
 */
function handlePut($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de usuario requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Verificar que el usuario existe
    $existe = $db->query("SELECT id FROM usuarios WHERE id = ?", [$id]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
        return;
    }
    
    // Construir consulta de actualización
    $campos = [];
    $params = [];
    
    $camposPermitidos = ['nombre', 'apellido', 'telefono', 'estado'];
    
    foreach ($camposPermitidos as $campo) {
        if (isset($input[$campo]) && trim($input[$campo]) !== '') {
            $campos[] = "$campo = ?";
            $params[] = trim($input[$campo]);
        }
    }
    
    // Manejar cambio de contraseña
    if (isset($input['password']) && !empty(trim($input['password']))) {
        if (strlen(trim($input['password'])) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'La contraseña debe tener al menos 6 caracteres']);
            return;
        }
        $campos[] = "password = ?";
        $params[] = password_hash(trim($input['password']), PASSWORD_DEFAULT);
    }
    
    if (empty($campos)) {
        http_response_code(400);
        echo json_encode(['error' => 'No hay campos para actualizar']);
        return;
    }
    
    $params[] = $id;
    $sql = "UPDATE usuarios SET " . implode(', ', $campos) . " WHERE id = ?";
    
    if ($db->execute($sql, $params)) {
        // Obtener usuario actualizado
        $usuario = $db->query(
            "SELECT id, nombre, apellido, email, telefono, fecha_registro, estado, rol 
             FROM usuarios WHERE id = ?", 
            [$id]
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Usuario actualizado exitosamente',
            'data' => $usuario[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar usuario']);
    }
}

/**
 * DELETE - Eliminar usuario
 */
function handleDelete($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de usuario requerido']);
        return;
    }
    
    // Verificar que el usuario existe
    $existe = $db->query("SELECT id FROM usuarios WHERE id = ?", [$id]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado']);
        return;
    }
    
    // Soft delete - cambiar estado en lugar de eliminar
    if ($db->execute("UPDATE usuarios SET estado = 'inactivo' WHERE id = ?", [$id])) {
        echo json_encode([
            'success' => true,
            'message' => 'Usuario desactivado exitosamente'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al desactivar usuario']);
    }
}
?>