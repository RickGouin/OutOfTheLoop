<?php
/**
 * Project Name: OutOfTheLoopAI
 * Description: Create a conversation between 2 different AI services
 * License: GPLv3
 * Version: 1.0
 * Author: Rick Gouin
 * Website: http://www.rickgouin.com
 */
/* ============================
   0) CONFIG: Load keys from environment
   ============================ */
$envPath = __DIR__.'/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        $pair = explode('=', $line, 2);
        if (count($pair) === 2 && !isset($_ENV[$pair[0]])) {
            $_ENV[$pair[0]] = trim($pair[1], "'\"");
        }
    }
}
$OPENAI_API_KEY    = $_ENV['OPENAI_API_KEY'] ?? '';
$GEMINI_API_KEY    = $_ENV['GEMINI_API_KEY'] ?? '';
$ANTHROPIC_API_KEY = $_ENV['ANTHROPIC_API_KEY'] ?? '';
$OLLAMA_HOST       = $_ENV['OLLAMA_HOST'] ?? 'http://localhost:11434';   // or your remote Ollama endpoint

/* ===================================================
   1) SIMPLE ROUTER FOR AJAX ACTIONS (JSON responses)
   =================================================== */
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'list_models') {
        header('Content-Type: application/json; charset=utf-8');
        $provider = $_GET['provider'] ?? '';
        echo json_encode(list_models($provider));
        exit;
    }

    if ($action === 'chat') {
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $provider = $payload['provider'] ?? '';
        $model    = $payload['model'] ?? '';
        $messages = $payload['messages'] ?? [];   // array of {role, content}
        $system   = $payload['system'] ?? null;   // optional
        $stream   = !empty($payload['stream']);
        if ($stream) {
            header('Content-Type: application/x-ndjson; charset=utf-8');
            chat_with_provider($provider, $model, $messages, $system, true);
        } else {
            header('Content-Type: application/json; charset=utf-8');
            $res = chat_with_provider($provider, $model, $messages, $system, false);
            echo json_encode($res);
        }
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

/* ======================================
   2) PROVIDER HELPERS (list + generate)
   ====================================== */
function list_models($provider) {
    global $OPENAI_API_KEY, $GEMINI_API_KEY, $OLLAMA_HOST, $ANTHROPIC_API_KEY;

    try {
        if ($provider === 'OpenAI') {
            // If no key, provide a sane fallback list so UI still works
            if (!$OPENAI_API_KEY || stripos($OPENAI_API_KEY, 'REPLACE') !== false) {
                return ['ok' => true, 'models' => [
                    'gpt-4o', 'gpt-4o-mini', 'o4-mini', 'gpt-4.1', 'gpt-4.1-mini'
                ], 'note' => 'OpenAI key not set; showing a fallback model list.'];
            }
            $resp = http_request('GET', 'https://api.openai.com/v1/models', null, [
                "Authorization: Bearer $OPENAI_API_KEY"
            ]);
            if ($resp['status'] >= 400) {
                return ['ok' => false, 'error' => 'OpenAI /v1/models error: '.$resp['body']];
            }
            $data = json_decode($resp['body'], true);
            $names = [];
            foreach ($data['data'] ?? [] as $m) {
                if (!empty($m['id'])) { $names[] = $m['id']; }
            }
            // optional: prefer commonly chat-capable names at the top
            usort($names, function($a,$b){
                $prio = ['gpt-4o','gpt-4o-mini','o4','o4-mini','gpt-4.1','gpt-4.1-mini'];
                $pa = array_search($a,$prio); $pb = array_search($b,$prio);
                if ($pa===false) $pa = 9999; if ($pb===false) $pb = 9999;
                if ($pa === $pb) return strcmp($a,$b);
                return $pa <=> $pb;
            });
            // de-dup just in case
            $names = array_values(array_unique($names));
            if (!$names) {
                return ['ok'=>false,'error'=>'OpenAI returned no models. Check account access.'];
            }
            return ['ok' => true, 'models' => $names];
        }

        if ($provider === 'Gemini') {
            if (!$GEMINI_API_KEY || stripos($GEMINI_API_KEY,'REPLACE') !== false) {
                return ['ok' => true, 'models' => [
                    'gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-1.0-pro'
                ], 'note' => 'Gemini key not set; showing a fallback model list.'];
            }
            $url = "https://generativelanguage.googleapis.com/v1beta/models?key=$GEMINI_API_KEY";
            $resp = http_request('GET', $url);
            if ($resp['status'] >= 400) {
                return ['ok' => false, 'error' => 'Gemini models error: '.$resp['body']];
            }
            $data = json_decode($resp['body'], true);
            $names = [];
            foreach ($data['models'] ?? [] as $m) {
                if (!empty($m['name'])) {
                    // names look like "models/gemini-1.5-flash"
                    $parts = explode('/', $m['name']);
                    $names[] = end($parts);
                }
            }
            sort($names);
            if (!$names) return ['ok'=>false,'error'=>'Gemini returned no models.'];
            return ['ok' => true, 'models' => $names];
        }

        if ($provider === 'Claude') {
            if (!$ANTHROPIC_API_KEY || stripos($ANTHROPIC_API_KEY,'REPLACE') !== false) {
                return ['ok' => true, 'models' => [
                    'claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku'
                ], 'note' => 'Anthropic key not set; showing a fallback model list.'];
            }
            $resp = http_request('GET', 'https://api.anthropic.com/v1/models', null, [
                'x-api-key: '.$ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01'
            ]);
            if ($resp['status'] >= 400) {
                return ['ok' => false, 'error' => 'Claude models error: '.$resp['body']];
            }
            $data = json_decode($resp['body'], true);
            $names = [];
            foreach ($data['data'] ?? [] as $m) {
                if (!empty($m['id'])) $names[] = $m['id'];
            }
            sort($names);
            if (!$names) return ['ok'=>false,'error'=>'Claude returned no models.'];
            return ['ok'=>true,'models'=>$names];
        }

        if ($provider === 'Ollama') {
            $resp = http_request('POST', rtrim($OLLAMA_HOST,'/').'/api/tags', '{}', [
                'Content-Type: application/json'
            ]);
            if ($resp['status'] >= 400) {
                return ['ok' => false, 'error' => 'Ollama /api/tags error: '.$resp['body']];
            }
            $data = json_decode($resp['body'], true);
            $names = [];
            foreach ($data['models'] ?? [] as $m) {
                if (!empty($m['name'])) $names[] = $m['name'];
            }
            sort($names);
            if (!$names) return ['ok'=>false,'error'=>'No Ollama models found. Did you pull any (e.g. `ollama pull llama3`)?'];
            return ['ok' => true, 'models' => $names];
        }

        return ['ok' => false, 'error' => 'Unknown provider'];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'Model list failed: '.$e->getMessage()];
    }
}

