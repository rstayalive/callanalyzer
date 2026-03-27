<?php
namespace FreePBX\modules;

if (!defined('FREEPBX_IS_AUTH')) { 
    die('No direct script access allowed'); 
}

include_once('functions.inc.php');

// Конфигурация
$config = [
    'log_dir' => '/var/log/asterisk/',
    'max_calls' => 30,
    'max_file_size' => 104857600, // 100MB
    'log_files' => ['full', 'full.0', 'full.1', 'full.2'],
];

// CSRF-защита
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

echo '<div class="container-fluid">';
echo '<h1>Анализатор логов звонков</h1>';

echo '<form method="post">';
echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
echo '<label for="date">Дата звонка (YYYY-MM-DD):</label><br>';
echo '<input type="date" id="date" name="date" required><br><br>';
echo '<label for="number">Номер телефона:</label><br>';
echo '<input type="text" id="number" name="number" placeholder="+7 (XXX) XXX-XX-XX" required><br><br>';
echo '<input type="submit" value="Анализировать" class="btn btn-primary">';
echo '</form>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo '<div class="alert alert-danger">Ошибка безопасности (CSRF). Обновите страницу и попробуйте снова.</div>';
        echo '</div>';
        exit;
    }

    $date = $_POST['date'] ?? '';
    $number = trim($_POST['number'] ?? '');
    
    // Валидация даты
    if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo '<div class="alert alert-danger">Укажите корректную дату в формате YYYY-MM-DD</div>';
        echo '</div>';
        exit;
    }

    // Валидация номера
    if (empty($number) || !preg_match('/^[\d\+\-\s\(\)]{5,20}$/', $number)) {
        echo '<div class="alert alert-danger">Укажите корректный номер телефона (5-20 символов)</div>';
        echo '</div>';
        exit;
    }

    // Нормализация номера
    $normalizedNumber = preg_replace('/^\+?7|^\+?8/', '', $number);
    $normalizedNumber = preg_replace('/\D/', '', $normalizedNumber);
    if (strlen($normalizedNumber) > 10) {
        $normalizedNumber = substr($normalizedNumber, -10);
    }
    if (strlen($normalizedNumber) < 5) {
        echo '<div class="alert alert-danger">Номер слишком короткий</div>';
        echo '</div>';
        exit;
    }

    // Сбор возможных логов
    $possibleLogs = [];
    foreach ($config['log_files'] as $logFile) {
        $f = $config['log_dir'] . $logFile;
        if (file_exists($f)) {
            // Проверка размера файла
            $size = filesize($f);
            if ($size > $config['max_file_size']) {
                error_log("CallAnalyzer: File $f too large ($size bytes), skipping");
                continue;
            }
            $possibleLogs[] = $f;
        }
    }
    
    // Добавляем ротированный лог за дату
    $yyyymmdd = str_replace('-', '', $date);
    $rotatedLog = $config['log_dir'] . 'full-' . $yyyymmdd;
    if (file_exists($rotatedLog)) {
        $size = filesize($rotatedLog);
        if ($size <= $config['max_file_size']) {
            $possibleLogs[] = $rotatedLog;
        }
    }

    if (empty($possibleLogs)) {
        echo '<div class="alert alert-warning">Файлы логов не найдены в ' . htmlspecialchars($config['log_dir']) . '</div>';
        echo '</div>';
        exit;
    }

    $datePrefix = '[' . $date;
    $callData = []; // Храним все строки для каждого Call-ID

    // === ОДИН ПРОХОД: находим все Call-ID и сразу собираем данные ===
    echo '<div class="alert alert-info">Поиск звонков по номеру...</div>';
    
    foreach ($possibleLogs as $file) {
        $handle = @fopen($file, 'r');
        if (!$handle) {
            error_log("CallAnalyzer: Cannot open $file");
            continue;
        }

        while (($line = fgets($handle)) !== false) {
            if (strpos($line, $datePrefix) !== 0) continue;
            if (strpos($line, $normalizedNumber) === false) continue;

            // Ищем Call-ID
            if (preg_match('/\[C-([0-9a-f]+)\]/', $line, $match)) {
                $cid = 'C-' . $match[1];
                if (!isset($callData[$cid])) {
                    $callData[$cid] = [
                        'lines' => [],
                        'time' => ''
                    ];
                }
                $callData[$cid]['lines'][] = trim($line);
                
                // Извлекаем время для сортировки (только если ещё не извлечено)
                if (empty($callData[$cid]['time'])) {
                    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $t)) {
                        $callData[$cid]['time'] = $t[1];
                    }
                }
            }
        }
        fclose($handle);
    }

    // Сортировка по времени (новые сверху)
    uasort($callData, function($a, $b) {
        return strcmp($b['time'] ?? '', $a['time'] ?? '');
    });

    $callIds = array_keys($callData);
    
    if (empty($callIds)) {
        echo '<div class="alert alert-warning">Звонки с номером ' . htmlspecialchars($number) . ' за ' . $date . ' не найдены.</div>';
        echo '</div>';
        exit;
    }

    // Ограничение количества звонков
    $maxCalls = $config['max_calls'];
    if (count($callIds) > $maxCalls) {
        echo '<div class="alert alert-warning">Найдено ' . count($callIds) . ' звонков. Показываем последние ' . $maxCalls . '.</div>';
        $callIds = array_slice($callIds, 0, $maxCalls);
    }

    echo '<h2>Найдено звонков: ' . count($callIds) . '</h2>';

    // === Анализ каждого звонка (данные уже собраны) ===
    foreach ($callIds as $callId) {
        $callLines = $callData[$callId]['lines'];

        if (empty($callLines)) continue;

        $fullLog = implode("\n", $callLines);
        $analysis = analyzeCall($callLines, $number, $callId, $normalizedNumber);

        echo "<h3>Звонок ID: $callId</h3>";
        echo $analysis;
        echo '<details><summary>Полный лог звонка (развернуть)</summary>';
        echo '<pre style="max-height: 70vh; overflow: auto; background:#f8f9fa; padding:15px; font-size:0.86em; line-height:1.4; border:1px solid #ddd; border-radius:4px;">' 
     . htmlspecialchars($fullLog) 
     . '</pre>';
        echo '</details><hr>';
    }

    error_log("CallAnalyzer: Completed analysis for $number on $date, found " . count($callIds) . " calls");
}

echo '</div>';
?>