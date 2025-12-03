<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Verifica se o usuário tem permissão (admin)
if ($_SESSION['user_type'] !== 'admin') {
    echo "Acesso negado!";
    exit;
}

require_once 'db.php'; // Conexão com o banco de dados

// Verifica se o ID do ativo foi fornecido
if (isset($_GET['id'])) {
    $asset_id = intval($_GET['id']);

    // Verifica se o ativo existe no banco de dados
    $query = $conn->prepare("SELECT * FROM assets WHERE id = ?");
    $query->bind_param("i", $asset_id);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        // Exclui o ativo
        $delete = $conn->prepare("DELETE FROM assets WHERE id = ?");
        $delete->bind_param("i", $asset_id);
        if ($delete->execute()) {
            // Redireciona com mensagem de sucesso
            header("Location: list_assets.php?message=deleted_successfully");
            exit;
        } else {
            echo "Erro ao tentar excluir o ativo.";
        }
    } else {
        echo "Ativo não encontrado.";
    }
} else {
    echo "ID do ativo não especificado.";
}
