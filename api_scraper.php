<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API SCRAPER — páginas públicas de animesdigital.org
|--------------------------------------------------------------------------
| Exemplos:
| api_scraper.php?url=https%3A%2F%2Fanimesdigital.org%2Fanime%2Fa%2FSLUG
| api_scraper.php?url=https%3A%2F%2Fanimesdigital.org%2Fanime%2Fa%2FSLUG&odr=1
| api_scraper.php?url=https%3A%2F%2Fanimesdigital.org%2Fanime%2Fa%2FSLUG&pagina=2
| api_scraper.php?url=https%3A%2F%2Fanimesdigital.org%2Fanime%2Fa%2FSLUG&ep=1
|
| A API só lê páginas publicamente acessíveis. Não contorna login, paywall,
| tokens, criptografia ou outras restrições do provedor.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const SCRAPER_TIMEOUT = 25;
const SCRAPER_CONNECT_TIMEOUT = 10;
const SCRAPER_MAX_REDIRECTS = 5;
const SCRAPER_MAX_PAGES = 100;
const SCRAPER_DELAY_US = 250000;
const SCRAPER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const SOURCE_HOSTS = ['animesdigital.org', 'www.animesdigital.org'];

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function jsonError(string $message, int $status = 400, array $extra = []): void
{
    jsonResponse(array_merge([
        'erro' => true,
        'mensagem' => $message,
    ], $extra), $status);
}

function isAllowedSourceUrl(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    return strtolower((string)$parts['scheme']) === 'https'
        && in_array(strtolower((string)$parts['host']), SOURCE_HOSTS, true);
}

/** Converte uma URL relativa em absoluta, sem depender de bibliotecas externas. */
function absoluteUrl(string $url, string $base): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '' || preg_match('/^(javascript:|data:|mailto:|tel:)/i', $url)) {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $baseParts = parse_url($base);
    if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return '';
    }

    $origin = $baseParts['scheme'] . '://' . $baseParts['host'];
    if (strpos($url, '//') === 0) {
        return $baseParts['scheme'] . ':' . $url;
    }
    if (strpos($url, '/') === 0) {
        return $origin . $url;
    }
    if (strpos($url, '#') === 0) {
        return preg_replace('/#.*$/', '', $base) . $url;
    }
    if (strpos($url, '?') === 0) {
        return preg_replace('/[?#].*$/', '', $base) . $url;
    }

    $path = (string)($baseParts['path'] ?? '/');
    $directory = preg_replace('#/[^/]*$#', '/', $path);
    $combined = $origin . ($directory ?: '/') . $url;

    // Normaliza segmentos . e .. sem alterar query string.
    $parts = parse_url($combined);
    $segments = [];
    foreach (explode('/', (string)($parts['path'] ?? '/')) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
        } else {
            $segments[] = $segment;
        }
    }

    $normalized = $origin . '/' . implode('/', $segments);
    if (!empty($parts['query'])) {
        $normalized .= '?' . $parts['query'];
    }
    if (!empty($parts['fragment'])) {
        $normalized .= '#' . $parts['fragment'];
    }
    return $normalized;
}

/**
 * Faz GET com redirecionamentos manuais. Cada destino é validado para impedir
 * que uma URL fornecida pelo usuário redirecione o servidor para outros hosts.
 */
