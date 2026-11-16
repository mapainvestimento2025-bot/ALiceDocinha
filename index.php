<?php
require_once __DIR__ . '/config.php';

$arquivoConteudos = __DIR__ . '/conteudos.json';

/*
|--------------------------------------------------------------------------
| CARREGAR CONTEÚDOS
|--------------------------------------------------------------------------
*/

if (!file_exists($arquivoConteudos)) {
    file_put_contents($arquivoConteudos, '[]', LOCK_EX);
}

$conteudoJson = file_get_contents($arquivoConteudos);
$dados = json_decode($conteudoJson, true);

if (!is_array($dados)) {
    $dados = [];
}

/*
|--------------------------------------------------------------------------
| FUNÇÕES AUXILIARES
|--------------------------------------------------------------------------
*/

function escaparCatalogo($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function normalizarTextoCatalogo($texto) {
    $texto = trim((string)$texto);

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($texto, 'UTF-8');
    }

    return strtolower($texto);
}

/*
|--------------------------------------------------------------------------
| ORGANIZAR CATÁLOGO E AGRUPAR TEMPORADAS / EPISÓDIOS
|--------------------------------------------------------------------------
| Cada anime/série aparece uma única vez no catálogo.
| Os registros separados são agrupados por tipo + título.
| Cada episódio é associado à sua temporada.
| Registros antigos sem "temporada" são considerados temporada 1.
| Também mantém compatibilidade com o formato antigo de episódios aninhados.
*/

