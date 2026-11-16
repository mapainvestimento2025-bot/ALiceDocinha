
<?php
require_once __DIR__ . '/config.php';

$arquivoConteudos = __DIR__ . '/conteudos.json';

function h($valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

if (!is_file($arquivoConteudos) || !is_readable($arquivoConteudos)) {
    header('Location: index.php');
    exit;
}

$dados = json_decode(file_get_contents($arquivoConteudos), true);
if (!is_array($dados)) $dados = [];

function normalizarTituloAssistir(string $t): string {
    return function_exists('mb_strtolower')
        ? mb_strtolower(trim($t), 'UTF-8')
        : strtolower(trim($t));
}

function normalizarTipoAssistir(string $t): string {
    $map = [
        'anime' => 'Anime',
        'serie' => 'Série',
        'série' => 'Série',
        'filme' => 'Filme'
    ];

    return $map[strtolower(trim($t))] ?? '';
}

function organizarConteudosAssistir(array $dados): array {
    $cat = [];

    foreach ($dados as $item) {
        if (!is_array($item)) continue;

        $tipo = normalizarTipoAssistir(
            (string)($item['tipo'] ?? $item['categoria'] ?? '')
        );

        if (!$tipo) continue;

        $titulo = trim((string)($item['titulo'] ?? $item['nome'] ?? ''));

        if (!$titulo) continue;

        $chave = $tipo . '|' . normalizarTituloAssistir($titulo);

        if (!isset($cat[$chave])) {
            $cat[$chave] = [
                'id' => (string)($item['id'] ?? ''),
                'ids' => [],
                'nome' => $titulo,
                'categoria' => $tipo,
                'capa' => (string)($item['capa'] ?? ''),
                'descricao' => (string)($item['descricao'] ?? ''),
                'episodios' => []
            ];
        }

        $id = (string)($item['id'] ?? '');

        if ($cat[$chave]['id'] === '' && $id) {
            $cat[$chave]['id'] = $id;
        }

        if ($cat[$chave]['capa'] === '' && !empty($item['capa'])) {
            $cat[$chave]['capa'] = (string)$item['capa'];
        }

        if ($cat[$chave]['descricao'] === '' && !empty($item['descricao'])) {
            $cat[$chave]['descricao'] = (string)$item['descricao'];
        }

        if ($id) $cat[$chave]['ids'][] = $id;

        if (!empty($item['episodios']) && is_array($item['episodios'])) {
            foreach ($item['episodios'] as $ep) {
                if (!is_array($ep)) continue;

                $cat[$chave]['episodios'][] = [
                    'id' => (string)($ep['id'] ?? ''),
                    'nome' => (string)($ep['nome'] ?? 'Episódio'),
                    'numero' => (int)($ep['numero'] ?? $ep['episodio'] ?? 0),
                    'player' => (string)($ep['player'] ?? '')
                ];
            }
        }

        if (!empty($item['player'])) {
            $num = (int)($item['episodio'] ?? $item['numero'] ?? 0);

            $nome = $tipo === 'Filme'
                ? 'Filme completo'
                : ($num > 0 ? 'Episódio ' . $num : 'Assistir');

            $cat[$chave]['episodios'][] = [
                'id' => $id,
                'nome' => $nome,
                'numero' => $num,
                'player' => (string)$item['player']
            ];
        }
    }

    foreach ($cat as &$c) {
        $u = [];
        $v = [];

        foreach ($c['episodios'] as $ep) {
            $k = $ep['id'] . '|' . $ep['numero'] . '|' . $ep['player'];

            if (!isset($v[$k])) {
                $v[$k] = true;
                $u[] = $ep;
            }
        }

        usort($u, fn($a, $b) => (int)$a['numero'] <=> (int)$b['numero']);

        $c['episodios'] = array_values($u);
        $c['ids'] = array_values(array_unique($c['ids']));
    }

    unset($c);

    return array_values($cat);
}

$catalogo = organizarConteudosAssistir($dados);

$id = trim((string)($_GET['id'] ?? ''));
$episodioId = trim((string)($_GET['ep'] ?? ''));

$conteudo = null;

if ($id !== '') {
    foreach ($catalogo as $item) {
        if (
            (string)($item['id'] ?? '') === $id ||
            in_array($id, $item['ids'], true)
        ) {
            $conteudo = $item;
            break;
        }
    }
}

if (!$conteudo) {
    header('Location: index.php');
    exit;
}

$episodios = $conteudo['episodios'];
$episodioAtual = null;

if ($episodioId !== '') {
    foreach ($episodios as $ep) {
        if ((string)($ep['id'] ?? '') === $episodioId) {
            $episodioAtual = $ep;
            break;
        }
    }
}

if ($episodioAtual === null && !empty($episodios)) {
    $episodioAtual = $episodios[0];
}

$playerRaw = $episodioAtual
    ? trim((string)($episodioAtual['player'] ?? ''))
    : '';

$ehProxy = str_starts_with($playerRaw, 'proxy.php?url=');
$ehHls = $ehProxy || str_contains($playerRaw, '.m3u8');
$ehIframe = !$ehHls && str_starts_with($playerRaw, 'https://');

$temPlayer = $playerRaw !== '';
$playerUrl = $playerRaw;

$qtdEps = count($episodios);
$ehFilme = $conteudo['categoria'] === 'Filme';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">

<meta name="theme-color" content="#C83D73">

<title><?= h($conteudo['nome']) ?> - ALice Docinha</title>

<link rel="stylesheet" href="assets/css/style.css">

<?php if ($ehHls): ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.13/dist/hls.min.js"></script>
<?php endif; ?>

<style>
:root {
    --rosa: #C83D73;
    --rosa-secundario: #D94F83;
    --rosa-escuro: #A92D60;
    --rosa-claro: #FFF0F5;
    --fundo: #FFF7FA;
    --branco: #FFFAFD;
    --borda: #F1DCE5;
    --texto: #30232A;
    --texto-secundario: #806B75;
    --sombra: 0 8px 30px rgba(169,45,96,.09);
}

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
    -webkit-text-size-adjust: 100%;
}

