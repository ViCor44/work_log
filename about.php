<?php
require_once 'public_header.php'; 
// Não precisamos de lógica de base de dados aqui, apenas do header e footer.
?>

<style>
    /* Imagem de fundo com um overlay escuro para legibilidade do texto */
    .hero-section {
        background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)), url('/work_log/images/c7a9801f-2e42-4a72-8918-8b8bebb0f903.webp'); /* Pode usar a mesma imagem ou outra */
        background-size: cover;
        background-position: center;
        color: white;
        padding: 6rem 0;
        text-align: center;
    }
    .hero-section h1 {
        font-weight: 700;
        font-size: 3.5rem;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
    }
    .hero-section p {
        font-size: 1.25rem;
        max-width: 800px;
        margin: 0 auto;
    }
    .section-title {
        text-align: center;
        margin-bottom: 3rem;
        font-weight: 700;
        color: #343a40;
    }
    .icon-box {
        font-size: 3rem;
        color: #0d6efd; /* Azul primário do Bootstrap */
        margin-bottom: 1rem;
    }
    .card-about {
        border: none;
        border-radius: 15px;
        transition: all 0.3s ease-in-out;
    }
    .card-about:hover {
        transform: translateY(-10px);
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    /* Estilos para a Linha do Tempo */
    .timeline {
        list-style: none;
        padding: 20px 0 20px;
        position: relative;
    }
    .timeline:before {
        top: 0;
        bottom: 0;
        position: absolute;
        content: " ";
        width: 3px;
        background-color: #e9ecef;
        left: 50%;
        margin-left: -1.5px;
    }
    .timeline > li {
        margin-bottom: 20px;
        position: relative;
    }
    .timeline > li:before, .timeline > li:after {
        content: " ";
        display: table;
    }
    .timeline > li:after {
        clear: both;
    }
    .timeline > li .timeline-panel {
        width: 45%;
        float: left;
        border: 1px solid #d4d4d4;
        border-radius: 8px;
        padding: 20px;
        position: relative;
        box-shadow: 0 1px 6px rgba(0, 0, 0, 0.05);
    }
    .timeline > li.timeline-inverted > .timeline-panel {
        float: right;
    }
    .timeline > li .timeline-badge {
        color: #fff;
        width: 50px;
        height: 50px;
        line-height: 50px;
        font-size: 1.4em;
        text-align: center;
        position: absolute;
        top: 16px;
        left: 50%;
        margin-left: -25px;
        background-color: #999999;
        z-index: 100;
        border-radius: 50%;
    }
    .timeline-badge.primary { background-color: #0d6efd !important; }
    .timeline-title { margin-top: 0; color: inherit; font-weight: bold; }
</style>

<div class="hero-section">
    <div class="container">
        <h1 class="display-4">A Nossa Missão: Excelência Invisível</h1>
        <p class="lead mt-3">Acreditamos que a melhor manutenção é aquela que ninguém vê. É a garantia silenciosa de que cada dia será seguro, limpo e inesquecível para os nossos visitantes.</p>
    </div>
</div>

<div class="container my-5">
    <section id="history" class="py-5">
        <h2 class="section-title">A Nossa Ferramenta, A Nossa Filosofia</h2>
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4>Nascido da Necessidade</h4>
                <p class="text-muted">O WorkLog CMMS não foi comprado. Foi forjado no terreno, nascido da necessidade diária de uma equipa que não se contenta com o "suficiente". Começou como uma solução simples para gerir tarefas e evoluiu para o cérebro das nossas operações de manutenção.</p>
                <h4>O Módulo de Piscinas</h4>
                <p class="text-muted">Este módulo de gestão de águas é o mais recente passo na nossa jornada de inovação. Ele representa o nosso compromisso total com a segurança e a qualidade, transformando dados complexos em informação clara e acionável, garantindo que a água que dá vida ao nosso parque é sempre de qualidade irrepreensível.</p>
            </div>
            <div class="col-md-6 text-center">
                <img src="/work_log/images/logo_worklog.png" class="img-fluid rounded" alt="Logotipo WorkLog CMMS">
            </div>
        </div>
    </section>

    <hr class="my-5">

    <section id="values" class="py-5">
        <h2 class="section-title">Os Pilares do Nosso Trabalho</h2>
        <div class="row text-center">
            <div class="col-lg-4 mb-4">
                <div class="card card-about h-100 p-4">
                    <div class="icon-box"><i class="fas fa-shield-alt"></i></div>
                    <h5 class="fw-bold">Segurança Inegociável</h5>
                    <p class="text-muted">Desde a estrutura de um escorrega à composição química da água, a segurança dos nossos visitantes e da nossa equipa é a nossa prioridade máxima e inegociável.</p>
                </div>
            </div>
            <div class="col-lg-4 mb-4">
                <div class="card card-about h-100 p-4">
                    <div class="icon-box"><i class="fas fa-cogs"></i></div>
                    <h5 class="fw-bold">Inovação Contínua</h5>
                    <p class="text-muted">Procuramos ativamente novas formas de melhorar a nossa eficiência e precisão. O WorkLog CMMS é um testemunho vivo dessa procura incessante pela ferramenta perfeita.</p>
                </div>
            </div>
            <div class="col-lg-4 mb-4">
                <div class="card card-about h-100 p-4">
                    <div class="icon-box"><i class="fas fa-check-circle"></i></div>
                    <h5 class="fw-bold">Rigor e Responsabilidade</h5>
                    <p class="text-muted">Cada registo, cada análise e cada relatório reflete o nosso profundo sentido de responsabilidade para com a qualidade da experiência que oferecemos.</p>
                </div>
            </div>
        </div>
    </section>	

    <section id="history" class="py-5">
        <h2 class="section-title">A Evolução do WorkLog CMMS</h2>
        <ul class="timeline">
            <li>				
	        	<div class="timeline-badge primary"><i class="fas fa-play"></i></div>
				<div class="timeline-panel">
					<div class="card card-about h-100 p-4">	
	                    <div class="timeline-heading">
	                        <h4 class="timeline-title">Conceito Inicial</h4>
	                        <p><small class="text-muted">Início do Projeto</small></p>
	                    </div>
	                    <div class="timeline-body">						
	                        <p  class="text-muted">O WorkLog CMMS nasce da necessidade de um sistema de gestão de manutenção mais ágil e focado nas operações diárias, começando com a gestão de ativos e ordens de trabalho.</p>
	                 </div>
				</div>
                </div>
            </li>
            <li class="timeline-inverted">
                <div class="timeline-badge primary"><i class="fas fa-swimming-pool"></i></div>
                <div class="timeline-panel">
					<div class="card card-about h-100 p-4">
                    <div class="timeline-heading">
                        <h4 class="timeline-title">Módulo de Piscinas</h4>
                    </div>
                    <div class="timeline-body">
                        <p>Desenvolvimento do módulo especializado para a gestão de tanques e piscinas, com registos de análises e consumos, e relatórios PDF profissionais.</p>
                    </div>
				</div>
                </div>
            </li>
            <li>
                <div class="timeline-badge primary"><i class="fas fa-tachometer-alt"></i></div>
                <div class="timeline-panel">
					<div class="card card-about h-100 p-4">
                    <div class="timeline-heading">
                        <h4 class="timeline-title">Monitorização em Tempo Real</h4>
                    </div>
                    <div class="timeline-body">
                        <p>Criação da API e do Dashboard de Controladores, permitindo a visualização de dados em tempo real (pH, Cloro, Temperatura) e alertas visuais para parâmetros fora dos limites.</p>
                    </div>
				</div>
                </div>
            </li>
             <li class="timeline-inverted">
                <div class="timeline-badge primary"><i class="fas fa-cogs"></i></div>
                <div class="timeline-panel">
					<div class="card card-about h-100 p-4">
                    <div class="timeline-heading">
                        <h4 class="timeline-title">Estado Atual e Futuro</h4>
                    </div>
                    <div class="timeline-body">
                        <p>O sistema está agora numa fase madura, com funcionalidades robustas de registo, consulta, edição e auditoria. Os próximos passos focam-se na contínua melhoria da experiência do utilizador e na expansão para outras áreas operacionais.</p>
                    </div>
					</div>
                </div>
            </li>
        </ul>
    </section>

	<div class="text-center py-5">
        <a href="javascript:history.back()" class="btn btn-lg btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Voltar
        </a>
    </div>
</div>

<?php
require_once 'public_footer.php';
?>