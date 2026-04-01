<?php
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
    $date   = isset($_POST['date']) ? $_POST['date'] : '';
    $number = trim(isset($_POST['number']) ? $_POST['number'] : '');

    if (empty($date) || empty($number)) {
        echo '<div class="alert alert-danger">Укажите дату и номер</div>';
        echo '</div>';
        exit;
    }

    // ==================== НОРМАЛИЗАЦИЯ НОМЕРА ====================
    $normalizedNumber = preg_replace('/^\+?(7|8)/', '', $number);
    $normalizedNumber = preg_replace('/\D/', '', $normalizedNumber);
    if (strlen($normalizedNumber) > 10) {
        $normalizedNumber = substr($normalizedNumber, -10);
    }

    $logDir = '/var/log/asterisk/';
    $possibleLogs = array($logDir . 'full');

    foreach (array('.0', '.1', '.2') as $suffix) {
        $f = $logDir . 'full' . $suffix;
        if (file_exists($f)) $possibleLogs[] = $f;
    }

    $yyyymmdd = str_replace('-', '', $date);
    $rotatedLog = $logDir . 'full-' . $yyyymmdd;
    if (file_exists($rotatedLog)) {
        $possibleLogs[] = $rotatedLog;
    }

    $datePrefix = '[' . $date;

    echo '<div class="alert alert-info">Поиск звонков по номеру...</div>';

    $callData = array();

    foreach ($possibleLogs as $file) {
        if (!file_exists($file)) continue;

        $handle = fopen($file, 'r');
        if (!$handle) continue;

        while (($line = fgets($handle)) !== false) {
            if (strpos($line, $datePrefix) !== 0) continue;

            if (preg_match('/\[C-([0-9a-f]+)\]/', $line, $match)) {
                $cid = 'C-' . $match[1];

                if (!isset($callData[$cid])) {
                    $callData[$cid] = array(
                        'time'      => '',
                        'lines'     => array(),
                        'hasNumber' => false
                    );
                    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $t)) {
                        $callData[$cid]['time'] = $t[1];
                    }
                }

                $callData[$cid]['lines'][] = trim($line);

                if (strpos($line, $normalizedNumber) !== false) {
                    $callData[$cid]['hasNumber'] = true;
                }
            }
        }
        fclose($handle);
    }

    // фильтр
    $filtered = array();
    foreach ($callData as $cid => $item) {
        if ($item['hasNumber']) $filtered[$cid] = $item;
    }
    $callData = $filtered;

    if (empty($callData)) {
        echo '<div class="alert alert-warning">Звонки с номером ' . htmlspecialchars($number) . ' за ' . $date . ' не найдены.</div>';
        echo '</div>';
        exit;
    }

    // сортировка по времени (новые сверху)
    uasort($callData, function($a, $b) {
        $timeA = isset($a['time']) ? $a['time'] : '';
        $timeB = isset($b['time']) ? $b['time'] : '';
        return strcmp($timeB, $timeA);
    });

    $maxCalls = 30;
    if (count($callData) > $maxCalls) {
        echo '<div class="alert alert-warning">Найдено ' . count($callData) . ' звонков. Показываем последние ' . $maxCalls . '.</div>';
        $callData = array_slice($callData, 0, $maxCalls, true);
    }

    echo '<h2>Найдено звонков: ' . count($callData) . '</h2>';

    foreach ($callData as $callId => $data) {
        $callLines = $data['lines'];
        $fullLog   = implode("\n", $callLines);

        $analysis = analyzeCall($callLines, $number, $callId, $normalizedNumber);

        echo "<h3>Звонок ID: $callId</h3>";
        echo $analysis;

        echo '<details><summary>Полный лог звонка (развернуть)</summary>';
        echo '<pre style="max-height: 70vh; overflow: auto; background:#f8f9fa; padding:15px; font-size:0.86em; line-height:1.4; border:1px solid #ddd; border-radius:4px;">' 
             . htmlspecialchars($fullLog) 
             . '</pre>';
        echo '</details><hr>';
    }
}

echo '</div>';