function organizarCatalogo($dados) {

    $catalogo = [];

    $tipos = [
        'anime' => 'Anime',
        'serie' => 'Série',
        'série' => 'Série',
        'filme' => 'Filme'
    ];

    foreach ($dados as $item) {

        if (!is_array($item)) {
            continue;
        }

        $tipoOriginal = normalizarTextoCatalogo(
            $item['tipo'] ?? $item['categoria'] ?? ''
        );

        $tipo = $tipos[$tipoOriginal] ?? '';

        if ($tipo === '') {
            continue;
        }

        $titulo = trim((string)($item['titulo'] ?? $item['nome'] ?? ''));

        if ($titulo === '') {
            continue;
        }

        $chave = $tipo . '|' . normalizarTextoCatalogo($titulo);

        if (!isset($catalogo[$chave])) {
            $catalogo[$chave] = [
                'id' => '',
                'ids' => [],
                'nome' => $titulo,
                'categoria' => $tipo,
                'capa' => '',
                'descricao' => '',
                'episodios' => [],
                'temporadas' => []
            ];
        }

        if (
            $catalogo[$chave]['capa'] === '' &&
            !empty($item['capa'])
        ) {
            $catalogo[$chave]['capa'] = trim((string)$item['capa']);
        }

        if (
            $catalogo[$chave]['descricao'] === '' &&
            !empty($item['descricao'])
        ) {
            $catalogo[$chave]['descricao'] = (string)$item['descricao'];
        }

        if (!empty($item['id'])) {
            $idItem = (string)$item['id'];

            if ($catalogo[$chave]['id'] === '') {
                $catalogo[$chave]['id'] = $idItem;
            }

            $catalogo[$chave]['ids'][] = $idItem;
        }

        /*
         * Compatibilidade com registros antigos que armazenam
         * uma lista de episódios dentro do próprio conteúdo.
         */
        if (!empty($item['episodios']) && is_array($item['episodios'])) {

            foreach ($item['episodios'] as $ep) {

                if (!is_array($ep)) {
                    continue;
                }

                $idEp = (string)($ep['id'] ?? '');

                if ($idEp === '') {
                    $idEp = 'ep-' . bin2hex(random_bytes(8));
                }

                $numero = (int)($ep['numero'] ?? $ep['episodio'] ?? 0);
                $temporada = max(1, (int)($ep['temporada'] ?? $item['temporada'] ?? 1));

                $catalogo[$chave]['episodios'][] = [
                    'id' => $idEp,
                    'nome' => (string)($ep['nome'] ?? (
                        $numero > 0 ? 'Episódio ' . $numero : 'Assistir'
                    )),
                    'numero' => $numero,
                    'temporada' => $temporada,
                    'player' => (string)($ep['player'] ?? '')
                ];
            }
        }

        /*
         * Formato atual do admin.php:
         * cada episódio é salvo como um registro separado.
         */
        if (!empty($item['player'])) {

            $numero = (int)($item['episodio'] ?? 0);
            $temporada = max(1, (int)($item['temporada'] ?? 1));

            if ($tipo === 'Filme') {
                $nomeEp = 'Filme completo';
                $temporada = 1;
            } elseif ($numero > 0) {
                $nomeEp = 'Episódio ' . $numero;
            } else {
                $nomeEp = 'Assistir';
            }

            $idEp = (string)($item['id'] ?? '');

            if ($idEp === '') {
                $idEp = 'ep-' . bin2hex(random_bytes(8));
            }

            $catalogo[$chave]['episodios'][] = [
                'id' => $idEp,
                'nome' => $nomeEp,
                'numero' => $numero,
                'temporada' => $temporada,
                'player' => (string)$item['player']
            ];
        }
    }

    /*
     * Remover duplicações por ID e ordenar por temporada / episódio.
     */
    foreach ($catalogo as &$conteudo) {

        $episodiosUnicos = [];
        $idsVistos = [];

        foreach ($conteudo['episodios'] as $ep) {
            $chaveEp = (string)$ep['id'];

            if (isset($idsVistos[$chaveEp])) {
                continue;
            }

            $idsVistos[$chaveEp] = true;
            $episodiosUnicos[] = $ep;
        }

        usort($episodiosUnicos, function ($a, $b) {

            $temporadaA = (int)($a['temporada'] ?? 1);
            $temporadaB = (int)($b['temporada'] ?? 1);

            if ($temporadaA !== $temporadaB) {
                return $temporadaA <=> $temporadaB;
            }

            if ((int)$a['numero'] === (int)$b['numero']) {
                return strcmp((string)$a['nome'], (string)$b['nome']);
            }

            if ((int)$a['numero'] === 0) {
                return 1;
            }

            if ((int)$b['numero'] === 0) {
                return -1;
            }

            return (int)$a['numero'] <=> (int)$b['numero'];
        });

        $conteudo['episodios'] = array_values($episodiosUnicos);

        /*
         * Montar estrutura de temporadas para exibição no catálogo.
         */
        $temporadas = [];

        foreach ($conteudo['episodios'] as $ep) {

            $numeroTemporada = max(1, (int)($ep['temporada'] ?? 1));

            if (!isset($temporadas[$numeroTemporada])) {
                $temporadas[$numeroTemporada] = [
                    'numero' => $numeroTemporada,
                    'episodios' => []
                ];
            }

            $temporadas[$numeroTemporada]['episodios'][] = $ep;
        }

        ksort($temporadas, SORT_NUMERIC);

        $conteudo['temporadas'] = array_values($temporadas);

        /*
         * Se o primeiro registro não tiver ID, usa o ID do primeiro
         * episódio disponível para abrir a página de reprodução.
         */
        if ($conteudo['id'] === '' && !empty($conteudo['episodios'])) {
            $conteudo['id'] = $conteudo['episodios'][0]['id'];
        }

        /*
         * Garante que o ID principal corresponda a um episódio existente.
         */
        if (!empty($conteudo['episodios'])) {
            $idsDisponiveis = array_column($conteudo['episodios'], 'id');

            if (!in_array($conteudo['id'], $idsDisponiveis, true)) {
                $conteudo['id'] = $conteudo['episodios'][0]['id'];
            }
        }
    }

    unset($conteudo);

    return array_values($catalogo);
}

/*
|--------------------------------------------------------------------------
| FILTROS E PESQUISA
|--------------------------------------------------------------------------
*/

$dados = organizarCatalogo($dados);

$categoria = trim((string)($_GET['categoria'] ?? 'Todos'));
$busca = trim((string)($_GET['busca'] ?? ''));

$categorias = ['Anime', 'Filme', 'Série'];

if ($categoria !== 'Todos' && !in_array($categoria, $categorias, true)) {
    $categoria = 'Todos';
}

$conteudos = array_filter($dados, function ($item) use ($categoria, $busca) {

    $nome = normalizarTextoCatalogo($item['nome'] ?? '');
    $termo = normalizarTextoCatalogo($busca);

    $categoriaOk = $categoria === 'Todos' ||
        ($item['categoria'] ?? '') === $categoria;

    $buscaOk = $termo === '' ||
        strpos($nome, $termo) !== false;

    return $categoriaOk && $buscaOk;
});

$conteudos = array_values($conteudos);

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<meta name="theme-color" content="#0c0e14">

<title>ANIMEFLIX - Filmes, Séries e Animes</title>

