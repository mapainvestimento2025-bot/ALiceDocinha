<?php
/*
|==========================================================
| proxy.php — Proxy HLS/MP4 para CDNs permitidos (v2)
|==========================================================
| Uso:   proxy.php?url=URL_ENCODADA
| Debug: proxy.php?url=URL_ENCODADA&debug=1  (mostra JSON com o diagnóstico)
|
| Correções desta versão:
|  - Só envia os cabeçalhos da resposta FINAL (antes enviava também os do
|    redirecionamento 301/302, o que corrompia Content-Length/Content-Type).
|  - Se a origem responder erro (403/404/5xx) ou HTML, devolve o código correto
|    em vez de "200 + página de erro" (que fazia o <video> falhar).
|  - Tenta novamente com outros Referer/Origin se a origem recusar.
|  - Suporta Range (seek) em MP4, funciona no celular (Android/iOS).
|  - HLS: resolve URLs relativas, absolutas e com query string.
|==========================================================
*/

@ini_set('zlib.output_compression', '0');
@ini_set('display_errors', '0');
@set_time_limit(0);
ignore_user_abort(false);

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'], true)) {
    http_response_code(405);
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$url   = trim((string)($_GET['url'] ?? ''));
$debug = isset($_GET['debug']);

if ($url === '') {
    http_response_code(400);
    exit('URL ausente.');
}

/* ---------- Validação do domínio ---------- */
$partes = parse_url($url);
$host   = strtolower((string)($partes['host'] ?? ''));
$scheme = strtolower((string)($partes['scheme'] ?? ''));

$dominiosPermitidos = [
    'api.anivideo.net',
    'cdn-sv01.maximaimg.online',
    'cdn-sv02.maximaimg.online',
    'cdn-sv03.maximaimg.online',
    'maximaimg.online',
    'animesdigital.org',
    'rebornpkm.blogspot.com',
];

$permitido = false;
foreach ($dominiosPermitidos as $d) {
    if ($host === $d || str_ends_with($host, '.' . $d)) {
        $permitido = true;
        break;
    }
}

if (!$permitido || $scheme !== 'https') {
    http_response_code(403);
    exit('Domínio não permitido.');
}

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

/* Combinações de Referer/Origin tentadas em ordem */
$perfis = [
    ['https://animesdigital.org/', 'https://animesdigital.org'],
    ['https://rebornpkm.blogspot.com/', 'https://rebornpkm.blogspot.com'],
    [null, null],
];

$ehMp4 = (bool)preg_match('/\.mp4(\?|$|\/)/i', $url);

/* ---------- Resolve URL relativa (para HLS) ---------- */
function resolverUrl(string $base, string $rel): string
{
    if (preg_match('#^https?://#i', $rel)) return $rel;
    $p = parse_url($base);
    $origem = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($rel, '//')) return $p['scheme'] . ':' . $rel;
    if (str_starts_with($rel, '/'))  return $origem . $rel;
    $dir = rtrim(dirname($p['path'] ?? '/'), '/');
    return $origem . $dir . '/' . $rel;
}

function viaProxy(string $u): string
{
    return 'proxy.php?url=' . rawurlencode($u);
}

/* ---------- Requisição (stream para MP4 / memória para HLS) ---------- */
/**
 * Se $stream = true, repassa o corpo direto ao cliente somente quando a
 * resposta FINAL for 200/206 e não for HTML/JSON.
 */
function pareceVideo(string $d): bool
{
    return substr($d, 4, 4) === 'ftyp'                         // MP4/MOV
        || strncmp($d, "\x1A\x45\xDF\xA3", 4) === 0           // WebM/MKV
        || strncmp($d, 'ID3', 3) === 0 || strncmp($d, 'RIFF', 4) === 0
        || strncmp($d, 'OggS', 4) === 0 || (strlen($d) > 188 && $d[0] === "\x47" && $d[188] === "\x47"); // MPEG-TS
}

function amostraTexto(string $d): string
{
    $t = preg_replace('/[^\x20-\x7E]+/', ' ', substr($d, 0, 200));
    return trim(preg_replace('/\s+/', ' ', $t));
}

