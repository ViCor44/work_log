<?php
require_once __DIR__ . '/../fpdf/fpdf.php';

$outFile = __DIR__ . '/../Guia_Actions_Runner_Outros_Repos.pdf';

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'Guia Rapido - GitHub Actions Runner (Windows)', 0, 1, 'L');

$pdf->SetFont('Arial', '', 11);
$pdf->MultiCell(0, 6, 'Objetivo: aplicar o mesmo padrao de deploy automatico em outros repositorios, com diagnostico rapido quando nao atualiza no servidor.');
$pdf->Ln(2);

function sectionTitle(FPDF $pdf, $text) {
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->Cell(0, 8, $text, 0, 1, 'L');
    $pdf->SetFont('Arial', '', 11);
}

function bullet(FPDF $pdf, $text) {
    $pdf->MultiCell(0, 6, '- ' . $text);
}

sectionTitle($pdf, '1) Verificacoes base no servidor');
bullet($pdf, 'Confirmar servico do runner: Get-Service actions.runner.*');
bullet($pdf, 'Confirmar processo ativo: Runner.Listener / RunnerService');
bullet($pdf, 'Confirmar repositorio alvo: git remote -v e branch correta');
bullet($pdf, 'Confirmar estado local vs remoto: git fetch --all --prune e git status -sb');

$pdf->Ln(1);
sectionTitle($pdf, '2) Workflow recomendado (.github/workflows/*.yml)');
bullet($pdf, 'Usar labels explicitas: runs-on: [self-hosted, Windows, X64]');
bullet($pdf, 'Deploy com fast-forward estrito: git pull --ff-only origin main');
bullet($pdf, "Ativar falha estrita no PowerShell: \$ErrorActionPreference = 'Stop'");
bullet($pdf, 'Validar exit code apos comandos criticos (git/composer)');
bullet($pdf, 'Validar commit deployado no fim (merge-base --is-ancestor)');

$pdf->Ln(1);
sectionTitle($pdf, '3) Erro comum: dubious ownership (Windows service account)');
bullet($pdf, 'Sintoma: detected dubious ownership in repository');
bullet($pdf, 'Causa: repo pertence a um utilizador, runner corre com outro (ex: Network Service)');
bullet($pdf, 'Correcao no workflow: usar git -c "safe.directory=C:/caminho/repo" em todos os comandos git do deploy');

$pdf->Ln(1);
sectionTitle($pdf, '4) Check rapido quando Actions esta verde mas servidor nao atualiza');
bullet($pdf, 'Ver se o job de deploy realmente correu (nao apenas CI)');
bullet($pdf, 'Ler _diag do runner e confirmar passo Deploy/Verify');
bullet($pdf, 'No servidor: comparar HEAD local com origin/main');
bullet($pdf, 'Se estiver behind: validar porque deploy nao aplicou pull');

$pdf->Ln(1);
sectionTitle($pdf, '5) Comandos de diagnostico recomendados');
bullet($pdf, 'git fetch --all --prune');
bullet($pdf, 'git status -sb');
bullet($pdf, 'git rev-parse --short HEAD');
bullet($pdf, 'git rev-parse --short origin/main');
bullet($pdf, 'Get-Service actions.runner.* | Select Name,Status,StartType');
bullet($pdf, 'Get-ChildItem C:/actions-runner-*/_diag');

$pdf->Ln(1);
sectionTitle($pdf, '6) Padrao de hardening que deves replicar');
bullet($pdf, 'Deploy step com fail-fast em todos os comandos');
bullet($pdf, 'Verificacao final de commit esperado ou mais recente');
bullet($pdf, 'Sem git reset --hard no deploy');
bullet($pdf, 'Logs claros no workflow para debug rapido');

$pdf->Ln(3);
$pdf->SetFont('Arial', 'I', 10);
$pdf->MultiCell(0, 5, 'Gerado automaticamente em ' . date('Y-m-d H:i:s') . ' no servidor.');

$pdf->Output('F', $outFile);

echo "PDF gerado: " . $outFile . PHP_EOL;
