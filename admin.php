<?php
require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| CONFIGURAÇÕES
|--------------------------------------------------------------------------
*/

$arquivoAdmins   = __DIR__ . '/admins.json';
$arquivoConteudos = __DIR__ . '/conteudos.json';

if (!file_exists($arquivoAdmins)) {
    file_put_contents($arquivoAdmins, '[]', LOCK_EX);
}

if (!file_exists($arquivoConteudos)) {
    file_put_contents($arquivoConteudos, '[]', LOCK_EX);
}

/*
|--------------------------------------------------------------------------
| FUNÇÕES
|--------------------------------------------------------------------------
*/

function escapar($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function listarJsonAdmin(string $arquivo): array
{
    if (!is_file($arquivo) || !is_readable($arquivo)) {
        return [];
    }

    $dados = json_decode((string)file_get_contents($arquivo), true);
    return is_array($dados) ? $dados : [];
}

function listarAdmins(): array
{
    global $arquivoAdmins;
    return listarJsonAdmin($arquivoAdmins);
}

function salvarAdmins(array $admins): bool
{
    global $arquivoAdmins;

    return file_put_contents(
        $arquivoAdmins,
        json_encode($admins, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

function listarConteudos(): array
{
    global $arquivoConteudos;
    return listarJsonAdmin($arquivoConteudos);
}

function salvarConteudos(array $conteudos): bool
{
    global $arquivoConteudos;

    return file_put_contents(
        $arquivoConteudos,
        json_encode(array_values($conteudos), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

function verificarToken(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
}

function normalizarTextoAdmin(string $valor): string
{
    $valor = preg_replace('/\s+/', ' ', trim($valor));
    return function_exists('mb_strtolower')
        ? mb_strtolower($valor, 'UTF-8')
        : strtolower($valor);
}

function normalizarTipoAdmin(string $tipo): string
{
    $tipo = strtolower(trim($tipo));

    $tipos = [
        'anime' => 'anime',
        'serie' => 'serie',
        'série' => 'serie',
        'filme' => 'filme'
    ];

    return $tipos[$tipo] ?? '';
}

function nomeTipoAdmin(string $tipo): string
{
    $nomes = [
        'anime' => 'Anime',
        'serie' => 'Série',
        'filme' => 'Filme'
    ];

    return $nomes[$tipo] ?? 'Conteúdo';
}

/*
|--------------------------------------------------------------------------
| VALIDAR URL
|--------------------------------------------------------------------------
*/

function validarUrl($url, bool $somenteHttps = false): bool
{
    $url = trim((string)$url);

    if ($url === '' || preg_match('/[\r\n<>"\']/', $url)) {
        return false;
    }

    $partes = @parse_url($url);
    if (!is_array($partes) || empty($partes['host'])) {
        return false;
    }

    $esquema = strtolower((string)($partes['scheme'] ?? ''));
    if ($somenteHttps && $esquema !== 'https') {
        return false;
    }
    if (!$somenteHttps && !in_array($esquema, ['http', 'https'], true)) {
        return false;
    }

    if (isset($partes['user']) || isset($partes['pass'])) {
        return false;
    }

    $host = rawurldecode((string)$partes['host']);
    if ($host === '' || preg_match('/[\s\/%?#@]/', $host)) {
        return false;
    }

    return filter_var($host, FILTER_VALIDATE_IP) !== false
        || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
}

/*
|--------------------------------------------------------------------------
| EXTRAIR PLAYER DO IFRAME
|--------------------------------------------------------------------------
*/

function extrairPlayer($entrada)
{
    $entrada = trim((string)$entrada);

    if ($entrada === '') {
        return false;
    }

    $entrada = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $entrada);
    $entrada = trim($entrada);

    $url = '';

    if (preg_match('/<iframe\b([^>]*)>/is', $entrada, $iframe)) {
        $atributos = $iframe[1];

        if (preg_match('/\b(?:src|data-src|data-lazy-src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $atributos, $src)) {
            $url = $src[1] !== '' ? $src[1] : ($src[2] !== '' ? $src[2] : ($src[3] ?? ''));
        } else {
            return false;
        }
    } else {
        $url = trim($entrada, " \t\n\r\0\x0B\"'");
    }

    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    }

    $partes = @parse_url($url);
    if (!is_array($partes) || empty($partes['host'])) {
        return false;
    }

    if (strtolower((string)($partes['scheme'] ?? '')) !== 'https') {
        return false;
    }

    if (isset($partes['user']) || isset($partes['pass'])) {
        return false;
    }

    $host = rawurldecode((string)$partes['host']);
    if ($host === '' || preg_match('/[\s\/%?#@]/', $host)) {
        return false;
    }

    $urlNormalizada = 'https://' . $host;
    if (isset($partes['port'])) {
        $urlNormalizada .= ':' . (int)$partes['port'];
    }
    $urlNormalizada .= (string)($partes['path'] ?? '');
    if (isset($partes['query'])) {
        $urlNormalizada .= '?' . $partes['query'];
    }
    if (isset($partes['fragment'])) {
        $urlNormalizada .= '#' . $partes['fragment'];
    }

    return validarUrl($urlNormalizada, true) ? $urlNormalizada : false;
}

/*
|--------------------------------------------------------------------------
| LOCALIZAR CAPA DE UM CONTEÚDO JÁ CADASTRADO
|--------------------------------------------------------------------------
*/

function buscarCapaExistente(array $conteudos, string $tipo, string $titulo): string
{
    $tituloNormalizado = normalizarTextoAdmin($titulo);

    foreach (array_reverse($conteudos) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $tipoExistente   = normalizarTipoAdmin((string)($item['tipo'] ?? $item['categoria'] ?? ''));
        $tituloExistente = normalizarTextoAdmin((string)($item['titulo'] ?? $item['nome'] ?? ''));

        if (
            $tipoExistente === $tipo &&
            $tituloExistente === $tituloNormalizado &&
            !empty($item['capa']) &&
            validarUrl((string)$item['capa'])
        ) {
            return trim((string)$item['capa']);
        }
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| AGRUPAR CATÁLOGO POR TÍTULO E TIPO
|--------------------------------------------------------------------------
*/

function agruparConteudosAdmin(array $conteudos): array
{
    $grupos = [];

    foreach ($conteudos as $item) {
        if (!is_array($item)) {
            continue;
        }

        $tipo   = normalizarTipoAdmin((string)($item['tipo'] ?? $item['categoria'] ?? ''));
        $titulo = trim((string)($item['titulo'] ?? $item['nome'] ?? ''));

        if ($tipo === '' || $titulo === '') {
            continue;
        }

        $chave = $tipo . '|' . normalizarTextoAdmin($titulo);

        if (!isset($grupos[$chave])) {
            $grupos[$chave] = [
                'chave'      => $chave,
                'tipo'       => $tipo,
                'titulo'     => $titulo,
                'capa'       => '',
                'ids'        => [],
                'episodios'  => [],
                'temporadas' => [],
                'quantidade' => 0,
                'atualizado' => ''
            ];
        }

        if ($grupos[$chave]['capa'] === '' && !empty($item['capa'])) {
            $grupos[$chave]['capa'] = (string)$item['capa'];
        }

        if (!empty($item['id'])) {
            $grupos[$chave]['ids'][] = (string)$item['id'];
        }

        $numero   = (int)($item['episodio'] ?? $item['numero'] ?? 0);
        $temporada = (int)($item['temporada'] ?? 1);
        if ($temporada < 1) { $temporada = 1; }

        if ($tipo === 'filme') {
            $rotulo = 'Filme completo';
        } elseif ($numero > 0) {
            $rotulo = 'Temporada ' . $temporada . ' • Episódio ' . $numero;
        } else {
            $rotulo = 'Player cadastrado';
        }

        if (!empty($item['player'])) {
            $grupos[$chave]['episodios'][] = [
                'id'       => (string)($item['id'] ?? ''),
                'numero'   => $numero,
                'temporada' => $temporada,
                'nome'     => $rotulo
            ];
        }

        if ($tipo !== 'filme') { $grupos[$chave]['temporadas'][$temporada] = true; }
        $grupos[$chave]['quantidade']++;
        $data = (string)($item['criado_em'] ?? '');
        if ($data > $grupos[$chave]['atualizado']) {
            $grupos[$chave]['atualizado'] = $data;
        }
    }

    foreach ($grupos as &$grupo) {
        usort($grupo['episodios'], function ($a, $b) {
            return ($a['temporada'] <=> $b['temporada']) ?: ($a['numero'] <=> $b['numero']);
        });
        $grupo['ids']        = array_values(array_unique($grupo['ids']));
        $grupo['primeiro_id'] = $grupo['ids'][0] ?? '';
    }
    unset($grupo);

    return array_values($grupos);
}

/*
|--------------------------------------------------------------------------
| TOKEN CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$admins    = listarAdmins();
$conteudos = listarConteudos();
$erro      = '';
$mensagem  = '';

/*
|--------------------------------------------------------------------------
| CADASTRAR PRIMEIRO ADMINISTRADOR
|--------------------------------------------------------------------------
*/

if (isset($_POST['cadastrar'])) {
    if (!verificarToken()) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }

    if (!empty($admins)) {
        $erro = 'Já existe um administrador cadastrado.';
    } else {
        $usuario  = trim((string)($_POST['usuario'] ?? ''));
        $senha    = (string)($_POST['senha'] ?? '');
        $confirmar = (string)($_POST['confirmar'] ?? '');

        if (strlen($usuario) < 3) {
            $erro = 'O usuário precisa ter pelo menos 3 caracteres.';
        } elseif (strlen($senha) < 10) {
            $erro = 'A senha precisa ter pelo menos 10 caracteres.';
        } elseif ($senha !== $confirmar) {
            $erro = 'As senhas não são iguais.';
        } else {
            $admins[] = [
                'id'      => bin2hex(random_bytes(12)),
                'usuario' => $usuario,
                'senha'   => password_hash($senha, PASSWORD_DEFAULT)
            ];

            if (!salvarAdmins($admins)) {
                $erro = 'Não foi possível salvar o administrador.';
            } else {
                session_regenerate_id(true);
                $_SESSION['admin']          = true;
                $_SESSION['admin_usuario']  = $usuario;
                $_SESSION['csrf']           = bin2hex(random_bytes(32));
                header('Location: admin.php');
                exit;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOGIN ADMINISTRATIVO
|--------------------------------------------------------------------------
*/

if (isset($_POST['entrar'])) {
    if (!verificarToken()) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }

    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $senha   = (string)($_POST['senha'] ?? '');

    foreach ($admins as $admin) {
        if (
            isset($admin['usuario'], $admin['senha']) &&
            hash_equals((string)$admin['usuario'], $usuario) &&
            password_verify($senha, (string)$admin['senha'])
        ) {
            session_regenerate_id(true);
            $_SESSION['admin']         = true;
            $_SESSION['admin_usuario'] = $usuario;
            $_SESSION['csrf']          = bin2hex(random_bytes(32));
            header('Location: admin.php');
            exit;
        }
    }

    $erro = 'Usuário ou senha incorretos.';
}

/*
|--------------------------------------------------------------------------
| SAIR DA CONTA
|--------------------------------------------------------------------------
*/

if (isset($_POST['sair'])) {
    if (!verificarToken()) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }

    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| VERIFICAR LOGIN
|--------------------------------------------------------------------------
*/

$logado          = !empty($_SESSION['admin']);
$primeiroCadastro = empty($admins);

/*
|--------------------------------------------------------------------------
| EDITAR EPISÓDIO / TEMPORADA
|--------------------------------------------------------------------------
*/

if (isset($_POST['salvar_edicao'])) {
    if (!$logado) {
        http_response_code(403);
        exit('Acesso não autorizado.');
    }
    if (!verificarToken()) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }

    $idEditar      = trim((string)($_POST['editar_id'] ?? ''));
    $novaTemporada = trim((string)($_POST['editar_temporada'] ?? '1'));
    $novoEpisodio  = trim((string)($_POST['editar_episodio'] ?? ''));
    $novoPlayer    = extrairPlayer((string)($_POST['editar_player'] ?? ''));
    $conteudos     = listarConteudos();
    $encontrado    = false;

    foreach ($conteudos as &$registro) {
        if (!is_array($registro) || (string)($registro['id'] ?? '') !== $idEditar) {
            continue;
        }

        $tipoRegistro = normalizarTipoAdmin((string)($registro['tipo'] ?? ''));
        if ($tipoRegistro !== 'filme') {
            if (!ctype_digit($novaTemporada) || (int)$novaTemporada < 1) {
                $erro = 'Informe um número de temporada válido.';
                break;
            }
            if (!ctype_digit($novoEpisodio) || (int)$novoEpisodio < 1) {
                $erro = 'Informe um número de episódio válido.';
                break;
            }
        }

        if ($novoPlayer === false) {
            $erro = 'Player inválido. Cole um iframe com endereço HTTPS ou uma URL HTTPS.';
            break;
        }

        $registro['temporada']    = $tipoRegistro === 'filme' ? '' : (int)$novaTemporada;
        $registro['episodio']     = $tipoRegistro === 'filme' ? '' : (int)$novoEpisodio;
        $registro['player']       = $novoPlayer;
        $registro['atualizado_em'] = date('Y-m-d H:i:s');
        $encontrado = true;
        break;
    }
    unset($registro);

    if ($erro === '' && !$encontrado) {
        $erro = 'O episódio que você tentou editar não foi encontrado.';
    }

    if ($erro === '' && salvarConteudos($conteudos)) {
        header('Location: admin.php?status=editado');
        exit;
    } elseif ($erro === '') {
        $erro = 'Não foi possível salvar as alterações.';
    }
}

/*
|--------------------------------------------------------------------------
| ADICIONAR CONTEÚDO OU NOVO EPISÓDIO A UM CONTEÚDO EXISTENTE
|--------------------------------------------------------------------------
*/

if (isset($_POST['adicionar_conteudo'])) {
    if (!$logado) {
        http_response_code(403);
        exit('Acesso não autorizado.');
    }

    if (!verificarToken()) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }

    $conteudos = listarConteudos();

    $tipo                = normalizarTipoAdmin((string)($_POST['tipo'] ?? ''));
    $titulo              = trim((string)($_POST['titulo'] ?? ''));
    $capaInformada       = trim((string)($_POST['capa'] ?? ''));
    $episodio            = trim((string)($_POST['episodio'] ?? ''));
    $temporada           = trim((string)($_POST['temporada'] ?? '1'));
    $playerEntrada       = trim((string)($_POST['player'] ?? ''));
    $conteudoExistenteId = trim((string)($_POST['conteudo_existente_id'] ?? ''));

    if ($conteudoExistenteId !== '') {
        $registroSelecionado = null;

        foreach ($conteudos as $registro) {
            if (
                is_array($registro) &&
                (string)($registro['id'] ?? '') === $conteudoExistenteId
            ) {
                $registroSelecionado = $registro;
                break;
            }
        }

        if ($registroSelecionado === null) {
            $erro = 'O conteúdo selecionado não foi encontrado. Atualize a página e tente novamente.';
        } else {
            $tipo   = normalizarTipoAdmin((string)($registroSelecionado['tipo'] ?? $registroSelecionado['categoria'] ?? ''));
            $titulo = trim((string)($registroSelecionado['titulo'] ?? $registroSelecionado['nome'] ?? ''));
        }
    }

    $player          = extrairPlayer($playerEntrada);
    $tiposPermitidos = ['anime', 'serie', 'filme'];

    if ($erro === '' && !in_array($tipo, $tiposPermitidos, true)) {
        $erro = 'Selecione um tipo de conteúdo válido.';
    } elseif ($erro === '' && ($titulo === '' || strlen($titulo) > 200)) {
        $erro = 'Informe um título válido de até 200 caracteres.';
    } elseif ($erro === '' && $tipo !== 'filme' && (!ctype_digit($temporada) || (int)$temporada < 1)) {
        $erro = 'Informe um número de temporada válido.';
    } elseif (
        $erro === '' &&
        $episodio !== '' &&
        (!ctype_digit($episodio) || (int)$episodio < 1)
    ) {
        $erro = 'Informe um número de episódio válido.';
    } elseif ($erro === '' && $tipo !== 'filme' && $episodio === '') {
        $erro = 'Informe o número do episódio.';
    } elseif ($erro === '' && $player === false) {
        $erro = 'Player inválido. Cole um iframe com endereço HTTPS ou uma URL HTTPS.';
    }

    if ($erro === '') {
        $capaExistente = buscarCapaExistente($conteudos, $tipo, $titulo);

        if ($capaInformada !== '') {
            if (!validarUrl($capaInformada)) {
                $erro = 'A URL da capa precisa ser válida (HTTP ou HTTPS).';
            } else {
                $capaFinal = $capaInformada;
            }
        } elseif ($capaExistente !== '') {
            $capaFinal = $capaExistente;
        } else {
            $erro = 'Informe a URL da capa para cadastrar este conteúdo pela primeira vez.';
        }
    }

    if ($erro === '') {
        $conteudos[] = [
            'id'         => bin2hex(random_bytes(12)),
            'tipo'       => $tipo,
            'titulo'     => $titulo,
            'capa'       => $capaFinal,
            'temporada'  => $tipo === 'filme' ? '' : (int)$temporada,
            'episodio'   => $tipo === 'filme' ? '' : (int)$episodio,
            'player'     => $player,
            'criado_em'  => date('Y-m-d H:i:s')
        ];

        if (salvarConteudos($conteudos)) {
            header('Location: admin.php?status=salvo');
            exit;
        }

        $erro = 'Não foi possível salvar o conteúdo. Verifique as permissões do arquivo conteudos.json.';
    }
}

/*
|--------------------------------------------------------------------------
| EXCLUIR UM REGISTRO / EPISÓDIO
|--------------------------------------------------------------------------
*/

if (isset($_POST['excluir_conteudo'])) {
    if (!$logado) {
        http_response_code(403);
        exit('Acesso não autorizado.');
    }

    if (!verificarToken()) {
        http_response_code(403);
        exit('Solicitação inválida.');
    }

    $idExcluir = (string)($_POST['id'] ?? '');
    $conteudos = array_values(array_filter(
        listarConteudos(),
        function ($item) use ($idExcluir) {
            return (string)($item['id'] ?? '') !== $idExcluir;
        }
    ));

    if (salvarConteudos($conteudos)) {
        header('Location: admin.php?status=excluido');
        exit;
    }

    $erro = 'Não foi possível excluir o registro.';
}

if (($_GET['status'] ?? '') === 'salvo') {
    $mensagem = 'Episódio/player cadastrado com sucesso!';
} elseif (($_GET['status'] ?? '') === 'excluido') {
    $mensagem = 'Registro excluído com sucesso.';
} elseif (($_GET['status'] ?? '') === 'editado') {
    $mensagem = 'Episódio e temporada atualizados com sucesso.';
}

$conteudos        = listarConteudos();
$gruposConteudos  = agruparConteudosAdmin($conteudos);

$quantidadesPorTipo = [
    'anime' => 0,
    'serie' => 0,
    'filme' => 0
];

foreach ($gruposConteudos as $grupo) {
    if (isset($quantidadesPorTipo[$grupo['tipo']])) {
        $quantidadesPorTipo[$grupo['tipo']]++;
    }
}

$totalTitulos   = count($gruposConteudos);
$totalRegistros = count($conteudos);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administração - AnimeFlix</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    background: #0c0e14;
    color: #fff;
    font-family: 'Inter', sans-serif;
}

.admin-container {
    width: min(1250px, 95%);
    margin: 38px auto;
}

.login-container {
    width: min(430px, 92%);
    margin: 65px auto;
    padding: 30px;
    background: #191d28;
    border: 1px solid #292f40;
    border-radius: 15px;
}

.login-container h1 { margin-top: 0; }
.login-container p { color: #a5adbd; line-height: 1.6; }
.login-container label { display: block; margin: 18px 0 8px; }

.login-container input {
    width: 100%;
    padding: 13px;
    background: #0e111a;
    border: 1px solid #30384b;
    border-radius: 8px;
    color: #fff;
}

.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}

.admin-header h1 { margin: 0 0 8px; font-size: 28px; }
.admin-header p { color: #929bad; margin: 0; line-height: 1.5; }
.admin-header strong { color: #fff; }

.admin-actions, .header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 18px;
    background: linear-gradient(135deg, #6555ef, #8b5cf6);
    border: 0;
    border-radius: 8px;
    color: #fff;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: .2s;
    font-family: inherit;
}

.btn:hover { transform: translateY(-2px); filter: brightness(1.1); }
.btn-secondary { background: #252c3c; }
.btn-danger { background: #a83245; }
.btn-small { padding: 9px 12px; font-size: 12px; }

.admin-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    padding: 21px;
    background: #191d28;
    border: 1px solid #292f40;
    border-radius: 13px;
}

.stat-card span { display: block; color: #9ca5b7; font-size: 13px; margin-bottom: 10px; }
.stat-card strong { font-size: 27px; }

.admin-panel {
    padding: 25px;
    margin-bottom: 24px;
    background: #191d28;
    border: 1px solid #292f40;
    border-radius: 14px;
}

.admin-panel h2 { margin: 0 0 8px; font-size: 21px; }
.panel-description { color: #969fb0; font-size: 14px; line-height: 1.6; margin: 0 0 22px; }

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.form-group { display: flex; flex-direction: column; min-width: 0; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { margin-bottom: 9px; font-size: 13px; font-weight: 700; color: #dce2ef; }

.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 13px;
    background: #0e111a;
    border: 1px solid #30384b;
    border-radius: 8px;
    outline: none;
    color: #fff;
    font-family: inherit;
    font-size: 14px;
}

.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    border-color: #7866ff;
    box-shadow: 0 0 0 3px rgba(120, 102, 255, .12);
}

.form-group input[readonly] {
    color: #b9c0d0;
    background: #151a26;
}

.form-group textarea { min-height: 125px; resize: vertical; line-height: 1.6; }
.form-group small { color: #818ba0; margin-top: 8px; font-size: 12px; line-height: 1.5; }
.form-submit { margin-top: 21px; }

.mensagem, .erro {
    padding: 14px;
    border-radius: 9px;
    margin-bottom: 20px;
    line-height: 1.5;
}

.mensagem { background: #123b2c; border: 1px solid #246c4b; color: #8ff0bd; }
.erro { background: #4a2029; border: 1px solid #813543; color: #ffb0bc; }

.catalog-tabs {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
    margin: 18px 0 20px;
    border-bottom: 1px solid #30384b;
    padding-bottom: 14px;
}

.catalog-tab {
    border: 1px solid #343c50;
    background: #111622;
    color: #b9c1d1;
    padding: 10px 16px;
    border-radius: 8px;
    cursor: pointer;
    font: 700 13px 'Inter', sans-serif;
    transition: .2s;
}

.catalog-tab:hover { border-color: #806dff; color: #fff; }
.catalog-tab.active { background: #6655e9; color: #fff; border-color: #6655e9; }

.catalog-count {
    display: inline-flex;
    min-width: 21px;
    height: 21px;
    align-items: center;
    justify-content: center;
    margin-left: 5px;
    padding: 0 5px;
    border-radius: 20px;
    background: rgba(255,255,255,.13);
    font-size: 11px;
}

.catalog-toolbar {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.catalog-search {
    width: min(360px, 100%);
    padding: 12px 14px;
    border: 1px solid #30384b;
    border-radius: 8px;
    background: #0e111a;
    color: #fff;
    outline: none;
    font: 14px 'Inter', sans-serif;
}

.catalog-search:focus { border-color: #7866ff; }

.catalog-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 17px;
}

.catalog-card {
    overflow: hidden;
    background: #10141e;
    border: 1px solid #292f40;
    border-radius: 12px;
    transition: border-color .2s, transform .2s;
}

.catalog-card:hover { border-color: #6e5df2; transform: translateY(-3px); }

.catalog-cover {
    width: 100%;
    height: 220px;
    display: block;
    object-fit: cover;
    background: #252c3c;
}

.catalog-cover-placeholder {
    height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    text-align: center;
    color: #778197;
    background: linear-gradient(135deg, #20283a, #121722);
}

.catalog-info { padding: 14px; }
.catalog-info h3 { font-size: 15px; margin: 0 0 9px; overflow-wrap: anywhere; line-height: 1.4; }
.catalog-meta { color: #9ca5b7; font-size: 12px; margin: 6px 0; line-height: 1.5; }

.tipo {
    display: inline-block;
    padding: 5px 9px;
    margin-bottom: 9px;
    background: #30275c;
    color: #c7baff;
    border-radius: 5px;
    font-size: 10px;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: .4px;
}

.catalog-card .btn { width: 100%; margin-top: 12px; font-size: 12px; padding: 10px; }
.catalog-info form { margin: 0; }

.empty-state {
    padding: 35px 15px;
    text-align: center;
    color: #939bad;
    background: #10141e;
    border: 1px dashed #353d50;
    border-radius: 10px;
    margin-top: 16px;
}

.empty-state h3 { color: #e6eaf2; margin: 0 0 8px; }
.empty-state p { margin: 0; line-height: 1.6; }

.selected-banner {
    display: none;
    padding: 13px 15px;
    margin-bottom: 18px;
    border-radius: 9px;
    background: rgba(101, 85, 239, .13);
    border: 1px solid rgba(129, 112, 255, .35);
    color: #d8d1ff;
    line-height: 1.5;
}

.selected-banner.visible { display: block; }
.selected-banner strong { color: #fff; }

.logout-form { margin: 0; }

@media (max-width: 650px) {
    .admin-container { width: 92%; margin: 24px auto; }
    .form-grid { grid-template-columns: 1fr; }
    .form-group.full { grid-column: auto; }
    .admin-header { align-items: flex-start; flex-direction: column; }
    .admin-header h1 { font-size: 23px; }
    .admin-panel { padding: 18px; }
    .login-container { margin: 35px auto; padding: 22px; }
    .catalog-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 11px; }
    .catalog-cover, .catalog-cover-placeholder { height: 170px; }
    .catalog-info { padding: 11px; }
    .catalog-info h3 { font-size: 13px; }
    .catalog-tab { padding: 9px 11px; font-size: 12px; }
}
</style>
</head>

<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<?php if ($logado): ?>

<main class="admin-container">

    <header class="admin-header">
        <div>
            <h1>Painel administrativo</h1>
            <p>
                Bem-vindo, <strong><?= escapar($_SESSION['admin_usuario'] ?? 'Administrador') ?></strong>.
                Gerencie o catálogo do AnimeFlix.
            </p>
        </div>

        <div class="admin-actions">
            <a href="index.php" class="btn btn-secondary">Ver site</a>
            <form method="POST" class="logout-form">
                <input type="hidden" name="csrf" value="<?= escapar($_SESSION['csrf']) ?>">
                <button type="submit" name="sair" class="btn btn-danger">Sair da conta</button>
            </form>
        </div>
    </header>

    <?php if ($erro !== ''): ?>
        <div class="erro"><?= escapar($erro) ?></div>
    <?php endif; ?>

    <?php if ($mensagem !== ''): ?>
        <div class="mensagem"><?= escapar($mensagem) ?></div>
    <?php endif; ?>

    <section class="admin-stats">
        <div class="stat-card">
            <span>Títulos no catálogo</span>
            <strong><?= (int)$totalTitulos ?></strong>
        </div>
        <div class="stat-card">
            <span>Registros / episódios</span>
            <strong><?= (int)$totalRegistros ?></strong>
        </div>
        <div class="stat-card">
            <span>Animes</span>
            <strong><?= (int)$quantidadesPorTipo['anime'] ?></strong>
        </div>
        <div class="stat-card">
            <span>Séries</span>
            <strong><?= (int)$quantidadesPorTipo['serie'] ?></strong>
        </div>
        <div class="stat-card">
            <span>Filmes</span>
            <strong><?= (int)$quantidadesPorTipo['filme'] ?></strong>
        </div>
    </section>

    <!-- CATÁLOGO EXISTENTE -->
    <section class="admin-panel" id="gerenciar-catalogo">
        <h2>Conteúdos já adicionados</h2>
        <p class="panel-description">
            Escolha um anime, série ou filme que já esteja no catálogo.
            Ao selecionar um título, o formulário será preenchido para você cadastrar outro episódio ou player nele.
        </p>

        <div class="catalog-tabs" role="tablist" aria-label="Filtrar conteúdos">
            <button type="button" class="catalog-tab active" data-filter="anime">
                Animes <span class="catalog-count"><?= (int)$quantidadesPorTipo['anime'] ?></span>
            </button>
            <button type="button" class="catalog-tab" data-filter="serie">
                Séries <span class="catalog-count"><?= (int)$quantidadesPorTipo['serie'] ?></span>
            </button>
            <button type="button" class="catalog-tab" data-filter="filme">
                Filmes <span class="catalog-count"><?= (int)$quantidadesPorTipo['filme'] ?></span>
            </button>
            <button type="button" class="catalog-tab" data-filter="todos">
                Todos <span class="catalog-count"><?= (int)$totalTitulos ?></span>
            </button>
        </div>

        <div class="catalog-toolbar">
            <input type="search" id="catalogSearch" class="catalog-search" placeholder="Pesquisar título..." autocomplete="off">
            <span class="catalog-meta" id="catalogResultCount"><?= (int)$totalTitulos ?> título(s)</span>
        </div>

        <?php if (!empty($gruposConteudos)): ?>
            <div class="catalog-grid" id="catalogGrid">
                <?php foreach ($gruposConteudos as $grupo): ?>
                    <article
                        class="catalog-card"
                        data-type="<?= escapar($grupo['tipo']) ?>"
                        data-title="<?= escapar(normalizarTextoAdmin($grupo['titulo'])) ?>"
                    >
                        <?php if (!empty($grupo['capa'])): ?>
                            <img
                                class="catalog-cover"
                                src="<?= escapar($grupo['capa']) ?>"
                                alt="Capa de <?= escapar($grupo['titulo']) ?>"
                                loading="lazy"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >
                            <div class="catalog-cover-placeholder" style="display:none">Capa indisponível</div>
                        <?php else: ?>
                            <div class="catalog-cover-placeholder">Sem imagem de capa</div>
                        <?php endif; ?>

                        <div class="catalog-info">
                            <span class="tipo"><?= escapar(nomeTipoAdmin($grupo['tipo'])) ?></span>
                            <h3><?= escapar($grupo['titulo']) ?></h3>

                            <?php if ($grupo['tipo'] === 'filme'): ?>
                                <p class="catalog-meta"><?= (int)$grupo['quantidade'] ?> player(s) cadastrado(s)</p>
                            <?php else: ?>
                                <p class="catalog-meta"><?= count($grupo['episodios']) ?> episódio(s) com player</p>
                            <?php endif; ?>

                            <p class="catalog-meta">
                                <?= $grupo['atualizado'] !== '' ? 'Último cadastro: ' . escapar($grupo['atualizado']) : 'Cadastro existente' ?>
                            </p>

                            <button
                                type="button"
                                class="btn select-content-btn"
                                data-id="<?= escapar($grupo['primeiro_id']) ?>"
                                data-type="<?= escapar($grupo['tipo']) ?>"
                                data-title="<?= escapar($grupo['titulo']) ?>"
                                data-cover="<?= escapar($grupo['capa']) ?>"
                            >
                                Selecionar e adicionar
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="empty-state" id="noSearchResults" style="display:none">
                <h3>Nenhum resultado encontrado</h3>
                <p>Tente pesquisar por outro nome ou mudar a aba selecionada.</p>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <h3>Nenhum conteúdo cadastrado ainda</h3>
                <p>Depois de cadastrar seu primeiro anime, série ou filme, ele aparecerá aqui para você selecionar e adicionar novos episódios.</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- FORMULÁRIO PARA NOVO CONTEÚDO OU EPISÓDIO -->
    <section class="admin-panel" id="formulario-conteudo">
        <h2 id="formTitle">Adicionar novo conteúdo</h2>
        <p class="panel-description" id="formDescription">
            Cadastre um novo anime, série ou filme. Para adicionar episódios a um título existente, selecione-o na seção acima.
        </p>

        <div class="selected-banner" id="selectedBanner">
            Você selecionou: <strong id="selectedName"></strong>.
            O próximo cadastro será adicionado a esse título.
        </div>

        <form method="POST" id="contentForm">
            <input type="hidden" name="csrf" value="<?= escapar($_SESSION['csrf']) ?>">
            <!-- campo usado para vincular ao conteúdo existente (selecionar e adicionar) -->
            <input type="hidden" name="conteudo_existente_id" id="conteudoExistenteId" value="">
            <!-- campo usado para identificar o registro a editar -->
            <input type="hidden" name="editar_id" id="editarId" value="">

            <div class="form-grid">

                <div class="form-group" id="tipoGroup">
                    <label for="tipo">Tipo de conteúdo</label>
                    <select name="tipo" id="tipo" required>
                        <option value="">Selecione o tipo</option>
                        <option value="anime">Anime</option>
                        <option value="serie">Série</option>
                        <option value="filme">Filme</option>
                    </select>
                </div>

                <div class="form-group" id="tituloGroup">
                    <label for="titulo">Nome do conteúdo</label>
                    <input
                        type="text"
                        name="titulo"
                        id="titulo"
                        placeholder="Ex: Naruto Shippuden"
                        maxlength="200"
                        required
                    >
                </div>

                <div class="form-group full">
                    <label for="capa">URL da capa <span id="capaObrigatoria">(obrigatória no primeiro cadastro)</span></label>
                    <input
                        type="url"
                        name="capa"
                        id="capa"
                        placeholder="https://exemplo.com/imagem.jpg"
                    >
                    <small id="capaAjuda">
                        No primeiro cadastro, informe a capa. Ao selecionar um conteúdo existente, a capa será reutilizada automaticamente se você deixar este campo vazio.
                    </small>
                </div>

                <div class="form-group" id="temporadaGroup">
                    <label for="temporada">Número da temporada</label>
                    <input
                        type="number"
                        name="temporada"
                        id="temporada"
                        value="1"
                        placeholder="Ex: 1"
                        min="1"
                        step="1"
                    >
                    <small>Informe a temporada correspondente ao episódio.</small>
                </div>

                <div class="form-group" id="episodioGroup">
                    <label for="episodio">Número do episódio</label>
                    <input
                        type="number"
                        name="episodio"
                        id="episodio"
                        placeholder="Ex: 1"
                        min="1"
                        step="1"
                    >
                    <small id="episodioAjuda">Informe o número do episódio para anime e série. Para filme, deixe vazio.</small>
                </div>

                <!-- Campos exclusivos do modo edição -->
                <div class="form-group" id="editarTemporadaGroup" style="display:none">
                    <label for="editarTemporada">Editar temporada</label>
                    <input type="number" name="editar_temporada" id="editarTemporada" min="1" step="1">
                </div>

                <div class="form-group" id="editarEpisodioGroup" style="display:none">
                    <label for="editarEpisodio">Editar número do episódio</label>
                    <input type="number" name="editar_episodio" id="editarEpisodio" min="1" step="1">
                </div>

                <div class="form-group full" id="editarPlayerGroup" style="display:none">
                    <label for="editarPlayer">Editar URL / iframe do player</label>
                    <textarea name="editar_player" id="editarPlayer"></textarea>
                    <small>Ao editar, informe novamente o endereço HTTPS do player.</small>
                </div>

                <div class="form-group full" id="playerGroup">
                    <label for="player">Player de vídeo / código iframe</label>
                    <textarea
                        name="player"
                        id="player"
                        placeholder="Cole o código iframe completo ou somente a URL HTTPS do player."
                        required
                    ></textarea>
                    <small>
                        O sistema extrai automaticamente o endereço src do iframe. São aceitos apenas endereços HTTPS.
                    </small>
                </div>

            </div>

            <div class="header-actions">
                <button type="submit" name="adicionar_conteudo" class="btn form-submit" id="submitContentBtn">
                    + Cadastrar conteúdo
                </button>
                <button type="submit" name="salvar_edicao" class="btn form-submit" id="saveEditBtn" style="display:none">
                    Salvar edição
                </button>
                <button type="button" class="btn btn-secondary form-submit" id="clearSelectionBtn" style="display:none">
                    Cancelar / limpar
                </button>
            </div>
        </form>
    </section>

    <!-- GERENCIAMENTO DOS REGISTROS INDIVIDUAIS -->
    <section class="admin-panel">
        <h2>Registros cadastrados</h2>
        <p class="panel-description">
            Cada episódio/player é um registro separado. Excluir um registro remove somente aquele episódio/player do catálogo.
        </p>

        <?php if (!empty($conteudos)): ?>
            <div class="catalog-grid">
                <?php foreach (array_reverse($conteudos) as $registro): ?>
                    <article class="catalog-card">
                        <?php if (!empty($registro['capa'])): ?>
                            <img
                                class="catalog-cover"
                                src="<?= escapar($registro['capa']) ?>"
                                alt="Capa de <?= escapar($registro['titulo'] ?? 'Conteúdo') ?>"
                                loading="lazy"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >
                            <div class="catalog-cover-placeholder" style="display:none">Capa indisponível</div>
                        <?php else: ?>
                            <div class="catalog-cover-placeholder">Sem imagem de capa</div>
                        <?php endif; ?>

                        <div class="catalog-info">
                            <span class="tipo"><?= escapar(nomeTipoAdmin(normalizarTipoAdmin((string)($registro['tipo'] ?? '')))) ?></span>
                            <h3><?= escapar($registro['titulo'] ?? 'Sem título') ?></h3>

                            <p class="catalog-meta">
                                <?php if (normalizarTipoAdmin((string)($registro['tipo'] ?? '')) === 'filme'): ?>
                                    Filme completo / player
                                <?php else: ?>
                                    Temporada <?= (int)($registro['temporada'] ?? 1) ?> • Episódio <?= escapar($registro['episodio'] ?? '') ?>
                                <?php endif; ?>
                            </p>
                            <p class="catalog-meta"><?= escapar($registro['criado_em'] ?? '') ?></p>

                            <?php if (normalizarTipoAdmin((string)($registro['tipo'] ?? '')) !== 'filme'): ?>
                                <button
                                    type="button"
                                    class="btn btn-secondary btn-small edit-episode-btn"
                                    data-id="<?= escapar($registro['id'] ?? '') ?>"
                                    data-title="<?= escapar($registro['titulo'] ?? '') ?>"
                                    data-season="<?= (int)($registro['temporada'] ?? 1) ?>"
                                    data-episode="<?= (int)($registro['episodio'] ?? 1) ?>"
                                    data-player="<?= escapar($registro['player'] ?? '') ?>"
                                >Editar episódio / temporada</button>
                            <?php endif; ?>

                            <form method="POST" onsubmit="return confirm('Excluir somente este registro/player? Esta ação não pode ser desfeita.');">
                                <input type="hidden" name="csrf" value="<?= escapar($_SESSION['csrf']) ?>">
                                <input type="hidden" name="id" value="<?= escapar($registro['id'] ?? '') ?>">
                                <button type="submit" name="excluir_conteudo" class="btn btn-danger btn-small">
                                    Excluir registro
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <h3>Nenhum registro cadastrado</h3>
                <p>Os registros adicionados aparecerão aqui.</p>
            </div>
        <?php endif; ?>
    </section>

</main>

<?php elseif ($primeiroCadastro): ?>

<div class="login-container">
    <h1>Criar administrador</h1>
    <p>Crie sua primeira conta administrativa do AnimeFlix.</p>

    <?php if ($erro !== ''): ?>
        <div class="erro"><?= escapar($erro) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf" value="<?= escapar($_SESSION['csrf']) ?>">

        <label>Nome de usuário</label>
        <input type="text" name="usuario" placeholder="Digite seu usuário" minlength="3" required>

        <label>Senha</label>
        <input type="password" name="senha" placeholder="Crie uma senha forte" minlength="10" required>

        <label>Confirmar senha</label>
        <input type="password" name="confirmar" placeholder="Repita sua senha" minlength="10" required>

        <button class="btn" name="cadastrar" style="width:100%;margin-top:22px">
            Criar minha conta
        </button>
    </form>
</div>

<?php else: ?>

<div class="login-container">
    <h1>Login administrativo</h1>
    <p>Entre na sua conta AnimeFlix.</p>

    <?php if ($erro !== ''): ?>
        <div class="erro"><?= escapar($erro) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf" value="<?= escapar($_SESSION['csrf']) ?>">

        <label>Usuário</label>
        <input type="text" name="usuario" placeholder="Seu usuário" required>

        <label>Senha</label>
        <input type="password" name="senha" placeholder="Sua senha" required>

        <button class="btn" name="entrar" style="width:100%;margin-top:22px">
            Entrar
        </button>
    </form>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ------------------------------------------------------------------ */
    /* ELEMENTOS                                                            */
    /* ------------------------------------------------------------------ */
    var tabs         = document.querySelectorAll('.catalog-tab');
    var cards        = document.querySelectorAll('#catalogGrid .catalog-card');
    var searchInput  = document.getElementById('catalogSearch');
    var resultCount  = document.getElementById('catalogResultCount');
    var noResults    = document.getElementById('noSearchResults');

    var form             = document.getElementById('contentForm');
    var tipoSelect       = document.getElementById('tipo');
    var tituloInput      = document.getElementById('titulo');
    var capaInput        = document.getElementById('capa');
    var temporadaInput   = document.getElementById('temporada');
    var episodioInput    = document.getElementById('episodio');
    var playerTextarea   = document.getElementById('player');
    var capaAjuda        = document.getElementById('capaAjuda');
    var capaObrigatoria  = document.getElementById('capaObrigatoria');
    var episodioAjuda    = document.getElementById('episodioAjuda');

    var temporadaGroup   = document.getElementById('temporadaGroup');
    var episodioGroup    = document.getElementById('episodioGroup');
    var playerGroup      = document.getElementById('playerGroup');

    var editarIdInput        = document.getElementById('editarId');
    var editarTemporadaInput = document.getElementById('editarTemporada');
    var editarEpisodioInput  = document.getElementById('editarEpisodio');
    var editarPlayerTextarea = document.getElementById('editarPlayer');
    var editarTemporadaGroup = document.getElementById('editarTemporadaGroup');
    var editarEpisodioGroup  = document.getElementById('editarEpisodioGroup');
    var editarPlayerGroup    = document.getElementById('editarPlayerGroup');

    var conteudoExistenteId = document.getElementById('conteudoExistenteId');
    var selectedBanner      = document.getElementById('selectedBanner');
    var selectedName        = document.getElementById('selectedName');
    var formTitle           = document.getElementById('formTitle');
    var formDescription     = document.getElementById('formDescription');

    var submitContentBtn = document.getElementById('submitContentBtn');
    var saveEditBtn      = document.getElementById('saveEditBtn');
    var clearBtn         = document.getElementById('clearSelectionBtn');

    /* ------------------------------------------------------------------ */
    /* ABAS DE FILTRO DO CATÁLOGO                                          */
    /* ------------------------------------------------------------------ */
    var filtroAtual = 'anime';

    function filtrarCatalogo() {
        if (!cards.length) return;

        var termo   = searchInput ? searchInput.value.trim().toLocaleLowerCase('pt-BR') : '';
        var visiveis = 0;

        cards.forEach(function (card) {
            var tipo   = card.dataset.type  || '';
            var titulo = (card.dataset.title || '').toLocaleLowerCase('pt-BR');
            var ok     = (filtroAtual === 'todos' || tipo === filtroAtual) &&
                         (termo === '' || titulo.includes(termo));

            card.style.display = ok ? '' : 'none';
            if (ok) visiveis++;
        });

        if (resultCount) {
            resultCount.textContent = visiveis + (visiveis === 1 ? ' título' : ' títulos');
        }
        if (noResults) {
            noResults.style.display = visiveis === 0 ? 'block' : 'none';
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            filtroAtual = tab.dataset.filter || 'todos';
            filtrarCatalogo();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', filtrarCatalogo);
    }

    filtrarCatalogo();

    /* ------------------------------------------------------------------ */
    /* AJUSTE DE CAMPOS CONFORME O TIPO SELECIONADO                        */
    /* ------------------------------------------------------------------ */
    function atualizarCamposPorTipo() {
        var tipo = tipoSelect ? tipoSelect.value : '';

        if (tipo === 'filme') {
            episodioInput.required  = false;
            episodioInput.value     = '';
            if (temporadaGroup) temporadaGroup.style.display = 'none';
            if (episodioGroup)  episodioGroup.style.display  = 'none';
            episodioAjuda.textContent   = 'Para filmes, o campo de episódio não é utilizado.';
            capaAjuda.textContent       = 'Informe a capa do filme. Se já existir, será reutilizada automaticamente.';
            capaObrigatoria.textContent = '(obrigatória no primeiro cadastro)';
        } else {
            episodioInput.required = true;
            if (temporadaGroup) temporadaGroup.style.display = '';
            if (episodioGroup)  episodioGroup.style.display  = '';
            episodioAjuda.textContent   = 'Informe o número do episódio que está cadastrando.';
            capaAjuda.textContent       = 'No primeiro cadastro, informe a capa. Ao adicionar episódios a um título existente, você pode deixar este campo vazio para reutilizar a capa cadastrada.';
            capaObrigatoria.textContent = '(obrigatória no primeiro cadastro)';
        }
    }

    if (tipoSelect) {
        tipoSelect.addEventListener('change', atualizarCamposPorTipo);
        atualizarCamposPorTipo();
    }

    /* ------------------------------------------------------------------ */
    /* MODO NORMAL (resetar tudo)                                          */
    /* ------------------------------------------------------------------ */
    function entrarModoNormal() {
        /* Limpa vinculação a conteúdo existente */
        conteudoExistenteId.value = '';

        /* Reabilita e limpa tipo/título */
        tipoSelect.disabled  = false;
        tituloInput.readOnly = false;
        tipoSelect.value     = '';
        tituloInput.value    = '';
        capaInput.value      = '';
        capaInput.placeholder = 'https://exemplo.com/imagem.jpg';
        if (temporadaInput) temporadaInput.value = '1';
        episodioInput.value  = '';
        playerTextarea.value = '';

        /* Limpa campos de edição */
        editarIdInput.value        = '';
        editarTemporadaInput.value = '';
        editarEpisodioInput.value  = '';
        editarPlayerTextarea.value = '';

        /* Visibilidade dos grupos de campos */
        if (temporadaGroup)       temporadaGroup.style.display       = '';
        if (episodioGroup)        episodioGroup.style.display        = '';
        if (playerGroup)          playerGroup.style.display          = '';
        if (editarTemporadaGroup) editarTemporadaGroup.style.display = 'none';
        if (editarEpisodioGroup)  editarEpisodioGroup.style.display  = 'none';
        if (editarPlayerGroup)    editarPlayerGroup.style.display    = 'none';

        /* Botões */
        submitContentBtn.style.display = '';
        saveEditBtn.style.display      = 'none';
        clearBtn.style.display         = 'none';

        /* required no player principal */
        playerTextarea.required = true;

        /* Banner e títulos */
        selectedBanner.classList.remove('visible');
        selectedName.textContent    = '';
        formTitle.textContent       = 'Adicionar novo conteúdo';
        formDescription.textContent = 'Cadastre um novo anime, série ou filme. Para adicionar episódios a um título existente, selecione-o na seção acima.';
        submitContentBtn.textContent = '+ Cadastrar conteúdo';

        atualizarCamposPorTipo();
    }

    /* ------------------------------------------------------------------ */
    /* BOTÃO "SELECIONAR E ADICIONAR"                                      */
    /* ------------------------------------------------------------------ */
    document.querySelectorAll('.select-content-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id             = btn.dataset.id    || '';
            var tipoSelecionado = btn.dataset.type  || '';
            var tituloSelecionado = btn.dataset.title || '';
            var capaSelecionada   = btn.dataset.cover || '';

            if (!id) {
                alert('Não foi possível identificar esse conteúdo. Atualize a página e tente novamente.');
                return;
            }

            /* Sai do modo edição antes de entrar no modo seleção */
            entrarModoNormal();

            /* Vincula ao conteúdo existente */
            conteudoExistenteId.value = id;
            tipoSelect.value          = tipoSelecionado;
            tituloInput.value         = tituloSelecionado;

            /* Bloqueia alteração de tipo e título */
            tipoSelect.disabled  = true;
            tituloInput.readOnly = true;

            /* Atualiza campos conforme tipo */
            atualizarCamposPorTipo();

            /* Banner */
            selectedBanner.classList.add('visible');
            selectedName.textContent = tituloSelecionado;

            /* Textos do formulário */
            formTitle.textContent = tipoSelecionado === 'filme'
                ? 'Adicionar outro player ao filme'
                : 'Adicionar episódio ao conteúdo';
            formDescription.textContent = 'O título e o tipo foram vinculados ao conteúdo selecionado. Informe o número do episódio e o novo player.';
            submitContentBtn.textContent = tipoSelecionado === 'filme'
                ? '+ Adicionar player ao filme'
                : '+ Adicionar episódio';
            clearBtn.style.display = 'inline-flex';

            if (capaSelecionada) {
                capaInput.placeholder = 'A capa existente será reutilizada automaticamente';
            }

            document.getElementById('formulario-conteudo').scrollIntoView({ behavior: 'smooth', block: 'start' });

            setTimeout(function () {
                if (tipoSelecionado !== 'filme') {
                    episodioInput.focus({ preventScroll: true });
                } else {
                    playerTextarea.focus({ preventScroll: true });
                }
            }, 400);
        });
    });

    /* ------------------------------------------------------------------ */
    /* BOTÃO "EDITAR EPISÓDIO / TEMPORADA"                                 */
    /* ------------------------------------------------------------------ */
    document.querySelectorAll('.edit-episode-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id    = btn.dataset.id     || '';
            var title = btn.dataset.title  || '';

            if (!id) return;

            /* Reseta o formulário para estado limpo antes de entrar no modo edição */
            entrarModoNormal();

            /* Preenche campos de edição */
            editarIdInput.value        = id;
            editarTemporadaInput.value = btn.dataset.season  || '1';
            editarEpisodioInput.value  = btn.dataset.episode || '1';
            editarPlayerTextarea.value = btn.dataset.player  || '';

            /* Mostra campos de edição, oculta campos de criação */
            if (editarTemporadaGroup) editarTemporadaGroup.style.display = '';
            if (editarEpisodioGroup)  editarEpisodioGroup.style.display  = '';
            if (editarPlayerGroup)    editarPlayerGroup.style.display    = '';
            if (temporadaGroup)       temporadaGroup.style.display       = 'none';
            if (episodioGroup)        episodioGroup.style.display        = 'none';
            if (playerGroup)          playerGroup.style.display          = 'none';

            /* O player principal não é obrigatório no modo edição */
            playerTextarea.required = false;

            /* Botões */
            submitContentBtn.style.display = 'none';
            saveEditBtn.style.display      = 'inline-flex';
            clearBtn.style.display         = 'inline-flex';

            /* Banner e textos */
            selectedBanner.classList.add('visible');
            selectedName.textContent    = title;
            formTitle.textContent       = 'Editar episódio / temporada';
            formDescription.textContent = 'Altere a temporada, o número do episódio ou o endereço do player. A alteração será aplicada somente a este registro.';

            document.getElementById('formulario-conteudo').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    /* ------------------------------------------------------------------ */
    /* BOTÃO "CANCELAR / LIMPAR"                                           */
    /* ------------------------------------------------------------------ */
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            entrarModoNormal();
        });
    }

    /* ------------------------------------------------------------------ */
    /* SUBMIT: reabilita tipo se estava desabilitado (seleção de conteúdo) */
    /* ------------------------------------------------------------------ */
    if (form) {
        form.addEventListener('submit', function () {
            /* Garante que o campo type chegue ao servidor mesmo quando
               disabled foi usado para impedir alterações visuais.       */
            tipoSelect.disabled = false;
        });
    }

});
</script>

</body>
</html>