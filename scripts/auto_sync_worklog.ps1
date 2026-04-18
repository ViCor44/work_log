$ErrorActionPreference = 'Stop'

$repoPath = 'C:\xampp\htdocs\work_log'
$logPath = 'C:\xampp\htdocs\work_log\logs\git_autosync.log'
$timestamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'

function Write-Log {
    param([string]$Message)
    Add-Content -Path $logPath -Value "[$timestamp] $Message"
}

try {
    if (-not (Test-Path $repoPath)) {
        Write-Log 'ERRO: repositorio nao encontrado.'
        exit 1
    }

    Push-Location $repoPath

    $insideRepo = git rev-parse --is-inside-work-tree 2>$null
    if ($LASTEXITCODE -ne 0 -or $insideRepo -ne 'true') {
        Write-Log 'ERRO: pasta nao e um repositorio git valido.'
        Pop-Location
        exit 1
    }

    $status = git status --porcelain
    if ($status) {
        Write-Log 'AVISO: repositorio com alteracoes locais; sync ignorado para evitar conflitos.'
        Pop-Location
        exit 0
    }

    git fetch --all --prune | Out-Null
    git checkout main | Out-Null
    git pull --ff-only origin main | Out-Null

    $head = git rev-parse --short HEAD
    Write-Log "OK: sincronizado para commit $head"

    Pop-Location
    exit 0
}
catch {
    Write-Log ("ERRO: " + $_.Exception.Message)
    if (Get-Location) {
        Pop-Location
    }
    exit 1
}
