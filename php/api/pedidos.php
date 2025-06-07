<?php
/**
 * API para manejar pedidos
 * Archivo: php/api/pedidos.php
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
$path = str_replace('/php/api/pedidos.php', '', $path);

// Obtener parámetros de la URL
$segments = explode('/', trim($path, '/'));
$action = isset($segments[0]) && !empty($segments[0]) ? $segments[0] : null;
$id = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;

try {
    $db = getDB();
    
    switch ($method) {
        case 'GET':
            if ($action === 'usuario' && $id) {
                handleGetByUser($db, $id);
            } elseif ($action === 'numero' && isset($segments[1])) {
                handleGetByNumber($db, $segments[1]);
            } elseif (is_numeric($action)) {
                handleGetById($db, (int)$action);
            } else {
                handleGet($db);
            }
            break;
            
        case 'POST':
            if ($action === 'crear') {
                handleCrearPedido($db);
            } else {
                handlePost($db);
            }
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
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor', 'message' => $e->getMessage()]);
}

/**
 * GET - Obtener todos los pedidos (con filtros)
 */
function handleGet($db) {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    $estado = isset($_GET['estado']) ? $_GET['estado'] : null;
    $usuario_id = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : null;
    $fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : null;
    $fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : null;
    
    $offset = ($page - 1) * $limit;
    
    // Construir consulta
    $where = "WHERE 1=1";
    $params = [];
    
    if ($estado) {
        $where .= " AND p.estado = ?";
        $params[] = $estado;
    }
    
    if ($usuario_id) {
        $where .= " AND p.usuario_id = ?";
        $params[] = $usuario_id;
    }
    
    if ($fecha_desde) {
        $where .= " AND DATE(p.fecha_pedido) >= ?";
        $params[] = $fecha_desde;
    }
    
    if ($fecha_hasta) {
        $where .= " AND DATE(p.fecha_pedido) <= ?";
        $params[] = $fecha_hasta;
    }
    
    $sql = "SELECT 
                p.*,
                u.nombre as usuario_nombre,
                u.apellido as usuario_apellido,
                u.email as usuario_email,
                COUNT(pd.id) as total_productos
            FROM pedidos p
            INNER JOIN usuarios u ON p.usuario_id = u.id
            LEFT JOIN pedido_detalles pd ON p.id = pd.pedido_id
            $where
            GROUP BY p.id
            ORDER BY p.fecha_pedido DESC
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $pedidos = $db->query($sql, $params);
    
    // Contar total
    $sqlCount = "SELECT COUNT(DISTINCT p.id) as total FROM pedidos p INNER JOIN usuarios u ON p.usuario_id = u.id $where";
    $totalResult = $db->query($sqlCount, array_slice($params, 0, -2));
    $total = $totalResult[0]['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $pedidos,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * GET /usuario/{id} - Obtener pedidos de un usuario específico
 */
function handleGetByUser($db, $userId) {
    $sql = "SELECT 
                p.*,
                COUNT(pd.id) as total_productos,
                GROUP_CONCAT(pr.nombre SEPARATOR ', ') as productos_nombres
            FROM pedidos p
            LEFT JOIN pedido_detalles pd ON p.id = pd.pedido_id
            LEFT JOIN productos pr ON pd.producto_id = pr.id
            WHERE p.usuario_id = ?
            GROUP BY p.id
            ORDER BY p.fecha_pedido DESC";
    
    $pedidos = $db->query($sql, [$userId]);
    
    echo json_encode([
        'success' => true,
        'data' => $pedidos
    ]);
}

/**
 * GET /numero/{numero_pedido} - Obtener pedido por número
 */
function handleGetByNumber($db, $numeroPedido) {
    $pedido = $db->query(
        "SELECT 
            p.*,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido,
            u.email as usuario_email,
            u.telefono as usuario_telefono
         FROM pedidos p
         INNER JOIN usuarios u ON p.usuario_id = u.id
         WHERE p.numero_pedido = ?",
        [$numeroPedido]
    );
    
    if (empty($pedido)) {
        http_response_code(404);
        echo json_encode(['error' => 'Pedido no encontrado']);
        return;
    }
    
    // Obtener detalles del pedido
    $detalles = $db->query(
        "SELECT 
            pd.*,
            p.nombre as producto_nombre,
            p.descripcion as producto_descripcion,
            p.imagen_principal as producto_imagen
         FROM pedido_detalles pd
         INNER JOIN productos p ON pd.producto_id = p.id
         WHERE pd.pedido_id = ?",
        [$pedido[0]['id']]
    );
    
    $pedido[0]['detalles'] = $detalles;
    
    echo json_encode([
        'success' => true,
        'data' => $pedido[0]
    ]);
}

/**
 * GET /{id} - Obtener pedido específico por ID
 */
function handleGetById($db, $id) {
    $pedido = $db->query(
        "SELECT 
            p.*,
            u.nombre as usuario_nombre,
            u.apellido as usuario_apellido,
            u.email as usuario_email,
            u.telefono as usuario_telefono
         FROM pedidos p
         INNER JOIN usuarios u ON p.usuario_id = u.id
         WHERE p.id = ?",
        [$id]
    );
    
    if (empty($pedido)) {
        http_response_code(404);
        echo json_encode(['error' => 'Pedido no encontrado']);
        return;
    }
    
    // Obtener detalles del pedido
    $detalles = $db->query(
        "SELECT 
            pd.*,
            p.nombre as producto_nombre,
            p.descripcion as producto_descripcion,
            p.imagen_principal as producto_imagen
         FROM pedido_detalles pd
         INNER JOIN productos p ON pd.producto_id = p.id
         WHERE pd.pedido_id = ?",
        [$id]
    );
    
    $pedido[0]['detalles'] = $detalles;
    
    echo json_encode([
        'success' => true,
        'data' => $pedido[0]
    ]);
}

/**
 * POST /crear - Crear pedido desde carrito
 */
function handleCrearPedido($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    $required = ['usuario_id', 'metodo_pago', 'direccion_envio'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
            http_response_code(400);
            echo json_encode(['error' => "Campo '$field' es requerido"]);
            return;
        }
    }
    
    $usuarioId = (int)$input['usuario_id'];
    $metodoPago = trim($input['metodo_pago']);
    $direccionEnvio = trim($input['direccion_envio']);
    $notas = trim($input['notas'] ?? '');
    
    // Validar método de pago
    $metodosValidos = ['tarjeta', 'transferencia', 'efectivo', 'yape', 'plin'];
    if (!in_array($metodoPago, $metodosValidos)) {
        http_response_code(400);
        echo json_encode(['error' => 'Método de pago no válido']);
        return;
    }
    
    // Verificar que el usuario existe
    $usuario = $db->query("SELECT id FROM usuarios WHERE id = ? AND estado = 'activo'", [$usuarioId]);
    if (empty($usuario)) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado o inactivo']);
        return;
    }
    
    // Obtener items del carrito
    $itemsCarrito = $db->query(
        "SELECT 
            c.producto_id,
            c.cantidad,
            p.nombre,
            p.precio,
            p.precio_oferta,
            p.stock,
            p.estado,
            COALESCE(p.precio_oferta, p.precio) as precio_final
         FROM carrito c
         INNER JOIN productos p ON c.producto_id = p.id
         WHERE c.usuario_id = ?",
        [$usuarioId]
    );
    
    if (empty($itemsCarrito)) {
        http_response_code(400);
        echo json_encode(['error' => 'El carrito está vacío']);
        return;
    }
    
    // Verificar stock y calcular total
    $total = 0;
    $itemsProblemas = [];
    
    foreach ($itemsCarrito as $item) {
        if ($item['estado'] !== 'activo') {
            $itemsProblemas[] = "El producto '{$item['nombre']}' no está disponible";
            continue;
        }
        
        if ($item['stock'] < $item['cantidad']) {
            $itemsProblemas[] = "Stock insuficiente para '{$item['nombre']}'. Disponible: {$item['stock']}, solicitado: {$item['cantidad']}";
            continue;
        }
        
        $total += $item['precio_final'] * $item['cantidad'];
    }
    
    if (!empty($itemsProblemas)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Hay problemas con algunos productos',
            'problemas' => $itemsProblemas
        ]);
        return;
    }
    
    // Iniciar transacción
    $db->getConnection()->beginTransaction();
    
    try {
        // Generar número de pedido único
        $numeroPedido = 'PED-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Verificar que el número no existe
        $numeroExiste = $db->query("SELECT id FROM pedidos WHERE numero_pedido = ?", [$numeroPedido]);
        while (!empty($numeroExiste)) {
            $numeroPedido = 'PED-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $numeroExiste = $db->query("SELECT id FROM pedidos WHERE numero_pedido = ?", [$numeroPedido]);
        }
        
        // Crear pedido
        $sqlPedido = "INSERT INTO pedidos (usuario_id, numero_pedido, total, metodo_pago, direccion_envio, notas) 
                      VALUES (?, ?, ?, ?, ?, ?)";
        
        $db->execute($sqlPedido, [$usuarioId, $numeroPedido, $total, $metodoPago, $direccionEnvio, $notas]);
        $pedidoId = $db->lastInsertId();
        
        // Crear detalles del pedido y actualizar stock
        foreach ($itemsCarrito as $item) {
            // Insertar detalle
            $sqlDetalle = "INSERT INTO pedido_detalles (pedido_id, producto_id, cantidad, precio_unitario, subtotal) 
                           VALUES (?, ?, ?, ?, ?)";
            
            $subtotal = $item['precio_final'] * $item['cantidad'];
            $db->execute($sqlDetalle, [
                $pedidoId,
                $item['producto_id'],
                $item['cantidad'],
                $item['precio_final'],
                $subtotal
            ]);
            
            // Actualizar stock
            $db->execute(
                "UPDATE productos SET stock = stock - ? WHERE id = ?",
                [$item['cantidad'], $item['producto_id']]
            );
        }
        
        // Limpiar carrito
        $db->execute("DELETE FROM carrito WHERE usuario_id = ?", [$usuarioId]);
        
        // Confirmar transacción
        $db->getConnection()->commit();
        
        // Obtener pedido creado
        $pedidoCreado = $db->query(
            "SELECT * FROM pedidos WHERE id = ?",
            [$pedidoId]
        );
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Pedido creado exitosamente',
            'data' => $pedidoCreado[0]
        ]);
        
    } catch (Exception $e) {
        // Revertir transacción
        $db->getConnection()->rollback();
        throw $e;
    }
}

