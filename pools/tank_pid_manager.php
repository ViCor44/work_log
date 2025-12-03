<?php
// tank_pid_manager.php — WorkLog CMMS
// Compatível com PHP 5.6.20
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Inicia buffer de saída para permitir redirects mesmo se houver output antes
require_once '../header.php'; // cria $conn (mysqli)
require_once '../core.php';
// Bloqueio básico de acesso: exige autenticação
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userName = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador';

// Gera token CSRF simples
if (empty($_SESSION['csrf_token'])) {
    if (function_exists('openssl_random_pseudo_bytes')) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(16));
    } else {
        $_SESSION['csrf_token'] = bin2hex(md5(uniqid('', true)));
    }
}
$csrfToken = $_SESSION['csrf_token'];

// Utilitários
function e($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

function normalize_decimal($val) {
    // Converte vírgula para ponto e remove espaços
    $val = trim(str_replace(' ', '', $val));
    $val = str_replace(',', '.', $val);
    return $val;
}

function tank_has_pid_columns($conn) {
    // Verifica se tanks tem colunas pid_p, pid_i, pid_d
    static $cached = null;
    if ($cached !== null) return $cached;

    $cols = array();
    if ($res = $conn->query("SHOW COLUMNS FROM `tanks`")) {
        while ($row = $res->fetch_assoc()) {
            $cols[$row['Field']] = true;
        }
        $res->free();
    }
    $cached = (isset($cols['pid_p']) && isset($cols['pid_i']) && isset($cols['pid_d']));
    return $cached;
}

// Carrega lista de tanques com controlador
$tanks = array();
$sqlTanks = "SELECT id, name FROM tanks WHERE has_controller = 1 ORDER BY name";
if ($res = $conn->query($sqlTanks)) {
    while ($row = $res->fetch_assoc()) {
        $tanks[] = $row;
    }
    $res->free();
}

$messages = array();
$errors = array();

// Mensagem flash pós-redirect (PRG)
if (isset($_SESSION['flash_success'])) {
    $messages[] = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validação CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Token CSRF inválido. Atualize a página e tente novamente.';
    } else {
        $tankId = isset($_POST['tank_id']) ? (int)$_POST['tank_id'] : 0;
        $p = isset($_POST['p']) ? (float) normalize_decimal($_POST['p']) : '';
        $i = isset($_POST['i']) ? trim($_POST['i']) : '';
        $d = isset($_POST['d']) ? trim($_POST['d']) : '';
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;

        // Validações
        if ($tankId <= 0) {
            $errors[] = 'Selecione um tanque válido.';
        }
        if ($p === '' || !is_numeric($p)) {
            $errors[] = 'P inválido.';
        }
        if ($i === '' || filter_var($i, FILTER_VALIDATE_INT) === false) { $errors[] = 'I inválido (inteiro).'; }
        if ($d === '' || filter_var($d, FILTER_VALIDATE_INT) === false) { $errors[] = 'D inválido (inteiro).'; }
        if ($reason === '') {
            $errors[] = 'Indique o motivo da alteração (reason).';
        }

        // Verifica se o tanque selecionado realmente tem controlador
        if (!$errors) {
            $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM tanks WHERE id = ? AND has_controller = 1");
            $stmtCheck->bind_param('i', $tankId);
            $stmtCheck->execute();
            $stmtCheck->bind_result($cnt);
            $stmtCheck->fetch();
            $stmtCheck->close();
            if ($cnt == 0) {
                $errors[] = 'O tanque selecionado não tem controlador ativo.';
            }
        }

        if (!$errors) {
    // Normaliza tipos
    $p = (float)$p;
    $i = (int)$i;
    $d = (int)$d;
    // Inserção na tabela histórica
            $stmt = $conn->prepare("INSERT INTO `tank_pid_changes` (`tank_id`,`p`,`i`,`d`,`reason`,`changed_by`,`ip_address`) VALUES (?,?,?,?,?,?,?)");
            if ($stmt === false) { $errors[] = 'Erro ao preparar inserção: ' . $conn->error; } else {
                $stmt->bind_param('idiisis', $tankId, $p, $i, $d, $reason, $userId, $ip);
                if (!$stmt->execute()) {
                    $errors[] = 'Falha ao registar a alteração de PID.';
                }
                $stmt->close();
            }

            // Atualiza valores correntes no tanque se as colunas existirem
            if (!$errors && tank_has_pid_columns($conn)) {
                $stmt2 = $conn->prepare("UPDATE tanks SET pid_p = ?, pid_i = ?, pid_d = ? WHERE id = ?");
                if ($stmt2) {
                    $stmt2->bind_param('diii', $p, $i, $d, $tankId);
                    if (!$stmt2->execute()) {
                        // Não é crítico, apenas avisa
                        $messages[] = 'Registo histórico gravado, mas não foi possível atualizar os valores atuais no tanque.';
                    }
                    $stmt2->close();
                }
            }

            if (!$errors) {
			    $_SESSION['flash_success'] = 'Alteração de PID gravada com sucesso.';
			// Limpa o buffer antes de enviar headers de redirect
			if (ob_get_length()) { ob_end_clean(); }
			header('Location: tank_pid_manager.php?tank_id_filter=' . $tankId);
			exit;
			}
        }
    }
}

