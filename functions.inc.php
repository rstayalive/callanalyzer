<?php
function analyzeCall($lines, $originalNumber, $callId, $normalizedNumber) {
    $summary = '<ul>';
    $type = 'Неопределён';
    $from = '';
    $to = '';
    $trunk = '';
    $did = '';
    $route = '';
    $ivrInput = '';
    $ringGroups = [];
    $queueGroups = [];
    $extensionsRung = [];
    $queueExtensions = [];
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
        // Время начала (первая строка)
        if (!$startTime && preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $timeMatch)) {
            $startTime = $timeMatch[1];
        }

        // Время конца (последняя подходящая)
        if (strpos($line, 'Hangup') !== false || strpos($line, 'exited non-zero') !== false || strpos($line, 'End MixMonitor') !== false || (strpos($line, 'bridge_channel.c: Channel') !== false && strpos($line, 'left') !== false)) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $timeMatch)) {
                $endTime = $timeMatch[1];
            }
        }

        // Тип по __DIRECTION
        if (strpos($line, '__DIRECTION=INBOUND') !== false) {
            $sawInbound = true;
            $type = 'Входящий';
        } elseif (strpos($line, '__DIRECTION=OUTBOUND') !== false) {
            $type = 'Исходящий';
        }

        // Дополнительно для исходящего
        if (!$sawInbound && strpos($line, '@from-internal:1') !== false && strlen($normalizedNumber) > 6) {
            $type = 'Исходящий';
            if (preg_match('/PJSIP\/(\d+)-/', $line, $fromMatch) && strlen($fromMatch[1]) < 5) $from = $fromMatch[1];
            if (preg_match('/\[(\d+)@from-internal:1\]/', $line, $toMatch)) $to = $toMatch[1];
        }

        // Внутренний
        elseif (!$sawInbound && strpos($line, '@from-internal') !== false && strpos($line, 'OUTNUM=') === false && strlen($normalizedNumber) <= 8) {
            $type = 'Внутренний';
            if (preg_match('/PJSIP\/(\d+)-/', $line, $fromMatch)) $from = $fromMatch[1];
            $to = $normalizedNumber;
        }

        // От (caller)
        if (strpos($line, 'FROMEXTEN=') !== false && !$from) {
            if (preg_match('/FROMEXTEN=(\d+)/', $line, $match)) $from = $match[1];
        } elseif (strpos($line, 'Caller ID name') !== false && !$from) {
            if (preg_match("/Caller ID name is '(\d+)'/", $line, $match)) $from = $match[1];
        }

        // На транк (incoming trunk)
        if ($type === 'Входящий' && (strpos($line, '@from-pstn:1') !== false || strpos($line, '@from-trunk:1') !== false)) {
            if (preg_match('/@(\d+)@/', $line, $trunkMatch)) $trunk = $trunkMatch[1];
        }

        // DID
        if (strpos($line, '__FROM_DID=') !== false) {
            if (preg_match('/__FROM_DID=(\d+)/', $line, $match)) $did = $match[1];
        }

        // IVR input
        if (strpos($line, 'User entered') !== false) {
            if (preg_match("/User entered '(\d+)'/", $line, $match)) $ivrInput = $match[1];
        }

        // Custom ivrp context
        if (strpos($line, '@ivrp') !== false) {
            $inCustomContext = true;
        }

        // Responsible dial in custom
        if ($inCustomContext && strpos($line, 'Dial') !== false && strpos($line, 'PJSIP/') !== false && strpos($line, '@') === false) {
            if (preg_match('/PJSIP\/(\d+),/', $line, $match)) $responsibleExtension = $match[1];
        }

        // Responsible status
        if ($inCustomContext && strpos($line, 'Nobody picked up') !== false && $responsibleExtension) {
            $responsibleStatus = 'не ответил';
        } elseif ($inCustomContext && strpos($line, 'answered') !== false && $responsibleExtension) {
            $responsibleStatus = 'ответил';
        }

        // Transition Goto after no answer
        if ($inCustomContext && $responsibleStatus === 'не ответил' && strpos($line, 'Goto') !== false) {
            if (preg_match('/Goto\(".*", "(\w+-\w+| \w+),(\d+|\w+),/', $line, $match)) {
                $context = $match[1];
                $tType = '';
                if ($context === 'ext-group') $tType = 'ring group';
                elseif ($context === 'ext-queues') $tType = 'queue';
                elseif (strpos($context, 'ivr-') === 0) $tType = 'ivr';
                elseif ($context === 'from-internal') $tType = 'extension';
                $transitionType = $tType;
                $transitionDestination = $match[2];
                $inCustomContext = false;
            }
        }

        // Ring group start
        if (strpos($line, 'ext-group,') !== false) {
            if ($currentGroup && $currentGroup['status']) {
                $ringGroups[] = $currentGroup;
            }
            if (preg_match('/ext-group,(\d+|\w+),/', $line, $match)) {
                $currentGroup = ['group' => $match[1], 'status' => '', 'answeredBy' => '', 'strategy' => 'неизвестно'];
            }
            $inQueueContext = false;
        }

        // Ring strategy
        if (strpos($line, 'RingGroupMethod=') !== false && $currentGroup) {
            if (preg_match('/RingGroupMethod=(\w+)/', $line, $match)) $currentGroup['strategy'] = $match[1];
        }

        // Extensions in ring group
        if (!$inQueueContext && strpos($line, 'Added extension') !== false) {
            if (preg_match('/Added extension (\d+)/', $line, $match)) $extensionsRung[$match[1]] = true;
        } elseif (!$inQueueContext && strpos($line, 'Built External dialstring component for') !== false) {
            if (preg_match('/for (\d+):/', $line, $match)) $extensionsRung[$match[1]] = true;
        }

        // Answered in group
        if (strpos($line, 'answered') !== false && $currentGroup) {
            if (preg_match('/PJSIP\/(\d+)-\w+ answered/', $line, $match)) {
                $currentGroup['answeredBy'] = $match[1];
                $currentGroup['status'] = "ответил {$match[1]}";
                $ringGroups[] = $currentGroup;
                $currentGroup = null;
            }
        }

        // No answer in group
        if (strpos($line, 'Nobody picked up') !== false && $currentGroup) {
            $currentGroup['status'] = 'никто не ответил';
            $ringGroups[] = $currentGroup;
            $currentGroup = null;
        }

        // Queue start
        if (strpos($line, 'Executing') !== false && strpos($line, 'Queue(') !== false) {
            if (preg_match('/Queue\(".*", "(\d+),/', $line, $match)) {
                if ($currentQueue && $currentQueue['status']) {
                    $queueGroups[] = $currentQueue;
                }
                $currentQueue = ['queue' => $match[1], 'status' => '', 'answeredBy' => ''];
                $inQueueContext = true;
            }
        }

        // Extensions in queue
        if ($inQueueContext && strpos($line, 'Called PJSIP/') !== false && strpos($line, '@') === false) {
            if (preg_match('/Called PJSIP\/(\d+)/', $line, $match)) $queueExtensions[$match[1]] = true;
        }

        // Answered in queue
        if ($inQueueContext && strpos($line, 'answered') !== false) {
            if (preg_match('/PJSIP\/(\d+)-\w+ answered/', $line, $match)) {
                $currentQueue['answeredBy'] = $match[1];
                $currentQueue['status'] = "ответил {$match[1]}";
                $queueGroups[] = $currentQueue;
                $currentQueue = null;
                $inQueueContext = false;
            }
        }

        // Transfer
        if (strpos($line, '@from-internal-xfer') !== false) {
            if (preg_match('/\[(\d+)@from-internal-xfer:1\]/', $line, $match)) {
                $prevAnswered = !empty($ringGroups) ? end($ringGroups)['answeredBy'] : (!empty($queueGroups) ? end($queueGroups)['answeredBy'] : $answeredBy);
                $transfer = "с $prevAnswered на {$match[1]}";
            }
        }

        // Forward (follow-me)
        if (strpos($line, 'followme-sub') !== false) {
            if (preg_match('/followme-sub,(\d+),/', $line, $match)) {
                $exten = $match[1];
                $forwardedTo = '';
                foreach ($lines as $l) {
                    if (strpos($l, 'Added extension') !== false && strpos($l, '#') !== false) {
                        if (preg_match('/Added extension (\d+#)/', $l, $m)) $forwardedTo = str_replace('#', '', $m[1]);
                    }
                }
                $forward = "follow me $exten на $forwardedTo";
            }
        }

        // Outbound route
        if (strpos($line, '_ROUTENAME=') !== false) {
            if (preg_match('/_ROUTENAME=(.+?)"/', $line, $match)) $route = $match[1];
        }

        // Исходящий транк
        if (strpos($line, 'Called PJSIP/') !== false && strpos($line, '@') !== false) {
            if (preg_match('/Called PJSIP\/.+@(\d{6,})/', $line, $match)) $outgoingTrunk = $match[1];
        }

        // Кто ответил / время ответа
        if (strpos($line, 'answered') !== false) {
            if (preg_match('/PJSIP\/(\d+)-\w+ answered/', $line, $match)) $answeredBy = $match[1];
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $timeMatch)) $answerTime = $timeMatch[1];
        }

        // Файл записи
        if (strpos($line, 'CDR(recordingfile)=') !== false && strpos($line, '.wav') !== false) {
            if (preg_match('/CDR\(recordingfile\)=(.+?)"/', $line, $match)) $recordingFile = $match[1];
        }

        // Статус для входящего без ответа
        if ($type === 'Входящий' && strpos($line, 'User disconnected') !== false && !$answeredBy) {
            $status = 'Абонент положил трубку';
        }
    }

    // Push last group/queue
    if ($currentGroup && $currentGroup['status']) $ringGroups[] = $currentGroup;
    if ($currentQueue && $currentQueue['status']) $queueGroups[] = $currentQueue;

    // Длительность
    if ($answerTime && $endTime) {
        $answerDate = strtotime($answerTime);
        $endDate = strtotime($endTime);
        $diff = floor(($endDate - $answerDate));
        if ($diff >= 0) {
            $duration = "$diff секунд";
        } else {
            $duration = 'Не отвечен';
        }
    }

    // Сборка summary
    $summary .= "<li>Тип: $type</li>";
    if ($from) $summary .= "<li>От: $from</li>";
    if ($to) $summary .= "<li>К: $to</li>";
    if ($trunk) $summary .= "<li>На транк: $trunk</li>";
    if ($did) $summary .= "<li>DID: $did</li>";
    if ($ivrInput) $summary .= "<li>IVR ввод: $ivrInput</li>";
    if ($responsibleExtension) $summary .= "<li>Поиск ответственного: $responsibleExtension ($responsibleStatus)</li>";
    if ($transitionType) $summary .= "<li>Переход с поиска ответственного: $transitionType $transitionDestination</li>";
    if (!empty($ringGroups)) {
        $rgText = implode(' и ', array_map(function($rg) { return "{$rg['group']} ({$rg['status']})"; }, $ringGroups));
        $summary .= "<li>Ring Group: $rgText</li>";
    }
    if (!empty($extensionsRung)) $summary .= "<li>Звонок был передан на номера: " . implode(', ', array_keys($extensionsRung)) . "</li>";
    if ($transfer) $summary .= "<li>Перевод звонка: $transfer</li>";
    if (!empty($queueGroups)) {
        $qgText = implode(' и ', array_map(function($qg) { return "{$qg['queue']} ({$qg['status']})"; }, $queueGroups));
        $summary .= "<li>Звонок в queue: $qgText</li>";
    }
    if (!empty($queueExtensions)) $summary .= "<li>Набраны номера очереди: " . implode(', ', array_keys($queueExtensions)) . "</li>";
    if ($forward) $summary .= "<li>Переадресация звонка: $forward</li>";
    $summary .= "<li>Исходящий транк: " . ($outgoingTrunk ?: 'не использовался') . "</li>";
    if ($answerTime) $summary .= "<li>Время ответа на звонок: " . ($answerTime ?: 'неизвестно') . "</li>";
    $summary .= "<li>Начало: " . ($startTime ?: 'неизвестно') . "</li>";
    $summary .= "<li>Конец: " . ($endTime ?: 'неизвестно') . "</li>";
    $summary .= "<li>Длительность: $duration</li>";
    if ($recordingFile) $summary .= "<li>Файл записи: $recordingFile</li>";
    if ($status) $summary .= "<li>Статус: $status</li>";
    $summary .= '</ul>';

    return $summary;
}
?>
