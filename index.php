<?php

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';

$host = (string) $config['db_host'];
$porta = (int) $config['db_port'];
$baseUrl = (string) $config['base_url'];
$banco = (string) $config['db_name'];
$usuario = (string) $config['db_user'];
$senha = (string) $config['db_pass'];
$versaoProjeto = (string) ($config['project_version'] ?? 'v2026.10.06');
$horaTopo = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('H:i');

$notaMin = isset($_GET['nota_min']) ? (float) $_GET['nota_min'] : 9;
$notaMax = isset($_GET['nota_max']) ? (float) $_GET['nota_max'] : 10;
$textoPesquisa = trim((string) ($_GET['pesquisa'] ?? ''));
$chvLista = trim((string) ($_GET['chv_lista'] ?? ''));
$ordenarPor = (string) ($_GET['ordenar_por'] ?? 'arquivo');
$direcaoOrdem = strtolower((string) ($_GET['direcao'] ?? 'asc'));

$notaMin = max(-1, min(10, $notaMin));
$notaMax = max(-1, min(10, $notaMax));

if ($notaMin > $notaMax) {
    $aux = $notaMin;
    $notaMin = $notaMax;
    $notaMax = $aux;
}

$musicas = [];
$erroBanco = '';
$topClassificacoes = [];
$listas = [];
$nomeListaSelecionada = '';
$opcoesOrdenacao = [
    'arquivo' => ['label' => 'Arquivo', 'sql' => 'a.ARQUIVO'],
    'pasta' => ['label' => 'Pasta', 'sql' => 'a.PASTA'],
    'nota' => ['label' => 'Nota', 'sql' => 'a.NOTA'],
    'cadastro' => ['label' => 'Cadastro', 'sql' => 'a.QDOINCLUSAO'],
    'qdoultimaveztocado' => ['label' => 'Última vez tocado', 'sql' => 'a.QDOULTIMAVEZTOCADO'],
];

if (!isset($opcoesOrdenacao[$ordenarPor])) {
    $ordenarPor = 'arquivo';
}

if (!in_array($direcaoOrdem, ['asc', 'desc'], true)) {
    $direcaoOrdem = 'asc';
}

function baseServidor() {
    $base = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
    $base = str_replace('\\', '/', trim((string) $base));
    $base = rtrim($base, '/');

    if ($base === '' || !preg_match('#^[A-Za-z]:/#', $base)) {
        $base = rtrim((string) realpath(__DIR__), '/');
    }

    return $base;
}

  
function caminhoMusicaParaUrl($pasta, $arquivo) {
    $pasta = str_replace('\\', '/', trim((string) $pasta));
    $arquivo = trim((string) $arquivo);

    if ($arquivo === '') {
        return rtrim($GLOBALS['baseUrl'], '/') . '/musicas';
    }

    $partes = array_values(array_filter(explode('/', $pasta), static function ($parte) {
        return $parte !== '';
    }));

    $partesMinusculas = array_map('strtolower', $partes);
    $indiceMusicas = array_search('musicas', $partesMinusculas, true);

    if ($indiceMusicas !== false) {
        $partesCaminho = array_slice($partes, $indiceMusicas);
        $partesCaminho[0] = 'musicas';
        $parteUrl = implode('/', $partesCaminho);
    } else {
        $parteUrl = 'musicas';
    }

    $parteUrl = trim($parteUrl, '/');
    $partesUrl = array_map(static function ($parte) {
        return rawurlencode(rawurldecode($parte));
    }, explode('/', $parteUrl));
    $arquivoUrl = rawurlencode(rawurldecode($arquivo));

    return rtrim($GLOBALS['baseUrl'], '/') . '/' . implode('/', $partesUrl) . '/' . $arquivoUrl;
}

function candidatosCaminhoLocal($pasta, $arquivo) {
    $pasta = str_replace('\\', '/', trim((string) $pasta));
    $arquivo = trim((string) $arquivo);
    $base = baseServidor();

    $candidatos = [];

    $caminhosBase = [
        $base,
        rtrim($base, '/') . '/musicas',
        rtrim($base, '/') . '/projetos/player/musicas',
        rtrim($base, '/') . '/projetos/musicas',
        'R:/xampp/htdocs/musicas',
        'r:/xampp/htdocs/musicas',
        'C:/xampp/htdocs/musicas',
        'c:/xampp/htdocs/musicas',
    ];

    foreach ($caminhosBase as $dir) {
        if ($dir !== '') {
            $candidatos[] = rtrim($dir, '/') . '/' . ltrim($arquivo, '/');
        }
    }

    if ($pasta !== '') {
        $candidatos[] = rtrim($pasta, '/') . '/' . ltrim($arquivo, '/');
        $candidatos[] = str_replace('\\', '/', $pasta) . '/' . ltrim($arquivo, '/');
        $candidatos[] = preg_replace('#^/+#', '', $pasta) . '/' . ltrim($arquivo, '/');
    }

    $candidatos[] = $base . '/' . ltrim($pasta, '/') . '/' . ltrim($arquivo, '/');
    $candidatos[] = $base . '/musicas/' . ltrim($arquivo, '/');

    $listaNormalizada = [];
    foreach ($candidatos as $valor) {
        $valor = str_replace('\\', '/', trim((string) $valor));
        if ($valor === '') {
            continue;
        }

        $valor = preg_replace('#^file://#i', '', $valor);
        $valor = preg_replace('#^/([A-Za-z]:/)#', '$1', $valor);

        if (preg_match('#^[A-Za-z]:/#', $valor)) {
            $listaNormalizada[] = str_replace('/', DIRECTORY_SEPARATOR, $valor);
        } else {
            $listaNormalizada[] = str_replace('/', DIRECTORY_SEPARATOR, $base . '/' . ltrim($valor, '/'));
        }
    }

    return array_values(array_unique($listaNormalizada));
}

