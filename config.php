<?php
session_start();

define('ADMIN_PASSWORD', 'troque-sua-senha');
define('DATA_FILE', __DIR__ . '/dados.json');
define('UPLOAD_DIR', __DIR__ . '/capas/');

function carregarDados() {
    $dados = json_decode(
        file_get_contents(DATA_FILE),
        true
    );

    return is_array($dados) ? $dados : [];
}

function salvarDados($dados) {
    file_put_contents(
        DATA_FILE,
        json_encode(
            $dados,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ),
        LOCK_EX
    );
}

function protegerPagina() {
    if (empty($_SESSION['admin'])) {
        header('Location: index.php');
        exit;
    }
}