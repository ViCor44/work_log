# WorkLog CMMS - Sistema de Gestão de Manutenção

## 1. Visão Geral do Projeto

O WorkLog CMMS é um sistema web desenvolvido em PHP e MySQL para a gestão centralizada de operações de manutenção. O sistema inclui módulos para a gestão de ativos, ordens de trabalho, relatórios, e um módulo especializado na monitorização de tanques e piscinas.

O objetivo é digitalizar e centralizar os registos diários, automatizar cálculos, gerar relatórios profissionais e fornecer ferramentas de monitorização em tempo real para otimizar a eficiência e a segurança das operações.

## 2. Funcionalidades Principais

### Core CMMS
* **Gestão de Utilizadores**: Sistema de login seguro com perfis e aprovação de novas contas.
* **Gestão de Ativos**: Sistema avançado com categorias e tipos de ativos personalizáveis.
* **Ordens de Trabalho**: Criação, atribuição e monitorização de ordens de trabalho com histórico detalhado.
* **Comunicação**: Sistema de mensagens internas com notificações.

### Módulo de Piscinas e Tanques
* **Configuração de Tanques**: Interface para criar e gerir tanques, definindo propriedades como tipo, frequência de leituras, e se possui controlador com IP.
* **Entrada de Dados Otimizada**: Formulários em grelha para inserção rápida de dados (análises, leituras de água/hipoclorito) com navegação por teclado e validações.
* **Relatórios Detalhados (Web e PDF)**: Painel central com acesso a:
    * Boletim de Análises Diárias (PDF).
    * Relatórios de Consumo Mensal (Hipoclorito, Água das Piscinas, Rede, etc.).
    * Relatório Semanal Consolidado de todos os contadores.
    * Relatório de Renovação de Água por piscina.
* **Dashboard Dinâmico**: Página inicial com um "ticker" de estado que se atualiza em tempo real via API.
* **Monitorização Detalhada**: Ecrã de detalhe para cada piscina com manómetros (gauges) e gráficos de histórico em tempo real.
* **API de Dados**: Ponto de API (`api/latest_status.php`) que expõe os últimos registos em formato JSON.

## 3. Arquitetura e Tecnologias

* **Backend**: PHP (Compatível com versões 5.6+).
* **Frontend**: HTML5, CSS3, JavaScript (vanilla).
* **Base de Dados**: MySQL / MariaDB.
* **Framework CSS**: Bootstrap 5.
* **Geração de PDF**: Biblioteca **FPDF**.
* **Gráficos**: Bibliotecas **Chart.js** e **chartjs-gauge**.

## 4. Guia de Instalação

1.  Copie a pasta do projeto para o diretório do seu servidor web (ex: `C:\xampp\htdocs\worklog`).
2.  Crie uma base de dados no seu gestor MySQL (ex: phpMyAdmin).
3.  Importe o ficheiro `schema.sql` para criar todas as tabelas.
4.  Configure as suas credenciais de acesso no ficheiro `db.php`.
5.  Certifique-se de que a biblioteca FPDF está presente na pasta `fpdf/`.

## 5. Estrutura de Pastas (Simplificada)

```
/work_log/
├── admin/
│   └── view_logs.php
├── api/
│   └── latest_status.php, get_pool_history.php
├── assets/
│   └── (logotipos, imagens)
├── fpdf/
│   └── (biblioteca FPDF)
├── pools/
│   ├── (todos os ficheiros do módulo de piscinas)
├── scripts/
│   └── fetch_controller_data.php
├── uploads/
│   └── (fotos, manuais, qrcodes)
├── core.php, db.php, header.php, footer.php, navbar.php
└── index.php, login.php, ...
```