function buscar(string $url, array $headersReq, bool $stream, string $ua, bool $semCorpo = false, bool $soAmostra = false, int $maxCorpo = 0): array
{
    $info = [
        'ok' => false, 'status' => 0, 'tipo' => '', 'erro' => '',
        'url_final' => $url, 'corpo' => '', 'enviou' => false, 'hdrs' => [], 'amostra' => '', 'hex' => '',
    ];
    $hdrsFinais = [];   // cabeçalhos da resposta ATUAL (resetado a cada redirect)
    $enviou = false;

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => !$stream,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $stream ? 0 : 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_ENCODING       => 'identity',
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => $headersReq,
        CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$hdrsFinais) {
            $t = trim($linha);
            if (stripos($t, 'HTTP/') === 0) {
                $hdrsFinais = [];              // nova resposta (ex.: após redirect)
            } elseif (strpos($t, ':') !== false) {
                [$k, $v] = explode(':', $t, 2);
                $hdrsFinais[strtolower(trim($k))] = trim($v);
            }
            return strlen($linha);
        },
    ];

    if ($semCorpo) {
        $opts[CURLOPT_NOBODY] = true;
    }

    if (!$stream && $maxCorpo > 0) {
        $acum = '';
        $opts[CURLOPT_RETURNTRANSFER] = false;
        $opts[CURLOPT_WRITEFUNCTION] = function ($c, $data) use (&$acum, $maxCorpo) {
            $acum .= $data;
            return strlen($acum) >= $maxCorpo ? 0 : strlen($data);   // 0 = para o download
        };
    }

    if ($stream && !$semCorpo) {
        $opts[CURLOPT_WRITEFUNCTION] = function ($c, $data) use (&$hdrsFinais, &$enviou, &$info, $soAmostra) {
            if (!$enviou) {
                $status = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
                $tipo   = strtolower($hdrsFinais['content-type'] ?? '');
                $info['status'] = $status;
                $info['tipo']   = $tipo;

                $info['amostra'] = amostraTexto($data);
                $info['hex']     = bin2hex(substr($data, 0, 12));
                if ($soAmostra) return 0;

                // Repassa se for sucesso 2xx e (conteúdo parece vídeo OU tipo não é HTML/JSON)
                $tipoRuim = str_contains($tipo, 'text/html') || str_contains($tipo, 'json') || str_contains($tipo, 'text/plain');
                if ($status < 200 || $status > 299 || ($tipoRuim && !pareceVideo($data))) {
                    return 0; // aborta: o chamador decide o que fazer
                }
                if ($status !== 206) $status = 200;

                http_response_code($status);
                $ct = ($tipo !== '' && !str_contains($tipo, 'octet-stream') && !str_contains($tipo, 'text/') && !str_contains($tipo, 'json')) ? $hdrsFinais['content-type'] : 'video/mp4';
                header('Content-Type: ' . $ct);
                foreach (['content-length', 'content-range', 'accept-ranges', 'last-modified', 'etag'] as $h) {
                    if (isset($hdrsFinais[$h])) header($h . ': ' . $hdrsFinais[$h]);
                }
                if (!isset($hdrsFinais['accept-ranges'])) header('Accept-Ranges: bytes');
                header('Cache-Control: public, max-age=3600');
                while (ob_get_level()) @ob_end_clean();
                $enviou = true;
                $info['enviou'] = true;
            }
            echo $data;
            @flush();
            return strlen($data);
        };
    }

    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $info['erro']      = curl_error($ch);
    $info['url_final'] = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if ($info['status'] === 0) {
        $info['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    }
    if ($info['tipo'] === '') {
        $info['tipo'] = strtolower($hdrsFinais['content-type'] ?? '');
    }
    $info['hdrs'] = $hdrsFinais;
    curl_close($ch);

    if (!$stream && is_string($res)) {
        $info['corpo'] = $res;
    }
    if (!$stream && $maxCorpo > 0) {
        $info['corpo'] = substr($acum, 0, $maxCorpo);
    }
    $info['enviou'] = $enviou;
    $info['ok'] = $enviou || (!$stream && in_array($info['status'], [200, 206], true));
    return $info;
}

function montarHeaders(?string $ref, ?string $org, bool $comRange, ?string $rangeForcado = null): array
{
    $h = [
        'Accept: */*',
        'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
        'Accept-Encoding: identity',
    ];
    if ($ref) $h[] = 'Referer: ' . $ref;
    if ($org) $h[] = 'Origin: ' . $org;
    if ($comRange) {
        $h[] = 'Range: ' . ($rangeForcado ?? ($_SERVER['HTTP_RANGE'] ?? 'bytes=0-'));
    }
    return $h;
}

/* ==========================================================
 | RESOLVEDOR: páginas HTML que escondem o vídeo real
 ========================================================== */
function urlSegura(string $u): bool
{
    $p = parse_url($u);
    if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    $ip = gethostbyname($p['host']);
    if (filter_var($ip, FILTER_VALIDATE_IP) &&
        !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return true;
}

function limparEscapes(string $h): string
{
    $h = str_replace(['\\/', '\\u0026', '\\u003d', '\\u003D', '\\x26', '\\x3d', '&amp;', '&#38;', '&#x2F;'],
                     ['/', '&', '=', '=', '&', '=', '&', '&', '/'], $h);
    return html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Procura no HTML: links diretos de vídeo e páginas/iframes para seguir. */
function extrairCandidatos(string $html, string $base): array
{
    $h = limparEscapes($html);
    $videos = [];
    $paginas = [];

    $padroes = [
        '#"(?:play_url|file|src|source|url|video_url|contentUrl)"\s*:\s*"(https?://[^"\s]+)"#i',
        '#<(?:source|video)[^>]+src=["\']([^"\']+)["\']#i',
        '#(https?://[^\s"\'<>\\\\]+?\.(?:mp4|m3u8)(?:\?[^\s"\'<>\\\\]*)?)#i',
        '#(https?://[^\s"\'<>\\\\]*googlevideo\.com/videoplayback[^\s"\'<>\\\\]*)#i',
    ];
    foreach ($padroes as $idx => $re) {
        if (preg_match_all($re, $h, $m)) {
            foreach ($m[1] as $u) {
                $u = resolverUrl($base, trim($u));
                if (preg_match('#\.(?:jpe?g|png|gif|webp|css|js|svg|ico)(\?|$)#i', $u)) continue;
                if ($idx <= 1 && !preg_match('#\.(?:html?|php)(\?|$)#i', $u)
                    || preg_match('#\.(?:mp4|m3u8)(\?|$)|googlevideo\.com/videoplayback|/videoplayback#i', $u)) {
                    $videos[$u] = true;
                }
            }
        }
    }

    // páginas para seguir: iframes e players do Blogger
    if (preg_match_all('#<iframe[^>]+src=["\']([^"\']+)["\']#i', $h, $m)) {
        foreach ($m[1] as $u) $paginas[resolverUrl($base, trim($u))] = true;
    }
    if (preg_match_all('#(https?://(?:www\.)?blogger\.com/video\.g\?[^\s"\'<>\\\\]+)#i', $h, $m)) {
        foreach ($m[1] as $u) $paginas[$u] = true;
    }

    return ['videos' => array_keys($videos), 'paginas' => array_keys($paginas)];
}

/** Segue a cadeia de páginas até achar o vídeo real. Retorna ['url'=>..., 'ref'=>...] ou null. */
function resolverVideo(string $inicio, array $perfis, string $ua, array &$log = []): ?array
{
    $fila = [[$inicio, 0, 'https://animesdigital.org/']];
    $vistos = [];
    while ($fila && count($vistos) < 8) {
        [$u, $prof, $refPai] = array_shift($fila);
        if (isset($vistos[$u]) || $prof > 3) continue;
        $vistos[$u] = true;

        $pagina = null;
        $tentativas = array_merge([[$refPai, preg_replace('#^(https?://[^/]+).*$#', '$1', $refPai)]], $perfis);
        foreach ($tentativas as [$ref, $org]) {
            $r = buscar($u, montarHeaders($ref, $org, false), false, $ua, false, false, 800000);
            if ($r['status'] >= 200 && $r['status'] < 300 && $r['corpo'] !== '') { $pagina = $r; break; }
        }
        if (!$pagina) { $log[] = ['url' => $u, 'status' => $r['status'] ?? 0, 'resultado' => 'sem resposta']; continue; }

        $corpo = $pagina['corpo'];
        $tipo  = $pagina['tipo'];
        if (!str_contains($tipo, 'html') && !str_contains($tipo, 'json') && !str_contains($tipo, 'text/') && pareceVideo($corpo)) {
            return ['url' => $u, 'ref' => $refPai];
        }

        $baseFinal = $pagina['url_final'] ?: $u;
        $c = extrairCandidatos($corpo, $baseFinal);
        $log[] = ['url' => $u, 'status' => $pagina['status'], 'tipo' => $tipo,
                  'videos' => array_slice($c['videos'], 0, 5), 'paginas' => array_slice($c['paginas'], 0, 5)];

        foreach ($c['videos'] as $v) {
            if (urlSegura($v)) return ['url' => $v, 'ref' => $baseFinal];
        }
        foreach ($c['paginas'] as $pg) {
            if (urlSegura($pg)) $fila[] = [$pg, $prof + 1, $baseFinal];
        }
    }
    return null;
}

/* cache curto da URL resolvida (o proxy é chamado várias vezes por vídeo: seek, Range) */
function cacheArq(string $url): string { return sys_get_temp_dir() . '/af_res_' . md5($url) . '.json'; }
function cacheLer(string $url): ?array
{
    $f = cacheArq($url);
    if (!is_file($f) || filemtime($f) < time() - 600) return null;
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) && !empty($d['url']) ? $d : null;
}
function cacheGravar(string $url, array $d): void { @file_put_contents(cacheArq($url), json_encode($d), LOCK_EX); }

/**
 * Tenta entregar um MP4 ao cliente com os perfis de Referer informados.
 * Retorna true se entregou (ou respondeu HEAD). $ultimo recebe o último resultado.
 */
function servirMp4(string $alvo, array $perfis, string $ua, ?array &$ultimo): bool
{
    $semCorpo = ($_SERVER['REQUEST_METHOD'] === 'HEAD');
    foreach ($perfis as [$ref, $org]) {
        if ($semCorpo) {
            $r = buscar($alvo, montarHeaders($ref, $org, true, 'bytes=0-0'), false, $ua);
            $ultimo = $r;
            if (in_array($r['status'], [200, 206], true) && !str_contains($r['tipo'], 'html')) {
                $total = null;
                if (preg_match('#/(\d+)$#', $r['hdrs']['content-range'] ?? '', $m)) $total = $m[1];
                elseif (isset($r['hdrs']['content-length']) && $r['status'] === 200) $total = $r['hdrs']['content-length'];
                http_response_code(200);
                header('Content-Type: video/mp4');
                header('Accept-Ranges: bytes');
                if ($total) header('Content-Length: ' . $total);
                return true;
            }
            continue;
        }
        $r = buscar($alvo, montarHeaders($ref, $org, isset($_SERVER['HTTP_RANGE'])), true, $ua);
        $ultimo = $r;
        if ($r['enviou'] || connection_aborted()) return true;
    }
    return false;
}

/* ==========================================================
 | MODO DEBUG  (proxy.php?url=...&debug=1  |  &debug=2 mostra o HTML bruto)
 ========================================================== */
if ($debug) {
    header('Cache-Control: no-store');

    if (($_GET['debug'] ?? '') === '2') {
        header('Content-Type: text/plain; charset=UTF-8');
        $r = buscar($url, montarHeaders($perfis[0][0], $perfis[0][1], false), false, $UA, false, false, 60000);
        echo "STATUS: {$r['status']}\nTIPO: {$r['tipo']}\nURL FINAL: {$r['url_final']}\n\n" . $r['corpo'];
        exit;
    }

    header('Content-Type: application/json; charset=UTF-8');
    $saida = [];
    foreach ($perfis as $i => [$ref, $org]) {
        $r = buscar($url, montarHeaders($ref, $org, $ehMp4, 'bytes=0-1023'), true, $UA, false, true);
        $saida[] = [
            'perfil'    => $i + 1,
            'referer'   => $ref,
            'status'    => $r['status'],
            'tipo'      => $r['tipo'],
            'primeiros_bytes_hex' => $r['hex'],
            'amostra'   => $r['amostra'],
            'url_final' => $r['url_final'],
        ];
    }
    $log = [];
    $res = resolverVideo($url, $perfis, $UA, $log);
    echo json_encode([
        'mp4' => $ehMp4,
        'tentativas' => $saida,
        'resolvido' => $res ? ['url' => $res['url'], 'ref' => $res['ref']] : null,
        'cadeia' => $log,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/* ==========================================================
 | MP4 — direto, ou resolvendo a página HTML que contém o vídeo
 ========================================================== */
if ($ehMp4) {
    $ultimo = null;

    // 1) resultado já resolvido recentemente
    if ($c = cacheLer($url)) {
        $perf = array_merge([[$c['ref'], preg_replace('#^(https?://[^/]+).*$#', '$1', $c['ref'])]], $perfis);
        if (servirMp4($c['url'], $perf, $UA, $ultimo)) exit;
        @unlink(cacheArq($url));   // expirou: resolve de novo
    }

    // 2) a URL já é o vídeo?
    if (servirMp4($url, $perfis, $UA, $ultimo)) exit;

    // 3) a URL é uma página HTML → acha o vídeo dentro dela
    $ehHtml = $ultimo && $ultimo['status'] >= 200 && $ultimo['status'] < 300 && str_contains($ultimo['tipo'], 'html');
    if ($ehHtml || ($ultimo && in_array($ultimo['status'], [403, 404, 410], true))) {
        $res = resolverVideo($url, $perfis, $UA);
        if ($res) {
            cacheGravar($url, $res);
            $perf = array_merge([[$res['ref'], preg_replace('#^(https?://[^/]+).*$#', '$1', $res['ref'])]], $perfis);
            if (servirMp4($res['url'], $perf, $UA, $ultimo)) exit;
        }
    }

    // 4) falhou: devolve o erro real
    $codigo = ($ultimo && $ultimo['status'] >= 400) ? $ultimo['status'] : 502;
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Não foi possível obter este vídeo da fonte autorizada. Tente novamente mais tarde.';
    exit;
}

/* ==========================================================
 | HLS / M3U8 / TS — carrega em memória (reescreve playlists)
 ========================================================== */
$r = null;
foreach ($perfis as [$ref, $org]) {
    $r = buscar($url, montarHeaders($ref, $org, false), false, $UA);
    if ($r['ok']) break;
}

if (!$r || !$r['ok']) {
    $codigo = ($r && $r['status'] >= 400) ? $r['status'] : 502;
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Não foi possível obter este stream da fonte autorizada. Tente novamente mais tarde.';
    exit;
}

$body   = $r['corpo'];
$ehM3u8 = str_contains($r['tipo'], 'mpegurl')
       || str_contains(strtolower($url), '.m3u8')
       || strncmp(ltrim($body), '#EXTM3U', 7) === 0;

if ($ehM3u8) {
    $base   = $r['url_final'] ?: $url;
    $linhas = preg_split('/\r\n|\r|\n/', $body);
    $novas  = [];

    foreach ($linhas as $linha) {
        $linha = rtrim($linha);
        if ($linha === '') { $novas[] = ''; continue; }

        if ($linha[0] === '#') {
            // Tags com URI="..." (chaves, mapas, mídia alternativa)
            $linha = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($base) {
                return 'URI="' . viaProxy(resolverUrl($base, $m[1])) . '"';
            }, $linha);
            $novas[] = $linha;
        } else {
            $novas[] = viaProxy(resolverUrl($base, $linha));
        }
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    echo implode("\n", $novas);
    exit;
}

http_response_code(200);
header('Content-Type: ' . ($r['tipo'] !== '' ? $r['tipo'] : 'application/octet-stream'));
header('Cache-Control: public, max-age=3600');
echo $body;
