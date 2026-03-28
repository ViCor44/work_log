<?php
// É uma boa prática definir o código de status HTTP correto.
// 404 significa 'Not Found', o que é apropriado mesmo para uma página "em construção".
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página em Construção - WorkLog CMMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            background: url('images/c7a9801f-2e42-4a72-8918-8b8bebb0f903.webp') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            color: #343a40; /* Cor escura para texto sobre fundo claro */
        }

        .construction-card {
            background-color: rgba(255, 255, 255, 0.95); /* Fundo branco semi-transparente para o cartão */
            padding: 3rem;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            max-width: 600px;
            width: 100%;
        }

        .construction-icon {
            font-size: 5rem;
            color: #ffc107; /* Cor de aviso (amarelo) */
            animation: spin 4s linear infinite;
        }

        h1 {
            font-weight: 700;
            margin-top: 1.5rem;
        }

        .lead {
            font-size: 1.25rem;
            color: #6c757d;
        }

        /* Animação para o ícone */
        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body>

    <div class="container text-center">
        <div class="construction-card">
            <div class="construction-icon">
                <i class="fas fa-tools"></i>
            </div>

            <h1>Página em Construção</h1>

            <p class="lead mt-3">A funcionalidade que tentou aceder ainda não está pronta.</p>
            <p>A nossa equipa está a trabalhar arduamente para a disponibilizar o mais brevemente possível. Agradecemos a sua compreensão!</p>

            <a href="javascript:history.back()" class="btn btn-primary btn-lg mt-4">
                <i class="fas fa-home"></i> Voltar à Página Inicial
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
</body>

</html>