<link rel="stylesheet" href="assets/css/style.css">

<style>
/* =========================================================
   ANIMEFLIX — TEMA ROSA CLARO PREMIUM
   Mantém a estrutura PHP e os componentes existentes.
========================================================= */

:root {
    color-scheme: light;
    --pink-50: #fff7fa;
    --pink-100: #ffedf3;
    --pink-200: #fbd8e4;
    --pink-300: #f5b8ce;
    --pink-500: #d94f83;
    --pink-600: #c83d73;
    --pink-700: #a92d60;
    --ink: #30232a;
    --ink-soft: #66535d;
    --muted: #806d77;
    --surface: #ffffff;
    --surface-soft: #fffafd;
    --border: #f1dce5;
    --shadow: 0 12px 35px rgba(125, 54, 85, .08);
    --shadow-hover: 0 20px 45px rgba(125, 54, 85, .15);
}

html {
    scroll-behavior: smooth;
    scroll-padding-top: 90px;
    background: var(--pink-50);
}

body {
    margin: 0;
    color: var(--ink);
    background:
        radial-gradient(ellipse at 10% 0%, rgba(251, 216, 228, .42), transparent 34rem),
        linear-gradient(180deg, #fffafd 0%, #fff7fa 48%, #fffafd 100%);
    font-family: Inter, Poppins, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}

button, input, select {
    font: inherit;
}

a {
    -webkit-tap-highlight-color: transparent;
}

/* Área de destaque */
.catalogo-hero {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    padding: clamp(54px, 7vw, 92px) max(6%, calc((100% - 1400px) / 2));
    background:
        radial-gradient(circle at 85% 20%, rgba(255,255,255,.62), transparent 19rem),
        linear-gradient(120deg, #ffe8f0 0%, #fbd3e2 52%, #f8c5d9 100%);
    border-bottom: 1px solid #f3d3df;
}

.catalogo-hero::after {
    content: "";
    position: absolute;
    z-index: -1;
    width: 340px;
    height: 340px;
    right: -110px;
    bottom: -210px;
    border: 1px solid rgba(201, 61, 115, .13);
    border-radius: 50%;
    box-shadow: 0 0 0 35px rgba(255,255,255,.12), 0 0 0 75px rgba(255,255,255,.09);
}

.catalogo-hero h1 {
    max-width: 760px;
    margin: 0 0 14px;
    color: #482536;
    font-size: clamp(30px, 4vw, 48px);
    font-weight: 800;
    line-height: 1.12;
    letter-spacing: -1.5px;
}

.catalogo-hero p {
    max-width: 620px;
    margin: 0 0 28px;
    color: #684454;
    font-size: clamp(15px, 1.5vw, 18px);
    line-height: 1.75;
}

.catalogo-hero .btn,
.btn.catalogo-assistir,
.catalogo-filtros button.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 46px;
    padding: 12px 21px;
    border: 1px solid transparent;
    border-radius: 12px;
    color: #fff;
    background: linear-gradient(135deg, var(--pink-500), var(--pink-600));
    box-shadow: 0 8px 18px rgba(200, 61, 115, .20);
    font-weight: 750;
    text-decoration: none;
    cursor: pointer;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease;
}

.catalogo-hero .btn:hover,
.btn.catalogo-assistir:hover,
.catalogo-filtros button.btn:hover {
    transform: translateY(-2px);
    filter: saturate(1.08);
    box-shadow: 0 12px 24px rgba(200, 61, 115, .27);
}

/* Conteúdo principal */
#catalogo.container {
    width: min(1400px, 88%);
    max-width: 1400px;
    margin: 0 auto;
    padding-top: clamp(38px, 5vw, 64px);
    padding-bottom: clamp(55px, 7vw, 90px);
}

#catalogo > h2 {
    margin: 0 0 22px !important;
    color: #422735;
    font-size: clamp(23px, 2.5vw, 30px);
    font-weight: 800;
    letter-spacing: -.7px;
}

/* Pesquisa e filtros */
.catalogo-filtros {
    display: flex;
    align-items: center;
    gap: 12px;
    width: min(780px, 100%);
    margin: 0 0 25px;
}

.catalogo-filtros input,
.catalogo-filtros select {
    box-sizing: border-box;
    min-height: 49px;
    padding: 0 15px;
    border: 1px solid #e9cbd8;
    border-radius: 12px;
    outline: none;
    color: #3c2932;
    background: rgba(255,255,255,.94);
    box-shadow: 0 3px 12px rgba(105, 49, 72, .035);
    font-size: 14px;
    transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
}