/**
 * POST - Crear pedido manual (admin)
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    $required = ['usuario_id', 'total', 'metodo_pago', 'direccion_envio'];
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Campo '$field' es requerido"]);
            return;
        }
    }
    
    // Generar número de pedido
    $numeroPedido = 'PED-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    $sql = "INSERT INTO pedidos (usuario_id, numero_pedido, total, estado, metodo_pago, direccion_envio, notas) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $params = [
        $input['usuario_id'],
        $numeroPedido,
        $input['total'],
        $input['estado'] ?? 'pendiente',
        $input['metodo_pago'],
        $input['direccion_envio'],
        $input['notas'] ?? ''
    ];
    
    if ($db->execute($sql, $params)) {
        $pedidoId = $db->lastInsertId();
        $pedido = $db->query("SELECT * FROM pedidos WHERE id = ?", [$pedidoId]);
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Pedido creado exitosamente',
            'data' => $pedido[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al crear pedido']);
    }
}

/**
 * PUT /{id} - Actualizar estado del pedido
 */
function handlePut($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de pedido requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Verificar que el pedido existe
    $existe = $db->query("SELECT id, estado FROM pedidos WHERE id = ?", [$id]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Pedido no encontrado']);
        return;
    }
    
    // Construir consulta de actualización
    $campos = [];
    $params = [];
    
    $camposPermitidos = ['estado', 'fecha_entrega', 'notas'];
    
    foreach ($camposPermitidos as $campo) {
        if (isset($input[$campo])) {
            $campos[] = "$campo = ?";
            $params[] = $input[$campo];
        }
    }
    
    if (empty($campos)) {
        http_response_code(400);
        echo json_encode(['error' => 'No hay campos para actualizar']);
        return;
    }
    
    $params[] = $id;
    $sql = "UPDATE pedidos SET " . implode(', ', $campos) . " WHERE id = ?";
    
    if ($db->execute($sql, $params)) {
        $pedido = $db->query("SELECT * FROM pedidos WHERE id = ?", [$id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Pedido actualizado exitosamente',
            'data' => $pedido[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar pedido']);
    }
}

/**
 * DELETE /{id} - Cancelar pedido
 */
function handleDelete($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de pedido requerido']);
        return;
    }
    
    // Verificar que el pedido existe y se puede cancelar
    $pedido = $db->query("SELECT id, estado FROM pedidos WHERE id = ?", [$id]);
    if (empty($pedido)) {
        http_response_code(404);
        echo json_encode(['error' => 'Pedido no encontrado']);
        return;
    }
    
    $estadoActual = $pedido[0]['estado'];
    if (in_array($estadoActual, ['entregado', 'cancelado'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No se puede cancelar un pedido ' . $estadoActual]);
        return;
    }
    
    // Cancelar pedido (soft delete)
    if ($db->execute("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?", [$id])) {
        echo json_encode([
            'success' => true,
            'message' => 'Pedido cancelado exitosamente'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al cancelar pedido']);
    }
}
?>