function chat_with_provider($provider, $model, $messages, $system = null, $stream = false) {
    try {
        if ($provider === 'OpenAI')  return chat_openai($model, $messages, $system, $stream);
        if ($provider === 'Gemini')  return chat_gemini($model, $messages, $system, $stream);
        if ($provider === 'Claude')  return chat_claude($model, $messages, $system, $stream);
        if ($provider === 'Ollama')  return chat_ollama($model, $messages, $system, $stream);
        if ($stream) {
            echo json_encode(['error' => 'Unknown provider', 'done' => true])."\n";
            return;
        }
        return ['ok' => false, 'error' => 'Unknown provider'];
    } catch (Exception $e) {
        if ($stream) {
            echo json_encode(['error' => $e->getMessage(), 'done' => true])."\n";
            return;
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function chat_openai($model, $messages, $system = null, $stream = false) {
    global $OPENAI_API_KEY;
    $sim = "[Simulated OpenAI:$model] ".truncate_prompt($messages);
    if (!$OPENAI_API_KEY || stripos($OPENAI_API_KEY,'REPLACE') !== false) {
        if ($stream) {
            foreach (preg_split('/(\s+)/', $sim, -1, PREG_SPLIT_DELIM_CAPTURE) as $tok) {
                if ($tok === '') continue;
                echo json_encode(['delta' => $tok])."\n";
                @ob_flush(); flush();
            }
            echo json_encode(['done' => true, 'ok' => true, 'text' => $sim])."\n";
            return;
        }
        return ['ok' => true, 'provider' => 'OpenAI', 'text' => $sim];
    }
    $url = 'https://api.openai.com/v1/chat/completions';
    $payload = [
        'model' => $model,
        'messages' => []
    ];
    if ($system) $payload['messages'][] = ['role' => 'system', 'content' => $system];
    foreach ($messages as $m) {
        $payload['messages'][] = ['role' => $m['role'], 'content' => $m['content']];
    }

    if ($stream) {
        $payload['stream'] = true;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer '.$OPENAI_API_KEY,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        $full = '';
        $buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$full) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || substr($line, 0, 5) !== 'data:') continue;
                $json = trim(substr($line, 5));
                if ($json === '[DONE]') continue;
                $obj = json_decode($json, true);
                $delta = $obj['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $full .= $delta;
                    echo json_encode(['delta' => $delta])."\n";
                    @ob_flush(); flush();
                }
            }
            return strlen($data);
        });
        curl_exec($ch);
        if ($err = curl_error($ch)) {
            echo json_encode(['error' => $err])."\n";
        }
        curl_close($ch);
        echo json_encode(['done' => true, 'ok' => true, 'text' => $full])."\n";
        @ob_flush(); flush();
        return;
    }

    $resp = http_request('POST', $url, json_encode($payload), [
        'Authorization: Bearer '.$OPENAI_API_KEY,
        'Content-Type: application/json'
    ]);
    if ($resp['status'] >= 400) return ['ok'=>false,'error'=>"OpenAI error: ".$resp['body']];
    $data = json_decode($resp['body'], true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    return ['ok' => true, 'provider' => 'OpenAI', 'text' => $text];
}

