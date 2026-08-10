<?php

/**
 * Inicia a sessão e recupera automaticamente de um ficheiro de sessão antigo
 * que o Apache já não consiga ler (por exemplo, por permissões incorretas).
 */
function start_worklog_session(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    if (@session_start()) {
        return true;
    }

    // Não reutilizar no segundo arranque o ID cujo ficheiro falhou.
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 42000, '/');
        unset($_COOKIE[session_name()]);
    }

    session_id(bin2hex(random_bytes(16)));
    return session_start();
}
