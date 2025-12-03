<?php
require_once '../core.php';

$action = isset($_POST['action']) ? $_POST['action'] : '';

switch ($action) {
    case 'create_product':
        if (!empty($_POST['name']) && !empty($_POST['unit']) && isset($_POST['package_volume']) && isset($_POST['initial_stock'])) {
            $name = $_POST['name'];
            $unit = $_POST['unit'];
            $package_volume = (float)$_POST['package_volume']; // NOVO CAMPO
            $initial_stock = (float)$_POST['initial_stock'];

            $stmt = $conn->prepare("INSERT INTO chemicals (name, unit, package_volume, current_stock) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssdd", $name, $unit, $package_volume, $initial_stock); // Assinatura atualizada
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Produto '".htmlspecialchars($name)."' criado com sucesso!";
            } else {
                $_SESSION['error_message'] = "Erro ao criar o produto: " . $stmt->error;
            }
            $stmt->close();
        }
        header("Location: gerir_produtos.php");
        break;

    case 'register_purchase':
        if (!empty($_POST['chemical_id']) && !empty($_POST['quantity']) && !empty($_POST['purchase_date'])) {
            
            $chemical_id = (int)$_POST['chemical_id'];
            $quantity = (float)$_POST['quantity'];
            $purchase_date = $_POST['purchase_date'];
            $notes = $_POST['notes'];
            $user_id = $_SESSION['user_id'];

            $conn->begin_transaction();
            try {
                // 1. Insere o registo na tabela de compras
                $stmt_purchase = $conn->prepare("INSERT INTO chemical_purchases (chemical_id, user_id, quantity, purchase_date, notes) VALUES (?, ?, ?, ?, ?)");
                $stmt_purchase->bind_param("iidss", $chemical_id, $user_id, $quantity, $purchase_date, $notes);
                $stmt_purchase->execute();
                $stmt_purchase->close();

                // 2. Atualiza o stock na tabela principal de produtos
                $stmt_stock = $conn->prepare("UPDATE chemicals SET current_stock = current_stock + ? WHERE id = ?");
                $stmt_stock->bind_param("di", $quantity, $chemical_id);
                $stmt_stock->execute();
                $stmt_stock->close();
                
                $conn->commit();
                $_SESSION['success_message'] = "Compra registada e stock atualizado com sucesso!";

            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = "Erro ao registar a compra: " . $e->getMessage();
            }
        }
        header("Location: gerir_produtos.php");
        break;
		    
    default:
        header("Location: gerir_produtos.php");
        break;
}
exit;
?>