function chat_gemini($model, $messages, $system = null, $stream = false) {
    global $GEMINI_API_KEY;
    $sim = "[Simulated Gemini:$model] ".truncate_prompt($messages);
    if (!$GEMINI_API_KEY || stripos($GEMINI_API_KEY,'REPLACE') !== false) {
        if ($stream) {
            foreach (preg_split('/(\s+)/', $sim, -1, PREG_SPLIT_DELIM_CAPTURE) as $tok) {
                if ($tok === '') continue;
                echo json_encode(['delta' => $tok])."\n";
                @ob_flush(); flush();
            }
            echo json_encode(['done' => true, 'ok' => true, 'text' => $sim])."\n";
            return;
        }
        return ['ok' => true, 'provider' => 'Gemini', 'text' => $sim];
    }
    $flat = flatten_messages($messages, $system);
    if ($stream) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/".rawurlencode($model).":streamGenerateContent?key=".$GEMINI_API_KEY;
        $payload = [
            "contents" => [
                ["role" => "user", "parts" => [["text" => $flat]]]
            ]
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        $full = '';
        $buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$full) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') continue;
                if (substr($line, 0, 5) === 'data:') {
                    $line = trim(substr($line, 5));
                }
                $obj = json_decode($line, true);
                $delta = $obj['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if ($delta !== '') {
                    $full .= $delta;
                    echo json_encode(['delta' => $delta])."\n";
                    @ob_flush(); flush();
                }
            }
            return strlen($data);
        });
        curl_exec($ch);
        if ($err = curl_error($ch)) {
            echo json_encode(['error' => $err])."\n";
        }
        curl_close($ch);
        echo json_encode(['done' => true, 'ok' => true, 'text' => $full])."\n";
        @ob_flush(); flush();
        return;
    }
    $url = "https://generativelanguage.googleapis.com/v1beta/models/".rawurlencode($model).":generateContent?key=".$GEMINI_API_KEY;
    $payload = [
        "contents" => [
            ["role" => "user", "parts" => [["text" => $flat]]]
        ]
    ];
    $resp = http_request('POST', $url, json_encode($payload), ['Content-Type: application/json']);
    if ($resp['status'] >= 400) return ['ok'=>false,'error'=>"Gemini error: ".$resp['body']];
    $data = json_decode($resp['body'], true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    return ['ok' => true, 'provider' => 'Gemini', 'text' => $text];
}

