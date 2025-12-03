<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.min.js"></script>
<script>
    function openDashboardInNewWindow() {
        // Define o URL para a sua página de dashboard
        const url = '/work_log/pools/dashboard.php';
        
        // Define um nome para a janela. Usar o mesmo nome fará com que o botão
        // reutilize a janela se ela já estiver aberta, em vez de abrir uma nova.
        const windowName = 'WorkLogDashboard';
        
        // Define as características da nova janela (tamanho, sem barras de ferramentas, etc.)
        const windowFeatures = 'width=1280,height=720,menubar=no,toolbar=no,location=no,resizable=yes,scrollbars=yes';
        
        // Abre a nova janela
        window.open(url, windowName, windowFeatures);
    }
</script>
</body>
</html>