<?php

function analyzeCall($lines, $originalNumber, $callId, $normalizedNumber)
{
    $type = 'Неопределён';
    $sawInbound = false;

    // Основные переменные
    $startTime = $answerTime = $endTime = $answeredBy = $hangupTime = '';
    $recordingFile = $uniqueCallId = '';
    $fromExten = $callerIdNum = $callerIdName = '';
    $did = $incomingTrunkChannel = $outgoingTrunk = $routeName = '';
    $dialedNumber = $outNum = '';
    $dialedExtensions = [];
    $ringGroups = [];
    $queueGroups = [];
    $finalDestinationType = $finalDestination = '';
    $blacklistCheck = 'passed';
    $timeCondition = '';
    $timeConditionRule = '';       
    $hangupReason = '';
    $bridgeJoined = false;
    $dialExecutedTime = $ringingTime = $answeredTime = '';
    $agiAnswerStatus = '';
    $ivrContext = '';
    $directExtension = '';

    foreach ($lines as $line) {
        // Время
        if (!$startTime && preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
            $startTime = $m[1];
        }
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
            $ts = $m[1];
            if (strpos($line, 'Hangup') !== false || strpos($line, 'exited non-zero') !== false || 
                strpos($line, 'End MixMonitor') !== false || strpos($line, 'left') !== false) {
                $endTime = $hangupTime = $ts;
            }
        }

        // Тип звонка
        if (strpos($line, '__DIRECTION=INBOUND') !== false) {
            $sawInbound = true;
            $type = 'Входящий';
        } elseif (strpos($line, '__DIRECTION=OUTBOUND') !== false) {
            $type = 'Исходящий';
        }

        // CallerID
        if (preg_match('/CALLERID\(name\)=["\']?([^"\']*?)(?=["\']|$)/', $line, $m)) {
            $name = trim($m[1]);
            if ($name !== '' && $name !== ')') $callerIdName = $name;
        }
        if (preg_match('/CALLERID\(num(?:ber)?\)=["\']?(\+?\d+)/', $line, $m)) $callerIdNum = $m[1];
        if (strpos($line, 'FROMEXTEN=') !== false && preg_match('/FROMEXTEN=(\d+)/', $line, $m)) $fromExten = $m[1];

        // DID + Trunk
        if (strpos($line, '__FROM_DID=') !== false && preg_match('/__FROM_DID=(\d+)/', $line, $m)) $did = $m[1];
        if ($type === 'Входящий' && preg_match('/PJSIP\/(.+?)(?=\s|$)/', $line, $m)) $incomingTrunkChannel = $m[1];

        // Запись
        if (strpos($line, 'CDR(recordingfile)=') !== false && preg_match('/CDR\(recordingfile\)=(.+?\.wav)/', $line, $m)) {
            $recordingFile = $m[1];
        }
        if (strpos($line, 'TOUCH_MONITOR=') !== false && preg_match('/TOUCH_MONITOR=([\d.]+)/', $line, $m)) {
            $uniqueCallId = $m[1];
        }

        // IVR и Direct Extension
        if (preg_match('/ivr-(\d+)/', $line, $m)) {
            $ivrContext = 'ivr-' . $m[1];
            $finalDestinationType = 'IVR';
            $finalDestination = $ivrContext;
        }
        if (strpos($line, 'from-did-direct') !== false && preg_match('/from-did-direct,(\d+)/', $line, $m)) {
            $directExtension = $m[1];
            $finalDestinationType = 'Direct Extension';
            $finalDestination = $directExtension;
        }

        // Исходящий
        if (!$sawInbound && strpos($line, '@from-internal') !== false) $type = 'Исходящий';
        if (strpos($line, 'OUTNUM=') !== false && preg_match('/OUTNUM=(\d+)/', $line, $m)) $outNum = $m[1];
        if (strpos($line, '_ROUTENAME=') !== false && preg_match('/_ROUTENAME=(.+?)"/', $line, $m)) $routeName = $m[1];
        if (strpos($line, 'Called PJSIP/') !== false && strpos($line, '@') !== false) {
            if (preg_match('/Called PJSIP\/[^@]+@([^\s,)]+)/', $line, $m)) $outgoingTrunk = $m[1];
        }

        // === Time Condition + полное правило ===
        if (strpos($line, 'timeconditions,') !== false && preg_match('/timeconditions,(\d+),/', $line, $m)) {
            $timeCondition = $m[1];
        }
        if (strpos($line, 'GotoIfTime') !== false) {
            if (preg_match('/GotoIfTime\(.+?,\s*"([^"]+)"/', $line, $m)) {
                $timeConditionRule = $m[1];   // например: 10:00-20:00,*,*,*,Europe/Moscow
            }
        }

        // Тайминги
        if (strpos($line, 'macro-dialout-trunk') !== false && !$dialExecutedTime) {
            preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m);
            $dialExecutedTime = $m[1] ?? '';
        }
        if (strpos($line, 'is ringing') !== false && !$ringingTime) {
            preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m);
            $ringingTime = $m[1] ?? '';
        }
        if (strpos($line, 'answered') !== false) {
            if (preg_match('/PJSIP\/(\d+)-\w+ answered/', $line, $m)) $answeredBy = $m[1];
            if (!$answerTime && preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                $answerTime = $answeredTime = $m[1];
            }
        }
        if (strpos($line, 'joined') !== false && strpos($line, 'simple_bridge') !== false) $bridgeJoined = true;

        // Dialed_Extensions
        if (strpos($line, 'Called PJSIP/') !== false) {
            if (preg_match('/Called PJSIP\/(\d+)/', $line, $m)) $dialedExtensions[] = $m[1];
        }
        if (strpos($line, 'Local/') !== false && preg_match('/Local\/(\d+)@from-queue/', $line, $m)) {
            $dialedExtensions[] = $m[1];
        }
        if (strpos($line, 'EXTTOCALL=') !== false && preg_match('/EXTTOCALL=(\d+)/', $line, $m)) {
            $dialedExtensions[] = $m[1];
        }

        // Ring Group / Queue
        if (strpos($line, 'ext-group,') !== false && preg_match('/ext-group,(\d+)/', $line, $m)) {
            $ringGroups[] = $m[1];
            $finalDestinationType = 'Ring Group';
            $finalDestination = $m[1];
        }
        if (strpos($line, 'Queue(') !== false && preg_match('/Queue\(".*?", "(\d+),/', $line, $m)) {
            $queueGroups[] = $m[1];
            $finalDestinationType = 'Queue';
            $finalDestination = $m[1];
        }

        // Blacklist
        if (strpos($line, 'app-blacklist-check') !== false) $blacklistCheck = 'passed';

        // AGI + Hangup
        if (strpos($line, 'missedcallnotify.php') !== false) {
            if (strpos($line, ',ANSWER,') !== false || strpos($line, 'ANSWER,,,') !== false) $agiAnswerStatus = 'ANSWER';
            elseif (strpos($line, ',CANCEL,') !== false) $agiAnswerStatus = 'CANCEL';
        }
        if (strpos($line, 'exited non-zero') !== false || strpos($line, 'Hangup') !== false) {
            $hangupReason = trim(substr($line, strpos($line, ']') + 2));
        }
    }

    $dialedExtensions = array_unique(array_filter($dialedExtensions));

    // Тайминги
    $ringTime = $talkTime = $totalDuration = '—';
    if ($startTime && $answerTime) {
        $st = strtotime($startTime);
        $at = strtotime($answerTime);
        $ringTime = max(0, $at - $st) . ' сек';
        if ($endTime) {
            $et = strtotime($endTime);
            $talkTime = max(0, $et - $at) . ' сек';
            $totalDuration = max(0, $et - $st) . ' сек';
        }
    } elseif ($startTime && $endTime) {
        $totalDuration = (strtotime($endTime) - strtotime($startTime)) . ' сек';
    }

    // Call_Result
    $callResult = ($answeredBy || $agiAnswerStatus === 'ANSWER') ? 'ANSWERED' : 'NO_ANSWER / HANGUP_BEFORE_ANSWER';
    if ($agiAnswerStatus === 'CANCEL') $callResult = 'NO_ANSWER / CANCELLED';

    // ==================== HTML ====================
    $html = '<div class="panel panel-info">';
    $html .= '<div class="panel-heading">';
    $html .= '<h4>Звонок <strong>' . htmlspecialchars($callId) . '</strong> — <span class="label label-default">' . $type . '</span></h4>';
    $html .= '</div>';
    $html .= '<div class="panel-body">';

    $html .= '<table class="table table-condensed table-bordered">';
    $html .= '<thead><tr><th width="35%">Параметр</th><th>Значение</th></tr></thead><tbody>';

    $html .= '<tr><td><strong>Время начала звонка</strong></td><td>' . ($startTime ?: '—') . '</td></tr>';
    if ($type === 'Входящий') {
        $html .= '<tr><td><strong>DID (входящий номер)</strong></td><td>' . ($did ?: '—') . '</td></tr>';
        $html .= '<tr><td><strong>CallerID_Num</strong></td><td>' . ($callerIdNum ?: $originalNumber) . '</td></tr>';
        $html .= '<tr><td><strong>CallerID_Name</strong></td><td>' . ($callerIdName ?: '—') . '</td></tr>';
        $html .= '<tr><td><strong>Входящий транк</strong></td><td>' . ($incomingTrunkChannel ?: '—') . '</td></tr>';
    } else {
        $html .= '<tr><td><strong>FROMEXTEN</strong></td><td>' . ($fromExten ?: '—') . '</td></tr>';
        $html .= '<tr><td><strong>CALLERID(Имя)</strong></td><td>' . ($callerIdName ?: '—') . '</td></tr>';
        $html .= '<tr><td><strong>CALLERID(Номер)</strong></td><td>' . ($callerIdNum ?: '—') . '</td></tr>';
        $html .= '<tr><td><strong>Набранный номер (original)</strong></td><td>' . ($dialedNumber ?: $normalizedNumber) . '</td></tr>';
        $html .= '<tr><td><strong>Набранный номер (processed)</strong></td><td>' . ($outNum ?: '—') . '</td></tr>';
        $html .= '<tr><td><strong>Транк</strong></td><td>' . ($outgoingTrunk ?: '—') . '</td></tr>';
        $html .= '<tr><td><strong>Исходящий маршрут</strong></td><td>' . ($routeName ?: '—') . '</td></tr>';
    }
    $html .= '<tr><td><strong>Unique Call ID</strong></td><td>' . ($uniqueCallId ?: $callId) . '</td></tr>';

    $html .= '<tr><td><strong>Запись разговора</strong></td><td>' . ($recordingFile ? 'YES' : 'NO') . '</td></tr>';
    $html .= '<tr><td><strong>Файл записи разговора</strong></td><td>' . ($recordingFile ?: '—') . '</td></tr>';

    // IVR и Direct Extension
    if ($ivrContext) {
        $html .= '<tr><td><strong>IVR</strong></td><td>' . $ivrContext . '</td></tr>';
    }
    if ($directExtension) {
        $html .= '<tr><td><strong>Direct Extension</strong></td><td>' . $directExtension . '</td></tr>';
    }

    if (!empty($dialedExtensions)) {
        $html .= '<tr><td><strong>Звонили номера</strong></td><td>' . implode(', ', $dialedExtensions) . '</td></tr>';
    }

    if ($type === 'Входящий') {
        // Time_Condition
        $tcDisplay = $timeCondition ?: '—';
        if ($timeConditionRule) {
            $tcDisplay .= ' (' . $timeConditionRule . ')';
        }
        $html .= '<tr><td><strong>TimeCondition</strong></td><td>' . $tcDisplay . '</td></tr>';

        $html .= '<tr><td><strong>Blacklist_Check</strong></td><td>' . $blacklistCheck . '</td></tr>';
        if ($finalDestinationType && !$ivrContext && !$directExtension) {
            $html .= '<tr><td><strong>Final_Destination_Type</strong></td><td>' . $finalDestinationType . '</td></tr>';
            $html .= '<tr><td><strong>Final_Destination</strong></td><td>' . $finalDestination . '</td></tr>';
        }
    }
    if (!empty($ringGroups) || !empty($queueGroups)) {
        $html .= '<tr><td><strong>Ring Group / Queue</strong></td><td>' . implode(' → ', array_merge($ringGroups, $queueGroups)) . '</td></tr>';
    }

    $html .= '<tr><td><strong>Кто ответил</strong></td><td>' . ($answeredBy ?: '—') . '</td></tr>';
    $html .= '<tr><td><strong>Время дозвона</strong></td><td>' . $ringTime . '</td></tr>';
    $html .= '<tr><td><strong>Время разговора</strong></td><td>' . $talkTime . '</td></tr>';
    $html .= '<tr><td><strong>Общая продолжительность звонка</strong></td><td>' . $totalDuration . '</td></tr>';
    if ($dialExecutedTime) $html .= '<tr><td><strong>Dial executed</strong></td><td>' . $dialExecutedTime . '</td></tr>';
    if ($ringingTime) $html .= '<tr><td><strong>Progress / Ringing</strong></td><td>' . $ringingTime . '</td></tr>';
    if ($answeredTime) $html .= '<tr><td><strong>Answered</strong></td><td>' . $answeredTime . '</td></tr>';

    $html .= '<tr><td><strong>Результат звонка</strong></td><td>' . $callResult . '</td></tr>';
    $html .= '<tr><td><strong>Voicemail</strong></td><td>нет</td></tr>';
    if ($hangupReason) $html .= '<tr><td><strong>Причина завершения</strong></td><td>' . htmlspecialchars($hangupReason) . '</td></tr>';

    $html .= '</tbody></table>';
    $html .= '</div></div>';

    return $html;
}