<?php
require_once 'db.php'; 

$all_equipment = [];
$sql = "SELECT rem.id, rem.name, rem.ip_address, rem.slave_id, cat.name AS category_name FROM remote_equipment AS rem LEFT JOIN categories AS cat ON rem.category_id = cat.id ORDER BY cat.name, rem.name";
$result = $conn->query($sql);
if ($result) {
    $all_equipment = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Conteúdo do Dashboard SCADA</title>
    <style>
        @keyframes pulse-red { 0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 50% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); } }
        .animate-pulse-red { animation: pulse-red 2s infinite; }
        .equipment-title-link { cursor: pointer; transition: color 0.2s; }
        .equipment-title-link:hover { color: #64ffda; }
        .modal-details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
        .detail-item { padding: 1rem; background-color: rgba(0,0,0,0.2); border-radius: 0.375rem; }
        .detail-item h6 { font-size: 0.9rem; color: #94a3b8; font-weight: bold; margin-bottom: 1rem; border-bottom: 1px solid #475569; padding-bottom: 0.5rem; text-transform: uppercase; }
        .detail-item .value-pair { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.5rem; font-size: 0.95rem; }
        .detail-item .value-pair-top { align-items: flex-start; }
    </style>
</head>
<body class="bg-slate-900 p-4 font-sans text-slate-300">
    
    <div id="loading-message" class="text-center p-4 text-slate-400 font-semibold">
        🔍 A ler equipamentos registados...
    </div>

    <div class="flex flex-col gap-4">
        <ul class="flex flex-wrap text-sm font-medium text-center text-gray-500 border-b border-gray-700" id="category-tabs"></ul>
        <div id="category-tab-content"></div>
    </div>

<div class="modal fade" id="equipmentLogModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content" style="background-color: #1e293b; color: #cbd5e1;">
            <div class="modal-header" style="border-bottom-color: #334155;">
                <h5 class="modal-title" id="equipmentLogModalLabel">Detalhes Rápidos</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center">
                    <div class="spinner-border" role="status"><span class="visually-hidden">A carregar...</span></div>
                </div>
            </div>
            <div class="modal-footer" style="border-top-color: #334155;">
                <a href="#" id="full-history-btn" class="btn btn-primary me-auto">Ver Histórico Completo</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
    const allEquipmentFromServer = <?php echo json_encode($all_equipment); ?>;
    const UPDATE_INTERVAL = 15000;
    let onlineEquipments = [];

    const iconRunning = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd" /></svg>`;
    const iconStopped = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8 7a1 1 0 00-1 1v4a1 1 0 001 1h4a1 1 0 001-1V8a1 1 0 00-1-1H8z" clip-rule="evenodd" /></svg>`;
    const iconFault = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>`;
    const iconTBS = `<svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" /><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" /></svg>`;
    const iconTachometer = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>`;
    const faultMap = { 0: 'Sem falha', 1: 'Proteção Inibida', 2: 'Falha Interna', 3: 'Curto-circuito/Sobrecorrente', 4: 'Inversão de Fase', 5: 'Falha Comunicação Linha', 6: 'Falha Externa', 7: 'Arranque Excessivo', 8: 'Falha de Tensão', 9: 'Falha de Fase', 10: 'Sobreaquecimento', 11: 'Rotor Bloqueado', 12: 'Sobrecarga Térmica', 13: 'Falha Frequência', 14: 'Sub-carga Motor', 15: 'Falha EEPROM', 16: 'Sobrecarga Corrente', 17: 'Config. Inválida', 18: 'Falha Térmica (PTC)', 20: 'Config. Inválida (Reset)', 21: 'Perda Alimentação Controlo' };

    function createSlaveCard(equipment) {
        return `
            <div id="statusCard-${equipment.slave_id}" data-ip="${equipment.ip_address}" class="bg-slate-800 rounded-lg shadow-lg border-t-4 border-slate-600 p-4 flex flex-col gap-3 transition-all h-full">
                <div class="flex justify-between items-center">
                    <h1 class="text-xl font-bold text-slate-100 equipment-title-link" data-bs-toggle="modal" data-bs-target="#equipmentLogModal" data-equipment-id="${equipment.id}" data-equipment-name="${equipment.name}" title="Ver detalhes rápidos">
                        ${equipment.name}
                    </h1>
                    <span id="onlineStatus-${equipment.slave_id}" class="text-base font-semibold px-3 py-1 rounded-full bg-slate-700 text-slate-400">A verificar...</span>
                </div>
                <div class="bg-slate-900/50 p-3 rounded-md flex items-center justify-between">
                    <span class="text-base text-slate-400">ESTADO</span>
                    <div class="flex items-center gap-2">
                        <span id="equipmentState-${equipment.slave_id}" class="text-xl font-bold">-</span>
                        <div id="icon-container-${equipment.slave_id}" class="text-slate-400"></div>
                    </div>
                </div>
                <div class="bg-slate-900/50 p-3 rounded-md flex items-center justify-between">
                    <div class="flex items-center gap-2 text-base text-slate-400">${iconTachometer} <span>MEDIÇÕES</span></div>
                    <span id="measurements-${equipment.slave_id}" class="font-mono font-bold text-xl text-slate-200">- / -</span>
                </div>
                <div id="fault-container-${equipment.slave_id}" class="bg-red-900/30 p-2 rounded-md text-center" style="display: none;">
                    <p class="text-base text-red-400 font-semibold">ÚLTIMA FALHA:</p>
                    <p id="faultDesc-${equipment.slave_id}" class="text-lg text-red-300 font-bold mt-1">-</p>
                </div>
                <div class="mt-auto grid gap-2">
                    <div id="runStopContainer-${equipment.slave_id}" class="grid grid-cols-2 gap-2">
                        <button id="runBtn-${equipment.slave_id}" onclick="sendCommand('/run', ${equipment.slave_id}, '${equipment.ip_address}')" class="py-2 px-2 rounded-md text-white font-semibold text-base bg-green-600 hover:bg-green-700 disabled:opacity-40 disabled:cursor-not-allowed">RUN</button>
                        <button id="stopBtn-${equipment.slave_id}" onclick="sendCommand('/stop', ${equipment.slave_id}, '${equipment.ip_address}')" class="py-2 px-2 rounded-md text-white font-semibold text-base bg-yellow-500 hover:bg-yellow-600" style="display: none;">STOP</button>
                    </div>
                    <button id="clearFaultButton-${equipment.slave_id}" onclick="sendCommand('/clear_fault', ${equipment.slave_id}, '${equipment.ip_address}')" class="py-2 px-2 rounded-md text-white font-semibold text-base bg-blue-600 hover:bg-blue-700" style="display: none;">LIMPAR FALHA</button>
                </div>
            </div>
        `;
    }
    
    function updateCardUI(slaveId, data) { const card = document.getElementById(`statusCard-${slaveId}`); const onlineStatus = document.getElementById(`onlineStatus-${slaveId}`); const equipmentState = document.getElementById(`equipmentState-${slaveId}`); const iconContainer = document.getElementById(`icon-container-${slaveId}`); const runBtn = document.getElementById(`runBtn-${slaveId}`); const stopBtn = document.getElementById(`stopBtn-${slaveId}`); const runStopContainer = document.getElementById(`runStopContainer-${slaveId}`); const clearFaultBtn = document.getElementById(`clearFaultButton-${slaveId}`); const faultContainer = document.getElementById(`fault-container-${slaveId}`); const faultDesc = document.getElementById(`faultDesc-${slaveId}`); const measurements = document.getElementById(`measurements-${slaveId}`); card.classList.remove('border-green-500', 'border-yellow-500', 'border-red-500', 'border-blue-500', 'border-slate-600', 'animate-pulse-red'); equipmentState.classList.remove('text-green-400', 'text-yellow-400', 'text-red-400', 'text-blue-400'); onlineStatus.classList.remove('bg-green-800', 'text-green-400'); onlineStatus.textContent = 'Online'; onlineStatus.classList.add('bg-green-800', 'text-green-400'); faultContainer.style.display = 'none'; clearFaultBtn.style.display = 'none'; runStopContainer.style.display = 'grid'; runStopContainer.classList.remove('grid-cols-1', 'grid-cols-2'); if (data.activeFault) { card.classList.add('border-red-500', 'animate-pulse-red'); equipmentState.textContent = 'FALHA ATIVA'; equipmentState.classList.add('text-red-400'); iconContainer.innerHTML = iconFault; faultContainer.style.display = 'block'; clearFaultBtn.style.display = 'block'; runStopContainer.style.display = 'none'; } else if (data.isTBS) { card.classList.add('border-blue-500'); equipmentState.textContent = 'PARADO (TBS)'; equipmentState.classList.add('text-blue-400'); iconContainer.innerHTML = iconTBS; runStopContainer.classList.add('grid-cols-2'); } else if (data.isRunning) { card.classList.add('border-green-500'); equipmentState.textContent = 'A FUNCIONAR'; equipmentState.classList.add('text-green-400'); iconContainer.innerHTML = iconRunning; runStopContainer.classList.add('grid-cols-1'); } else { card.classList.add('border-yellow-500'); equipmentState.textContent = 'PARADO'; equipmentState.classList.add('text-yellow-400'); iconContainer.innerHTML = iconStopped; runStopContainer.classList.add('grid-cols-2'); } const currentA = (data.currentRaw !== undefined) ? data.currentRaw.toFixed(1) : '-'; const V_LL = 400, SQRT3 = Math.sqrt(3), PF = 0.8; let powerCalc = '-'; if (currentA !== '-' && parseFloat(currentA) > 0) { powerCalc = ((V_LL * parseFloat(currentA) * SQRT3 * PF) / 1000).toFixed(2); } measurements.textContent = `${currentA} A / ${powerCalc} kW`; runBtn.style.display = data.isRunning ? 'none' : 'block'; stopBtn.style.display = data.isRunning ? 'block' : 'none'; if (data.isRunning) { runStopContainer.classList.add('grid-cols-1'); runStopContainer.classList.remove('grid-cols-2'); } else { runStopContainer.classList.add('grid-cols-2'); runStopContainer.classList.remove('grid-cols-1'); runBtn.style.display = 'block'; stopBtn.style.display = 'none'; } runBtn.disabled = data.activeFault || data.isTBS; const faultNum = parseInt(data.faultHex, 16); faultDesc.textContent = faultMap[faultNum] || `Código: ${faultNum}`; }
    function handleUpdateError(slaveId) { const card=document.getElementById(`statusCard-${slaveId}`); if(card) { card.style.borderTopColor = '#64748b'; card.style.opacity = '0.7'; const onlineStatus = document.getElementById(`onlineStatus-${slaveId}`); onlineStatus.textContent = 'Offline'; onlineStatus.classList.remove('bg-green-800', 'text-green-400'); onlineStatus.classList.add('bg-red-800', 'text-red-400'); document.getElementById(`equipmentState-${slaveId}`).textContent = "SEM COMUNICAÇÃO"; }}
    async function sendCommand(path, slaveId, ipAddress) { try { await fetch(`remote_command_api.php?command=${path.substring(1)}&slave_id=${slaveId}&ip=${ipAddress}`); setTimeout(() => updateStatus({ slave_id: slaveId, ip_address: ipAddress }), 250); } catch(e) { alert(`Erro: ${e.message}`); handleUpdateError(slaveId); } }
    async function updateStatus(equipment) { try { const response = await fetch(`http://${equipment.ip_address}/api/status/${equipment.slave_id}`); if (!response.ok) throw new Error("Network"); const data = await response.json(); updateCardUI(equipment.slave_id, data); } catch(error) { handleUpdateError(equipment.slave_id); } }
    
    async function updateAllStatuses() {
        for (const equipment of onlineEquipments) {
            await updateStatus(equipment);
        }
    }

    async function initializeDashboard() {
        const loadingMsg = document.getElementById('loading-message');
        const tabsContainer = document.getElementById('category-tabs');
        const contentContainer = document.getElementById('category-tab-content');
        try {
            if (!allEquipmentFromServer || allEquipmentFromServer.length === 0) { 
                loadingMsg.textContent = 'Nenhum equipamento registado.'; 
                return; 
            }
            
            loadingMsg.textContent = `🔍 A verificar ${allEquipmentFromServer.length} equipamentos...`;

            const groupedByCategory = allEquipmentFromServer.reduce(((acc, eq) => { const category = eq.category_name || 'Sem Casa de Máquinas'; (acc[category] = acc[category] || []).push(eq); return acc; }), {});
            let isFirstTab = true;
            for (const categoryName in groupedByCategory) {
                const categoryId = "tab-" + categoryName.replace(/\s+/g, '-').toLowerCase();
                tabsContainer.innerHTML += `<li class="mr-2"><button class="inline-block p-4 border-b-2 rounded-t-lg ${isFirstTab ? 'text-blue-500 border-blue-500' : 'border-transparent hover:text-gray-300 hover:border-gray-500'}" data-tab-target="#${categoryId}">${categoryName}</button></li>`;
                // ALTERAÇÃO: Menos colunas para cards mais largos
                contentContainer.innerHTML += `<div class="${isFirstTab ? '' : 'hidden'}" id="${categoryId}"><div id="grid-${categoryId}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"></div></div>`;
                isFirstTab = false;
            }
            
            tabsContainer.addEventListener('click', (e) => { if(e.target.tagName === 'BUTTON') { tabsContainer.querySelectorAll('button').forEach(b => { b.classList.remove('text-blue-500', 'border-blue-500'); b.classList.add('border-transparent', 'hover:text-gray-300', 'hover:border-gray-500'); }); e.target.classList.add('text-blue-500', 'border-blue-500'); contentContainer.querySelectorAll('div[id^="tab-"]').forEach(p => p.classList.add('hidden')); document.querySelector(e.target.dataset.tabTarget).classList.remove('hidden'); } });
            
            for (const equipment of allEquipmentFromServer) {
                try {
                    const pingResponse = await fetch(`http://${equipment.ip_address}/ping/${equipment.slave_id}`);
                    const pingData = await pingResponse.json();
                    if (pingData.success) {
                        onlineEquipments.push(equipment);
                        const categoryName = equipment.category_name || 'Sem Casa de Máquinas';
                        const categoryId = "tab-" + categoryName.replace(/\s+/g, '-').toLowerCase();
                        document.getElementById(`grid-${categoryId}`).innerHTML += createSlaveCard(equipment);
                    }
                } catch (e) { console.error(`Ping falhou para ${equipment.name}`); }
            }

            loadingMsg.style.display = 'none';
            if (onlineEquipments.length > 0) { 
                updateAllStatuses(); 
                setInterval(updateAllStatuses, UPDATE_INTERVAL); 
            }
        } catch (error) { 
            loadingMsg.textContent = `Erro: ${error.message}`;
            loadingMsg.classList.add('text-red-400');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const equipmentLogModal = document.getElementById('equipmentLogModal');
        const formatModalTimestamp = (timestamp) => {
            if (!timestamp) return 'N/A';
            const date = new Date(timestamp);
            return `${date.toLocaleDateString('pt-PT')}<br>${date.toLocaleTimeString('pt-PT')}`;
        };

        if (equipmentLogModal) {
            equipmentLogModal.addEventListener('show.bs.modal', async function (event) {
                const triggerElement = event.relatedTarget;
                const equipmentId = triggerElement.dataset.equipmentId;
                const equipmentName = triggerElement.dataset.equipmentName;
                const modalTitle = equipmentLogModal.querySelector('.modal-title');
                const modalBody = equipmentLogModal.querySelector('.modal-body');
                const fullHistoryBtn = document.getElementById('full-history-btn');

                modalTitle.textContent = 'Detalhes Rápidos - ' + equipmentName;
                fullHistoryBtn.href = `view_equipment_details.php?id=${equipmentId}`;
                modalBody.innerHTML = `<div class="text-center p-4"><div class="spinner-border text-light" role="status"></div></div>`;

                try {
                    const response = await fetch(`api/get_details_for_modal.php?id=${equipmentId}`);
                    const data = await response.json();
                    if (data.error) throw new Error(data.error);

                    let liveStatusHtml = '<div class="detail-item"><h6>Estado em Tempo Real</h6><p class="text-center text-slate-400">Não foi possível ler.</p></div>';
                    if (data.live_status) {
                        const ls = data.live_status;
                        let stateText = 'Desconhecido';
                        if (ls.activeFault) stateText = 'FALHA'; else if (ls.isRunning) stateText = ls.isTBS ? 'A FUNCIONAR (TBS)' : 'A FUNCIONAR'; else stateText = ls.isTBS ? 'PARADO (TBS)' : 'PARADO';
                        const current = ls.currentRaw ? ls.currentRaw.toFixed(1) : '-';
                        const V_LL = 400, SQRT3 = Math.sqrt(3), PF = 0.8;
                        let power = '-';
                        if (current !== '-' && parseFloat(current) > 0) { power = ((V_LL * parseFloat(current) * SQRT3 * PF) / 1000).toFixed(2); }
                        liveStatusHtml = `<div class="detail-item"><h6>Estado em Tempo Real</h6><div class="value-pair"><span class="text-slate-400">Estado:</span> <span class="font-bold">${stateText}</span></div><div class="value-pair"><span class="text-slate-400">Corrente:</span> <span class="font-bold">${current} A</span></div><div class="value-pair"><span class="text-slate-400">Potência:</span> <span class="font-bold">${power} kW</span></div></div>`;
                    }

                    let lastCommandHtml = '<div class="detail-item"><h6>Último Comando</h6><p class="text-center text-slate-400">Nenhum registado.</p></div>';
                    if (data.last_command) {
                        const lc = data.last_command;
                        const formattedAction = lc.action.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                        lastCommandHtml = `<div class="detail-item"><h6>Último Comando</h6><div class="value-pair"><span class="text-slate-400">Ação:</span> <span class="font-bold">${formattedAction}</span></div><div class="value-pair"><span class="text-slate-400">Origem:</span> <span class="font-bold">${lc.user_name}</span></div><div class="value-pair value-pair-top"><span class="text-slate-400">Data:</span> <span class="font-bold text-right">${formatModalTimestamp(lc.timestamp)}</span></div></div>`;
                    }
                    
                    let lastFaultHtml = '<div class="detail-item"><h6>Última Falha</h6><p class="text-center text-slate-400">Nenhuma registada.</p></div>';
                    if (data.last_fault) {
                        const lf = data.last_fault;
                        const faultCode = (lf.details.match(/0x[0-9a-fA-F]+/) || ['N/A'])[0];
                        const faultDesc = faultMap[parseInt(faultCode, 16)] || `Código: ${faultCode}`;
                        lastFaultHtml = `<div class="detail-item"><h6>Última Falha</h6><div class="value-pair"><span class="text-slate-400">Falha:</span> <span class="font-bold text-red-400">${faultDesc}</span></div><div class="value-pair value-pair-top"><span class="text-slate-400">Data:</span> <span class="font-bold text-right">${formatModalTimestamp(lf.timestamp)}</span></div></div>`;
                    }
                    
                    modalBody.innerHTML = `<div class="modal-details-grid">${liveStatusHtml}${lastCommandHtml}${lastFaultHtml}</div>`;

                } catch (error) {
                    modalBody.innerHTML = `<div class="p-4 bg-red-900/50 text-red-300 rounded-md">Erro: ${error.message}</div>`;
                }
            });
        }
        
        initializeDashboard();
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

