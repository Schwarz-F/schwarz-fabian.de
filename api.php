<?php
/**
 * FABIAN.OS API v1
 * Handles config read/write, file management, backgrounds, recycle bin.
 * Place alongside index.html. Requires PHP 8.0+.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$DATA      = __DIR__ . '/data';
$CFG       = $DATA . '/config.json';
$CFG_DEF   = $DATA . '/config_default.json';
$FILES_DIR = $DATA . '/files';
$BIN_DIR   = $DATA . '/recyclebin';
$BG_DIR    = $DATA . '/backgrounds';

// Boot: ensure dirs exist
foreach ([$DATA,$FILES_DIR,$BIN_DIR,$BG_DIR,
          "$FILES_DIR/Bilder","$FILES_DIR/Dokumente","$FILES_DIR/Videos"] as $d)
    if (!is_dir($d)) mkdir($d, 0755, true);

$action = $_GET['action'] ?? $_POST['action'] ?? 'ping';

function ok($d=[])  { echo json_encode(array_merge(['ok'=>true],$d)); exit; }
function err($m,int $c=400) { http_response_code($c); echo json_encode(['error'=>$m]); exit; }
function safe_path(string $base, string $rel): string|false {
    $p = realpath($base . '/' . ltrim($rel,'./'));
    return ($p && str_starts_with($p, $base)) ? $p : false;
}
function hidden_list(string $files_dir): array {
    $f = $files_dir.'/.hidden.json';
    return file_exists($f) ? (json_decode(file_get_contents($f),true) ?: []) : [];
}

switch ($action) {

    /* ── PING ─────────────────────────────────── */
    case 'ping':
        ok(['version'=>'1.0','php'=>PHP_VERSION]);

    /* ── CONFIG ──────────────────────────────── */
    case 'read_config': {
        $def  = file_exists($CFG_DEF) ? json_decode(file_get_contents($CFG_DEF),true) ?: [] : [];
        $user = file_exists($CFG)     ? json_decode(file_get_contents($CFG),    true) ?: [] : [];
        echo json_encode(array_merge($def,$user)); exit;
    }
    case 'write_config': {
        $d = json_decode(file_get_contents('php://input'),true);
        if (!$d) err('Invalid JSON');
        file_put_contents($CFG, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        ok();
    }
    case 'write_default': {
        $d = json_decode(file_get_contents('php://input'),true);
        if (!$d) err('Invalid JSON');
        file_put_contents($CFG_DEF, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        ok();
    }

    /* ── FILE SYSTEM ─────────────────────────── */
    case 'list_tree': {
        $hidden = hidden_list($FILES_DIR);
        function scan_tree(string $dir, string $base, array $hidden): array {
            $res = [];
            foreach (@scandir($dir) ?: [] as $item) {
                if ($item[0] === '.') continue;
                $full = $dir.'/'.$item;
                $rel  = ltrim(str_replace($base,'',$full),'/');
                if (is_dir($full))
                    $res[] = ['name'=>$item,'path'=>$rel,'hidden'=>in_array($rel,$hidden),
                              'children'=>scan_tree($full,$base,$hidden)];
            }
            return $res;
        }
        echo json_encode(scan_tree($FILES_DIR,$FILES_DIR,$hidden)); exit;
    }
    case 'list_folder': {
        $folder = $_GET['folder'] ?? '';
        $base   = $folder ? safe_path($FILES_DIR,$folder) : $FILES_DIR;
        if (!$base) err('Bad path');
        $hidden = hidden_list($FILES_DIR);
        $folders = $files = [];
        foreach (@scandir($base) ?: [] as $item) {
            if ($item[0] === '.') continue;
            $full = $base.'/'.$item;
            $rel  = ltrim(str_replace($FILES_DIR,'',$full),'/');
            if (is_dir($full)) {
                $folders[] = ['name'=>$item,'path'=>$rel,'hidden'=>in_array($rel,$hidden)];
            } else {
                $ext = strtolower(pathinfo($item,PATHINFO_EXTENSION));
                if ($ext==='jpeg') $ext='jpg';
                if (in_array($ext,['jpg','png','mp4','txt'])) {
                    $url = './data/files/'.ltrim($rel,'/');
                    $folders_used = [];
                    $files[] = ['name'=>$item,'path'=>$rel,'ext'=>$ext,
                                'size'=>filesize($full),'url'=>$url];
                }
            }
        }
        echo json_encode(['folders'=>$folders,'files'=>$files]); exit;
    }
    case 'create_folder': {
        $parent = $_POST['parent'] ?? '';
        $name   = preg_replace('/[^a-zA-Z0-9_\-\s\x{00C0}-\x{024F}]/u','',$_POST['name']??'');
        if (!$name) err('Bad name');
        $base = $parent ? safe_path($FILES_DIR,$parent) : $FILES_DIR;
        if (!$base) err('Bad parent');
        $new = $base.'/'.$name;
        if (!is_dir($new)) mkdir($new,0755,true);
        $rel = ltrim(str_replace($FILES_DIR,'',$new),'/');
        ok(['path'=>$rel]);
    }
    case 'delete_path': {
        $rel  = $_POST['path'] ?? '';
        $full = safe_path($FILES_DIR,$rel);
        if (!$full) err('Bad path');
        function rrmdir(string $d): void { foreach(scandir($d) as $f){if($f[0]==='.')continue;$p=$d.'/'.$f;is_dir($p)?rrmdir($p):unlink($p);}rmdir($d); }
        if (is_file($full)) unlink($full);
        elseif (is_dir($full)) rrmdir($full);
        ok();
    }
    case 'upload_file': {
        if (!isset($_FILES['file'])) err('No file');
        $f   = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if ($ext==='jpeg') $ext='jpg';
        if (!in_array($ext,['jpg','png','mp4','txt'])) err('Invalid type');
        if ($f['size'] > 50*1024*1024) err('Too large (max 50 MB)');
        $folder = $_POST['folder'] ?? '';
        $dest   = $folder ? safe_path($FILES_DIR,$folder) : $FILES_DIR;
        if (!$dest) err('Bad folder');
        $name = preg_replace('/[^a-zA-Z0-9._-]/','_',$f['name']);
        move_uploaded_file($f['tmp_name'],$dest.'/'.$name);
        $rel = ltrim(str_replace($FILES_DIR,'',$dest.'/'.$name),'/');
        ok(['name'=>$name,'path'=>$rel,'url'=>'./data/files/'.$rel]);
    }
    case 'toggle_hidden': {
        $rel  = $_POST['path'] ?? '';
        $hf   = $FILES_DIR.'/.hidden.json';
        $list = hidden_list($FILES_DIR);
        if (($idx = array_search($rel,$list)) !== false) array_splice($list,$idx,1);
        else $list[] = $rel;
        file_put_contents($hf, json_encode(array_values($list)));
        ok(['hidden'=>$list]);
    }

    /* ── RECYCLE BIN ─────────────────────────── */
    case 'list_bin': {
        $files = [];
        foreach (@scandir($BIN_DIR) ?: [] as $f) {
            if ($f[0]==='.' || pathinfo($f,PATHINFO_EXTENSION)!=='txt') continue;
            $files[] = ['name'=>$f,'content'=>file_get_contents($BIN_DIR.'/'.$f),
                        'date'=>date('d.m.Y',filemtime($BIN_DIR.'/'.$f))];
        }
        echo json_encode($files); exit;
    }
    case 'save_bin_file': {
        $name    = preg_replace('/[^a-zA-Z0-9._-]/','_',$_POST['name']??'file.txt');
        if (!str_ends_with($name,'.txt')) $name .= '.txt';
        $content = $_POST['content'] ?? '';
        file_put_contents($BIN_DIR.'/'.$name,$content);
        ok(['name'=>$name]);
    }
    case 'delete_bin_file': {
        $name = basename($_POST['name']??'');
        $p    = $BIN_DIR.'/'.$name;
        if (file_exists($p)) unlink($p);
        ok();
    }

    /* ── BACKGROUNDS ─────────────────────────── */
    case 'upload_bg': {
        if (!isset($_FILES['file'])) err('No file');
        $f   = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if (!in_array($ext,['jpg','jpeg','png','webp','gif'])) err('Invalid type');
        $name = 'bg_'.time().'.'.$ext;
        move_uploaded_file($f['tmp_name'],$BG_DIR.'/'.$name);
        ok(['url'=>'./data/backgrounds/'.$name,'name'=>$name]);
    }
    case 'list_bgs': {
        $files = [];
        foreach (@scandir($BG_DIR) ?: [] as $f) {
            if ($f[0]==='.') continue;
            $ext = strtolower(pathinfo($f,PATHINFO_EXTENSION));
            if (in_array($ext,['jpg','jpeg','png','webp','gif']))
                $files[] = ['name'=>$f,'url'=>'./data/backgrounds/'.$f];
        }
        echo json_encode($files); exit;
    }
    case 'delete_bg': {
        $name = basename($_POST['name']??'');
        $p = $BG_DIR.'/'.$name;
        if (file_exists($p)) unlink($p);
        ok();
    }

    default: err("Unknown action: $action", 404);
}