function fetchPublicHtml(string $url, string $referer = ''): array
{
    if (!isAllowedSourceUrl($url)) {
        jsonError('A URL solicitada não pertence ao domínio HTTPS autorizado.', 400);
    }

    $currentUrl = $url;
    $lastError = '';

    for ($redirect = 0; $redirect <= SCRAPER_MAX_REDIRECTS; $redirect++) {
        $responseHeaders = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $currentUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => SCRAPER_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => SCRAPER_TIMEOUT,
            CURLOPT_USERAGENT => SCRAPER_USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
                'Cache-Control: no-cache',
            ],
        ]);

        if ($referer !== '' && isAllowedSourceUrl($referer)) {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
        }

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $location = (string)($responseHeaders['location'] ?? '');
        curl_close($ch);

        if ($body === false) {
            $lastError = $curlError !== '' ? $curlError : 'Falha de comunicação cURL.';
            jsonError('Não foi possível consultar a página de origem.', 502, [
                'detalhe' => $lastError,
                'url' => $currentUrl,
            ]);
        }

        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            if ($location === '') {
                // Sem Location não há como seguir o redirecionamento com segurança.
                jsonError('A origem redirecionou sem informar um destino válido.', 502, [
                    'http_origem' => $status,
                    'url' => $currentUrl,
                ]);
            }
            $nextUrl = absoluteUrl($location, $currentUrl);
            if (!isAllowedSourceUrl($nextUrl)) {
                jsonError('A origem redirecionou para um domínio não permitido.', 502, [
                    'http_origem' => $status,
                ]);
            }
            $currentUrl = $nextUrl;
            continue;
        }

        if ($status < 200 || $status >= 300) {
            jsonError('A página de origem respondeu com HTTP ' . $status . '.', 502, [
                'http_origem' => $status,
                'url' => $currentUrl,
                'content_type' => $contentType,
            ]);
        }

        if (!is_string($body) || trim($body) === '') {
            jsonError('A origem retornou uma resposta vazia.', 502, [
                'http_origem' => $status,
                'url' => $currentUrl,
            ]);
        }

        // Esta API faz parsing de HTML; não confunde uma resposta JSON ou binária com HTML.
        $looksLikeHtml = stripos($contentType, 'text/html') !== false
            || stripos($contentType, 'application/xhtml+xml') !== false
            || preg_match('/^\s*(<!doctype\s+html|<html\b)/i', $body) === 1;

        if (!$looksLikeHtml) {
            jsonError('A origem não retornou HTML para a página solicitada.', 502, [
                'http_origem' => $status,
                'content_type' => $contentType,
                'url' => $currentUrl,
            ]);
        }

        return [
            'html' => $body,
            'status' => $status,
            'content_type' => $contentType,
            'url_final' => $currentUrl,
        ];
    }

    jsonError('A origem excedeu o limite de redirecionamentos.', 502, [
        'detalhe' => $lastError,
    ]);
}