body {
    background: var(--fundo);
    color: var(--texto);
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
}

/* CONTAINER PRINCIPAL */

.watch-container {
    width: min(1150px, calc(100% - 40px));
    margin: 35px auto;
    padding: 20px 0 60px;
}

/* BOTÃO VOLTAR */

.watch-back {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    padding: 12px 18px;
    background: var(--rosa);
    color: white;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 700;
    transition: .2s;
}

.watch-back:hover {
    background: var(--rosa-escuro);
    transform: translateY(-2px);
}

/* INFORMAÇÕES DO ANIME */

.watch-header {
    display: flex;
    gap: 25px;
    align-items: center;
    padding: 25px;
    margin: 25px 0;
    background: var(--branco);
    border: 1px solid var(--borda);
    border-radius: 16px;
    box-shadow: var(--sombra);
}

.watch-capa {
    width: 125px;
    height: 175px;
    flex: 0 0 auto;
    object-fit: cover;
    border-radius: 12px;
    background: var(--rosa-claro);
    box-shadow: 0 5px 18px rgba(0,0,0,.12);
}

.watch-infos {
    min-width: 0;
}

.watch-infos h1 {
    color: var(--rosa-escuro);
    margin: 0 0 12px;
    font-size: clamp(23px, 4vw, 34px);
    overflow-wrap: anywhere;
}

.watch-infos p {
    color: var(--texto-secundario);
    margin: 8px 0;
    font-size: 15px;
}

/* PROTEÇÃO / STATUS */

.ad-block-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 12px;
    padding: 8px 12px;
    background: var(--rosa-claro);
    border: 1px solid var(--borda);
    border-radius: 8px;
    color: var(--rosa-escuro);
    font-size: 12px;
    font-weight: 700;
}

