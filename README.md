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