/** Carrega HTML com DOMDocument e retorna um XPath ou null se DOM não existir. */
function createXPath(string $html): ?DOMXPath
{
    if (!class_exists('DOMDocument')) {
        return null;
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $loaded ? new DOMXPath($dom) : null;
}

function cleanText(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function extractEpisodeNumber(string $title, string $url): int
{
    if (preg_match('/epis[oó]dio\s*#?\s*0*(\d+)/iu', $title, $match)) {
        return (int)$match[1];
    }

    // Fallback para identificadores públicos no formato "...x001c".
    if (preg_match('/x0*(\d+)[a-z]?$/i', (string)parse_url($url, PHP_URL_PATH), $match)) {
        return (int)$match[1];
    }

    return 0;
}

function episodeTitleFromAnchor(string $text): string
{
    $text = cleanText($text);
    $text = preg_replace('/^assistir\s+/iu', '', $text) ?? $text;

    // Alguns layouts repetem o título e acrescentam "há X anos".
    if (preg_match('/^(.*?epis[oó]dio\s*#?\s*0*\d+)(?:\s+\1)?(?:\s+\d+\s+(?:ano|anos|m[eê]s|meses|dia|dias)\s+atr[aá]s)?.*$/iu', $text, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/^(.*?epis[oó]dio\s*#?\s*0*\d+)/iu', $text, $m)) {
        return trim($m[1]);
    }
    return $text;
}

function scrapeEpisodeList(string $html, string $pageUrl): array
{
    $episodes = [];
    $xpath = createXPath($html);

    if ($xpath !== null) {
        $nodes = $xpath->query('//a[@href]');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $href = trim((string)$node->getAttribute('href'));
                if (stripos($href, '/video/') === false) {
                    continue;
                }

                $episodeUrl = absoluteUrl($href, $pageUrl);
                if ($episodeUrl === '') {
                    continue;
                }

                $title = episodeTitleFromAnchor((string)$node->textContent);
                $number = extractEpisodeNumber($title, $episodeUrl);
                if ($title === '' && $number > 0) {
                    $title = 'Episódio ' . $number;
                }

                $episodes[] = [
                    'numero' => $number,
                    'titulo' => $title,
                    'url_episodio' => $episodeUrl,
                    'players' => [],
                ];
            }
        }
    } else {
        // Fallback sem extensão DOM: captura âncoras de episódio.
        if (preg_match_all('~<a\b[^>]*href\s*=\s*["\']([^"\']*/video/[^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $episodeUrl = absoluteUrl($match[1], $pageUrl);
                $title = episodeTitleFromAnchor((string)$match[2]);
                $number = extractEpisodeNumber($title, $episodeUrl);
                if ($episodeUrl !== '') {
                    $episodes[] = [
                        'numero' => $number,
                        'titulo' => $title,
                        'url_episodio' => $episodeUrl,
                        'players' => [],
                    ];
                }
            }
        }
    }

    return $episodes;
}

function scrapeMetadata(string $html): array
{
    $title = '';
    $cover = '';
    $totalPages = 1;
    $xpath = createXPath($html);

    if ($xpath !== null) {
        $titleNode = $xpath->query('//h1[1]');
        if ($titleNode !== false && $titleNode->length > 0) {
            $title = cleanText((string)$titleNode->item(0)->textContent);
        }

        $ogTitle = $xpath->query('//meta[translate(@property,"OG:","og:")="og:title"]/@content');
        if ($title === '' && $ogTitle !== false && $ogTitle->length > 0) {
            $title = cleanText((string)$ogTitle->item(0)->nodeValue);
        }

        $ogImage = $xpath->query('//meta[@property="og:image"]/@content');
        if ($ogImage !== false && $ogImage->length > 0) {
            $cover = trim((string)$ogImage->item(0)->nodeValue);
        }

        $links = $xpath->query('//a[@href]');
        if ($links !== false) {
            foreach ($links as $link) {
                $href = (string)$link->getAttribute('href');
                if (preg_match('~/page/(\d+)/?~i', $href, $m)) {
                    $totalPages = max($totalPages, (int)$m[1]);
                }
                $query = parse_url($href, PHP_URL_QUERY);
                if (is_string($query)) {
                    parse_str($query, $params);
                    if (isset($params['pagina']) && ctype_digit((string)$params['pagina'])) {
                        $totalPages = max($totalPages, (int)$params['pagina']);
                    }
                }
            }
        }
    } else {
        if (preg_match('~<h1\b[^>]*>(.*?)</h1>~is', $html, $m)) {
            $title = cleanText($m[1]);
        }
        if (preg_match('~<meta\b[^>]*(?:property|name)=["\']og:title["\'][^>]*content=["\']([^"\']+)~i', $html, $m)) {
            $title = $title !== '' ? $title : cleanText($m[1]);
        }
        if (preg_match('~<meta\b[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)~i', $html, $m)) {
            $cover = trim($m[1]);
        }
        if (preg_match_all('~/page/(\d+)/?~i', $html, $m)) {
            $totalPages = max(1, max(array_map('intval', $m[1])));
        }
    }

    return [
        'titulo' => $title,
        'capa' => $cover,
        'total_paginas' => max(1, $totalPages),
    ];
}

function isLikelyPlayerUrl(string $url, string $tagName = ''): bool
{
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }

    $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));

    if (in_array($tagName, ['iframe', 'video', 'source'], true)) {
        return true;
    }

    return preg_match('/\.(m3u8|mp4|mkv|webm)(?:$)/i', $path) === 1
        || strpos($host, 'anivideo') !== false
        || strpos($host, 'videohls') !== false
        || strpos($host, 'stream') !== false
        || strpos($url, 'videohls') !== false;
}