/* PLAYER */

.player-wrap {
    width: 100%;
    aspect-ratio: 16 / 9;
    position: relative;
    overflow: hidden;
    border-radius: 15px;
    background: #000;
    box-shadow: 0 12px 35px rgba(48,35,42,.18);
}

.player-wrap video,
.player-wrap iframe {
    width: 100%;
    height: 100%;
    display: block;
    border: 0;
    outline: none;
    background: #000;
}

.player-wrap video {
    object-fit: contain;
}

.controls {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 12px 15px;
    background: linear-gradient(transparent, rgba(0,0,0,.9));
    display: flex;
    flex-direction: column;
    gap: 9px;
    opacity: 0;
    transition: opacity .3s;
    pointer-events: none;
}

.player-wrap:hover .controls,
.player-wrap.paused .controls,
.player-wrap:focus-within .controls {
    opacity: 1;
    pointer-events: all;
}

.progress-bar {
    width: 100%;
    height: 6px;
    background: rgba(255,255,255,.3);
    border-radius: 5px;
    cursor: pointer;
}

.progress-fill {
    height: 100%;
    background: var(--rosa-secundario);
    border-radius: 5px;
    width: 0%;
}

.ctrl-row {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ctrl-btn {
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    padding: 5px;
    font-size: 20px;
}

.time-display {
    color: white;
    font-size: 12px;
    font-family: monospace;
}

.vol-wrap {
    display: flex;
    align-items: center;
    gap: 7px;
}

.vol-slider {
    width: 75px;
    accent-color: var(--rosa);
}

.ctrl-right {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 10px;
}

.speed-btn {
    background: var(--rosa);
    border: none;
    color: white;
    border-radius: 6px;
    padding: 7px 10px;
    font-size: 12px;
    cursor: pointer;
}

/* CARREGAMENTO */

.loading-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,.78);
    color: white;
    font-size: 14px;
    gap: 10px;
    z-index: 5;
}

.spinner {
    width: 28px;
    height: 28px;
    border: 3px solid rgba(255,255,255,.2);
    border-top-color: var(--rosa-secundario);
    border-radius: 50%;
    animation: spin .7s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* TÍTULOS */

.watch-title {
    margin: 24px 0;
    padding-left: 13px;
    border-left: 4px solid var(--rosa);
    color: var(--rosa-escuro);
    font-size: 23px;
}

.descricao {
    color: var(--texto-secundario);
    line-height: 1.8;
    margin-top: 25px;
    padding: 20px;
    background: var(--branco);
    border: 1px solid var(--borda);
    border-radius: 12px;
    overflow-wrap: anywhere;
}

.watch-divider {
    margin: 40px 0;
    border: 0;
    border-top: 1px solid var(--borda);
}

.episodios-titulo {
    margin: 0 0 20px;
    color: var(--rosa-escuro);
    font-size: 23px;
}

/* EPISÓDIOS */

.episodios {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(135px, 1fr));
    gap: 12px;
}

.episodio {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 50px;
    padding: 12px 9px;
    border-radius: 10px;
    background: var(--branco);
    border: 1px solid var(--borda);
    color: var(--texto);
    text-decoration: none;
    text-align: center;
    font-size: 14px;
    font-weight: 600;
    overflow-wrap: anywhere;
    transition: .2s;
}

.episodio:hover {
    background: var(--rosa-claro);
    border-color: var(--rosa);
    color: var(--rosa-escuro);
    transform: translateY(-2px);
}

.episodio.ativo {
    background: var(--rosa);
    border-color: var(--rosa);
    color: white;
}

.episodios-vazios {
    color: var(--texto-secundario);
}

/* PLAYER VAZIO */

.player-vazio {
    padding: 45px 22px;
    border-radius: 15px;
    text-align: center;
    background: var(--branco);
    border: 1px solid var(--borda);
}