.catalogo-filtros input {
    flex: 1;
    min-width: 190px;
}

.catalogo-filtros input::placeholder {
    color: #927d87;
    opacity: 1;
}

.catalogo-filtros input:focus,
.catalogo-filtros select:focus {
    border-color: var(--pink-500);
    background: #fff;
    box-shadow: 0 0 0 4px rgba(217, 79, 131, .13);
}

.catalogo-filtros select {
    min-width: 180px;
    cursor: pointer;
}

.catalogo-filtros button.btn {
    min-height: 49px;
    white-space: nowrap;
}

/* Contador */
.catalogo-contador {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 22px;
    color: #725c67;
    font-size: 13px;
    font-weight: 600;
}

.catalogo-contador::before {
    content: "";
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #d94f83;
    box-shadow: 0 0 0 4px rgba(217, 79, 131, .12);
}

/* Grade de conteúdos */
.catalogo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(205px, 1fr));
    gap: 24px;
    align-items: stretch;
}

.catalogo-card {
    min-width: 0;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 17px;
    background: var(--surface);
    box-shadow: var(--shadow);
    transition: transform .28s cubic-bezier(.2,.75,.25,1), box-shadow .28s ease, border-color .28s ease;
    animation: catalogoEntrada .45s ease both;
}

.catalogo-card:hover {
    transform: translateY(-6px);
    border-color: #eab4c9;
    box-shadow: var(--shadow-hover);
}

.catalogo-capa,
.catalogo-capa-placeholder {
    display: block;
    width: 100%;
    height: 290px;
    object-fit: cover;
    background: #f8eaf0;
}

.catalogo-capa {
    transition: transform .45s ease;
}

.catalogo-card:hover .catalogo-capa {
    transform: scale(1.035);
}