// Filtro de histórico por tanque (opcional)
$filterTankId = isset($_GET['tank_id_filter']) ? (int)$_GET['tank_id_filter'] : 0;
$history = array();

// Descobrir dinamicamente o nome da coluna "nome do utilizador" e "nome do tanque"
function pick_existing_column_expr($conn, $table, array $candidates, $aliasPrefix) {
    $cols = array();
    if ($res = $conn->query("SHOW COLUMNS FROM `".$conn->real_escape_string($table)."`")) {
        while ($row = $res->fetch_assoc()) { $cols[$row['Field']] = true; }
        $res->free();
    }
    foreach ($candidates as $c) {
        if (isset($cols[$c])) return $aliasPrefix.".`".$c."`"; // ex.: u.`name`
    }
    return "CONCAT('#', ".$aliasPrefix.".`id`)"; // fallback seguro
}

$userNameExpr = pick_existing_column_expr($conn, 'users', array('name','username','display_name','nome','email'), 'u');
$tankNameExpr = pick_existing_column_expr($conn, 'tanks', array('name','tank_name','descricao','description','tag'), 't');

// Garante que a variável existe sempre (evita Notice)
$selectedTankName = '';

if ($filterTankId > 0) {
    $sqlH = "SELECT DATE(c.changed_at) AS changed_at, c.p, c.i, c.d, c.reason, ".$userNameExpr." AS user_name, u.id AS user_id
         FROM `tank_pid_changes` c
         LEFT JOIN `users` u ON u.`id` = c.`changed_by`
         WHERE c.`tank_id` = ?
         ORDER BY c.`changed_at` DESC
         LIMIT 50";
    $stmtH = $conn->prepare($sqlH);
    if ($stmtH === false) {
        $errors[] = 'Erro ao preparar histórico (filtrado): ' . $conn->error;
    } else {
        $stmtH->bind_param('i', $filterTankId);
    }

    // Obter o nome do tanque selecionado para o cabeçalho
    $sqlN = "SELECT ".$tankNameExpr." AS tank_name FROM `tanks` t WHERE t.`id` = ? LIMIT 1";
    $stmtN = $conn->prepare($sqlN);
    if ($stmtN) {
        $stmtN->bind_param('i', $filterTankId);
        if ($stmtN->execute()) {
            $stmtN->bind_result($selectedTankName);
            $stmtN->fetch();
        }
        $stmtN->close();
    }
} else {
    $sqlH = "SELECT DATE(c.changed_at) AS changed_at, c.p, c.i, c.d, c.reason, ".$userNameExpr." AS user_name, u.id AS user_id, ".$tankNameExpr." AS tank_name
         FROM `tank_pid_changes` c
         LEFT JOIN `users` u ON u.`id` = c.`changed_by`
         LEFT JOIN `tanks` t ON t.`id` = c.`tank_id`
         ORDER BY c.`changed_at` DESC
         LIMIT 50";
    $stmtH = $conn->prepare($sqlH);
    if ($stmtH === false) {
        $errors[] = 'Erro ao preparar histórico: ' . $conn->error;
    }
}

