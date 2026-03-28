<?php
require_once '../header.php';

// Lógica para ler e validar a licença atual
$licenca_atual = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'license_key'")->fetch_assoc();
$status_licenca = "Inválida ou Inexistente";
$cor_status = "danger";

if ($licenca_atual && !empty($licenca_atual['setting_value'])) {
    $secret_key = 'MinhaChaveSuperSecreta@2025!';
    $dados_licenca = base64_decode($licenca_atual['setting_value']);
    $iv = substr($dados_licenca, 0, 16);
    $dados_encriptados = substr($dados_licenca, 16);
    $data_validade_str = openssl_decrypt($dados_encriptados, 'aes-256-cbc', $secret_key, 0, $iv);

    if ($data_validade_str) {
        $data_validade = new DateTime($data_validade_str);
        $hoje = new DateTime();
        if ($hoje > $data_validade) {
            $status_licenca = "Expirada a " . $data_validade->format('d/m/Y');
            $cor_status = "warning";
        } else {
            $status_licenca = "Válida até " . $data_validade->format('d/m/Y');
            $cor_status = "success";
        }
    }
}
?>
<div class="container mt-4">
    <h1 class="h3 mb-4">Gestão da Licença do Software</h1>
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Estado da Licença Atual</h5>
            <p>Estado: <span class="badge bg-<?= $cor_status; ?>"><?= $status_licenca; ?></span></p>
            <hr>
            <h5 class="card-title">Atualizar Chave de Licença</h5>
            <form action="guardar_licenca.php" method="POST">
                <div class="mb-3">
                    <label for="license_key" class="form-label">Nova Chave de Licença</label>
                    <textarea class="form-control" id="license_key" name="license_key" rows="4" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Ativar Nova Licença</button>
            </form>
        </div>
    </div>
</div>
<?php require_once '../footer.php'; ?>