function caminhoMusicaLocal($pasta, $arquivo) {
    $candidatos = candidatosCaminhoLocal($pasta, $arquivo);

    foreach ($candidatos as $caminho) {
        if (file_exists($caminho)) {
            return $caminho;
        }
    }

    return $candidatos[0] ?? '';
}

function normalizarClassificacoes($valor) {
    $partes = preg_split('/[;]+/', (string) $valor);
    $classificacoes = [];
    $vistos = [];

    foreach ($partes as $parte) {
        $texto = trim($parte);

        if ($texto === '') {
            continue;
        }

        $chave = function_exists('mb_strtolower') ? mb_strtolower($texto, 'UTF-8') : strtolower($texto);
        if (isset($vistos[$chave])) {
            continue;
        }

        $vistos[$chave] = true;
        $classificacoes[] = $texto;
    }

    return implode('; ', $classificacoes);
}

function formatarDuracaoMinutos($valor) {
    $valor = trim((string) $valor);

    if ($valor === '') {
        return '';
    }

    if (!is_numeric($valor)) {
        return $valor;
    }

    $segundos = max(0, (int) round((float) $valor));
    $minutos = intdiv($segundos, 60);
    $restoSegundos = $segundos % 60;

    return sprintf('%d:%02d min', $minutos, $restoSegundos);
}

function formatarCadastro($valor) {
    $valor = trim((string) $valor);

    if ($valor === '') {
        return '';
    }

    try {
        return (new DateTime($valor))->format('d/m/y H:i');
    } catch (Exception $e) {
        return $valor;
    }
}

function urlVideoSegura($valor) {
    $valor = trim((string) $valor);
    if ($valor === '' || filter_var($valor, FILTER_VALIDATE_URL) === false) {
        return '';
    }
    $esquema = strtolower((string) parse_url($valor, PHP_URL_SCHEME));
    return in_array($esquema, ['http', 'https'], true) ? $valor : '';
}

