<?php
/**
 * Configuración CORS mejorada para Next.js
 * Archivo: php/config/cors.php
 */

function configurarCORS() {
    // Dominios permitidos (agregar tu dominio de frontend)
    $allowedOrigins = [
        'http://localhost:3000',    // Next.js development
        'http://127.0.0.1:3000',
        'https://tu-dominio.com',   // Producción
        'https://tu-app.vercel.app' // Vercel deployment
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Verificar si el origin está permitido
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        header('Access-Control-Allow-Origin: *'); // Solo para desarrollo
    }
    
    // Headers CORS estándar
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // Cache preflight por 24 horas
    
    // Manejar preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// Aplicar configuración CORS
configurarCORS();
?>