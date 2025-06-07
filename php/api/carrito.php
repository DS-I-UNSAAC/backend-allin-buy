<?php
/**
 * API para manejar carrito de compras
 * Archivo: php/api/carrito.php
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
$path = str_replace('/php/api/carrito.php', '', $path);

// Obtener parámetros de la URL
$segments = explode('/', trim($path, '/'));
$userId = isset($segments[0]) && is_numeric($segments[0]) ? (int)$segments[0] : null;
$action = isset($segments[1]) ? $segments[1] : null;

try {
    $db = getDB();
    
    // Todas las operaciones del carrito requieren usuario_id
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de usuario requerido en la URL: /carrito/{usuario_id}']);
        exit();
    }
    
    // Verificar que el usuario existe
    $usuarioExiste = $db->query("SELECT id FROM usuarios WHERE id = ? AND estado = 'activo'", [$userId]);
    if (empty($usuarioExiste)) {
        http_response_code(404);
        echo json_encode(['error' => 'Usuario no encontrado o inactivo']);
        exit();
    }
    
    switch ($method) {
        case 'GET':
            if ($action === 'total') {
                handleGetTotal($db, $userId);
            } else {
                handleGet($db, $userId);
            }
            break;
            
        case 'POST':
            handlePost($db, $userId);
            break;
            
        case 'PUT':
            handlePut($db, $userId, $action);
            break;
            
        case 'DELETE':
            if ($action === 'limpiar') {
                handleLimpiar($db, $userId);
            } else {
                handleDelete($db, $userId, $action);
            }
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
 * GET /{usuario_id} - Obtener carrito del usuario
 */
function handleGet($db, $userId) {
    $sql = "SELECT 
                c.id,
                c.producto_id,
                c.cantidad,
                c.fecha_agregado,
                p.nombre as producto_nombre,
                p.descripcion as producto_descripcion,
                p.precio as producto_precio,
                p.precio_oferta as producto_precio_oferta,
                p.imagen_principal as producto_imagen,
                p.stock as producto_stock,
                p.estado as producto_estado,
                cat.nombre as categoria_nombre,
                (COALESCE(p.precio_oferta, p.precio) * c.cantidad) as subtotal
            FROM carrito c
            INNER JOIN productos p ON c.producto_id = p.id
            LEFT JOIN categorias cat ON p.categoria_id = cat.id
            WHERE c.usuario_id = ?
            ORDER BY c.fecha_agregado DESC";
    
    $items = $db->query($sql, [$userId]);
    
    // Calcular totales
    $totalItems = 0;
    $totalPrecio = 0;
    $itemsDisponibles = 0;
    
    foreach ($items as &$item) {
        $totalItems += $item['cantidad'];
        $totalPrecio += $item['subtotal'];
        
        // Verificar disponibilidad
        $item['disponible'] = $item['producto_estado'] === 'activo' && $item['producto_stock'] >= $item['cantidad'];
        if ($item['disponible']) {
            $itemsDisponibles++;
        }
        
        // Formatear precios
        $item['producto_precio'] = (float)$item['producto_precio'];
        $item['producto_precio_oferta'] = $item['producto_precio_oferta'] ? (float)$item['producto_precio_oferta'] : null;
        $item['subtotal'] = (float)$item['subtotal'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $items,
            'resumen' => [
                'total_items' => $totalItems,
                'total_productos' => count($items),
                'total_precio' => round($totalPrecio, 2),
                'items_disponibles' => $itemsDisponibles,
                'hay_items_no_disponibles' => $itemsDisponibles < count($items)
            ]
        ]
    ]);
}

/**
 * GET /{usuario_id}/total - Obtener solo totales del carrito
 */
function handleGetTotal($db, $userId) {
    $sql = "SELECT 
                COUNT(*) as total_productos,
                SUM(c.cantidad) as total_items,
                SUM(COALESCE(p.precio_oferta, p.precio) * c.cantidad) as total_precio
            FROM carrito c
            INNER JOIN productos p ON c.producto_id = p.id
            WHERE c.usuario_id = ? AND p.estado = 'activo'";
    
    $total = $db->query($sql, [$userId]);
    $resultado = $total[0];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total_productos' => (int)$resultado['total_productos'],
            'total_items' => (int)($resultado['total_items'] ?? 0),
            'total_precio' => round((float)($resultado['total_precio'] ?? 0), 2)
        ]
    ]);
}

/**
 * POST /{usuario_id} - Agregar producto al carrito
 */
