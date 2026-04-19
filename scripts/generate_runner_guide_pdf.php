<?php
require_once __DIR__ . '/../fpdf/fpdf.php';

$outFile = __DIR__ . '/../Guia_Actions_Runner_Outros_Repos.pdf';

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Guia Completo - GitHub Actions Runner (Windows)', 0, 1, 'L');

$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 6, 'Objetivo: ter Actions Runner a funcionar de forma consistente em varios repositorios, com deploy automatico e diagnostico rapido.');
$pdf->Ln(2);

function sectionTitle(FPDF $pdf, $text) {
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(0, 8, $text, 0, 1, 'L');
    $pdf->SetFont('Arial', '', 11);
}

function bullet(FPDF $pdf, $text) {
    $pdf->MultiCell(0, 6, '- ' . $text);
}

sectionTitle($pdf, '1) Preparar a maquina runner (uma vez por servidor)');
bullet($pdf, 'Instalar Git, PHP/Composer (se o projeto precisar) e confirmar acesso ao GitHub.');
bullet($pdf, 'Criar uma pasta por runner (exemplo: C:/actions-runner-meurepo).');
bullet($pdf, 'No GitHub: Settings > Actions > Runners > New self-hosted runner.');
bullet($pdf, 'Executar os comandos de configuracao fornecidos pelo GitHub.');
bullet($pdf, 'Instalar como servico no Windows e confirmar Running + Automatic.');

$pdf->Ln(1);
sectionTitle($pdf, '2) Definir estrategia de runners');
bullet($pdf, 'Opcao A: um runner por repositorio (mais isolamento).');
bullet($pdf, 'Opcao B: runners partilhados com labels (mais simples de gerir).');
bullet($pdf, 'Labels consistentes: self-hosted, Windows, X64 (+ opcionais por ambiente).');

$pdf->Ln(1);
sectionTitle($pdf, '3) Workflow minimo por repositorio');
bullet($pdf, 'Usar runs-on com labels explicitas: [self-hosted, Windows, X64].');
bullet($pdf, 'Separar CI e deploy em jobs diferentes.');
bullet($pdf, 'No deploy: git fetch --all --prune, git checkout main, git pull --ff-only origin main.');
bullet($pdf, "Ativar fail-fast no PowerShell com \$ErrorActionPreference = 'Stop'.");
bullet($pdf, 'Validar o commit deployado no fim contra GITHUB_SHA.');

$pdf->Ln(1);
sectionTitle($pdf, '4) Evitar erro comum: dubious ownership');
bullet($pdf, 'Sintoma: detected dubious ownership in repository.');
bullet($pdf, 'Causa: repo pertence a um utilizador e o runner corre com outro utilizador.');
bullet($pdf, 'Correcao: usar git -c "safe.directory=C:/caminho/repo" nos comandos git do deploy.');

$pdf->Ln(1);
sectionTitle($pdf, '5) Regras para nao ter "verde" sem atualizar servidor');
bullet($pdf, 'Falhar o job se git/composer falhar.');
bullet($pdf, 'Ter verificacao final de commit deployado.');
bullet($pdf, 'Confirmar que o job de deploy correu (nao apenas CI).');

$pdf->Ln(1);
sectionTitle($pdf, '6) Checklist de diagnostico rapido');
bullet($pdf, 'Servico runner esta Running?');
bullet($pdf, 'Repo local esta behind? (git fetch --all --prune + git status -sb)');
bullet($pdf, 'HEAD local bate com origin/main?');
bullet($pdf, 'Logs do runner indicam Job completed: Failed ou Succeeded?');

$pdf->Ln(1);
sectionTitle($pdf, '7) Operacao diaria recomendada');
bullet($pdf, 'Commit/push dispara workflow.');
bullet($pdf, 'Runner executa CI e deploy.');
bullet($pdf, 'Se deploy falhar, validar sempre o job de deploy em separado.');
bullet($pdf, 'Reutilizar este mesmo padrao em todos os repositorios.');

$pdf->Ln(1);
sectionTitle($pdf, 'Comandos uteis (resumo)');
bullet($pdf, 'git fetch --all --prune');
bullet($pdf, 'git status -sb');
bullet($pdf, 'git rev-parse --short HEAD');
bullet($pdf, 'git rev-parse --short origin/main');
bullet($pdf, 'Get-Service actions.runner.* | Select Name,Status,StartType');
bullet($pdf, 'Get-ChildItem C:/actions-runner-*/_diag');

$pdf->Ln(3);
$pdf->SetFont('Arial', 'I', 10);
$pdf->MultiCell(0, 5, 'Gerado automaticamente em ' . date('Y-m-d H:i:s') . ' no servidor.');

$pdf->Output('F', $outFile);

echo "PDF gerado: " . $outFile . PHP_EOL;