function chat_claude($model, $messages, $system = null, $stream = false) {
    global $ANTHROPIC_API_KEY;
    $sim = "[Simulated Claude:$model] ".truncate_prompt($messages);
    if (!$ANTHROPIC_API_KEY || stripos($ANTHROPIC_API_KEY,'REPLACE') !== false) {
        if ($stream) {
            foreach (preg_split('/(\s+)/', $sim, -1, PREG_SPLIT_DELIM_CAPTURE) as $tok) {
                if ($tok === '') continue;
                echo json_encode(['delta' => $tok])."\n";
                @ob_flush(); flush();
            }
            echo json_encode(['done' => true, 'ok' => true, 'text' => $sim])."\n";
            return;
        }
        return ['ok' => true, 'provider' => 'Claude', 'text' => $sim];
    }
    $payload = [
        'model' => $model,
        'max_tokens' => 1024,
        'messages' => []
    ];
    foreach ($messages as $m) {
        $payload['messages'][] = [
            'role' => $m['role'],
            'content' => [['type' => 'text', 'text' => $m['content']]]
        ];
    }
    if ($system) $payload['system'] = $system;
    if ($stream) {
        $payload['stream'] = true;
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: '.$ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        $full = '';
        $buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$full) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || substr($line, 0, 5) !== 'data:') continue;
                $json = trim(substr($line, 5));
                if ($json === '[DONE]') continue;
                $obj = json_decode($json, true);
                $delta = $obj['delta']['text'] ?? '';
                if ($delta !== '') {
                    $full .= $delta;
                    echo json_encode(['delta' => $delta])."\n";
                    @ob_flush(); flush();
                }
            }
            return strlen($data);
        });
        curl_exec($ch);
        if ($err = curl_error($ch)) {
            echo json_encode(['error' => $err])."\n";
        }
        curl_close($ch);
        echo json_encode(['done' => true, 'ok' => true, 'text' => $full])."\n";
        @ob_flush(); flush();
        return;
    }
    $resp = http_request('POST', 'https://api.anthropic.com/v1/messages', json_encode($payload), [
        'x-api-key: '.$ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json'
    ]);
    if ($resp['status'] >= 400) return ['ok'=>false,'error'=>'Claude error: '.$resp['body']];
    $data = json_decode($resp['body'], true);
    $text = $data['content'][0]['text'] ?? '';
    return ['ok' => true, 'provider' => 'Claude', 'text' => $text];
}