.catalogo-capa-placeholder {
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 18px;
    color: #80566a;
    background:
        radial-gradient(circle at 50% 30%, rgba(255,255,255,.9), transparent 55%),
        linear-gradient(145deg, #ffeaf2, #f7d6e3);
    text-align: center;
}

.catalogo-capa-placeholder span {
    font-size: 46px;
    filter: drop-shadow(0 5px 8px rgba(140, 60, 91, .12));
}

.catalogo-capa-placeholder strong {
    max-width: 100%;
    color: #593448;
    font-size: 13px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

/* Informações do card */
.catalogo-info {
    padding: 17px;
}

.catalogo-info h3 {
    margin: 0 0 9px;
    color: #382630;
    font-size: 17px;
    font-weight: 800;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.catalogo-tipo {
    display: inline-flex;
    align-items: center;
    margin-bottom: 11px;
    padding: 6px 10px;
    border: 1px solid #f3d0de;
    border-radius: 999px;
    color: #9e315e;
    background: #fff0f5;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .55px;
    text-transform: uppercase;
}

.catalogo-episodios {
    margin-top: 10px;
    color: #796570;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.6;
}

/* Temporadas e episódios */
.catalogo-temporadas {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 15px;
}

.catalogo-temporada {
    padding: 11px;
    border: 1px solid #f0dce5;
    border-radius: 11px;
    background: #fff9fc;
}

.catalogo-temporada-titulo {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 10px;
    color: #71364f;
    font-size: 12px;
    font-weight: 800;
}

.catalogo-temporada-total {
    color: #927985;
    font-size: 10px;
    font-weight: 650;
}

.catalogo-episodios-lista {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.ep-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 30px;
    padding: 6px 9px;
    border: 1px solid #efd6e1;
    border-radius: 8px;
    color: #684354;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.3;
    text-decoration: none;
    transition: color .2s ease, background .2s ease, border-color .2s ease, transform .2s ease;
}

.ep-link:hover {
    transform: translateY(-1px);
    border-color: var(--pink-500);
    color: #fff;
    background: var(--pink-600);
}

.ep-link-mais {
    border-color: #f0c6d7;
    color: #a33361;
    background: #ffedf4;
}

.ep-link-mais:hover {
    border-color: var(--pink-600);
    color: #fff;
    background: var(--pink-600);
}

/* Ação assistir */
.btn.catalogo-assistir {
    box-sizing: border-box;
    width: 100%;
    min-height: 44px;
    margin-top: 16px;
    padding: 11px 14px;
    border-radius: 11px;
    font-size: 13px;
    letter-spacing: .1px;
}

.btn.catalogo-assistir:disabled {
    border: 1px solid #eadde3;
    color: #8b7b83;
    background: #f5eff2;
    box-shadow: none;
    cursor: not-allowed;
    opacity: 1;
}

/* Estado vazio */
.catalogo-vazio {
    padding: clamp(35px, 6vw, 60px) 22px;
    border: 1px dashed #e6b9cb;
    border-radius: 17px;
    color: #705b65;
    background: rgba(255,255,255,.8);
    text-align: center;
}

.catalogo-vazio h3 {
    margin: 0;
    color: #4a2e3b;
    font-size: 20px;
    font-weight: 800;
}

.catalogo-vazio p {
    max-width: 520px;
    margin: 10px auto 0 !important;
    color: #796570;
    font-size: 14px;
    line-height: 1.7;
}

/* Acessibilidade e movimento */
:focus-visible {
    outline: 3px solid rgba(217, 79, 131, .42);
    outline-offset: 3px;
}

@keyframes catalogoEntrada {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 900px) {
    #catalogo.container {
        width: 90%;
    }

    .catalogo-grid {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 18px;
    }

    .catalogo-capa,
    .catalogo-capa-placeholder {
        height: 260px;
    }
}

@media (max-width: 600px) {
    .catalogo-hero {
        padding: 48px 5.5%;
    }

    .catalogo-hero h1 {
        max-width: 520px;
        font-size: 29px;
        letter-spacing: -.8px;
    }

    .catalogo-hero p {
        font-size: 14px;
    }

    #catalogo.container {
        width: 90%;
        padding-top: 36px;
    }

    .catalogo-filtros {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }

    .catalogo-filtros input,
    .catalogo-filtros select,
    .catalogo-filtros button {
        box-sizing: border-box;
        width: 100%;
        min-width: 0;
    }

    .catalogo-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 13px;
    }

    .catalogo-capa,
    .catalogo-capa-placeholder {
        height: clamp(175px, 55vw, 235px);
    }

    .catalogo-info {
        padding: 11px;
    }

    .catalogo-info h3 {
        font-size: 13px;
    }

    .catalogo-tipo {
        margin-bottom: 8px;
        padding: 5px 8px;
        font-size: 9px;
    }

    .catalogo-episodios {
        font-size: 11px;
    }

    .catalogo-temporada {
        padding: 8px;
    }

    .catalogo-temporada-titulo {
        font-size: 10px;
    }

    .ep-link {
        min-height: 27px;
        padding: 5px 7px;
        font-size: 9px;
    }

    .btn.catalogo-assistir {
        min-height: 40px;
        padding: 9px 7px;
        font-size: 11px;
    }
}

@media (max-width: 360px) {
    .catalogo-grid {
        grid-template-columns: 1fr;
    }

    .catalogo-capa,
    .catalogo-capa-placeholder {
        height: 300px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        scroll-behavior: auto !important;
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
    }
}
</style>

</head>

<body>

<?php include __DIR__ . '/includes/header.php'; ?>


<!-- DESTAQUE -->

<section class="catalogo-hero">

    <h1>Seu entretenimento em um só lugar</h1>

    <p>
        Descubra animes, filmes e séries.
    </p>

    <a href="#catalogo" class="btn">
        Explorar catálogo
    </a>

</section>


<!-- CATÁLOGO -->