function formatarTamanho($valor) {
    $valor = trim((string) $valor);

    if ($valor === '') {
        return '';
    }

    if (!is_numeric($valor)) {
        return $valor;
    }

    $bytes = max(0, (float) $valor);
    $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
    $indice = 0;

    while ($bytes >= 1024 && $indice < count($unidades) - 1) {
        $bytes /= 1024;
        $indice++;
    }

    return number_format($bytes, $indice === 0 ? 0 : 2, ',', '.') . ' ' . $unidades[$indice];
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$porta;dbname=$banco;charset=utf8mb4",
        $usuario,
        $senha
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tokenEnviado = (string) ($_POST['csrf_token'] ?? '');
        if ($tokenEnviado === '' || !hash_equals($csrfToken, $tokenEnviado)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'erro' => 'Token de segurança inválido.']);
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'registrar_erro') {
        $idMusica = (int) ($_POST['id'] ?? 0);
        $mensagem = trim((string) ($_POST['mensagem'] ?? 'Erro desconhecido de reprodução.'));

        if ($idMusica > 0) {
            $stmtArquivo = $pdo->prepare('SELECT CHV, PASTA, ARQUIVO FROM ARQUIVOS WHERE ID = :id');
            $stmtArquivo->bindValue(':id', $idMusica, PDO::PARAM_INT);
            $stmtArquivo->execute();
            $arquivoErro = $stmtArquivo->fetch(PDO::FETCH_ASSOC);

            if ($arquivoErro) {
                $stmtLogErro = $pdo->prepare('
                    INSERT INTO ARQUIVOS_ERROS (CHV, PASTA, ARQUIVO, MENSAGEM)
                    VALUES (:chv, :pasta, :arquivo, :mensagem)
                ');
                $stmtLogErro->bindValue(':chv', (string) $arquivoErro['CHV'], PDO::PARAM_STR);
                $stmtLogErro->bindValue(':pasta', (string) $arquivoErro['PASTA'], PDO::PARAM_STR);
                $stmtLogErro->bindValue(':arquivo', (string) $arquivoErro['ARQUIVO'], PDO::PARAM_STR);
                $stmtLogErro->bindValue(':mensagem', $mensagem, PDO::PARAM_STR);
                $stmtLogErro->execute();

                $stmtMarcaErro = $pdo->prepare('UPDATE ARQUIVOS SET ERRO = 1 WHERE ID = :id');
                $stmtMarcaErro->bindValue(':id', $idMusica, PDO::PARAM_INT);
                $stmtMarcaErro->execute();
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'limpar_erro') {
        $idMusica = (int) ($_POST['id'] ?? 0);

        if ($idMusica > 0) {
            $stmtLimpaErro = $pdo->prepare('UPDATE ARQUIVOS SET ERRO = 0 WHERE ID = :id');
            $stmtLimpaErro->bindValue(':id', $idMusica, PDO::PARAM_INT);
            $stmtLimpaErro->execute();
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'marcar_tocada') {
        $idMusica = (int) ($_POST['id'] ?? 0);

        if ($idMusica > 0) {
            $stmtTocada = $pdo->prepare('UPDATE ARQUIVOS SET QDOULTIMAVEZTOCADO = NOW() WHERE ID = :id');
            $stmtTocada->bindValue(':id', $idMusica, PDO::PARAM_INT);
            $stmtTocada->execute();
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_musica') {
        $idMusica = (int) ($_POST['id'] ?? 0);
        $nota = isset($_POST['nota']) ? (float) $_POST['nota'] : -1;
        $nota = max(-1, min(10, $nota));
        $classificacoes = normalizarClassificacoes($_POST['classificacoes'] ?? '');
        $observacoes = trim((string) ($_POST['observacoes'] ?? ''));
        $comentario = trim((string) ($_POST['comentario'] ?? ''));

        if ($idMusica > 0) {
            $stmtAtualiza = $pdo->prepare('
                UPDATE ARQUIVOS
                SET NOTA = :nota,
                    CLASSIFICACOES = :classificacoes,
                    OBS = :observacoes,
                    COMENTARIO = :comentario
                WHERE ID = :id
            ');
            $stmtAtualiza->bindValue(':nota', $nota, PDO::PARAM_STR);
            $stmtAtualiza->bindValue(':classificacoes', $classificacoes, PDO::PARAM_STR);
            $stmtAtualiza->bindValue(':observacoes', $observacoes, PDO::PARAM_STR);
            $stmtAtualiza->bindValue(':comentario', $comentario, PDO::PARAM_STR);
            $stmtAtualiza->bindValue(':id', $idMusica, PDO::PARAM_INT);
            $stmtAtualiza->execute();
        }

        $queryRetorno = [];
        parse_str((string) ($_POST['query_retorno'] ?? ''), $queryBruta);
        foreach (['nota_min', 'nota_max', 'pesquisa', 'chv_lista', 'ordenar_por', 'direcao'] as $chavePermitida) {
            if (isset($queryBruta[$chavePermitida]) && is_scalar($queryBruta[$chavePermitida])) {
                $queryRetorno[$chavePermitida] = (string) $queryBruta[$chavePermitida];
            }
        }
        $queryRetorno['salvo'] = '1';
        header('Location: index.php?' . http_build_query($queryRetorno));
        exit;
    }

    $stmtListas = $pdo->query('SELECT CHV, NOME FROM LISTAS ORDER BY NOME');
    $listas = $stmtListas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($listas as $lista) {
        if ((string) $lista['CHV'] === $chvLista) {
            $nomeListaSelecionada = (string) $lista['NOME'];
            break;
        }
    }

    $stmtClassificacoes = $pdo->query("SELECT CLASSIFICACOES FROM ARQUIVOS WHERE CLASSIFICACOES IS NOT NULL AND CLASSIFICACOES <> ''");
    $contagemClassificacoes = [];
    while ($linhaClassificacao = $stmtClassificacoes->fetch(PDO::FETCH_ASSOC)) {
        foreach (preg_split('/[;]+/', (string) $linhaClassificacao['CLASSIFICACOES']) as $parte) {
            $texto = trim($parte);

            if ($texto === '') {
                continue;
            }

            $chave = function_exists('mb_strtolower') ? mb_strtolower($texto, 'UTF-8') : strtolower($texto);
            if (!isset($contagemClassificacoes[$chave])) {
                $contagemClassificacoes[$chave] = [
                    'texto' => $texto,
                    'total' => 0,
                ];
            }

            $contagemClassificacoes[$chave]['total']++;
        }
    }

    usort($contagemClassificacoes, static function ($a, $b) {
        if ($a['total'] === $b['total']) {
            return strcasecmp($a['texto'], $b['texto']);
        }

        return $b['total'] <=> $a['total'];
    });
    $topClassificacoes = array_slice($contagemClassificacoes, 0, 20);

    $camposPesquisa = ['ARQUIVO', 'PASTA', 'TITULO', 'ARTISTA', 'ALBUM', 'OBS', 'COMENTARIO', 'CLASSIFICACOES'];
    $condicoesTexto = [];
    foreach ($camposPesquisa as $indice => $campo) {
        $condicoesTexto[] = 'a.' . $campo . ' LIKE :pesquisa' . $indice;
    }
    $whereTexto = $textoPesquisa !== '' ? ' AND (' . implode(' OR ', $condicoesTexto) . ')' : '';

    $sql = "
        SELECT
            a.ID,
            a.CHV,
            a.QDOINCLUSAO,
            a.QDOULTIMAVEZTOCADO,
            a.PASTA,
            a.ARQUIVO,
            a.EXT,
            a.TITULO,
            a.ARTISTA,
            a.ALBUM,
            a.CLASSIFICACOES,
            a.OBS,
            a.COMENTARIO,
            a.NOTA,
            a.DURACAO,
            a.TAMANHO,
            a.VIDEO,
            a.ERRO
        FROM ARQUIVOS a
    ";

    if ($chvLista !== '') {
        $sql .= "
            INNER JOIN LISTASITENS i ON i.CHVARQUIVO = a.CHV
            INNER JOIN LISTAS l ON l.CHV = i.CHVLISTA
        ";
    }

    $sql .= "
        WHERE a.EXT = 'mp3'
          AND a.NOTA BETWEEN :notaMin AND :notaMax
    ";

    if ($chvLista !== '') {
        $sql .= " AND l.CHV = :chvLista";
    }

    $sql .= $whereTexto;

    if ($chvLista !== '') {
        $sql .= " ORDER BY i.ORDEM ASC, {$opcoesOrdenacao[$ordenarPor]['sql']} $direcaoOrdem, a.ARTISTA, a.TITULO";
    } else {
        $sql .= " ORDER BY {$opcoesOrdenacao[$ordenarPor]['sql']} $direcaoOrdem, a.ARTISTA, a.TITULO";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':notaMin', $notaMin, PDO::PARAM_STR);
    $stmt->bindValue(':notaMax', $notaMax, PDO::PARAM_STR);
    if ($chvLista !== '') {
        $stmt->bindValue(':chvLista', $chvLista, PDO::PARAM_STR);
    }
    if ($textoPesquisa !== '') {
        foreach ($camposPesquisa as $indice => $campo) {
            $stmt->bindValue(':pesquisa' . $indice, '%' . $textoPesquisa . '%', PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $musicas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Erro no banco de dados do player: ' . $e->getMessage());
    $erroBanco = 'Não foi possível carregar as músicas agora. Verifique a conexão com o banco de dados.';
}

$totalMusicas = count($musicas);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Player de Músicas</title>

    <link rel="stylesheet" href="style.css">
</head>

<body>
    <main class="app-shell">
        <header class="topo">
            <div>
                <p class="eyebrow">Biblioteca local</p>
                <h1>Player de Músicas</h1>
                <p class="versao-projeto">
                    Versão <?= htmlspecialchars($versaoProjeto, ENT_QUOTES, 'UTF-8') ?>
                    <span><?= htmlspecialchars($horaTopo, ENT_QUOTES, 'UTF-8') ?></span>
                </p>
            </div>
            <div class="resumo" aria-label="Total de músicas filtradas">
                <span><?= $totalMusicas ?></span>
                <small><?= $totalMusicas === 1 ? 'música' : 'músicas' ?></small>
            </div>
        </header>

        <?php if ($erroBanco !== ''): ?>
            <p class="alerta" role="alert"><?= htmlspecialchars($erroBanco, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if (isset($_GET['salvo'])): ?>
            <p class="alerta sucesso" role="status">Alterações salvas.</p>
        <?php endif; ?>

        <section class="painel-filtros" aria-label="Filtros">
            <form method="GET" class="filtro-nota">
                <label>
                    Lista
                    <select name="chv_lista">
                        <option value="">Todas as músicas</option>
                        <?php foreach ($listas as $lista): ?>
                            <option value="<?= htmlspecialchars((string) $lista['CHV'], ENT_QUOTES, 'UTF-8') ?>" <?= (string) $lista['CHV'] === $chvLista ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $lista['NOME'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Nota mínima
                    <input type="number" name="nota_min" min="-1" max="10" step="0.1" value="<?= htmlspecialchars((string) $notaMin, ENT_QUOTES, 'UTF-8') ?>">
                </label>

                <label>
                    Nota máxima
                    <input type="number" name="nota_max" min="-1" max="10" step="0.1" value="<?= htmlspecialchars((string) $notaMax, ENT_QUOTES, 'UTF-8') ?>">
                </label>

                <label class="campo-pesquisa">
                    Buscar
                    <input type="search" name="pesquisa" placeholder="Título, artista, pasta, arquivo..." value="<?= htmlspecialchars($textoPesquisa, ENT_QUOTES, 'UTF-8') ?>">
                </label>

                <label>
                    Ordenar por
                    <select name="ordenar_por">
                        <?php foreach ($opcoesOrdenacao as $valorOrdem => $opcaoOrdem): ?>
                            <option value="<?= htmlspecialchars($valorOrdem, ENT_QUOTES, 'UTF-8') ?>" <?= $ordenarPor === $valorOrdem ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opcaoOrdem['label'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Direção
                    <select name="direcao">
                        <option value="asc" <?= $direcaoOrdem === 'asc' ? 'selected' : '' ?>>Crescente</option>
                        <option value="desc" <?= $direcaoOrdem === 'desc' ? 'selected' : '' ?>>Decrescente</option>
                    </select>
                </label>

                <button type="submit" class="botao-primario">
                    <span class="icone" aria-hidden="true">&#8981;</span>
                    Filtrar
                </button>
            </form>
        </section>

        <section class="player" aria-label="Player">
            <div class="player-info">
                <span class="icone-player" aria-hidden="true">&#9835;</span>
                <div>
                    <span class="rotulo">Reproduzindo</span>
                    <strong id="musica-atual">Selecione uma música</strong>
                </div>
            </div>
            <div class="controles-player" aria-label="Controles do player">
                <button type="button" class="botao-controle" id="botao-anterior" title="Anterior">
                    <span aria-hidden="true">&#9198;</span>
                    Anterior
                </button>
                <button type="button" class="botao-controle destaque" id="botao-play" title="Play">
                    <span aria-hidden="true">&#9654;</span>
                    Play
                </button>
                <button type="button" class="botao-controle" id="botao-pausa" title="Pausa">
                    <span aria-hidden="true">&#10074;&#10074;</span>
                    Pausa
                </button>
                <button type="button" class="botao-controle" id="botao-proxima" title="Próxima">
                    <span aria-hidden="true">&#9197;</span>
                    Próxima
                </button>
            </div>
            <p id="erro-reproducao" class="erro-reproducao" role="alert" hidden></p>
            <audio id="audio-player" controls></audio>
        </section>

        <section class="lista-musicas" aria-label="Lista de músicas">
            <div class="lista-topo">
                <h2>
                    <?= $nomeListaSelecionada !== '' ? 'Lista: ' . htmlspecialchars($nomeListaSelecionada, ENT_QUOTES, 'UTF-8') : 'Faixas' ?>
                </h2>
                <span><?= $totalMusicas ?> resultado<?= $totalMusicas === 1 ? '' : 's' ?></span>
            </div>

            <div class="tabela-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th class="coluna-acao">Tocar / Arquivo</th>
                            <th class="coluna-nota">Nota</th>
                        </tr>
                    </thead>

                    <tbody>

        <?php foreach ($musicas as $index => $musica): ?>
            <?php
                $url = caminhoMusicaParaUrl((string) $musica['PASTA'], (string) $musica['ARQUIVO']);
                $tituloPlayer = trim(((string) ($musica['ARTISTA'] ?: 'Artista desconhecido')) . ' - ' . ((string) ($musica['TITULO'] ?: $musica['ARQUIVO'])));
            ?>

            <tr class="musica-linha <?= (int) $musica['ERRO'] === 1 ? 'com-erro' : '' ?>" data-id="<?= (int) $musica['ID'] ?>" data-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" data-titulo="<?= htmlspecialchars($tituloPlayer, ENT_QUOTES, 'UTF-8') ?>" data-disponivel="1">
                <td><?= $index + 1 ?></td>
                <td class="coluna-acao">
                    <div class="bloco-reproducao">
                        <button
                            type="button"
                            class="botao-tocar"
                            data-index="<?= $index ?>"
                            data-id="<?= (int) $musica['ID'] ?>"
                            data-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                            data-titulo="<?= htmlspecialchars($tituloPlayer, ENT_QUOTES, 'UTF-8') ?>"
                            title="Tocar música"
                        >
                            <span class="icone" aria-hidden="true">&#9654;</span>
                            Tocar
                        </button>
                        <button
                            type="button"
                            class="botao-detalhes"
                            title="<?= htmlspecialchars((string) $musica['ARQUIVO'], ENT_QUOTES, 'UTF-8') ?>"
                            data-id="<?= (int) $musica['ID'] ?>"
                            data-arquivo="<?= htmlspecialchars((string) $musica['ARQUIVO'], ENT_QUOTES, 'UTF-8') ?>"
                            data-titulo="<?= htmlspecialchars((string) $musica['TITULO'], ENT_QUOTES, 'UTF-8') ?>"
                            data-artista="<?= htmlspecialchars((string) $musica['ARTISTA'], ENT_QUOTES, 'UTF-8') ?>"
                            data-album="<?= htmlspecialchars((string) $musica['ALBUM'], ENT_QUOTES, 'UTF-8') ?>"
                            data-nota="<?= htmlspecialchars((string) $musica['NOTA'], ENT_QUOTES, 'UTF-8') ?>"
                            data-duracao="<?= htmlspecialchars(formatarDuracaoMinutos($musica['DURACAO']), ENT_QUOTES, 'UTF-8') ?>"
                            data-pasta="<?= htmlspecialchars((string) $musica['PASTA'], ENT_QUOTES, 'UTF-8') ?>"
                            data-url="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                            data-chv="<?= htmlspecialchars((string) $musica['CHV'], ENT_QUOTES, 'UTF-8') ?>"
                            data-cadastro="<?= htmlspecialchars(formatarCadastro($musica['QDOINCLUSAO']), ENT_QUOTES, 'UTF-8') ?>"
                            data-qdoinclusao="<?= htmlspecialchars(formatarCadastro($musica['QDOINCLUSAO']), ENT_QUOTES, 'UTF-8') ?>"
                            data-ultimavez="<?= htmlspecialchars(formatarCadastro($musica['QDOULTIMAVEZTOCADO']), ENT_QUOTES, 'UTF-8') ?>"
                            data-tamanho="<?= htmlspecialchars(formatarTamanho($musica['TAMANHO']), ENT_QUOTES, 'UTF-8') ?>"
                            data-video="<?= htmlspecialchars(urlVideoSegura($musica['VIDEO']), ENT_QUOTES, 'UTF-8') ?>"
                            data-classificacoes="<?= htmlspecialchars((string) $musica['CLASSIFICACOES'], ENT_QUOTES, 'UTF-8') ?>"
                            data-observacoes="<?= htmlspecialchars((string) $musica['OBS'], ENT_QUOTES, 'UTF-8') ?>"
                            data-comentario="<?= htmlspecialchars((string) $musica['COMENTARIO'], ENT_QUOTES, 'UTF-8') ?>"
                            data-erro="<?= (int) $musica['ERRO'] ?>"
                        >
                            <?= htmlspecialchars((string) $musica['ARQUIVO'], ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </td>
                <td class="coluna-nota"><?= htmlspecialchars((string) $musica['NOTA'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>

        <?php endforeach; ?>

        <?php if ($totalMusicas === 0): ?>
            <tr>
                <td colspan="3" class="estado-vazio">
                    <?= $nomeListaSelecionada !== '' ? 'Nenhum arquivo encontrado nesta lista.' : 'Nenhuma música encontrada.' ?>
                </td>
            </tr>
        <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <dialog class="modal-detalhes" id="modal-detalhes">
        <form method="POST" class="modal-card">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acao" value="salvar_musica">
            <input type="hidden" name="id" id="detalhe-id">
            <input type="hidden" name="query_retorno" value="<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <header class="modal-topo">
                <div>
                    <span class="rotulo">Detalhes da música</span>
                    <h2 id="detalhe-arquivo">Arquivo</h2>
                </div>
                <button type="button" class="botao-fechar" id="fechar-detalhes" aria-label="Fechar detalhes">&times;</button>
            </header>

            <dl class="grade-detalhes">
                <div class="detalhe-editavel">
                    <dt>Nota</dt>
                    <dd>
                        <input type="number" name="nota" id="detalhe-nota" min="-1" max="10" step="0.1">
                    </dd>
                </div>
                <div><dt>Duração</dt><dd id="detalhe-duracao"></dd></div>
                <div><dt>Cadastro</dt><dd id="detalhe-cadastro"></dd></div>

                <div class="detalhe-largo detalhe-editavel">
                    <dt>Classificações</dt>
                    <dd>
                        <textarea name="classificacoes" id="detalhe-classificacoes" rows="3"></textarea>
                        <?php if (!empty($topClassificacoes)): ?>
                            <div class="chips-classificacoes" aria-label="Classificações mais usadas">
                                <?php foreach ($topClassificacoes as $classificacao): ?>
                                    <button type="button" class="chip-classificacao" data-palavra="<?= htmlspecialchars($classificacao['texto'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($classificacao['texto'], ENT_QUOTES, 'UTF-8') ?>
                                        <small><?= (int) $classificacao['total'] ?></small>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </dd>
                </div>
                <div><dt>Última vez tocado</dt><dd id="detalhe-ultimavez"></dd></div>
                <div><dt>QDOINCLUSAO</dt><dd id="detalhe-qdoinclusao"></dd></div>
                <div><dt>Tamanho</dt><dd id="detalhe-tamanho"></dd></div>
                <div class="detalhe-largo detalhe-editavel">
                    <dt>Observações</dt>
                    <dd><textarea name="observacoes" id="detalhe-observacoes" rows="3"></textarea></dd>
                </div>
                <div class="detalhe-largo detalhe-editavel">
                    <dt>Comentário</dt>
                    <dd><textarea name="comentario" id="detalhe-comentario" rows="3"></textarea></dd>
                </div>

                <div><dt>Título</dt><dd id="detalhe-titulo"></dd></div>
                <div><dt>Artista</dt><dd id="detalhe-artista"></dd></div>
                <div><dt>Álbum</dt><dd id="detalhe-album"></dd></div>
                <div><dt>CHV</dt><dd id="detalhe-chv"></dd></div>
                <div class="detalhe-largo"><dt>Pasta</dt><dd id="detalhe-pasta"></dd></div>
                <div class="detalhe-largo"><dt>URL</dt><dd id="detalhe-url"></dd></div>
                <div class="detalhe-largo"><dt>Vídeo</dt><dd><a href="#" id="detalhe-video" target="_blank" rel="noopener"></a></dd></div>
            </dl>

            <footer class="modal-acoes">
                <button type="button" class="botao-secundario" id="cancelar-detalhes">Cancelar</button>
                <button type="submit" class="botao-primario">
                    <span class="icone" aria-hidden="true">&#10003;</span>
                    Salvar
                </button>
            </footer>
        </form>
    </dialog>

    <script>
        const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const audio = document.getElementById('audio-player');
        const musicaAtual = document.getElementById('musica-atual');
        const erroReproducao = document.getElementById('erro-reproducao');
        const botaoAnterior = document.getElementById('botao-anterior');
        const botaoPlay = document.getElementById('botao-play');
        const botaoPausa = document.getElementById('botao-pausa');
        const botaoProxima = document.getElementById('botao-proxima');
        const botoes = Array.from(document.querySelectorAll('.botao-tocar'));
        const linhas = Array.from(document.querySelectorAll('.musica-linha'));
        const botoesDetalhes = Array.from(document.querySelectorAll('.botao-detalhes'));
        const modalDetalhes = document.getElementById('modal-detalhes');
        const inputDetalheId = document.getElementById('detalhe-id');
        const inputDetalheNota = document.getElementById('detalhe-nota');
        const inputDetalheClassificacoes = document.getElementById('detalhe-classificacoes');
        const inputDetalheObservacoes = document.getElementById('detalhe-observacoes');
        const inputDetalheComentario = document.getElementById('detalhe-comentario');
        const chipsClassificacoes = Array.from(document.querySelectorAll('.chip-classificacao'));
        const fecharDetalhes = document.getElementById('fechar-detalhes');
        const cancelarDetalhes = document.getElementById('cancelar-detalhes');
        const linkDetalheVideo = document.getElementById('detalhe-video');

        let indiceAtual = -1;

        const camposDetalhes = {
            arquivo: document.getElementById('detalhe-arquivo'),
            titulo: document.getElementById('detalhe-titulo'),
            artista: document.getElementById('detalhe-artista'),
            album: document.getElementById('detalhe-album'),
            duracao: document.getElementById('detalhe-duracao'),
            pasta: document.getElementById('detalhe-pasta'),
            url: document.getElementById('detalhe-url'),
            chv: document.getElementById('detalhe-chv'),
            cadastro: document.getElementById('detalhe-cadastro'),
            qdoinclusao: document.getElementById('detalhe-qdoinclusao'),
            ultimavez: document.getElementById('detalhe-ultimavez'),
            tamanho: document.getElementById('detalhe-tamanho')
        };

        function textoOuTraco(valor) {
            return valor && valor.trim() !== '' ? valor : '-';
        }

        function separarClassificacoes(valor) {
            return (valor || '')
                .split(';')
                .map((parte) => parte.trim())
                .filter((parte, indice, lista) => parte !== '' && lista.findIndex((item) => item.toLowerCase() === parte.toLowerCase()) === indice);
        }

        function atualizarChipsAtivos() {
            const classificacoesAtuais = separarClassificacoes(inputDetalheClassificacoes.value).map((parte) => parte.toLowerCase());

            chipsClassificacoes.forEach((chip) => {
                chip.classList.toggle('ativo', classificacoesAtuais.includes(chip.dataset.palavra.toLowerCase()));
            });
        }

        function gravarClassificacoes(lista) {
            inputDetalheClassificacoes.value = lista.join('; ');
            atualizarChipsAtivos();
        }

        function alternarClassificacao(palavra) {
            const classificacoes = separarClassificacoes(inputDetalheClassificacoes.value);
            const indice = classificacoes.findIndex((item) => item.toLowerCase() === palavra.toLowerCase());

            if (indice === -1) {
                classificacoes.push(palavra);
            } else {
                classificacoes.splice(indice, 1);
            }

            gravarClassificacoes(classificacoes);
        }

        function abrirDetalhes(botao) {
            Object.entries(camposDetalhes).forEach(([campo, elemento]) => {
                elemento.textContent = textoOuTraco(botao.dataset[campo] || '');
            });

            const urlVideo = (botao.dataset.video || '').trim();
            linkDetalheVideo.textContent = textoOuTraco(urlVideo);
            if (urlVideo !== '') {
                linkDetalheVideo.href = urlVideo;
                linkDetalheVideo.hidden = false;
            } else {
                linkDetalheVideo.textContent = '-';
                linkDetalheVideo.removeAttribute('href');
                linkDetalheVideo.hidden = false;
            }

            inputDetalheId.value = botao.dataset.id || '';
            inputDetalheNota.value = botao.dataset.nota || '-1';
            inputDetalheClassificacoes.value = botao.dataset.classificacoes || '';
            inputDetalheObservacoes.value = botao.dataset.observacoes || '';
            inputDetalheComentario.value = botao.dataset.comentario || '';
            atualizarChipsAtivos();

            if (typeof modalDetalhes.showModal === 'function') {
                modalDetalhes.showModal();
                return;
            }

            modalDetalhes.setAttribute('open', '');
        }

        function destacarMusicaAtiva(idMusica) {
            linhas.forEach((linha) => {
                const ativa = Number(linha.dataset.id) === Number(idMusica);
                linha.classList.toggle('tocando', ativa);
            });
        }

        function sinalizarErro(mensagem) {
            erroReproducao.textContent = mensagem + ' URL: ' + (audio.currentSrc || audio.src);
            erroReproducao.hidden = false;
            linhas.forEach((linha) => {
                if (Number(linha.dataset.id) === Number(audio.dataset.idMusica)) {
                    linha.classList.add('arquivo-indisponivel');
                }
            });
        }

        function proximoIndiceDisponivel(aPartirDe) {
            for (let index = aPartirDe; index < botoes.length; index += 1) {
                const linha = botoes[index].closest('.musica-linha');

                if (linha && linha.dataset.disponivel !== '0' && !botoes[index].disabled) {
                    return index;
                }
            }

            return -1;
        }

        function indiceDisponivelAnterior(aPartirDe) {
            for (let index = aPartirDe; index >= 0; index -= 1) {
                const linha = botoes[index].closest('.musica-linha');

                if (linha && linha.dataset.disponivel !== '0' && !botoes[index].disabled) {
                    return index;
                }
            }

            return -1;
        }

        function proximaMusica() {
            const proximoIndice = proximoIndiceDisponivel(indiceAtual + 1);

            if (proximoIndice !== -1) {
                tocarMusicaPorIndice(proximoIndice);
                return;
            }

            indiceAtual = -1;
            musicaAtual.textContent = 'Selecione uma música';
            linhas.forEach((linha) => linha.classList.remove('tocando'));
        }

        function musicaAnterior() {
            const indiceAnterior = indiceDisponivelAnterior(indiceAtual - 1);

            if (indiceAnterior !== -1) {
                tocarMusicaPorIndice(indiceAnterior);
            }
        }

        function dataHoraAtualFormatada() {
            const agora = new Date();
            const doisDigitos = (valor) => String(valor).padStart(2, '0');

            return [
                doisDigitos(agora.getDate()),
                doisDigitos(agora.getMonth() + 1),
                String(agora.getFullYear()).slice(-2)
            ].join('/') + ' ' + doisDigitos(agora.getHours()) + ':' + doisDigitos(agora.getMinutes());
        }

        function atualizarUltimaVezTocadaNaTela(idMusica) {
            const linha = linhas.find((item) => Number(item.dataset.id) === Number(idMusica));
            const botaoDetalhes = linha ? linha.querySelector('.botao-detalhes') : null;
            const valor = dataHoraAtualFormatada();

            if (botaoDetalhes) {
                botaoDetalhes.dataset.ultimavez = valor;
            }

            if (inputDetalheId.value !== '' && Number(inputDetalheId.value) === Number(idMusica)) {
                camposDetalhes.ultimavez.textContent = valor;
            }
        }

        function marcarMusicaComoTocada(idMusica) {
            if (!idMusica) {
                return;
            }

            fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: new URLSearchParams({
                    acao: 'marcar_tocada',
                    id: idMusica,
                    csrf_token: csrfToken
                })
            }).then((resposta) => {
                if (resposta.ok) {
                    atualizarUltimaVezTocadaNaTela(idMusica);
                }
            }).catch(() => {});
        }

        function enviarAcaoMusica(acao, idMusica, dadosExtras = {}) {
            if (!idMusica) {
                return Promise.resolve();
            }

            return fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: new URLSearchParams({
                    acao,
                    id: idMusica,
                    csrf_token: csrfToken,
                    ...dadosExtras
                })
            });
        }

        function marcarErroNaTela(idMusica) {
            const linha = linhas.find((item) => Number(item.dataset.id) === Number(idMusica));
            const botaoDetalhes = linha ? linha.querySelector('.botao-detalhes') : null;

            if (linha) {
                linha.classList.add('com-erro', 'arquivo-indisponivel');
            }

            if (botaoDetalhes) {
                botaoDetalhes.dataset.erro = '1';
            }
        }

        function limparErroNaTela(idMusica) {
            const linha = linhas.find((item) => Number(item.dataset.id) === Number(idMusica));
            const botaoDetalhes = linha ? linha.querySelector('.botao-detalhes') : null;

            if (linha) {
                linha.classList.remove('com-erro', 'arquivo-indisponivel');
            }

            if (botaoDetalhes) {
                botaoDetalhes.dataset.erro = '0';
            }
        }

        function registrarErroMusica(mensagem) {
            const idMusica = audio.dataset.idMusica;

            if (!idMusica || audio.dataset.erroRegistrado === '1') {
                return;
            }

            audio.dataset.erroRegistrado = '1';
            marcarErroNaTela(idMusica);
            enviarAcaoMusica('registrar_erro', idMusica, { mensagem }).finally(() => {
                setTimeout(proximaMusica, 250);
            });
        }

        function limparErroMusicaAtual() {
            const idMusica = audio.dataset.idMusica;

            if (!idMusica || audio.dataset.erroLimpo === '1') {
                return;
            }

            audio.dataset.erroLimpo = '1';
            limparErroNaTela(idMusica);
            enviarAcaoMusica('limpar_erro', idMusica).catch(() => {});
        }

        function tocarMusicaPorIndice(index) {
            if (index < 0 || index >= botoes.length) {
                return;
            }

            const botao = botoes[index];
            const linha = botao.closest('.musica-linha');

            if (!linha || linha.dataset.disponivel === '0') {
                musicaAtual.textContent = 'Arquivo indisponível para reprodução';
                if (linha) {
                    linha.classList.add('arquivo-indisponivel');
                }
                return;
            }

            indiceAtual = index;
            audio.dataset.idMusica = botao.dataset.id;
            audio.dataset.tocadaRegistrada = '0';
            audio.dataset.erroRegistrado = '0';
            audio.dataset.erroLimpo = '0';
            erroReproducao.hidden = true;
            erroReproducao.textContent = '';
            audio.src = botao.dataset.url;
            musicaAtual.textContent = botao.dataset.titulo;
            destacarMusicaAtiva(botao.dataset.id);
            audio.play().catch((erro) => {
                const mensagem = 'Falha ao iniciar reprodução (' + erro.name + ': ' + erro.message + ').';
                sinalizarErro(mensagem);
                registrarErroMusica(mensagem);
            });
        }

        botoes.forEach((botao, index) => {
            botao.addEventListener('click', () => {
                tocarMusicaPorIndice(index);
            });
        });

        botoesDetalhes.forEach((botao) => {
            botao.addEventListener('click', () => {
                abrirDetalhes(botao);
            });
        });

        chipsClassificacoes.forEach((chip) => {
            chip.addEventListener('click', () => {
                alternarClassificacao(chip.dataset.palavra);
            });
        });

        inputDetalheClassificacoes.addEventListener('input', atualizarChipsAtivos);

        [fecharDetalhes, cancelarDetalhes].forEach((botao) => {
            botao.addEventListener('click', () => {
                modalDetalhes.close();
            });
        });

        botaoAnterior.addEventListener('click', musicaAnterior);
        botaoProxima.addEventListener('click', proximaMusica);
        botaoPausa.addEventListener('click', () => audio.pause());
        botaoPlay.addEventListener('click', () => {
            if (indiceAtual === -1) {
                const primeiroIndice = proximoIndiceDisponivel(0);
                if (primeiroIndice !== -1) {
                    tocarMusicaPorIndice(primeiroIndice);
                }
                return;
            }

            audio.play().catch((erro) => {
                const mensagem = 'Falha ao retomar reprodução (' + erro.name + ': ' + erro.message + ').';
                sinalizarErro(mensagem);
                registrarErroMusica(mensagem);
            });
        });

        audio.addEventListener('error', () => {
            const errosAudio = {
                1: 'Carregamento de áudio cancelado',
                2: 'Erro de rede ao carregar o áudio',
                3: 'Erro ao decodificar o áudio',
                4: 'Formato não suportado ou arquivo não encontrado'
            };
            const codigoErro = audio.error ? audio.error.code : 0;
            const mensagem = (errosAudio[codigoErro] || 'Erro desconhecido de reprodução') + ' (código ' + codigoErro + ').';
            sinalizarErro(mensagem);
            registrarErroMusica(mensagem);
            linhas.forEach((linha) => linha.classList.remove('tocando'));
        });

        audio.addEventListener('playing', limparErroMusicaAtual);

        audio.addEventListener('timeupdate', () => {
            const idMusica = audio.dataset.idMusica;
            const duracao = audio.duration;

            if (
                audio.dataset.tocadaRegistrada === '1' ||
                !idMusica ||
                !Number.isFinite(duracao) ||
                duracao <= 0 ||
                audio.currentTime < duracao * 0.15
            ) {
                return;
            }

            audio.dataset.tocadaRegistrada = '1';
            marcarMusicaComoTocada(idMusica);
        });

        audio.addEventListener('ended', () => {
            proximaMusica();
        });
    </script>

</body>
</html>
