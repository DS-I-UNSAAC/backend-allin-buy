<?php
/**
 * API para manejar productos
 * Archivo: php/api/productos.php
 */

// Incluir configuración de base de datos
require_once '../config/database.php';
require_once '../config/cors.php';

// Configurar headers para API REST
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Para permitir requests desde el frontend
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
$path = str_replace('/php/api/productos.php', '', $path);

// Obtener parámetros de la URL
$segments = explode('/', trim($path, '/'));
$id = isset($segments[0]) && is_numeric($segments[0]) ? (int)$segments[0] : null;

try {
    $db = getDB();
    
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
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error interno del servidor', 'message' => $e->getMessage()]);
}

/**
 * GET - Obtener productos
 */
function handleGet($db, $id) {
    if ($id) {
        // Obtener producto específico
        $producto = $db->query(
            "SELECT p.*, c.nombre AS categoria_nombre 
             FROM productos p 
             LEFT JOIN categorias c ON p.categoria_id = c.id 
             WHERE p.id = ?", 
            [$id]
        );
        
        if (empty($producto)) {
            http_response_code(404);
            echo json_encode(['error' => 'Producto no encontrado']);
            return;
        }
        
        // Obtener imágenes del producto
        $imagenes = $db->query(
            "SELECT imagen_url, es_principal FROM producto_imagenes WHERE producto_id = ? ORDER BY orden",
            [$id]
        );
        
        $producto[0]['imagenes'] = $imagenes;
        echo json_encode(['success' => true, 'data' => $producto[0]]);
        
    } else {
        // Obtener todos los productos con filtros opcionales
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
        $categoria = isset($_GET['categoria']) ? (int)$_GET['categoria'] : null;
        $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : null;
        $orden = isset($_GET['orden']) ? $_GET['orden'] : 'id';
        
        $offset = ($page - 1) * $limit;
        
        // Construir consulta
        $where = "WHERE p.estado = 'activo'";
        $params = [];
        
        if ($categoria) {
            $where .= " AND p.categoria_id = ?";
            $params[] = $categoria;
        }
        
        if ($buscar) {
            $where .= " AND (p.nombre LIKE ? OR p.descripcion LIKE ?)";
            $params[] = "%$buscar%";
            $params[] = "%$buscar%";
        }
        
        // Validar orden
        $ordenesValidos = ['id', 'nombre', 'precio', 'fecha_creacion'];
        if (!in_array($orden, $ordenesValidos)) {
            $orden = 'id';
        }
        
        $sql = "SELECT p.*, c.nombre AS categoria_nombre 
                FROM productos p 
                LEFT JOIN categorias c ON p.categoria_id = c.id 
                $where 
                ORDER BY p.$orden 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $productos = $db->query($sql, $params);
        
        // Contar total para paginación
        $sqlCount = "SELECT COUNT(*) as total FROM productos p $where";
        $totalResult = $db->query($sqlCount, array_slice($params, 0, -2));
        $total = $totalResult[0]['total'];
        
        echo json_encode([
            'success' => true,
            'data' => $productos,
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
 * POST - Crear nuevo producto
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validar datos requeridos
    $required = ['nombre', 'precio', 'categoria_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Campo '$field' es requerido"]);
            return;
        }
    }
    
    // Insertar producto
    $sql = "INSERT INTO productos (nombre, descripcion, precio, precio_oferta, stock, categoria_id, imagen_principal) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $params = [
        $input['nombre'],
        $input['descripcion'] ?? null,
        $input['precio'],
        $input['precio_oferta'] ?? null,
        $input['stock'] ?? 0,
        $input['categoria_id'],
        $input['imagen_principal'] ?? null
    ];
    
    if ($db->execute($sql, $params)) {
        $productoId = $db->lastInsertId();
        
        // Obtener el producto creado
        $producto = $db->query("SELECT * FROM productos WHERE id = ?", [$productoId]);
        
        http_response_code(201);
        echo json_encode([
            'success' => true, 
            'message' => 'Producto creado exitosamente',
            'data' => $producto[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al crear producto']);
    }
}

/**
 * PUT - Actualizar producto
 */
function handlePut($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de producto requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Verificar que el producto existe
    $existe = $db->query("SELECT id FROM productos WHERE id = ?", [$id]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
        return;
    }
    
    // Construir consulta de actualización
    $campos = [];
    $params = [];
    
    $camposPermitidos = ['nombre', 'descripcion', 'precio', 'precio_oferta', 'stock', 'categoria_id', 'imagen_principal', 'estado'];
    
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
    $sql = "UPDATE productos SET " . implode(', ', $campos) . " WHERE id = ?";
    
    if ($db->execute($sql, $params)) {
        // Obtener producto actualizado
        $producto = $db->query("SELECT * FROM productos WHERE id = ?", [$id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Producto actualizado exitosamente',
            'data' => $producto[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar producto']);
    }
}

/**
 * DELETE - Eliminar producto
 */
function handleDelete($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de producto requerido']);
        return;
    }
    
    // Verificar que el producto existe
    $existe = $db->query("SELECT id FROM productos WHERE id = ?", [$id]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
        return;
    }
    
    // Soft delete - cambiar estado en lugar de eliminar
    if ($db->execute("UPDATE productos SET estado = 'inactivo' WHERE id = ?", [$id])) {
        echo json_encode([
            'success' => true,
            'message' => 'Producto eliminado exitosamente'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al eliminar producto']);
    }
}
?>