function handlePost($db, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    if (!isset($input['producto_id']) || !is_numeric($input['producto_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'producto_id es requerido y debe ser numérico']);
        return;
    }
    
    $productoId = (int)$input['producto_id'];
    $cantidad = isset($input['cantidad']) ? max(1, (int)$input['cantidad']) : 1;
    
    // Verificar que el producto existe y está activo
    $producto = $db->query(
        "SELECT id, nombre, stock, estado FROM productos WHERE id = ?", 
        [$productoId]
    );
    
    if (empty($producto)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
        return;
    }
    
    $producto = $producto[0];
    
    if ($producto['estado'] !== 'activo') {
        http_response_code(400);
        echo json_encode(['error' => 'Producto no disponible']);
        return;
    }
    
    // Verificar stock disponible
    $stockEnCarrito = $db->query(
        "SELECT COALESCE(cantidad, 0) as cantidad FROM carrito WHERE usuario_id = ? AND producto_id = ?",
        [$userId, $productoId]
    );
    
    $cantidadActual = !empty($stockEnCarrito) ? $stockEnCarrito[0]['cantidad'] : 0;
    $cantidadTotal = $cantidadActual + $cantidad;
    
    if ($cantidadTotal > $producto['stock']) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Stock insuficiente',
            'stock_disponible' => $producto['stock'],
            'cantidad_en_carrito' => $cantidadActual
        ]);
        return;
    }
    
    // Agregar o actualizar item en carrito
    if ($cantidadActual > 0) {
        // Actualizar cantidad existente
        $sql = "UPDATE carrito SET cantidad = cantidad + ? WHERE usuario_id = ? AND producto_id = ?";
        $params = [$cantidad, $userId, $productoId];
    } else {
        // Insertar nuevo item
        $sql = "INSERT INTO carrito (usuario_id, producto_id, cantidad) VALUES (?, ?, ?)";
        $params = [$userId, $productoId, $cantidad];
    }
    
    if ($db->execute($sql, $params)) {
        // Obtener item actualizado
        $item = $db->query(
            "SELECT 
                c.*,
                p.nombre as producto_nombre,
                p.precio as producto_precio,
                p.precio_oferta as producto_precio_oferta,
                (COALESCE(p.precio_oferta, p.precio) * c.cantidad) as subtotal
             FROM carrito c
             INNER JOIN productos p ON c.producto_id = p.id
             WHERE c.usuario_id = ? AND c.producto_id = ?",
            [$userId, $productoId]
        );
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Producto agregado al carrito',
            'data' => $item[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al agregar producto al carrito']);
    }
}

/**
 * PUT /{usuario_id}/{producto_id} - Actualizar cantidad de producto en carrito
 */
function handlePut($db, $userId, $productoId) {
    if (!$productoId || !is_numeric($productoId)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de producto requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['cantidad']) || !is_numeric($input['cantidad'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Cantidad es requerida y debe ser numérica']);
        return;
    }
    
    $cantidad = max(1, (int)$input['cantidad']);
    
    // Verificar que el item existe en el carrito
    $itemExiste = $db->query(
        "SELECT c.*, p.stock, p.estado FROM carrito c 
         INNER JOIN productos p ON c.producto_id = p.id
         WHERE c.usuario_id = ? AND c.producto_id = ?",
        [$userId, $productoId]
    );
    
    if (empty($itemExiste)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado en el carrito']);
        return;
    }
    
    $item = $itemExiste[0];
    
    // Verificar stock
    if ($cantidad > $item['stock']) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Stock insuficiente',
            'stock_disponible' => $item['stock']
        ]);
        return;
    }
    
    // Actualizar cantidad
    if ($db->execute("UPDATE carrito SET cantidad = ? WHERE usuario_id = ? AND producto_id = ?", [$cantidad, $userId, $productoId])) {
        // Obtener item actualizado
        $itemActualizado = $db->query(
            "SELECT 
                c.*,
                p.nombre as producto_nombre,
                p.precio as producto_precio,
                p.precio_oferta as producto_precio_oferta,
                (COALESCE(p.precio_oferta, p.precio) * c.cantidad) as subtotal
             FROM carrito c
             INNER JOIN productos p ON c.producto_id = p.id
             WHERE c.usuario_id = ? AND c.producto_id = ?",
            [$userId, $productoId]
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Cantidad actualizada',
            'data' => $itemActualizado[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar cantidad']);
    }
}

/**
 * DELETE /{usuario_id}/{producto_id} - Eliminar producto del carrito
 */
function handleDelete($db, $userId, $productoId) {
    if (!$productoId || !is_numeric($productoId)) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de producto requerido']);
        return;
    }
    
    // Verificar que el item existe
    $existe = $db->query("SELECT id FROM carrito WHERE usuario_id = ? AND producto_id = ?", [$userId, $productoId]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado en el carrito']);
        return;
    }
    
    // Eliminar item
    if ($db->execute("DELETE FROM carrito WHERE usuario_id = ? AND producto_id = ?", [$userId, $productoId])) {
        echo json_encode([
            'success' => true,
            'message' => 'Producto eliminado del carrito'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al eliminar producto del carrito']);
    }
}

/**
 * DELETE /{usuario_id}/limpiar - Limpiar todo el carrito
 */
function handleLimpiar($db, $userId) {
    if ($db->execute("DELETE FROM carrito WHERE usuario_id = ?", [$userId])) {
        echo json_encode([
            'success' => true,
            'message' => 'Carrito limpiado exitosamente'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al limpiar carrito']);
    }
}
?>