function chat_ollama($model, $messages, $system = null, $stream = false) {
    global $OLLAMA_HOST;
    $flat = flatten_messages($messages, $system);
    if ($stream) {
        $payload = [
            "model" => $model,
            "prompt" => $flat,
            "stream" => true
        ];
        $ch = curl_init(rtrim($OLLAMA_HOST,'/').'/api/generate');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        $full = '';
        $buffer = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$full) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') continue;
                $obj = json_decode($line, true);
                if (!$obj) continue;
                $delta = $obj['response'] ?? '';
                if ($delta !== '') {
                    $full .= $delta;
                    echo json_encode(['delta' => $delta])."\n";
                    @ob_flush(); flush();
                }
            }
            return strlen($data);
        });
        curl_exec($ch);
        if ($err = curl_error($ch)) {
            echo json_encode(['error' => $err])."\n";
        }
        curl_close($ch);
        echo json_encode(['done' => true, 'ok' => true, 'text' => $full])."\n";
        @ob_flush(); flush();
        return;
    }
    $payload = [
        "model" => $model,
        "prompt" => $flat,
        "stream" => false
    ];
    $resp = http_request('POST', rtrim($OLLAMA_HOST,'/').'/api/generate', json_encode($payload), [
        'Content-Type: application/json'
    ]);
    if ($resp['status'] >= 400) return ['ok'=>false,'error'=>"Ollama error: ".$resp['body']];
    $data = json_decode($resp['body'], true);
    $text = $data['response'] ?? '';
    return ['ok' => true, 'provider' => 'Ollama', 'text' => $text];
}

/* ===========================
   3) SHARED UTILITIES
   =========================== */
function http_request($method, $url, $body = null, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $respBody = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($respBody === false) {
        return ['status' => 500, 'body' => $err ?: 'Unknown cURL error'];
    }
    return ['status' => $status, 'body' => $respBody];
}

function flatten_messages($messages, $system = null) {
    $buf = '';
    if ($system) $buf .= "System:\n$system\n\n";
    foreach ($messages as $m) {
        $role = ucfirst($m['role']);
        $buf .= "$role:\n".$m['content']."\n\n";
    }
    return trim($buf);
}

