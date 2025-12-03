<?php
$filename = 'report_pdfs/' . basename($_GET['file']);

if (file_exists($filename)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($filename) . '"');
    readfile($filename);
} else {
    echo "Arquivo não encontrado.";
}
?>