<?php
namespace FreePBX\modules;
// Включаем FreePBX фреймворк
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

// Вспомогательные функции (включаем из functions.inc.php)
include_once('functions.inc.php');

// Форма
echo '<div class="container-fluid">';
echo '<h1>Анализатор логов звонков</h1>';
echo '<form method="post">';
echo '<label for="date">Дата звонка (YYYY-MM-DD):</label>';
echo '<input type="date" id="date" name="date" required><br><br>';
echo '<label for="number">Номер телефона:</label>';
echo '<input type="text" id="number" name="number" required><br><br>';
echo '<input type="submit" value="Анализировать">';
echo '</form>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $number = trim($_POST['number']);

    // Нормализация номера
    $normalizedNumber = preg_replace('/^\+?7|^\+?8/', '', $number);
    $normalizedNumber = preg_replace('/\D/', '', $normalizedNumber);
    if (strlen($normalizedNumber) > 10) {
        $normalizedNumber = substr($normalizedNumber, -10);
    }

    // Список лог-файлов
    $logDir = '/var/log/asterisk/';
    $baseLog = $logDir . 'full';
    $possibleLogs = [$baseLog];

    $full0 = $baseLog . '.0';
    $full1 = $baseLog . '.1';
    if (file_exists($full0)) $possibleLogs[] = $full0;
    if (file_exists($full1)) $possibleLogs[] = $full1;

    $yyyymmdd = str_replace('-', '', $date);
    $rotatedLog = $logDir . 'full-' . $yyyymmdd;
    if (file_exists($rotatedLog) && !in_array($rotatedLog, $possibleLogs)) {
        $possibleLogs[] = $rotatedLog;
    }

    // Сбор строк по дате
    $datedLines = [];
    foreach ($possibleLogs as $file) {
        if (file_exists($file)) {
            $logContent = file_get_contents($file);
            $lines = explode("\n", $logContent);
            $datePrefix = '[' . $date;
            foreach ($lines as $line) {
                if (strpos($line, $datePrefix) === 0) {
                    $datedLines[] = $line;
                }
            }
        }
    }

    if (empty($datedLines)) {
        echo 'Логи для указанной даты не найдены.';
        return;
    }

    // Поиск строк с номером
    $matchingLines = [];
    foreach ($datedLines as $line) {
        if (strpos($line, $normalizedNumber) !== false) {
            $matchingLines[] = $line;
        }
    }

    if (empty($matchingLines)) {
        echo 'Звонки не найдены.';
        return;
    }

    // Извлечение уникальных call ID
    $callIds = [];
    foreach ($matchingLines as $line) {
        if (preg_match('/\[C-([0-9a-f]+)\]/', $line, $match)) {
            $callIds[] = 'C-' . $match[1];
        }
    }
    $callIds = array_unique($callIds);

    echo '<h1>Анализ звонков</h1>';

    foreach ($callIds as $callId) {
        // Все строки для этого call ID
        $callLines = [];
        foreach ($datedLines as $line) {
            if (strpos($line, '[' . $callId . ']') !== false) {
                $callLines[] = $line;
            }
        }

        // Полный лог
        $fullLog = implode("\n", $callLines);

        // Анализ (функция из functions.inc.php)
        $analysis = analyzeCall($callLines, $number, $callId, $normalizedNumber);

        echo "<h2>Звонок ID: $callId</h2>";
        echo $analysis;
        echo "<details><summary>Полный лог (развернуть)</summary><pre>$fullLog</pre></details><hr>";
    }
}

echo '</div>';
?>