function truncate_prompt($messages) {
    $flat = flatten_messages($messages);
    if (mb_strlen($flat) > 300) $flat = mb_substr($flat, 0, 300).'…';
    return $flat;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>OutOfTheLoopAI</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- No external JS dependencies; vanilla JavaScript only -->

  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; background: #f5f7fb; }
    .wrap { max-width: 1080px; margin: 24px auto; padding: 16px; }
    h1 { font-weight: 700; letter-spacing: 0.2px; margin: 0 0 16px; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .field { margin-bottom: 12px; }
    label { display:block; font-size: 12px; color:#444; margin-bottom:4px; }
    textarea { width: 100%; min-height: 96px; }
    #controls, #conversation, #about { margin-top: 16px; }
    #conversation { background: #fff; border-radius: 12px; padding: 12px; border: 1px solid #e3e7ef; }
    .bubble { max-width: 72%; padding: 10px 12px; border-radius: 12px; margin: 8px 0; line-height: 1.4; white-space: pre-wrap; }
    .left  { background: #f0f4ff; color: #243b6b; border: 1px solid #dbe5ff; margin-right: auto; }
    .right { background: #e6fff0; color: #1b5e20; border: 1px solid #c7f5d7; margin-left: auto; }
    .meta { font-size: 11px; color: #666; margin-top: 2px; }
    .toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .provider-card { background: #fff; border:1px solid #e3e7ef; border-radius:12px; padding:12px; }
    .card { background:#fff; border:1px solid #e3e7ef; border-radius:12px; }
    .card-header { background:#f5f7fb; border-radius:8px; }
    select, textarea, button { border-radius:8px; }
    button { border-radius:10px; }
    .faded { opacity: 0.7; }
    .error { background:#fff4f4; border:1px solid #f5cccc; color:#9b1c1c; padding:10px; border-radius:8px; margin:8px 0; }
    .success { background:#eefbf1; border:1px solid #c7f5d7; color:#1b5e20; padding:10px; border-radius:8px; margin:8px 0; }
    footer { margin:24px 0 8px; color:#777; font-size:12px; text-align:center; }
    .small { font-size: 12px; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>OutOfTheLoopAI</h1>

  <div id="about" class="small faded">
    Pick two providers/models. The initial prompt goes to AI #1. Its reply plus your follow-up prompt is sent to AI #2. That pair is one iteration. Choose 1–10 iterations to watch them go back and forth (default 3).
  </div>

  <div id="controls" class="card" style="padding:16px; margin-top:12px;">
    <div class="row">
      <div class="provider-card">
        <h3 class="card-header" style="padding:8px;">AI #1 (starter)</h3>
        <div class="field">
          <label for="prov1">Provider</label>
          <select id="prov1">
            <option value="">— choose —</option>
            <option>OpenAI</option>
            <option>Ollama</option>
            <option>Gemini</option>
            <option>Claude</option>
          </select>
        </div>
        <div class="field">
          <label for="model1">Model</label>
          <select id="model1"><option value="">— select provider first —</option></select>
        </div>
        <div class="field">
          <label for="role1">Role</label>
          <select id="role1">
            <option value="">— none —</option>
            <option value="Skeptic">Skeptic</option>
            <option value="Visionary">Visionary</option>
            <option value="Pragmatist">Pragmatist</option>
            <option value="Critic">Critic</option>
            <option value="Mentor">Mentor</option>
          </select>
        </div>
      </div>

      <div class="provider-card">
        <h3 class="card-header" style="padding:8px;">AI #2 (responder)</h3>
        <div class="field">
          <label for="prov2">Provider</label>
          <select id="prov2">
            <option value="">— choose —</option>
            <option>OpenAI</option>
            <option>Ollama</option>
            <option>Gemini</option>
            <option>Claude</option>
          </select>
        </div>
        <div class="field">
          <label for="model2">Model</label>
          <select id="model2"><option value="">— select provider first —</option></select>
        </div>
        <div class="field">
          <label for="role2">Role</label>
          <select id="role2">
            <option value="">— none —</option>
            <option value="Skeptic">Skeptic</option>
            <option value="Visionary">Visionary</option>
            <option value="Pragmatist">Pragmatist</option>
            <option value="Critic">Critic</option>
            <option value="Mentor">Mentor</option>
          </select>
        </div>
      </div>
    </div>

    <div class="row" style="margin-top:8px;">
      <div class="field">
        <label for="init">Initial prompt (sent to AI #1)</label>
        <textarea id="init" placeholder="e.g., Debate the pros and cons of remote work."></textarea>
      </div>
      <div class="field">
        <label for="follow">Follow-up prompt (appended to AI #1 reply, sent to AI #2)</label>
        <textarea id="follow" placeholder="e.g., Please critique and propose counterarguments with sources."></textarea>
      </div>
    </div>

    <div class="toolbar" style="margin-top:8px;">
      <label for="iters">Iterations</label>
      <select id="iters">
        <?php for($i=1;$i<=10;$i++){ $sel = ($i===3?' selected':''); echo "<option value=\"$i\"$sel>$i</option>"; } ?>
      </select>
        <button id="startBtn">Start Conversation</button>
        <button id="stopBtn">Stop</button>
        <button id="downloadBtn" style="display:none;">Download Conversation</button>
      <span id="status" class="small faded"></span>
    </div>
  </div>

  <div id="conversation">
    <!-- Bubbles go here -->
  </div>

  <footer>Tip: set your API keys near the top of this file. Ollama needs a running daemon and local models installed.</footer>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const startBtn = document.getElementById('startBtn');
    const stopBtn = document.getElementById('stopBtn');
    const downloadBtn = document.getElementById('downloadBtn');
    const conversation = document.getElementById('conversation');
    const statusEl = document.getElementById('status');

    let stopFlag = false;
    let transcript = '';
    const ROLE_PROMPTS = {
      'Skeptic': 'You are a skeptical scientist. You carefully question every claim, look for evidence, and challenge assumptions. You rarely accept ideas at face value, and you always demand clarity and rigor.',
      'Visionary': 'You are a visionary futurist. You are enthusiastic about bold ideas, paint vivid pictures of possible futures, and focus on possibilities rather than limitations. You encourage expansive, imaginative thinking.',
      'Pragmatist': 'You are a pragmatic engineer. You focus on what is practical, implementable, and efficient. You prefer clear steps and concrete outcomes over speculation or theory.',
      'Critic': 'You are a sharp critic. You analyze arguments and proposals with a critical eye, highlighting flaws, weaknesses, and potential risks. You value precision and are not afraid to point out problems.',
      'Mentor': 'You are a supportive mentor. You provide encouragement, constructive feedback, and helpful guidance. You are patient, empathetic, and focused on helping the other person grow.'
    };

  const prov1El = document.getElementById('prov1');
  const prov2El = document.getElementById('prov2');
  const model1El = document.getElementById('model1');
  const model2El = document.getElementById('model2');
  const role1El = document.getElementById('role1');
  const role2El = document.getElementById('role2');
  const initEl = document.getElementById('init');
  const followEl = document.getElementById('follow');
  const itersEl = document.getElementById('iters');

  prov1El.addEventListener('change', () => fetchModels(prov1El.value, model1El));
  prov2El.addEventListener('change', () => fetchModels(prov2El.value, model2El));

  async function fetchModels(provider, target) {
    if (!provider) {
      target.innerHTML = '';
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = '— select provider first —';
      target.appendChild(opt);
      return;
    }
    target.innerHTML = '';
    const loading = document.createElement('option');
    loading.value = '';
    loading.textContent = 'Loading…';
    target.appendChild(loading);

    try {
      const res = await fetch(`?action=list_models&provider=${encodeURIComponent(provider)}`);
      const data = await res.json();
      target.innerHTML = '';
      if (data.ok) {
        if (data.note) prependMessage('success', data.note);
        if (!data.models || data.models.length === 0) {
          const opt = document.createElement('option');
          opt.value = '';
          opt.textContent = 'No models found';
          target.appendChild(opt);
        } else {
          const first = document.createElement('option');
          first.value = '';
          first.textContent = '— choose model —';
          target.appendChild(first);
          data.models.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m;
            opt.textContent = m;
            target.appendChild(opt);
          });
        }
      } else {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'Error loading models';
        target.appendChild(opt);
        prependMessage('error', data.error || 'Unknown error while loading models.');
      }
    } catch {
      target.innerHTML = '';
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Network error';
      target.appendChild(opt);
      prependMessage('error', 'Network error while loading models.');
    }
  }

  function createMessage(cls, text) {
    const div = document.createElement('div');
    div.className = cls;
    div.textContent = text;
    return div;
  }

  function prependMessage(cls, text) {
    conversation.insertBefore(createMessage(cls, text), conversation.firstChild);
  }

  function addBubble(side, info, text) {
    const b = document.createElement('div');
    b.className = `bubble ${side === 'left' ? 'left' : 'right'}`;
    b.textContent = text;
    const meta = document.createElement('div');
    meta.className = 'meta';
    const parts = [];
    if (info.who) parts.push(info.who);
    if (info.provider) parts.push(info.provider);
    if (info.model) parts.push(info.model);
    if (info.role) parts.push(info.role);
    meta.textContent = `${parts.join(' · ')} • ${new Date().toLocaleString()}`;
    const wrap = document.createElement('div');
    wrap.appendChild(b);
    wrap.appendChild(meta);
    conversation.appendChild(wrap);
    window.scrollTo(0, document.body.scrollHeight);
    return b;
  }

  function validateForm() {
    const p1 = prov1El.value, m1 = model1El.value;
    const p2 = prov2El.value, m2 = model2El.value;
    const init = initEl.value.trim();
    const follow = followEl.value.trim();
    if (!p1 || !m1 || !p2 || !m2) return 'Please choose both providers and models.';
    if (!init) return 'Please enter an initial prompt.';
    if (!follow) return 'Please enter a follow-up prompt.';
    return null;
  }

  startBtn.addEventListener('click', async () => {
    const err = validateForm();
    if (err) { prependMessage('error', err); return; }

    stopFlag = false;
    statusEl.textContent = 'Running…';
    startBtn.disabled = true;
    downloadBtn.style.display = 'none';

    const prov1 = prov1El.value, model1 = model1El.value;
    const prov2 = prov2El.value, model2 = model2El.value;
    const role1 = role1El.value, role2 = role2El.value;
    const system1 = ROLE_PROMPTS[role1] || '';
    const system2 = ROLE_PROMPTS[role2] || '';
    const iters = parseInt(itersEl.value, 10);
    const init = initEl.value.trim();
    const follow = followEl.value.trim();

    conversation.innerHTML = '';
    addBubble('right', { who: 'You', role: 'initial' }, init);

    transcript = `[${new Date().toLocaleString()}] You (initial):\n` + init;

    for (let i = 1; i <= iters; i++) {
      if (stopFlag) break;

      const bubble1 = addBubble('left', { who: 'AI #1', provider: prov1, model: model1, role: role1 }, '');
      const res1 = await sendChat(prov1, model1, transcript, system1, chunk => {
        bubble1.textContent += chunk;
      });
      if (!res1 || !res1.ok) {
        conversation.appendChild(createMessage('error', (res1 && res1.error) ? res1.error : 'AI #1 error'));
        break;
      }
      const text1 = res1.text || '';

      if (stopFlag) break;

      const toSecond = text1 + '\n\n' + follow;
      const bubble2 = addBubble('right', { who: 'AI #2', provider: prov2, model: model2, role: role2 }, '');
      const res2 = await sendChat(prov2, model2, toSecond, system2, chunk => {
        bubble2.textContent += chunk;
      });
      if (!res2 || !res2.ok) {
        conversation.appendChild(createMessage('error', (res2 && res2.error) ? res2.error : 'AI #2 error'));
        break;
      }
      const text2 = res2.text || '';

      transcript += `\n\n[${new Date().toLocaleString()}] AI #1 (${prov1} · ${model1}${role1 ? ' · ' + role1 : ''}):\n${text1}`;
      transcript += `\n\n[${new Date().toLocaleString()}] AI #2 (${prov2} · ${model2}${role2 ? ' · ' + role2 : ''}):\n${text2}`;
    }

      statusEl.textContent = stopFlag ? 'Stopped.' : 'Done.';
      startBtn.disabled = false;
      if (transcript) downloadBtn.style.display = 'inline-block';
  });

    stopBtn.addEventListener('click', () => {
      stopFlag = true;
      if (transcript) downloadBtn.style.display = 'inline-block';
    });

    downloadBtn.addEventListener('click', () => {
      const blob = new Blob([transcript], { type: 'text/plain' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'conversation.txt';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    });

  async function sendChat(provider, model, text, system, onDelta) {
    const body = {
      provider,
      model,
      stream: true,
      messages: [{ role: 'user', content: text }]
    };
    if (system) body.system = system;
    try {
      const res = await fetch('?action=chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      if (!res.body) return { ok: false, error: 'No stream' };
      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buf = '', full = '';
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += decoder.decode(value, { stream: true });
        let lines = buf.split('\n');
        buf = lines.pop();
        for (const line of lines) {
          if (!line.trim()) continue;
          let obj;
          try { obj = JSON.parse(line); } catch { continue; }
          if (obj.delta) {
            full += obj.delta;
            if (onDelta) onDelta(obj.delta);
          }
          if (obj.error) return { ok: false, error: obj.error };
          if (obj.done) {
            return { ok: obj.ok !== false, text: obj.text || full };
          }
        }
      }
      return { ok: false, error: 'Stream ended unexpectedly' };
    } catch (e) {
      return { ok: false, error: e?.message || 'Network error' };
    }
  }
});
</script>
</body>
</html>
