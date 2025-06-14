<?php
/**
 * API para manejar categorías
 * Archivo: php/api/categorias.php
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
$path = str_replace('/php/api/categorias.php', '', $path);

$segments = explode('/', trim($path, '/'));
$action = isset($segments[0]) && !empty($segments[0]) ? $segments[0] : null;
$id = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;

try {
    $db = getDB();
    
    switch ($method) {
        case 'GET':
            if ($action === 'destacadas') {
                handleDestacadas($db);
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
 * GET /destacadas - Obtener categorías destacadas para la página principal
 */
function handleDestacadas($db) {
    $sql = "SELECT 
                c.id,
                c.nombre,
                c.descripcion,
                c.icono,
                c.color_hex,
                c.total_productos,
                c.es_destacada
            FROM categorias c
            WHERE c.estado = 'activo' 
            AND c.es_destacada = 1
            ORDER BY c.total_productos DESC
            LIMIT 8";
    
    $categorias = $db->query($sql);
    
    // Formatear para el frontend
    $categoriasFormateadas = array_map(function($categoria) {
        return [
            'id' => strtolower(str_replace(' ', '-', $categoria['nombre'])), // Para URLs amigables
            'name' => $categoria['nombre'],
            'description' => $categoria['descripcion'],
            'imageClass' => getImageClass($categoria['color_hex'] ?? '#F97316'),
            'productCount' => (int)$categoria['total_productos'],
            'icon' => $categoria['icono']
        ];
    }, $categorias);
    
    echo json_encode([
        'success' => true,
        'data' => $categoriasFormateadas
    ]);
}

/**
 * GET - Obtener todas las categorías
 */
function handleGet($db) {
    $incluirInactivas = isset($_GET['incluir_inactivas']) ? (bool)$_GET['incluir_inactivas'] : false;
    
    $where = $incluirInactivas ? "" : "WHERE c.estado = 'activo'";
    
    $sql = "SELECT 
                c.id,
                c.nombre,
                c.descripcion,
                c.icono,
                c.color_hex,
                c.total_productos,
                c.es_destacada,
                c.estado,
                c.categoria_padre,
                padre.nombre as categoria_padre_nombre
            FROM categorias c
            LEFT JOIN categorias padre ON c.categoria_padre = padre.id
            $where
            ORDER BY c.es_destacada DESC, c.total_productos DESC, c.nombre ASC";
    
    $categorias = $db->query($sql);
    
    // Formatear para el frontend
    $categoriasFormateadas = array_map(function($categoria) {
        return [
            'id' => (int)$categoria['id'],
            'slug' => strtolower(str_replace(' ', '-', $categoria['nombre'])),
            'name' => $categoria['nombre'],
            'description' => $categoria['descripcion'],
            'icon' => $categoria['icono'],
            'color' => $categoria['color_hex'],
            'imageClass' => getImageClass($categoria['color_hex'] ?? '#F97316'),
            'productCount' => (int)$categoria['total_productos'],
            'featured' => (bool)$categoria['es_destacada'],
            'status' => $categoria['estado'],
            'parentCategory' => $categoria['categoria_padre_nombre']
        ];
    }, $categorias);
    
    echo json_encode([
        'success' => true,
        'data' => $categoriasFormateadas
    ]);
}

/**
 * GET /{id} - Obtener categoría específica
 */
function handleGetById($db, $id) {
    $sql = "SELECT 
                c.*,
                padre.nombre as categoria_padre_nombre,
                COUNT(p.id) as productos_activos
            FROM categorias c
            LEFT JOIN categorias padre ON c.categoria_padre = padre.id
            LEFT JOIN productos p ON c.id = p.categoria_id AND p.estado = 'activo'
            WHERE c.id = ?
            GROUP BY c.id";
    
    $categoria = $db->query($sql, [$id]);
    
    if (empty($categoria)) {
        http_response_code(404);
        echo json_encode(['error' => 'Categoría no encontrada']);
        return;
    }
    
    $categoria = $categoria[0];
    
    // Obtener subcategorías si las tiene
    $subcategorias = $db->query(
        "SELECT id, nombre, descripcion, total_productos 
         FROM categorias 
         WHERE categoria_padre = ? AND estado = 'activo'
         ORDER BY nombre",
        [$id]
    );
    
    $categoriaFormateada = [
        'id' => (int)$categoria['id'],
        'slug' => strtolower(str_replace(' ', '-', $categoria['nombre'])),
        'name' => $categoria['nombre'],
        'description' => $categoria['descripcion'],
        'icon' => $categoria['icono'],
        'color' => $categoria['color_hex'],
        'imageClass' => getImageClass($categoria['color_hex'] ?? '#F97316'),
        'productCount' => (int)$categoria['productos_activos'],
        'featured' => (bool)$categoria['es_destacada'],
        'status' => $categoria['estado'],
        'parentCategory' => $categoria['categoria_padre_nombre'],
        'subcategories' => array_map(function($sub) {
            return [
                'id' => (int)$sub['id'],
                'name' => $sub['nombre'],
                'description' => $sub['descripcion'],
                'productCount' => (int)$sub['total_productos']
            ];
        }, $subcategorias)
    ];
    
    echo json_encode([
        'success' => true,
        'data' => $categoriaFormateada
    ]);
}