if (isset($stmtH) && $stmtH && $stmtH->execute()) {
    $resH = $stmtH->get_result();
    while ($row = $resH->fetch_assoc()) { $history[] = $row; }
    $stmtH->close();
}
?>
<style>
	body {
	    background-color: #f0f0f0; /* Um cinza bem claro */
	}
</style>
<div class="container pid-page" style="margin-top:20px;">
    <div class="row">
        <div class="col-sm-12 col-md-3">
            <div class="card">
                <div class="card-header"><strong>Nova alteração de PID</strong></div>
                <div class="card-body">
                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <ul style="margin:0; padding-left:18px;">
                                <?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($messages): ?>
                        <div class="alert alert-success">
                            <ul style="margin:0; padding-left:18px;">
                                <?php foreach ($messages as $m): ?><li><?php echo e($m); ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="form">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <div class="form-group">
                            <label for="tank_id">Tanque com controlador</label>
                            <select id="tank_id" name="tank_id" class="form-select" required>
                                <option value="">— selecione —</option>
                                <?php foreach ($tanks as $t): ?>
                                    <option value="<?php echo (int)$t['id']; ?>" <?php echo ($filterTankId == (int)$t['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($t['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="p">P (Kp)</label>
                            <div class="input-group">
                                <input type="number" id="p" name="p" class="form-control" placeholder="ex.: 1.2" step="0.000001" required>
                                <span class="input-group-text">Kp</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="i">I (Ti)</label>
                            <div class="input-group">
                                <input type="number" id="i" name="i" class="form-control" placeholder="ex.: 1800" step="1" required>
                                <span class="input-group-text">Ti</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="d">D (Td)</label>
                            <div class="input-group">
                                <input type="number" id="d" name="d" class="form-control" placeholder="ex.: 450" step="1" required>
                                <span class="input-group-text">Td</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="reason">Motivo</label>
                            <textarea id="reason" name="reason" class="form-control" rows="3" maxlength="255" placeholder="Descreva o porquê da alteração" required></textarea>
                        </div>
                        <div class="d-flex justify-content-between mt-4">
						    <a href="javascript:history.back()" class="btn btn-secondary">Voltar</a>
						    <button type="submit" class="btn btn-primary">Guardar alteração</button>
						</div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-sm-12 col-md-9">
            <div class="card">
                <div class="card-header">
                    <strong>Histórico recente de alterações</strong>
                    <?php if ($filterTankId > 0): ?>
                        <span class="text-muted">— Tanque: <?php echo e($selectedTankName); ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="get" class="mb-2" style="margin-bottom:10px;">
                        <div class="form-group">
                            <label class="sr-only" for="tank_id_filter">Filtrar por tanque</label>
                            <select id="tank_id_filter" name="tank_id_filter" class="form-select" onchange="this.form.submit()">
                                <option value="0">— todos —</option>
                                <?php foreach ($tanks as $t): ?>
                                    <option value="<?php echo (int)$t['id']; ?>" <?php echo ($filterTankId == (int)$t['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($t['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <?php if ($filterTankId == 0): ?><th>Tanque</th><?php endif; ?>
                                    <th class="num">P (Kp)</th>
                                    <th class="num">I (Ti)</th>
                                    <th class="num">D (Td)</th>
                                    <th>Utilizador</th>
                                    <th>Motivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($history)): ?>
                                    <tr><td colspan="<?php echo $filterTankId == 0 ? 7 : 6; ?>" class="text-center text-muted">Sem registos.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($history as $h): ?>
                                        <tr>
                                            <td><?php echo e($h['changed_at']); ?></td>
                                            <?php if ($filterTankId == 0): ?>
                                                <td><?php echo e(isset($h['tank_name']) ? $h['tank_name'] : ''); ?></td>
                                            <?php endif; ?>
                                            <td class="num"><?php echo e($h['p']); ?></td>
                                            <td class="num"><?php echo e($h['i']); ?></td>
                                            <td class="num"><?php echo e($h['d']); ?></td>
                                            <td><?php echo e(isset($h['user_name']) ? $h['user_name'] : ('#'.$h['user_id'])); ?></td>
                                            <td><?php echo e($h['reason']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>
<script src="/wor_log/js/bootstrap.bundle.min.js"></script>
</body>
</html>
 <?php ob_end_flush(); ?>
