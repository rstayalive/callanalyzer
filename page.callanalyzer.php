<?php
namespace FreePBX\modules;

if (!defined('FREEPBX_IS_AUTH')) { 
    die('No direct script access allowed'); 
}

include_once('functions.inc.php');

echo '<div class="container-fluid">';
echo '<h1>Анализатор логов звонков</h1>';

echo '<form method="post">';
echo '<label for="date">Дата звонка (YYYY-MM-DD):</label><br>';
echo '<input type="date" id="date" name="date" required><br><br>';
echo '<label for="number">Номер телефона:</label><br>';
echo '<input type="text" id="number" name="number" required><br><br>';
echo '<input type="submit" value="Анализировать" class="btn btn-primary">';
echo '</form>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $number = trim($_POST['number']);
    
    if (empty($date) || empty($number)) {
        echo '<div class="alert alert-danger">Укажите дату и номер</div>';
        exit;
    }

    // Нормализация номера
    $normalizedNumber = preg_replace('/^\+?7|^\+?8/', '', $number);
    $normalizedNumber = preg_replace('/\D/', '', $normalizedNumber);
    if (strlen($normalizedNumber) > 10) {
        $normalizedNumber = substr($normalizedNumber, -10);
    }

    $logDir = '/var/log/asterisk/';
    $possibleLogs = [$logDir . 'full'];
    
    // Добавляем ротированные логи
    foreach (['.0', '.1', '.2'] as $suffix) {
        $f = $logDir . 'full' . $suffix;
        if (file_exists($f)) $possibleLogs[] = $f;
    }
    
    $yyyymmdd = str_replace('-', '', $date);
    $rotatedLog = $logDir . 'full-' . $yyyymmdd;
    if (file_exists($rotatedLog)) {
        $possibleLogs[] = $rotatedLog;
    }

    $datePrefix = '[' . $date;
    $callIds = [];
    $callIdToTime = []; // для сортировки

    // === Первый проход: находим все Call-ID для номера ===
    echo '<div class="alert alert-info">Поиск звонков по номеру...</div>';
    
    foreach ($possibleLogs as $file) {
        if (!file_exists($file)) continue;
        
        $handle = fopen($file, 'r');
        if (!$handle) continue;

        while (($line = fgets($handle)) !== false) {
            if (strpos($line, $datePrefix) !== 0) continue;
            if (strpos($line, $normalizedNumber) === false) continue;

            // Ищем Call-ID
            if (preg_match('/\[C-([0-9a-f]+)\]/', $line, $match)) {
                $cid = 'C-' . $match[1];
                if (!isset($callIds[$cid])) {
                    $callIds[$cid] = true;
                    
                    // Извлекаем время для сортировки
                    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $t)) {
                        $callIdToTime[$cid] = $t[1];
                    }
                }
            }
        }
        fclose($handle);
    }

    $callIds = array_keys($callIds);
    
    if (empty($callIds)) {
        echo '<div class="alert alert-warning">Звонки с номером ' . htmlspecialchars($number) . ' за ' . $date . ' не найдены.</div>';
        echo '</div>';
        exit;
    }

    // Сортируем по времени (новые сверху)
    uasort($callIds, function($a, $b) use ($callIdToTime) {
        return strcmp($callIdToTime[$b] ?? '', $callIdToTime[$a] ?? '');
    });

    // Ограничение количества звонков (защита от слишком большого результата)
    $maxCalls = 30;
    if (count($callIds) > $maxCalls) {
        echo '<div class="alert alert-warning">Найдено ' . count($callIds) . ' звонков. Показываем последние ' . $maxCalls . '.</div>';
        $callIds = array_slice($callIds, 0, $maxCalls);
    }

    echo '<h2>Найдено звонков: ' . count($callIds) . '</h2>';

    // === Второй проход: анализируем каждый звонок ===
    foreach ($callIds as $callId) {
        $callLines = [];
        
        foreach ($possibleLogs as $file) {
            if (!file_exists($file)) continue;
            
            $handle = fopen($file, 'r');
            if (!$handle) continue;

            while (($line = fgets($handle)) !== false) {
                if (strpos($line, '[' . $callId . ']') !== false) {
                    $callLines[] = trim($line);
                }
            }
            fclose($handle);
        }

        if (empty($callLines)) continue;

        $fullLog = implode("\n", $callLines);
        $analysis = analyzeCall($callLines, $number, $callId, $normalizedNumber);

        echo "<h3>Звонок ID: $callId</h3>";
        echo $analysis;
        echo '<details><summary>Полный лог звонка (развернуть)</summary>';
        echo '<pre style="max-height: 400px; overflow: auto;">' . htmlspecialchars($fullLog) . '</pre>';
        echo '</details><hr>';
    }
}

echo '</div>';
?>