/**
 * POST - Crear nueva categoría
 */
function handlePost($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $required = ['nombre'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
            http_response_code(400);
            echo json_encode(['error' => "Campo '$field' es requerido"]);
            return;
        }
    }
    
    $sql = "INSERT INTO categorias (nombre, descripcion, icono, color_hex, es_destacada, categoria_padre) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $params = [
        trim($input['nombre']),
        trim($input['descripcion'] ?? ''),
        trim($input['icono'] ?? ''),
        $input['color_hex'] ?? '#F97316',
        isset($input['es_destacada']) ? (bool)$input['es_destacada'] : false,
        isset($input['categoria_padre']) ? (int)$input['categoria_padre'] : null
    ];
    
    if ($db->execute($sql, $params)) {
        $categoriaId = $db->lastInsertId();
        $categoria = $db->query("SELECT * FROM categorias WHERE id = ?", [$categoriaId]);
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Categoría creada exitosamente',
            'data' => $categoria[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al crear categoría']);
    }
}

/**
 * PUT /{id} - Actualizar categoría
 */
function handlePut($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de categoría requerido']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $existe = $db->query("SELECT id FROM categorias WHERE id = ?", [$id]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Categoría no encontrada']);
        return;
    }
    
    $campos = [];
    $params = [];
    
    $camposPermitidos = ['nombre', 'descripcion', 'icono', 'color_hex', 'es_destacada', 'estado', 'categoria_padre'];
    
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
    $sql = "UPDATE categorias SET " . implode(', ', $campos) . " WHERE id = ?";
    
    if ($db->execute($sql, $params)) {
        $categoria = $db->query("SELECT * FROM categorias WHERE id = ?", [$id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Categoría actualizada exitosamente',
            'data' => $categoria[0]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar categoría']);
    }
}

/**
 * DELETE /{id} - Eliminar categoría
 */
function handleDelete($db, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de categoría requerido']);
        return;
    }
    
    $existe = $db->query("SELECT id FROM categorias WHERE id = ?", [$id]);
    if (empty($existe)) {
        http_response_code(404);
        echo json_encode(['error' => 'Categoría no encontrada']);
        return;
    }
    
    // Verificar si tiene productos asociados
    $tieneProductos = $db->query("SELECT COUNT(*) as total FROM productos WHERE categoria_id = ?", [$id]);
    if ($tieneProductos[0]['total'] > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No se puede eliminar la categoría porque tiene productos asociados']);
        return;
    }
    
    if ($db->execute("UPDATE categorias SET estado = 'inactivo' WHERE id = ?", [$id])) {
        echo json_encode([
            'success' => true,
            'message' => 'Categoría eliminada exitosamente'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Error al eliminar categoría']);
    }
}

/**
 * Función helper para generar clases CSS basadas en el color
 */
function getImageClass($colorHex) {
    // Convertir color hex a clase CSS Tailwind aproximada
    $colorMap = [
        '#F97316' => 'bg-amber-100',
        '#4F46E5' => 'bg-blue-100', 
        '#059669' => 'bg-green-100',
        '#DC2626' => 'bg-red-100',
        '#7C3AED' => 'bg-purple-100',
        '#EA580C' => 'bg-orange-100',
        '#8B4513' => 'bg-amber-100',
        '#1F2937' => 'bg-gray-100'
    ];
    
    return $colorMap[$colorHex] ?? 'bg-gray-100';
}
?>