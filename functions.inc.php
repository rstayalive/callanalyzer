<?php

function analyzeCall($lines, $originalNumber, $callId, $normalizedNumber)
{
    $summary = '<ul>';
    $type = 'Неопределён';
    $from = '';
    $to = '';
    $trunk = '';
    $did = '';
    $route = '';
    $ivrInput = '';
    $ringGroups = array();
    $queueGroups = array();
    $extensionsRung = array();
    $queueExtensions = array();
    $answeredBy = '';
    $startTime = '';
    $answerTime = '';
    $endTime = '';
    $duration = 'Не отвечен';
    $recordingFile = '';
    $status = '';
    $transfer = '';
    $forward = '';
    $outgoingTrunk = '';
    $sawInbound = false;

    $responsibleExtension = '';
    $responsibleStatus = '';
    $transitionType = '';
    $transitionDestination = '';
    $inCustomContext = false;

    $currentGroup = null;
    $currentQueue = null;
    $inQueueContext = false;

    foreach ($lines as $line) {
        // Время начала
        if (!$startTime && preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $timeMatch)) {
            $startTime = $timeMatch[1];
        }

        // Время окончания
        if ((strpos($line, 'Hangup') !== false || strpos($line, 'exited non-zero') !== false ||
             strpos($line, 'End MixMonitor') !== false ||
             (strpos($line, 'bridge_channel.c: Channel') !== false && strpos($line, 'left') !== false))) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $timeMatch)) {
                $endTime = $timeMatch[1];
            }
        }

        // Тип звонка
        if (strpos($line, '__DIRECTION=INBOUND') !== false) {
            $sawInbound = true;
            $type = 'Входящий';
        } elseif (strpos($line, '__DIRECTION=OUTBOUND') !== false) {
            $type = 'Исходящий';
        }

        // Исходящий (дополнительная логика)
        if (!$sawInbound && strpos($line, '@from-internal:1') !== false && strlen($normalizedNumber) > 6) {
            $type = 'Исходящий';
            if (preg_match('/PJSIP\/(\d+)-/', $line, $m) && strlen($m[1]) < 5) $from = $m[1];
            if (preg_match('/\[(\d+)@from-internal:1\]/', $line, $m)) $to = $m[1];
        } elseif (!$sawInbound && strpos($line, '@from-internal') !== false && strpos($line, 'OUTNUM=') === false && strlen($normalizedNumber) <= 8) {
            $type = 'Внутренний';
            if (preg_match('/PJSIP\/(\d+)-/', $line, $m)) $from = $m[1];
            $to = $normalizedNumber;
        }

        // От кого (Caller)
        if (strpos($line, 'FROMEXTEN=') !== false && !$from) {
            if (preg_match('/FROMEXTEN=(\d+)/', $line, $m)) $from = $m[1];
        } elseif (strpos($line, 'Caller ID name') !== false && !$from) {
            if (preg_match("/Caller ID name is '(\d+)'/", $line, $m)) $from = $m[1];
        }

        // Транк (входящий)
        if ($type === 'Входящий' && (strpos($line, '@from-pstn:1') !== false || strpos($line, '@from-trunk:1') !== false)) {
            if (preg_match('/@(\d+)@/', $line, $m)) $trunk = $m[1];
        }

        // DID
        if (strpos($line, '__FROM_DID=') !== false) {
            if (preg_match('/__FROM_DID=(\d+)/', $line, $m)) $did = $m[1];
        }

        // IVR ввод
        if (strpos($line, 'User entered') !== false) {
            if (preg_match("/User entered '(\d+)'/", $line, $m)) $ivrInput = $m[1];
        }

        // Custom ivrp context
        if (strpos($line, '@ivrp') !== false) $inCustomContext = true;

        // Поиск ответственного в custom-контексте
        if ($inCustomContext && strpos($line, 'Dial') !== false && strpos($line, 'PJSIP/') !== false && strpos($line, '@') === false) {
            if (preg_match('/PJSIP\/(\d+),/', $line, $m)) $responsibleExtension = $m[1];
        }

        if ($inCustomContext && $responsibleExtension) {
            if (strpos($line, 'Nobody picked up') !== false) $responsibleStatus = 'не ответил';
            elseif (strpos($line, 'answered') !== false) $responsibleStatus = 'ответил';
        }

        // Переход после неудачного поиска
        if ($inCustomContext && $responsibleStatus === 'не ответил' && strpos($line, 'Goto') !== false) {
            if (preg_match('/Goto\(".*", "(\w+-\w+|\w+),(\d+|\w+),/', $line, $m)) {
                $context = $m[1];
                if ($context == 'ext-group') {
                    $tType = 'ring group';
                } elseif ($context == 'ext-queues') {
                    $tType = 'queue';
                } elseif (strpos($context, 'ivr-') === 0) {
                    $tType = 'ivr';
                } elseif ($context === 'from-internal') {
                    $tType = 'extension';
                } else {
                    $tType = $context;
                }
                $transitionType = $tType;
                $transitionDestination = $m[2];
                $inCustomContext = false;
            }
        }

        // === Ring Groups ===
        if (strpos($line, 'ext-group,') !== false) {
            if (is_array($currentGroup) && $currentGroup['status']) $ringGroups[] = $currentGroup;
            if (preg_match('/ext-group,(\d+|\w+),/', $line, $m)) {
                $currentGroup = array('group' => $m[1], 'status' => '', 'answeredBy' => '', 'strategy' => 'неизвестно');
            }
            $inQueueContext = false;
        }

        if (is_array($currentGroup) && strpos($line, 'RingGroupMethod=') !== false) {
            if (preg_match('/RingGroupMethod=(\w+)/', $line, $m)) $currentGroup['strategy'] = $m[1];
        }

        if (!$inQueueContext && strpos($line, 'Added extension') !== false) {
            if (preg_match('/Added extension (\d+)/', $line, $m)) $extensionsRung[$m[1]] = true;
        } elseif (!$inQueueContext && strpos($line, 'Built External dialstring component for') !== false) {
            if (preg_match('/for (\d+):/', $line, $m)) $extensionsRung[$m[1]] = true;
        }

        if (strpos($line, 'answered') !== false && is_array($currentGroup)) {
            if (preg_match('/PJSIP\/(\d+)-\w+ answered/', $line, $m)) {
                $currentGroup['answeredBy'] = $m[1];
                $currentGroup['status'] = "ответил {$m[1]}";
                $ringGroups[] = $currentGroup;
                $currentGroup = null;
            }
        }

        if (strpos($line, 'Nobody picked up') !== false && is_array($currentGroup)) {
            $currentGroup['status'] = 'никто не ответил';
            $ringGroups[] = $currentGroup;
            $currentGroup = null;
        }

        // === Queues ===
        if (strpos($line, 'Executing') !== false && strpos($line, 'Queue(') !== false) {
            if (preg_match('/Queue\(".*", "(\d+),/', $line, $m)) {
                if (is_array($currentQueue) && $currentQueue['status']) $queueGroups[] = $currentQueue;
                $currentQueue = array('queue' => $m[1], 'status' => '', 'answeredBy' => '');
                $inQueueContext = true;
            }
        }

        if ($inQueueContext && strpos($line, 'Called PJSIP/') !== false && strpos($line, '@') === false) {
            if (preg_match('/Called PJSIP\/(\d+)/', $line, $m)) $queueExtensions[$m[1]] = true;
        }

        if ($inQueueContext && strpos($line, 'answered') !== false) {
            if (preg_match('/PJSIP\/(\d+)-\w+ answered/', $line, $m)) {
                $currentQueue['answeredBy'] = $m[1];
                $currentQueue['status'] = "ответил {$m[1]}";
                $queueGroups[] = $currentQueue;
                $currentQueue = null;
                $inQueueContext = false;
            }
        }

        // Transfer
        if (strpos($line, '@from-internal-xfer') !== false) {
            if (preg_match('/\[(\d+)@from-internal-xfer:1\]/', $line, $m)) {
                $prev = '';
                if (!empty($ringGroups)) {
                    $last = end($ringGroups);
                    $prev = $last['answeredBy'];
                } elseif (!empty($queueGroups)) {
                    $last = end($queueGroups);
                    $prev = $last['answeredBy'];
                } else {
                    $prev = $answeredBy;
                }
                $transfer = $prev ? "с $prev на {$m[1]}" : "на {$m[1]}";
            }
        }

        // Forward (follow-me)
        if (strpos($line, 'followme-sub') !== false) {
            if (preg_match('/followme-sub,(\d+),/', $line, $m)) {
                $forward = "follow me {$m[1]}";
            }
        }

        // Outbound route
        if (strpos($line, '_ROUTENAME=') !== false) {
            if (preg_match('/_ROUTENAME=(.+?)"/', $line, $m)) $route = $m[1];
        }

        // Исходящий транк
        if (strpos($line, 'Called PJSIP/') !== false && strpos($line, '@') !== false) {
            if (!preg_match('/Called PJSIP\/\d{2,5}\/sip:\d+@[\d.]+/', $line)) {
                if (preg_match('/Called PJSIP\/[^@]+@([^\s,)\]]+)/', $line, $m)) {
                    $outgoingTrunk = $m[1];
                }
            }
        }

        // Ответил + время ответа
        if (strpos($line, 'answered') !== false) {
            if (preg_match('/PJSIP\/(\d+)-\w+ answered/', $line, $m)) $answeredBy = $m[1];
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $timeMatch)) $answerTime = $timeMatch[1];
        }

        // Запись разговора
        if (strpos($line, 'CDR(recordingfile)=') !== false && strpos($line, '.wav') !== false) {
            if (preg_match('/CDR\(recordingfile\)=(.+?)"/', $line, $m)) $recordingFile = $m[1];
        }

        // Статус «абонент положил трубку»
        if ($type === 'Входящий' && strpos($line, 'User disconnected') !== false && !$answeredBy) {
            $status = 'Абонент положил трубку';
        }
    }

    // Добавить последние группы/очереди
    if (is_array($currentGroup) && $currentGroup['status']) $ringGroups[] = $currentGroup;
    if (is_array($currentQueue) && $currentQueue['status']) $queueGroups[] = $currentQueue;

    // Длительность
    if ($answerTime && $endTime) {
        $diff = strtotime($endTime) - strtotime($answerTime);
        $duration = ($diff >= 0) ? "$diff секунд" : 'Не отвечен';
    }

    // ==================== ФОРМИРОВАНИЕ ИТОГА ====================
    $summary .= "<li><strong>Тип:</strong> $type</li>";
    if ($from) $summary .= "<li><strong>От:</strong> $from</li>";
    if ($to)   $summary .= "<li><strong>К:</strong> $to</li>";
    if ($trunk) $summary .= "<li><strong>На транк:</strong> $trunk</li>";
    if ($did)   $summary .= "<li><strong>DID:</strong> $did</li>";
    if ($ivrInput) $summary .= "<li><strong>IVR ввод:</strong> $ivrInput</li>";

    if ($responsibleExtension) {
        $summary .= "<li><strong>Поиск ответственного:</strong> $responsibleExtension ($responsibleStatus)</li>";
    }
    if ($transitionType) {
        $summary .= "<li><strong>Переход после поиска:</strong> $transitionType $transitionDestination</li>";
    }

    if (!empty($ringGroups)) {
        $rgParts = array();
        foreach ($ringGroups as $g) {
            $rgParts[] = $g['group'] . ' (' . $g['status'] . ')';
        }
        $rgText = implode(' и ', $rgParts);
        $summary .= "<li><strong>Ring Group:</strong> $rgText</li>";
    }
    if (!empty($extensionsRung)) {
        $summary .= "<li><strong>Звонок был передан на номера:</strong> " . implode(', ', array_keys($extensionsRung)) . "</li>";
    }
    if ($transfer) $summary .= "<li><strong>Перевод звонка:</strong> $transfer</li>";

    if (!empty($queueGroups)) {
        $qgParts = array();
        foreach ($queueGroups as $q) {
            $qgParts[] = $q['queue'] . ' (' . $q['status'] . ')';
        }
        $qgText = implode(' и ', $qgParts);
        $summary .= "<li><strong>Звонок в queue:</strong> $qgText</li>";
    }
    if (!empty($queueExtensions)) {
        $summary .= "<li><strong>Набраны номера очереди:</strong> " . implode(', ', array_keys($queueExtensions)) . "</li>";
    }
    if ($forward) $summary .= "<li><strong>Переадресация:</strong> $forward</li>";

    if ($route) $summary .= "<li><strong>Исходящий маршрут:</strong> $route</li>";
    $outTrunkText = $outgoingTrunk ? $outgoingTrunk : 'не использовался';
    $summary .= "<li><strong>Исходящий транк:</strong> $outTrunkText</li>";

    if ($answerTime) $summary .= "<li><strong>Время ответа:</strong> $answerTime</li>";
    $summary .= "<li><strong>Начало:</strong> " . ($startTime ? $startTime : 'неизвестно') . "</li>";
    $summary .= "<li><strong>Конец:</strong> " . ($endTime ? $endTime : 'неизвестно') . "</li>";
    $summary .= "<li><strong>Длительность:</strong> $duration</li>";

    if ($recordingFile) $summary .= "<li><strong>Файл записи:</strong> $recordingFile</li>";
    if ($status) $summary .= "<li><strong>Статус:</strong> $status</li>";

    $summary .= '</ul>';

    return $summary;
}