/**
 * Extrai URLs de players declaradas no HTML público do episódio:
 * iframe, video, source, atributos data-* e links explícitos de mídia.
 * Não tenta decodificar tokens nem contornar mecanismos de proteção.
 */
function scrapeEpisodePlayers(string $html, string $episodeUrl): array
{
    $players = [];
    $add = static function (string $rawUrl, string $tag = '') use (&$players, $episodeUrl): void {
        $url = absoluteUrl($rawUrl, $episodeUrl);
        if ($url !== '' && isLikelyPlayerUrl($url, $tag)) {
            $players[$url] = $url;
        }
    };

    $xpath = createXPath($html);
    if ($xpath !== null) {
        $queries = [
            ['//iframe[@src]', 'iframe', 'src'],
            ['//video[@src]', 'video', 'src'],
            ['//source[@src]', 'source', 'src'],
            ['//*[@data-src]', '', 'data-src'],
            ['//*[@data-url]', '', 'data-url'],
            ['//*[@data-embed]', 'iframe', 'data-embed'],
            ['//a[@href]', 'a', 'href'],
        ];

        foreach ($queries as $queryInfo) {
            [$query, $tag, $attribute] = $queryInfo;
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                $value = trim((string)$node->getAttribute($attribute));
                if ($value !== '') {
                    $add($value, $tag);
                }
            }
        }
    } else {
        $patterns = [
            '~<(iframe|video|source)\b[^>]*\b(src)=["\']([^"\']+)["\']~i',
            '~\b(data-src|data-url|data-embed)=["\']([^"\']+)["\']~i',
            '~<a\b[^>]*\bhref=["\'](https?://[^"\']+)["\']~i',
        ];
        foreach ($patterns as $index => $pattern) {
            if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                if ($index === 0) {
                    $add($match[3], strtolower($match[1]));
                } elseif ($index === 1) {
                    $add($match[2], 'data');
                } else {
                    $add($match[1], 'a');
                }
            }
        }
    }

    // Detecta apenas URLs explícitas de arquivos de mídia ou endpoints conhecidos no HTML.
    if (preg_match_all('~https?://[^\s<>"\']+~i', $html, $matches)) {
        foreach ($matches[0] as $raw) {
            $raw = rtrim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '),.;]');
            if (isLikelyPlayerUrl($raw)) {
                $add($raw);
            }
        }
    }

    return array_values($players);
}

function buildListingUrl(string $baseUrl, int $page, string $order): string
{
    $path = rtrim((string)parse_url($baseUrl, PHP_URL_PATH), '/');
    $url = 'https://' . parse_url($baseUrl, PHP_URL_HOST) . $path;
    if ($page > 1) {
        $url .= '/page/' . $page;
    }
    return $url . '/?odr=' . rawurlencode($order);
}

/* Entrada e validação. */
$urlAnime = trim((string)($_GET['url'] ?? ''));
$order = ((string)($_GET['odr'] ?? '1')) === '0' ? '0' : '1';
$pageRequested = trim((string)($_GET['pagina'] ?? ''));
$episodeRequested = trim((string)($_GET['ep'] ?? ''));

if ($urlAnime === '') {
    jsonError('O parâmetro "url" é obrigatório.', 400);
}
if (!isAllowedSourceUrl($urlAnime)) {
    jsonError('Informe uma URL HTTPS válida de animesdigital.org.', 400);
}

$basePath = (string)parse_url($urlAnime, PHP_URL_PATH);
$baseUrl = 'https://' . parse_url($urlAnime, PHP_URL_HOST) . rtrim($basePath, '/');
if ($basePath === '') {
    jsonError('A URL informada não parece ser uma página de anime.', 400);
}

/* Primeira página: metadados e episódios iniciais. */
$firstUrl = buildListingUrl($baseUrl, 1, $order);
$firstResponse = fetchPublicHtml($firstUrl);
$metadata = scrapeMetadata($firstResponse['html']);
$episodes = scrapeEpisodeList($firstResponse['html'], $firstResponse['url_final']);
$totalPages = min(SCRAPER_MAX_PAGES, max(1, (int)$metadata['total_paginas']));