<main class="container" id="catalogo">

    <h2 style="margin-bottom:20px">
        Explore o catálogo
    </h2>


    <!-- PESQUISA -->

    <form method="GET" class="catalogo-filtros">

        <input
            type="text"
            name="busca"
            placeholder="Buscar anime, filme ou série..."
            value="<?= escaparCatalogo($busca) ?>"
        >

        <select name="categoria">

            <option value="Todos">
                Todas as categorias
            </option>

            <?php foreach ($categorias as $cat): ?>

                <option
                    value="<?= escaparCatalogo($cat) ?>"
                    <?= $categoria === $cat ? 'selected' : '' ?>
                >
                    <?= escaparCatalogo($cat) ?>
                </option>

            <?php endforeach; ?>

        </select>

        <button class="btn" type="submit">
            Pesquisar
        </button>

    </form>


    <p class="catalogo-contador">
        <?= count($conteudos) ?>
        conteúdo(s) encontrado(s)
    </p>


    <!-- RESULTADOS -->

    <?php if (empty($conteudos)): ?>

        <div class="catalogo-vazio">

            <h3>Nenhum conteúdo encontrado</h3>

            <p style="margin-top:10px">
                Os conteúdos cadastrados no painel administrativo
                aparecerão aqui automaticamente.
            </p>

        </div>

    <?php else: ?>

        <div class="catalogo-grid">

            <?php foreach ($conteudos as $item): ?>

                <article class="catalogo-card">

                    <!-- CAPA ÚNICA DO ANIME/SÉRIE/FILME -->

                    <?php if (!empty($item['capa'])): ?>

                        <img
                            class="catalogo-capa"
                            src="<?= escaparCatalogo($item['capa']) ?>"
                            alt="<?= escaparCatalogo($item['nome']) ?>"
                            loading="lazy"
                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                        >

                        <div class="catalogo-capa-placeholder" style="display:none">
                            <span>🎬</span>
                            <strong><?= escaparCatalogo($item['nome']) ?></strong>
                        </div>

                    <?php else: ?>

                        <div class="catalogo-capa-placeholder">
                            <span>🎬</span>
                            <strong><?= escaparCatalogo($item['nome']) ?></strong>
                        </div>

                    <?php endif; ?>


                    <div class="catalogo-info">

                        <!-- CATEGORIA -->

                        <span class="catalogo-tipo">
                            <?= escaparCatalogo($item['categoria']) ?>
                        </span>


                        <!-- NOME -->

                        <h3>
                            <?= escaparCatalogo($item['nome']) ?>
                        </h3>


                        <!-- QUANTIDADE DE TEMPORADAS / EPISÓDIOS -->

                        <div class="catalogo-episodios">

                            <?php if ($item['categoria'] === 'Filme'): ?>

                                Filme completo

                            <?php else: ?>

                                <?php
                                    $totalTemporadas = count($item['temporadas']);
                                    $totalEpisodios = count($item['episodios']);
                                ?>

                                <?= $totalTemporadas ?>
                                temporada(s) •
                                <?= $totalEpisodios ?>
                                episódio(s)

                            <?php endif; ?>

                        </div>


                        <!-- TEMPORADAS E EPISÓDIOS -->

                        <?php if (!empty($item['temporadas']) && $item['categoria'] !== 'Filme'): ?>

                            <div class="catalogo-temporadas">

                                <?php foreach ($item['temporadas'] as $temporada): ?>

                                    <section class="catalogo-temporada">

                                        <div class="catalogo-temporada-titulo">

                                            <span>
                                                Temporada <?= (int)$temporada['numero'] ?>
                                            </span>

                                            <span class="catalogo-temporada-total">
                                                <?= count($temporada['episodios']) ?>
                                                ep.
                                            </span>

                                        </div>

                                        <div class="catalogo-episodios-lista">

                                            <?php
                                                $episodiosExibidos = array_slice($temporada['episodios'], 0, 4);
                                            ?>

                                            <?php foreach ($episodiosExibidos as $ep): ?>

                                                <a
                                                    class="ep-link"
                                                    href="assistir.php?id=<?= urlencode($item['id']) ?>&ep=<?= urlencode($ep['id']) ?>"
                                                    title="<?= escaparCatalogo($ep['nome']) ?>"
                                                >
                                                    <?= escaparCatalogo($ep['nome']) ?>
                                                </a>

                                            <?php endforeach; ?>

                                            <?php if (count($temporada['episodios']) > 4): ?>

                                                <a
                                                    class="ep-link ep-link-mais"
                                                    href="assistir.php?id=<?= urlencode($item['id']) ?>"
                                                >
                                                    +<?= count($temporada['episodios']) - 4 ?> episódios
                                                </a>

                                            <?php endif; ?>

                                        </div>

                                    </section>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>


                        <!-- BOTÃO ASSISTIR -->

                        <?php if (!empty($item['episodios']) && !empty($item['id'])): ?>

                            <?php
                                $primeiroEpisodio = $item['episodios'][0];
                            ?>

                            <a
                                class="btn catalogo-assistir"
                                href="assistir.php?id=<?= urlencode($item['id']) ?>&ep=<?= urlencode($primeiroEpisodio['id']) ?>"
                            >
                                ▶ Assistir agora
                            </a>

                        <?php else: ?>

                            <button
                                class="btn catalogo-assistir"
                                type="button"
                                disabled
                            >
                                Sem player disponível
                            </button>

                        <?php endif; ?>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>


<?php include __DIR__ . '/includes/footer.php'; ?>

</body>

</html>
