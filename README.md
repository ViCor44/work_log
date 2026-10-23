# WorkLog CMMS

WorkLog CMMS é um sistema de Gestãoo de Manutenção Computadorizado (CMMS) desenvolvido em PHP e MySQL. Ele oferece funcionalidades para rastrear e gerir ativos, ordens de trabalho, relatórios e mensagens entre utilizadores, promovendo eficiência e organização na gestão de manutenção.

## Funcionalidades

- **Gestão de Ativos**:
  - Cadastro de ativos com informações detalhadas.
  - Upload de fotos e manuais.
  - Geração automática de QR Codes para acesso rápido aos detalhes do ativo.

- **Ordens de Trabalho**:
  - Criação e gestão de ordens de manutenção preventiva e corretiva.
  - Atribuição de ordens a utilizadores específicos.
  - Notificações automáticas ao criar ou atualizar ordens de trabalho.
  - Alteração de status e registo de ações no histórico da ordem.

- **Mensagens Internas**:
  - Sistema de mensagens entre utilizadores para facilitar a comunicação.

- **Relatórios**:
  - Criação e visualização de relatórios de manutenção, avarias, diários e medidas de autoproteção.
  - Exportação em formato PDF.

- **Gestão de Utilizadores**:
  - Registo de novos utilizadores com validação e aceitação por administradores.
  - Hierarquias de acesso para garantir segurança.

- **Estatísticas e Painel**:
  - Painel com estatísticas e gráficos para análise rápida do desempenho e status do sistema.

- **Logs de atividade**:
  - São mantidas apenas as 1000 entradas mais recentes na tabela `logs`; as anteriores são eliminadas definitivamente ao gravar uma atividade ou abrir a página de logs.
  - A listagem carrega apenas uma página de 50 entradas por pedido. Os filtros pesquisam apenas os registos mantidos.
  - Esta retenção não elimina os ficheiros de diagnóstico dos workers nem outros históricos da aplicação.

## Instalação

1. Clone o repositório:
    ```bash
    git clone https://github.com/ViCor44/work_log.git
    
 2. Configure o banco de dados:
  - Crie uma base de dados no MySQL.
  - Importe o arquivo database.sql localizado na raiz do projeto.

3. Atualize as configurações:
  - Edite dB.php com as informações do banco de dados (utilizador, senha, nome do banco, etc.).
    
4. Inicie o servidor:
  - Use o PHP Built-in Server:
    ```bash    
    php -S localhost:8000
    
5. Acesse o sistema no navegador:
  - URL: http://localhost:8000

## Uso

### Bloqueio automatico de dosagem

Na pagina **Configuracao de alarmes**, os limites de variacao absoluta de cloro
(mg/L) e pH podem ser definidos globalmente ou por controlador. Cada controlador
pode herdar a configuracao global, usar limites proprios ou desativar a deteccao.
A funcionalidade esta desligada por defeito e exige ambos os limites positivos.

O worker `scripts/fetch_controller_data.php` compara as leituras validas consecutivas
que recolhe (nao todas as leituras internas do Arduino). Uma variacao **superior**
ao limite de qualquer parametro envia `/relay/on`. Uma leitura invalida interrompe
a comparacao desse parametro; a primeira leitura valida estabelece a referencia.
Falhas de envio ficam pendentes para nova tentativa no ciclo seguinte, com auditoria.
E necessario executar regularmente este worker e instalar firmware compativel;
esta protecao depende da rede e nao substitui os interbloqueios locais do equipamento.

Nao ha desbloqueio automatico: o **Reset** manual envia `/relay/off` e limpa a
referencia de comparacao apenas depois da confirmacao do controlador. Alterar ou
desativar a configuracao nao remove bloqueios existentes nem tentativas pendentes.
O setpoint dinamico nao e aplicado enquanto existir bloqueio automatico,
bloqueio indicado por `digitalIn=1`, ou uma falha na avaliacao da protecao.

1. Faça login com o administrador padrão:
  - Usuário: admin
  - Senha: admin

2. Configure o sistema conforme necessário
  - Adicione usuários, ativos e ordens de trabalho.
  - Personalize as configurações para atender às necessidades da sua organização.
    
## Tecnologias Utilizadas
  - Backend: PHP 8+
  - Base de Dados: MySQL
  - Frontend: Bootstrap 5
  - Bibliotecas:
    - phpqrcode para geração de QR Codes.
    - 
## Contribuição
Faça um fork do projeto.
  - Crie um branch para suas alterações:
    ```bash
    git checkout -b feature/nova-funcionalidade
  - Envie um pull request.
    
## Licença
  Este projeto está licenciado sob a MIT License.

## Doações
  Caso este projeto lhe seja util a si ou à sua empresa, considere efectuar uma doação 
  para apoiar o desenvolvimento contínuo

   [![Doe com PayPal](https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif)](https://www.paypal.com/donate?business=victor.a.correia@gmail.com)

---

Caso precise de alterações específicas ou detalhes adicionais, avise-me! 😊
Para dúvidas ou sugestões, entre em contato pelo e-mail: victor.a.correia@gmail.com.
