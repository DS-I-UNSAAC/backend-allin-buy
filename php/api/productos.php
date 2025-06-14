<?php
/**
 * API mejorada para productos - compatible con el frontend
 * Archivo: php/api/productos.php
 */

require_once '../config/database.php';
require_once '../config/cors.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$request = $_SERVER['REQUEST_URI'];
$path = parse_url($request, PHP_URL_PATH);
$path = str_replace('/php/api/productos.php', '', $path);

$segments = explode('/', trim($path, '/'));
$action = isset($segments[0]) && !empty($segments[0]) ? $segments[0] : null;
$id = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;

try {
    $db = getDB();
    
    switch ($method) {
        case 'GET':
            if ($action === 'destacados') {
                handleDestacados($db);
            } elseif ($action === 'categoria' && $id) {
                handleByCategoria($db, $id);
            } elseif (is_numeric($action)) {
                handleGetById($db, (int)$action);
            } else {
                handleGet($db);
            }
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
 * GET /destacados - Obtener productos destacados para la página principal
 */
function handleDestacados($db) {
    $sql = "SELECT 
                p.id,
                p.nombre,
                p.descripcion,
                COALESCE(p.precio_oferta, p.precio) as precio,
                p.precio as precio_original,
                p.precio_oferta,
                p.stock,
                p.estado,
                p.imagen_principal as imagen,
                p.calificacion_promedio as rating,
                p.total_resenas as reviewCount,
                p.es_destacado,
                c.nombre as categoria,
                e.nombre_negocio as seller,
                e.id as seller_id,
                -- Calcular descuento si hay precio de oferta
                CASE 
                    WHEN p.precio_oferta IS NOT NULL AND p.precio_oferta < p.precio 
                    THEN ROUND(((p.precio - p.precio_oferta) / p.precio) * 100, 0)
                    ELSE 0 
                END as discount
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN emprendedores e ON p.emprendedor_id = e.id
            WHERE p.estado = 'activo' 
            AND p.stock > 0
            AND (p.es_destacado = 1 OR p.total_ventas > 5)
            ORDER BY p.es_destacado DESC, p.calificacion_promedio DESC, p.total_ventas DESC
            LIMIT 8";
    
    $productos = $db->query($sql);
    
    // Formatear datos para el frontend
    $productosFormateados = array_map(function($producto) {
        return [
            'id' => (int)$producto['id'],
            'name' => $producto['nombre'],
            'description' => $producto['descripcion'],
            'price' => (float)$producto['precio'],
            'oldPrice' => $producto['precio_oferta'] ? (float)$producto['precio_original'] : null,
            'category' => $producto['categoria'],
            'seller' => $producto['seller'] ?? 'AllinBuy',
            'rating' => (float)($producto['rating'] ?? 4.5),
            'reviewCount' => (int)($producto['reviewCount'] ?? 0),
            'image' => $producto['imagen'] ?? '/assets/placeholder.png',
            'stock' => (int)$producto['stock'],
            'discount' => (int)$producto['discount'],
            'isNew' => false, // Puedes agregar lógica para productos nuevos
            'isFavorite' => false // Se actualizará con la sesión del usuario
        ];
    }, $productos);
    
    echo json_encode([
        'success' => true,
        'data' => $productosFormateados
    ]);
}

/**
 * GET /categoria/{id} - Obtener productos por categoría
 */
function handleByCategoria($db, $categoriaId) {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 12;
    $orden = isset($_GET['orden']) ? $_GET['orden'] : 'relevancia';
    $precioMin = isset($_GET['precio_min']) ? (float)$_GET['precio_min'] : 0;
    $precioMax = isset($_GET['precio_max']) ? (float)$_GET['precio_max'] : 999999;
    
    $offset = ($page - 1) * $limit;
    
    // Determinar ORDER BY según el filtro
    $orderBy = "p.es_destacado DESC, p.calificacion_promedio DESC";
    switch ($orden) {
        case 'precio-asc':
            $orderBy = "COALESCE(p.precio_oferta, p.precio) ASC";
            break;
        case 'precio-desc':
            $orderBy = "COALESCE(p.precio_oferta, p.precio) DESC";
            break;
        case 'rating':
            $orderBy = "p.calificacion_promedio DESC, p.total_resenas DESC";
            break;
        case 'newest':
            $orderBy = "p.fecha_creacion DESC";
            break;
    }
    
    $sql = "SELECT 
                p.id,
                p.nombre,
                p.descripcion,
                COALESCE(p.precio_oferta, p.precio) as precio,
                p.precio as precio_original,
                p.precio_oferta,
                p.stock,
                p.imagen_principal as imagen,
                p.calificacion_promedio as rating,
                p.total_resenas as reviewCount,
                c.nombre as categoria,
                e.nombre_negocio as seller,
                CASE 
                    WHEN p.precio_oferta IS NOT NULL AND p.precio_oferta < p.precio 
                    THEN ROUND(((p.precio - p.precio_oferta) / p.precio) * 100, 0)
                    ELSE 0 
                END as discount
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN emprendedores e ON p.emprendedor_id = e.id
            WHERE p.estado = 'activo' 
            AND p.categoria_id = ?
            AND COALESCE(p.precio_oferta, p.precio) BETWEEN ? AND ?
            ORDER BY $orderBy
            LIMIT ? OFFSET ?";
    
    $productos = $db->query($sql, [$categoriaId, $precioMin, $precioMax, $limit, $offset]);
    
    // Contar total para paginación
    $sqlCount = "SELECT COUNT(*) as total 
                 FROM productos p 
                 WHERE p.estado = 'activo' 
                 AND p.categoria_id = ?
                 AND COALESCE(p.precio_oferta, p.precio) BETWEEN ? AND ?";
    $totalResult = $db->query($sqlCount, [$categoriaId, $precioMin, $precioMax]);
    $total = $totalResult[0]['total'];
    
    // Formatear productos
    $productosFormateados = array_map(function($producto) {
        return [
            'id' => (int)$producto['id'],
            'name' => $producto['nombre'],
            'description' => substr($producto['descripcion'], 0, 100) . '...',
            'price' => (float)$producto['precio'],
            'oldPrice' => $producto['precio_oferta'] ? (float)$producto['precio_original'] : null,
            'category' => $producto['categoria'],
            'seller' => $producto['seller'] ?? 'AllinBuy',
            'rating' => (float)($producto['rating'] ?? 4.5),
            'reviewCount' => (int)($producto['reviewCount'] ?? 0),
            'image' => $producto['imagen'] ?? '/assets/placeholder.png',
            'stock' => (int)$producto['stock'],
            'discount' => (int)$producto['discount'],
            'inStock' => (int)$producto['stock'] > 0
        ];
    }, $productos);
    
    echo json_encode([
        'success' => true,
        'data' => $productosFormateados,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * GET /{id} - Obtener producto específico
 */
function handleGetById($db, $id) {
    $sql = "SELECT 
                p.*,
                c.nombre as categoria_nombre,
                e.nombre_negocio as vendedor_nombre,
                e.descripcion as vendedor_descripcion,
                e.calificacion_promedio as vendedor_rating,
                e.total_ventas as vendedor_productos,
                e.fecha_registro as vendedor_desde
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN emprendedores e ON p.emprendedor_id = e.id
            WHERE p.id = ? AND p.estado = 'activo'";
    
    $producto = $db->query($sql, [$id]);
    
    if (empty($producto)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
        return;
    }
    
    $producto = $producto[0];
    
    // Obtener imágenes del producto
    $imagenes = $db->query(
        "SELECT url_imagen, alt_text, es_principal 
         FROM producto_imagenes 
         WHERE producto_id = ? 
         ORDER BY es_principal DESC, orden ASC",
        [$id]
    );
    
    // Obtener reseñas
    $resenas = $db->query(
        "SELECT r.*, u.nombre, u.apellido
         FROM resenas r
         INNER JOIN usuarios u ON r.usuario_id = u.id
         WHERE r.producto_id = ?
         ORDER BY r.fecha_resena DESC
         LIMIT 10",
        [$id]
    );
    
    // Formatear para el frontend
    $productoFormateado = [
        'id' => (int)$producto['id'],
        'name' => $producto['nombre'],
        'description' => $producto['descripcion'],
        'price' => (float)($producto['precio_oferta'] ?? $producto['precio']),
        'originalPrice' => (float)$producto['precio'],
        'category' => $producto['categoria_nombre'],
        'rating' => (float)($producto['calificacion_promedio'] ?? 4.5),
        'reviewCount' => (int)($producto['total_resenas'] ?? 0),
        'stock' => (int)$producto['stock'],
        'images' => array_map(function($img) {
            return $img['url_imagen'] ?? '/assets/placeholder.png';
        }, $imagenes) ?: ['/assets/placeholder.png'],
        'seller' => [
            'name' => $producto['vendedor_nombre'] ?? 'AllinBuy',
            'description' => $producto['vendedor_descripcion'] ?? '',
            'rating' => (float)($producto['vendedor_rating'] ?? 4.5),
            'products' => (int)($producto['vendedor_productos'] ?? 0),
            'joinedDate' => $producto['vendedor_desde'] ? date('F Y', strtotime($producto['vendedor_desde'])) : 'N/A'
        ],
        'specifications' => [
            ['name' => 'Peso', 'value' => $producto['peso'] ? $producto['peso'] . 'g' : 'N/A'],
            ['name' => 'Dimensiones', 'value' => $producto['dimensiones'] ?? 'N/A'],
            ['name' => 'Palabras clave', 'value' => $producto['palabras_clave'] ?? 'N/A']
        ],
        'reviews' => array_map(function($resena) {
            return [
                'id' => (int)$resena['id'],
                'user' => $resena['nombre'] . ' ' . substr($resena['apellido'], 0, 1) . '.',
                'rating' => (int)$resena['calificacion'],
                'comment' => $resena['comentario'],
                'date' => date('d M, Y', strtotime($resena['fecha_resena']))
            ];
        }, $resenas)
    ];
    
    echo json_encode([
        'success' => true,
        'data' => $productoFormateado
    ]);
}

/**
 * GET - Obtener productos con filtros (para página de productos)
 */
function handleGet($db) {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 12;
    $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
    $categoria = isset($_GET['categoria']) ? (int)$_GET['categoria'] : null;
    $orden = isset($_GET['orden']) ? $_GET['orden'] : 'relevancia';
    $precioMin = isset($_GET['precio_min']) ? (float)$_GET['precio_min'] : 0;
    $precioMax = isset($_GET['precio_max']) ? (float)$_GET['precio_max'] : 999999;
    $soloStock = isset($_GET['solo_stock']) ? (bool)$_GET['solo_stock'] : false;
    
    $offset = ($page - 1) * $limit;
    
    // Construir WHERE
    $where = "WHERE p.estado = 'activo'";
    $params = [];
    
    if ($categoria) {
        $where .= " AND p.categoria_id = ?";
        $params[] = $categoria;
    }
    
    if ($buscar) {
        $where .= " AND (p.nombre LIKE ? OR p.descripcion LIKE ? OR p.palabras_clave LIKE ?)";
        $params[] = "%$buscar%";
        $params[] = "%$buscar%";
        $params[] = "%$buscar%";
    }
    
    if ($soloStock) {
        $where .= " AND p.stock > 0";
    }
    
    $where .= " AND COALESCE(p.precio_oferta, p.precio) BETWEEN ? AND ?";
    $params[] = $precioMin;
    $params[] = $precioMax;
    
    // ORDER BY
    $orderBy = "p.es_destacado DESC, p.calificacion_promedio DESC";
    switch ($orden) {
        case 'precio-asc':
            $orderBy = "COALESCE(p.precio_oferta, p.precio) ASC";
            break;
        case 'precio-desc':
            $orderBy = "COALESCE(p.precio_oferta, p.precio) DESC";
            break;
        case 'rating':
            $orderBy = "p.calificacion_promedio DESC";
            break;
        case 'newest':
            $orderBy = "p.fecha_creacion DESC";
            break;
    }
    
    $sql = "SELECT 
                p.id,
                p.nombre,
                p.descripcion,
                COALESCE(p.precio_oferta, p.precio) as precio,
                p.precio as precio_original,
                p.precio_oferta,
                p.stock,
                p.imagen_principal as imagen,
                p.calificacion_promedio as rating,
                p.total_resenas as reviewCount,
                c.nombre as categoria,
                e.nombre_negocio as seller,
                CASE 
                    WHEN p.precio_oferta IS NOT NULL AND p.precio_oferta < p.precio 
                    THEN ROUND(((p.precio - p.precio_oferta) / p.precio) * 100, 0)
                    ELSE 0 
                END as discount,
                DATEDIFF(NOW(), p.fecha_creacion) <= 30 as isNew
            FROM productos p
            LEFT JOIN categorias c ON p.categoria_id = c.id
            LEFT JOIN emprendedores e ON p.emprendedor_id = e.id
            $where
            ORDER BY $orderBy
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $productos = $db->query($sql, $params);
    
    // Contar total
    $sqlCount = "SELECT COUNT(*) as total FROM productos p LEFT JOIN categorias c ON p.categoria_id = c.id $where";
    $totalResult = $db->query($sqlCount, array_slice($params, 0, -2));
    $total = $totalResult[0]['total'];
    
    // Formatear productos
    $productosFormateados = array_map(function($producto) {
        return [
            'id' => (int)$producto['id'],
            'name' => $producto['nombre'],
            'description' => substr($producto['descripcion'], 0, 100) . '...',
            'price' => (float)$producto['precio'],
            'oldPrice' => $producto['precio_oferta'] ? (float)$producto['precio_original'] : null,
            'category' => $producto['categoria'],
            'seller' => $producto['seller'] ?? 'AllinBuy',
            'rating' => (float)($producto['rating'] ?? 4.5),
            'reviewCount' => (int)($producto['reviewCount'] ?? 0),
            'image' => $producto['imagen'] ?? '/assets/placeholder.png',
            'stock' => (int)$producto['stock'],
            'discount' => (int)$producto['discount'],
            'isNew' => (bool)$producto['isNew'],
            'isFavorite' => false,
            'tags' => []
        ];
    }, $productos);
    
    echo json_encode([
        'success' => true,
        'data' => $productosFormateados,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

// Resto de funciones (POST, PUT, DELETE) mantienen la lógica original...
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $required = ['nombre', 'precio', 'categoria_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Campo '$field' es requerido"]);
            return;
        }
    }
    
    $sql = "INSERT INTO productos (nombre, descripcion, precio, precio_oferta, stock, categoria_id, imagen_principal, palabras_clave) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $params = [
        $input['nombre'],
        $input['descripcion'] ?? '',
        $input['precio'],
        $input['precio_oferta'] ?? null,
        $input['stock'] ?? 0,
        $input['categoria_id'],
        $input['imagen_principal'] ?? null,
        $input['palabras_clave'] ?? ''
    ];
    
    if ($db->execute($sql, $params)) {
        $productoId = $db->lastInsertId();
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

function handlePut($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de producto requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $existe = $db->query("SELECT id FROM productos WHERE id = ?", [$id]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
        return;
    }
    
    $campos = [];
    $params = [];
    
    $camposPermitidos = ['nombre', 'descripcion', 'precio', 'precio_oferta', 'stock', 'categoria_id', 'imagen_principal', 'estado', 'palabras_clave'];
    
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

function handleDelete($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de producto requerido']);
        return;
    }
    
    $existe = $db->query("SELECT id FROM productos WHERE id = ?", [$id]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
        return;
    }
    
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