.player-vazio h2 {
    color: var(--rosa-escuro);
    margin: 0 0 10px;
}

.player-vazio p {
    color: var(--texto-secundario);
    margin: 0;
    line-height: 1.6;
}

/* TABLETS */

@media (max-width: 900px) {
    .watch-container {
        width: calc(100% - 32px);
    }

    .episodios {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

/* MOBILE AUTOMÁTICO */

@media (max-width: 600px) {
    body {
        overflow-x: hidden;
    }

    .watch-container {
        width: calc(100% - 24px);
        margin: 15px auto;
        padding: 10px 0 35px;
    }

    .watch-back {
        width: 100%;
        padding: 13px;
        font-size: 14px;
    }

    .watch-header {
        gap: 13px;
        padding: 14px;
        margin: 18px 0;
        align-items: flex-start;
        border-radius: 12px;
    }

    .watch-capa {
        width: 90px;
        height: 125px;
        border-radius: 9px;
    }

    .watch-infos h1 {
        font-size: 19px;
        margin-bottom: 8px;
    }

    .watch-infos p {
        font-size: 12px;
        margin: 6px 0;
    }

    .ad-block-badge {
        font-size: 10px;
        padding: 7px 9px;
    }

    .player-wrap {
        width: 100%;
        border-radius: 10px;
        aspect-ratio: 16 / 9;
    }

    .controls {
        padding: 8px;
        gap: 6px;
    }

    .ctrl-row {
        gap: 7px;
    }

    .ctrl-btn {
        font-size: 17px;
        padding: 5px;
    }

    .time-display {
        font-size: 10px;
    }

    .vol-wrap {
        display: none;
    }

    .speed-btn {
        font-size: 10px;
        padding: 5px 7px;
    }

    .watch-title {
        font-size: 19px;
        margin: 18px 0;
    }

    .descricao {
        font-size: 14px;
        padding: 14px;
    }

    .episodios-titulo {
        font-size: 20px;
    }

    .episodios {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
    }

    .episodio {
        min-height: 44px;
        padding: 9px 5px;
        font-size: 12px;
        border-radius: 8px;
    }

    .watch-divider {
        margin: 27px 0;
    }
}

/* CELULARES MUITO PEQUENOS */

@media (max-width: 360px) {
    .watch-header {
        gap: 9px;
        padding: 10px;
    }

    .watch-capa {
        width: 72px;
        height: 105px;
    }

    .watch-infos h1 {
        font-size: 16px;
    }

    .episodios {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* DESKTOP E TELAS GRANDES */

@media (min-width: 1200px) {
    .watch-container {
        width: min(1200px, calc(100% - 60px));
    }

    .player-wrap {
        border-radius: 16px;
    }

    .episodios {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
}

/* ACESSIBILIDADE */

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        scroll-behavior: auto !important;
        animation-duration: .01ms !important;
        transition-duration: .01ms !important;
    }
}
</style>
</head>

<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="watch-container">

    <a href="index.php" class="watch-back">
        ← Voltar ao catálogo
    </a>

    <section class="watch-header">

        <?php if (!empty($conteudo['capa'])): ?>

            <img
                class="watch-capa"
                src="<?= h($conteudo['capa']) ?>"
                alt="<?= h($conteudo['nome']) ?>"
                loading="lazy"
                onerror="this.style.display='none'"
            >

        <?php endif; ?>

        <div class="watch-infos">

            <h1><?= h($conteudo['nome']) ?></h1>

            <p><?= h($conteudo['categoria']) ?></p>

            <p>
                <?= $ehFilme
                    ? 'Filme completo'
                    : $qtdEps . ' episódio(s) disponível(is)' ?>
            </p>

            <div class="ad-block-badge">
                🛡️ Bloqueador de anúncios ativo
            </div>

        </div>

    </section>

    <?php if ($episodioAtual !== null && $temPlayer): ?>

        <div class="player-wrap" id="playerWrap">

            <div class="loading-overlay" id="loadingOverlay">
                <div class="spinner"></div>
                Carregando...
            </div>

            <?php if ($ehHls): ?>

                <video
                    id="videoEl"
                    preload="auto"
                    playsinline
                    webkit-playsinline
                ></video>

                <div class="controls">

                    <div class="progress-bar" id="progressBar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>

                    <div class="ctrl-row">

                        <button class="ctrl-btn" id="playBtn" aria-label="Reproduzir ou pausar">
                            ▶
                        </button>

                        <div class="vol-wrap">

                            <button class="ctrl-btn" id="muteBtn" aria-label="Silenciar">
                                🔊
                            </button>

                            <input
                                type="range"
                                class="vol-slider"
                                id="volSlider"
                                min="0"
                                max="1"
                                step="0.05"
                                value="1"
                                aria-label="Volume"
                            >

                        </div>

                        <span class="time-display" id="timeDisplay">
                            0:00 / 0:00
                        </span>

                        <div class="ctrl-right">

                            <button class="speed-btn" id="speedBtn">
                                1x
                            </button>

                            <button class="ctrl-btn" id="fsBtn" aria-label="Tela cheia">
                                ⛶
                            </button>

                        </div>

                    </div>

                </div>

            <?php else: ?>

                <iframe
                    src="<?= h($playerUrl) ?>"
                    title="<?= h($conteudo['nome'] . ' — ' . ($episodioAtual['nome'] ?? '')) ?>"
                    allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                    allowfullscreen
                    loading="eager"
                    referrerpolicy="no-referrer-when-downgrade"
                    onload="document.getElementById('loadingOverlay').style.display='none'"
                ></iframe>

            <?php endif; ?>

        </div>

        <h2 class="watch-title">
            <?= h($episodioAtual['nome'] ?? '') ?>
        </h2>

        <?php if ($ehHls): ?>

        <script>
        (function() {
            'use strict';

            var src = <?= json_encode($playerUrl) ?>;

            var video = document.getElementById('videoEl');
            var wrap = document.getElementById('playerWrap');
            var ov = document.getElementById('loadingOverlay');

            var pb = document.getElementById('playBtn');
            var mb = document.getElementById('muteBtn');
            var vs = document.getElementById('volSlider');
            var td = document.getElementById('timeDisplay');
            var bar = document.getElementById('progressBar');
            var fill = document.getElementById('progressFill');
            var sb = document.getElementById('speedBtn');
            var fs = document.getElementById('fsBtn');

            var spd = [0.5, 0.75, 1, 1.25, 1.5, 2];
            var si = 2;

            if (typeof Hls !== 'undefined' && Hls.isSupported()) {

                var hls = new Hls({
                    maxBufferLength: 60,
                    maxMaxBufferLength: 120,
                    fragLoadingTimeOut: 20000,
                    manifestLoadingTimeOut: 15000
                });

                hls.loadSource(src);
                hls.attachMedia(video);

                hls.on(Hls.Events.MANIFEST_PARSED, function() {
                    ov.style.display = 'none';
                    video.play().catch(function() {});
                });

                hls.on(Hls.Events.ERROR, function(e, d) {

                    if (d.fatal) {

                        if (d.type === Hls.ErrorTypes.NETWORK_ERROR) {
                            hls.startLoad();

                        } else if (d.type === Hls.ErrorTypes.MEDIA_ERROR) {
                            hls.recoverMediaError();

                        } else {
                            ov.innerHTML =
                                '<span style="color:#f87171;text-align:center;padding:20px">Vídeo temporariamente indisponível.<br>Tente recarregar a página.</span>';
                        }
                    }
                });

            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {

                video.src = src;

                video.addEventListener('canplay', function() {
                    ov.style.display = 'none';
                });

                video.addEventListener('error', function() {
                    ov.innerHTML = '<span>Erro ao carregar o vídeo.</span>';
                });

            } else {

                ov.innerHTML = '<span>Navegador sem suporte HLS.</span>';
            }

            function fmt(s) {
                s = Math.floor(s || 0);

                var m = Math.floor(s / 60);

                return m + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
            }

            video.addEventListener('play', function() {
                pb.textContent = '⏸';
                wrap.classList.remove('paused');
            });

            video.addEventListener('pause', function() {
                pb.textContent = '▶';
                wrap.classList.add('paused');
            });

            video.addEventListener('timeupdate', function() {

                if (!video.duration || !isFinite(video.duration)) return;

                fill.style.width =
                    (video.currentTime / video.duration * 100) + '%';

                td.textContent =
                    fmt(video.currentTime) + ' / ' + fmt(video.duration);
            });

            video.addEventListener('waiting', function() {
                ov.style.display = 'flex';
            });

            video.addEventListener('playing', function() {
                ov.style.display = 'none';
            });

            pb.addEventListener('click', function() {
                video.paused ? video.play() : video.pause();
            });

            mb.addEventListener('click', function() {
                video.muted = !video.muted;
                mb.textContent = video.muted ? '🔇' : '🔊';
            });

            vs.addEventListener('input', function() {
                video.volume = Number(this.value);
            });

            bar.addEventListener('click', function(e) {

                if (!video.duration || !isFinite(video.duration)) return;

                var r = bar.getBoundingClientRect();

                var percentual = Math.max(
                    0,
                    Math.min(1, (e.clientX - r.left) / r.width)
                );

                video.currentTime = percentual * video.duration;
            });

            sb.addEventListener('click', function() {
                si = (si + 1) % spd.length;

                video.playbackRate = spd[si];

                sb.textContent = spd[si] + 'x';
            });

            fs.addEventListener('click', function() {

                if (document.fullscreenElement) {

                    document.exitFullscreen();

                } else if (wrap.requestFullscreen) {

                    wrap.requestFullscreen();

                } else if (video.webkitEnterFullscreen) {

                    video.webkitEnterFullscreen();
                }
            });

        })();
        </script>

        <?php endif; ?>

    <?php elseif (empty($episodios)): ?>

        <div class="player-vazio">
            <h2>Nenhum episódio cadastrado</h2>
            <p>Cadastre pelo painel administrativo.</p>
        </div>

    <?php else: ?>

        <div class="player-vazio">
            <h2>Player indisponível</h2>
            <p>Episódio sem player válido no momento.</p>
        </div>

    <?php endif; ?>

    <?php if (!empty($conteudo['descricao'])): ?>

        <p class="descricao">
            <?= nl2br(h($conteudo['descricao'])) ?>
        </p>

    <?php endif; ?>

    <hr class="watch-divider">

    <h2 class="episodios-titulo">
        <?= $ehFilme ? 'Reprodução' : 'Episódios disponíveis' ?>
    </h2>

    <?php if (empty($episodios)): ?>

        <p class="episodios-vazios">
            Nenhum episódio cadastrado.
        </p>

    <?php else: ?>

        <div class="episodios">

            <?php foreach ($episodios as $ep): ?>

                <?php
                    $epId = (string)($ep['id'] ?? '');

                    $ativo = $episodioAtual &&
                        $epId !== '' &&
                        $epId === (string)($episodioAtual['id'] ?? '');

                    $urlEp = 'assistir.php?id=' .
                        rawurlencode((string)$conteudo['id']) .
                        '&ep=' .
                        rawurlencode($epId);
                ?>

                <a
                    class="episodio <?= $ativo ? 'ativo' : '' ?>"
                    href="<?= h($urlEp) ?>"
                    <?= $ativo ? 'aria-current="page"' : '' ?>
                >
                    <?= h($ep['nome'] ?? 'Episódio') ?>
                </a>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
