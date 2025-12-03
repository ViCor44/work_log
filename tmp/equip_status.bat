@echo off
REM ============================================================================
REM Ficheiro Batch para executar o script de verificação de estado dos equipamentos
REM WorkLog CMMS
REM ============================================================================

ECHO [ %TIME% ] Iniciando a tarefa de verificação de equipamentos...

REM --- CONFIGURAÇÃO ---
REM Altere os caminhos abaixo para corresponderem à sua instalação.

REM 1. Caminho completo para o executável php.exe do seu XAMPP
SET PHP_EXE="C:\xampp\php\php.exe"

REM 2. Caminho completo para a pasta raiz do seu projeto WorkLog
SET PROJECT_PATH="C:\xampp\htdocs\work_log"

REM --- EXECUÇÃO ---
REM O comando 'cd /d' muda o diretório de trabalho para a pasta do projeto.
REM Isto é crucial para que o script PHP encontre o ficheiro 'db.php' corretamente.
cd /d %PROJECT_PATH%

REM Executa o script PHP em background. A opção '-f' especifica o ficheiro a ser executado.
%PHP_EXE% -f check_equipment_status.php

ECHO [ %TIME% ] Tarefa concluída.
```

### Como Utilizar

Agora, a configuração no **Agendador de Tarefas do Windows** fica muito mais simples:

1.  Guarde o ficheiro `run_check_equipment.bat` na pasta principal do seu projeto (ex: `C:\xampp\htdocs\work_log`).
2.  Abra o **Agendador de Tarefas** e edite a tarefa que criámos anteriormente.
3.  Vá ao separador **"Ações"** e clique em "Editar...".
4.  Agora, no campo **"Programa/script"**, em vez de apontar para o `php.exe`, aponte para o seu novo ficheiro `.bat`:
    ```
    "C:\xampp\htdocs\work_log\run_check_equipment.bat"
    
