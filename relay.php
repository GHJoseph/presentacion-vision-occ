<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$room = $_GET['room'] ?? '';
if (isset($_GET['ping'])) { echo '{"ok":true}'; exit; }
if (!preg_match('/^\d{6}$/', $room)) { http_response_code(400); echo '{"error":"room"}'; exit; }

$dir = dirname(__DIR__) . '/occ-relay';
if (!is_dir($dir)) mkdir($dir, 0700, true);
if (mt_rand(1, 200) === 1) {
  foreach (glob("$dir/*") as $f) if (filemtime($f) < time() - 86400) @unlink($f);
}

$op = $_GET['op'] ?? '';
$stateFile = "$dir/$room.state";
$inboxFile = "$dir/$room.inbox";

function body() {
  $raw = file_get_contents('php://input', false, null, 0, 65536);
  return json_decode($raw, true);
}

switch ($op) {
  case 'setstate':
    $msg = body();
    if (!is_array($msg)) { http_response_code(400); exit; }
    $seq = (int) round(microtime(true) * 1000);
    file_put_contents($stateFile, json_encode(['seq' => $seq, 'msg' => $msg]), LOCK_EX);
    echo json_encode(['seq' => $seq]);
    break;

  case 'getstate':
    $since = (int) ($_GET['since'] ?? 0);
    $data = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
    echo ($data && $data['seq'] > $since) ? json_encode($data) : '{"seq":0}';
    break;

  case 'send':
    $msg = body();
    if (!is_array($msg)) { http_response_code(400); exit; }
    file_put_contents($inboxFile, json_encode($msg) . "\n", FILE_APPEND | LOCK_EX);
    echo '{"ok":true}';
    break;

  case 'recv':
    $out = [];
    if (is_file($inboxFile) && ($fh = fopen($inboxFile, 'c+'))) {
      flock($fh, LOCK_EX);
      $lines = stream_get_contents($fh);
      ftruncate($fh, 0);
      flock($fh, LOCK_UN);
      fclose($fh);
      foreach (explode("\n", trim($lines)) as $l) if ($l !== '') $out[] = json_decode($l, true);
    }
    echo json_encode($out);
    break;

  default:
    http_response_code(400);
    echo '{"error":"op"}';
}
