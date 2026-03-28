<?php
// Teste simples para verificar se a página carrega
echo "<h1>Teste de Carregamento da Página PID</h1>";
echo "<p>Se você vê esta página, o PHP está funcionando.</p>";

// Simular o que a página faz
$tank_id = 5;
$controller_ip = '192.168.1.100';

echo "<h2>Variáveis PHP:</h2>";
echo "<ul>";
echo "<li>tank_id: $tank_id</li>";
echo "<li>controller_ip: $controller_ip</li>";
echo "</ul>";

echo "<h2>Teste JavaScript:</h2>";
echo "<button onclick='testFunction()'>Testar Função</button>";
echo "<div id='test-result'></div>";

echo "<script>
function testFunction() {
    document.getElementById('test-result').innerHTML = '✅ JavaScript funcionando!';
}

function fetchPidSuggestions(days = 3) {
    document.getElementById('test-result').innerHTML = '✅ fetchPidSuggestions está definida!';
    return Promise.resolve();
}

// Teste automático
window.onload = function() {
    console.log('Página carregada');
    console.log('fetchPidSuggestions definida:', typeof fetchPidSuggestions);
};
</script>";
?>