/* Se pagina foi informada, consulta somente essa página. */
if ($pageRequested !== '') {
    if (!ctype_digit($pageRequested) || (int)$pageRequested < 1) {
        jsonError('O parâmetro "pagina" precisa ser um número inteiro positivo.', 400);
    }
    $requestedPage = (int)$pageRequested;
    if ($requestedPage > SCRAPER_MAX_PAGES) {
        jsonError('A página solicitada excede o limite de segurança da API.', 400);
    }

    if ($requestedPage === 1) {
        $episodes = scrapeEpisodeList($firstResponse['html'], $firstResponse['url_final']);
    } else {
        $pageUrl = buildListingUrl($baseUrl, $requestedPage, $order);
        $pageResponse = fetchPublicHtml($pageUrl, $firstResponse['url_final']);
        $episodes = scrapeEpisodeList($pageResponse['html'], $pageResponse['url_final']);
    }
    $totalPages = max($totalPages, $requestedPage);
} else {
    /* Busca as páginas restantes, respeitando uma pequena pausa entre chamadas. */
    for ($page = 2; $page <= $totalPages; $page++) {
        usleep(SCRAPER_DELAY_US);
        $pageUrl = buildListingUrl($baseUrl, $page, $order);
        $pageResponse = fetchPublicHtml($pageUrl, $firstResponse['url_final']);
        $pageEpisodes = scrapeEpisodeList($pageResponse['html'], $pageResponse['url_final']);
        $episodes = array_merge($episodes, $pageEpisodes);
    }
}

/* Remove duplicatas por URL. */
$unique = [];
foreach ($episodes as $episode) {
    $key = $episode['url_episodio'];
    if (!isset($unique[$key])) {
        $unique[$key] = $episode;
    }
}
$episodes = array_values($unique);

/* Ordenação de acordo com odr. */
usort($episodes, static function (array $a, array $b) use ($order): int {
    $comparison = $a['numero'] <=> $b['numero'];
    return $order === '0' ? -$comparison : $comparison;
});

/* Resolve players apenas quando ep=N for solicitado. */
if ($episodeRequested !== '') {
    if (!ctype_digit($episodeRequested) || (int)$episodeRequested < 1) {
        jsonError('O parâmetro "ep" precisa ser um número inteiro positivo.', 400);
    }

    $episodeNumber = (int)$episodeRequested;
    $found = null;

    foreach ($episodes as $episode) {
        if ((int)$episode['numero'] !== $episodeNumber) {
            continue;
        }

        $detail = fetchPublicHtml($episode['url_episodio'], $firstResponse['url_final']);
        $episode['players'] = scrapeEpisodePlayers($detail['html'], $detail['url_final']);
        $episode['diagnostico'] = [
            'pagina_http' => $detail['status'],
            'content_type' => $detail['content_type'],
            'quantidade_players' => count($episode['players']),
        ];
        $found = $episode;
        break;
    }

    if ($found === null) {
        jsonError('Episódio ' . $episodeNumber . ' não encontrado na listagem.', 404, [
            'total_episodios_encontrados' => count($episodes),
        ]);
    }

    jsonResponse([
        'titulo' => $metadata['titulo'],
        'capa' => $metadata['capa'],
        'total_episodios' => count($episodes),
        'total_paginas' => $totalPages,
        'episodio' => $found,
    ]);
}

/* Listagem geral: não consulta a página individual de cada episódio. */
$cleanEpisodes = [];
foreach ($episodes as $episode) {
    unset($episode['players']);
    $cleanEpisodes[] = $episode;
}

jsonResponse([
    'titulo' => $metadata['titulo'],
    'capa' => $metadata['capa'],
    'total_episodios' => count($cleanEpisodes),
    'total_paginas' => $totalPages,
    'episodios' => $cleanEpisodes,
]);
