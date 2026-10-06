<?php
// Obtener el nombre del archivo actual para marcar el menú activo
$current_page = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Compras y Remitos - Obras Públicas</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-logo">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                    <rect width="20" height="14" x="2" y="6" rx="2"/>
                </svg>
                <span>Muni Obras</span>
            </div>
            
            <nav class="sidebar-nav">
                <a href="index.php" class="sidebar-link <?= ($current_page == 'index.php') ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="7" height="9" x="3" y="3" rx="1"/>
                        <rect width="7" height="5" x="14" y="3" rx="1"/>
                        <rect width="7" height="9" x="14" y="12" rx="1"/>
                        <rect width="7" height="5" x="3" y="16" rx="1"/>
                    </svg>
                    <span>Inicio (Dashboard)</span>
                </a>
                
                <a href="obras.php" class="sidebar-link <?= ($current_page == 'obras.php' || $current_page == 'obra_detalle.php') ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 20h20"/>
                        <path d="m17 18-1.5-1.5"/>
                        <path d="M7 18v-6a5 5 0 0 1 10 0v6"/>
                        <path d="M12 2v3"/>
                        <path d="M9 4v1"/>
                        <path d="M15 4v1"/>
                    </svg>
                    <span>Obras e Hitos</span>
                </a>
                
                <a href="compras.php" class="sidebar-link <?= ($current_page == 'compras.php') ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/>
                        <path d="M3 6h18"/>
                        <path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                    <span>Compras (OC)</span>
                </a>
                
                <a href="remitos.php" class="sidebar-link <?= ($current_page == 'remitos.php') ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <path d="M14 2v6h6"/>
                        <path d="m9 15 2 2 4-4"/>
                    </svg>
                    <span>Remitos (Entregas)</span>
                </a>
                
                <a href="facturas.php" class="sidebar-link <?= ($current_page == 'facturas.php') ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" x2="12" y1="2" y2="22"/>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                    <span>Facturas</span>
                </a>

                <a href="expedientes.php" class="sidebar-link <?= ($current_page == 'expedientes.php') ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <path d="m9 14 2 2 4-4"/>
                    </svg>
                    <span>Control de Expedientes</span>
                </a>

                <a href="informe_datos.php" class="sidebar-link <?= ($current_page == 'informe_datos.php') ? 'active' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 11l3 3L22 4"/>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                    </svg>
                    <span>Informe de datos</span>
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <form method="POST" action="login.php" style="margin: 0 0 .75rem;">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="accion" value="salir">
                    <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%;">Salir (<?= h(auth_usuario()) ?>)</button>
                </form>
                <p>Módulo de Compras v1.0</p>
                <p>Municipalidad Local</p>
            </div>
        </aside>

        <!-- Main Workspace Container -->
        <main class="main-content">
